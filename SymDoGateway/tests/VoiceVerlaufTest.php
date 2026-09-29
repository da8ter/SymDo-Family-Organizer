<?php

declare(strict_types=1);

/**
 * Verlaufsfragen im Sprachdialog (29.09.2026): VoiceVerlaufCalc direkt und das
 * Werkzeug verlauf_lesen gegen die Symcon-Stubs samt Stub-Archiv.
 *
 *   php SymDoGateway/tests/VoiceVerlaufTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/VoiceResolve.php';
require_once __DIR__ . '/../libs/VoiceDevices.php';
require_once __DIR__ . '/../libs/VoiceVerlauf.php';

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
    printf("%-4s %-80s%s\n", $ok ? 'OK' : 'FEHL', $name, $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

// ══ 1. Rechnung an einem festen „jetzt": Dienstag, 29.09.2026, 08:30 ═════════
$jetzt = (int)strtotime('2026-09-29 08:30:00');
$f = static fn(int $ts): string => date('Y-m-d H:i', $ts);
$z = static function (string $art, ?string $datum = null) use ($jetzt, $f): array {
    $r = VoiceVerlaufCalc::Zeitraum($art, $datum, $jetzt);
    return $r['ok'] ? [$f($r['von']), $f($r['bis']), $r['stufe'], $r['wort']] : ['FEHLER', $r['fehler']];
};
pruefe('gestern: ganzer Tag, stuendlich', $z('gestern'), ['2026-09-28 00:00', '2026-09-28 23:59', 0, 'yesterday']);
pruefe('letzte Nacht: gestern 22 Uhr bis heute 6 Uhr, ueber Mitternacht', $z('letzte_nacht'),
    ['2026-09-28 22:00', '2026-09-29 05:59', 0, 'last night']);
pruefe('heute: bis jetzt, nicht bis Mitternacht', $z('heute'), ['2026-09-29 00:00', '2026-09-29 08:30', 0, 'today']);
pruefe('diese Woche: ab Montag, taeglich', $z('diese_woche'), ['2026-09-28 00:00', '2026-09-29 08:30', 0, 'this week']);
pruefe('letzte Woche: Montag bis Sonntag davor, taeglich', $z('letzte_woche'), ['2026-09-21 00:00', '2026-09-27 23:59', 1, 'last week']);
pruefe('letzter Monat: August ganz', $z('letzter_monat'), ['2026-08-01 00:00', '2026-08-31 23:59', 1, 'last month']);
pruefe('dieses Jahr: monatlich', $z('dieses_jahr'), ['2026-01-01 00:00', '2026-09-29 08:30', 3, 'this year']);
pruefe('bestimmter Tag: deutscher Wortlaut', $z('tag', '2026-09-22'), ['2026-09-22 00:00', '2026-09-22 23:59', 0, 'am Dienstag, 22. September']);
pruefe('bestimmter Tag = gestern heisst „gestern"', $z('tag', '2026-09-28')[3], 'yesterday');
pruefe('Zukunft, kaputtes Datum, unbekannte Art: Fehler',
    [$z('tag', '2026-10-01'), $z('tag', '2026-02-30'), $z('tag', null), $z('uebermorgen')],
    [['FEHLER', 'zukunft'], ['FEHLER', 'datum'], ['FEHLER', 'datum'], ['FEHLER', 'art']]);
$frueh = VoiceVerlaufCalc::Zeitraum('letzte_nacht', null, (int)strtotime('2026-09-29 03:00:00'));
pruefe('Um 3 Uhr ist die Nacht noch nicht vorbei: sie endet jetzt', $f($frueh['bis']), '2026-09-29 03:00');

$agg = [
    ['TimeStamp' => 3600, 'Duration' => 3600, 'Avg' => 20.0, 'Min' => 19.5, 'MinTime' => 4000, 'Max' => 20.4, 'MaxTime' => 5000],
    ['TimeStamp' => 0,    'Duration' => 1800, 'Avg' => 18.0, 'Min' => 17.2, 'MinTime' => 900,  'Max' => 18.1, 'MaxTime' => 100],
];
$s = VoiceVerlaufCalc::Zusammenfassen($agg, false);
pruefe('Standard: gewichteter Mittelwert, Min/Max mit Zeitpunkt',
    [round($s['mittel'], 4), $s['min'], $s['minZeit'], $s['max'], $s['maxZeit']], [19.3333, 17.2, 900, 20.4, 5000]);
$zz = VoiceVerlaufCalc::Zusammenfassen([['Avg' => 1.5, 'Duration' => 86400], ['Avg' => 2.25, 'Duration' => 86400]], true);
pruefe('Zaehler: Summe der Abschnitte, kein Mittelwert', [$zz['zaehler'], $zz['summe']], [true, 3.75]);
pruefe('Leer bleibt leer', VoiceVerlaufCalc::Zusammenfassen([], false)['leer'], true);

$t = static fn(string $x): string => $x;
$zahl = static fn(float $v): string => number_format($v, 1, ',', '') . ' °C';
$uhr = static fn(int $ts): string => date('G', $ts) . ' Uhr';
pruefe('Satz Standard mit Zeitpunkten bei stuendlicher Stufe',
    VoiceVerlaufCalc::Satz('Kinderzimmer Temperatur', 'letzte Nacht',
        ['leer' => false, 'zaehler' => false, 'mittel' => 19.2, 'min' => 18.5, 'minZeit' => (int)strtotime('2026-09-29 05:10'),
         'max' => 20.1, 'maxZeit' => (int)strtotime('2026-09-28 22:00'), 'abschnitte' => 8], false, $zahl, $uhr, 0, $t),
    'Kinderzimmer Temperatur was letzte Nacht between 18,5 °C (5 Uhr) and 20,1 °C (22 Uhr), on average 19,2 °C.');

// ══ 2. Werkzeug gegen Stubs mit Archiv ══════════════════════════════════════
IPS\ModuleLoader::loadLibrary($stubs . '/CoreStubs/library.json');
$ac = IPS_CreateInstance('{43192F0B-135B-4CE7-A0A7-1475603F3060}');

class VerlaufHarness extends IPSModuleStrict
{
    use VoiceResolve;
    use VoiceDevices;
    use VoiceVerlauf;

    /** @var list<int> */
    public array $roots = [];

    public function Translate(string $Text): string
    {
        static $de = null;
        if ($de === null) {
            $j = json_decode((string)file_get_contents(__DIR__ . '/../locale.json'), true);
            $de = is_array($j) ? ($j['translations']['de'] ?? []) : [];
        }
        return (string)($de[$Text] ?? $Text);
    }
    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    protected function LogMessage(string $Message, int $Type): bool { return true; }
    private function VoiceNorm(string $t): string
    {
        $t = mb_strtolower(trim($t));
        return strtr($t, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }
    private function VoiceErr(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message], 'sag' => $message];
    }
    private function VoiceObjektListe(string $property): array { return $property === 'VoiceDeviceRoots' ? $this->roots : []; }
    private function VoiceGeraetMitRueckfrage(int $objectID): bool { return false; }
    private function VoiceIstKind(array $ctx): bool { return false; }
    private function LoadUsers(): array { return []; }
    /** Woertlich aus VoiceTools.php. */
    private function VoiceGesprocheneUhrzeit(int $ts): string
    {
        return (int)date('G', $ts) . ' Uhr' . ((int)date('i', $ts) !== 0 ? ' ' . date('i', $ts) : '');
    }
    public function pVerlauf(array $args): array
    {
        $this->voiceGeraeteMemo = null;
        return $this->VoiceToolVerlauf($args + ['geraet' => null, 'raum' => null, 'id' => null, 'datum' => null],
            ['userId' => '', 'defaults' => []]);
    }
}

