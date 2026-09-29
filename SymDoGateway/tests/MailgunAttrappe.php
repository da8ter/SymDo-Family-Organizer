<?php

declare(strict_types=1);

/**
 * Eine Attrappe von Mailguns API — genug fuer den Einrichten-Knopf.
 *
 * Sie spricht nie mit dem Netz; `MailgunSetup` bekommt sie statt `MailgunHttp`.
 * Sie haelt je Region Domains und Routen und verhaelt sich wie Mailgun laut
 * OpenAPI-Beschreibung: Basic-Auth „api:<key>", skip/limit mit total_count,
 * Formularrumpf mit WIEDERHOLTEN `action`-Feldern, {message, route} bei
 * POST/PUT, {route} oder 404 bei match, {http_signing_key} oder 400.
 *
 * `match` wertet die Ausdruecke aus wie Mailgun: nach Prioritaet, bei gleicher
 * in der Reihenfolge des Anlegens; `match_recipient("…")` als Python-`re.match`
 * (vorn verankert), `catch_all()` passt immer.
 *
 * Genutzt von MailgunSetupTest.php und MailgunEinrichtungTest.php.
 */
final class MailgunAttrappe
{
    /** @var list<array<string, mixed>> jede Anfrage, wie sie ankam */
    public array $rufe = [];
    /** @var array<string, list<array<string, mixed>>> Region → Domains */
    public array $domains = ['us' => [], 'eu' => []];
    /** @var array<string, array<string, array<string, mixed>>> Region → id → Route */
    public array $routen = ['us' => [], 'eu' => []];
    /** @var array<string, bool> nimmt die Region den Schluessel an */
    public array $gueltig = ['us' => true, 'eu' => true];
    public ?string $signatur = 'attrappe-signierschluessel-nur-fuer-tests';
    /** @var array<string, array{0: int, 1: string}> "<region> <METHODE> <pfad>" → [Status, Rumpf] */
    public array $erzwingen = [];
    /** Wird bei jedem Aufruf gerufen (etwa um die Uhr weiterzudrehen). */
    public $beiRuf = null;
    private int $naechste = 1;

    public function __construct(public string $schluessel = 'key-probe-0123456789abcdef0123')
    {
    }

    /** @return array{status: int, body: string, error: string} */
    public function __invoke(string $methode, string $url, array $kopf, string $rumpf, int $zeitMs): array
    {
        $p = parse_url($url);
        $host = (string)($p['host'] ?? '');
        $region = $host === 'api.eu.mailgun.net' ? 'eu' : ($host === 'api.mailgun.net' ? 'us' : '?');
        $abfrage = [];
        parse_str((string)($p['query'] ?? ''), $abfrage);
        $pfad = (string)($p['path'] ?? '');
        $this->rufe[] = ['methode' => $methode, 'region' => $region, 'pfad' => $pfad, 'abfrage' => $abfrage,
                         'rumpf' => $rumpf, 'kopf' => $kopf, 'zeitMs' => $zeitMs, 'url' => $url];
        if (is_callable($this->beiRuf)) {
            ($this->beiRuf)();
        }
        $fall = $region . ' ' . $methode . ' ' . $pfad;
        if (isset($this->erzwingen[$fall])) {
            return ['status' => $this->erzwingen[$fall][0], 'body' => $this->erzwingen[$fall][1], 'error' => ''];
        }
        if ($region === '?') {
            return ['status' => 0, 'body' => '', 'error' => 'fremder Host'];
        }
        if (!in_array('Authorization: Basic ' . base64_encode('api:' . $this->schluessel), $kopf, true)
            || !$this->gueltig[$region]) {
            return $this->json(401, ['message' => 'Invalid private key']);
        }
        if ($methode === 'GET' && $pfad === '/v4/domains') {
            return $this->seite($this->domains[$region], $abfrage);
        }
        if ($methode === 'GET' && $pfad === '/v3/routes') {
            return $this->seite(array_values($this->routen[$region]), $abfrage);
        }
        if ($methode === 'POST' && $pfad === '/v3/routes') {
            $id = sprintf('%024x', 0x5d0000 + $this->naechste++);
            $this->routen[$region][$id] = self::route($id, self::felder($rumpf));
            return $this->json(200, ['message' => 'Route has been created', 'route' => $this->routen[$region][$id]]);
        }
        if ($methode === 'PUT' && preg_match('~^/v3/routes/([^/]+)$~', $pfad, $m) === 1) {
            $id = rawurldecode($m[1]);
            if (!isset($this->routen[$region][$id])) {
                return $this->json(404, ['message' => 'Route not found']);
            }
            $this->routen[$region][$id] = self::route($id, self::felder($rumpf), $this->routen[$region][$id]);
            return $this->json(200, ['message' => 'Route has been updated', 'route' => $this->routen[$region][$id]]);
        }
        if ($methode === 'GET' && $pfad === '/v3/routes/match') {
            $r = $this->treffer($region, (string)($abfrage['address'] ?? ''));
            return $r === null ? $this->json(404, ['message' => 'Route not found']) : $this->json(200, ['route' => $r]);
        }
        if ($methode === 'GET' && $pfad === '/v5/accounts/http_signing_key') {
            return $this->signatur === null
                ? $this->json(400, ['message' => 'could not get HSK for account'])
                : $this->json(200, ['http_signing_key' => $this->signatur]);
        }
        return $this->json(404, ['message' => 'no such endpoint']);
    }

