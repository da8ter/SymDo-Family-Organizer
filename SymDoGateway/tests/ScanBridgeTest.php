<?php

declare(strict_types=1);

/**
 * Die Gateway-Seite des Scan-Kanals — und die ganze Kette einmal offline.
 *
 * Drei Fragen, die sich nur hier beantworten lassen:
 *
 * 1. Setzt sich die ECHTE Gateway-Klasse mit dem neuen Trait noch zusammen?
 *    Eine Namenskollision zwischen zwei Traits ist ein Fatal beim Deklarieren
 *    — und ein Fatal in dieser Klasse nimmt die ganze Bibliothek mit: jede
 *    Instanz meldet dann „Class not found". Das muss VOR dem Neustart auffallen.
 * 2. Legt das Gateway seine Scanner richtig an — und nur einmal?
 * 3. Traegt die Kette? Auftrag ablegen -> Signal -> Scanner nimmt -> Ergebnis
 *    -> Weckruf -> Gateway pflegt ein -> Kanal leer. Hier laeuft sie mit den
 *    echten Klassen beider Seiten, nur ohne Symcon.
 *
 *   php SymDoGateway/tests/ScanBridgeTest.php
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
require_once __DIR__ . '/../libs/ScanBridge.php';
require_once __DIR__ . '/../libs/DokuGemein.php';
require_once __DIR__ . '/../libs/DokuBau.php';
require_once __DIR__ . '/../libs/SymconDoku.php';
require_once __DIR__ . '/../../SymDoScanner/module.php';

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
 * Das Gateway, auf den Kanal zusammengestrichen. Es benutzt DIESELBEN Traits
 * in derselben Reihenfolge wie das echte — die vollstaendige Klasse wird
 * weiter unten zusaetzlich deklariert, aber nicht instanziiert: dafuer
 * braeuchte es halb Symcon.
 */
final class BrueckeProbe extends IPSModuleStrict
{
    use Konfig;
    use Belegung;
    use ScanKanal;
    use ScanBridge;
    /* Das Handbuch kommt mit, weil an ihm die Weiche haengt: baut ein Scanner,
       darf das Gateway NICHT mitbauen. Dass die drei Traits ueberhaupt
       zusammenpassen, prueft sich hier gleich mit. */
    use DokuGemein;
    use DokuBau;
    use SymconDoku;

    public function Create(): void
    {
        parent::Create();
        $this->ScanCreate();
        $this->DokuCreate();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->ScanApplyChanges();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($this->ScanRequestAction($Ident, $Value)) {
            return;
        }
        parent::RequestAction($Ident, $Value);
    }

    // Tueren fuer den Pruefstand — die Griffe selbst sind privat.
    public function pFehlt(): bool { return $this->ScannerFehlt(); }
    public function pAnlegen(): void { $this->ScannerAnlegen(); }
    public function pScanner(): array { return $this->ScannerInstanzen(); }
    public function pAuftrag(string $q, array $a = []): bool { return $this->ScanAuftragGeben($q, $a); }
    public function pSignal(): int { return (int)@GetValue((int)@$this->GetIDForIdent(self::SCAN_SIGNAL_IDENT)); }
    public function pSignalGeben(): void { $this->ScanSignalGeben(); }
    public function pEinpflegen(): int { return $this->ScanEinpflegen(); }
    public function pListe(): array { return $this->ScanErgebnisse(); }
    public function pStand(): array { return $this->ScanStand(); }
    public function pSchreiben(array $u): bool { return $this->ScanErgebnisSchreiben($u); }
    public function pDokuTick(): void { $this->DokuTick(); }
    public function pDokuTakt(): int { return (int)$this->GetTimerInterval('DokuIndex'); }
    public function pDokuTaktAn(): void { $this->SetTimerInterval('DokuIndex', 8000); }
    public function pFehlerListe(): array { return $this->ScanErgebnisse(); }

    protected function getTime(): int
    {
        return time();
    }
}

/**
 * Der echte Scanner, nur mit einer Uhr fuer die Attrappen — und mit einem
 * Zaehler: an ihm laesst sich ablesen, WER das Uebernehmen ausgeloest hat.
 * Genau darum geht es bei der Richtungsregel.
 */
final class ScannerAnDerBruecke extends SymDoScanner
{
    public int $anwendungen = 0;

    public function ApplyChanges(): void
    {
        $this->anwendungen++;
        parent::ApplyChanges();
    }

    protected function getTime(): int
    {
        return time();
    }
}

