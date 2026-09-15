<?php

declare(strict_types=1);

/**
 * Die Tagesgrenze der Routinen — und was an den Zeitumstellungen passiert.
 *
 * Warum es diesen Prüfstand gibt: die Tageskennung zog die Reset-Uhrzeit als
 * SEKUNDEN ab, der Timer baute dieselbe Grenze mit `mktime` an der Kalenderuhr.
 * Zweimal im Jahr sind das verschiedene Grenzen. Bei Reset 03:00 lieferte die
 * Kennung am 29.03.2026 um 03:30 noch den 28.03. (der Timer hatte da längst
 * zurückgesetzt), und am 25.10.2026 um 02:30 CET schon den 25.10., obwohl der
 * Reset erst bevorstand. Zustand und Timer liefen auseinander; Häkchen aus dem
 * Zwischenraum gingen verloren, und weil Münzen den Tagesreset überleben,
 * konnte erneutes Abhaken erneut Münzen erzeugen.
 *
 * Gemeldet von einem externen Codereview am 14.09.2026 (F5).
 *
 *   php Routines/tests/TagesgrenzeTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/RoutineStore.php';

/* Die Umstellungen sind der ganze Punkt — ohne feste Zone prüft das nichts. */
date_default_timezone_set('Europe/Berlin');

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
    printf("%-4s %-62s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

final class GrenzProbe extends IPSModuleStrict
{
    use RoutineStore;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('ResetTime', '');
        $this->RegisterAttributeString('State', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function Reset(string $zeit): void
    {
        IPS_SetProperty($this->InstanceID, 'ResetTime', $zeit);
        IPS_ApplyChanges($this->InstanceID);
    }

    public function Tag(int $jetzt): string
    {
        return (string)(new ReflectionMethod(self::class, 'TagKennung'))->invoke($this, $jetzt);
    }
    public function Grenze(int $jetzt): int
    {
        return (int)(new ReflectionMethod(self::class, 'ResetGrenze'))->invoke($this, $jetzt);
    }
    public function BisReset(int $jetzt): int
    {
        return (int)(new ReflectionMethod(self::class, 'NaechsteResetMs'))->invoke($this, $jetzt);
    }
    protected function getTime(): int { return time(); }
}

IPS\Kernel::reset();
$iid = IPS\ObjectManager::registerObject(1);
ob_start();
IPS\InstanceManager::createInstance($iid, ['ModuleID' => '{00000000-0000-0000-0000-00000000DE11}',
    'ModuleName' => 'GrenzProbe', 'ModuleType' => 3, 'Class' => 'GrenzProbe']);
ob_end_clean();
/** @var GrenzProbe $p */
$p = IPS\InstanceManager::getInstanceInterface($iid);
$p->Reset('{"hour":3,"minute":0}');

/** Ortszeit als Zeitstempel — mit ausdrücklicher Zonenangabe, damit die
 *  mehrdeutige Stunde im Oktober eindeutig wird. */
function t(string $s): int
{
    return (new DateTimeImmutable($s, new DateTimeZone('Europe/Berlin')))->getTimestamp();
}

// ── Ein gewöhnlicher Tag ──────────────────────────────────────────────────
pruefe('02:59 gehoert noch zum Vortag',        $p->Tag(t('2026-09-15 02:59:00')), '2026-09-14');
pruefe('03:00 ist der neue Tag',               $p->Tag(t('2026-09-15 03:00:00')), '2026-09-15');
pruefe('Mittags ist es derselbe Tag',          $p->Tag(t('2026-09-15 12:00:00')), '2026-09-15');
pruefe('23:59 ebenfalls',                      $p->Tag(t('2026-09-15 23:59:00')), '2026-09-15');
pruefe('00:30 der Folgenacht noch nicht',      $p->Tag(t('2026-09-16 00:30:00')), '2026-09-15');

// ── Frühjahr: 29.03.2026, 02:00 CET → 03:00 CEST ─────────────────────────
/* Die Stunde 02:00–02:59 gibt es nicht. Der Reset um 03:00 CEST faellt mit dem
   Sprung zusammen — direkt danach MUSS der neue Tag gelten. Der alte Weg
   lieferte hier noch den 28.03., weil er 3 Stunden von einem Zeitstempel abzog,
   der bereits eine Stunde uebersprungen hatte. */
pruefe('29.03. 01:30 CET ist noch der 28.03.', $p->Tag(t('2026-03-29 01:30:00')), '2026-03-28');
pruefe('29.03. 03:00 CEST ist der 29.03.',     $p->Tag(t('2026-03-29 03:00:00')), '2026-03-29');
pruefe('29.03. 03:30 CEST ist der 29.03.',     $p->Tag(t('2026-03-29 03:30:00')), '2026-03-29');
pruefe('29.03. 12:00 ist der 29.03.',          $p->Tag(t('2026-03-29 12:00:00')), '2026-03-29');
pruefe('30.03. 02:00 gehoert noch zum 29.03.', $p->Tag(t('2026-03-30 02:00:00')), '2026-03-29');

// ── Herbst: 25.10.2026, 03:00 CEST → 02:00 CET ───────────────────────────
/* Die Stunde 02:00–02:59 gibt es ZWEIMAL. Vor dem Reset um 03:00 CET gilt in
   beiden Durchgaengen noch der 24.10. — der alte Weg sprang in der zweiten
   (CET-)Runde schon auf den 25.10. */
pruefe('25.10. 02:30 CEST ist noch der 24.10.',
    $p->Tag((new DateTimeImmutable('2026-10-25 02:30:00 +0200'))->getTimestamp()), '2026-10-24');
pruefe('25.10. 02:30 CET ist noch der 24.10.',
    $p->Tag((new DateTimeImmutable('2026-10-25 02:30:00 +0100'))->getTimestamp()), '2026-10-24');
pruefe('25.10. 03:00 CET ist der 25.10.',
    $p->Tag((new DateTimeImmutable('2026-10-25 03:00:00 +0100'))->getTimestamp()), '2026-10-25');
pruefe('26.10. 02:30 gehoert noch zum 25.10.', $p->Tag(t('2026-10-26 02:30:00')), '2026-10-25');

// ── Kennung und Timer meinen DIESELBE Grenze ─────────────────────────────
/* Das ist der eigentliche Fund: nicht „die Kennung ist falsch", sondern
   „Kennung und Timer sind zwei verschiedene Grenzen". */
$proben = ['2026-03-29 01:30:00', '2026-03-29 03:30:00', '2026-09-15 02:59:00',
           '2026-09-15 03:00:00', '2026-10-26 02:30:00', '2026-12-31 23:59:00'];
$einig = true;
foreach ($proben as $s) {
    $jetzt = t($s);
    /* Eine Sekunde, nachdem der Timer feuert, muss die Kennung umgesprungen
       sein — und keine Sekunde frueher. */
    $ziel = $jetzt + intdiv($p->BisReset($jetzt), 1000);
    if ($p->Tag($ziel) === $p->Tag($jetzt) || $p->Tag($ziel - 1) !== $p->Tag($jetzt)) {
        $einig = false;
        echo "     uneinig bei $s\n";
    }
}
pruefe('Der Timer feuert genau dann, wenn die Kennung springt', $einig, true);

// ── Andere Reset-Zeiten ──────────────────────────────────────────────────
$p->Reset('{"hour":0,"minute":0}');
pruefe('Reset 00:00: 23:59 ist noch heute',    $p->Tag(t('2026-09-15 23:59:00')), '2026-09-15');
pruefe('Reset 00:00: 00:00 ist morgen',        $p->Tag(t('2026-09-16 00:00:00')), '2026-09-16');

/* 02:30 gibt es am 29.03. gar nicht — mktime rueckt auf 03:30 CEST vor. Die
   Kennung muss auch dann eindeutig bleiben. */
$p->Reset('{"hour":2,"minute":30}');
pruefe('Reset 02:30 in der uebersprungenen Stunde: 01:30 ist Vortag',
    $p->Tag(t('2026-03-29 01:30:00')), '2026-03-28');
pruefe('… und 04:00 ist der neue Tag',         $p->Tag(t('2026-03-29 04:00:00')), '2026-03-29');
pruefe('… der Timer laeuft nicht rueckwaerts', $p->BisReset(t('2026-03-29 04:00:00')) > 0, true);

$p->Reset('');
pruefe('Ohne Angabe gilt 03:00',               $p->Tag(t('2026-09-15 02:59:00')), '2026-09-14');

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
