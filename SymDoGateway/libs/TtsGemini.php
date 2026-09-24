<?php

declare(strict_types=1);

/**
 * Google Gemini TTS: die Antwort auslesen — rein, ohne Symcon, damit der
 * Pruefstand sie direkt fuettern kann.
 *
 * Die Interactions-Schnittstelle (seit 23.09.2026) legt den Ton Base64-kodiert
 * in `steps[] (type model_output) → content[] (type audio) → data`. Zur
 * Sicherheit wird auch die Form der aelteren generateContent-Schnittstelle
 * gelesen (`candidates[0].content.parts[].inlineData.data`) — sie liefert
 * rohes PCM ohne Kopf.
 */
final class TtsGemini
{
    /** Gemini spricht mit 24 kHz, 16 Bit, mono. */
    public const RATE = 24000;

    /** @return string WAV-Daten mit RIFF-Kopf, '' wenn keine Tondaten darin stehen */
    public static function AudioAusAntwort(string $json): string
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return '';
        }
        $b64  = '';
        $mime = '';
        foreach ((array)($d['steps'] ?? []) as $schritt) {
            if (!is_array($schritt) || (string)($schritt['type'] ?? '') !== 'model_output') {
                continue;
            }
            foreach ((array)($schritt['content'] ?? []) as $c) {
                if (is_array($c) && (string)($c['type'] ?? '') === 'audio' && is_string($c['data'] ?? null)) {
                    // Das LETZTE Audio-Stueck, so steht es in der Doku.
                    $b64  = $c['data'];
                    $mime = (string)($c['mime_type'] ?? ($c['mimeType'] ?? ''));
                }
            }
        }
        if ($b64 === '') {
            foreach ((array)($d['candidates'][0]['content']['parts'] ?? []) as $teil) {
                $inline = is_array($teil) ? ($teil['inlineData'] ?? ($teil['inline_data'] ?? null)) : null;
                if (is_array($inline) && is_string($inline['data'] ?? null)) {
                    $b64  = $inline['data'];
                    $mime = (string)($inline['mimeType'] ?? ($inline['mime_type'] ?? ''));
                }
            }
        }
        if ($b64 === '') {
            return '';
        }
        $roh = base64_decode($b64, true);
        if ($roh === false || $roh === '') {
            return '';
        }
        if (str_starts_with($roh, 'RIFF')) {
            return $roh;
        }
        // Rohes PCM (audio/l16): die Rate steht im MIME-Typ („audio/L16;rate=24000").
        $rate = preg_match('/rate=(\d+)/i', $mime, $m) === 1 ? (int)$m[1] : self::RATE;
        return self::WavKopf(strlen($roh), $rate) . $roh;
    }

    /** RIFF/WAVE-Kopf fuer 16-Bit-PCM, mono. */
    public static function WavKopf(int $bytes, int $rate = self::RATE): string
    {
        return 'RIFF' . pack('V', 36 + $bytes) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            . 'data' . pack('V', $bytes);
    }
}
