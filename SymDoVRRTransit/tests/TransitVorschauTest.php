<?php

declare(strict_types=1);

/**
 * Die Vorschau der Kachel im Formular — Renderer, Symbole und Verdrahtung.
 *
 * Das Bild ist ein SVG mit der Kachel als HTML+CSS im foreignObject. Drei
 * Dinge muessen halten: es ist wohlgeformtes XML (sonst zeigt das <img> der
 * Konsole nichts, ohne Fehler), jede Einstellung ist im Markup zu sehen
 * (Steig, Farben, Symbole, Ansicht, Stil), und kein Wert aus der Farbtabelle
 * gelangt ungeprueft ins Markup — die Tabelle ist eine Formular-Eingabe.
 *
 * Der Renderer braucht kein Symcon. Der letzte Teil prueft die Verdrahtung am
 * Quelltext des Moduls.
 *
 *   php SymDoVRRTransit/tests/TransitVorschauTest.php
 */

require_once __DIR__ . '/../libs/TransitVorschau.php';

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
    printf("%-4s %-64s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

libxml_use_internal_errors(true);
function wohlgeformt(string $svg): bool { return simplexml_load_string($svg) !== false; }
function klasse(string $svg, string $k): int { return preg_match_all('/class="(?:[^"]* )?' . preg_quote($k, '/') . '(?: [^"]*)?"/', $svg); }

/* Das echte Stilblatt der Kachel, so wie das Modul es liest. */
$html = (string)file_get_contents(__DIR__ . '/../module.html');
preg_match('#<style>(.*?)</style>#s', $html, $m);
$css = (string)($m[1] ?? '');
pruefe('Das Stilblatt der Kachel ist lesbar und nicht winzig', strlen($css) > 10000, true);

function bild(array $roh, ?string $css = null): string
{
    global $css_global;
    return TransitVorschau::Svg(TransitVorschau::Einstellungen($roh), $css ?? $css_global, []);
}
$css_global = $css;

// ── Alle Varianten: wohlgeformt, foreignObject, Groesse ────────────────────
$varianten = [
    'abfahrten' => ['view' => 'departures'],
    'balken'    => ['view' => 'routes', 'style' => 'bars'],
    'zeitachse' => ['view' => 'routes', 'style' => 'timeline'],
    'senkrecht' => ['view' => 'routes', 'style' => 'vertical'],
];
foreach ($varianten as $name => $roh) {
    foreach ([true, false] as $steig) {
        $svg = bild($roh + ['platform' => $steig]);
        $xml = simplexml_load_string($svg);
        pruefe("$name (Steig " . ($steig ? 'an' : 'aus') . ') ist wohlgeformtes SVG mit foreignObject und XHTML',
            [$xml !== false, str_contains($svg, '<foreignObject width="100%" height="100%">'),
             str_contains($svg, '<div xmlns="http://www.w3.org/1999/xhtml" class="vorschau">')],
            [true, true, true]);
        $uri = TransitVorschau::DataUri(TransitVorschau::Einstellungen($roh + ['platform' => $steig]), $css, []);
        pruefe('… traegt das Stilblatt und bleibt unter 60 KB',
            [str_contains($svg, '<style><![CDATA['), str_contains($svg, '.karte'), strlen($uri) < 61440],
            [true, true, true]);
    }
}
pruefe('Senkrechte Ansicht und Zeitachse bekommen ein hoeheres Bild, die Tafel die Grundhoehe',
    [(bool)preg_match('/height="' . TransitVorschau::HOEHE_SENKRECHT . '"/', bild(['view' => 'routes', 'style' => 'vertical'])),
     (bool)preg_match('/height="' . TransitVorschau::HOEHE_ZEITACHSE . '"/', bild(['view' => 'routes', 'style' => 'timeline'])),
     (bool)preg_match('/height="' . TransitVorschau::HOEHE . '"/', bild(['view' => 'departures'])),
     TransitVorschau::HOEHE_ZEITACHSE], [true, true, true, 350]);
$uri = TransitVorschau::DataUri(TransitVorschau::Einstellungen([]), $css, []);
pruefe('Die Data-URI traegt den SVG-Kopf und kommt unversehrt zurueck',
    [str_starts_with($uri, 'data:image/svg+xml;base64,'),
     base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true) === bild([])], [true, true]);

