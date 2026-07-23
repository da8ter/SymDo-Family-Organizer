<?php

declare(strict_types=1);

/**
 * KI-Extraktion für die Web-App. Zwei Einsatzzwecke, gemeinsame Provider-Logik:
 *
 *  1. „Foto → Aufgaben" (ToDo): ein hochgeladenes Foto geht direkt an ein
 *     Vision-LLM (Anthropic, OpenAI oder lokaler OpenAI-kompatibler Server),
 *     das daraus ToDo-Aufgaben ableitet.
 *  2. „Foto/URL → Zutaten" (Einkaufsliste): entweder ein Foto (Rezeptseite,
 *     handschriftliche Liste, Aushang, Verpackung) an ein Vision-LLM, oder eine
 *     Rezept-URL — die Bridge holt die Seite serverseitig (SSRF-geschützt),
 *     macht daraus Text und lässt das LLM die Zutatenliste extrahieren.
 *
 * Der API-Key bleibt in beiden Fällen serverseitig in der Bridge-Config. Es wird
 * nichts automatisch angelegt — die Vorschläge gehen zur Bestätigung an die
 * Web-App (Review-Overlay).
 */
trait AiExtract
{
    // Vision-fähige Default-Modelle der Cloud-Anbieter (wie die iOS-App).
    private const AI_ANTHROPIC_MODEL = 'claude-sonnet-4-5';
    private const AI_OPENAI_MODEL    = 'gpt-4o';
    private const AI_MAX_TOKENS      = 2000;
    private const AI_TIMEOUT         = 120;
    // Rezept-URL-Analyse
    private const AI_MAX_INGREDIENTS  = 100;
    private const AI_HTTP_GET_TIMEOUT = 15;
    private const AI_RECIPE_TEXT_MAX  = 12000;

    // ────────────────────────────── ToDo (Foto → Aufgaben) ──────────────────────────────

    private function HandleAiExtract(array $device): void
    {
        if (!$this->AiIsEnabled()) {
            return;
        }
        $body  = $this->ReadJsonBody();
        $image = $this->AiReadImage($body);
        if ($image === null) {
            return; // Fehler wurde bereits gesendet
        }
        $result = $this->AiExtractTodos($image);
        if (($result['ok'] ?? false) !== true) {
            $this->SendAiErrorResult($result);
            return;
        }
        $this->SendJson(['ok' => true, 'todos' => $result['todos']]);
    }

    /** @return array ok:true+todos | ok:false+code+message+status */
    private function AiExtractTodos(string $imageBase64): array
    {
        $r = $this->AiRunCompletion(
            $this->AiSystemPrompt(date('Y-m-d')),
            'Extrahiere die Aufgaben aus diesem Dokument.',
            $imageBase64
        );
        if (($r['ok'] ?? false) !== true) {
            return $r;
        }
        return ['ok' => true, 'todos' => $this->AiParseTodos((string)$r['text'])];
    }

    // ─────────────────────── Einkaufsliste (Foto/URL → Zutaten) ───────────────────────

    private function HandleAiIngredients(array $device): void
    {
        if (!$this->AiIsEnabled()) {
            return;
        }
        $body = $this->ReadJsonBody();
        $url  = trim((string)($body['url'] ?? ''));

        if (($body['image'] ?? '') !== '') {
            $image = $this->AiReadImage($body);
            if ($image === null) {
                return;
            }
            $result = $this->AiExtractIngredientsFromImage($image);
        } elseif ($url !== '') {
            $result = $this->AiExtractIngredientsFromUrl($url);
        } else {
            $this->SendApiError('invalid_payload', $this->Translate('No image or URL provided.'), 422);
            return;
        }

        if (($result['ok'] ?? false) !== true) {
            $this->SendAiErrorResult($result);
            return;
        }
        $this->SendJson(['ok' => true, 'items' => $result['items']]);
    }

