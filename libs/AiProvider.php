<?php

declare(strict_types=1);

require_once __DIR__ . '/AiHttp.php';

/**
 * Der KI-Anbieter, ohne Symcon.
 *
 * Hier steht, WIE mit Anthropic, OpenAI oder einem lokalen Server gesprochen
 * wird: welcher Rumpf, welche Kopfzeilen, welches Modell, wie eine Antwort
 * gedeutet wird. Herausgeloest aus `AiExtract`, damit zwei Dinge moeglich
 * werden, die vorher nicht gingen:
 *
 *  1. Ein Laufwerk AUSSERHALB des Gateways kann den Anbieter rufen. Genau
 *     darum geht es beim Umbau „Gateway frei halten": ein KI-Aufruf dauert bis
 *     zu 45 Sekunden (lokal 300), und solange steht sonst die ganze App —
 *     Symcon fuehrt je Instanz genau eine Sache zur Zeit aus.
 *  2. Der Rumpf jeder Anfrage laesst sich pruefen, ohne dass ein Kernel laeuft.
 *     Vorher konnte man nur hoffen, dass `max_completion_tokens` beim richtigen
 *     Modell steht und der lokale Pfad sein `/v1` bekommt.
 *
 * Ohne Symcon heisst hier dreierlei:
 *
 *  - **Keine Uebersetzung.** Ein Fehler kommt als CODE zurueck, nicht als Satz.
 *    Den Satz macht `AiErrorMessage()` im Trait — dort, wo `Translate()` lebt.
 *    Manche Codes haben mehrere Saetze (drei verschiedene „nicht
 *    eingerichtet"); dafuer gibt es das Feld `grund`.
 *  - **Kein SendDebug.** Was aufgefallen ist, sammelt `debugZeilen()`; der
 *    Aufrufer schreibt es in sein Protokoll.
 *  - **Kein Zugriff auf Eigenschaften.** Die Konfiguration kommt einmal
 *    herein (`ausKonfiguration`) und wird nicht nachgeladen.
 */
/**
 * „Den Auftrag gibt es nicht mehr" — geworfen an der Transportgrenze.
 *
 * Eine eigene Ausnahme und kein Fehlercode: ein Code liefe durch die Deutung,
 * wuerde als voruebergehende Stoerung gewertet und der Auftrag vertagt. Genau
 * das darf ein Widerruf nicht ausloesen.
 */
final class AiWiderrufen extends \RuntimeException
{
}

final class AiProvider
{
    // ── Modelle ──────────────────────────────────────────────────────────
    public const ANTHROPIC_MODEL = 'claude-sonnet-4-5';
    public const OPENAI_MODEL    = 'gpt-4o';
    /** PDF braucht ein Modell mit Datei-Eingang; gpt-4o kann kein PDF. */
    public const OPENAI_PDF_MODEL = 'gpt-5.6-terra';
    public const TRANSCRIBE_MODEL = 'gpt-4o-mini-transcribe';

    // ── Groessen und Fristen ─────────────────────────────────────────────
    /** Ein Denkmodell verbraucht sein Budget zuerst im Denken — kleine Deckel enden leer. */
    public const MAX_TOKENS       = 32000;
    public const MAX_TOKENS_GPT4O = 16384;
    public const TIMEOUT          = 45;
    /** Der eigene Rechner bekommt mehr Zeit als eine Cloud. */
    public const LOCAL_TIMEOUT    = 300;
    public const CONNECT_TIMEOUT  = 5;
    public const TRANSCRIBE_TIMEOUT = 120;

    // ── PDF fuer Server ohne Datei-Eingang ───────────────────────────────
    /** So viel Text muss eine Textebene hergeben, sonst ist es ein Scan. */
    public const PDF_MIN_TEXT    = 200;
    public const PDF_PAGES_MAX   = 3;
    public const PDF_IMAGE_WIDTH = 1400;

