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
        $dateien = 0;
        $foren   = 0;
        foreach ($kurse as $kurs) {
            $inhalt = $this->MoodleRest($zugang, 'core_course_get_contents',
                ['courseid' => (int)($kurs['id'] ?? 0)]);
            foreach ((array)$inhalt as $abschnitt) {
                foreach ((array)($abschnitt['modules'] ?? []) as $modul) {
                    if ((string)($modul['modname'] ?? '') === 'forum') {
                        $foren++;
                    }
                    $dateien += count((array)($modul['contents'] ?? []));
                }
            }
        }
        return sprintf($this->Translate('%1$d course(s), %2$d file(s), %3$d forum(s)%4$s'),
            count($kurse), $dateien, $foren,
            $trocken ? ' — ' . $this->Translate('dry run, nothing written') : '');
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
