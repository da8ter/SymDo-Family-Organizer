<?php

declare(strict_types=1);

/**
 * Was die KI-Anbieter wirklich zu hoeren bekommen.
 *
 * Bis September 2026 steckte das in `AiExtract` und war nur im Betrieb zu
 * pruefen — man schickte ein Foto los und sah, was zurueckkam. Genau dort
 * sassen die Fehler, die Geld kosteten und tagelang unentdeckt blieben:
 *
 *  - Ein PDF ging an ein Modell, das keine Dateien nimmt.
 *  - Der lokale Server bekam `/chat/completions` ohne `/v1` davor und
 *    antwortete „Unexpected endpoint" — was von aussen wie ein Modellfehler
 *    aussah.
 *  - Leeres Guthaben kam als „Rate-Limit" zurueck, also mit dem Rat, es
 *    spaeter noch einmal zu versuchen. Spaeter half nicht.
 *
 * Seit der Anbieter eine eigene Klasse ohne Symcon ist, laesst sich der Rumpf
 * jeder Anfrage hier festnageln. Geprueft wird, WAS gesendet wird — nicht, ob
 * ein fremder Dienst antwortet.
 *
 *   php SymDoGateway/tests/AiProviderTest.php
 */

require_once __DIR__ . '/../../libs/AiProvider.php';
require_once __DIR__ . '/../../libs/AiRecipePage.php';

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

/**
 * Eine Attrappe des Netzes: sie merkt sich, was hinausgegangen waere, und
 * antwortet mit dem, was der jeweilige Fall braucht.
 */
final class Leitung
{
    public string $url = '';
    /** @var list<string> */
    public array $kopf = [];
    public string $rumpf = '';
    public int $frist = 0;
    public int $verbinden = 0;
    /** @var array{status:int,body:string,err:string} */
    public array $antwort = ['status' => 200, 'body' => '{}', 'err' => ''];

    public function __invoke(string $url, array $kopf, string $rumpf, int $frist, int $verbinden): array
    {
        $this->url = $url;
        $this->kopf = $kopf;
        $this->rumpf = $rumpf;
        $this->frist = $frist;
        $this->verbinden = $verbinden;
        return $this->antwort;
    }

    /** Der gesendete Rumpf als Feld. */
    public function gesendet(): array
    {
        $d = json_decode($this->rumpf, true);
        return is_array($d) ? $d : [];
    }
}

/** @param array<string,mixed> $cfg */
function anbieter(array $cfg, Leitung $l): AiProvider
{
    return AiProvider::ausKonfiguration($cfg, $l);
}

$antwortOpenAi = static fn(string $text): string => (string)json_encode(
    ['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]]);
$antwortClaude = static fn(string $text): string => (string)json_encode(
    ['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn']);

// ── Anthropic ─────────────────────────────────────────────────────────────
$l = new Leitung();
$l->antwort = ['status' => 200, 'body' => $antwortClaude('Hallo'), 'err' => ''];
$a = anbieter(['AiProvider' => 'anthropic', 'AiAnthropicKey' => 'sk-ant-x'], $l);
$r = $a->complete('Du bist knapp.', 'Was ist los?', null);
pruefe('Anthropic: Text kommt an', [$r['ok'], $r['text']], [true, 'Hallo']);
$g = $l->gesendet();
pruefe('Anthropic: Modell, Deckel, System und Nachricht',
    [$g['model'], $g['max_tokens'], $g['system'], $g['messages'][0]['content']],
    [AiProvider::ANTHROPIC_MODEL, AiProvider::MAX_TOKENS, 'Du bist knapp.', 'Was ist los?']);
pruefe('Anthropic: Schluessel und Fassung im Kopf, nicht als Bearer',
    [in_array('x-api-key: sk-ant-x', $l->kopf, true),
     in_array('anthropic-version: 2023-06-01', $l->kopf, true),
     (bool)preg_grep('/^Authorization:/', $l->kopf)],
    [true, true, false]);
pruefe('Anthropic: Adresse und Frist', [$l->url, $l->frist, $l->verbinden],
    ['https://api.anthropic.com/v1/messages', AiProvider::TIMEOUT, AiProvider::CONNECT_TIMEOUT]);

/* Ein Bild wird als eigener Block VOR den Text gestellt — in dieser
   Reihenfolge, sonst bezieht sich die Aufgabe auf nichts. */
$png = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 40));
$a->complete('S', 'Was steht da?', $png);
$inhalt = $l->gesendet()['messages'][0]['content'];
pruefe('Anthropic: Bild als Block, Typ aus der Signatur, danach der Text',
    [$inhalt[0]['type'], $inhalt[0]['source']['media_type'], $inhalt[1]['type'], $inhalt[1]['text']],
    ['image', 'image/png', 'text', 'Was steht da?']);

