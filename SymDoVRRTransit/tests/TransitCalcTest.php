<?php

declare(strict_types=1);

/**
 * Offline-Prüfstand für das Rechenwerk der VRR-Auskunft.
 *
 * Die Prüfdaten in `fixtures/` sind ECHTE Antworten der EFA vom 11.09.2026, von
 * neutralen Düsseldorfer Haltestellen — Hauptbahnhof und Benrath. Gekürzt sind
 * sie nur dort, wo es um Umfang geht: am ersten Abschnitt der Umstiegs-Strecke
 * steht der Ballast (`properties`, `coords`, `stopSequence`) absichtlich noch
 * drin, damit die weiße Liste etwas zu verwerfen hat.
 *
 * Braucht keine Symcon-Attrappen: TransitCalc ruft keine einzige IPS-Funktion.
 * Genau dafür ist es eine eigene Klasse.
 *
 *   php SymDoVRRTransit/tests/TransitCalcTest.php
 */

require_once __DIR__ . '/../libs/TransitCalc.php';

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
    printf("%-4s %-62s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

function fixture(string $name): array
{
    $roh = @file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');
    if (!is_string($roh)) {
        fwrite(STDERR, "Prüfdatei $name.json fehlt.\n");
        exit(2);
    }
    return (array)json_decode($roh, true);
}

// ── Abfahrten ──────────────────────────────────────────────────────────────
// Bezugszeit ist der Moment des Abrufs: 11.09.2026, 15:54 Ortszeit.
$jetzt = strtotime('2026-09-11 15:54:00');
$ab = TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt);

pruefe('drei Abfahrten', count($ab), 3);
pruefe('aufsteigend nach Abfahrt sortiert',
    [$ab[0]['at'] <= $ab[1]['at'], $ab[1]['at'] <= $ab[2]['at']], [true, true]);

/* Der RE4 ist der Beweis für die Zeitzone: die EFA schickt „13:41Z", das
   klassische JSON derselben Abfrage nennt 15:41 Ortszeit. Rechnet man das Z als
   Ortszeit, geht die Kachel im Sommer zwei Stunden falsch. */
$re4 = $ab[2];
pruefe('RE4: Planzeit in Ortszeit', $re4['planned'], '15:41');
pruefe('RE4: Prognose in Ortszeit', $re4['estimated'], '16:14');
/* Gegengelesen am klassischen JSON derselben Abfrage: dort steht delay 33 und
   countdown 20. Wer beides selbst rechnet, muss auf dieselben Zahlen kommen. */
pruefe('RE4: Verspätung wie von der EFA selbst gemeldet', $re4['delay'], 33);
pruefe('RE4: Countdown wie von der EFA selbst gemeldet', $re4['countdown'], 20);
pruefe('RE4: Steig', $re4['platform'], '10');
pruefe('RE4: Echtzeit erkannt', $re4['realtime'], true);
pruefe('RE4: nicht entfallen', $re4['cancelled'], false);
pruefe('RE4: Symbol aus der Produktklasse 13', [$re4['class'], $re4['icon']], [13, 'fa-train']);

/* Der Fernverkehr kam ohne `disassembledName`. Ohne Rückfall auf `number`
   stünde in der Kachel eine leere Linie — gemessen, nicht vermutet. */
$ic = $ab[1];
pruefe('Fernzug ohne disassembledName: Rückfall auf number', $ic['line'], '2203');
pruefe('Fernzug: Ziel steht trotzdem', $ic['destination'], 'Köln Hbf');

$bus = $ab[0];
pruefe('Bus: Symbol aus der Produktklasse 5', [$bus['class'], $bus['icon']], [5, 'fa-bus']);
pruefe('Bus: pünktlich', $bus['delay'], 0);

// Linienfilter — die Schreibweise darf nicht entscheiden
pruefe('Filter auf eine Linie',
    array_column(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, ['RE4']), 'line'), ['RE4']);
pruefe('Filter ist unempfindlich gegen Schreibweise',
    array_column(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, ['re 4']), 'line'), ['RE4']);
pruefe('Filter auf eine Linie, die es hier nicht gibt',
    TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, ['U79']), []);
pruefe('Obergrenze greift',
    count(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 2)), 2);

// Richtungsfilter — das Ziel wie vorn am Fahrzeug, oder der Steig
pruefe('Richtung nach Ziel',
    array_column(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, ['Köln']), 'line'), ['2203']);
pruefe('Richtung: mehrere Ziele, Schreibweise und Leerraum egal',
    array_column(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, ['dortmund hbf', ' köln ']), 'line'),
    ['2203', 'RE4']);
pruefe('Richtung: Bindestriche und Punkte zählen nicht',
    array_column(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, ['theodor heuss']), 'line'), ['834']);
pruefe('Richtung nach Steig',
    array_column(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, ['Steig 12']), 'line'), ['834']);
pruefe('Richtung nach Gleis mit Punkt und ohne Leerzeichen',
    array_column(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, ['Gl.10']), 'line'), ['RE4']);
pruefe('Richtung, die es hier nicht gibt',
    TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, ['Wuppertal']), []);