    /** So viel Text vertraegt ein Aufruf — gilt fuer PDF-Auszug wie Rezeptseite. */
    public const TEXT_MAX = 12000;

    /**
     * Die Namen, unter denen die Zugaenge im Formular des Gateways stehen.
     *
     * An EINER Stelle, weil ZWEI Seiten sie brauchen: das Gateway und die
     * Scanner-Instanz, die den Anbieter in ihrer eigenen Spur ruft. Faellt hier
     * spaeter ein Feld dazu und eine Seite vergisst es, laeuft der eine Weg
     * weiter und der andere sagt „nicht eingerichtet" — ein Fehler, den man
     * lange sucht.
     */
    public const KONFIG_FELDER = ['AiProvider', 'AiAnthropicKey', 'AiOpenAIKey',
        'AiLocalBaseUrl', 'AiLocalModel', 'AiLocalKey'];

    /** @var array<string,mixed> die Einstellungen des Gateways, einmal gelesen */
    private array $konfig;

    /** @var list<string> was unterwegs aufgefallen ist */
    private array $notizen = [];

    /**
     * Wer den Ruf tatsaechlich hinausschickt.
     *
     * Im Betrieb `AiHttp::post`. Im Pruefstand eine Attrappe — nur so laesst
     * sich pruefen, WAS gesendet wird: dass `max_completion_tokens` beim
     * PDF-Modell steht und nicht beim alten, dass der lokale Server sein `/v1`
     * bekommt, dass das Diktat seine Grenze richtig setzt. Das ging vorher
     * nicht, und genau dort sassen die Fehler, die erst im Betrieb auffielen.
     *
     * @var null|callable(string,list<string>,string,int,int):array
     */
    private $senden;

    /**
     * Darf noch gesendet werden?
     *
     * Zwischen der Entscheidung „los" und dem tatsaechlichen Netzaufruf liegt
     * ARBEIT: ein PDF wird fuer einen lokalen Server erst in Text oder in
     * Seitenbilder verwandelt, und das dauert. Zieht der Nutzer in dieser Zeit
     * seine Einwilligung zurueck, ging der Inhalt bisher trotzdem hinaus — die
     * Probe davor hatte den Widerruf noch nicht sehen koennen. Gemeldet von
     * einem externen Codereview am 15.09.2026 (F7, zweite Nachfassung).
     *
     * Der Waechter sitzt deshalb an der TRANSPORTGRENZE, nicht davor: `post()`
     * ist die einzige Stelle, durch die jeder Anbieteraufruf geht.
     *
     * @var null|callable():bool
     */
    private $weiter = null;

    /**
     * Den Waechter setzen. Wer keinen setzt, sendet wie bisher.
     *
     * @param null|callable():bool $weiter false = nicht mehr senden
     */
    public function abbruchWaechter(?callable $weiter): void
    {
        $this->weiter = $weiter;
    }

    /**
     * @param array<string,mixed> $konfig
     * @param null|callable(string,list<string>,string,int,int):array $senden
     */
    private function __construct(array $konfig, ?callable $senden = null)
    {
        $this->konfig = $konfig;
        $this->senden = $senden;
    }

    /**
     * @param list<string> $kopf
     * @return array{status:int,body:string,err:string}
     */
    private function post(string $url, array $kopf, string $rumpf, int $frist): array
    {
        /* Die LETZTE Probe, unmittelbar vor dem Netz. Sie wirft, statt einen
           Fehler zurueckzugeben: ein Rueckgabewert liefe durch die Deutung,
           wuerde als „nicht erreichbar" gewertet und der Auftrag noch einmal
           versucht. Weg heisst weg. */
        if ($this->weiter !== null && !($this->weiter)()) {
            throw new AiWiderrufen('Auftrag waehrend der Aufbereitung widerrufen');
        }
        if ($this->senden !== null) {
            return ($this->senden)($url, $kopf, $rumpf, $frist, self::CONNECT_TIMEOUT);
        }
        return AiHttp::post($url, $kopf, $rumpf, $frist, self::CONNECT_TIMEOUT);
    }

