<?php

declare(strict_types=1);

/**
 * Welche Push-Endpunkte das Gateway annimmt — und welche nicht.
 *
 * Geprueft wurde bis zum 15.09.2026 nur das Praefix `https://`. Ein gekoppeltes
 * Geraet konnte damit jede Adresse angeben, die der Symcon-Rechner erreicht, und
 * das Gateway schickte dorthin einen POST; ueber `/push/test` kamen Status und
 * Transportfehler zurueck. Gemeldet von einem externen Codereview am 14.09.2026
 * (F8).
 *
 * Aufgeloest wird hier NICHT im Netz: die Namensaufloesung ist ausgetauscht, der
 * Prueflauf laeuft also ohne Internet und ohne fremde DNS-Antwort. Was er prueft,
 * ist die Entscheidung — Schema, Port, Literal, und was mit den aufgeloesten
 * Adressen geschieht.
 *
 *   php SymDoGateway/tests/PushZielTest.php
 */

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
    printf("%-4s %-60s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/* Die Namensaufloesung abfangen, BEVOR AiRecipePage geladen wird: beide
   Funktionen werden dort unqualifiziert gerufen, also gewinnt eine Definition im
   Namensraum der Datei. AiRecipePage und PushZiel stehen im globalen Raum —
   deshalb wird hier ein eigener Raum aufgemacht und der Quelltext beider Dateien
   hineingezogen. */
$namen = [
    'web.push.apple.com'                => ['17.188.1.1'],
    'fcm.googleapis.com'                => ['142.250.185.106'],
    'updates.push.services.mozilla.com' => ['34.107.221.82'],
    'push.example.test'                 => ['203.0.113.7', '198.51.100.9'],
    'innen.example.test'                => ['192.168.1.50'],
    'gemischt.example.test'             => ['203.0.113.7', '10.0.0.5'],   // eine reicht
    'cgnat.example.test'                => ['100.64.0.1'],
    'nat64.example.test'                => [],                            // nur IPv6, siehe unten
    'weg.example.test'                  => [],                            // kennt niemand
];
$v6 = [
    'nat64.example.test' => ['64:ff9b::7f00:1'],   // NAT64 auf 127.0.0.1
];

require __DIR__ . '/../../libs/AiRecipePage.php';
require __DIR__ . '/../../libs/PushZiel.php';

/* Die echten Aufloeser durch die Tabelle ersetzen: runkit gibt es nicht, also
   wird der Quelltext beider Klassen in einen eigenen Namensraum kopiert, in dem
   `gethostbynamel` und `dns_get_record` unsere sind. So laeuft der ORIGINALE
   Entscheidungsbaum — nur die Auskunft kommt von hier. */
$quelle = '';
foreach ([__DIR__ . '/../../libs/AiRecipePage.php', __DIR__ . '/../../libs/PushZiel.php'] as $datei) {
    $t = (string)file_get_contents($datei);
    $t = preg_replace('/^<\?php\s*/', '', $t);
    $t = str_replace('declare(strict_types=1);', '', $t);
    $t = preg_replace('/^\s*require_once[^;]+;\s*$/m', '', $t);
    $quelle .= $t . "\n";
}
eval('namespace Probe; ' . $quelle);

/* Die Attrappen im Namensraum Probe — sie gewinnen dort gegen die globalen. */
eval('namespace Probe;
function gethostbynamel(string $h) { global $namen; return $namen[$h] ?? false; }
function dns_get_record(string $h, int $typ = DNS_ANY) {
    global $v6;
    $raus = [];
    foreach ($v6[$h] ?? [] as $a) { $raus[] = ["ipv6" => $a]; }
    return $raus;
}');

$erlaubt = static fn(string $u): array => \Probe\PushZiel::pruefen($u);

// ── Echte Dienste gehen durch ────────────────────────────────────────────
foreach (['https://web.push.apple.com/QF7', 'https://fcm.googleapis.com/fcm/send/abc',
          'https://updates.push.services.mozilla.com/wpush/v2/gAA'] as $u) {
    $e = $erlaubt($u);
    pruefe('Dienst erlaubt: ' . parse_url($u, PHP_URL_HOST), $e['ok'], true);
}
pruefe('Der Standardport darf dastehen', $erlaubt('https://push.example.test:443/x')['ok'], true);
pruefe('Alle aufgeloesten Adressen kommen zurueck',
    $erlaubt('https://push.example.test/x')['ips'], ['203.0.113.7', '198.51.100.9']);

// ── Der gemeldete Fall ───────────────────────────────────────────────────
pruefe('Loopback mit eigenem Port abgelehnt',
    $erlaubt('https://127.0.0.1:8443/private'), ['ok' => false, 'grund' => 'form', 'ips' => []]);

// ── Form: dauerhaft unbrauchbar ─────────────────────────────────────────
$form = [
    'http statt https'          => 'http://push.example.test/x',
    'Adressliteral IPv4'        => 'https://203.0.113.7/x',
    'Adressliteral IPv6'        => 'https://[2606:4700::1111]/x',
    'Loopback als Name'         => 'https://127.0.0.1/x',
    'Ungewoehnlicher Port'      => 'https://push.example.test:8443/x',
    'Port 80'                   => 'https://push.example.test:80/x',
    'Zugangsdaten in der URL'   => 'https://nutzer:geheim@push.example.test/x',
    'Kein Schema'               => 'push.example.test/x',
    'Leer'                      => '',
    'file'                      => 'file:///etc/passwd',
    'gopher'                    => 'gopher://push.example.test/x',
];
foreach ($form as $name => $u) {
    pruefe('Abgelehnt (' . $name . ')', $erlaubt($u)['grund'], 'form');
}
pruefe('Ueberlaenge abgelehnt',
    $erlaubt('https://push.example.test/' . str_repeat('a', 2100))['grund'], 'form');

// ── Netz: zeigt nach innen ──────────────────────────────────────────────
pruefe('Privates Netz abgelehnt',    $erlaubt('https://innen.example.test/x')['grund'], 'privat');
pruefe('Eine private von zweien reicht',
    $erlaubt('https://gemischt.example.test/x')['grund'], 'privat');
pruefe('CGNAT abgelehnt',            $erlaubt('https://cgnat.example.test/x')['grund'], 'privat');
pruefe('NAT64 auf Loopback abgelehnt', $erlaubt('https://nat64.example.test/x')['grund'], 'privat');

// ── Netz: gar nicht aufloesbar ──────────────────────────────────────────
/* Der Unterschied traegt eine Entscheidung: nur bei 'form' darf ein Abo
   weggeworfen werden. Eine DNS-Stoerung kostet sonst gueltige Abos. */
pruefe('Unaufloesbar ist NICHT "form"', $erlaubt('https://weg.example.test/x')['grund'], 'dns');

// ── Die beiden Einbaustellen ────────────────────────────────────────────
$router = (string)file_get_contents(__DIR__ . '/../libs/ApiRouter.php');
$push   = (string)file_get_contents(__DIR__ . '/../libs/WebPush.php');
$geraet = (string)file_get_contents(__DIR__ . '/../libs/DeviceRegistry.php');
pruefe('Beim Anmelden geprueft',  str_contains($router, 'PushZiel::pruefen($endpunkt)'), true);
pruefe('Vor jedem Versand geprueft', str_contains($push, '$ziel = PushZiel::pruefen($endpunkt);'), true);
pruefe('Auch der Schreibweg in den Bestand',
    str_contains($geraet, 'PushZiel::erlaubt($endpoint)'), true);
pruefe('Kein "https://"-Praefix mehr als einzige Pruefung',
    preg_match('/str_starts_with\(\$endp(unkt|oint), .https:\/\/.\)/', $router . $push . $geraet), 0);
/* Ohne das Festnageln loest curl den Namen noch einmal auf — zwischen Pruefung
   und Verbindung koennte dann eine andere Antwort stehen. */
pruefe('Die geprueften Adressen werden festgenagelt',
    str_contains($push, 'CURLOPT_RESOLVE') && str_contains($push, "PushHttp(\$endpunkt, (string)\$ver['body'], \$ziel['ips']"), true);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