pruefe('Ein Ort, der wie ein Gleis anfängt, ist ein Ziel',
    [TransitCalc::RichtungPasst('Gladbeck Bf', '3', ['Gladbeck']), TransitCalc::RichtungPasst('Gleisdreieck', '3', ['Gleisdreieck'])],
    [true, true]);
pruefe('Steig mit Präfix in der Antwort der EFA',
    TransitCalc::RichtungPasst('Irgendwo', 'Bstg. 2', ['Steig 2']), true);
pruefe('Leere Angaben lassen alles durch',
    count(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, ['', ' '])), 3);
pruefe('Richtung und Linie zusammen',
    array_column(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, ['RE4', '2203'], 0, ['Hbf']), 'line'),
    ['2203', 'RE4']);

// ── Touren einer Haltestelle ───────────────────────────────────────────────
/* Der Zeilen-Editor zeigt je Linie und Ziel eine Zeile mit einem Haken. Die
   eigentliche Zusicherung ist NICHT die Liste selbst, sondern der Rundgang: was
   hier als Tour steht, muss die zugehoerige Abfahrt danach auch wirklich
   verstecken lassen. Eine Tour, die nichts trifft, waere schlimmer als keine —
   der Haken taete dann nichts, und niemand wuesste warum. */
$touren = TransitCalc::Touren(fixture('abfahrten'));
pruefe('Je Linie und Ziel eine Tour', count($touren), 3);
pruefe('Jede traegt Linie, Ziel und einen gesetzten Haken',
    array_values(array_unique(array_map(
        static fn(array $t): string => ($t['line'] !== '' ? 'L' : '-')
            . ($t['direction'] !== '' ? 'Z' : '-') . ($t['show'] ? 'H' : '-'), $touren))),
    ['LZH']);

$alle = count(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt));
pruefe('Alle Haken gesetzt heisst: nichts wird versteckt',
    count(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, [], $touren)), $alle);

foreach ($touren as $nr => $t) {
    $eine = $touren;
    $eine[$nr]['show'] = false;
    pruefe('Ohne Haken faellt genau „' . $t['line'] . ' → ' . $t['direction'] . '" weg',
        count(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, [], $eine)), $alle - 1);
}

pruefe('Eine leere Liste versteckt nichts',
    count(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, [], [])), $alle);
/* SPERRE, nicht Freigabe: eine Linie, die neu an die Haltestelle kommt, steht
   in keiner gespeicherten Liste — sie muss trotzdem fahren. */
pruefe('Was nicht in der Liste steht, faehrt weiter',
    count(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, [],
        [['line' => 'X99', 'direction' => 'Nirgendwo', 'show' => false]])), $alle);
/* Ein Ueberbleibsel im Bestand — am 15.09.2026 entstanden, als die inneren
   Spalten noch kein `edit` hatten und die Konsole beim Speichern nur den Haken
   zurueckschickte. Es darf nichts stilllegen. */
pruefe('Eine Zeile ohne Linie und ohne Ziel versteckt nichts',
    count(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 0, [],
        [['show' => false], ['show' => false]])), $alle);
pruefe('Eine Zeile ohne Haken-Feld versteckt nichts',
    TransitCalc::TourVersteckt($touren[0]['line'], $touren[0]['direction'],
        [['line' => $touren[0]['line'], 'direction' => $touren[0]['direction']]]), false);

/* „Aachen, Hbf" gibt es wirklich. Das Komma wird zum Leerzeichen, damit die
   Zelle nicht aussieht wie zwei Eintraege — und der Vergleich trifft trotzdem,
   weil Wortschluessel ohnehin alles wirft, was kein Buchstabe ist. Am
   15.09.2026 im Livelauf gegen die echte EFA aufgefallen. */
$komma = ['stopEvents' => [
    ['transportation' => ['number' => '789', 'destination' => ['name' => 'Aachen, Hbf']]],
    ['transportation' => ['number' => '789', 'destination' => ['name' => 'Aachen, Hbf']]],
]];
$ausKomma = TransitCalc::Touren($komma);
pruefe('Ein Komma im Ziel wird zum Leerzeichen, Doppelte fallen weg',
    array_map(static fn(array $t): string => $t['direction'], $ausKomma), ['Aachen Hbf']);
pruefe('… und der Haken trifft das Ziel MIT Komma',
    TransitCalc::TourVersteckt('789', 'Aachen, Hbf',
        [['line' => '789', 'direction' => 'Aachen Hbf', 'show' => false]]), true);
pruefe('… aber nicht dieselbe Linie in eine andere Richtung',
    TransitCalc::TourVersteckt('789', 'Köln Hbf',
        [['line' => '789', 'direction' => 'Aachen Hbf', 'show' => false]]), false);
pruefe('Der Deckel greift', count(TransitCalc::Touren(fixture('abfahrten'), 1)), 1);
pruefe('Eine leere Antwort gibt nichts', TransitCalc::Touren(fixture('leer')), []);

// ── Nur ohne Umsteigen ─────────────────────────────────────────────────────
/* Der Haken „Nur ohne Umsteigen" siebt an derselben Zahl, die die Kachel als
   „direkt" oder „1 Umstieg" ausschreibt. Beide Prüfdateien tragen genau eine
   Verbindung, und die hat einen Umstieg — nach dem Sieben bleibt nichts. */