    /**
     * Aus den Eigenschaften einer Gateway-Instanz.
     *
     * Erwartet werden die Namen, unter denen sie im Formular stehen:
     * `AiProvider`, `AiAnthropicKey`, `AiOpenAIKey`, `AiLocalBaseUrl`,
     * `AiLocalModel`, `AiLocalKey`.
     *
     * @param array<string,mixed> $konfig
     * @param null|callable(string,list<string>,string,int,int):array $senden Nur fuer den Pruefstand.
     */
    public static function ausKonfiguration(array $konfig, ?callable $senden = null): self
    {
        return new self($konfig, $senden);
    }

    /**
     * Fehlt etwas, um ueberhaupt anrufen zu koennen?
     *
     * Liefert den `grund`, den auch `complete()` liefern wuerde — nur ohne
     * den Aufruf. Damit kann ein Hook sofort absagen, statt einen Auftrag
     * einzureihen, der nie gelingen kann.
     */
    public function konfigurationsfehler(): ?string
    {
        switch ((string)$this->wert('AiProvider')) {
            case 'anthropic':
                return trim((string)$this->wert('AiAnthropicKey')) === '' ? 'anthropic' : null;
            case 'openai':
                return trim((string)$this->wert('AiOpenAIKey')) === '' ? 'openai' : null;
            case 'local':
                return (trim((string)$this->wert('AiLocalBaseUrl')) === ''
                     || trim((string)$this->wert('AiLocalModel')) === '') ? 'local' : null;
        }
        return 'keiner';
    }

    /** Laeuft die KI auf dem eigenen Rechner? Dann darf ein Aufruf viel laenger dauern. */
    public function istLokal(): bool
    {
        return (string)$this->wert('AiProvider') === 'local';
    }

    /** @return list<string> */
    public function debugZeilen(): array
    {
        return $this->notizen;
    }

    /** Eine Einstellung lesen. */
    private function wert(string $name): mixed
    {
        return $this->konfig[$name] ?? '';
    }

    private function merken(string $zeile): void
    {
        // Gedeckelt: ein Laeufer kann viele Aufrufe machen, und die Zeilen
        // wandern am Ende in EINE Antwort.
        if (count($this->notizen) < 50) {
            $this->notizen[] = $zeile;
        }
    }

    /**
     * Ein Fehler als Code.
     *
     * `grund` unterscheidet mehrere Saetze unter demselben Code (drei
     * verschiedene „nicht eingerichtet"), `detail` traegt das Bewegliche
     * (die Meldung von curl, der HTTP-Status, der Grund des Anbieters).
     *
     * @return array{ok:false,code:string,grund:string,detail:string}
     */
    /**
     * Sagt die Fehlerantwort „kein Guthaben"? Dann hilft Warten nicht.
     *
     * OpenAI nennt es in ZWEI Feldern, und nicht immer im selben: frueher
     * `code: insufficient_quota`, am 25.09.2026 gemessen `type:
     * insufficient_quota` mit `code: credit_balance_exhausted`. Nur das
     * Feld `code` zu pruefen liess das leere Konto wieder als „Ratenlimit"
     * durch — drei vergebliche Versuche je Briefing, und die falsche Meldung.
     */
    public static function keinGuthaben(string $body): bool
    {
        $d = json_decode($body, true);
        if (!is_array($d) || !is_array($d['error'] ?? null)) {
            return false;
        }
        $typ  = (string)($d['error']['type'] ?? '');
        $code = (string)($d['error']['code'] ?? '');
        return $typ === 'insufficient_quota'
            || in_array($code, ['insufficient_quota', 'credit_balance_exhausted', 'billing_hard_limit_reached'], true);
    }

