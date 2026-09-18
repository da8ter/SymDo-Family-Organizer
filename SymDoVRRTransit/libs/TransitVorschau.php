<?php

declare(strict_types=1);

require_once __DIR__ . '/TransitCalc.php';
require_once __DIR__ . '/TransitIcons.php';

/**
 * Die Vorschau der Kachel im Konfigurationsformular — die Kachel selbst, nicht
 * ein Schema davon.
 *
 * Ein „HTML-Export als SVG" braucht einen Browser, den der Server nicht hat.
 * Aber SVG kann HTML samt CSS in einem `<foreignObject>` tragen, und das
 * Image-Element der Konsole ist ein echtes `<img>`, das so ein SVG rendert
 * (dieselbe Technik wie dom-to-image). Also baut PHP hier dasselbe Markup, das
 * `zeichne()` in module.html fuer Beispieldaten erzeugen wuerde, und legt das
 * echte `<style>` der Kachel dazu — das liest das Modul aus module.html, damit
 * die Vorschau jede Gestaltungsaenderung von selbst mitmacht. Das MARKUP muss
 * dagegen von Hand mit `zeichne()` Schritt halten: gleiche Klassen, gleiche
 * Inline-Stile (`background`, `flex-grow`, `--l/--r`, `--o/--u/--knick`,
 * `min-height`, `grid-template-columns`).
 *
 * Was in einem `<img>`-SVG NICHT geht, bestimmt den Bau:
 *  - kein Skript: `/icons.js` setzt keine Symbole ein → `TransitIcons` liefert
 *    sie als Pfade; `engePruefen()` misst nichts → keine `ohne-symbol`-Klassen;
 *  - keine fremden Ressourcen: kein Avatar-Bild;
 *  - ein eigenes Dokument: die drei Visu-Variablen (`--card-color`,
 *    `--content-color`, `--accent-color`) kommen nicht an, die Fallbacks des
 *    Stilblatts greifen (dunkle Karte, weisse Schrift, Akzent #00cdab);
 *  - wohlgeformtes XML: XHTML-Namensraum, geschlossene Void-Elemente, `&#160;`
 *    statt `&nbsp;`, das CSS in CDATA;
 *  - nichts scrollt: der Inhalt muss in die Flaeche passen — die senkrechte
 *    Ansicht bekommt deshalb eine kurze Fahrt und ein hoeheres Bild.
 *
 * Fremd ist alles aus der Farbtabelle: Ansicht und Stil sind Weisslisten, der
 * Steig ein Bool-Filter, Farben laufen durch `TransitCalc::VmAussehen` und eine
 * Regex, Symbolnamen durch die Weissliste von `TransitIcons`. Die Namen der
 * Tabelle werden nicht gezeichnet. Die Beispieltexte kommen uebersetzt vom
 * Modul und werden XML-escaped.
 *
 * Ohne Symcon-Aufrufe; prueftbar mit `php SymDoVRRTransit/tests/TransitVorschauTest.php`.
 */
final class TransitVorschau
{
    /* 630 — anderthalbfache Kachelbreite (Wunsch des Nutzers, 18.09.2026): so
       zeigt die Zeitachse ihre Namen ungekuerzt, und die Vorschau nutzt die
       Breite des Formulars, statt in einer Ecke zu stehen. */
    public const BREITE = 630;
    /** Abfahrten, Balken, Zeitachse. */
    public const HOEHE = 250;
    /** Die ausfuehrliche Zeitachse: 40 % mehr als die Grundhoehe (Wunsch des Nutzers). */
    public const HOEHE_ZEITACHSE = 350;
    /** Die senkrechte Ansicht reiht Halte — sie braucht Hoehe statt Breite. */
    public const HOEHE_SENKRECHT = 380;

    public const ANSICHTEN = ['departures', 'routes'];
    public const STILE     = ['bars', 'timeline', 'vertical'];

    private const GRAU = '#808080';