foreach (['strecke-umstieg', 'strecke-koordinate'] as $datei) {
    pruefe('Ohne Haken kommt die Verbindung mit Umstieg durch (' . $datei . ')',
        count(TransitCalc::Verbindungen(fixture($datei), 4)), 1);
    pruefe('Mit Haken bleibt sie draussen (' . $datei . ')',
        TransitCalc::Verbindungen(fixture($datei), 4, 0, true), []);
}

/* Und die Gegenrichtung: eine umsteigefreie Verbindung muss bleiben. Gebaut
   statt geholt — beide echten Prüfdateien haben keine. */
$direkt = ['journeys' => [[
    'interchanges' => 0,
    'legs' => [['duration' => 600,
        'origin'      => ['name' => 'A', 'departureTimePlanned' => '2026-09-11T05:40:00Z'],
        'destination' => ['name' => 'B', 'arrivalTimePlanned'  => '2026-09-11T05:50:00Z'],
        'transportation' => ['number' => '779', 'product' => ['name' => 'Bus', 'class' => 5],
                             'destination' => ['name' => 'B']]]],
]]];
pruefe('Eine umsteigefreie Verbindung ueberlebt den Haken',
    count(TransitCalc::Verbindungen($direkt, 4, 0, true)), 1);
pruefe('… und ihre Umstiegszahl bleibt null',
    TransitCalc::Verbindungen($direkt, 4, 0, true)[0]['interchanges'], 0);
/* Der Haken darf die Ankunftsvorgabe nicht aushebeln: beides muss greifen. */
pruefe('Haken und Ankunftsvorgabe zusammen',
    TransitCalc::Verbindungen($direkt, 4, strtotime('2026-09-11 07:45:00'), true), []);

// ── Uhrzeit aus der Formularzelle ──────────────────────────────────────────
pruefe('Zeitwaehler-Objekt', TransitCalc::ZeitText(['hour' => 7, 'minute' => 50, 'second' => 0]), '07:50');
pruefe('Zeitwaehler als JSON-Text', TransitCalc::ZeitText('{"hour":16,"minute":5,"second":0}'), '16:05');
pruefe('alter Text mit Doppelpunkt', TransitCalc::ZeitText('7:05'), '07:05');
pruefe('alter Text ohne Minuten', TransitCalc::ZeitText('8'), '08:00');
pruefe('alter Text mit Punkt', TransitCalc::ZeitText('08.30'), '08:30');
pruefe('leer bleibt leer', [TransitCalc::ZeitText(''), TransitCalc::ZeitText('Unsinn'), TransitCalc::ZeitText([])], ['', '', '']);
pruefe('unmoegliche Werte werden gekappt', TransitCalc::ZeitText(['hour' => 99, 'minute' => 88]), '23:59');
pruefe('Zelle zurueck', TransitCalc::ZeitFeld('07:50'), '{"hour":7,"minute":50,"second":0}');
pruefe('Zelle aus Unsinn ist Mitternacht', TransitCalc::ZeitFeld('x'), '{"hour":0,"minute":0,"second":0}');
pruefe('und wieder zurueck', TransitCalc::ZeitText(TransitCalc::ZeitFeld('16:05')), '16:05');

// ── Eigene Namen an den Enden ──────────────────────────────────────────────
$fahrten = TransitCalc::Verbindungen(fixture('strecke-umstieg'), 3);
$benannt = TransitCalc::EndenBenennen($fahrten, 'Zuhause', 'Schule');
pruefe('erstes Ende umbenannt', $benannt[0]['legs'][0]['from'], 'Zuhause');
pruefe('letztes Ende umbenannt', $benannt[0]['legs'][count($benannt[0]['legs']) - 1]['to'], 'Schule');
pruefe('Umstieg dazwischen bleibt, wie er heisst',
    $benannt[0]['legs'][1]['from'], $fahrten[0]['legs'][1]['from']);
pruefe('ohne Namen bleibt alles gleich', TransitCalc::EndenBenennen($fahrten, '', ''), $fahrten);
$nurZiel = TransitCalc::EndenBenennen($fahrten, '', 'Schule');
pruefe('nur das Ziel benannt',
    [$nurZiel[0]['legs'][0]['from'], $nurZiel[0]['legs'][count($nurZiel[0]['legs']) - 1]['to']],
    [$fahrten[0]['legs'][0]['from'], 'Schule']);
pruefe('leere Verbindungsliste', TransitCalc::EndenBenennen([], 'a', 'b'), []);

// ── Adressen und Umkreis ───────────────────────────────────────────────────
$adresse = ['locations' => [
    ['type' => 'street', 'name' => 'Düsseldorf, Musterweg', 'coord' => [51.258054, 6.86798], 'matchQuality' => 250],
    ['type' => 'stop', 'name' => 'Woanders', 'id' => 'de:05111:1', 'coord' => [51.1, 6.1], 'matchQuality' => 900],
    ['type' => 'poi', 'name' => 'Ohne Koordinate', 'coord' => [0, 0], 'matchQuality' => 500],
]];
pruefe('Haltestellen bleiben Haltestellen',
    array_column(TransitCalc::Haltestellen($adresse), 'name'), ['Woanders']);
