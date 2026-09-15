<?php

declare(strict_types=1);

/**
 * Wechsel der Alexa-/Bring-Liste — und warum das keine Löschung ist.
 *
 * Warum es diesen Prüfstand geben MUSS: die Zuordnungen sind nur nach DIENST
 * abgelegt (`alexa`, `bring`). **Welche** Liste gemeint war, stand nirgends.
 * Wählte jemand eine andere Liste als Quelle, trug jeder lokale Eintrag noch
 * die Kennungen der alten; die neue kannte sie nicht, und der Abgleich hielt
 * sie für gelöscht — alle zuvor verknüpften offenen Einträge verschwanden. In
 * der Aufgabenliste ruft das zusätzlich den normalen Löschweg, der die
 * Löschung an Google, Microsoft oder CalDAV weiterreicht.
 *
 * Gemeldet von einem externen Codereview am 14.09.2026.
 *
 *   php ToDoList/tests/QuellwechselTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../../libs/ListSource.php';
require_once __DIR__ . '/../../libs/ExternalListSync.php';

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
    printf("%-4s %-58s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/** Nur die Buchhaltung — die Gegenstellen selbst spielen hier keine Rolle. */
final class WechselProbe extends IPSModuleStrict
{
    use ExternalListSync;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterAttributeString('ExtListKnownIds', '{}');
        $this->RegisterAttributeString('ExtListRemovedIds', '{}');
        $this->RegisterAttributeString('ExtListQuellen', '{}');
        $this->RegisterAttributeString('ExtListFremdIds', '{}');
    }

    public function pWechsel(string $key, int $instanz): void
    {
        (new ReflectionMethod(self::class, 'ExtListQuelleWechsel'))->invoke($this, $key, $instanz);
    }
    public function pFremd(): array
    {
        return (array)(new ReflectionMethod(self::class, 'ExtListFremdRead'))->invoke($this);
    }
    public function pBekannt(): array
    {
        return (array)(new ReflectionMethod(self::class, 'ExtListKnownRead'))->invoke($this);
    }
    public function pSetzen(string $name, array $wert): void
    {
        $this->WriteAttributeString($name, (string)json_encode($wert));
    }
    protected function getTime(): int { return time(); }
}

IPS\Kernel::reset();
$p = new WechselProbe(8811);
$p->Create();

// ── Erste Einrichtung: es gibt nichts Altes ───────────────────────────────
$p->pWechsel('alexa', 100);
pruefe('Die erste Einrichtung legt nichts still', $p->pFremd(), []);

// ── Derselbe Lauf noch einmal: kein Wechsel ───────────────────────────────
$p->pSetzen('ExtListKnownIds', ['alexa' => ['a1' => 1, 'a2' => 1]]);
$p->pWechsel('alexa', 100);
pruefe('Dieselbe Liste aendert nichts', $p->pFremd(), []);
pruefe('… und der Merkposten bleibt stehen',
    array_keys((array)($p->pBekannt()['alexa'] ?? [])), ['a1', 'a2']);

// ── Der Wechsel ───────────────────────────────────────────────────────────
/* DAS ist der Fall. Vorher wurden hier zwei offene Einträge gelöscht, weil
   Liste B ihre Kennungen nicht kennt. */
$p->pWechsel('alexa', 200);
pruefe('Die alten Kennungen werden stillgelegt',
    array_keys((array)($p->pFremd()['alexa'] ?? [])), ['a1', 'a2']);
pruefe('Der Merkposten der alten Liste ist weg', $p->pBekannt(), []);

// ── Ein zweiter Wechsel sammelt weiter ────────────────────────────────────
$p->pSetzen('ExtListKnownIds', ['alexa' => ['b1' => 1]]);
$p->pWechsel('alexa', 300);
pruefe('Auch die Kennungen der zweiten Liste werden stillgelegt',
    array_keys((array)($p->pFremd()['alexa'] ?? [])), ['a1', 'a2', 'b1']);

// ── Dienste bleiben getrennt ──────────────────────────────────────────────
/* Ein Wechsel bei Alexa darf Bring nicht anfassen — sonst fiele dort der
   Löschweg aus, obwohl sich nichts geändert hat. */
$p->pSetzen('ExtListKnownIds', ['alexa' => ['c1' => 1], 'bring' => ['x1' => 1]]);
$p->pWechsel('bring', 900);
$p->pWechsel('bring', 901);
pruefe('Der Wechsel bei Bring legt nur Bring still',
    array_keys((array)($p->pFremd()['bring'] ?? [])), ['x1']);
pruefe('… und Alexa behaelt seinen eigenen Stand',
    array_keys((array)($p->pFremd()['alexa'] ?? [])), ['a1', 'a2', 'b1']);
pruefe('… der Merkposten von Alexa bleibt unberuehrt',
    array_keys((array)($p->pBekannt()['alexa'] ?? [])), ['c1']);

/* Der Filter muss die stillgelegten Kennungen wirklich anwenden — sonst wäre
   die ganze Buchhaltung Zierde. */
$quelle = (string)file_get_contents(__DIR__ . '/../../libs/ExternalListSync.php');
pruefe('Der Abgleich uebergeht stillgelegte Kennungen',
    str_contains($quelle, '$fremde = $this->ExtListFremdRead()[$key] ?? [];')
    && str_contains($quelle, '!isset($fremde[$id])'), true);
pruefe('Und er erkennt den Wechsel vor dem Vergleich',
    str_contains($quelle, '$this->ExtListQuelleWechsel($key, $quelle->InstanceID());'), true);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