$a->complete('S', 'Lies das PDF', null, 'JVBERi0x');
$inhalt = $l->gesendet()['messages'][0]['content'];
pruefe('Anthropic: PDF geht als Dokument mit, ohne Umweg',
    [$inhalt[0]['type'], $inhalt[0]['source']['media_type'], $inhalt[0]['source']['data']],
    ['document', 'application/pdf', 'JVBERi0x']);

pruefe('Anthropic ohne Schluessel sagt es, bevor jemand anruft',
    [anbieter(['AiProvider' => 'anthropic'], $l)->konfigurationsfehler(),
     anbieter(['AiProvider' => 'anthropic'], $l)->complete('s', 'u', null)['grund']],
    ['anthropic', 'anthropic']);

// ── OpenAI ────────────────────────────────────────────────────────────────
$l = new Leitung();
$l->antwort = ['status' => 200, 'body' => $antwortOpenAi('Fertig'), 'err' => ''];
$o = anbieter(['AiProvider' => 'openai', 'AiOpenAIKey' => 'sk-o'], $l);
$o->complete('S', 'U', null);
$g = $l->gesendet();
pruefe('OpenAI: Modell und der Deckel FUER dieses Modell',
    [$g['model'], $g['max_tokens'] ?? null, isset($g['max_completion_tokens'])],
    [AiProvider::OPENAI_MODEL, AiProvider::MAX_TOKENS_GPT4O, false]);
pruefe('OpenAI: System und Nutzer als zwei Nachrichten',
    [$g['messages'][0]['role'], $g['messages'][0]['content'],
     $g['messages'][1]['role'], $g['messages'][1]['content']],
    ['system', 'S', 'user', 'U']);
pruefe('OpenAI: Bearer im Kopf', in_array('Authorization: Bearer sk-o', $l->kopf, true), true);

/* Der Fall, der im Betrieb Geld kostete: ein PDF braucht ein anderes Modell
   UND einen anderen Namen fuer den Deckel. */
$o->complete('S', 'U', null, 'JVBERi0x');
$g = $l->gesendet();
pruefe('OpenAI mit PDF: anderes Modell, anderer Deckelname, voller Deckel',
    [$g['model'], $g['max_completion_tokens'] ?? null, isset($g['max_tokens'])],
    [AiProvider::OPENAI_PDF_MODEL, AiProvider::MAX_TOKENS, false]);
pruefe('OpenAI mit PDF: die Datei als eigener Block',
    [$g['messages'][1]['content'][0]['type'],
     $g['messages'][1]['content'][0]['file']['filename'],
     str_starts_with((string)$g['messages'][1]['content'][0]['file']['file_data'], 'data:application/pdf;base64,')],
    ['file', 'dokument.pdf', true]);

pruefe('OpenAI ohne Schluessel sagt es vorher',
    anbieter(['AiProvider' => 'openai'], $l)->konfigurationsfehler(), 'openai');

// ── Lokaler Server ────────────────────────────────────────────────────────
$l = new Leitung();
$l->antwort = ['status' => 200, 'body' => $antwortOpenAi('lokal'), 'err' => ''];
/* Die Falle vom 19.08.2026: das Formular fragt nach der BASIS, die Server
   bedienen /v1. Ohne die Ergaenzung antwortet LM Studio „Unexpected
   endpoint", und das sah von aussen aus wie ein Modellfehler. */
