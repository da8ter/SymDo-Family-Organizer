<?php

declare(strict_types=1);

/**
 * Das Symcon-Handbuch als Nachschlagewerk für den Sprachdialog.
 *
 * Der Inhalt wird IMMER live von symcon.de geholt — eine Antwort ist damit so
 * aktuell wie die Doku selbst. Nur das INHALTSVERZEICHNIS liegt zwischen: die
 * Doku hat über tausend Seiten (gemessen: 978 gefundene Seiten nach 260
 * Abrufen, und die Warteschlange war noch lange nicht leer), ein vollständiger
 * Durchlauf je Frage wäre also ausgeschlossen. Die Struktur ändert sich
 * ohnehin selten; sie wird deshalb einmal wöchentlich neu erfasst.
 *
 * Aufgebaut wird das Verzeichnis in Häppchen: jeder Aufruf hat ein knappes
 * Zeitbudget und arbeitet die Warteschlange ein Stück weiter. So blockiert kein
 * Gespräch minutenlang, und nach wenigen Fragen steht es vollständig.
 *
 * Der Weg zur Antwort:
 *   Frage → passende Seiten aus dem Verzeichnis → die beste LIVE holen →
 *   Fließtext herausschälen → Auszug zurück, aus dem das Modell antwortet.
 */
trait SymconDoku
{
    private const DOKU_HOST      = 'https://www.symcon.de';
    private const DOKU_WURZEL    = '/de/service/dokumentation/';
    /** So lange gilt ein fertiges Verzeichnis (Sekunden). */
    private const DOKU_HALTBAR   = 7 * 86400;
    /** Bis zu dieser Tiefe werden Seiten ABGERUFEN; Verweise darunter landen
     *  trotzdem im Verzeichnis. Muss bis 8 reichen: die Befehlsreferenz endet
     *  bei 6, der Entwicklerbereich geht tiefer (entwicklerbereich/sdk-tools/
     *  sdk-php/…). Mit 6 fehlten genau die SDK-Funktionen — RegisterTimer war
     *  nicht auffindbar. */
    private const DOKU_TIEFE     = 8;
    /** Zählt hoch, wenn sich am Aufbau oder an der Normierung etwas ändert —
     *  ein altes Verzeichnis wäre sonst eine Woche lang stillschweigend falsch. */
    private const DOKU_BAU       = 4;
    /* ── Bedeutungssuche (RAG) ────────────────────────────────────────────
       Die Slug-Suche bewertet nur die ADRESSE. Gemessen an sieben Fragen war
       das bei drei davon zu schwach: „Wie lasse ich mein Modul regelmäßig
       etwas tun?" landete bei SelectModule statt RegisterTimer, „Wie sichere
       ich meine Konfiguration?" bei Konfiguration statt Datensicherung, und
       ein Vergleich zweier Funktionen ist mit einer Seite gar nicht möglich.
       Deshalb werden die Seiten in Abschnitte zerlegt und eingebettet.

       Die Maße sind gemessen, nicht geraten: 978 Seiten, ⌀2736 Zeichen, daraus
       rund 3345 Abschnitte. Bei 512 Dimensionen als EIN BYTE je Zahl sind das
       1,7 MB, und die Suche darüber kostet in PHP 29 ms (Messung mit
       unpack je Abschnitt — sparsamer als ein unpack über alles: 20 statt
       52 MB Spitzenspeicher). */
    private const DOKU_DIM       = 512;
    private const DOKU_STUECK    = 800;
    private const DOKU_UEBERLAPP = 120;
    private const DOKU_MODELL    = 'text-embedding-3-small';
    /** Wie viele Abschnitte in die Antwort einfließen. */
    private const DOKU_TOP       = 4;
    /* Zeitbudget je Durchgang und Taktabstand. Symcon arbeitet Aufrufe je
       Instanz NACHEINANDER ab: 4 Sekunden Arbeit alle 5 Sekunden hiessen, dass
       das Gateway vier Fuenftel der Zeit belegt ist — jede Anfrage der Web-App,
       der Kachel und jeder Werkzeugaufruf muss dahinter warten. Deshalb jetzt
       kurze Haeppchen mit Luft dazwischen: rund ein Achtel Auslastung. Der
       Aufbau dauert damit laenger, ist aber eine Sache von Minuten pro Woche —
       die Bedienung ist jede Sekunde wichtig.
       Nachgemessen am 02.09.2026: bei 2 s alle 15 s antworteten die Hooks in
       10 bis 30 ms, der Aufbau brauchte aber 2,5 Stunden. Der Ausfall kam nicht
       vom Takt, sondern von einem haengenden Ueberrest-Timer. Deshalb jetzt
       3 s alle 8 s (rund 37 % Auslastung) — die Hook-Zeiten werden danach
       erneut gemessen. */
    private const DOKU_BUDGET    = 3.0;
    private const DOKU_TAKT      = 8000;
    private const DOKU_SEITEN_MAX = 2500;
    /** So viel Text darf ein Auszug haben — der Rest ist für ein Gespräch ohnehin zu viel. */
    private const DOKU_AUSZUG    = 1800;

