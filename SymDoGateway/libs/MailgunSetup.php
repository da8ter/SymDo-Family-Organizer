<?php

declare(strict_types=1);

require_once __DIR__ . '/MailgunHttp.php';

/**
 * Mailgun einrichten — ohne Symcon.
 *
 * Kennt weder Instanz noch Formular: bekommt den Schluessel und eine
 * HTTP-Funktion, fragt, rechnet und meldet Felder (Code, Schritt, Einzelheiten).
 * Den Satz baut die Symcon-Seite (MailScan) — nur die kann uebersetzen.
 *
 *  - Nur https zu den zwei Hosts aus HOSTS. Die Adresse entsteht aus der
 *    Region, nie aus einer Antwort; `urlErlaubt()` prueft sie vor JEDEM Aufruf,
 *    MailgunHttp ein zweites Mal. Weiterleitungen werden nicht verfolgt.
 *  - Verbindung hoechstens 6 s, der GANZE Lauf hoechstens 15 s — ein Budget, nicht
 *    je Anfrage: die Gateway-Spur ist serialisiert, sieben mal 15 s waeren fast
 *    zwei Minuten ohne Antwort fuer jede App.
 *  - Der Schluessel steht nur im Authorization-Kopf; jede Meldung laeuft durch
 *    `bereinigen()` (ohne Schluessel, Hook-Token, Signaturschluessel; kurz).
 *
 * Formate laut Mailguns OpenAPI (29.09.2026): /v4/domains und /v3/routes →
 * {total_count, items}, skip/limit; POST/PUT /v3/routes → {message, route};
 * `action` ist ein WIEDERHOLTES Formularfeld; /v3/routes/match → {route} oder
 * 404; /v5/accounts/http_signing_key → {http_signing_key}, 400 ohne Schluessel.
 * Routen gelten je REGION, Schluessel in beiden — gesucht wird in beiden.
 */
final class MailgunSetup
{
    public const HOSTS = ['us' => 'api.mailgun.net', 'eu' => 'api.eu.mailgun.net'];
    public const VERBINDEN_S = 6;
    public const BUDGET_S    = 15.0;
    /** Mailguns Vorgabe; mehr als eine Seite hat ein Haushalt ohnehin nie. */
    public const SEITE       = 100;
    public const SEITEN_MAX  = 20;
    public const TEXT_MAX    = 160;
    public const ANTWORT_MAX = 1048576;
    /** Lokaler Teil der Adresse fuer die Selbstpruefung (es wird nichts gesendet). */
    public const PROBE       = 'symdo-check';

    /** @var callable(string, string, list<string>, string, int): array{status?: int, body?: string, error?: string} */
    private $senden;
    /** @var callable(): float */
    private $uhr;
    private string $kopf;
    private float $frist;
    /** @var list<string> */
    private array $geheim = [];

    public function __construct(#[\SensitiveParameter] string $schluessel, ?callable $senden = null,
        ?callable $uhr = null, float $budget = self::BUDGET_S)
    {
        $schluessel   = trim($schluessel);
        $this->senden = $senden ?? [MailgunHttp::class, 'senden'];
        $this->uhr    = $uhr ?? static fn(): float => microtime(true);
        $this->frist  = ($this->uhr)() + $budget;
        $this->kopf   = 'Authorization: Basic ' . base64_encode('api:' . $schluessel);
        $this->geheimnis($schluessel);
        $this->geheimnis(base64_encode('api:' . $schluessel));
    }

