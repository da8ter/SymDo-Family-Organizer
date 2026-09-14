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
        $this->DokuStandUebergeben();

        $s = $this->DokuStand();
        $frisch = $s['fertig'] && (time() - $s['stand']) < self::DOKU_HALTBAR;

        if ($this->ScanQuelleUebernommen('doku')) {
            /* Ein Scanner baut. Unser eigener Zeitgeber bleibt aus — sonst
               liefe der Bau zweimal, und zwar der teure Teil: jede Seite
               zweimal geholt und zweimal eingebettet. */
            @$this->SetTimerInterval('DokuIndex', 0);
            if (!$frisch && $this->DokuBaubar()) {
                $this->ScanAuftragGeben('doku', ['anlass' => 'start']);
            }
            return;
        }
        @$this->SetTimerInterval('DokuIndex', ($frisch || !$this->DokuBaubar()) ? 0 : self::DOKU_TAKT);
    }

    /**
     * Den Bauzustand EINMAL aus dem alten Attribut in die Datei heben.
     *
     * Ohne diesen Griff faenge der Scanner nach der Aktualisierung bei null
     * an: Attribute einer fremden Instanz kann er nicht lesen. Er kroche
     * tausenddreihundert Seiten neu und bettete sie neu ein — anderthalb Cent
     * und ein halber Tag fuer ein Verzeichnis, das fertig auf der Platte liegt.
     *
     * Geschrieben wird nur, wenn es die Datei noch nicht gibt: ab dann gehoert
     * sie dem, der baut.
     */
    private function DokuStandUebergeben(): void
    {
        if (@is_file($this->DokuStandDatei())) {
            return;
        }
        $roh = json_decode((string)@$this->ReadAttributeString('DokuIndex'), true);
        if (!is_array($roh) || !is_array($roh['seiten'] ?? null)) {
            return;
        }
        /* Nur ein Stand aus DIESEN Einstellungen ist etwas wert. Mit Tiefe 6
           fehlten seinerzeit alle SDK-Seiten; so einen weiterzureichen hiesse,
           ein halbes Verzeichnis als fertig auszugeben. */
        if ((int)($roh['tiefe'] ?? 0) !== self::DOKU_TIEFE
            || (int)($roh['bau'] ?? 0) !== self::DOKU_BAU) {
            return;
        }
        $this->DokuStandSchreiben($roh);
        $this->SendDebug('Doku', 'Bauzustand in die Datei uebergeben: '
            . count($roh['seiten']) . ' Seiten', 0);
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
        /* Bedient inzwischen ein Scanner die Quelle, ist dieser Zeitgeber ein
           Ueberrest. Abgestellt wird er sonst nur in DokuApplyChanges — und das
           laeuft erst beim naechsten Kernelstart wieder. Bis dahin baute das
           Gateway NEBEN dem Scanner: dieselben tausenddreihundert Seiten
           zweimal geholt, zweimal eingebettet, und beide haengen an dieselben
           Zwischendateien an. Text und Vektor stehen dort Zeile fuer Zeile
           gepaart — ein fremder Anhang dazwischen verschiebt die Paarung
           dauerhaft, und das Verzeichnis antwortet ab dann mit den Abschnitten
           der falschen Seiten. */
        if ($this->ScanQuelleUebernommen('doku')) {
            @$this->SetTimerInterval('DokuIndex', 0);
            return;
        }
        /* Waehrend eines laufenden Gespraechs GAR NICHT bauen: dort zaehlt jede
           Zehntelsekunde, und die Werkzeugfrist des Sprachkerns liegt bei 8 s. */
        if ($this->VoiceCalls() !== []) {
            return;
        }
        /* Der Schluessel kann im Betrieb verschwinden (Anbieterwechsel). Dann
           haelt der Bau an, statt in die Schleife von oben zu laufen. */
        if (!$this->DokuBaubar()) {
            @$this->SetTimerInterval('DokuIndex', 0);
            $this->SendDebug('Doku', 'Kein OpenAI-Schluessel — Aufbau angehalten.', 0);
            return;
        }
        $s = $this->DokuIndexPflegen();
        /* Abgestellt wird HIER, nicht im Bau: der kennt seinen Zeitgeber nicht
           mehr, weil er im Scanner anders heisst. Zwei Gruende zum Aufhoeren —
           fertig, oder dreimal hintereinander keine Einbettung. */
        if ($s['fertig'] || (int)($s['fehler'] ?? 0) >= self::DOKU_FEHLER_MAX) {
            @$this->SetTimerInterval('DokuIndex', 0);
        }
        if ($s['fertig']) {
            $this->SendDebug('Doku', 'Verzeichnis fertig: ' . count($s['seiten']) . ' Seiten', 0);
        }
    }

    /**
     * Zu welchem Bereich des Handbuchs gehoert eine Seite? Die Adresse sagt es
     * zuverlaessig — dafuer braucht es kein Modell.
     *
     * Das ist wichtiger, als es klingt: die meisten Fragenden BEDIENEN Symcon,
     * sie programmieren keine Module. Gemessen am 02.09.2026 fand „Wie reagiere
     * ich auf eine Wertaenderung?" in den besten vier AUSSCHLIESSLICH
     * Entwicklerseiten (MessageSink, WD_GetAlertTargets), waehrend die Antwort
     * fuer einen Endnutzer schlicht „ein Ereignis vom Typ Bei Aenderung" lautet.
     */
    private function DokuBereich(string $pfad): string
    {
        foreach (['entwicklerbereich', 'befehlsreferenz', 'modulreferenz'] as $teil) {
            if (str_contains($pfad, $teil)) {
                return 'entwickler';
            }
        }
        return 'bedienung';
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
     * Bedeutungssuche: die Frage einbetten und gegen alle Abschnitte halten.
     * Gibt die besten Abschnitte zurück — oder null, wenn die Ablage fehlt oder
     * die Einbettung nicht erreichbar ist (dann greift die Stichwortsuche).
     *
     * @return list<array{pfad:string,titel:string,text:string,punkte:float}>|null
     */
    /**
     * Einen Auszug an Wortgrenzen beginnen lassen. Die Abschnitte werden nach
     * ZEICHENZAHL geschnitten, nicht nach Saetzen — vorgelesen fing die
     * Antwort damit gern mitten im Wort an („s PHP einen Timer & Variable
     * anlegen"). Faengt der Text nicht sauber an, wird bis zum naechsten
     * Satzende vorgerueckt, wenn eines in Reichweite liegt, sonst wenigstens
     * bis zur naechsten Wortgrenze.
     */
    private function DokuRand(string $t): string
    {
        $t = trim($t);
        if ($t === '' || preg_match('/^["„(\[]?[A-ZÄÖÜ0-9]/u', $t) === 1) {
            return $t;
        }
        // Nur echte Satzenden: der Doppelpunkt steht in Code ueberall
        // (`mixed $Value): void`, `case "X":`) und schnitte mitten hinein.
        if (preg_match('/^.{0,90}?[.!?]\s+/su', $t, $m) === 1) {
            return ltrim(mb_substr($t, mb_strlen($m[0])));
        }
        $p = mb_strpos($t, ' ');
        return ($p !== false && $p < 40) ? ltrim(mb_substr($t, $p + 1)) : $t;
    }

    /**
     * Einen Abschnitt am letzten SATZENDE beenden.
     *
     * Die Abschnitte werden nach Zeichenzahl geschnitten und hoeren deshalb
     * mitten im Satz auf. Das Lesemodell vervollstaendigt so einen Satz dann
     * aus eigenem Wissen — am 02.09.2026 beobachtet: der Auszug endete mit
     * „Kopie einer beliebigen (z.B. die", die Antwort ergaenzte „in die
     * Konfigurationsdatei settings.json". Das war zufaellig richtig, ist aber
     * genau die Stelle, an der Erfundenes entsteht.
     *
     * Gekappt wird nur, wenn genug uebrig bleibt: ein Abschnitt ohne jedes
     * Satzende (Code, Tabellen) bleibt lieber ganz.
     */
    private function DokuSatzEnde(string $t): string
    {
        $t = rtrim($t);
        if ($t === '') {
            return $t;
        }
        /* Abkuerzungen sind KEIN Satzende. Ohne diese Liste endete der Auszug
           bei „Kopie einer beliebigen (z.B." — genauso mitten im Satz wie
           vorher, nur mit Punkt. */
        $abk = '/(?:\s\p{L}\.|z\.\s?B\.|ca\.|bzw\.|usw\.|etc\.|Nr\.|Abs\.|vgl\.|ggf\.|evtl\.)$/u';
        if (preg_match('/[.!?:]$/u', $t) === 1 && preg_match($abk, $t) !== 1) {
            return $t;
        }
        if (preg_match_all('/[.!?](?=\s)/u', $t, $m, PREG_OFFSET_CAPTURE) > 0) {
            $mindest = (int)(mb_strlen($t) * 0.5);
            for ($i = count($m[0]) - 1; $i >= 0; $i--) {
                // PREG_OFFSET_CAPTURE liefert BYTE-Positionen — also substr, nicht mb_substr.
                $kand = rtrim(substr($t, 0, (int)$m[0][$i][1] + 1));
                if (mb_strlen($kand) < $mindest) {
                    break;
                }
                if (preg_match($abk, $kand) === 1) {
                    continue;
                }
                return $kand;
            }
        }
        return $t;
    }

    private function DokuBedeutungssuche(string $frage): ?array
    {
        $vekDatei  = $this->DokuDatei('vek');
        $textDatei = $this->DokuDatei('text');
        if (!is_file($vekDatei) || !is_file($textDatei)) {
            return null;
        }
        /* Kurze Frist: hier wartet jemand auf eine gesprochene Antwort, und das
           Sprachbudget sind acht Sekunden insgesamt. Der BAU nimmt sich die
           vollen dreissig — er laeuft im Scanner, wo niemand zusieht. */
        $einbettung = $this->DokuEinbetten([$frage], self::DOKU_EINBETT_SPRACHE_S);
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
        /* Mehr sammeln als am Ende gebraucht wird: eine grosse Seite (die FAQ
           „Wie kann ich…?" hat hunderte Abschnitte) belegt sonst alle Plaetze,
           und die Seite mit der eigentlichen Antwort fliegt heraus. Gemessen an
           „Skript zyklisch alle 5 Minuten": 6 der besten 10 Abschnitte kamen von
           dieser einen FAQ-Seite, „Zyklisch" stand auf Platz 2 und fiel damit
           unter den Tisch. */
        $vorrat = self::DOKU_TOP * 8;
        $beste = [];   // Rang → [index, punkte]
        for ($n = 0; $n < $anzahl; $n++) {
            $v = unpack('c' . self::DOKU_DIM, substr($blob, $n * self::DOKU_DIM, self::DOKU_DIM));
            $summe = 0.0;
            for ($i = 1; $i <= self::DOKU_DIM; $i++) {
                $summe += $v[$i] * $q[$i - 1];
            }
            if (count($beste) < $vorrat) {
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
        /* Je SEITE nur der beste Abschnitt. So stehen in der Antwort vier
           verschiedene Seiten statt vier Ausschnitte derselben — bei einer
           Frage, die mehrere Wege hat (Ereignis ODER IPS_SetScriptTimer), ist
           genau das der Unterschied zwischen richtig und unbrauchbar. */
        $jeSeite = [];
        foreach ($treffer as $t) {
            $pfad = (string)$t['pfad'];
            if (!isset($jeSeite[$pfad])) {
                $jeSeite[$pfad] = $t;
            }
        }
        $liste = array_values($jeSeite);
        /* Beide Bereiche vertreten, wenn es sie gibt: sonst liegen dem Leser
           nur Entwicklerseiten vor und er KANN nicht fuer einen Endnutzer
           antworten, selbst wenn er wollte. Ein Platz von vieren wird dafuer
           freigehalten — mehr nicht, damit die Rangfolge bestimmend bleibt. */
        $treffer = array_slice($liste, 0, self::DOKU_TOP);
        $bereiche = array_unique(array_map(
            fn(array $t): string => $this->DokuBereich((string)$t['pfad']), $treffer));
        if (count($bereiche) === 1) {
            $fehlt = reset($bereiche) === 'entwickler' ? 'bedienung' : 'entwickler';
            foreach (array_slice($liste, self::DOKU_TOP) as $t) {
                if ($this->DokuBereich((string)$t['pfad']) === $fehlt) {
                    array_pop($treffer);
                    $treffer[] = $t;
                    break;
                }
            }
        }
        /* Ein zu schwacher Bestwert heisst „nichts Passendes" — sonst kaeme auf
           jede Frage irgendein Abschnitt, und das klaenge nach Antwort. Der
           Wert ist ein Skalarprodukt aus einem Byte-Vektor gegen einen
           Einheitsvektor, entspricht also dem 127-fachen Kosinus.

           Am fertigen Verzeichnis (4677 Abschnitte) gemessen: echte Fragen
           kamen auf 74 bis 96 Punkte (Kosinus 0,58-0,76), abwegige auf 35 bis
           46 (0,27-0,36) — „Wie backe ich einen Kuchen?" landete bei 43,5 auf
           der Seite „Aufzaehlung". Die Schwelle liegt darum mitten in der
           Luecke; die alten 20 (Kosinus 0,16) lagen unter dem Rauschen und
           liessen JEDE Frage beantwortet aussehen. */
        if ($treffer === [] || $treffer[0]['punkte'] < 60.0) {
            return [];
        }
        return $treffer;
    }

    /** Welches Modell liest? Leer heisst: gar keins (dann bleibt der Satzanfang). */
    private function DokuLeserModell(): string
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        // Property gibt es erst nach dem naechsten Kernel-Start; bis dahin die Vorgabe.
        return is_array($cfg) && array_key_exists('VoiceDocModel', $cfg)
            ? trim((string)$cfg['VoiceDocModel']) : self::DOKU_LESER_VORGABE;
    }

    /**
     * Aus Frage und Fundstellen eine fertige, vorlesbare Antwort machen.
     * Leerer Rueckgabewert heisst: hat nicht geklappt — dann bleibt es beim
     * Satzanfang samt Auszug, also beim Verhalten von vorher.
     */
    private function DokuAntwortFormulieren(string $frage, string $auszug): string
    {
        $modell = $this->DokuLeserModell();
        $key    = trim((string) $this->AiProp('AiOpenAIKey'));
        if ($modell === '' || $key === '' || trim($auszug) === '') {
            return '';
        }
        $anweisung = 'Du beantwortest eine Frage zu IP-Symcon AUSSCHLIESSLICH aus den unten '
            . 'gelieferten Auszuegen des offiziellen Handbuchs. Die Auszuege stammen von mehreren '
            . 'Seiten; jeder ist mit seinem Seitennamen in eckigen Klammern beschriftet. Waehle den '
            . 'Teil, der die Frage wirklich beantwortet — der erste ist nicht immer der richtige. '
            . 'Antworte auf Deutsch in drei bis fuenf kurzen Saetzen, die VORGELESEN werden: keine '
            . 'Aufzaehlungszeichen, keine Zeilenumbrueche, keine Adressen, keine '
            . 'Klammer-Beschriftungen, kein Code-Block. Sprich den Fragenden mit DU an. Schreibe '
            . 'Abkuerzungen aus („zum Beispiel" statt „z. B.", „Grad Celsius" statt „°C"), denn '
            . 'eine Stimme liest sie sonst falsch vor. Sage NICHT, woher du es weisst — kein „im '
            . 'Auszug steht", kein „laut Handbuch": antworte einfach. '
            . 'WER FRAGT: fast immer jemand, der Symcon BEDIENT, nicht jemand, der ein Modul '
            . 'programmiert. Die Auszuege sind mit „Bedienung" oder „Entwickler" beschriftet. '
            . 'Passen beide auf die Frage, antworte aus der BEDIENUNG und biete den Weg fuer '
            . 'Entwickler in einem Halbsatz an („in einem Modul geht das über die passende SDK-Funktion"). '
            . 'Fragt jemand ausdruecklich nach Modul, PHP, SDK oder einer Funktion, antworte '
            . 'direkt aus dem Entwicklerteil und lass diesen Halbsatz WEG — er waere dann eine '
            . 'Wiederholung. '
            . 'Nur wenn du ohne Klaerung nicht antworten kannst, stelle GENAU EINE kurze '
            . 'Rueckfrage und sonst nichts. '
            . 'Nenne konkrete Namen von Funktionen, Feldern oder Menuepunkten, wenn sie im Auszug '
            . 'stehen. VERWEISE NICHT auf andere Seiten oder Abschnitte des Handbuchs und nicht '
            . 'auf die Befehlsreferenz — sage, was zu tun ist, oder sage, dass es im Auszug nicht '
            . 'steht. Steht die Antwort NICHT in den Auszuegen, sage genau das in einem Satz und '
            . 'erfinde nichts.';
        $rumpf = [
            'model'    => $modell,
            'messages' => [
                ['role' => 'system', 'content' => $anweisung],
                ['role' => 'user',   'content' => 'Frage: ' . $frage . "\n\nAuszuege:\n" . $auszug],
            ],
            'max_completion_tokens' => self::DOKU_LESER_TOKEN,
        ];
        /* Nur die gpt-5-Reihe kennt reasoning_effort — und braucht es: ohne die
           Angabe steckt sie das ganze Budget ins Nachdenken und liefert nichts.
           gpt-4.1 wuerde den unbekannten Parameter mit HTTP 400 abweisen. */
        if (str_starts_with($modell, 'gpt-5') || str_starts_with($modell, 'o4')) {
            $rumpf['reasoning_effort'] = 'minimal';
        }
        try {
            $a = $this->AiHttpPost('https://api.openai.com/v1/chat/completions',
                ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
                (string)json_encode($rumpf, JSON_UNESCAPED_UNICODE), self::DOKU_LESER_FRIST);
        } catch (\Throwable $e) {
            $this->SendDebug('Doku', 'Leser warf: ' . $e->getMessage(), 0);
            return '';
        }
        if ((int)($a['status'] ?? 0) !== 200) {
            $this->SendDebug('Doku', 'Leser HTTP ' . (int)($a['status'] ?? 0) . ' '
                . mb_substr((string)($a['err'] ?? '') . (string)($a['body'] ?? ''), 0, 200), 0);
            return '';
        }
        $d = json_decode((string)($a['body'] ?? ''), true);
        $text = trim((string)((($d['choices'][0]['message']['content']) ?? '')));
        // Ein leerer Text ist kein Erfolg — dann lieber der alte Weg.
        return $text;
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
        $t0 = microtime(true);
        $zeit = [];
        $s = $this->DokuStand();
        if ((!$s['fertig'] || (time() - $s['stand']) >= self::DOKU_HALTBAR) && $this->DokuBaubar()) {
            // Nicht hier bauen — das sprengte die Werkzeugfrist. Nur anstoßen,
            // und nur, wenn der Bau überhaupt gelingen kann (siehe DokuBaubar).
            if ($this->ScanQuelleUebernommen('doku')) {
                $this->ScanAuftragGeben('doku', ['anlass' => 'hook']);
            } else {
                @$this->SetTimerInterval('DokuIndex', self::DOKU_TAKT);
            }
        }

        /* Zuerst nach BEDEUTUNG suchen. Fehlt die Ablage oder ist die
           Einbettung nicht erreichbar, bleibt die Stichwortsuche über die
           Adressen — sie ist schwächer, aber immer da. */
        $abschnitte = $this->DokuBedeutungssuche($frage);
        $zeit['suche'] = (int)round((microtime(true) - $t0) * 1000);
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
            /* JEDER Abschnitt mit seiner Seite beschriftet. Vorher waren die
               Fundstellen unbeschriftet aneinandergeklebt — das Modell konnte
               nicht erkennen, dass der zweite Absatz von der Seite „Zyklisch"
               kommt und die eigentliche Antwort ist, und blieb entsprechend
               vage. Der Name der Seite ist der billigste Hinweis darauf, was
               ein Absatz beantwortet. */
            $teile = [];
            foreach ($abschnitte as $a) {
                $stueck = $this->DokuSatzEnde($this->DokuRand($a['text']));
                if ($stueck === '') {
                    continue;
                }
                $ueber = trim((string)$a['titel']);
                // Der Bereich steht dabei: der Leser soll wissen, ob ein Absatz
                // die Bedienung erklaert oder die Programmierung.
                $wo = $this->DokuBereich((string)$a['pfad']) === 'entwickler'
                    ? ' · Entwickler' : ' · Bedienung';
                $teile[] = ($ueber !== '' ? '[' . $ueber . $wo . '] ' : '') . $stueck;
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

        /* Die Seite nur dann live laden, wenn die Stichwortsuche auf sie zeigt —
           dann gibt es noch keinen Text. Liegen Abschnitte aus dem Index vor,
           SIND sie die Antwortgrundlage: der frische Abruf kostete bis zu drei
           Sekunden (gemessen 3,5–5,1 s je Aufruf bei 8 s Frist), und das
           Handbuch aendert sich seltener als der Index (7 Tage) neu gebaut wird. */
        $t1 = microtime(true);
        $html = (is_array($abschnitte) && $abschnitte !== []) ? '' : $this->DokuHol($seite, 3);
        $zeit['seite'] = (int)round((microtime(true) - $t1) * 1000);
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
                        /* Die frische Fassung gilt — aber NUR fuer die fuehrende
                           Seite, und sie darf die anderen Fundstellen nicht
                           verdraengen. Vorher wurde $auszug hier komplett
                           ersetzt: die Antwort bestand dann aus einem Fenster
                           der erstbesten Seite, und die Abschnitte der anderen
                           (oft die eigentliche Antwort) waren weg. Deshalb ein
                           Deckel auf das Fenster, damit fuer den Rest Platz
                           bleibt. */
                        // Vorne an der Wortgrenze, HINTEN am Satzende — sonst
                        // vervollstaendigt der Leser den abgebrochenen Satz aus
                        // eigenem Wissen.
                        $fenster = $this->DokuSatzEnde($this->DokuRand(mb_substr($inhalt['text'],
                            max(0, (int)$wo - 200), (int)(self::DOKU_AUSZUG * 0.55))));
                        $rest = array_slice($teile, 1);
                        // Auch das frische Fenster bekommt seine Ueberschrift.
                        $fenster = '[' . $titel
                            . ($this->DokuBereich($seite) === 'entwickler' ? ' · Entwickler' : ' · Bedienung')
                            . '] ' . $fenster;
                        $auszug = $rest === [] ? $fenster
                            : $fenster . "\n\n" . implode("\n\n", $rest);
                    }
                }
            }
        }
        if (trim($auszug) === '') {
            return $this->VoiceErr('nicht_bereit', $this->Translate('The Symcon manual is not reachable right now.'));
        }
        /* Vorne ist schon geschnitten — je Abschnitt beziehungsweise beim
           Ausschnitt aus der frischen Seite. Ein zweites Mal DokuRand haette
           bei CODE weitergeknabbert: aus „Beispiel:" wurde erst „// IPSModule
           Strict …", dann „void { switch(…". */
        $text = $auszug;
        if (mb_strlen($text) > self::DOKU_AUSZUG) {
            $text = mb_substr($text, 0, self::DOKU_AUSZUG);
            /* Hinten am LETZTEN Satzende kappen (der Ausdruck ist absichtlich
               gierig), sonst wenigstens am letzten Leerzeichen. Ein mitten im
               Wort endender Auszug klingt vorgelesen wie ein Abbruch. */
            if (preg_match('/^(.*[.!?])\s/su', $text, $m) === 1
                && mb_strlen($m[1]) > (int)(self::DOKU_AUSZUG * 0.6)) {
                $text = $m[1];
            } else {
                $p = mb_strrpos($text, ' ');
                if ($p !== false) { $text = mb_substr($text, 0, $p); }
            }
            $text .= ' …';
        }

        /* Die Adresse geht NUR ins Protokoll, nicht an das Modell. Lag sie in
           der Antwort, verwies es darauf, statt den Auszug zu benutzen — und
           eine vorgelesene Internetadresse kann sich ohnehin niemand merken. */
        $this->SendDebug('Doku', 'Quelle: ' . self::DOKU_HOST . $seite, 0);

        /* Jetzt liest ein Textmodell die Fundstellen und formuliert die Antwort.
           Gelingt das, geht NUR sie hinaus — der Auszug bleibt hier, sonst
           faengt das Sprachmodell an, ihn ein zweites Mal zu deuten. */
        $t2 = microtime(true);
        $antwort = $this->DokuAntwortFormulieren($frage, $text);
        $zeit['leser'] = (int)round((microtime(true) - $t2) * 1000);
        $zeit['gesamt'] = (int)round((microtime(true) - $t0) * 1000);
        // Wo die Zeit bleibt, steht im Debug UND in der Antwort (die Kachel ignoriert das Feld).
        $this->SendDebug('Doku', 'Zeiten ms: ' . json_encode($zeit), 0);
        if ($antwort !== '') {
            /* Ohne „weitere": vorgelesene Seitennamen sind Rauschen, und sie
               verleiten das Sprachmodell dazu, sie aufzuzählen statt die
               Antwort zu sagen. Der Titel bleibt als Zusammenhang. */
            return [
                'ok'    => true,
                'titel' => $titel,
                // Fertige Auskunft, wie bei jedem anderen Werkzeug.
                'sag'   => $antwort,
                'zeit'  => $zeit,
            ];
        }

        /* Rückfall auf das alte Verhalten, wenn der Leser nicht erreichbar ist:
           Satzanfang plus Auszug ist schlechter, aber besser als keine Antwort. */
        return [
            'ok'      => true,
            'titel'   => $titel,
            'auszug'  => $text,
            'weitere' => $weitereTitel,
            /* Hier ist „sag" kein fertiger Satz, sondern nur der ANFANG: die
               Antwort steht im Auszug und muss von dort kommen. Ein
               abgeschlossenes „Ich habe X gefunden." lud das Modell dazu ein,
               genau dort aufzuhören. */
            'sag'     => sprintf($this->Translate('The manual says about "%s":'), $titel),
        ];
    }
}
