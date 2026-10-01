<?php

declare(strict_types=1);

/**
 * KI-Eingang als Kachel (01.10.2026).
 *
 * Die Kachel ist die Web-App mit nur dem KI-Bereich. Zwei Wege gehen hinaus:
 *  - das AiCall-Relay zum Gateway (Vorschlaege, Termine, Notizen, Hausaufgaben),
 *  - der Call an eine ToDo-Liste — NUR AddItem und NUR an die Ziel-Listen.
 *
 *   php SymDoAIInbox/tests/AIInboxTest.php
 */

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
    printf("%-4s %-66s%s\n", $ok ? 'OK' : 'FEHL', $name, $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

const GW_GUID   = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';
const TODO_GUID = '{E0E38D9B-31BC-4F5E-A6CA-91A2A60C7C46}';
const SL_GUID   = '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}';

/** Gateway: beantwortet AiTileRequest synchron ueber das AiResult der Kachel. */
final class GatewayAttrappe extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('AiEnabled', true);
    }
    public function RequestAction(string $Ident, mixed $Value): void
    {
        $r = json_decode((string)$Value, true);
        $GLOBALS['gwAufrufe'][] = $r['path'] ?? '';
        IPS_RequestAction((int)$r['sdwa'], 'AiResult', json_encode(['txn' => $r['txn'], 'status' => 200, 'json' => ['ok' => true, 'proposals' => []]]));
    }
}

final class ListenAttrappe extends IPSModuleStrict
{
}

final class InboxProbe extends SymDoAIInbox
{
    public array $gesendet = [];
    protected function UpdateVisualizationValue(mixed $Value)
    {
        $this->gesendet[] = json_decode((string)$Value, true);
        return true;
    }
}

$GLOBALS['gwAufrufe'] = [];
$GLOBALS['tdl'] = [];
function TGW_GetUsersForTile(int $id): string
{
    return (string)json_encode([['id' => 'u1', 'name' => 'Anna', 'avatar' => '']]);
}
function TDL_AppCall(int $id, string $action, string $payload): string
{
    $GLOBALS['tdl'][] = [$id, $action, json_decode($payload, true)];
    return (string)json_encode(['ok' => true, 'revision' => 3, 'kind' => 'todo', 'state' => ['items' => []]]);
}

function instanz(string $klasse, string $guid): int
{
    $iid = IPS\ObjectManager::registerObject(1);
    ob_start();
    IPS\InstanceManager::createInstance($iid, ['ModuleID' => $guid, 'ModuleName' => $klasse, 'ModuleType' => 3, 'Class' => $klasse]);
    ob_end_clean();
    return $iid;
}

// ── Ohne Gateway ───────────────────────────────────────────────────────────
$kid = instanz('InboxProbe', '{62F42E2D-BE0A-4490-80C2-CF54355E94C2}');
/** @var InboxProbe $k */
$k = IPS\InstanceManager::getInstanceInterface($kid);
$k->gesendet = [];
IPS_RequestAction($kid, 'GetState', 0);
pruefe('Ohne Gateway: gatewayAvailable false, aiEnabled false',
    [$k->gesendet[0]['gatewayAvailable'] ?? null, $k->gesendet[0]['aiEnabled'] ?? null], [false, false]);
$k->gesendet = [];
IPS_RequestAction($kid, 'AiCall', json_encode(['path' => '/mail/proposals', 'payload' => [], 'txn' => 't0']));
pruefe('Ohne Gateway: AiCall antwortet sofort mit ai_unavailable',
    [$k->gesendet[0]['txn'] ?? null, $k->gesendet[0]['json']['error']['code'] ?? null], ['t0', 'ai_unavailable']);

// ── Mit Gateway und zwei ToDo-Listen, dazu eine Einkaufsliste ──────────────
$gw  = instanz('GatewayAttrappe', GW_GUID);
$l1  = instanz('ListenAttrappe', TODO_GUID);
$l2  = instanz('ListenAttrappe', TODO_GUID);
$ein = instanz('ListenAttrappe', SL_GUID);

$k->gesendet = [];
IPS_RequestAction($kid, 'GetState', 0);
$z = $k->gesendet[0] ?? [];
pruefe('Zustand nennt NUR den KI-Bereich',
    array_keys(array_filter($z['tabs'] ?? [])), ['ki']);
pruefe('Zustand: Gateway da, KI an, Mitglieder vom Gateway',
    [$z['gatewayAvailable'] ?? null, $z['aiEnabled'] ?? null, array_column($z['users'] ?? [], 'name')], [true, true, ['Anna']]);
