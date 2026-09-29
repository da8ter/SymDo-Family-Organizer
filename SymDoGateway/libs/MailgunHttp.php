<?php

declare(strict_types=1);

/**
 * Der eingebaute Sender fuer `MailgunSetup` — curl, sonst nichts.
 *
 * Eigene Datei, weil er das Austauschbare ist: der Pruefstand reicht an seiner
 * Stelle eine Attrappe herein und spricht nie mit Mailgun. Was er selbst
 * garantiert, unabhaengig davon, wer ihn ruft:
 *  - nur https zu den zwei API-Hosts (`MailgunSetup::urlErlaubt`), geprueft
 *    VOR dem Verbindungsaufbau; auch curl darf kein anderes Protokoll;
 *  - keine Weiterleitungen (eine 3xx kommt als Status zurueck);
 *  - Verbindung hoechstens VERBINDEN_S, gesamt hoechstens die mitgegebene Zeit;
 *  - hoechstens ANTWORT_MAX Bytes Antwort.
 * Die Kopfzeilen (samt Authorization) gehen nirgends hin ausser zu curl.
 */
final class MailgunHttp
{
    /**
     * @param list<string> $kopf
     * @return array{status: int, body: string, error: string}
     */
    public static function senden(string $methode, string $url, #[\SensitiveParameter] array $kopf,
        string $rumpf, int $zeitMs): array
    {
        if (!MailgunSetup::urlErlaubt($url)) {
            return ['status' => 0, 'body' => '', 'error' => 'host not allowed'];
        }
        $zeitMs  = max(1, $zeitMs);
        $puffer  = '';
        $zuGross = false;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL               => $url,
            CURLOPT_CUSTOMREQUEST     => $methode,
            CURLOPT_HTTPHEADER        => $kopf,
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_MAXREDIRS         => 0,
            CURLOPT_CONNECTTIMEOUT_MS => min(MailgunSetup::VERBINDEN_S * 1000, $zeitMs),
            CURLOPT_TIMEOUT_MS        => $zeitMs,
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_SSL_VERIFYHOST    => 2,
            CURLOPT_NOSIGNAL          => true,
            CURLOPT_WRITEFUNCTION     => static function ($ch, string $stueck) use (&$puffer, &$zuGross): int {
                if (strlen($puffer) + strlen($stueck) > MailgunSetup::ANTWORT_MAX) {
                    $zuGross = true;
                    return 0;   // bricht den Transfer ab
                }
                $puffer .= $stueck;
                return strlen($stueck);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
        } else {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if ($methode === 'POST' || $methode === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rumpf);
        }
        $ok     = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $fehler = $zuGross ? 'answer too large' : ($ok === false ? curl_error($ch) : '');
        // Kein curl_close(): seit PHP 8.0 wirkungslos, ab 8.5 als veraltet gemeldet.
        unset($ch);
        return ['status' => $ok === false ? 0 : $status, 'body' => $puffer, 'error' => $fehler];
    }
}
