<?php

declare(strict_types=1);

/**
 * Der Rauchtest des Scanners — und zugleich der Beweis A des Umbaus
 * „Gateway frei halten", nur offline statt im Docker.
 *
 * Er setzt die ECHTE Klasse SymDoScanner gegen die Symcon-Attrappen zusammen
 * und laesst sie einen Auftrag ausfuehren. Drei Dinge lassen sich nur so
 * finden, nie durch Lesen:
 *
 * 1. Ob die drei Traits (Konfig, Belegung, ScanKanal) in EINER Klasse
 *    zusammenpassen. Eine Namenskollision zwischen Traits ist ein Fatal, und
 *    ein Fatal in dieser Bibliothek nimmt jede andere Instanz mit.
 * 2. Ob der Klassenname zur module.json passt und die Schnittstellen-GUID zum
 *    Gateway. Symcon erzeugt die Klasse aus `name` ohne Leerzeichen; am
 *    29.08.2026 legte ein Bindestrich darin die ganze Bibliothek lahm.
 * 3. Ob der Weg traegt: Auftrag -> Lauf -> Umschlag im Kanal -> Weckruf ans
 *    Gateway. Dafuer bekommt der Test eine Gateway-Attrappe, die denselben
 *    Kanal liest und den Weckruf mitschreibt.
 *
 *   php SymDoScanner/tests/ScannerSmokeTest.php
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
require_once __DIR__ . '/../module.php';

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
 * Der echte Scanner, nur mit einer Uhr: die Attrappen verlangen `getTime()`
 * vom Modul unter Test (sie bauen daraus ihre Timer-Buchhaltung). Alles
 * andere — Create, ApplyChanges, der ganze Lauf — ist der Originalcode.
 */
final class ScannerProbe extends SymDoScanner
{
    public function pTakt(): int { return (int)$this->GetTimerInterval('Takt'); }

    protected function getTime(): int
    {
        return time();
    }
}

/**
 * Die Gegenseite, absichtlich winzig: der Rauchtest prueft den Scanner, nicht
 * das Gateway. Sie liest denselben Kanal und schreibt jeden Weckruf mit.
 */
final class GatewayAttrappe extends IPSModuleStrict
{
    use Konfig;
    use Belegung;
    use ScanKanal;

    /** @var list<string> jeder Weckruf, den der Scanner geschickt hat */
    public static array $gerufen = [];

    public function RequestAction(string $Ident, mixed $Value): void
    {
        self::$gerufen[] = $Ident;
    }

    public function gAblegen(string $q, array $a): bool { return $this->ScanAuftragAblegen($q, $a); }
    public function gListe(string $q = ''): array { return $this->ScanErgebnisse($q); }
    public function gLesen(string $p): ?array { return $this->ScanErgebnisLesen($p); }
    public function gWeg(string $p): void { $this->ScanErgebnisWeg($p); }
    public function gOffen(array $q): int { return $this->ScanAuftraegeOffen($q); }

    protected function getTime(): int
    {
        return time();
    }
}

$modul    = dirname(__DIR__);
$kernel   = rtrim(sys_get_temp_dir(), '/\\');
$wurzel   = $kernel . '/symdo_scankanal';
$attrappe = $kernel . '/symdo_scanner_attrappe';
$marker   = $kernel . '/symdo-messung.an';
$log      = $kernel . '/symdo-belegung.log';

/* Eine fremde Messung nicht anfassen: laeuft gerade eine, wird der
   Belegungsteil uebersprungen statt sie zu verfaelschen. */
$messenErlaubt = !is_file($marker);

$aufraeumen = static function () use ($wurzel, $attrappe, $marker, $log, $messenErlaubt): void {
    exec('rm -rf ' . escapeshellarg($wurzel));
    exec('rm -rf ' . escapeshellarg($attrappe));
    if ($messenErlaubt) {
        @unlink($marker);
        @unlink($log);
    }
};
$aufraeumen();
register_shutdown_function($aufraeumen);

// ── module.json: die Regeln des Hauses ─────────────────────────────────────
$json     = json_decode((string)file_get_contents($modul . '/module.json'), true);
$gwJson   = json_decode((string)file_get_contents(dirname($modul) . '/SymDoGateway/module.json'), true);
$schnitt  = '{5A13BDF9-E069-4AA9-9D9B-35C1BDC785B8}';

pruefe('Der Klassenname folgt aus name ohne Leerzeichen',
    [str_replace(' ', '', (string)$json['name']), class_exists('SymDoScanner')],
    ['SymDoScanner', true]);
pruefe('Typ, Praefix, Hersteller, keine Aliase',
    [$json['type'], $json['prefix'], $json['vendor'], $json['aliases']],
    [3, 'SDSC', 'Stephan Sprick', []]);
