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
    private const MOODLE_HTTP_FRIST    = 25;              // Sekunden je Aufruf
    private const MOODLE_INTERVALL_STD = 6;               // Stunden zwischen Läufen
    /* Deckel je Datei. AiFetchPublicPage deckelt bei 2 MB und BRICHT dann ab —
       die gemessene Elternabend-Präsentation hat 6,3 MB. Was darüber liegt,
       wird als Verweis vermerkt und nicht abgelegt. */
    private const MOODLE_DATEI_MAX     = 8388608;
    /* So viele Fehlschläge je Zugang, dann ruht er bis zum nächsten Griff von
       Hand. Ein toter Token soll nicht sechsmal am Tag angeklopft werden. */
    private const MOODLE_FEHLER_MAX    = 3;
    /* So viele Karten gehen je Lauf an die KI. Jede kostet einen Aufruf beim
       Anbieter; der Rest kommt beim naechsten Mal. Derselbe Wert wie bei den
       Klassenseiten. */
    private const MOODLE_JE_LAUF_MAX   = 5;
    private const MOODLE_TEXT_MAX      = 8000;
    /* So weit im Voraus werden Aufgaben geholt — und genau dieses Fenster gilt
       auch beim Zurueckziehen. Nicht das Jahr aus HomeworkCalc: ein halb
       gelesener Abruf soll nicht ein Jahr Hausaufgaben wegnehmen koennen. */
    private const MOODLE_TAGE_VOR      = 90;
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
            // Trockenlauf: lesen und berichten, nichts ablegen und nichts melden.
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

    /**
     * Ein Web-Service-Aufruf. Gibt die Antwort als Karte zurück, `null` bei
     * jedem Fehlschlag — der Aufrufer entscheidet, ob das schlimm ist.
     *
     * HIER sitzt die weisse Liste. Sie ist der Grund, warum dieses Modul in der
     * Schule nichts anrichten kann: was nicht in MoodleCalc::ERLAUBT steht,
     * verlässt diese Funktion nicht.
     */
    private function MoodleRest(array $zugang, string $funktion, array $params = []): ?array
    {
        if (!MoodleCalc::Erlaubt($funktion)) {
            /* Kein stilles Nein: das ist ein Fehler im Modul, nicht im Netz. */
            $this->SendDebug('Moodle', 'ABGEWIESEN (nicht auf der weissen Liste): ' . $funktion, 0);
            $this->LogMessage('SymDo: Moodle-Aufruf „' . $funktion
                . '" ist nicht freigegeben — nur lesende Funktionen sind erlaubt.', KL_ERROR);
            return null;
        }
        $token = $this->MoodleTokenVon($zugang);
        if ($token === '') {
            $this->SendDebug('Moodle', 'kein Token für ' . $this->MoodleName($zugang), 0);
            return null;
        }
        $antwort = $this->MoodleHttp((string)$zugang['site'] . '/webservice/rest/server.php',
            array_merge([
                'wstoken'            => $token,
                'wsfunction'         => $funktion,
                'moodlewsrestformat' => 'json',
            ], $this->MoodleFlach($params)));
        if ($antwort === null) {
            return null;
        }
        $d = json_decode((string)$antwort['body'], true);
        if ($d === null) {
            $this->SendDebug('Moodle', $funktion . ': keine JSON-Antwort (HTTP '
                . (int)$antwort['status'] . ')', 0);
            return null;
        }
        /* Moodle meldet Fehler als 200 mit `exception`. Ohne diese Weiche sähe
           ein abgelaufener Token wie eine leere Antwort aus — und der Lauf
           räumte auf, statt zu klagen. */
        if (is_array($d) && isset($d['exception'])) {
            $code = trim((string)($d['errorcode'] ?? ''));
            $this->SendDebug('Moodle', $funktion . ': ' . $code . ' — '
                . trim((string)($d['message'] ?? '')), 0);
            return null;
        }
        // Eine Liste kommt als Liste, eine Karte als Karte — beides ist ein array.
        return is_array($d) ? $d : null;
    }

    /**
     * Moodle nimmt verschachtelte Parameter nur flach: `courseids[0]=77`.
     *
     * @return array<string,string>
     */
    private function MoodleFlach(array $params, string $praefix = ''): array
    {
        $raus = [];
        foreach ($params as $k => $v) {
            $name = $praefix === '' ? (string)$k : $praefix . '[' . $k . ']';
            if (is_array($v)) {
                $raus += $this->MoodleFlach($v, $name);
                continue;
            }
            $raus[$name] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
        }
        return $raus;
    }

    /**
     * POST mit Formularfeldern. Eigenes curl wie in jedem anderen Trait hier —
     * ein gemeinsamer HTTP-Helfer gibt es im Gateway nicht.
     *
     * @return array{status:int,body:string}|null
     */
    private function MoodleHttp(string $url, array $felder): ?array
    {
        /* Dieselbe Wache wie beim Abruf fremder Seiten: die Adresse kommt aus
           dem Formular, sie darf nicht ins eigene Netz zeigen. */
        if (!$this->AiIsPublicUrl($url)) {
            $this->SendDebug('Moodle', 'Adresse nicht zulässig: ' . $url, 0);
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($felder),
            CURLOPT_TIMEOUT        => self::MOODLE_HTTP_FRIST,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'SymDo',
        ]);
        $body   = curl_exec($ch);
        $fehler = ($body === false) ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($fehler !== '') {
            $this->SendDebug('Moodle', 'HTTP-Fehler: ' . $fehler, 0);
            return null;
        }
        return ['status' => $status, 'body' => (string)$body];
    }

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
     * Ein Konto lesen. Stufe 1: Standort, Kurse, Inhaltsübersicht — als
     * Auskunft. Leerer Rückgabewert heisst „nicht lesbar" (der Aufrufer zählt
     * das als Fehlschlag).
     */
    private function MoodleKontoLesen(array $zugang, bool $trocken): string
    {
        $info = $this->MoodleRest($zugang, 'core_webservice_get_site_info');
        if (!is_array($info)) {
            return '';
        }
        $kurse = $this->MoodleRest($zugang, 'core_enrol_get_users_courses',
            ['userid' => (int)($info['userid'] ?? 0)]);
        if (!is_array($kurse)) {
            return '';
        }
        /* Der Schalter „als Karten ablegen" wird nicht mehr hier gelesen: er
           steht jetzt in `EduSpiegelnAn`, zusammen mit dem der Klassenseiten —
           beide Quellen gehen durch dieselbe Tuer, und die Frage gehoert an
           EINE Stelle. */
        /* Auswerten kostet Geld — deshalb haengt es an der KI-Einwilligung und
           am eigenen Schalter, genau wie bei den Klassenseiten. */
        $auswerten = (bool)$this->MoodleProp('MoodleAnalyse', true)
            && (bool)$this->MoodleProp('AiEnabled', false)
            && $this->AiPrivacyAccepted();
        $karten = 0;
        $neu = 0;
        $kursListe = [];
        $hausaufgaben = '';
        $termine = '';
        $geaendert = 0;
        $analysiert = 0;
        $archiviert = 0;
        $gesperrt = 0;
        $gedeckelt = false;
        foreach ($kurse as $kurs) {
            if (!is_array($kurs) || (int)($kurs['id'] ?? 0) <= 0) {
                continue;
            }
            $seite = $this->MoodleSeite($zugang, $kurs);
            /* Ein in der App geloeschter Kurs bleibt geloescht. Dieselbe
               Sperrliste wie bei den Klassenseiten — sie haengt an der Adresse,
               und die Kursadresse ist genau so eine. */
            if ($this->EduGesperrt((string)$seite['url'])) {
                $gesperrt++;
                continue;
            }
            $liste = $this->MoodleKarten($zugang, (int)$kurs['id']);
            $karten += count($liste);
            /* Spiegeln fuer die GANZE Seite auf einmal — derselbe Weg wie bei
               den Klassenseiten, seit dem 15.09.2026 auch hier. Vorher gab es
               dafuer 190 eigene Zeilen: ein Schreibvorgang JE KARTE, ohne
               Medien-Schuebe und ohne Ruecknahme bei Fehlschlag. Der Bestand
               wird bis zu ein Megabyte gross, und er liegt in derselben Spur,
               die die App bedient. */
            if (!$trocken) {
                $neu += $this->EduSeiteSpiegeln($seite, $liste);
            }
            /* Der ERSTE Lauf einer Seite merkt sich nur. Sonst stuenden beim
               Einschalten zwanzig Vorschlaege auf einmal da, und jeder kostet
               Geld. Erkannt am leeren Topf dieser Seite. */
            $topf = 'moodle:' . md5((string)$seite['url']);
            $ersterLauf = !$this->MoodleTopfHatEintraege($topf);
            foreach ($liste as $karte) {
                if ($trocken) {
                    continue;
                }
                $schluessel = (string)$karte['srcId'] . ':' . (int)$karte['updated'];
                if ($this->MoodleGesehen($topf, $schluessel)) {
                    continue;
                }
                $geaendert++;
                if ($ersterLauf) {
                    $this->MoodleMerken($topf, $schluessel);
                    continue;
                }
                if (!$auswerten) {
                    continue;
                }
                /* Deckel: ab hier wird nicht mehr ausgewertet — aber weiter
                   gespiegelt, denn das kostet nichts. Die Karte bleibt
                   unvermerkt und kommt beim naechsten Lauf an die Reihe. */
                if ($this->MailDayLimitReached()) {
                    $this->SendDebug('Moodle', 'Tagesdeckel erreicht — Auswertung wartet', 0);
                    $gedeckelt = true;
                    continue;
                }
                if ($analysiert >= self::MOODLE_JE_LAUF_MAX) {
                    $this->SendDebug('Moodle', 'Deckel je Lauf erreicht — Auswertung wartet', 0);
                    $gedeckelt = true;
                    continue;
                }
                if ($this->MoodleKarteAnalysieren($zugang, $seite, $karte)) {
                    $this->MoodleMerken($topf, $schluessel);
                    $analysiert++;
                }
            }
            if (!$trocken) {
                $archiviert += $this->EduArchivAbgleichen($seite, $liste);
            }
            $kursListe[(int)$kurs['id']] = $seite;
        }
        /* Aufgaben und Termine hangen NICHT an einer Seite, sondern am Konto:
           beide Abrufe nennen ihre Kurse selbst. Deshalb hier, nach der
           Schleife, und in EINEM Aufruf je Konto. */
        if (!$trocken && (bool)$this->MoodleProp('MoodleHomework', true)) {
            $namen = array_map(static fn($f): string => (string)($f['name'] ?? ''),
                (array)($info['functions'] ?? []));
            $rueckmeldungen = $this->MoodleAbstimmungen($zugang, $kursListe, $namen);
            [$von, $bis] = $this->MoodleFenster();
            $hausaufgaben = $this->MoodleAufgabenEinpflegen((string)$zugang['userId'],
                $this->MoodleAufgabenZeilen($zugang, $kursListe, $rueckmeldungen), $von, $bis);
            if ($rueckmeldungen !== []) {
                $hausaufgaben = trim($hausaufgaben . ', ' . sprintf(
                    $this->Translate('%d reply/replies with a deadline'), count($rueckmeldungen)), ', ');
            }
        }
        if (!$trocken && (bool)$this->MoodleProp('MoodleEvents', true)) {
            $termine = $this->MoodleTermineEinpflegen((string)$zugang['userId'],
                $this->MoodleTermineZeilen($zugang, $kursListe));
        }
        return sprintf($this->Translate('%1$d course(s), %2$d card(s), %3$d written, %4$d changed, %5$d analysed%6$s'),
            count($kurse), $karten, $neu, $geaendert, $analysiert,
            ($archiviert > 0 ? ', ' . sprintf($this->Translate('%d archived'), $archiviert) : '')
            . ($gesperrt > 0 ? ', ' . sprintf($this->Translate('%d blocked'), $gesperrt) : '')
            . ($gedeckelt ? ' — ' . $this->Translate('daily AI limit reached, the rest follows later') : '')
            . ($hausaufgaben === '' ? '' : ', ' . $hausaufgaben)
            . ($termine === '' ? '' : ', ' . $termine)
            . ($trocken ? ' — ' . $this->Translate('dry run, nothing written') : ''));
    }

    /**
     * Ein Kurs als „Seite" im Sinne der Klassenseiten.
     *
     * Die Adresse ist die ECHTE Kursadresse: sie ist eindeutig, sie oeffnet den
     * Kurs im Browser, und sie ist damit derselbe Schluessel, den die
     * Klassenseiten schon benutzen (`edupage:` + md5 der Adresse). Deshalb
     * braucht EduOrdner() keine Zeile Aenderung — Ebene 1 ist der Ordner des
     * Kindes, den es fuer Edumaps schon gibt, Ebene 2 dieser Kurs.
     *
     * @return array{name:string,url:string,userId:string}
     */
    private function MoodleSeite(array $zugang, array $kurs): array
    {
        $name = trim((string)($kurs['shortname'] ?? ''));
        if ($name === '') {
            $name = trim((string)($kurs['fullname'] ?? ''));
        }
        return [
            'name'   => $name !== '' ? $name : $this->Translate('Course'),
            'url'    => (string)$zugang['site'] . '/course/view.php?id=' . (int)$kurs['id'],
            'userId' => (string)$zugang['userId'],
        ];
    }

    /**
     * Die Karten eines Kurses: Dateien und Forumsbeitraege.
     *
     * Eine Karte je MODUL und nicht je Datei: ein „Material" kann mehrere
     * Dateien tragen, und in der App gehoeren sie zusammen (die Karte haelt sie
     * als Anhaenge). Ein Modul ohne Datei und ohne Text faellt weg — eine leere
     * Karte ist keine Auskunft.
     *
     * @return list<array<string,mixed>>
     */
    private function MoodleKarten(array $zugang, int $kursId): array
    {
        $raus = [];
        $inhalt = $this->MoodleRest($zugang, 'core_course_get_contents', ['courseid' => $kursId]);
        $foren = [];
        foreach ((array)$inhalt as $abschnitt) {
            if (!is_array($abschnitt)) {
                continue;
            }
            $wo = trim((string)($abschnitt['name'] ?? ''));
            /* Die Beschreibung des Abschnitts. Sie steht schon in dieser
               Antwort, kostet also keinen Aufruf — und dort steht oft das
               Eigentliche („bitte bis Freitag zurueck").
               NUR wenn Text uebrig bleibt: an der geprueften Grundschule
               bestehen alle vier Beschreibungen ausschliesslich aus einem
               Kopfbild (205-274 Zeichen HTML, 0 Zeichen Text). Eine Karte
               daraus waere eine leere Karte, und das Bild haengt an einer
               Adresse, die ohne Token nichts liefert. */
            $vorwort = $this->EduText((string)($abschnitt['summary'] ?? ''));
            if ($vorwort !== '' && $wo !== '') {
                $raus[] = [
                    // Dieselbe Form wie in MoodleModulKarte, siehe dort.
                    'quelle' => 'moodle',
                    'srcId'  => 'moodlesec:' . (int)($abschnitt['id'] ?? 0),
                    /* Ein Abschnitt hat kein `timemodified`. Die Fassung ist
                       deshalb der Fingerabdruck des Textes: aendert die Schule
                       ihn, ist es eine neue Fassung — sonst nicht. */
                    'updated'   => (int)crc32((string)($abschnitt['summary'] ?? '')),
                    /* Ein Abschnitt hat kein Datum — dann gilt das des Bestands.
                       AUSDRUECKLICH 0, nicht weggelassen: sonst faellt das Feld
                       auf die Fassung zurueck, und die ist hier ein
                       Fingerabdruck und keine Zeit. */
                    'srcAt'     => 0,
                    // Kein eigener Weg — dann zeigt die Karte auf die Kursseite.
                    'srcUrl'    => '',
                    'titel'     => $wo,
                    'text'      => $vorwort,
                    'html'      => $this->EduHtml((string)($abschnitt['summary'] ?? '')),
                    'abschnitt' => $wo,
                    'abschnittFarbe' => '',
                    'farbe'     => '',
                    'buchung'   => null,
                    'anhaenge'  => [],
                ];
            }
            foreach ((array)($abschnitt['modules'] ?? []) as $modul) {
                if (!is_array($modul)) {
                    continue;
                }
                $art = (string)($modul['modname'] ?? '');
                if ($art === 'forum') {
                    // Foren kommen unten, in einem Zug je Kurs.
                    $foren[(int)($modul['instance'] ?? 0)] = $wo;
                    continue;
                }
                $karte = $this->MoodleModulKarte($modul, $wo);
                if ($karte !== null) {
                    $raus[] = $karte;
                }
            }
        }
        foreach ($this->MoodleForenKarten($zugang, $kursId, $foren) as $k) {
            $raus[] = $k;
        }
        return $raus;
    }

    /**
     * Ein Modul (Material, Ordner, Seite, Verweis) als Karte.
     *
     * @return array<string,mixed>|null null = nichts drin
     */
    private function MoodleModulKarte(array $modul, string $abschnitt): ?array
    {
        $dateien = [];
        $stand = (int)($modul['timemodified'] ?? 0);
        foreach ((array)($modul['contents'] ?? []) as $c) {
            if (!is_array($c) || (string)($c['type'] ?? '') === 'url') {
                continue;
            }
            $name = trim((string)($c['filename'] ?? ''));
            $url  = trim((string)($c['fileurl'] ?? ''));
            if ($name === '' || $url === '' || $this->EduArt($name) === '') {
                // Nur Bild und PDF: mehr kann die Karte nicht halten.
                continue;
            }
            /* In der Form, die `EduNotizAnhaenge` erwartet. `preview` bleibt
               leer: LOGINEO liefert kein Vorschaubild, und der Nachzug fragt
               dann gar nicht erst danach. */
            $dateien[] = ['name' => $name, 'datei' => $name, 'url' => $url,
                          'preview' => '', 'bytes' => (int)($c['filesize'] ?? 0)];
            $stand = max($stand, (int)($c['timemodified'] ?? 0));
        }
        $text = $this->EduText((string)($modul['description'] ?? ''));
        if ($dateien === [] && $text === '') {
            return null;
        }
        return [
            /* Die Form der KLASSENSEITEN-Karte, plus die vier Felder, die eine
               fremde Quelle mitbringen muss. Nur so geht LOGINEO durch dieselbe
               Tuer in den Bestand (`EduEinpflegen`) — vorher hatte es einen
               eigenen Spiegel, und jede Korrektur musste zweimal gemacht
               werden. */
            'quelle'    => 'moodle',
            'srcId'     => 'moodle:' . (int)($modul['id'] ?? 0),
            'updated'   => $stand,
            // Wann die Schule das Modul (oder seine neueste Datei) angefasst hat.
            'srcAt'     => $stand,
            'srcUrl'    => trim((string)($modul['url'] ?? '')),
            'titel'     => trim((string)($modul['name'] ?? '')),
            'text'      => $text,
            /* Durch die WEISSE LISTE, wie bei Edumaps. Die formatierte Fassung
               geht in der App durch `innerHTML`, und dort wird nichts mehr
               geprueft („weil hier nichts mehr geprueft werden kann", sagt der
               Kommentar in der Web-App). Dieser Text ist von Lehrkraeften in
               Moodle geschrieben — er darf kein Skript und kein Ereignis-
               Attribut mitbringen. Vorher stand er hier ROH: mein Fehler von
               heute Nachmittag. */
            'html'      => $this->EduHtml((string)($modul['description'] ?? '')),
            'abschnitt' => $abschnitt,
            'abschnittFarbe' => '',
            'farbe'     => '',
            'buchung'   => null,
            'anhaenge'  => $dateien,
        ];
    }

    /**
     * Die Beitraege der Foren eines Kurses als Karten.
     *
     * @param array<int,string> $foren Forum-Kennung → Abschnittsname
     * @return list<array<string,mixed>>
     */
    private function MoodleForenKarten(array $zugang, int $kursId, array $foren): array
    {
        if ($foren === []) {
            return [];
        }
        $meta = $this->MoodleRest($zugang, 'mod_forum_get_forums_by_courses',
            ['courseids' => [$kursId]]);
        $raus = [];
        foreach ((array)$meta as $forum) {
            if (!is_array($forum) || (int)($forum['id'] ?? 0) <= 0) {
                continue;
            }
            /* Ein leeres Forum gar nicht erst abfragen: `numdiscussions` steht
               schon hier, und jeder Aufruf ist eine Anfrage an die Schule. */
            if ((int)($forum['numdiscussions'] ?? 0) <= 0) {
                continue;
            }
            $wo = trim((string)($foren[(int)$forum['id']] ?? ''));
            if ($wo === '') {
                $wo = trim((string)($forum['name'] ?? ''));
            }
            $antwort = $this->MoodleRest($zugang, 'mod_forum_get_forum_discussions',
                ['forumid' => (int)$forum['id'], 'perpage' => 20]);
            foreach ((array)($antwort['discussions'] ?? []) as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $dateien = [];
                foreach ((array)($d['attachments'] ?? []) as $a) {
                    $name = trim((string)($a['filename'] ?? ''));
                    $url  = trim((string)($a['fileurl'] ?? ''));
                    if ($name !== '' && $url !== '' && $this->EduArt($name) !== '') {
                        $dateien[] = ['name' => $name, 'datei' => $name, 'url' => $url,
                                      'preview' => '', 'bytes' => (int)($a['filesize'] ?? 0)];
                    }
                }
                $stand = (int)($d['timemodified'] ?? ($d['modified'] ?? 0));
                $raus[] = [
                    // Dieselbe Form wie in MoodleModulKarte, siehe dort.
                    'quelle'    => 'moodle',
                    'srcId'     => 'moodlepost:' . (int)($d['discussion'] ?? ($d['id'] ?? 0)),
                    'updated'   => $stand,
                    'srcAt'     => $stand,
                    'srcUrl'    => (string)$zugang['site'] . '/mod/forum/discuss.php?d='
                                   . (int)($d['discussion'] ?? ($d['id'] ?? 0)),
                    'titel'     => trim((string)($d['subject'] ?? '')),
                    'text'      => $this->EduText((string)($d['message'] ?? '')),
                    // Weisse Liste, siehe MoodleModulKarte: ein Forumsbeitrag
                    // ist fremdes HTML.
                    'html'      => $this->EduHtml((string)($d['message'] ?? '')),
                    'abschnitt' => $wo,
                    'abschnittFarbe' => '',
                    'farbe'     => '',
                    'buchung'   => null,
                    'anhaenge'  => $dateien,
                ];
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
    private function MoodleKarteAnalysieren(array $zugang, array $seite, array $karte): bool
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
        return $this->MailAnalyseRecord(
            (string)$karte['srcId'] . ':' . (int)$karte['updated'],
            $kopf,
            $text,
            $this->MoodleAnhaengeFuerKi($zugang, $karte),
            (string)$seite['userId'],
            'LOGINEO'
        );
    }

    /**
     * Die Dateien einer Karte fuer die KI — als Base64, nicht als Medienobjekt.
     *
     * @return list<array{kind:string,name:string,base64:string}>
     */
    private function MoodleAnhaengeFuerKi(array $zugang, array $karte): array
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
                $roh = $this->MoodleDatei($zugang, (string)$datei['url']);
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
     * Aufgaben der Plattform als Hausaufgaben.
     *
     * Das Fach ist der Kursname: eine Schule nennt ihren Kurs „Mathematik 5a",
     * und HomeworkCalc::FachAufloesen findet daraus „Mathematik", wenn der
     * Stundenplan es kennt — sonst bleibt der Kursname stehen. Erledigt ist,
     * was abgegeben wurde; das Haekchen gehoert damit der Schule und laesst
     * sich zu Hause nicht zuruecknehmen (Sperrklinke in HomeworkCalc).
     *
     * Diese Haelfte LIEST nur — sie laeuft dort, wo die Zeit verbraucht wird
     * (je Aufgabe ein eigener Abruf fuer den Abgabestand). Eingepflegt wird in
     * `MoodleAufgabenEinpflegen`, und zwar dort, wo die Hausaufgaben stehen.
     *
     * @param array<int,array<string,mixed>> $kurse Kurskennung → Seite
     * @return list<array<string,mixed>> Zeilen fuer HomeworkImportieren
     */
    private function MoodleAufgabenZeilen(array $zugang, array $kurse, array $zusatz = []): array
    {
        if ($kurse === []) {
            return [];
        }
        $antwort = $this->MoodleRest($zugang, 'mod_assign_get_assignments',
            ['courseids' => array_values(array_map('intval', array_keys($kurse)))]);
        if (!is_array($antwort)) {
            return [];
        }
        [$von, $bis] = $this->MoodleFenster();
        $roh = [];
        foreach ((array)($antwort['courses'] ?? []) as $kurs) {
            $fach = trim((string)($kurs['shortname'] ?? ($kurs['fullname'] ?? '')));
            foreach ((array)($kurs['assignments'] ?? []) as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $faellig = (int)($a['duedate'] ?? 0);
                /* Ohne Faelligkeit ist es keine Hausaufgabe, sondern
                   Kursmaterial — das steht als Karte schon da. */
                if ($faellig <= 0) {
                    continue;
                }
                $tag = date('Y-m-d', $faellig);
                if ($tag < $von || $tag > $bis) {
                    continue;
                }
                /* Der Abgabestand kostet einen Aufruf JE Aufgabe — deshalb erst
                   hier, nachdem Fenster und Faelligkeit stimmen. */
                $erledigt = false;
                $stand = $this->MoodleRest($zugang, 'mod_assign_get_submission_status',
                    ['assignid' => (int)($a['id'] ?? 0)]);
                if (is_array($stand)) {
                    $status = (string)((($stand['lastattempt']['submission']['status']) ?? ''));
                    $erledigt = in_array($status, ['submitted', 'graded'], true);
                }
                $roh[] = [
                    'srcId'   => (int)($a['id'] ?? 0),
                    'subject' => $fach,
                    'due'     => $tag,
                    'note'    => trim((string)($a['name'] ?? '')),
                    'done'    => $erledigt,
                ];
            }
        }
        /* Die Abstimmungen kommen MIT — in EINEM Import.
           Zwei Aufrufe von HomeworkImportieren mit derselben Quelle wuerden
           sich gegenseitig aufraeumen: der zweite haelt die Zeilen des ersten
           fuer verschwunden und loescht sie im Fenster. Genau dieser Fehler ist
           heute zwischen UNTIS und LOGINEO aufgefallen; er gilt innerhalb einer
           Quelle genauso. */
        foreach ($zusatz as $z) {
            if (!is_array($z)) {
                continue;
            }
            $tag = (string)($z['due'] ?? '');
            if ($tag < $von || $tag > $bis) {
                continue;                       // dasselbe Fenster wie fuer Aufgaben
            }
            $roh[] = $z;
        }
        return $roh;
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

    /** Das Fenster, in dem Aufgaben und Abstimmungen zaehlen. */
    private function MoodleFenster(): array
    {
        return [date('Y-m-d'), date('Y-m-d', strtotime('+' . self::MOODLE_TAGE_VOR . ' days'))];
    }

    /**
     * Abstimmungen als Aufgabe mit Frist.
     *
     * Eine Abstimmung ist das einzige, was diese Plattform an ECHTEN Fristen
     * hergibt: „Fotos der Kinder auf LOGINEO?", „Betreuung in den
     * Winterferien?". Ein Dokument kann man nachlesen, eine Frist verpasst man.
     *
     * Zwei Aufrufe, beide lesend: einmal alle Abstimmungen der Kurse, und je
     * OFFENER Abstimmung ihre Optionen — denn nur dort steht, ob das eigene
     * Konto schon geantwortet hat. Geschlossene kosten keinen zweiten Aufruf;
     * die Frist wird VORHER geprueft.
     *
     * @param array<int,array<string,mixed>> $kurse       Kurskennung → Seite
     * @param list<string>                   $funktionen  was der Server hergibt
     * @return list<array<string,mixed>> Zeilen für HomeworkImportieren
     */
    private function MoodleAbstimmungen(array $zugang, array $kurse, array $funktionen): array
    {
        if ($kurse === [] || !MoodleCalc::KannAbstimmungen($funktionen)) {
            return [];
        }
        $antwort = $this->MoodleRest($zugang, 'mod_choice_get_choices_by_courses',
            ['courseids' => array_values(array_map('intval', array_keys($kurse)))]);
        if (!is_array($antwort)) {
            return [];
        }
        $jetzt = time();
        $raus = [];
        foreach ((array)($antwort['choices'] ?? []) as $ab) {
            if (!is_array($ab)) {
                continue;
            }
            // Erst die Frist, dann der Aufruf: eine abgelaufene Abstimmung
            // interessiert niemanden mehr, und jeder Aufruf geht an die Schule.
            if (MoodleCalc::AbstimmungAufgabe($ab, [], $jetzt) === null) {
                continue;
            }
            $opt = $this->MoodleRest($zugang, 'mod_choice_get_choice_options',
                ['choiceid' => (int)($ab['id'] ?? 0)]);
            $zeile = MoodleCalc::AbstimmungAufgabe($ab,
                is_array($opt) ? (array)($opt['options'] ?? []) : [], $jetzt);
            if ($zeile === null) {
                continue;
            }
            $kursId = (int)($ab['course'] ?? 0);
            $fach = trim((string)($kurse[$kursId]['name'] ?? ''));
            $raus[] = [
                'srcId'   => (int)$zeile['srcId'],
                'subject' => $fach !== '' ? $fach : $this->Translate('LOGINEO'),
                'due'     => (string)$zeile['due'],
                'note'    => $this->Translate('Reply needed:') . ' ' . (string)$zeile['name'],
                'done'    => (bool)$zeile['done'],
            ];
        }
        return $raus;
    }

    /**
     * Termine der Plattform als Vorschlag — OHNE KI-Aufruf.
     *
     * Ein Termin aus dem Kalender ist schon strukturiert: Name, Zeitpunkt,
     * Kurs. Ihn durch die Auswertung zu schicken kostete Geld und koennte ihn
     * nur schlechter machen. Er wird deshalb direkt zu einem Vorschlag —
     * angelegt wird er erst, wenn jemand ihn uebernimmt („nichts entsteht
     * ungefragt" gilt auch hier).
     *
     * Diese Haelfte LIEST nur — zwei Abrufe an die Schule. Abgelegt wird in
     * `MoodleTermineEinpflegen`, dort steht der Vorschlagsbestand.
     *
     * @param array<int,array<string,mixed>> $kurse Kurskennung → Seite
     * @return list<array<string,mixed>> Vorschlaege, jeder mit seinem Merker
     */
    private function MoodleTermineZeilen(array $zugang, array $kurse): array
    {
        $jetzt = time();
        /* Weg 1: die Zeitleiste. Sie nennt FRISTEN von Aktivitaeten — Aufgaben,
           Abstimmungen — und nur die des eigenen Kontos. */
        $a = $this->MoodleRest($zugang, 'core_calendar_get_action_events_by_timesort',
            ['timesortfrom' => $jetzt, 'limitnum' => 20]);
        /* Weg 2: der Kalender. Hier liegen Kurs-, Nutzer- und SEITENtermine —
           Schliesstage, Ferien, Elternabende. Zwei Wege, weil die Zeitleiste
           davon nichts weiss.
           An der geprueften Grundschule sind beide leer (gemessen ueber ein
           Jahr in beide Richtungen: 0 Eintraege). Der Weg ist trotzdem da: er
           kostet einen Aufruf, und eine Schule, die ihren Kalender pflegt,
           braucht keine Codeaenderung dafuer. */
        $kalender = $this->MoodleRest($zugang, 'core_calendar_get_calendar_events', [
            'events'  => ['courseids' => array_values(array_map('intval', array_keys($kurse)))],
            'options' => [
                'userevents' => 1,
                'siteevents' => 1,
                'timestart'  => $jetzt,
                'timeend'    => $jetzt + self::MOODLE_TAGE_VOR * 86400,
            ],
        ]);
        if (!is_array($a) && !is_array($kalender)) {
            return [];
        }
        $liste = MoodleCalc::TermineVereinen(
            is_array($a) ? (array)($a['events'] ?? []) : [],
            is_array($kalender) ? (array)($kalender['events'] ?? []) : []);
        $topf = 'moodleevents:' . mb_strtolower((string)$zugang['site']);
        $raus = [];
        foreach ($liste as $e) {
            if (!is_array($e)) {
                continue;
            }
            $ts = (int)($e['timesort'] ?? ($e['timestart'] ?? 0));
            $titel = trim((string)($e['name'] ?? ''));
            if ($ts <= 0 || $titel === '') {
                continue;
            }
            $schluessel = 'ev:' . (int)($e['id'] ?? 0) . ':' . $ts;
            $kursName = trim((string)((($e['course']['shortname']) ?? ($e['course']['fullname'] ?? ''))));
            $raus[] = ['topf' => $topf, 'schluessel' => $schluessel, 'satz' => [
                'id'        => 'moodleevent:' . (int)($e['id'] ?? 0) . ':' . $ts,
                'at'        => $ts,
                'created'   => time(),
                'from'      => '',
                'fromName'  => $kursName !== '' ? $kursName : $this->Translate('LOGINEO'),
                'subject'   => $titel,
                'recipient' => '',
                'userId'    => (string)$zugang['userId'],
                'origin'    => null,
                'items'     => [[
                    'title'      => mb_substr($titel, 0, 120),
                    'info'       => $kursName,
                    'due'        => date('Y-m-d', $ts),
                    /* Mitternacht heisst „ganztaegig": Moodle setzt fuer einen
                       Tagestermin 00:00, und eine Uhrzeit „0:00" im Kalender
                       waere eine Behauptung. */
                    'time'       => date('H:i', $ts) === '00:00' ? null : date('H:i', $ts),
                    'priority'   => 'normal',
                    'kind'       => 'event',
                    'end'        => null,
                    'allDay'     => date('H:i', $ts) === '00:00',
                    'recurrence' => null,
                    'assignedTo' => [(string)$zugang['userId']],
                    'taken'      => false,
                ]],
            ]];
        }
        return $raus;
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
