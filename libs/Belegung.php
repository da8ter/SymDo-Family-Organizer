<?php

declare(strict_types=1);

/**
 * Ein Messpunkt fuer die Belegung einer Instanz-Spur.
 *
 * Symcon fuehrt je Instanz genau EINE Sache zur Zeit aus — am 11.09.2026
 * gemessen: 30 gleichzeitige Hook-Abrufe brauchten 874 ms statt 31, der Hook
 * einer FREMDEN Instanz blieb dabei unbeeindruckt (2,7 -> 3,0 ms). Jede lange
 * Arbeit in einer Spur ist deshalb eine Wartezeit fuer alles andere in
 * DERSELBEN Spur.
 *
 * Liegt in `List/libs/`, weil beide Seiten des Umbaus „Gateway frei halten"
 * dasselbe Log schreiben: das Gateway (Hook, Aktion) und die ausgelagerten
 * Scanner-Instanzen. Die Zeile traegt die Instanz-ID — im Log ist damit
 * ablesbar, WELCHE Spur wie lange belegt war. Genau das ist der Beweis, dass
 * ein Scan die Hooks nicht mehr aufhaelt: dieselbe Vorgangskette steht mit
 * zwei verschiedenen Instanz-IDs da, die lange Zeile unter der des Scanners.
 */
trait Belegung
{
    /**
     * Aus, solange die Markierungsdatei fehlt: eine Messung gehoert nicht in
     * den Dauerbetrieb. Bewusst eine Datei und keine Eigenschaft — die gaebe es
     * erst nach einem Kernel-Neustart, und ein Schreibzugriff auf ein noch
     * nicht registriertes Attribut zerlegt im Hook die HTTP-Antwort.
     *
     * Der Pfad kommt aus `IPS_GetKernelDir()` und nicht mehr fest aus
     * `/var/lib/symcon/`: auf der SymBox liegt das Verzeichnis anderswo, und
     * eine Messung, die dort still nichts schreibt, waere schlimmer als keine.
     * `rtrim` davor, weil der Kernel den Schraegstrich je nach Umgebung mit
     * oder ohne liefert (in den Pruefstands-Attrappen ohne).
     */
    private function Belegung(string $art, string $name, float $start): void
    {
        $wurzel = rtrim((string) @IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR;
        if (!@is_file($wurzel . 'symdo-messung.an')) {
            return;
        }
        $datei = $wurzel . 'symdo-belegung.log';
        // Deckel gegen eine Messung, die jemand anzuschalten vergisst.
        if ((int)@filesize($datei) > 20 * 1024 * 1024) {
            return;
        }
        $dauer = (microtime(true) - $start) * 1000;
        @file_put_contents($datei, sprintf(
            "%s\t%d\t%s\t%s\t%.1f\n",
            date('Y-m-d H:i:s'), $this->InstanceID, $art, $name, $dauer
        ), FILE_APPEND);
    }
}