pruefe('Orte sind alles andere MIT Koordinate',
    array_map(static fn(array $o): array => [$o['name'], $o['lat'], $o['lon']], TransitCalc::Orte($adresse)),
    [['Düsseldorf, Musterweg', 51.258054, 6.86798]]);
$nah = ['locations' => [
    ['type' => 'stop', 'name' => 'Weiter weg', 'id' => 'de:05111:3', 'properties' => ['distance' => 1151]],
    ['type' => 'stop', 'name' => 'Ganz nah', 'id' => 'de:05111:2', 'properties' => ['distance' => 337]],
    ['type' => 'street', 'name' => 'Keine Haltestelle', 'id' => 'x'],
]];
pruefe('Umkreis: nach Entfernung, die naechste zuerst',
    array_map(static fn(array $h): array => [$h['name'], $h['distance']], TransitCalc::Umkreis($nah)),
    [['Ganz nah', 337], ['Weiter weg', 1151]]);


/* Der Fußweg ist die Zahl, die wirklich zählt: nicht „wann fährt der Zug",
   sondern „wann muss ich vom Tisch aufstehen". */
$mitWeg = TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 25);
pruefe('25 Minuten Fußweg: der RE4 in 20 Minuten ist nicht mehr zu schaffen',
    [$mitWeg[2]['leaveIn'], $mitWeg[2]['reachable']], [-5, false]);
$knapp = TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 20);
pruefe('20 Minuten Fußweg: genau erreichbar',
    [$knapp[2]['leaveIn'], $knapp[2]['reachable']], [0, true]);

/* Die EFA liefert von sich aus Abfahrten, die schon weg sind — um 17:02 stand
   eine von 16:50 in der Antwort. Sie gehören nicht auf eine Tafel. */
$spaeter = TransitCalc::Abfahrten(fixture('abfahrten'), strtotime('2026-09-11 16:00:00'));
pruefe('vergangene Abfahrten fallen weg', array_column($spaeter, 'line'), ['RE4']);
pruefe('eine Minute Nachsicht: die Abfahrt „jetzt" bleibt',
    count(TransitCalc::Abfahrten(fixture('abfahrten'), strtotime('2026-09-11 15:54:30'))), 3);
pruefe('nach der Nachsicht ist sie weg',
    count(TransitCalc::Abfahrten(fixture('abfahrten'), strtotime('2026-09-11 15:55:30'))), 2);

/* Mit Fußweg sind die nächsten Abfahrten oft schon verpasst. Höchstens zwei
   davon bleiben stehen; die Obergrenze zählt, was man noch erreicht. */
// 20 Minuten Fußweg: die ersten beiden sind weg, der RE4 in 20 Minuten geht
// gerade noch. Bestellt ist EINE erreichbare — dazu kommen die zwei verpassten.
$gemischt = TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 20, [], 1);
pruefe('zwei verpasste und eine erreichbare',
    array_map(static fn(array $a): bool => $a['reachable'], $gemischt), [false, false, true]);
// 25 Minuten: gar nichts ist mehr zu schaffen — dann bleiben nur die zwei.
pruefe('ist nichts erreichbar, bleiben zwei stehen',
    count(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 25, [], 6)), 2);
// Ohne Fußweg zählt die Obergrenze ganz normal.
pruefe('ohne Fußweg zählt die Obergrenze schlicht',
    count(TransitCalc::Abfahrten(fixture('abfahrten'), $jetzt, 0, [], 2)), 2);

/* Eine unbekannte Haltestelle antwortet mit HTTP 200 und einer leeren Liste,
   nicht mit einem Fehler. Der Aufrufer erkennt den Fall nur hieran. */
pruefe('unbekannte Haltestelle: leere Liste statt Fehler',
    TransitCalc::Abfahrten(fixture('leer'), $jetzt), []);

// ── Verbindungen ───────────────────────────────────────────────────────────
$v = TransitCalc::Verbindungen(fixture('strecke-umstieg'));
pruefe('eine Verbindung', count($v), 1);
pruefe('ein Umstieg', $v[0]['interchanges'], 1);
pruefe('vier Abschnitte: Fahrt, Fußweg, Warten, Fahrt',
    array_column($v[0]['legs'], 'kind'), ['ride', 'walk', 'wait', 'ride']);
pruefe('Linien der beiden Fahrten',
    [$v[0]['legs'][0]['line'], $v[0]['legs'][3]['line']], ['RE1', 'RE5']);

/* Die Wartezeit MUSS mitgezeichnet werden, sonst endet die Zeitachse zu früh:
   ohne sie waren von 33 Minuten nur 86,4 % gedeckt, und die viereinhalb
   Minuten auf dem Bahnsteig kamen nirgends vor. */
$deckung = static function (array $verbindung): float {
    $summe = 0;
    foreach ($verbindung['legs'] as $l) { $summe += $l['seconds']; }
    return round($summe / max(1, $verbindung['seconds']) * 100, 1);
};
pruefe('die Abschnitte decken die ganze Verbindung', $deckung($v[0]), 100.0);
$warten = array_values(array_filter($v[0]['legs'],
    static fn(array $l): bool => $l['kind'] === 'wait'));