    /**
     * Rohwerte aus Formular oder Konfiguration in geprüfte Einstellungen.
     *
     * @param array<string,mixed> $roh view|style|platform|modes
     * @return array{view:string,style:string,steig:bool,farben:array<string,string>,icons:array<string,string>}
     */
    public static function Einstellungen(array $roh): array
    {
        $view  = (string)($roh['view'] ?? '');
        $style = (string)($roh['style'] ?? '');
        $steig = filter_var($roh['platform'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        /* Die Farbe darf nur eine Zahl sein: `(int)` auf einen fremden String
           ergaebe 0 = Schwarz und saehe wie eine Wahl aus; -1 heisst Vorgabe. */
        $zeilen = [];
        foreach ((array)($roh['modes'] ?? []) as $z) {
            if (!is_array($z)) {
                continue;
            }
            $farbe = $z['color'] ?? -1;
            if (!is_int($farbe) && !(is_string($farbe) && ctype_digit($farbe))) {
                $farbe = -1;
            }
            $zeilen[] = ['key' => (string)($z['key'] ?? ''), 'color' => (int)$farbe,
                         'icon' => (string)($z['icon'] ?? '')];
        }
        $farben = [];
        $icons  = [];
        foreach (TransitCalc::VmAussehen($zeilen) as $key => $aussehen) {
            $farben[$key] = self::Farbe((string)$aussehen['color']);
            $icons[$key]  = (string)$aussehen['icon'];   // TransitIcons prueft den Namen
        }

        return [
            'view'   => in_array($view, self::ANSICHTEN, true) ? $view : 'departures',
            'style'  => in_array($style, self::STILE, true) ? $style : 'bars',
            'steig'  => $steig ?? true,
            'farben' => $farben,
            'icons'  => $icons,
        ];
    }

    /**
     * Die Beispieltexte, die das Modul uebersetzt mitgibt — mit englischem
     * Rueckfall, damit der Renderer auch ohne Woerterbuch etwas zeigt.
     *
     * @return array<string,string>
     */
    public static function Vorgabetexte(): array
    {
        return [
            'Departures' => 'Departures', 'Routes' => 'Routes', 'min' => 'min', 'Pl.' => 'Pl.',
            'on foot' => 'on foot', 'change' => 'change', 'Departure' => 'Departure',
            'Arrival' => 'Arrival', 'in total' => 'in total', 'stops' => 'stops',
            'Total time' => 'Total time', 'Changes' => 'Changes', 'Occupancy' => 'Occupancy',
            'plenty of room' => 'plenty of room', 'now' => 'now', 'in %s' => 'in %s',
            'towards %s' => 'towards %s', '%d changes' => '%d changes',
            // Die Beispieldaten selbst.
            'Main station' => 'Main station', 'City hall' => 'City hall', 'School' => 'School',
            'Airport' => 'Airport', 'Old town' => 'Old town', 'Park' => 'Park',
            'Way to school' => 'Way to school',
        ];
    }

    /** @param array<string,string> $texte */
    public static function DataUri(array $e, string $css, array $texte = []): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::Svg($e, $css, $texte));
    }

    /**
     * Das ganze Bild.
     *
     * @param array{view:string,style:string,steig:bool,farben:array<string,string>,icons:array<string,string>} $e
     * @param string               $css   das <style> der Kachel (Inhalt ohne Tag), darf leer sein
     * @param array<string,string> $texte uebersetzte Beispieltexte (siehe Vorgabetexte)
     */
    public static function Svg(array $e, string $css, array $texte = []): string
    {
        $t = $texte + self::Vorgabetexte();
        $hoehe = self::HOEHE;
        if ($e['view'] === 'routes') {
            $hoehe = match ($e['style']) {
                'vertical' => self::HOEHE_SENKRECHT,
                'timeline' => self::HOEHE_ZEITACHSE,
                default    => self::HOEHE,
            };
        }

        $inhalt = $e['view'] === 'departures'
            ? self::Abfahrten($e, $t)
            : match ($e['style']) {
                'timeline' => self::Zeitachse($e, $t),
                'vertical' => self::Senkrecht($e, $t),
                default    => self::Balken($e, $t),
            };

        $wurzel = '<div id="wurzel">' . self::Kopf($e, $t)
            . '<div class="liste">' . $inhalt . '</div></div>';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::BREITE . ' ' . $hoehe . '"'
            . ' width="' . self::BREITE . '" height="' . $hoehe . '">'
            . '<foreignObject width="100%" height="100%">'
            . '<div xmlns="http://www.w3.org/1999/xhtml" class="vorschau">'
            . '<style><![CDATA[' . self::Css($css, $hoehe) . ']]></style>'
            . $wurzel
            . '</div></foreignObject></svg>';
    }

