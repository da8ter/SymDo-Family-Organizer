<?php

declare(strict_types=1);

/**
 * Das Rechenwerk der VRR-Auskunft — ohne Symcon, damit es unter Prüfstand steht.
 *
 * Hier passiert dreierlei:
 *
 * 1. Die WEISSE LISTE. Eine Streckenauskunft der EFA wog am 11.09.2026 247 KB,
 *    davon 176 KB `properties`, 36 KB `coords` und 21 KB `stopSequence` — Dinge,
 *    die keine Oberfläche je zeigt. Was hier nicht namentlich steht, kommt nicht
 *    durch; das ist kein Sparen, sondern die Zusage, dass in Bestand und
 *    Antwort nur steht, was gebraucht wird.
 *
 * 2. Die Zeitumrechnung. `rapidJSON` liefert UTC mit „Z" am Ende
 *    (`2026-09-11T12:21:00Z` ist 14:21 Ortszeit). Wer das übersieht, baut eine
 *    Kachel, die im Sommer zwei Stunden falsch geht.
 *
 * 3. Der Schulweg — das einzige Stück, das nicht aus der EFA kommt, sondern aus
 *    dem Stundenplan: wann muss das Kind da sein, wann ist Schluss, und in
 *    welche Richtung fährt es gerade.
 */
final class TransitCalc
{
    /**
     * Der Wert, mit dem die Auswahlliste einer Strecke auf die Karte verweist.
     * Keine Haltestellen-Kennung der EFA sieht so aus (sie beginnen mit
     * „de:"), eine Verwechslung ist also ausgeschlossen.
     */
    public const PUNKT_KARTE = 'geo';

    /**
     * Symbol je Produktklasse der EFA. Gemessen am 11.09.2026: 1 = S-Bahn,
     * 5 = Niederflurbus, 13 = Regionalzug; U-Bahn und Fußweg kamen in den
     * Streckenauskünften dazu.
     *
     * Die Zahl ist der verlässlichere Schlüssel als der Name — „Niederflurbus"
     * und „Bus" meinen dasselbe, und der Verbund darf den Text jederzeit ändern.
     */
    /**
     * Die Verkehrsmittel, die der Nutzer im Formular gestalten kann.
     *
     * Eine Zeile je Gattung, die jemand wiedererkennt — nicht je EFA-Nummer:
     * die Auskunft unterscheidet achtzehn Klassen, aber „Straßenbahn" und
     * „Stadtbahn" will niemand getrennt einfärben. `klassen` sagt, welche
     * Nummern zu einer Zeile gehören.
     *
     * Symbolnamen stehen OHNE `fa-` davor — genau so speichert es der Wähler
     * von Symcon (dieselbe Schreibweise wie in der Einkaufsliste). Farben sind
     * Ganzzahlen wie von SelectColor.
     *
     * Die Farben sind UNSERE Zuordnung: der VRR liefert keine mit (gemessen an
     * 80 Abfahrten in drei Städten — kein Feld, das nach Farbe aussieht).
     * Gewählt ist, was im deutschen Nahverkehr üblich ist.
     */
    public const VM_VORGABE = [
        ['key' => 'sbahn',  'name' => 'S-Bahn',              'icon' => 'train-subway', 'color' => 0x1A8A3C, 'klassen' => [1]],
        ['key' => 'ubahn',  'name' => 'U-Bahn',              'icon' => 'train-subway', 'color' => 0x0A63B0, 'klassen' => [2]],
        ['key' => 'tram',   'name' => 'Straßenbahn',         'icon' => 'train-tram',   'color' => 0xC1152B, 'klassen' => [3, 4]],
        ['key' => 'bus',    'name' => 'Bus',                 'icon' => 'bus',          'color' => 0x8C2F8C, 'klassen' => [5, 6, 7]],
        ['key' => 'ruf',    'name' => 'Rufbus und Sammeltaxi','icon' => 'taxi',        'color' => 0x7A3E9D, 'klassen' => [10, 11, 19]],
        ['key' => 'regio',  'name' => 'Regionalzug',         'icon' => 'train',        'color' => 0x4F5B66, 'klassen' => [13, 14]],
        ['key' => 'fern',   'name' => 'Fernzug',             'icon' => 'train',        'color' => 0x9C1620, 'klassen' => [15, 16, 17, 18]],
        ['key' => 'faehre', 'name' => 'Fähre',               'icon' => 'ship',         'color' => 0x127A8A, 'klassen' => [9]],
        ['key' => 'seil',   'name' => 'Seilbahn',            'icon' => 'cable-car',    'color' => 0x1E7A5A, 'klassen' => [8]],
        ['key' => 'sonst',  'name' => 'Sonstige',            'icon' => 'route',        'color' => 0x6E7781, 'klassen' => [0]],
        /* Der Fussweg ist kein Verkehrsmittel — aber er wird gezeichnet, und
           deshalb darf man ihm auch Farbe und Symbol geben. Seine Klassen sind
           99 (die Wege, die das Modul selbst einsetzt) und 100 (die Fusspfade
           der Auskunft). */
        ['key' => 'fuss',   'name' => 'Fußweg',              'icon' => 'person-walking', 'color' => 0x5E8C80, 'klassen' => [99, 100]],
    ];

    /**
     * Welche Zeile der Tabelle gilt für diese EFA-Klasse?
     *
     * Was nirgends steht, landet bei „Sonstige" — lieber einheitlich grau als
     * bunt geraten.
     */
    public static function VmSchluessel(int $klasse): string
    {
        foreach (self::VM_VORGABE as $zeile) {
            if (in_array($klasse, $zeile['klassen'], true)) {
                return $zeile['key'];
            }
        }
        return 'sonst';
    }

    /**
     * Die Gestaltungstabelle aus dem Formular in eine Nachschlagetabelle.
     *
     * Was der Nutzer nicht gesetzt hat, kommt aus der Vorgabe — und eine Zeile,
     * die er gelöscht hat, ebenfalls: das Aussehen darf nie leer sein, sonst
     * stünde in der Kachel eine Fahrt ohne Farbe und ohne Symbol.
     *
     * @param list<array<string,mixed>> $zeilen
     * @return array<string,array{icon:string,color:string}>
     */
    public static function VmAussehen(array $zeilen): array
    {
        $raus = [];
        foreach (self::VM_VORGABE as $vorgabe) {
            $raus[$vorgabe['key']] = [
                'icon'  => 'fa-' . $vorgabe['icon'],
                'color' => sprintf('#%06X', $vorgabe['color'] & 0xFFFFFF),
            ];
        }
        foreach ($zeilen as $z) {
            $key = (string)($z['key'] ?? '');
            if ($key === '' || !isset($raus[$key])) {
                continue;
            }
            $icon = trim((string)($z['icon'] ?? ''));
            if ($icon !== '') {
                $raus[$key]['icon'] = str_starts_with($icon, 'fa-') ? $icon : 'fa-' . $icon;
            }
            /* -1 ist der Wert von SelectColor fuer „keine Farbe" — dann bleibt
               es bei der Vorgabe statt bei Schwarz. */
            $farbe = (int)($z['color'] ?? -1);
            if ($farbe >= 0) {
                $raus[$key]['color'] = sprintf('#%06X', $farbe & 0xFFFFFF);
            }
        }
        return $raus;
    }

