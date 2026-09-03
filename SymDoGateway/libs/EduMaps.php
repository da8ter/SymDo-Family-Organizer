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

    /** Gesamtgroesse der Anhaenge je Karte, base64 (wie beim Webhook-Weg). */
    private const EDU_ANHANG_MAX_B64 = 8000000;

    /** Wie oft nachgesehen wird, wenn die Property noch nicht existiert. */
    private const EDU_INTERVALL_STD = 6;

    /** Hoechstens so viele Analysen je Lauf — der Rest kommt beim naechsten. */
    private const EDU_JE_LAUF_MAX = 5;

    private ?array $eduConfigCache = null;

    /** Setzt EduNotizOrdner, wenn es den Bestand angefasst hat (Ordner angelegt
     *  oder nachtraeglich in den Kindordner gehoben). Dann muss geschrieben
     *  werden, auch wenn an der Notiz selbst nichts zu tun war. */
    private bool $eduOrdnerGeaendert = false;

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
        /* Eigener Merker, NICHT MailSeenUIDs: die Toepfe dort sind auf 500
           Eintraege gedeckelt und werden vom Mailweg beschrieben. Eine
           Klassenseite mit zwoelf Karten braucht ihren eigenen Platz. */
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
        $karten = 0;
        $geaendert = 0;
        $analysiert = 0;
        $gespiegelt = 0;
        $fehler = [];
        foreach ($seiten as $seite) {
            $erg = $this->EduSeiteLesen($seite, $analysiert, $alles);
            $karten     += $erg['karten'];
            $geaendert  += $erg['geaendert'];
            $analysiert += $erg['analysiert'];
            $gespiegelt += (int)($erg['gespiegelt'] ?? 0);
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
        $karten = $this->EduKarten((string)($antwort['body'] ?? ''));
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
        foreach ($karten as $karte) {
            /* Spiegeln zuerst und fuer JEDE Karte: es kostet keinen KI-Aufruf,
               haengt also an keinem Deckel und auch nicht daran, ob die Karte
               als „geaendert" gilt. Die Notiz selbst entscheidet, ob es etwas
               zu tun gibt (srcRev). */
            if ($this->EduNotizSpiegeln($seite, $karte)) {
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
            if ($this->MailDayLimitReached()) {
                $this->SendDebug('EduMaps', 'Tagesdeckel erreicht — Rest beim naechsten Lauf', 0);
                $gedeckelt = true;
                break;
            }
            if (!$alles && $schonAnalysiert + $analysiert >= self::EDU_JE_LAUF_MAX) {
                $this->SendDebug('EduMaps', 'Deckel je Lauf erreicht — Rest beim naechsten Lauf', 0);
                break;
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
    private function EduNotizSpiegeln(array $seite, array $karte): bool
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
                if ($this->eduOrdnerGeaendert) {
                    $store['notes'][$i]['folderId'] = $ordnerId;
                    $this->NotesWriteStore($store);
                    $this->eduOrdnerGeaendert = false;
                }
                return false;
            }
            if ($i < 0 && count($store['notes']) >= self::NOTES_MAX) {
                $this->SendDebug('EduMaps', 'Notizgrenze erreicht — Karte nicht gespiegelt: ' . $karte['titel'], 0);
                return false;
            }

            $text = (string)$karte['text'];
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
            return (string)$f['id'];
        }
        if (count($store['folders']) >= self::NOTES_FOLDERS_MAX) {
            $this->SendDebug('EduMaps', 'Ordnergrenze erreicht — kein Edumaps-Ordner angelegt', 0);
            return '';
        }
        /* IM Ordner des Kindes, wenn es einen hat — dann heisst er einfach
           „Edumaps", der Name des Kindes steht schon darueber. Ohne
           Mitglieder-Ordner liegt er oben und traegt den Namen mit. */
        $eltern = $mitgliedsOrdner;
        $name = 'Edumaps';
        if ($eltern === '') {
            foreach ($this->LoadUsers() as $u) {
                if ((string)($u['id'] ?? '') === $userId && trim((string)($u['name'] ?? '')) !== '') {
                    $name = 'Edumaps ' . trim((string)$u['name']);
                    break;
                }
            }
            if ($name === 'Edumaps' && trim((string)($seite['name'] ?? '')) !== '') {
                // Ohne Mitglied: der Seitenname ist besser als nichts.
                $name = 'Edumaps ' . trim((string)$seite['name']);
            }
        }
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
                if ($this->EduArt((string)$a['name']) === '') {
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
                $raus[] = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'],
                           'name' => (string)$r['name'], 'bytes' => (int)$r['bytes']];
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
                $art = $this->EduArt((string)$a['name']);
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
        $spalten = [];
        if (preg_match_all('/<span class="pathlabel">(.*?)<\/span>/su', $html, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[1] as $treffer) {
                $spalten[] = ['pos' => (int)$treffer[1], 'name' => $this->EduText((string)$treffer[0])];
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
            foreach ($spalten as $sp) {
                if ($pos !== false && $sp['pos'] < $pos) {
                    $abschnitt = $sp['name'];
                }
            }
            $karten[] = [
                'boxid'     => $boxid,
                'updated'   => $updated,
                'abschnitt' => $abschnitt,
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
                $raus[] = ['name' => $name,
                           'url'  => 'https://nrw.edumaps.de/file/' . $treffer[1] . '/' . $treffer[2] . '/fd'];
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