    /** Wo die beiden Ablagen liegen — der Bau schreibt daneben in Zwischendateien. */
    private function DokuDatei(string $art): string
    {
        return IPS_GetKernelDir() . 'media/symdo_doku_' . $art . '_' . $this->InstanceID . '.bin';
    }

    private function DokuCreate(): void
    {
        // Verzeichnis + Warteschlange + Zeitstempel in EINEM Attribut: sie
        // gehören zusammen, ein halb geschriebener Stand wäre wertlos.
        $this->RegisterAttributeString('DokuIndex', '{}');
        /* Der Aufbau läuft im Hintergrund, NICHT im Gespräch: die Werkzeugfrist
           des Sprachkerns liegt bei 8 s, und ein vollständiger Durchlauf über
           die Doku dauert ein Vielfaches davon. Der Timer stellt sich selbst ab,
           sobald das Verzeichnis steht. */
        $this->RegisterTimer('DokuIndex', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'DokuTick\', 0);');
    }

    /** Beim Start (und nach jedem Reload) den Aufbau anstoßen, falls nötig. */
    private function DokuApplyChanges(): void
    {
        $s = $this->DokuStand();
        $frisch = $s['fertig'] && (time() - $s['stand']) < self::DOKU_HALTBAR;
        @$this->SetTimerInterval('DokuIndex', $frisch ? 0 : self::DOKU_TAKT);
    }

