<?php

declare(strict_types=1);

/**
 * Routinen: Kachel-Push nur bei echter Aenderung, gebuendelt (26.09.2026).
 *
 * Die Routinen abonnieren die drei Zaehler JEDER ToDo-Liste und bauten bei
 * jeder Meldung neu auf — den vollen Zustand aller Listen, die Gesichter aus
 * dem Gateway — und schoben ihn an alle Kacheln. Eine Liste meldet dreimal je
 * Speichern und dreimal je Abgleich, meist ohne neuen Wert.
 *
 * Zwei Faelle, die dabei nicht verloren gehen duerfen:
 *  - Umbenennen oder Zuweisen einer Aufgabe laesst die Zaehler gleich, aendert
 *    aber die Heute-Aufgaben (die Revision der Liste verraet es);
 *  - die Kachel zeigt einen Haken sofort an — gilt er nicht, ist ein
 *    UNVERAENDERTER Stand die Korrektur (Korrekturzustand).
 *
 *   php Routines/tests/KachelPushTest.php
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

/** Eine ToDo-Liste: drei Zaehler; ToggleDone scheitert auf Wunsch. */
final class ToDoAttrappe extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterVariableInteger('OpenTasks', 'Open', '', 1);
        $this->RegisterVariableInteger('OverdueTasks', 'Overdue', '', 2);
        $this->RegisterVariableInteger('DueTodayTasks', 'Today', '', 3);
    }
    public function RequestAction(string $Ident, mixed $Value): void
    {
        throw new Exception('Unknown item');
    }
    protected function getTime(): int { return time(); }
}

final class RoutinenProbe extends SymDoRoutines
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

$GLOBALS['todo'] = ['revision' => 5, 'items' => [
    ['id' => 11, 'title' => 'Hausaufgaben', 'done' => false, 'due' => time() - 60, 'dueAllDay' => false, 'assignedTo' => ['kind1']],
]];
$GLOBALS['todoLesen'] = 0;
function TDL_GetAppState(int $id): string
{
    $GLOBALS['todoLesen']++;
    return (string)json_encode(['revision' => $GLOBALS['todo']['revision'], 'state' => ['items' => $GLOBALS['todo']['items']]]);
}
function TDL_GetAppRevision(int $id): int
{
    return $GLOBALS['todo']['revision'];
}

function instanz(string $klasse, string $guid): int
{
    $iid = IPS\ObjectManager::registerObject(1);
    ob_start();
    IPS\InstanceManager::createInstance($iid, ['ModuleID' => $guid, 'ModuleName' => $klasse, 'ModuleType' => 3, 'Class' => $klasse]);
    ob_end_clean();
    return $iid;
}

$liste = instanz('ToDoAttrappe', '{E0E38D9B-31BC-4F5E-A6CA-91A2A60C7C46}');
$offen = IPS_GetObjectIDByIdent('OpenTasks', $liste);
$rid = instanz('RoutinenProbe', '{00000000-0000-0000-0000-00000000A0B1}');
/** @var RoutinenProbe $r */
$r = IPS\InstanceManager::getInstanceInterface($rid);
IPS_SetProperty($rid, 'Routines', json_encode([['id' => 'r1', 'name' => 'Morgen', 'emoji' => '', 'memberId' => 'kind1', 'von' => '', 'bis' => '']]));
IPS_SetProperty($rid, 'Steps', json_encode([['routine' => 'r1', 'emoji' => '', 'text' => 'Zaehne putzen', 'coins' => 5]]));
$r->gesendet = [];
IPS_ApplyChanges($rid);
pruefe('ApplyChanges: der Stand geht hinaus, mit der Heute-Aufgabe des Kindes',
    [count($r->gesendet), array_column($r->gesendet[0]['todos'] ?? [], 'title')], [1, ['Hausaufgaben']]);

// Abgleich der Liste ohne Aenderung: drei Meldungen ohne neuen Wert, Revision gleich.
$r->gesendet = [];
$lesen = $GLOBALS['todoLesen'];
foreach ([0, 1, 2] as $unused) {
    $r->MessageSink(time(), $offen, VM_UPDATE, [1, false, 1, time()]);
}
pruefe('Abgleich ohne Aenderung → kein Aufbau, nichts gesendet',
    [$GLOBALS['todoLesen'] - $lesen, count($r->gesendet)], [0, 0]);

