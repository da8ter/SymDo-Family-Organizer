<?php

declare(strict_types=1);

/**
 * Der Bau des Handbuch-Verzeichnisses: kriechen, lesen, zerlegen, einbetten,
 * ablegen.
 *
 * Laeuft in der Scanner-Instanz (Quelle „doku"), im Gateway nur noch als
 * Rueckfall, solange kein Scanner die Quelle bedient. Deshalb steht hier KEIN
 * Zeitgeber und KEIN Blick auf laufende Gespraeche: beides entscheidet der
 * Aufrufer, und beide Aufrufer entscheiden es verschieden. Der Bau sagt nur,
 * wie weit er gekommen ist — `fertig` und `fehler` im zurueckgegebenen Stand.
 *
 * Braucht aus seiner Klasse: `DokuGemein` (Masse, Pfade, Zustand, Einbettung),
 * `Konfig` (AiProp, BestandID) und `SendDebug`. Mehr nicht — genau deshalb
 * kann der Scanner ihn tragen, ohne das halbe Gateway mitzunehmen.
 */
trait DokuBau
{

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
                /* Ohne Einbettung keine halben Daten: die Seite bleibt liegen
                   und kommt beim naechsten Schlag wieder dran — ein einzelner
                   Aussetzer darf nicht die ganze Etappe kosten.
                   Aber NICHT endlos: geht es dreimal hintereinander nicht (kein
                   Guthaben, gesperrter Schluessel, Anbieter weg), haelt der Bau
                   an. Sonst holt der Timer dieselbe Seite alle acht Sekunden,
                   Tag und Nacht — gemessen waeren das ueber 10.000 Abrufe bei
                   symcon.de am Tag. Der Zaehler faellt beim naechsten Erfolg. */
                $s['fehler'] = (int)($s['fehler'] ?? 0) + 1;
                if ($s['fehler'] >= self::DOKU_FEHLER_MAX) {
                    /* Angehalten wird NICHT hier. Der Bau kennt seinen
                       Zeitgeber nicht mehr — im Gateway heisst er anders als
                       im Scanner. Er meldet die Fehlerkette im Zustand, und
                       wer ihn gerufen hat, stellt seinen Takt ab. */
                    $this->SendDebug('Doku', sprintf(
                        '%dx keine Einbettung — Aufbau angehalten. Naechster Anlauf beim naechsten Uebernehmen.',
                        $s['fehler']), 0);
                }
                $s['pos']--;
                $this->DokuStandSchreiben($s);
                return $s;
            }
            // Ein Erfolg loescht die Fehlerkette.
            $s['fehler'] = 0;
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
        /* Unter BestandID, nicht unter der eigenen Instanz: baut der Scanner,
           soll das Medienobjekt trotzdem dort stehen, wo es immer stand — beim
           Gateway. Sonst haette der Nutzer nach dem Umzug zwei. */
        $ident = 'DokuRag' . ucfirst($art);
        $mid = @IPS_GetObjectIDByIdent($ident, $this->BestandID());
        try {
            if (!$mid) {
                $mid = IPS_CreateMedia(5);                 // MEDIATYPE_DOCUMENT
                IPS_SetParent($mid, $this->BestandID());
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
}
