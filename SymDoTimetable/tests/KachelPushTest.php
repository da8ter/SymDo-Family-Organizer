<?php

declare(strict_types=1);

/**
 * Der Fuenf-Minuten-Takt der Stundenplan-Kachel (26.09.2026): der ganze Plan
 * (~50 KB) geht nur noch hinaus, wenn sich daran etwas geaendert hat. Die
 * Minute („now") zaehlt nicht mit — die rechnet die Kachel selbst weiter;
 * nur ob es eine gibt (Jetzt-Strich an oder aus).
 * Oeffnen (GetState) und Uebernehmen senden immer.
 *
 *   php SymDoTimetable/tests/KachelPushTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../module.php';

final class KachelPushHarness extends SymDoTimetable
{
    public array $plan = [];
    public array $puffer = [];
    public array $gesendet = [];

    public function __construct()
    {
        // Ohne Kernel: nur die Wege, die PushState braucht.
    }

    public function GetTilePlan(): string { return (string)json_encode($this->plan); }
    protected function SetBuffer(string $Name, string $Data): bool { $this->puffer[$Name] = $Data; return true; }
    protected function GetBuffer(string $Name): string { return $this->puffer[$Name] ?? ''; }
    protected function UpdateVisualizationValue(mixed $Value): bool { $this->gesendet[] = json_decode((string)$Value, true); return true; }
    protected function ReadPropertyString(string $Name): string { return $Name === 'HolidaySource' ? 'none' : ''; }
    protected function ReadAttributeInteger(string $Name): int { return 0; }
}

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, mixed $ist, mixed $soll): void
{
    global $fehler, $anzahl;
    $anzahl++;
    if ($ist === $soll) {
        echo "OK   $name\n";
        return;
    }
    $fehler++;
    echo "FEHL $name\n     ist:  " . var_export($ist, true) . "\n     soll: " . var_export($soll, true) . "\n";
}

$k = new KachelPushHarness();
$k->plan = ['mode' => 'timeline', 'date' => '2026-09-28', 'now' => 480,
            'children' => [['name' => 'Anna', 'next' => 'Mathematik']], 'empty' => false];

$k->Refresh();
pruefe('Erster Takt sendet den Plan', count($k->gesendet), 1);

$k->plan['now'] = 485;
$k->Refresh();
pruefe('Nur die Minute weiter: kein erneuter Versand', count($k->gesendet), 1);

$k->plan['now'] = 530;
$k->plan['children'][0]['next'] = 'Kunst';
$k->Refresh();
pruefe('Naechste Stunde geaendert: Plan geht hinaus', count($k->gesendet), 2);
pruefe('Versandt wird der Plan samt aktueller Minute', $k->gesendet[1]['now'] ?? null, 530);

$k->plan['now'] = 535;
$k->RequestAction('GetState', 0);
pruefe('Oeffnen der Kachel sendet auch einen unveraenderten Plan', count($k->gesendet), 3);

$k->plan['now'] = 540;
$k->plan['date'] = '2026-09-29';
$k->Refresh();
pruefe('Neuer Tag: Plan geht hinaus', count($k->gesendet), 4);

$k->plan['now'] = null;
$k->Refresh();
pruefe('Jetzt-Strich faellt weg: Plan geht hinaus, auch bei gleichem Datum', count($k->gesendet), 5);
$k->Refresh();
pruefe('Weiter ohne Minute und unveraendert: kein Versand', count($k->gesendet), 5);

echo "\n$anzahl Zusicherungen, $fehler Abweichung(en).\n";
exit($fehler === 0 ? 0 : 1);
