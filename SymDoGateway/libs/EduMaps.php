<?php

declare(strict_types=1);

/**
 * Klassenseiten (Edumaps) auslesen.
 *
 * Die Klassenseite der Schule traegt genau das, was im Haushalt sonst abgetippt
 * wird: Termine, Elternbriefe mit Fristen, Materiallisten, den Stundenplan als
 * PDF. Diese Quelle holt die Seite, erkennt GEAENDERTE Karten und schickt jede
 * einzeln durch dieselbe Kette wie eine Schulmail — KI-Analyse, dann ein
 * Vorschlag in der App, den jemand prueft und uebernimmt. Nichts entsteht
 * ungefragt.
 *
 * Warum das so wenig Code ist: MailAnalyseRecord() ist quellenneutral (letzter
 * Parameter `$quelle`) und hatte schon zwei Aufrufer, den Postfach- und den
 * Webhook-Weg. Alles dahinter — KI-Aufruf mit Anhang, Zerlegung in
 * task/event/note, Ablage als Vorschlag, Uebernahme — wird unveraendert
 * mitbenutzt.
 *
 * Am 03.09.2026 an der echten Seite gemessen:
 *  - reines server-gerendertes HTML, ~58 kB, kein JS-Framework, kein JSON
 *  - JEDE Karte traegt `data-boxid` und `data-updated` im Markup; damit braucht
 *    es keinen Textvergleich, um Aenderungen zu erkennen
 *  - Anhaenge liegen unter /file/<id>.<ext>/<token> und sind ohne Anmeldung
 *    ladbar
 *  - kein ETag, kein Last-Modified (`no-store`) — es hilft nur Nachsehen
 *
 * Die Adresse enthaelt den Zugang und wirkt wie ein Kennwort: sie steht in
 * einer Property, NICHT im Quelltext und nicht im Protokoll.
 */
trait EduMaps
{
    /** Karten je Lauf — mehr ist ein Zeichen dafuer, dass etwas nicht stimmt. */
    private const EDU_KARTEN_MAX = 60;

    /** Zeichen je Karte, die an die KI gehen (wie MAIL_TEXT_MAX, nur kleiner). */
    private const EDU_TEXT_MAX = 8000;

    /** Laenger wird die formatierte Fassung nicht abgelegt — dann bleibt es beim
     *  Klartext, der ohnehin daneben steht. */
    private const EDU_HTML_MAX = 8000;

    /** Gesamtgroesse der Anhaenge je Karte, base64 (wie beim Webhook-Weg). */
    private const EDU_ANHANG_MAX_B64 = 8000000;

    /** Wie oft nachgesehen wird, wenn die Property noch nicht existiert. */
    private const EDU_INTERVALL_STD = 6;

    /** Hoechstens so viele Analysen je Lauf — der Rest kommt beim naechsten. */
    private const EDU_JE_LAUF_MAX = 5;

    /** Leerer Sammeleintrag je Familienmitglied — siehe EduPushSenden(). */
    private const EDU_PUSH_LEER = ['karten' => 0, 'aufgaben' => 0, 'termine' => 0,
                                   'notizen' => 0, 'seiten' => []];

    /** So viele verlinkte Karten werden hoechstens aufgenommen. */
    private const EDU_GEFUNDEN_MAX = 20;

    private ?array $eduConfigCache = null;

    /** Setzt EduNotizOrdner, wenn es den Bestand angefasst hat (Ordner angelegt
     *  oder nachtraeglich in den Kindordner gehoben). Dann muss geschrieben
     *  werden, auch wenn an der Notiz selbst nichts zu tun war. */
    private bool $eduOrdnerGeaendert = false;

    /** Die Meldungen EINES Durchgangs, je Familienmitglied: aktualisierte Karten,
     *  neue Vorschlaege und die Namen der beteiligten Seiten. Null heisst, dass
     *  gerade kein Durchgang laeuft. */
    private ?array $eduPushSammlung = null;

    // ────────────────────────────── Lebenszyklus ──────────────────────────────

    private function EduCreate(): void
    {
        $this->RegisterPropertyBoolean('EduEnabled', false);
        /* Liste statt Einzeladresse: eine zweite Klasse kommt absehbar dazu, und
           eine Liste kostet jetzt dasselbe wie ein Feld. */
        $this->RegisterPropertyString('EduPages', '[]');
        /* 1:1-Spiegel der Karten als Notizen. Getrennt vom Vorschlagsweg: der
           eine wertet aus (kostet KI), der andere legt nur ab. */
        $this->RegisterPropertyBoolean('EduToNotes', false);
        $this->RegisterPropertyInteger('EduIntervalHours', self::EDU_INTERVALL_STD);
        /* Meldung aufs Telefon — EINE je Durchgang, nicht eine je Karte. Sonst
           staenden nach dem ersten Lauf sechzehn Nachrichten auf dem
           Sperrbildschirm, eine je gespiegelter Karte. */
        $this->RegisterPropertyBoolean('EduPush', true);
        /* Eigener Merker, NICHT MailSeenUIDs: die Toepfe dort sind auf 500
           Eintraege gedeckelt und werden vom Mailweg beschrieben. Eine
           Klassenseite mit zwoelf Karten braucht ihren eigenen Platz. */
        /* Verlinkte Karten mitnehmen: eine Klassenseite verweist auf weitere
           Karten (etwa „Englisch Grammatik"). Sie werden nur GESPIEGELT, nie
           ausgewertet — es sind Nachschlagewerke, keine Elternbriefe, und
           jede Auswertung kostet einen KI-Aufruf. */
        $this->RegisterPropertyBoolean('EduFollowLinks', false);
        $this->RegisterAttributeString('EduFound', '[]');
        $this->RegisterAttributeString('EduSeen', '{}');
        $this->RegisterAttributeString('EduStatus', '{}');
        $this->RegisterTimer('EduScan', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'EduScan\', 0);');
    }

    private function EduApplyChanges(): void
    {
        $stunden = (int)$this->EduProp('EduIntervalHours', self::EDU_INTERVALL_STD);
        $an = $this->EduIsEnabled() && $this->EduSeiten() !== [];
        @$this->SetTimerInterval('EduScan', $an ? max(1, $stunden) * 3600000 : 0);
    }