    /** Noch ein Wert, der in keiner Meldung stehen darf. */
    public function geheimnis(#[\SensitiveParameter] string $wert): void
    {
        if (strlen($wert) >= 6 && !in_array($wert, $this->geheim, true)) {
            $this->geheim[] = $wert;
        }
    }

    /**
     * Der ganze Knopf. Erst alles Lesende, dann die EINE schreibende Anfrage
     * (die Route), zuletzt die Pruefung: scheitert etwas vorher, ist bei Mailgun
     * nichts veraendert. Scheitert nur die Pruefung, bleibt `ok` wahr.
     *
     * @return array<string, mixed>
     */
    public function einrichten(string $domain, string $region, string $hookUrl,
        string $beschreibung, string $hookPraefix): array
    {
        if (!self::hookGueltig($hookUrl, $hookPraefix)) {
            return $this->fehler('hook_invalid', 'hook');
        }
        $this->geheimnis(substr($hookUrl, strlen($hookPraefix)));
        $wahl = $this->domainWaehlen($domain, $region);
        if (!$wahl['ok']) {
            return $wahl;
        }
        $sig = $this->signaturSchluessel($wahl['region']);
        if (!$sig['ok']) {
            return $sig;
        }
        $route = $this->routeSetzen($wahl['region'], $wahl['domain'], $hookUrl, $beschreibung, $hookPraefix);
        if (!$route['ok']) {
            return $route;
        }
        return [
            'ok'         => true,
            'region'     => $wahl['region'],
            'domain'     => $wahl['domain'],
            'zustand'    => $wahl['zustand'],
            'signingKey' => $sig['schluessel'],
            'routeId'    => $route['id'],
            'neu'        => $route['neu'],
            'weitere'    => $route['weitere'],
            'pruefung'   => $this->pruefen($wahl['region'], $wahl['domain'], $route['id']),
        ];
    }

    /**
     * Welche Domain, in welcher Region. Eingetragen → sie muss im Konto sein
     * (gemerkte Region zuerst). Leer → genau eine aktive wird genommen, mehrere
     * sind eine Rueckfrage. Antwortet eine Region nicht, wird nicht geraten.
     *
     * @return array<string, mixed>
     */
    public function domainWaehlen(string $gewuenscht, string $hinweis): array
    {
        $gewuenscht = self::domainNormal($gewuenscht);
        if ($gewuenscht !== '' && !self::domainGueltig($gewuenscht)) {
            return $this->fehler('domain_invalid', 'domains', ['domain' => $this->bereinigen($gewuenscht)]);
        }
        $kandidaten = [];
        $stoerung   = null;
        $abgewiesen = null;
        foreach (self::reihenfolge($hinweis) as $region) {
            $liste = $this->domainsLesen($region);
            if (!$liste['ok']) {
                if ($liste['code'] === 'budget') {
                    return $liste;
                }
                if ($liste['code'] === 'key_invalid') {
                    $abgewiesen ??= $liste;
                } else {
                    $stoerung ??= $liste;
                }
                continue;
            }
            foreach ($liste['domains'] as $d) {
                if ($gewuenscht !== '' && $d['name'] === $gewuenscht) {
                    return ['ok' => true, 'region' => $region, 'domain' => $d['name'], 'zustand' => $d['zustand']];
                }
                $kandidaten[] = $d + ['region' => $region];
            }
        }
        if ($stoerung !== null) {
            return $stoerung;
        }
        $namen = array_values(array_unique(array_column($kandidaten, 'name')));
        if ($gewuenscht !== '') {
            // Eine Region, die den Schluessel abwies, kann die Domain haben.
            return $abgewiesen ?? $this->fehler('domain_unknown', 'domains', ['domain' => $gewuenscht, 'liste' => $namen]);
        }
        if (count($kandidaten) === 1) {
            $d = $kandidaten[0];
            return ['ok' => true, 'region' => $d['region'], 'domain' => $d['name'], 'zustand' => $d['zustand']];
        }
        if ($kandidaten === []) {
            return $abgewiesen ?? $this->fehler('no_domain', 'domains');
        }
        return $this->fehler('domain_ambiguous', 'domains', ['liste' => $namen]);
    }

    /**
     * Die eigene Route anlegen oder nachfuehren — nie eine zweite. Gesucht wird
     * ueber ALLE Seiten: steht sie auf Seite drei, waere ein POST die Dublette.
     *
     * @return array<string, mixed>
     */
    public function routeSetzen(string $region, string $domain, string $hookUrl,
        string $beschreibung, string $hookPraefix): array
    {
        $liste = $this->routenLesen($region);
        if (!$liste['ok']) {
            return $liste;
        }
        $beste = null;
        $besteWert = 0;
        $zuUns = 0;
        foreach ($liste['routen'] as $r) {
            $wert = self::unsere($r, $beschreibung, $domain, $hookPraefix);
            if (($wert & 2) !== 0) {
                $zuUns++;
            }
            if ($wert > $besteWert) {
                [$beste, $besteWert] = [$r, $wert];
            }
        }
        $felder = self::routeFelder($domain, $hookUrl, $beschreibung);
        if ($beste !== null) {
            $id = (string)$beste['id'];
            $r  = $this->rufen('PUT', $region, '/v3/routes/' . rawurlencode($id), [], $felder, 'route_save');
            if (!$r['ok']) {
                return $r;
            }
        } else {
            $r = $this->rufen('POST', $region, '/v3/routes', [], $felder, 'route_save');
            if (!$r['ok']) {
                return $r;
            }
            $id = (string)($r['daten']['route']['id'] ?? $r['daten']['id'] ?? '');
            if (!self::idGueltig($id)) {
                return $this->fehler('bad_response', 'route_save', ['detail' => 'route id missing']);
            }
        }
        return ['ok' => true, 'id' => $id, 'neu' => $beste === null,
                'weitere' => max(0, $zuUns - (($besteWert & 2) !== 0 ? 1 : 0))];
    }

    /** @return array<string, mixed> */
    public function signaturSchluessel(string $region): array
    {
        $r = $this->rufen('GET', $region, '/v5/accounts/http_signing_key', [], null, 'signing_key');
        if (!$r['ok']) {
            // 400 „could not get HSK for account": das Konto hat noch keinen.
            return in_array($r['status'], [400, 404], true)
                ? $this->fehler('signing_key_missing', 'signing_key', ['status' => $r['status'], 'detail' => $r['detail']])
                : $r;
        }
        $k = $r['daten']['http_signing_key'] ?? null;
        $k = is_string($k) ? trim($k) : '';
        if (preg_match('/^[\x21-\x7E]{16,200}$/', $k) !== 1) {
            return $this->fehler('signing_key_missing', 'signing_key', ['detail' => 'field http_signing_key missing']);
        }
        $this->geheimnis($k);
        return ['ok' => true, 'schluessel' => $k];
    }

    /**
     * Bekommt eine Probeadresse UNSERE Route? Eine fremde davor stoert nur mit
     * stop() — sonst laeuft unsere danach trotzdem.
     *
     * @return array<string, mixed>
     */
    public function pruefen(string $region, string $domain, string $routeId): array
    {
        $r = $this->rufen('GET', $region, '/v3/routes/match', ['address' => self::PROBE . '@' . $domain], null, 'match');
        if (!$r['ok']) {
            return $r['status'] === 404 ? $this->fehler('match_none', 'match') : $r;
        }
        $route = $r['daten']['route'] ?? null;
        if (!is_array($route)) {
            return $this->fehler('bad_response', 'match', ['detail' => 'route missing']);
        }
        if ((string)($route['id'] ?? '') === $routeId) {
            return ['ok' => true];
        }
        $wer = trim((string)($route['description'] ?? ''));
        $wer = $this->bereinigen($wer !== '' ? $wer : (string)($route['expression'] ?? ''));
        foreach ((array)($route['actions'] ?? []) as $a) {
            if (is_string($a) && preg_match('/^\s*stop\(\s*\)\s*$/', $a) === 1) {
                return $this->fehler('match_other', 'match', ['detail' => $wer]);
            }
        }
        return ['ok' => true, 'vorher' => $wer];
    }

    // ───────────────────────────── Rechnen ─────────────────────────────

    /** Gezielt auf DIE Domain, nicht catch_all: das Konto kann weitere haben. */
    public static function ausdruck(string $domain): string
    {
        return 'match_recipient(".*@' . str_replace('.', '\\.', $domain) . '$")';
    }

    /** @return list<array{0: string, 1: string}> */
    public static function routeFelder(string $domain, string $hookUrl, string $beschreibung): array
    {
        return [
            ['priority', '0'],
            ['description', $beschreibung],
            ['expression', self::ausdruck($domain)],
            ['action', 'forward("' . $hookUrl . '")'],
            ['action', 'stop()'],
        ];
    }

    /**
     * Formularrumpf mit WIEDERHOLTEN Feldern — http_build_query macht aus zwei
     * `action` eines oder `action[0]`, `action[1]`.
     *
     * @param list<array{0: string, 1: string}> $felder
     */
    public static function formular(array $felder): string
    {
        $teile = [];
        foreach ($felder as [$name, $wert]) {
            $teile[] = rawurlencode((string)$name) . '=' . rawurlencode((string)$wert);
        }
        return implode('&', $teile);
    }

    /**
     * 2 = leitet auf DIESES System weiter (Connect + Hook-Pfad, Token egal),
     * 1 = unsere Beschreibung fuer diese Domain, 3 = beides, 0 = fremd.
     *
     * @param array<string, mixed> $route
     */
    public static function unsere(array $route, string $beschreibung, string $domain, string $hookPraefix): int
    {
        if (!self::idGueltig((string)($route['id'] ?? ''))) {
            return 0;
        }
        $wert = 0;
        $praefix = strtolower($hookPraefix);
        foreach ((array)($route['actions'] ?? []) as $a) {
            if ($praefix !== '' && is_string($a)
                && preg_match('/^\s*forward\(\s*["\']([^"\']+)["\']\s*\)\s*$/i', $a, $m) === 1
                && str_starts_with(strtolower(trim($m[1])), $praefix)) {
                $wert |= 2;
                break;
            }
        }
        if ($beschreibung !== '' && trim((string)($route['description'] ?? '')) === $beschreibung
            && str_contains((string)($route['expression'] ?? ''), '@' . str_replace('.', '\\.', $domain))) {
            $wert |= 1;
        }
        return $wert;
    }

    /** @return list<string> */
    public static function reihenfolge(string $hinweis): array
    {
        $alle = array_keys(self::HOSTS);
        return in_array($hinweis, $alle, true) ? array_values(array_unique(array_merge([$hinweis], $alle))) : $alle;
    }

    public static function domainNormal(string $d): string
    {
        return strtolower(trim($d, " \t\r\n<>."));
    }

    public static function domainGueltig(string $d): bool
    {
        return preg_match('/^(?=.{3,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $d) === 1;
    }

    /** Druckbares ASCII ohne Leerzeichen — nichts, was einen Kopf aufbrechen kann. */
    public static function schluesselGueltig(#[\SensitiveParameter] string $k): bool
    {
        return preg_match('/^[\x21-\x7E]{10,200}$/', $k) === 1;
    }

    /** Der Token steht im Pfad der Hook-Adresse. */
    public static function tokenGueltig(#[\SensitiveParameter] string $t): bool
    {
        return preg_match('/^[A-Za-z0-9]{24,128}$/', $t) === 1;
    }

    public static function idGueltig(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1;
    }

    /** Steht woertlich in `forward("…")`: https, Praefix, dahinter nur ein Token. */
    public static function hookGueltig(#[\SensitiveParameter] string $url, string $praefix): bool
    {
        return preg_match('~^https://[a-z0-9.-]+(:[0-9]{1,5})?/[A-Za-z0-9._/-]*/$~i', $praefix) === 1
            && str_starts_with($url, $praefix)
            && self::tokenGueltig(substr($url, strlen($praefix)));
    }

    public static function urlErlaubt(string $url): bool
    {
        $p = parse_url($url);
        return is_array($p)
            && ($p['scheme'] ?? '') === 'https'
            && in_array(strtolower((string)($p['host'] ?? '')), self::HOSTS, true)
            // Einzeln: isset() mit mehreren Argumenten ist nur wahr, wenn ALLE da sind.
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['port']) && !isset($p['fragment'])
            && preg_match('/[\s\x00-\x1F\x7F\\\\]/', $url) !== 1;
    }

    /** Fremdtext fuer Status und Debug: ohne Geheimnisse, Markup, Steuerzeichen; kurz. */
    public function bereinigen(string $text): string
    {
        foreach ($this->geheim as $g) {
            $text = str_replace($g, '***', $text);
        }
        $text = strip_tags(mb_scrub($text, 'UTF-8'));
        $text = trim((string)preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $text));
        return mb_strlen($text) > self::TEXT_MAX ? rtrim(mb_substr($text, 0, self::TEXT_MAX - 1)) . '…' : $text;
    }

    // ───────────────────────────── Abrufen ─────────────────────────────

    /** @return array<string, mixed> */
    private function domainsLesen(string $region): array
    {
        $r = $this->seiten($region, '/v4/domains', 'domains');
        if (!$r['ok']) {
            return $r;
        }
        $raus = [];
        foreach ($r['items'] as $d) {
            $name    = is_array($d) ? self::domainNormal((string)($d['name'] ?? '')) : '';
            $zustand = is_array($d) ? strtolower(trim((string)($d['state'] ?? ''))) : '';
            if ($name === '' || $zustand === 'disabled'
                || filter_var($d['is_disabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $raus[] = ['name' => $name, 'zustand' => $zustand];
        }
        return ['ok' => true, 'domains' => $raus];
    }

    /** @return array<string, mixed> */
    private function routenLesen(string $region): array
    {
        $r = $this->seiten($region, '/v3/routes', 'routes');
        return $r['ok'] ? ['ok' => true, 'routen' => array_values(array_filter($r['items'], 'is_array'))] : $r;
    }

    /** Alle Seiten; Schluss bei kurzer Seite oder `total_count`. @return array<string, mixed> */
    private function seiten(string $region, string $pfad, string $schritt): array
    {
        $items = [];
        for ($seite = 0; $seite < self::SEITEN_MAX; $seite++) {
            $r = $this->rufen('GET', $region, $pfad, ['limit' => self::SEITE, 'skip' => count($items)], null, $schritt);
            if (!$r['ok']) {
                return $r;
            }
            $stueck = $r['daten']['items'] ?? null;
            // Eine Region ohne Eintraege antwortet mit "items": null statt [] (gemessen
            // 29.09.2026: EU ohne Domains, {"items":null,"total_count":0}). Das ist leer,
            // keine Stoerung - sonst bricht die Domainsuche ab, obwohl US die Domain kennt.
            if ($stueck === null && is_array($r['daten']) && array_key_exists('items', $r['daten'])) {
                $stueck = [];
            }
            if (!is_array($stueck)) {
                return $this->fehler('bad_response', $schritt, ['detail' => 'items missing']);
            }
            array_push($items, ...array_values($stueck));
            $gesamt = $r['daten']['total_count'] ?? null;
            if (count($stueck) < self::SEITE || (is_numeric($gesamt) && count($items) >= (int)$gesamt)) {
                break;
            }
        }
        return ['ok' => true, 'items' => $items];
    }

    /**
     * EINE Anfrage; im Fehlerfall nur bereinigte Texte.
     *
     * @param array<string, string|int> $abfrage
     * @param list<array{0: string, 1: string}>|null $felder
     * @return array<string, mixed>
     */
    private function rufen(string $methode, string $region, string $pfad, array $abfrage,
        ?array $felder, string $schritt): array
    {
        $url = 'https://' . (self::HOSTS[$region] ?? '-') . $pfad
            . ($abfrage !== [] ? '?' . http_build_query($abfrage, '', '&', PHP_QUERY_RFC3986) : '');
        if (!isset(self::HOSTS[$region]) || !self::urlErlaubt($url)) {
            return $this->fehler('host_denied', $schritt);
        }
        $rest = $this->frist - ($this->uhr)();
        if ($rest < 0.5) {
            return $this->fehler('budget', $schritt);
        }
        $kopf  = [$this->kopf, 'Accept: application/json'];
        $rumpf = '';
        if ($felder !== null) {
            $kopf[] = 'Content-Type: application/x-www-form-urlencoded';
            $rumpf  = self::formular($felder);
        }
        try {
            $a = ($this->senden)($methode, $url, $kopf, $rumpf, (int)round(min(self::BUDGET_S, $rest) * 1000));
        } catch (\Throwable $e) {
            return $this->fehler('unreachable', $schritt, ['detail' => $this->bereinigen($e->getMessage())]);
        }
        $status = is_array($a) ? (int)($a['status'] ?? 0) : 0;
        $body   = is_array($a) ? (string)($a['body'] ?? '') : '';
        if ($status === 0) {
            $grund = is_array($a) ? (string)($a['error'] ?? '') : '';
            return $this->fehler('unreachable', $schritt, ['detail' => $this->bereinigen($grund !== '' ? $grund : 'no answer')]);
        }
        $daten = json_decode($body, true);
        if ($status >= 200 && $status < 300) {
            return is_array($daten)
                ? ['ok' => true, 'status' => $status, 'daten' => $daten]
                : $this->fehler('bad_response', $schritt, ['status' => $status, 'detail' => 'no JSON']);
        }
        $text = is_array($daten) && is_string($daten['message'] ?? null) ? $daten['message'] : $body;
        $code = match (true) {
            $status === 401, $status === 403 => 'key_invalid',
            $status === 429                  => 'rate_limited',
            $status >= 500                   => 'server',
            $status >= 300 && $status < 400  => 'redirect',
            default                          => 'rejected',
        };
        return $this->fehler($code, $schritt, ['status' => $status, 'detail' => $this->bereinigen($text)]);
    }

    /** @return array<string, mixed> */
    private function fehler(string $code, string $schritt, array $mehr = []): array
    {
        return ['ok' => false, 'code' => $code, 'schritt' => $schritt,
                'status' => (int)($mehr['status'] ?? 0), 'detail' => (string)($mehr['detail'] ?? ''),
                'domain' => (string)($mehr['domain'] ?? ''), 'liste' => (array)($mehr['liste'] ?? [])];
    }
}
