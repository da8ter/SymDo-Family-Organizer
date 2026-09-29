<?php

declare(strict_types=1);

/**
 * Die Einkaufs-Uebersicht holt und sendet nur, was der Streifen braucht
 * (26.09.2026).
 *
 * Vorher: bei jedem Ereignis SL_GetAppState (der VOLLE Zustand samt Vorschlaegen,
 * Favoriten, Kaeufen) und die komplette Bildkarte — rund 3 300 Eintraege, 100 KB —
 * an jede offene Kachel, auch wenn sich nichts geaendert hatte. Beim Kernelstart
 * und beim Neuladen stand zudem „InstanceInterface is not available" im Log, und
 * die Kachel bekam einen leeren Streifen.
 *
 * Gefahren wird gegen die echten Klassen mit den Symcon-Attrappen und gegen die
 * echte Bildkarte aus SymDoShoppingList/assets. Ob die gesiebte Karte dieselben
 * Bilder ergibt, prueft der Aufloeser der Kachel selbst (Node, wenn vorhanden).
 *
 *   php SymDoShoppingListOverview/tests/UebersichtTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../../SymDoShoppingList/module.php';
require_once __DIR__ . '/../module.php';

IPS\Kernel::reset();

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
    printf("%-4s %-66s%s\n", $ok ? 'OK' : 'FEHL', $name, $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/* Warnungen, die NICHT unterdrueckt sind, landen hier — genau die standen im Log. */
$warnungen = [];
set_error_handler(static function (int $nr, string $text) use (&$warnungen): bool {
    if (error_reporting() & $nr) {
        $warnungen[] = $text;
    }
    return true;
});

// ── EinkaufsUebersicht: offene Artikel ─────────────────────────────────────
$state = [
    'items' => [
        ['id' => 'a', 'name' => 'Vollmilch', 'category' => 'Milchprodukte', 'amount' => '2', 'inCart' => false, 'notes' => 'x'],
        ['id' => 'b', 'name' => 'Äpfel', 'category' => 'Obst & Gemüse', 'amount' => '', 'inCart' => false],
        ['id' => 'c', 'name' => 'Brot', 'category' => 'Backwaren', 'amount' => '', 'inCart' => true],
        ['id' => 'd', 'name' => 'Pringles Sweet Paprika', 'category' => '', 'amount' => '', 'inCart' => false],
        ['id' => 'e', 'name' => 'Schraube M4', 'category' => '42', 'amount' => '8', 'inCart' => false],
    ],
    'categoryOrder' => ['Obst & Gemüse', '42', 'Milchprodukte'],
];
pruefe('OffeneArtikel: Kategorien-Reihenfolge, Abgehaktes fehlt, nur vier Felder',
    EinkaufsUebersicht::OffeneArtikel($state), [
        ['id' => 'b', 'name' => 'Äpfel', 'amount' => '', 'imageUrl' => ''],
        ['id' => 'e', 'name' => 'Schraube M4', 'amount' => '8', 'imageUrl' => ''],
        ['id' => 'a', 'name' => 'Vollmilch', 'amount' => '2', 'imageUrl' => ''],
        ['id' => 'd', 'name' => 'Pringles Sweet Paprika', 'amount' => '', 'imageUrl' => ''],
    ]);
pruefe('OffeneArtikel: eine Kategorie aus Ziffern erscheint nur EINMAL',
    count(EinkaufsUebersicht::OffeneArtikel(['items' => $state['items'], 'categoryOrder' => ['42']])), 4);

// ── EinkaufsUebersicht: Bildkarten ─────────────────────────────────────────
$bilder = ['milch' => 'milch.png', 'käse' => 'kaese.png', 'tomate' => 'tomate.png', 'brot' => 'brot.png',
           'apfel' => 'apfel.png', 'äpfel' => 'apfel.png', '7' => 'sieben.png'];