const PRES_VALUE  = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
const PRES_SWITCH = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
function kat(string $name, int $eltern): int
{
    $id = IPS_CreateCategory();
    IPS_SetParent($id, $eltern);
    IPS_SetName($id, $name);
    return $id;
}
function var_(string $name, int $eltern, int $typ, array $pres, mixed $wert): int
{
    $id = IPS_CreateVariable($typ);
    IPS_SetParent($id, $eltern);
    IPS_SetName($id, $name);
    IPS_SetVariableCustomPresentation($id, $pres);
    SetValue($id, $wert);
    return $id;
}
$haus = kat('Haus', 0);
$kind = kat('Kinderzimmer', $haus);
$bad  = kat('Bad', $haus);
$tempKind = var_('Temperatur', $kind, 2, ['PRESENTATION' => PRES_VALUE, 'DIGITS' => 1, 'SUFFIX' => ' °C'], 19.0);
$tempBad  = var_('Temperatur', $bad, 2, ['PRESENTATION' => PRES_VALUE, 'DIGITS' => 1, 'SUFFIX' => ' °C'], 22.0);
$strom    = var_('Stromzähler', $haus, 2, ['PRESENTATION' => PRES_VALUE, 'DIGITS' => 1, 'SUFFIX' => ' kWh'], 1234.5);
$heizung  = var_('Heizung', $bad, 0, ['PRESENTATION' => PRES_SWITCH, 'CAPTION_ON' => 'An', 'CAPTION_OFF' => 'Aus'], false);
$ohneLog  = var_('Luftfeuchte', $kind, 2, ['PRESENTATION' => PRES_VALUE, 'SUFFIX' => ' %'], 50.0);
$aussen   = kat('Garage', 0);
$fremd    = var_('Garagentemperatur', $aussen, 2, ['PRESENTATION' => PRES_VALUE, 'SUFFIX' => ' °C'], 10.0);

