<?php

declare(strict_types=1);

/**
 * Prüfstand für den GPT-Live-Weg des Sprachdialogs (23.09.2026).
 *
 * Teil 1: der IPS-freie Rechenkern (VoiceLiveCalc) — Rumpf der Sitzung,
 * Weißlisten, Lesen der Antwort. Teil 2: Riegel auf Voice.php und den
 * Browser-Kern, die beweisen, dass der Weg verdrahtet ist und der alte
 * Realtime-Weg unangetastet bleibt.
 *
 *   php SymDoGateway/tests/VoiceLiveTest.php
 */

require_once __DIR__ . '/../libs/VoiceLiveCalc.php';

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
pruefe(VoiceLiveCalc::IstLive('gpt-live-1'), 'gpt-live-1 ist Live');
pruefe(VoiceLiveCalc::IstLive(' gpt-live-1 '), 'Live-Erkennung verträgt Leerraum');
pruefe(!VoiceLiveCalc::IstLive('gpt-realtime-mini'), 'Realtime ist nicht Live');
pruefe(!VoiceLiveCalc::IstLive(''), 'leer ist nicht Live');

pruefe(VoiceLiveCalc::Backend('gpt-5.6-terra') === 'gpt-5.6-terra', 'Backend terra bleibt');
pruefe(VoiceLiveCalc::Backend('gpt-4o') === 'gpt-5.6-luna', 'fremdes Backend fällt auf luna');
pruefe(VoiceLiveCalc::Stimme('vesper') === 'vesper', 'Live-Stimme bleibt');
pruefe(VoiceLiveCalc::Stimme('marin') === 'quartz', 'Realtime-Stimme fällt auf quartz (andere Namensräume)');
pruefe(count(VoiceLiveCalc::STIMMEN) === 12 && count(array_unique(VoiceLiveCalc::STIMMEN)) === 12, '12 Live-Stimmen, keine doppelt');

$werkzeuge = [['type' => 'function', 'name' => 'liste_lesen', 'description' => 'x', 'parameters' => ['type' => 'object']]];
$rumpf = VoiceLiveCalc::SitzungsRumpf([
    'sprech'           => 'Sprich kurz.',
    'backend'          => 'gpt-5.6-terra',
    'backendAnweisung' => 'Du bist SymDo…',
    'werkzeuge'        => $werkzeuge,
    'stimme'           => 'stone',
], "v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\n");

pruefe(($rumpf['session']['model'] ?? '') === 'gpt-live-1', 'Rumpf: Live-Modell');
pruefe(($rumpf['session']['instructions'] ?? '') === 'Sprich kurz.', 'Rumpf: kurze Sprech-Anweisung an der Sitzung');
pruefe(($rumpf['session']['audio']['output']['voice'] ?? '') === 'stone', 'Rumpf: Stimme');
pruefe(($rumpf['session']['delegation']['type'] ?? '') === 'responses', 'Rumpf: Delegation an Responses');
$resp = $rumpf['session']['delegation']['responses'] ?? [];
pruefe(($resp['model'] ?? '') === 'gpt-5.6-terra', 'Rumpf: Backend-Modell');
pruefe(($resp['instructions'] ?? '') === 'Du bist SymDo…', 'Rumpf: lange Anweisung beim Backend, nicht bei der Stimme');
pruefe(($resp['tools'] ?? null) === $werkzeuge, 'Rumpf: Werkzeuge beim Backend');
pruefe(($resp['tool_choice'] ?? '') === 'auto', 'Rumpf: tool_choice auto');
pruefe(($resp['parallel_tool_calls'] ?? true) === false, 'Rumpf: keine parallelen Werkzeugaufrufe');
pruefe(!isset($rumpf['session']['tools']), 'Rumpf: KEINE Werkzeuge an der Sitzung selbst');
pruefe(($rumpf['transport']['type'] ?? '') === 'webrtc', 'Rumpf: Transport webrtc');
pruefe(str_starts_with((string)($rumpf['transport']['sdp'] ?? ''), 'v=0'), 'Rumpf: SDP-Angebot im Transport');
pruefe(json_encode($rumpf) !== false, 'Rumpf ist JSON-fähig');

$gelesen = VoiceLiveCalc::AntwortLesen(['session' => ['id' => 'sess_abc'], 'transport' => ['type' => 'webrtc', 'sdp' => "v=0\r\nanswer"]]);
pruefe($gelesen['sdp'] === "v=0\r\nanswer" && $gelesen['id'] === 'sess_abc', 'Antwort lesen: SDP + Kennung');
$leer = VoiceLiveCalc::AntwortLesen(['error' => ['message' => 'nope']]);
pruefe($leer['sdp'] === '' && $leer['id'] === '', 'Antwort lesen: Fehlerantwort ergibt leer');
pruefe(VoiceLiveCalc::AntwortLesen(null)['sdp'] === '', 'Antwort lesen: null ergibt leer');
pruefe(VoiceLiveCalc::AntwortLesen(['transport' => ['sdp' => '<html>']])['sdp'] === '', 'Antwort lesen: kein SDP ohne v=');