pruefe('Zustand: beide ToDo-Listen als Ziel, keine Einkaufsliste',
    [array_column($z['instances'] ?? [], 'id'), array_unique(array_column($z['instances'] ?? [], 'kind'))], [[min($l1, $l2), max($l1, $l2)], ['todo']]);
pruefe('Zustand traegt einen Pruefwert', strlen((string)($z['stateHash'] ?? '')) > 0, true);

$k->gesendet = [];
IPS_RequestAction($kid, 'AiCall', json_encode(['path' => '/mail/proposals', 'payload' => ['withTaken' => true], 'txn' => 't1']));
pruefe('AiCall geht ans Gateway, die Antwort kommt EINMAL zurueck',
    [$GLOBALS['gwAufrufe'], count($k->gesendet), $k->gesendet[0]['txn'] ?? null, $k->gesendet[0]['json']['ok'] ?? null],
    [['/mail/proposals'], 1, 't1', true]);

// ── Call: nur AddItem an Ziel-Listen ───────────────────────────────────────
$k->gesendet = [];
IPS_RequestAction($kid, 'Call', json_encode(['instanceID' => $l2, 'action' => 'AddItem', 'payload' => ['title' => 'Elternabend'], 'txn' => 'tx1']));
pruefe('AddItem an eine ToDo-Liste wird weitergereicht',
    [$GLOBALS['tdl'], $k->gesendet[0]['type'] ?? null, $k->gesendet[0]['ok'] ?? null, $k->gesendet[0]['txn'] ?? null],
    [[[$l2, 'AddItem', ['title' => 'Elternabend']]], 'instanceState', true, 'tx1']);

$GLOBALS['tdl'] = [];
$abgewiesen = [];
foreach ([[$l1, 'DeleteItem'], [$l1, 'ToggleDone'], [$ein, 'AddItem'], [$gw, 'AddItem'], [0, 'AddItem']] as [$ziel, $aktion]) {
    $k->gesendet = [];
    IPS_RequestAction($kid, 'Call', json_encode(['instanceID' => $ziel, 'action' => $aktion, 'payload' => ['title' => 'x'], 'txn' => 'tx']));
    $abgewiesen[] = [$k->gesendet[0]['ok'] ?? null, $k->gesendet[0]['error'] ?? null];
}
pruefe('Andere Aktionen und andere Instanzen werden abgewiesen, nichts erreicht eine Liste',
    [$abgewiesen, $GLOBALS['tdl']], [array_fill(0, 5, [false, 'invalid_call']), []]);

// ── Feste Zielliste ────────────────────────────────────────────────────────
IPS_SetProperty($kid, 'TodoListID', $l1);
IPS_ApplyChanges($kid);
$k->gesendet = [];
IPS_RequestAction($kid, 'GetState', 0);
pruefe('Feste Zielliste: nur sie steht im Zustand',
    array_column($k->gesendet[0]['instances'] ?? [], 'id'), [$l1]);
$k->gesendet = [];
IPS_RequestAction($kid, 'Call', json_encode(['instanceID' => $l2, 'action' => 'AddItem', 'payload' => ['title' => 'x'], 'txn' => 'tx2']));
pruefe('Feste Zielliste: die andere Liste ist kein Ziel mehr',
    [$k->gesendet[0]['ok'] ?? null, $GLOBALS['tdl']], [false, []]);

IPS_SetProperty($kid, 'TodoListID', 99999);
IPS_ApplyChanges($kid);
$k->gesendet = [];
IPS_RequestAction($kid, 'GetState', 0);
pruefe('Geloeschte Zielliste: keine Liste (statt still in eine andere)',
    $k->gesendet[0]['instances'] ?? null, []);

// ── Sonstiges ──────────────────────────────────────────────────────────────
IPS_SetProperty($gw, 'AiEnabled', false);
IPS_ApplyChanges($gw);
$k->gesendet = [];
IPS_RequestAction($kid, 'GetState', 0);
pruefe('KI im Gateway aus → aiEnabled false', $k->gesendet[0]['aiEnabled'] ?? null, false);

$k->gesendet = [];
IPS_RequestAction($kid, 'ReportVisuTheme', '{}');
pruefe('ReportVisuTheme wird still angenommen', count($k->gesendet), 0);

$html = $k->GetVisualizationTile();
pruefe('Kachel endet mit dem Anfangszustand',
    (bool)preg_match('/<script>handleMessage\(\{"type":"state".*\}\);<\/script>$/s', $html), true);

$form = json_decode($k->GetConfigurationForm(), true);
pruefe('Formular: Mitglied und Zielliste',
    array_values(array_filter(array_column($form['elements'] ?? [], 'name'))), ['DefaultUserID', 'TodoListID']);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
