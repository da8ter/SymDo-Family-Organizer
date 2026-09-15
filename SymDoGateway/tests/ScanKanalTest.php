<?php

declare(strict_types=1);

/**
 * Offline-Pruefstand fuer das Rechenwerk des Scan-Kanals.
 *
 * Der Kanal traegt spaeter Klassenseiten, Mailvorschlaege und Briefing-Texte
 * zwischen zwei Instanz-Spuren hin und her. Was hier schiefgeht, merkt man im
 * Betrieb erst daran, dass ein Ergebnis stillschweigend im Fehlerordner landet
 * — deshalb steht das Rechnen in einer eigenen Klasse ohne IPS und wird hier
 * ohne Kernel geprueft.
 *
 * Braucht keine Symcon-Attrappen: ScanKanalCalc ruft keine einzige
 * IPS-Funktion, fasst keine Datei an und liest keine Uhr. Genau dafuer ist es
 * eine eigene Klasse.
 *
 *   php SymDoGateway/tests/ScanKanalTest.php
 */

require_once __DIR__ . '/../../libs/ScanKanalCalc.php';

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

// ── Namen ──────────────────────────────────────────────────────────────────
$name = ScanKanalCalc::ErgebnisName(1789220157, 4213, 42711, 'probe', '9c1f');
pruefe('Ergebnisname mit fester Breite', $name, '1789220157-004213-42711-probe-9c1f.json');
pruefe('Name zerlegen', ScanKanalCalc::NameZerlegen($name), [
    'basis' => '1789220157-004213-42711-probe-9c1f', 'at' => 1789220157,
    'us' => 4213, 'instanz' => 42711, 'quelle' => 'probe', 'zufall' => '9c1f',
]);
pruefe('Name zerlegen wirkt auch auf vollem Pfad',
    ScanKanalCalc::NameZerlegen('/var/lib/symcon/symdo_scankanal/16011/ergebnis/' . $name)['quelle'], 'probe');
pruefe('Fremde Datei wird nicht zerlegt', ScanKanalCalc::NameZerlegen('irgendwas.json'), null);
pruefe('Unbekannte Quelle wird nicht zerlegt',
    ScanKanalCalc::NameZerlegen('1789220157-004213-1-unfug-9c1f.json'), null);
pruefe('Nebendatei traegt kein .json', ScanKanalCalc::NebenName('1789220157-004213-42711-probe-9c1f', 3),
    '1789220157-004213-42711-probe-9c1f.d3');
pruefe('Zugehoerigkeit: Umschlag, Nebendatei, Grund — ja; Fremdes — nein', [
    ScanKanalCalc::GehoertDazu('b', 'b.json'),
    ScanKanalCalc::GehoertDazu('b', 'b.d12'),
    ScanKanalCalc::GehoertDazu('b', 'b.grund'),
    ScanKanalCalc::GehoertDazu('b', 'bb.json'),
], [true, true, true, false]);
pruefe('Auftragsname', ScanKanalCalc::AuftragName('edu'), 'edu.json');
pruefe('Anspruchsname traegt die Zeit', ScanKanalCalc::NimmtName('edu', 1789220157, 42711),
    'edu.1789220157.42711.nimmt');
pruefe('Anspruch zerlegen', ScanKanalCalc::NimmtZerlegen('edu.1789220157.42711.nimmt'),
    ['quelle' => 'edu', 'at' => 1789220157, 'instanz' => 42711]);

// ── Reihenfolge und Verfall ────────────────────────────────────────────────
$dateien = [
    '/p/1789220157-004213-1-edu-aaaa.json',      // gleiche Sekunde, spaetere Mikrosekunde
    '/p/1789220157-000001-1-edu-bbbb.json',
    '/p/1789220100-999999-9-mail-cccc.json',     // aelter
    '/p/nichtunseres.json',
];
pruefe('Aelteste zuerst, Fremdes faellt raus',
    array_map('basename', ScanKanalCalc::Sortieren($dateien)), [
        '1789220100-999999-9-mail-cccc.json',
        '1789220157-000001-1-edu-bbbb.json',
        '1789220157-004213-1-edu-aaaa.json',
    ]);
// 30 s nach der juengsten Datei, Frist 60 s: nur die 57 s aeltere ist durch.
pruefe('Verfall wird am Namen gemessen, nicht an der Datei',
    array_map('basename', ScanKanalCalc::Verfallen($dateien, 1789220157 + 30, 60)),
    ['1789220100-999999-9-mail-cccc.json']);
pruefe('Ist die Frist ueberall um, faellt alles',
    count(ScanKanalCalc::Verfallen($dateien, 1789220157 + 100, 60)), 3);
