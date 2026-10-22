<?php

declare(strict_types=1);

/**
 * Listen-Umschalter der Einkaufs-Kachel (libs/ListSwitcher.php, 22.10.2026).
 *
 * Drei echte Einkaufslisten mit vollem Create() in den Symcon-Attrappen: „Zuhause"
 * (die Kachel), „Büro" (Umschalter an) und „Privat" (Umschalter aus). Geprüft wird,
 * welche Listen der Umschalter zeigt, dass Stände und Aktionen nur an freigegebene
 * Listen gehen und dass eine Änderung anderswo als `listChanged` ankommt.
 *
 *   php SymDoShoppingList/tests/ListSwitcherTest.php
 */

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
    $a = json_encode($ist, JSON_UNESCAPED_UNICODE);
    $b = json_encode($soll, JSON_UNESCAPED_UNICODE);
    $ok = $a === $b;
    if (!$ok) {
        $fehler++;
    }
    printf("%-4s %-74s%s\n", $ok ? 'OK' : 'FEHL', $name, $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/** Echte Klasse; Hook, Zeit und Push sind Attrappen, die Präfix-Funktionen gehen direkt an die Instanz. */
final class UmschalterProbe extends SymDoShoppingList
{
    /** @var array<int, list<array>> Pushes je Instanz */
    public static array $pushes = [];

    protected function RegisterHook(string $HookPath): bool { return true; }
    public function ApplyChanges(): void { IPSModuleStrict::ApplyChanges(); }
    protected function getTime(): int { return time(); }
    protected function UpdateVisualizationValue(mixed $Value)
    {
        self::$pushes[$this->InstanceID][] = json_decode((string)$Value, true);
        return true;
    }
    protected function PeerTileState(int $id): ?array
    {
        return json_decode(IPS\InstanceManager::getInstanceInterface($id)->GetTileState(), true);
    }
    protected function PeerAppCall(int $id, string $action, string $payload): array
    {
        return json_decode(IPS\InstanceManager::getInstanceInterface($id)->AppCall($action, $payload), true);
    }
    public function pWatch(): void { (new ReflectionMethod(SymDoShoppingList::class, "SwitcherWatch"))->invoke($this); }
    public function pZeilen(): array { return json_decode($this->ReadAttributeString('Items'), true) ?: []; }
}

$neu = static function (string $name, bool $umschalter): int {
    $id = IPS\ObjectManager::registerObject(1);
    IPS\InstanceManager::createInstance($id, ['ModuleID' => '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}',
        'ModuleName' => 'UmschalterProbe', 'ModuleType' => 3, 'Class' => 'UmschalterProbe']);
    IPS_SetName($id, $name);
    if (!$umschalter) {
        IPS_SetProperty($id, 'ListSwitcher', false);
        IPS_ApplyChanges($id);
    }
    return $id;
};
$heim = $neu('Zuhause', true);
$buero = $neu('Büro', true);
$privat = $neu('Privat', false);
/** @var UmschalterProbe $k */
$k = IPS\InstanceManager::getInstanceInterface($heim);
$letzter = static fn(int $id): ?array => ($p = UmschalterProbe::$pushes[$id] ?? []) ? end($p) : null;
$fehlerVon = static function (callable $f): string {
    try { $f(); return ''; } catch (\Throwable $e) { return $e->getMessage(); }
};

// ── Welche Listen zeigt der Umschalter? ───────────────────────────────────────
UmschalterProbe::$pushes = [];
IPS_RequestAction($heim, 'GetState', 0);
$stand = $letzter($heim);
pruefe('Eigener Stand: Listen nach Namen, „Privat" (Schalter aus) fehlt',
    $stand['lists'] ?? null, [['id' => $buero, 'name' => 'Büro'], ['id' => $heim, 'name' => 'Zuhause']]);
pruefe('Eigener Stand trägt listId = homeId = diese Instanz',
    [$stand['listId'] ?? 0, $stand['homeId'] ?? 0], [$heim, $heim]);

// ── Stand einer fremden Liste ─────────────────────────────────────────────────
UmschalterProbe::$pushes = [];
IPS_RequestAction($heim, 'GetState', json_encode(['hash' => '', 'list' => $buero]));
$stand = $letzter($heim);
pruefe('GetState mit list=Büro: Stand von Büro an die EIGENE Kachel, Heimat bleibt',
    [$stand['listId'] ?? 0, $stand['homeId'] ?? 0, isset($stand['stateHash']), count(UmschalterProbe::$pushes[$buero] ?? [])],
    [$buero, $heim, true, 0]);
$hash = (string)($stand['stateHash'] ?? '');
UmschalterProbe::$pushes = [];
IPS_RequestAction($heim, 'GetState', json_encode(['hash' => $hash, 'list' => $buero]));
pruefe('Bekannter Prüfwert: nichts senden', UmschalterProbe::$pushes[$heim] ?? [], []);
pruefe('Liste mit Schalter aus ist nicht erreichbar',
    $fehlerVon(static fn() => IPS_RequestAction($heim, 'GetState', json_encode(['list' => $privat]))),
    'This shopping list is not available here');
pruefe('Unbekannte ID ist nicht erreichbar',
    $fehlerVon(static fn() => IPS_RequestAction($heim, 'GetState', json_encode(['list' => 99999]))),
    'This shopping list is not available here');

// ── Aktionen in der fremden Liste ─────────────────────────────────────────────
UmschalterProbe::$pushes = [];
IPS_RequestAction($heim, 'ListCall', json_encode(['list' => $buero, 'action' => 'AddItem',
    'payload' => json_encode(['name' => 'Kaffee', 'category' => 'Getränke', 'amount' => '1'])]));
$bueroObj = IPS\InstanceManager::getInstanceInterface($buero);
$stand = $letzter($heim);
pruefe('ListCall AddItem landet in Büro, nicht in Zuhause',
    [array_column($bueroObj->pZeilen(), 'name'), array_column($k->pZeilen(), 'name')], [['Kaffee'], []]);
pruefe('Danach geht der neue Büro-Stand an die eigene Kachel',
    [$stand['listId'] ?? 0, array_column($stand['items'] ?? [], 'name')], [$buero, ['Kaffee']]);
pruefe('Nutzlast als Objekt geht auch',
    $fehlerVon(static fn() => IPS_RequestAction($heim, 'ListCall', json_encode(['list' => $buero, 'action' => 'SetHint',
        'payload' => ['text' => 'Pfand mitnehmen']]))), '');
pruefe('Aktion außerhalb der AppCall-Freigabe wird abgelehnt',
    $fehlerVon(static fn() => IPS_RequestAction($heim, 'ListCall', json_encode(['list' => $buero, 'action' => 'StoreOrderReset', 'payload' => '']))),
    'Unknown action: StoreOrderReset');
pruefe('ListCall an die gesperrte Liste wird abgelehnt, nichts landet dort',
    [$fehlerVon(static fn() => IPS_RequestAction($heim, 'ListCall', json_encode(['list' => $privat, 'action' => 'AddItem',
        'payload' => json_encode(['name' => 'Wein'])]))),
     array_column(IPS\InstanceManager::getInstanceInterface($privat)->pZeilen(), 'name')],
    ['This shopping list is not available here', []]);
pruefe('ListCall ohne fremde Liste (eigene) wird abgelehnt',
    $fehlerVon(static fn() => IPS_RequestAction($heim, 'ListCall', json_encode(['list' => $heim, 'action' => 'AddItem', 'payload' => '{}']))),
    'Invalid payload');

// ── Änderung anderswo: listChanged ────────────────────────────────────────────
// Die Attrappen stellen keine Nachrichten zu: VM_UPDATE von Hand wie der Kernel.
$k->pWatch();
UmschalterProbe::$pushes = [];
$k->MessageSink(time(), IPS_GetObjectIDByIdent('ItemCount', $buero), VM_UPDATE, [1, true, 0]);
pruefe('Büro ändert sich anderswo: Kachel bekommt nur listChanged für Büro',
    UmschalterProbe::$pushes[$heim] ?? [], [['type' => 'listChanged', 'listId' => $buero]]);
UmschalterProbe::$pushes = [];
$k->MessageSink(time(), IPS_GetObjectIDByIdent('ItemCount', $privat), VM_UPDATE, [1, true, 0]);
$k->MessageSink(time(), IPS_GetObjectIDByIdent('ItemCount', $heim), VM_UPDATE, [1, true, 0]);
pruefe('Gesperrte und eigene Liste lösen kein listChanged aus', UmschalterProbe::$pushes[$heim] ?? [], []);

// ── Schalter hier aus ─────────────────────────────────────────────────────────
IPS_SetProperty($heim, 'ListSwitcher', false);
IPS_ApplyChanges($heim);
UmschalterProbe::$pushes = [];
IPS_RequestAction($heim, 'GetState', 0);
$stand = $letzter($heim);
pruefe('Schalter aus: kein Umschalter im Stand',
    [array_key_exists('lists', $stand ?? []), array_key_exists('listId', $stand ?? [])], [false, false]);
pruefe('Schalter aus: fremde Listen nicht erreichbar',
    $fehlerVon(static fn() => IPS_RequestAction($heim, 'GetState', json_encode(['list' => $buero]))),
    'This shopping list is not available here');
IPS_SetProperty($heim, 'ListSwitcher', true);
IPS_ApplyChanges($heim);
IPS_SetProperty($buero, 'ListSwitcher', false);
IPS_ApplyChanges($buero);
UmschalterProbe::$pushes = [];
IPS_RequestAction($heim, 'GetState', 0);
pruefe('Nur eine Liste übrig: kein Umschalter', array_key_exists('lists', $letzter($heim) ?? []), false);

ob_end_flush();
printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
