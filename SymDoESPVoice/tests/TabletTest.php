<?php

declare(strict_types=1);

/**
 * Prüfstand „Ton über das Tablet": TabletCalc und die beiden Weiterleitungen
 * (Sprachgerät → Kachel in SymDoESPVoice, Kachel ↔ Browser in SymDoVoice),
 * mit Attrappen der Symcon-Funktionen.
 *
 *   php SymDoESPVoice/tests/TabletTest.php
 */

require_once __DIR__ . '/../libs/TabletCalc.php';

$fehler = 0;
$zahl = 0;
function pruefe(bool $ok, string $was): void
{
    global $fehler, $zahl;
    $zahl++;
    if (!$ok) {
        $fehler++;
        echo "  FEHLT: $was\n";
    }
}

// ── TabletCalc ──────────────────────────────────────────────────────────────
pruefe(TabletCalc::ThemaEreignis('ab12cd34') === 'symdo/esp/ab12cd34/event' && TabletCalc::ThemaMikro('ab12cd34') === 'symdo/esp/ab12cd34/mic', 'Themen');
$f = '/' . TabletCalc::Filter('ab12cd34') . '/';
pruefe(preg_match($f, '{"Topic":"symdo/esp/ab12cd34/mic"}') === 1 && preg_match($f, '{"Topic":"symdo/esp/ab12cd34/status"}') === 1
    && preg_match($f, '{"Topic":"symdo/esp/ab12cd34/event"}') === 1, 'Filter lässt Status, Ereignis und Mikrofon durch');
pruefe(preg_match($f, '{"Topic":"symdo/esp/ffffffff/mic"}') === 0 && preg_match($f, '{"Topic":"symdo/esp/ab12cd34/cmd"}') === 0, 'Filter: fremdes Gerät und eigene Befehle nicht');
pruefe(TabletCalc::Ereignis('{"wake":"0a1b2c3d","hf":true}') === ['art' => 'wake', 'nonce' => '0a1b2c3d', 'hf' => true], 'Ereignis wake mit Freihand');
pruefe(TabletCalc::Ereignis('{"wake":"0a1b2c3d"}')['hf'] === false, 'hf fehlt → Taste');
pruefe(TabletCalc::Ereignis('{"ende":"0a1b2c3d"}') === ['art' => 'ende', 'nonce' => '0a1b2c3d'], 'Ereignis ende');
pruefe(TabletCalc::Ereignis('{"wake":"../x"}') === null && TabletCalc::Ereignis('kaputt') === null && TabletCalc::Ereignis('{"wake":"0A1B2C3D"}') === null, 'ungültige Ereignisse verworfen');
pruefe(TabletCalc::MicWert('start', '0a1b2c3d') === 'start|0a1b2c3d' && TabletCalc::MicWert('nein', '0a1b2c3d') === 'nein|0a1b2c3d', 'mic-Wert start/nein mit Nonce');
pruefe(TabletCalc::MicWert('start', 'x|y') === null && TabletCalc::MicWert('start', '') === null, 'start ohne gültige Nonce → nichts');
pruefe(TabletCalc::MicWert('stop', '') === 'stop' && TabletCalc::MicWert('keep', 'egal') === 'keep' && TabletCalc::MicWert('reboot', '') === null, 'stop/keep ohne Nonce, sonst nichts');
pruefe(TabletCalc::Paket("\x00\x01" . str_repeat("\xff", 1600)) === base64_encode("\x00\x01" . str_repeat("\xff", 1600)), 'Paket → Base64');
pruefe(TabletCalc::Paket("\x00\x01") === null && TabletCalc::Paket(str_repeat('a', TabletCalc::PAKET_MAX + 1)) === null, 'leeres oder übergroßes Paket verworfen');
$s = ['nonce' => '0a1b2c3d', 'gewinner' => ''];
$z = TabletCalc::Zusage($s, '0a1b2c3d', 'fenster1');
pruefe($z['weiter'] && $z['stand']['gewinner'] === 'fenster1', 'erste Zusage gewinnt');
$z2 = TabletCalc::Zusage($z['stand'], '0a1b2c3d', 'fenster2');
pruefe(!$z2['weiter'] && $z2['stand']['gewinner'] === 'fenster1', 'zweite Zusage verliert');
pruefe(!TabletCalc::Zusage($s, 'ffffffff', 'fenster1')['weiter'] && !TabletCalc::Zusage($s, '0a1b2c3d', 'X!')['weiter'], 'falsche Nonce oder Kennung: keine Zusage');
pruefe(TabletCalc::DarSteuern($z['stand'], '0a1b2c3d', 'fenster1') && !TabletCalc::DarSteuern($z['stand'], '0a1b2c3d', 'fenster2'), 'nur der Gewinner darf stoppen');
pruefe(TabletCalc::Bereit(1000, 1070) && !TabletCalc::Bereit(1000, 1100) && !TabletCalc::Bereit(0, 5), 'Lebenszeichen gilt 75 s');

