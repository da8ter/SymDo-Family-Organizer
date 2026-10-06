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
$K = str_repeat('ab', 32);
$JETZT = 1791316306000;
function ereignis(string $art, string $nonce, ?bool $hf, int $ts, string $k): string
{
    $e = [$art => $nonce];
    if ($hf !== null) {
        $e['hf'] = $hf;
    }
    $e['ts'] = $ts;
    $e['mac'] = hash_hmac('sha256', TabletCalc::EreignisText($art, $nonce, $hf, $ts), (string)hex2bin($k));
    return (string)json_encode($e);
}
pruefe(TabletCalc::Ereignis(ereignis('wake', '0a1b2c3d', true, $JETZT, $K), $K, $JETZT) === ['art' => 'wake', 'nonce' => '0a1b2c3d', 'hf' => true, 'ts' => $JETZT], 'Ereignis wake, signiert');
pruefe(TabletCalc::Ereignis(ereignis('wake', '0a1b2c3d', false, $JETZT, $K), $K, $JETZT)['hf'] === false, 'Taste statt Weckwort');
pruefe(TabletCalc::Ereignis(ereignis('ende', '0a1b2c3d', null, $JETZT, $K), $K, $JETZT + 5000)['art'] === 'ende', 'Ereignis ende, signiert');
pruefe(TabletCalc::EreignisText('wake', '0a1b2c3d', true, 5) === 'wake|0a1b2c3d|1|5' && TabletCalc::EreignisText('ende', '0a1b2c3d', null, 5) === 'ende|0a1b2c3d|5',
    'Signaturtext wie in tablet.c');
pruefe(TabletCalc::Ereignis('{"wake":"0a1b2c3d","hf":true}', $K, $JETZT) === null, 'unsigniertes Ereignis (anderes Gerät am Server) abgewiesen');
pruefe(TabletCalc::Ereignis(ereignis('wake', '0a1b2c3d', true, $JETZT, str_repeat('cd', 32)), $K, $JETZT) === null, 'fremder Schlüssel abgewiesen');
$gefaelscht = json_decode(ereignis('wake', '0a1b2c3d', false, $JETZT, $K), true);
$gefaelscht['hf'] = true;
pruefe(TabletCalc::Ereignis((string)json_encode($gefaelscht), $K, $JETZT) === null, 'veränderter Inhalt abgewiesen');
pruefe(TabletCalc::Ereignis(ereignis('wake', '0a1b2c3d', true, $JETZT - 61000, $K), $K, $JETZT) === null, 'zu alter Zeitstempel abgewiesen');
pruefe(TabletCalc::Ereignis(ereignis('wake', '../x', true, $JETZT, $K), $K, $JETZT) === null && TabletCalc::Ereignis('kaputt', $K, $JETZT) === null
    && TabletCalc::Ereignis(ereignis('wake', '0a1b2c3d', true, $JETZT, $K), '', $JETZT) === null, 'ungültige Ereignisse verworfen');
pruefe(TabletCalc::MicWert('start', '0a1b2c3d') === 'start|0a1b2c3d' && TabletCalc::MicWert('nein', '0a1b2c3d') === 'nein|0a1b2c3d', 'mic-Wert start/nein mit Nonce');
pruefe(TabletCalc::MicWert('start', 'x|y') === null && TabletCalc::MicWert('start', '') === null, 'start ohne gültige Nonce → nichts');
pruefe(TabletCalc::MicWert('stop', '') === 'stop' && TabletCalc::MicWert('keep', 'egal') === 'keep' && TabletCalc::MicWert('reboot', '') === null, 'stop/keep ohne Nonce, sonst nichts');
function geraetPaket(int $nr, string $ton, string $nonce, string $k): string
{
    $n = chr($nr >> 8) . chr($nr & 0xff);
    return $n . substr(hash_hmac('sha256', $nonce . $n . $ton, (string)hex2bin($k), true), 0, 8) . $ton;
}
$ton = str_repeat("\x7f", 1600);
pruefe(TabletCalc::Paket(geraetPaket(258, $ton, '0a1b2c3d', $K), '0a1b2c3d', $K) === base64_encode("\x01\x02" . $ton), 'gültiges Paket → Nummer + Ton, ohne HMAC');
pruefe(TabletCalc::Paket(geraetPaket(258, $ton, '0a1b2c3d', $K), 'ffffffff', $K) === null, 'Paket eines anderen Gesprächs (Wiederholung) abgewiesen');
$verfaelscht = geraetPaket(258, $ton, '0a1b2c3d', $K);
$verfaelscht[20] = "\x00";
pruefe(TabletCalc::Paket($verfaelscht, '0a1b2c3d', $K) === null, 'veränderter Ton abgewiesen');
pruefe(TabletCalc::Paket("\x00\x01" . $ton, '0a1b2c3d', $K) === null, 'Paket ohne HMAC (anderes Gerät) abgewiesen');
pruefe(TabletCalc::Paket(str_repeat('a', TabletCalc::PAKET_MAX + 1), '0a1b2c3d', $K) === null && TabletCalc::Paket('abc', '0a1b2c3d', $K) === null, 'übergroß oder zu kurz verworfen');
$v = TabletCalc::Verschluesseln(base64_encode("\x01\x02" . $ton), $K);
pruefe($v !== null && strlen((string)base64_decode($v['n'])) === 24
    && sodium_crypto_stream_xchacha20_xor((string)base64_decode($v['d']), (string)base64_decode($v['n']), (string)hex2bin($K)) === "\x01\x02" . $ton
    && base64_decode($v['d']) !== "\x01\x02" . $ton, 'Ton zur Kachel: XChaCha20 mit dem Schlüssel des Browsers');
