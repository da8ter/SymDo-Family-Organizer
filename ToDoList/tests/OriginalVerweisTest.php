<?php

declare(strict_types=1);

/**
 * Pruefstand: eine Aufgabe haelt ihr Original (24.09.2026).
 *
 * „Original speichern" aus einem KI-Vorschlag: AddItem nimmt die Kennung eines
 * Originals im SymDo-Gateway auf — nur in der Form, die das Gateway vergibt.
 * UpdateItem fasst sie nicht an, damit keine Oberflaeche sie beim Bearbeiten
 * verliert.
 *
 *   php ToDoList/tests/OriginalVerweisTest.php
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
    printf("%-4s %-60s%s\n", $ok ? 'OK' : 'FEHL', $name, $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

final class OriginalTodoProbe extends SymDoToDoList
{
    protected function getTime(): int { return time(); }
    public function pItems(): array { return (array)(new ReflectionMethod(SymDoToDoList::class, 'LoadItems'))->invoke($this); }
    public function pNorm(mixed $roh): string
    {
        return (string)(new ReflectionMethod(SymDoToDoList::class, 'NormalizeOriginalId'))->invoke($this, $roh);
    }
}

/* Eine echte Instanz im Stub-Kernel — Create() legt Variablen an, dafuer muss
   das Objekt existieren (Muster: SymDoGateway/tests/ScanBridgeTest.php). */
$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/symdo_todo_orig_' . bin2hex(random_bytes(4));
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($tmp)));
@mkdir($tmp . '/Probe', 0700, true);
$guid = (string)json_decode((string)file_get_contents(__DIR__ . '/../module.json'), true)['id'];
file_put_contents($tmp . '/Probe/module.json', (string)json_encode([
    'id' => $guid, 'name' => 'OriginalTodoProbe', 'type' => 3, 'vendor' => 'Stephan Sprick', 'aliases' => [],
    'parentRequirements' => [], 'childRequirements' => [], 'implemented' => [], 'prefix' => 'TDLPROBE',
]));
file_put_contents($tmp . '/Probe/module.php', "<?php\n");
IPS\ModuleLoader::loadSingleModule($tmp . '/Probe', '{TDL-PROBE-LIB}');
$p = IPS\InstanceManager::getInstanceInterface(IPS_CreateInstance($guid));
pruefe('NormalizeOriginalId: gueltig bleibt, Unsinn wird leer',
    [$p->pNorm(str_repeat('a', 24)), $p->pNorm(' ' . str_repeat('b', 24) . ' '), $p->pNorm('../x'), $p->pNorm(str_repeat('A', 24)),
     $p->pNorm(42), $p->pNorm(null)],
    [str_repeat('a', 24), str_repeat('b', 24), '', '', '', '']);

$mit = $p->AddItem(['title' => 'Arbeitsheft S. 4', 'originalId' => str_repeat('c', 24)]);
$ohne = $p->AddItem(['title' => 'Ohne Original']);
$falsch = $p->AddItem(['title' => 'Falsch', 'originalId' => 'rm -rf']);
$nachId = array_column($p->pItems(), null, 'id');
pruefe('AddItem nimmt eine gueltige Kennung auf, sonst keine',
    [$nachId[$mit]['originalId'] ?? null, array_key_exists('originalId', $nachId[$ohne] ?? []), array_key_exists('originalId', $nachId[$falsch] ?? [])],
    [str_repeat('c', 24), false, false]);

$p->UpdateItem(['id' => $mit, 'title' => 'Arbeitsheft S. 4 und 5', 'originalId' => str_repeat('d', 24)]);
$nachId = array_column($p->pItems(), null, 'id');
pruefe('UpdateItem aendert den Titel, laesst die Kennung aber stehen',
    [$nachId[$mit]['title'] ?? null, $nachId[$mit]['originalId'] ?? null], ['Arbeitsheft S. 4 und 5', str_repeat('c', 24)]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
