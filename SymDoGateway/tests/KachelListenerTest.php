<?php

declare(strict_types=1);

/**
 * Jede Kachel, die auf `message` hoert, prueft den Absender.
 *
 * `handleMessage` nimmt einen kompletten Zustand entgegen und zeichnet ihn.
 * Ohne die Pruefung von `event.source` koennte jeder Frame im selben Fenster
 * einen Zustand injizieren — die SDWA-Familie prueft seit jeher, fuenf Kacheln
 * (Chores, Voice, MealPlan, Routines, ToDoOverview) taten es bis zum
 * 18.09.2026 nicht. Gefunden vom Sicherheits-Review. Dieser Riegel gilt fuer
 * ALLE module.html der Bibliothek, auch kuenftige.
 *
 *   php SymDoGateway/tests/KachelListenerTest.php
 */

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, mixed $ist, mixed $soll): void
{
    global $fehler, $anzahl;
    $anzahl++;
    $a = json_encode($ist, JSON_UNESCAPED_UNICODE);
    $b = json_encode($soll, JSON_UNESCAPED_UNICODE);
    $ok = $a === $b;
    if (!$ok) {
        $fehler++;
    }
    printf("%-4s %-62s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

$wurzel = dirname(__DIR__, 2);
$dateien = glob($wurzel . '/*/module.html') ?: [];
pruefe('Es gibt Kacheln zu pruefen', count($dateien) > 5, true);

$ohneWache = [];
$mitListener = 0;
foreach ($dateien as $datei) {
    $q = (string)file_get_contents($datei);
    $pos = 0;
    /* Nur das FENSTER: der Service-Worker-Kanal (`navigator.serviceWorker`) ist
       eigener Code derselben Herkunft und hat keinen fremden Absender. */
    while (($pos = strpos($q, "window.addEventListener('message'", $pos)) !== false) {
        $mitListener++;
        /* Die Wache muss im Rumpf des Listeners stehen — die naechsten paar
           Zeilen reichen; ein Listener ist hier nie laenger. */
        $rumpf = substr($q, $pos, 400);
        if (!str_contains($rumpf, 'event.source !== window')
            || !str_contains($rumpf, 'window.parent')) {
            $ohneWache[] = basename(dirname($datei)) . ':' . (substr_count(substr($q, 0, $pos), "\n") + 1);
        }
        $pos += 10;
    }
}
pruefe('Jeder message-Listener prueft event.source gegen window und window.parent', $ohneWache, []);
pruefe('… und es sind die bekannten elf Kacheln', $mitListener, 11);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
