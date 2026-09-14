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
            $this->UpdateFormField('EduStatusLabel', 'caption', $this->EduScanRun(true, true));
            return true;
        }
        if ($Ident === 'EduScanNow') {
            $bericht = $this->EduScanRun(true);
            $this->UpdateFormField('EduStatusLabel', 'caption', $bericht);
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
        $topf = 'edu:' . md5($seite['url']);
        $ersterLauf = !$this->EduTopfHatEintraege($topf);
        $geaendert = 0;
        $analysiert = 0;
        $gedeckelt = false;
        /* Spiegeln zuerst und fuer die GANZE Seite auf einmal: es kostet keinen
           KI-Aufruf, haengt also an keinem Deckel und auch nicht daran, ob eine
           Karte als „geaendert" gilt. Die Notiz selbst entscheidet je Karte, ob
           es etwas zu tun gibt (srcRev).

           In EINEM Schreibvorgang, nicht in einem je Karte: der Bestand wird
           bis zu ein Megabyte gross, und er lag in derselben Spur, die die App
           bedient. Fuenfundfuenfzig Karten waren fuenfundfuenfzig Mal alles. */
        $gespiegelt = $this->EduSeiteSpiegeln($seite, $karten);
        foreach ($karten as $nr => $karte) {
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
            if ($this->EduKarteAnalysieren($seite, $karte)) {
                $this->EduMerken($topf, $schluessel);
                $analysiert++;
            }
        }
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
        /* Edumaps haengt hinter jeden Verweis das Woertchen „LINK" und ein
           Icon-Element. Auf der Seite ist das Wort fuer den Screenreader da und
           per CSS versteckt; hier faellt das CSS weg, und es stand als nackter
           Text in der Notiz. Das Icon wiederum verliert in der Weissliste seine
           Klasse und blieb als leeres <i></i> uebrig — beides zusammen ergab
           „… </a> LINK". Also: das Wort raus, dem Icon seine Klasse zurueck.
           Am Bestand gemessen (06.09.2026): 10 von 10 Vorkommen folgen genau
           diesem Muster, keines steht ohne vorangehenden Verweis. Aendert
           Edumaps die Beschriftung, greift die Zeile nicht mehr — dann steht
           dort wieder das Wort, es geht aber nichts kaputt. */
        $h = (string)preg_replace(
            '#</a>\s*LINK\s*(?:<i>\s*</i>)?#u',
            '</a> <i class="fa-light fa-link"></i>',
            $h
        );
        // Uebrige leere Inline-Elemente sind ausgezogene Icons — sie zeigen
        // nichts und kosten nur Platz im Bestand.
        $h = (string)preg_replace('#<(i|b|u|em|strong)>\s*</\1>#u', '', $h);
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
    private function EduNotizAnhaenge(array $karte): array
    {
        $raus = [];
        $speicherVorher = (string)@ini_get('memory_limit');
        @ini_set('memory_limit', '192M');
        try {
            foreach ((array)$karte['anhaenge'] as $a) {
                if (count($raus) >= EduStoreCalc::ATTACH_MAX) {
                    $this->SendDebug('EduMaps', 'Mehr als ' . EduStoreCalc::ATTACH_MAX
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
        if (!(bool)$this->EduProp('EduToNotes', false) || !$this->EduStorable()) {
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
                $aktuell['edu:' . (string)$k['boxid']] = true;
            }
            $jetzt = time();
            $neu = 0;
            $zurueck = 0;
            foreach ($store['notes'] as $i => $n) {
                if ((string)($n['folderId'] ?? '') !== $ordnerId
                    || (string)($n['source'] ?? '') !== 'edumaps') {
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
            /* Der Buchungskasten fliegt aus dem TEXT: „Buchen 12 / 16" stand
               sonst als erste Zeile der Notiz — und dieselbe Angabe steht
               darunter noch einmal als eigene Zeile (EduBuchung → booking).
               Die Zahlen holt EduBuchung aus den data-Attributen der Karte,
               nicht von hier; das Ausschneiden nimmt ihr also nichts weg. */
            $rumpf = $this->EduBlockRaus($rumpf, 'booking-wrap');
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
                'buchung'   => $this->EduBuchung($teil),
            ];
            if (count($karten) >= self::EDU_KARTEN_MAX) {
                break;
            }
        }
        return $karten;
    }

    /**
     * Ein `div` samt Inhalt aus dem Markup schneiden, erkannt an seiner Klasse.
     *
     * BEWUSST kein regulaerer Ausdruck: die Kaesten von Edumaps sind
     * verschachtelt, und `<div class="x">.*?</div>` schnitte am ERSTEN
     * schliessenden Tag ab — der Rest des Blocks bliebe stehen, und die Karte
     * verloere dazu ihr schliessendes Tag. Deshalb wird gezaehlt: von der
     * Fundstelle an jedes `<div` hoch, jedes `</div>` runter, und beim
     * Nullpunkt ist der Block zu Ende.
     */
    private function EduBlockRaus(string $html, string $klasse): string
    {
        while (preg_match('#<div[^>]*class="[^"]*\b' . preg_quote($klasse, '#') . '\b[^"]*"[^>]*>#i',
                $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $start = (int)$m[0][1];
            $pos   = $start + strlen((string)$m[0][0]);
            $tiefe = 1;
            $laenge = strlen($html);
            while ($tiefe > 0 && $pos < $laenge) {
                $auf = stripos($html, '<div', $pos);
                $zu  = stripos($html, '</div>', $pos);
                if ($zu === false) {
                    return $html;                 // unausgeglichen — lieber nichts anfassen
                }
                if ($auf !== false && $auf < $zu) {
                    $tiefe++;
                    $pos = $auf + 4;
                } else {
                    $tiefe--;
                    $pos = $zu + 6;
                }
            }
            if ($tiefe !== 0) {
                return $html;
            }
            $html = substr($html, 0, $start) . substr($html, $pos);
        }
        return $html;
    }

    /**
     * Die Buchungslage einer Karte — Plaetze, Preis, Zeit.
     *
     * Manche Karten sind buchbar (AG-Wahl, Elternsprechtag). Der „Buchen"-Knopf
     * der Seite ist ein <span> mit JavaScript dahinter, kein Verweis; buchen
     * kann man nur DORT. Die Zahlen dazu stehen aber offen im Markup, und genau
     * die sind beim Waehlen interessant: „12 von 16" sagt, dass es eng wird,
     * „16 von 16", dass man sich den Weg sparen kann.
     *
     * Uebernommen wird nur, was eine echte Buchung kennzeichnet: ein Limit > 0.
     * „Ansprechpartnerin" traegt zwar dieselben Attribute, aber alle auf 0 — das
     * ist keine buchbare Karte, sondern nur dasselbe Kastenformat.
     *
     * @return array{anzahl:int,limit:int,preis:float,zeit:string}|null
     */
    private function EduBuchung(string $teil): ?array
    {
        $zahl = static function (string $name) use ($teil): string {
            return preg_match('/data-book' . $name . '="([^"]*)"/', $teil, $m) === 1 ? trim($m[1]) : '';
        };
        $limit = (int)$zahl('limit');
        if ($limit <= 0) {
            return null;
        }
        return [
            'anzahl' => max(0, (int)$zahl('count')),
            'limit'  => $limit,
            'preis'  => (float)str_replace(',', '.', $zahl('price')),
            'zeit'   => mb_substr($zahl('time'), 0, 60),
        ];
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
        /* Der Rumpf der Adresse wird MITGENOMMEN statt fest verdrahtet: edumaps
           laeuft je Bundesland auf einem eigenen Namen (nrw., hh., …). Mit fest
           „nrw." zeigten Datei- und Vorschauadresse anderswo ins Leere — und
           zwar lautlos, denn gefunden wurde die Datei ja. */
        if (preg_match_all('#(https://[^"\']+?)/file/([^"\'/]+)/([a-z0-9]+)(?:/(?:preview|fd))?#i',
                $rumpf, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $treffer) {
                $basis = rtrim((string)$treffer[1], '/');
                $name = urldecode((string)$treffer[2]);
                $schluessel = $name . '/' . $treffer[3];
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
                           'url'  => $basis . '/file/' . $treffer[2] . '/' . $treffer[3] . '/fd',
                           /* edumaps rendert von jedem PDF eine Seitenvorschau
                              und liefert sie unter „/preview" (gemessen:
                              180×255 JPEG, rund 11 KB). Selbst rendern koennten
                              wir sie nicht — dafuer fehlt in Symcon ein
                              PDF-Renderer. */
                           'preview' => $basis . '/file/' . $treffer[2] . '/' . $treffer[3] . '/preview'];
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
        /* Dasselbe „LINK" wie in EduHtml(): auf der Seite eine per CSS
           versteckte Beschriftung fuer den Screenreader, im Klartext ein
           sinnloses Wort hinter jedem Verweis. Ein Icon gibt es hier nicht — es
           faellt ersatzlos weg.
           Geprueft wird die ZEILE, nicht die Nachbarschaft zum </a>: im
           Rohmarkup steht die Beschriftung in einer eigenen Huelle, das </a>
           ist also nicht der direkte Vorgaenger (in der bereinigten Fassung
           schon — daher die andere Regel dort). Nach dem Umbruch-Ersatz steht
           sie als eigene Zeile da. Eine Zeile, die nur aus diesem Wort besteht,
           traegt keine Aussage; ein „LINK" im Satz bleibt unberuehrt. */
        $t = preg_replace('/^[ \t]*LINK[ \t]*$/mu', '', $t) ?? $t;
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
        // Jeder edumaps-Name, nicht nur „nrw." — jedes Bundesland hat seinen
        // eigenen, und eine Anlage aus Hamburg verwies sonst auf nichts.
        if (preg_match_all('#https://[a-z0-9.-]+\.edumaps\.de/(\d+)/(\d+)/([a-z0-9]+)/([a-z0-9]+)#i',
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
