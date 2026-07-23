<?php

declare(strict_types=1);

/**
 * KI „Foto → Aufgaben": nimmt ein von der Web-App hochgeladenes Foto entgegen und
 * lässt daraus per Vision-LLM ToDo-Aufgaben extrahieren. Anders als die iOS-App
 * (on-device-OCR, dann Text an die KI) hat der Server kein OCR → das Foto geht
 * direkt an ein Vision-Modell (Anthropic, OpenAI oder lokaler OpenAI-kompatibler
 * Server). Der API-Key bleibt serverseitig in der Bridge-Config.
 *
 * Gibt die Vorschläge NUR zurück ({title, info|null, due:"YYYY-MM-DD"|null,
 * priority}); angelegt werden sie erst nach Nutzer-Review in der Web-App.
 */
trait AiExtract
{
    // Vision-fähige Default-Modelle der Cloud-Anbieter (wie die iOS-App).
    private const AI_ANTHROPIC_MODEL = 'claude-sonnet-4-5';
    private const AI_OPENAI_MODEL    = 'gpt-4o';
    private const AI_MAX_TOKENS      = 2000;
    private const AI_TIMEOUT         = 120;

    private function HandleAiExtract(array $device): void
    {
        $body  = $this->ReadJsonBody();
        $image = (string)($body['image'] ?? '');
        // data:-Prefix entfernen → reines base64
        $comma = strpos($image, 'base64,');
        if ($comma !== false) {
            $image = substr($image, $comma + 7);
        }
        $image = trim($image);
        if ($image === '') {
            $this->SendApiError('invalid_payload', $this->Translate('No image provided.'), 422);
            return;
        }
        // grobe Größenbegrenzung (base64 ~ 4/3 der Bytes); die Web-App skaliert vorher runter
        if (strlen($image) > 12 * 1024 * 1024) {
            $this->SendApiError('invalid_payload', $this->Translate('Image too large.'), 413);
            return;
        }

        $result = $this->AiExtractTodos($image);
        if (($result['ok'] ?? false) !== true) {
            $this->SendApiError(
                (string)($result['code'] ?? 'ai_error'),
                (string)($result['message'] ?? 'AI error'),
                (int)($result['status'] ?? 502)
            );
            return;
        }
        $this->SendJson(['ok' => true, 'todos' => $result['todos']]);
    }

    /** @return array ok:true+todos | ok:false+code+message+status */
    private function AiExtractTodos(string $imageBase64): array
    {
        $provider = $this->ReadPropertyString('AiProvider');
        $today    = date('Y-m-d');
        $system   = $this->AiSystemPrompt($today);
        $userText = 'Extrahiere die Aufgaben aus diesem Dokument.';

        if ($provider === 'anthropic') {
            $key = trim($this->ReadPropertyString('AiAnthropicKey'));
            if ($key === '') {
                return ['ok' => false, 'code' => 'ai_not_configured', 'message' => $this->Translate('No Anthropic API key configured.'), 'status' => 400];
            }
            $bodyArr = [
                'model'      => self::AI_ANTHROPIC_MODEL,
                'max_tokens' => self::AI_MAX_TOKENS,
                'system'     => $system,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $imageBase64]],
                        ['type' => 'text', 'text' => $userText],
                    ],
                ]],
            ];
            $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
            $resp = $this->AiHttpPost('https://api.anthropic.com/v1/messages', $headers, json_encode($bodyArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $this->AiFinish($resp, static function (array $data): string {
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
            $bodyArr = [
                'model'      => $model,
                'max_tokens' => self::AI_MAX_TOKENS,
                'messages'   => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => [
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imageBase64]],
                        ['type' => 'text', 'text' => $userText],
                    ]],
                ],
            ];
            $headers = ['Content-Type: application/json'];
            if ($key !== '') {
                $headers[] = 'Authorization: Bearer ' . $key;
            }
            $resp = $this->AiHttpPost($url, $headers, json_encode($bodyArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $this->AiFinish($resp, static function (array $data): string {
                return (string)($data['choices'][0]['message']['content'] ?? '');
            });
        }

        return ['ok' => false, 'code' => 'ai_not_configured', 'message' => $this->Translate('No AI provider configured.'), 'status' => 400];
    }

    /** Wertet die HTTP-Antwort aus: Fehler mappen, sonst Text extrahieren + parsen. */
    private function AiFinish(array $resp, callable $extractText): array
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
        $text = $extractText($data);
        return ['ok' => true, 'todos' => $this->AiParseTodos((string)$text)];
    }

    /** Toleranter Parser: erstes „[" … letztes „]", dann feldweise validieren. */
    private function AiParseTodos(string $text): array
    {
        $start = strpos($text, '[');
        $end   = strrpos($text, ']');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $json = substr($text, $start, $end - $start + 1);
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
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
}