pruefe('die Wartezeit steht zwischen Fußweg und Anschluss',
    [$warten[0]['depText'], $warten[0]['arrText'], $warten[0]['seconds']], ['15:51', '15:57', 318]);
pruefe('und sie trägt kein Fahrzeug', [$warten[0]['line'], $warten[0]['icon']],
    ['', 'fa-hourglass-half']);
pruefe('Abfahrt und Ankunft der ganzen Verbindung',
    [$v[0]['departureText'], $v[0]['arrivalText']], ['15:35', '16:05']);

/* Der Fußweg beim Umstieg dauert 240 s und liegt IN der Lücke zwischen 15:47
   und 15:57 — er verlängert die Verbindung nicht. `footPathInfoRedundant` sagt
   das, und in beiden gemessenen Auskünften stand es auf true. Ohne diese
   Beachtung endete der Fußweg nach der Abfahrt des Anschlusses: eine Zeitachse,
   die rückwärts läuft. */
$umstieg = $v[0]['legs'][1];
pruefe('Umstiegsfußweg: vier Minuten', $umstieg['seconds'], 240);
pruefe('Umstiegsfußweg: liegt in der Lücke',
    [$umstieg['depText'], $umstieg['arrText']], ['15:47', '15:51']);

$vorwaerts = static function (array $verbindungen): bool {
    foreach ($verbindungen as $x) {
        $bis = 0;
        foreach ($x['legs'] as $l) {
            if ($l['depAt'] < $bis) {
                return false;
            }
            $bis = $l['arrAt'];
        }
    }
    return true;
};
pruefe('die Zeitachse läuft nie rückwärts (Umstieg)', $vorwaerts($v), true);

$k = TransitCalc::Verbindungen(fixture('strecke-koordinate'));
pruefe('Start an der Haustür: erster Abschnitt ist ein Fußweg',
    [$k[0]['legs'][0]['kind'], $k[0]['legs'][0]['seconds']], ['walk', 360]);
pruefe('die Zeitachse läuft nie rückwärts (ab Koordinate)', $vorwaerts($k), true);
/* Der Fußweg, für den es keine Lücke gibt, fällt weg: er hat keine Zeit
   gekostet, und ein Abschnitt ohne Dauer ist eine Behauptung. */
pruefe('kein Scheinfußweg ohne Lücke',
    array_column($k[0]['legs'], 'kind'), ['walk', 'ride', 'walk', 'wait', 'ride']);
pruefe('auch hier decken die Abschnitte alles', $deckung($k[0]), 100.0);
// Unter einer Minute ist es Rundung, keine Wartezeit — ein Stück von zwei
// Pixeln wäre nur Unruhe.
foreach ($k[0]['legs'] as $l) {
    if ($l['kind'] === 'wait') {
        pruefe('keine Wartezeit unter einer Minute', $l['seconds'] >= 60, true);
    }
}

/* Bei einer Ankunftsvorgabe legt die EFA eine Verbindung dazu, die zu spät
   kommt — zu „bis 08:00" kam neben 07:40 und 07:53 auch eine Ankunft um 08:03.
   Für einen Schulweg ist das keine Alternative, sondern ein Zuspätkommen. */
$anKommt = $v[0]['arrival'];
pruefe('Ankunftsvorgabe genau auf die Ankunft: Verbindung bleibt',
    count(TransitCalc::Verbindungen(fixture('strecke-umstieg'), 3, $anKommt)), 1);
pruefe('Ankunftsvorgabe eine Minute früher: Verbindung fällt weg',
    TransitCalc::Verbindungen(fixture('strecke-umstieg'), 3, $anKommt - 60), []);
pruefe('ohne Vorgabe bleibt sie ohnehin',
    count(TransitCalc::Verbindungen(fixture('strecke-umstieg'), 3, 0)), 1);

// ── Die weisse Liste ───────────────────────────────────────────────────────
/* Eine Streckenauskunft wog roh 247 KB, davon 176 KB `properties`. Was hier
   durchkäme, stünde in jedem Bestand und in jeder Antwort an die App. */
$text = json_encode(TransitCalc::Verbindungen(fixture('strecke-umstieg')), JSON_UNESCAPED_UNICODE);
foreach (['properties', 'coords', 'stopSequence', 'pathDescriptions', 'hints', 'infos'] as $ballast) {
    pruefe("Ballast bleibt draußen: $ballast", str_contains((string)$text, '"' . $ballast . '"'), false);
}
pruefe('und das Ergebnis ist klein', strlen((string)$text) < 4096, true);

// ── Haltestellensuche ──────────────────────────────────────────────────────
/* Die EFA sortiert NICHT nach Güte. In dieser echten Antwort steht der beste
   Treffer (953) ganz hinten, hinter einem mit 166. Ohne Sortierung wählt der
   Nutzer die falsche Haltestelle und sucht den Fehler später im Modul. */