    private static function fehler(string $code, string $grund = '', string $detail = ''): array
    {
        return ['ok' => false, 'code' => $code, 'grund' => $grund, 'detail' => $detail];
    }


    /** @return array ok:true+text | ok:false+code+message+status */
    public function complete(string $system, string $userText, ?string $imageBase64, ?string $pdfBase64 = null): array
    {
        $provider = (string) $this->wert('AiProvider');
        // Ein Reasoning-Modell verbraucht sein Budget zuerst im Denken (gemessen
        // lokal: 794 von 990 Tokens gingen in den Denktext) — ein kleiner Deckel
        // brachte regelmaessig eine leere Antwort mit finish_reason „length".
        $maxTokens = self::MAX_TOKENS;

        if ($provider === 'anthropic') {
            $key = trim((string) $this->wert('AiAnthropicKey'));
            if ($key === '') {
                return self::fehler('ai_not_configured', 'anthropic');
            }
            if ($pdfBase64 !== null) {
                $content = [
                    ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdfBase64]],
                    ['type' => 'text', 'text' => $userText],
                ];
            } elseif ($imageBase64 !== null) {
                $content = [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => self::bildTyp($imageBase64), 'data' => $imageBase64]],
                    ['type' => 'text', 'text' => $userText],
                ];
            } else {
                $content = $userText;
            }
            $bodyArr = [
                'model'      => self::ANTHROPIC_MODEL,
                'max_tokens' => $maxTokens,
                'system'     => $system,
                'messages'   => [['role' => 'user', 'content' => $content]],
            ];
            $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
            // JSON_INVALID_UTF8_SUBSTITUTE: ein einzelnes kaputtes Byte im Text
            // (byteweise gekuerzte oder falsch deklarierte Mail) darf den Aufruf
            // nicht in `false` und damit einen TypeError kippen.
            $resp = $this->post('https://api.anthropic.com/v1/messages', $headers, json_encode($bodyArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), self::TIMEOUT);
            return $this->finishText($resp, static function (array $data): string {
                $text = '';
                foreach (($data['content'] ?? []) as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $text .= (string)($block['text'] ?? '');
                    }
                }
                return $text;
            });
        }

        if ($provider === 'openai' || $provider === 'local') {
            // Ein lokaler Server nimmt keine PDF-Datei an, also wird sie hier
            // aufbereitet: erst der Textweg (schnell, genau, traegt jedes digital
            // erzeugte PDF), und nur wenn zu wenig Text herauskommt — also bei
            // einem Scan — gehen die ersten Seiten als Bilder mit. Dafuer braucht
            // es ein Modell mit Bildverstaendnis; hat es keines, kommt eine leere
            // oder fantasierte Antwort zurueck, weshalb der Textweg Vorrang hat.
            $seitenBilder = [];
            if ($provider === 'local' && $pdfBase64 !== null) {
                $pdfText = $this->pdfText($pdfBase64);
                if (strlen($pdfText) >= self::PDF_MIN_TEXT) {
                    $this->merken(sprintf('PDF als Text uebergeben (%d Zeichen)', strlen($pdfText)));
                    $userText .= "\n\n--- Inhalt der beigefuegten PDF-Datei ---\n" . $pdfText;
                } else {
                    $seitenBilder = $this->pdfBilder($pdfBase64);
                    if ($seitenBilder === []) {
                        return self::fehler('ai_pdf_unsupported');
                    }
                    $this->merken(sprintf('PDF als %d Seitenbild(er) uebergeben (kein Text im PDF)', count($seitenBilder)));
                }
                $pdfBase64 = null;   // ab hier ist es Text bzw. sind es Bilder
            }
            if ($provider === 'openai') {
                $key   = trim((string) $this->wert('AiOpenAIKey'));
                $url   = 'https://api.openai.com/v1/chat/completions';
                // PDF braucht ein Modell mit Datei-Input; gpt-4o kann kein PDF.
                $model = ($pdfBase64 !== null) ? self::OPENAI_PDF_MODEL : self::OPENAI_MODEL;
                if (str_starts_with($model, 'gpt-4o')) {
                    $maxTokens = min($maxTokens, self::MAX_TOKENS_GPT4O);
                }
                if ($key === '') {
                    return self::fehler('ai_not_configured', 'openai');
                }
            } else {
                $key     = trim((string) $this->wert('AiLocalKey'));
                $baseUrl = rtrim(trim((string) $this->wert('AiLocalBaseUrl')), '/');
                $model   = trim((string) $this->wert('AiLocalModel'));
                if ($baseUrl === '' || $model === '') {
                    return self::fehler('ai_not_configured', 'local');
                }
                // „http://127.0.0.1:1234" und „…:1234/v1" muessen beide gehen: die
                // Server (LM Studio, Ollama, llama.cpp, vLLM) bedienen alle den
                // Pfad /v1, aber das Formular fragt nach der BASIS. Ohne diese
                // Ergaenzung antwortet LM Studio mit „Unexpected endpoint or
                // method. (POST /chat/completions)" — und die leere Antwort sah
                // aus wie ein Modellfehler (am 19.08.2026 genau so gemessen).
                $url = (preg_match('#/v\d+$#', $baseUrl) === 1 ? $baseUrl : $baseUrl . '/v1') . '/chat/completions';
            }
            if ($seitenBilder !== []) {
                // Jede Seite ein eigener Bildblock, danach die Aufgabe — dieselbe
                // Reihenfolge wie beim Einzelbild.
                $userContent = [];
                foreach ($seitenBilder as $seite) {
                    $userContent[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $seite]];
                }
                $userContent[] = ['type' => 'text', 'text' => $userText];
            } elseif ($pdfBase64 !== null) {
                $userContent = [
                    ['type' => 'file', 'file' => ['filename' => 'dokument.pdf', 'file_data' => 'data:application/pdf;base64,' . $pdfBase64]],
                    ['type' => 'text', 'text' => $userText],
                ];
            } elseif ($imageBase64 !== null) {
                $userContent = [
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . self::bildTyp($imageBase64) . ';base64,' . $imageBase64]],
                    ['type' => 'text', 'text' => $userText],
                ];
            } else {
                $userContent = $userText;
            }
            // Neuere OpenAI-Modelle (PDF) erwarten max_completion_tokens statt max_tokens.
            $tokenKey = ($provider === 'openai' && $pdfBase64 !== null) ? 'max_completion_tokens' : 'max_tokens';
            $bodyArr = [
                'model'    => $model,
                $tokenKey  => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $userContent],
                ],
            ];
            $headers = ['Content-Type: application/json'];
            if ($key !== '') {
                $headers[] = 'Authorization: Bearer ' . $key;
            }
            // Siehe Anthropic-Zweig: kaputte UTF-8-Bytes ersetzen statt scheitern.
            // Der eigene Rechner bekommt mehr Zeit als eine Cloud (AI_LOCAL_TIMEOUT).
            $resp = $this->post(
                $url,
                $headers,
                json_encode($bodyArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                $provider === 'local' ? self::LOCAL_TIMEOUT : self::TIMEOUT
            );
            return $this->finishText($resp, static function (array $data): string {
                return (string)($data['choices'][0]['message']['content'] ?? '');
            });
        }

        return self::fehler('ai_not_configured', 'keiner');
    }


    /** Wertet die HTTP-Antwort aus: Fehler mappen, sonst den Antworttext liefern. */
    private function finishText(array $resp, callable $extractText): array
    {
        if (($resp['err'] ?? '') !== '') {
            return self::fehler('ai_unreachable', '', (string)$resp['err']);
        }
        $status = (int)($resp['status'] ?? 0);
        if ($status === 401 || $status === 403) {
            return self::fehler('ai_unauthorized');
        }
        if ($status === 429) {
            /* Leeres Guthaben sieht aus wie ein Rate-Limit, ist aber das
               Gegenteil: Warten hilft nicht. Der Anbieter unterscheidet es im
               Feld `code` — ohne diese Zeile stand tagelang „versuch es
               spaeter nochmal" an einem Konto ohne Guthaben. */
            if (self::keinGuthaben((string)($resp['body'] ?? ''))) {
                return self::fehler('ai_no_credit');
            }
            return self::fehler('ai_rate_limited');
        }
        if ($status < 200 || $status >= 300) {
            return self::fehler('ai_upstream', '', (string)$status);
        }
        $data = json_decode((string)($resp['body'] ?? ''), true);
        if (!is_array($data)) {
            return self::fehler('ai_bad_response');
        }
        // Abgeschnittene Antwort erkennen: sonst degradiert eine am Token-Limit
        // gekappte Ausgabe still zu einer leeren/halben Liste („nichts erkannt"),
        // obwohl Tokens abgerechnet wurden.
        $stop = (string)($data['stop_reason'] ?? ($data['choices'][0]['finish_reason'] ?? ''));
        if ($stop === 'max_tokens' || $stop === 'length') {
            return self::fehler('ai_truncated');
        }
        $text = $extractText($data);
        if (trim($text) === '') {
            // Die Rohantwort mitschreiben: ein Server, der den Pfad nicht kennt,
            // oder ein Modell ohne Ausgabe sehen von aussen gleich aus — ohne diese
            // Zeile sucht man am falschen Ende (siehe Kommentar zum /v1-Pfad).
            $this->merken('Leere Antwort, Rohdaten: ' . mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE) ?: '', 0, 400));
            return self::fehler('ai_empty');
        }
        return ['ok' => true, 'text' => $text];
    }


    /**
     * Sprache -> Text ueber eine OpenAI-kompatible /audio/transcriptions.
     *
     * Anbieterwahl unabhaengig vom Chat-Anbieter: ein OpenAI-Schluessel gewinnt
     * (auch wenn die Extraktion bei Anthropic oder lokal laeuft — Anthropic hat
     * keine Transkription), sonst der lokale Server, sonst eine klare Ansage.
     *
     * @return array{ok:bool, text?:string, code?:string, message?:string, status?:int}
     */
    public function transcribe(string $bytes, string $mime): array
    {
        $openAiKey = trim((string) $this->wert('AiOpenAIKey'));
        $lokalBase = rtrim(trim((string) $this->wert('AiLocalBaseUrl')), '/');
        if ($openAiKey !== '') {
            $url     = 'https://api.openai.com/v1/audio/transcriptions';
            $modell  = self::TRANSCRIBE_MODEL;
            $kopfAuth = ['Authorization: Bearer ' . $openAiKey];
        } elseif ((string) $this->wert('AiProvider') === 'local' && $lokalBase !== '') {
            // Dieselbe Basis-Ergaenzung wie beim Chat: das Formular fragt nach
            // der BASIS, die Server bedienen /v1.
            $url    = (preg_match('#/v\d+$#', $lokalBase) === 1 ? $lokalBase : $lokalBase . '/v1') . '/audio/transcriptions';
            $modell = 'whisper-1';
            $lokalKey = trim((string) $this->wert('AiLocalKey'));
            $kopfAuth = $lokalKey !== '' ? ['Authorization: Bearer ' . $lokalKey] : [];
        } else {
            return self::fehler('ai_not_configured', 'diktat');
        }

        $endung = match (strtolower(strtok($mime, ';') ?: '')) {
            'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac' => 'm4a',
            'audio/mpeg', 'audio/mp3'                            => 'mp3',
            'audio/ogg'                                          => 'ogg',
            'audio/wav', 'audio/x-wav'                           => 'wav',
            default                                              => 'webm',
        };
        // Multipart von Hand: AiHttpPost nimmt einen fertigen Rumpf, und curl
        // braucht fuer form-data nur die passende Grenze im Content-Type.
        $grenze = '----symdo' . bin2hex(random_bytes(12));
        $rumpf  = '--' . $grenze . "\r\n"
            . "Content-Disposition: form-data; name=\"model\"\r\n\r\n" . $modell . "\r\n"
            . '--' . $grenze . "\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"diktat." . $endung . "\"\r\n"
            . 'Content-Type: ' . $mime . "\r\n\r\n"
            . $bytes . "\r\n"
            . '--' . $grenze . "--\r\n";
        $kopf = array_merge(['Content-Type: multipart/form-data; boundary=' . $grenze], $kopfAuth);

        $resp = $this->post($url, $kopf, $rumpf, self::TRANSCRIBE_TIMEOUT);
        if ($resp['err'] !== '' || $resp['status'] < 200 || $resp['status'] >= 300) {
            $daten = json_decode((string)$resp['body'], true);
            $grund = is_array($daten) ? (string)($daten['error']['message'] ?? '') : '';
            if ($grund === '') {
                $grund = $resp['err'] !== '' ? $resp['err'] : ('HTTP ' . $resp['status']);
            }
            $this->merken('Transkription fehlgeschlagen: ' . $grund);
            return self::fehler('ai_failed', '', mb_substr($grund, 0, 200));
        }
        $daten = json_decode((string)$resp['body'], true);
        $text  = is_array($daten) ? trim((string)($daten['text'] ?? '')) : '';
        if ($text === '') {
            return self::fehler('ai_failed', 'leer');
        }
        return ['ok' => true, 'text' => $text];
    }


    /**
     * Bildtyp aus den Daten selbst bestimmen.
     *
     * Bewusst an den Magic Bytes und nicht am Dateinamen oder an einer
     * durchgereichten Angabe: Das Bild kommt aus drei Richtungen (Kamera der App,
     * Mailanhang, Kachel-Relay), und nur die Bytes wissen sicher, was es ist. Eine
     * falsche Etikettierung weisen die Anbieter zurueck.
     *
     * @return string image/jpeg, image/png, image/gif oder image/webp — im Zweifel
     *                JPEG, denn das war bis dahin die einzige Annahme im Modul.
     */
    public static function bildTyp(string $base64): string
    {
        // 24 base64-Zeichen (Vielfaches von 4) ergeben 18 Bytes — genug für jede Signatur.
        $kopf = (string)base64_decode(substr($base64, 0, 24), false);
        if (str_starts_with($kopf, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($kopf, 'GIF87a') || str_starts_with($kopf, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($kopf, 'RIFF') && substr($kopf, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return 'image/jpeg';
    }


    // ────────────────────────────── PDF für lokale Server ──────────────────────────────

    /**
     * Werkzeug im Dateisystem suchen. Symcon erbt keinen brauchbaren PATH, deshalb
     * absolute Pfade — Homebrew (Apple Silicon und Intel) und die Systemablage.
     */
    private static function werkzeug(string $name): ?string
    {
        foreach (['/opt/homebrew/bin/', '/usr/local/bin/', '/usr/bin/'] as $ordner) {
            $pfad = $ordner . $name;
            if (is_executable($pfad)) {
                return $pfad;
            }
        }
        return null;
    }


    /**
     * Textebene eines PDFs lesen. Leer bei einem Scan — dann ist der Bildweg dran.
     *
     * Bewusst ueber Dateien und exec statt ueber Pipes: proc_open bleibt in Symcons
     * Umgebung haengen (gemessen — das Skript kam nie zurueck), exec dagegen laeuft
     * zuverlaessig. Beide Dateien verschwinden in jedem Fall wieder.
     */
    public function pdfText(string $pdfBase64): string
    {
        $werkzeug = self::werkzeug('pdftotext');
        if ($werkzeug === null) {
            $this->merken('pdftotext nicht gefunden — PDF-Text entfaellt');
            return '';
        }
        $roh = base64_decode($pdfBase64, true);
        if (!is_string($roh) || $roh === '') {
            return '';
        }
        $stamm  = sys_get_temp_dir() . '/symdo_pdftxt_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $quelle = $stamm . '.pdf';
        $ziel   = $stamm . '.txt';
        $text   = '';
        try {
            if (@file_put_contents($quelle, $roh) === false) {
                return '';
            }
            unset($roh);
            $rc = 0;
            $ausgabe = [];
            @exec(escapeshellarg($werkzeug) . ' -layout -enc UTF-8 '
                . escapeshellarg($quelle) . ' ' . escapeshellarg($ziel) . ' 2>/dev/null', $ausgabe, $rc);
            if ($rc !== 0) {
                $this->merken('pdftotext meldet Fehler ' . $rc);
            }
            $text = (string)@file_get_contents($ziel);
        } catch (Throwable $e) {
            $this->merken('PDF-Textauszug fehlgeschlagen: ' . $e->getMessage());
        } finally {
            @unlink($quelle);
            @unlink($ziel);
        }

        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? '');
        if (strlen($text) > self::TEXT_MAX) {
            // mb_strcut, damit kein Multibyte-Zeichen zerschnitten wird (siehe MailPrepareText).
            $text = mb_strcut($text, 0, self::TEXT_MAX, 'UTF-8');
        }
        return $text;
    }


    /**
     * Die ersten Seiten eines PDFs als JPEG — für Scans ohne Textebene.
     *
     * pdftoppm rendert direkt in Zielbreite; GD bleibt bewusst außen vor, denn das
     * Laden einer A4-Seite kostet dort 16 MB von 32 MB memory_limit (gemessen).
     *
     * @return list<string> base64-kodierte JPEGs, leer wenn nichts ging
     */
    public function pdfBilder(string $pdfBase64, int $maxSeiten = self::PDF_PAGES_MAX): array
    {
        $werkzeug = self::werkzeug('pdftoppm');
        if ($werkzeug === null) {
            $this->merken('pdftoppm nicht gefunden — PDF-Bildweg entfaellt');
            return [];
        }
        $roh = base64_decode($pdfBase64, true);
        if (!is_string($roh) || $roh === '') {
            return [];
        }
        $stamm = sys_get_temp_dir() . '/symdo_pdf_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $quelle = $stamm . '.pdf';
        $bilder = [];
        try {
            if (@file_put_contents($quelle, $roh) === false) {
                return [];
            }
            unset($roh);
            $cmd = escapeshellarg($werkzeug) . ' -jpeg -jpegopt quality=82'
                 . ' -f 1 -l ' . max(1, $maxSeiten)
                 . ' -scale-to-x ' . self::PDF_IMAGE_WIDTH . ' -scale-to-y -1 '
                 . escapeshellarg($quelle) . ' ' . escapeshellarg($stamm) . ' 2>/dev/null';
            $rc = 0;
            $ausgabe = [];
            @exec($cmd, $ausgabe, $rc);
            if ($rc !== 0) {
                $this->merken('pdftoppm meldet Fehler ' . $rc);
            }
            $seiten = glob($stamm . '-*.jpg') ?: [];
            sort($seiten, SORT_NATURAL);
            foreach (array_slice($seiten, 0, $maxSeiten) as $datei) {
                $inhalt = (string)@file_get_contents($datei);
                if ($inhalt !== '') {
                    $bilder[] = base64_encode($inhalt);
                }
            }
        } catch (Throwable $e) {
            $this->merken('PDF-Bildwandlung fehlgeschlagen: ' . $e->getMessage());
        } finally {
            // Nichts auf der Platte zurücklassen — auch nicht bei einem Abbruch.
            @unlink($quelle);
            foreach (glob($stamm . '-*.jpg') ?: [] as $datei) {
                @unlink($datei);
            }
        }
        return $bilder;
    }
}
