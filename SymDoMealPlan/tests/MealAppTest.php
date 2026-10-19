<?php

declare(strict_types=1);

/**
 * Essensplan für die Web-App (19.10.2026): MPL_AppAction und der Durchgang im
 * Gateway (MealPlanBridge).
 *
 * Geprüft wird, was über die Grenze darf: nur bekannte Aktionen, nur echte
 * Rezepte, nur Zutaten, die im Rezept stehen. Dazu der Stand für die App —
 * ohne Kachel-Texte, mit der Kennung des Gerichtsbilds statt der Miniatur.
 *
 *   php SymDoMealPlan/tests/MealAppTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../module.php';
require_once __DIR__ . '/../../SymDoGateway/libs/MealPlanBridge.php';

date_default_timezone_set('Europe/Berlin');
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

/** Die Einkaufsliste: merkt sich, was der Essensplan bei ihr bestellt. */
final class EinkaufAttrappe extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterVariableInteger('ItemCount', 'Item Count', '', 1);
        $this->RegisterVariableInteger('LastUsed', 'Last Used', '', 2);
    }
    public function RequestAction(string $Ident, mixed $Value): void
    {
        $GLOBALS['bestellt'][] = [$Ident, is_string($Value) && str_starts_with($Value, '{') ? json_decode($Value, true) : $Value];
    }
    protected function getTime(): int { return time(); }
}

final class EssensplanProbe extends SymDoMealPlan
{
    public array $gesendet = [];
    protected function UpdateVisualizationValue(mixed $Value)
    {
        $this->gesendet[] = json_decode((string)$Value, true);
        return true;
    }
    protected function getTime(): int { return time(); }
}

$GLOBALS['bestellt'] = [];
$GLOBALS['favoriten'] = [
    ['id' => 'f1', 'name' => 'Lasagne', 'mediaId' => 0, 'servings' => 4, 'isRecipe' => true, 'imageId' => 4711,
     'items' => [['name' => 'Nudeln', 'amount' => '500 g', 'category' => 'Trocken'], ['name' => 'Tomaten', 'amount' => '2']]],
    ['id' => 'f2', 'name' => 'Brot', 'mediaId' => 0, 'items' => [['name' => 'Mehl', 'amount' => '1 kg']]],
];
function SL_GetAppState(int $id): string
{
    return (string)json_encode(['revision' => 1, 'state' => ['favoriteLists' => $GLOBALS['favoriten']]]);
}
/** Neue Favoritenliste wie die Einkaufsliste: listId vergibt sie selbst. */
function SL_AppCall(int $id, string $aktion, string $json): string
{
    $d = json_decode($json, true);
    $GLOBALS['angelegt'][] = [$aktion, $d];
    $GLOBALS['favoriten'][] = ['id' => 'neu' . count($GLOBALS['favoriten']), 'name' => $d['name'],
        'items' => $d['items'], 'mediaId' => (int)$d['mediaId'], 'isRecipe' => $d['isRecipe']];
    return SL_GetAppState($id);
}

function instanz(string $klasse, string $guid): int
{
    $iid = IPS\ObjectManager::registerObject(1);
    ob_start();
    IPS\InstanceManager::createInstance($iid, ['ModuleID' => $guid, 'ModuleName' => $klasse, 'ModuleType' => 3, 'Class' => $klasse]);
    ob_end_clean();
    return $iid;
}

$sl = instanz('EinkaufAttrappe', '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}');
$mid = instanz('EssensplanProbe', '{94E5ED88-1017-4A73-B9BF-2B2010679022}');
/** @var EssensplanProbe $m */
$m = IPS\InstanceManager::getInstanceInterface($mid);
IPS_SetProperty($mid, 'ShoppingListInstanceID', $sl);
IPS_ApplyChanges($mid);

$rufe = static fn(array $anfrage): array => json_decode($m->AppAction((string)json_encode($anfrage)), true);
$heute = date('Y-m-d');
$tagIn = static function (array $stand, string $datum): array {
    foreach ($stand['weeks'] ?? [] as $w) {
        foreach ($w['days'] ?? [] as $d) {
            if ($d['date'] === $datum) {
                return $d;
            }
        }
    }
    return [];
};

// ── Stand ──────────────────────────────────────────────────────────────────
$r = $rufe(['action' => 'state']);
pruefe('Stand: zwei Wochen, Rezepte, ohne Kachel-Texte und ohne type',
    [$r['ok'], count($r['mealplan']['weeks'] ?? []), array_column($r['mealplan']['favorites'] ?? [], 'id'),
     isset($r['mealplan']['texts']), isset($r['mealplan']['type']), $r['mealplan']['hasSource'] ?? null],
    [true, 2, ['f2', 'f1'], false, false, true]);
$r = $rufe([]);
pruefe('Ohne Aktion gilt „state"', [$r['ok'], isset($r['mealplan'])], [true, true]);

