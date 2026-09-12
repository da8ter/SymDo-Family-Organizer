<?php

declare(strict_types=1);

/**
 * Pruefstand fuer die Dateischicht des Scan-Kanals — und die
 * KOMPOSITIONSPROBE, ohne die der Umbau gefaehrlich waere.
 *
 * Zwei Dinge lassen sich nur hier finden, nie durch Lesen:
 *
 * 1. Der Trait ScanKanal ruft `$this->KonfigID()` und `$this->Belegung()`.
 *    Fehlt einer der beiden Traits in einer benutzenden Klasse, ist der erste
 *    Griff ein Fatal — und ein Fatal im Gateway nimmt die ganze Bibliothek mit
 *    (jede Instanz meldet dann „Class not found"). Deshalb wird hier die ECHTE
 *    Gateway-Klasse zusammengesetzt und auf ihre Methoden abgeklopft, bevor
 *    irgendetwas neu geladen wird.
 * 2. Die Invariante des Kanals — Umschlag zuletzt hin, zuerst weg — laesst
 *    sich nur an einem echten Verzeichnis pruefen.
 *
 *   php SymDoGateway/tests/ScanKanalTraitTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../../libs/Konfig.php';
require_once __DIR__ . '/../../libs/Belegung.php';
require_once __DIR__ . '/../../libs/ScanKanal.php';

IPS\Kernel::reset();
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

/**
 * Eine Wegwerf-Instanz, die den Kanal benutzt — genau so, wie Gateway und
 * Scanner ihn spaeter benutzen. `KonfigID` liefert die Gateway-ID: beim
 * Scanner ist das die FREMDE Instanz, und genau dieser Fall muss tragen.
 */
final class KanalProbe extends IPSModuleStrict
{
    use Konfig;
    use Belegung;
    use ScanKanal;

    public int $gateway = 16011;

    protected function KonfigID(): int
    {
        return $this->gateway;
    }

    // Die Griffe sind privat — der Pruefstand braucht Tueren.
    public function pAblegen(string $q, array $a): bool { return $this->ScanAuftragAblegen($q, $a); }
    public function pOffen(array $q): int { return $this->ScanAuftraegeOffen($q); }
    public function pNehmen(array $q): ?array { return $this->ScanAuftragNehmen($q); }
    public function pSchreiben(array $u, array $n = []): bool { return $this->ScanErgebnisSchreiben($u, $n); }
    public function pListe(string $q = ''): array { return $this->ScanErgebnisse($q); }
    public function pLesen(string $p): ?array { return $this->ScanErgebnisLesen($p); }
    public function pNeben(string $p, int $nr): ?string { return $this->ScanNebenLesen($p, $nr); }
    public function pUebernehmen(string $p, int $nr, string $z): bool { return $this->ScanNebenUebernehmen($p, $nr, $z); }
    public function pWeg(string $p): void { $this->ScanErgebnisWeg($p); }
    public function pFehler(string $p, string $g): void { $this->ScanErgebnisInFehler($p, $g); }
    public function pAufraeumen(): int { return $this->ScanAufraeumen(); }
    public function pStand(): array { return $this->ScanStand(); }
    public function pDir(string $topf): string { return $this->ScanDir($topf, true); }
}

// Frisches Verzeichnis: die Attrappe liefert sys_get_temp_dir() OHNE Schraegstrich.
$wurzel = rtrim(sys_get_temp_dir(), '/\\') . '/symdo_scankanal';
if (is_dir($wurzel)) {
    exec('rm -rf ' . escapeshellarg($wurzel));
}

$gw = new KanalProbe(16011);       // das Gateway: KonfigID === eigene ID
$sc = new KanalProbe(42711);       // ein Scanner: KonfigID === 16011, InstanceID 42711
$sc2 = new KanalProbe(42712);      // ein zweiter Scanner an demselben Gateway

// ── Kompositionsprobe ──────────────────────────────────────────────────────
$noetig = ['KonfigID', 'BestandID', 'AiProp', 'Belegung'];
$fehlend = array_values(array_filter($noetig, static fn(string $m): bool => !method_exists('KanalProbe', $m)));
pruefe('Der Kanal findet alles, was er ruft', $fehlend, []);

pruefe('Beide Seiten zeigen auf dasselbe Verzeichnis',
    basename(dirname($gw->pDir('ergebnis'))) === basename(dirname($sc->pDir('ergebnis'))), true);
pruefe('Das Verzeichnis traegt die Gateway-ID',
    basename(dirname($sc->pDir('ergebnis'))), '16011');

// ── Auftrag: ablegen, verschmelzen, genau einer gewinnt ────────────────────
pruefe('Auftrag ablegen', $gw->pAblegen('edu', ['anlass' => 'timer']), true);
pruefe('Ein offener Auftrag', $gw->pOffen(['edu', 'mail']), 1);
pruefe('Unbekannte Quelle wird nicht abgelegt', $gw->pAblegen('unfug', []), false);

