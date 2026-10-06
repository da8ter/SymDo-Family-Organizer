<?php

declare(strict_types=1);

/**
 * Prüfstand: Sprachprofil gekoppelter Sprachgeräte (ESP32, 06.10.2026).
 *
 * Teil 1: der Rechenkern (VoiceGeraetProfilCalc). Teil 2: Riegel auf die
 * Verdrahtung in Voice.php, VoiceTools.php und DeviceRegistry.php — dass das
 * Profil den Rumpf überschreibt, die Anweisung den Raum kennt und ein Gerät
 * ohne Schalterlaubnis auch beim direkten Werkzeugaufruf abgewiesen wird.
 *
 *   php SymDoGateway/tests/VoiceGeraetProfilTest.php
 */

require_once __DIR__ . '/../libs/VoiceGeraetProfilCalc.php';

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

// ── Teil 1: Rechenkern ──────────────────────────────────────────────────────
$n = VoiceGeraetProfilCalc::Normalisieren(['userId' => ' u1 ', 'raum' => ' Küche ', 'geraete' => false]);
pruefe($n === ['userId' => 'u1', 'raum' => 'Küche', 'geraete' => false], 'Normalisieren trimmt und übernimmt geraete=false');
pruefe(VoiceGeraetProfilCalc::Normalisieren(null)['geraete'] === true, 'ohne Angabe ist Schalten erlaubt');
pruefe(VoiceGeraetProfilCalc::Normalisieren(['geraete' => 0])['geraete'] === true, 'nur echtes false sperrt');
pruefe(mb_strlen(VoiceGeraetProfilCalc::Normalisieren(['raum' => str_repeat('x', 200)])['raum']) === 60, 'Raum wird gekappt');

$app = ['id' => 'aaaa1111', 'platform' => 'ios'];
$esp = ['id' => 'bbbb2222', 'platform' => 'esp32', 'voice' => ['userId' => 'kind', 'raum' => 'Kinderzimmer', 'geraete' => false]];
$rumpf = ['action' => 'open', 'userId' => 'papa', 'tile' => 12345, 'defaults' => ['shopping' => 1]];

pruefe(VoiceGeraetProfilCalc::Anwenden($rumpf, $app) === $rumpf, 'App ohne Profil bleibt unberührt');
pruefe(VoiceGeraetProfilCalc::Anwenden($rumpf, null) === $rumpf, 'ohne Gerät unberührt');
$neu = VoiceGeraetProfilCalc::Anwenden($rumpf, $esp);
pruefe($neu['userId'] === 'kind', 'Profil überschreibt das Mitglied aus dem Rumpf');
pruefe($neu['tile'] === VoiceGeraetProfilCalc::TileVon('bbbb2222') && $neu['tile'] !== 12345, 'Kachel kommt vom Gerät');
pruefe($neu['_raum'] === 'Kinderzimmer' && $neu['_geraete'] === false, 'Raum und Schalterlaubnis werden mitgegeben');
pruefe(!isset($neu['defaults']), 'Listen-Vorgaben aus dem Rumpf fallen weg');
pruefe($neu['action'] === 'open', 'Aktion bleibt');

$t = VoiceGeraetProfilCalc::TileVon('bbbb2222');
pruefe($t === VoiceGeraetProfilCalc::TileVon('bbbb2222'), 'Kachel-Kennung ist stabil');
pruefe($t >= 1000000 && $t < 2000000, 'Kachel-Kennung liegt oberhalb echter Objekt-IDs');
pruefe($t !== VoiceGeraetProfilCalc::TileVon('cccc3333'), 'verschiedene Geräte, verschiedene Kacheln');

pruefe(VoiceGeraetProfilCalc::RaumHinweis('') === '', 'ohne Raum kein Hinweis');
pruefe(str_contains(VoiceGeraetProfilCalc::RaumHinweis('Küche'), '„Küche"'), 'Hinweis nennt den Raum');

// ── Teil 2: Verdrahtung ─────────────────────────────────────────────────────
$voice = (string)file_get_contents(__DIR__ . '/../libs/Voice.php');
$tools = (string)file_get_contents(__DIR__ . '/../libs/VoiceTools.php');
$reg   = (string)file_get_contents(__DIR__ . '/../libs/DeviceRegistry.php');

$handle = substr($voice, (int)strpos($voice, 'function VoiceHandleAction'), 600);
pruefe(str_contains($handle, 'VoiceGeraetProfilCalc::Anwenden($body, $device)')
    && strpos($handle, 'Anwenden') < strpos($handle, "\$action = "), 'Profil greift VOR der Verteilung auf die Aktionen');
pruefe(str_contains($voice, "\$this->VoiceInstructions(\$userId, \$raum, \$geraete)"), 'Realtime-Anweisung bekommt Raum und Schalterlaubnis');
pruefe(str_contains($voice, "'backendAnweisung' => \$this->VoiceInstructions(\$userId, (string)(\$body['_raum']"), 'Live-Anweisung bekommt Raum und Schalterlaubnis');
pruefe(str_contains($voice, "'geraete'   => (\$body['_geraete'] ?? true) !== false"), 'Sitzung merkt sich die Schalterlaubnis');
pruefe(str_contains($voice, "\$ctx['geraete'] ="), 'Werkzeugkontext trägt die Schalterlaubnis');

$run = substr($tools, (int)strpos($tools, 'function VoiceRunTool'), 1800);
pruefe(str_contains($run, "(\$ctx['geraete'] ?? true) === false") && strpos($run, "ctx['geraete']") < strpos($run, 'VoiceGeraeteTorZu'),
    'Gerätewerkzeuge werden ohne Schalterlaubnis vor allem anderen abgewiesen');
pruefe(str_contains($tools, '$geraete = $geraeteErlaubt && $this->VoiceGeraeteOk();'), 'ohne Erlaubnis kennt die Anweisung keine Geräte');
pruefe(str_contains($tools, 'VoiceGeraetProfilCalc::RaumHinweis($raum)'), 'Anweisung nennt den Raum des Geräts');

pruefe(str_contains($reg, 'public function SetDeviceVoiceProfile(string $DeviceId, string $Profile): bool'), 'TGW_SetDeviceVoiceProfile existiert');
pruefe(str_contains($reg, 'VoiceGeraetProfilCalc::Normalisieren($daten)'), 'gespeichertes Profil wird normalisiert');

echo ($fehler === 0 ? 'OK' : 'FEHLER') . ": $zahl Prüfungen, $fehler fehlgeschlagen\n";
exit($fehler === 0 ? 0 : 1);