    /** @return array ok:true+items | ok:false+code+message+status */
    private function AiExtractIngredientsFromImage(string $imageBase64): array
    {
        $r = $this->AiRunCompletion(
            $this->AiIngredientsSystemPrompt(),
            'Extrahiere die Artikel bzw. Zutaten aus diesem Bild.',
            $imageBase64
        );
        if (($r['ok'] ?? false) !== true) {
            return $r;
        }
        return ['ok' => true, 'items' => $this->AiParseIngredients((string)$r['text'])];
    }

    /** @return array ok:true+items | ok:false+code+message+status */
    private function AiExtractIngredientsFromUrl(string $url): array
    {
        $page = $this->AiFetchPublicPage($url);
        if (($page['ok'] ?? false) !== true) {
            return $page;
        }
        $text = $this->AiRecipeText((string)$page['body']);
        if ($text === '') {
            return ['ok' => false, 'code' => 'ai_url_empty', 'message' => $this->Translate('Could not load the page.'), 'status' => 502];
        }
        $r = $this->AiRunCompletion(
            $this->AiRecipeSystemPrompt(),
            "Extrahiere die Zutatenliste aus diesem Rezept:\n\n" . $text,
            null
        );
        if (($r['ok'] ?? false) !== true) {
            return $r;
        }
        return ['ok' => true, 'items' => $this->AiParseIngredients((string)$r['text'])];
    }

    // ────────────────────────────── Gemeinsame Provider-Logik ──────────────────────────────

    /**
     * Ein Chat-/Vision-Aufruf an den konfigurierten Anbieter. Ohne $imageBase64
     * wird eine reine Text-Anfrage gestellt (Rezept-Text), sonst ein Vision-Block
     * vorangestellt (Foto).
     * @return array ok:true+text | ok:false+code+message+status
     */
    private function AiRunCompletion(string $system, string $userText, ?string $imageBase64): array
    {
        $provider = $this->ReadPropertyString('AiProvider');

        if ($provider === 'anthropic') {
            $key = trim($this->ReadPropertyString('AiAnthropicKey'));
            if ($key === '') {
                return ['ok' => false, 'code' => 'ai_not_configured', 'message' => $this->Translate('No Anthropic API key configured.'), 'status' => 400];
            }
            if ($imageBase64 !== null) {
                $content = [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $imageBase64]],
                    ['type' => 'text', 'text' => $userText],
                ];
            } else {
                $content = $userText;
            }
            $bodyArr = [
                'model'      => self::AI_ANTHROPIC_MODEL,
                'max_tokens' => self::AI_MAX_TOKENS,
                'system'     => $system,
                'messages'   => [['role' => 'user', 'content' => $content]],
            ];
            $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
            $resp = $this->AiHttpPost('https://api.anthropic.com/v1/messages', $headers, json_encode($bodyArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $this->AiFinishText($resp, static function (array $data): string {
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
            if ($provider === 'openai') {
                $key   = trim($this->ReadPropertyString('AiOpenAIKey'));
                $url   = 'https://api.openai.com/v1/chat/completions';
                $model = self::AI_OPENAI_MODEL;
                if ($key === '') {
                    return ['ok' => false, 'code' => 'ai_not_configured', 'message' => $this->Translate('No OpenAI API key configured.'), 'status' => 400];
                }
            } else {
                $key     = trim($this->ReadPropertyString('AiLocalKey'));
                $baseUrl = rtrim(trim($this->ReadPropertyString('AiLocalBaseUrl')), '/');
                $model   = trim($this->ReadPropertyString('AiLocalModel'));
                if ($baseUrl === '' || $model === '') {
                    return ['ok' => false, 'code' => 'ai_not_configured', 'message' => $this->Translate('Local server URL and model must be configured.'), 'status' => 400];
                }
                $url = $baseUrl . '/chat/completions';
            }
            if ($imageBase64 !== null) {
                $userContent = [
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imageBase64]],
                    ['type' => 'text', 'text' => $userText],
                ];
            } else {
                $userContent = $userText;
            }
            $bodyArr = [
                'model'      => $model,
                'max_tokens' => self::AI_MAX_TOKENS,
                'messages'   => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $userContent],
                ],
            ];
            $headers = ['Content-Type: application/json'];
            if ($key !== '') {
                $headers[] = 'Authorization: Bearer ' . $key;
            }
            $resp = $this->AiHttpPost($url, $headers, json_encode($bodyArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $this->AiFinishText($resp, static function (array $data): string {
                return (string)($data['choices'][0]['message']['content'] ?? '');
            });
        }

        return ['ok' => false, 'code' => 'ai_not_configured', 'message' => $this->Translate('No AI provider configured.'), 'status' => 400];
    }

