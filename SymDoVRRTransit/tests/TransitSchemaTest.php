<?php

declare(strict_types=1);

/**
 * Die schematische Vorschau im Formular — Renderer und Verdrahtung.
 *
 * Drei Dinge muessen halten: das SVG ist wohlgeformt und klein, jede
 * Einstellung ist im Bild zu sehen (Steig-Spalte, Farben, Ansicht, Stil), und
 * kein Nutzerwert aus der Farbtabelle gelangt ungeprueft ins Markup — die
 * Tabelle ist eine Formular-Eingabe, ihr Inhalt fremd.
 *
 * Der Renderer braucht kein Symcon. Der zweite Teil prueft die Verdrahtung
 * am Quelltext des Moduls: das Image-Element, die vier onChange, der Reset.
 *
 *   php SymDoVRRTransit/tests/TransitSchemaTest.php
 */

require_once __DIR__ . '/../libs/TransitSchema.php';

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

libxml_use_internal_errors(true);
function wohlgeformt(string $svg): bool { return simplexml_load_string($svg) !== false; }
function zaehle(string $svg, string $teil): int { return substr_count($svg, 'data-teil="' . $teil . '"'); }
function bild(array $roh): string { return TransitSchema::Svg(TransitSchema::Einstellungen($roh)); }

