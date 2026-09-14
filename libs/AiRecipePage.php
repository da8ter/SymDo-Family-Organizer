<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProvider.php';

/**
 * Eine oeffentliche Seite holen und den Text herausschaelen — ohne Symcon.
 *
 * Gebraucht wird das fuer „Rezept von einer Adresse": der Nutzer gibt eine
 * URL, der Server holt die Seite und schickt ihren Text an die KI. Weil der
 * SERVER holt und nicht der Browser, ist jede Adresse ein Einfallstor ins
 * eigene Netz. Deshalb prueft diese Klasse JEDE Weiterleitung einzeln gegen
 * private und reservierte Adressbereiche und bindet die geprueifte Adresse an
 * curl — sonst loeste curl den Namen ein zweites Mal auf, und eine Antwort mit
 * Lebensdauer null koennte beim zweiten Mal auf 127.0.0.1 zeigen.
 *
 * Herausgeloest aus `AiExtract`, damit ein Laufwerk ausserhalb des Gateways
 * sie benutzen kann. Fehler kommen als CODE zurueck; den Satz dazu macht
 * `AiErrorMessage()` im Trait.
 */
final class AiRecipePage
{
    /**
     * Wie lange auf eine fremde Seite gewartet wird — INSGESAMT, ueber alle
     * Weiterleitungen hinweg.
     *
     * Bis zum 14.09.2026 galt die Zahl je Sprung. Vier Spruenge sind erlaubt,
     * der schlimmste Fall waren also 60 Sekunden plus Verbindungsaufbau — in
     * der Gateway-Spur, die waehrenddessen keinen Hook bedient. Eine Seite, die
     * ihre Weiterleitungskette nicht in fuenfzehn Sekunden durchlaeuft, gibt
     * auch nach einer Minute nichts her.
     */
    public const GET_TIMEOUT = 15;

    /**
     * Dieselbe Frage im HOOK: dort wartet ein Mensch. Gilt fuer den
     * synchronen Rueckfallweg von `/v1/ai/ingredients` — mit `async` holt
     * laengst der Laeufer, und der nimmt sich die vollen fuenfzehn.
     */
    public const GET_TIMEOUT_HOOK = 8;

    /**
     * Unter so viel Rest lohnt kein weiterer Sprung mehr — die Kette bricht
     * dann ab, statt ein Mindestmass draufzulegen. Nur so ist die Frist eine
     * echte Obergrenze und keine Richtgroesse.
     */
    private const HOP_MIN = 2;

    /**
     * So viel Text geht hoechstens an die KI. Bewusst DIESELBE Zahl wie beim
     * PDF-Auszug: es ist dieselbe Frage — was vertraegt ein Aufruf.
     */
    public const TEXT_MAX = AiProvider::TEXT_MAX;

    /**
     * So viele Zutaten nimmt eine Seite hoechstens her. Eine Rezeptseite mit
     * mehr als hundert ist keine mehr — dann hat die Seite eine Liste
     * ausgegeben, die niemand kochen will.
     */
    public const MAX_INGREDIENTS = 100;


    /**
     * Holt eine öffentliche Webseite server-seitig — SSRF-sicher: jede Weiterleitung
     * wird einzeln gegen private/reservierte Adressbereiche geprüft (kein blindes
     * FOLLOWLOCATION). Nur http/https, Zeit-/Größen-Limits.
     * @return array ok:true+body | ok:false+code+message+status
     */
    public static function holen(string $url, int $frist = self::GET_TIMEOUT): array
    {
        $urlErr = ['ok' => false, 'code' => 'invalid_url'];
        /* Das Budget wird VERBRAUCHT, nicht je Sprung neu vergeben: sonst
           koennte eine Kette aus vier Weiterleitungen das Vierfache kosten. */
        $rest = max(self::HOP_MIN, $frist);
        for ($hop = 0; $hop < 4; $hop++) {
            $validIps = [];
            if (!self::istOeffentlich($url, $validIps)) {
                return $urlErr;
            }
            // Die geprüfte IP wird an cURL gebunden: sonst löst cURL den Namen ein
            // zweites Mal auf und ein 0-TTL-Rebinding könnte auf 127.0.0.1 zeigen.
            $begonnen = microtime(true);
            $resp   = self::hol($url, $validIps, $rest);
            $rest   = (int)max(self::HOP_MIN, $rest - (int)ceil(microtime(true) - $begonnen));
            $status = (int)$resp['status'];
            if (($resp['err'] ?? '') !== '') {
                return ['ok' => false, 'code' => 'ai_url_fetch'];
            }
            if ($status >= 300 && $status < 400 && (string)$resp['location'] !== '' && $hop < 3) {
                /* Ist das Budget aufgebraucht, wird der Weiterleitung NICHT
                   mehr gefolgt. Sonst kaeme zur Frist je Sprung ein Mindestmass
                   hinzu, und aus der Obergrenze waere wieder eine Richtgroesse
                   geworden — genau der Fehler, den dieser Umbau abstellt. */
                if ($rest <= self::HOP_MIN) {
                    return ['ok' => false, 'code' => 'ai_url_fetch', 'detail' => 'timeout'];
                }
                $url = self::umleitung($url, (string)$resp['location']);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                return ['ok' => false, 'code' => 'ai_url_fetch', 'detail' => (string)$status];
            }
            return ['ok' => true, 'body' => (string)$resp['body']];
        }
        return $urlErr;
    }