    /**
     * Farbe und Symbol an jeden Fahrabschnitt schreiben.
     *
     * Gilt für Abfahrten wie für Verbindungsabschnitte — beide tragen `class`.
     * Fußwege und Wartezeiten bleiben unangetastet: sie sind kein
     * Verkehrsmittel, und ihr Symbol steht schon fest.
     *
     * @param list<array<string,mixed>>                      $eintraege
     * @param array<string,array{icon:string,color:string}>  $aussehen
     * @return list<array<string,mixed>>
     */
    public static function VmAnmalen(array $eintraege, array $aussehen): array
    {
        foreach ($eintraege as $i => $e) {
            $art = is_array($e) ? (string)($e['kind'] ?? 'ride') : '';
            /* Fahrten und Fusswege bekommen ihr Aussehen, Wartezeiten nicht:
               Warten ist kein Weg, es hat kein Symbol und keine Farbe. */
            if ($art !== 'ride' && $art !== 'walk') {
                continue;
            }
            $stil = $aussehen[self::VmSchluessel((int)($e['class'] ?? -1))] ?? null;
            if ($stil === null) {
                continue;
            }
            $eintraege[$i]['icon']  = $stil['icon'];
            $eintraege[$i]['color'] = $stil['color'];
        }
        return $eintraege;
    }

    /**
     * Dasselbe fuer ganze Verbindungen — sie tragen ihre Abschnitte verschachtelt.
     *
     * @param list<array<string,mixed>>                      $verbindungen
     * @param array<string,array{icon:string,color:string}>  $aussehen
     * @return list<array<string,mixed>>
     */
    public static function VmAnmalenVerbindungen(array $verbindungen, array $aussehen): array
    {
        foreach ($verbindungen as $i => $v) {
            if (is_array($v) && is_array($v['legs'] ?? null)) {
                $verbindungen[$i]['legs'] = self::VmAnmalen($v['legs'], $aussehen);
            }
        }
        return $verbindungen;
    }

    private const SYMBOL_JE_KLASSE = [
        0  => 'fa-train',            // Zug (Fern)
        1  => 'fa-train-subway',     // S-Bahn
        2  => 'fa-train-subway',     // U-Bahn
        3  => 'fa-train-tram',       // Stadtbahn
        4  => 'fa-train-tram',       // Straßenbahn
        5  => 'fa-bus',              // Bus
        6  => 'fa-bus',              // Schnellbus
        7  => 'fa-bus',              // Bus
        8  => 'fa-cable-car',        // Seilbahn
        9  => 'fa-ship',             // Schiff
        10 => 'fa-taxi',             // Anruf-Sammeltaxi
        13 => 'fa-train',            // Regionalzug
        14 => 'fa-train',            // Regional-Express
        15 => 'fa-train',            // Fernzug
        16 => 'fa-train',            // Hochgeschwindigkeit
        17 => 'fa-train',            // Sonderzug
        99 => 'fa-person-walking',   // Fußweg
    ];

    /** Rückfall über den Produktnamen, wenn die Klasse unbekannt ist. */
    private const SYMBOL_JE_NAME = [
        'footpath' => 'fa-person-walking',
        'fussweg'  => 'fa-person-walking',
        's-bahn'   => 'fa-train-subway',
        'u-bahn'   => 'fa-train-subway',
        'bus'      => 'fa-bus',
        'tram'     => 'fa-train-tram',
    ];

    /**
     * Wie lange nach der planmäßigen Abfahrt des Rückwegs er noch gilt.
     *
     * Danach ist der Schultag durch und es gilt der Hinweg des nächsten Tages.
     * Drei Stunden, weil ein Kind auch mal später losfährt — und weil die
     * Alternative, die tatsächliche Ankunft abzuwarten, eine Auskunft bräuchte,
     * die es zum Zeitpunkt der Entscheidung noch nicht gibt.
     */
    public const RUECKWEG_NACHLAUF_S = 3 * 3600;

    /**
     * Wie lange eine Abfahrt nach ihrer Zeit noch angezeigt wird.
     *
     * Die EFA liefert von sich aus Abfahrten, die schon weg sind — am
     * 11.09.2026 um 17:02 stand eine von 16:50 in der Antwort, und sortiert war
     * die Liste auch nicht (17:09, 17:02, 16:50, 17:54 …). Eine Tafel, die
     * einen abgefahrenen Zug zeigt, ist schlimmer als eine kurze. Eine Minute
     * Nachsicht bleibt: der Bus „um jetzt" soll noch dastehen.
     */
    private const VERGANGEN_S = 60;

    /**
     * Wie viele bereits verpasste Abfahrten oben stehen bleiben.
     *
     * Mit gepflegtem Fußweg sind die nächsten Abfahrten oft schon nicht mehr zu
     * schaffen — an einem Hauptbahnhof mit fünf Minuten Weg waren es sechs von
     * sechs. Sie ganz wegzulassen wäre falsch („der wäre gegangen" ist auch
     * eine Auskunft), sie alle zu zeigen auch: dann steht auf der Tafel nichts,
     * was man noch erreicht. Zwei bleiben, der Rest macht Platz.
     */
    private const VERPASST_MAX = 2;

    /** Ab wann eine Lücke zwischen zwei Abschnitten als Wartezeit gilt. */
    private const WARTEN_MIN_S = 60;