anbieter(['AiProvider' => 'local', 'AiLocalBaseUrl' => 'http://127.0.0.1:1234',
          'AiLocalModel' => 'qwen'], $l)->complete('S', 'U', null);
pruefe('Lokal: /v1 wird ergaenzt', $l->url, 'http://127.0.0.1:1234/v1/chat/completions');
pruefe('Lokal: laengere Frist als eine Cloud', $l->frist, AiProvider::LOCAL_TIMEOUT);
pruefe('Lokal: ohne Schluessel kein Authorization-Kopf',
    (bool)preg_grep('/^Authorization:/', $l->kopf), false);

anbieter(['AiProvider' => 'local', 'AiLocalBaseUrl' => 'http://127.0.0.1:1234/v1/',
          'AiLocalModel' => 'qwen', 'AiLocalKey' => 'geheim'], $l)->complete('S', 'U', null);
pruefe('Lokal: ein vorhandenes /v1 wird NICHT verdoppelt',
    $l->url, 'http://127.0.0.1:1234/v1/chat/completions');
pruefe('Lokal: ein Schluessel geht als Bearer mit',
    in_array('Authorization: Bearer geheim', $l->kopf, true), true);
pruefe('Lokal: das Modell aus der Einstellung', $l->gesendet()['model'], 'qwen');

pruefe('Lokal ohne Adresse oder Modell sagt es vorher',
    [anbieter(['AiProvider' => 'local', 'AiLocalModel' => 'q'], $l)->konfigurationsfehler(),
     anbieter(['AiProvider' => 'local', 'AiLocalBaseUrl' => 'http://x'], $l)->konfigurationsfehler(),
     anbieter(['AiProvider' => 'local', 'AiLocalBaseUrl' => 'http://x', 'AiLocalModel' => 'q'], $l)->konfigurationsfehler()],
    ['local', 'local', null]);
pruefe('Und „lokal" ist als solches erkennbar — daran haengen die Fristen',
    [anbieter(['AiProvider' => 'local'], $l)->istLokal(),
     anbieter(['AiProvider' => 'openai'], $l)->istLokal()], [true, false]);

pruefe('Ohne Anbieter gibt es gar keinen Aufruf',
    anbieter([], $l)->complete('s', 'u', null)['grund'], 'keiner');

// ── Was aus einer Antwort gelesen wird ────────────────────────────────────
$fall = static function (array $antwort) use (&$l): array {
    $leitung = new Leitung();
    $leitung->antwort = $antwort + ['status' => 200, 'body' => '{}', 'err' => ''];
    return anbieter(['AiProvider' => 'openai', 'AiOpenAIKey' => 'k'], $leitung)->complete('S', 'U', null);
};
pruefe('Netz nicht erreichbar', $fall(['err' => 'timeout'])['code'], 'ai_unreachable');
pruefe('Und der Grund steht dabei', $fall(['err' => 'timeout'])['detail'], 'timeout');
pruefe('Schluessel abgelehnt', $fall(['status' => 401])['code'], 'ai_unauthorized');
pruefe('Auch bei 403', $fall(['status' => 403])['code'], 'ai_unauthorized');
pruefe('Zu viele Anfragen', $fall(['status' => 429])['code'], 'ai_rate_limited');
/* Leeres Guthaben sieht aus wie ein Rate-Limit, ist aber das Gegenteil:
   Warten hilft nicht. Ohne diese Unterscheidung stand tagelang „versuch es
   spaeter nochmal" an einem Konto ohne Guthaben. */
pruefe('Leeres Guthaben ist etwas anderes als ein Rate-Limit',
    $fall(['status' => 429, 'body' => '{"error":{"code":"insufficient_quota"}}'])['code'], 'ai_no_credit');
