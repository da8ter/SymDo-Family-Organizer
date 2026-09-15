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

    /** Zeichen je Karte, die an die KI gehen (wie MAIL_TEXT_MAX, nur kleiner). */
    private const EDU_TEXT_MAX = 8000;

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

    /* Obergrenze fuer den QR-Leser, in Megapixel. Gemessen: rund 25 MB Speicher
       je Megapixel, also gut 150 MB an dieser Grenze — das passt unter die
       192 MB, die EduQrCode fuer die Dauer des Lesens setzt. Groessere Bilder
       sind in Klassenseiten-Anhaengen ohnehin die Ausnahme, und ein gedruckter
       QR-Code ist auch verkleinert noch lesbar. */
    private const EDU_QR_MP_MAX = 6.0;

    private ?array $eduConfigCache = null;

    /** Setzt EduNotizOrdner, wenn es den Bestand angefasst hat (Ordner angelegt
     *  oder nachtraeglich in den Kindordner gehoben). Dann muss geschrieben
     *  werden, auch wenn an der Notiz selbst nichts zu tun war. */
    private bool $eduOrdnerGeaendert = false;

    /** Die Meldungen EINES Durchgangs, je Familienmitglied: aktualisierte Karten,
     *  neue Vorschlaege und die Namen der beteiligten Seiten. Null heisst, dass
     *  gerade kein Durchgang laeuft. */
    private ?array $eduPushSammlung = null;

    /** Adressen ANDERER Anlagen, die in QR-Codes einer Seite steckten. Gesammelt
     *  waehrend die Karten laufen und danach aufgenommen — mitten im Spiegeln
     *  aufzunehmen hiesse, je Karte die Fundliste zu lesen und zu schreiben.
     *  @var list<string> */
    private array $eduQrSeiten = [];

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
        /* Die Sammelmeldung eines Laufs, ueber die Auftraege hinweg.
           Sie MUSS auf der Platte liegen: jeder fertige Auftrag kommt in einem
           eigenen RequestAction an, also in einem eigenen Objekt — ein Feld
           ueberlebte das nicht. Ohne sie kaeme je ausgewerteter Karte eine
           Meldung, und die Ratenbremse je Geraet verschluckte den Rest. */
        $this->RegisterAttributeString('EduPushOffen', '{}');
        $this->RegisterTimer('EduScan', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'EduScan\', 0);');
    }

    private function EduApplyChanges(): void
    {
        /* Der Umzug der Karten aus den Notizen — einmal, gemerkt im Bestand.
           Er steht hier und nicht im Timer, damit er nach dem Modul-Update beim
           ersten Übernehmen läuft und nicht erst Stunden später.

           WICHTIG für die Reihenfolge in module.php: der Medien-Aufräumer läuft
           weiter unten in NotesApplyChanges() und muss BEIDE Bestände kennen,
           bevor hier etwas umzieht — sonst sieht er die Anhänge der Karten als
           Waisen. Deshalb wurde er in einem früheren Commit erweitert. */
        $this->EduMigrate();
        $stunden = (int)$this->EduProp('EduIntervalHours', self::EDU_INTERVALL_STD);
        $an = $this->EduIsEnabled() && $this->EduSeiten() !== [];
        @$this->SetTimerInterval('EduScan', $an ? max(1, $stunden) * 3600000 : 0);
    }

    private function EduRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident === 'EduScan') {
            /* Bedient ein Scanner die Klassenseiten, wird hier nur noch ein
               AUFTRAG abgelegt — sonst holte jeder Lauf die Seiten zweimal und
               beide Haelften schrieben gegeneinander in denselben Bestand.

               Der Zeitgeber bleibt dabei AN, und das ist wichtiger, als es
               aussieht: er ist die einzige Uhr dieses Laufs. Der Scanner hat
               keine eigene — er arbeitet ab, was im Kanal liegt, und ohne
               diesen Schlag legte niemand je etwas hinein. Ihn abzuschalten
               hiesse, die Klassenseiten nach genau einem Lauf einschlafen zu
               lassen (bis zum naechsten Uebernehmen, das ihn wieder stellt).
               Das Auftraggeben selbst kostet Millisekunden: eine Datei und ein
               Zaehler. */
            if ($this->ScanQuelleUebernommen('edu')) {
                $this->EduAuftragGeben('timer');
                return true;
            }
            $this->EduScanRun();
            return true;
        }
        if ($Ident === 'EduForgetFound') {
            @$this->WriteAttributeString('EduFound', '[]');
            $this->UpdateFormField('EduStatusLabel', 'caption',
                $this->Translate('Linked pages forgotten — the next check finds them again.'));
            return true;
        }
        if ($Ident === 'EduScanAll') {
            // Alles auswerten, auch schon Gesehenes. Teuer, darum ein eigener Knopf.
            $this->UpdateFormField('EduStatusLabel', 'caption',
                $this->EduVonHand(true));
            return true;
        }
        if ($Ident === 'EduScanNow') {
            $this->UpdateFormField('EduStatusLabel', 'caption',
                $this->EduVonHand(false));
            return true;
        }
        if ($Ident === 'EduMigrateNow') {
            /* Der Umzug der Karten aus den Notizen von Hand. Er laeuft ohnehin
               beim Uebernehmen, aber dort sieht man seinen Bericht nicht — und
               genau der sagt, ob er etwas getan hat, ob er auf einen
               Kernel-Neustart wartet oder ob es nichts zu holen gab. */
            $bericht = $this->EduMigrate();
            $this->UpdateFormField('EduStatusLabel', 'caption',
                $bericht !== '' ? $bericht : $this->Translate('Nothing to move — the cards are already in their own store.'));
            return true;
        }
        if ($Ident === 'EduUnblock') {
            $this->UpdateFormField('EduStatusLabel', 'caption', $this->EduSperreAufheben((string)$Value));
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
     * Das Ende eines Laufs: melden — oder warten, bis die Auswertung zurueck ist.
     *
     * Laeuft die Auswertung als Auftrag, sind ihre Zahlen jetzt noch nicht da.
     * Wer hier trotzdem schickte, saehe zwei Meldungen: erst „3 Karten
     * aktualisiert", Minuten spaeter „2 Termine warten" — die zweite ohne den
     * Namen der Seite, weil sie den Sammler des Laufs nicht mehr kennt. Vorher
     * war das EINE Nachricht, und genau darum geht es bei dieser Sammlung.
     *
     * Also: wartet noch Hintergrundarbeit, wandert der Stand des Laufs ins
     * Attribut und der letzte fertige Auftrag schickt alles zusammen.
     */
    private function EduPushAbschluss(): void
    {
        $sammlung = $this->eduPushSammlung ?? [];
        $this->eduPushSammlung = null;
        if ($sammlung === []) {
            return;
        }
        if (!$this->AiJobMoeglich()
            || $this->AiJobLaden(false)->zaehleWartende(AiJobStore::HERKUNFT_HINTERGRUND) === 0) {
            $this->eduPushSammlung = $sammlung;
            $this->EduPushSenden();
            return;
        }
        foreach ($sammlung as $userId => $e) {
            $this->EduPushUebertragen((string)$userId, (array)$e);
        }
    }

    /**
     * Einen Posten in den Zwischenstand auf der Platte einrechnen.
     *
     * @param array<string,mixed> $e
     */
    private function EduPushUebertragen(string $userId, array $e): void
    {
        $offen = json_decode((string)@$this->ReadAttributeString('EduPushOffen'), true);
        $offen = is_array($offen) ? $offen : [];
        $alt = is_array($offen[$userId] ?? null) ? $offen[$userId] : self::EDU_PUSH_LEER;
        foreach (['karten', 'aufgaben', 'termine', 'notizen'] as $feld) {
            $alt[$feld] = (int)($alt[$feld] ?? 0) + (int)($e[$feld] ?? 0);
        }
        foreach ((array)($e['seiten'] ?? []) as $name) {
            if ((string)$name !== '' && !in_array((string)$name, (array)$alt['seiten'], true)) {
                $alt['seiten'][] = (string)$name;
            }
        }
        $offen[$userId] = $alt;
        @$this->WriteAttributeString('EduPushOffen', (string)json_encode($offen, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Die Sammelmeldung eines AUFTRAGS-Laufs fortschreiben.
     *
     * Der synchrone Lauf sammelt im Objektfeld und schickt am Ende; ueber
     * Auftraege hinweg geht das nicht — jeder kommt in einem eigenen Objekt an.
     * Deshalb liegt der Zwischenstand im Attribut, und geschickt wird erst,
     * wenn keine Hintergrundarbeit mehr wartet.
     */
    private function EduPushAuftrag(string $userId, int $aufgaben, int $termine, int $notizen): void
    {
        $this->EduPushUebertragen($userId, ['aufgaben' => $aufgaben, 'termine' => $termine,
                                            'notizen' => $notizen]);
    }

    /**
     * Ist die Hintergrundarbeit durch, geht EINE Meldung hinaus.
     *
     * Aufgerufen nach jedem fertigen Auftrag: solange noch einer wartet,
     * passiert nichts.
     */
    private function EduPushAuftragFertig(): void
    {
        if ($this->AiJobLaden(false)->zaehleWartende(AiJobStore::HERKUNFT_HINTERGRUND) > 0) {
            return;   // es kommt noch etwas
        }
        $offen = json_decode((string)@$this->ReadAttributeString('EduPushOffen'), true);
        if (!is_array($offen) || $offen === []) {
            return;
        }
        @$this->WriteAttributeString('EduPushOffen', '{}');
        $this->eduPushSammlung = $offen;
        $this->EduPushSenden();
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
               „Klassenseite 5b Musterklasse" sagt mehr als „Neues von der Klassenseite".
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
        /* Die verlinkten Seiten hinten anstellen. Sie werden wie die
           eingetragenen behandelt — gespiegelt UND ausgewertet —, tragen aber
           ein Merkmal: von ihnen aus wird kein Verweis weiterverfolgt (die
           Kette bleibt eine Ebene tief, siehe unten).

           Bis zum 10.09.2026 wurden sie ausdruecklich NICHT ausgewertet, mit
           der Begruendung, es seien Nachschlagewerke. Das traf auf die eine
           Grammatikseite zu und auf die Elternbrief-Seiten daneben nicht. Der
           Nutzer entscheidet das jetzt ueber den Schalter „Verlinkten Seiten
           folgen": wer sie mitnimmt, will sie auch ausgewertet haben.

           Ein Ansturm entsteht dabei nicht: die Karten dieser Seiten stehen
           schon im Merker (sie wurden bisher beim Spiegeln vermerkt), also
           laeuft nur durch die KI, was sich AENDERT — und darueber liegen
           weiterhin der Deckel je Lauf und der Tagesdeckel. */
        foreach ($this->EduGefundene() as $g) {
            $seiten[] = $g + ['verlinkt' => true];
        }
        /* Von Hand geloeschte Seiten fallen hier raus — eingetragene wie
           gefundene. Der zweite Riegel sitzt in EduGefundeneAufnehmen und haelt
           sie aus der Fundliste; dieser hier haelt sie aus dem LAUF, denn eine
           eingetragene Seite steht weiter in der Konfiguration, und ein Eintrag,
           der schon in EduFound stand, liefe sonst weiter mit. */
        $vorher = count($seiten);
        $seiten = array_values(array_filter($seiten,
            fn(array $s): bool => !$this->EduGesperrt((string)$s['url'])));
        if (count($seiten) < $vorher) {
            $this->SendDebug('EduMaps', sprintf('%d gesperrte Seite(n) uebersprungen',
                $vorher - count($seiten)), 0);
        }
        if ($seiten === []) {
            return $this->Translate('All class pages are blocked — release one in the list below.');
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
            $bericht .= ' ' . sprintf($this->Translate('%d card(s) mirrored.'), $gespiegelt);
        }
        if ($fehler !== []) {
            $bericht .= ' — ' . implode(' | ', $fehler);
        }
        @$this->WriteAttributeString('EduStatus', (string)json_encode(
            ['t' => time(), 'text' => $bericht], JSON_UNESCAPED_UNICODE));
        $this->SendDebug('EduMaps', $bericht, 0);
        $this->EduPushAbschluss();
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
        if (($seite['verlinkt'] ?? false) !== true) {
            $this->EduGefundeneErgaenzen($seite, $rumpfSeite);
        }
        // Die QR-Funde gehoeren zu DIESER Seite; was eine vorige gesammelt hat,
        // ist laengst aufgenommen.
        $this->eduQrSeiten = [];
        $karten = $this->EduKarten($rumpfSeite);
        if ($karten === []) {
            /* Kein stilles Schweigen: bricht Edumaps das Markup, sieht es sonst
               aus wie „nichts Neues". */
            return ['fehler' => $this->Translate('0 cards found — has the page changed?')] + $leer;
        }
        /* Spiegeln zuerst und fuer die GANZE Seite auf einmal: es kostet keinen
           KI-Aufruf, haengt also an keinem Deckel und auch nicht daran, ob eine
           Karte als „geaendert" gilt. Die Notiz selbst entscheidet je Karte, ob
           es etwas zu tun gibt (srcRev).

           In EINEM Schreibvorgang, nicht in einem je Karte: der Bestand wird
           bis zu ein Megabyte gross, und er lag in derselben Spur, die die App
           bedient. Fuenfundfuenfzig Karten waren fuenfundfuenfzig Mal alles. */
        $gespiegelt = $this->EduSeiteSpiegeln($seite, $karten);
        $aus = $this->EduKartenAuswerten($seite, $karten, $schonAnalysiert, $alles);
        $geaendert  = $aus['geaendert'];
        $analysiert = $aus['analysiert'];
        $gedeckelt  = $aus['gedeckelt'];
        /* Was in QR-Codes auf ANDERE Anlagen zeigte, wird jetzt aufgenommen —
           wie ein Verweis im Kartentext, mit denselben Grenzen. Erst hier, damit
           die Fundliste einmal je Seite geschrieben wird und nicht je Karte.
           Auch von einer VERLINKTEN Seite: der Code steht im BILD, ist also kein
           Glied der Verweiskette, die eine Ebene tief bleiben soll. */
        if ($this->eduQrSeiten !== []) {
            $neu = $this->EduGefundeneAufnehmen($seite, $this->eduQrSeiten);
            if ($neu > 0) {
                $this->SendDebug('EduMaps', sprintf('%d Anlage(n) aus QR-Codes aufgenommen (%s)',
                    $neu, $seite['name']), 0);
            }
            $this->eduQrSeiten = [];
        }
        $archiviert = $this->EduArchivAbgleichen($seite, $karten);
        return ['karten' => count($karten), 'geaendert' => $geaendert,
                'archiviert' => $archiviert,
                'analysiert' => $analysiert, 'gespiegelt' => $gespiegelt,
                // Ein abgebrochener Lauf darf nicht wie ein vollstaendiger aussehen.
                'fehler' => $gedeckelt ? $this->Translate('daily AI limit reached — the rest follows later') : ''];
    }

    /**
     * Welche Karten einer Seite ausgewertet werden — und die Auswertung anstossen.
     *
     * DIE Stelle, an der Geld ausgegeben wird. Sie steht hier eigens, weil es
     * seit der Uebergabe an den Scanner ZWEI Wege zu ihr gibt: den eigenen Lauf
     * (`EduSeiteLesen`) und den Umschlag aus der zweiten Spur
     * (`EduUmschlagEinpflegen`). Beide muessen dieselben Deckel ziehen und
     * denselben Merker fuehren — zwei Abschriften waeren zwei Politiken, und
     * die teurere faellt erst auf der Rechnung auf.
     *
     * Ausgewertet wird IMMER hier, nie beim Scanner: den Prompt baut nur diese
     * Instanz (sie hat den Bestand), der Merker ist ihr Attribut, und die
     * Tagesdeckel zaehlen bei ihr. Der Scanner liest und zerlegt, mehr nicht.
     *
     * @param array{name:string,url:string,userId:string} $seite
     * @param list<array<string,mixed>> $karten
     * @param int  $schonAnalysiert  was in DIESEM Lauf schon durch die KI ging
     * @param bool $alles            der Knopf „Alles auswerten"
     * @return array{geaendert:int,analysiert:int,gedeckelt:bool}
     */
    private function EduKartenAuswerten(array $seite, array $karten, int $schonAnalysiert, bool $alles): array
    {
        $leer = ['geaendert' => 0, 'analysiert' => 0, 'gedeckelt' => false];
        if ($karten === []) {
            return $leer;
        }
        $quelle = EduStoreCalc::Quelle($karten[0]);
        $topf = $this->EduMerkerTopf($quelle, (string)$seite['url']);
        $ersterLauf = !$this->EduMerkerFrisch($quelle, $topf);
        $auswerten  = $this->EduAuswertenAn($quelle);
        $geaendert  = 0;
        $analysiert = 0;
        $gedeckelt  = false;
        foreach ($karten as $karte) {
            $schluessel = $this->EduMerkerSchluessel($karte);
            /* „Alles auswerten" nimmt auch die Karten, die schon im Merker
               stehen — sonst waere nach dem ersten Lauf, der nur vermerkt,
               nie etwas auszuwerten. Das ist ein Griff von Hand und teuer:
               jede Karte kostet einen KI-Aufruf, hier also sechzehn. */
            if (!$alles && $this->EduMerkerGesehen($quelle, $topf, $schluessel)) {
                continue;
            }
            $geaendert++;
            if ($ersterLauf && !$alles) {
                /* Erster Lauf: nur vermerken. Sonst stuenden beim Einschalten
                   zwoelf Vorschlaege auf einmal da, und jeder kostet Geld. */
                $this->EduMerkerSetzen($quelle, $topf, $schluessel);
                continue;
            }
            if (!$auswerten) {
                /* Die Auswertung ist abgeschaltet. NICHT vermerken: sonst
                   waere die Karte beim Einschalten schon „gesehen" und kaeme
                   nie mehr an die Reihe. */
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
            if ($this->EduKarteAnalysierenJe($quelle, $seite, $karte)) {
                $this->EduMerkerSetzen($quelle, $topf, $schluessel);
                $analysiert++;
            }
        }
        return ['geaendert' => $geaendert, 'analysiert' => $analysiert, 'gedeckelt' => $gedeckelt];
    }

    // ── Was die beiden Quellen wirklich unterscheidet ────────────────────
    //
    // Sechs kleine Weichen statt zweier Schleifen. Die Schleife selbst ist die
    // teure Entscheidung (jede Karte kostet einen KI-Aufruf), und die gab es
    // zweimal — mit verschiedenen Deckeln und verschiedenen Meldungen. Was sich
    // wirklich unterscheidet, steht jetzt hier, in sechs Zeilen.

    /** Darf diese Quelle ueberhaupt auswerten? */
    private function EduAuswertenAn(string $quelle): bool
    {
        if ($quelle === 'moodle') {
            return (bool)$this->MoodleProp('MoodleAnalyse', true)
                && (bool)$this->MoodleProp('AiEnabled', false)
                && $this->AiPrivacyAccepted();
        }
        return $this->EduIsEnabled();
    }

    /** Der Merker-Topf DIESER Seite. Je Quelle ein eigener Bestand. */
    private function EduMerkerTopf(string $quelle, string $url): string
    {
        return ($quelle === 'moodle' ? 'moodle:' : 'edu:') . md5($url);
    }

    /**
     * Der Schluessel einer Karte im Merker.
     *
     * Bewusst NICHT vereinheitlicht: die Klassenseiten merken sich
     * `<boxid>:<fassung>`, LOGINEO `<moodle:id>:<fassung>`. Wer das anglich,
     * entwertete JEDEN vorhandenen Merker — und beim naechsten Lauf liefe jede
     * bereits ausgewertete Karte noch einmal durch die KI. Das kostet Geld und
     * traegt nichts ein.
     */
    private function EduMerkerSchluessel(array $karte): string
    {
        return EduStoreCalc::Quelle($karte) === 'moodle'
            ? (string)($karte['srcId'] ?? '') . ':' . (int)($karte['updated'] ?? 0)
            : (string)($karte['boxid'] ?? '') . ':' . (int)($karte['updated'] ?? 0);
    }

    /** Steht in diesem Topf schon etwas? Leer heisst „erster Lauf". */
    private function EduMerkerFrisch(string $quelle, string $topf): bool
    {
        return $quelle === 'moodle'
            ? $this->MoodleTopfHatEintraege($topf) : $this->EduTopfHatEintraege($topf);
    }

    private function EduMerkerGesehen(string $quelle, string $topf, string $schluessel): bool
    {
        return $quelle === 'moodle'
            ? $this->MoodleGesehen($topf, $schluessel) : $this->EduGesehen($topf, $schluessel);
    }

    private function EduMerkerSetzen(string $quelle, string $topf, string $schluessel): void
    {
        if ($quelle === 'moodle') {
            $this->MoodleMerken($topf, $schluessel);
            return;
        }
        $this->EduMerken($topf, $schluessel);
    }

    /** Die Karte durch die Mail-Kette schicken — je Quelle mit eigenem Absender. */
    private function EduKarteAnalysierenJe(string $quelle, array $seite, array $karte): bool
    {
        return $quelle === 'moodle'
            ? $this->MoodleKarteAnalysieren($seite, $karte)
            : $this->EduKarteAnalysieren($seite, $karte);
    }

    /**
     * Eine Karte durch die Mail-Kette schicken. Titel und Abschnitt bilden den
     * „Betreff", damit die KI den Zusammenhang hat („Elternbriefe · Kopiergeld").
     *
     * Das SPIEGELN steht nicht mehr hier, sondern in `EduEinpflegen` — es ist
     * die Gateway-Haelfte des Laufs und schreibt einmal je Seite.
     */

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
    private function EduOrdner(array &$store, array $seite): string
    {
        $userId = trim((string)($seite['userId'] ?? ''));
        $schluessel = 'edu:' . ($userId !== '' ? $userId : md5((string)$seite['url']));

        /* ── 1. Ebene: der Ordner des KINDES ──
           Im eigenen Bestand gibt es keine Zwischenebene „Edumaps" mehr — der
           ganze Bestand IST die Klassenseite. Ebene 1 ist deshalb der Ordner des
           Kindes: er traegt `memberId`, damit die Oberflaeche ihn mit Foto
           zeichnet, und heisst wie das Mitglied. */
        $edu = '';
        foreach ($store['folders'] as $k => $f) {
            if ((string)($f['eduKey'] ?? '') !== $schluessel) {
                continue;
            }
            /* Nachziehen: ein Ordner aus dem Notizen-Bestand kam ohne Mitglied
               herueber (der Umzug setzt es, aber ein spaeter angelegtes Mitglied
               fehlt dort noch). Der NAME bleibt, den darf der Nutzer aendern. */
            if ($userId !== '' && (string)($f['memberId'] ?? '') !== $userId) {
                $store['folders'][$k]['memberId'] = $userId;
                $store['folders'][$k]['updatedAt'] = time();
                $this->eduOrdnerGeaendert = true;
            }
            $edu = (string)$f['id'];
            break;
        }
        if ($edu === '') {
            if (count($store['folders']) >= EduStoreCalc::FOLDERS_MAX) {
                $this->SendDebug('EduMaps', 'Ordnergrenze erreicht — kein Ordner angelegt', 0);
                return '';
            }
            /* Der Name des Kindes, wenn es eines gibt. Sonst „Klassenseiten" —
               ein Ordner ohne Namen waere schlimmer als einer mit einem
               allgemeinen. */
            $name = $this->Translate('Class pages');
            foreach ($this->LoadUsers() as $u) {
                if ((string)($u['id'] ?? '') === $userId && trim((string)($u['name'] ?? '')) !== '') {
                    $name = trim((string)$u['name']);
                    break;
                }
            }
            $edu = $this->EduOrdnerAnlegen($store, $name, '', $schluessel, $userId);
        }

        /* ── 2. Ebene: je SEITE ein eigener Ordner ──
           Eine Klassenseite und ein Nachschlagewerk gehoeren nicht in denselben
           Topf; mit zwei Karten laegen sonst vierunddreissig Karten
           durcheinander. Der Schluessel haengt an der ADRESSE, nicht am Namen:
           beide darf der Nutzer aendern, die Adresse nicht. */
        $seiteSchluessel = 'edupage:' . md5((string)$seite['url']);
        foreach ($store['folders'] as $k => $f) {
            if ((string)($f['eduKey'] ?? '') !== $seiteSchluessel) {
                continue;
            }
            /* UMGEHAENGT: das Mitglied der Seite wurde in der Konfiguration
               geaendert. Der Ordner der Seite selbst bleibt derselbe — er haengt
               an der Adresse —, er zieht nur unter das neue Mitglied.

               Ohne diese Zeilen stand die Seite mit allen Karten weiter beim
               alten Mitglied, waehrend fuer das neue ein zweiter, leerer Ordner
               entstand: der Ordner der ersten Ebene haengt an der
               Mitgliedskennung, der der zweiten an der Adresse. Genau dieser
               Fall trat am 10.09.2026 ein (Seite vom Vater auf das Kind
               umgestellt) — und im Kindmodus zeigt die Oberflaeche nur die
               Seiten des gewaehlten Kindes, die Karten waeren also unerreichbar
               geworden. */
            if ($edu !== '' && (string)($f['parentId'] ?? '') !== $edu) {
                $alt = (string)($f['parentId'] ?? '');
                $store['folders'][$k]['parentId'] = $edu;
                $store['folders'][$k]['updatedAt'] = time();
                $this->eduOrdnerGeaendert = true;
                $this->EduLeerenOrdnerEntfernen($store, $alt, $edu);
            }
            return (string)$f['id'];
        }
        if ($edu === '' || count($store['folders']) >= EduStoreCalc::FOLDERS_MAX) {
            return $edu;
        }
        return $this->EduOrdnerAnlegen($store, (string)($seite['name'] ?? 'Karte'), $edu, $seiteSchluessel);
    }

    /**
     * Den zurueckgelassenen Mitglieds-Ordner wegnehmen, WENN nichts mehr darin
     * steht.
     *
     * Nur ein Ordner, den dieses Modul selbst angelegt hat (`eduKey` beginnt mit
     * „edu:"), nur ohne Unterordner und ohne Karte, und nie der Ordner, unter
     * dem die Seite gerade gelandet ist. Ein Ordner mit Inhalt bleibt stehen:
     * lieber ein uebriger Ordner als eine verschwundene Karte — dieselbe
     * Ruecksicht wie beim Umzug der Karten aus den Notizen.
     *
     * Gesperrt wird hier NICHTS. Die Sperre gehoert zum Loeschen von Hand
     * (folderDelete): dort sagt der Nutzer „diese Seite will ich nicht mehr".
     * Hier zieht dieselbe Seite nur um.
     *
     * @param array<string,mixed> $store wird geaendert (noch nicht geschrieben)
     */
    private function EduLeerenOrdnerEntfernen(array &$store, string $id, string $behalten): void
    {
        if ($id === '' || $id === $behalten) {
            return;
        }
        foreach ($store['folders'] as $f) {
            if ((string)($f['parentId'] ?? '') === $id) {
                return;
            }
        }
        foreach ((array)($store['notes'] ?? []) as $n) {
            if ((string)($n['folderId'] ?? '') === $id) {
                return;
            }
        }
        foreach ($store['folders'] as $k => $f) {
            if ((string)($f['id'] ?? '') !== $id) {
                continue;
            }
            if (!str_starts_with((string)($f['eduKey'] ?? ''), 'edu:')) {
                return;
            }
            unset($store['folders'][$k]);
            $store['folders'] = array_values($store['folders']);
            $this->eduOrdnerGeaendert = true;
            return;
        }
    }

    /**
     * Einen Ordner anlegen und merken, dass der Bestand geschrieben werden muss.
     *
     * @param array<string,mixed> $store wird ergaenzt (noch nicht geschrieben)
     */
    private function EduOrdnerAnlegen(array &$store, string $name, string $eltern,
        string $schluessel, string $memberId = ''): string
    {
        $jetzt = time();
        $ordner = ['id' => $this->NotesNewId(),
                   'name' => EduStoreCalc::Kappen($name, EduStoreCalc::TITLE_MAX),
                   'memberId' => $memberId, 'parentId' => $eltern, 'eduKey' => $schluessel,
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
    /**
     * Schreibt DIESE Quelle ueberhaupt in den Bestand?
     *
     * Zwei Schalter, ein Weg: die Klassenseiten haben „in die Notizen
     * spiegeln", LOGINEO hat „als Karten ablegen". Beide meinen dasselbe, und
     * seit beide Quellen durch `EduEinpflegen` gehen, muss die Frage an EINER
     * Stelle beantwortet werden — sonst richtete sich LOGINEO nach dem Schalter
     * der Klassenseiten.
     */
    private function EduSpiegelnAn(string $quelle): bool
    {
        return $quelle === 'moodle'
            ? (bool)$this->MoodleProp('MoodleToCards', true)
            : (bool)$this->EduProp('EduToNotes', false);
    }

    /**
     * Eine Datei der Karte holen — je nach Quelle auf verschiedenen Wegen.
     *
     * Eine Klassenseite ist oeffentlich, ihre Dateien kommen ueber den
     * SSRF-sicheren Abruf (Deckel 2 MB, bricht darueber ab). LOGINEO verlangt
     * den Token in der Adresse und darf bis 8 MB liefern — der Weg dorthin
     * steht im Moodle-Teil und traegt seine eigenen Fristen.
     */
    private function EduDateiHolen(array $karte, string $url): ?string
    {
        if (EduStoreCalc::Quelle($karte) === 'moodle') {
            return $this->MoodleDateiVonUrl($url);
        }
        $antwort = $this->AiFetchPublicPage($url);
        return ($antwort['ok'] ?? false) === true ? (string)($antwort['body'] ?? '') : null;
    }

    /** Groesse, ab der gar nicht erst geladen wird. 0 = die Quelle sagt sie nicht. */
    private function EduDateiDeckel(array $karte): int
    {
        return EduStoreCalc::Quelle($karte) === 'moodle' ? $this->MoodleDateiDeckel() : 0;
    }

    private function EduNotizAnhaenge(array $karte): array
    {
        $raus = [];
        $speicherVorher = (string)@ini_get('memory_limit');
        @ini_set('memory_limit', '192M');
        try {
            $versuche = 0;
            foreach ((array)$karte['anhaenge'] as $a) {
                /* Der Deckel zaehlt VERSUCHE mit, nicht nur Erfolge. Jeder
                   Eintrag kostet einen Abruf von bis zu fuenfzehn Sekunden in
                   dieser Spur — auch der, der scheitert. Bei einer Liste aus
                   lauter unerreichbaren Adressen brach die Schleife sonst nie
                   ab, und das Gateway antwortete stundenlang auf keinen Hook.
                   Auf einer echten Klassenseite faellt das nie auf: dort haengt
                   an einer Karte eine Handvoll Dateien. */
                if (++$versuche > EduStoreCalc::ATTACH_MAX * 2) {
                    $this->SendDebug('EduMaps', 'Zu viele Anhangs-Versuche an der Karte: '
                        . (string)($karte['titel'] ?? ''), 0);
                    break;
                }
                if (count($raus) >= EduStoreCalc::ATTACH_MAX) {
                    $this->SendDebug('EduMaps', 'Mehr als ' . EduStoreCalc::ATTACH_MAX
                        . ' Dateien an der Karte — die weiteren bleiben in der Notiz weg: ' . $karte['titel'], 0);
                    break;
                }
                if ($this->EduArt((string)($a['datei'] ?? $a['name'])) === '') {
                    continue;
                }
                /* Was der Bestand ohnehin nicht annimmt, wird gar nicht erst
                   geladen. Nur LOGINEO nennt die Groesse vorher — gemessen an
                   dieser Schule: die Elternabend-Praesentation hat 6,3 MB und
                   passt nicht, sie waere umsonst geholt worden. */
                $deckel = $this->EduDateiDeckel($karte);
                if ($deckel > 0 && (int)($a['bytes'] ?? 0) > $deckel) {
                    $this->SendDebug('EduMaps', sprintf('Datei zu gross fuer den Bestand, nur verlinkt: %s (%d > %d)',
                        (string)$a['name'], (int)($a['bytes'] ?? 0), $deckel), 0);
                    continue;
                }
                $roh = $this->EduDateiHolen($karte, (string)$a['url']);
                if ($roh === null) {
                    $this->SendDebug('EduMaps', 'Datei nicht ladbar: ' . $a['name'], 0);
                    continue;
                }
                $r = $this->NotesSaveAttachment(base64_encode($roh), (string)$a['name']);
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
                /* Steckt im Bild ein QR-Code, haengt seine Adresse am Anhang.
                   Beim PDF wird die VORSCHAU gelesen — die Seite selbst kann
                   PHP ohne Imagick nicht rastern, und gemessen genuegt die
                   Vorschau (175x255) fuer einen sauber gedruckten Code. */
                $qrQuelle = $anhang['kind'] === 'image' ? $anhang['id'] : (int)($anhang['thumb'] ?? 0);
                $qr = $this->EduQrCode($qrQuelle);
                if ($qr !== '') {
                    $anhang['qr'] = $qr;
                    $this->SendDebug('EduMaps', 'QR im Anhang „' . $anhang['name'] . '": ' . $qr, 0);
                }
                $raus[] = $anhang;
            }
        } finally {
            @ini_set('memory_limit', $speicherVorher);
        }
        return $raus;
    }

    /**
     * Die Adresse aus einem QR-Code im Bild — oder '' , wenn keiner drin ist.
     *
     * Klassenseiten drucken ihre Verweise gern als QR-Code ins Bild; wer die App
     * am Rechner liest, kann ihn nicht abfotografieren. Deshalb einmal beim
     * Spiegeln lesen und die Adresse an den Anhang haengen.
     *
     * NUR http(s) wird uebernommen: ein QR-Code kann auch WLAN-Zugangsdaten,
     * eine vCard oder eine Zahlungsanweisung enthalten — nichts davon gehoert
     * als anklickbarer Verweis in eine Notiz.
     *
     * Das `@` ist Pflicht, nicht Bequemlichkeit: die Bibliothek gibt bei einer
     * fehlenden oder unlesbaren Datei eine PHP-WARNUNG aus (file_get_contents,
     * imagecreatefromstring). Im Hook landete die mitten in der HTTP-Antwort.
     */
    /**
     * Karten, die es auf der Seite nicht mehr gibt, ins Archiv legen — und
     * zurueckgekehrte wieder herausholen.
     *
     * Geloescht wird NICHTS. Ein Elternbrief, den die Schule von der Seite
     * nimmt, ist deswegen nicht wertlos; er soll nur nicht mehr zwischen den
     * aktuellen Karten stehen. `section` und `pos` bleiben deshalb unberuehrt —
     * kommt die Karte zurueck, steht sie wieder an ihrem alten Platz.
     *
     * Vier Riegel, ohne die es Datenmuell oder Fehlalarm gaebe:
     *  1. Nur bei erfolgreich gelesener Seite. Die Fehlerfaelle kehren schon vor
     *     der Kartenschleife zurueck, diese Funktion sieht sie also nie.
     *  2. NICHT beim Kartendeckel: EduKarten bricht bei EDU_KARTEN_MAX ab, eine
     *     laengere Seite saehe sonst aus, als waeren alle Karten ab Nummer 61
     *     verschwunden.
     *  3. Nur gespiegelte Karten (source „edumaps" MIT srcId) — eigene Notizen
     *     im selben Ordner gehen das hier nichts an.
     *  4. Geschrieben wird nur bei echter Aenderung.
     *
     * @param list<array<string,mixed>> $karten
     * @return int wie viele Karten neu ins Archiv wanderten
     */
    private function EduArchivAbgleichen(array $seite, array $karten): int
    {
        if ($karten === [] || count($karten) >= self::EDU_KARTEN_MAX) {
            if ($karten !== []) {
                $this->SendDebug('EduMaps', 'Kartendeckel erreicht — kein Archiv-Abgleich: '
                    . (string)$seite['name'], 0);
            }
            return 0;
        }
        $quelle = EduStoreCalc::Quelle($karten[0]);
        if (!$this->EduSpiegelnAn($quelle) || !$this->EduStorable()) {
            return 0;
        }
        $lock = self::EDU_LOCK . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            $this->SendDebug('EduMaps', 'Bestand belegt — Archiv-Abgleich beim naechsten Lauf', 0);
            return 0;
        }
        try {
            $store = $this->EduStoreRead();
            $ordnerId = '';
            $schluessel = 'edupage:' . md5((string)$seite['url']);
            foreach ($store['folders'] as $f) {
                if ((string)($f['eduKey'] ?? '') === $schluessel) {
                    $ordnerId = (string)$f['id'];
                    break;
                }
            }
            if ($ordnerId === '') {
                return 0;                       // noch nie gespiegelt
            }
            $aktuell = [];
            foreach ($karten as $k) {
                $aktuell[EduStoreCalc::SrcId($k)] = true;
            }
            $jetzt = time();
            $neu = 0;
            $zurueck = 0;
            foreach ($store['notes'] as $i => $n) {
                /* JE ORDNER UND JE QUELLE. Eine LOGINEO-Karte im selben
                   Kind-Ordner darf ein Klassenseiten-Lauf nicht anfassen — und
                   umgekehrt. */
                if ((string)($n['folderId'] ?? '') !== $ordnerId
                    || (string)($n['source'] ?? '') !== $quelle) {
                    continue;
                }
                $srcId = (string)($n['srcId'] ?? '');
                if ($srcId === '') {
                    continue;                   // vom Nutzer selbst angelegt
                }
                $fehlt = !isset($aktuell[$srcId]);
                $imArchiv = (int)($n['archived'] ?? 0) > 0;
                if ($fehlt && !$imArchiv) {
                    $store['notes'][$i]['archived'] = $jetzt;
                    $neu++;
                } elseif (!$fehlt && $imArchiv) {
                    unset($store['notes'][$i]['archived']);
                    $zurueck++;
                }
            }
            if ($neu === 0 && $zurueck === 0) {
                return 0;
            }
            if (!$this->EduWriteStore($store)) {
                $this->SendDebug('EduMaps', 'Archiv-Abgleich nicht schreibbar', 0);
                return 0;
            }
            $this->SendDebug('EduMaps', sprintf('Archiv „%s": %d neu, %d zurueck',
                (string)$seite['name'], $neu, $zurueck), 0);
            return $neu;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Die von Hand geloeschten Klassenseiten.
     *
     * Sie stehen im eigenen Bestand (`blocked`), nicht in einem zweiten
     * Attribut: Loeschen und Sperren muessen in DENSELBEN Schreibvorgang —
     * sonst gibt es das Fenster „Ordner weg, Sperre nicht geschrieben, Ordner
     * beim naechsten Lauf wieder da". Die Begruendung steht an EduStore.
     *
     * @return list<array{key:string,url:string,name:string,at:int}>
     */
    private function EduGesperrteSeiten(): array
    {
        $store = $this->EduStoreRead();
        $raus = [];
        foreach ((array)($store['blocked'] ?? []) as $b) {
            if (is_array($b) && (string)($b['key'] ?? '') !== '') {
                $raus[] = ['key' => (string)$b['key'], 'url' => (string)($b['url'] ?? ''),
                           'name' => (string)($b['name'] ?? ''), 'at' => (int)($b['at'] ?? 0)];
            }
        }
        return $raus;
    }

    /** Die Sperrliste fuer das Formular — dieselben Daten, nur oeffentlich lesbar. */
    private function EduGesperrteSeitenPublic(): array
    {
        return $this->EduGesperrteSeiten();
    }

    /**
     * Eine Sperre aufheben. `$wahl` ist die in der Formularliste gewaehlte Zeile
     * (JSON) — daraus zaehlt der Schluessel.
     *
     * Die Seite kommt danach beim naechsten Lauf zurueck: eine eingetragene ueber
     * die Konfiguration, eine gefundene ueber den Verweis oder den QR-Code, der
     * sie schon einmal gebracht hat.
     */
    private function EduSperreAufheben(string $wahl): string
    {
        $zeile = json_decode($wahl, true);
        $key = is_array($zeile) ? (string)($zeile['key'] ?? '') : '';
        if ($key === '') {
            return $this->Translate('Select a page in the list first.');
        }
        $lock = self::EDU_LOCK . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            return $this->Translate('Class pages are busy — try again in a moment.');
        }
        try {
            $store = $this->EduStoreRead();
            $name = '';
            $bleibt = [];
            foreach ((array)($store['blocked'] ?? []) as $b) {
                if (is_array($b) && (string)($b['key'] ?? '') === $key) {
                    $name = (string)($b['name'] ?? '');
                    continue;
                }
                $bleibt[] = $b;
            }
            if ($name === '' && count($bleibt) === count((array)($store['blocked'] ?? []))) {
                return $this->Translate('That page is not blocked.');
            }
            $store['blocked'] = $bleibt;
            if (!$this->EduWriteStore($store)) {
                return $this->Translate('Could not save — the store is full or unwritable.');
            }
            $this->ReloadForm();
            return sprintf($this->Translate('„%s" released — it will be mirrored again at the next check.'),
                $name !== '' ? $name : $key);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Ist diese Seite gesperrt?
     *
     * Geprueft wird gegen BEIDE Formen der Adresse: die Kandidaten aus Verweisen
     * und QR-Codes kommen mit `rtrim($url, '/')`, der Ordnerschluessel benutzt
     * die Adresse dagegen roh (EduOrdner) — mit und ohne Schraegstrich
     * ergaeben sonst zwei verschiedene Schluessel.
     */
    private function EduGesperrt(string $url): bool
    {
        $roh = trim($url);
        if ($roh === '') {
            return false;
        }
        $glatt = rtrim($roh, '/');
        $schluessel = ['edupage:' . md5($roh), 'edupage:' . md5($glatt)];
        foreach ($this->EduGesperrteSeiten() as $b) {
            if (in_array($b['key'], $schluessel, true)) {
                return true;
            }
            if ($b['url'] !== '' && rtrim($b['url'], '/') === $glatt) {
                return true;
            }
        }
        return false;
    }

    /**
     * Zeigt eine QR-Adresse auf eine ANDERE Anlage, fuer den Durchgang vormerken.
     *
     * Das ist derselbe Fund wie ein Verweis im Kartentext und wird genauso
     * behandelt. Gerufen wird das fuer JEDEN bekannten Code — den gerade
     * gelesenen wie den laengst gespeicherten: dekodiert wird nur einmal je
     * Anhang, und ohne diesen zweiten Weg kaeme eine Adresse aus einem frueheren
     * Lauf nie in die Fundliste.
     */
    private function EduQrVormerken(string $text): void
    {
        if (preg_match('#^https://[a-z0-9.-]+\.edumaps\.de/\d+/\d+/[a-z0-9]+/[a-z0-9]+#i', $text) !== 1) {
            return;
        }
        $sauber = rtrim($text, '/');
        if (!in_array($sauber, $this->eduQrSeiten, true)) {
            $this->eduQrSeiten[] = $sauber;
        }
    }

    private function EduQrCode(int $mediaId): string
    {
        if ($mediaId <= 0 || !IPS_MediaExists($mediaId)) {
            return '';
        }
        $datei = IPS_GetKernelDir() . IPS_GetMedia($mediaId)['MediaFile'];
        if (!is_file($datei) || filesize($datei) < 64) {
            return '';
        }
        /* GEMESSEN am 06.09.2026: der Leser braucht rund 25 MB je Megapixel —
           52 MB bei einem 1254x1254-Bild. Symcons PHP steht auf 32 MB, und ein
           ueberschrittenes Limit ist kein Fehler, den man fangen kann: der
           Prozess stirbt mitten im Spiegeln und laesst die Notiz-Sperre liegen
           („Semaphore TGW_Notes_… wurde nicht korrekt verlassen"). Genau so
           passiert, bevor diese beiden Riegel hier standen.
           Deshalb: Grenze hochsetzen wie beim Laden der Anhaenge — und alles
           ueber EDU_QR_MP_MAX gar nicht erst versuchen. */
        $masse = @getimagesize($datei);
        if (!is_array($masse) || $masse[0] < 1 || $masse[1] < 1) {
            return '';
        }
        $megapixel = ($masse[0] * $masse[1]) / 1000000;
        if ($megapixel > self::EDU_QR_MP_MAX) {
            $this->SendDebug('EduMaps', sprintf('QR uebersprungen, Bild zu gross: %.1f MP (%s)',
                $megapixel, basename($datei)), 0);
            return '';
        }
        /* Erst hier laden, wie beim QR-Generator: das Paket sind 50 Dateien, und
           gebraucht wird es nur beim Spiegeln von Bildanhaengen. */
        static $geladen = false;
        if (!$geladen) {
            $basis = __DIR__ . '/vendor/zxing/';
            if (!is_file($basis . 'QrReader.php')) {
                return '';                       // Paket fehlt — dann eben ohne
            }
            spl_autoload_register(static function (string $klasse) use ($basis): void {
                $p = 'da8ter\\SymDo\\Zxing\\';
                if (!str_starts_with($klasse, $p)) {
                    return;
                }
                $d = $basis . str_replace('\\', '/', substr($klasse, strlen($p))) . '.php';
                if (is_file($d)) {
                    require_once $d;
                }
            });
            require_once $basis . 'Common/customFunctions.php';
            $geladen = true;
        }
        $speicherVorher = (string)@ini_get('memory_limit');
        @ini_set('memory_limit', '192M');
        try {
            $text = @(new \da8ter\SymDo\Zxing\QrReader($datei))->text();
        } catch (\Throwable $e) {
            $this->SendDebug('EduMaps', 'QR-Leser warf: ' . $e->getMessage(), 0);
            return '';
        } finally {
            @ini_set('memory_limit', $speicherVorher);
        }
        $text = is_string($text) ? trim($text) : '';
        if ($text === '' || preg_match('#^https?://#i', $text) !== 1) {
            return '';
        }
        if (mb_strlen($text) > 500) {
            return '';
        }
        $this->EduQrVormerken($text);
        return $text;
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
           „5b Musterklasse" statt eines Fragezeichens erscheint. */
        $kopf = [
            'Subject'    => $seite['name'] . ' — ' . $betreff,
            'SenderName' => $seite['name'],
            'Date'       => (int)$karte['updated'],
        ];
        $anhaenge = $this->EduAnhaenge($karte);
        /* Als AUFTRAG, sobald eine Scanner-Instanz die Warteschlange bedient:
           der Anbieteraufruf dauert bis zu fuenfundvierzig Sekunden und lief
           bisher hier, in der Spur, die auch die App bedient. Den Prompt baut
           weiterhin diese Instanz — dafuer braucht es ihren Bestand. */
        if ($this->AiJobMoeglich()) {
            /* Der Merker reist MIT: gemerkt wird beim Einreihen (sonst zahlte
               der naechste Lauf doppelt), zurueckgenommen beim Scheitern. */
            return $this->MailAnalyseAuftrag(
                'edu:' . $karte['boxid'] . ':' . $karte['updated'],
                $kopf, $text, $anhaenge, (string)$seite['userId'], 'Edumaps',
                ['topf' => 'edu:' . md5((string)$seite['url']),
                 'schluessel' => $karte['boxid'] . ':' . $karte['updated']]);
        }
        return $this->MailAnalyseRecord(
            'edu:' . $karte['boxid'] . ':' . $karte['updated'],
            $kopf,
            $text,
            $anhaenge,
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
                /* Die ADRESSE geht mit. Der Auftragsweg loescht die Nutzlast,
                   sobald der Anbieter geantwortet hat — die Datei fuer die
                   Notiz wird darueber neu geholt. */
                $raus[] = ['kind' => $art, 'name' => (string)$a['name'],
                           'url' => (string)$a['url'], 'base64' => $base64];
            }
        } finally {
            @ini_set('memory_limit', $speicherVorher);
        }
        return $raus;
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
        /* Das Mitglied kommt bei JEDEM Lesen frisch aus der eingetragenen Seite,
           auf der der Verweis gefunden wurde. Gespeichert ist es auch — aus der
           Stunde des Fundes —, aber wer eine Seite einem anderen Kind zuordnet,
           meint auch ihre verlinkten Seiten: sonst zoegen die Karten der einen
           Seite zum neuen Kind und die der verlinkten blieben beim alten, jede
           Haelfte in einem eigenen Ordner. Genau das stand am 10.09.2026 bevor
           (eine eingetragene Seite, drei verlinkte).

           Zugeordnet wird ueber den NAMEN der Herkunftsseite — mehr steht im
           Fundeintrag nicht. Wird sie umbenannt, greift der Rueckfall auf das
           gespeicherte Mitglied, und es bleibt beim Stand des Fundes. */
        $vonMitglied = [];
        foreach ($this->EduSeiten() as $s) {
            if ((string)$s['userId'] !== '') {
                $vonMitglied[(string)$s['name']] = (string)$s['userId'];
            }
        }
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $z) {
            if (!is_array($z) || !str_starts_with((string)($z['url'] ?? ''), 'https://')) {
                continue;
            }
            $von = (string)($z['von'] ?? '');
            $raus[] = ['name' => (string)($z['name'] ?? ''), 'url' => (string)$z['url'],
                       'userId' => $vonMitglied[$von] ?? (string)($z['userId'] ?? ''),
                       'von' => $von];
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
        return $this->EduGefundeneAufnehmen($seite, $this->EduKartenLinks($html, (string)$seite['url']));
    }

    /**
     * Verweise auf andere Anlagen aufnehmen — egal, woher sie kommen.
     *
     * Zwei Quellen muenden hier: die Verweise IM Kartentext (EduKartenLinks) und
     * die Adressen aus QR-Codes in den Bildern (EduQrCode). Fuer beide gilt
     * dasselbe: nur eine Ebene tief, nur bis EDU_GEFUNDEN_MAX, und nur was sich
     * wirklich abrufen laesst — sonst stuende eine tote Adresse in der Liste.
     *
     * @param list<string> $adressen
     */
    private function EduGefundeneAufnehmen(array $seite, array $adressen): int
    {
        if ($adressen === [] || !(bool)$this->EduProp('EduFollowLinks', false)) {
            return 0;
        }
        $bekannt = [];
        foreach (array_merge($this->EduSeiten(), $this->EduGefundene()) as $s) {
            $bekannt[rtrim((string)$s['url'], '/')] = true;
        }
        /* Gesperrte Seiten wie bekannte behandeln: dann greift der `continue`
           unten unveraendert — und der teure AiFetchPublicPage-Abruf, der den
           Namen der Seite holt, findet gar nicht erst statt. Beide Zubringer
           (Verweise im Text und QR-Codes) muenden hier, es genuegt also diese
           eine Stelle. */
        foreach ($this->EduGesperrteSeiten() as $b) {
            $u = rtrim((string)($b['url'] ?? ''), '/');
            if ($u !== '') {
                $bekannt[$u] = true;
            }
        }
        $liste = $this->EduGefundene();
        $neu = 0;
        foreach ($adressen as $url) {
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
            $liste[] = ['name' => $name !== '' ? $name : $this->Translate('Linked page'),
                        'url' => $url, 'userId' => (string)$seite['userId'], 'von' => (string)$seite['name']];
            $bekannt[$url] = true;
            $neu++;
        }
        if ($neu > 0) {
            @$this->WriteAttributeString('EduFound', (string)json_encode($liste, JSON_UNESCAPED_UNICODE));
        }
        return $neu;
    }

    // ─────────────────────────────── Kleinkram ───────────────────────────────

    /** @return list<array{name:string,url:string,userId:string}> */
    /**
     * Der Knopf im Formular.
     *
     * Bedient ein Scanner die Klassenseiten, wird hier nur ein AUFTRAG
     * abgelegt — der Lauf dauert Minuten, und das Formular haengt so lange am
     * Knopf. Der Bericht kommt spaeter mit dem Umschlag und landet in derselben
     * Statuszeile; das Formular zeigt ihn beim naechsten Oeffnen.
     */
    private function EduVonHand(bool $alles): string
    {
        if (!$this->ScanQuelleUebernommen('edu')) {
            return $this->EduScanRun(true, $alles);
        }
        if (!$this->EduAuftragGeben('hand', $alles)) {
            return $this->Translate('Nothing to scan — no page entered, or class pages are off.');
        }
        return $this->Translate('Order placed — the scanner is working on it. '
            . 'The report appears here when it is done.');
    }

    /**
     * Einen Klassenseiten-Lauf an den Scanner geben.
     *
     * Die Seiten reisen MIT dem Auftrag. Sie stehen als Eigenschaft an dieser
     * Instanz, und `IPS_GetProperty` auf eine fremde Instanz zeigt einen nur
     * eingetippten, noch nicht uebernommenen Wert nicht — der Scanner koennte
     * sie also weder lesen noch merken, ob sie aktuell sind.
     *
     * Die Sperrliste wird HIER angewandt und nicht dort: sie steht als
     * `blocked` im Bestand und wird aus dem Hook geschrieben, wenn jemand in
     * der App einen Seitenordner loescht. Eine zweite Probe beim Einpflegen
     * faengt, was sich zwischen Auftrag und Ergebnis noch aendert.
     *
     * @param string $anlass 'timer' | 'hand'
     */
    private function EduAuftragGeben(string $anlass, bool $alles = false): bool
    {
        if (!$this->EduIsEnabled()) {
            return false;
        }
        $seiten = $this->EduSeiten();
        /* Die verlinkten Seiten hinten an — wie im eigenen Lauf. Von ihnen aus
           wird kein Verweis weiterverfolgt; die Kette bleibt eine Ebene tief. */
        foreach ($this->EduGefundene() as $g) {
            $seiten[] = ['name' => (string)$g['name'], 'url' => (string)$g['url'],
                         'userId' => (string)($g['userId'] ?? '')];
        }
        $vorher = count($seiten);
        $seiten = array_values(array_filter($seiten,
            fn(array $s): bool => !$this->EduGesperrt((string)$s['url'])));
        if (count($seiten) < $vorher) {
            $this->SendDebug('EduMaps', sprintf('%d gesperrte Seite(n) nicht beauftragt',
                $vorher - count($seiten)), 0);
        }
        if ($seiten === []) {
            return false;
        }
        return $this->ScanAuftragGeben('edu', [
            'anlass' => $anlass,
            'alles'  => $alles,
            'seiten' => $seiten,
        ]);
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

    /**
     * Einen Merker wieder wegnehmen.
     *
     * Gebraucht, seit die Auswertung ein AUFTRAG ist: gemerkt wird beim
     * Einreihen, denn sonst reihte der naechste Lauf dieselbe Karte noch einmal
     * ein und zahlte doppelt. Scheitert der Auftrag aber, waere die Karte fuer
     * immer als gesehen abgelegt, ohne je ausgewertet worden zu sein — der
     * Elternbrief kaeme nie wieder, nur „Alles auswerten" holte ihn zurueck.
     * Der synchrone Weg hatte das Problem nicht: dort hiess „fertig" wirklich
     * fertig.
     */
    private function EduVergessen(string $topf, string $schluessel): void
    {
        $karte = $this->EduSeenKarte();
        if (!isset($karte[$topf])) {
            return;
        }
        $liste = array_values(array_filter(array_map('strval', (array)$karte[$topf]),
            static fn(string $s): bool => $s !== $schluessel));
        if ($liste === []) {
            unset($karte[$topf]);
        } else {
            $karte[$topf] = $liste;
        }
        @$this->WriteAttributeString('EduSeen', (string)json_encode($karte, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Ein gescheiterter Auswerte-Auftrag: den Merker zuruecknehmen, damit die
     * Karte beim naechsten Lauf wieder drankommt.
     *
     * @param array<string,mixed> $merker aus der Herkunft des Auftrags
     */
    private function EduMerkerZuruecknehmen(array $merker): void
    {
        $topf = trim((string)($merker['topf'] ?? ''));
        $schluessel = trim((string)($merker['schluessel'] ?? ''));
        if ($topf === '' || $schluessel === '') {
            return;
        }
        /* In den RICHTIGEN Merker zurueck. Es gibt zwei Bestaende, und wer
           hier den falschen anfasst, erreicht zweierlei auf einmal: die
           gescheiterte Karte bleibt fuer immer als „gesehen" liegen (der
           Elternbrief kaeme nie wieder), und eine fremde, erfolgreich
           ausgewertete Karte laeuft beim naechsten Lauf noch einmal durch die
           KI. */
        if ((string)($merker['quelle'] ?? '') === 'moodle') {
            $this->MoodleVergessen($topf, $schluessel);
        } else {
            $this->EduVergessen($topf, $schluessel);
        }
        $this->SendDebug('EduMaps', 'Auswertung gescheitert — Karte kommt wieder dran: '
            . $schluessel, 0);
    }
}
