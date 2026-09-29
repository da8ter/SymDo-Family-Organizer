<?php

declare(strict_types=1);

/**
 * Kachel-Push nur bei echter Aenderung (26.09.2026): die Regeln aus
 * libs/KachelPush.php und ihr Einsatz in der ToDo-Uebersicht.
 *
 * Die Uebersicht schob bei JEDER Aktualisierung der drei Zaehler einer ToDo-
 * Liste den Stand an alle Kacheln — die Liste setzt sie bei jedem Speichern
 * und nach jedem Abgleich, meist ohne neuen Wert.
 *
 *   php SymDoToDoOverview/tests/KachelPushTest.php
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

// ── Regel 1: VM_UPDATE nur mit echter Aenderung ────────────────────────────
pruefe('Beachten: geaendert ja, unveraendert nein, ohne $Data[1] ja',
    [KachelPush::Beachten([5, true, 4], ''), KachelPush::Beachten([5, false, 5], ''), KachelPush::Beachten([5], '')],
    [true, false, true]);
pruefe('Beachten: nur ein echtes true zaehlt (1 ist kein true)', KachelPush::Beachten([5, 1, 4], ''), false);
pruefe('Beachten: im Korrekturzustand auch unveraendert',
    KachelPush::Beachten([5, false, 5], KachelPush::Aktion(1000.0)), true);

// ── Pruefwert ──────────────────────────────────────────────────────────────
$stand = ['type' => 'state', 'open' => 3, 'now' => 1758880000];
pruefe('Pruefwert: 16 Hexzeichen, stateHash zaehlt nicht mit',
    [preg_match('/^[0-9a-f]{16}$/', KachelPush::Pruefwert($stand)),
     KachelPush::Pruefwert($stand) === KachelPush::Pruefwert($stand + ['stateHash' => 'x'])], [1, true]);
pruefe('Pruefwert: ein geaenderter Wert ergibt einen anderen',
    KachelPush::Pruefwert(['open' => 4] + $stand) !== KachelPush::Pruefwert($stand), true);
pruefe('Pruefwert: $ohne laesst ein Feld weg (Routinen: now)',
    KachelPush::Pruefwert(['now' => 1] + $stand, ['now']) === KachelPush::Pruefwert(['now' => 2] + $stand, ['now']), true);
pruefe('Pruefwert: $minuten zaehlt minutengenau (VRR: now)',
    [KachelPush::Pruefwert(['now' => 120] + $stand, [], ['now']) === KachelPush::Pruefwert(['now' => 179] + $stand, [], ['now']),
     KachelPush::Pruefwert(['now' => 179] + $stand, [], ['now']) === KachelPush::Pruefwert(['now' => 180] + $stand, [], ['now'])],
    [true, false]);

// ── Regel 2: identische Nutzlast nicht erneut ──────────────────────────────
$p1 = KachelPush::Pruefwert($stand);
$p2 = KachelPush::Pruefwert(['open' => 9] + $stand);
pruefe('Entscheiden: nichts bekannt (Erstaufbau) → senden, Pruefwert merken',
    KachelPush::Entscheiden($p1, '', '', 1000.0), [true, $p1, '']);
pruefe('Entscheiden: gleich dem zuletzt gesendeten → nichts', KachelPush::Entscheiden($p1, $p1, '', 1000.0), [false, $p1, '']);
pruefe('Entscheiden: anders → senden', KachelPush::Entscheiden($p2, $p1, '', 1000.0), [true, $p2, '']);

// ── Regel 3: Korrekturzustand nach einer Aktion aus der Kachel ─────────────
$aktion = KachelPush::Aktion(1000.0);
pruefe('Korrektur: unveraenderter Stand 0,2 s nach der Aktion geht hinaus, Zustand bleibt',
    KachelPush::Entscheiden($p1, $p1, $aktion, 1000.2), [true, $p1, $aktion]);
pruefe('Korrektur: 1,49 s danach ebenso', KachelPush::Entscheiden($p1, $p1, $aktion, 1001.49), [true, $p1, $aktion]);
pruefe('Korrektur: die erste Nachricht ab 1,5 s geht hinaus und beendet ihn',
    KachelPush::Entscheiden($p1, $p1, $aktion, 1001.5), [true, $p1, '']);
pruefe('Danach gilt Regel 2 wieder', KachelPush::Entscheiden($p1, $p1, '', 1002.0), [false, $p1, '']);
pruefe('Rueckwaerts gestellte Uhr beendet ihn (sonst liefe er bis zum Aufholen)',
    KachelPush::Entscheiden($p1, $p1, $aktion, 990.0), [true, $p1, '']);
/* Gefunden im Pruefstand: auf Millisekunden gerundet lag die Aktion bis zu einer
   halben Millisekunde „in der Zukunft", und die Nachricht direkt danach beendete
   den Zustand als zurueckgestellte Uhr. */