/* Beides zusammen, sonst meldet Symcon „inkompatibel": der Scanner VERLANGT
   die Schnittstelle des Gateways und meldet sie selbst — wie das VRR-Modul. */
pruefe('Er verlangt die Gateway-Schnittstelle und meldet sie',
    [$json['parentRequirements'], $json['implemented']], [[$schnitt], [$schnitt]]);
pruefe('Und das Gateway bietet genau diese an',
    [$gwJson['childRequirements'], $gwJson['implemented']], [[$schnitt], [$schnitt]]);

// ── Kompositionsprobe ──────────────────────────────────────────────────────
$noetig = ['KonfigID', 'BestandID', 'AiProp', 'Belegung', 'ScanDir', 'ScanAuftragAblegen',
    'ScanAuftraegeOffen', 'ScanAuftragNehmen', 'ScanAnspruchVerwaist', 'ScanErgebnisSchreiben',
    'ScanErgebnisse', 'ScanErgebnisLesen', 'ScanAufraeumen', 'ScanStand'];
$fehlend = array_values(array_filter($noetig, static fn(string $m): bool => !method_exists('SymDoScanner', $m)));
pruefe('Der Scanner findet alles, was er ruft', $fehlend, []);

/* Der Scanner bringt bewusst KEINE Hooks mit. Er darf Fachteile aus dem
   Gateway-Ordner einbinden — aber nie AppCore oder den Router: mit denen
   kaeme die Hook-Anmeldung in die zweite Spur, und beide Instanzen stritten
   um dieselben Pfade. */
preg_match_all('/require_once __DIR__ \. \'([^\']+)\'/', (string)file_get_contents($modul . '/module.php'), $t);
$verboten = array_values(array_filter($t[1], static fn(string $d): bool =>
    (bool)preg_match('#(AppCore|ApiRouter|WebPush|DeviceRegistry)\.php$#', $d)));
pruefe('Bindet nichts ein, was Hooks mitbraechte', $verboten, []);
pruefe('Und die geteilten Grundlagen schon',
    array_values(array_intersect($t[1],
        ['/../libs/Konfig.php', '/../libs/Belegung.php', '/../libs/ScanKanal.php'])),
    ['/../libs/Konfig.php', '/../libs/Belegung.php', '/../libs/ScanKanal.php']);

// ── Zwei Instanzen, Gateway zuerst, damit der Scanner es findet ────────────
// Die Attrappen erzeugen die Klasse aus der module.json. Beide Klassen stehen
// schon oben in dieser Datei; die Wegwerf-module.php bleibt deshalb leer.
$anmelden = static function (string $ordner, string $guid, string $klasse, array $json) use ($attrappe): void {
    $pfad = $attrappe . '/' . $ordner;
    @mkdir($pfad, 0700, true);
    file_put_contents($pfad . '/module.json', (string)json_encode($json + [
        'id'                 => $guid,
        'name'               => $klasse,
        'type'               => 3,
        'vendor'             => 'Stephan Sprick',
        'aliases'            => [],
        'parentRequirements' => [],
        'childRequirements'  => [],
        'implemented'        => [],
        'prefix'             => 'SMOKE',
    ], JSON_PRETTY_PRINT));
    file_put_contents($pfad . '/module.php', "<?php\n");
    IPS\ModuleLoader::loadSingleModule($pfad, '{SMOKE-LIB}');
};
$anmelden('Gateway', $gwJson['id'], 'GatewayAttrappe', ['implemented' => [$schnitt]]);
$anmelden('Scanner', $json['id'], 'ScannerProbe', ['parentRequirements' => [$schnitt]]);

$gw = IPS_CreateInstance((string)$gwJson['id']);
$sc = IPS_CreateInstance((string)$json['id']);
$scanner = IPS\InstanceManager::getInstanceInterface($sc);
$gateway = IPS\InstanceManager::getInstanceInterface($gw);

pruefe('Der Scanner haengt nach dem ersten Uebernehmen am Gateway',
    (int)IPS_GetInstance($sc)['ConnectionID'], $gw);

$stand = json_decode($scanner->Stand(), true);
pruefe('Er kennt heute nur die Selbstprobe und sein Gateway',
    [$stand['quellen'], $stand['gateway'], $stand['wartend'], $stand['laeuft']],
    [['probe'], $gw, 0, '']);

// ── Was er ablehnt ─────────────────────────────────────────────────────────
pruefe('Eine unbekannte Quelle lehnt er ab', $scanner->Auftrag('unfug', ''), 'Unknown source');
pruefe('Eine fremde Quelle ebenso — sie gehoert einer anderen Instanz',
    $scanner->Auftrag('edu', '{}'), 'This instance does not handle that source');