pruefe('Anderer Fehler des Dienstes', $fall(['status' => 500])['code'], 'ai_upstream');
pruefe('Mit dem Status als Detail', $fall(['status' => 500])['detail'], '500');
pruefe('Kein JSON zurueck', $fall(['body' => 'kaputt'])['code'], 'ai_bad_response');
pruefe('Leere Antwort', $fall(['body' => '{"choices":[{"message":{"content":""}}]}'])['code'], 'ai_empty');
/* Abgeschnitten erkennen: sonst degradiert eine am Deckel gekappte Ausgabe
   still zu „nichts erkannt", obwohl Tokens abgerechnet wurden. */
pruefe('Abgeschnitten bei OpenAI',
    $fall(['body' => '{"choices":[{"message":{"content":"halb"},"finish_reason":"length"}]}'])['code'],
    'ai_truncated');
$claude = new Leitung();
$claude->antwort = ['status' => 200, 'err' => '',
    'body' => '{"stop_reason":"max_tokens","content":[{"type":"text","text":"halb"}]}'];
pruefe('Abgeschnitten bei Anthropic',
    anbieter(['AiProvider' => 'anthropic', 'AiAnthropicKey' => 'k'], $claude)
        ->complete('S', 'U', null)['code'], 'ai_truncated');

// ── Diktat ────────────────────────────────────────────────────────────────
$l = new Leitung();
$l->antwort = ['status' => 200, 'body' => '{"text":"guten morgen"}', 'err' => ''];
$r = anbieter(['AiProvider' => 'openai', 'AiOpenAIKey' => 'sk-o'], $l)
    ->transcribe('ROHDATEN', 'audio/mp4');
pruefe('Diktat: Text kommt zurueck', [$r['ok'], $r['text']], [true, 'guten morgen']);
pruefe('Diktat: Adresse, Frist und Modell',
    [$l->url, $l->frist, str_contains($l->rumpf, AiProvider::TRANSCRIBE_MODEL)],
    ['https://api.openai.com/v1/audio/transcriptions', AiProvider::TRANSCRIBE_TIMEOUT, true]);
/* Die Grenze muss im Kopf UND im Rumpf stehen, sonst liest der Dienst nichts. */
$grenze = '';
foreach ($l->kopf as $k) {
    if (str_starts_with($k, 'Content-Type: multipart/form-data; boundary=')) {
        $grenze = substr($k, strlen('Content-Type: multipart/form-data; boundary='));
    }
}
pruefe('Diktat: die Grenze steht im Kopf und traegt den Rumpf',
    [$grenze !== '', str_starts_with($l->rumpf, '--' . $grenze), str_ends_with($l->rumpf, '--' . $grenze . "--\r\n")],
    [true, true, true]);
pruefe('Diktat: die Dateiendung folgt dem Typ, nicht dem Zufall',
    str_contains($l->rumpf, 'filename="diktat.m4a"'), true);
foreach (['audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/webm' => 'webm'] as $typ => $endung) {
    anbieter(['AiProvider' => 'openai', 'AiOpenAIKey' => 'k'], $l)->transcribe('X', $typ);
    pruefe('Diktat: ' . $typ . ' wird zu ' . $endung,
        str_contains($l->rumpf, 'filename="diktat.' . $endung . '"'), true);
}
pruefe('Diktat ohne Schluessel und ohne lokalen Server',
    anbieter(['AiProvider' => 'openai'], $l)->transcribe('X', 'audio/webm')['grund'], 'diktat');
anbieter(['AiProvider' => 'local', 'AiLocalBaseUrl' => 'http://127.0.0.1:1234'], $l)->transcribe('X', 'audio/webm');
pruefe('Diktat lokal: auch hier das /v1 und whisper',
    [$l->url, str_contains($l->rumpf, 'whisper-1')],
    ['http://127.0.0.1:1234/v1/audio/transcriptions', true]);