// ── Planen ─────────────────────────────────────────────────────────────────
$m->gesendet = [];
$r = $rufe(['action' => 'setMeal', 'date' => '2026-02-30', 'listId' => 'f1']);
pruefe('Kein echtes Datum → abgelehnt, nichts gespeichert, nichts an die Kachel',
    [$r['ok'], $r['error']['code'] ?? '', count($m->gesendet)], [false, 'invalid_payload', 0]);
$r = $rufe(['action' => 'setMeal', 'date' => $heute, 'listId' => 'erfunden']);
pruefe('Unbekanntes Rezept → abgelehnt (kein „Rezept gelöscht" aus dem Nichts)',
    [$r['ok'], $r['error']['code'] ?? ''], [false, 'not_found']);
$r = $rufe(['action' => 'setMeal', 'date' => $heute, 'listId' => 'f1']);
$tag = $tagIn($r['mealplan'] ?? [], $heute);
pruefe('Rezept geplant → frischer Stand zurück, Kachel bekommt ihn auch',
    [$r['ok'], $tag['title'] ?? null, $tag['listId'] ?? null, $tag['cart'] ?? null, array_column($m->gesendet, 'type')],
    [true, 'Lasagne', 'f1', true, ['state']]);
pruefe('App-Stand: Gerichtsbild nur als Kennung, ohne Miniatur',
    [$tag['dishId'] ?? null, $tag['image'] ?? null], [4711, '']);
$kachel = json_decode($m->GetState(), true);
pruefe('Kachel-Stand trägt die Kennung ebenfalls (und weiter die Texte)',
    [$tagIn($kachel, $heute)['dishId'] ?? null, isset($kachel['texts'])], [4711, true]);
$morgen = date('Y-m-d', time() + 86400);
$r = $rufe(['action' => 'setMeal', 'date' => $morgen, 'text' => '  Pizza bestellen  ']);
pruefe('Freitext → Titel ohne Rezept, ohne Korb',
    [$tagIn($r['mealplan'], $morgen)['title'] ?? null, $tagIn($r['mealplan'], $morgen)['listId'] ?? null, $tagIn($r['mealplan'], $morgen)['cart'] ?? null],
    ['Pizza bestellen', '', false]);
$r = $rufe(['action' => 'setMeal', 'date' => $morgen, 'listId' => '', 'text' => '']);
pruefe('Beides leer → Tag geräumt', $tagIn($r['mealplan'], $morgen)['title'] ?? null, '');

// ── Detail und Woche ───────────────────────────────────────────────────────
$r = $rufe(['action' => 'detail', 'date' => $heute]);
pruefe('Detail: Titel, Portionen, Zutaten, Bildkennung, ohne type',
    [$r['ok'], $r['detail']['title'] ?? null, $r['detail']['servings'] ?? null,
     array_column($r['detail']['ingredients'] ?? [], 'name'), $r['detail']['dishId'] ?? null, isset($r['detail']['type'])],
    [true, 'Lasagne', 4, ['Nudeln', 'Tomaten'], 4711, false]);
$montag = date('Y-m-d', strtotime('monday this week 12:00'));
$r = $rufe(['action' => 'weekDetail', 'monday' => $montag]);
pruefe('Woche: Zutaten mit Herkunft',
    [$r['ok'], array_column($r['ingredients'] ?? [], 'name'), array_unique(array_column($r['ingredients'] ?? [], 'listId'))],
    [true, ['Nudeln', 'Tomaten'], ['f1']]);

// ── Einkauf ────────────────────────────────────────────────────────────────
$GLOBALS['bestellt'] = [];
$r = $rufe(['action' => 'addItemsToCart', 'listId' => 'f1', 'items' => [
    ['name' => 'nudeln', 'amount' => '1000 g'], ['name' => 'Gold', 'amount' => '1 kg'], 'kaputt']]);
pruefe('Auswahl: nur Zutaten des Rezepts, Name und Kategorie aus dem Rezept, Menge vom Steller',
    [$r['ok'], $r['count'] ?? null, $GLOBALS['bestellt']],
    [true, 1, [['AddItem', ['name' => 'Nudeln', 'category' => 'Trocken', 'amount' => '1000 g']]]]);
$GLOBALS['bestellt'] = [];
$r = $rufe(['action' => 'addItemsToCart', 'items' => [['name' => 'Mehl', 'listId' => 'f2'], ['name' => 'Tomaten', 'listId' => 'f1']]]);
pruefe('Wochen-Auswahl: Herkunft je Zeile, je Rezept gezählt',
    [$r['count'] ?? null, array_column(array_column($GLOBALS['bestellt'], 1), 'name')], [2, ['Mehl', 'Tomaten']]);
$GLOBALS['bestellt'] = [];
$r = $rufe(['action' => 'addItemsToCart', 'listId' => 'f1', 'items' => [['name' => 'Gold']]]);
pruefe('Nichts Passendes → ok false, nichts bestellt', [$r['ok'], $GLOBALS['bestellt']], [false, []]);
$r = $rufe(['action' => 'addToCart', 'listId' => 'f1']);
pruefe('Ganzes Rezept → AddFavoriteListToCart mit der rohen listId',
    [$r['ok'], $GLOBALS['bestellt']], [true, [['AddFavoriteListToCart', 'f1']]]);