$gwModul = dirname(__DIR__);
$kernel  = rtrim(sys_get_temp_dir(), '/\\');
$wurzel  = $kernel . '/symdo_scankanal';
$tmp     = $kernel . '/symdo_bruecke_attrappe';

$aufraeumen = static function () use ($wurzel, $tmp): void {
    exec('rm -rf ' . escapeshellarg($wurzel));
    exec('rm -rf ' . escapeshellarg($tmp));
};
$aufraeumen();
register_shutdown_function($aufraeumen);

// ── 1. Die echte Klasse muss sich deklarieren lassen ───────────────────────
// Das ist der teuerste Fehler des ganzen Umbaus, und er faellt genau hier auf.
require_once $gwModul . '/module.php';
pruefe('Die echte Gateway-Klasse deklariert sich mit der Bruecke',
    class_exists('SymDoGateway'), true);
$noetig = ['ScanCreate', 'ScanApplyChanges', 'ScanRequestAction', 'ScanSignalGeben',
    'ScanAuftragGeben', 'ScanEinpflegen', 'ScannerInstanzen', 'ScannerAnlegen',
    'ScanErgebnisLesen', 'ScanAufraeumen', 'Belegung', 'KonfigID'];
pruefe('Und findet alles, was die Bruecke ruft',
    array_values(array_filter($noetig, static fn(string $m): bool => !method_exists('SymDoGateway', $m))), []);

/* Die Richtungsregel steht im Code, nicht nur im Kommentar: kein Aufruf einer
   Scanner-Praefixfunktion irgendwo im Gateway. Gesucht wird der AUFRUF samt
   Klammer — das Wort selbst steht in den Kommentaren der Bruecke. */
exec('grep -rlE "SDSC_[A-Za-z]+ *\(" ' . escapeshellarg($gwModul) . ' 2>/dev/null', $treffer);
pruefe('Das Gateway ruft keine Scanner-Funktion', $treffer, []);

/* Und es uebernimmt fremde Instanzen nicht: `IPS_ApplyChanges` wartet auf
   deren Spur. Genau EINE Stelle darf es — die frisch angelegte Instanz, die
   noch nichts tun kann. Wer eine zweite ergaenzt, soll hier stolpern und
   nachdenken; was dabei schiefgeht, steht weiter unten als Verhaltensprobe. */
pruefe('Nur eine einzige Stelle laesst uebernehmen',
    substr_count((string)file_get_contents($gwModul . '/libs/ScanBridge.php'), 'IPS_ApplyChanges('), 1);

// ── Beide Seiten als Module anmelden ───────────────────────────────────────
$gwJson = json_decode((string)file_get_contents($gwModul . '/module.json'), true);
$scJson = json_decode((string)file_get_contents(dirname($gwModul) . '/SymDoScanner/module.json'), true);

$anmelden = static function (string $ordner, string $guid, string $klasse) use ($tmp): void {
    $pfad = $tmp . '/' . $ordner;
    @mkdir($pfad, 0700, true);
    file_put_contents($pfad . '/module.json', (string)json_encode([
        'id' => $guid, 'name' => $klasse, 'type' => 3, 'vendor' => 'Stephan Sprick',
        'aliases' => [], 'parentRequirements' => [], 'childRequirements' => [],
        'implemented' => [], 'prefix' => 'BRUECKE',
    ]));
    file_put_contents($pfad . '/module.php', "<?php\n");
    IPS\ModuleLoader::loadSingleModule($pfad, '{BRUECKE-LIB}');
};
$anmelden('Gateway', (string)$gwJson['id'], 'BrueckeProbe');
$anmelden('Scanner', (string)$scJson['id'], 'ScannerAnDerBruecke');

$gw = IPS_CreateInstance((string)$gwJson['id']);
$gateway = IPS\InstanceManager::getInstanceInterface($gw);

// ── 2. Die Signalvariable ──────────────────────────────────────────────────
$signal = (int)@IPS_GetObjectIDByIdent('ScanSignal', $gw);
pruefe('Die Signalvariable steht am Gateway und ist versteckt',
    [$signal > 0, (bool)IPS_GetObject($signal)['ObjectIsHidden']], [true, true]);

$vorher = $gateway->pSignal();
$gateway->pSignalGeben();
pruefe('Klingeln erhoeht sie', $gateway->pSignal() - $vorher, 1);

// ── 3. Die Scanner-Instanzen ───────────────────────────────────────────────
/* Angelegt hat das Gateway sie schon beim Uebernehmen: ApplyChanges setzt
   dafuer einen Einmal-Zeitgeber, und die Attrappen fuehren den sofort aus
   (in Symcon vergehen ein paar Millisekunden — genau darum steht er dort,
   statt mitten im ApplyChanges zu laufen). Eine Instanz je Rolle, die schon
   eine Quelle hat; „briefing" ist noch leer und bekommt deshalb keine. */