// Umbenannt: Zaehler gleich, Revision neu → die Aenderung geht hinaus.
$GLOBALS['todo']['items'][0]['title'] = 'Mathe-Hausaufgaben';
$GLOBALS['todo']['revision']++;
$r->MessageSink(time(), $offen, VM_UPDATE, [1, false, 1, time()]);
pruefe('Aufgabe umbenannt (Zaehler gleich, Revision neu) → der neue Titel geht hinaus',
    [count($r->gesendet), array_column($r->gesendet[0]['todos'] ?? [], 'title')], [1, ['Mathe-Hausaufgaben']]);

$r->gesendet = [];
$lesen = $GLOBALS['todoLesen'];
$r->MessageSink(time(), $offen, VM_UPDATE, [2, true, 1, time()]);
pruefe('Neuer Zaehlerwert ohne sichtbare Folge → Aufbau, aber nichts gesendet',
    [$GLOBALS['todoLesen'] - $lesen, count($r->gesendet)], [1, 0]);

// ── Korrekturzustand: Haken aus der Kachel ─────────────────────────────────
IPS_RequestAction($rid, 'Check', json_encode(['routine' => 'r1', 'step' => 99, 'done' => true]));
pruefe('Haken auf einen Schritt, den es nicht gibt → der UNVERAENDERTE Stand geht hinaus',
    [count($r->gesendet), $r->gesendet[0]['routines'][0]['steps'][0]['done'] ?? null], [1, false]);
$r->gesendet = [];
IPS_RequestAction($rid, 'TodoCheck', json_encode(['list' => $liste, 'id' => 11, 'done' => true]));
pruefe('Heute-Aufgabe abhaken scheitert in der Liste → der unveraenderte Stand geht hinaus',
    [count($r->gesendet), array_column($r->gesendet[0]['todos'] ?? [], 'title')], [1, ['Mathe-Hausaufgaben']]);
$r->gesendet = [];
$r->MessageSink(time(), $offen, VM_UPDATE, [2, false, 2, time()]);
pruefe('Im Korrekturzustand geht auch eine Meldung ohne Aenderung hinaus', count($r->gesendet), 1);
$r->pPuffer(KachelPush::PUFFER_AKTION, KachelPush::Aktion(microtime(true) - 2.0));
$r->gesendet = [];
$r->MessageSink(time(), $offen, VM_UPDATE, [2, false, 2, time()]);
$r->MessageSink(time(), $offen, VM_UPDATE, [2, false, 2, time()]);
pruefe('Ab 1,5 s nach der Aktion: noch EINE Nachricht, dann wieder gefiltert',
    [count($r->gesendet), $r->pPuffer(KachelPush::PUFFER_AKTION)], [1, '']);

$r->gesendet = [];
IPS_RequestAction($rid, 'Check', json_encode(['routine' => 'r1', 'step' => 0, 'done' => true]));
pruefe('Gueltiger Haken → der neue Stand', $r->gesendet[0]['routines'][0]['steps'][0]['done'] ?? null, true);

// Ohne Routine mit Kind haengen die Heute-Aufgaben an keiner Liste. Der Haken
// oben liegt da lange zurueck: der Aufbau in ApplyChanges beendet seinen Korrekturzustand.
$r->pPuffer(KachelPush::PUFFER_AKTION, KachelPush::Aktion(microtime(true) - 2.0));
IPS_SetProperty($rid, 'Routines', json_encode([['id' => 'r1', 'name' => 'Morgen', 'emoji' => '', 'memberId' => '', 'von' => '', 'bis' => '']]));
IPS_ApplyChanges($rid);
$r->gesendet = [];
$GLOBALS['todo']['revision']++;
$lesen = $GLOBALS['todoLesen'];
$r->MessageSink(time(), $offen, VM_UPDATE, [2, false, 2, time()]);
pruefe('Ohne Kind: eine Listenaenderung ist kein Grund zum Aufbau',
    [$GLOBALS['todoLesen'] - $lesen, count($r->gesendet)], [0, 0]);

$quelle = (string)file_get_contents(__DIR__ . '/../module.php');
pruefe('`now` zaehlt nicht mit (die Kachel geht nach der eigenen Uhr)',
    str_contains($quelle, "KachelPush::Pruefwert(\$daten, ['now'])"), true);
$kachel = (string)file_get_contents(__DIR__ . '/../module.html');
pruefe('… und die Kachel liest `now` wirklich nicht', preg_match('/zustand\.now\b/', $kachel), 0);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