$h = TransitCalc::Haltestellen(fixture('haltestellen'));
pruefe('nur Haltestellen, keine Stadtteile', count($h), 6);
pruefe('der beste Treffer steht vorn', $h[0]['quality'], 953);
pruefe('und der schlechteste hinten', $h[count($h) - 1]['quality'], 166);
pruefe('Kennung und Name kommen mit',
    [$h[0]['id'] !== '', $h[0]['name'] !== ''], [true, true]);
pruefe('Obergrenze greift auch hier', count(TransitCalc::Haltestellen(fixture('haltestellen'), 2)), 2);

// ── Der Schulweg ───────────────────────────────────────────────────────────
/* Ein Montag mit drei Stunden. Die Richtung ergibt sich allein aus der Uhrzeit;
   das ist die ganze Bedienung. */
$stunde = static fn(string $von, string $bis, string $status = '', bool $care = false): array =>
    ['start' => $von, 'end' => $bis, 'status' => $status, 'care' => $care];
$tag = ['date' => '2026-09-14', 'slots' => [
    $stunde('08:00', '08:45'),
    $stunde('08:55', '09:40'),
    $stunde('12:20', '13:05'),
]];
$frueh = strtotime('2026-09-14 07:00:00');
$mittags = strtotime('2026-09-14 09:00:00');
$abends = strtotime('2026-09-14 19:00:00');

$hin = TransitCalc::Schulweg($tag, '2026-09-14', $frueh, 10);
pruefe('morgens: Hinweg', [$hin['direction'], $hin['mode']], ['to', 'arr']);
pruefe('morgens: ankommen zehn Minuten vor Unterrichtsbeginn', $hin['targetTime'], '07:50');
pruefe('morgens: beide Schulzeiten stehen im Ergebnis',
    [$hin['schoolStart'], $hin['schoolEnd']], ['08:00', '13:05']);
pruefe('die Pufferzeit wird mitgegeben', $hin['bufferUsed'], 10);
pruefe('ohne Puffer ist das Ziel der Beginn',
    TransitCalc::Schulweg($tag, '2026-09-14', $frueh, 0)['targetTime'], '08:00');

/* Fällt die erste Stunde aus, darf das Kind später los. Das ist der Fall, für
   den sich die Kopplung an den Stundenplan überhaupt lohnt. */
$ohneErste = $tag;
$ohneErste['slots'][0]['status'] = 'entfall';
$spaeter = TransitCalc::Schulweg($ohneErste, '2026-09-14', $frueh, 10);
pruefe('erste Stunde entfällt: Beginn rutscht auf die zweite', $spaeter['schoolStart'], '08:55');
pruefe('erste Stunde entfällt: Ziel entsprechend später', $spaeter['targetTime'], '08:45');

$rueck = TransitCalc::Schulweg($tag, '2026-09-14', $mittags, 10);
pruefe('ab Unterrichtsbeginn: Rückweg', [$rueck['direction'], $rueck['mode']], ['from', 'dep']);
pruefe('Rückweg: losfahren zehn Minuten nach Schulschluss', $rueck['targetTime'], '13:15');

/* Kommt das Kind später aus dem Gebäude, ist die planmäßige Abfahrt längst weg.
   Dann gilt JETZT — sonst stünde um 15:40 noch der Zug von 13:15 da. */
$verspaetet = strtotime('2026-09-14 15:40:00');
$spaet = TransitCalc::Schulweg($tag, '2026-09-14', $verspaetet, 10);
pruefe('Schulschluss vorbei: Verbindungen ab jetzt',
    [$spaet['direction'], $spaet['targetTime'], $spaet['fromNow']], ['from', '15:40', true]);
pruefe('der Schulschluss selbst bleibt stehen', $spaet['schoolEnd'], '13:05');
pruefe('rechtzeitig gefragt: die planmäßige Zeit gilt',
    [$rueck['targetTime'], $rueck['fromNow']], ['13:15', false]);

/* Und umgekehrt: fällt die letzte Stunde aus, ist früher Schluss. */
$ohneLetzte = $tag;
$ohneLetzte['slots'][2]['status'] = 'entfall';
$frueherHeim = TransitCalc::Schulweg($ohneLetzte, '2026-09-14', $mittags, 10);
pruefe('letzte Stunde entfällt: Schluss ist früher', $frueherHeim['schoolEnd'], '09:40');
pruefe('letzte Stunde entfällt: Rückweg entsprechend früher', $frueherHeim['targetTime'], '09:50');

/* Betreuung zählt mit: ist sie der letzte Eintrag, fährt das Kind danach. */
$mitBetreuung = $tag;
$mitBetreuung['slots'][] = $stunde('13:05', '15:00', '', true);
pruefe('Betreuung bis 15:00: Rückweg ab 15:10',
    TransitCalc::Schulweg($mitBetreuung, '2026-09-14', $mittags, 10)['targetTime'], '15:10');

pruefe('Ferien: kein Schulweg',
    TransitCalc::Schulweg(['holiday' => ['name' => 'Herbstferien'], 'slots' => []], '2026-09-14', $frueh, 10), null);
pruefe('Tag ohne Unterricht: kein Schulweg',
    TransitCalc::Schulweg(['slots' => []], '2026-09-14', $frueh, 10), null);