$nachRolle = static function (array $liste, string $rolle): int {
    foreach ($liste as $id => $s) {
        if ($s['rolle'] === $rolle) {
            return (int)$id;
        }
    }
    return 0;
};
$scanner = $gateway->pScanner();
pruefe('Das Gateway legt seine Scanner beim Uebernehmen selbst an',
    [count($scanner),
     $scanner[$nachRolle($scanner, 'jobs')] ?? null,
     $scanner[$nachRolle($scanner, 'schule')] ?? null],
    [2, ['rolle' => 'jobs', 'quellen' => ['probe', 'auftrag']], ['rolle' => 'schule', 'quellen' => ['doku']]]);
pruefe('Danach fehlt nichts mehr', $gateway->pFehlt(), false);

$gateway->pAnlegen();
pruefe('Ein zweiter Durchgang legt nichts nach', count($gateway->pScanner()), 2);

$sc = $nachRolle($scanner, 'jobs');
pruefe('Der Scanner haengt am Gateway', (int)IPS_GetInstance($sc)['ConnectionID'], $gw);
pruefe('Und traegt einen Namen, der seine Rolle nennt',
    IPS_GetName($sc), 'SymDo - Scanner (jobs)');

/* Wer seinen Scanner loescht, soll beim naechsten Uebernehmen einen neuen
   bekommen — sonst liefe nach einem Versehen nie wieder ein Scan. */
IPS_DeleteInstance($sc);
pruefe('Ohne Scanner faellt das sofort auf', [$gateway->pFehlt(), count($gateway->pScanner())], [true, 1]);
$gateway->pAnlegen();
$scanner = $gateway->pScanner();
pruefe('Und er wird nachgelegt', count($scanner), 2);
$sc = $nachRolle($scanner, 'jobs');

// ── 4. Die ganze Kette, beide Seiten echt ──────────────────────────────────
$signalVor = $gateway->pSignal();
pruefe('Das Gateway legt einen Auftrag ab und klingelt',
    [$gateway->pAuftrag('probe', ['anlass' => 'timer']), $gateway->pSignal() - $signalVor],
    [true, 1]);
pruefe('Der Auftrag liegt im Kanal', $gateway->pStand()['auftraege'], 1);

/* Jetzt der Scanner. In Symcon weckt ihn die Signalvariable; die Attrappen
   stellen keine Nachrichten zu, also stossen wir seinen Takt an — der Weg
   danach ist derselbe. Der Weckruf zurueck ins Gateway laeuft dabei ECHT
   durch dessen RequestAction, und das pflegt ein. */
IPS_RequestAction($sc, 'Takt', 0);

pruefe('Ergebnis eingepflegt, Kanal wieder leer',
    [$gateway->pStand()['auftraege'], $gateway->pStand()['ergebnisse'], $gateway->pStand()['fehler']],
    [0, 0, 0]);

// ── 5. Was das Gateway (noch) nicht kennt ──────────────────────────────────
$fremd = ScanKanalCalc::LeererUmschlag('edu', $gw, $sc, time(), 'zu frueh');
$gateway->pSchreiben($fremd);
pruefe('Eine noch nicht umgezogene Quelle landet sichtbar im Fehlerordner',
    [$gateway->pEinpflegen(), $gateway->pStand()['ergebnisse'], $gateway->pStand()['fehler']],
    [0, 0, 1]);

// ── 6. Der Stapel wird nicht am Stueck abgearbeitet ────────────────────────
for ($i = 0; $i < 7; $i++) {
    usleep(1500);
    $gateway->pSchreiben(ScanKanalCalc::LeererUmschlag('probe', $gw, $sc, time(), 'Nr ' . $i));
}
pruefe('Sieben Umschlaege warten', $gateway->pStand()['ergebnisse'], 7);
/* Ein Durchgang nimmt fuenf und laesst den Rest einem Einmal-Zeitgeber. Die
   Attrappen fuehren den sofort aus — in Symcon liegen dazwischen die Hooks,
   und genau darum geht es. */
pruefe('Ein Durchgang nimmt fuenf, der Rest kommt hinterher',
    [$gateway->pEinpflegen(), $gateway->pStand()['ergebnisse']], [5, 0]);

// ── 7. Die Verweildauer der Selbstprobe kommt wirklich an ─────────────────
/* Sie fiel einmal lautlos aus dem Auftrag heraus (weisse Liste), und der
   Beweislauf des ganzen Umbaus mass danach gegen eine Spur, die gar nicht
   belegt war. Eine Sekunde Laufzeit im Pruefstand ist der Preis dafuer, dass
   das nicht noch einmal passiert. */