$l->antwort = ['status' => 200, 'body' => '{"text":"   "}', 'err' => ''];
pruefe('Diktat: leeres Ergebnis wird gemeldet',
    anbieter(['AiProvider' => 'openai', 'AiOpenAIKey' => 'k'], $l)->transcribe('X', 'audio/webm')['grund'], 'leer');

// ── Kleinigkeiten, an denen viel haengt ───────────────────────────────────
pruefe('Bildtyp aus der Signatur, nicht aus dem Dateinamen',
    [AiProvider::bildTyp(base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 40))),
     AiProvider::bildTyp(base64_encode('GIF89a' . str_repeat('x', 40))),
     AiProvider::bildTyp(base64_encode('RIFF' . '1234' . 'WEBP' . str_repeat('x', 40))),
     AiProvider::bildTyp(base64_encode("\xff\xd8\xff" . str_repeat('x', 40)))],
    ['image/png', 'image/gif', 'image/webp', 'image/jpeg']);

// ── Die Rezeptseite: der Riegel gegen das eigene Netz ─────────────────────
/* Der Server holt hier fremde Adressen. Ohne diese Pruefung waere jede
   Rezept-URL ein Weg ins Heimnetz. */
foreach (['http://127.0.0.1/x', 'http://localhost/x', 'http://192.168.1.5/x', 'http://10.0.0.1/x',
          'http://[::1]/x', 'http://169.254.169.254/latest/meta-data/', 'file:///etc/passwd',
          'ftp://example.com/x', 'nicht mal eine adresse'] as $url) {
    pruefe('Gesperrt: ' . $url, AiRecipePage::istOeffentlich($url), false);
}
pruefe('Erlaubt: eine gewoehnliche oeffentliche Adresse',
    AiRecipePage::istOeffentlich('https://www.chefkoch.de/rezepte/1/'), true);

/* Absatzenden werden zu Zeilenumbruechen, Skripte und Kommentare fliegen ganz
   heraus — sonst landete JavaScript im Text und damit in der Anfrage an die KI. */
pruefe('HTML wird zu Fliesstext, Skripte und Kommentare fliegen raus',
    AiRecipePage::htmlZuText('<p>Hallo</p><script>boese()</script><!-- weg --><b>Welt</b>'),
    "Hallo\n  Welt");
pruefe('Zeilenumbrueche werden uebernommen, Leerzeilen gekappt',
    AiRecipePage::htmlZuText('<div>a</div><br><br><br><br>b'), "a\n\nb");
pruefe('Strukturierte Zutaten stehen vor dem Fliesstext',
    str_starts_with(AiRecipePage::text(
        '<script type="application/ld+json">{"@type":"Recipe","recipeIngredient":["2 Eier","Mehl"]}</script><p>Text</p>'),
        "Zutaten (aus strukturierten Daten der Seite):\n- 2 Eier\n- Mehl"), true);

// ── Jede Konstante, die eine Klasse ruft, muss es auch geben ──────────────
/* Der Umzug hat die Konstanten umbenannt (AI_RECIPE_TEXT_MAX -> TEXT_MAX).
   Eine Verwendung blieb dabei auf halbem Weg stehen: `self::RECIPE_TEXT_MAX`.
   PHP merkt das erst beim Ausfuehren — und diese Zeile lief nur auf dem einen
   Weg „lokale KI mit PDF", also erst beim Nutzer. Diese Probe braucht keinen
   Prueffall je Zweig: sie liest jede `self::NAME` aus der Datei und fragt die
   Klasse, ob sie den Namen kennt. */
foreach (['AiProvider' => __DIR__ . '/../../libs/AiProvider.php',
          'AiRecipePage' => __DIR__ . '/../../libs/AiRecipePage.php',
          'AiHttp' => __DIR__ . '/../../libs/AiHttp.php'] as $klasse => $datei) {
    $quelle = (string)file_get_contents($datei);
    // Nur Konstanten: `self::NAME` ohne Klammer dahinter (sonst ist es ein Griff).
    preg_match_all('/self::([A-Z][A-Z0-9_]*)\b(?!\s*\()/', $quelle, $treffer);
    $spiegel = new ReflectionClass($klasse);
    $fehlend = array_values(array_unique(array_filter(array_unique($treffer[1]),
        static fn(string $n): bool => !$spiegel->hasConstant($n))));
    pruefe($klasse . ': jede gerufene Konstante gibt es auch', $fehlend, []);
}