pruefe('alle Stunden entfallen: kein Schulweg',
    TransitCalc::Schulweg(['slots' => [$stunde('08:00', '08:45', 'entfall')]], '2026-09-14', $frueh, 10), null);

/* Abends ist der Schultag durch — dann gibt dieser Tag nichts mehr her, und der
   Aufrufer fragt den nächsten. Weil mit Zeitstempeln gerechnet wird, liefert
   derselbe Aufruf für morgen von selbst wieder den Hinweg. */
pruefe('abends: dieser Tag ist durch',
    TransitCalc::Schulweg($tag, '2026-09-14', $abends, 10), null);
$morgen = TransitCalc::Schulweg($tag, '2026-09-15', $abends, 10);
pruefe('abends: der nächste Schultag zeigt wieder den Hinweg',
    [$morgen['direction'], $morgen['date'], $morgen['targetTime']], ['to', '2026-09-15', '07:50']);

/* Die Grenze selbst: drei Stunden nach der Rückfahrt gilt sie noch, danach
   nicht mehr. */
$knappVorbei = strtotime('2026-09-14 16:14:00');   // 13:15 + 3 h minus eine Minute
$knappDanach = strtotime('2026-09-14 16:16:00');
pruefe('kurz vor der Nachlaufgrenze: Rückweg gilt noch',
    TransitCalc::Schulweg($tag, '2026-09-14', $knappVorbei, 10)['direction'], 'from');
pruefe('kurz danach: der Tag ist durch',
    TransitCalc::Schulweg($tag, '2026-09-14', $knappDanach, 10), null);

/* ── Start und Ziel einer Strecke ───────────────────────────────────────────
   Zwei Wege fuehren zu einem Punkt: die Auswahlliste mit der Kennung einer
   eingerichteten Haltestelle, oder die Markierung auf der Karte. Der Fall, der
   hier wirklich geprueft gehoert, ist die NULLINSEL: eine nie angefasste
   Karte liefert 0/0, und die EFA sucht darauf klaglos eine Verbindung aus dem
   Atlantik vor Ghana. */
$karte = static fn(float $b, float $l): string => json_encode(['latitude' => $b, 'longitude' => $l]);

pruefe('Auswahl: die Haltestellenkennung gewinnt',
    TransitCalc::Punkt(['from' => 'de:05111:18235', 'fromGeo' => $karte(51.2, 6.7)], 'from'),
    'de:05111:18235');
pruefe('Auswahl „Karte": die Markierung gilt',
    TransitCalc::Punkt(['from' => 'geo', 'fromGeo' => $karte(51.22170, 6.77630)], 'from'),
    '51.22170,6.77630');
pruefe('leere Auswahl mit Markierung: die Markierung gilt',
    TransitCalc::Punkt(['to' => '', 'toGeo' => $karte(51.0, 7.0)], 'to'),
    '51.00000,7.00000');
pruefe('Nullinsel: kein Punkt',
    TransitCalc::Punkt(['from' => 'geo', 'fromGeo' => $karte(0, 0)], 'from'), '');
pruefe('Karte gewaehlt, aber nie gesetzt: kein Punkt',
    TransitCalc::Punkt(['from' => 'geo'], 'from'), '');
pruefe('unsinnige Breite: kein Punkt',
    TransitCalc::Punkt(['from' => 'geo', 'fromGeo' => $karte(96.0, 6.7)], 'from'), '');
pruefe('unsinnige Laenge: kein Punkt',
    TransitCalc::Punkt(['from' => 'geo', 'fromGeo' => $karte(51.2, 200.0)], 'from'), '');
pruefe('gar nichts eingetragen: kein Punkt',
    TransitCalc::Punkt([], 'from'), '');
pruefe('Schrott in der Kartenspalte: kein Punkt',
    TransitCalc::Punkt(['from' => 'geo', 'fromGeo' => 'kaputt'], 'from'), '');
/* Die Reihenfolge ist die der Karten-Apps (Breite, Laenge) — Efa::Ort() dreht
   sie fuer die EFA um. Wer hier tauscht, sucht Verbindungen in Somalia. */
pruefe('Reihenfolge: Breite vor Laenge',
    TransitCalc::Punkt(['from' => 'geo', 'fromGeo' => $karte(51.5, 6.5)], 'from'),
    '51.50000,6.50000');
pruefe('von Hand getippte Koordinate bleibt unveraendert',
    TransitCalc::Punkt(['from' => '51.2217,6.7763'], 'from'), '51.2217,6.7763');

/* ── Haltestellen, Steige, Auslastung ──────────────────────────────────────
   Die drei Angaben traegt nur die ausfuehrliche Zeitachse — und alle drei
   kommen aus Ecken der EFA-Antwort, die man leicht falsch liest. */

$abschnitte = TransitCalc::Verbindungen(fixture('strecke-umstieg'))[0]['legs'];
$fahrt = [];
foreach ($abschnitte as $a) {
    if ($a['kind'] === 'ride') { $fahrt[] = $a; }
}
/* Die Haltestellenfolge im Prueffall ist drei Eintraege lang — Einstieg,
   ein Halt dazwischen, Ausstieg. Gezaehlt werden die Halte NACH dem
   Einsteigen, also zwei. */
