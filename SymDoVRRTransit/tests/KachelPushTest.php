<?php

declare(strict_types=1);

/**
 * VRR-Kachel: der Herzschlag schickt den Pruefwert mit (26.09.2026).
 *
 * Jede offene Kachel meldet sich jede Minute (GetState), und jede Meldung
 * schob den ganzen Stand an ALLE offenen Kacheln — dazu der eigene Abruf-Takt.
 * Jetzt schickt die Kachel den Pruefwert ihres Stands mit (KachelStand), und
 * der Takt sendet nur, was sich geaendert hat (KachelPush). Die Minuten
 * duerfen dabei nicht stehen bleiben: `now` zaehlt minutengenau mit.
 *
 *   php SymDoVRRTransit/tests/KachelPushTest.php
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

final class VrrProbe extends SymDoVRRTransit
{
    public array $gesendet = [];
    protected function UpdateVisualizationValue(mixed $Value)
    {
        $this->gesendet[] = json_decode((string)$Value, true);
        return true;
    }
    protected function getTime(): int { return time(); }
    public function pPruefwert(array $daten): string
    {
        return (string)(new ReflectionMethod(SymDoVRRTransit::class, 'MitPruefwert'))->invoke($this, $daten)['stateHash'];
    }
}

$iid = IPS\ObjectManager::registerObject(1);
ob_start();
IPS\InstanceManager::createInstance($iid, ['ModuleID' => '{00000000-0000-0000-0000-00000000F1A2}',
    'ModuleName' => 'VrrProbe', 'ModuleType' => 3, 'Class' => 'VrrProbe']);
ob_end_clean();
/** @var VrrProbe $v */
$v = IPS\InstanceManager::getInstanceInterface($iid);
/* Nicht an der Minutengrenze starten: dann wechselte `now` mitten im Pruefstand. */
while ((int)date('s') > 50) {
    usleep(200000);
}
$v->gesendet = [];
IPS_ApplyChanges($iid);
$stand = $v->gesendet[0] ?? [];
pruefe('ApplyChanges: der Stand geht hinaus, mit Pruefwert', [count($v->gesendet), preg_match('/^[0-9a-f]{16}$/', (string)($stand['stateHash'] ?? ''))], [1, 1]);

$v->gesendet = [];
IPS_RequestAction($iid, 'GetState', json_encode(['hash' => $stand['stateHash']]));
pruefe('Herzschlag einer Kachel, die den Stand hat → nichts', count($v->gesendet), 0);
IPS_RequestAction($iid, 'GetState', json_encode(['hash' => '0000000000000000']));
pruefe('Herzschlag mit anderem Pruefwert (verpasster Push) → der Stand', count($v->gesendet), 1);
IPS_RequestAction($iid, 'GetState', '');
pruefe('Alte Kachel ohne Pruefwert → wie bisher der Stand', count($v->gesendet), 2);
pruefe('Der Herzschlag stempelt „jemand sieht hin", auch wenn nichts hinausgeht',
    (new ReflectionMethod(SymDoVRRTransit::class, 'TransitZuschauer'))->invoke($v, time()), true);

$v->gesendet = [];
IPS_RequestAction($iid, 'Refresh', 0);
pruefe('Abruf-Takt ohne neuen Stand → nichts (vorher: jede Minute an alle)', count($v->gesendet), 0);

// Die Minuten laufen: `now` zaehlt minutengenau.
$basis = ['v' => 1, 'view' => 'departures', 'stops' => [], 'routes' => []];
pruefe('Pruefwert: dieselbe Minute gleich, die naechste Minute neu',
    [$v->pPruefwert(['now' => 1758880800] + $basis) === $v->pPruefwert(['now' => 1758880859] + $basis),
     $v->pPruefwert(['now' => 1758880859] + $basis) === $v->pPruefwert(['now' => 1758880860] + $basis)],
    [true, false]);
pruefe('Pruefwert: eine geaenderte Abfahrtsminute ist ein neuer Stand',
    $v->pPruefwert(['now' => 1758880800, 'stops' => [['departures' => [['countdown' => 4]]]]] + $basis)
        !== $v->pPruefwert(['now' => 1758880800, 'stops' => [['departures' => [['countdown' => 3]]]]] + $basis), true);

$html = $v->GetVisualizationTile();
pruefe('Anfangsstand in der Kachel traegt den Pruefwert', (bool)preg_match('/"stateHash":"[0-9a-f]{16}"/', $html), true);

$kachel = (string)file_get_contents(__DIR__ . '/../module.html');
pruefe('Kachel merkt den Pruefwert und schickt ihn mit jeder Meldung',
    [str_contains($kachel, "if (typeof daten.stateHash === 'string') { kachelStateHash = daten.stateHash; }"),
     str_contains($kachel, "requestAction('GetState', JSON.stringify({ hash: kachelStateHash }))"),
     str_contains($kachel, "requestAction('GetState', '')")],
    [true, true, false]);
pruefe('Der geteilte Icon-Baustein steht unveraendert genau einmal da',
    [substr_count($kachel, '<!-- symcon-icons-shared'), substr_count($kachel, '<!-- /symcon-icons-shared -->')], [1, 1]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
