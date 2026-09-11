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
     * Symbol je Produktklasse der EFA. Gemessen am 11.09.2026: 1 = S-Bahn,
     * 5 = Niederflurbus, 13 = Regionalzug; U-Bahn und Fußweg kamen in den
     * Streckenauskünften dazu.
     *
     * Die Zahl ist der verlässlichere Schlüssel als der Name — „Niederflurbus"
     * und „Bus" meinen dasselbe, und der Verbund darf den Text jederzeit ändern.
     */
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
     * @return list<array<string,mixed>>
     */
    public static function Abfahrten(array $roh, int $jetzt, int $fussweg = 0,
                                     array $linien = [], int $hoechstens = 0): array
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
    public static function Verbindungen(array $roh, int $hoechstens = 3, int $nichtNach = 0): array
    {
        $raus = [];
        foreach ((array)($roh['journeys'] ?? []) as $v) {
            if (!is_array($v) || !is_array($v['legs'] ?? null)) {
                continue;
            }
            $roh_abschnitte = [];
            foreach ($v['legs'] as $leg) {
                if (is_array($leg)) {
                    $roh_abschnitte[] = self::Hauptabschnitt($leg);
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
        return $hoechstens > 0 ? array_slice($raus, 0, $hoechstens) : $raus;
    }

    /**
     * Ein EFA-Abschnitt wird zu einem eigenen — die Fußwege kommen später dazu.
     *
     * @param array<string,mixed> $leg
     * @return array{seg:array<string,mixed>,fuss:list<array{pos:string,dauer:int,drin:bool}>}
     */
    private static function Hauptabschnitt(array $leg): array
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
}
