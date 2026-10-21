<?php

declare(strict_types=1);

/**
 * „Zuletzt benutzt" (der Wagen) — Zusammenführen und Löschen (21.10.2026).
 *
 * Nutzer-Rückmeldung: die Liste wuchs mit „Milch 1", „Milch 2" … und das
 * Löschen eines Eintrags nahm alle gleichnamigen mit, auch den offenen Artikel.
 * Jetzt: der neueste Kauf ersetzt gleichnamige Wagen-Zeilen; eine Wagen-Zeile
 * löscht sich allein. Bestehende Doppel werden nicht von selbst aufgeräumt.
 *
 * Gefahren gegen die echte Klasse (volles Create) mit den Symcon-Attrappen.
 *
 *   php SymDoShoppingList/tests/WagenTest.php
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
    printf("%-4s %-70s%s\n", $ok ? 'OK' : 'FEHL', $name, $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/** SymDoShoppingList mit vollem Create(); Hook, Push und ApplyChanges sind Attrappen. */
final class WagenProbe extends SymDoShoppingList
{
    protected function RegisterHook(string $HookPath): bool { return true; }
    public function ApplyChanges(): void { IPSModuleStrict::ApplyChanges(); }
    protected function getTime(): int { return time(); }
    protected function UpdateVisualizationValue(mixed $Value) { return true; }
    public function pSetzen(array $items): void { $this->WriteAttributeString('Items', (string)json_encode($items)); }
    public function pZeilen(): array { return json_decode($this->ReadAttributeString('Items'), true) ?: []; }
}

$iid = IPS\ObjectManager::registerObject(1);
IPS\InstanceManager::createInstance($iid, ['ModuleID' => '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}',
    'ModuleName' => 'WagenProbe', 'ModuleType' => 3, 'Class' => 'WagenProbe']);
/** @var WagenProbe $p */
$p = IPS\InstanceManager::getInstanceInterface($iid);

$zeile = static fn(string $id, string $name, string $menge, bool $wagen, array $mehr = []): array => [
    'id' => $id, 'name' => $name, 'category' => 'Milch & Käse', 'amount' => $menge, 'notes' => '', 'inCart' => $wagen,
] + $mehr;
/** Kurzform: [id, name, menge, im Wagen] je Zeile. */
$kurz = static fn(array $zeilen): array => array_map(
    static fn(array $z): array => [$z['id'], $z['name'], $z['amount'], $z['inCart']], $zeilen);
$aktion = static function (string $ident, mixed $wert) use ($iid): void {
    IPS_RequestAction($iid, $ident, $wert);
};

// ── Abhaken: der neueste Kauf ersetzt die gleichnamige Wagen-Zeile ──────────
$p->pSetzen([$zeile('a', 'Milch', '1', true, ['boughtAt' => 100]), $zeile('b', 'milch ', '2', false)]);
$aktion('ToggleCart', json_encode(['id' => 'b', 'inCart' => true]));
$z = $p->pZeilen();
pruefe('Abhaken: „Milch 2" ersetzt „Milch 1" im Wagen (Groß/Klein, Rand egal)',
    [$kurz($z), ($z[0]['boughtAt'] ?? 0) > 100], [[['b', 'milch ', '2', true]], true]);

$p->pSetzen([$zeile('a', 'Milch', '1', true), $zeile('c', 'Brot', '1', true), $zeile('b', 'Milch', '1', false)]);
$aktion('ToggleCart', json_encode(['id' => 'b', 'inCart' => true]));
pruefe('Abhaken mit gleicher Menge: ebenfalls nur eine Zeile, andere bleiben',
    $kurz($p->pZeilen()), [['c', 'Brot', '1', true], ['b', 'Milch', '1', true]]);

$p->pSetzen([$zeile('a', 'Milch', '1', true), $zeile('x', 'Milch', '3', true)]);
$aktion('ToggleCart', json_encode(['id' => 'x', 'inCart' => false]));
pruefe('Zurück auf die Liste räumt nichts weg',
    $kurz($p->pZeilen()), [['a', 'Milch', '1', true], ['x', 'Milch', '3', false]]);

// ── „Alles erledigt": je Name eine Zeile, die zuletzt abgehakte gewinnt ─────
$p->pSetzen([
    $zeile('a', 'Milch', '1', true), $zeile('k', 'Käse', '1', true), $zeile('k2', 'Käse', '2', true),
    $zeile('b', 'Milch', '2', false), $zeile('c', 'Brot', '1', false),
]);
$aktion('MarkAllDone', '');
pruefe('Alles erledigt: Milch neu ersetzt alt; Käse-Doppel ohne neuen Kauf bleibt',
    $kurz($p->pZeilen()),
    [['k', 'Käse', '1', true], ['k2', 'Käse', '2', true], ['b', 'Milch', '2', true], ['c', 'Brot', '1', true]]);

// ── Löschen ─────────────────────────────────────────────────────────────────
$p->pSetzen([$zeile('o', 'Milch', '1', false), $zeile('w1', 'Milch', '2', true), $zeile('w2', 'MILCH', '3', true)]);
$aktion('DeleteItem', 'w1');
pruefe('Wagen-Zeile löschen: nur genau diese; offener Artikel und anderes Doppel bleiben',
    $kurz($p->pZeilen()), [['o', 'Milch', '1', false], ['w2', 'MILCH', '3', true]]);

$aktion('DeleteItem', 'o');
pruefe('Offenen Artikel löschen: wie bisher alles gleichen Namens',
    $kurz($p->pZeilen()), []);

$quelle = (string)file_get_contents(__DIR__ . '/../libs/ItemStore.php');
pruefe('Die alte Doppel-Regel (Menge UND Notiz) ist weg',
    [str_contains($quelle, 'HasCartDuplicate'), str_contains($quelle, 'function CartGleichnamige')], [false, true]);

ob_end_flush();
printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