$gleich = KachelPush::Aktion(1000.0004);
pruefe('Aktion und Nachricht in derselben Millisekunde: der Zustand bleibt',
    KachelPush::Entscheiden($p1, $p1, $gleich, 1000.0003)[2], $gleich);
pruefe('Die Aktion traegt Mikrosekunden', KachelPush::Aktion(1000.0004), '1000.000400');
pruefe('Unlesbarer Puffer beendet ihn ebenfalls', KachelPush::Entscheiden($p1, $p1, 'kaputt', 1000.0), [true, $p1, '']);

// ── Die ToDo-Uebersicht mit den Symcon-Attrappen ───────────────────────────

/** Die Quelle: eine ToDo-Liste mit ihren drei Zaehlern. */
final class ToDoListeAttrappe extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterVariableInteger('OpenTasks', 'Open', '', 1);
        $this->RegisterVariableInteger('OverdueTasks', 'Overdue', '', 2);
        $this->RegisterVariableInteger('DueTodayTasks', 'Today', '', 3);
    }
    protected function getTime(): int { return time(); }
}

/** Die echte Klasse; mitgeschrieben wird nur, was an die Kachel geht. */
final class ToDoUebersichtProbe extends SymDoToDoOverview
{
    public array $gesendet = [];
    protected function UpdateVisualizationValue(mixed $Value)
    {
        $this->gesendet[] = json_decode((string)$Value, true);
        return true;
    }
    protected function getTime(): int { return time(); }
}

function instanz(string $klasse, string $guid): int
{
    $iid = IPS\ObjectManager::registerObject(1);
    ob_start();
    IPS\InstanceManager::createInstance($iid, ['ModuleID' => $guid, 'ModuleName' => $klasse, 'ModuleType' => 3, 'Class' => $klasse]);
    ob_end_clean();
    return $iid;
}

$liste = instanz('ToDoListeAttrappe', '{E0E38D9B-31BC-4F5E-A6CA-91A2A60C7C46}');
$offen = IPS_GetObjectIDByIdent('OpenTasks', $liste);
SetValue($offen, 3);
$uid = instanz('ToDoUebersichtProbe', '{00000000-0000-0000-0000-00000000D0E1}');
/** @var ToDoUebersichtProbe $u */
$u = IPS\InstanceManager::getInstanceInterface($uid);
IPS_SetProperty($uid, 'ToDoListInstanceID', $liste);
$u->gesendet = [];
IPS_ApplyChanges($uid);
pruefe('ApplyChanges: der Stand geht hinaus (Erstaufbau)', [count($u->gesendet), $u->gesendet[0]['open'] ?? null], [1, 3]);

$u->gesendet = [];
$u->MessageSink(time(), $offen, VM_UPDATE, [3, false, 3, time()]);
pruefe('Aktualisierung ohne neuen Wert (Abgleich, Speichern) → nichts', count($u->gesendet), 0);

SetValue($offen, 4);
$u->MessageSink(time(), $offen, VM_UPDATE, [4, true, 3, time()]);
pruefe('Neuer Zaehlerwert → genau eine Nachricht mit dem neuen Wert', [count($u->gesendet), $u->gesendet[0]['open'] ?? null], [1, 4]);

$u->gesendet = [];
$u->MessageSink(time(), $offen, VM_UPDATE, [4, true, 3, time()]);
pruefe('Dieselbe Meldung noch einmal (zweiter Zaehler, gleicher Stand) → nichts', count($u->gesendet), 0);
$u->MessageSink(time(), $offen, VM_UPDATE, [4]);
pruefe('Ohne $Data[1] beachtet, aber gleicher Stand → nichts', count($u->gesendet), 0);

IPS_ApplyChanges($uid);
pruefe('Erneuter Erstaufbau sendet auch den unveraenderten Stand', count($u->gesendet), 1);

// ── Die Stelle im Modul ────────────────────────────────────────────────────
$quelle = (string)file_get_contents(__DIR__ . '/../module.php');
pruefe('MessageSink filtert ueber KachelVmBeachten, gesendet wird ueber KachelSenden',
    [str_contains($quelle, 'if ($this->KachelVmBeachten($Data))'), str_contains($quelle, '$this->KachelSenden('),
     substr_count($quelle, 'UpdateVisualizationValue(')], [true, true, 0]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