$gestern = (int)strtotime('yesterday 00:00');
$stunden = static function (int $ab, int $n, callable $wert): array {
    $r = [];
    for ($i = 0; $i < $n; $i++) {
        $v = (float)$wert($i);
        $r[] = ['TimeStamp' => $ab + $i * 3600, 'Duration' => 3600, 'Avg' => $v, 'Min' => $v - 0.2,
                'MinTime' => $ab + $i * 3600 + 600, 'Max' => $v + 0.2, 'MaxTime' => $ab + $i * 3600 + 1200];
    }
    return $r;
};
foreach ([$tempKind, $tempBad, $strom, $heizung, $fremd] as $v) {
    AC_SetLoggingStatus($ac, $v, true);
}
AC_StubsAddAggregatedValues($ac, $tempKind, 0, $stunden($gestern, 24, fn($i) => 18 + $i * 0.1));
AC_StubsAddAggregatedValues($ac, $tempBad, 0, $stunden($gestern, 24, fn($i) => 22));
AC_SetAggregationType($ac, $strom, 1);
AC_StubsAddAggregatedValues($ac, $strom, 0, $stunden($gestern, 24, fn($i) => 0.5));
AC_StubsAddAggregatedValues($ac, $heizung, 0, $stunden($gestern, 24, fn($i) => $i < 6 ? 1 : 0));
AC_StubsAddAggregatedValues($ac, $fremd, 0, $stunden($gestern, 24, fn($i) => 10));

$hid = IPS\ObjectManager::registerObject(1);
ob_start();
IPS\InstanceManager::createInstance($hid, ['ModuleID' => '{00000000-0000-0000-0000-00000000DE09}', 'ModuleName' => 'VerlaufHarness', 'ModuleType' => 3, 'Class' => 'VerlaufHarness']);
ob_end_clean();
/** @var VerlaufHarness $h */
$h = IPS\InstanceManager::getInstanceInterface($hid);
$h->roots = [$haus];

$r = $h->pVerlauf(['geraet' => 'Temperatur', 'raum' => 'Kinderzimmer', 'zeitraum' => 'gestern']);
pruefe('Name + Raum: Kinderzimmer, gestern, Min/Max/Mittel aus 24 Stunden',
    [$r['ok'], $r['geraet'], $r['min'], $r['max'], $r['mittel']], [true, 'Kinderzimmer Temperatur', 17.8, 20.5, 19.15]);