// ── Symcon-Attrappen ───────────────────────────────────────────────────────
const KR_READY = 10103;
$GLOBALS['aufrufe'] = [];
$GLOBALS['instanzen'] = [];   // id => ['modul' => GUID, 'cfg' => []]
function IPS_RequestAction(int $id, string $ident, mixed $wert): void { $GLOBALS['aufrufe'][] = [$id, $ident, $wert]; }
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['instanzen'][$id]); }
function IPS_GetInstance(int $id): array { return ['ModuleInfo' => ['ModuleID' => $GLOBALS['instanzen'][$id]['modul'] ?? '']]; }
function IPS_GetName(int $id): string { return 'Wohnzimmer'; }
function IPS_GetConfiguration(int $id): string { return json_encode($GLOBALS['instanzen'][$id]['cfg'] ?? []); }
function IPS_GetInstanceListByModuleID(string $guid): array
{
    return array_keys(array_filter($GLOBALS['instanzen'], static fn($i) => $i['modul'] === $guid));
}

require_once __DIR__ . '/../libs/TabletWeiterleitung.php';
require_once __DIR__ . '/../../SymDoVoice/libs/EspTablet.php';

const SDEV = '{DDF91F65-36AE-4539-BBFC-6F1F1D943A9E}';
const VOICE = '{1F413A34-452C-4A8D-BEFC-CA7CB9DBB1BB}';

class GeraetAttrappe
{
    use TabletWeiterleitung;
    public int $InstanceID = 20000;
    public int $kachel = 0;
    public array $befehle = [];
    public function ReadPropertyInteger(string $n): int { return $this->kachel; }
    public function Senden(string $cmd, int|string|null $wert = null): bool { $this->befehle[] = [$cmd, $wert]; return true; }
    public function SendDebug(string $a, string $b, int $c): void {}
    public function los(string $json): void { $this->Ereignis($json); }
    public function idDerKachel(): int { return $this->KachelID(); }
}

class KachelAttrappe
{
    use EspTablet;
    public int $InstanceID = 30000;
    public array $attr = ['EspBereit' => 0, 'EspStand' => ''];
    public array $pushes = [];
    public function ReadAttributeInteger(string $n): int { return (int)$this->attr[$n]; }
    public function ReadAttributeString(string $n): string { return (string)$this->attr[$n]; }
    public function WriteAttributeString(string $n, string $w): void { $this->attr[$n] = $w; }
    public function Push(array $d): void { $this->pushes[] = $d; }
    public function wake(string $j): void { $this->EspWake($j); }
    public function mic(string $j): void { $this->EspMic($j); }
}

$GLOBALS['instanzen'] = [
    20000 => ['modul' => SDEV, 'cfg' => ['SpeakerTile' => 30000]],
    30000 => ['modul' => VOICE, 'cfg' => []],
    40000 => ['modul' => '{00000000-0000-0000-0000-000000000000}', 'cfg' => []],
];

// Gerät ohne Kachel: sofort „nein", das Gerät spricht selbst
$g = new GeraetAttrappe();
$g->los('{"wake":"0a1b2c3d","hf":false}');
pruefe($g->befehle === [['mic', 'nein|0a1b2c3d']] && $GLOBALS['aufrufe'] === [], 'ohne gewählte Kachel: sofort nein');
$g->kachel = 40000;
pruefe($g->idDerKachel() === 0, 'nur SymDo-Voice-Kacheln zählen');
$g->kachel = 30000;
$g->befehle = [];
$g->los('{"wake":"0a1b2c3d","hf":true}');
$a = $GLOBALS['aufrufe'][0] ?? [];
pruefe(($a[0] ?? 0) === 30000 && ($a[1] ?? '') === 'EspWake' && json_decode((string)$a[2], true)['nonce'] === '0a1b2c3d' && $g->befehle === [], 'mit Kachel: Weckwort geht an die Kachel');
$GLOBALS['aufrufe'] = [];
$g->los('{"ende":"0a1b2c3d"}');
pruefe(($GLOBALS['aufrufe'][0][1] ?? '') === 'EspEnde', 'Taste am Gerät: Ende an die Kachel');