    // ─────────────────────────────── CSS ───────────────────────────────

    /**
     * Das Stilblatt der Kachel, verschlankt, plus das Wenige, das die Vorschau
     * anders braucht: `html`/`body` gibt es im Fremdobjekt nicht — deren Rolle
     * (Flexrahmen, Schrift, Farbe) uebernimmt `.vorschau`; die Raender, die die
     * Kachel aus der Adresszeile liest, sind hier ein festes Polster; und die
     * Symbole brauchen die Grundregel, die sonst Font Awesomes eigenes CSS
     * mitbringt.
     */
    private static function Css(string $css, int $hoehe): string
    {
        $css = (string)preg_replace('#/\*.*?\*/#s', '', $css);
        $css = (string)preg_replace('/\s+/', ' ', $css);
        // Das Ende einer CDATA-Sektion darf im CSS nicht vorkommen.
        $css = str_replace(']]>', ']]]]><![CDATA[>', $css);
        /* Die drei Variablen der Visualisierung VORWEG, mit den Werten der
           Fallbacks im Stilblatt: `--zeile`, `--dezent` und `--rand` mischen
           sie per color-mix OHNE Fallback — in einem eigenen Dokument sind sie
           sonst undefiniert, die Mischung ungueltig, und die Karte hat keinen
           Kasten (im Prueflauf gesehen: alle Abfahrten schwebten frei). */
        $visu = ':root{--card-color:#2b2c30;--content-color:#ffffff;--accent-color:#00cdab}';
        $eigen = '.vorschau{width:' . self::BREITE . 'px;height:' . $hoehe . 'px;box-sizing:border-box;'
            . 'overflow:hidden;display:flex;flex-direction:column;padding:10px;'
            . 'background:#2b2c30;color:var(--text,#fff);'
            . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13px}'
            . '.vorschau #wurzel{padding:0}'
            . '.vorschau svg.svg-inline--fa{height:1em;width:1.25em;overflow:visible;vertical-align:-0.125em}';
        return $visu . trim($css) . $eigen;
    }

    // ─────────────────────────────── Kopf ──────────────────────────────

    /** Der Umschalter — wie `kopfHtml()`, wenn es Haltestellen UND Strecken gibt. */
    private static function Kopf(array $e, array $t): string
    {
        $abfahrten = $e['view'] === 'departures';
        return '<div class="kopf"><div class="umschalter">'
            . '<button data-ansicht="departures" class="' . ($abfahrten ? 'an' : '') . '">' . self::Text($t['Departures']) . '</button>'
            . '<button data-ansicht="routes" class="' . ($abfahrten ? '' : 'an') . '">' . self::Text($t['Routes']) . '</button>'
            . '</div></div>';
    }

    // ───────────────────────────── Abfahrten ─────────────────────────────

    /** Eine Haltestelle mit drei Abfahrten — wie `abfahrtenHtml()`. */
    private static function Abfahrten(array $e, array $t): string
    {
        $zeilen = [
            ['key' => 'tram',  'linie' => '708',  'ziel' => $t['City hall'], 'min' => 4,  'spaet' => 0, 'steig' => '2'],
            ['key' => 'ubahn', 'linie' => 'U79',  'ziel' => $t['Airport'],   'min' => 7,  'spaet' => 3, 'steig' => '1'],
            ['key' => 'bus',   'linie' => 'SB50', 'ziel' => $t['Park'],      'min' => 12, 'spaet' => 0, 'steig' => ''],
        ];
        $html = '';
        foreach ($zeilen as $z) {
            $html .= '<div class="abfahrt">'
                . '<span class="sym" style="background:' . self::Modus($e, $z['key']) . '">'
                . self::Symbol($e, $z['key']) . '</span>'
                . '<span class="linie">' . self::Text($z['linie']) . '</span>'
                . '<span class="ziel"><span class="pfeil">' . TransitIcons::Svg('arrow-right') . '</span>'
                . self::Text($z['ziel']) . '</span>'
                . '<span class="wann">' . $z['min'] . '&#160;' . self::Text($t['min']) . '</span>'
                . '<span class="spaet">' . ($z['spaet'] > 0 ? '+' . $z['spaet'] : '') . '</span>'
                . ($e['steig']
                    ? '<span class="steig">' . ($z['steig'] !== '' ? self::Text($t['Pl.']) . ' ' . $z['steig'] : '') . '</span>'
                    : '')
                . '</div>';
        }
        return '<div class="karte"><div class="titel"><span>' . self::Text($t['Main station']) . '</span></div>'
            . '<div class="abfahrten' . ($e['steig'] ? '' : ' ohne-steig') . '">' . $html . '</div></div>';
    }

    // ────────────────────────────── Strecken ──────────────────────────────

    /**
     * Die Beispielstrecke: zu Fuss, Strassenbahn, Umstieg, Bus, zu Fuss — und
     * eine zweite Fahrt ohne Umstieg. Sekunden wie in der Nutzlast.
     *
     * @return list<array<string,mixed>>
     */
    private static function Fahrten(array $t): array
    {
        return [
            [
                'ab' => '07:12', 'an' => '07:41', 'sek' => 29 * 60, 'um' => 1,
                'legs' => [
                    ['kind' => 'walk', 'sek' => 4 * 60],
                    ['kind' => 'ride', 'sek' => 11 * 60, 'key' => 'tram', 'linie' => '708',
                     'von' => $t['Main station'], 'nach' => $t['Old town'], 'ab' => '07:16', 'an' => '07:27',
                     'steig' => '2', 'steigNach' => '', 'halte' => 6, 'richtung' => $t['City hall']],
                    ['kind' => 'wait', 'sek' => 3 * 60],
                    ['kind' => 'ride', 'sek' => 9 * 60, 'key' => 'bus', 'linie' => 'SB50',
                     'von' => $t['Old town'], 'nach' => $t['School'], 'ab' => '07:30', 'an' => '07:39',
                     'steig' => '', 'steigNach' => '1', 'halte' => 4, 'richtung' => $t['Park']],
                    ['kind' => 'walk', 'sek' => 2 * 60],
                ],
            ],
            [
                'ab' => '07:20', 'an' => '07:46', 'sek' => 26 * 60, 'um' => 0,
                'legs' => [
                    ['kind' => 'walk', 'sek' => 5 * 60],
                    ['kind' => 'ride', 'sek' => 21 * 60, 'key' => 'ubahn', 'linie' => 'U79',
                     'von' => $t['Main station'], 'nach' => $t['School'], 'ab' => '07:25', 'an' => '07:46',
                     'steig' => '1', 'steigNach' => '', 'halte' => 9, 'richtung' => $t['Airport']],
                ],
            ],
        ];
    }

    /** Die kompakte Ansicht — wie `streckenHtml()` + `balkenHtml()`. */
    private static function Balken(array $e, array $t): string
    {
        $html = '';
        foreach (self::Fahrten($t) as $f) {
            $stuecke = '';
            foreach ($f['legs'] as $l) {
                $min = (int)round($l['sek'] / 60) . ' ' . $t['min'];
                $stil = [];
                if ($l['kind'] === 'ride') {
                    $stil[] = 'flex-grow:' . max(1, (int)$l['sek']);
                }
                if ($l['kind'] !== 'wait') {
                    $stil[] = 'background:' . self::Modus($e, $l['kind'] === 'walk' ? 'fuss' : (string)$l['key']);
                }
                $text = $l['kind'] === 'ride'
                    ? '<span class="linie">' . self::Text((string)$l['linie']) . '</span>'
                      . '<span class="zeit">· ' . self::Text($min) . '</span>'
                    : '<span>' . self::Text($min) . '</span>';
                $symbol = $l['kind'] === 'wait' ? '' : self::Symbol($e, $l['kind'] === 'walk' ? 'fuss' : (string)$l['key']);
                $stuecke .= '<div class="stueck ' . $l['kind'] . '"'
                    . ($stil !== [] ? ' style="' . implode(';', $stil) . '"' : '') . '>'
                    . $symbol . $text . '</div>';
            }
            $erste = $f['legs'][1];
            $letzte = $f['legs'][count($f['legs']) - (count($f['legs']) > 2 ? 2 : 1)];
            $html .= '<div class="fahrt">'
                . '<div class="zeilen"><span class="stark">' . $f['ab'] . '</span><span class="grund">→</span>'
                . '<span class="stark">' . $f['an'] . '</span>'
                . '<span class="grund">' . (int)round($f['sek'] / 60) . ' ' . self::Text($t['min'])
                . ' · ' . self::Text(sprintf($t['%d changes'], (int)$f['um'])) . '</span></div>'
                . '<div class="balken">' . $stuecke . '</div>'
                . '<div class="fuss"><span>' . self::Text((string)$erste['von']) . '</span>'
                . '<span>' . self::Text((string)$letzte['nach']) . '</span></div>'
                . '</div>';
        }
        return '<div class="karte"><div class="titel"><span>' . self::Text($t['Way to school']) . '</span></div>'
            . '<div class="fahrten">' . $html . '</div>'
            . '<div class="punkte"><span class="an"></span><span></span></div></div>';
    }

    /** Die ausfuehrliche Zeitachse der ersten Fahrt — wie `zeitachseHtml()`. */
    private static function Zeitachse(array $e, array $t): string
    {
        $f = self::Fahrten($t)[0];
        $legs = $f['legs'];
        $fuss = self::Modus($e, 'fuss');
        $min = static fn(int $sek): string => (int)round($sek / 60) . ' ' . $t['min'];
        $laufen = TransitIcons::Svg('person-walking');

        // Teile wie im Skript: weg, halt, fahrt, um, fahrt, halt, weg.
        $teile = [
            ['typ' => 'weg', 's' => $legs[0]['sek']],
            ['typ' => 'halt', 'name' => $legs[1]['von'], 'steig' => $legs[1]['steig'], 'l' => $fuss, 'r' => self::Modus($e, 'tram')],
            ['typ' => 'fahrt', 'leg' => $legs[1]],
            ['typ' => 'um', 's' => $legs[2]['sek'], 'name' => $legs[1]['nach'], 'zeit' => $legs[1]['an']],
            ['typ' => 'fahrt', 'leg' => $legs[3]],
            ['typ' => 'halt', 'name' => $legs[3]['nach'], 'steig' => $legs[3]['steigNach'], 'l' => self::Modus($e, 'bus'), 'r' => $fuss],
            ['typ' => 'weg', 's' => $legs[4]['sek']],
        ];
        $spalten = [];
        $zellen = '';
        foreach ($teile as $teil) {
            $klasse = '';
            $oben = $mitte = $unten = $stilM = '';
            switch ($teil['typ']) {
                case 'halt':
                    $klasse = 'tl-halt';
                    $stilM = ' style="--l:' . $teil['l'] . ';--r:' . $teil['r'] . '"';
                    $spalten[] = 'minmax(0, max-content)';
                    $mitte = '<div class="pkt"></div>';
                    $unten = '<b>' . self::Text((string)$teil['name']) . '</b>'
                        . ($teil['steig'] !== '' && $e['steig']
                            ? '<span>' . self::Text($t['Pl.']) . ' ' . self::Text((string)$teil['steig']) . '</span>' : '');
                    break;
                case 'fahrt':
                    $l = $teil['leg'];
                    $farbe = self::Modus($e, (string)$l['key']);
                    $klasse = 'tl-fahrt';
                    $stilM = ' style="--f:' . $farbe . '"';
                    $spalten[] = 'minmax(min-content, ' . max(1, (int)$l['sek']) . 'fr)';
                    $oben = '<span class="marke" style="background:' . $farbe . '">' . self::Symbol($e, (string)$l['key'])
                        . '<b>' . self::Text((string)$l['linie']) . '</b></span>';
                    $mitte = '<div class="bahn" style="background:' . $farbe . '"></div>';
                    $unten = '<b>' . self::Text($min((int)$l['sek'])) . '</b>'
                        . '<span>' . (int)$l['halte'] . ' ' . self::Text($t['stops']) . '</span>';
                    break;
                case 'um':
                    $klasse = 'tl-um';
                    $spalten[] = 'minmax(0, max-content)';
                    $oben = '<em>' . self::Text((string)$teil['zeit']) . '</em><span>' . self::Text((string)$teil['name']) . '</span>';
                    $mitte = '<div class="pkt"></div><div class="bahn"></div><div class="pkt"></div>';
                    $unten = '<b>' . $laufen . ' ' . self::Text($min((int)$teil['s'])) . '</b><span>' . self::Text($t['change']) . '</span>';
                    break;
                default:
                    $klasse = 'tl-weg';
                    $spalten[] = 'min-content';
                    $mitte = '<div class="bahn"></div>';
                    $unten = '<b>' . $laufen . ' ' . self::Text($min((int)$teil['s'])) . '</b><span>' . self::Text($t['on foot']) . '</span>';
            }
            $zellen .= '<div class="o ' . $klasse . '">' . $oben . '</div>'
                . '<div class="m ' . $klasse . '"' . $stilM . '>' . $mitte . '</div>'
                . '<div class="u ' . $klasse . '">' . $unten . '</div>';
        }
        $tl = '<div class="tl"><div class="tl-kopf">'
            . '<div class="seite"><div class="was">' . self::Text($t['Departure']) . '</div><div class="uhr">' . $f['ab'] . '</div>'
            . '<div class="gleich">' . self::Text(sprintf($t['in %s'], '9 ' . $t['min'])) . '</div></div>'
            . '<div class="seite rechts"><div class="was">' . self::Text($t['Arrival']) . '</div><div class="uhr">' . $f['an'] . '</div>'
            . '<div class="gesamt">' . self::Text($min((int)$f['sek']) . ' ' . $t['in total']) . '</div></div>'
            . '</div>'
            . '<div class="tl-spur" style="grid-template-columns:' . implode(' ', $spalten) . '">' . $zellen . '</div>'
            . self::Werte($f, $t)
            . '</div>';
        return '<div class="karte"><div class="titel"><span>' . self::Text($t['Way to school']) . '</span></div>'
            . '<div class="fahrten"><div class="fahrt">' . $tl . '</div></div></div>';
    }

    /**
     * Die senkrechte Ansicht der zweiten (kurzen) Fahrt — wie `senkrechtHtml()`.
     * Kurz, weil in einem Bild nichts scrollt: Fussweg, eine Fahrt, Ziel.
     */
    private static function Senkrecht(array $e, array $t): string
    {
        $f = self::Fahrten($t)[1];
        $legs = $f['legs'];
        $ride = $legs[1];
        $fuss = self::Modus($e, 'fuss');
        $farbe = self::Modus($e, (string)$ride['key']);
        $min = static fn(int $sek): string => (int)round($sek / 60) . ' ' . $t['min'];
        // Wie `laenge()`: acht Pixel je Minute, hoechstens 280, abzueglich der Nachbarhalte.
        $laenge = static fn(int $sek, int $abzug): int => max(0, min(280, (int)round($sek / 60 * 8)) - $abzug);
        $zeile = static function (string $klasse, string $oben, string $unten, string $inhalt, string $knick, bool $punkt, int $dauer): string {
            $muster = ($oben === 'punkte' ? ' o-punkte' : '') . ($unten === 'punkte' ? ' u-punkte' : '');
            return '<div class="vt-zeile ' . $klasse . $muster . '"' . ($dauer > 0 ? ' style="min-height:' . $dauer . 'px"' : '') . '>'
                . '<div class="vt-schiene" style="--o:' . ($oben === 'punkte' ? 'transparent' : $oben)
                . ';--u:' . ($unten === 'punkte' ? 'transparent' : $unten) . ';--knick:' . $knick . '">'
                . ($punkt ? '<div class="pkt"></div>' : '') . '</div>'
                . '<div class="vt-inhalt">' . $inhalt . '</div></div>';
        };
        $laufen = TransitIcons::Svg('person-walking');
        $steig = static fn(string $s) => $s !== '' && $e['steig']
            ? '<div class="steig">' . self::Text($t['Pl.']) . ' ' . self::Text($s) . '</div>' : '';

        $html = $zeile('weg', $fuss, $fuss,
            '<b>' . $laufen . ' ' . self::Text($min((int)$legs[0]['sek'])) . '</b> ' . self::Text($t['on foot']),
            '0px', false, $laenge((int)$legs[0]['sek'], 9));
        $html .= $zeile('vt-halt', $fuss, $farbe,
            '<div class="zeit">' . $ride['ab'] . '</div><div class="name">' . self::Text((string)$ride['von']) . '</div>' . $steig((string)$ride['steig']),
            '9px', true, 0);
        $html .= $zeile('vt-fahrt', $farbe, $farbe,
            '<div class="kopf"><span class="marke" style="background:' . $farbe . '">' . self::Symbol($e, (string)$ride['key'])
            . '<b>' . self::Text((string)$ride['linie']) . '</b></span>'
            . '<span class="richtung">' . self::Text(sprintf($t['towards %s'], (string)$ride['richtung'])) . '</span></div>'
            . '<div class="vt-knopf" style="cursor:default"><span class="zahl">' . self::Text($min((int)$ride['sek'])) . '</span>'
            . '<span>· ' . (int)$ride['halte'] . ' ' . self::Text($t['stops']) . '</span>'
            . '<span class="pfeil">' . TransitIcons::Svg('chevron-down') . '</span></div>',
            '0px', false, $laenge((int)$ride['sek'], 9 + 13));
        $html .= $zeile('vt-halt', $farbe, 'transparent',
            '<div class="zeit">' . $ride['an'] . '</div><div class="name">' . self::Text((string)$ride['nach']) . '</div>' . $steig((string)$ride['steigNach']),
            '9px', true, 0);

        return '<div class="karte"><div class="titel"><span>' . self::Text($t['Way to school']) . '</span></div>'
            . '<div class="vt-stapel"><div class="fahrt"><div class="vt">' . $html . '</div>' . self::Werte($f, $t) . '</div></div></div>';
    }

    /** Die Kennzahlen unter einer Verbindung — wie `werteHtml()`. */
    private static function Werte(array $f, array $t): string
    {
        $wert = static fn(string $was, string $zahl, string $zeichen): string =>
            '<div class="tl-wert"><div class="sinn">' . TransitIcons::Svg($zeichen) . '</div>'
            . '<div class="text"><div class="was">' . self::Text($was) . '</div><div class="zahl">' . self::Text($zahl) . '</div></div></div>';
        return '<div class="tl-werte">'
            . $wert($t['Total time'], (int)round($f['sek'] / 60) . ' ' . $t['min'], 'stopwatch')
            . $wert($t['Changes'], sprintf($t['%d changes'], (int)$f['um']), 'arrow-right-arrow-left')
            . $wert($t['Occupancy'], $t['plenty of room'], 'users')
            . '</div>';
    }

    // ────────────────────────────── Helfer ──────────────────────────────

    /** Die Farbe eines Verkehrsmittels — geprueft, sonst Grau. */
    private static function Modus(array $e, string $key): string
    {
        return self::Farbe((string)($e['farben'][$key] ?? self::GRAU));
    }

    /** Das Symbol eines Verkehrsmittels — TransitIcons prueft den Namen. */
    private static function Symbol(array $e, string $key): string
    {
        return TransitIcons::Svg((string)($e['icons'][$key] ?? TransitIcons::RUECKFALL));
    }

    /** Letzter Riegel vor dem Markup: nur `#RRGGBB`, sonst Grau. */
    private static function Farbe(string $hex): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1 ? strtoupper($hex) : self::GRAU;
    }

    private static function Text(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