pruefe(TabletCalc::Verschluesseln('AAAA', 'kurz') === null, 'ohne gültigen Schlüssel geht kein Ton hinaus');
$s = ['nonce' => '0a1b2c3d', 'gewinner' => ''];
$z = TabletCalc::Zusage($s, '0a1b2c3d', 'fenster1');
pruefe($z['weiter'] && $z['stand']['gewinner'] === 'fenster1', 'erste Zusage gewinnt');
$z2 = TabletCalc::Zusage($z['stand'], '0a1b2c3d', 'fenster2');
pruefe(!$z2['weiter'] && $z2['stand']['gewinner'] === 'fenster1', 'zweite Zusage verliert');
pruefe(!TabletCalc::Zusage($s, 'ffffffff', 'fenster1')['weiter'] && !TabletCalc::Zusage($s, '0a1b2c3d', 'X!')['weiter'], 'falsche Nonce oder Kennung: keine Zusage');
$mitGeheim = $z['stand'] + ['geheim' => str_repeat('5e', 16)];
pruefe(TabletCalc::DarSteuern($mitGeheim, '0a1b2c3d', 'fenster1', str_repeat('5e', 16)) && !TabletCalc::DarSteuern($mitGeheim, '0a1b2c3d', 'fenster2', str_repeat('5e', 16)), 'nur der Gewinner darf stoppen');
pruefe(!TabletCalc::DarSteuern($mitGeheim, '0a1b2c3d', 'fenster1', '') && !TabletCalc::DarSteuern($mitGeheim, '0a1b2c3d', 'fenster1', str_repeat('00', 16))
    && !TabletCalc::DarSteuern($z['stand'], '0a1b2c3d', 'fenster1', ''), 'die (allen bekannte) Fensterkennung allein reicht nicht');
pruefe(TabletCalc::GeheimGueltig(str_repeat('ab', 16)) && !TabletCalc::GeheimGueltig('ab') && !TabletCalc::GeheimGueltig(str_repeat('AB', 16)), 'Geheimnis: 16 Byte Hex');
pruefe(TabletCalc::Bereit(1000, 1070) && !TabletCalc::Bereit(1000, 1100) && !TabletCalc::Bereit(0, 5), 'Lebenszeichen gilt 75 s');

// ── Symcon-Attrappen ───────────────────────────────────────────────────────
const KR_READY = 10103;
$GLOBALS['aufrufe'] = [];
$GLOBALS['instanzen'] = [];   // id => ['modul' => GUID, 'cfg' => []]
function SDEV_MicSteuern(int $id, string $json): void { $GLOBALS['aufrufe'][] = [$id, 'Mic', $json]; }
function SDVC_GeraetWeckruf(int $id, string $json): void { $GLOBALS['aufrufe'][] = [$id, 'EspWake', $json]; }
function SDVC_GeraetTon(int $id, string $paket): void { $GLOBALS['aufrufe'][] = [$id, 'EspAudio', $paket]; }
function SDVC_GeraetEnde(int $id, string $json): void { $GLOBALS['aufrufe'][] = [$id, 'EspEnde', $json]; }
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
    public array $attr = ['CmdKey' => '', 'MicNonce' => '', 'MicNoncen' => '[]'];
    public function ReadPropertyInteger(string $n): int { return $this->kachel; }
    public function ReadAttributeString(string $n): string { return (string)$this->attr[$n]; }
    public function WriteAttributeString(string $n, string $w): void { $this->attr[$n] = $w; }
    public function ton(string $roh): void { $this->MikroPaket($roh); }
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
    public function SendDebug(string $a, string $b, int $c): void {}
    public function wake(string $j): void { $this->GeraetWeckruf($j); }
    public function mic(string $j): void { $this->EspMic($j); }
}