$marken = ['pringles' => 'chips.png', 'nutella' => 'nutella.png'];
$karten = EinkaufsUebersicht::Bildkarten([
    ['name' => 'Vollmilch'], ['name' => 'Kaese'], ['name' => 'Tomaten'], ['name' => 'Pringles Sweet'],
    ['name' => 'Brot', 'imageUrl' => 'api-images/1.webp'], ['name' => '7up'],
], $bilder, $marken);
pruefe('Bildkarten: Endung, Entfaltung ae→ä, Stamm, Marke — und nichts sonst',
    [$karten['bilder'], $karten['marken']],
    [['milch' => 'milch.png', 'käse' => 'kaese.png', 'tomate' => 'tomate.png', '7' => 'sieben.png'], ['pringles' => 'chips.png']]);
pruefe('Bildkarten: ein Artikel mit eigenem Bild braucht keine Karte',
    EinkaufsUebersicht::Bildkarten([['name' => 'Milch', 'imageUrl' => 'x.webp']], $bilder, $marken), ['bilder' => [], 'marken' => []]);
pruefe('Kandidaten: Faltung, Entfaltung, entfaltete Vorsilben (wie die Stämme der Kachel)',
    array_values(array_intersect(['äpfel', 'aepfel', 'käse', 'kä', 'tomat'],
        array_merge(EinkaufsUebersicht::Kandidaten('Äpfel'), EinkaufsUebersicht::Kandidaten('Kaese'),
            EinkaufsUebersicht::Kandidaten('Tomaten')))),
    ['äpfel', 'aepfel', 'käse', 'kä', 'tomat']);

// ── Die echte Einkaufsliste als Quelle ─────────────────────────────────────

/** SymDoShoppingList mit genau dem, was GetOverviewState und der Vergleich brauchen. */
final class ListenProbe extends SymDoShoppingList
{
    public function Create(): void
    {
        $this->RegisterAttributeString('Items', '[]');
        $this->RegisterPropertyBoolean('ShowProductImages', true);
        $this->RegisterAttributeString('ImageMapCache', '');
        $this->RegisterPropertyBoolean('StoreOrderEnabled', false);
        $this->RegisterPropertyString('CategoryOrder', '["Obst & Gemüse","Milchprodukte"]');
        $this->RegisterAttributeString('WebHookToken', 'tok');
        $this->RegisterAttributeInteger('AppRevision', 7);
        $this->RegisterVariableInteger('ItemCount', 'Item Count', '', 1);
        $this->RegisterVariableInteger('LastUsed', 'Last Used', '', 2);
    }
    public function ApplyChanges(): void {}
    protected function getTime(): int { return time(); }

    public function pArtikel(array $items): void { $this->WriteAttributeString('Items', (string)json_encode($items)); }
    public function pRef(string $fn, mixed ...$a): mixed
    {
        return (new ReflectionMethod(SymDoShoppingList::class, $fn))->invoke($this, ...$a);
    }
    /** Die Schluessel des vollen Zustands, die die Uebersicht liest — gebaut wie in BuildStatePayload. */
    public function pVoll(): array
    {
        $bilder = $this->pRef('GetAvailableProductImages');
        return ['revision' => 7, 'kind' => 'shopping', 'state' => [
            'items'           => $this->pRef('LoadItems'),
            'suggestions'     => [['name' => 'Beiwerk', 'category' => 'X']],
            'favoriteLists'   => [['id' => 'f', 'name' => 'Grillen', 'items' => []]],
            'categoryOrder'   => $this->pRef('GetCategoryOrderFlat'),
            'imageBase'       => $this->GetTileImageBase(),
            'availableImages' => $bilder,
            'availableBrands' => $this->pRef('GetAvailableBrandImages', $bilder),
        ]];
    }
    /** Abhaken wie die Liste: unbekannte Kennung wirft. */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident !== 'ToggleCart') {
            return;
        }
        $d = json_decode((string)$Value, true);
        $items = $this->pRef('LoadItems');
        foreach ($items as &$it) {
            if ($it['id'] === ($d['id'] ?? '')) {
                $it['inCart'] = true;
                $this->pArtikel($items);
                return;
            }
        }
        throw new Exception('Unknown item id');
    }
}

