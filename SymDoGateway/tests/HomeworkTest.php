<?php

declare(strict_types=1);

/**
 * Offline-Prüfstand für die Hausaufgaben: Gültigkeit, Fachauflösung,
 * Aufbewahrung, Zuordnung zur Stunde und die Vorabend-Auswahl.
 *
 * Läuft ohne Symcon gegen die Symcon-Stubs (symcon/module-tests) unter
 * TileVisu-Raum-Titel-Kachel/tests/stubs — anderer Pfad über SYMCON_STUBS.
 *
 * Geprüft wird HomeworkCalc: dort steht alles Rechnende, damit genau das hier
 * ohne Attribut, ohne Uhr und ohne Gateway laufen kann.
 *
 *   php SymDoGateway/tests/HomeworkTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/HomeworkCalc.php';

IPS\Kernel::reset();
date_default_timezone_set('Europe/Berlin');

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
    printf("%s %-52s%s\n", $ok ? 'OK  ' : 'FEHL', $name, $ok ? '' : "  ist: $a  soll: $b");
}

$FAECHER = ['Mathematik', 'Deutsch', 'Englisch', 'Sport', 'Musik'];
$KINDER = ['k1', 'k2'];
$HEUTE = '2026-09-09';
$JETZT = (int)strtotime('2026-09-09 12:00');

// ── Datum ───────────────────────────────────────────────────────────────────
pruefe('gueltiges Datum', HomeworkCalc::DatumGueltig('2026-09-10'), true);
pruefe('30. Februar gibt es nicht', HomeworkCalc::DatumGueltig('2026-02-30'), false);
pruefe('Schaltjahr 29.02.2028', HomeworkCalc::DatumGueltig('2028-02-29'), true);
pruefe('kein Datum', HomeworkCalc::DatumGueltig('morgen'), false);
pruefe('Datum ohne Nullen', HomeworkCalc::DatumGueltig('2026-9-9'), false);
pruefe('morgen liegt im Fenster', HomeworkCalc::DatumImFenster('2026-09-10', $HEUTE), true);
pruefe('ein Jahr voraus liegt im Fenster', HomeworkCalc::DatumImFenster('2027-09-08', $HEUTE), true);
pruefe('zwei Jahre voraus nicht', HomeworkCalc::DatumImFenster('2028-09-09', $HEUTE), false);
pruefe('ein Jahr zurueck liegt im Fenster', HomeworkCalc::DatumImFenster('2025-09-10', $HEUTE), true);
pruefe('drei Jahre zurueck nicht', HomeworkCalc::DatumImFenster('2023-09-09', $HEUTE), false);

// ── Fachvergleich und Auflösung ─────────────────────────────────────────────
pruefe('gleiches Fach', HomeworkCalc::FachTreffer('Mathematik', 'Mathematik'), true);
pruefe('Gross- und Kleinschreibung', HomeworkCalc::FachTreffer('mathematik', 'Mathematik'), true);
pruefe('Kurzform trifft den Anfang', HomeworkCalc::FachTreffer('Mathe', 'Mathematik'), true);
pruefe('Kurzform in der anderen Richtung', HomeworkCalc::FachTreffer('Mathematik', 'Mathe'), true);
pruefe('zwei Buchstaben treffen nicht', HomeworkCalc::FachTreffer('Ma', 'Mathematik'), false);
pruefe('anderes Fach trifft nicht', HomeworkCalc::FachTreffer('Deutsch', 'Mathematik'), false);
pruefe('Umlaut bleibt Umlaut', HomeworkCalc::FachTreffer('Erdkunde', 'Erdkünde'), false);
pruefe('leeres Fach trifft nichts', HomeworkCalc::FachTreffer('', 'Mathematik'), false);
pruefe('Kurzform wird zum langen Namen',
    HomeworkCalc::FachAufloesen('  mathe ', $FAECHER), 'Mathematik');
pruefe('unbekanntes Fach bleibt stehen',
    HomeworkCalc::FachAufloesen('Werken', $FAECHER), 'Werken');
pruefe('leeres Fach bleibt leer', HomeworkCalc::FachAufloesen('   ', $FAECHER), '');
pruefe('sehr langes Fach wird gekuerzt',
    mb_strlen(HomeworkCalc::FachAufloesen(str_repeat('x', 200), $FAECHER)), HomeworkCalc::SUBJECT_MAX);

// ── Normalisieren ───────────────────────────────────────────────────────────
$gut = HomeworkCalc::Normalisieren(
    ['childId' => 'k1', 'subject' => 'Mathe', 'due' => '2026-09-11', 'note' => ' S. 42 Nr. 3-5 '],
    $FAECHER, $KINDER, $HEUTE, $JETZT);
pruefe('gueltiger Eintrag: Fach aufgeloest', $gut['subject'], 'Mathematik');
pruefe('gueltiger Eintrag: Notiz getrimmt', $gut['note'], 'S. 42 Nr. 3-5');
pruefe('gueltiger Eintrag: nicht erledigt', $gut['done'], false);
pruefe('gueltiger Eintrag: Quelle Vorgabe', $gut['source'], 'app');
pruefe('gueltiger Eintrag: Zeitstempel gesetzt', $gut['createdAt'], $JETZT);
pruefe('fremdes Kind wird abgelehnt', HomeworkCalc::Normalisieren(
    ['childId' => 'erwachsener', 'subject' => 'Mathe'], $FAECHER, $KINDER, $HEUTE, $JETZT), null);
pruefe('ohne Kind wird abgelehnt', HomeworkCalc::Normalisieren(
    ['subject' => 'Mathe'], $FAECHER, $KINDER, $HEUTE, $JETZT), null);
pruefe('ohne Fach wird abgelehnt', HomeworkCalc::Normalisieren(
    ['childId' => 'k1', 'subject' => '  '], $FAECHER, $KINDER, $HEUTE, $JETZT), null);
pruefe('unmoegliches Datum wird abgelehnt', HomeworkCalc::Normalisieren(
    ['childId' => 'k1', 'subject' => 'Mathe', 'due' => '2026-02-30'], $FAECHER, $KINDER, $HEUTE, $JETZT), null);
pruefe('Datum weit voraus wird abgelehnt', HomeworkCalc::Normalisieren(
    ['childId' => 'k1', 'subject' => 'Mathe', 'due' => '2030-01-01'], $FAECHER, $KINDER, $HEUTE, $JETZT), null);
pruefe('ohne Datum ist zulaessig', HomeworkCalc::Normalisieren(
    ['childId' => 'k1', 'subject' => 'Mathe'], $FAECHER, $KINDER, $HEUTE, $JETZT)['due'], '');
pruefe('erfundene Quelle faellt auf app zurueck', HomeworkCalc::Normalisieren(
    ['childId' => 'k1', 'subject' => 'Mathe', 'source' => 'zauberei'], $FAECHER, $KINDER, $HEUTE, $JETZT)['source'], 'app');
pruefe('Quelle edumaps bleibt', HomeworkCalc::Normalisieren(
    ['childId' => 'k1', 'subject' => 'Mathe', 'source' => 'edumaps'], $FAECHER, $KINDER, $HEUTE, $JETZT)['source'], 'edumaps');
pruefe('zu lange Notiz wird gekuerzt', mb_strlen(HomeworkCalc::Normalisieren(
    ['childId' => 'k1', 'subject' => 'Mathe', 'note' => str_repeat('a', 900)],
    $FAECHER, $KINDER, $HEUTE, $JETZT)['note']), HomeworkCalc::NOTE_MAX);
pruefe('erledigt setzt doneAt', HomeworkCalc::Normalisieren(
    ['childId' => 'k1', 'subject' => 'Mathe', 'done' => true], $FAECHER, $KINDER, $HEUTE, $JETZT)['doneAt'], $JETZT);

// ── Aufbewahrung ────────────────────────────────────────────────────────────
$tag = static fn(int $n): string => date('Y-m-d', (int)strtotime("$n days", $JETZT));
$items = [
    ['id' => 'a', 'childId' => 'k1', 'subject' => 'Mathematik', 'due' => $tag(1),
     'done' => false, 'doneAt' => 0, 'createdAt' => $JETZT, 'updatedAt' => $JETZT],
    // gerade noch aufbewahrt: erledigt vor 13 Tagen
    ['id' => 'b', 'childId' => 'k1', 'subject' => 'Deutsch', 'due' => $tag(-14),
     'done' => true, 'doneAt' => $JETZT - 13 * 86400, 'createdAt' => $JETZT - 20 * 86400, 'updatedAt' => $JETZT],
    // faellt weg: erledigt vor 15 Tagen
    ['id' => 'c', 'childId' => 'k1', 'subject' => 'Sport', 'due' => $tag(-16),
     'done' => true, 'doneAt' => $JETZT - 15 * 86400, 'createdAt' => $JETZT - 20 * 86400, 'updatedAt' => $JETZT],
    // offen und alt: faellt weg
    ['id' => 'd', 'childId' => 'k2', 'subject' => 'Musik', 'due' => $tag(-70),
     'done' => false, 'doneAt' => 0, 'createdAt' => $JETZT - 70 * 86400, 'updatedAt' => $JETZT],
    // offen, alt, aber gerade noch drin
    ['id' => 'e', 'childId' => 'k2', 'subject' => 'Englisch', 'due' => $tag(-59),
     'done' => false, 'doneAt' => 0, 'createdAt' => $JETZT - 59 * 86400, 'updatedAt' => $JETZT],
    // offen ohne Datum: die Anlagezeit entscheidet
    ['id' => 'f', 'childId' => 'k1', 'subject' => 'Mathematik', 'due' => '',
     'done' => false, 'doneAt' => 0, 'createdAt' => $JETZT - 3 * 86400, 'updatedAt' => $JETZT],
];
$bleibt = array_column(HomeworkCalc::Aufbewahrung($items, $JETZT), 'id');
pruefe('Aufbewahrung an den Tagesraendern', $bleibt, ['a', 'b', 'e', 'f']);

// Deckel: die AELTESTEN fallen heraus, gemessen an der Anlagezeit
$viele = [];
for ($i = 0; $i < HomeworkCalc::ITEMS_MAX + 20; $i++) {
    $viele[] = ['id' => 'x' . $i, 'childId' => 'k1', 'subject' => 'Mathematik', 'due' => $tag(1),
                'done' => false, 'doneAt' => 0, 'createdAt' => $JETZT - (HomeworkCalc::ITEMS_MAX + 20 - $i) * 60,
                'updatedAt' => $JETZT];
}
$gekappt = HomeworkCalc::Aufbewahrung($viele, $JETZT);
pruefe('Deckel greift', count($gekappt), HomeworkCalc::ITEMS_MAX);
pruefe('der aelteste faellt heraus', $gekappt[0]['id'], 'x20');
pruefe('der neueste bleibt', end($gekappt)['id'], 'x' . (HomeworkCalc::ITEMS_MAX + 19));

// ── Zuordnung zur Stunde ────────────────────────────────────────────────────
$plan = ['children' => [
    ['userId' => 'k1', 'days' => [
        ['date' => '2026-09-10', 'slots' => [
            ['id' => 's1', 'name' => 'Mathematik'],
            ['id' => 's2', 'name' => 'Deutsch'],
            ['id' => 's3', 'name' => 'Mathematik'],
        ]],
        ['date' => '2026-09-11', 'slots' => [['id' => 's4', 'name' => 'Sport']]],
    ]],
    ['userId' => 'k2', 'days' => [
        ['date' => '2026-09-10', 'slots' => [['id' => 's9', 'name' => 'Musik']]],
    ]],
]];
$hw = [
    ['childId' => 'k1', 'subject' => 'Mathematik', 'due' => '2026-09-10', 'done' => false],
    ['childId' => 'k1', 'subject' => 'Mathe', 'due' => '2026-09-10', 'done' => false],
    ['childId' => 'k1', 'subject' => 'Deutsch', 'due' => '2026-09-10', 'done' => false],
    // erledigt: zaehlt nicht
    ['childId' => 'k1', 'subject' => 'Deutsch', 'due' => '2026-09-10', 'done' => true],
    // Fach hat an dem Tag keine Stunde
    ['childId' => 'k1', 'subject' => 'Englisch', 'due' => '2026-09-10', 'done' => false],
    // anderes Kind
    ['childId' => 'k2', 'subject' => 'Musik', 'due' => '2026-09-10', 'done' => false],
    // ohne Datum: gehoert nirgends hin
    ['childId' => 'k1', 'subject' => 'Sport', 'due' => '', 'done' => false],
];
$zu = HomeworkCalc::FuerPlan($hw, $plan);
pruefe('zwei Aufgaben an der ERSTEN Mathestunde', $zu['slots']['s1'] ?? 0, 2);
pruefe('die zweite Mathestunde bleibt leer', isset($zu['slots']['s3']), false);
pruefe('Deutsch zaehlt nur die offene', $zu['slots']['s2'] ?? 0, 1);
pruefe('Musik beim anderen Kind', $zu['slots']['s9'] ?? 0, 1);
pruefe('Fach ohne Stunde wird am Tag gesammelt', $zu['frei']['k1|2026-09-10'] ?? 0, 1);
pruefe('ohne Datum gar nicht', count($zu['frei']), 1);

// ── Vorabend ────────────────────────────────────────────────────────────────
$fuerMorgen = HomeworkCalc::FuerTag($hw, '2026-09-10');
pruefe('zwei Kinder haben etwas fuer morgen', array_keys($fuerMorgen), ['k1', 'k2']);
pruefe('bei k1 vier offene', count($fuerMorgen['k1']), 4);
pruefe('erledigte zaehlen nicht mit',
    count(array_filter($fuerMorgen['k1'], static fn(array $i): bool => $i['done'] === true)), 0);
pruefe('an einem Tag ohne Aufgaben nichts', HomeworkCalc::FuerTag($hw, '2026-09-12'), []);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
