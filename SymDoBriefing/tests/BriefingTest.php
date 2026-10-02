<?php

declare(strict_types=1);

/**
 * Briefing als Kachel (02.10.2026).
 *
 * Die Kachel ist die Web-App mit nur der Uebersicht und dem unsichtbaren
 * Schalter nurBriefing. Text und Ton reisen ueber das AiCall-Relay;
 * SDBR_PlayBriefing stoesst die Wiedergabe in genau dieser Kachel an.
 *
 *   php SymDoBriefing/tests/BriefingTest.php
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

final class BriefingProbe extends SymDoBriefing
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
$kid = instanz('BriefingProbe', '{568937AB-7F27-40DE-9B30-CE24C04107C5}');
/** @var BriefingProbe $k */
$k = IPS\InstanceManager::getInstanceInterface($kid);
$k->gesendet = [];
IPS_RequestAction($kid, 'GetState', 0);
pruefe('Ohne Gateway: gatewayAvailable false', $k->gesendet[0]['gatewayAvailable'] ?? null, false);

$gw = instanz('GatewayAttrappe', GW_GUID);
$l1 = instanz('ListenAttrappe', TODO_GUID);
$k->gesendet = [];
IPS_RequestAction($kid, 'GetState', 0);
$z = $k->gesendet[0] ?? [];
pruefe('Zustand: nur die Uebersicht, Schalter nurBriefing gesetzt',
    [array_keys(array_filter($z['tabs'] ?? [])), $z['nurBriefing'] ?? null], [['dashboard'], true]);
pruefe('Zustand: Gateway da, Mitglieder fuer die Ueberschrift, keine Listen',
    [$z['gatewayAvailable'] ?? null, array_column($z['users'] ?? [], 'name'), $z['instances'] ?? null], [true, ['Anna'], []]);

$k->gesendet = [];
IPS_RequestAction($kid, 'AiCall', json_encode(['path' => '/briefing', 'payload' => [], 'txn' => 'b1']));
pruefe('AiCall /briefing geht ans Gateway, Antwort kommt EINMAL zurueck',
    [$GLOBALS['gwAufrufe'], count($k->gesendet), $k->gesendet[0]['txn'] ?? null], [['/briefing'], 1, 'b1']);

$k->gesendet = [];
pruefe('PlayBriefing meldet true', $k->PlayBriefing(), true);
IPS_RequestAction($kid, 'PlayBriefing', 0);
pruefe('PlayBriefing (Funktion und RequestAction) schickt briefingPlay',
    array_column($k->gesendet, 'type'), ['briefingPlay', 'briefingPlay']);

$k->gesendet = [];
IPS_RequestAction($kid, 'Call', json_encode(['instanceID' => $l1, 'action' => 'AddItem', 'payload' => ['title' => 'x'], 'txn' => 't']));
IPS_RequestAction($kid, 'ReportVisuTheme', '{}');
pruefe('Call und ReportVisuTheme werden still geschluckt, nichts erreicht eine Liste',
    [count($k->gesendet), $GLOBALS['tdl']], [0, []]);

$html = $k->GetVisualizationTile();
pruefe('Kachel endet mit dem Anfangszustand',
    (bool)preg_match('/<script>handleMessage\(\{"type":"state".*"nurBriefing":true.*\}\);<\/script>$/s', $html), true);
pruefe('Kachel ist die Web-App mit briefingAllein', str_contains($html, 'function briefingAllein') || str_contains($html, '/hook/lists/webapp/app.js'), true);

$form = json_decode($k->GetConfigurationForm(), true);
pruefe('Formular: keine Eigenschaften, Abspielknopf', [array_values(array_filter(array_column($form['elements'] ?? [], 'name'))),
    str_contains(json_encode($form['actions'] ?? []), 'PlayBriefing')], [[], true]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