// ── Alle Varianten sind wohlgeformt und klein ──────────────────────────────
$varianten = [
    'abfahrten' => ['view' => 'departures'],
    'balken'    => ['view' => 'routes', 'style' => 'bars'],
    'zeitachse' => ['view' => 'routes', 'style' => 'timeline'],
    'senkrecht' => ['view' => 'routes', 'style' => 'vertical'],
];
foreach ($varianten as $name => $roh) {
    foreach ([true, false] as $steig) {
        $svg = bild($roh + ['platform' => $steig]);
        pruefe("$name (Steig " . ($steig ? 'an' : 'aus') . ') ist wohlgeformtes SVG',
            [wohlgeformt($svg), str_starts_with($svg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 200"')],
            [true, true]);
        $uri = TransitSchema::DataUri(TransitSchema::Einstellungen($roh + ['platform' => $steig]));
        pruefe("… und als Data-URI unter 10 KB", strlen($uri) < 10240, true);
    }
}
$uri = TransitSchema::DataUri(TransitSchema::Einstellungen([]));
pruefe('Die Data-URI traegt den SVG-Kopf und kommt unversehrt zurueck',
    [str_starts_with($uri, 'data:image/svg+xml;base64,'),
     base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true) === bild([])],
    [true, true]);

// ── Einstellungen: Weisslisten und Vorgaben ────────────────────────────────
$e = TransitSchema::Einstellungen(['view' => 'foo', 'style' => 'bar', 'platform' => 'nein']);
pruefe('Unbekannte Ansicht, Stil und Steig-Angabe fallen auf die Vorgaben',
    [$e['view'], $e['style'], $e['steig']], ['departures', 'bars', true]);
pruefe('„false" und 0 schalten den Steig aus',
    [TransitSchema::Einstellungen(['platform' => 'false'])['steig'],
     TransitSchema::Einstellungen(['platform' => 0])['steig'],
     TransitSchema::Einstellungen(['platform' => true])['steig']], [false, false, true]);
pruefe('Ohne Tabelle stehen alle elf Verkehrsmittel mit Vorgabefarbe da',
    [count($e['farben']), $e['farben']['tram'], $e['farben']['fuss']], [11, '#C1152B', '#5E8C80']);

// ── Abfahrten ──────────────────────────────────────────────────────────────
$mit  = bild(['view' => 'departures', 'platform' => true]);
$ohne = bild(['view' => 'departures', 'platform' => false]);
pruefe('Die Tafel hat vier Zeilen mit je einem Symbol',
    [zaehle($mit, 'zeile'), zaehle($mit, 'sym')], [4, 4]);
pruefe('Die Steig-Spalte gibt es nur, wenn sie eingeschaltet ist',
    [zaehle($mit, 'steig'), zaehle($ohne, 'steig')], [4, 0]);
pruefe('Ohne Steig wird das Ziel breiter',
    [str_contains($mit, 'width="128"'), str_contains($ohne, 'width="160"')], [true, true]);
pruefe('Der Umschalter zeigt „Abfahrten" als aktiv (linke Pille)',
    (bool)preg_match('/x="232"[^>]*data-teil="umschalter-an"/', $mit), true);
pruefe('… und in der Streckenansicht die rechte',
    (bool)preg_match('/x="272"[^>]*data-teil="umschalter-an"/', bild(['view' => 'routes'])), true);

// ── Farben aus der Tabelle ─────────────────────────────────────────────────
$tabelle = static fn(mixed $farbe, string $key = 'tram'): string
    => bild(['view' => 'departures', 'modes' => [['key' => $key, 'color' => $farbe]]]);
pruefe('Eine gewaehlte Farbe faerbt das Symbol', str_contains($tabelle(0x112233), 'fill="#112233"'), true);
pruefe('-1 heisst Vorgabe', [str_contains($tabelle(-1), '#C1152B'), str_contains($tabelle(-1), '#000000')], [true, false]);
pruefe('Hoehere Bits werden abgeschnitten', str_contains($tabelle(0xFF112233), 'fill="#112233"'), true);
pruefe('Ein Ziffernstring gilt', str_contains($tabelle('1122867'), 'fill="#112233"'), true);
pruefe('Ein Farbwort ist keine Zahl — Vorgabe, nicht Schwarz',
    [str_contains($tabelle('red'), '#C1152B'), str_contains($tabelle('red'), '#000000')], [true, false]);
pruefe('Ein fremder Schluessel wird uebergangen',
    [wohlgeformt($tabelle(0x112233, 'fremd')), str_contains($tabelle(0x112233, 'fremd'), '#112233')], [true, false]);

// ── Balken, Zeitachse, Senkrecht ───────────────────────────────────────────
$balken = bild(['view' => 'routes', 'style' => 'bars',
    'modes' => [['key' => 'tram', 'color' => 0x110000], ['key' => 'bus', 'color' => 0x000022]]]);
pruefe('Der Balken hat drei Fahrten, ein Warten und Fusswege',
    [zaehle($balken, 'fahrt'), zaehle($balken, 'warten'), zaehle($balken, 'fuss') >= 1], [3, 1, true]);
pruefe('… in den Farben der beiden Verkehrsmittel',
    [str_contains($balken, '#110000'), str_contains($balken, '#000022')], [true, true]);

$zeit = bild(['view' => 'routes', 'style' => 'timeline', 'platform' => true]);
pruefe('Die Zeitachse hat drei Punkte und zwei farbige Abschnitte',
    [zaehle($zeit, 'punkt'), zaehle($zeit, 'fahrt')], [3, 2]);
pruefe('… Steig unter Start und Umstieg, ohne Schalter keiner',
    [zaehle($zeit, 'steig'), zaehle(bild(['view' => 'routes', 'style' => 'timeline', 'platform' => false]), 'steig')], [2, 0]);

$senk = bild(['view' => 'routes', 'style' => 'vertical', 'platform' => true]);
pruefe('Die senkrechte Ansicht: drei Halte, zwei Fahrten, vier Masse',
    [zaehle($senk, 'halt'), zaehle($senk, 'fahrt'), zaehle($senk, 'mass')], [3, 2, 4]);
preg_match_all('/cy="(\d+)"[^>]*data-teil="halt"/', $senk, $m);
$ys = array_map('intval', $m[1]);
pruefe('… die Halte stehen von oben nach unten',
    [count($ys), $ys[0] < $ys[1] && $ys[1] < $ys[2]], [3, true]);
pruefe('… Steig an den Halten nur mit Schalter',
    [zaehle($senk, 'steig'), zaehle(bild(['view' => 'routes', 'style' => 'vertical', 'platform' => false]), 'steig')], [3, 0]);

// ── Feindliche Tabelle ─────────────────────────────────────────────────────
/* Die Tabelle ist eine Formular-Eingabe. Was darin steht, ist fremd — und ein
   SVG ist Markup, das die Konsole rendert. */
$boese = bild(['view' => 'departures', 'modes' => [[
    'key'   => 'tram"><script>alert(1)</script>',
    'name'  => '<script>alert(2)</script>',
    'icon'  => '" onload="alert(3)',
    'color' => '#fff" onload="alert(4)',
]]]);
pruefe('Nichts aus einer feindlichen Tabelle gelangt ins Markup',
    [wohlgeformt($boese), str_contains($boese, '<script'), str_contains($boese, 'onload'),
     str_contains($boese, 'alert')], [true, false, false, false]);
pruefe('… die Farbe ist die Vorgabe', str_contains($boese, '#C1152B'), true);

// ── Verdrahtung im Modul ───────────────────────────────────────────────────
$quelle = (string)file_get_contents(__DIR__ . '/../module.php');
$panel  = substr($quelle, (int)strpos($quelle, "\$this->Translate('Appearance')"));
$panel  = substr($panel, 0, (int)strpos($panel, "'actions' =>"));
pruefe('Das Formular traegt das Bild als Image-Element „Schema"',
    (bool)preg_match("/'type' => 'Image',\s*'name' => 'Schema'/", $panel), true);
pruefe('Das Bild steht VOR dem ersten Auswahlfeld',
    strpos($panel, "'name' => 'Schema'") < strpos($panel, "'name' => 'DefaultView'"), true);
pruefe('Alle vier Darstellungsfelder melden ihre Aenderung',
    substr_count($panel, "'onChange' => \$schema"), 4);
pruefe('… an SchemaZeigen, mit der Tabelle als Liste',
    [str_contains($quelle, '"SchemaZeigen", json_encode('), str_contains($quelle, 'iterator_to_array($Modes)')],
    [true, true]);
pruefe('Der Reset-Knopf reicht die Auswahl mit, damit die Vorschau passt',
    (bool)preg_match('/"ModesReset", json_encode\(\["view" => \$DefaultView/', $quelle), true);
pruefe('RequestAction kennt den Fall', str_contains($quelle, "case 'SchemaZeigen':"), true);
$store = (string)file_get_contents(__DIR__ . '/../libs/TransitStore.php');
pruefe('Der Bestand baut das Bild aus Formularwerten und Konfiguration',
    [str_contains($store, 'private function TransitSchemaBild(array $roh): string'),
     str_contains($store, "require_once __DIR__ . '/TransitSchema.php';")], [true, true]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
