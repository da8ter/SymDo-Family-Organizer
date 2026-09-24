<?php

declare(strict_types=1);

/**
 * Google Gemini TTS als fuenfter Sprachanbieter (25.09.2026).
 *
 *  1. TtsGemini: Antwort auslesen (Interactions-Form, alte generateContent-Form
 *     mit rohem PCM), WAV-Kopf.
 *  2. Tts-Trait: Anbieterwahl, Format, Freischaltung, die Anfrage an Google
 *     (Adresse, Schluessel im Kopf, Modell, Stimme, Stil), Fehlerfaelle,
 *     Zwischenspeicher-Schluessel.
 *  3. Personas: jede hat eine gueltige eingebaute Gemini-Stimme.
 *
 *   php SymDoGateway/tests/GeminiTtsTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/Tts.php';
require_once __DIR__ . '/../libs/Briefing.php';

IPS\Kernel::reset();

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

// ══ 1. TtsGemini ═══════════════════════════════════════════════════════════
$pcm = str_repeat("\x01\x00", 2400);                     // 0,1 s Ton
$wav = TtsGemini::WavKopf(strlen($pcm)) . $pcm;
pruefe('WAV-Kopf: 44 Bytes, RIFF/WAVE, 24 kHz, Datenlaenge',
    [strlen(TtsGemini::WavKopf(10)), substr($wav, 0, 4), substr($wav, 8, 4),
     unpack('V', substr($wav, 24, 4))[1], unpack('V', substr($wav, 40, 4))[1]],
    [44, 'RIFF', 'WAVE', 24000, 4800]);

$interactions = json_encode(['id' => 'x', 'steps' => [
    ['type' => 'thought', 'content' => []],
    ['type' => 'model_output', 'content' => [
        ['type' => 'audio', 'mime_type' => 'audio/wav', 'data' => base64_encode('RIFFalt')],
        ['type' => 'audio', 'mime_type' => 'audio/wav', 'data' => base64_encode($wav)],
    ]],
]]);
pruefe('Interactions-Antwort: das LETZTE Audio-Stueck, unveraendert', TtsGemini::AudioAusAntwort($interactions) === $wav, true);

$alt = json_encode(['candidates' => [['content' => ['parts' => [
    ['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=16000', 'data' => base64_encode($pcm)]]]]]]]);
$ausAlt = TtsGemini::AudioAusAntwort($alt);
pruefe('Alte Form mit rohem PCM: Kopf davor, Rate aus dem MIME-Typ',
    [substr($ausAlt, 0, 4), unpack('V', substr($ausAlt, 24, 4))[1], strlen($ausAlt)], ['RIFF', 16000, 44 + strlen($pcm)]);
pruefe('Kein Audio, kaputtes JSON, kaputtes Base64: leer',
    [TtsGemini::AudioAusAntwort('{"steps":[]}'), TtsGemini::AudioAusAntwort('nix'),
     TtsGemini::AudioAusAntwort('{"steps":[{"type":"model_output","content":[{"type":"audio","data":"%%%"}]}]}')],
    ['', '', '']);

// ══ 2. Tts-Trait ═══════════════════════════════════════════════════════════
final class TtsProbe extends IPSModuleStrict
{
    use Tts;

    public array $cfg = [];
    public array $anfragen = [];
    public array $antwort = ['status' => 200, 'body' => '', 'err' => ''];

    public function Translate(string $Text): string { return $Text; }
    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    private function TtsSetting(string $name, string $default): string
    {
        $w = trim((string)($this->cfg[$name] ?? ''));
        return $w !== '' ? $w : $default;
    }
    private function TtsStorageReady(): bool { return true; }
    private function AiProp(string $name): mixed { return null; }
    private function OutputLimit(): int { return 6048576 - 100000; }
    private function BriefingLimitText(): string { return '5,7 MiB'; }
    private function AiHttpPost(string $url, array $kopf, string $body): array
    {
        $this->anfragen[] = ['url' => $url, 'kopf' => $kopf, 'body' => json_decode($body, true)];
        return $this->antwort;
    }
    public function __call(string $n, array $a): mixed
    {
        return (new ReflectionMethod(self::class, $n))->invoke($this, ...$a);
    }
}

$t = new TtsProbe(5001);
$t->cfg = ['TtsProvider' => 'gemini'];
pruefe('Anbieter „gemini" wird erkannt, Format immer WAV',
    [$t->TtsProvider(), $t->TtsFormat('aac'), $t->TtsFormat('mp3')], ['gemini', 'wav', 'wav']);
pruefe('Ohne Schluessel aus, mit Schluessel an', [$t->TtsEnabled(), (function () use ($t) {
    $t->cfg['TtsGeminiKey'] = 'geheim-123';
    return $t->TtsEnabled();
})()], [false, true]);

$t->antwort = ['status' => 200, 'err' => '', 'body' => $interactions];
$ton = $t->TtsRequestAudio('Guten Morgen.', 'Charon', 'Sprich ruhig.', 'wav');
$a = $t->anfragen[0];
pruefe('Anfrage: Interactions-Adresse, Schluessel im Kopf (nicht in der Adresse)',
    [$a['url'], in_array('x-goog-api-key: geheim-123', $a['kopf'], true), str_contains($a['url'], 'geheim')],
    ['https://generativelanguage.googleapis.com/v1beta/interactions', true, false]);
pruefe('Anfrage: Modell, Text, Stil als speech_metadata, Stimme, WAV',
    [$a['body']['model'], $a['body']['input'][0]['type'], $a['body']['input'][0]['content'][0]['text'],
     $a['body']['input'][0]['content'][0]['annotations'][0], $a['body']['generation_config']['speech_config'][0]['voice'],
     $a['body']['response_format']],
    ['gemini-3.8-flash-tts', 'user_input', 'Guten Morgen.', ['type' => 'speech_metadata', 'style' => 'Sprich ruhig.'],
     'Charon', ['type' => 'audio', 'mime_type' => 'audio/wav']]);
pruefe('Antwort: die WAV-Daten', $ton === $wav, true);

$t->cfg += ['TtsGeminiModel' => 'gemini-3.8-flash-lite-tts', 'TtsGeminiVoice' => 'Sulafat'];
$t->TtsRequestAudio('Hallo.', 'shimmer', '', 'wav');
$b = $t->anfragen[1]['body'];
pruefe('Fremde Stimme (OpenAI-Name) → die eingestellte; Lite-Modell; ohne Anweisung die Vorlese-Vorgabe als Stil',
    [$b['generation_config']['speech_config'][0]['voice'], $b['model'],
     ($b['input'][0]['content'][0]['annotations'][0]['style'] ?? '') !== ''],
    ['Sulafat', 'gemini-3.8-flash-lite-tts', true]);
$t->cfg['TtsGeminiModel'] = 'gemini-9-quatsch';
$t->cfg['TtsGeminiVoice'] = 'Niemand';
$t->TtsRequestAudio('Hallo.', '', '', 'wav');
$c = $t->anfragen[2]['body'];
pruefe('Unbekanntes Modell und unbekannte Stimme fallen auf die Vorgaben',
    [$c['model'], $c['generation_config']['speech_config'][0]['voice']], ['gemini-3.8-flash-tts', 'Kore']);

$t->antwort = ['status' => 429, 'err' => '', 'body' => '{"error":{"message":"quota"}}'];
pruefe('HTTP-Fehler: kein Ton', $t->TtsRequestAudio('x', '', '', 'wav'), '');
$t->antwort = ['status' => 200, 'err' => '', 'body' => '{"steps":[]}'];
pruefe('200 ohne Audio: kein Ton', $t->TtsRequestAudio('x', '', '', 'wav'), '');
$t->antwort = ['status' => 0, 'err' => 'timeout', 'body' => ''];
pruefe('Netzfehler: kein Ton', $t->TtsRequestAudio('x', '', '', 'wav'), '');

$t->cfg = ['TtsProvider' => 'gemini', 'TtsGeminiKey' => 'k', 'TtsGeminiVoice' => 'Kore'];
$h1 = $t->TtsHash('Text', '', '', 'wav');
$t->cfg['TtsGeminiVoice'] = 'Puck';
$h2 = $t->TtsHash('Text', '', '', 'wav');
$t->cfg['TtsGeminiModel'] = 'gemini-3.8-flash-lite-tts';
$h3 = $t->TtsHash('Text', '', '', 'wav');
$t->cfg['TtsProvider'] = 'openai';
$h4 = $t->TtsHash('Text', '', '', 'wav');
pruefe('Schluessel wechselt mit Stimme, Modell und Anbieter', count(array_unique([$h1, $h2, $h3, $h4])), 4);
pruefe('MIME-Typ einer WAV-Aufnahme', $t->TtsMimeType('wav'), 'audio/wav');

// ══ 3. Personas ════════════════════════════════════════════════════════════
final class PersonaProbe
{
    use Briefing;

    public function liste(): array { return (new ReflectionMethod(self::class, 'BriefingPersonas'))->invoke($this); }
}
$stimmen = (new ReflectionClassConstant(TtsProbe::class, 'TTS_GEMINI_VOICES'))->getValue();
$ungueltig = array_values(array_filter((new PersonaProbe())->liste(),
    static fn(array $p): bool => !isset($stimmen[$p['gemini'] ?? ''])));
pruefe('Jede Persona hat eine gueltige eingebaute Gemini-Stimme', array_column($ungueltig, 'key'), []);

$tts = (string)file_get_contents(__DIR__ . '/../libs/Tts.php');
pruefe('Einkaufs-Ansage: Format vom Anbieter, nicht fest mp3 (Docker 25.09.: WAV ging als audio/mpeg hinaus)',
    [str_contains($tts, "\$format = \$this->TtsFormat('mp3');"),
     str_contains($tts, "\$this->TtsProduce(\$hash, \$text, '', '', \$format)"),
     str_contains($tts, "\$mid = \$this->TtsProduce(\$hash, \$text);")], [true, true, false]);
$brief = (string)file_get_contents(__DIR__ . '/../libs/Briefing.php');
pruefe('Formular zeigt/verbirgt die Gemini-Felder beim Umschalten',
    str_contains($tts, "'gemini'     => ['TtsGeminiKey', 'TtsGeminiModel', 'TtsGeminiVoice', 'TtsGeminiHint']"), true);
pruefe('Briefing: eigener Zweig fuer Stimme und eigene Groessenrechnung',
    [str_contains($brief, "\$anbieter === 'gemini'"), str_contains($brief, 'BRIEFING_TTS_BYTES_GEMINI_WAV;')], [true, true]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