pruefe('Nichts verfaellt, solange die Frist traegt',
    ScanKanalCalc::Verfallen($dateien, 1789220157, 86400), []);

// ── Der leere Umschlag ist gueltig ─────────────────────────────────────────
$leer = ScanKanalCalc::LeererUmschlag('probe', 16011, 42711, 1789220157, 'Kanal geprueft');
$g = ScanKanalCalc::PruefeErgebnis($leer, 16011);
pruefe('Leerer Umschlag geht durch', [$g['ok'], $g['fehler']], [true, []]);
pruefe('Leerer Umschlag behaelt seinen Text', $g['umschlag']['status']['text'], 'Kanal geprueft');

// ── Ablehnungen ────────────────────────────────────────────────────────────
pruefe('Fremdes Gateway wird abgelehnt',
    ScanKanalCalc::PruefeErgebnis($leer, 99999)['fehler'], ['fremdes Gateway']);
$alt = $leer; $alt['v'] = 0;
pruefe('Falsche Fassung wird abgelehnt',
    ScanKanalCalc::PruefeErgebnis($alt, 16011)['fehler'], ['falsche Fassung']);
$ohne = $leer; unset($ohne['status']);
pruefe('Ohne Status wird abgelehnt',
    ScanKanalCalc::PruefeErgebnis($ohne, 16011)['fehler'], ['Status fehlt oder ist falsch geformt']);
pruefe('Kein Objekt wird abgelehnt',
    ScanKanalCalc::PruefeErgebnis('nein', 16011)['ok'], false);

// ── Kappen statt ablehnen ──────────────────────────────────────────────────
$voll = $leer;
$voll['quelle'] = 'edu';
$voll['push'] = array_fill(0, 60, ['titel' => 'x']);       // Grenze 50
$voll['kiAufrufe'] = 900;                                   // Grenze 500
$voll['seiten'] = ['keinObjekt', ['ok' => 1]];              // ein Eintrag ohne Form
$voll['spiegel'] = ['a' => 1];                              // gar keine Liste
$voll['unbekannt'] = 'faellt weg';
$v = ScanKanalCalc::PruefeErgebnis($voll, 16011);
pruefe('Ueberlange Listen kappen, statt alles zu verwerfen', [
    $v['ok'], count($v['umschlag']['push']), $v['umschlag']['kiAufrufe'], count($v['umschlag']['seiten']),
], [true, 50, 500, 1]);
pruefe('Kein Feld ohne Liste im Ergebnis', array_key_exists('spiegel', $v['umschlag']), false);
pruefe('Unbekannte Felder fallen weg', array_key_exists('unbekannt', $v['umschlag']), false);
pruefe('Jede Kappung steht im Status', count($v['umschlag']['status']['fehler']), 4);

// ── Auftraege ──────────────────────────────────────────────────────────────
$auftrag = $leer;
$auftrag['quelle'] = 'edu';
$auftrag['auftrag'] = ['anlass' => 'hand', 'alles' => true, 'nur' => ['a', 'b'], 'tage' => 2, 'verweilen' => 0];
$pa = ScanKanalCalc::PruefeAuftrag($auftrag, 16011);
pruefe('Auftrag geht durch und behaelt seinen Block',
    [$pa['ok'], $pa['umschlag']['auftrag']], [true, ['anlass' => 'hand', 'alles' => true, 'nur' => ['a', 'b'], 'tage' => 2, 'verweilen' => 0, 'seiten' => []]]);
pruefe('Auftrag ohne Block bekommt Vorgaben',
    ScanKanalCalc::AuftragBlock([]), ['anlass' => 'timer', 'alles' => false, 'nur' => [], 'tage' => 0, 'verweilen' => 0, 'seiten' => []]);
pruefe('Unbekannter Anlass faellt auf den Zeitgeber zurueck',
    ScanKanalCalc::AuftragBlock(['anlass' => 'unfug'])['anlass'], 'timer');

// ── Verschmelzen ───────────────────────────────────────────────────────────
pruefe('Von Hand schlaegt Zeitgeber, alles bleibt alles',
    ScanKanalCalc::AuftragVerschmelzen(
        ['anlass' => 'hand', 'alles' => true],
        ['anlass' => 'timer', 'alles' => false]),
    ['anlass' => 'hand', 'alles' => true, 'nur' => [], 'tage' => 0, 'verweilen' => 0, 'seiten' => []]);
pruefe('Eine Einschraenkung faellt, sobald einer ohne sie kommt',
    ScanKanalCalc::AuftragVerschmelzen(
        ['anlass' => 'timer', 'nur' => ['seite-1']],
        ['anlass' => 'timer'])['nur'], []);
