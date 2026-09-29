<?php

declare(strict_types=1);

/**
 * Essensplan: Kachel-Push nur bei echter Aenderung (26.09.2026).
 *
 * Der Plan hing an JEDER Aktualisierung der zwei Zaehler der Einkaufsliste —
 * jedes Speichern dort baute den Wochen-Stand neu (voller Listen-Zustand, bis zu
 * vierzehn Miniaturen) und schob ihn an alle Kacheln, obwohl er an den
 * Favoritenlisten haengt und nicht an den Artikeln.
 *
 *   php SymDoMealPlan/tests/KachelPushTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../module.php';

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

final class EinkaufAttrappe extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterVariableInteger('ItemCount', 'Item Count', '', 1);
        $this->RegisterVariableInteger('LastUsed', 'Last Used', '', 2);
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
    public function pPuffer(string $name, ?string $wert = null): string
    {
        if ($wert !== null) {
            $this->SetBuffer($name, $wert);
        }
        return $this->GetBuffer($name);
    }
    public function typen(): array { return array_map(static fn($m) => $m['type'] ?? '?', $this->gesendet); }
}

$GLOBALS['favoriten'] = [['id' => 'f1', 'name' => 'Lasagne', 'items' => [['name' => 'Nudeln']], 'mediaId' => 0]];
$GLOBALS['slRufe'] = 0;
function SL_GetAppState(int $id): string
{
    $GLOBALS['slRufe']++;
    return (string)json_encode(['revision' => 1, 'state' => ['favoriteLists' => $GLOBALS['favoriten']]]);
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
$zaehler = IPS_GetObjectIDByIdent('ItemCount', $sl);
$mid = instanz('EssensplanProbe', '{00000000-0000-0000-0000-00000000E55E}');
/** @var EssensplanProbe $m */
$m = IPS\InstanceManager::getInstanceInterface($mid);
IPS_SetProperty($mid, 'ShoppingListInstanceID', $sl);
$m->gesendet = [];
IPS_ApplyChanges($mid);
pruefe('ApplyChanges: der Wochen-Stand geht hinaus (Erstaufbau)',
    [$m->typen(), $m->gesendet[0]['favorites'][0]['name'] ?? null], [['state'], 'Lasagne']);

$m->gesendet = [];
$rufe = $GLOBALS['slRufe'];
$m->MessageSink(time(), $zaehler, VM_UPDATE, [3, false, 3, time()]);
pruefe('Zaehler ohne neuen Wert (Umbenennen im Einkauf) → kein Aufbau, nichts gesendet',
    [$GLOBALS['slRufe'] - $rufe, count($m->gesendet)], [0, 0]);
$m->MessageSink(time(), $zaehler, VM_UPDATE, [4, true, 3, time()]);
pruefe('Neuer Zaehlerwert → ein Aufbau, aber gleicher Stand → nichts',
    [$GLOBALS['slRufe'] - $rufe, count($m->gesendet)], [1, 0]);
$GLOBALS['favoriten'][0]['name'] = 'Lasagne al forno';
$m->MessageSink(time(), $zaehler, VM_UPDATE, [5, true, 4, time()]);
pruefe('Favorit umbenannt → der neue Stand geht hinaus',
    [count($m->gesendet), $m->gesendet[0]['favorites'][0]['name'] ?? null], [1, 'Lasagne al forno']);

// Datumswechsel: „heute" rueckt — das naechste Ereignis zaehlt auch ohne neuen Wert.
$m->gesendet = [];
$rufe = $GLOBALS['slRufe'];
$m->pPuffer('KachelPushTag', '2000-01-01');
$m->MessageSink(time(), $zaehler, VM_UPDATE, [5, false, 5, time()]);
$nachWechsel = $GLOBALS['slRufe'] - $rufe;
$m->MessageSink(time(), $zaehler, VM_UPDATE, [5, false, 5, time()]);
pruefe('Nach einem Datumswechsel baut das naechste Ereignis auf, danach wieder nicht',
    [$nachWechsel, $GLOBALS['slRufe'] - $rufe, $m->pPuffer('KachelPushTag')], [1, 1, date('Y-m-d')]);

// Antworten sind keine Staende — sie gehen immer hinaus.
$m->gesendet = [];
IPS_RequestAction($mid, 'MealDetail', date('Y-m-d'));
IPS_RequestAction($mid, 'MealDetail', date('Y-m-d'));
IPS_RequestAction($mid, 'WeekDetail', date('Y-m-d'));
pruefe('Antworten (Detail, Woche) werden nie gefiltert', $m->typen(), ['mealDetail', 'mealDetail', 'weekDetail']);

$m->gesendet = [];
IPS_RequestAction($mid, 'GetState', 0);
pruefe('GetState (ausdrueckliche Anfrage) sendet auch den unveraenderten Stand', $m->typen(), ['state']);

// Die Kachel zeigt ein Gericht NICHT vorab an — sie wartet auf den Stand.
$m->gesendet = [];
IPS_RequestAction($mid, 'SetMeal', json_encode(['date' => 'kein-datum', 'listId' => 'f1']));
pruefe('Ungueltiges Gericht: Stand unveraendert → nichts (die Kachel zeigt nichts vorab)', count($m->gesendet), 0);
IPS_RequestAction($mid, 'SetMeal', json_encode(['date' => date('Y-m-d'), 'listId' => 'f1']));
$heute = [];
foreach ($m->gesendet[0]['weeks'][0]['days'] ?? [] as $tag) {
    if ($tag['today']) {
        $heute = $tag;
    }
}
pruefe('Gericht fuer heute gesetzt → der neue Stand geht hinaus',
    [count($m->gesendet), $heute['title'] ?? null], [1, 'Lasagne al forno']);

$quelle = (string)file_get_contents(__DIR__ . '/../module.php');
pruefe('Stand ueber KachelSenden, Antworten weiter ueber Push()',
    [str_contains($quelle, '$this->KachelSenden('), substr_count($quelle, 'UpdateVisualizationValue(')], [true, 1]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
