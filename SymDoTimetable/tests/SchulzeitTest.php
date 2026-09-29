<?php

declare(strict_types=1);

/**
 * Unterrichtsbeginn und -ende je Kind (29.09.2026): TimetableCalc::Schulzeit,
 * die Normalisierung von Typ und Datum und die Verdrahtung von
 * STPL_GetSchoolTime / STPL_GetSchoolTimestamp.
 *
 *   php SymDoTimetable/tests/SchulzeitTest.php
 */

require_once __DIR__ . '/../libs/TimetableCalc.php';

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

$s = static fn(string $von, string $bis, array $x = []): array => ['start' => $von, 'end' => $bis] + $x;
$tag = [
    $s('09:30', '10:30'),
    $s('08:00', '09:00'),                                   // unsortiert: die erste Stunde steht nicht vorn
    $s('12:00', '13:00', ['status' => 'vertretung']),
    $s('13:00', '15:00', ['care' => true]),                 // Betreuung
];
pruefe('Beginn: frueheste stattfindende Stunde, auch unsortiert', TimetableCalc::Schulzeit($tag, 'start'), '08:00');
pruefe('Ende: letzte Stunde, Vertretung zaehlt, Betreuung nicht', TimetableCalc::Schulzeit($tag, 'end'), '13:00');
pruefe('Ende mit Betreuung', TimetableCalc::Schulzeit($tag, 'end_care'), '15:00');

$erste = [$s('08:00', '09:00', ['status' => 'entfall']), $s('09:30', '10:30'), $s('10:35', '11:35', ['status' => 'entfall'])];
pruefe('Erste Stunde entfaellt: Beginn rueckt', TimetableCalc::Schulzeit($erste, 'start'), '09:30');
pruefe('Letzte Stunde entfaellt: Ende rueckt', TimetableCalc::Schulzeit($erste, 'end'), '10:30');
pruefe('Alles entfaellt: kein Unterricht', TimetableCalc::Schulzeit([$s('08:00', '09:00', ['status' => 'entfall'])], 'start'), '');
pruefe('Nur Betreuung: kein Unterricht, auch nicht mit end_care',
    [TimetableCalc::Schulzeit([$s('13:00', '15:00', ['care' => true])], 'start'),
     TimetableCalc::Schulzeit([$s('13:00', '15:00', ['care' => true])], 'end_care')], ['', '']);
pruefe('Leerer Tag', TimetableCalc::Schulzeit([], 'end'), '');
pruefe('Pruefung und Projekttag sind Unterricht',
    [TimetableCalc::Schulzeit([$s('07:45', '08:30', ['exam' => true, 'status' => 'vertretung'])], 'start'),
     TimetableCalc::Schulzeit([$s('08:00', '13:00', ['status' => 'termin'])], 'end')], ['07:45', '13:00']);
pruefe('Betreuung frueher als Unterrichtsende aendert end_care nicht',
    TimetableCalc::Schulzeit([$s('08:00', '14:00'), $s('07:00', '07:45', ['care' => true])], 'end_care'), '14:00');
pruefe('Zeitwaehler-Objekt statt Text wird gelesen',
    TimetableCalc::Schulzeit([['start' => '{"hour":8,"minute":15,"second":0}', 'end' => '{"hour":9,"minute":0,"second":0}']], 'start'), '08:15');
pruefe('Unlesbare Zeit verfaelscht den Beginn nicht',
    TimetableCalc::Schulzeit([$s('kaputt', '09:00'), $s('08:30', '09:15')], 'start'), '08:30');

pruefe('Typen: englisch, deutsch, Grossschreibung, Unsinn',
    array_map([TimetableCalc::class, 'SchulzeitArt'], ['start', 'Beginn', 'ende', 'END_CARE', 'ende_betreuung', 'mittag']),
    ['start', 'start', 'end', 'end_care', 'end_care', '']);
$jetzt = (int)strtotime('2026-09-29 16:00:00');
pruefe('Datum: leer/heute/morgen/ISO/kaputt',
    array_map(static fn(string $d): string => TimetableCalc::SchulzeitDatum($d, $jetzt), ['', 'heute', 'Morgen', '2026-10-05', '2026-02-30', '05.10.']),
    ['2026-09-29', '2026-09-29', '2026-09-30', '2026-10-05', '', '']);

$mod = (string)file_get_contents(__DIR__ . '/../module.php');
pruefe('Verdrahtung: zwei oeffentliche Funktionen mit vier Pflichtangaben, gemeinsame Kind-Aufloesung',
    [str_contains($mod, 'public function GetSchoolTime(string $Child, string $Type, string $Date): string'),
     str_contains($mod, 'public function GetSchoolTimestamp(string $Child, string $Type, string $Date): int'),
     str_contains($mod, '$nr = $this->KindNummer($wunsch, $kinder);'),
     str_contains($mod, "(\$tag['holiday'] ?? null) !== null ? ''")],
    [true, true, true, true]);

echo "\n$anzahl Zusicherungen, $fehler Abweichung(en).\n";
exit($fehler === 0 ? 0 : 1);
