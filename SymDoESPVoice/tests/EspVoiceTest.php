<?php

declare(strict_types=1);

/**
 * Prüfstand SymDoESPVoice: Rechenkern (EspStatusCalc) und Riegel auf module.php.
 *
 *   php SymDoESPVoice/tests/EspVoiceTest.php
 */

require_once __DIR__ . '/../libs/EspStatusCalc.php';

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

// ── Themen und Geräte-ID ────────────────────────────────────────────────────
pruefe(EspStatusCalc::ThemaStatus('e51f5bc1') === 'symdo/esp/e51f5bc1/status', 'Statusthema');
pruefe(EspStatusCalc::ThemaBefehl('e51f5bc1') === 'symdo/esp/e51f5bc1/cmd', 'Befehlsthema');
pruefe(EspStatusCalc::GeraetGueltig('e51f5bc1'), 'Gateway-ID gültig');
pruefe(!EspStatusCalc::GeraetGueltig('e51f5bc1/#') && !EspStatusCalc::GeraetGueltig('') && !EspStatusCalc::GeraetGueltig('E51F5BC1'),
    'keine Platzhalter, nichts Leeres, nur Kleinbuchstaben-Hex im Thema');

// ── Statusmeldung ───────────────────────────────────────────────────────────
$s = EspStatusCalc::Status('{"online":true,"akku":93,"laedt":false,"zustand":2,"lautstaerke":70,"helligkeit":75,"frage":"Wie heiße ich?","antwort":"Max","version":"fe17b44"}');
pruefe($s === ['online' => true, 'akku' => 93, 'lautstaerke' => 70, 'helligkeit' => 75, 'zustand' => 2, 'laedt' => false,
               'frage' => 'Wie heiße ich?', 'antwort' => 'Max', 'version' => 'fe17b44'], 'vollständige Meldung');
pruefe(EspStatusCalc::Status('{"online":false}') === ['online' => false], 'Last Will: nur online=false');
pruefe(EspStatusCalc::Status('{"akku":150,"lautstaerke":-5}') === ['akku' => 100, 'lautstaerke' => 0], 'Werte werden geklemmt');
pruefe(EspStatusCalc::Status('{"zustand":42}') === [], 'unbekannter Zustand fällt weg');
pruefe(EspStatusCalc::Status('{"online":"ja"}') === ['online' => false], 'online nur bei echtem true');
pruefe(EspStatusCalc::Status('kein json') === [], 'Unsinn ergibt nichts');
pruefe(mb_strlen(EspStatusCalc::Status('{"antwort":"' . str_repeat('x', 3000) . '"}')['antwort']) === 1000, 'Antwort gekappt');
pruefe(EspStatusCalc::Status('{"evil":"x","frage":["a"]}') === [], 'fremde Felder und falsche Typen fallen weg');

// ── Befehle und Profil ──────────────────────────────────────────────────────
$k = str_repeat('0f', 32);
$b = json_decode(EspStatusCalc::Befehl('volume', 55, $k, 1791300000), true);
pruefe($b['cmd'] === 'volume' && $b['value'] === 55 && $b['ts'] === 1791300000, 'Lautstärke-Befehl mit Zeitstempel');
pruefe($b['mac'] === hash_hmac('sha256', 'volume|55|1791300000', (string)hex2bin($k)), 'Signatur über cmd|value|ts mit dem Geräteschlüssel');
$s0 = json_decode(EspStatusCalc::Befehl('start', null, $k, 5), true);
pruefe(!isset($s0['value']) && $s0['mac'] === hash_hmac('sha256', 'start||5', (string)hex2bin($k)), 'Start ohne Wert, Signatur mit leerem Wert');
pruefe(EspStatusCalc::Signatur('start', null, $k, 5) !== EspStatusCalc::Signatur('start', null, str_repeat('f0', 32), 5), 'anderer Schlüssel, andere Signatur');
pruefe(strlen(EspStatusCalc::NeuerSchluessel()) === 64 && EspStatusCalc::NeuerSchluessel() !== EspStatusCalc::NeuerSchluessel(), 'Schlüssel: 64 Hex, zufällig');
pruefe(EspStatusCalc::Weckwort('alexa', '') === 'alexa', 'fertiges Weckwort');
pruefe(EspStatusCalc::Weckwort('eigen', ' hey sim doo ') === 'eigen:hey sim doo', 'eigener Ausdruck (Gateway prüft weiter)');
pruefe(EspStatusCalc::Weckwort('quatsch', '') === 'hiesp', 'unbekannte Wahl → Vorgabe');
$p = EspStatusCalc::Profil('4d78fead', 'Wohnzimmer', false, 'hiesp', ['port' => 1890, 'user' => 'u', 'pass' => 'p']);
pruefe($p['userId'] === '4d78fead' && $p['geraete'] === false && $p['mqtt']['port'] === 1890, 'Profil mit MQTT');
pruefe(!isset(EspStatusCalc::Profil('', '', true, 'aus', null)['mqtt']), 'ohne MQTT-Server kein MQTT im Profil');
pruefe(EspStatusCalc::Profil('', '', true, 'aus', null)['mitschrift'] === false, 'Mitschrift standardmäßig aus');

// ── Riegel auf module.php ───────────────────────────────────────────────────
$m = (string)file_get_contents(__DIR__ . '/../module.php');
pruefe(str_contains($m, 'class SymDoESPVoice extends IPSModuleStrict'), 'Module Strict');
pruefe(str_contains($m, "SetReceiveDataFilter('^$')"), 'ohne Gerät wird nichts empfangen');
pruefe(str_contains($m, "!== EspStatusCalc::ThemaStatus(\$geraet)"), 'nur das eigene Statusthema wird gelesen');
pruefe(str_contains($m, 'hex2bin($roh)') && str_contains($m, "'Payload'          => bin2hex(\$nutzlast)"), 'Nutzlast hex-kodiert (Module Strict)');
pruefe(str_contains($m, 'ServerNurFuerSprachgeraete($server)') && substr_count($m, 'ServerNurFuerSprachgeraete(') >= 3,
    'MQTT-Zugang nur von einem Server, an dem ausschließlich Sprachgeräte hängen');
pruefe(str_contains($m, "RegisterPropertyBoolean('ShareTranscript', false)"), 'Mitschrift-Schalter standardmäßig aus');
pruefe(str_contains($m, "EspStatusCalc::Befehl(\$cmd, \$wert, \$schluessel, time())") && str_contains($m, 'strlen($schluessel) !== 64'), 'jeder Befehl signiert, ohne Schlüssel kein Befehl');
pruefe(!preg_match("/'~[A-Z]/", $m), 'keine Variablenprofile');
pruefe(substr_count($m, 'EnableAction(') === 2, 'nur Lautstärke und Helligkeit schaltbar');

echo ($fehler === 0 ? 'OK' : 'FEHLER') . ": $zahl Prüfungen, $fehler fehlgeschlagen\n";
exit($fehler === 0 ? 0 : 1);
