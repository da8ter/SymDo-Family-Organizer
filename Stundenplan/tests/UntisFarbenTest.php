<?php

declare(strict_types=1);

/**
 * Fachfarben aus WebUntis (24.09.2026): die Reihenfolge in
 * TimetableSubjects::Aufloesen und die Namenssuche in UntisFarbe.
 *
 *   php Stundenplan/tests/UntisFarbenTest.php
 */

require_once __DIR__ . '/../libs/TimetableSubjects.php';

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, mixed $ist, mixed $soll): void
{
    global $fehler, $anzahl;
    $anzahl++;
    if ($ist === $soll) {
        echo "OK   $name\n";
        return;
    }
    $fehler++;
    echo "FEHL $name\n     ist:  " . var_export($ist, true) . "\n     soll: " . var_export($soll, true) . "\n";
}

$faecher = [['id' => 'Mathematik', 'name' => 'Mathematik', 'icon' => 'calculator', 'color' => 0x43A047],
            ['id' => 'Kunst', 'name' => 'Kunst', 'icon' => '', 'color' => 0xFB8C00]];
$untis   = ['Mathematik' => '#4DA9FF', 'Englisch' => '#F09FE0'];
$stunde  = static fn(string $f): array => ['subjectId' => $f, 'subject' => $f, 'color' => null];

pruefe('Mit UNTIS-Farbe: UNTIS schlaegt die Faecherliste',
    TimetableSubjects::Aufloesen($stunde('Mathematik'), $faecher, $untis)['color'], '#4DA9FF');
pruefe('Fach ohne UNTIS-Farbe behaelt seine eigene',
    TimetableSubjects::Aufloesen($stunde('Kunst'), $faecher, $untis)['color'], '#FB8C00');
pruefe('Fach nur in WebUntis bekommt die UNTIS-Farbe statt der Vorgabe',
    TimetableSubjects::Aufloesen($stunde('Englisch'), $faecher, $untis)['color'], '#F09FE0');
pruefe('Schalter aus (leere Liste): Farbe der Faecherliste',
    TimetableSubjects::Aufloesen($stunde('Mathematik'), $faecher)['color'], '#43A047');
pruefe('Eigene Farbe der Stunde bleibt vorn',
    TimetableSubjects::Aufloesen(['subject' => 'Mathematik', 'color' => '#123456'], $faecher, $untis)['color'], '#123456');
pruefe('Kurzform trifft den langen UNTIS-Namen', TimetableSubjects::UntisFarbe('Mathe', $untis), '#4DA9FF');
pruefe('Zu kurze Kurzform trifft nicht', TimetableSubjects::UntisFarbe('Ma', $untis), null);
pruefe('Unbrauchbarer Wert ergibt null', TimetableSubjects::UntisFarbe('Kunst', ['Kunst' => 'rot']), null);

echo "\n$anzahl Zusicherungen, $fehler Abweichung(en).\n";
exit($fehler === 0 ? 0 : 1);