    /** Eine Domain, wie /v4/domains sie liefert. */
    public static function domain(string $name, string $zustand = 'active', bool $aus = false): array
    {
        return ['created_at' => 'Sat, 06 Jan 2024 10:27:15 GMT', 'id' => substr(sha1($name), 0, 10),
                'is_disabled' => $aus, 'name' => $name, 'state' => $zustand,
                'type' => str_starts_with($name, 'sandbox') ? 'sandbox' : 'custom'];
    }

    /** Eine vorhandene Route von Hand anlegen (etwa die des alten Handwegs). */
    public function anlegen(string $region, string $beschreibung, string $ausdruck, array $aktionen, int $prio = 0): string
    {
        $id = sprintf('%024x', 0xa10000 + $this->naechste++);
        $this->routen[$region][$id] = ['id' => $id, 'priority' => $prio, 'description' => $beschreibung,
            'expression' => $ausdruck, 'actions' => $aktionen, 'created_at' => 'Wed, 15 Feb 2012 13:03:31 GMT'];
        return $id;
    }

    /** @return list<array<string, mixed>> Anfragen einer Art */
    public function gerufen(string $methode, string $pfadAnfang = ''): array
    {
        return array_values(array_filter($this->rufe, static fn(array $r): bool
            => $r['methode'] === $methode && str_starts_with($r['pfad'], $pfadAnfang)));
    }

    /** Formularrumpf zerlegen — Paare in Reihenfolge, Wiederholungen bleiben. */
    public static function felder(string $rumpf): array
    {
        $raus = [];
        foreach ($rumpf === '' ? [] : explode('&', $rumpf) as $teil) {
            [$n, $w] = array_pad(explode('=', $teil, 2), 2, '');
            $raus[] = [rawurldecode(str_replace('+', ' ', $n)), rawurldecode(str_replace('+', ' ', $w))];
        }
        return $raus;
    }

    /** @param list<array{0: string, 1: string}> $felder */
    private static function route(string $id, array $felder, array $alt = []): array
    {
        $r = $alt + ['id' => $id, 'priority' => 0, 'description' => '', 'expression' => '', 'actions' => [],
                     'created_at' => 'Tue, 29 Sep 2026 10:00:00 GMT'];
        $aktionen = [];
        foreach ($felder as [$n, $w]) {
            match ($n) {
                'priority'    => $r['priority'] = (int)$w,
                'description' => $r['description'] = $w,
                'expression'  => $r['expression'] = $w,
                'action'      => $aktionen[] = $w,
                default       => null,
            };
        }
        if ($aktionen !== []) {
            $r['actions'] = $aktionen;   // PUT ersetzt, was mitkommt
        }
        return $r;
    }

    private function treffer(string $region, string $adresse): ?array
    {
        // Nach Prioritaet; bei Gleichstand gewinnt, was zuerst angelegt wurde.
        $liste = [];
        foreach (array_values($this->routen[$region]) as $i => $r) {
            $liste[] = [$r, $i];
        }
        usort($liste, static fn(array $a, array $b): int
            => [(int)$a[0]['priority'], $a[1]] <=> [(int)$b[0]['priority'], $b[1]]);
        foreach ($liste as [$r]) {
            $x = (string)$r['expression'];
            if ($x === 'catch_all()') {
                return $r;
            }
            if (preg_match('/^match_recipient\(["\'](.*)["\']\)$/s', $x, $m) === 1
                && @preg_match('~^(?:' . str_replace('~', '\~', $m[1]) . ')~', $adresse) === 1) {
                return $r;
            }
        }
        return null;
    }

    private function seite(array $alle, array $abfrage): array
    {
        $limit = (int)($abfrage['limit'] ?? 100);
        $skip  = (int)($abfrage['skip'] ?? 0);
        if ($limit > 1000) {
            return $this->json(400, ['message' => "The 'limit' parameter can't be larger than 1000"]);
        }
        return $this->json(200, ['total_count' => count($alle), 'items' => array_slice(array_values($alle), $skip, $limit)]);
    }

    private function json(int $status, array $daten): array
    {
        return ['status' => $status, 'body' => (string)json_encode($daten, JSON_UNESCAPED_SLASHES), 'error' => ''];
    }
}