$GLOBALS['instanzen'] = [
    20000 => ['modul' => SDEV, 'cfg' => ['SpeakerTile' => 30000]],
    30000 => ['modul' => VOICE, 'cfg' => []],
    40000 => ['modul' => '{00000000-0000-0000-0000-000000000000}', 'cfg' => []],
];

$jetzt = static fn(): int => (int)floor(microtime(true) * 1000);
// Gerät ohne Kachel: sofort „nein", das Gerät spricht selbst
$g = new GeraetAttrappe();
$g->attr['CmdKey'] = $K;
$g->los('{"wake":"0a1b2c3d","hf":false}');
pruefe($g->befehle === [] && $GLOBALS['aufrufe'] === [], 'unsignierter Weckruf: gar nichts');
$g->los(ereignis('wake', '0a1b2c3d', false, $jetzt(), $K));
pruefe($g->befehle === [['mic', 'nein|0a1b2c3d']] && $GLOBALS['aufrufe'] === [], 'ohne gewählte Kachel: sofort nein');
$g->kachel = 40000;
pruefe($g->idDerKachel() === 0, 'nur SymDo-Voice-Kacheln zählen');
$g->kachel = 30000;
$g->befehle = [];
$g->los(ereignis('wake', '0a1b2c3d', true, $jetzt(), $K));
pruefe($g->befehle === [] && $GLOBALS['aufrufe'] === [], 'Wiederholung eines benutzten Weckrufs: abgewiesen');
$g->los(ereignis('wake', '1a1b2c3d', true, $jetzt(), $K));
$a = $GLOBALS['aufrufe'][0] ?? [];
pruefe(($a[0] ?? 0) === 30000 && ($a[1] ?? '') === 'EspWake' && json_decode((string)$a[2], true)['nonce'] === '1a1b2c3d' && $g->befehle === [], 'mit Kachel: Weckwort geht an die Kachel');
$GLOBALS['aufrufe'] = [];
$g->ton(geraetPaket(1, $ton, '1a1b2c3d', $K));
$g->ton("\x00\x02" . $ton);
$g->ton(geraetPaket(3, $ton, '0a1b2c3d', $K));
pruefe(count($GLOBALS['aufrufe']) === 1 && $GLOBALS['aufrufe'][0][1] === 'EspAudio' && $GLOBALS['aufrufe'][0][2] === base64_encode("\x00\x01" . $ton),
    'nur signierte Pakete des laufenden Gesprächs gehen zur Kachel');
$GLOBALS['aufrufe'] = [];
$g->los(ereignis('ende', '1a1b2c3d', null, $jetzt(), $K));
pruefe(($GLOBALS['aufrufe'][0][1] ?? '') === 'EspEnde' && $g->attr['MicNonce'] === '', 'Taste am Gerät: Ende an die Kachel, Gespräch geschlossen');
$GLOBALS['aufrufe'] = [];
$g->ton(geraetPaket(4, $ton, '1a1b2c3d', $K));
pruefe($GLOBALS['aufrufe'] === [], 'nach dem Ende keine Pakete mehr');

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
$k->mic('{"aktion":"start","nonce":"0a1b2c3d","client":"fensterx"}');
pruefe($GLOBALS['aufrufe'] === [], 'Zusage ohne Schlüssel zählt nicht');
$SA = str_repeat('1a', 32);
$GA = str_repeat('3c', 16);
$k->mic('{"aktion":"start","nonce":"0a1b2c3d","client":"fensterx","schluessel":"' . $SA . '"}');
pruefe($GLOBALS['aufrufe'] === [], 'Zusage ohne Geheimnis zählt nicht');
$k->mic('{"aktion":"start","nonce":"0a1b2c3d","client":"fenstera","schluessel":"' . $SA . '","geheim":"' . $GA . '"}');
$k->mic('{"aktion":"start","nonce":"0a1b2c3d","client":"fensterb","schluessel":"' . str_repeat('2b', 32) . '","geheim":"' . str_repeat('4d', 16) . '"}');
$starts = array_values(array_filter($GLOBALS['aufrufe'], static fn($x) => $x[1] === 'Mic'));
pruefe(count($starts) === 1 && json_decode((string)$starts[0][2], true) === ['aktion' => 'start', 'nonce' => '0a1b2c3d'], 'nur eine Zusage geht ans Gerät');
$gew = array_values(array_filter($k->pushes, static fn($p) => $p['type'] === 'espGewaehlt'));
pruefe(count($gew) === 4 && $gew[2]['client'] === 'fenstera' && $gew[3]['client'] === 'fenstera', 'alle Fenster erfahren: A führt');
pruefe(!str_contains((string)json_encode($k->pushes), $GA) && !str_contains((string)json_encode($k->pushes), $SA), 'Schlüssel und Geheimnis von A werden nie gepusht');
$k->pushes = [];
$k->GeraetTon(base64_encode("\x00\x05" . $ton));
$p = $k->pushes[0] ?? [];
pruefe(($p['type'] ?? '') === 'espAudio' && !isset($p['d']) === false
    && sodium_crypto_stream_xchacha20_xor((string)base64_decode($p['d']), (string)base64_decode($p['n']), (string)hex2bin($SA)) === "\x00\x05" . $ton
    && !str_contains((string)base64_decode($p['d']), str_repeat("\x7f", 64)), 'Ton geht nur mit dem Schlüssel von A lesbar hinaus');