IPS_RequestAction($gw, 'ScanProbe', 1);
$los = microtime(true);
IPS_RequestAction($sc, 'Takt', 0);
$dauer = (int)round((microtime(true) - $los) * 1000);
$log = $gateway->pStand();
pruefe('Nach der Probe ist der Kanal wieder leer',
    [$log['auftraege'], $log['ergebnisse']], [0, 0]);
pruefe('Und sie hat die verlangte Sekunde wirklich verweilt',
    $dauer >= 1000 && $dauer < 5000, true);

// ── 8. Ein vorhandener Scanner wird uebernommen, nicht verdoppelt ─────────
/* Der Fall von heute: es steht schon eine Instanz da, angelegt bevor es die
   Eigenschaft „Rolle" gab. Sie bekommt die Rolle — und zwar OHNE dass das
   Gateway `IPS_ApplyChanges` auf sie ruft: das wuerde auf ihre Spur warten,
   und faehrt die gerade einen langen Scan, stuende solange die App. */
IPS_DeleteInstance($sc);
$hand = IPS_CreateInstance((string)$scJson['id']);   // Rolle '' wie vorher — die Rolle „jobs" fehlt jetzt
$handObj = IPS\InstanceManager::getInstanceInterface($hand);
$vorAnw = $handObj->anwendungen;
$markierung = rtrim(sys_get_temp_dir(), '/\\') . '/symdo_scankanal/' . $gw . '/nachtrag/' . $hand . '.json';

/* In Symcon loest IPS_ConnectInstance drueben ein ApplyChanges aus und der
   Griff liefe von selbst; die Attrappen setzen nur die Kennung. Also von
   Hand — geprueft wird ja, WAS er tut, nicht wer ihn anstoesst. */
$gateway->pAnlegen();

pruefe('Kein zweiter Scanner daneben', count($gateway->pScanner()), 2);
pruefe('Er heisst jetzt nach seiner Rolle', IPS_GetName($hand), 'SymDo - Scanner (jobs)');
pruefe('Das Gateway hat nur Bescheid gegeben, nicht uebernehmen lassen',
    [$handObj->anwendungen - $vorAnw, is_file($markierung)], [0, true]);

IPS_RequestAction($hand, 'Takt', 0);
pruefe('Der Scanner uebernimmt selbst und raeumt die Markierung weg',
    [$handObj->anwendungen > $vorAnw, is_file($markierung)], [true, false]);

// ── 9. Das Handbuch: der Umschlag des Baus wird eingepflegt ───────────────
/* Ohne einen eigenen Zweig faellt jede Rueckmeldung des Handbuch-Baus als
   „niemand zustaendig" in den Fehlerordner — und der Nutzer erfuehre nie,
   dass sein Verzeichnis steht. */
$gateway->pSchreiben(ScanKanalCalc::LeererUmschlag('doku', $gw, $hand, time(),
    'Handbuch-Verzeichnis fertig: 1356 Seiten, 4681 Abschnitte'));
pruefe('Die Rueckmeldung des Handbuch-Baus wird eingepflegt, nicht abgelegt',
    [$gateway->pEinpflegen(), $gateway->pStand()['ergebnisse'], $gateway->pStand()['fehler']],
    [1, 0, 1]);

// ── 10. Kein zweiter Bau im Gateway ───────────────────────────────────────
/* Der gefaehrlichste Fall des Umzugs: der alte Zeitgeber des Gateways laeuft
   weiter, waehrend der Scanner schon baut. Beide haengen dann an dieselben
   Zwischendateien an — Text und Vektor stehen dort Zeile fuer Zeile gepaart,
   und ein fremder Anhang dazwischen verschiebt die Paarung dauerhaft. Danach
   antwortet das Handbuch mit den Abschnitten der falschen Seiten.

   Die Probe traegt sich selbst: faellt die Wache weg, laeuft DokuTick in
   `VoiceCalls()` — eine Methode, die dieser Klasse fehlt. Der Fehler ist dann
   also sichtbar, statt sich als stiller Doppelbau zu verstecken. */
$gateway->pDokuTaktAn();
$wurf = '';
try {
    $gateway->pDokuTick();
} catch (\Throwable $e) {
    $wurf = $e->getMessage();
}
pruefe('Bedient ein Scanner die Quelle, baut das Gateway nicht mit',
    [$wurf, $gateway->pDokuTakt()], ['', 0]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
