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
    private const DOKU_BAU       = 2;
    /** Zeitbudget je Durchgang. Klein gehalten: der Aufbau läuft im TIMER, und
     *  ein Symcon-Timer soll nicht minutenlang im Kernel hängen. */
    private const DOKU_BUDGET    = 4.0;
    private const DOKU_SEITEN_MAX = 2500;
    /** So viel Text darf ein Auszug haben — der Rest ist für ein Gespräch ohnehin zu viel. */
    private const DOKU_AUSZUG    = 1800;

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
        @$this->SetTimerInterval('DokuIndex', $frisch ? 0 : 5000);
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

    /** @return array{stand:int,fertig:bool,seiten:array<string,int>,schlange:list<string>} */
    private function DokuStand(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('DokuIndex'), true);
        if (!is_array($roh) || !isset($roh['seiten']) || !is_array($roh['seiten'])) {
            return ['stand' => 0, 'fertig' => false,
                    'seiten' => [self::DOKU_WURZEL => 1], 'schlange' => [self::DOKU_WURZEL]];
        }
        /* Wurde mit einer ANDEREN Suchtiefe gebaut, ist es unbrauchbar: mit
           Tiefe 6 fehlten alle SDK-Seiten. Ohne diese Probe bliebe so ein
           Verzeichnis eine Woche lang stehen und keiner wüsste, warum die
           Hälfte fehlt. */
        if ((int)($roh['tiefe'] ?? 0) !== self::DOKU_TIEFE
            || (int)($roh['bau'] ?? 0) !== self::DOKU_BAU) {
            return ['stand' => 0, 'fertig' => false,
                    'seiten' => [self::DOKU_WURZEL => 1], 'schlange' => [self::DOKU_WURZEL]];
        }
        return [
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
            $s = ['stand' => 0, 'fertig' => false,
                  'seiten' => [self::DOKU_WURZEL => 1], 'schlange' => [self::DOKU_WURZEL]];
        }
        $ende = microtime(true) + self::DOKU_BUDGET;
        while ($s['schlange'] !== [] && microtime(true) < $ende
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
        if ($s['schlange'] === [] || count($s['seiten']) >= self::DOKU_SEITEN_MAX) {
            $s['fertig'] = true;
            $s['stand']  = time();
        }
        $s['tiefe'] = self::DOKU_TIEFE;
        $s['bau']   = self::DOKU_BAU;
        $this->DokuStandSchreiben($s);
        return $s;
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
            @$this->SetTimerInterval('DokuIndex', 5000);
        }
        $treffer = $this->DokuSuchen($frage, $s['seiten']);
        if ($treffer === []) {
            return [
                'ok' => false, 'error' => ['code' => 'nicht_gefunden', 'message' => 'nichts im Handbuch'],
                'sag' => $s['fertig']
                    ? sprintf($this->Translate('I cannot find anything about "%s" in the Symcon manual.'), $frage)
                    : $this->Translate('The manual index is still being built — please ask again in a moment.'),
            ];
        }
        // Der beste Treffer wird LIVE geholt — der Auszug ist damit so aktuell
        // wie die Doku.
        $seite = $treffer[0]['pfad'];
        $html  = $this->DokuHol($seite);
        if ($html === '') {
            return $this->VoiceErr('nicht_bereit', $this->Translate('The Symcon manual is not reachable right now.'));
        }
        $inhalt = $this->DokuInhalt($html);
        if (trim($inhalt['text']) === '') {
            return $this->VoiceErr('nicht_gefunden', $this->Translate('I found the page but no readable text on it.'));
        }
        $text = $inhalt['text'];
        if (mb_strlen($text) > self::DOKU_AUSZUG) {
            $text = mb_substr($text, 0, self::DOKU_AUSZUG) . ' …';
        }
        $weitere = [];
        foreach (array_slice($treffer, 1, 3) as $t) {
            $teile = array_values(array_filter(explode('/', trim($t['pfad'], '/'))));
            $weitere[] = (string)end($teile);
        }
        /* Die Adresse geht NUR ins Protokoll, nicht an das Modell. Lag sie in
           der Antwort, verwies es darauf, statt den Auszug zu benutzen — und
           eine vorgelesene Internetadresse kann sich ohnehin niemand merken. */
        $this->SendDebug('Doku', 'Quelle: ' . self::DOKU_HOST . $seite, 0);
        return [
            'ok'      => true,
            'titel'   => $inhalt['titel'],
            'auszug'  => $text,
            'weitere' => $weitere,
            /* Anders als bei allen anderen Werkzeugen ist „sag" hier keine
               fertige Auskunft, sondern nur der ANFANG des Satzes: die Antwort
               steht im Auszug und muss von dort kommen. Ein abgeschlossenes
               „Ich habe X gefunden." lud das Modell dazu ein, genau dort
               aufzuhören. */
            'sag'     => sprintf($this->Translate('The manual says about "%s":'), $inhalt['titel']),
        ];
    }
}