$sprech = VoiceLiveCalc::SprechAnweisung('Stephan', 'Mittwoch, 23. September 2026');
pruefe(str_contains($sprech, 'SymDo') && str_contains($sprech, 'Deutsch'), 'Sprech-Anweisung: Persona und Sprache');
pruefe(str_contains($sprech, 'delegierst'), 'Sprech-Anweisung: Regel zur Delegation');
pruefe(str_contains($sprech, 'Du sprichst mit Stephan'), 'Sprech-Anweisung: Gesprächspartner');
pruefe(!str_contains(VoiceLiveCalc::SprechAnweisung('', 'heute'), 'Du sprichst mit'), 'Sprech-Anweisung: ohne Namen kein Satz dazu');
pruefe(mb_strlen($sprech) < 1200, 'Sprech-Anweisung bleibt kurz (das Fachliche steht beim Backend)');

// ── Teil 2: Riegel ──────────────────────────────────────────────────────────
$voice = (string)file_get_contents(__DIR__ . '/../libs/Voice.php');
pruefe(str_contains($voice, "require_once __DIR__ . '/VoiceLiveCalc.php';"), 'Voice.php lädt den Rechenkern');
pruefe(str_contains($voice, "case 'livesdp':"), 'Voice.php: Aktion livesdp');
pruefe(str_contains($voice, "'https://api.openai.com/v1/live/sessions'"), 'Voice.php: Sitzung am Live-Endpunkt');
pruefe(str_contains($voice, "RegisterPropertyString('VoiceLiveBackend'") && str_contains($voice, "RegisterPropertyString('VoiceLiveVoice'"), 'Voice.php: neue Eigenschaften');
pruefe(str_contains($voice, '\'live\'      => ($body[\'live\'] ?? false) === true'), 'Voice.php: Anruf merkt sich den Live-Weg');
pruefe(str_contains($voice, "'https://api.openai.com/v1/live/sessions/' . rawurlencode(\$callId) . '/hangup'"), 'Voice.php: Auflegen am Live-Endpunkt');
pruefe(str_contains($voice, "'https://api.openai.com/v1/realtime/calls/' . rawurlencode(\$callId) . '/hangup'"), 'Voice.php: Realtime-Auflegen bleibt');
pruefe(str_contains($voice, "'value' => VoiceLiveCalc::MODELL]"), 'Voice.php: Formular bietet gpt-live-1 an');
pruefe(str_contains($voice, "['ok' => true, 'live' => true, 'model' => VoiceLiveCalc::MODELL"), 'Voice.php: open antwortet live:true statt Marke');
pruefe(str_contains($voice, 'v1/realtime/client_secrets'), 'Voice.php: Realtime-Marke unangetastet');
// Der Live-Weg prüft dieselben Tore wie das Öffnen — hier entsteht die Sitzung, die Geld kostet.
$liveSdp = substr($voice, strpos($voice, 'private function VoiceLiveSdp'), 2600);
pruefe(str_contains($liveSdp, 'VoiceTorZu()') && str_contains($liveSdp, 'VoiceBudgetLeft()'), 'livesdp: Einwilligung + Budget geprüft');
pruefe(str_contains($liveSdp, "str_starts_with(\$sdp, 'v=')") && str_contains($liveSdp, '65536'), 'livesdp: SDP geprüft und begrenzt');
pruefe(str_contains($liveSdp, 'VoiceInstructions($userId)') && str_contains($liveSdp, 'VoiceToolSpec()'), 'livesdp: dieselbe Anweisung und dieselben Werkzeuge wie Realtime');

$kern = (string)file_get_contents(__DIR__ . '/../libs/voice-core.js');
pruefe(str_contains($kern, 'function handschlagLive('), 'Kern: Live-Handschlag');
pruefe(str_contains($kern, "action: 'livesdp'"), 'Kern: schickt das Angebot ans Gateway');
pruefe(!str_contains($kern, "sitzung.value") || str_contains($kern, "'Bearer ' + sitzung.value"), 'Kern: Realtime-Marke weiter im Gebrauch');
pruefe(str_contains($kern, "action: 'opened', callId: callId, live: true"), 'Kern: meldet den Live-Anruf als solchen');
pruefe(str_contains($kern, "case 'session.started':") && str_contains($kern, "case 'session.closed':"), 'Kern: Live-Sitzungsereignisse');
pruefe(str_contains($kern, "case 'response.event':"), 'Kern: packt den Backend-Umschlag aus');
pruefe(str_contains($kern, "live ? 'response.item.create' : 'conversation.item.create'"), 'Kern: Werkzeugergebnis im Live-Umschlag');
pruefe(str_contains($kern, "senden({ type: 'session.close' })"), 'Kern: schließt die Live-Sitzung beim Beenden');
pruefe(str_contains($kern, "if (live && ev.item && ev.item.type === 'function_call')"), 'Kern: output_item.done nur im Live-Weg (kein Doppelaufruf bei Realtime)');
pruefe(!str_contains($kern, "https://api.openai.com/v1/live"), 'Kern: kein direkter Live-Aufruf aus dem Browser (Schlüssel bleibt serverseitig)');

$locale = json_decode((string)file_get_contents(__DIR__ . '/../locale.json'), true)['translations']['de'] ?? [];
foreach (['Voice (GPT-Live)', 'Backend model (GPT-Live)', 'GPT-Live is not available in this account.'] as $k) {
    pruefe(isset($locale[$k]), "locale de: $k");
}

echo $fehler === 0 ? "OK — $zahl Prüfungen bestanden\n" : "FEHLER — $fehler von $zahl Prüfungen gefallen\n";
exit($fehler === 0 ? 0 : 1);
