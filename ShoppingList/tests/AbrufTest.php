<?php

declare(strict_types=1);

/**
 * Fremde Adressen holt die Einkaufsliste nur ueber den gepruefen Abruf.
 *
 * Zwei Stellen laden von aussen: die Barcode-Datenbanken (feste Adressen, aber
 * WOHIN sie umleiten, entscheiden sie) und das Produktbild, dessen Adresse aus
 * der Antwort der Haendler-API kommt — also aus fremder Hand. Bis zum
 * 18.09.2026 taten beide das mit rohem cURL und FOLLOWLOCATION: jede Umleitung
 * wurde gefolgt, auch ins eigene Netz, und die Adresse stand im Protokoll.
 * Gefunden vom Sicherheits-Review.
 *
 * Gefahren wird gegen die echte Klasse mit den Symcon-Attrappen; das Netz wird
 * nicht beruehrt, weil eine Loopback-Adresse schon an der Pruefung scheitert.
 *
 *   php ShoppingList/tests/AbrufTest.php
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
    printf("%-4s %-62s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/** Die echte Klasse; nur das Protokoll wird mitgeschrieben. */
final class AbrufProbe extends SymDoShoppingList
{
    public array $debug = [];
    protected function SendDebug(string $Message, mixed $Data, int $Format): bool
    {
        $this->debug[] = $Message . ': ' . (string)$Data;
        return true;
    }
    public function pBild(string $url, string $ean): string
    {
        return (string)(new ReflectionMethod(SymDoShoppingList::class, 'DownloadApiImage'))
            ->invoke($this, $url, $ean, []);
    }
    public function pBarcode(string $url): ?string
    {
        return (new ReflectionMethod(SymDoShoppingList::class, 'FetchBarcodeLookupUrl'))
            ->invoke($this, $url, 'Probe');
    }
    /* Kein volles Create(): das legt Variablen und Hooks an, die die Attrappen
       ohne Kernel-Objekt nicht tragen. Der Bildweg braucht genau ein Attribut. */
    public function pVorbereiten(): void { $this->RegisterAttributeString('ImageMapCache', ''); }
    protected function getTime(): int { return time(); }
}

$p = new AbrufProbe(4711);
$p->pVorbereiten();
/* Eine EAN, zu der es sicher kein Bild gibt — sonst griffe der Zwischenspeicher. */
$ean = '0000000000000';
$datei = __DIR__ . '/../assets/api-images/' . $ean . '.webp';
@unlink($datei);

// ── Loopback: die Adresse aus der fremden Antwort zeigt ins eigene Netz ────
pruefe('Ein Bild von 127.0.0.1 wird NICHT geholt', $p->pBild('http://127.0.0.1/bild.jpg', $ean), '');
pruefe('… und nichts abgelegt', is_file($datei), false);
pruefe('… die Adresse steht nicht im Protokoll',
    (bool)array_filter($p->debug, static fn(string $z): bool => str_contains($z, '/bild.jpg')), false);
pruefe('… der Host schon', (bool)array_filter($p->debug,
    static fn(string $z): bool => str_contains($z, 'host: 127.0.0.1')), true);
pruefe('Eine Barcode-Abfrage gegen 10.0.0.1 kommt leer zurueck',
    $p->pBarcode('http://10.0.0.1/api.php?ean=1'), null);
pruefe('Auch ein anderes Schema (file:) wird abgewiesen', $p->pBarcode('file:///etc/hosts'), null);
@unlink($datei);

// ── Die Klasse: beide Wege durch dieselbe Tuer ─────────────────────────────
$quelle = (string)file_get_contents(__DIR__ . '/../module.php');
$ausschnitt = static function (string $fn) use ($quelle): string {
    $von = (int)strpos($quelle, 'private function ' . $fn . '(');
    $bis = (int)strpos($quelle, "\n    }\n", $von);
    return substr($quelle, $von, $bis - $von);
};
pruefe('DownloadApiImage holt ueber AiRecipePage::holen',
    [str_contains($ausschnitt('DownloadApiImage'), 'AiRecipePage::holen('),
     str_contains($ausschnitt('DownloadApiImage'), 'curl_init(')], [true, false]);
pruefe('FetchBarcodeLookupUrl ebenso',
    [str_contains($ausschnitt('FetchBarcodeLookupUrl'), 'AiRecipePage::holen('),
     str_contains($ausschnitt('FetchBarcodeLookupUrl'), 'curl_init(')], [true, false]);
/* Der eine verbleibende FOLLOWLOCATION-Aufruf gehoert dem Haendler-Client mit
   der vom Nutzer eingestellten Basisadresse — erlaubt. Kommt ein zweiter dazu,
   soll das auffallen. */
pruefe('Genau ein FOLLOWLOCATION bleibt — der Haendler-Client',
    substr_count($quelle, 'CURLOPT_FOLLOWLOCATION, true'), 1);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
