<?php

declare(strict_types=1);

/**
 * Der Bauzustand des Handbuch-Verzeichnisses.
 *
 * Er ist im September 2026 aus einem Attribut des Gateways in eine Datei
 * gezogen, weil der Bau in eine andere Instanz ging — Attribute einer fremden
 * Instanz kann niemand lesen. An diesem Zustand haengt mehr, als man ihm
 * ansieht:
 *
 *  - Er traegt einen Versionsstempel (Suchtiefe und Bau-Fassung). Ein Stand
 *    ohne passenden Stempel gilt als unbrauchbar, und der naechste Griff faengt
 *    von vorn an — tausenddreihundert Seiten kriechen und neu einbetten.
 *  - Genau das passierte: EIN Ausgang vergass den Stempel, naemlich der nach
 *    einer gescheiterten Einbettung. Der Bau warf sich damit selbst weg, und
 *    weil die Fehlerkette im Zustand steht, verschwand sie mit — die Bremse
 *    nach drei Fehlversuchen war nie erreichbar. Der Kommentar an Ort und
 *    Stelle verspricht, sie halte „zehntausend Abrufe bei symcon.de am Tag"
 *    auf; sie tat es nicht.
 *
 * Deshalb steht der Stempel jetzt im Schreiber und nicht an den Ausgaengen —
 * und deshalb gibt es diesen Pruefstand.
 *
 *   php SymDoGateway/tests/DokuStandTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../../libs/Konfig.php';
require_once __DIR__ . '/../libs/DokuGemein.php';

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

/** Nur die beiden Traits, die den Zustand halten — mehr braucht es dafuer nicht. */
final class StandProbe extends IPSModuleStrict
{
    use Konfig;
    use DokuGemein;

    public function pSchreiben(array $s): void { $this->DokuStandSchreiben($s); }
    public function pLesen(): array { return $this->DokuStand(); }
    public function pDatei(): string { return $this->DokuStandDatei(); }
    public function pLeer(): array { return $this->DokuLeer(); }

    protected function getTime(): int { return time(); }
}

$probe = new StandProbe(4711);
$datei = $probe->pDatei();
@unlink($datei);
register_shutdown_function(static function () use ($datei): void { @unlink($datei); });

// ── Der Stempel kommt vom Schreiber, nicht vom Aufrufer ───────────────────
/* So sieht der Ausgang nach einer gescheiterten Einbettung aus: ein Stand
   mitten in der Lesephase, ohne dass jemand an tiefe/bau gedacht haette. */
$mitten = ['phase' => 'lesen', 'pos' => 500, 'liste' => ['/a/', '/b/'], 'stuecke' => 1234,
           'stand' => 0, 'fertig' => false, 'fehler' => 1,
           'seiten' => ['/a/' => 1, '/b/' => 1], 'schlange' => []];
$probe->pSchreiben($mitten);
$zurueck = $probe->pLesen();
pruefe('Ein Stand ohne Stempel ueberlebt trotzdem',
    [$zurueck['phase'], $zurueck['pos'], $zurueck['stuecke'], $zurueck['fehler']],
    ['lesen', 500, 1234, 1]);

/* Die Probe aufs Exempel: ohne den Stempel im Schreiber kaeme hier „crawl",
   pos 0 und fehler 0 zurueck — der ganze Bau weg und die Bremse geloest. */
$mitten['fehler'] = 2;
$probe->pSchreiben($mitten);
pruefe('Und die Fehlerkette waechst weiter, statt sich zurueckzusetzen',
    $probe->pLesen()['fehler'], 2);

// ── Ein Stand aus ANDEREN Einstellungen ist wertlos ───────────────────────
$roh = json_decode((string)file_get_contents($datei), true);
$roh['tiefe'] = 6;                    // damals fehlten alle SDK-Seiten
file_put_contents($datei, (string)json_encode($roh));
$nach = $probe->pLesen();
pruefe('Ein Stand mit anderer Suchtiefe wird verworfen',
    [$nach['phase'], $nach['pos'], $nach['fertig']], ['crawl', 0, false]);

// ── Unteilbar schreiben ───────────────────────────────────────────────────
$probe->pSchreiben($mitten);
pruefe('Es bleibt keine Zwischendatei liegen', @is_file($datei . '.tmp'), false);

@unlink($datei);
pruefe('Ohne Datei beginnt der Bau von vorn',
    [$probe->pLesen()['phase'], $probe->pLesen()['pos']], ['crawl', 0]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
