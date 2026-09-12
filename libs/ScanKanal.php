<?php

declare(strict_types=1);

require_once __DIR__ . '/ScanKanalCalc.php';

/**
 * Der passive Kanal zwischen der Gateway-Spur und den Scanner-Spuren.
 *
 * Symcon fuehrt je Instanz genau EINE Sache zur Zeit aus. Ein Gateway, das
 * einen Scanner synchron ruft, wartet auf dessen Spur — und waehrend es
 * wartet, wartet die ganze App. Deshalb reden die beiden Seiten ueber Dateien:
 * das Gateway legt Auftraege ab und liest Ergebnisse, der Scanner nimmt
 * Auftraege und schreibt Ergebnisse. Niemand ruft, niemand wartet.
 *
 * Bewusst Dateien und keine Attribute — dieselbe Begruendung wie beim
 * Mail-Spool: ein Attribut gibt es nach einem Modul-Reload ohne Kernel-Neustart
 * nicht, und ein Schreibzugriff darauf zerlegt im Hook die HTTP-Antwort.
 * Dateien ueberleben beides, und der Bestand ist mit glob() ohne jeden
 * weiteren Zustand ablesbar.
 *
 * DIE INVARIANTE, an der alles haengt:
 *   Der Umschlag erscheint als LETZTES und verschwindet als ERSTES.
 * Erst alle Nebendateien, dann der Umschlag; beim Loeschen erst der Umschlag,
 * dann die Nebendateien. Eine verwaiste Nebendatei ist Muell, den das
 * Aufraeumen holt — ein verwaister Umschlag waere ein Blockierer, der das
 * aelteste Ergebnis bliebe und alle neueren aufhielte.
 *
 * Voraussetzung der benutzenden Klasse: die Traits Konfig (KonfigID) und
 * Belegung. Die Kompositionsprobe im Pruefstand haelt das fest — fehlt einer,
 * ist der erste Griff ein Fatal, und ein Fatal im Gateway nimmt die ganze
 * Bibliothek mit.
 */
trait ScanKanal
{
    /** Wurzelverzeichnis unter dem Kernel-Verzeichnis. */
    private const SCAN_DIR = 'symdo_scankanal';

    private const SCAN_TOPF_AUFTRAG  = 'auftrag';
    private const SCAN_TOPF_ERGEBNIS = 'ergebnis';
    private const SCAN_TOPF_FEHLER   = 'fehler';

    /**
     * Die Signalvariable am Gateway. Ihr Wert bedeutet nichts — nur dass er
     * sich geaendert hat. Der Scanner haengt mit RegisterMessage daran; sein
     * MessageSink laeuft in einem eigenen Nachrichten-Thread und weckt ihn
     * auch dann, wenn seine eigene Spur gerade belegt ist (gemessen: ~30 ms,
     * mitten in einem 10-Sekunden-Lauf).
     */
    private const SCAN_SIGNAL_IDENT = 'ScanSignal';

    /** Ein Auftrag heisst „jetzt scannen" — ein sechs Stunden alter liefe zur falschen Zeit. */
    private const SCAN_AUFTRAG_TTL = 900;
    /** In Ergebnissen stecken echte Nutzerdaten; sie muessen ein Wochenende ueberstehen. */
    private const SCAN_ERGEBNIS_TTL = 7 * 86400;
    private const SCAN_FEHLER_TTL   = 30 * 86400;
    /** Wie lange ein Anspruch gelten darf, bevor er als verwaist gilt. */
    private const SCAN_ANSPRUCH_TTL = 3600;

    private const SCAN_ERGEBNIS_MAX = 50;
    private const SCAN_FEHLER_MAX   = 20;

    /** Der Umschlag wird VOR json_decode an seiner Groesse gemessen — memory_limit ist 64 MB. */
    private const SCAN_JSON_MAX  = 2097152;
    /** Was ScanNebenLesen in den Speicher holen darf. */
    private const SCAN_NEBEN_LESE_MAX = 6291456;
    private const SCAN_NEBEN_STUECK   = 32;