// ── Portionen ──────────────────────────────────────────────────────────────
$GLOBALS['bestellt'] = [];
$r = $rufe(['action' => 'setServings', 'listId' => 'f1', 'servings' => 0]);
pruefe('Portionen 0 → abgelehnt, nichts geschrieben', [$r['ok'], $GLOBALS['bestellt']], [false, []]);
$r = $rufe(['action' => 'setServings', 'listId' => 'f1', 'servings' => 6,
            'amounts' => ['Nudeln' => '750 g', 'Tomaten' => 3, 'Böse' => ['x'], 7 => 'y']]);
pruefe('Portionen 6 → an die Einkaufsliste, nur Text-/Zahlmengen mit Namen',
    [$r['ok'], $GLOBALS['bestellt']],
    [true, [['SetFavoriteServings', ['listId' => 'f1', 'servings' => 6, 'amounts' => ['Nudeln' => '750 g', 'Tomaten' => '3']]]]]);

// ── Rezept-Scan ────────────────────────────────────────────────────────────
$GLOBALS['angelegt'] = [];
$r = $rufe(['action' => 'saveScan', 'date' => $morgen, 'name' => '  Ofengemüse  ', 'servings' => 120,
    'url' => 'javascript:alert(1)', 'mediaId' => '17abc',
    'items' => [['name' => 'Paprika', 'amount' => '2', 'category' => 'Obst', 'boese' => 'x'], ['name' => ''], 'kaputt', ['name' => ['x']]]]);
$neu = $GLOBALS['angelegt'][0][1] ?? [];
pruefe('Scan: Rezept angelegt, dem Tag zugewiesen, listId zurück',
    [$r['ok'], $r['listId'] ?? '', $tagIn($r['mealplan'] ?? [], $morgen)['title'] ?? null],
    [true, 'neu2', 'Ofengemüse']);
pruefe('Scan: bereinigt an der Grenze (Zutaten, Link nur http, Medien-ID als Zahl, Portionen gedeckelt, Rezept-Haken)',
    [$neu['items'] ?? null, $neu['url'] ?? null, $neu['mediaId'] ?? null, $neu['servings'] ?? null, $neu['isRecipe'] ?? null],
    [[['name' => 'Paprika', 'amount' => '2', 'category' => 'Obst']], '', '17', 99, true]);
$GLOBALS['angelegt'] = [];
pruefe('Scan ohne Zutaten oder mit falschem Datum → abgelehnt, nichts angelegt',
    [$rufe(['action' => 'saveScan', 'date' => $morgen, 'name' => 'Leer', 'items' => []])['error']['code'] ?? '',
     $rufe(['action' => 'saveScan', 'date' => 'morgen', 'name' => 'X', 'items' => [['name' => 'Y']]])['error']['code'] ?? '',
     $GLOBALS['angelegt']],
    ['invalid_payload', 'invalid_payload', []]);

// ── Grenze ─────────────────────────────────────────────────────────────────
pruefe('Unbekannte Aktion und kaputtes JSON',
    [$rufe(['action' => 'SaveScanX'])['error']['code'] ?? '', json_decode($m->AppAction('{kaputt'), true)['error']['code'] ?? ''],
    ['unknown_action', 'invalid_payload']);

// ── Gateway-Durchgang (MealPlanBridge) ─────────────────────────────────────
function MPL_AppAction(int $id, string $json): string
{
    $GLOBALS['durchgereicht'][] = [$id, json_decode($json, true)];
    return IPS\InstanceManager::getInstanceInterface($id)->AppAction($json);
}
final class BrueckeProbe
{
    use MealPlanBridge;
    public function handeln(array $body): array { return $this->MealPlanHandleAction($body); }
    public function instanzId(): int { return $this->MealPlanInstanz(); }
    protected function SendDebug(string $a, string $b, int $c): void {}
}
$b = new BrueckeProbe();
$GLOBALS['durchgereicht'] = [];
$r = $b->handeln(['action' => 'setMeal', 'date' => $heute, 'listId' => 'f2', 'boese' => 'x', 'sdwa' => 1]);
pruefe('Gateway: reicht an die Instanz, nur Felder der Weißliste',
    [$b->instanzId(), $GLOBALS['durchgereicht'][0][1] ?? null, $tagIn($r['mealplan'] ?? [], $heute)['title'] ?? null],
    [$mid, ['action' => 'setMeal', 'date' => $heute, 'listId' => 'f2'], 'Brot']);
$GLOBALS['durchgereicht'] = [];
$r = $b->handeln(['action' => 'deleteAll', 'name' => 'x']);
pruefe('Gateway: Aktion außerhalb der Weißliste kommt nicht bis zum Modul',
    [$r['error']['code'] ?? '', $GLOBALS['durchgereicht']], ['unknown_action', []]);
IPS\Kernel::reset();
pruefe('Gateway: ohne Essensplan-Instanz → mealplan null (kein Bereich)',
    $b->handeln(['action' => 'state']), ['ok' => true, 'mealplan' => null]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
