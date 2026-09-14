<?php

declare(strict_types=1);

/**
 * Laesst sich die Gateway-Klasse ueberhaupt zusammensetzen?
 *
 * `SymDoGateway` besteht aus zweiunddreissig Traits. Zwei davon duerfen keine
 * Methode und keine Konstante desselben Namens mitbringen — PHP meldet das
 * nicht als Warnung, sondern als **fatalen Fehler beim Laden der Klasse**. Und
 * weil Symcon alle Module einer Bibliothek zusammen laedt, steht danach nicht
 * nur das Gateway still: JEDE Instanz der Bibliothek meldet „Class not found",
 * alle Praefix-Funktionen sind weg, und die App erreicht gar nichts mehr.
 * Genau so geschehen am 29.08.2026, ausgeloest von einem Bindestrich.
 *
 * Auffallen wuerde das sonst erst beim Deploy — nach `MC_ReloadModule`, mit
 * einer Konsole, die nichts mehr zeigt. Hier faellt es in einer Sekunde auf.
 *
 * Geprueft wird ausserdem, dass keine Trait-Haelfte des Umbaus „Gateway frei
 * halten" beim Aufraeumen verlorengeht: die Methoden, auf die der Lauf der
 * Klassenseiten sich stuetzt, muessen an der Klasse ankommen.
 *
 *   php SymDoGateway/tests/KompositionTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';

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

/* Eine Warnung beim Laden ist hier so ernst wie ein Fehler: die Attrappen
   melden fehlende Symcon-Griffe auf diesem Weg. */
$warnungen = [];
set_error_handler(static function (int $n, string $m) use (&$warnungen): bool {
    $warnungen[] = $m;
    return true;
});
require_once __DIR__ . '/../module.php';
restore_error_handler();

pruefe('Die Klasse laedt ohne Warnung', $warnungen, []);
pruefe('SymDoGateway existiert', class_exists('SymDoGateway', false), true);

$k = new ReflectionClass('SymDoGateway');

/* Die Haelften des Klassenseiten-Laufs. `EduSeiteSpiegeln` ist die
   Gateway-Haelfte: sie schreibt EINMAL je Seite. Faellt sie beim Aufraeumen
   weg, spiegelt der Lauf still gar nichts mehr — ohne Fehlermeldung, denn
   `EduSeiteLesen` zaehlt dann einfach null gespiegelte Karten. */
foreach (['EduSeiteSpiegeln', 'EduKarteEinpflegen', 'EduNachzug',
          'EduSeiteLesen', 'EduScanRun', 'EduOrdner', 'EduWriteStore',
          'ScanEinpflegen', 'AiJobEnqueue', 'DokuStand'] as $m) {
    pruefe('Die Klasse kennt ' . $m, $k->hasMethod($m), true);
}

/* Der Rechenkern gehoert NICHT in die Klasse: er ist Symcon-frei und wird
   ohne Kernel geprueft. Eine Trait-Methode gleichen Namens waere ein Zeichen,
   dass jemand ihn zurueckgeholt hat. */
foreach (['Bedarf', 'SatzBauen', 'NachzugRechnen'] as $m) {
    pruefe('Der Rechenkern bleibt draussen: kein ' . $m . ' an der Klasse',
        $k->hasMethod($m), false);
}
pruefe('… sondern steht in EduStoreCalc',
    [method_exists('EduStoreCalc', 'Bedarf'), method_exists('EduStoreCalc', 'SatzBauen'),
     method_exists('EduStoreCalc', 'NachzugRechnen')], [true, true, true]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
