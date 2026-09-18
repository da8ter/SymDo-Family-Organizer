<?php

declare(strict_types=1);

/**
 * LOGINEO NRW LMS (Moodle) — Transport und Zugang.
 *
 * LOGINEO NRW LMS ist Moodle; der Web-Service der Moodle-App („moodle_mobile_app")
 * ist auf den geprüften Installationen eingeschaltet und gibt gegen Benutzer und
 * Kennwort einen Token her. Damit liest dieses Modul, was sonst abgetippt wird:
 * Elternpost, Lernzeitpläne, Wochenpläne, Forumsbeiträge — und, wo die Schule sie
 * pflegt, Aufgaben mit Fälligkeit und Termine.
 *
 * Gerüst nach dem Vorbild von WebUntis.php (Create/ApplyChanges/RequestAction/
 * ScanRun/Prop/StatusSchreiben), Rechenwerk in MoodleCalc.php.
 *
 * DREI ENTSCHEIDUNGEN, die alles andere bestimmen:
 *
 * 1. Es wird NUR gelesen. Auf dem geprüften Schulkonto sind auch
 *    Schreibfunktionen freigegeben; jeder Aufruf läuft deshalb durch die weisse
 *    Liste in MoodleCalc::ERLAUBT und wird sonst abgewiesen — nicht als
 *    Höflichkeit, sondern damit ein Tippfehler nichts in der Schule anrichtet.
 * 2. Ein Konto JE KIND. LOGINEO kennt keine Elternzugänge: jedes Kind hat einen
 *    eigenen Login. Die Zugänge stehen deshalb als Liste da, nicht als ein Paar
 *    Felder — und ein Fehler bei einem Kind hält die anderen nicht an.
 * 3. Gespeichert wird NUR der Token, nie das Kennwort. Das Kennwort steht einmal
 *    im Formular, der Knopf tauscht es gegen den Token und leert das Feld. Der
 *    Token ist in Moodle widerrufbar; ein Kennwort ist es nicht. Nebenwirkung:
 *    der Takt meldet sich nie an, also kann er auch kein Konto sperren.
 */
trait Moodle
{
    private const MOODLE_INTERVALL_STD = 6;               // Stunden zwischen Läufen
    /* Deckel je Datei. AiFetchPublicPage deckelt bei 2 MB und BRICHT dann ab —
       die gemessene Elternabend-Präsentation hat 6,3 MB. Was darüber liegt,
       wird als Verweis vermerkt und nicht abgelegt. */
    private const MOODLE_DATEI_MAX     = 8388608;
    /* So viele Fehlschläge je Zugang, dann ruht er bis zum nächsten Griff von
       Hand. Ein toter Token soll nicht sechsmal am Tag angeklopft werden. */
    private const MOODLE_FEHLER_MAX    = 3;
    /* Wie viele Karten je Lauf an die KI gehen, steht nicht mehr hier: beide
       Quellen teilen sich die Auswerteschleife, und damit `EDU_JE_LAUF_MAX`.
       Die Werte waren ohnehin gleich (5). */
    private const MOODLE_TEXT_MAX      = 8000;
    /* Anhaenge fuer die KI, zusammen als Base64. Groesseres schickt niemand
       durch: die Auswertung soll an einer Datei nicht scheitern. */
    private const MOODLE_ANHANG_MAX_B64 = 8000000;

    /** Token je Zugang, im Lauf gehalten: Schlüssel → Token. */
    private array $moodleTokenCache = [];
    private ?array $moodleConfigCache = null;

    // ────────────────────────────── Lebenszyklus ──────────────────────────────

    private function MoodleCreate(): void
    {
        $this->RegisterPropertyBoolean('MoodleEnabled', false);
        /* Je Kind eine Zeile: Anzeigename, Adresse der Schule, Benutzername,
           Familienmitglied, optional die Ziel-Stundenplaninstanz. Der TOKEN
           steht bewusst nicht hier — er gehört ins Attribut, damit er nicht in
           einer Liste steht, die man versehentlich weiterschickt. */
        $this->RegisterPropertyString('MoodleAccounts', '[]');
        $this->RegisterPropertyInteger('MoodleIntervalHours', self::MOODLE_INTERVALL_STD);
        /* Kennwortfeld des Formulars. Es ist eine EIGENSCHAFT und kein freies
           Feld, weil ein Formularfeld ohne Eigenschaft „Übernehmen" für die
           ganze Konfiguration scheitern lässt. Der Token-Knopf leert es sofort
           wieder (UpdateFormField) — gespeichert wird es also nur, wenn jemand
           dazwischen „Übernehmen" drückt. */
        $this->RegisterPropertyString('MoodlePassword', '');
        $this->RegisterPropertyString('MoodleAccountPick', '');
        // Was übernommen werden soll — die Stufen 2 bis 4 hängen daran.
        $this->RegisterPropertyBoolean('MoodleToCards', true);
        /* Neue Dokumente durch die KI schicken. Eigener Schalter, weil es Geld
           kostet — der Spiegel darueber kostet keinen Aufruf. */
        $this->RegisterPropertyBoolean('MoodleAnalyse', true);
        $this->RegisterPropertyBoolean('MoodleHomework', true);
        $this->RegisterPropertyBoolean('MoodleEvents', true);
        $this->RegisterPropertyBoolean('MoodlePush', true);
        // Token je Zugang: Schlüssel (Adresse|Mitglied) → {token, at, user, userid}
        $this->RegisterAttributeString('MoodleTokens', '{}');
        // Was schon gesehen wurde: Schlüssel → Fassung, damit nur Neues arbeitet.
        $this->RegisterAttributeString('MoodleSeen', '{}');
        // Fehlschläge JE ZUGANG — ein Kind darf die Geschwister nicht anhalten.
        $this->RegisterAttributeString('MoodleFails', '{}');
        $this->RegisterAttributeString('MoodleStatus', '{}');
        $this->RegisterTimer('MoodleScan', 0,
            'IPS_RequestAction($_IPS[\'TARGET\'], \'MoodleScan\', 0);');
    }

    private function MoodleApplyChanges(): void
    {
        $this->moodleConfigCache = null;
        $stunden = max(1, (int)$this->MoodleProp('MoodleIntervalHours', self::MOODLE_INTERVALL_STD));
        $an = $this->MoodleIsEnabled() && $this->MoodleKonten() !== [];
        $this->SetTimerInterval('MoodleScan', $an ? $stunden * 3600000 : 0);
    }