// Zweiter Anstoss: „von Hand, alles" muss den Zeitgeber schlagen.
$gw->pAblegen('edu', ['anlass' => 'hand', 'alles' => true]);
pruefe('Immer noch genau ein Auftrag', $gw->pOffen(['edu']), 1);

$genommen = $sc->pNehmen(['edu', 'mail']);
pruefe('Der Scanner nimmt den verschmolzenen Auftrag',
    [$genommen['quelle'], $genommen['auftrag']['anlass'], $genommen['auftrag']['alles']],
    ['edu', 'hand', true]);
pruefe('Danach wartet keiner mehr', $gw->pOffen(['edu']), 0);
pruefe('Der zweite Scanner bekommt nichts', $sc2->pNehmen(['edu']), null);

// ── Ergebnis mit Nebendatei ────────────────────────────────────────────────
$umschlag = ScanKanalCalc::LeererUmschlag('edu', 16011, 42711, time(), '2 Karten geaendert');
$umschlag['kiAufrufe'] = 2;
pruefe('Ergebnis mit Nebendatei schreiben',
    $sc->pSchreiben($umschlag, [['rolle' => 'ton', 'bytes' => 'ABCDE']]), true);

$liste = $gw->pListe();
pruefe('Das Gateway sieht genau ein Ergebnis', count($liste), 1);
$gelesen = $gw->pLesen($liste[0]);
pruefe('Umschlag kommt geprueft zurueck',
    [$gelesen['quelle'], $gelesen['status']['text'], $gelesen['kiAufrufe'], $gelesen['scanner']],
    ['edu', '2 Karten geaendert', 2, 42711]);
pruefe('Die Nebendatei steht im Verzeichnis des Umschlags',
    [$gelesen['dateien'][0]['rolle'], $gelesen['dateien'][0]['bytes']], ['ton', 5]);
pruefe('Nebendatei lesen', $gw->pNeben($liste[0], 0), 'ABCDE');

// Uebernehmen verschiebt sie aus dem Kanal heraus.
$ziel = rtrim(sys_get_temp_dir(), '/\\') . '/symdo_kanal_ziel.bin';
@unlink($ziel);
pruefe('Nebendatei uebernehmen', $gw->pUebernehmen($liste[0], 0, $ziel), true);
pruefe('Sie liegt jetzt am Ziel', @file_get_contents($ziel), 'ABCDE');
pruefe('Und nicht mehr im Kanal', $gw->pNeben($liste[0], 0), null);
@unlink($ziel);

$gw->pWeg($liste[0]);
pruefe('Nach dem Einpflegen ist der Kanal leer', count($gw->pListe()), 0);

// ── Fremdes Gateway wird nicht gefressen ───────────────────────────────────
$fremd = ScanKanalCalc::LeererUmschlag('edu', 99999, 42711, time());
$sc->pSchreiben($fremd);
$liste = $gw->pListe();
pruefe('Ein Umschlag fuer ein fremdes Gateway ist nicht lesbar', $gw->pLesen($liste[0]), null);
$gw->pFehler($liste[0], 'fremdes Gateway');
pruefe('Er liegt im Fehlerordner, nicht mehr in der Warteschlange',
    [count($gw->pListe()), $gw->pStand()['fehler']], [0, 1]);

// ── Reihenfolge: aeltestes zuerst, auch ueber zwei Scanner ─────────────────
$jetzt = time();
$sc->pSchreiben(ScanKanalCalc::LeererUmschlag('edu', 16011, 42711, $jetzt, 'erst'));
usleep(2000);
$sc2->pSchreiben(ScanKanalCalc::LeererUmschlag('mail', 16011, 42712, $jetzt, 'dann'));
$liste = $gw->pListe();
pruefe('Zwei Scanner, zwei Ergebnisse, aeltestes zuerst',
    [count($liste), $gw->pLesen($liste[0])['status']['text']], [2, 'erst']);
pruefe('Nach Quelle filtern', count($gw->pListe('mail')), 1);

// ── Invariante: halbe Ergebnisse werden nie sichtbar ───────────────────────
pruefe('Eine fehlende Nebendatei laesst das Ergebnis gar nicht erst entstehen',
    $sc->pSchreiben(ScanKanalCalc::LeererUmschlag('edu', 16011, 42711, time()),
        [['rolle' => 'weg', 'datei' => '/gibt/es/nicht']]), false);
pruefe('Die Warteschlange ist dadurch nicht gewachsen', count($gw->pListe()), 2);

// ── Aufraeumen ─────────────────────────────────────────────────────────────
$vorher = $gw->pStand();
pruefe('Frisches faellt beim Aufraeumen nicht weg',
    [$gw->pAufraeumen(), count($gw->pListe())], [0, 2]);
pruefe('Der Stand zaehlt Warteschlange und Fehlerordner getrennt',
    [$vorher['ergebnisse'], $vorher['fehler']], [2, 1]);

exec('rm -rf ' . escapeshellarg($wurzel));
printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