// ── Einstellungen ──────────────────────────────────────────────────────────
$e = TransitVorschau::Einstellungen(['view' => 'foo', 'style' => 'bar', 'platform' => 'nein']);
pruefe('Unbekannte Ansicht, Stil und Steig-Angabe fallen auf die Vorgaben',
    [$e['view'], $e['style'], $e['steig']], ['departures', 'bars', true]);
pruefe('„false" und 0 schalten den Steig aus',
    [TransitVorschau::Einstellungen(['platform' => 'false'])['steig'],
     TransitVorschau::Einstellungen(['platform' => 0])['steig']], [false, false]);
pruefe('Ohne Tabelle: elf Verkehrsmittel mit Vorgabefarbe und -symbol',
    [count($e['farben']), $e['farben']['tram'], $e['icons']['tram']], [11, '#C1152B', 'fa-train-tram']);

// ── Abfahrten ──────────────────────────────────────────────────────────────
$mit  = bild(['view' => 'departures', 'platform' => true]);
$ohne = bild(['view' => 'departures', 'platform' => false]);
pruefe('Die Tafel hat drei Abfahrten mit Symbol, Linie, Ziel und Zeit',
    [klasse($mit, 'abfahrt'), klasse($mit, 'sym'), klasse($mit, 'linie'), klasse($mit, 'wann')], [3, 3, 3, 3]);
pruefe('Die Steig-Spalte gibt es nur mit Schalter — sonst die Klasse ohne-steig',
    [klasse($mit, 'steig'), klasse($ohne, 'steig'), klasse($mit, 'ohne-steig'), klasse($ohne, 'ohne-steig')],
    [3, 0, 0, 1]);
pruefe('Der geschuetzte Leerraum ist XML, nicht HTML',
    [str_contains($mit, '&#160;'), str_contains($mit, '&nbsp;')], [true, false]);
pruefe('Der Umschalter markiert die gewaehlte Ansicht',
    [(bool)preg_match('/data-ansicht="departures" class="an"/', $mit),
     (bool)preg_match('/data-ansicht="routes" class="an"/', bild(['view' => 'routes']))], [true, true]);

// ── Farben und Symbole aus der Tabelle ─────────────────────────────────────
$tabelle = static fn(array $zeile): string => bild(['view' => 'departures', 'modes' => [$zeile + ['key' => 'tram']]]);
pruefe('Eine gewaehlte Farbe faerbt das Symbol', str_contains($tabelle(['color' => 0x112233]), 'background:#112233'), true);
pruefe('-1 heisst Vorgabe', [str_contains($tabelle(['color' => -1]), '#C1152B'), str_contains($tabelle(['color' => -1]), '#000000')], [true, false]);
pruefe('Hoehere Bits werden abgeschnitten', str_contains($tabelle(['color' => 0xFF112233]), 'background:#112233'), true);
pruefe('Ein Farbwort ist keine Zahl — Vorgabe, nicht Schwarz',
    [str_contains($tabelle(['color' => 'red']), '#C1152B'), str_contains($tabelle(['color' => 'red']), '#000000')], [true, false]);
pruefe('Das Symbol der Tabelle wird gezeichnet',
    [str_contains($tabelle(['icon' => 'bus']), 'class="svg-inline--fa fa-bus"'),
     str_contains($tabelle(['icon' => 'fa-ship']), 'fa-ship"')], [true, true]);
pruefe('Ein Symbol, das die Kachel nicht kennt, wird der Kreis',
    [str_contains($tabelle(['icon' => 'unicorn']), 'fa-circle"'), str_contains($tabelle(['icon' => 'unicorn']), 'unicorn')], [true, false]);
pruefe('Jedes Symbol traegt den SVG-Namensraum — sonst bleibt es im XHTML leer',
    substr_count($mit, '<svg xmlns="http://www.w3.org/2000/svg" class="svg-inline--fa') >= 6, true);