pruefe('… mit deutschem Satz samt Uhrzeiten', $r['sag'],
    'Kinderzimmer Temperatur lag gestern zwischen 17,8 °C (0 Uhr 10) und 20,5 °C (23 Uhr 20), im Mittel 19,2 °C.');
$r = $h->pVerlauf(['geraet' => 'Stromzähler', 'zeitraum' => 'gestern']);
pruefe('Zaehler: Verbrauch als Summe (24 x 0,5 kWh)', [$r['summe'] ?? null, $r['sag']], [12.0, 'Haus Stromzähler: Gestern 12,0 kWh.']);
$r = $h->pVerlauf(['geraet' => 'Heizung', 'raum' => 'Bad', 'zeitraum' => 'gestern']);
pruefe('Bool: Anteil der Zeit an (6 von 24 Stunden)', $r['sag'], 'Bad Heizung war gestern zu 25 Prozent der Zeit an.');
$r = $h->pVerlauf(['geraet' => 'Luftfeuchte', 'raum' => 'Kinderzimmer', 'zeitraum' => 'gestern']);
pruefe('Nicht archiviert: ehrlicher Satz', [$r['ok'], $r['protokolliert'] ?? null, $r['sag']],
    [true, false, 'Für Kinderzimmer Luftfeuchte wird kein Verlauf aufgezeichnet.']);
$r = $h->pVerlauf(['geraet' => 'Temperatur', 'zeitraum' => 'gestern']);
pruefe('Ohne Raum und zwei Temperaturen: Rueckfrage statt Raten, beide unter den Kandidaten',
    [$r['ok'], $r['error']['code'] ?? '', count(array_filter(array_map('json_encode', $r['kandidaten'] ?? []),
        static fn($k) => str_contains((string)$k, 'Temperatur'))) >= 2],
    [false, 'mehrdeutig', true]);
$r = $h->pVerlauf(['id' => $fremd, 'zeitraum' => 'gestern']);
pruefe('Variable ausserhalb der freigegebenen Wurzel: nicht erlaubt, obwohl archiviert', [$r['ok'], $r['error']['code'] ?? ''], [false, 'nicht_gefunden']);
$r = $h->pVerlauf(['geraet' => 'Temperatur', 'raum' => 'Kinderzimmer', 'zeitraum' => 'letzte_woche']);
pruefe('Keine Daten im Fenster (Stufe Tag leer): „keine aufgezeichneten Werte"', $r['sag'],
    'Für Kinderzimmer Temperatur gibt es letzte Woche keine aufgezeichneten Werte.');
$r = $h->pVerlauf(['geraet' => 'Temperatur', 'raum' => 'Kinderzimmer', 'zeitraum' => 'tag', 'datum' => date('Y-m-d', strtotime('+3 days'))]);
pruefe('Zukunft: Fehler zeitraum', [$r['ok'], $r['error']['code'] ?? ''], [false, 'zeitraum']);

// ══ 3. Verdrahtung ══════════════════════════════════════════════════════════
$tools = (string)file_get_contents(__DIR__ . '/../libs/VoiceTools.php');
$live  = (string)file_get_contents(__DIR__ . '/../libs/VoiceLiveCalc.php');
$kern  = (string)file_get_contents(__DIR__ . '/../libs/voice-core.js');
$mod   = (string)file_get_contents(__DIR__ . '/../module.php');
pruefe('Katalog: verlauf_lesen liest, hinter dem Geraete-Tor',
    str_contains($tools, "'verlauf_lesen' => [\n                'art' => 'lesen', 'tor' => 'geraete',"), true);
pruefe('Dispatch, Anweisung, Live-Delegation, Zeitdeckel, Einbindung',
    [str_contains($tools, "'verlauf_lesen'       => \$this->VoiceToolVerlauf(\$args, \$ctx),"),
     str_contains($tools, '(verlauf_lesen); '), str_contains($live, 'Geräte samt ihrem Verlauf'),
     str_contains($kern, 'verlauf_lesen: 12000'), str_contains($mod, 'use VoiceVerlauf;')],
    [true, true, true, true, true]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
