<?php

declare(strict_types=1);

/**
 * Was der Knopf „Set up Mailgun" bei Mailgun anstellt — ohne Mailgun.
 *
 * `MailgunSetup` bekommt statt curl die Attrappe aus MailgunAttrappe.php. Die
 * haelt Domains und Routen je Region und antwortet in Mailguns Formaten
 * (laut OpenAPI-Beschreibung). Geprueft wird, WAS hinausginge und wie die
 * Antworten gedeutet werden:
 *
 *  - US/EU: die gemerkte Region zuerst, sonst US, dann EU.
 *  - Domainwahl: eine, mehrere, eingetragen, deaktiviert, Stoerung.
 *  - Route: neu (POST) oder vorhanden (PUT) — auch wenn sie auf Seite drei steht.
 *  - Ausdruck: Punkte maskiert, am Ende verankert.
 *  - Rumpf: `action` zweimal, nicht `action[0]`.
 *  - Signaturschluessel, Selbstpruefung, 401/403/429/5xx.
 *  - Der Schluessel steht in keiner Meldung, und nur zwei Hosts sind erlaubt.
 *
 *   php SymDoGateway/tests/MailgunSetupTest.php
 */

require_once __DIR__ . '/../libs/MailgunSetup.php';
require_once __DIR__ . '/MailgunAttrappe.php';

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, mixed $ist, mixed $soll): void
{
    global $fehler, $anzahl;
    $anzahl++;
    $a = json_encode($ist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $b = json_encode($soll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = $a === $b;
    if (!$ok) {
        $fehler++;
    }
    printf("%-4s %-66s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

const PRAEFIX = 'https://abc123.ipmagic.de/hook/lists/app/v1/mail/hook/';
const TOKEN   = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a6978';
const BESCHR  = 'SymDo Gateway 12345';
$hook = PRAEFIX . TOKEN;

/** @return array{0: MailgunSetup, 1: MailgunAttrappe} */
function neu(?MailgunAttrappe $mg = null, ?callable $uhr = null): array
{
    $mg ??= new MailgunAttrappe();
    return [new MailgunSetup($mg->schluessel, $mg, $uhr), $mg];
}
$regionen = static fn(MailgunAttrappe $mg, string $pfad): array
    => array_column($mg->gerufen('GET', $pfad), 'region');

// ══ 1. Region ═════════════════════════════════════════════════════════════
[$s, $mg] = neu();
$mg->domains['eu'][] = MailgunAttrappe::domain('mg.example.eu');
$w = $s->domainWaehlen('mg.example.eu', '');
pruefe('EU-Domain ohne Hinweis: gefunden, Region eu', [$w['ok'], $w['region'] ?? null, $w['domain'] ?? null], [true, 'eu', 'mg.example.eu']);
pruefe('… gefragt wurde erst US, dann EU', $regionen($mg, '/v4/domains'), ['us', 'eu']);

[$s, $mg] = neu();
$mg->domains['eu'][] = MailgunAttrappe::domain('mg.example.eu');
$w = $s->domainWaehlen('mg.example.eu', 'eu');
pruefe('Gemerkte Region eu: nur EU wird gefragt', [$w['region'] ?? null, $regionen($mg, '/v4/domains')], ['eu', ['eu']]);

[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('mg.example.com');
$w = $s->domainWaehlen('mg.example.com', '');
pruefe('US-Domain: nach dem ersten Treffer keine weitere Anfrage', [$w['region'] ?? null, $regionen($mg, '/v4/domains')], ['us', ['us']]);

[$s, $mg] = neu();
$mg->gueltig['us'] = false;
$mg->domains['eu'][] = MailgunAttrappe::domain('mg.example.eu');
$w = $s->domainWaehlen('mg.example.eu', '');
pruefe('US weist den Schluessel ab, EU kennt die Domain: eu', [$w['ok'], $w['region'] ?? null], [true, 'eu']);

[$s, $mg] = neu();
$mg->gueltig = ['us' => false, 'eu' => false];
$w = $s->domainWaehlen('', '');
pruefe('Schluessel ueberall abgewiesen: key_invalid (HTTP 401)', [$w['ok'], $w['code'], $w['status']], [false, 'key_invalid', 401]);

pruefe('reihenfolge: gemerkte zuerst, Unbekanntes ignoriert',
    [MailgunSetup::reihenfolge('eu'), MailgunSetup::reihenfolge(''), MailgunSetup::reihenfolge('xx')],
    [['eu', 'us'], ['us', 'eu'], ['us', 'eu']]);

// ══ 2. Domainwahl ═════════════════════════════════════════════════════════
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$w = $s->domainWaehlen('', '');
pruefe('Genau eine Domain, nichts eingetragen: sie wird genommen', [$w['ok'], $w['domain'] ?? null, $w['region'] ?? null],
    [true, 'sandbox12.mailgun.org', 'us']);
pruefe('… und dafuer wurden BEIDE Regionen gefragt', $regionen($mg, '/v4/domains'), ['us', 'eu']);

[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$mg->domains['eu'][] = MailgunAttrappe::domain('mg.example.eu');
$w = $s->domainWaehlen('', '');
pruefe('Mehrere Domains: Rueckfrage mit der Liste', [$w['ok'], $w['code'], $w['liste']],
    [false, 'domain_ambiguous', ['sandbox12.mailgun.org', 'mg.example.eu']]);

[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$w = $s->domainWaehlen('other.example.com', '');
pruefe('Eingetragen, aber nicht im Konto: domain_unknown mit Fundliste', [$w['code'], $w['domain'], $w['liste']],
    ['domain_unknown', 'other.example.com', ['sandbox12.mailgun.org']]);

[$s, $mg] = neu();
$mg->domains['us'] = [MailgunAttrappe::domain('a.example.com'), MailgunAttrappe::domain('b.example.com', 'active', true),
                      MailgunAttrappe::domain('c.example.com', 'disabled')];
$w = $s->domainWaehlen('', '');
pruefe('Deaktivierte zaehlen nicht (is_disabled und state=disabled)', [$w['ok'], $w['domain'] ?? null], [true, 'a.example.com']);
$w = $s->domainWaehlen('b.example.com', '');
pruefe('… auch nicht, wenn man sie eintraegt', $w['code'] ?? null, 'domain_unknown');

[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('mg.example.com');
$w = $s->domainWaehlen("  MG.Example.COM. \n", '');
pruefe('Eingetragene Domain: Gross/klein, Leerraum und Schlusspunkt egal', [$w['ok'], $w['domain'] ?? null], [true, 'mg.example.com']);

[$s, $mg] = neu();
$w = $s->domainWaehlen('exa mple', '');
pruefe('Keine Domain: domain_invalid, und keine einzige Anfrage', [$w['code'], count($mg->rufe)], ['domain_invalid', 0]);

[$s, $mg] = neu();
$w = $s->domainWaehlen('', '');
pruefe('Keine aktive Domain im Konto: no_domain', $w['code'] ?? null, 'no_domain');

[$s, $mg] = neu();
$mg->erzwingen['us GET /v4/domains'] = [503, '{"message":"upstream"}'];
$mg->domains['eu'][] = MailgunAttrappe::domain('mg.example.eu');
$w = $s->domainWaehlen('', '');
pruefe('US gestoert, EU hat eine: NICHT raten (server, 503)', [$w['ok'], $w['code'], $w['status']], [false, 'server', 503]);
[$s, $mg] = neu();
$mg->erzwingen['us GET /v4/domains'] = [503, '{"message":"upstream"}'];
$mg->domains['eu'][] = MailgunAttrappe::domain('mg.example.eu');
$w = $s->domainWaehlen('mg.example.eu', '');
pruefe('… eingetragen und in EU gefunden: das zaehlt', [$w['ok'], $w['region'] ?? null], [true, 'eu']);

[$s, $mg] = neu();
for ($i = 1; $i <= 150; $i++) {
    $mg->domains['us'][] = MailgunAttrappe::domain(sprintf('d%03d.example.com', $i));
}
$w = $s->domainWaehlen('d150.example.com', '');
pruefe('Domainliste ueber zwei Seiten: die 150. wird gefunden', [$w['ok'], $w['domain'] ?? null], [true, 'd150.example.com']);
pruefe('… mit skip 0 und 100', array_map(static fn(array $r): string => $r['abfrage']['skip'] . '/' . $r['abfrage']['limit'],
    $mg->gerufen('GET', '/v4/domains')), ['0/100', '100/100']);

[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('mg.example.com', 'unverified');
$w = $s->domainWaehlen('', '');
pruefe('Unbestaetigte Domain: genommen, Zustand reist mit', [$w['domain'] ?? null, $w['zustand'] ?? null], ['mg.example.com', 'unverified']);

// ══ 3. Route: neu oder vorhanden ══════════════════════════════════════════
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$e = $s->einrichten('', '', $hook, BESCHR, PRAEFIX);
$route = array_values($mg->routen['us'])[0] ?? [];
pruefe('Frisches Konto: ok, Route angelegt (POST)', [$e['ok'], $e['neu'], count($mg->gerufen('POST', '/v3/routes')), count($mg->routen['us'])],
    [true, true, 1, 1]);
pruefe('… mit Prioritaet 0, Beschreibung, Ausdruck und zwei Aktionen',
    [$route['priority'], $route['description'], $route['expression'], $route['actions']],
    [0, BESCHR, 'match_recipient(".*@sandbox12\.mailgun\.org$")', ['forward("' . $hook . '")', 'stop()']]);
pruefe('… Ergebnis traegt Region, Domain, Signaturschluessel, Route-ID',
    [$e['region'], $e['domain'], $e['signingKey'], $e['routeId'] === $route['id'], $e['pruefung']],
    ['us', 'sandbox12.mailgun.org', $mg->signatur, true, ['ok' => true]]);
pruefe('… Reihenfolge: Domains, Signaturschluessel, Routen, POST, Pruefung',
    array_map(static fn(array $r): string => $r['region'] . ' ' . $r['methode'] . ' ' . $r['pfad'], $mg->rufe),
    ['us GET /v4/domains', 'eu GET /v4/domains', 'us GET /v5/accounts/http_signing_key', 'us GET /v3/routes',
     'us POST /v3/routes', 'us GET /v3/routes/match']);

$s2 = new MailgunSetup($mg->schluessel, $mg);
$e2 = $s2->einrichten('sandbox12.mailgun.org', 'us', $hook, BESCHR, PRAEFIX);
pruefe('Zweiter Lauf: PUT auf dieselbe Route, keine zweite', [$e2['ok'], $e2['neu'], $e2['routeId'] === $e['routeId'],
    count($mg->routen['us']), count($mg->gerufen('PUT', '/v3/routes/'))], [true, false, true, 1, 1]);

// Der alte Handweg: catch_all, Weiterleitung auf dieses System mit altem Token.
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$alt = $mg->anlegen('us', '', 'catch_all()', ['forward("' . PRAEFIX . str_repeat('ab', 24) . '")', 'stop()']);
$e = $s->einrichten('', 'us', $hook, BESCHR, PRAEFIX);
pruefe('Alte Handroute (catch_all, alter Token) wird uebernommen: PUT',
    [$e['ok'], $e['neu'], $e['routeId'], count($mg->routen['us']), count($mg->gerufen('POST', '/v3/routes'))],
    [true, false, $alt, 1, 0]);
pruefe('… jetzt gezielt auf die Domain und auf den neuen Token',
    [$mg->routen['us'][$alt]['expression'], $mg->routen['us'][$alt]['actions'][0], $mg->routen['us'][$alt]['description']],
    ['match_recipient(".*@sandbox12\.mailgun\.org$")', 'forward("' . $hook . '")', BESCHR]);

// Paging: unsere Route steht an Stelle 231, hinter 230 fremden.
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
for ($i = 0; $i < 230; $i++) {
    $mg->anlegen('us', 'Fremd ' . $i, 'match_recipient(".*@shop' . $i . '\.example\.com$")', ['forward("https://shop.example.com/in")'], 5);
}
$unsere = $mg->anlegen('us', BESCHR, MailgunSetup::ausdruck('sandbox12.mailgun.org'), ['forward("https://alt.example.net/x")', 'stop()']);
$e = $s->einrichten('sandbox12.mailgun.org', 'us', $hook, BESCHR, PRAEFIX);
pruefe('Route auf Seite drei: PUT statt POST', [$e['ok'], $e['neu'], $e['routeId'], count($mg->gerufen('POST', '/v3/routes'))],
    [true, false, $unsere, 0]);
pruefe('… die Liste wurde ganz gelesen (skip 0, 100, 200)',
    array_values(array_map(static fn(array $r): string => (string)$r['abfrage']['skip'],
        array_filter($mg->gerufen('GET', '/v3/routes'), static fn(array $r): bool => $r['pfad'] === '/v3/routes'))),
    ['0', '100', '200']);
pruefe('… und die fremden Routen sind unberuehrt', count(array_filter($mg->routen['us'],
    static fn(array $r): bool => str_starts_with($r['description'], 'Fremd ') && $r['actions'] === ['forward("https://shop.example.com/in")'])), 230);

// Ein ZWEITES System im selben Konto: gleiche Beschreibung, andere Domain, anderer Host.
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$fremd = $mg->anlegen('us', BESCHR, MailgunSetup::ausdruck('mg.nachbar.de'),
    ['forward("https://zzz999.ipmagic.de/hook/lists/app/v1/mail/hook/' . str_repeat('cd', 24) . '")', 'stop()']);
$vorher = $mg->routen['us'][$fremd];
$e = $s->einrichten('sandbox12.mailgun.org', 'us', $hook, BESCHR, PRAEFIX);
pruefe('Route eines anderen Systems bleibt, unsere kommt dazu', [$e['neu'], count($mg->routen['us']), $mg->routen['us'][$fremd] === $vorher],
    [true, 2, true]);

[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$mg->anlegen('us', 'alt 1', 'catch_all()', ['forward("' . PRAEFIX . str_repeat('11', 24) . '")']);
$mg->anlegen('us', 'alt 2', 'catch_all()', ['forward("' . PRAEFIX . str_repeat('22', 24) . '")']);
$e = $s->einrichten('sandbox12.mailgun.org', 'us', $hook, BESCHR, PRAEFIX);
pruefe('Zwei Routen auf dieses System: eine nachgefuehrt, eine gemeldet', [$e['neu'], $e['weitere'], count($mg->routen['us'])], [false, 1, 2]);

[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$mg->erzwingen['us POST /v3/routes'] = [400, '{"message":"Invalid expression"}'];
$e = $s->einrichten('', 'us', $hook, BESCHR, PRAEFIX);
pruefe('Route abgelehnt: rejected mit Mailguns Text, Schritt route_save', [$e['ok'], $e['code'], $e['schritt'], $e['status'], $e['detail']],
    [false, 'rejected', 'route_save', 400, 'Invalid expression']);

// ══ 4. Ausdruck ═══════════════════════════════════════════════════════════
pruefe('Ausdruck: Punkte maskiert, am Ende verankert', MailgunSetup::ausdruck('sandbox12.mailgun.org'),
    'match_recipient(".*@sandbox12\.mailgun\.org$")');
pruefe('… Bindestrich bleibt', MailgunSetup::ausdruck('mg.my-domain.de'), 'match_recipient(".*@mg\.my-domain\.de$")');
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$s->einrichten('', 'us', $hook, BESCHR, PRAEFIX);
$passt = [];
foreach (['lena@sandbox12.mailgun.org', 'post+lena@sandbox12.mailgun.org', 'x@sandbox12xmailgun.org',
          'x@sandbox12.mailgun.org.evil.com', 'x@sub.sandbox12.mailgun.orgx'] as $adresse) {
    $mg->rufe = [];
    $antwort = $mg('GET', 'https://api.mailgun.net/v3/routes/match?address=' . rawurlencode($adresse),
        ['Authorization: Basic ' . base64_encode('api:' . $mg->schluessel)], '', 1000);
    $passt[$adresse] = $antwort['status'] === 200;
}
pruefe('… trifft die Domain und Plus-Adressen, sonst nichts', $passt, [
    'lena@sandbox12.mailgun.org' => true, 'post+lena@sandbox12.mailgun.org' => true,
    'x@sandbox12xmailgun.org' => false, 'x@sandbox12.mailgun.org.evil.com' => false, 'x@sub.sandbox12.mailgun.orgx' => false]);

// ══ 5. Wiederholte action-Felder ══════════════════════════════════════════
pruefe('formular(): wiederholte Felder bleiben wiederholt', MailgunSetup::formular([['action', 'a b'], ['action', 'stop()']]),
    'action=a%20b&action=stop%28%29');
pruefe('… zum Vergleich: http_build_query macht daraus action[0]/action[1]',
    str_contains(http_build_query(['action' => ['a', 'b']]), 'action%5B0%5D'), true);
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$s->einrichten('', 'us', $hook, BESCHR, PRAEFIX);
$post = $mg->gerufen('POST', '/v3/routes')[0]['rumpf'] ?? '';
pruefe('POST-Rumpf: zweimal action=, kein action%5B', [substr_count($post, 'action='), str_contains($post, 'action%5B')], [2, false]);
pruefe('… entschluesselt in der richtigen Reihenfolge', MailgunAttrappe::felder($post), [
    ['priority', '0'], ['description', BESCHR], ['expression', 'match_recipient(".*@sandbox12\.mailgun\.org$")'],
    ['action', 'forward("' . $hook . '")'], ['action', 'stop()']]);
pruefe('… Content-Type ist ein Formular', in_array('Content-Type: application/x-www-form-urlencoded',
    $mg->gerufen('POST', '/v3/routes')[0]['kopf'], true), true);

// ══ 6. Signaturschluessel ═════════════════════════════════════════════════
[$s, $mg] = neu();
pruefe('Signaturschluessel: Feld http_signing_key', $s->signaturSchluessel('us'), ['ok' => true, 'schluessel' => $mg->signatur]);
[$s, $mg] = neu();
$mg->signatur = null;
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$e = $s->einrichten('', 'us', $hook, BESCHR, PRAEFIX);
pruefe('Konto ohne Signaturschluessel (400): signing_key_missing', [$e['ok'], $e['code'], $e['status']], [false, 'signing_key_missing', 400]);
pruefe('… und vorher wurde NICHTS geschrieben', [count($mg->gerufen('POST')), count($mg->gerufen('PUT')), count($mg->routen['us'])], [0, 0, 0]);
[$s, $mg] = neu();
$mg->erzwingen['us GET /v5/accounts/http_signing_key'] = [200, '{"message":"ok"}'];
pruefe('200 ohne das Feld: signing_key_missing', $s->signaturSchluessel('us')['code'] ?? null, 'signing_key_missing');

// ══ 7. Selbstpruefung ═════════════════════════════════════════════════════
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$mg->anlegen('us', 'Alles ins Archiv', 'catch_all()', ['store()', 'stop()']);
$e = $s->einrichten('', 'us', $hook, BESCHR, PRAEFIX);
pruefe('Fremde catch_all-Route mit stop() davor: gemeldet, Route steht trotzdem',
    [$e['ok'], $e['neu'], $e['pruefung']['code'] ?? null, $e['pruefung']['detail'] ?? null], [true, true, 'match_other', 'Alles ins Archiv']);
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$mg->anlegen('us', 'Kopie ins Archiv', 'catch_all()', ['store()']);
$e = $s->einrichten('', 'us', $hook, BESCHR, PRAEFIX);
pruefe('… ohne stop(): bestanden, mit Hinweis', $e['pruefung'], ['ok' => true, 'vorher' => 'Kopie ins Archiv']);
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$mg->erzwingen['us GET /v3/routes/match'] = [404, '{"message":"Route not found"}'];
pruefe('Keine Route fuer die Probeadresse: match_none', $s->einrichten('', 'us', $hook, BESCHR, PRAEFIX)['pruefung']['code'] ?? null, 'match_none');
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$mg->erzwingen['us GET /v3/routes/match'] = [500, 'kaputt'];
$e = $s->einrichten('', 'us', $hook, BESCHR, PRAEFIX);
pruefe('Pruefung gestoert (500): Einrichten bleibt ok, Befund server', [$e['ok'], $e['pruefung']['code'] ?? null], [true, 'server']);
$probe = $mg->gerufen('GET', '/v3/routes/match')[0]['abfrage']['address'] ?? '';
pruefe('Probeadresse liegt auf der Domain', $probe, 'symdo-check@sandbox12.mailgun.org');

// ══ 8. Fehlerbilder ═══════════════════════════════════════════════════════
$fall = static function (int $status, string $body, string $error = ''): array {
    $s = new MailgunSetup('key-probe-0123456789abcdef0123',
        static fn(string $m, string $u, array $k, string $r, int $z): array => ['status' => $status, 'body' => $body, 'error' => $error]);
    return $s->domainWaehlen('mg.example.com', 'us');
};
$f = $fall(401, '{"message":"Invalid private key"}');
pruefe('401: key_invalid', [$f['code'], $f['status']], ['key_invalid', 401]);
$f = $fall(403, '{"message":"Forbidden"}');
pruefe('403 (Rolle ohne Rechte): ebenfalls key_invalid', [$f['code'], $f['status']], ['key_invalid', 403]);
$f = $fall(502, '<html>Bad Gateway</html>');
pruefe('502: server', [$f['code'], $f['status']], ['server', 502]);
$f = $fall(429, '{"message":"Too many requests"}');
pruefe('429: rate_limited', $f['code'], 'rate_limited');
$f = $fall(301, '');
pruefe('301: redirect — nicht gefolgt', [$f['code'], $f['status']], ['redirect', 301]);
$f = $fall(0, '', 'Connection timed out after 6001 milliseconds');
pruefe('Kein Status: unreachable mit curl-Grund', [$f['code'], $f['detail']], ['unreachable', 'Connection timed out after 6001 milliseconds']);
$f = $fall(200, '<html>Wartung</html>');
pruefe('200 ohne JSON: bad_response', $f['code'], 'bad_response');
$s = new MailgunSetup('key-probe-0123456789abcdef0123', static function (): array {
    throw new RuntimeException('Leitung weg');
});
pruefe('Sender wirft: unreachable statt Absturz', $s->domainWaehlen('mg.example.com', 'us')['code'] ?? null, 'unreachable');

// ══ 9. Der Schluessel steht in keiner Meldung ═════════════════════════════
$key = 'key-geheim-4f3bad2335335426750048c6';
$sig = 'key-sign-0123456789abcdef0123456789';
$roh = 'Fehler <b>fett</b> mit ' . $key . "\nund " . base64_encode('api:' . $key) . ' und ' . TOKEN . ' und ' . $sig
    . str_repeat(' lang', 80);
$mg = new MailgunAttrappe($key);
$mg->signatur = $sig;
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$mg->erzwingen['us POST /v3/routes'] = [400, (string)json_encode(['message' => $roh])];
$s = new MailgunSetup($key, $mg);
$e = $s->einrichten('', 'us', $hook, BESCHR, PRAEFIX);
$d = (string)$e['detail'];
pruefe('Mailguns Text: ohne Schluessel, Basic-Wert, Token, Signaturschluessel',
    [str_contains($d, $key), str_contains($d, base64_encode('api:' . $key)), str_contains($d, TOKEN), str_contains($d, $sig), str_contains($d, '***')],
    [false, false, false, false, true]);
pruefe('… ohne Markup und Zeilenumbruch, hoechstens 160 Zeichen',
    [str_contains($d, '<b>'), str_contains($d, "\n"), mb_strlen($d) <= MailgunSetup::TEXT_MAX, str_starts_with($d, 'Fehler fett mit ***')],
    [false, false, true, true]);
$s = new MailgunSetup($key, static fn(): array => ['status' => 0, 'body' => '', 'error' => 'could not resolve api:' . $key]);
pruefe('curl-Fehler mit Schluessel: getilgt', str_contains((string)$s->domainWaehlen('', 'us')['detail'], $key), false);
$mg = new MailgunAttrappe($key);
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$e = (new MailgunSetup($key, $mg))->einrichten('', 'us', $hook, BESCHR, PRAEFIX);
$json = (string)json_encode($e);
pruefe('Erfolgsergebnis: kein API-Schluessel, kein Token', [str_contains($json, $key), str_contains($json, TOKEN)], [false, false]);
$kopfe = array_merge(...array_column($mg->rufe, 'kopf'));
pruefe('Der Schluessel reist NUR im Authorization-Kopf', [
    count(array_filter($mg->rufe, static fn(array $r): bool => str_contains($r['url'] . $r['rumpf'], $key))),
    count(array_filter($kopfe, static fn(string $k): bool => $k === 'Authorization: Basic ' . base64_encode('api:' . $key))) === count($mg->rufe),
], [0, true]);

// ══ 10. Nur die zwei Hosts ════════════════════════════════════════════════
$erlaubt = [];
foreach (['https://api.mailgun.net/v3/routes', 'https://api.eu.mailgun.net/v4/domains?limit=100',
          'http://api.mailgun.net/v3/routes', 'https://api.mailgun.net.evil.com/v3/routes',
          'https://evil.com/?h=api.mailgun.net', 'https://user@api.mailgun.net/v3/routes',
          'https://api.mailgun.net:8443/v3/routes', 'https://API.Mailgun.net/v3/routes',
          'https://api.mailgun.org/v3/routes', "https://api.mailgun.net/v3/routes\r\nX: y"] as $u) {
    $erlaubt[] = MailgunSetup::urlErlaubt($u);
}
pruefe('urlErlaubt: https und genau die zwei Hosts', $erlaubt, [true, true, false, false, false, false, false, true, false, false]);
pruefe('MailgunHttp lehnt einen fremden Host ab, ohne zu verbinden',
    MailgunHttp::senden('GET', 'https://evil.example/v3/routes', [], '', 1000), ['status' => 0, 'body' => '', 'error' => 'host not allowed']);
pruefe('… und http:// ebenso', MailgunHttp::senden('GET', 'http://api.mailgun.net/v3/routes', [], '', 1000)['error'], 'host not allowed');
$gerufen = 0;
$s = new MailgunSetup('key-probe-0123456789abcdef0123', static function () use (&$gerufen): array {
    $gerufen++;
    return ['status' => 200, 'body' => '{}', 'error' => ''];
});
$r = (new ReflectionMethod(MailgunSetup::class, 'rufen'))->invoke($s, 'GET', 'xx', '/v3/routes', [], null, 'routes');
pruefe('Unbekannte Region: host_denied, der Sender wird gar nicht gerufen', [$r['code'], $gerufen], ['host_denied', 0]);
$http = (string)file_get_contents(__DIR__ . '/../libs/MailgunHttp.php');
pruefe('MailgunHttp: keine Weiterleitungen, Verbindung <= 6 s, nur https',
    [str_contains($http, 'CURLOPT_FOLLOWLOCATION    => false'), str_contains($http, 'CURLOPT_MAXREDIRS         => 0'),
     str_contains($http, 'min(MailgunSetup::VERBINDEN_S * 1000, $zeitMs)'), str_contains($http, "CURLOPT_PROTOCOLS_STR, 'https'"),
     str_contains($http, 'CURLOPT_VERBOSE')], [true, true, true, true, false]);

// ══ 11. Zeitbudget ════════════════════════════════════════════════════════
$jetzt = 1000.0;
$mg = new MailgunAttrappe();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$mg->beiRuf = static function () use (&$jetzt): void {
    $jetzt += 6.0;   // jede Anfrage dauert sechs Sekunden
};
$s = new MailgunSetup($mg->schluessel, $mg, static function () use (&$jetzt): float {
    return $jetzt;
});
$e = $s->einrichten('sandbox12.mailgun.org', 'us', $hook, BESCHR, PRAEFIX);
pruefe('Budget 15 s fuer den GANZEN Lauf: nach drei 6-s-Anfragen ist Schluss', [$e['ok'], $e['code'], count($mg->rufe)], [false, 'budget', 3]);
pruefe('… jede Anfrage bekommt nur die Restzeit', array_column($mg->rufe, 'zeitMs'), [15000, 9000, 3000]);

// ══ 12. Hook-Adresse und Zugangsformen ════════════════════════════════════
[$s, $mg] = neu();
pruefe('Hook ohne https: hook_invalid, keine Anfrage',
    [$s->einrichten('', '', 'http://abc123.ipmagic.de/hook/lists/app/v1/mail/hook/' . TOKEN, BESCHR,
        'http://abc123.ipmagic.de/hook/lists/app/v1/mail/hook/')['code'], count($mg->rufe)], ['hook_invalid', 0]);
pruefe('Token mit Anfuehrungszeichen: hook_invalid', $s->einrichten('', '', PRAEFIX . TOKEN . '")', BESCHR, PRAEFIX)['code'], 'hook_invalid');
pruefe('tokenGueltig', [MailgunSetup::tokenGueltig(TOKEN), MailgunSetup::tokenGueltig('kurz'), MailgunSetup::tokenGueltig(str_repeat('a', 23) . '/')],
    [true, false, false]);
pruefe('schluesselGueltig: kein Leerzeichen, kein Zeilenumbruch',
    [MailgunSetup::schluesselGueltig('4f3bad2335335426750048c6-1b2c3d4e-5f6a7b8c'), MailgunSetup::schluesselGueltig("key-abc\r\nX: y"),
     MailgunSetup::schluesselGueltig('key abc def ghi'), MailgunSetup::schluesselGueltig('kurz')], [true, false, false, false]);
[$s, $mg] = neu();
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$s->einrichten('', 'us', $hook, BESCHR, PRAEFIX);
pruefe('Jede Anfrage: Basic-Auth api:<key> und Accept JSON', count(array_filter($mg->rufe, static fn(array $r): bool
    => in_array('Authorization: Basic ' . base64_encode('api:' . $mg->schluessel), $r['kopf'], true)
       && in_array('Accept: application/json', $r['kopf'], true))), count($mg->rufe));
pruefe('unsere(): 3 = beides, 2 = Weiterleitung, 1 = Beschreibung + Domain, 0 = fremd', [
    MailgunSetup::unsere(['id' => 'a1', 'description' => BESCHR, 'expression' => MailgunSetup::ausdruck('x.de'),
        'actions' => ['forward("' . strtoupper(PRAEFIX) . 'alt")']], BESCHR, 'x.de', PRAEFIX),
    MailgunSetup::unsere(['id' => 'a2', 'actions' => ["forward('" . PRAEFIX . "alt')"]], BESCHR, 'x.de', PRAEFIX),
    MailgunSetup::unsere(['id' => 'a3', 'description' => BESCHR, 'expression' => MailgunSetup::ausdruck('x.de')], BESCHR, 'x.de', PRAEFIX),
    MailgunSetup::unsere(['id' => 'a4', 'description' => BESCHR, 'expression' => MailgunSetup::ausdruck('y.de')], BESCHR, 'x.de', PRAEFIX),
    MailgunSetup::unsere(['id' => '../x', 'actions' => ['forward("' . PRAEFIX . 'alt")']], BESCHR, 'x.de', PRAEFIX),
], [3, 2, 1, 0, 0]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