pruefe('Haltestellenfolge: eins weniger als Eintraege', $fahrt[0]['stops'], 2);
/* Der zweite Abschnitt hat gar keine Folge: dann steht dort 0 und nicht etwa
   eine geratene Zahl — die Kachel laesst die Angabe dann weg. */
pruefe('ohne Haltestellenfolge: keine Zahl', $fahrt[1]['stops'], 0);
pruefe('Steig beim Einsteigen', $fahrt[0]['platform'], '19');
/* Der Steig am ZIEL stand vorher nirgends — die Zeitachse zeigt ihn an, und er
   kommt aus `destination`, nicht aus `origin`. */
pruefe('Steig beim Aussteigen', $fahrt[0]['platformTo'], '3');
pruefe('keine Auslastung gemeldet: leer', $fahrt[0]['occupancy'], '');
/* Fusswege haben die Felder ebenfalls, sonst liefe die Kachel auf ein
   fehlendes Feld. */
$fuss = null;
foreach ($abschnitte as $a) {
    if ($a['kind'] === 'walk') { $fuss = $a; break; }
}
pruefe('Fussweg hat die Felder auch', $fuss === null ? 'kein Fussweg'
    : [$fuss['stops'], $fuss['platformTo'], $fuss['occupancy']], [0, '', '']);

/* Zwei Steige derselben Haltestelle stehen in der EFA-Folge zweimal
   hintereinander — gemessen am 16.09.2026 an einer echten Antwort. Ungefiltert
   meldete die Kachel einen Halt zu viel. */
$roh = json_decode(json_encode(fixture('strecke-umstieg')), true);
$roh['journeys'][0]['legs'][0]['stopSequence'] = [
    ['name' => 'A'], ['name' => 'B'], ['name' => 'B'], ['name' => 'C'],
];
$roh['journeys'][0]['legs'][0]['origin']['properties']['occupancy'] = 'MANY_SEATS';
$mitDoppel = TransitCalc::Verbindungen($roh)[0]['legs'];
foreach ($mitDoppel as $a) {
    if ($a['kind'] === 'ride') {
        pruefe('doppelter Halt zaehlt einmal', $a['stops'], 2);
        pruefe('Auslastung wird uebersetzt', $a['occupancy'], 'many');
        break;
    }
}
/* Eine Stufe, die wir nicht kennen, wird NICHT durchgereicht: die Kachel hat
   fuer sie keinen Text, und ein roher Schluessel stuende dann in der Anzeige. */
$roh['journeys'][0]['legs'][0]['origin']['properties']['occupancy'] = 'SEHR_VOLL_VIELLEICHT';
foreach (TransitCalc::Verbindungen($roh)[0]['legs'] as $a) {
    if ($a['kind'] === 'ride') {
        pruefe('unbekannte Stufe faellt weg', $a['occupancy'], '');
        break;
    }
}

/* ── Haltestellenfolge fuer die senkrechte Ansicht ────────────────────────── */

$ohne = TransitCalc::Verbindungen(fixture('strecke-umstieg'))[0]['legs'];
$mit  = TransitCalc::Verbindungen(fixture('strecke-umstieg'), 3, 0, false, true)[0]['legs'];
$ersteFahrt = static function (array $abschnitte): array {
    foreach ($abschnitte as $a) {
        if ($a['kind'] === 'ride') { return $a; }
    }
    return [];
};
/* Ohne Anforderung bleibt sie leer — sie waere in jeder Nutzlast totes
   Gewicht, und die Kachel bekommt die Nutzlast jede Minute neu. */
pruefe('Haltestellenfolge nur auf Anforderung', $ersteFahrt($ohne)['halte'], []);
pruefe('angefordert kommt sie mit', count($ersteFahrt($mit)['halte']), 3);
pruefe('je Halt Name und Zeit',
    array_keys($ersteFahrt($mit)['halte'][0]), ['n', 't']);
pruefe('erster Halt ist der Einstieg',
    $ersteFahrt($mit)['halte'][0]['n'], $ersteFahrt($mit)['from']);
/* Derselbe Halt zweimal hintereinander (zwei Steige) zaehlt einmal — wie beim
   Zaehlen auch, sonst stuende er doppelt in der Liste. */
$roh = json_decode(json_encode(fixture('strecke-umstieg')), true);
$roh['journeys'][0]['legs'][0]['stopSequence'] = [
    ['name' => 'A', 'departureTimePlanned' => '2026-09-11T07:00:00Z'],
    ['name' => 'B', 'departureTimePlanned' => '2026-09-11T07:05:00Z'],
    ['name' => 'B', 'departureTimePlanned' => '2026-09-11T07:06:00Z'],
    ['name' => 'C', 'departureTimePlanned' => '2026-09-11T07:10:00Z'],
];
$doppelt = $ersteFahrt(TransitCalc::Verbindungen($roh, 3, 0, false, true)[0]['legs']);
pruefe('doppelter Halt steht einmal in der Liste',
    array_column($doppelt['halte'], 'n'), ['A', 'B', 'C']);
pruefe('Liste und Zaehlung sagen dasselbe',
    count($doppelt['halte']) - 1, $doppelt['stops']);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
