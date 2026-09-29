<?php

declare(strict_types=1);

/**
 * Bildkarte der Einkaufs-Kachel als versionierte Datei (26.09.2026).
 *
 * Die Produktbild-Karte (availableImages + availableBrands, rund 3 300 Namen,
 * ~105 kB) reiste in JEDEM Kachel-Push und in jedem Kacheldokument mit —
 * gemessen 105 von 175 kB je Push. Jetzt bekommt nur die eigene Kachel eine
 * Adresse mit Version (availableImagesUrl) und den Merker imagesEnabled; der
 * Asset-Hook liefert die Datei mit derselben Zugangsregel wie die Bilder
 * (Token), ein Jahr cachebar, mit ETag/304 und gzip. GetAppState — und damit
 * Gateway, iOS-App und Web-App im Browser — bleibt unveraendert.
 *
 * Gefahren wird gegen die echte Klasse (volles Create) mit den Symcon-Attrappen
 * und der echten Bildkarte aus SymDoShoppingList/assets. Den Teil der Kachel prueft
 * BildkarteTest.mjs in Node (wenn vorhanden), gegen das Skript der Web-App.
 *
 *   php SymDoShoppingList/tests/BildkarteTest.php
 */

/* Alles gepuffert: im CLI gelten die Kopfzeilen nach der ersten Ausgabe als
   gesendet, und der Hook setzt bei falschem Token einen Status (403) — der soll
   hier lesbar sein und keine Warnung werfen. Der Puffer geht am Ende hinaus,
   auch nach einem Abbruch. */
ob_start();

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../module.php';