    /** Der Timer-Rückruf. Eigener Zweig in der RequestAction-Kette des Gateways. */
    private function DokuRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident !== 'DokuTick') {
            return false;
        }
        $this->DokuTick();
        return true;
    }

    /** Ein Timer-Schlag: ein Stück weiterbauen, am Ende den Timer abstellen. */
    private function DokuTick(): void
    {
        /* Waehrend eines laufenden Gespraechs GAR NICHT bauen: dort zaehlt jede
           Zehntelsekunde, und die Werkzeugfrist des Sprachkerns liegt bei 8 s. */
        if ($this->VoiceCalls() !== []) {
            return;
        }
        $s = $this->DokuIndexPflegen();
        if ($s['fertig']) {
            @$this->SetTimerInterval('DokuIndex', 0);
            $this->SendDebug('Doku', 'Verzeichnis fertig: ' . count($s['seiten']) . ' Seiten', 0);
        }
    }

    /**
     * Eine Doku-Seite holen. Bewusst schlank und ohne Weiterleitungs-Ketten in
     * fremde Hosts: die Adresse wird immer selbst zusammengesetzt.
     */
    private function DokuHol(string $pfad, int $timeout = 12): string
    {
        if (!str_starts_with($pfad, self::DOKU_WURZEL)) {
            return '';
        }
        $ch = curl_init(self::DOKU_HOST . $pfad);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SymDo/1.0 (Symcon-Modul; Handbuch-Abfrage)');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: text/html']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && is_string($body)) ? $body : '';
    }

    /** Der Anfangszustand — an EINER Stelle, damit die drei Rücksetzwege nicht auseinanderlaufen. */
    private function DokuLeer(): array
    {
        @unlink($this->DokuDatei('bau_text'));
        @unlink($this->DokuDatei('bau_vek'));
        return ['phase' => 'crawl', 'pos' => 0, 'liste' => [], 'stuecke' => 0,
                'stand' => 0, 'fertig' => false,
                'seiten' => [self::DOKU_WURZEL => 1], 'schlange' => [self::DOKU_WURZEL]];
    }

    /** @return array<string,mixed> */
    private function DokuStand(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('DokuIndex'), true);
        if (!is_array($roh) || !isset($roh['seiten']) || !is_array($roh['seiten'])) {
            return $this->DokuLeer();
        }
        /* Wurde mit einer ANDEREN Suchtiefe gebaut, ist es unbrauchbar: mit
           Tiefe 6 fehlten alle SDK-Seiten. Ohne diese Probe bliebe so ein
           Verzeichnis eine Woche lang stehen und keiner wüsste, warum die
           Hälfte fehlt. */
        if ((int)($roh['tiefe'] ?? 0) !== self::DOKU_TIEFE
            || (int)($roh['bau'] ?? 0) !== self::DOKU_BAU) {
            return $this->DokuLeer();
        }
        return [
            'phase'    => (string)($roh['phase'] ?? 'crawl'),
            'pos'      => (int)($roh['pos'] ?? 0),
            'liste'    => is_array($roh['liste'] ?? null) ? array_values($roh['liste']) : [],
            'stuecke'  => (int)($roh['stuecke'] ?? 0),
            'stand'    => (int)($roh['stand'] ?? 0),
            'fertig'   => ($roh['fertig'] ?? false) === true,
            'seiten'   => $roh['seiten'],
            'schlange' => is_array($roh['schlange'] ?? null) ? array_values($roh['schlange']) : [],
        ];
    }

    private function DokuStandSchreiben(array $s): void
    {
        @$this->WriteAttributeString('DokuIndex', (string)json_encode($s,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Das Verzeichnis ein Stück weiterbauen — höchstens DOKU_BUDGET Sekunden.
     * Ist es fertig und noch haltbar, passiert nichts.
     */
    private function DokuIndexPflegen(): array
    {
        $s = $this->DokuStand();
        $frisch = $s['fertig'] && (time() - $s['stand']) < self::DOKU_HALTBAR;
        if ($frisch) {
            return $s;
        }
        if ($s['fertig']) {           // abgelaufen → von vorn
            $s = $this->DokuLeer();
        }
        $ende = microtime(true) + self::DOKU_BUDGET;
        if ($s['phase'] === 'lesen') {
            return $this->DokuLesenPhase($s, $ende);
        }
        while ($s['phase'] === 'crawl' && $s['schlange'] !== [] && microtime(true) < $ende
               && count($s['seiten']) < self::DOKU_SEITEN_MAX) {
            $pfad = (string)array_shift($s['schlange']);
            $html = $this->DokuHol($pfad, 8);
            if ($html === '') {
                continue;
            }
            if (preg_match_all('#href="(' . preg_quote(self::DOKU_WURZEL, '#') . '[^"\#?]*)"#', $html, $m) > 0) {
                foreach (array_unique($m[1]) as $l) {
                    // Mit und ohne Schrägstrich ist dieselbe Seite — sonst steht
                    // sie doppelt im Verzeichnis und wird zweimal abgerufen.
                    $l = rtrim($l, '/') . '/';
                    if (isset($s['seiten'][$l])) {
                        continue;
                    }
                    $s['seiten'][$l] = 1;
                    // Nur bis zur Grenztiefe WEITERSUCHEN — die Blätter darunter
                    // stehen bereits im Verzeichnis, ihre Kinder gibt es nicht.
                    if (substr_count(rtrim($l, '/'), '/') < self::DOKU_TIEFE) {
                        $s['schlange'][] = $l;
                    }
                }
            }
        }
        if ($s['phase'] === 'crawl'
            && ($s['schlange'] === [] || count($s['seiten']) >= self::DOKU_SEITEN_MAX)) {
            // Verzeichnis steht — ab jetzt wird gelesen und eingebettet.
            $s['phase'] = 'lesen';
            $s['liste'] = array_keys($s['seiten']);
            $s['pos']   = 0;
            @unlink($this->DokuDatei('bau_text'));
            @unlink($this->DokuDatei('bau_vek'));
        }
        $s['tiefe'] = self::DOKU_TIEFE;
        $s['bau']   = self::DOKU_BAU;
        $this->DokuStandSchreiben($s);
        return $s;
    }


    // ------------------------------------------------------------------
    // Bedeutungssuche: zerlegen, einbetten, ablegen
    // ------------------------------------------------------------------

    /**
     * Eine Seite in Abschnitte schneiden. Geschnitten wird an Zeilenenden, damit
     * kein Satz zerrissen wird; ein Überlapp trägt den Zusammenhang über die
     * Naht. Jeder Abschnitt bekommt den Seitentitel vorangestellt — sonst weiß
     * die Einbettung nicht, wovon der Text handelt („Parameterliste: VariablenID"
     * allein könnte zu jeder Funktion gehören).
     *
     * @return list<string>
     */
    private function DokuZerlegen(string $titel, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $stuecke = [];
        $pos = 0;
        $laenge = mb_strlen($text);
        while ($pos < $laenge) {
            $roh = mb_substr($text, $pos, self::DOKU_STUECK);
            if ($pos + self::DOKU_STUECK < $laenge) {
                /* An der letzten Zeilengrenze trennen — aber nur, wenn dabei
                   nicht der halbe Abschnitt wegfällt. Die Doku steckt voller
                   kurzer Zeilen (Tabellen!), und bei einer Schwelle von 50 %
                   blieben Krümel übrig. */
                $bruch = mb_strrpos($roh, "\n");
                if ($bruch !== false && $bruch > self::DOKU_STUECK * 0.7) {
                    $roh = mb_substr($roh, 0, $bruch);
                }
            }
            $stueck = trim($roh);
            // Krümel tragen keine Bedeutung und blähen nur die Ablage auf.
            if (mb_strlen($stueck) >= 40) {
                $stuecke[] = $titel . ' — ' . $stueck;
            }
            /* Der Vorschub muss sich an der SOLLGRÖSSE messen, nicht am
               geschnittenen Stück: sonst schrumpft er mit jedem kurzen
               Abschnitt weiter (gemessen: ⌀204 statt 800 Zeichen, 15840 statt
               rund 5000 Abschnitte — vierfache Ablage und vierfache Suchzeit,
               bei weniger Zusammenhang je Abschnitt). */
            $pos += max((int)(self::DOKU_STUECK * 0.6), mb_strlen($roh) - self::DOKU_UEBERLAPP);
            if (count($stuecke) >= 40) {
                break;   // eine einzelne Seite darf den Speicher nicht sprengen
            }
        }
        return $stuecke;
    }

    /**
     * Abschnitte einbetten. Ein Aufruf je Seite (alle ihre Abschnitte auf
     * einmal) — das sind rund tausend Aufrufe für die ganze Doku und kostet
     * etwa anderthalb Cent.
     *
     * @param list<string> $texte
     * @return list<list<float>>|null null = fehlgeschlagen
     */
    private function DokuEinbetten(array $texte): ?array
    {
        $key = trim($this->ReadPropertyString('AiOpenAIKey'));
        if ($key === '' || $texte === []) {
            return null;
        }
        $antwort = $this->AiHttpPost('https://api.openai.com/v1/embeddings',
            ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            (string)json_encode(['model' => self::DOKU_MODELL, 'input' => array_values($texte),
                                 'dimensions' => self::DOKU_DIM], JSON_UNESCAPED_UNICODE),
            30);
        if ((int)($antwort['status'] ?? 0) !== 200) {
            $this->SendDebug('Doku', 'Einbettung fehlgeschlagen: ' . (string)($antwort['err'] ?? $antwort['status'] ?? '?'), 0);
            return null;
        }
        $daten = json_decode((string)($antwort['body'] ?? ''), true);
        $raus = [];
        foreach ((array)($daten['data'] ?? []) as $d) {
            if (is_array($d['embedding'] ?? null)) {
                $raus[] = $d['embedding'];
            }
        }
        return count($raus) === count($texte) ? $raus : null;
    }

    /**
     * Einen Vektor als EIN BYTE je Zahl ablegen. Die Einbettungen sind auf
     * Länge 1 normiert, ein fester Faktor genügt also — kein Maßstab je Vektor.
     * Der Rangfolge tut die Vergröberung nichts, spart aber drei Viertel des
     * Platzes (1,7 statt 6,9 MB) und dieselbe Zeit beim Lesen.
     */
    private function DokuPacken(array $vektor): string
    {
        $bytes = '';
        for ($i = 0; $i < self::DOKU_DIM; $i++) {
            $v = (float)($vektor[$i] ?? 0.0);
            $b = (int)round($v * 127);
            $bytes .= chr(max(-127, min(127, $b)) & 0xFF);
        }
        return $bytes;
    }

    /**
     * Zweite Etappe: Seite für Seite lesen, in Abschnitte schneiden, einbetten
     * und BEIDES an Zwischendateien anhängen. Angehängt wird direkt auf der
     * Platte (1 ms für 2 MB gemessen) — der Zustand im Attribut bleibt dadurch
     * klein, obwohl am Ende Megabyte entstehen.
     *
     * @return array<string,mixed>
     */
    private function DokuLesenPhase(array $s, float $ende): array
    {
        $textDatei = $this->DokuDatei('bau_text');
        $vekDatei  = $this->DokuDatei('bau_vek');
        $liste = $s['liste'];
        $anzahl = count($liste);
        while ($s['pos'] < $anzahl && microtime(true) < $ende) {
            $pfad = (string)$liste[$s['pos']];
            $s['pos']++;
            $html = $this->DokuHol($pfad, 8);
            if ($html === '') {
                continue;
            }
            $inhalt = $this->DokuInhalt($html);
            $stuecke = $this->DokuZerlegen($inhalt['titel'], $inhalt['text']);
            if ($stuecke === []) {
                continue;
            }
            $vektoren = $this->DokuEinbetten($stuecke);
            if ($vektoren === null) {
                // Ohne Einbettung keine halben Daten: Seite überspringen, es
                // bleibt die Stichwortsuche. Ein Abbruch der ganzen Etappe wäre
                // schlimmer — ein einzelner Aussetzer darf nicht alles kosten.
                $s['pos']--;
                $this->DokuStandSchreiben($s);
                return $s;
            }
            $zeilen = '';
            $bytes  = '';
            foreach ($stuecke as $i => $text) {
                $zeilen .= json_encode(['p' => $pfad, 't' => $inhalt['titel'], 'x' => $text],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                $bytes  .= $this->DokuPacken($vektoren[$i]);
            }
            @file_put_contents($textDatei, $zeilen, FILE_APPEND);
            @file_put_contents($vekDatei, $bytes, FILE_APPEND);
            $s['stuecke'] += count($stuecke);
        }
        if ($s['pos'] >= $anzahl) {
            $this->DokuAblegen();
            $s['phase']  = 'fertig';
            $s['fertig'] = true;
            $s['stand']  = time();
            $s['liste']  = [];      // wird nicht mehr gebraucht, spart Platz im Attribut
        }
        $s['tiefe'] = self::DOKU_TIEFE;
        $s['bau']   = self::DOKU_BAU;
        $this->DokuStandSchreiben($s);
        return $s;
    }

    /**
     * Die fertigen Zwischendateien in Medienobjekte überführen. Erst dadurch
     * stehen sie im Objektbaum, wandern in die Datensicherung und sind
     * löschbar — gelesen wird später trotzdem direkt von der Platte, weil das
     * gemessen 1 ms statt 32 ms kostet.
     */
    private function DokuAblegen(): void
    {
        foreach ([['bau_text', 'text', 'SymDo Handbuch — Abschnitte'],
                  ['bau_vek',  'vek',  'SymDo Handbuch — Vektoren']] as [$von, $nach, $name]) {
            $roh = @file_get_contents($this->DokuDatei($von));
            if (!is_string($roh) || $roh === '') {
                continue;
            }
            @file_put_contents($this->DokuDatei($nach), $roh);
            @unlink($this->DokuDatei($von));
            $this->DokuMedium($nach, $name);
        }
    }

    /** Ein Medienobjekt auf die Datei zeigen lassen (einmalig anlegen). */
    private function DokuMedium(string $art, string $name): void
    {
        $ident = 'DokuRag' . ucfirst($art);
        $mid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        try {
            if (!$mid) {
                $mid = IPS_CreateMedia(5);                 // MEDIATYPE_DOCUMENT
                IPS_SetParent($mid, $this->InstanceID);
                IPS_SetIdent($mid, $ident);
                IPS_SetName($mid, $name);
            }
            // Auf die BESTEHENDE Datei zeigen, nicht neu schreiben: sie liegt
            // schon da, und ein zweites Mal 2 MB durch base64 zu schicken wäre
            // nur Arbeit.
            IPS_SetMediaFile($mid, 'media/' . basename($this->DokuDatei($art)), false);
        } catch (\Throwable $e) {
            $this->SendDebug('Doku', 'Medienobjekt ' . $art . ': ' . $e->getMessage(), 0);
        }
    }

    /** Kleinschreibung + Umlautfaltung, wie beim Auflösen von Titeln. */
    private function DokuNorm(string $t): string
    {
        /* Binde- und Unterstrich fallen WEG, nicht auf ein Leerzeichen: die Doku
           schreibt „ips-setproperty", gefragt wird nach „IPS_SetProperty". Beide
           werden so zu „ipssetproperty" und finden sich. */
        $t = strtr(mb_strtolower($t), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
                                       '-' => '', '_' => '']);
        return trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    }

    /**
     * Die passendsten Seiten zur Frage. Bewertet wird gegen den Pfad: die
     * Adressen sind sprechend (…/befehlsreferenz/variablenzugriff/setvalue/),
     * und bei PHP-Funktionen IST der letzte Abschnitt der Funktionsname.
     *
     * @return list<array{pfad:string,punkte:int}>
     */
    private function DokuSuchen(string $frage, array $seiten): array
    {
        /* Ohne Stoppwörter wird jede Frage zum Zufallstreffer: „Was ist ein
           Variablenprofil?" landete über das „ein" in „einfuehrung" und über
           „ist" irgendwo — als Antwort kam die Google-Assistant-Seite. */
        static $stopp = ['was', 'ist', 'sind', 'ein', 'eine', 'einen', 'einem', 'der', 'die', 'das',
            'den', 'dem', 'des', 'wie', 'wo', 'wer', 'warum', 'kann', 'ich', 'man', 'mir', 'mich',
            'und', 'oder', 'fuer', 'mit', 'von', 'zum', 'zur', 'auf', 'bei', 'aus', 'nach', 'ueber',
            'gibt', 'macht', 'machen', 'legen', 'lege', 'erstellen', 'erstelle', 'bitte', 'symcon',
            'funktioniert', 'bedeutet', 'heisst', 'nochmal', 'genau', 'eigentlich'];
        $worte = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}_]+/u', $this->DokuNorm($frage), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn(string $w): bool => mb_strlen($w) >= 4 && !in_array($w, $stopp, true)
        ));
        if ($worte === []) {
            return [];
        }
        $treffer = [];
        foreach (array_keys($seiten) as $pfad) {
            $teile = array_values(array_filter(explode('/', trim((string)$pfad, '/'))));
            $blatt = $this->DokuNorm((string)end($teile));
            $rumpf = $this->DokuNorm(implode(' ', array_slice($teile, 3)));
            if ($rumpf === '') {
                continue;
            }
            $p = 0;
            foreach ($worte as $w) {
                // Der Blattname ist das stärkste Signal — dort steht der
                // Funktionsname bzw. das Thema.
                if ($blatt === $w) {
                    $p += 100;
                } elseif (str_contains($blatt, $w)) {
                    $p += 40;
                } elseif (str_contains($rumpf, $w)) {
                    $p += 12;
                }
            }
            if ($p > 0) {
                // Tiefe Seiten sind konkreter als Übersichten.
                $p += min(6, count($teile) - 3);
                $treffer[] = ['pfad' => (string)$pfad, 'punkte' => $p];
            }
        }
        usort($treffer, static fn(array $a, array $b): int => $b['punkte'] <=> $a['punkte']);
        /* Ein schwacher Treffer ist schlechter als keiner: er klingt nach Antwort
           und ist doch nur ein zufaellig geteiltes Wortstueck. */
        $treffer = array_values(array_filter($treffer,
            static fn(array $t): bool => $t['punkte'] >= 40));
        return array_slice($treffer, 0, 5);
    }

    /**
     * Den Fließtext einer Seite herausschälen. Der echte Titel ist die LETZTE
     * <h1> — davor stehen der Feedback-Kasten und das Wort „Dokumentation".
     *
     * @return array{titel:string,text:string}
     */
    private function DokuInhalt(string $html): array
    {
        if (preg_match_all('#<h1[^>]*>(.*?)</h1>#is', $html, $m, PREG_OFFSET_CAPTURE) < 1) {
            return ['titel' => '', 'text' => ''];
        }
        $letzte = end($m[0]);
        $titel  = trim(strip_tags((string)end($m[1])[0]));
        $rest   = substr($html, (int)$letzte[1] + strlen((string)$letzte[0]));
        foreach (['<footer', 'Was können wir verbessern', 'id="footer"', '<h4'] as $marke) {
            $i = strpos($rest, $marke);
            if ($i !== false && $i > 0) {
                $rest = substr($rest, 0, $i);
                break;
            }
        }
        // Signatur und Beispiele erhalten — OHNE spitze Klammern als Marke,
        // die fräse der Tag-Filter gleich wieder weg.
        $rest = preg_replace_callback('#<pre[^>]*>(.*?)</pre>#is',
            static fn(array $t): string => "\n" . trim(strip_tags($t[1])) . "\n", $rest) ?? $rest;
        $rest = preg_replace('#<script.*?</script>|<style.*?</style>#is', '', $rest) ?? $rest;
        $rest = preg_replace_callback('#<h([2-4])[^>]*>(.*?)</h\1>#is',
            static fn(array $t): string => "\n" . trim(strip_tags($t[2])) . ': ', $rest) ?? $rest;
        $rest = preg_replace('#</(p|tr|li|div)>#i', "\n", $rest) ?? $rest;
        $rest = preg_replace('#</td>#i', ' — ', $rest) ?? $rest;
        $rest = html_entity_decode(strip_tags($rest), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rest = preg_replace('/[ \t]+/', ' ', $rest) ?? $rest;
        $rest = preg_replace("/\n\s*\n\s*/", "\n", $rest) ?? $rest;
        return ['titel' => $titel, 'text' => trim($rest)];
    }

    /**
     * Bedeutungssuche: die Frage einbetten und gegen alle Abschnitte halten.
     * Gibt die besten Abschnitte zurück — oder null, wenn die Ablage fehlt oder
     * die Einbettung nicht erreichbar ist (dann greift die Stichwortsuche).
     *
     * @return list<array{pfad:string,titel:string,text:string,punkte:float}>|null
     */
    private function DokuBedeutungssuche(string $frage): ?array
    {
        $vekDatei  = $this->DokuDatei('vek');
        $textDatei = $this->DokuDatei('text');
        if (!is_file($vekDatei) || !is_file($textDatei)) {
            return null;
        }
        $einbettung = $this->DokuEinbetten([$frage]);
        if ($einbettung === null) {
            return null;
        }
        $q = [];
        for ($i = 0; $i < self::DOKU_DIM; $i++) {
            $q[$i] = (float)($einbettung[0][$i] ?? 0.0);
        }
        // Direkt von der Platte: 1 ms statt 32 ms über IPS_GetMediaContent.
        $blob = @file_get_contents($vekDatei);
        if (!is_string($blob) || $blob === '') {
            return null;
        }
        $anzahl = intdiv(strlen($blob), self::DOKU_DIM);
        $beste = [];   // Rang → [index, punkte]
        for ($n = 0; $n < $anzahl; $n++) {
            $v = unpack('c' . self::DOKU_DIM, substr($blob, $n * self::DOKU_DIM, self::DOKU_DIM));
            $summe = 0.0;
            for ($i = 1; $i <= self::DOKU_DIM; $i++) {
                $summe += $v[$i] * $q[$i - 1];
            }
            if (count($beste) < self::DOKU_TOP) {
                $beste[] = [$n, $summe];
                usort($beste, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
            } elseif ($summe > $beste[0][1]) {
                $beste[0] = [$n, $summe];
                usort($beste, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
            }
        }
        if ($beste === []) {
            return null;
        }
        $beste = array_reverse($beste);
        // Nur die gebrauchten Zeilen aus der Textdatei holen, statt 2,7 MB zu
        // dekodieren: die Datei ist zeilenweise, eine Zeile je Abschnitt.
        $gesucht = [];
        foreach ($beste as [$n, $punkte]) {
            $gesucht[$n] = $punkte;
        }
        $treffer = [];
        $zeiger = @fopen($textDatei, 'r');
        if ($zeiger === false) {
            return null;
        }
        $n = 0;
        while (($zeile = fgets($zeiger)) !== false) {
            if (isset($gesucht[$n])) {
                $d = json_decode(trim($zeile), true);
                if (is_array($d)) {
                    $treffer[] = ['pfad' => (string)($d['p'] ?? ''), 'titel' => (string)($d['t'] ?? ''),
                                  'text' => (string)($d['x'] ?? ''), 'punkte' => (float)$gesucht[$n]];
                }
            }
            $n++;
        }
        fclose($zeiger);
        usort($treffer, static fn(array $a, array $b): int => $b['punkte'] <=> $a['punkte']);
        /* Ein zu schwacher Bestwert heisst „nichts Passendes" — sonst kaeme auf
           jede Frage irgendein Abschnitt, und das klaenge nach Antwort. Der
           Wert ist ein Skalarprodukt aus einem Byte-Vektor gegen einen
           Einheitsvektor; bei 512 Dimensionen liegen gute Treffer deutlich
           ueber 20. */
        if ($treffer === [] || $treffer[0]['punkte'] < 20.0) {
            return [];
        }
        return $treffer;
    }

    /**
     * Das Werkzeug: eine Frage zum Handbuch beantworten.
     * @return array<string,mixed>
     */
    private function VoiceToolHandbuch(array $args, array $ctx): array
    {
        $frage = trim((string)($args['frage'] ?? ''));
        if ($frage === '') {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('What would you like to know about Symcon?'));
        }
        $s = $this->DokuStand();
        if (!$s['fertig'] || (time() - $s['stand']) >= self::DOKU_HALTBAR) {
            // Nicht hier bauen — das sprengte die Werkzeugfrist. Nur anstoßen.
            @$this->SetTimerInterval('DokuIndex', self::DOKU_TAKT);
        }

        /* Zuerst nach BEDEUTUNG suchen. Fehlt die Ablage oder ist die
           Einbettung nicht erreichbar, bleibt die Stichwortsuche über die
           Adressen — sie ist schwächer, aber immer da. */
        $abschnitte = $this->DokuBedeutungssuche($frage);
        $seite = '';
        $auszug = '';
        $titel = '';
        $weitereTitel = [];
        if (is_array($abschnitte) && $abschnitte !== []) {
            $seite  = $abschnitte[0]['pfad'];
            $titel  = $abschnitte[0]['titel'];
            /* Die gefundenen Abschnitte SIND die Antwortgrundlage — anders als
               bei der Adress-Suche, die nur auf eine Seite zeigt und dann deren
               Anfang schickt. Mehrere Abschnitte erlauben auch Fragen, die zwei
               Seiten brauchen. */
            $teile = [];
            foreach ($abschnitte as $a) {
                $teile[] = $a['text'];
                if ($a['titel'] !== $titel && !in_array($a['titel'], $weitereTitel, true)) {
                    $weitereTitel[] = $a['titel'];
                }
            }
            $auszug = implode("\n\n", $teile);
        } elseif (is_array($abschnitte)) {
            // Ablage da, aber nichts Passendes gefunden.
            return [
                'ok' => false, 'error' => ['code' => 'nicht_gefunden', 'message' => 'nichts im Handbuch'],
                'sag' => sprintf($this->Translate('I cannot find anything about "%s" in the Symcon manual.'), $frage),
            ];
        } else {
            $treffer = $this->DokuSuchen($frage, $s['seiten']);
            if ($treffer === []) {
                return [
                    'ok' => false, 'error' => ['code' => 'nicht_gefunden', 'message' => 'nichts im Handbuch'],
                    'sag' => $s['fertig']
                        ? sprintf($this->Translate('I cannot find anything about "%s" in the Symcon manual.'), $frage)
                        : $this->Translate('The manual index is still being built — please ask again in a moment.'),
                ];
            }
            $seite = $treffer[0]['pfad'];
            foreach (array_slice($treffer, 1, 3) as $t) {
                $teile = array_values(array_filter(explode('/', trim($t['pfad'], '/'))));
                $weitereTitel[] = (string)end($teile);
            }
        }

        /* Den Text IMMER live nachladen: die Abschnitte stammen aus dem letzten
           Aufbau, die Seite kann sich seither geändert haben. Gelingt das nicht,
           bleiben die abgelegten Abschnitte — besser als keine Antwort. */
        $html = $this->DokuHol($seite);
        if ($html !== '') {
            $inhalt = $this->DokuInhalt($html);
            if (trim($inhalt['text']) !== '') {
                $titel = $inhalt['titel'] !== '' ? $inhalt['titel'] : $titel;
                if ($auszug === '') {
                    $auszug = $inhalt['text'];
                } else {
                    /* Steht der gefundene Abschnitt noch so auf der frischen
                       Seite, gilt die frische Fassung — sonst der abgelegte
                       Text. So bleibt die Antwort aktuell UND an der Stelle,
                       die zur Frage passt. */
                    $kern = trim(mb_substr($abschnitte[0]['text'], mb_strpos($abschnitte[0]['text'], '—') !== false
                        ? (int)mb_strpos($abschnitte[0]['text'], '—') + 1 : 0, 120));
                    $wo = $kern !== '' ? mb_strpos($inhalt['text'], $kern) : false;
                    if ($wo !== false) {
                        $auszug = trim(mb_substr($inhalt['text'], max(0, (int)$wo - 200), self::DOKU_AUSZUG));
                    }
                }
            }
        }
        if (trim($auszug) === '') {
            return $this->VoiceErr('nicht_bereit', $this->Translate('The Symcon manual is not reachable right now.'));
        }
        $text = mb_strlen($auszug) > self::DOKU_AUSZUG
            ? mb_substr($auszug, 0, self::DOKU_AUSZUG) . ' …' : $auszug;

        /* Die Adresse geht NUR ins Protokoll, nicht an das Modell. Lag sie in
           der Antwort, verwies es darauf, statt den Auszug zu benutzen — und
           eine vorgelesene Internetadresse kann sich ohnehin niemand merken. */
        $this->SendDebug('Doku', 'Quelle: ' . self::DOKU_HOST . $seite, 0);
        return [
            'ok'      => true,
            'titel'   => $titel,
            'auszug'  => $text,
            'weitere' => $weitereTitel,
            /* Anders als bei allen anderen Werkzeugen ist „sag" hier keine
               fertige Auskunft, sondern nur der ANFANG des Satzes: die Antwort
               steht im Auszug und muss von dort kommen. Ein abgeschlossenes
               „Ich habe X gefunden." lud das Modell dazu ein, genau dort
               aufzuhören. */
            'sag'     => sprintf($this->Translate('The manual says about "%s":'), $titel),
        ];
    }
}
