<?php

declare(strict_types=1);

/**
 * Pruefstand fuer die Bereiche der Tab-Leiste (Reihenfolge + Sichtbarkeit).
 *
 * Braucht keine Symcon-Attrappen: Bereiche rechnet nur mit dem, was es bekommt.
 * Dazu Riegel am Quelltext — dass Formular, Kachel, Gateway und die Kopien in
 * den Kacheln die neue Reihenfolge wirklich tragen.
 *
 *   php SymDoWebApp/tests/BereicheTest.php
 */

require_once __DIR__ . '/../../libs/Bereiche.php';

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
    printf("%-4s %-66s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}
$keys = static fn(array $zeilen): array => array_column($zeilen, 'key');
$show = static fn(array $zeilen): array => array_combine(array_column($zeilen, 'key'), array_column($zeilen, 'show'));

$vorgabe = ['dashboard', 'school', 'plan', 'transit', 'ki', 'shopping', 'todos', 'calendar', 'notes', 'edumaps', 'homework'];

// ── Vorgabe ────────────────────────────────────────────────────────────────
pruefe('Ohne Konfiguration: Vorgabereihenfolge, alles an',
    [$keys(Bereiche::Zeilen(null)), array_unique(array_values($show(Bereiche::Zeilen(null))))], [$vorgabe, [true]]);
pruefe('Leere Liste ebenso', $keys(Bereiche::Zeilen(['Sections' => '[]'])), $vorgabe);
pruefe('Ungueltiges JSON ebenso', $keys(Bereiche::Zeilen(['Sections' => '{nicht'])), $vorgabe);
pruefe('Elf Namen, elf alte Schalter fuer acht davon',
    [count(Bereiche::NAMEN), count(Bereiche::ALT), array_keys(Bereiche::NAMEN)], [11, 8, $vorgabe]);

// ── Altbestand ─────────────────────────────────────────────────────────────
$alt = ['ShowDashboard' => true, 'ShowKi' => false, 'ShowNotes' => false, 'ShowShopping' => true];
$z = Bereiche::Zeilen($alt);
pruefe('Alte Schalter geben den Haken vor, wo die Liste nichts weiss',
    [$show($z)['ki'], $show($z)['notes'], $show($z)['shopping'], $show($z)['plan'], $show($z)['school'], $show($z)['transit']],
    [false, false, true, true, true, true]);
pruefe('Alter Schalter als Text/Zahl wird verstanden',
    [$show(Bereiche::Zeilen(['ShowKi' => '0']))['ki'], $show(Bereiche::Zeilen(['ShowKi' => 1]))['ki']], [false, true]);

// ── Gespeicherte Liste ─────────────────────────────────────────────────────
$liste = json_encode([['key' => 'todos', 'show' => true], ['key' => 'dashboard', 'show' => false], ['key' => 'school', 'show' => true]]);
$z = Bereiche::Zeilen(['Sections' => $liste, 'ShowDashboard' => true, 'ShowKi' => false]);
pruefe('Gespeicherte Reihenfolge zuerst, Fehlendes hinten in Vorgabereihenfolge',
    $keys($z), ['todos', 'dashboard', 'school', 'plan', 'transit', 'ki', 'shopping', 'calendar', 'notes', 'edumaps', 'homework']);
pruefe('Der Haken der Liste schlaegt den alten Schalter; Fehlendes nimmt den alten',
    [$show($z)['dashboard'], $show($z)['ki']], [false, false]);
$z = Bereiche::Zeilen(['Sections' => json_encode([
    ['key' => 'notes'], ['key' => 'notes', 'show' => false], ['key' => 'fremd', 'show' => true],
    ['show' => true], 'kaputt', ['key' => 'ki', 'show' => 'true'], ['key' => 'shopping', 'show' => '0'],
])]);
pruefe('Doppel: erstes gewinnt; Unbekanntes und Zeilen ohne key fallen weg',
    array_slice($keys($z), 0, 3), ['notes', 'ki', 'shopping']);
pruefe('Fehlender Haken ist an; Text-Haken werden verstanden',
    [$show($z)['notes'], $show($z)['ki'], $show($z)['shopping']], [true, true, false]);
pruefe('Immer elf Zeilen, jede genau einmal',
    [count($z), count(array_unique($keys($z)))], [11, 11]);

// ── Nutzlast ───────────────────────────────────────────────────────────────
$p = Bereiche::AusKonfiguration(['Sections' => $liste, 'ShowKi' => false]);
pruefe('Nutzlast: tabs als Karte, tabOrder als Liste, beide vollstaendig',
    [count($p['tabs']), $p['tabOrder'][0], $p['tabOrder'][1], $p['tabs']['dashboard'], $p['tabs']['ki'], $p['tabs']['homework']],
    [11, 'todos', 'dashboard', false, false, true]);

// ── Riegel am Quelltext ────────────────────────────────────────────────────
$wurzel = __DIR__ . '/../..';
$lesen = static fn(string $p): string => (string)@file_get_contents($wurzel . '/' . $p);
$form = json_decode($lesen('SymDoWebApp/form.json'), true);
$liste = null;
$alteHaken = [];
$suche = static function (array $items) use (&$suche, &$liste, &$alteHaken): void {
    foreach ($items as $e) {
        if (($e['name'] ?? '') === 'Sections') { $liste = $e; }
        if (($e['type'] ?? '') === 'CheckBox' && in_array($e['name'] ?? '', Bereiche::ALT, true)) { $alteHaken[$e['name']] = $e['visible'] ?? true; }
        if (isset($e['items'])) { $suche($e['items']); }
    }
};
$suche($form['elements'] ?? []);
$spalten = array_combine(array_column($liste['columns'] ?? [], 'name'), $liste['columns'] ?? []);
pruefe('Formular: Liste Sections mit Ziehen, ohne Anlegen/Loeschen, Kennung mit save',
    [$liste['changeOrder'] ?? null, $liste['add'] ?? null, $liste['delete'] ?? null,
     $spalten['key']['save'] ?? null, $spalten['key']['visible'] ?? null, isset($spalten['show']['edit']), isset($spalten['name']['save'])],
    [true, false, false, true, false, true, false]);
pruefe('Formular: die acht alten Haken stehen noch da, aber unsichtbar',
    [count($alteHaken), array_unique(array_values($alteHaken))], [8, [false]]);

$php = $lesen('SymDoWebApp/module.php');
pruefe('Kachel: Sections registriert, Bereiche eingebunden, tabOrder in Zustand UND Meta',
    [str_contains($php, "RegisterPropertyString('Sections', '[]')"), str_contains($php, "/../libs/Bereiche.php"),
     substr_count($php, "'tabOrder'") >= 2, str_contains($php, "'SectionsRestartHint'")],
    [true, true, true, true]);
$gw = $lesen('SymDoGateway/libs/AppCore.php') . $lesen('SymDoGateway/libs/ApiRouter.php');
pruefe('Gateway: tabOrder in window.__SYMDO__ und in der Discovery-Auskunft',
    [substr_count($gw, "['tabOrder']") + substr_count($gw, "'tabOrder' ") >= 2, str_contains($gw, 'Bereiche::'),
     str_contains($lesen('SymDoGateway/module.php'), "/../libs/Bereiche.php")],
    [true, true, true]);

$html = $lesen('SymDoWebApp/module.html');
preg_match("/const TAB_REIHENFOLGE = \[([^\]]*)\];/", $html, $m);
$jsReihe = array_map(static fn($s) => trim($s, " '\""), explode(',', $m[1] ?? ''));
pruefe('Kachel-JS: dieselben elf Schluessel in derselben Vorgabereihenfolge', $jsReihe, Bereiche::SCHLUESSEL);
pruefe('Kachel-JS: normalizeTabs kennt school/plan/transit, Reihenfolge wird gelesen und der DOM sortiert',
    [str_contains($html, 'school:    t.school'), str_contains($html, 'plan:      t.plan'), str_contains($html, 'transit:   t.transit'),
     str_contains($html, 'function tabReihenfolge'), str_contains($html, 'store.tabOrder'),
     str_contains($html, 'const schuleWeg = t.dashboard && !t.school;'), str_contains($html, 'ziel.forEach(b => leiste.appendChild(b))')],
    [true, true, true, true, true, true, true]);
pruefe('Die Kachel-Kopien tragen die Reihenfolge mit',
    [str_contains($lesen('ToDoList/module.html'), 'function tabReihenfolge'), str_contains($lesen('ShoppingList/module.html'), 'function tabReihenfolge')],
    [true, true]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