    /** Schema + öffentlicher (nicht privater/reservierter) Host? */
    public static function istOeffentlich(string $url, ?array &$resolvedIps = null): bool
    {
        $resolvedIps = [];
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts  = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host   = (string)($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }
        // Credentials in der URL (http://user:pass@host) ablehnen und nur die
        // Standard-Ports zulassen — sonst wird das Gateway zum Port-Scanner.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $port = (int)($parts['port'] ?? 0);
        if ($port !== 0 && $port !== 80 && $port !== 443) {
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
            // PHPs Filter lässt diese durch, sie sind aber nicht „öffentlich":
            // 100.64.0.0/10 (CGNAT), 198.18.0.0/15 (Benchmark), 64:ff9b::/96 (NAT64,
            // mappt u.a. 127.0.0.1) und IPv4-mapped IPv6.
            if (self::istGesperrt($ip)) {
                return false;
            }
        }
        $resolvedIps = array_values(array_unique($ips));
        return true;
    }


    /** Zusätzliche Deny-Liste für Bereiche, die FILTER_FLAG_NO_PRIV_RANGE nicht erfasst. */
    private static function istGesperrt(string $ip): bool
    {
        $blocked = ['100.64.0.0/10', '198.18.0.0/15', '64:ff9b::/96', '::ffff:0:0/96'];
        foreach ($blocked as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $ipBin  = @inet_pton($ip);
            $netBin = @inet_pton($net);
            if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
                continue;
            }
            $bytes = intdiv((int)$bits, 8);
            $rest  = (int)$bits % 8;
            if ($bytes > 0 && strncmp($ipBin, $netBin, $bytes) !== 0) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = chr((0xFF << (8 - $rest)) & 0xFF);
            if ((($ipBin[$bytes] ?? "\0") & $mask) === (($netBin[$bytes] ?? "\0") & $mask)) {
                return true;
            }
        }
        return false;
    }


    private static function umleitung(string $base, string $location): string
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


    private static function hol(string $url, array $pinnedIps = [], int $frist = self::GET_TIMEOUT): array
    {
        $body     = '';
        $location = '';
        $max      = 2 * 1024 * 1024;
        $ch = curl_init($url);
        if ($pinnedIps !== []) {
            $parts = parse_url($url);
            $host  = (string)($parts['host'] ?? '');
            $port  = (int)($parts['port'] ?? (strtolower((string)($parts['scheme'] ?? '')) === 'https' ? 443 : 80));
            if ($host !== '') {
                curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . implode(',', $pinnedIps)]);
            }
        }
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT        => max(self::HOP_MIN, $frist),
            /* Der Verbindungsaufbau steckt IM Gesamtwert, darf ihn aber nicht
               allein aufbrauchen — sonst bliebe fuer die Antwort nichts. */
            CURLOPT_CONNECTTIMEOUT => (int)min(5, max(1, intdiv(max(self::HOP_MIN, $frist), 2))),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SymDoGateway/1.0)',
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
    public static function text(string $html): string
    {
        $prefix      = '';
        $ingredients = self::zutatenAusJsonLd($html);
        if ($ingredients !== []) {
            $prefix = "Zutaten (aus strukturierten Daten der Seite):\n- " . implode("\n- ", $ingredients) . "\n\n";
        }
        $combined = $prefix . self::htmlZuText($html);
        if (strlen($combined) > self::TEXT_MAX) {
            $combined = substr($combined, 0, self::TEXT_MAX);
        }
        return trim($combined);
    }


    /** schema.org/Recipe „recipeIngredient" aus JSON-LD-Blöcken (rekursiv). */
    private static function zutatenAusJsonLd(string $html): array
    {
        if (!preg_match_all('#<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $block) {
            $data = json_decode(trim($block), true);
            if (is_array($data)) {
                self::zutatenSammeln($data, $out);
            }
        }
        $clean = [];
        foreach ($out as $line) {
            $line = trim((string)preg_replace('/\s+/', ' ', (string)$line));
            if ($line !== '') {
                $clean[$line] = true;
            }
        }
        return array_slice(array_keys($clean), 0, self::MAX_INGREDIENTS);
    }


    private static function zutatenSammeln(array $node, array &$out): void
    {
        foreach ($node as $key => $val) {
            if ($key === 'recipeIngredient' && is_array($val)) {
                foreach ($val as $ing) {
                    if (is_string($ing)) {
                        $out[] = $ing;
                    }
                }
            } elseif (is_array($val)) {
                self::zutatenSammeln($val, $out);
            }
        }
    }


    public static function htmlZuText(string $html): string
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
}