    private function MoodleRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident === 'MoodleScan') {
            $this->MoodleScanRun();
            return true;
        }
        if ($Ident === 'MoodleScanNow') {
            /* Trockenlauf: lesen und berichten, nichts ablegen und nichts
               melden. Er bleibt AUCH NACH DER UEBERGABE hier — und das ist eine
               Entscheidung, keine Nachlaessigkeit: der Knopf soll pruefen, ob
               Zugang und Token stimmen, und darauf wartet jemand vor dem
               Formular. Ein Auftrag an den Scanner haette zwei Nachteile: die
               Antwort kaeme Minuten spaeter in die Statuszeile, und er wuerde
               wirklich SCHREIBEN — genau das, was ein Trockenlauf nicht tut.
               Der Preis ist bekannt: dieser eine Knopf belegt die Gateway-Spur,
               solange er laeuft. */
            $this->UpdateFormField('MoodleStatusLabel', 'caption', $this->MoodleScanRun(true));
            return true;
        }
        if ($Ident === 'MoodleToken') {
            $this->UpdateFormField('MoodleStatusLabel', 'caption',
                $this->MoodleTokenHolen((string)$Value));
            return true;
        }
        if ($Ident === 'MoodleTokenWeg') {
            $this->UpdateFormField('MoodleStatusLabel', 'caption',
                $this->MoodleTokenEntfernen((string)$Value));
            return true;
        }
        if ($Ident === 'MoodleForget') {
            @$this->WriteAttributeString('MoodleSeen', '{}');
            @$this->WriteAttributeString('MoodleFails', '{}');
            $this->UpdateFormField('MoodleStatusLabel', 'caption',
                $this->Translate('Memory cleared — the next check counts as the first one.'));
            return true;
        }
        return false;
    }

    // ─────────────────────────────── Zugänge ───────────────────────────────

    private function MoodleIsEnabled(): bool
    {
        return (bool)$this->MoodleProp('MoodleEnabled', false);
    }

    /**
     * Gelesen über IPS_GetConfiguration statt ReadPropertyX: die Eigenschaften
     * entstehen in Create() und existieren erst beim nächsten Kernel-Start.
     * ReadPropertyX liefert bis dahin eine PHP-Warnung — und die zerlegt im
     * Hook die HTTP-Antwort.
     */
    private function MoodleProp(string $name, mixed $vorgabe): mixed
    {
        if ($this->moodleConfigCache === null) {
            $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
            $this->moodleConfigCache = is_array($cfg) ? $cfg : [];
        }
        return array_key_exists($name, $this->moodleConfigCache)
            ? $this->moodleConfigCache[$name] : $vorgabe;
    }

    /** @return list<array{name:string,site:string,user:string,userId:string,stpl:int}> */
    private function MoodleKonten(): array
    {
        return MoodleCalc::Konten(json_decode((string)$this->MoodleProp('MoodleAccounts', '[]'), true));
    }

    /** Der Anzeigename eines Zugangs: der des Mitglieds, sonst der eingetragene. */
    private function MoodleName(array $zugang): string
    {
        $mitglieder = [];
        try {
            foreach ($this->LoadUsers() as $u) {
                $mitglieder[(string)($u['id'] ?? '')] = trim((string)($u['name'] ?? ''));
            }
        } catch (\Throwable $e) {
            // ohne Mitgliederliste bleibt der eingetragene Name
        }
        $name = trim((string)($mitglieder[$zugang['userId']] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $eigen = trim((string)($zugang['name'] ?? ''));
        return $eigen !== '' ? $eigen : ('#' . $zugang['userId']);
    }

    /** @return array<string,array<string,mixed>> */
    private function MoodleTokenListe(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('MoodleTokens'), true);
        return is_array($roh) ? $roh : [];
    }

    private function MoodleTokenVon(array $zugang): string
    {
        $k = MoodleCalc::TokenSchluessel((string)$zugang['site'], (string)$zugang['userId']);
        if (array_key_exists($k, $this->moodleTokenCache)) {
            return $this->moodleTokenCache[$k];
        }
        $liste = $this->MoodleTokenListe();
        $t = trim((string)(($liste[$k]['token']) ?? ''));
        $this->moodleTokenCache[$k] = $t;
        return $t;
    }

    /**
     * Einen Zugang aus der Eigenschaft um seinen Token ergaenzen.
     *
     * Der Leser (`MoodleLesen`) kennt keinen Tokenspeicher — er laeuft auch in
     * einer Scanner-Instanz und bekommt den Token MIT dem Zugang. Jeder Weg,
     * der hier im Gateway einen Zugang aus `MoodleKonten()` an den Leser gibt,
     * muss ihn vorher hier durchreichen. Bis zum 18.09.2026 taten das weder
     * der synchrone Lauf noch die Zugangspruefung: beide prueften, DASS ein
     * Token gespeichert ist, gaben dann aber den Zugang ohne ihn weiter — und
     * der Leser meldete „kein Token", der Lauf „nichts lesbar", die Pruefung
     * „der Token funktioniert nicht". Nur der Auftragsweg (MoodleAuftragGeben)
     * legte ihn dazu. Gefunden vom externen Codereview (F10).
     *
     * Ein schon vorhandener Token bleibt: der Auftragsweg traegt ihn selbst.
     */
    private function MoodleMitToken(array $zugang): array
    {
        if (trim((string)($zugang['token'] ?? '')) === '') {
            $zugang['token'] = $this->MoodleTokenVon($zugang);
        }
        return $zugang;
    }

    /**
     * Kennwort gegen Token tauschen — der einzige Ort, an dem sich dieses Modul
     * überhaupt anmeldet.
     *
     * Die Nutzlast kommt aus dem Formular (Feldvariablen im onClick), damit das
     * Kennwort nicht durch eine Eigenschaft muss. Danach wird das Feld geleert;
     * gespeichert wird nur der Token.
     */
    private function MoodleTokenHolen(string $json): string
    {
        $req  = json_decode($json, true);
        $wahl = trim((string)(is_array($req) ? ($req['account'] ?? '') : ''));
        $pass = (string)(is_array($req) ? ($req['password'] ?? '') : '');
        $zugang = null;
        foreach ($this->MoodleKonten() as $k) {
            if ($k['userId'] === $wahl) {
                $zugang = $k;
                break;
            }
        }
        if ($zugang === null) {
            return $this->Translate('Pick a row first — and press „Apply" after adding one.');
        }
        if (trim((string)$zugang['user']) === '' || $pass === '') {
            return $this->Translate('User and password are needed once — the token replaces them afterwards.');
        }

        $antwort = $this->MoodleHttp((string)$zugang['site'] . '/login/token.php', [
            'username' => (string)$zugang['user'],
            'password' => $pass,
            'service'  => MoodleCalc::SERVICE,
        ]);
        // Das Kennwort ist verbraucht: Feld leeren, bevor irgendetwas anderes passiert.
        $this->UpdateFormField('MoodlePassword', 'value', '');
        if ($antwort === null) {
            return $this->Translate('The server did not answer.');
        }
        $d = json_decode((string)$antwort['body'], true);
        if (!is_array($d)) {
            return sprintf($this->Translate('No usable answer (HTTP %d).'), (int)$antwort['status']);
        }
        $token = trim((string)($d['token'] ?? ''));
        if ($token === '') {
            /* Moodle nennt hier den Grund: falsches Kennwort, Dienst nicht
               eingeschaltet, Konto gesperrt. Der Text gehört in die Statuszeile —
               sonst rät der Nutzer. */
            return sprintf($this->Translate('No token: %s'),
                trim((string)($d['error'] ?? ($d['errorcode'] ?? '?'))));
        }
        $liste = $this->MoodleTokenListe();
        $liste[MoodleCalc::TokenSchluessel((string)$zugang['site'], (string)$zugang['userId'])] = [
            'token' => $token,
            'at'    => time(),
            'user'  => (string)$zugang['user'],
        ];
        @$this->WriteAttributeString('MoodleTokens', (string)json_encode($liste, JSON_UNESCAPED_UNICODE));
        $this->moodleTokenCache = [];
        // Fehlerzähler dieses Zugangs zurücksetzen: ein frischer Token ist ein Neuanfang.
        $this->MoodleFehlerLoesen($zugang);

        /* Gleich nachsehen, ob der Token auch trägt — und was das Konto hergibt.
           Ein „Token geholt" ohne diese Probe wäre eine halbe Auskunft. */
        return $this->Translate('Token stored, password field cleared — please press „Apply".')
            . ' ' . $this->MoodleZugangPruefen($zugang);
    }

    /**
     * Einen gespeicherten Token wegnehmen.
     *
     * Es braucht diesen Weg: ein Kind wechselt die Schule, ein Token wird in
     * Moodle widerrufen, oder man will einfach aufräumen. „Merker leeren" tut
     * das ausdrücklich NICHT — der Merker sagt, was schon gesehen wurde, und
     * dabei den Zugang zu verlieren wäre eine Überraschung.
     */
    private function MoodleTokenEntfernen(string $json): string
    {
        $req  = json_decode($json, true);
        $wahl = trim((string)(is_array($req) ? ($req['account'] ?? '') : ''));
        if ($wahl === '') {
            return $this->Translate('Pick a row first — and press „Apply" after adding one.');
        }
        $liste = $this->MoodleTokenListe();
        $weg = 0;
        foreach ($liste as $k => $e) {
            /* Der Schlüssel endet auf „|<Mitglied>". Ein Kind an zwei Schulen
               hat zwei Zugänge — dann gehen beide, denn gewählt wird das KIND. */
            if (str_ends_with((string)$k, '|' . $wahl)) {
                unset($liste[$k]);
                $weg++;
            }
        }
        if ($weg === 0) {
            return $this->Translate('There was no token for this row.');
        }
        @$this->WriteAttributeString('MoodleTokens', (string)json_encode($liste, JSON_UNESCAPED_UNICODE));
        $this->moodleTokenCache = [];
        return sprintf($this->Translate('%d token(s) removed — remember to revoke them in Moodle as well.'), $weg);
    }

    /**
     * Was gibt dieses Konto her? Standortauskunft, fehlende Funktionen, Kurse.
     * Reines Lesen, drei Aufrufe.
     */
    private function MoodleZugangPruefen(array $zugang): string
    {
        $zugang = $this->MoodleMitToken($zugang);
        $info = $this->MoodleRest($zugang, 'core_webservice_get_site_info');
        if (!is_array($info)) {
            return $this->Translate('The token does not work — fetch a new one.');
        }
        $fehlt = MoodleCalc::FehlendeFunktionen(array_map(
            static fn($f): string => (string)($f['name'] ?? ''),
            (array)($info['functions'] ?? [])));
        $teile = [sprintf($this->Translate('Moodle %s, %d function(s) released'),
            (string)($info['release'] ?? '?'), count((array)($info['functions'] ?? [])))];
        if ($fehlt !== []) {
            $teile[] = sprintf($this->Translate('missing: %s'), implode(', ', $fehlt));
        }
        $kurse = $this->MoodleRest($zugang, 'core_enrol_get_users_courses',
            ['userid' => (int)($info['userid'] ?? 0)]);
        if (is_array($kurse)) {
            $namen = [];
            foreach ($kurse as $k) {
                $n = trim((string)($k['shortname'] ?? ($k['fullname'] ?? '')));
                if ($n !== '') {
                    $namen[] = $n;
                }
            }
            $teile[] = $namen === []
                ? $this->Translate('no courses on this account')
                : sprintf($this->Translate('%1$d course(s): %2$s'), count($namen),
                    implode(', ', array_slice($namen, 0, 5)) . (count($namen) > 5 ? ' …' : ''));
        }
        return implode(', ', $teile);
    }

    // ─────────────────────────────── Der Draht ───────────────────────────────

    // ────────────────────── Fehlerzähler, je Zugang ──────────────────────

    /** @return array<string,array{fails:int,at:int}> */
    private function MoodleFehlerListe(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('MoodleFails'), true);
        return is_array($roh) ? $roh : [];
    }

    private function MoodleRuht(array $zugang): bool
    {
        $k = MoodleCalc::TokenSchluessel((string)$zugang['site'], (string)$zugang['userId']);
        $e = $this->MoodleFehlerListe()[$k] ?? null;
        return is_array($e) && (int)($e['fails'] ?? 0) >= self::MOODLE_FEHLER_MAX;
    }

    private function MoodleFehlerZaehlen(array $zugang): void
    {
        $k = MoodleCalc::TokenSchluessel((string)$zugang['site'], (string)$zugang['userId']);
        $liste = $this->MoodleFehlerListe();
        $n = (int)(($liste[$k]['fails']) ?? 0) + 1;
        $liste[$k] = ['fails' => $n, 'at' => time()];
        @$this->WriteAttributeString('MoodleFails', (string)json_encode($liste, JSON_UNESCAPED_UNICODE));
        if ($n >= self::MOODLE_FEHLER_MAX) {
            $this->LogMessage('SymDo: LOGINEO-Zugang „' . $this->MoodleName($zugang)
                . '" ruht nach ' . $n . ' Fehlschlägen — Token neu holen.', KL_WARNING);
        }
    }

    private function MoodleFehlerLoesen(array $zugang): void
    {
        $k = MoodleCalc::TokenSchluessel((string)$zugang['site'], (string)$zugang['userId']);
        $liste = $this->MoodleFehlerListe();
        if (array_key_exists($k, $liste)) {
            unset($liste[$k]);
            @$this->WriteAttributeString('MoodleFails', (string)json_encode($liste, JSON_UNESCAPED_UNICODE));
        }
    }

    // ─────────────────────────────── Der Lauf ───────────────────────────────

    /**
     * Alle Zugänge der Reihe nach. Stufe 1 liest und berichtet; das Spiegeln,
     * die Aufgaben und die Termine kommen in den Stufen darauf.
     *
     * @param bool $trocken nichts ablegen, nichts melden
     */
    private function MoodleScanRun(bool $trocken = false): string
    {
        if (!$this->MoodleIsEnabled()) {
            return $this->Translate('LOGINEO is switched off.');
        }
        $konten = $this->MoodleKonten();
        if ($konten === []) {
            return $this->Translate('No access entered yet.');
        }

        /* Bedient ein Scanner LOGINEO, wird hier nur noch ein AUFTRAG abgelegt.
           Der Zeitgeber bleibt dabei AN — er ist die einzige Uhr dieses Laufs;
           der Scanner arbeitet nur ab, was im Kanal liegt. Dieselbe
           Ueberlegung wie bei den Klassenseiten, und derselbe Fehler, der dort
           beinahe stehen geblieben waere. Auftraggeben kostet Millisekunden:
           eine Datei und ein Zaehler. */
        if (!$trocken && $this->ScanQuelleUebernommen('moodle')) {
            return $this->MoodleAuftragGeben('timer')
                ? $this->Translate('Order placed — the scanner is working on it. '
                    . 'The report appears here when it is done.')
                : $this->Translate('No access entered yet.');
        }

        $teile = [];
        foreach ($konten as $zugang) {
            $name = $this->MoodleName($zugang);
            if ($this->MoodleTokenVon($zugang) === '') {
                $teile[] = $name . ': ' . $this->Translate('no token — fetch one');
                continue;
            }
            /* Ein ruhender Zugang hält die anderen NICHT an. Genau dafür ist der
               Zähler je Zugang da: bei WebUntis steht er global, und dort
               bremst ein falsches Kennwort alle Kinder. */
            if ($this->MoodleRuht($zugang)) {
                $teile[] = $name . ': ' . $this->Translate('resting after repeated failures');
                continue;
            }
            $bericht = $this->MoodleKontoLesen($zugang, $trocken);
            if ($bericht === '') {
                $this->MoodleFehlerZaehlen($zugang);
                $teile[] = $name . ': ' . $this->Translate('nothing readable');
                continue;
            }
            $this->MoodleFehlerLoesen($zugang);
            $teile[] = $name . ': ' . $bericht;
        }
        $text = implode(' | ', $teile);
        if (!$trocken) {
            $this->MoodleStatusSchreiben($text);
        }
        return $text;
    }

    /**
     * Einen LOGINEO-Lauf an den Scanner geben.
     *
     * Die Zugaenge reisen MIT dem Auftrag, samt Token: er steht in einem
     * Attribut dieser Instanz, und ein Attribut ist von einer anderen aus
     * nicht zu lesen. Dasselbe gilt fuer die Sperrliste, die im
     * Klassenseiten-Bestand liegt.
     *
     * Ruhende Zugaenge bleiben hier: der Fehlerzaehler steht im Gateway, und
     * ein toter Token soll nicht sechsmal am Tag angeklopft werden.
     *
     * @param string $anlass 'timer' | 'hand'
     */
    private function MoodleAuftragGeben(string $anlass): bool
    {
        if (!$this->MoodleIsEnabled()) {
            return false;
        }
        $mit = [];
        foreach ($this->MoodleKonten() as $zugang) {
            $token = $this->MoodleTokenVon($zugang);
            if ($token === '' || $this->MoodleRuht($zugang)) {
                continue;
            }
            $mit[] = [
                'site'   => (string)$zugang['site'],
                'userId' => (string)$zugang['userId'],
                'name'   => $this->MoodleName($zugang),
                'token'  => $token,
            ];
        }
        if ($mit === []) {
            return false;
        }
        return $this->ScanAuftragGeben('moodle', [
            'anlass'   => $anlass,
            'konten'   => $mit,
            'gesperrt' => $this->MoodleGesperrte(),
        ]);
    }

    /**
     * Den Umschlag eines LOGINEO-Laufs einpflegen.
     *
     * Er traegt dieselben Felder, die eine Ernte hier auch selbst erzeugt —
     * nur kommen sie aus einer Datei und sind deshalb schon durch die weisse
     * Liste des Kanals gegangen. Gezaehlt wird nicht noch einmal: die Zahlen
     * fuer die Statuszeile stehen im Text des Scanners.
     *
     * @param array<string,mixed> $umschlag
     */
    private function MoodleUmschlagEinpflegen(array $umschlag): string
    {
        $bericht = $this->MoodleErnteEinpflegen([
            'ok'           => true,
            'kurse'        => 0,
            'karten'       => 0,
            'seiten'       => (array)($umschlag['seiten'] ?? []),
            'hausaufgaben' => (array)($umschlag['hausaufgaben'] ?? []),
            'vorschlaege'  => (array)($umschlag['vorschlaege'] ?? []),
            'gesperrt'     => 0,
            'rueckmeldungen' => 0,
        ]);
        /* Die Statuszeile gehoert ins Attribut DIESER Instanz — das
           Konfigurationsformular liest sie hier. Was der Scanner geschickt hat,
           sagt „gelesen"; was daraus wurde, weiss erst diese Haelfte. */
        $text = trim(trim((string)($umschlag['status']['text'] ?? '')) . ' ' . $bericht);
        $this->MoodleStatusSchreiben($text);
        return $text;
    }

    /**
     * Ein Konto lesen UND einpflegen — der Weg ohne Scanner.
     *
     * Zwei Schritte, die auch einzeln laufen: ernten (das Teure, kann in einer
     * Scanner-Instanz laufen) und einpflegen (schreibt, gehoert hierher).
     * Leerer Rueckgabewert heisst „nicht lesbar"; der Aufrufer zaehlt das als
     * Fehlschlag.
     */
    private function MoodleKontoLesen(array $zugang, bool $trocken): string
    {
        $ernte = $this->MoodleKontoErnten($this->MoodleMitToken($zugang), $this->MoodleGesperrte(),
            !$trocken && (bool)$this->MoodleProp('MoodleHomework', true),
            !$trocken && (bool)$this->MoodleProp('MoodleEvents', true));
        if (($ernte['ok'] ?? false) !== true) {
            return '';
        }
        return $this->MoodleErnteEinpflegen($ernte, $trocken);
    }

    /**
     * Eine Ernte in den Bestand — die schreibende Haelfte.
     *
     * Sie laeuft IMMER hier, ob die Ernte aus dem eigenen Lauf kommt oder als
     * Umschlag aus der zweiten Spur. Alles, was sie anfasst, haengt an dieser
     * Instanz: der Klassenseiten-Bestand, die Merker, die Hausaufgaben, die
     * Vorschlaege und der Tagesdeckel.
     *
     * @param array<string,mixed> $ernte
     */
    private function MoodleErnteEinpflegen(array $ernte, bool $trocken = false): string
    {
        $karten     = (int)($ernte['karten'] ?? 0);
        $neu        = 0;
        $geaendert  = 0;
        $analysiert = 0;
        $archiviert = 0;
        $gesperrt   = (int)($ernte['gesperrt'] ?? 0);
        $gedeckelt  = false;

        foreach ((array)($ernte['seiten'] ?? []) as $eintrag) {
            if (!is_array($eintrag)) {
                continue;
            }
            $seite  = is_array($eintrag['seite'] ?? null) ? $eintrag['seite'] : [];
            $liste  = (array)($eintrag['karten'] ?? []);
            if (trim((string)($seite['url'] ?? '')) === '' || $liste === []) {
                continue;
            }
            /* Ein zweites Mal: zwischen Ernte und Einpflegen koennen Minuten
               liegen, und in dieser Zeit kann jemand den Kurs in der App
               geloescht haben. */
            if ($this->EduGesperrt((string)$seite['url'])) {
                $gesperrt++;
                continue;
            }
            if ($trocken) {
                continue;
            }
            $neu += $this->EduSeiteSpiegeln($seite, $liste);
            $aus = $this->EduKartenAuswerten($seite, $liste, $analysiert, false);
            $geaendert  += $aus['geaendert'];
            $analysiert += $aus['analysiert'];
            $gedeckelt   = $gedeckelt || $aus['gedeckelt'];
            $archiviert += $this->EduArchivAbgleichen($seite, $liste);
        }

        $hausaufgaben = '';
        foreach ((array)($ernte['hausaufgaben'] ?? []) as $h) {
            if (!is_array($h) || $trocken) {
                continue;
            }
            $hausaufgaben = $this->MoodleAufgabenEinpflegen((string)($h['userId'] ?? ''),
                (array)($h['zeilen'] ?? []), (string)($h['von'] ?? ''), (string)($h['bis'] ?? ''));
        }
        $rueckmeldungen = (int)($ernte['rueckmeldungen'] ?? 0);
        if ($rueckmeldungen > 0 && !$trocken) {
            $hausaufgaben = trim($hausaufgaben . ', ' . sprintf(
                $this->Translate('%d reply/replies with a deadline'), $rueckmeldungen), ', ');
        }

        $termine = '';
        $zeilen  = (array)($ernte['vorschlaege'] ?? []);
        if ($zeilen !== [] && !$trocken) {
            $termine = $this->MoodleTermineEinpflegen(
                (string)($zeilen[0]['userId'] ?? ''), $zeilen);
        }

        /* Die GELESENEN Zahlen nur, wenn dieser Lauf sie selbst kennt. Kommt
           die Ernte als Umschlag, stehen sie schon im Text des Scanners — und
           „2 Kurs(e), 37 Karte(n) gelesen 0 Kurs(e), 0 Karte(n)" in einer Zeile
           liest sich wie ein Fehler. Am 15.09.2026 am lebenden System gesehen. */
        $gelesen = ((int)($ernte['kurse'] ?? 0) > 0 || $karten > 0)
            ? sprintf($this->Translate('%1$d course(s), %2$d card(s), '),
                (int)($ernte['kurse'] ?? 0), $karten)
            : '';
        return $gelesen . sprintf($this->Translate('%1$d written, %2$d changed, %3$d analysed%4$s'),
            $neu, $geaendert, $analysiert,
            ($archiviert > 0 ? ', ' . sprintf($this->Translate('%d archived'), $archiviert) : '')
            . ($gesperrt > 0 ? ', ' . sprintf($this->Translate('%d blocked'), $gesperrt) : '')
            . ($gedeckelt ? ' — ' . $this->Translate('daily AI limit reached, the rest follows later') : '')
            . ($hausaufgaben === '' ? '' : ', ' . $hausaufgaben)
            . ($termine === '' ? '' : ', ' . $termine)
            . ($trocken ? ' — ' . $this->Translate('dry run, nothing written') : ''));
    }

    /**
     * Die Adressen, die der Nutzer in der App geloescht hat.
     *
     * Sie stehen im Klassenseiten-Bestand (`blocked`) und muessen MIT dem
     * Auftrag reisen: der Leser kann sie sonst nicht kennen und holte die
     * Inhalte eines Kurses, den es hier gar nicht mehr geben soll.
     *
     * @return list<string>
     */
    private function MoodleGesperrte(): array
    {
        $raus = [];
        foreach ((array)($this->EduStoreRead()['blocked'] ?? []) as $b) {
            $u = trim((string)(is_array($b) ? ($b['url'] ?? '') : $b));
            if ($u !== '') {
                $raus[] = $u;
            }
        }
        return $raus;
    }

    /**
     * Eine Datei holen. Der Token haengt an der ADRESSE (`&token=`) — so will
     * es der Web-Service der Moodle-App.
     *
     * Eigener Weg und nicht AiFetchPublicPage: das deckelt bei 2 MB und BRICHT
     * dann ab. Die gemessene Elternabend-Praesentation hat 6,3 MB.
     */
    private function MoodleDatei(array $zugang, string $url): ?string
    {
        $token = $this->MoodleTokenVon($zugang);
        if ($token === '' || $url === '') {
            return null;
        }
        return $this->MoodleDateiVonUrl(
            $url . (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($token));
    }

    /** Groesse, ab der eine LOGINEO-Datei gar nicht erst geholt wird. */
    private function MoodleDateiDeckel(): int
    {
        /* Nicht groesser, als der Bestand annimmt: ein PDF darf hoechstens
           OutputLimit() Bytes haben (die Ausgabegrenze minus Reserve), sonst
           weist NotesSaveAttachment es mit „file_too_large" ab. Gemessen an
           dieser Schule: die Elternabend-Praesentation hat 6,3 MB und passt
           damit nicht — sie waere umsonst geladen worden. Die Karte behaelt
           Text und Verweis auf die Seite. */
        return min(self::MOODLE_DATEI_MAX, $this->OutputLimit());
    }

    /**
     * Dieselbe Datei, aber die Adresse traegt den Token schon.
     *
     * Getrennt, seit der Spiegel beider Quellen in `EduEinpflegen` liegt: dort
     * steht kein Zugang mehr zur Verfuegung, wohl aber die fertige Adresse an
     * der Karte. Der Groessendeckel und die Fristen bleiben, wo sie waren.
     */
    private function MoodleDateiVonUrl(string $voll): ?string
    {
        if ($voll === '') {
            return null;
        }
        if (!$this->AiIsPublicUrl($voll)) {
            $this->SendDebug('Moodle', 'Dateiadresse nicht zulaessig', 0);
            return null;
        }
        $ch = curl_init($voll);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::MOODLE_HTTP_FRIST,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'SymDo',
            // Grosse Dateien fruehzeitig abweisen, statt sie ganz zu laden.
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $soll, $ist): int {
                return ($soll > self::MOODLE_DATEI_MAX || $ist > self::MOODLE_DATEI_MAX) ? 1 : 0;
            },
        ]);
        $body   = curl_exec($ch);
        $fehler = ($body === false) ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($fehler !== '' || $status !== 200 || !is_string($body) || $body === '') {
            $this->SendDebug('Moodle', 'Datei nicht ladbar (HTTP ' . $status . ') ' . $fehler, 0);
            return null;
        }
        return $body;
    }

    /** @return array<string,list<string>> Topf → gesehene Schlüssel */
    private function MoodleSeenKarte(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('MoodleSeen'), true);
        return is_array($roh) ? $roh : [];
    }

    private function MoodleTopfHatEintraege(string $topf): bool
    {
        $k = $this->MoodleSeenKarte();
        return is_array($k[$topf] ?? null) && $k[$topf] !== [];
    }

    private function MoodleGesehen(string $topf, string $schluessel): bool
    {
        $k = $this->MoodleSeenKarte();
        return in_array($schluessel, array_map('strval', (array)($k[$topf] ?? [])), true);
    }

    private function MoodleMerken(string $topf, string $schluessel): void
    {
        $karte = $this->MoodleSeenKarte();
        $liste = array_map('strval', (array)($karte[$topf] ?? []));
        $liste[] = $schluessel;
        /* Gedeckelt je Kurs: ein Dokument, das sich staendig aendert, darf den
           Merker nicht aufblaehen. */
        if (count($liste) > 200) {
            $liste = array_slice($liste, -200);
        }
        $karte[$topf] = array_values(array_unique($liste));
        @$this->WriteAttributeString('MoodleSeen',
            (string)json_encode($karte, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Eine Karte durch dieselbe Kette wie eine Schulmail schicken.
     *
     * Aus dem Ergebnis wird ein Vorschlag im KI-Eingang, den jemand prueft und
     * uebernimmt — nichts entsteht ungefragt.
     */
    private function MoodleKarteAnalysieren(array $seite, array $karte): bool
    {
        $betreff = trim((string)$karte['abschnitt']) !== ''
            ? $karte['abschnitt'] . ' · ' . $karte['titel']
            : (string)$karte['titel'];
        $text = (string)$karte['text'];
        if (mb_strlen($text) > self::MOODLE_TEXT_MAX) {
            $text = mb_substr($text, 0, self::MOODLE_TEXT_MAX);
        }
        /* „Date" ist eine UNIX-ZEIT, kein Text: MailAnalyseRecord macht daraus
           mit (int) das Feld `at`, und danach richtet sich die 21-Tage-Grenze
           der Vorschlagsliste. Ein ISO-Text ergibt Januar 1970 — der Vorschlag
           waere sofort zu alt und unsichtbar. Dieselbe Falle wie bei den
           Klassenseiten, dort einmal gemessen. */
        $kopf = [
            'Subject'    => $seite['name'] . ' — ' . $betreff,
            'SenderName' => $seite['name'],
            'Date'       => (int)$karte['updated'],
        ];
        $vorschlag = (string)$karte['srcId'] . ':' . (int)$karte['updated'];
        $anhaenge  = $this->MoodleAnhaengeFuerKi($karte);
        /* Als AUFTRAG, sobald ein Laeufer die Warteschlange bedient — dieselbe
           Weiche wie bei den Klassenseiten. Sie fehlte hier bis zum 15.09.2026,
           und seit der Lauf ein Umschlag ist, faellt das ins Gewicht: bis zu
           fuenf Karten werden am Stueck eingepflegt, und jede haette bis zu
           fuenfundvierzig Sekunden in der Spur gestanden, die auch die App
           bedient.

           Der Merker reist MIT und nennt seine Quelle: gemerkt wird beim
           Einreihen (sonst zahlte der naechste Lauf doppelt), zurueckgenommen
           beim Scheitern — und zwar im LOGINEO-Merker, nicht im Klassenseiten-
           Merker. */
        if ($this->AiJobMoeglich()) {
            return $this->MailAnalyseAuftrag($vorschlag, $kopf, $text, $anhaenge,
                (string)$seite['userId'], 'LOGINEO',
                ['quelle' => 'moodle',
                 'topf' => $this->EduMerkerTopf('moodle', (string)$seite['url']),
                 'schluessel' => $vorschlag]);
        }
        return $this->MailAnalyseRecord($vorschlag, $kopf, $text, $anhaenge,
            (string)$seite['userId'], 'LOGINEO');
    }

    /** Einen LOGINEO-Merker wieder wegnehmen (Gegenstueck zu MoodleMerken). */
    private function MoodleVergessen(string $topf, string $schluessel): void
    {
        $karte = $this->MoodleSeenKarte();
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
        @$this->WriteAttributeString('MoodleSeen',
            (string)json_encode($karte, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Die Dateien einer Karte fuer die KI — als Base64, nicht als Medienobjekt.
     *
     * @return list<array{kind:string,name:string,base64:string}>
     */
    private function MoodleAnhaengeFuerKi(array $karte): array
    {
        $raus = [];
        $summe = 0;
        $speicherVorher = (string)@ini_get('memory_limit');
        @ini_set('memory_limit', '192M');
        try {
            foreach ((array)$karte['anhaenge'] as $datei) {
                $art = $this->EduArt((string)$datei['name']);
                if ($art === '') {
                    continue;   // weder Bild noch PDF — die KI kann damit nichts
                }
                // Der Token steckt seit dem Lesen in der Adresse (MoodleKarten).
                $roh = $this->MoodleDateiVonUrl((string)$datei['url']);
                if ($roh === null) {
                    continue;
                }
                $base64 = base64_encode($roh);
                if ($base64 === '' || $summe + strlen($base64) > self::MOODLE_ANHANG_MAX_B64) {
                    /* Die Karte wird trotzdem ausgewertet, nur ohne diesen
                       Anhang — besser als ein halbes PDF an die KI. */
                    continue;
                }
                $summe += strlen($base64);
                $raus[] = ['kind' => $art, 'name' => (string)$datei['name'], 'base64' => $base64];
            }
        } finally {
            @ini_set('memory_limit', $speicherVorher);
        }
        return $raus;
    }

    /**
     * Die gelesenen Zeilen in die Hausaufgaben uebernehmen.
     *
     * Die schreibende Haelfte, und sie bleibt beim Gateway: dort liegen die
     * Hausaufgaben, und `HomeworkImportieren` raeumt im Fenster auf.
     *
     * @param list<array<string,mixed>> $roh
     */
    private function MoodleAufgabenEinpflegen(string $userId, array $roh, string $von, string $bis): string
    {
        $e = $this->HomeworkImportieren($userId, $roh, $von, $bis, 'moodle');
        if (($e['ok'] ?? false) !== true) {
            return sprintf($this->Translate('homework: %s'), (string)($e['fehler'] ?? '?'));
        }
        if ($roh === [] && (int)($e['entfernt'] ?? 0) === 0) {
            return '';                          // diese Schule pflegt keine Aufgaben
        }
        return sprintf($this->Translate('%1$d homework item(s), %2$d new, %3$d withdrawn'),
            count($roh), (int)($e['neu'] ?? 0), (int)($e['entfernt'] ?? 0));
    }

    /**
     * Die gelesenen Termine als Vorschlaege ablegen.
     *
     * Die schreibende Haelfte, und sie bleibt beim Gateway: dort steht der
     * Vorschlagsbestand, und dort haengt auch der Merker, der einen Termin
     * nicht zweimal vorschlaegt.
     *
     * Der Merker wird ERST NACH dem Ablegen gesetzt — scheitert das Ablegen,
     * soll der Termin beim naechsten Lauf wiederkommen.
     *
     * @param list<array<string,mixed>> $zeilen
     */
    private function MoodleTermineEinpflegen(string $userId, array $zeilen): string
    {
        $neu = 0;
        foreach ($zeilen as $z) {
            if (!is_array($z) || !is_array($z['satz'] ?? null)) {
                continue;
            }
            $topf = (string)($z['topf'] ?? '');
            $schluessel = (string)($z['schluessel'] ?? '');
            if ($topf === '' || $schluessel === '' || $this->MoodleGesehen($topf, $schluessel)) {
                continue;
            }
            if ($this->MailStoreProposal($z['satz'])) {
                $this->MoodleMerken($topf, $schluessel);
                $neu++;
            }
        }
        if ($neu === 0) {
            return '';
        }
        $this->MailNotifyProposal(0, $neu, 0, $userId, 'LOGINEO');
        return sprintf($this->Translate('%d date(s) suggested'), $neu);
    }

    private function MoodleStatusSchreiben(string $text): void
    {
        @$this->WriteAttributeString('MoodleStatus',
            (string)json_encode(['t' => time(), 'text' => $text], JSON_UNESCAPED_UNICODE));
        $this->SendDebug('Moodle', $text, 0);
    }

    // ─────────────────────────────── Formular ───────────────────────────────

    /**
     * Das Panel. Vier Gruppen wie bei den Klassenseiten: erst die Zugänge,
     * dann was ankommt, dann der Stand.
     */
    private function GetMoodlePanel(): array
    {
        $mitglieder = [['caption' => $this->Translate('— none —'), 'value' => '']];
        $wahl = [['caption' => $this->Translate('— pick —'), 'value' => '']];
        try {
            foreach ($this->LoadUsers() as $u) {
                $n = trim((string)($u['name'] ?? ''));
                if ($n !== '') {
                    $mitglieder[] = ['caption' => $n, 'value' => (string)($u['id'] ?? '')];
                }
            }
        } catch (\Throwable $e) {
            // ohne Mitgliederliste eben nur „keins"
        }
        $tokens = $this->MoodleTokenListe();
        foreach ($this->MoodleKonten() as $k) {
            $wahl[] = ['caption' => $this->MoodleName($k), 'value' => (string)$k['userId']];
        }
        /* Der Token-Stand je Zeile, als Auskunft in der Liste: ohne ihn ist
           „warum kommt nichts" eine Suche. Der Token selbst steht NICHT da. */
        $zeilen = [];
        foreach ($this->MoodleKonten() as $k) {
            $e = $tokens[MoodleCalc::TokenSchluessel((string)$k['site'], (string)$k['userId'])] ?? null;
            $zeilen[] = [
                'name'   => (string)$k['name'],
                'site'   => (string)$k['site'],
                'user'   => (string)$k['user'],
                'userId' => (string)$k['userId'],
                'stpl'   => (int)$k['stpl'],
                'stand'  => is_array($e) && trim((string)($e['token'] ?? '')) !== ''
                    ? sprintf($this->Translate('token since %s'), date('d.m.Y', (int)($e['at'] ?? 0)))
                    : $this->Translate('no token'),
            ];
        }
        $stand = json_decode((string)@$this->ReadAttributeString('MoodleStatus'), true);
        $zeile = is_array($stand) && ($stand['text'] ?? '') !== ''
            ? sprintf($this->Translate('Last check %1$s: %2$s'),
                date('d.m.Y H:i', (int)($stand['t'] ?? 0)), (string)$stand['text'])
            : $this->Translate('Not checked yet.');

        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('LOGINEO NRW LMS (Moodle)'),
            'expanded' => false,
            'items'    => [
                ['type' => 'Label', 'caption' => $this->Translate('Reads what the school puts into its learning platform: parent letters, weekly plans, forum posts — and, where the school keeps them, assignments with due dates. Only reading happens here; nothing is ever written into the school system.')],
                ['type' => 'CheckBox', 'name' => 'MoodleEnabled',
                 'caption' => $this->Translate('Read from LOGINEO')],

                ['type' => 'ExpansionPanel', 'expanded' => true,
                 'caption' => $this->Translate('1. Access per child'),
                 'items' => [
                    ['type' => 'Label', 'caption' => $this->Translate('LOGINEO has no guardian accounts — every child has its own login. One row per child: the address of the school, the user name, and which family member it belongs to.')],
                    ['type' => 'List', 'name' => 'MoodleAccounts', 'rowCount' => 3,
                     'add' => true, 'delete' => true,
                     'caption' => $this->Translate('Accesses'),
                     'columns' => [
                         ['caption' => $this->Translate('Name'), 'name' => 'name', 'width' => '120px',
                          'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                         ['caption' => $this->Translate('Address'), 'name' => 'site', 'width' => '260px',
                          'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                         ['caption' => $this->Translate('User'), 'name' => 'user', 'width' => '160px',
                          'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                         ['caption' => $this->Translate('Family member'), 'name' => 'userId', 'width' => '150px',
                          'add' => '', 'edit' => ['type' => 'Select', 'options' => $mitglieder]],
                         ['caption' => $this->Translate('Timetable instance'), 'name' => 'stpl', 'width' => '180px',
                          'add' => 0, 'edit' => ['type' => 'SelectInstance']],
                         ['caption' => $this->Translate('Token'), 'name' => 'stand', 'width' => 'auto',
                          'add' => '', 'save' => false],
                     ],
                     'values' => $zeilen],
                    ['type' => 'Label', 'caption' => $this->Translate('The password is needed once. It is exchanged for a token, the field is cleared right away, and only the token is stored — it can be revoked in Moodle at any time, a password cannot.')],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Select', 'name' => 'MoodleAccountPick', 'width' => '180px',
                         'caption' => $this->Translate('For'), 'options' => $wahl],
                        ['type' => 'PasswordTextBox', 'name' => 'MoodlePassword', 'width' => '200px',
                         'caption' => $this->Translate('Password (once)')],
                        ['type' => 'Button', 'caption' => $this->Translate('Sign in and fetch token'),
                         'onClick' => 'IPS_RequestAction($id, \'MoodleToken\', json_encode(['
                             . '"account" => $MoodleAccountPick, "password" => $MoodlePassword]));'],
                        ['type' => 'Button', 'caption' => $this->Translate('Remove token'),
                         'confirm' => $this->Translate('Remove the stored token of this row? Nothing is read afterwards until a new one is fetched.'),
                         'onClick' => 'IPS_RequestAction($id, \'MoodleTokenWeg\', json_encode(['
                             . '"account" => $MoodleAccountPick]));'],
                    ]],
                 ]],

                ['type' => 'ExpansionPanel', 'expanded' => false,
                 'caption' => $this->Translate('2. What is taken over'),
                 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'MoodleIntervalHours', 'minimum' => 1,
                     'maximum' => 48, 'suffix' => ' h',
                     'caption' => $this->Translate('Update interval')],
                    ['type' => 'CheckBox', 'name' => 'MoodleToCards',
                     'caption' => $this->Translate('Mirror documents and forum posts as cards')],
                    ['type' => 'CheckBox', 'name' => 'MoodleAnalyse',
                     'caption' => $this->Translate('Send new documents to the AI as suggestions')],
                    ['type' => 'Label', 'caption' => $this->Translate('A new document goes through the same chain as a school mail: analysis, then a suggestion in the AI inbox that someone checks and accepts. The first check of a course only notes what is there — otherwise twenty suggestions would arrive at once. The suggestions count towards the daily AI limit.')],
                    ['type' => 'CheckBox', 'name' => 'MoodleHomework',
                     'caption' => $this->Translate('Take over assignments as homework')],
                    ['type' => 'CheckBox', 'name' => 'MoodleEvents',
                     'caption' => $this->Translate('Suggest dates from the platform calendar')],
                    ['type' => 'CheckBox', 'name' => 'MoodlePush',
                     'caption' => $this->Translate('Push when something new arrives')],
                 ]],

                ['type' => 'ExpansionPanel', 'expanded' => true,
                 'caption' => $this->Translate('3. Status and maintenance'),
                 'items' => [
                    ['type' => 'Label', 'name' => 'MoodleStatusLabel', 'caption' => $zeile],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Button', 'caption' => $this->Translate('Dry run'),
                         'onClick' => 'IPS_RequestAction($id, \'MoodleScanNow\', 0);'],
                        ['type' => 'Button', 'caption' => $this->Translate('Clear memory'),
                         'onClick' => 'IPS_RequestAction($id, \'MoodleForget\', 0);'],
                    ]],
                 ]],
            ],
        ];
    }
}
