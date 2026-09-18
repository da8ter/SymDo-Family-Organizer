<?php

declare(strict_types=1);

require_once __DIR__ . '/TransitCalc.php';

/**
 * Die schematische Vorschau der Kachel — als SVG fuers Konfigurationsformular.
 *
 * Wer die Darstellung einstellt (Ansicht beim Oeffnen, Streckendarstellung,
 * Steig-Spalte, Farben der Verkehrsmittel), sah das Ergebnis bisher erst nach
 * „Uebernehmen" in der Visualisierung. Dieses Bild zeigt es sofort im Formular:
 * das Formularelement `Image` nimmt eine Data-URI, und `UpdateFormField` tauscht
 * sie zur Laufzeit — dasselbe Muster wie der Pairing-QR des Gateways.
 *
 * Es ist ein SCHEMA, kein Abbild: graue Platzhalter fuer Text, die Farben der
 * Verkehrsmittel als einzige Akzente. Kein Hintergrund und nur Grau mit
 * Deckkraft, damit es auf heller wie dunkler Konsole lesbar bleibt. Keine
 * Woerter — die muessten uebersetzt werden, und der Renderer kennt kein
 * Translate. Keine Symbole — die FontAwesome-Namen aus der Tabelle gibt es
 * im SVG nicht.
 *
 * Alles, was aus dem Formular kommt, gilt als fremd: Ansicht und Stil sind
 * Weisslisten, die Steig-Angabe ein Bool-Filter, die Farben laufen durch
 * `TransitCalc::VmAussehen` (Weissliste der Schluessel, Vorgabe bei -1,
 * Maske auf 24 Bit) und vor dem Einsetzen noch einmal durch eine Regex. Namen
 * und Symbole werden gar nicht gezeichnet. So gelangt kein Nutzerstring in
 * das Markup — ein `"><script>` als Farbe wird zur Vorgabefarbe.
 *
 * Ohne Symcon-Aufrufe, prueftbar mit `php SymDoVRRTransit/tests/TransitSchemaTest.php`.
 */
final class TransitSchema
{
    public const BREITE = 320;
    public const HOEHE  = 200;
    public const ANSICHTEN = ['departures', 'routes'];
    public const STILE     = ['bars', 'timeline', 'vertical'];

    /** Themenneutral: Helligkeit kommt ueber die Deckkraft, nicht ueber den Ton. */
    private const GRAU = '#808080';

    /**
     * Rohwerte aus Formular oder Konfiguration in geprüfte Einstellungen.
     *
     * @param array<string,mixed> $roh view|style|platform|modes
     * @return array{view:string,style:string,steig:bool,farben:array<string,string>}
     */
    public static function Einstellungen(array $roh): array
    {
        $view  = (string)($roh['view'] ?? '');
        $style = (string)($roh['style'] ?? '');
        $steig = filter_var($roh['platform'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        /* Die Farbe darf nur eine Zahl sein. Ein `(int)` auf einen fremden
           String ergaebe 0 = Schwarz und saehe wie eine Wahl aus; -1 heisst
           „Vorgabe", und genau das soll ein unbrauchbarer Wert bedeuten. */
        $zeilen = [];
        foreach ((array)($roh['modes'] ?? []) as $z) {
            if (!is_array($z)) {
                continue;
            }
            $farbe = $z['color'] ?? -1;
            if (!is_int($farbe) && !(is_string($farbe) && ctype_digit($farbe))) {
                $farbe = -1;
            }
            $zeilen[] = ['key' => (string)($z['key'] ?? ''), 'color' => (int)$farbe];
        }
        $farben = [];
        foreach (TransitCalc::VmAussehen($zeilen) as $key => $aussehen) {
            $farben[$key] = self::Farbe((string)$aussehen['color']);
        }

        return [
            'view'   => in_array($view, self::ANSICHTEN, true) ? $view : 'departures',
            'style'  => in_array($style, self::STILE, true) ? $style : 'bars',
            'steig'  => $steig ?? true,
            'farben' => $farben,
        ];
    }

    /** @param array{view:string,style:string,steig:bool,farben:array<string,string>} $e */
    public static function DataUri(array $e): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::Svg($e));
    }

    /** @param array{view:string,style:string,steig:bool,farben:array<string,string>} $e */
    public static function Svg(array $e): string
    {
        $inhalt = $e['view'] === 'departures'
            ? self::Abfahrten($e)
            : match ($e['style']) {
                'timeline' => self::Zeitachse($e),
                'vertical' => self::Senkrecht($e),
                default    => self::Balken($e),
            };
        return self::Rahmen($e, $inhalt);
    }

    // ────────────────────────────── Rahmen ──────────────────────────────