pruefe('Und nichts davon ist im Kanal gelandet', count($gateway->gListe()), 0);

// ── Beweis A: von Hand, ohne die Gateway-Spur zu belegen ───────────────────
if ($messenErlaubt) {
    touch($marker);
}
GatewayAttrappe::$gerufen = [];
pruefe('Die Selbstprobe meldet, was sie getan hat',
    $scanner->Auftrag('probe', '{}'), 'Channel checked (0 s dwell, reason hand)');

$liste = $gateway->gListe();
pruefe('Das Gateway sieht genau einen Umschlag', count($liste), 1);
$umschlag = $gateway->gLesen($liste[0]);
pruefe('Er traegt Quelle, Scanner, Gateway und den Anlass „von Hand"',
    [$umschlag['quelle'], $umschlag['scanner'], $umschlag['gateway'],
        $umschlag['status']['ok'], $umschlag['anlass']],
    ['probe', $sc, $gw, true, 'hand']);
pruefe('Und der Scanner hat das Gateway geweckt', GatewayAttrappe::$gerufen, ['ScanInbox']);

if ($messenErlaubt) {
    $zeilen = array_values(array_filter(explode("\n", (string)@file_get_contents($log))));
    $ids    = array_values(array_unique(array_map(
        static fn(string $z): int => (int)(explode("\t", $z)[1] ?? 0), $zeilen)));
    pruefe('Jede Belegungszeile steht unter der Kennung des SCANNERS', $ids, [$sc]);
} else {
    printf("%-4s %s\n", '--', 'Belegung uebersprungen: eine Messung laeuft bereits');
}

$gateway->gWeg($liste[0]);

// ── Der passive Weg: Auftragsdatei statt Aufruf ────────────────────────────
GatewayAttrappe::$gerufen = [];
pruefe('Das Gateway legt einen Auftrag ab', $gateway->gAblegen('probe', ['anlass' => 'timer']), true);
pruefe('Der Scanner sieht ihn warten',
    json_decode($scanner->Stand(), true)['wartend'], 1);

IPS_RequestAction($sc, 'Takt', 0);

$liste = $gateway->gListe();
pruefe('Ein Takt holt ihn ab und legt das Ergebnis in den Kanal', count($liste), 1);
$umschlag = $gateway->gLesen($liste[0]);
pruefe('Diesmal mit dem Anlass des Zeitgebers und der Auftragszeit',
    [$umschlag['anlass'], ($umschlag['auftragAt'] ?? 0) > 0], ['timer', true]);
pruefe('Danach wartet nichts mehr', $gateway->gOffen(['probe']), 0);
pruefe('Auch hier ging der Weckruf ans Gateway', GatewayAttrappe::$gerufen, ['ScanInbox']);

$gateway->gWeg($liste[0]);

// Ein Takt ohne Auftrag darf NICHTS schreiben — sonst liefe der Kanal voll.
IPS_RequestAction($sc, 'Takt', 0);
pruefe('Ein Leertakt schreibt nichts', count($gateway->gListe()), 0);

// ── Die Richtungsregel ─────────────────────────────────────────────────────
// Das Gateway darf nie synchron in den Scanner rufen: es wuerde auf dessen
// Spur warten und damit genau das Problem verschieben, das der Umbau loest.
// Gesucht wird der AUFRUF (Praefix samt Klammer), nicht das Wort: in den
// Kommentaren der Bruecke steht die Regel ja ausgeschrieben.
exec('grep -rlE "SDSC_[A-Za-z]+ *\\(" ' . escapeshellarg(dirname($modul) . '/SymDoGateway') . ' 2>/dev/null', $treffer);
pruefe('Keine Gateway-Datei ruft eine Scanner-Funktion', $treffer, []);

// ── Ohne Gateway stumm, aber nicht tot ─────────────────────────────────────
/* Die Instanzliste des Gateways kommt waehrend eines Modul-Neuladens kurz
   leer zurueck — das Gateway selbst faengt genau diese Falle ab. Faellt das
   Uebernehmen des Scanners in dieses Fenster, DARF der Takt nicht ausgehen:
   er ist der einzige Rueckweg, denn hereinrufen darf hier niemand. */
IPS_DeleteInstance($gw);
IPS_ApplyChanges($sc);
pruefe('Ohne Gateway laeuft der Takt weiter — er ist der einzige Rueckweg',
    $scanner->pTakt() > 0, true);
pruefe('Und ein Lauf schreibt trotzdem nichts',
    json_decode($scanner->Stand(), true)['gateway'], 0);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