pruefe('Alle sechzehn Symbole der Kachel sind da',
    count(array_intersect(['train-subway', 'train-tram', 'bus', 'taxi', 'train', 'ship', 'cable-car', 'route', 'person-walking',
        'arrow-right', 'chevron-down', 'triangle-exclamation', 'stopwatch', 'arrow-right-arrow-left', 'users', 'circle'],
        array_keys(TransitIcons::PFADE))), 16);

// ── Balken, Zeitachse, Senkrecht ───────────────────────────────────────────
$balken = bild(['view' => 'routes', 'style' => 'bars',
    'modes' => [['key' => 'tram', 'color' => 0x110000], ['key' => 'bus', 'color' => 0x000022]]]);
pruefe('Der Balken hat drei Fahrten, ein Warten, drei Fusswege — und nur die Fahrten wachsen',
    [klasse($balken, 'ride'), klasse($balken, 'wait'), klasse($balken, 'walk'), substr_count($balken, 'flex-grow:')], [3, 1, 3, 3]);
pruefe('… in den Farben der Tabelle', [str_contains($balken, 'background:#110000'), str_contains($balken, 'background:#000022')], [true, true]);

$zeit = bild(['view' => 'routes', 'style' => 'timeline', 'platform' => true]);
pruefe('Die Zeitachse hat zwei Halte, einen Umstieg, zwei Fahrten, zwei Fusswege (je drei Zellen)',
    [klasse($zeit, 'tl-halt'), klasse($zeit, 'tl-um'), klasse($zeit, 'tl-fahrt'), klasse($zeit, 'tl-weg')], [6, 3, 6, 6]);
pruefe('… mit Schiene links/rechts am Halt und Spaltenbreiten wie die Kachel',
    [substr_count($zeit, '--l:#'), str_contains($zeit, 'grid-template-columns:min-content minmax(0, max-content) minmax(min-content, 660fr)')], [2, true]);
pruefe('… Steig nur mit Schalter',
    [substr_count($zeit, 'Pl. '), substr_count(bild(['view' => 'routes', 'style' => 'timeline', 'platform' => false]), 'Pl. ')], [2, 0]);

$senk = bild(['view' => 'routes', 'style' => 'vertical', 'platform' => true]);
pruefe('Die senkrechte Ansicht: Fussweg, Einstieg, Fahrt, Ausstieg, dazu die Kennzahlen',
    [klasse($senk, 'weg'), klasse($senk, 'vt-halt'), klasse($senk, 'vt-fahrt'), klasse($senk, 'tl-wert')], [1, 2, 1, 3]);
pruefe('… Hoehen wie laenge(): 21 min Fahrt = 168 − 22 Pixel', str_contains($senk, 'min-height:146px'), true);
pruefe('… Steig nur mit Schalter',
    [klasse($senk, 'steig'), klasse(bild(['view' => 'routes', 'style' => 'vertical', 'platform' => false]), 'steig')], [1, 0]);

// ── Fremdes ────────────────────────────────────────────────────────────────
$boese = bild(['view' => 'departures', 'modes' => [[
    'key'   => 'tram"><script>alert(1)</script>',
    'name'  => '<script>alert(2)</script>',
    'icon'  => 'x" onload="alert(3)',
    'color' => '#fff" onload="alert(4)',
], ['key' => 'bus', 'icon' => '../../etc/passwd', 'color' => 'javascript:alert(5)']]]);
pruefe('Nichts aus einer feindlichen Tabelle gelangt ins Markup',
    [wohlgeformt($boese), str_contains($boese, '<script'), str_contains($boese, 'onload'),
     str_contains($boese, 'alert'), str_contains($boese, 'passwd'), str_contains($boese, 'javascript')],
    [true, false, false, false, false, false]);
pruefe('… Farbe und Symbol sind die Vorgabe',
    [str_contains($boese, '#C1152B'), str_contains($boese, 'fa-train-tram"')], [true, true]);
/* Fremde Texte kommen nur uebersetzt vom Modul — aber escaped werden sie trotzdem. */
$text = TransitVorschau::Svg(TransitVorschau::Einstellungen([]), $css, ['Main station' => '<b>Haupt & Bahnhof</b>']);
pruefe('Ein Text mit Markup wird escaped', [wohlgeformt($text), str_contains($text, '&lt;b&gt;Haupt &amp; Bahnhof')], [true, true]);