    /**
     * Kopf und Karte, wie die Kachel sie hat: Titel, der Umschalter
     * „Abfahrten | Strecken" (die aktive Pille ist die gewaehlte Ansicht),
     * darunter die Karte mit dem Inhalt.
     *
     * `width`/`height` stehen zusaetzlich zur `viewBox`: ohne eigene Groesse
     * rendert ein `<img>` ein SVG unter Umstaenden null Pixel hoch.
     */
    private static function Rahmen(array $e, string $inhalt): string
    {
        $abfahrtenAn = $e['view'] === 'departures';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::BREITE . ' ' . self::HOEHE . '"'
            . ' width="' . self::BREITE . '" height="' . self::HOEHE . '">'
            . self::Rect(8, 10, 90, 10, self::GRAU, 0.35)
            . self::Rect(232, 8, 40, 14, self::GRAU, $abfahrtenAn ? 0.7 : 0.2, 7, $abfahrtenAn ? 'umschalter-an' : 'umschalter')
            . self::Rect(272, 8, 40, 14, self::GRAU, $abfahrtenAn ? 0.2 : 0.7, 7, $abfahrtenAn ? 'umschalter' : 'umschalter-an')
            . self::Rect(8, 30, 304, 162, self::GRAU, 0.12, 10, 'karte')
            . $inhalt
            . '</svg>';
    }

    // ───────────────────────────── Abfahrten ─────────────────────────────

    /**
     * Die Abfahrtstafel: vier Zeilen aus Symbol, Linie, Ziel, Zeit —
     * und der Steig-Spalte, wenn sie eingeschaltet ist. Ohne sie wachsen Ziel
     * und Zeit nach rechts, wie `.abfahrten.ohne-steig` in der Kachel.
     */
    private static function Abfahrten(array $e): string
    {
        $raus = self::Rect(20, 38, 70, 8, self::GRAU, 0.5);   // Name der Haltestelle
        $modi = ['sbahn', 'ubahn', 'tram', 'bus'];
        foreach ($modi as $i => $key) {
            $y = 54 + $i * 34;
            $zeile = self::Kreis(26, $y + 9, 8, self::Farbe($e['farben'][$key] ?? self::GRAU), 1.0, 'sym')
                . self::Rect(42, $y + 3, 26, 12, self::GRAU, 0.5)
                . self::Rect(76, $y + 4, $e['steig'] ? 128 : 160, 10, self::GRAU, 0.3)
                . self::Rect($e['steig'] ? 216 : 248, $y + 3, 28, 12, self::GRAU, 0.5);
            if ($i === 1) {
                // Eine Verspaetung als kleines Kaestchen neben der Zeit.
                $zeile .= self::Rect($e['steig'] ? 248 : 280, $y + 5, 14, 8, self::GRAU, 0.35);
            }
            if ($e['steig']) {
                $zeile .= self::Rect(276, $y + 2, 28, 14, self::GRAU, 0.25, 3, 'steig');
            }
            if ($i > 0) {
                $zeile .= '<line x1="20" y1="' . ($y - 8) . '" x2="300" y2="' . ($y - 8)
                    . '" stroke="' . self::GRAU . '" stroke-opacity="0.25"/>';
            }
            $raus .= '<g data-teil="zeile">' . $zeile . '</g>';
        }
        return $raus;
    }

    // ────────────────────────────── Balken ──────────────────────────────

    /**
     * Die kompakte Streckenansicht: je Fahrt eine Zeile „ab → an · Dauer",
     * darunter der Balken aus Stuecken (Fuss, Fahrt, Warten, Fahrt) mit
     * Breiten wie Dauern, darunter Von und Bis.
     */
    private static function Balken(array $e): string
    {
        $raus = '';
        $fahrten = [
            // [Stuecke: [Art, Anteil, Schluessel]]
            [['fuss', 0.20, 'fuss'], ['fahrt', 0.40, 'tram'], ['warten', 0.08, ''], ['fahrt', 0.32, 'bus']],
            [['fuss', 0.15, 'fuss'], ['fahrt', 0.85, 'ubahn']],
        ];
        foreach ($fahrten as $n => $stuecke) {
            $y = 44 + $n * 66;
            $raus .= self::Rect(20, $y, 34, 9, self::GRAU, 0.4)
                . self::Rect(60, $y, 34, 9, self::GRAU, 0.4)
                . self::Rect(100, $y, 44, 9, self::GRAU, 0.4);
            $x = 20.0;
            foreach ($stuecke as [$art, $anteil, $key]) {
                $w = 280 * $anteil;
                $raus .= match ($art) {
                    'fahrt'  => self::Rect((int)round($x), $y + 18, (int)round($w), 14,
                        self::Farbe($e['farben'][$key] ?? self::GRAU), 1.0, 4, 'fahrt'),
                    'warten' => self::Rect((int)round($x), $y + 18, (int)round($w), 14, self::GRAU, 0.15, 4, 'warten'),
                    default  => self::Rect((int)round($x), $y + 18, (int)round($w), 14, self::GRAU, 0.3, 4, 'fuss'),
                };
                $x += $w;
            }
            $raus .= self::Rect(20, $y + 40, 60, 8, self::GRAU, 0.35)
                . self::Rect(240, $y + 40, 60, 8, self::GRAU, 0.35);
        }
        return $raus;
    }