    /** Wertet die HTTP-Antwort aus: Fehler mappen, sonst den Antworttext liefern. */
    private function AiFinishText(array $resp, callable $extractText): array
    {
        if (($resp['err'] ?? '') !== '') {
            return ['ok' => false, 'code' => 'ai_unreachable', 'message' => $this->Translate('Could not reach the AI service.') . ' ' . $resp['err'], 'status' => 502];
        }
        $status = (int)($resp['status'] ?? 0);
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'code' => 'ai_unauthorized', 'message' => $this->Translate('AI rejected the API key.'), 'status' => 502];
        }
        if ($status === 429) {
            return ['ok' => false, 'code' => 'ai_rate_limited', 'message' => $this->Translate('AI rate limit reached — try again later.'), 'status' => 502];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'code' => 'ai_upstream', 'message' => $this->Translate('AI request failed.') . ' (HTTP ' . $status . ')', 'status' => 502];
        }
        $data = json_decode((string)($resp['body'] ?? ''), true);
        if (!is_array($data)) {
            return ['ok' => false, 'code' => 'ai_bad_response', 'message' => $this->Translate('Unexpected AI response.'), 'status' => 502];
        }
        return ['ok' => true, 'text' => $extractText($data)];
    }

    // ────────────────────────────── Parser ──────────────────────────────

    /** Toleranter Parser: erstes „[" … letztes „]", dann feldweise validieren. */
    private function AiParseTodos(string $text): array
    {
        $rows = $this->AiDecodeJsonArray($text);
        $out  = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $info = $row['info'] ?? null;
            $info = ($info === null || $info === '') ? null : (string)$info;

            $due = trim((string)($row['due'] ?? ''));
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                // gültiges Kalenderdatum
            } else {
                $due = null;
            }

            $priority = strtolower(trim((string)($row['priority'] ?? 'normal')));
            if (!in_array($priority, ['high', 'normal', 'low'], true)) {
                $priority = 'normal';
            }
            $out[] = ['title' => $title, 'info' => $info, 'due' => $due, 'priority' => $priority];
            if (count($out) >= 50) {
                break;
            }
        }
        return $out;
    }

    /** Toleranter Parser für Zutaten/Artikel: {name, amount, category}. */
    private function AiParseIngredients(string $text): array
    {
        $rows = $this->AiDecodeJsonArray($text);
        $out  = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $amount = $row['amount'] ?? '';
            if (is_int($amount) || is_float($amount)) {
                $amount = (string)$amount;
            }
            $amount   = is_string($amount) ? trim($amount) : '';
            $category = trim((string)($row['category'] ?? ''));
            $out[] = ['name' => $name, 'amount' => $amount, 'category' => $category];
            if (count($out) >= self::AI_MAX_INGREDIENTS) {
                break;
            }
        }
        return $out;
    }

    /** Schneidet das erste JSON-Array aus dem Text und dekodiert es. */
    private function AiDecodeJsonArray(string $text): array
    {
        $start = strpos($text, '[');
        $end   = strrpos($text, ']');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $rows = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($rows) ? $rows : [];
    }

    // ────────────────────────────── HTTP ──────────────────────────────

    private function AiHttpPost(string $url, array $headers, string $bodyJson): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::AI_TIMEOUT);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        $err  = ($body === false) ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => is_string($body) ? $body : '', 'err' => $err];
    }

    /**
     * Holt eine öffentliche Webseite server-seitig — SSRF-sicher: jede Weiterleitung
     * wird einzeln gegen private/reservierte Adressbereiche geprüft (kein blindes
     * FOLLOWLOCATION). Nur http/https, Zeit-/Größen-Limits.
     * @return array ok:true+body | ok:false+code+message+status
     */
    private function AiFetchPublicPage(string $url): array
    {
        $urlErr = ['ok' => false, 'code' => 'invalid_url', 'message' => $this->Translate('Invalid or non-public URL.'), 'status' => 422];
        for ($hop = 0; $hop < 4; $hop++) {
            if (!$this->AiIsPublicUrl($url)) {
                return $urlErr;
            }
            $resp   = $this->AiHttpGet($url);
            $status = (int)$resp['status'];
            if (($resp['err'] ?? '') !== '') {
                return ['ok' => false, 'code' => 'ai_url_fetch', 'message' => $this->Translate('Could not load the page.'), 'status' => 502];
            }
            if ($status >= 300 && $status < 400 && (string)$resp['location'] !== '' && $hop < 3) {
                $url = $this->AiResolveRedirect($url, (string)$resp['location']);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                return ['ok' => false, 'code' => 'ai_url_fetch', 'message' => $this->Translate('Could not load the page.') . ' (HTTP ' . $status . ')', 'status' => 502];
            }
            return ['ok' => true, 'body' => (string)$resp['body']];
        }
        return $urlErr;
    }

    /** Schema + öffentlicher (nicht privater/reservierter) Host? */
    private function AiIsPublicUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts  = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host   = (string)($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }
        $host = trim($host, '[]'); // IPv6-Literale
        $ips  = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            $a = @gethostbynamel($host);
            if (is_array($a)) {
                $ips = $a;
            }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = (string)$rec['ipv6'];
                    }
                }
            }
        }
        if ($ips === []) {
            return false; // nicht auflösbar → ablehnen
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false; // privat / loopback / link-local / reserviert
            }
        }
        return true;
    }

    private function AiResolveRedirect(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p      = parse_url($base);
        $scheme = (string)($p['scheme'] ?? 'https');
        $host   = (string)($p['host'] ?? '');
        $port   = isset($p['port']) ? ':' . $p['port'] : '';
        if ($host === '') {
            return $location;
        }
        if ($location !== '' && $location[0] === '/') {
            return $scheme . '://' . $host . $port . $location;
        }
        $path = (string)($p['path'] ?? '/');
        $slash = strrpos($path, '/');
        $dir   = $slash === false ? '/' : substr($path, 0, $slash + 1);
        return $scheme . '://' . $host . $port . $dir . $location;
    }

    private function AiHttpGet(string $url): array
    {
        $body     = '';
        $location = '';
        $max      = 2 * 1024 * 1024;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT        => self::AI_HTTP_GET_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SymDoBridge/1.0)',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, $max): int {
                $body .= $chunk;
                return (strlen($body) > $max) ? 0 : strlen($chunk);
            },
        ]);
        $ok    = curl_exec($ch);
        $errno = curl_errno($ch);
        // Abbruch durch die Größenbegrenzung (CURLE_WRITE_ERROR) ist kein Fehler:
        // der bis dahin geladene Body reicht.
        $err    = ($ok === false && $errno !== CURLE_WRITE_ERROR) ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'err' => $err, 'location' => $location];
    }

    // ────────────────────────────── Rezept-Text ──────────────────────────────

    /** Macht aus Roh-HTML den für die KI relevanten Text (JSON-LD-Zutaten bevorzugt). */
    private function AiRecipeText(string $html): string
    {
        $prefix      = '';
        $ingredients = $this->AiExtractJsonLdIngredients($html);
        if ($ingredients !== []) {
            $prefix = "Zutaten (aus strukturierten Daten der Seite):\n- " . implode("\n- ", $ingredients) . "\n\n";
        }
        $combined = $prefix . $this->AiHtmlToText($html);
        if (strlen($combined) > self::AI_RECIPE_TEXT_MAX) {
            $combined = substr($combined, 0, self::AI_RECIPE_TEXT_MAX);
        }
        return trim($combined);
    }

    /** schema.org/Recipe „recipeIngredient" aus JSON-LD-Blöcken (rekursiv). */
    private function AiExtractJsonLdIngredients(string $html): array
    {
        if (!preg_match_all('#<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $block) {
            $data = json_decode(trim($block), true);
            if (is_array($data)) {
                $this->AiCollectRecipeIngredients($data, $out);
            }
        }
        $clean = [];
        foreach ($out as $line) {
            $line = trim((string)preg_replace('/\s+/', ' ', (string)$line));
            if ($line !== '') {
                $clean[$line] = true;
            }
        }
        return array_slice(array_keys($clean), 0, self::AI_MAX_INGREDIENTS);
    }

    private function AiCollectRecipeIngredients(array $node, array &$out): void
    {
        foreach ($node as $key => $val) {
            if ($key === 'recipeIngredient' && is_array($val)) {
                foreach ($val as $ing) {
                    if (is_string($ing)) {
                        $out[] = $ing;
                    }
                }
            } elseif (is_array($val)) {
                $this->AiCollectRecipeIngredients($val, $out);
            }
        }
    }

    private function AiHtmlToText(string $html): string
    {
        // Skripte/Styles/versteckte Blöcke zuerst raus, sonst landet JS/CSS im Text.
        $t = preg_replace('#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $t = preg_replace('#<!--.*?-->#s', ' ', $t) ?? $t;
        $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t) ?? $t;
        $t = preg_replace('/<\/\s*(p|div|li|tr|h[1-6])\s*>/i', "\n", $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+\n/", "\n", $t) ?? $t;
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    // ────────────────────────────── Helfer ──────────────────────────────

    /** Master-Schalter: bei deaktivierter KI wird der Endpunkt abgelehnt (403). */
    private function AiIsEnabled(): bool
    {
        if ($this->ReadPropertyBoolean('AiEnabled')) {
            return true;
        }
        $this->SendApiError('ai_disabled', $this->Translate('AI analysis is disabled.'), 403);
        return false;
    }

    /** Liest das Bild aus dem Body (data:-Prefix strippen, Größen-Guard). null = Fehler gesendet. */
    private function AiReadImage(array $body): ?string
    {
        $image = (string)($body['image'] ?? '');
        $comma = strpos($image, 'base64,');
        if ($comma !== false) {
            $image = substr($image, $comma + 7);
        }
        $image = trim($image);
        if ($image === '') {
            $this->SendApiError('invalid_payload', $this->Translate('No image provided.'), 422);
            return null;
        }
        // grobe Größenbegrenzung (base64 ~ 4/3 der Bytes); die Web-App skaliert vorher runter
        if (strlen($image) > 12 * 1024 * 1024) {
            $this->SendApiError('invalid_payload', $this->Translate('Image too large.'), 413);
            return null;
        }
        return $image;
    }

    private function SendAiErrorResult(array $result): void
    {
        $this->SendApiError(
            (string)($result['code'] ?? 'ai_error'),
            (string)($result['message'] ?? 'AI error'),
            (int)($result['status'] ?? 502)
        );
    }

    // ────────────────────────────── Prompts ──────────────────────────────

    private function AiSystemPrompt(string $today): string
    {
        return 'Du extrahierst Aufgaben (ToDos) aus Dokumenten: Briefe, Behörden- und Bankschreiben, '
            . 'Rechnungen, Notizen, Listen, E-Mails oder Fotos davon. Wichtig: Aufgaben stehen oft NICHT '
            . 'als Liste im Dokument, sondern stecken implizit in Handlungsaufforderungen — z.B. „bitte '
            . 'bestätigen Sie…“, „senden Sie das Formular zurück“, „überweisen Sie bis…“, „vereinbaren Sie '
            . 'einen Termin“. Leite daraus die Aufgabe ab, die der Empfänger erledigen muss, aus dessen '
            . 'Sicht formuliert (kurzer, prägnanter deutscher Titel, z.B. „Daten bei der Commerzbank '
            . 'bestätigen“). Drohende Konsequenzen (Sperrung, Mahnung, Frist) → priority "high". Nutze '
            . '"info" für den wichtigsten Kontext (Absender, Referenz, Konsequenz, geforderter Weg). Ein '
            . 'Termin oder eine Frist im Dokument gehört in "due". Heute ist der ' . $today . '. Antworte '
            . 'AUSSCHLIESSLICH mit einem JSON-Array, ohne Erklärungen und ohne Markdown. Jedes Element hat '
            . 'exakt diese Felder: {"title": string, "info": string oder null, "due": "YYYY-MM-DD" oder '
            . 'null, "priority": "high" oder "normal" oder "low"}. Nur wenn wirklich keinerlei Handlung für '
            . 'den Empfänger erkennbar ist, antworte mit [].';
    }

    private function AiIngredientsSystemPrompt(): string
    {
        return 'Du extrahierst Einkaufs-Artikel bzw. Zutaten aus einem Bild: Rezept- oder Kochbuchseiten, '
            . 'handschriftliche Einkaufslisten, Aushänge, Notizzettel oder Produktverpackungen. Gib die '
            . 'benötigten Artikel/Zutaten zurück. "name" = der Artikel bzw. die Zutat, kurz und ohne Menge '
            . '(z.B. „Mehl“, „Tomaten“, „Milch“). "amount" = die Menge samt Einheit als kurzer Text '
            . '(z.B. „500 g“, „2“, „1 Bund“), leer wenn keine Menge angegeben ist. "category" = grobe '
            . 'Lebensmittel-Kategorie (z.B. „Obst & Gemüse“, „Molkerei“, „Backwaren“, „Fleisch“) oder leer. '
            . 'Fasse Dubletten zusammen. Antworte AUSSCHLIESSLICH mit einem JSON-Array, ohne Erklärungen und '
            . 'ohne Markdown. Jedes Element hat exakt diese Felder: {"name": string, "amount": string, '
            . '"category": string}. Wenn keinerlei Artikel erkennbar sind, antworte mit [].';
    }

    private function AiRecipeSystemPrompt(): string
    {
        return 'Du erhältst den Textinhalt einer Rezept-Webseite. Extrahiere daraus die vollständige '
            . 'Zutatenliste des Rezepts. Ignoriere Navigation, Werbung, Kommentare, Nährwerte und '
            . 'Zubereitungsschritte. "name" = die Zutat, kurz und ohne Menge (z.B. „Mehl“, „Zwiebeln“). '
            . '"amount" = die Menge samt Einheit als kurzer Text (z.B. „500 g“, „2“, „1 EL“), leer wenn '
            . 'keine Menge angegeben ist. "category" = grobe Lebensmittel-Kategorie (z.B. „Obst & Gemüse“, '
            . '„Molkerei“, „Backwaren“, „Fleisch“) oder leer. Rechne Mengen NICHT um. Antworte '
            . 'AUSSCHLIESSLICH mit einem JSON-Array, ohne Erklärungen und ohne Markdown. Jedes Element hat '
            . 'exakt diese Felder: {"name": string, "amount": string, "category": string}. Wenn keine '
            . 'Zutaten erkennbar sind, antworte mit [].';
    }
}
