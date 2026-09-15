<?php

declare(strict_types=1);

/**
 * Wiederkehrende Aufgaben an der Zeitumstellung.
 *
 * Warum es diesen Prüfstand geben MUSS: `GetNextDue` zählte Tage und Wochen in
 * festen Sekunden. Eine Woche sind aber nicht immer 604800 Sekunden — an der
 * Umstellung sind es 604800 ± 3600. Die Folgen waren nicht theoretisch:
 *
 *  - Eine wöchentliche ganztägige Aufgabe vom 19.10. landete auf dem **25.10.**
 *    statt dem 26.10.
 *  - Eine **tägliche** ganztägige Aufgabe am 25.10. bekam als nächsten Termin
 *    wieder den 25.10. Sie rückte nie vor, und auch die Nachholschleife kam
 *    nicht daran vorbei.
 *  - Eine Aufgabe um 09:00 sprang auf 10:00.
 *
 * Gemeldet von einem externen Codereview am 14.09.2026. Gefahren wird die
 * ECHTE Methode der echten Klasse — eine Nachbildung hätte denselben Fehler
 * machen können wie der Produktivcode.
 *
 *   php ToDoList/tests/WiederholungTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../module.php';

date_default_timezone_set('Europe/Berlin');
IPS\Kernel::reset();

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
    printf("%-4s %-56s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

$modul  = (new ReflectionClass('SymDoToDoList'))->newInstanceArgs([4242]);
$naechste = new ReflectionMethod('SymDoToDoList', 'GetNextDue');

/**
 * @return string Datum (ganztägig) oder Datum und Uhrzeit
 */
function weiter(object $modul, ReflectionMethod $m, string $wann, string $art,
    string $einheit = '', int $wert = 0, bool $ganztags = true): string
{
    $due  = (int)strtotime($wann);
    $next = (int)$m->invoke($modul, $due, $art, $einheit, $wert, $ganztags);
    return date($ganztags ? 'Y-m-d' : 'Y-m-d H:i', $next);
}

// ── Ende der Sommerzeit: 25.10.2026, die Uhr geht zurück ──────────────────
pruefe('Wöchentlich ganztägig über die Umstellung',
    weiter($modul, $naechste, '2026-10-19 00:00:00', 'w1'), '2026-10-26');
pruefe('Täglich ganztägig AM Umstellungstag',
    weiter($modul, $naechste, '2026-10-25 00:00:00', 'custom', 'd', 1), '2026-10-26');
pruefe('Zweiwöchentlich über die Umstellung',
    weiter($modul, $naechste, '2026-10-19 00:00:00', 'w2'), '2026-11-02');
pruefe('Dreiwöchentlich über die Umstellung',
    weiter($modul, $naechste, '2026-10-12 00:00:00', 'w3'), '2026-11-02');
pruefe('Mit Uhrzeit bleibt die Uhrzeit',
    weiter($modul, $naechste, '2026-10-19 07:30:00', 'w1', '', 0, false), '2026-10-26 07:30');

// ── Beginn der Sommerzeit: 29.03.2026, die Uhr geht vor ───────────────────
pruefe('Wöchentlich mit Uhrzeit über den Frühjahrssprung',
    weiter($modul, $naechste, '2026-03-23 09:00:00', 'w1', '', 0, false), '2026-03-30 09:00');
pruefe('Täglich ganztägig über den Frühjahrssprung',
    weiter($modul, $naechste, '2026-03-28 00:00:00', 'custom', 'd', 1), '2026-03-29');
pruefe('Und der Tag NACH dem Sprung',
    weiter($modul, $naechste, '2026-03-29 00:00:00', 'custom', 'd', 1), '2026-03-30');

// ── Ohne Umstellung bleibt alles, wie es war ──────────────────────────────
pruefe('Eine gewöhnliche Woche',
    weiter($modul, $naechste, '2026-06-01 00:00:00', 'w1'), '2026-06-08');
pruefe('Ein gewöhnlicher Tag mit Uhrzeit',
    weiter($modul, $naechste, '2026-06-01 18:15:00', 'custom', 'd', 1, false), '2026-06-02 18:15');
pruefe('Vierzehn Tage am Stück',
    weiter($modul, $naechste, '2026-06-01 00:00:00', 'custom', 'd', 14), '2026-06-15');

/* STUNDEN sind echte Zeitdauern — dort ist die Sekundenrechnung richtig, und
   sie MUSS es bleiben: „alle zwei Stunden" heißt zwei Stunden, auch wenn in
   dieser Nacht die Uhr springt. */
pruefe('Stunden bleiben echte Zeitdauern',
    weiter($modul, $naechste, '2026-10-25 01:00:00', 'custom', 'h', 2, false), '2026-10-25 02:00');

// ── Monate und Jahre bleiben kalendarisch ─────────────────────────────────
pruefe('Ein Monat', weiter($modul, $naechste, '2026-01-31 00:00:00', 'm1'), '2026-02-28');
pruefe('Ein Quartal', weiter($modul, $naechste, '2026-10-19 00:00:00', 'q1'), '2027-01-19');
pruefe('Ein Jahr', weiter($modul, $naechste, '2026-10-19 00:00:00', 'y1'), '2027-10-19');

/* DIE INVARIANTE. Ein nächster Termin, der nicht NACH dem bisherigen liegt,
   bringt die Nachholschleife zum Stehen — die Aufgabe bliebe für immer auf
   demselben Tag. Genau das war der schlimmste Fall. */
$stehengeblieben = [];
foreach (['w1', 'w2', 'w3', 'm1', 'q1', 'y1'] as $art) {
    for ($tag = 0; $tag < 366; $tag++) {
        $wann = date('Y-m-d 00:00:00', (int)strtotime('2026-01-01 00:00:00 +' . $tag . ' days'));
        $due  = (int)strtotime($wann);
        if ((int)$naechste->invoke($modul, $due, $art, '', 0, true) <= $due) {
            $stehengeblieben[] = $art . ' @ ' . substr($wann, 0, 10);
        }
    }
}
foreach ([1, 2, 7, 14] as $v) {
    for ($tag = 0; $tag < 366; $tag++) {
        $due = (int)strtotime(date('Y-m-d 00:00:00', (int)strtotime('2026-01-01 +' . $tag . ' days')));
        if ((int)$naechste->invoke($modul, $due, 'custom', 'd', $v, true) <= $due) {
            $stehengeblieben[] = 'd' . $v . ' @ ' . date('Y-m-d', $due);
        }
    }
}
pruefe('Kein Termin des Jahres bleibt stehen', $stehengeblieben, []);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