$GLOBALS['aufrufe'] = [];
$k->mic('{"aktion":"stop","nonce":"0a1b2c3d","client":"fensterb","geheim":"' . str_repeat('4d', 16) . '"}');
pruefe($GLOBALS['aufrufe'] === [], 'Verlierer kann nicht stoppen');
$k->mic('{"aktion":"keep","nonce":"0a1b2c3d","client":"fenstera"}');
$k->mic('{"aktion":"stop","nonce":"0a1b2c3d","client":"fenstera","geheim":"' . str_repeat('00', 16) . '"}');
pruefe($GLOBALS['aufrufe'] === [], 'mit der gepushten Kennung von A, aber ohne sein Geheimnis: weder keep noch stop');
$k->mic('{"aktion":"keep","nonce":"0a1b2c3d","client":"fenstera","geheim":"' . $GA . '"}');
$k->mic('{"aktion":"stop","nonce":"0a1b2c3d","client":"fenstera","geheim":"' . $GA . '"}');
pruefe(count($GLOBALS['aufrufe']) === 2 && json_decode((string)$GLOBALS['aufrufe'][1][2], true)['aktion'] === 'stop' && $k->attr['EspStand'] === '', 'Gewinner: keep und stop gehen ans Gerät, danach ist die Anfrage zu');
$GLOBALS['aufrufe'] = [];
$k->mic('{"aktion":"start","nonce":"0a1b2c3d","client":"fensterc"}');
pruefe($GLOBALS['aufrufe'] === [], 'nach dem Ende öffnet eine späte Zusage kein Mikrofon');

// Riegel auf die Modul-Dateien
$sdev = (string)file_get_contents(__DIR__ . '/../module.php');
$voice = (string)file_get_contents(__DIR__ . '/../../SymDoVoice/module.php');
pruefe(str_contains($sdev, 'SetReceiveDataFilter(TabletCalc::Filter($geraet))'), 'SDEV empfängt Ereignis und Mikrofon');
$weiter = (string)file_get_contents(__DIR__ . '/../libs/TabletWeiterleitung.php');
pruefe(!str_contains($sdev, "case 'Mic':") && str_contains($weiter, 'public function MicSteuern(string $Json): void'), 'Gerätebefehle nur über SDEV_MicSteuern, nicht über RequestAction');
pruefe(str_contains($voice, "case 'EspHier':") && str_contains($voice, "case 'EspMic':") && !preg_match("/case 'Esp(Wake|Audio|Ende)'/", $voice),
    'Kachel: der Browser erreicht nur EspHier und EspMic');
pruefe(!str_contains($weiter, 'IPS_RequestAction') && !str_contains((string)file_get_contents(__DIR__ . '/../../SymDoVoice/libs/EspTablet.php'), 'IPS_RequestAction'),
    'zwischen Gerät und Kachel kein RequestAction');
pruefe(str_contains($voice, "esp-mikro.js"), 'Kachel liefert esp-mikro.js mit');

echo ($fehler === 0 ? 'OK' : 'FEHLER') . ": $zahl Prüfungen, $fehler fehlgeschlagen\n";
exit($fehler === 0 ? 0 : 1);