IPS\Kernel::reset();

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
    printf("%-4s %-70s%s\n", $ok ? 'OK' : 'FEHL', $name, $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/* Warnungen, die NICHT unterdrueckt sind — im Hook zerlegte jede die Antwort. */
$warnungen = [];
set_error_handler(static function (int $nr, string $text) use (&$warnungen): bool {
    if (error_reporting() & $nr) {
        $warnungen[] = $text;
    }
    return true;
});

// ── KachelApp: die Helfer fuer versionierte Dateien (IPS-frei) ─────────────
pruefe('gzip nur, wenn angeboten; q=0 heisst nein', [
    KachelApp::GzipErlaubt('gzip, deflate, br'), KachelApp::GzipErlaubt('br;q=1.0, GZIP;q=0.8'),
    KachelApp::GzipErlaubt('gzip;q=0'), KachelApp::GzipErlaubt('deflate, gzip; q=0.0'),
    KachelApp::GzipErlaubt('identity'), KachelApp::GzipErlaubt(''), KachelApp::GzipErlaubt('gzip;q=0.5'),
], [true, true, false, false, false, false, true]);
pruefe('ETag: genau, schwach (W/), aus einer Liste, Stern — sonst nicht', [
    KachelApp::EtagTrifft('"a"', '"a"'), KachelApp::EtagTrifft('"a"', 'W/"a"'),
    KachelApp::EtagTrifft('"a"', '"b", W/"a"'), KachelApp::EtagTrifft('"a"', '*'),
    KachelApp::EtagTrifft('"a"', '"b"'), KachelApp::EtagTrifft('"a"', ''), KachelApp::EtagTrifft('"a"', '"a-gz"'),
], [true, true, true, true, false, false, false]);

$text = str_repeat('{"milch":"milch.png"},', 400);
$a = KachelApp::Datei($text, 'application/json; charset=utf-8', 'v1', true, true, 'gzip', '', 1000000);
pruefe('Datei: aktuelle Version gepackt — privat, ein Jahr, ETag mit -gz, Vary', [$a['status'], $a['kopf'],
    gzdecode($a['rumpf']) === $text], [200, [
    'Content-Type: application/json; charset=utf-8', 'Cache-Control: private, max-age=31536000, immutable',
    'Vary: Accept-Encoding', 'ETag: "v1-gz"', 'X-Content-Type-Options: nosniff', 'Content-Encoding: gzip'], true]);
$a = KachelApp::Datei($text, 'text/plain', 'v1', true, false, '', '', 1000000);
pruefe('Datei: ohne gzip ungepackt, ETag ohne -gz; oeffentlich auf Wunsch',
    [$a['rumpf'] === $text, in_array('ETag: "v1"', $a['kopf'], true),
     in_array('Cache-Control: public, max-age=31536000, immutable', $a['kopf'], true),
     (bool)preg_grep('/^Content-Encoding/', $a['kopf'])], [true, true, true, false]);
$a = KachelApp::Datei($text, 'text/plain', 'v1', false, true, 'gzip', '', 1000000);
pruefe('Datei: alte Version — Inhalt ja, Cache no-cache', [$a['status'],
    in_array('Cache-Control: no-cache', $a['kopf'], true)], [200, true]);
pruefe('Datei: passender ETag → 304 ohne Rumpf (auch schwach)', [
    KachelApp::Datei($text, 't', 'v1', true, true, 'gzip', '"v1-gz"', 1000000)['status'],
    KachelApp::Datei($text, 't', 'v1', true, true, 'gzip', 'W/"v1-gz"', 1000000)['rumpf'],
    KachelApp::Datei($text, 't', 'v1', true, true, '', '"v1"', 1000000)['status'],
    KachelApp::Datei($text, 't', 'v1', true, true, 'gzip', '"v1"', 1000000)['status']], [304, '', 304, 200]);
$a = KachelApp::Datei($text, 't', 'v1', true, true, '', '', 100);
pruefe('Datei: ueber der Ausgabegrenze 503 statt still ersetzter Antwort',
    [$a['status'], in_array('Cache-Control: no-store', $a['kopf'], true), strlen($a['rumpf']) < 100], [503, true, true]);
$a = KachelApp::Datei('x', 't', 'v1', true, true, 'gzip', '', 1000000);
pruefe('Datei: gepackt nur, wenn es kleiner wird', [$a['rumpf'], in_array('ETag: "v1"', $a['kopf'], true)], ['x', true]);
pruefe('Ausgabegrenze: Werkswert 1 MiB abzueglich Luft, wenn die Option fehlt', KachelApp::Ausgabegrenze(), 948576);

// ── Die echte Einkaufsliste ─────────────────────────────────────────────────

/** SymDoShoppingList mit vollem Create(); nur Hook-Anmeldung, Push und Hook-Ausgabe sind Attrappen. */
final class BildkartenProbe extends SymDoShoppingList
{
    public array $gesendet = [];
    public array $antworten = [];
    protected function RegisterHook(string $HookPath): bool { return true; }
    /* Nur die Eigenschaften uebernehmen (Basisklasse der Attrappen) — das echte
       ApplyChanges schreibt Zertifikate, bindet Ausloeser und pusht. */
    public function ApplyChanges(): void { IPSModuleStrict::ApplyChanges(); }
    protected function getTime(): int { return time(); }
    protected function UpdateVisualizationValue(mixed $Value)
    {
        $this->gesendet[] = (string)$Value;
        return true;
    }
    protected function HookAntworten(array $antwort): void { $this->antworten[] = $antwort; }
    /** Eine private Methode rufen; null, wenn es sie nicht gibt (Gegenprobe gegen einen aelteren Stand). */
    public function pRef(string $fn, mixed ...$a): mixed
    {
        if (!method_exists(SymDoShoppingList::class, $fn)) {
            return null;
        }
        return (new ReflectionMethod(SymDoShoppingList::class, $fn))->invoke($this, ...$a);
    }
    public function pAttr(string $name, string $wert): void { $this->WriteAttributeString($name, $wert); }
    public function pPuffer(string $name, ?string $wert = null): string
    {
        if ($wert !== null) {
            $this->SetBuffer($name, $wert);
        }
        return $this->GetBuffer($name);
    }
    /** Ein Aufruf des Hooks: Antworten und Ausgabe dieses Aufrufs. */
    public function pHook(array $get, array $server = []): array
    {
        $vorher = $_SERVER;
        $_GET = $get;
        $_SERVER = $server + ['REQUEST_METHOD' => 'GET'];
        $this->antworten = [];
        ob_start();
        try {
            $this->pRef('ProcessHookData');
        } finally {
            $ausgabe = (string)ob_get_clean();
            $_GET = [];
            $_SERVER = $vorher;
        }
        return ['antworten' => $this->antworten, 'ausgabe' => $ausgabe];
    }
    /** Den letzten Push lesen. */
    public function pPush(mixed $kachel = null): ?array
    {
        $vorher = count($this->gesendet);
        $this->pRef('PushCurrentState', $kachel);
        return count($this->gesendet) > $vorher ? json_decode(end($this->gesendet), true) : null;
    }
}

$iid = IPS\ObjectManager::registerObject(1);
IPS\InstanceManager::createInstance($iid, ['ModuleID' => '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}',
    'ModuleName' => 'BildkartenProbe', 'ModuleType' => 3, 'Class' => 'BildkartenProbe']);
/** @var BildkartenProbe $p */
$p = IPS\InstanceManager::getInstanceInterface($iid);
$p->pAttr('WebHookToken', 'tok');
$p->pAttr('Items', (string)json_encode([
    ['id' => 'a', 'name' => 'Vollmilch', 'category' => 'Milch & Käse', 'amount' => '2', 'inCart' => false, 'notes' => ''],
    ['id' => 'b', 'name' => 'Pringles Sweet Paprika', 'category' => '', 'amount' => '', 'inCart' => false, 'notes' => ''],
]));
$bilder = $p->pRef('GetAvailableProductImages');
$marken = $p->pRef('GetAvailableBrandImages', $bilder);
pruefe('Die echte Bildkarte ist gross (sonst prueft das hier nichts)', [count($bilder) > 1000, count($marken) > 5], [true, true]);
$version = (string)$p->pRef('BildkarteVersion');
$adresse = '/hook/shoppinglist/assets/' . $iid . '/?t=tok&a=bildkarte&v=' . $version;

// ── Kachel-Push und Anfangszustand: Adresse und Merker statt Karte ─────────
$push = $p->pPush();
pruefe('Push: keine Karte, dafuer Adresse mit Version und Merker', [
    array_key_exists('availableImages', $push ?? []), array_key_exists('availableBrands', $push ?? []),
    $push['availableImagesUrl'] ?? null, $push['imagesEnabled'] ?? null, preg_match('/^[0-9a-f]{16}$/', $version)],
    [false, false, $adresse, true, 1]);
pruefe('Push: Bild-Basis und der Rest des Zustands wie bisher', [$push['imageBase'] ?? null,
    array_column($push['items'] ?? [], 'id')], [$p->GetTileImageBase(), ['a', 'b']]);
$gross = strlen((string)json_encode(['availableImages' => $bilder, 'availableBrands' => $marken], JSON_UNESCAPED_SLASHES));
pruefe('Push: um die Karte kleiner (' . round($gross / 1024) . ' kB weniger)',
    strlen(end($p->gesendet)) < 60000 && $gross > 100000, true);

$doc = $p->GetVisualizationTile();
$von = strrpos($doc, 'handleMessage(');
$bis = strrpos($doc, ');</script>');
$anfang = ($von !== false && $bis !== false) ? json_decode(substr($doc, $von + 14, $bis - $von - 14), true) : null;
pruefe('Anfangszustand im Dokument: ebenso ohne Karte, mit Adresse und Merker', [
    is_array($anfang), array_key_exists('availableImages', $anfang ?? []), $anfang['availableImagesUrl'] ?? null,
    $anfang['imagesEnabled'] ?? null], [true, false, $adresse, true]);
pruefe('… mit demselben Pruefwert wie der Push (sonst sendete der erste Abgleich neu)',
    $anfang['stateHash'] ?? null, $push['stateHash'] ?? '');

// ── KachelStand: ein unveraenderter Stand sendet weiter nichts ─────────────
pruefe('Kachel kennt den Stand → nichts gesendet',
    $p->pPush((string)json_encode(['hash' => $push['stateHash'] ?? ''])), null);
$p->pAttr('Items', (string)json_encode([
    ['id' => 'a', 'name' => 'Vollmilch', 'category' => 'Milch & Käse', 'amount' => '3', 'inCart' => false, 'notes' => ''],
]));
$neu = $p->pPush((string)json_encode(['hash' => $push['stateHash'] ?? '']));
pruefe('Geaenderter Stand → gesendet, neuer Pruefwert, dieselbe Adresse', [
    is_array($neu), ($neu['stateHash'] ?? '') !== ($push['stateHash'] ?? ''), $neu['availableImagesUrl'] ?? null],
    [true, true, $adresse]);
pruefe('Die Groesse der Datei steht klein im Puffer (je Version einmal gemessen)',
    $p->pPuffer('BildkarteGroesse'), $version . ':' . strlen((string)$p->pRef('BildkarteRumpf')));

// ── GetAppState: unveraendert, mit Karte ───────────────────────────────────
$app = json_decode($p->GetAppState(), true);
pruefe('GetAppState: dieselben Schluessel in derselben Reihenfolge wie vorher', array_keys($app['state'] ?? []), [
    'type', 'items', 'suggestions', 'categoryOrder', 'categoryStyles', 'favoriteLists', 'hint', 'purchased',
    'imageBase', 'extApiBase', 'availableImages', 'availableBrands', 'extApiEnabled', 'extApiShowPrice',
    'extApiCartReady', 'scannerEnabled', 'showFavoriteHeart', 'showEditButton', 'showDeleteButton']);
pruefe('GetAppState: die volle Karte, keine Adresse, kein Merker', [
    ($app['state']['availableImages'] ?? null) == $bilder, ($app['state']['availableBrands'] ?? null) == $marken,
    array_key_exists('availableImagesUrl', $app['state'] ?? []), array_key_exists('imagesEnabled', $app['state'] ?? [])],
    [true, true, false, false]);
pruefe('GetAppState: keine Bildkarten-Adresse irgendwo im Text', str_contains($p->GetAppState(), 'a=bildkarte'), false);

// ── Der Hook ───────────────────────────────────────────────────────────────
$h = $p->pHook(['t' => 'tok', 'a' => 'bildkarte', 'v' => $version], ['HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br']);
$ant = $h['antworten'][0] ?? ['status' => 0, 'kopf' => [], 'rumpf' => ''];
$karte = json_decode((string)@gzdecode($ant['rumpf']), true);
pruefe('Hook: aktuelle Version — 200, privat ein Jahr, ETag, gzip', [$ant['status'], $ant['kopf']], [200, [
    'Content-Type: application/json; charset=utf-8', 'Cache-Control: private, max-age=31536000, immutable',
    'Vary: Accept-Encoding', 'ETag: "' . $version . '-gz"', 'X-Content-Type-Options: nosniff', 'Content-Encoding: gzip']]);
pruefe('Hook: der Inhalt ist genau die Karte des Zustands', [
    ($karte['images'] ?? null) == $bilder, ($karte['brands'] ?? null) == $marken], [true, true]);
$roh = $p->pHook(['t' => 'tok', 'a' => 'bildkarte', 'v' => $version])['antworten'][0] ?? ['kopf' => [], 'rumpf' => ''];
pruefe('Hook: ohne gzip ungepackt, eigener ETag',
    [json_decode($roh['rumpf'], true) == $karte, in_array('ETag: "' . $version . '"', $roh['kopf'], true),
     (bool)preg_grep('/^Content-Encoding/', $roh['kopf'])], [true, true, false]);
printf("     Datei: %d Bytes, gepackt %d Bytes\n", strlen($roh['rumpf']), strlen($ant['rumpf']));
$h = $p->pHook(['t' => 'tok', 'a' => 'bildkarte', 'v' => $version],
    ['HTTP_ACCEPT_ENCODING' => 'gzip', 'HTTP_IF_NONE_MATCH' => '"' . $version . '-gz"']);
pruefe('Hook: passender ETag → 304 ohne Rumpf', [$h['antworten'][0]['status'] ?? 0, $h['antworten'][0]['rumpf'] ?? null],
    [304, '']);
$h = $p->pHook(['t' => 'tok', 'a' => 'bildkarte', 'v' => '0000000000000000'], ['HTTP_ACCEPT_ENCODING' => 'gzip']);
pruefe('Hook: veraltete Version — aktuelle Karte, aber no-cache', [$h['antworten'][0]['status'] ?? 0,
    in_array('Cache-Control: no-cache', $h['antworten'][0]['kopf'] ?? [], true),
    json_decode((string)@gzdecode($h['antworten'][0]['rumpf'] ?? ''), true) == $karte], [200, true, true]);
$h = $p->pHook(['t' => 'tok', 'a' => 'bildkarte', 'v' => ['x']]);
pruefe('Hook: v als Liste — keine Warnung, gilt als veraltet',
    [in_array('Cache-Control: no-cache', $h['antworten'][0]['kopf'] ?? [], true), $warnungen], [true, []]);
http_response_code(200);
$h = $p->pHook(['t' => 'falsch', 'a' => 'bildkarte', 'v' => $version], ['HTTP_ACCEPT_ENCODING' => 'gzip']);
pruefe('Zugangsregel: falsches Token → 403, keine Karte', [$h['antworten'], $h['ausgabe'], http_response_code()],
    [[], '', 403]);
http_response_code(200);
$h = $p->pHook(['a' => 'bildkarte', 'v' => $version]);
pruefe('Zugangsregel: ohne Token → 403, keine Karte', [$h['antworten'], $h['ausgabe'], http_response_code()],
    [[], '', 403]);

// ── Rueckfall: kann der Hook nicht, bleibt die Karte im Zustand ────────────
$p->pAttr('WebHookToken', '');
$ohne = $p->pPush();
pruefe('Ohne Token (Hook liesse niemanden herein): Karte im Push wie bisher, Merker dazu', [
    ($ohne['availableImages'] ?? null) == $bilder, ($ohne['availableBrands'] ?? null) == $marken,
    array_key_exists('availableImagesUrl', $ohne ?? []), $ohne['imagesEnabled'] ?? null], [true, true, false, true]);
$p->pAttr('WebHookToken', 'tok');
$p->pPuffer('BildkarteGroesse', $version . ':' . (KachelApp::Ausgabegrenze() + 1));
$zuGross = $p->pPush();
pruefe('Zu gross fuer eine Hook-Antwort: ebenso die Karte im Zustand', [
    ($zuGross['availableImages'] ?? null) == $bilder, array_key_exists('availableImagesUrl', $zuGross ?? [])], [true, false]);
$p->pPuffer('BildkarteGroesse', 'kaputt');
pruefe('Unlesbarer Puffer: neu gemessen, wieder die Adresse',
    [$p->pPush()['availableImagesUrl'] ?? null, $p->pPuffer('BildkarteGroesse')],
    [$adresse, $version . ':' . strlen((string)$p->pRef('BildkarteRumpf'))]);

// ── Produktbilder aus: kein Merker „an", keine Karte, keine Datei ──────────
IPS_SetProperty($iid, 'ShowProductImages', false);
IPS_ApplyChanges($iid);
$aus = $p->pPush();
pruefe('Bilder aus: Merker falsch, leere Karten, keine Adresse', [$aus['imagesEnabled'] ?? null,
    $aus['availableImages'] ?? null, $aus['availableBrands'] ?? null, array_key_exists('availableImagesUrl', $aus ?? [])],
    [false, [], [], false]);
$h = $p->pHook(['t' => 'tok', 'a' => 'bildkarte', 'v' => $version]);
pruefe('Bilder aus: der Hook liefert keine Karte (404)', [$h['antworten'][0]['status'] ?? 0,
    $h['antworten'][0]['rumpf'] ?? null], [404, '']);
IPS_SetProperty($iid, 'ShowProductImages', true);
IPS_ApplyChanges($iid);

pruefe('Keine unterdrueckte Warnung ist durchgerutscht', $warnungen, []);

// ── Die Kachel: kachelZustand und der Lader im Skript der Web-App (Node) ──
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "--   Node fehlt: der Teil der Kachel (BildkarteTest.mjs) entfaellt\n";
} else {
    $aus = [];
    exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/BildkarteTest.mjs') . ' 2>&1', $aus, $rc);
    foreach ($aus as $zeile) {
        echo '   ' . $zeile . "\n";
    }
    pruefe('Kachel-Teil in Node (BildkarteTest.mjs) gruen', $rc, 0);
}

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