pruefe('Zwei Einschraenkungen werden vereinigt',
    ScanKanalCalc::AuftragVerschmelzen(
        ['anlass' => 'timer', 'nur' => ['a']],
        ['anlass' => 'timer', 'nur' => ['b', 'a']])['nur'], ['a', 'b']);

/* Die Verweildauer der Selbstprobe. Sie stand einmal NICHT in der weissen
   Liste — dann kam sie beim Scanner nie an, und der Beweislauf des ganzen
   Umbaus („die App bleibt schnell, waehrend der Scanner steht") mass gegen
   eine Spur, die gar nicht belegt war. Deshalb steht sie jetzt hier. */
pruefe('Die Verweildauer kommt durch und wird gedeckelt',
    [ScanKanalCalc::AuftragBlock(['verweilen' => 10])['verweilen'],
     ScanKanalCalc::AuftragBlock(['verweilen' => 9999])['verweilen'],
     ScanKanalCalc::AuftragBlock(['verweilen' => -5])['verweilen']],
    [10, ScanKanalCalc::VERWEIL_MAX, 0]);
pruefe('Von zwei Proben gewinnt die laengere',
    ScanKanalCalc::AuftragVerschmelzen(
        ['anlass' => 'hand', 'verweilen' => 3, 'seiten' => []],
        ['anlass' => 'hand', 'verweilen' => 8, 'seiten' => []])['verweilen'], 8);
pruefe('Der groessere Briefing-Slot gewinnt',
    ScanKanalCalc::AuftragVerschmelzen(['tage' => 0], ['tage' => 1])['tage'], 1);

// ── Die Seitenliste im Auftrag ────────────────────────────────────────────
/* Sie MUSS mit dem Auftrag reisen: die Seiten stehen als Eigenschaft am
   Gateway, und IPS_GetProperty auf eine fremde Instanz zeigt einen nur
   eingetippten, noch nicht uebernommenen Wert nicht. Faellt sie aus der weissen
   Liste, klappert der Scanner gar nichts ab — und es faellt niemandem auf.
   Genau so ist die Verweildauer der Selbstprobe einmal verschwunden. */
$mitSeiten = ScanKanalCalc::AuftragBlock(['seiten' => [
    ['name' => '5b', 'url' => 'https://beispiel.test/s', 'userId' => 'u1'],
]]);
pruefe('Die Seitenliste kommt durch', count($mitSeiten['seiten']), 1);
pruefe('… mit Name, Adresse und Mitglied',
    $mitSeiten['seiten'][0], ['name' => '5b', 'url' => 'https://beispiel.test/s', 'userId' => 'u1']);

/* Der Auftrag ist eine Datei, und was hier durchkommt, ruft der Scanner
   anschliessend im Netz ab. Ein fremdes Schema waere ein Abruf, den niemand
   gewollt hat. */
foreach (['file:///etc/passwd', 'javascript:alert(1)', 'https:///ohne-rechner', ''] as $boese) {
    pruefe('Eine unbrauchbare Adresse faellt weg: ' . ($boese === '' ? '(leer)' : mb_substr($boese, 0, 16)),
        ScanKanalCalc::AuftragBlock(['seiten' => [['url' => $boese]]])['seiten'], []);
}
pruefe('Kein Objekt, keine Seite',
    ScanKanalCalc::AuftragBlock(['seiten' => 'alle'])['seiten'], []);
pruefe('Die Zahl der Seiten ist gedeckelt',
    count(ScanKanalCalc::AuftragBlock(['seiten' => array_fill(0, 200,
        ['url' => 'https://beispiel.test/s'])])['seiten']), ScanKanalCalc::SEITEN_MAX);

/* Beim Verschmelzen gewinnt die JUENGERE Liste, nicht die Vereinigung: das
   Gateway schickt jedes Mal seinen aktuellen Stand mit. Wer vereinigte,
   brachte eine geloeschte Seite mit dem naechsten Auftrag zurueck. */
$a1 = ['seiten' => [['url' => 'https://beispiel.test/alt']]];
$a2 = ['seiten' => [['url' => 'https://beispiel.test/neu']]];
pruefe('Die juengere Seitenliste gewinnt',
    ScanKanalCalc::AuftragVerschmelzen($a1, $a2)['seiten'][0]['url'], 'https://beispiel.test/neu');
/* Eine leere Liste ist keine Aussage, sondern eine fehlende — etwa bei einem
   Auftrag von Hand. Dann bleibt die alte stehen. */
pruefe('Eine fehlende Liste laesst die alte stehen',
    ScanKanalCalc::AuftragVerschmelzen($a1, ['anlass' => 'hand'])['seiten'][0]['url'],
    'https://beispiel.test/alt');

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