    /**
     * Abfahrten einer Haltestelle aus einer `XML_DM_REQUEST`-Antwort.
     *
     * @param array<string,mixed> $roh      die geparste Antwort
     * @param int                 $jetzt    Bezugszeitpunkt (für den Countdown)
     * @param int                 $fussweg  Minuten bis zur Haltestelle
     * @param list<string>        $linien   nur diese Linien; leer = alle
     * @param int                 $hoechstens 0 = alle
     * @param list<string>        $richtungen nur diese Ziele oder Steige; leer = beide Richtungen
     * @param list<array<string,mixed>> $touren Paare Linie+Ziel; ein entfernter Haken versteckt
     * @return list<array<string,mixed>>
     */
    public static function Abfahrten(array $roh, int $jetzt, int $fussweg = 0,
                                     array $linien = [], int $hoechstens = 0,
                                     array $richtungen = [], array $touren = []): array
    {
        $nurDiese = [];
        foreach ($linien as $l) {
            $l = self::Schluessel((string)$l);
            if ($l !== '') {
                $nurDiese[$l] = true;
            }
        }

        $raus = [];
        foreach ((array)($roh['stopEvents'] ?? []) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $t = is_array($e['transportation'] ?? null) ? $e['transportation'] : [];
            $p = is_array($t['product'] ?? null) ? $t['product'] : [];

            $linie = self::Linie($t);
            if ($nurDiese !== [] && !isset($nurDiese[self::Schluessel($linie)])) {
                continue;
            }

            $plan = self::Zeitstempel($e['departureTimePlanned'] ?? null);
            $ist  = self::Zeitstempel($e['departureTimeEstimated'] ?? null) ?: $plan;
            if ($plan === 0 && $ist === 0) {
                continue;   // ohne Zeit ist eine Abfahrt keine
            }

            $status = array_map('strval', (array)($e['realtimeStatus'] ?? []));
            // Die EFA schreibt den Entfall in den Status, nicht in ein eigenes
            // Feld. Beide Schreibweisen sind belegt.
            $entfall = in_array('TRIP_CANCELLED', $status, true)
                    || in_array('CANCELLED', $status, true);

            $eigen = is_array($e['location']['properties'] ?? null)
                ? $e['location']['properties'] : [];

            /* Die Gegenrichtung interessiert meist nicht: wer morgens zur Schule
               will, braucht die Fahrten in die andere Richtung nicht auf der
               Tafel. Gesiebt wird nach Ziel oder Steig, siehe RichtungPasst(). */
            if (!self::RichtungPasst((string)($t['destination']['name'] ?? ''),
                                     (string)($eigen['platformName'] ?? ''), $richtungen)) {
                continue;
            }

            /* Der einzelne Haken aus dem Zeilen-Editor. Er kommt NACH den
               beiden Textfeldern, weil er das feinere Werkzeug ist: die Felder
               sieben grob (eine Linie, eine Richtung), die Liste nimmt genau
               ein Paar heraus. */
            if (self::TourVersteckt($linie, (string)($t['destination']['name'] ?? ''), $touren)) {
                continue;
            }

            /* Der Countdown zählt ab JETZT, nicht ab der Planzeit — und mit
               abgezogenem Fußweg ist es die Zahl, die wirklich zählt: wie lange
               man noch am Frühstückstisch sitzen darf. */
            $inMinuten = (int)floor(($ist - $jetzt) / 60);
            if (($jetzt - $ist) > self::VERGANGEN_S) {
                continue;
            }

            $raus[] = [
                'line'        => $linie,
                'product'     => (string)($p['name'] ?? ''),
                'class'       => (int)($p['class'] ?? -1),
                'icon'        => self::Symbol($p),
                'destination' => (string)($t['destination']['name'] ?? ''),
                'planned'     => $plan > 0 ? date('H:i', $plan) : '',
                'estimated'   => $ist > 0 ? date('H:i', $ist) : '',
                'at'          => $ist,
                'delay'       => ($plan > 0 && $ist > 0) ? (int)round(($ist - $plan) / 60) : 0,
                'countdown'   => $inMinuten,
                'leaveIn'     => $inMinuten - $fussweg,
                'reachable'   => !$entfall && ($inMinuten - $fussweg) >= 0,
                'platform'    => (string)($eigen['platformName'] ?? ''),
                'realtime'    => ($e['isRealtimeControlled'] ?? false) === true,
                'cancelled'   => $entfall,
            ];
        }

        usort($raus, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
        if ($hoechstens <= 0) {
            return $raus;
        }
        /* Die Obergrenze zählt das Erreichbare: was der Fußweg schon gefressen
           hat, darf die Tafel nicht füllen. */
        $gewaehlt = [];
        $verpasst = 0;
        foreach ($raus as $a) {
            if (!$a['reachable'] && !$a['cancelled']) {
                if ($verpasst >= self::VERPASST_MAX) {
                    continue;
                }
                $verpasst++;
                $gewaehlt[] = $a;
                continue;
            }
            if (count($gewaehlt) - $verpasst >= $hoechstens) {
                break;
            }
            $gewaehlt[] = $a;
        }
        return $gewaehlt;
    }

    /**
     * Verbindungen aus einer `XML_TRIP_REQUEST2`-Antwort.
     *
     * @param array<string,mixed> $roh
     * @param int $nichtNach Zeitstempel: Verbindungen, die später ankommen,
     *                       fallen weg. 0 = alle behalten.
     * @return list<array<string,mixed>>
     */
    public static function Verbindungen(array $roh, int $hoechstens = 3, int $nichtNach = 0,
                                       bool $nurDirekt = false, bool $mitHalten = false,
                                       int $nichtVor = 0): array
    {
        $raus = [];
        foreach ((array)($roh['journeys'] ?? []) as $v) {
            if (!is_array($v) || !is_array($v['legs'] ?? null)) {
                continue;
            }
            /* Das ZWEITE Netz. Gesucht wird umsteigefrei schon bei der
               Auskunft (`maxChanges=0` in Efa::Strecke) — das ist der bessere
               Weg, weil sie dann andere Verbindungen findet statt uns nur die
               Reste zu lassen. Hier wird trotzdem noch einmal gesiebt: nicht
               jede EFA-Installation muss den Parameter auswerten, und dann
               stuenden Umstiege in einer Kachel, an der „nur ohne Umsteigen"
               angehakt ist. Gesiebt wird an derselben Zahl, die die Kachel als
               „direkt" oder „1 Umstieg" ausschreibt. */
            if ($nurDirekt && (int)($v['interchanges'] ?? 0) > 0) {
                continue;
            }
            $roh_abschnitte = [];
            foreach ($v['legs'] as $leg) {
                if (is_array($leg)) {
                    $roh_abschnitte[] = self::Hauptabschnitt($leg, $mitHalten);
                }
            }
            $abschnitte = self::MitFusswegen($roh_abschnitte);
            if ($abschnitte === []) {
                continue;
            }
            $ab = $abschnitte[0]['depAt'];
            $an = $abschnitte[count($abschnitte) - 1]['arrAt'];
            /* Bei einer Ankunftsvorgabe legt die EFA eine Verbindung dazu, die
               ZU SPÄT kommt — gemessen am 11.09.2026: zu „bis 08:00" kam neben
               07:40 und 07:53 auch eine Ankunft um 08:03. Für einen Schulweg
               ist das keine Alternative, sondern ein Zuspätkommen. */
            if ($nichtNach > 0 && $an > $nichtNach) {
                continue;
            }
            /* Und was schon WEG ist, ist auch keine Alternative. Bei einer
               Ankunftsvorgabe („da sein um 07:50") antwortet die EFA mit den
               spaetesten Verbindungen, die es noch schaffen — steht die Frage
               um 07:25 noch auf demselben Ziel, sind das 07:03, 07:11 und
               07:20, und alle drei sind gefahren. Genau so gemeldet am
               18.09.2026: die Karte zeigte um 07:24 den Bus von 07:03.
               Eine Minute Nachlauf, damit eine Verbindung, die gerade
               abfaehrt, nicht im selben Atemzug verschwindet. */
            if ($nichtVor > 0 && $ab > 0 && $ab < ($nichtVor - 60)) {
                continue;
            }
            $raus[] = [
                'departure'     => $ab,
                'arrival'       => $an,
                'departureText' => $ab > 0 ? date('H:i', $ab) : '',
                'arrivalText'   => $an > 0 ? date('H:i', $an) : '',
                'seconds'       => max(0, $an - $ab),
                'interchanges'  => (int)($v['interchanges'] ?? 0),
                'legs'          => $abschnitte,
            ];
        }
        usort($raus, static fn(array $a, array $b): int => $a['departure'] <=> $b['departure']);
        /* Doppelte fallen weg. Die EFA liefert sie zwar in der Regel nicht,
           aber eine Verbindung zweimal untereinander wäre ein Fehler, den
           niemand sich erklären kann — und die Prüfung kostet nichts. */
        $sauber = [];
        $gesehen = [];
        foreach ($raus as $v) {
            $marke = $v['departure'] . '-' . $v['arrival'];
            if (isset($gesehen[$marke])) {
                continue;
            }
            $gesehen[$marke] = true;
            $sauber[] = $v;
        }
        return $hoechstens > 0 ? array_slice($sauber, 0, $hoechstens) : $sauber;
    }

    /**
     * Wie viele Haltestellen der Wagen nach dem Einsteigen noch anfährt.
     *
     * Die EFA hängt an jeden Fahrabschnitt die ganze Haltestellenfolge — Start
     * und Ziel eingeschlossen. Gezählt wird deshalb eins weniger als die Folge
     * lang ist: das ist die Zahl der Halte, die man absitzt, die Ausstiegs-
     * haltestelle mitgerechnet.
     *
     * **Aufeinanderfolgende Gleiche zählen einmal.** Gemessen am 16.09.2026
     * stand „D-Eller S" zweimal hintereinander in der Folge (ein Halt, zwei
     * Steige) — ungefiltert hätte die Kachel einen Halt zu viel gemeldet.
     *
     * @param mixed $folge
     */
    private static function Zwischenhalte(mixed $folge): int
    {
        if (!is_array($folge)) {
            return 0;
        }
        $namen = [];
        foreach ($folge as $halt) {
            $name = is_array($halt) ? trim((string)($halt['name'] ?? '')) : '';
            if ($name === '' || $name === ($namen[count($namen) - 1] ?? null)) {
                continue;
            }
            $namen[] = $name;
        }
        return max(0, count($namen) - 1);
    }

    /**
     * Die Haltestellenfolge eines Abschnitts, zum Ausklappen in der Kachel.
     *
     * Je Halt nur, was angezeigt wird: Name und Uhrzeit. Die EFA liefert an den
     * Zwischenhalten in aller Regel nur die PLANzeit — eine Echtzeit steht dort
     * nur, wenn das Verkehrsunternehmen sie meldet; dann gilt sie.
     *
     * Aufeinanderfolgende Gleiche fallen zusammen, wie beim Zaehlen auch: „D-
     * Eller S" stand in einer echten Antwort zweimal hintereinander, einmal je
     * Steig.
     *
     * @param mixed $folge
     * @return list<array{n:string,t:string}>
     */
    private static function Haltefolge(mixed $folge): array
    {
        if (!is_array($folge)) {
            return [];
        }
        $raus = [];
        foreach ($folge as $halt) {
            if (!is_array($halt)) {
                continue;
            }
            $name = trim((string)($halt['name'] ?? ''));
            if ($name === '' || $name === ($raus[count($raus) - 1]['n'] ?? null)) {
                continue;
            }
            $zeit = self::Zeitstempel($halt['departureTimeEstimated'] ?? null)
                 ?: self::Zeitstempel($halt['departureTimePlanned'] ?? null)
                 ?: self::Zeitstempel($halt['arrivalTimeEstimated'] ?? null)
                 ?: self::Zeitstempel($halt['arrivalTimePlanned'] ?? null);
            $raus[] = ['n' => $name, 't' => $zeit > 0 ? date('H:i', $zeit) : ''];
        }
        return $raus;
    }

    /**
     * Die Auslastung, wie die Verkehrsunternehmen sie melden.
     *
     * Sie steht NICHT an jedem Abschnitt — am 16.09.2026 gemessen: an einem von
     * zwei Fahrabschnitten. Fehlt sie, bleibt es beim leeren Text, und die
     * Kachel lässt die Angabe dann ganz weg; „unbekannt" anzuzeigen wäre eine
     * Auskunft, die keine ist.
     *
     * @param mixed $roh
     */
    private static function Auslastung(mixed $roh): string
    {
        $stufen = [
            'MANY_SEATS'                   => 'many',
            'FEW_SEATS'                    => 'few',
            'STANDING_ONLY'                => 'standing',
            'CRUSHED_STANDING_ROOM_ONLY'   => 'full',
            'FULL'                         => 'full',
        ];
        return $stufen[strtoupper(trim((string)(is_scalar($roh) ? $roh : '')))] ?? '';
    }

    /**
     * Ein EFA-Abschnitt wird zu einem eigenen — die Fußwege kommen später dazu.
     *
     * @param array<string,mixed> $leg
     * @return array{seg:array<string,mixed>,fuss:list<array{pos:string,dauer:int,drin:bool}>}
     */
    private static function Hauptabschnitt(array $leg, bool $mitHalten = false): array
    {
        $t = is_array($leg['transportation'] ?? null) ? $leg['transportation'] : [];
        $p = is_array($t['product'] ?? null) ? $t['product'] : [];
        $o = is_array($leg['origin'] ?? null) ? $leg['origin'] : [];
        $z = is_array($leg['destination'] ?? null) ? $leg['destination'] : [];

        $abPlan = self::Zeitstempel($o['departureTimePlanned'] ?? null);
        $abIst  = self::Zeitstempel($o['departureTimeEstimated'] ?? null) ?: $abPlan;
        $anPlan = self::Zeitstempel($z['arrivalTimePlanned'] ?? null);
        $anIst  = self::Zeitstempel($z['arrivalTimeEstimated'] ?? null) ?: $anPlan;

        $linie = self::Linie($t);
        // Ein Fußweg trägt in der EFA das Produkt „footpath" und keine Linie.
        $istFuss = strtolower((string)($p['name'] ?? '')) === 'footpath' || $linie === '';

        $haupt = [
            'kind'        => $istFuss ? 'walk' : 'ride',
            'line'        => $linie,
            'product'     => (string)($p['name'] ?? ''),
            'class'       => (int)($p['class'] ?? -1),
            'icon'        => $istFuss ? 'fa-person-walking' : self::Symbol($p),
            'destination' => (string)($t['destination']['name'] ?? ''),
            'from'        => (string)($o['name'] ?? ''),
            'to'          => (string)($z['name'] ?? ''),
            'depAt'       => $abIst,
            'arrAt'       => $anIst,
            'depText'     => $abIst > 0 ? date('H:i', $abIst) : '',
            'arrText'     => $anIst > 0 ? date('H:i', $anIst) : '',
            'depDelay'    => ($abPlan > 0 && $abIst > 0) ? (int)round(($abIst - $abPlan) / 60) : 0,
            'arrDelay'    => ($anPlan > 0 && $anIst > 0) ? (int)round(($anIst - $anPlan) / 60) : 0,
            'seconds'     => (int)($leg['duration'] ?? max(0, $anIst - $abIst)),
            'realtime'    => ($leg['isRealtimeControlled'] ?? false) === true,
            'platform'    => (string)($o['properties']['platformName'] ?? ''),
            'platformTo'  => (string)($z['properties']['platformName'] ?? ''),
            'stops'       => $istFuss ? 0 : self::Zwischenhalte($leg['stopSequence'] ?? null),
            'occupancy'   => self::Auslastung($o['properties']['occupancy'] ?? null),
            /* Die Haltestellenfolge kommt NUR mit, wenn eine Ansicht sie zeigt:
               vierzehn Halte je Abschnitt sind rund 600 Byte, und die Kachel
               bekommt ihre Nutzlast bei jedem Abruf neu. */
            'halte'       => ($mitHalten && !$istFuss)
                ? self::Haltefolge($leg['stopSequence'] ?? null)
                : [],
        ];

        /* Die Fußwege stehen NICHT als eigener Abschnitt in der Antwort, sondern
           als `footPathInfo` am benachbarten — mit `position` und der Dauer in
           Sekunden. Gesammelt werden sie hier, eingehängt erst in
           MitFusswegen(): dort sind die Nachbarn bekannt, und ohne die geht es
           nicht (siehe dort). */
        $fuss = [];
        // `footPathInfoRedundant` heisst: die Laufzeit steckt SCHON in den
        // Zeiten der Abschnitte. Am 11.09.2026 war sie in beiden gemessenen
        // Auskünften gesetzt — sie ist also der Normalfall, nicht die Ausnahme.
        $drin = ($leg['footPathInfoRedundant'] ?? false) === true;
        foreach ((array)($leg['footPathInfo'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $dauer = (int)($f['duration'] ?? 0);
            if ($dauer > 0) {
                $fuss[] = [
                    'pos'   => strtoupper((string)($f['position'] ?? 'AFTER')),
                    'dauer' => $dauer,
                    'drin'  => $drin,
                ];
            }
        }

        return ['seg' => $haupt, 'fuss' => $fuss];
    }

    /**
     * Die Fußwege in die Abschnittsfolge einhängen.
     *
     * Das geht nur mit Blick auf die NACHBARN. Ist der Fußweg `redundant`,
     * steckt er schon in den Zeiten: zwischen „an 15:47" und „ab 15:57" liegen
     * zehn Minuten, von denen vier das Laufen über den Bahnsteig sind. Wer ihn
     * dann obendrauf rechnet, baut eine Zeitachse, die rückwärts läuft — genau
     * das kam beim ersten Versuch heraus (ein Fußweg endete 16:04, während die
     * Bahn schon 16:01 fuhr).
     *
     * Also: in die Lücke legen, nie darüber hinaus. Und wo keine Lücke ist,
     * fällt er weg — er hat dort keine Zeit gekostet.
     *
     * @param list<array{seg:array<string,mixed>,fuss:list<array{pos:string,dauer:int,drin:bool}>}> $eintraege
     * @return list<array<string,mixed>>
     */
    private static function MitFusswegen(array $eintraege): array
    {
        $raus = [];
        foreach ($eintraege as $i => $eintrag) {
            $seg = $eintrag['seg'];
            $vor = [];
            $nach = [];
            foreach ($eintrag['fuss'] as $f) {
                $vorher = $f['pos'] === 'BEFORE';
                if ($vorher) {
                    $lueckeBis = $seg['depAt'];
                    $lueckeAb  = isset($eintraege[$i - 1]) ? (int)$eintraege[$i - 1]['seg']['arrAt'] : 0;
                } else {
                    $lueckeAb  = $seg['arrAt'];
                    $lueckeBis = isset($eintraege[$i + 1]) ? (int)$eintraege[$i + 1]['seg']['depAt'] : 0;
                }
                $dauer = $f['dauer'];
                if ($f['drin']) {
                    // Die Lücke ist die Obergrenze; ohne Nachbarn gibt es keine.
                    $luecke = ($lueckeAb > 0 && $lueckeBis > 0) ? $lueckeBis - $lueckeAb : 0;
                    if ($luecke <= 0) {
                        continue;
                    }
                    $dauer = min($dauer, $luecke);
                }
                $ab = $vorher ? max(0, $seg['depAt'] - $dauer) : $seg['arrAt'];
                $weg = [
                    'kind'        => 'walk',
                    'line'        => '',
                    'product'     => 'footpath',
                    'class'       => 99,
                    'icon'        => 'fa-person-walking',
                    'destination' => '',
                    'from'        => $vorher ? (string)$seg['from'] : (string)$seg['to'],
                    'to'          => $vorher ? (string)$seg['from'] : (string)$seg['to'],
                    'depAt'       => $ab,
                    'arrAt'       => $ab + $dauer,
                    'depText'     => $ab > 0 ? date('H:i', $ab) : '',
                    'arrText'     => $ab > 0 ? date('H:i', $ab + $dauer) : '',
                    'depDelay'    => 0,
                    'arrDelay'    => 0,
                    'seconds'     => $dauer,
                    'realtime'    => false,
                    'platform'    => '',
                    'platformTo'  => '',
                    'stops'       => 0,
                    'occupancy'   => '',
                    'halte'       => [],
                ];
                if ($vorher) {
                    $vor[] = $weg;
                } else {
                    $nach[] = $weg;
                }
            }
            foreach ($vor as $w) {
                $raus[] = $w;
            }
            $raus[] = $seg;
            foreach ($nach as $w) {
                $raus[] = $w;
            }
        }
        return self::MitWartezeiten($raus);
    }

    /**
     * Die Wartezeiten zwischen den Abschnitten sichtbar machen.
     *
     * Ohne sie endet die Zeitachse zu früh: bei einer Verbindung von 33 Minuten
     * waren nur 86,4 % gezeichnet — Fahren und Laufen. Die fehlenden viereinhalb
     * Minuten standen niemandem zur Verfügung, obwohl sie das Kind auf dem
     * Bahnsteig verbringt. Jetzt sind sie ein eigenes Stück: der Balken füllt
     * die Breite, und es steht da, was beim Umstieg wirklich interessiert.
     *
     * Lücken unter einer Minute bleiben draußen — das ist Rundung, keine
     * Wartezeit, und ein Stück von zwei Pixeln wäre nur Unruhe.
     *
     * @param list<array<string,mixed>> $abschnitte
     * @return list<array<string,mixed>>
     */
    private static function MitWartezeiten(array $abschnitte): array
    {
        $raus = [];
        foreach ($abschnitte as $i => $a) {
            if ($i > 0) {
                $vorher = $abschnitte[$i - 1];
                $luecke = (int)$a['depAt'] - (int)$vorher['arrAt'];
                if ($luecke >= self::WARTEN_MIN_S && (int)$vorher['arrAt'] > 0) {
                    $raus[] = [
                        'kind'        => 'wait',
                        'line'        => '',
                        'product'     => 'wait',
                        'class'       => 98,
                        'icon'        => 'fa-hourglass-half',
                        'destination' => '',
                        'from'        => (string)$vorher['to'],
                        'to'          => (string)$vorher['to'],
                        'depAt'       => (int)$vorher['arrAt'],
                        'arrAt'       => (int)$a['depAt'],
                        'depText'     => (string)$vorher['arrText'],
                        'arrText'     => (string)$a['depText'],
                        'depDelay'    => 0,
                        'arrDelay'    => 0,
                        'seconds'     => $luecke,
                        'realtime'    => false,
                        'platform'    => '',
                        'platformTo'  => '',
                        'stops'       => 0,
                        'occupancy'   => '',
                        'halte'       => [],
                    ];
                }
            }
            $raus[] = $a;
        }
        return $raus;
    }

    /**
     * Schulzeiten eines Tages aus dem Stundenplan.
     *
     * Gezählt wird nur, was WIRKLICH STATTFINDET (`status !== 'entfall'`) —
     * Betreuung eingeschlossen, denn ist sie der erste Eintrag, muss das Kind
     * dann dort sein. Fällt die erste Stunde aus, rutscht der Beginn nach
     * hinten; fällt die letzte aus, ist früher Schluss. Genau dafür lohnt sich
     * die Kopplung an den Stundenplan, und beides fällt ohne Zutun an, seit der
     * Plan den Entfall datiert kennt.
     *
     * @param array<string,mixed> $tag ein Tag aus STPL_GetPlanForDate
     * @return array{start:string,end:string}|null  null = kein Unterricht
     */
    public static function Schulzeiten(array $tag): ?array
    {
        if (is_array($tag['holiday'] ?? null)) {
            return null;
        }
        $beginn = null;
        $ende   = null;
        foreach ((array)($tag['slots'] ?? []) as $s) {
            if (!is_array($s) || (string)($s['status'] ?? '') === 'entfall') {
                continue;
            }
            $von = trim((string)($s['start'] ?? ''));
            $bis = trim((string)($s['end'] ?? ''));
            if ($von === '' || $bis === '') {
                continue;
            }
            if ($beginn === null || $von < $beginn) {
                $beginn = $von;
            }
            if ($ende === null || $bis > $ende) {
                $ende = $bis;
            }
        }
        return ($beginn === null || $ende === null) ? null : ['start' => $beginn, 'end' => $ende];
    }

    /**
     * Richtung und Zielzeit des Schulwegs an EINEM Tag.
     *
     * Die Richtung ergibt sich aus der Uhrzeit, das ist die ganze Bedienung:
     * vor Unterrichtsbeginn zur Schule, danach nach Hause. Ist der Schultag
     * lange durch, gibt es hier nichts mehr — der Aufrufer fragt dann den
     * nächsten Tag, und weil mit Zeitstempeln gerechnet wird, liefert derselbe
     * Aufruf für morgen von selbst wieder den Hinweg.
     *
     * @param array<string,mixed> $tag    ein Tag aus STPL_GetPlanForDate
     * @param string              $datum  „JJJJ-MM-TT" dieses Tages
     * @param int                 $jetzt  Bezugszeitpunkt
     * @param int                 $puffer Minuten
     * @return array{direction:string,mode:string,date:string,schoolStart:string,schoolEnd:string,targetTime:string,targetAt:int,bufferUsed:int}|null
     */
    public static function Schulweg(array $tag, string $datum, int $jetzt, int $puffer): ?array
    {
        $zeiten = self::Schulzeiten($tag);
        if ($zeiten === null || $datum === '') {
            return null;
        }
        $beginn = strtotime($datum . ' ' . $zeiten['start']);
        $ende   = strtotime($datum . ' ' . $zeiten['end']);
        if ($beginn === false || $ende === false) {
            return null;
        }
        $puffer = max(0, $puffer);
        $abJetzt = false;

        if ($jetzt < $beginn) {
            $ziel = $beginn - $puffer * 60;
            $richtung = 'to';
            $modus = 'arr';
        } else {
            $ziel = $ende + $puffer * 60;
            $richtung = 'from';
            $modus = 'dep';
            // Der Schultag ist durch: der Aufrufer fragt den nächsten.
            if ($jetzt > $ziel + self::RUECKWEG_NACHLAUF_S) {
                return null;
            }
            /* Liegt die Abfahrtszeit schon hinter uns, gilt JETZT. Wer später
               aus dem Gebäude kommt — Gespräch mit der Lehrerin, vergessenes
               Heft, Regen — braucht die Verbindung, die er noch bekommt, nicht
               die, die er verpasst hat. Ohne das zeigte die Karte um 15:40 noch
               immer den Zug von 15:10. */
            if ($ziel < $jetzt) {
                $ziel = $jetzt;
                $abJetzt = true;
            }
        }

        return [
            'direction'   => $richtung,
            'mode'        => $modus,
            'date'        => $datum,
            'schoolStart' => $zeiten['start'],
            'schoolEnd'   => $zeiten['end'],
            'targetTime'  => date('H:i', $ziel),
            'targetAt'    => $ziel,
            'bufferUsed'  => $puffer,
            /* Wahr, wenn der planmäßige Zeitpunkt schon vorbei war und statt
               dessen ab jetzt gesucht wurde — die Oberfläche sagt das dann
               auch, sonst wundert man sich über die Zeiten. */
            'fromNow'     => $abJetzt,
        ];
    }

    /**
     * Start oder Ziel einer Strecke aus einer Formularzeile.
     *
     * Zwei Wege führen zu einem Punkt, und sie schließen einander aus:
     *
     * - die Auswahlliste `from`/`to` trägt die Kennung einer eingerichteten
     *   Haltestelle,
     * - steht dort `geo`, gilt stattdessen die Markierung aus der Karte
     *   (`fromGeo`/`toGeo`), die Symcon als `{"latitude":…,"longitude":…}`
     *   ablegt.
     *
     * Zwei Fallen stecken darin. Die erste: eine nie angefasste Karte liefert
     * 0/0 — die Nullinsel im Atlantik vor Ghana. Ohne die Prüfung suchte die
     * EFA klaglos eine Verbindung von dort. Die zweite: die Karte gibt Breite
     * und Länge, die EFA will sie andersherum — das dreht Efa::Ort() um, und
     * deshalb entsteht hier die Schreibweise „Breite,Länge", die jede
     * Karten-App anzeigt und die man auch von Hand eintippen kann.
     *
     * @param array<string,mixed> $zeile
     * @param string              $feld  'from' oder 'to'
     */
    public static function Punkt(array $zeile, string $feld): string
    {
        $wert = trim((string)($zeile[$feld] ?? ''));
        if ($wert !== '' && $wert !== self::PUNKT_KARTE) {
            return $wert;
        }
        /* Auch bei leerer Auswahl gilt die Karte, sobald eine Markierung
           gesetzt ist: wer sie gesetzt hat, meinte sie. */
        $roh = $zeile[$feld . 'Geo'] ?? '';
        $geo = is_array($roh) ? $roh : json_decode((string)$roh, true);
        if (!is_array($geo)) {
            return '';
        }
        $breite = (float)($geo['latitude'] ?? 0);
        $laenge = (float)($geo['longitude'] ?? 0);
        if (abs($breite) < 0.00001 && abs($laenge) < 0.00001) {
            return '';                       // Nullinsel = nie gesetzt
        }
        if (abs($breite) > 90 || abs($laenge) > 180) {
            return '';
        }
        // Fünf Nachkommastellen sind gut ein Meter — mehr braucht kein Haus.
        return sprintf('%.5f,%.5f', $breite, $laenge);
    }

    /**
     * Haltestellen-Treffer, brauchbar sortiert.
     *
     * Die EFA liefert sie NICHT nach Güte: die Suche „Benrath" gab am
     * 11.09.2026 fünfunddreißig Treffer, der richtige stand an zweiter Stelle
     * mit `matchQuality` 946 hinter einem mit 166. Ohne diese Sortierung wählt
     * der Nutzer die falsche Haltestelle und sucht den Fehler später im Modul.
     *
     * @param array<string,mixed> $roh
     * @return list<array{id:string,name:string,quality:int}>
     */
    public static function Haltestellen(array $roh, int $hoechstens = 25): array
    {
        $raus = [];
        foreach ((array)($roh['locations'] ?? []) as $l) {
            if (!is_array($l) || (string)($l['type'] ?? '') !== 'stop') {
                continue;
            }
            $id = trim((string)($l['id'] ?? ''));
            $name = trim((string)($l['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $raus[] = ['id' => $id, 'name' => $name, 'quality' => (int)($l['matchQuality'] ?? 0)];
        }
        usort($raus, static fn(array $a, array $b): int => $b['quality'] <=> $a['quality']);
        return $hoechstens > 0 ? array_slice($raus, 0, $hoechstens) : $raus;
    }

    /**
     * Die Linie einer Fahrt.
     *
     * `disassembledName` ist der kurze Name („U75") und das, was auf dem Zug
     * steht — aber er FEHLT im Fernverkehr: am 11.09.2026 kam ein IC ohne ihn,
     * und ohne Rückfall stünde in der Kachel ein leeres Feld.
     *
     * @param array<string,mixed> $t
     */
    private static function Linie(array $t): string
    {
        foreach (['disassembledName', 'number', 'name'] as $feld) {
            $wert = trim((string)($t[$feld] ?? ''));
            if ($wert !== '') {
                return $wert;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $p */
    private static function Symbol(array $p): string
    {
        $klasse = (int)($p['class'] ?? -1);
        if (isset(self::SYMBOL_JE_KLASSE[$klasse])) {
            return self::SYMBOL_JE_KLASSE[$klasse];
        }
        $name = strtolower(trim((string)($p['name'] ?? '')));
        foreach (self::SYMBOL_JE_NAME as $teil => $symbol) {
            if ($name !== '' && str_contains($name, $teil)) {
                return $symbol;
            }
        }
        return 'fa-route';
    }

    /**
     * „2026-09-11T12:21:00Z" → Zeitstempel. Die Z-Form ist UTC; `date()` macht
     * daraus später Ortszeit, und genau so soll es sein.
     */
    private static function Zeitstempel(mixed $iso): int
    {
        $iso = trim((string)($iso ?? ''));
        if ($iso === '') {
            return 0;
        }
        $t = strtotime($iso);
        return $t === false ? 0 : $t;
    }

    /** Vergleichsform einer Linienbezeichnung: „U 75" und „u75" sind dasselbe. */
    private static function Schluessel(string $wert): string
    {
        return strtolower((string)preg_replace('/\s+/', '', trim($wert)));
    }

    /**
     * Passt eine Abfahrt zur gewünschten Richtung?
     *
     * Eine Richtung ist das ZIEL, wie es vorn am Fahrzeug steht („Hbf",
     * „Krankenhaus") — oder ein Steig („Steig 1", „Gl. 10"), denn an vielen
     * Haltestellen trennt der Steig die Fahrtrichtungen sauberer als jedes
     * Ziel. Verglichen wird ohne Groß/Klein, Leerzeichen, Punkte und
     * Bindestriche: „st vinzenz" trifft „D-St.-Vinzenz-Krankenhaus". Eine
     * Abfahrt bleibt, wenn EINE der Angaben passt; ohne Angabe bleiben alle.
     *
     * Als Steig gilt nur, was nach dem Wort einen Punkt oder ein Leerzeichen
     * hat: „Gladbeck" und „Gleisdreieck" sind Orte, kein Gleis.
     *
     * @param list<string> $richtungen
     */
    public static function RichtungPasst(string $ziel, string $steig, array $richtungen): bool
    {
        $offen = true;
        foreach ($richtungen as $r) {
            $r = trim((string)$r);
            if ($r === '') {
                continue;
            }
            $offen = false;
            if (preg_match(self::STEIG_MUSTER, $r, $m) === 1) {
                $soll = self::Wortschluessel($m[1]);
                $ist  = self::Wortschluessel((string)preg_replace(self::STEIG_MUSTER, '$1', trim($steig)));
                if ($soll !== '' && $soll === $ist) {
                    return true;
                }
                continue;
            }
            $wunsch = self::Wortschluessel($r);
            if ($wunsch !== '' && str_contains(self::Wortschluessel($ziel), $wunsch)) {
                return true;
            }
        }
        return $offen;
    }

    /** So viele Touren passen sinnvoll in den Zeilen-Editor. */
    public const TOUREN_MAX = 40;

    /**
     * Die Touren einer Haltestelle: je Linie und Ziel eine Zeile.
     *
     * Was an einer Haltestelle faehrt, ist nicht „Linie ODER Richtung", sondern
     * ein PAAR. Die beiden Textfelder „Nur diese Linien" und „Richtung" konnten
     * das nie ausdruecken: sie werden UND-verknuepft, und „789 nach Monheim"
     * zusammen mit „834 nach Hbf" ergibt vier Kombinationen statt zwei. Diese
     * Liste nennt die Paare, die es wirklich gibt, und jedes bekommt seinen
     * Haken.
     *
     * `show` steht auf wahr: wer nichts tut, sieht alles wie bisher.
     *
     * @param array<string,mixed> $roh die geparste `XML_DM_REQUEST`-Antwort
     * @return list<array{line:string,direction:string,show:bool}>
     */
    public static function Touren(array $roh, int $hoechstens = self::TOUREN_MAX): array
    {
        $raus    = [];
        $gesehen = [];
        foreach ((array)($roh['stopEvents'] ?? []) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $t     = is_array($e['transportation'] ?? null) ? $e['transportation'] : [];
            $linie = self::Linie($t);
            $ziel  = (string)($t['destination']['name'] ?? '');
            // Komma und Semikolon trennen anderswo Richtungen — im Ziel nicht.
            $ziel  = trim((string)preg_replace('/\s+/u', ' ', str_replace([',', ';'], ' ', $ziel)));
            if ($linie === '' && $ziel === '') {
                continue;
            }
            $schluessel = self::Wortschluessel($linie) . '|' . self::Wortschluessel($ziel);
            if (isset($gesehen[$schluessel])) {
                continue;
            }
            $gesehen[$schluessel] = true;
            $raus[] = ['line' => $linie, 'direction' => $ziel, 'show' => true];
            if (count($raus) >= max(1, $hoechstens)) {
                break;
            }
        }
        return $raus;
    }

    /**
     * Ist diese Abfahrt durch einen entfernten Haken ausgeschlossen?
     *
     * Die Liste wirkt als SPERRE, nicht als Freigabe: versteckt wird nur, was
     * ausdruecklich dasteht UND keinen Haken hat. Alles andere faehrt weiter —
     * sonst verschwaende eine Linie, die neu an die Haltestelle kommt,
     * stillschweigend aus der Kachel, und niemand kaeme darauf, dass eine
     * Liste von vorgestern sie nicht kennt.
     *
     * @param list<array<string,mixed>> $touren
     */
    public static function TourVersteckt(string $linie, string $ziel, array $touren): bool
    {
        if ($touren === []) {
            return false;
        }
        $l = self::Wortschluessel($linie);
        $z = self::Wortschluessel($ziel);
        foreach ($touren as $t) {
            if (!is_array($t)) {
                continue;
            }
            /* Eine Zeile ohne Linie UND ohne Ziel ist ein Rest, keine Tour. Sie
               darf nichts verstecken — sonst legte ein Ueberbleibsel im Bestand
               eine Abfahrt still, deren Linie und Ziel zufaellig auch leer
               waeren. */
            if ((string)($t['line'] ?? '') === '' && (string)($t['direction'] ?? '') === '') {
                continue;
            }
            if (self::Wortschluessel((string)($t['line'] ?? '')) !== $l) {
                continue;
            }
            if (self::Wortschluessel((string)($t['direction'] ?? '')) !== $z) {
                continue;
            }
            // Fehlt der Haken ganz (aeltere Zeile), gilt „sichtbar".
            return array_key_exists('show', $t) && !$t['show'];
        }
        return false;
    }

    /** „Steig 1", „Bstg. 2", „Gleis 10", „Gl.10", „Platform 3" — der Rest ist die Nummer. */
    private const STEIG_MUSTER = '/^(?:steig|bstg|bahnsteig|gleis|gl|platform|pl)(?:\.\s*|\s+)(\S.*)$/iu';

    /**
     * Eine Uhrzeit aus einer Formularzelle als „HH:MM" — leer, wenn keine
     * brauchbare darin steht.
     *
     * Die Spalte ist ein Zeitwaehler (`SelectTime`), und der legt ein OBJEKT
     * ab: `{"hour":7,"minute":50,"second":0}`. Aeltere Bestaende tragen dort
     * noch Text („07:50", „8"). Beides wird gelesen, damit eine gewachsene
     * Liste nicht stillschweigend auf „jetzt" zurueckfaellt — dieselbe Falle
     * wie im Stundenplan, wo die Konsole eine Textzelle als „ungueltig" zeigte.
     */
    public static function ZeitText(mixed $wert): string
    {
        if (is_string($wert) && str_starts_with(trim($wert), '{')) {
            $wert = json_decode(trim($wert), true);
        }
        if (is_array($wert)) {
            if (!isset($wert['hour']) && !isset($wert['minute'])) {
                return '';
            }
            return sprintf('%02d:%02d', max(0, min(23, (int)($wert['hour'] ?? 0))),
                                        max(0, min(59, (int)($wert['minute'] ?? 0))));
        }
        $text = trim((string)$wert);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2})[:.\s]?(\d{2})?$/', $text, $t) !== 1) {
            return '';
        }
        return sprintf('%02d:%02d', max(0, min(23, (int)$t[1])), max(0, min(59, (int)($t[2] ?? 0))));
    }

    /**
     * Dieselbe Uhrzeit, wie der Zeitwaehler sie ablegt: als JSON-TEXT.
     *
     * Nicht als Feld: die Konsole legt eine `SelectTime`-Zelle als Zeichenkette
     * mit JSON darin ab — im Stundenplan gegengelesen. Ein echtes Feld kommt
     * beim Speichern anders zurueck, und die Zelle zeigte „ungueltig".
     */
    public static function ZeitFeld(string $hhmm): string
    {
        $text = self::ZeitText($hhmm);
        [$h, $m] = $text === '' ? [0, 0] : array_map('intval', explode(':', $text));
        return (string)json_encode(['hour' => $h, 'minute' => $m, 'second' => 0]);
    }

    /**
     * Die beiden Enden einer Verbindung ueberschreiben.
     *
     * Was die EFA als Start und Ziel nennt, ist die HALTESTELLE — bei einer
     * Koordinate steht dort die Adresse, und die will niemand lesen („51.25790,
     * 6.86770" oder „Duesseldorf, Kissbergweg 12"). Traegt die Strecke eigene
     * Namen („Zuhause", „Schule"), gelten die; sonst bleibt alles, wie es war.
     *
     * Ueberschrieben wird nur das ERSTE `from` und das LETZTE `to` — die
     * Umstiege dazwischen sind echte Haltestellen und heissen weiter so.
     *
     * @param list<array<string,mixed>> $verbindungen
     * @return list<array<string,mixed>>
     */
    public static function EndenBenennen(array $verbindungen, string $start, string $ziel): array
    {
        $start = trim($start);
        $ziel  = trim($ziel);
        if ($start === '' && $ziel === '') {
            return $verbindungen;
        }
        foreach ($verbindungen as $i => $v) {
            $legs = is_array($v['legs'] ?? null) ? $v['legs'] : [];
            if ($legs === []) {
                continue;
            }
            $erste = array_key_first($legs);
            $letzte = array_key_last($legs);
            if ($start !== '') {
                $verbindungen[$i]['legs'][$erste]['from'] = $start;
            }
            if ($ziel !== '') {
                $verbindungen[$i]['legs'][$letzte]['to'] = $ziel;
            }
        }
        return $verbindungen;
    }

    /**
     * Treffer der Suche, die KEINE Haltestelle sind — Strassen, Adressen, Orte.
     *
     * Die EFA kennt „Duesseldorf, Kissbergweg" als `street` mit Koordinate,
     * aber als Haltestelle gibt es sie nicht. Aus der Koordinate laesst sich
     * die naechste Haltestelle finden; dafuer kommen die Treffer hier heraus.
     *
     * @param array<string,mixed> $roh
     * @return list<array{name:string,lat:float,lon:float,type:string,quality:int}>
     */
    public static function Orte(array $roh, int $hoechstens = 5): array
    {
        $raus = [];
        foreach ((array)($roh['locations'] ?? []) as $l) {
            if (!is_array($l) || (string)($l['type'] ?? '') === 'stop') {
                continue;
            }
            $koord = (array)($l['coord'] ?? []);
            // WGS84 kommt als [Breite, Laenge]; ohne coordOutputFormat stuende
            // hier Mercator, und die Zahlen waeren um Groessenordnungen daneben.
            $breite = (float)($koord[0] ?? 0);
            $laenge = (float)($koord[1] ?? 0);
            $name = trim((string)($l['name'] ?? ''));
            if ($name === '' || abs($breite) > 90 || abs($laenge) > 180
                || (abs($breite) < 0.00001 && abs($laenge) < 0.00001)) {
                continue;
            }
            $raus[] = ['name' => $name, 'lat' => $breite, 'lon' => $laenge,
                       'type' => (string)($l['type'] ?? ''), 'quality' => (int)($l['matchQuality'] ?? 0)];
        }
        usort($raus, static fn(array $a, array $b): int => $b['quality'] <=> $a['quality']);
        return $hoechstens > 0 ? array_slice($raus, 0, $hoechstens) : $raus;
    }

    /**
     * Haltestellen aus einer Umkreissuche — nach Entfernung, die naechste oben.
     *
     * @param array<string,mixed> $roh
     * @return list<array{id:string,name:string,distance:int}>
     */
    public static function Umkreis(array $roh, int $hoechstens = 10): array
    {
        $raus = [];
        foreach ((array)($roh['locations'] ?? []) as $l) {
            if (!is_array($l) || (string)($l['type'] ?? '') !== 'stop') {
                continue;
            }
            $id = trim((string)($l['id'] ?? ''));
            $name = trim((string)($l['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $raus[] = ['id' => $id, 'name' => $name,
                       'distance' => (int)($l['properties']['distance'] ?? 0)];
        }
        usort($raus, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);
        return $hoechstens > 0 ? array_slice($raus, 0, $hoechstens) : $raus;
    }

    /** Nur Buchstaben und Ziffern, klein — so vergleichen sich Ziele und Steige. */
    private static function Wortschluessel(string $wert): string
    {
        return mb_strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', '', $wert));
    }
}