// ── Und die Rueckuebersetzung: aus dem Code wieder ein Satz ───────────────
/* Der Anbieter kennt keine Saetze mehr, nur Codes. Erst `AiErrorMessage` im
   Trait macht daraus wieder das, was in der App steht. Geht dabei ein Fall
   verloren, sieht der Nutzer „AI request failed." statt der Erklaerung — und
   niemand merkt es, weil nichts abstuerzt. Deshalb steht die ganze Tabelle
   hier, mit den Saetzen aus dem Stand VOR der Aufteilung. */
$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (is_file($stubs . '/autoload.php')) {
    require_once $stubs . '/autoload.php';
    require_once __DIR__ . '/../libs/AiExtract.php';

    /* Nur der Trait, sonst nichts: `AiErrorMessage` ruft ausser `Translate`
       niemanden. Die Attrappe liefert dort den englischen Urtext zurueck —
       genau der steht in der locale.json als Schluessel. */
    final class FehlerProbe extends IPSModuleStrict
    {
        use AiExtract;

        public function p(string $code, string $grund = '', string $detail = ''): array
        {
            return $this->AiErrorMessage($code, $grund, $detail);
        }
    }
    $fp = new FehlerProbe(1);

    $tabelle = [
        ['ai_not_configured', 'anthropic', '', 400, 'No Anthropic API key configured.'],
        ['ai_not_configured', 'openai',    '', 400, 'No OpenAI API key configured.'],
        ['ai_not_configured', 'local',     '', 400, 'Local server URL and model must be configured.'],
        ['ai_not_configured', 'diktat',    '', 400, 'Dictation needs an OpenAI API key or a local AI server with transcription.'],
        ['ai_not_configured', 'keiner',    '', 400, 'No AI provider configured.'],
        ['ai_pdf_unsupported', '', '', 400, 'PDF is not supported by this AI provider.'],
        ['ai_unreachable', '', 'timeout', 502, 'Could not reach the AI service. timeout'],
        ['ai_unauthorized', '', '', 502, 'AI rejected the API key.'],
        ['ai_no_credit', '', '', 502, 'No credit left at the AI provider — top up the account.'],
        ['ai_rate_limited', '', '', 502, 'AI rate limit reached — try again later.'],
        ['ai_upstream', '', '500', 502, 'AI request failed. (HTTP 500)'],
        ['ai_bad_response', '', '', 502, 'Unexpected AI response.'],
        ['ai_truncated', '', '', 502, 'The AI answer was cut off — try a smaller document.'],
        ['ai_empty', '', '', 502, 'The AI returned an empty answer.'],
        ['ai_failed', '', 'Grund', 502, 'Transcription failed. Grund'],
        ['ai_failed', 'leer', '', 502, 'Transcription came back empty.'],
        ['invalid_url', '', '', 422, 'Invalid or non-public URL.'],
        ['ai_url_fetch', '', '', 502, 'Could not load the page.'],
        ['ai_url_fetch', '', '404', 502, 'Could not load the page. (HTTP 404)'],
    ];
    foreach ($tabelle as [$code, $grund, $detail, $status, $satz]) {
        $r = $fp->p($code, $grund, $detail);
        pruefe('Satz zu ' . $code . ($grund !== '' ? '/' . $grund : ''),
            [$r['ok'], $r['code'], $r['status'], $r['message']],
            [false, $code, $status, $satz]);
    }
    pruefe('Ein unbekannter Code faellt auf den allgemeinen Satz zurueck',
        $fp->p('irgendwas')['message'], 'AI request failed.');
} else {
    printf("%-4s %s\n", '--', 'Fehlertabelle uebersprungen: Attrappen nicht gefunden');
}

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