// Kachel ohne Lebenszeichen lehnt ab
$GLOBALS['aufrufe'] = [];
$k = new KachelAttrappe();
$k->wake('{"sdev":20000,"nonce":"0a1b2c3d","hf":true}');
$a = $GLOBALS['aufrufe'][0] ?? [];
pruefe(($a[0] ?? 0) === 20000 && ($a[1] ?? '') === 'Mic' && json_decode((string)$a[2], true) === ['aktion' => 'nein', 'nonce' => '0a1b2c3d'] && $k->pushes === [], 'kein Browser bereit: Kachel lehnt ab');

// Fremde Instanz darf kein Weckwort einspeisen
$GLOBALS['aufrufe'] = [];
$k->attr['EspBereit'] = time();
$k->wake('{"sdev":40000,"nonce":"0a1b2c3d"}');
pruefe($GLOBALS['aufrufe'] === [] && $k->pushes === [], 'Weckwort nur von einem Gerät, das diese Kachel gewählt hat');

// Bereit: anbieten, erster gewinnt, zweiter erfährt den Gewinner
$k->wake('{"sdev":20000,"nonce":"0a1b2c3d","hf":true,"name":"Wohnzimmer"}');
pruefe(($k->pushes[0]['type'] ?? '') === 'espWake' && $k->pushes[0]['nonce'] === '0a1b2c3d' && $k->pushes[0]['hf'] === true, 'bereit: Weckwort an die Browser');
$k->mic('{"aktion":"start","nonce":"0a1b2c3d","client":"fenstera"}');
$k->mic('{"aktion":"start","nonce":"0a1b2c3d","client":"fensterb"}');
$starts = array_values(array_filter($GLOBALS['aufrufe'], static fn($x) => $x[1] === 'Mic'));
pruefe(count($starts) === 1 && json_decode((string)$starts[0][2], true) === ['aktion' => 'start', 'nonce' => '0a1b2c3d'], 'nur eine Zusage geht ans Gerät');
$gew = array_values(array_filter($k->pushes, static fn($p) => $p['type'] === 'espGewaehlt'));
pruefe(count($gew) === 2 && $gew[0]['client'] === 'fenstera' && $gew[1]['client'] === 'fenstera', 'beide Fenster erfahren: A führt');
$GLOBALS['aufrufe'] = [];
$k->mic('{"aktion":"stop","nonce":"0a1b2c3d","client":"fensterb"}');
pruefe($GLOBALS['aufrufe'] === [], 'Verlierer kann nicht stoppen');
$k->mic('{"aktion":"keep","nonce":"0a1b2c3d","client":"fenstera"}');
$k->mic('{"aktion":"stop","nonce":"0a1b2c3d","client":"fenstera"}');
pruefe(count($GLOBALS['aufrufe']) === 2 && json_decode((string)$GLOBALS['aufrufe'][1][2], true)['aktion'] === 'stop' && $k->attr['EspStand'] === '', 'Gewinner: keep und stop gehen ans Gerät, danach ist die Anfrage zu');
$GLOBALS['aufrufe'] = [];
$k->mic('{"aktion":"start","nonce":"0a1b2c3d","client":"fensterc"}');
pruefe($GLOBALS['aufrufe'] === [], 'nach dem Ende öffnet eine späte Zusage kein Mikrofon');

// Riegel auf die Modul-Dateien
$sdev = (string)file_get_contents(__DIR__ . '/../module.php');
$voice = (string)file_get_contents(__DIR__ . '/../../SymDoVoice/module.php');
pruefe(str_contains($sdev, 'SetReceiveDataFilter(TabletCalc::Filter($geraet))'), 'SDEV empfängt Ereignis und Mikrofon');
pruefe(str_contains($sdev, "case 'Mic':") && str_contains($sdev, "\$this->Senden('mic', \$wert)"), 'Mic-Befehle gehen signiert ans Gerät');
pruefe(str_contains($voice, "case 'EspHier':") && str_contains($voice, "'espMikro'     => \$this->EspGeraete() !== []"), 'Kachel: Lebenszeichen und Zustand espMikro');
pruefe(str_contains($voice, "esp-mikro.js"), 'Kachel liefert esp-mikro.js mit');

echo ($fehler === 0 ? 'OK' : 'FEHLER') . ": $zahl Prüfungen, $fehler fehlgeschlagen\n";
exit($fehler === 0 ? 0 : 1);