    private function EduRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident === 'EduScan') {
            $this->EduScanRun();
            return true;
        }
        if ($Ident === 'EduForgetFound') {
            @$this->WriteAttributeString('EduFound', '[]');
            $this->UpdateFormField('EduStatusLabel', 'caption',
                $this->Translate('Linked maps forgotten — the next check finds them again.'));
            return true;
        }
        if ($Ident === 'EduScanAll') {
            // Alles auswerten, auch schon Gesehenes. Teuer, darum ein eigener Knopf.
            $this->UpdateFormField('EduStatusLabel', 'caption', $this->EduScanRun(true, true));
            return true;
        }
        if ($Ident === 'EduScanNow') {
            $bericht = $this->EduScanRun(true);
            $this->UpdateFormField('EduStatusLabel', 'caption', $bericht);
            return true;
        }
        if ($Ident === 'EduForget') {
            // Nach einer Fehlkonfiguration: alles vergessen, damit der naechste
            // Lauf wieder als erster gilt (also nur vermerkt, nicht analysiert).
            @$this->WriteAttributeString('EduSeen', '{}');
            $this->LogMessage('SymDo: gemerkte Klassenseiten-Karten vergessen', KL_NOTIFY);
            $this->UpdateFormField('EduStatusLabel', 'caption',
                $this->Translate('Noted cards forgotten — the next check counts as the first run.'));
            return true;
        }
        return false;
    }

    // ───────────────────────────── Meldung ─────────────────────────────

    /**
     * Aktualisierte Karten einer Seite vormerken.
     *
     * Gezaehlt wird, was der Spiegel WIRKLICH geschrieben hat — eine unveraenderte
     * Karte gibt nichts zurueck und taucht hier nie auf.
     */
    private function EduPushKarten(string $userId, string $seite, int $karten): void
    {
        if ($this->eduPushSammlung === null || $karten <= 0) {
            return;
        }
        $e = $this->eduPushSammlung[$userId] ?? self::EDU_PUSH_LEER;
        $e['karten'] += $karten;
        if ($seite !== '' && !in_array($seite, $e['seiten'], true)) {
            $e['seiten'][] = $seite;
        }
        $this->eduPushSammlung[$userId] = $e;
    }

    /**
     * Neue Vorschlaege einer Karte vormerken. Gerufen aus MailNotifyProposal,
     * sobald die Quelle „Edumaps" heisst.
     */
    private function EduPushMerken(string $userId, int $aufgaben, int $termine, int $notizen): void
    {
        // Ausserhalb eines Durchgangs (denkbar, wenn eine Karte einzeln laeuft)
        // wird nicht gesammelt, sondern sofort gemeldet.
        $sofort = $this->eduPushSammlung === null;
        if ($sofort) {
            $this->eduPushSammlung = [];
        }
        $e = $this->eduPushSammlung[$userId] ?? self::EDU_PUSH_LEER;
        $e['aufgaben'] += $aufgaben;
        $e['termine']  += $termine;
        $e['notizen']  += $notizen;
        $this->eduPushSammlung[$userId] = $e;
        if ($sofort) {
            $this->EduPushSenden();
        }
    }

    /**
     * Eine Nachricht je Familienmitglied, dann ist der Durchgang zu Ende.
     *
     * Inhalt bewusst NUR Zahlen und der Name der Seite: der Text der Karten ist
     * nichts fuer einen Sperrbildschirm.
     */
    private function EduPushSenden(): void
    {
        $sammlung = $this->eduPushSammlung ?? [];
        $this->eduPushSammlung = null;
        if ($sammlung === [] || !(bool)$this->EduProp('EduPush', true)) {
            return;
        }
        foreach ($sammlung as $userId => $e) {
            $teile = [];
            if ($e['karten'] > 0) {
                $teile[] = $e['karten'] === 1
                    ? $this->Translate('1 card updated')
                    : sprintf($this->Translate('%d cards updated'), $e['karten']);
            }
            $wartet = [];
            if ($e['notizen'] > 0) {
                $wartet[] = $e['notizen'] === 1
                    ? $this->Translate('1 note')
                    : sprintf($this->Translate('%d notes'), $e['notizen']);
            }
            if ($e['termine'] > 0) {
                $wartet[] = $e['termine'] === 1
                    ? $this->Translate('1 appointment')
                    : sprintf($this->Translate('%d appointments'), $e['termine']);
            }
            if ($e['aufgaben'] > 0) {
                $wartet[] = $e['aufgaben'] === 1
                    ? $this->Translate('1 task')
                    : sprintf($this->Translate('%d tasks'), $e['aufgaben']);
            }
            if ($wartet !== []) {
                $teile[] = sprintf($this->Translate('%s waiting to be accepted'), implode(', ', $wartet));
            }
            if ($teile === []) {
                continue;
            }
            /* Der Name der Seite steht im Titel, solange nur eine beteiligt war —
               „Klassenseite 5a Joshua" sagt mehr als „Neues von der Klassenseite".
               Bei mehreren waere er irrefuehrend. */
            $titel = count($e['seiten']) === 1
                ? sprintf($this->Translate('Class page %s'), (string)$e['seiten'][0])
                : $this->Translate('New from the class page');
            // Antippen fuehrt dorthin, wo das Neue liegt: Vorschlaege in den
            // KI-Bereich, gespiegelte Karten in die Notizen.
            $vorschlaege = ($e['aufgaben'] + $e['termine'] + $e['notizen']) > 0;
            $this->PushBroadcast(
                $titel,
                implode(' · ', $teile),
                (string)$userId,
                $vorschlaege ? 'ki' : 'notes',
                // Aufs App-Symbol kommt nur, was auch wirklich wartet.
                $vorschlaege ? $this->MailPendingCount() : -1
            );
        }
    }

    // ──────────────────────────────── Ablauf ────────────────────────────────

    /**
     * Ein Durchgang ueber alle eingetragenen Seiten.
     *
     * @param bool $vonHand aus dem Formular angestossen — dann ist die Rueckgabe
     *                      der Text fuer die Statuszeile.
     */
    private function EduScanRun(bool $vonHand = false, bool $alles = false): string
    {
        if (!$this->EduIsEnabled()) {
            return $this->Translate('Class pages are switched off.');
        }
        $seiten = $this->EduSeiten();
        if ($seiten === []) {
            return $this->Translate('No class page entered yet.');
        }
        /* Die verlinkten Karten hinten anstellen und NUR spiegeln: sie sind
           Nachschlagewerke („Englisch Grammatik"), keine Elternbriefe. Eine
           Auswertung wuerde daraus Aufgaben wie „Present" machen und je Karte
           einen KI-Aufruf kosten. */
        foreach ($this->EduGefundene() as $g) {
            $seiten[] = $g + ['nurSpiegeln' => true];
        }
        $karten = 0;
        $geaendert = 0;
        $analysiert = 0;
        $gespiegelt = 0;
        $fehler = [];
        // Ab hier wird gesammelt statt gemeldet — EINE Nachricht am Ende.
        $this->eduPushSammlung = [];
        foreach ($seiten as $seite) {
            $erg = $this->EduSeiteLesen($seite, $analysiert, $alles);
            $karten     += $erg['karten'];
            $geaendert  += $erg['geaendert'];
            $analysiert += $erg['analysiert'];
            $gespiegelt += (int)($erg['gespiegelt'] ?? 0);
            $this->EduPushKarten((string)($seite['userId'] ?? ''), (string)$seite['name'],
                (int)($erg['gespiegelt'] ?? 0));
            if (($erg['fehler'] ?? '') !== '') {
                $fehler[] = $seite['name'] . ': ' . $erg['fehler'];
            }
        }
        $bericht = sprintf(
            $this->Translate('%1$d card(s) on %2$d page(s), %3$d changed, %4$d analysed.'),
            $karten, count($seiten), $geaendert, $analysiert
        );
        if ($gespiegelt > 0) {
            $bericht .= ' ' . sprintf($this->Translate('%d saved as note(s).'), $gespiegelt);
        }
        if ($fehler !== []) {
            $bericht .= ' — ' . implode(' | ', $fehler);
        }
        @$this->WriteAttributeString('EduStatus', (string)json_encode(
            ['t' => time(), 'text' => $bericht], JSON_UNESCAPED_UNICODE));
        $this->SendDebug('EduMaps', $bericht, 0);
        $this->EduPushSenden();
        return $bericht;
    }

    /**
     * Eine Seite: holen, zerlegen, geaenderte Karten analysieren.
     *
     * @param array{name:string,url:string,userId:string} $seite
     * @return array{karten:int,geaendert:int,analysiert:int,fehler:string}
     */
    private function EduSeiteLesen(array $seite, int $schonAnalysiert, bool $alles = false): array
    {
        $leer = ['karten' => 0, 'geaendert' => 0, 'analysiert' => 0, 'fehler' => ''];
        $antwort = $this->AiFetchPublicPage($seite['url']);
        if (($antwort['ok'] ?? false) !== true) {
            // Die Adresse NICHT ins Protokoll: sie ist der Zugang.
            return ['fehler' => (string)($antwort['message'] ?? $antwort['code'] ?? 'Abruf fehlgeschlagen')] + $leer;
        }
        $rumpfSeite = (string)($antwort['body'] ?? '');
        // Verweise nur von den EINGETRAGENEN Seiten verfolgen, eine Ebene tief.
        if (($seite['nurSpiegeln'] ?? false) !== true) {
            $this->EduGefundeneErgaenzen($seite, $rumpfSeite);
        }
        $karten = $this->EduKarten($rumpfSeite);
        if ($karten === []) {
            /* Kein stilles Schweigen: bricht Edumaps das Markup, sieht es sonst
               aus wie „nichts Neues". */
            return ['fehler' => $this->Translate('0 cards found — has the page changed?')] + $leer;
        }
        $topf = 'edu:' . md5($seite['url']);
        $ersterLauf = !$this->EduTopfHatEintraege($topf);
        $geaendert = 0;
        $analysiert = 0;
        $gedeckelt = false;
        $gespiegelt = 0;
        foreach ($karten as $nr => $karte) {
            /* Spiegeln zuerst und fuer JEDE Karte: es kostet keinen KI-Aufruf,
               haengt also an keinem Deckel und auch nicht daran, ob die Karte
               als „geaendert" gilt. Die Notiz selbst entscheidet, ob es etwas
               zu tun gibt (srcRev). */
            if ($this->EduNotizSpiegeln($seite, $karte, (int)$nr)) {
                $gespiegelt++;
            }
            $schluessel = $karte['boxid'] . ':' . $karte['updated'];
            /* „Alles auswerten" nimmt auch die Karten, die schon im Merker
               stehen — sonst waere nach dem ersten Lauf, der nur vermerkt,
               nie etwas auszuwerten. Das ist ein Griff von Hand und teuer:
               jede Karte kostet einen KI-Aufruf, hier also sechzehn. */
            if (!$alles && $this->EduGesehen($topf, $schluessel)) {
                continue;
            }
            $geaendert++;
            if ($ersterLauf && !$alles) {
                /* Erster Lauf: nur vermerken. Sonst stuenden beim Einschalten
                   zwoelf Vorschlaege auf einmal da, und jeder kostet Geld. */
                $this->EduMerken($topf, $schluessel);
                continue;
            }
            /* Deckel: ab hier wird NICHT mehr ausgewertet — aber weiter
               gespiegelt. `break` liess frueher die ganze Schleife fallen, und
               damit blieben die restlichen Karten auch UNGESPIEGELT liegen,
               obwohl das Spiegeln keinen KI-Aufruf kostet und oben in derselben
               Runde schon gelaufen waere. Also `continue`: die Karte ist
               gespiegelt, sie bleibt nur unvermerkt und kommt beim naechsten
               Lauf zur Auswertung. */
            if ($this->MailDayLimitReached()) {
                $this->SendDebug('EduMaps', 'Tagesdeckel erreicht — Auswertung wartet, Spiegel laeuft weiter', 0);
                $gedeckelt = true;
                continue;
            }
            if (!$alles && $schonAnalysiert + $analysiert >= self::EDU_JE_LAUF_MAX) {
                $this->SendDebug('EduMaps', 'Deckel je Lauf erreicht — Auswertung wartet, Spiegel laeuft weiter', 0);
                continue;
            }
            if (($seite['nurSpiegeln'] ?? false) === true) {
                // Gespiegelt ist sie schon; ausgewertet wird sie nicht.
                $this->EduMerken($topf, $schluessel);
                continue;
            }
            if ($this->EduKarteAnalysieren($seite, $karte)) {
                $this->EduMerken($topf, $schluessel);
                $analysiert++;
            }
        }
        return ['karten' => count($karten), 'geaendert' => $geaendert,
                'analysiert' => $analysiert, 'gespiegelt' => $gespiegelt,
                // Ein abgebrochener Lauf darf nicht wie ein vollstaendiger aussehen.
                'fehler' => $gedeckelt ? $this->Translate('daily AI limit reached — the rest follows later') : ''];
    }

    /**
     * Eine Karte durch die Mail-Kette schicken. Titel und Abschnitt bilden den
     * „Betreff", damit die KI den Zusammenhang hat („Elternbriefe · Kopiergeld").
     *
     * @param array{name:string,url:string,userId:string} $seite
     * @param array<string,mixed> $karte
     */
    /**
     * Eine Karte 1:1 als Notiz ablegen — voller Text, Bilder und Dateien.
     *
     * Das ist ABSICHTLICH von der KI-Auswertung getrennt: Spiegeln kostet nichts,
     * also haengt es weder am Tagesdeckel noch am Deckel je Lauf, und es laeuft
     * auch beim ERSTEN Lauf, der sonst nur vermerkt. Wer den Schalter umlegt,
     * will den Bestand sehen, nicht in sechs Stunden die Haelfte davon.
     *
     * Der Ordner liegt IM Ordner des Kindes (Notizen kennen seit dem 03.09.2026
     * Verschachtelung). Hat das Kind keinen Mitglieder-Ordner, liegt er oben.
     *
     * Wiedererkannt wird die Karte an `srcId` in der Notiz selbst, nicht an einem
     * eigenen Merker: ein neues Attribut braeuchte einen Kernel-Neustart, und ein
     * zweiter Bestand kann mit dem ersten auseinanderlaufen.
     */
    private function EduNotizSpiegeln(array $seite, array $karte, int $nr = 0): bool
    {
        if (!(bool)$this->EduProp('EduToNotes', false)) {
            return false;
        }
        if (!$this->NotesStorable()) {
            $this->SendDebug('EduMaps', 'Notizen-Bestand nicht beschreibbar — Kernel-Neustart nötig', 0);
            return false;
        }
        $lock = self::NOTES_LOCK . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 2000)) {
            $this->SendDebug('EduMaps', 'Notizen belegt — Karte beim naechsten Lauf', 0);
            return false;
        }
        try {
            $store = $this->NotesStore();
            $this->eduOrdnerGeaendert = false;
            $ordnerId = $this->EduNotizOrdner($store, $seite);
            if ($ordnerId === '') {
                return false;
            }
            $srcId = 'edu:' . $karte['boxid'];
            $jetzt = time();
            $i = -1;
            foreach ($store['notes'] as $k => $n) {
                if ((string)($n['srcId'] ?? '') === $srcId) {
                    $i = (int)$k;
                    break;
                }
            }
            // Unveraendert? Dann nichts anfassen — kein Schreiben, keine neuen
            // Medien. Nur wenn der ORDNER sich bewegt hat, muss der Bestand
            // trotzdem einmal geschrieben werden.
            if ($i >= 0 && (int)($store['notes'][$i]['srcRev'] ?? -1) === (int)$karte['updated']) {
                /* Nichts Neues an der Karte. Zwei Dinge koennen trotzdem fehlen:
                   der verschobene Ordner und — bei Notizen aus der Zeit vor der
                   Kartenansicht — Abschnitt und Platz. Beides nachziehen, ohne
                   die Anhaenge anzufassen. */
                $fehlt = !array_key_exists('section', $store['notes'][$i])
                    || (int)($store['notes'][$i]['pos'] ?? -1) !== $nr
                    || (string)($store['notes'][$i]['sectionColor'] ?? '') !== (string)($karte['abschnittFarbe'] ?? '')
                    || (string)($store['notes'][$i]['color'] ?? '') !== (string)($karte['farbe'] ?? '');
                $store['notes'][$i]['sectionColor'] = (string)($karte['abschnittFarbe'] ?? '');
                $store['notes'][$i]['color'] = (string)($karte['farbe'] ?? '');
                if ((string)($store['notes'][$i]['html'] ?? '') !== (string)($karte['html'] ?? '')) {
                    $store['notes'][$i]['html'] = (string)($karte['html'] ?? '');
                    $fehlt = true;
                }
                /* Anhaenge, die noch die rohe Kennung als Namen tragen, bekommen
                   den Klarnamen — ohne die Datei neu zu laden. Zuordnung ueber
                   die Reihenfolge: die Anhaenge sind in genau der Reihenfolge
                   angelegt worden, in der sie auf der Karte stehen. */
                $alteAtt = is_array($store['notes'][$i]['att'] ?? null) ? $store['notes'][$i]['att'] : [];
                $kartenNamen = array_values(array_map(
                    static fn(array $a): string => (string)$a['name'],
                    array_filter((array)$karte['anhaenge'],
                        fn(array $a): bool => $this->EduArt((string)($a['datei'] ?? $a['name'])) !== '')));
                if (count($alteAtt) === count($kartenNamen)) {
                    foreach ($alteAtt as $k => $a) {
                        if (preg_match('/^\d{6,}\./', (string)$a['name']) === 1
                            && $kartenNamen[$k] !== (string)$a['name']) {
                            $store['notes'][$i]['att'][$k]['name'] = $kartenNamen[$k];
                            $fehlt = true;
                        }
                    }
                }
                /* Vorschaubilder nachtragen: Anhaenge aus der Zeit davor haben
                   keine. Nur fuer PDF und nur, wenn die Karte eine Adresse
                   dafuer nennt — die Zuordnung wieder ueber die Reihenfolge. */
                $kartenDateien = array_values(array_filter((array)$karte['anhaenge'],
                    fn(array $a): bool => $this->EduArt((string)($a['datei'] ?? $a['name'])) !== ''));
                if (count($alteAtt) === count($kartenDateien)) {
                    foreach ($alteAtt as $k => $a) {
                        if ((string)($a['kind'] ?? '') !== 'pdf' || (int)($a['thumb'] ?? 0) > 0) {
                            continue;
                        }
                        $mini = $this->EduVorschau((string)($kartenDateien[$k]['preview'] ?? ''),
                            (string)$a['name']);
                        if ($mini > 0) {
                            $store['notes'][$i]['att'][$k]['thumb'] = $mini;
                            $fehlt = true;
                        }
                    }
                }
                // Der Text kann sich ebenfalls geaendert haben (Titelzeile raus).
                $neuerText = $this->EduNotizText($karte);
                if (mb_strlen($neuerText) <= self::NOTE_TEXT_MAX
                    && $neuerText !== (string)($store['notes'][$i]['text'] ?? '')) {
                    $store['notes'][$i]['text'] = $neuerText;
                    $fehlt = true;
                }
                if ((string)($store['notes'][$i]['folderId'] ?? '') !== $ordnerId) {
                    // Der Ordner je Karte ist neu — die vorhandenen Notizen ziehen um.
                    $fehlt = true;
                }
                if ($this->eduOrdnerGeaendert || $fehlt) {
                    $store['notes'][$i]['folderId'] = $ordnerId;
                    $store['notes'][$i]['section'] = $this->NotesTrim(
                        (string)($karte['abschnitt'] ?? ''), self::NOTE_TITLE_MAX);
                    $store['notes'][$i]['pos'] = $nr;
                    $this->NotesWriteStore($store);
                    $this->eduOrdnerGeaendert = false;
                }
                return false;
            }
            if ($i < 0 && count($store['notes']) >= self::NOTES_MAX) {
                $this->SendDebug('EduMaps', 'Notizgrenze erreicht — Karte nicht gespiegelt: ' . $karte['titel'], 0);
                return false;
            }

            $text = $this->EduNotizText($karte);
            if (mb_strlen($text) > self::NOTE_TEXT_MAX) {
                /* Gekuerzt wird SICHTBAR. Eine still gekappte Notiz waere
                   schlimmer als eine fehlende — man sieht ihr nicht an, dass
                   die Haelfte fehlt. */
                $text = mb_substr($text, 0, self::NOTE_TEXT_MAX - 40) . "\n\n… (gekürzt)";
            }
            $alteMedien = $i >= 0 ? $this->NotesAttachmentIds([$store['notes'][$i]]) : [];
            $anhaenge = $this->EduNotizAnhaenge($karte);

            $satz = [
                'id'        => $i >= 0 ? (string)$store['notes'][$i]['id'] : $this->NotesNewId(),
                'folderId'  => $ordnerId,
                'title'     => $this->NotesTrim((string)$karte['titel'], self::NOTE_TITLE_MAX),
                'text'      => $text,
                'att'       => $anhaenge,
                'createdAt' => $i >= 0 ? (int)($store['notes'][$i]['createdAt'] ?? $jetzt) : $jetzt,
                'updatedAt' => $jetzt,
                'source'    => 'edumaps',
                'srcId'     => $srcId,
                'srcRev'    => (int)$karte['updated'],
                /* Abschnitt und Platz auf der Seite: erst damit kann die App die
                   Karten so zeigen, wie sie auf der Klassenseite stehen. Ohne
                   sie waere es eine Liste nach Aenderungsdatum. */
                'section'   => $this->NotesTrim((string)($karte['abschnitt'] ?? ''), self::NOTE_TITLE_MAX),
                'pos'       => $nr,
                // Farben der Seite: Abschnitt und Karte, beide als #RRGGBB.
                'sectionColor' => (string)($karte['abschnittFarbe'] ?? ''),
                'color'     => (string)($karte['farbe'] ?? ''),
                // Formatierte Fassung fuer die Kartenansicht; der Klartext
                // daneben bleibt, er traegt Editor, Suche und KI-Auswertung.
                'html'      => (string)($karte['html'] ?? ''),
            ];
            if ($i >= 0) {
                $store['notes'][$i] = $satz;
            } else {
                $store['notes'][] = $satz;
            }
            if (!$this->NotesWriteStore($store)) {
                // Die eben angelegten Medien gehoeren jetzt niemandem.
                $this->NotesDeleteMedia(array_map(static fn(array $a): int => (int)$a['id'], $anhaenge));
                return false;
            }
            // ERST der Bestand, DANN die alten Medien — und nur, was keine andere
            // Notiz und kein offener Vorschlag mehr nennt.
            if ($alteMedien !== []) {
                $this->NotesDeleteMedia($this->NotesUnreferencedMedia($store, $alteMedien));
            }
            return true;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Das Vorschaubild eines PDF holen und ablegen.
     *
     * Es haengt AM ANHANG (`thumb`) und ist kein zweiter Anhang: sonst waere
     * der Deckel von fuenf Anhaengen je Notiz nach zwei PDF erreicht, und in
     * der Liste stuende jede Datei doppelt.
     *
     * @return int Medien-Kennung, 0 wenn es keine gibt
     */
    private function EduVorschau(string $url, string $name): int
    {
        if ($url === '') {
            return 0;
        }
        $antwort = $this->AiFetchPublicPage($url);
        if (($antwort['ok'] ?? false) !== true) {
            $this->SendDebug('EduMaps', 'Keine Vorschau zu ' . $name, 0);
            return 0;
        }
        // Derselbe Weg wie fuer jeden anderen Anhang: er prueft die Magic Bytes,
        // rechnet auf JPEG um und legt das Medienobjekt an.
        $r = $this->NotesSaveAttachment(base64_encode((string)($antwort['body'] ?? '')), 'vorschau.jpg');
        return ($r['ok'] ?? false) === true ? (int)$r['id'] : 0;
    }

    /**
     * Der Kartentext MIT seiner Formatierung — als sehr enge Auswahl an Tags.
     *
     * Der Klartext verliert, was die Karte ausmacht: die Klassenregeln sind auf
     * der Seite NUMMERIERT (`<ol class="list">`), Wichtiges steht fett. Nach
     * `strip_tags` stand dort eine Reihe gleichrangiger Zeilen.
     *
     * WEISSLISTE, kein Aufraeumen: erlaubt sind Absatz, Umbruch, Liste,
     * Aufzaehlung, fett, kursiv, unterstrichen und der Verweis. Alles andere
     * faellt weg, und von den Attributen ueberlebt nur `href` mit http, https
     * oder mailto. Das Ergebnis geht in der App durch `innerHTML` — deshalb
     * entscheidet DIESE Funktion ueber die Sicherheit, nicht der Browser.
     *
     * Die Dateizeilen fliegen raus: die Anhaenge stehen in der Karte ohnehin
     * darunter, mit Vorschau und Namen.
     */
    private function EduHtml(string $rumpf): string
    {
        if (!class_exists('DOMDocument')) {
            return '';
        }
        $doc = new \DOMDocument();
        // Ohne den Kopf haelt DOMDocument den Text fuer Latin-1 und macht aus
        // „für" ein „fÃ¼r". libxml meckert ueber jedes fremde Attribut — das
        // interessiert hier nicht, deshalb der Riegel davor.
        $vorher = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $rumpf . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        $wurzel = $doc->getElementsByTagName('div')->item(0);
        if ($wurzel === null) {
            return '';
        }
        $h = trim($this->EduHtmlKnoten($wurzel, false));
        $h = (string)preg_replace('#<p>(\s|&nbsp;|<br>)*</p>#i', '', $h);
        $h = trim((string)preg_replace('#\s+#u', ' ', $h));
        return mb_strlen($h) > self::EDU_HTML_MAX ? '' : $h;
    }

    /**
     * Ein Knoten und seine Kinder, auf die Weissliste reduziert.
     *
     * Die Zeilen der Seite (`li.itemline` in einer Huelle) sind KEINE
     * Aufzaehlung, sondern das Layout — sie werden zu Absaetzen. Eine echte
     * Liste erkennt man an `class="list"`; nur ihre `li` bleiben `li`. Ohne
     * diese Unterscheidung bekaeme jede Zeile der Karte einen Punkt.
     *
     * @param bool $inListe steht dieser Knoten in einer ECHTEN Liste?
     */
    private function EduHtmlKnoten(\DOMNode $knoten, bool $inListe): string
    {
        $raus = '';
        foreach ($knoten->childNodes as $kind) {
            if ($kind->nodeType === XML_TEXT_NODE) {
                $raus .= htmlspecialchars((string)$kind->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            if (!($kind instanceof \DOMElement)) {
                continue;
            }
            $tag = strtolower($kind->tagName);
            $klasse = strtolower((string)$kind->getAttribute('class'));
            // Dateiblöcke fallen ganz weg — sie stehen als Anhang unter der Karte.
            if (str_contains($kind->C14N(), '/file/')
                && ($tag === 'a' || str_contains($klasse, 'media') || str_contains($klasse, 'pdf'))) {
                continue;
            }
            switch ($tag) {
                case 'script': case 'style': case 'iframe': case 'img': case 'h3':
                    break;                                   // ohne Inhalt weiter
                case 'br':
                    $raus .= '<br>';
                    break;
                case 'b': case 'strong': case 'i': case 'em': case 'u':
                    $raus .= '<' . $tag . '>' . $this->EduHtmlKnoten($kind, $inListe) . '</' . $tag . '>';
                    break;
                case 'a':
                    $ziel = (string)$kind->getAttribute('href');
                    $inhalt = $this->EduHtmlKnoten($kind, $inListe);
                    $raus .= preg_match('#^(https?://|mailto:)#i', $ziel) === 1
                        ? '<a href="' . htmlspecialchars($ziel, ENT_QUOTES, 'UTF-8')
                          . '" target="_blank" rel="noopener noreferrer">' . $inhalt . '</a>'
                        : $inhalt;
                    break;
                case 'ol': case 'ul':
                    /* Nur die ECHTE Liste bleibt eine; die Zeilenhuelle nicht.
                       Die Klasse muss GENAU „list" heissen — mit str_contains
                       passte auch die Huelle („boxcontent-list"), und dann
                       bekam jede Zeile der Karte einen Punkt. */
                    if (preg_match('/(^|\s)list(\s|$)/', $klasse) === 1) {
                        $raus .= '<' . $tag . '>' . $this->EduHtmlKnoten($kind, true) . '</' . $tag . '>';
                    } else {
                        $raus .= $this->EduHtmlKnoten($kind, false);
                    }
                    break;
                case 'li':
                    $raus .= $inListe
                        ? '<li>' . $this->EduHtmlKnoten($kind, true) . '</li>'
                        : $this->EduHtmlKnoten($kind, false);
                    break;
                case 'p':
                    $raus .= '<p>' . $this->EduHtmlKnoten($kind, $inListe) . '</p>';
                    break;
                case 'div':
                    // Die Textzeile der Seite wird ein Absatz, jede andere Huelle
                    // reicht ihren Inhalt nur durch.
                    $raus .= str_contains($klasse, 'line-puretext')
                        ? '<p>' . $this->EduHtmlKnoten($kind, $inListe) . '</p>'
                        : $this->EduHtmlKnoten($kind, $inListe);
                    break;
                default:
                    $raus .= $this->EduHtmlKnoten($kind, $inListe);
            }
        }
        return $raus;
    }


    /**
     * Der Kartentext, wie er in der Notiz stehen soll.
     *
     * Zwei Zeilen muessen raus, die auf der Karte selbst richtig sind, in der
     * Notiz aber doppelt: der TITEL (er steht schon als Titel darueber) und
     * die blossen DATEINAMEN (sie stehen als Anhang darunter). Gemessen an der
     * Karte „Regeln": Text begann mit „Regeln", dann „Unsere Schulregeln
     * TER.pdf", dann „Unsere Schulregeln.pdf" — dreimal dasselbe im Blick.
     */
    private function EduNotizText(array $karte): string
    {
        $namen = [];
        foreach ((array)$karte['anhaenge'] as $a) {
            $namen[mb_strtolower(trim((string)$a['name']))] = true;
        }
        $titel = mb_strtolower(trim((string)$karte['titel']));
        $zeilen = [];
        $ersteWeg = false;
        foreach (explode("\n", (string)$karte['text']) as $zeile) {
            $roh = trim($zeile);
            $klein = mb_strtolower($roh);
            if (!$ersteWeg && $klein === $titel) {
                $ersteWeg = true;   // nur die ERSTE Zeile, nicht jede Wiederholung
                continue;
            }
            if ($roh !== '' && isset($namen[$klein])) {
                continue;
            }
            $zeilen[] = $zeile;
        }
        return trim(implode("\n", $zeilen));
    }

    /**
     * Der Ordner dieses Kindes, angelegt falls noetig. Erkannt wird er an der
     * Mitgliedskennung im Datensatz, nicht am Namen: der Nutzer darf ihn
     * umbenennen, ohne dass beim naechsten Lauf ein zweiter entsteht.
     *
     * @param array<string,mixed> $store wird bei Bedarf ergaenzt (noch nicht geschrieben)
     */
    private function EduNotizOrdner(array &$store, array $seite): string
    {
        $userId = trim((string)($seite['userId'] ?? ''));
        $schluessel = 'edu:' . ($userId !== '' ? $userId : md5((string)$seite['url']));
        $mitgliedsOrdner = '';
        foreach ($store['folders'] as $f) {
            if ($userId !== '' && (string)($f['memberId'] ?? '') === $userId) {
                $mitgliedsOrdner = (string)$f['id'];
                break;
            }
        }
        // ── 1. Ebene: der Ordner „Edumaps" beim Kind ──
        $edu = '';
        foreach ($store['folders'] as $k => $f) {
            if ((string)($f['eduKey'] ?? '') !== $schluessel) {
                continue;
            }
            /* Nachziehen: der Ordner ist vor der Verschachtelung entstanden und
               liegt noch oben. Einmal in den Kindordner heben — der Name bleibt,
               den darf der Nutzer selbst aendern. */
            if ((string)($f['parentId'] ?? '') === '' && $mitgliedsOrdner !== ''
                && $mitgliedsOrdner !== (string)$f['id']) {
                $store['folders'][$k]['parentId'] = $mitgliedsOrdner;
                $store['folders'][$k]['updatedAt'] = time();
                $this->eduOrdnerGeaendert = true;
                $this->SendDebug('EduMaps', 'Edumaps-Ordner in den Kindordner verschoben', 0);
            }
            $edu = (string)$f['id'];
            break;
        }
        if ($edu === '') {
            if (count($store['folders']) >= self::NOTES_FOLDERS_MAX) {
                $this->SendDebug('EduMaps', 'Ordnergrenze erreicht — kein Edumaps-Ordner angelegt', 0);
                return '';
            }
            /* IM Ordner des Kindes, wenn es einen hat — dann heisst er einfach
               „Edumaps", der Name des Kindes steht schon darueber. Ohne
               Mitglieder-Ordner liegt er oben und traegt den Namen mit. */
            $name = 'Edumaps';
            if ($mitgliedsOrdner === '') {
                foreach ($this->LoadUsers() as $u) {
                    if ((string)($u['id'] ?? '') === $userId && trim((string)($u['name'] ?? '')) !== '') {
                        $name = 'Edumaps ' . trim((string)$u['name']);
                        break;
                    }
                }
            }
            $edu = $this->EduOrdnerAnlegen($store, $name, $mitgliedsOrdner, $schluessel);
        }

        /* ── 2. Ebene: je KARTE ein eigener Ordner ──
           Eine Klassenseite und ein Nachschlagewerk gehoeren nicht in denselben
           Topf; mit zwei Karten laegen sonst vierunddreissig Notizen
           durcheinander. Der Schluessel haengt an der ADRESSE, nicht am Namen:
           beide darf der Nutzer aendern, die Adresse nicht. */
        $seiteSchluessel = 'edupage:' . md5((string)$seite['url']);
        foreach ($store['folders'] as $f) {
            if ((string)($f['eduKey'] ?? '') === $seiteSchluessel) {
                return (string)$f['id'];
            }
        }
        if ($edu === '' || count($store['folders']) >= self::NOTES_FOLDERS_MAX) {
            return $edu;
        }
        return $this->EduOrdnerAnlegen($store, (string)($seite['name'] ?? 'Karte'), $edu, $seiteSchluessel);
    }

    /**
     * Einen Ordner anlegen und merken, dass der Bestand geschrieben werden muss.
     *
     * @param array<string,mixed> $store wird ergaenzt (noch nicht geschrieben)
     */
    private function EduOrdnerAnlegen(array &$store, string $name, string $eltern, string $schluessel): string
    {
        $jetzt = time();
        $ordner = ['id' => $this->NotesNewId(), 'name' => $this->NotesTrim($name, self::NOTE_FOLDER_NAME_MAX),
                   'memberId' => '', 'parentId' => $eltern, 'eduKey' => $schluessel,
                   'createdAt' => $jetzt, 'updatedAt' => $jetzt];
        $store['folders'][] = $ordner;
        $this->eduOrdnerGeaendert = true;
        return (string)$ordner['id'];
    }

    /**
     * Die Dateien der Karte als Notiz-Anhaenge. Bilder und PDF, mehr kann die
     * Notiz nicht halten; der Deckel je Notiz gilt auch hier.
     *
     * @return list<array{id:int,kind:string,name:string,bytes:int}>
     */
    private function EduNotizAnhaenge(array $karte): array
    {
        $raus = [];
        $speicherVorher = (string)@ini_get('memory_limit');
        @ini_set('memory_limit', '192M');
        try {
            foreach ((array)$karte['anhaenge'] as $a) {
                if (count($raus) >= self::NOTE_ATTACH_MAX) {
                    $this->SendDebug('EduMaps', 'Mehr als ' . self::NOTE_ATTACH_MAX
                        . ' Dateien an der Karte — die weiteren bleiben in der Notiz weg: ' . $karte['titel'], 0);
                    break;
                }
                if ($this->EduArt((string)($a['datei'] ?? $a['name'])) === '') {
                    continue;
                }
                $antwort = $this->AiFetchPublicPage((string)$a['url']);
                if (($antwort['ok'] ?? false) !== true) {
                    $this->SendDebug('EduMaps', 'Datei nicht ladbar: ' . $a['name'], 0);
                    continue;
                }
                $r = $this->NotesSaveAttachment(base64_encode((string)($antwort['body'] ?? '')), (string)$a['name']);
                if (($r['ok'] ?? false) !== true) {
                    $this->SendDebug('EduMaps', 'Datei nicht ablegbar (' . (string)($r['error']['code'] ?? '?')
                        . '): ' . $a['name'], 0);
                    continue;
                }
                $anhang = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'],
                           'name' => (string)$r['name'], 'bytes' => (int)$r['bytes']];
                if ($anhang['kind'] === 'pdf') {
                    $mini = $this->EduVorschau((string)($a['preview'] ?? ''), (string)$a['name']);
                    if ($mini > 0) {
                        $anhang['thumb'] = $mini;
                    }
                }
                $raus[] = $anhang;
            }
        } finally {
            @ini_set('memory_limit', $speicherVorher);
        }
        return $raus;
    }

    private function EduKarteAnalysieren(array $seite, array $karte): bool
    {
        $betreff = trim((string)$karte['abschnitt']) !== ''
            ? $karte['abschnitt'] . ' · ' . $karte['titel']
            : (string)$karte['titel'];
        $text = (string)$karte['text'];
        if (mb_strlen($text) > self::EDU_TEXT_MAX) {
            $text = mb_substr($text, 0, self::EDU_TEXT_MAX);
        }
        /* „Date" ist eine UNIX-ZEIT, kein Text: MailAnalyseRecord macht daraus
           mit (int) das Feld `at`, und danach richtet sich die 21-Tage-Grenze
           der Vorschlagsliste. Ein ISO-Text ergab dort 2026 — also Januar 1970,
           und der Vorschlag war sofort „zu alt" und unsichtbar. Genau so
           gemessen, bevor diese Zeile stand.
           Absender: der Name der Seite steht als Absendername, damit in der App
           „5a Joshua" statt eines Fragezeichens erscheint. */
        $kopf = [
            'Subject'    => $seite['name'] . ' — ' . $betreff,
            'SenderName' => $seite['name'],
            'Date'       => (int)$karte['updated'],
        ];
        return $this->MailAnalyseRecord(
            'edu:' . $karte['boxid'] . ':' . $karte['updated'],
            $kopf,
            $text,
            $this->EduAnhaenge($karte),
            (string)$seite['userId'],
            'Edumaps'
        );
    }

    /**
     * Anhaenge einer Karte holen und base64 kodieren — mit Gesamtdeckel, wie es
     * der Webhook-Weg tut. Ein Stundenplan-PDF ist gross.
     *
     * @param array<string,mixed> $karte
     * @return list<array{kind:string,name:string,base64:string}>
     */
    private function EduAnhaenge(array $karte): array
    {
        $raus = [];
        $summe = 0;
        $speicherVorher = (string)@ini_get('memory_limit');
        @ini_set('memory_limit', '192M');
        try {
            foreach ((array)$karte['anhaenge'] as $a) {
                $art = $this->EduArt((string)($a['datei'] ?? $a['name']));
                if ($art === '') {
                    continue;   // weder Bild noch PDF — die KI kann damit nichts
                }
                /* Derselbe SSRF-sichere Abruf wie fuer die Seite — er deckelt
                   bei 2 MB und BRICHT dann ab, statt abzuschneiden. Ein
                   groesseres PDF faellt hier also heraus; die Karte wird
                   trotzdem analysiert, nur eben ohne Anhang. Das ist besser als
                   ein halbes PDF an die KI zu schicken. */
                $antwort = $this->AiFetchPublicPage((string)$a['url']);
                if (($antwort['ok'] ?? false) !== true) {
                    $this->SendDebug('EduMaps', 'Anhang uebersprungen (zu gross oder nicht ladbar): ' . $a['name'], 0);
                    continue;
                }
                $base64 = base64_encode((string)($antwort['body'] ?? ''));
                if ($base64 === '' || $summe + strlen($base64) > self::EDU_ANHANG_MAX_B64) {
                    continue;
                }
                $summe += strlen($base64);
                $raus[] = ['kind' => $art, 'name' => (string)$a['name'], 'base64' => $base64];
            }
        } finally {
            @ini_set('memory_limit', $speicherVorher);
        }
        return $raus;
    }

    /** Bild oder PDF? Alles andere geht die KI nichts an. */
    private function EduArt(string $name): string
    {
        $endung = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if ($endung === 'pdf') {
            return 'pdf';
        }
        return in_array($endung, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) ? 'image' : '';
    }

    // ──────────────────────────────── Zerleger ────────────────────────────────

    /**
     * Die Karten einer Edumaps-Seite.
     *
     * Bewusst mit Ausdruecken statt DOM: die Seite ist gross, wir brauchen nur
     * fuenf Angaben je Karte, und die Anker sind eindeutig
     * (`data-boxid`, `data-updated`, `h3.boxlabel`, `.boxmaterial-wrap`).
     *
     * @return list<array{boxid:string,updated:int,abschnitt:string,titel:string,text:string,anhaenge:list<array{name:string,url:string}>}>
     */
    private function EduKarten(string $html): array
    {
        if ($html === '') {
            return [];
        }
        /* Abschnittsnamen stehen in der Spalte UEBER den Karten. Ihre Position im
           Text entscheidet, welche Karte dazugehoert. */
        /* Der Spaltenkopf traegt seine Farbe als Inline-Stil:
           <h2 class="pathhead" style="background:#FF851B;"><span class="pathlabel">…
           Sie kommt mit, damit die App die Abschnitte so faerben kann wie die
           Seite selbst — sonst waeren alle Bereiche gleich grau. */
        $spalten = [];
        if (preg_match_all('/<h2[^>]*class="pathhead"[^>]*>\s*<span class="pathlabel">(.*?)<\/span>/su',
                $html, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[1] as $k => $treffer) {
                $kopf = (string)$m[0][$k][0];
                $farbe = preg_match('/background:\s*(#[0-9A-Fa-f]{6})/', $kopf, $f) === 1 ? strtoupper($f[1]) : '';
                $spalten[] = ['pos' => (int)$treffer[1], 'name' => $this->EduText((string)$treffer[0]),
                              'farbe' => $farbe];
            }
        }
        $karten = [];
        $teile = preg_split('/(?=<div class="box-item")/s', $html);
        foreach ((array)$teile as $teil) {
            if (!is_string($teil) || !str_contains($teil, 'data-boxid=')) {
                continue;
            }
            if (preg_match('/data-boxid="(\d+)"/', $teil, $b) !== 1) {
                continue;
            }
            $boxid = $b[1];
            /* `data-updated` ist LEER, solange eine Karte seit dem Anlegen nicht
               angefasst wurde — auf der Probeseite bei drei von fuenfzehn
               (Klassenkasse, Klassenfahrt, Hausaufgabenbetreuung). Dann gilt
               das Anlegedatum. Ohne diesen Rueckfall trugen alle drei die
               gleiche 0 und waeren am Merker nicht auseinanderzuhalten. */
            $updated = 0;
            if (preg_match('/data-updated="(\d+)"/', $teil, $u) === 1) {
                $updated = (int)$u[1];
            } elseif (preg_match('/data-created="(\d+)"/', $teil, $c) === 1) {
                $updated = (int)$c[1];
            }
            $titel = preg_match('/<h3[^>]*class="[^"]*boxlabel[^"]*"[^>]*>(.*?)<\/h3>/su', $teil, $t) === 1
                ? $this->EduText($t[1]) : '';
            if ($titel === '') {
                continue;
            }
            // Der Rumpf endet an der Fusszeile der Karte; alles danach ist Beiwerk.
            $rumpf = $teil;
            $ende = strpos($rumpf, '<div class="box-meta-wrap"');
            if ($ende !== false) {
                $rumpf = substr($rumpf, 0, $ende);
            }
            $anhaenge = [];
            foreach ($this->EduDateien($rumpf) as $datei) {
                $anhaenge[] = $datei;
            }
            $pos = strpos($html, 'data-boxid="' . $boxid . '"');
            $abschnitt = '';
            $abschnittFarbe = '';
            foreach ($spalten as $sp) {
                if ($pos !== false && $sp['pos'] < $pos) {
                    $abschnitt = $sp['name'];
                    $abschnittFarbe = (string)$sp['farbe'];
                }
            }
            /* Auch die Karte selbst hat eine Farbe („boxlabel customcolor").
               Meist ist es die der Spalte, aber nicht immer — genommen wird die
               ERSTE im Kartenkopf, also vor dem Inhalt. */
            $kopfTeil = ($e = strpos($teil, 'boxcontent-wrap')) !== false ? substr($teil, 0, $e) : $teil;
            $eigeneFarbe = preg_match('/background:\s*(#[0-9A-Fa-f]{6})/', $kopfTeil, $ff) === 1
                ? strtoupper($ff[1]) : '';
            $karten[] = [
                'boxid'     => $boxid,
                'updated'   => $updated,
                'html'      => $this->EduHtml($rumpf),
                'abschnitt' => $abschnitt,
                'farbe'     => $eigeneFarbe !== '' ? $eigeneFarbe : $abschnittFarbe,
                'abschnittFarbe' => $abschnittFarbe,
                'titel'     => $titel,
                'text'      => $this->EduText($rumpf),
                'anhaenge'  => $anhaenge,
            ];
            if (count($karten) >= self::EDU_KARTEN_MAX) {
                break;
            }
        }
        return $karten;
    }

    /**
     * Die Dateien einer Karte. Edumaps bietet dieselbe Datei dreifach an
     * (Anzeige, `/preview`, `/fd`) — genommen wird die nackte Adresse, und jede
     * Datei nur EINMAL.
     *
     * @return list<array{name:string,url:string}>
     */
    private function EduDateien(string $rumpf): array
    {
        /* Der SICHTBARE Name steht nicht in der Adresse — die traegt nur eine
           Zahl —, sondern im Etikett unter der Vorschau:
           <span class="medialabel"><a href="…/file/<id>/<token>">Regeln.pdf</a></span>.
           Ohne ihn hiess die Datei in der App „2284251677130317565.pdf". */
        $namen = [];
        if (preg_match_all('#<span class="medialabel">\s*<a[^>]*/file/([^"\'/]+)/([a-z0-9]+)[^>]*>(.*?)</a>#su',
                $rumpf, $mn, PREG_SET_ORDER) > 0) {
            foreach ($mn as $t) {
                $klar = $this->EduText($t[3]);
                if ($klar !== '') {
                    $namen[$t[1] . '/' . $t[2]] = $klar;
                }
            }
        }
        $raus = [];
        $gesehen = [];
        if (preg_match_all('#https://[^"\']+/file/([^"\'/]+)/([a-z0-9]+)(?:/(?:preview|fd))?#i',
                $rumpf, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $treffer) {
                $name = urldecode((string)$treffer[1]);
                $schluessel = $name . '/' . $treffer[2];
                if (isset($gesehen[$schluessel])) {
                    continue;
                }
                $gesehen[$schluessel] = true;
                // „/fd" liefert die Datei mit Dateinamen; die nackte Adresse
                // liefert eine Ansichtsseite.
                $raus[] = [/* Anzeigename fuer den Menschen … */
                           'name' => $namen[$schluessel] ?? $name,
                           /* … und der technische aus der Adresse. NUR er traegt
                              die Endung: das Etikett der Seite kann jeder Text
                              sein („Einladung Pflegschaftssitzung 1. Hj"), und
                              wer daraus den Dateityp ableitet, haelt ein PDF
                              fuer nichts und laesst es weg. Genau so ist die
                              Einladung ohne Vorschau und ohne Namen geblieben. */
                           'datei' => $name,
                           'url'  => 'https://nrw.edumaps.de/file/' . $treffer[1] . '/' . $treffer[2] . '/fd',
                           /* edumaps rendert von jedem PDF eine Seitenvorschau
                              und liefert sie unter „/preview" (gemessen:
                              180×255 JPEG, rund 11 KB). Selbst rendern koennten
                              wir sie nicht — dafuer fehlt in Symcon ein
                              PDF-Renderer. */
                           'preview' => 'https://nrw.edumaps.de/file/' . $treffer[1] . '/' . $treffer[2] . '/preview'];
            }
        }
        return $raus;
    }

    /** Markup zu lesbarem Text: Umbrueche erhalten, Entities aufloesen. */
    private function EduText(string $html): string
    {
        $t = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/su', ' ', $html) ?? $html;
        $t = preg_replace('/<br\s*\/?>|<\/(p|div|li|tr|h[1-6])>/i', "\n", $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);
        $t = preg_replace('/[ \t]+/u', ' ', $t) ?? $t;
        // Mehr als eine Leerzeile bringt nichts und kostet Tokens.
        $t = preg_replace('/\n\s*\n\s*\n+/u', "\n\n", $t) ?? $t;
        return trim($t);
    }

    // ─────────────────────────────── Kleinkram ───────────────────────────────

    /** @return list<array{name:string,url:string,userId:string}> */
    /**
     * Verweise auf ANDERE Karten derselben Anlage.
     *
     * Eine Adresse hat vier Abschnitte („/176181/94492/qmz5o2s2ctwn/yfq8mzyd…").
     * Dateien (`/file/…`) und die eigene Seite bleiben draussen.
     *
     * @return list<string>
     */
    private function EduKartenLinks(string $html, string $eigene): array
    {
        if (preg_match_all('#https://nrw\.edumaps\.de/(\d+)/(\d+)/([a-z0-9]+)/([a-z0-9]+)#i',
                $html, $m, PREG_SET_ORDER) === 0) {
            return [];
        }
        $raus = [];
        foreach ($m as $t) {
            $url = rtrim($t[0], '/');
            if ($url !== rtrim($eigene, '/') && !isset($raus[$url])) {
                $raus[$url] = true;
            }
        }
        return array_keys($raus);
    }

    /**
     * Gefundene Karten: die vom Nutzer eingetragenen bleiben unberuehrt, diese
     * hier stehen im Attribut. So kann der Nutzer sie im Formular sehen und
     * vergessen lassen, ohne dass das Modul in seine Liste schreibt — ein
     * IPS_ApplyChanges auf die EIGENE Instanz mitten im Lauf waere ein Griff
     * ins eigene Getriebe (Timer, Formular, Wiedereintritt).
     *
     * @return list<array{name:string,url:string,userId:string,von:string}>
     */
    private function EduGefundene(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('EduFound'), true);
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $z) {
            if (!is_array($z) || !str_starts_with((string)($z['url'] ?? ''), 'https://')) {
                continue;
            }
            $raus[] = ['name' => (string)($z['name'] ?? ''), 'url' => (string)$z['url'],
                       'userId' => (string)($z['userId'] ?? ''), 'von' => (string)($z['von'] ?? '')];
        }
        return $raus;
    }

    /**
     * Neue Verweise einer Seite aufnehmen. Nur EINE Ebene tief: die gefundenen
     * Karten werden selbst nicht mehr durchsucht, sonst zoege eine Verweiskette
     * das halbe Netz herein.
     */
    private function EduGefundeneErgaenzen(array $seite, string $html): int
    {
        if (!(bool)$this->EduProp('EduFollowLinks', false)) {
            return 0;
        }
        $bekannt = [];
        foreach (array_merge($this->EduSeiten(), $this->EduGefundene()) as $s) {
            $bekannt[rtrim((string)$s['url'], '/')] = true;
        }
        $liste = $this->EduGefundene();
        $neu = 0;
        foreach ($this->EduKartenLinks($html, (string)$seite['url']) as $url) {
            if (isset($bekannt[$url]) || count($liste) >= self::EDU_GEFUNDEN_MAX) {
                continue;
            }
            $antwort = $this->AiFetchPublicPage($url);
            if (($antwort['ok'] ?? false) !== true) {
                continue;
            }
            // Der Name steht im Seitentitel: „Englisch Grammatik - Edumaps".
            $name = '';
            if (preg_match('#<title>(.*?)</title>#su', (string)($antwort['body'] ?? ''), $t) === 1) {
                $name = trim((string)preg_replace('/\s*[-–]\s*Edumaps\s*$/ui', '', $this->EduText($t[1])));
            }
            $liste[] = ['name' => $name !== '' ? $name : $this->Translate('Linked map'),
                        'url' => $url, 'userId' => (string)$seite['userId'], 'von' => (string)$seite['name']];
            $bekannt[$url] = true;
            $neu++;
        }
        if ($neu > 0) {
            @$this->WriteAttributeString('EduFound', (string)json_encode($liste, JSON_UNESCAPED_UNICODE));
        }
        return $neu;
    }

    private function EduSeiten(): array
    {
        $roh = json_decode((string)$this->EduProp('EduPages', '[]'), true);
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $z) {
            if (!is_array($z)) {
                continue;
            }
            $url = trim((string)($z['url'] ?? ''));
            if (!str_starts_with($url, 'https://')) {
                continue;
            }
            $name = trim((string)($z['name'] ?? ''));
            $raus[] = [
                'name'   => $name !== '' ? $name : $this->Translate('Class page'),
                'url'    => $url,
                'userId' => trim((string)($z['userId'] ?? '')),
            ];
        }
        return $raus;
    }

    private function EduIsEnabled(): bool
    {
        /* Dieselben Riegel wie beim Mailweg: die KI muss an sein und die
           Einwilligung vorliegen — die Karten gehen zum Anbieter. */
        return (bool)$this->EduProp('EduEnabled', false)
            && (bool)$this->EduProp('AiEnabled', false)
            && $this->AiPrivacyAccepted();
    }

    private function EduProp(string $name, mixed $vorgabe): mixed
    {
        if ($this->eduConfigCache === null) {
            $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
            $this->eduConfigCache = is_array($cfg) ? $cfg : [];
        }
        return array_key_exists($name, $this->eduConfigCache)
            ? $this->eduConfigCache[$name] : $vorgabe;
    }

    /** @return array<string,list<string>> */
    private function EduSeenKarte(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('EduSeen'), true);
        return is_array($roh) ? $roh : [];
    }

    private function EduTopfHatEintraege(string $topf): bool
    {
        $karte = $this->EduSeenKarte();
        return is_array($karte[$topf] ?? null) && $karte[$topf] !== [];
    }

    private function EduGesehen(string $topf, string $schluessel): bool
    {
        $karte = $this->EduSeenKarte();
        return in_array($schluessel, array_map('strval', (array)($karte[$topf] ?? [])), true);
    }

    private function EduMerken(string $topf, string $schluessel): void
    {
        $karte = $this->EduSeenKarte();
        $liste = array_map('strval', (array)($karte[$topf] ?? []));
        $liste[] = $schluessel;
        /* Gedeckelt je Seite: eine Karte, die sich staendig aendert, darf den
           Merker nicht aufblaehen. Zwoelf Karten mal ein paar Aenderungen
           passen bequem in 200. */
        if (count($liste) > 200) {
            $liste = array_slice($liste, -200);
        }
        $karte[$topf] = array_values(array_unique($liste));
        @$this->WriteAttributeString('EduSeen', (string)json_encode($karte, JSON_UNESCAPED_UNICODE));
    }
}