    // ------------------------------------------------------------------
    // Verzeichnisse
    // ------------------------------------------------------------------

    /**
     * Ein Topf des Kanals. Unter der KonfigID, damit zwei Gateways im selben
     * Kernel sich nicht die Auftragsdateien gegenseitig ueberschreiben — es
     * gibt je Quelle genau eine, das waere Datenverlust und nicht bloss ein
     * Lesefehler. Im Gateway ist die KonfigID die eigene, im Scanner die des
     * Gateways; es braucht dafuer keine zweite Einstellung.
     *
     * Angelegt wird nur auf dem schreibenden Weg — das blosse Anzeigen eines
     * Formulars soll nichts hinterlassen. Liefert '' statt einer Ausnahme,
     * wenn das Verzeichnis nicht nutzbar ist.
     */
    private function ScanDir(string $topf, bool $anlegen = false): string
    {
        $wurzel = rtrim((string) @IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR
            . self::SCAN_DIR . DIRECTORY_SEPARATOR . $this->KonfigID() . DIRECTORY_SEPARATOR;
        $pfad = $wurzel . $topf . DIRECTORY_SEPARATOR;
        if (!@is_dir($pfad)) {
            if (!$anlegen) {
                return '';
            }
            @mkdir($pfad, 0700, true);
            // Nach mkdir, weil die umask die Rechte sonst aufweicht.
            @chmod($wurzel, 0700);
            @chmod($pfad, 0700);
        }
        return @is_dir($pfad) && @is_writable($pfad) ? $pfad : '';
    }

    // ------------------------------------------------------------------
    // Auftraege — vom Gateway abgelegt, vom Scanner genommen
    // ------------------------------------------------------------------

    /**
     * Einen Auftrag ablegen. Liegt schon einer fuer diese Quelle, werden beide
     * verschmolzen statt angereiht: sonst liefe derselbe Scan zweimal, nur
     * weil zweimal geweckt wurde.
     *
     * Der Aufrufer gibt danach das Signal — nicht dieser Griff, denn die
     * Signalvariable gehoert dem Gateway, der Kanal aber beiden Seiten.
     */
    private function ScanAuftragAblegen(string $quelle, array $auftrag): bool
    {
        $start = microtime(true);
        if (!ScanKanalCalc::QuelleGueltig($quelle)) {
            return false;
        }
        $dir = $this->ScanDir(self::SCAN_TOPF_AUFTRAG, true);
        if ($dir === '') {
            return false;
        }
        $pfad = $dir . ScanKanalCalc::AuftragName($quelle);
        $block = ScanKanalCalc::AuftragBlock($auftrag);
        $alt = $this->ScanUmschlagLesen($pfad);
        if ($alt !== null && is_array($alt['auftrag'] ?? null)) {
            $block = ScanKanalCalc::AuftragVerschmelzen($alt['auftrag'], $block);
        }
        $umschlag = ScanKanalCalc::LeererUmschlag($quelle, $this->KonfigID(), 0, time());
        $umschlag['auftrag'] = $block;
        $ok = $this->ScanSchreibenAtomar($pfad, (string)json_encode($umschlag, JSON_UNESCAPED_UNICODE));
        $this->Belegung('kanal', 'AuftragAblegen', $start);
        return $ok;
    }

    /**
     * Wie viele Auftraege dieser Quellen warten — fuer die Statuszeile im
     * Formular und fuer die Entscheidung, ob sich ein Lauf lohnt.
     *
     * @param list<string> $quellen
     */
    private function ScanAuftraegeOffen(array $quellen): int
    {
        $dir = $this->ScanDir(self::SCAN_TOPF_AUFTRAG);
        if ($dir === '') {
            return 0;
        }
        $n = 0;
        foreach ($quellen as $q) {
            if (@is_file($dir . ScanKanalCalc::AuftragName((string)$q))) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Einen Auftrag beanspruchen. Entschieden wird allein durch `rename()` —
     * nie mit `file_exists` davor: zwischen Pruefen und Umbenennen passt ein
     * zweiter Scanner. Genau eine Instanz gewinnt.
     *
     * @param list<string> $quellen
     * @return array{quelle:string,auftrag:array<string,mixed>,at:int}|null
     */
    private function ScanAuftragNehmen(array $quellen): ?array
    {
        $start = microtime(true);
        $dir = $this->ScanDir(self::SCAN_TOPF_AUFTRAG);
        if ($dir === '') {
            return null;
        }
        $jetzt = time();
        foreach ($quellen as $q) {
            $quelle = (string)$q;
            if (!ScanKanalCalc::QuelleGueltig($quelle)) {
                continue;
            }
            $von  = $dir . ScanKanalCalc::AuftragName($quelle);
            $nach = $dir . ScanKanalCalc::NimmtName($quelle, $jetzt, $this->InstanceID);
            if (!@rename($von, $nach)) {
                continue;   // war nicht da, oder ein anderer war schneller
            }
            $umschlag = $this->ScanUmschlagLesen($nach);
            @unlink($nach);
            if ($umschlag === null) {
                continue;
            }
            $geprueft = ScanKanalCalc::PruefeAuftrag($umschlag, $this->KonfigID());
            if (!$geprueft['ok']) {
                continue;   // fremdes Gateway oder kaputt — verworfen, nicht ausgefuehrt
            }
            $alter = $jetzt - (int)$geprueft['umschlag']['at'];
            if ($alter > self::SCAN_AUFTRAG_TTL) {
                continue;   // zu alt: der Zeitgeber macht ohnehin einen frischen
            }
            $this->Belegung('kanal', 'AuftragNehmen', $start);
            return [
                'quelle'  => $quelle,
                'auftrag' => $geprueft['umschlag']['auftrag'],
                'at'      => (int)$geprueft['umschlag']['at'],
            ];
        }
        return null;
    }

    /**
     * Ansprueche aufraeumen, die niemand mehr einloest — ein Scanner, der
     * mitten im Lauf gestorben ist, hinterlaesst sonst eine Datei, die nichts
     * altern sieht. Der Auftrag ist damit weg; das ist richtig, denn der
     * Zeitgeber stellt einen neuen.
     */
    private function ScanAnspruchVerwaist(int $maxAlter = self::SCAN_ANSPRUCH_TTL): int
    {
        $dir = $this->ScanDir(self::SCAN_TOPF_AUFTRAG);
        if ($dir === '') {
            return 0;
        }
        $jetzt = time();
        $weg = 0;
        foreach ((array)@glob($dir . '*.nimmt') as $pfad) {
            $teile = ScanKanalCalc::NimmtZerlegen((string)$pfad);
            if ($teile !== null && ($jetzt - $teile['at']) > $maxAlter && @unlink((string)$pfad)) {
                $weg++;
            }
        }
        return $weg;
    }

    // ------------------------------------------------------------------
    // Ergebnisse — vom Scanner geschrieben, vom Gateway eingepflegt
    // ------------------------------------------------------------------

    /**
     * Ein Ergebnis ablegen: erst alle Nebendateien, dann der Umschlag.
     *
     * @param array<string,mixed> $umschlag
     * @param list<array{rolle:string,bytes?:string,datei?:string}> $neben
     */
    private function ScanErgebnisSchreiben(array $umschlag, array $neben = []): bool
    {
        $start = microtime(true);
        $dir = $this->ScanDir(self::SCAN_TOPF_ERGEBNIS, true);
        if ($dir === '') {
            return false;
        }
        if (count((array)@glob($dir . '*.json')) >= self::SCAN_ERGEBNIS_MAX) {
            $this->ScanMelden('Warteschlange voll, Ergebnis verworfen', KL_WARNING);
            return false;
        }
        if (count($neben) > self::SCAN_NEBEN_STUECK) {
            $neben = array_slice($neben, 0, self::SCAN_NEBEN_STUECK);
        }

        $quelle = (string)($umschlag['quelle'] ?? '');
        if (!ScanKanalCalc::QuelleGueltig($quelle)) {
            return false;
        }
        $t = microtime(true);
        $basis = '';
        for ($versuch = 0; $versuch < 3; $versuch++) {
            $name = ScanKanalCalc::ErgebnisName((int)$t, (int)round(($t - (int)$t) * 1000000),
                $this->InstanceID, $quelle, bin2hex(random_bytes(2)));
            if (!@file_exists($dir . $name)) {
                $basis = substr($name, 0, -5);
                break;
            }
        }
        if ($basis === '') {
            // Nie ueberschreiben: ein rename() wuerde ein fremdes Ergebnis lautlos vernichten.
            $this->ScanMelden('Kein freier Ergebnisname', KL_ERROR);
            return false;
        }

        $verzeichnis = [];
        $nr = 0;
        foreach ($neben as $eintrag) {
            if (!is_array($eintrag)) {
                continue;
            }
            $ziel = $dir . ScanKanalCalc::NebenName($basis, $nr);
            $ok = false;
            if (isset($eintrag['datei'])) {
                // Nur umbenennen, nie lesen — so passt auch ein grosser Index durch.
                $ok = @rename((string)$eintrag['datei'], $ziel);
            } elseif (isset($eintrag['bytes'])) {
                $ok = $this->ScanSchreibenAtomar($ziel, (string)$eintrag['bytes']);
            }
            if (!$ok) {
                $this->ScanReste($dir, $basis);
                $this->ScanMelden('Nebendatei fehlgeschlagen, Ergebnis verworfen', KL_WARNING);
                return false;
            }
            $verzeichnis[] = [
                'rolle' => (string)($eintrag['rolle'] ?? ''),
                'name'  => basename($ziel),
                'bytes' => (int)@filesize($ziel),
            ];
            $nr++;
        }
        if ($verzeichnis !== []) {
            $umschlag['dateien'] = $verzeichnis;
        }

        $roh = (string)json_encode($umschlag, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($roh) > self::SCAN_JSON_MAX) {
            $this->ScanReste($dir, $basis);
            $this->ScanMelden('Umschlag zu gross, Ergebnis verworfen', KL_WARNING);
            return false;
        }
        // Der Umschlag als Letztes: davor ist das Ergebnis fuer den Leser unsichtbar.
        if (!$this->ScanSchreibenAtomar($dir . $basis . '.json', $roh)) {
            $this->ScanReste($dir, $basis);
            return false;
        }
        $this->Belegung('kanal', 'ErgebnisSchreiben', $start);
        return true;
    }

    /**
     * Die wartenden Ergebnisse, aeltestes zuerst.
     *
     * @return list<string> volle Pfade
     */
    private function ScanErgebnisse(string $quelle = ''): array
    {
        $dir = $this->ScanDir(self::SCAN_TOPF_ERGEBNIS);
        if ($dir === '') {
            return [];
        }
        $alle = ScanKanalCalc::Sortieren((array)@glob($dir . '*.json'));
        if ($quelle === '') {
            return $alle;
        }
        return array_values(array_filter($alle, static function (string $p) use ($quelle): bool {
            $t = ScanKanalCalc::NameZerlegen($p);
            return $t !== null && $t['quelle'] === $quelle;
        }));
    }

    /**
     * Einen Umschlag lesen und pruefen. null heisst „nicht verwendbar" — der
     * Aufrufer schiebt ihn dann in den Fehlerordner.
     *
     * @return array<string,mixed>|null
     */
    private function ScanErgebnisLesen(string $pfad): ?array
    {
        $roh = $this->ScanUmschlagLesen($pfad);
        if ($roh === null) {
            return null;
        }
        $geprueft = ScanKanalCalc::PruefeErgebnis($roh, $this->KonfigID());
        return $geprueft['ok'] ? $geprueft['umschlag'] : null;
    }

    /** Eine Nebendatei in den Speicher holen — bis SCAN_NEBEN_LESE_MAX. */
    private function ScanNebenLesen(string $pfad, int $nr): ?string
    {
        $teile = ScanKanalCalc::NameZerlegen($pfad);
        if ($teile === null) {
            return null;
        }
        $datei = dirname($pfad) . DIRECTORY_SEPARATOR . ScanKanalCalc::NebenName($teile['basis'], $nr);
        if (!@is_file($datei) || (int)@filesize($datei) > self::SCAN_NEBEN_LESE_MAX) {
            return null;
        }
        $inhalt = @file_get_contents($datei);
        return is_string($inhalt) ? $inhalt : null;
    }

    /** Eine Nebendatei an ihren Zielort umbenennen — ganz ohne Speicher. */
    private function ScanNebenUebernehmen(string $pfad, int $nr, string $ziel): bool
    {
        $teile = ScanKanalCalc::NameZerlegen($pfad);
        if ($teile === null) {
            return false;
        }
        $datei = dirname($pfad) . DIRECTORY_SEPARATOR . ScanKanalCalc::NebenName($teile['basis'], $nr);
        return @is_file($datei) && @rename($datei, $ziel);
    }

    /** Ein eingepflegtes Ergebnis wegraeumen: erst der Umschlag, dann die Nebendateien. */
    private function ScanErgebnisWeg(string $pfad): void
    {
        $teile = ScanKanalCalc::NameZerlegen($pfad);
        @unlink($pfad);
        if ($teile !== null) {
            $this->ScanReste(dirname($pfad) . DIRECTORY_SEPARATOR, $teile['basis']);
        }
    }

    /**
     * Was sich nicht einpflegen laesst, wandert weg — EIN Fehlversuch genuegt.
     *
     * Ein Zaehler braeuchte ein Attribut, und Attribute gibt es nach einem
     * Modul-Reload ohne Kernel-Neustart nicht. Ohne diesen Weg bliebe ein
     * kaputtes Ergebnis fuer immer das aelteste und hielte alle neueren auf.
     */
    private function ScanErgebnisInFehler(string $pfad, string $grund): void
    {
        $ziel = $this->ScanDir(self::SCAN_TOPF_FEHLER, true);
        $teile = ScanKanalCalc::NameZerlegen($pfad);
        if ($ziel === '' || $teile === null) {
            @unlink($pfad);
            return;
        }
        $quell = dirname($pfad) . DIRECTORY_SEPARATOR;
        @rename($pfad, $ziel . $teile['basis'] . '.json');
        for ($nr = 0; $nr < self::SCAN_NEBEN_STUECK; $nr++) {
            $neben = ScanKanalCalc::NebenName($teile['basis'], $nr);
            if (@is_file($quell . $neben)) {
                @rename($quell . $neben, $ziel . $neben);
            }
        }
        @file_put_contents($ziel . $teile['basis'] . '.grund',
            date('Y-m-d H:i:s') . ' ' . $grund . "\n");
        @chmod($ziel . $teile['basis'] . '.grund', 0600);
        $this->ScanMelden('Ergebnis in den Fehlerordner: ' . $grund, KL_WARNING);

        // Deckel: die aeltesten Fehler fallen weg, damit der Ordner nicht waechst.
        $fehler = ScanKanalCalc::Sortieren((array)@glob($ziel . '*.json'));
        while (count($fehler) > self::SCAN_FEHLER_MAX) {
            $weg = array_shift($fehler);
            $t = ScanKanalCalc::NameZerlegen((string)$weg);
            @unlink((string)$weg);
            if ($t !== null) {
                @unlink($ziel . $t['basis'] . '.grund');
                $this->ScanReste($ziel, $t['basis']);
            }
        }
    }

    /** Verfallenes in allen drei Toepfen. Gibt zurueck, wie viele Umschlaege gingen. */
    private function ScanAufraeumen(): int
    {
        $jetzt = time();
        $weg = 0;
        $weg += $this->ScanAnspruchVerwaist();
        foreach ([
            [self::SCAN_TOPF_ERGEBNIS, self::SCAN_ERGEBNIS_TTL],
            [self::SCAN_TOPF_FEHLER,   self::SCAN_FEHLER_TTL],
        ] as [$topf, $ttl]) {
            $dir = $this->ScanDir($topf);
            if ($dir === '') {
                continue;
            }
            foreach (ScanKanalCalc::Verfallen((array)@glob($dir . '*.json'), $jetzt, $ttl) as $pfad) {
                $t = ScanKanalCalc::NameZerlegen((string)$pfad);
                @unlink((string)$pfad);
                if ($t !== null) {
                    @unlink($dir . $t['basis'] . '.grund');
                    $this->ScanReste($dir, $t['basis']);
                }
                $weg++;
            }
        }
        // Auftraege altern am Zeitstempel IM Umschlag, nicht am Namen.
        $dir = $this->ScanDir(self::SCAN_TOPF_AUFTRAG);
        if ($dir !== '') {
            foreach ((array)@glob($dir . '*.json') as $pfad) {
                $u = $this->ScanUmschlagLesen((string)$pfad);
                if ($u === null || ($jetzt - (int)($u['at'] ?? 0)) > self::SCAN_AUFTRAG_TTL) {
                    @unlink((string)$pfad);
                    $weg++;
                }
            }
        }
        return $weg;
    }

    /** @return array{auftraege:int,ergebnisse:int,fehler:int,bytes:int} */
    private function ScanStand(): array
    {
        $zahl = ['auftraege' => 0, 'ergebnisse' => 0, 'fehler' => 0, 'bytes' => 0];
        foreach ([
            self::SCAN_TOPF_AUFTRAG  => 'auftraege',
            self::SCAN_TOPF_ERGEBNIS => 'ergebnisse',
            self::SCAN_TOPF_FEHLER   => 'fehler',
        ] as $topf => $schluessel) {
            $dir = $this->ScanDir($topf);
            if ($dir === '') {
                continue;
            }
            $zahl[$schluessel] = count((array)@glob($dir . '*.json'));
            foreach ((array)@glob($dir . '*') as $d) {
                $zahl['bytes'] += (int)@filesize((string)$d);
            }
        }
        return $zahl;
    }

    // ------------------------------------------------------------------
    // Innenleben
    // ------------------------------------------------------------------

    /**
     * Schreiben, das entweder ganz oder gar nicht geschieht: erst in eine
     * Zwischendatei mit fuehrendem Punkt (und ohne `.json`, damit sie kein
     * Leser einsammelt), Rechte setzen, dann umbenennen. `rename()` ist im
     * selben Verzeichnis unteilbar.
     */
    private function ScanSchreibenAtomar(string $ziel, string $inhalt): bool
    {
        $tmp = dirname($ziel) . DIRECTORY_SEPARATOR . '.' . basename($ziel) . '.tmp';
        if (@file_put_contents($tmp, $inhalt, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $ziel)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Rohes Lesen eines Umschlags mit Groessenwache VOR dem Zerlegen: ein
     * decodierter 2-MiB-JSON kostet als PHP-Feld ein Vielfaches, und das
     * memory_limit liegt bei 64 MB.
     *
     * @return array<string,mixed>|null
     */
    private function ScanUmschlagLesen(string $pfad): ?array
    {
        if (!@is_file($pfad) || (int)@filesize($pfad) > self::SCAN_JSON_MAX) {
            return null;
        }
        $roh = @file_get_contents($pfad);
        if (!is_string($roh) || $roh === '') {
            return null;
        }
        $daten = json_decode($roh, true);
        return is_array($daten) ? $daten : null;
    }

    /** Nebendateien und Zwischendateien eines Umschlags entfernen. */
    private function ScanReste(string $dir, string $basis): void
    {
        for ($nr = 0; $nr < self::SCAN_NEBEN_STUECK; $nr++) {
            @unlink($dir . ScanKanalCalc::NebenName($basis, $nr));
        }
        @unlink($dir . '.' . $basis . '.json.tmp');
    }

    /** Eine Zeile ins Symcon-Protokoll — der Kanal hat keine eigene Oberflaeche. */
    private function ScanMelden(string $text, int $art): void
    {
        if (function_exists('IPS_LogMessage')) {
            @IPS_LogMessage('SymDo Scan-Kanal', $text);
        }
        if (method_exists($this, 'LogMessage')) {
            @$this->LogMessage($text, $art);
        }
    }
}