    // ───────────────────────────── Zeitachse ─────────────────────────────

    /**
     * Die ausfuehrliche Zeitachse: eine Schiene aus zwei farbigen Abschnitten,
     * drei Punkte (Start, Umstieg, Ziel), Linienschilder ueber den
     * Abschnitten, Zeiten oben, Namen unten — und der Steig unter Start und
     * Umstieg, wenn er eingeschaltet ist.
     */
    private static function Zeitachse(array $e): string
    {
        $tram = self::Farbe($e['farben']['tram'] ?? self::GRAU);
        $bus  = self::Farbe($e['farben']['bus'] ?? self::GRAU);
        $raus = '<line x1="40" y1="110" x2="160" y2="110" stroke="' . $tram . '" stroke-width="4" data-teil="fahrt"/>'
            . '<line x1="160" y1="110" x2="280" y2="110" stroke="' . $bus . '" stroke-width="4" data-teil="fahrt"/>'
            . self::Rect(88, 94, 24, 12, $tram, 1.0, 3)
            . self::Rect(208, 94, 24, 12, $bus, 1.0, 3);
        foreach ([40, 160, 280] as $i => $x) {
            $raus .= self::Kreis($x, 110, 6, self::GRAU, 0.9, 'punkt')
                . self::Rect($x - 14, 70, 28, 9, self::GRAU, 0.4)
                . self::Rect($x - 20, 124, 40, 8, self::GRAU, 0.35);
            if ($e['steig'] && $i < 2) {
                $raus .= self::Rect($x - 10, 138, 20, 8, self::GRAU, 0.25, 2, 'steig');
            }
        }
        // Von und Bis am unteren Rand.
        $raus .= self::Rect(20, 172, 70, 8, self::GRAU, 0.35) . self::Rect(230, 172, 70, 8, self::GRAU, 0.35);
        return $raus;
    }

    // ───────────────────────────── Senkrecht ─────────────────────────────

    /**
     * Die senkrechte Ansicht mit Halten: oben der Masstreifen (Ankunft,
     * Dauer, Umstiege, Auslastung), darunter gestapelt Halt, Fahrt (Hoehe wie
     * Dauer), Umstieg, Fussweg, Fahrt, Ziel.
     */
    private static function Senkrecht(array $e): string
    {
        $raus = '';
        foreach ([20, 90, 160, 230] as $x) {
            $raus .= self::Rect($x, 38, 60, 12, self::GRAU, 0.25, 6, 'mass');
        }
        $halt = function (int $y) use ($e): string {
            return self::Kreis(20, $y + 4, 5, self::GRAU, 0.9, 'halt')
                . self::Rect(36, $y, 26, 9, self::GRAU, 0.5)
                . self::Rect(70, $y, 110, 9, self::GRAU, 0.35)
                . ($e['steig'] ? self::Rect(190, $y, 22, 9, self::GRAU, 0.25, 2, 'steig') : '');
        };
        $fahrt = function (int $y, int $h, string $key) use ($e): string {
            $farbe = self::Farbe($e['farben'][$key] ?? self::GRAU);
            return self::Rect(16, $y, 8, $h, $farbe, 1.0, 3, 'fahrt')
                . self::Rect(36, $y + 4, 26, 12, $farbe, 1.0, 3)
                . self::Rect(70, $y + 6, 90, 8, self::GRAU, 0.35);
        };
        $raus .= $halt(58)
            . $fahrt(72, 32, 'tram')
            . $halt(108)
            . self::Rect(16, 122, 8, 10, self::GRAU, 0.3, 3, 'fuss')
            . $fahrt(136, 24, 'bus')
            . $halt(164);
        return $raus;
    }

    // ────────────────────────────── Helfer ──────────────────────────────

    /** Letzter Riegel vor dem Markup: nur `#RRGGBB`, sonst Grau. */
    private static function Farbe(string $hex): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1 ? strtoupper($hex) : self::GRAU;
    }

    /** Fuer den Fall, dass je Beschriftungen uebergeben werden — heute ungenutzt. */
    private static function Text(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function Rect(int $x, int $y, int $w, int $h, string $fill, float $deckkraft = 1.0,
        int $rx = 3, string $teil = ''): string
    {
        return '<rect x="' . $x . '" y="' . $y . '" width="' . max(1, $w) . '" height="' . max(1, $h)
            . '" rx="' . $rx . '" fill="' . self::Farbe($fill) . '"'
            . ($deckkraft < 1.0 ? ' fill-opacity="' . $deckkraft . '"' : '')
            . ($teil !== '' ? ' data-teil="' . $teil . '"' : '') . '/>';
    }

    private static function Kreis(int $cx, int $cy, int $r, string $fill, float $deckkraft = 1.0,
        string $teil = ''): string
    {
        return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="' . self::Farbe($fill) . '"'
            . ($deckkraft < 1.0 ? ' fill-opacity="' . $deckkraft . '"' : '')
            . ($teil !== '' ? ' data-teil="' . $teil . '"' : '') . '/>';
    }
}