// ── CSS ────────────────────────────────────────────────────────────────────
$roh = TransitVorschau::Svg(TransitVorschau::Einstellungen([]), "/* weg */ .a{color:red} ]]> .b{}", []);
pruefe('Kommentare fallen weg, ein CDATA-Ende im CSS wird entschaerft',
    [str_contains($roh, 'weg */'), wohlgeformt($roh), str_contains($roh, ']]]]><![CDATA[>')], [false, true, true]);
/* Die Karte bekommt ihren Kasten aus `--zeile`, einer color-mix aus den
   Visu-Variablen ohne Fallback. Ohne die Vorbelegung ist die Mischung im
   eigenen Dokument ungueltig und die Karte unsichtbar. */
$voll = bild(['view' => 'departures']);
pruefe('Die Visu-Variablen sind vorbelegt, und zwar VOR dem Stilblatt der Kachel',
    [str_contains($voll, ':root{--card-color:#2b2c30;--content-color:#ffffff;--accent-color:#00cdab}'),
     strpos($voll, '--card-color:#2b2c30') < strpos($voll, '.karte')], [true, true]);
pruefe('Ohne Stilblatt trotzdem wohlgeformt', wohlgeformt(TransitVorschau::Svg(TransitVorschau::Einstellungen([]), '', [])), true);

// ── Verdrahtung im Modul ───────────────────────────────────────────────────
$quelle = (string)file_get_contents(__DIR__ . '/../module.php');
$panel  = substr($quelle, (int)strpos($quelle, "\$this->Translate('Appearance')"));
$panel  = substr($panel, 0, (int)strpos($panel, "'actions' =>"));
pruefe('Das Formular traegt das Bild als Image-Element „Schema" vor dem ersten Feld',
    [(bool)preg_match("/'type' => 'Image',\s*'name' => 'Schema'/", $panel),
     strpos($panel, "'name' => 'Schema'") < strpos($panel, "'name' => 'DefaultView'")], [true, true]);
/* Drei Felder per onChange — und die Tabelle per onEdit: eine Liste kennt
   kein onChange (nur onAdd/onEdit/onDelete/onChangeOrder). Mit onChange an der
   Tabelle feuerte nichts, und die Farben folgten erst nach dem Uebernehmen. */
pruefe('Drei Felder melden per onChange, die Farbtabelle per onEdit — alle an SchemaZeigen',
    [substr_count($panel, "'onChange' => \$schema"), substr_count($panel, "'onEdit' => \$schema"),
     (bool)preg_match("/'name' => 'Modes'[^\]]*?'onEdit' => \\\$schema/s", $panel),
     str_contains($quelle, '"SchemaZeigen", json_encode('), str_contains($quelle, 'iterator_to_array($Modes)'),
     str_contains($quelle, "case 'SchemaZeigen':")], [3, 1, true, true, true, true]);
pruefe('Kein onChange an der Liste — das feuert nie', (bool)preg_match("/'name' => 'Modes'[^\]]*?'onChange'/s", $panel), false);
pruefe('Der Reset-Knopf reicht die Auswahl mit', (bool)preg_match('/"ModesReset", json_encode\(\["view" => \$DefaultView/', $quelle), true);
$store = (string)file_get_contents(__DIR__ . '/../libs/TransitStore.php');
pruefe('Der Bestand liest das Stilblatt aus module.html und uebersetzt die Texte',
    [str_contains($store, "file_get_contents(__DIR__ . '/../module.html')"),
     str_contains($store, 'TransitVorschau::Vorgabetexte()'), str_contains($store, 'TransitVorschau::DataUri(')], [true, true, true]);
$de = json_decode((string)file_get_contents(__DIR__ . '/../locale.json'), true)['translations']['de'] ?? [];
$fehlt = array_values(array_filter(array_keys(TransitVorschau::Vorgabetexte()), static fn(string $k): bool => !isset($de[$k])));
pruefe('Jeder Beispieltext hat eine deutsche Uebersetzung', $fehlt, []);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