final class UebersichtProbe extends SymDoShoppingListOverview
{
    public array $gesendet = [];
    protected function UpdateVisualizationValue(mixed $Value)
    {
        $this->gesendet[] = json_decode((string)$Value, true);
        return true;
    }
    protected function getTime(): int { return time(); }
    public function pPuffer(string $name, ?string $wert = null): string
    {
        if ($wert !== null) {
            $this->SetBuffer($name, $wert);
        }
        return $this->GetBuffer($name);
    }
}

function instanz(string $klasse, string $guid): int
{
    $iid = IPS\ObjectManager::registerObject(1);
    ob_start();
    IPS\InstanceManager::createInstance($iid, ['ModuleID' => $guid, 'ModuleName' => $klasse, 'ModuleType' => 3, 'Class' => $klasse]);
    ob_end_clean();
    return $iid;
}

/* Die Praefix-Funktionen der Liste. `slWeg` spielt die Liste im Neuaufbau:
   Symcon warnt dann und liefert keinen Text. */
$GLOBALS['slWeg'] = false;
$GLOBALS['slRufe'] = [];
function SL_GetAppState(int $id)
{
    $GLOBALS['slRufe'][] = 'GetAppState';
    if ($GLOBALS['slWeg']) {
        trigger_error('InstanceInterface is not available', E_USER_WARNING);
        return null;
    }
    return (string)json_encode(IPS\InstanceManager::getInstanceInterface($id)->pVoll(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

$sl = instanz('ListenProbe', '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}');
/** @var ListenProbe $liste */
$liste = IPS\InstanceManager::getInstanceInterface($sl);
$liste->pArtikel([
    ['id' => 'a', 'name' => 'Vollmilch', 'category' => 'Milchprodukte', 'amount' => '2', 'inCart' => false],
    ['id' => 'b', 'name' => 'Äpfel', 'category' => 'Obst & Gemüse', 'amount' => '', 'inCart' => false],
    ['id' => 'd', 'name' => 'Pringles Sweet Paprika', 'category' => '', 'amount' => '', 'inCart' => false],
    ['id' => 'c', 'name' => 'Brot', 'category' => '', 'amount' => '', 'inCart' => true],
]);
$zaehler = IPS_GetObjectIDByIdent('ItemCount', $sl);
$vollBilder = $liste->pRef('GetAvailableProductImages');
pruefe('Die echte Bildkarte ist gross (sonst prueft das hier nichts)', count($vollBilder) > 1000, true);

$uid = instanz('UebersichtProbe', '{00000000-0000-0000-0000-00000000E1A5}');
/** @var UebersichtProbe $u */
$u = IPS\InstanceManager::getInstanceInterface($uid);
IPS_SetProperty($uid, 'ShoppingListInstanceID', $sl);
$u->gesendet = [];
IPS_ApplyChanges($uid);
$erster = $u->gesendet[0] ?? [];
pruefe('ApplyChanges: der Stand geht hinaus, offene Artikel in Reihenfolge',
    [count($u->gesendet), array_column($erster['items'] ?? [], 'name')],
    [1, ['Äpfel', 'Vollmilch', 'Pringles Sweet Paprika']]);
pruefe('Gesendet wird nur der passende Teil der Bildkarte',
    [count((array)($erster['productImages'] ?? [])) < 20, count((array)($erster['productImages'] ?? [])) > 0,
     array_keys((array)($erster['productBrands'] ?? []))], [true, true, ['pringles']]);
pruefe('… die Nachricht ist klein (vorher >100 KB)', strlen((string)json_encode($erster)) < 2000, true);
pruefe('Bild-Basis aus dem Zustand, kein zweiter Ruf in die Liste',
    [$erster['imageBase'] ?? null, $GLOBALS['slRufe']], [$liste->GetTileImageBase(), ['GetAppState']]);

// Dieselbe Meldung ohne Aenderung — der Zaehler wird bei JEDEM Speichern gesetzt.
$u->gesendet = [];
$u->MessageSink(time(), $zaehler, VM_UPDATE, [3, false, 3, time()]);
pruefe('Zaehler ohne neuen Wert, nichts geaendert → nichts', count($u->gesendet), 0);
$u->MessageSink(time(), $zaehler, VM_UPDATE, [3, true, 2, time()]);
pruefe('Zaehler mit neuem Wert, Stand trotzdem gleich → nichts (Pruefwert)', count($u->gesendet), 0);

// Menge geaendert: die Zaehler bleiben gleich ($Data[1] false) — der Streifen zeigt Neues.
$liste->pArtikel([
    ['id' => 'a', 'name' => 'Vollmilch', 'category' => 'Milchprodukte', 'amount' => '3', 'inCart' => false],
    ['id' => 'b', 'name' => 'Äpfel', 'category' => 'Obst & Gemüse', 'amount' => '', 'inCart' => false],
    ['id' => 'd', 'name' => 'Pringles Sweet Paprika', 'category' => '', 'amount' => '', 'inCart' => false],
    ['id' => 'c', 'name' => 'Brot', 'category' => '', 'amount' => '', 'inCart' => true],
]);
$u->MessageSink(time(), $zaehler, VM_UPDATE, [3, false, 3, time()]);
pruefe('Menge geaendert bei unveraenderten Zaehlern → die Aenderung geht hinaus',
    [count($u->gesendet), $u->gesendet[0]['items'][1]['amount'] ?? null], [1, '3']);

// ── Liste im Neuaufbau: still, und kein leerer Streifen ────────────────────
$u->gesendet = [];
$GLOBALS['slWeg'] = true;
$liste->pArtikel([['id' => 'z', 'name' => 'Neu', 'category' => '', 'amount' => '', 'inCart' => false]]);
$u->MessageSink(time(), $zaehler, VM_UPDATE, [1, true, 3, time()]);
IPS_ApplyChanges($uid);
pruefe('Liste antwortet nicht: keine Nachricht (vorher: leerer Streifen), keine Warnung',
    [count($u->gesendet), $warnungen], [0, []]);
$html = $u->GetVisualizationTile();
pruefe('… die Kachel baut trotzdem auf (leerer Streifen, ohne Warnung)',
    [str_contains($html, '"items":[]'), $warnungen], [true, []]);
$GLOBALS['slWeg'] = false;
$u->MessageSink(time(), $zaehler, VM_UPDATE, [1, false, 1, time()]);
pruefe('Steht die Liste wieder, kommt der Stand mit dem naechsten Ereignis',
    [count($u->gesendet), array_column($u->gesendet[0]['items'] ?? [], 'name')], [1, ['Neu']]);

// ── Korrekturzustand: Abhaken aus der Kachel ───────────────────────────────
$liste->pArtikel([
    ['id' => 'a', 'name' => 'Vollmilch', 'category' => 'Milchprodukte', 'amount' => '2', 'inCart' => false],
    ['id' => 'b', 'name' => 'Äpfel', 'category' => 'Obst & Gemüse', 'amount' => '', 'inCart' => false],
]);
$u->MessageSink(time(), $zaehler, VM_UPDATE, [2, true, 1, time()]);
$u->gesendet = [];
IPS_RequestAction($uid, 'Check', 'gibtsnicht');
pruefe('Abhaken scheitert (unbekannte Kennung) → der UNVERAENDERTE Stand geht hinaus',
    [count($u->gesendet), array_column($u->gesendet[0]['items'] ?? [], 'id'), $warnungen], [1, ['b', 'a'], []]);
$u->gesendet = [];
$u->MessageSink(time(), $zaehler, VM_UPDATE, [2, false, 2, time()]);
pruefe('Im Korrekturzustand geht auch eine Meldung ohne Aenderung hinaus', count($u->gesendet), 1);

$u->gesendet = [];
IPS_RequestAction($uid, 'Check', 'a');
pruefe('Abhaken gelingt: kein eigener Push, die Liste meldet sich', count($u->gesendet), 0);
$u->MessageSink(time(), $zaehler, VM_UPDATE, [1, true, 2, time()]);
pruefe('… und ihr Ereignis bringt den neuen Stand', array_column($u->gesendet[0]['items'] ?? [], 'id'), ['b']);

// Frueher als 1,5 s: der Zustand bleibt; ab 1,5 s beendet die naechste Nachricht ihn.
$u->pPuffer(KachelPush::PUFFER_AKTION, KachelPush::Aktion(microtime(true) - 2.0));
$u->gesendet = [];
$u->MessageSink(time(), $zaehler, VM_UPDATE, [1, false, 1, time()]);
$u->MessageSink(time(), $zaehler, VM_UPDATE, [1, false, 1, time()]);
pruefe('Ab 1,5 s nach der Aktion: noch EINE Nachricht, dann wieder gefiltert',
    [count($u->gesendet), $u->pPuffer(KachelPush::PUFFER_AKTION)], [1, '']);

// ── Schlank: SL_GetOverviewState, sobald registriert ───────────────────────
$u->gesendet = [];
$GLOBALS['slRufe'] = [];
IPS_ApplyChanges($uid);
$ueberVoll = $u->gesendet[0] ?? null;
/* eval nur mit festem Text aus diesem Pruefstand: die Funktion soll es erst JETZT
   geben — so wie Symcon sie erst nach einem Kernel-Neustart registriert. */
eval('function SL_GetOverviewState(int $id) { $GLOBALS["slRufe"][] = "GetOverviewState";'
    . ' return IPS\InstanceManager::getInstanceInterface($id)->GetOverviewState(); }');
IPS_ApplyChanges($uid);
$ueberSchlank = $u->gesendet[1] ?? null;
pruefe('Mit SL_GetOverviewState wird schlank geholt …', $GLOBALS['slRufe'], ['GetAppState', 'GetOverviewState']);
pruefe('… und die Kachel bekommt genau dasselbe', $ueberSchlank, $ueberVoll);
$auszug = json_decode($liste->GetOverviewState(), true);
pruefe('GetOverviewState traegt keine Vorschlaege, Favoriten, Kaeufe, und nur offene Artikel',
    [array_keys($auszug['state']), array_column($auszug['state']['items'], 'id')],
    [['items', 'categoryOrder', 'imageBase', 'availableImages', 'availableBrands'], ['b']]);

// ── Gleiche Bilder: der Aufloeser der KACHEL ueber volle und gesiebte Karte ─
$namen = ['Vollmilch', 'Milch', 'H-Milch 1,5%', 'Hafermilch', 'Äpfel', 'Aepfel', 'Apfel', 'Kaese', 'Käse',
    'Frischkäse', 'Bio-Tomaten', 'Tomaten passiert', 'Pringles Sweet Paprika', 'Coca Cola Zero', 'Brötchen',
    'Broetchen', 'Zahnpasta', 'Toilettenpapier', 'Eier', 'Butter', 'Nudeln', 'Spaghetti', 'Basmati Reis',
    'Kartoffeln', 'Zwiebeln', 'Knoblauch', 'Paprikapulver edelsüß', 'Unbekanntes Ding', 'X', '  milch  ',
    'MILCH', 'Straße', 'Grüner Tee', 'Mehl Type 405', 'Joghurt', 'Erdnussbutter', 'Nutella', 'Mineralwasser',
    'Kaffeebohnen', 'Katzenfutter', 'Müsli', 'Muesli', 'Haferflocken', 'Olivenöl', 'Gurken', 'Möhren'];
// Dazu jeder 25. Schluessel der echten Karte, abgewandelt wie ein Mensch ihn tippt.
$schluessel = array_map('strval', array_keys($vollBilder));
for ($i = 0; $i < count($schluessel); $i += 25) {
    $k = $schluessel[$i];
    $namen[] = mb_convert_case($k, MB_CASE_TITLE);
    $namen[] = 'Bio ' . $k . 'n';
}
$vollMarken = $liste->pRef('GetAvailableBrandImages', $vollBilder);
$artikel = array_map(static fn(string $n): array => ['name' => $n, 'imageUrl' => ''], $namen);
$alle = EinkaufsUebersicht::Bildkarten($artikel, $vollBilder, $vollMarken);
$einzeln = [];
foreach ($artikel as $a) {
    $einzeln[] = EinkaufsUebersicht::Bildkarten([$a], $vollBilder, $vollMarken);
}
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "--   Node fehlt: der Vergleich mit dem Aufloeser der Kachel entfaellt\n";
} else {
    $tmp = rtrim(sys_get_temp_dir(), '/') . '/symdo_slo_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($tmp)));
    file_put_contents($tmp . '/eingabe.json', (string)json_encode([
        'artikel' => $artikel, 'voll' => ['b' => $vollBilder, 'm' => $vollMarken],
        'alle' => $alle, 'einzeln' => $einzeln,
    ], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT));
    /* Der Aufloeser wird aus module.html geschnitten — geprueft wird der Code,
       der in der Kachel laeuft, nicht eine Abschrift. */
    file_put_contents($tmp . '/lauf.js', <<<'JS'
const fs = require('fs');
const html = fs.readFileSync(process.argv[2], 'utf8');
const von = html.indexOf('let productImages = {};');
const bis = html.indexOf('// ── Kachel', von);
const code = html.slice(von, bis);
const e = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const liste = (o) => Object.values(o);
// new Function nur mit dem Aufloeser aus der eigenen module.html, lokal in Node — keine fremde Eingabe.
const lauf = new Function('art', 'b', 'm', code + `
  let state = { imageBase: '/b/' };
  productImages = b; productBrands = m; _rebuildPiKeys();
  return art.map((a) => imageUrlFor(a));`);
const art = liste(e.artikel);
const voll = lauf(art, e.voll.b, e.voll.m);
const alle = lauf(art, e.alle.bilder, e.alle.marken);
const einzeln = art.map((a, i) => lauf([a], e.einzeln[i].bilder, e.einzeln[i].marken)[0]);
const abw = [];
art.forEach((a, i) => {
  if (voll[i] !== alle[i] || voll[i] !== einzeln[i]) abw.push([a.name, voll[i], alle[i], einzeln[i]]);
});
process.stdout.write(JSON.stringify({ n: art.length, getroffen: voll.filter(Boolean).length, abw }));
JS);
    $aus = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp . '/lauf.js') . ' '
        . escapeshellarg(__DIR__ . '/../module.html') . ' ' . escapeshellarg($tmp . '/eingabe.json') . ' 2>&1');
    $erg = json_decode($aus, true);
    pruefe('Kachel-Aufloeser: gesiebte Karte (alle / je Artikel) = volle Karte',
        is_array($erg) ? $erg['abw'] : $aus, []);
    pruefe('… bei ' . (is_array($erg) ? $erg['n'] : 0) . ' Namen, von denen die meisten ein Bild haben',
        is_array($erg) && $erg['getroffen'] > $erg['n'] / 2, true);
}
pruefe('Gesiebt bleibt ein Bruchteil der Karte',
    [count($alle['bilder']) < count($vollBilder) / 2, max(array_map(static fn($k) => count($k['bilder']), $einzeln)) < 60],
    [true, true]);

pruefe('Keine unterdrueckte Warnung ist durchgerutscht', $warnungen, []);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
