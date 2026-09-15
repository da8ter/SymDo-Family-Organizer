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
    /**
     * Der Klassenseiten-Leser — im Rauchtest ohne Netz, also nur die Wege.
     *
     * Ueber Reflection und nicht ueber eine gelockerte Sichtbarkeit: was im
     * Produktivcode `private` ist, soll es bleiben. Eine Unterklasse kommt an
     * eine private Methode ihrer Elternklasse nicht heran.
     */
    public function pSeiten(array $seiten): array
    {
        // Seit PHP 8.1 greift Reflection ohne setAccessible — das ist seit 8.5
        // sogar abgekuendigt und wuerde hier nur eine Warnung erzeugen.
        return (array)(new ReflectionMethod(SymDoScanner::class, 'EduSeitenLesen'))
            ->invoke($this, $seiten);
    }

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
/* KEINE `parentRequirements` mehr — und das ist die Zusicherung, nicht ein
   vergessener Eintrag. Sie waren der einzige Weg zu einem Elternanschluss, und
   der Anschluss hat am 15.09.2026 das Gateway gekostet: die Konsole bietet
   beim Loeschen einer Instanz ihre uebergeordnete mit an. `implemented` bleibt
   stehen — es sagt nur, was der Scanner ist, und kostet nichts. Ausfuehrlich
   in SymDoGateway/tests/ElternanschlussTest.php. */
pruefe('Er verlangt keinen Elternknoten mehr, meldet sich aber',
    [$json['parentRequirements'], $json['implemented']], [[], [$schnitt]]);
pruefe('Und das Gateway bietet genau diese an',
    [$gwJson['childRequirements'], $gwJson['implemented']], [[$schnitt], [$schnitt]]);

// ── Kompositionsprobe ──────────────────────────────────────────────────────
/* JEDE Methode, die der Scanner ruft, muss es auch geben.
 *
 * Diese Liste wird aus dem QUELLTEXT abgeleitet und nicht von Hand gepflegt —
 * das ist der ganze Punkt. Eine Handliste prueft, woran jemand gedacht hat;
 * sie stand hier mit vierzehn Namen und war gruen, waehrend
 * `MoodleLesen::MoodleHttp` eine Gateway-Hilfe rief, die es in einer
 * Scanner-Instanz gar nicht gibt (`AiIsPublicUrl`). Der erste LOGINEO-Lauf aus
 * der zweiten Spur starb daran — „Call to undefined method", still, in einem
 * Zeitgeber, ohne Umschlag und ohne Meldung. Am 15.09.2026 am lebenden System
 * gefunden, nicht hier.
 *
 * Kommentare werden vorher entfernt: ein Methodenname in einer Erklaerung ist
 * kein Aufruf. */
$ohneKommentare = static function (string $php): string {
    $raus = '';
    foreach (token_get_all($php) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                continue;
            }
            $raus .= $t[1];
            continue;
        }
        $raus .= $t;
    }
    return $raus;
};
/* Und die DATEILISTE wird ebenfalls abgeleitet — aus den `require_once` des
   Moduls. Sie stand hier zuerst von Hand, mit acht Eintraegen, und beim naechsten
   Umzug (WebUntis) fehlte der neunte: der Riegel war gruen, waehrend
   `UntisLesen` eine Methode rief, die es in einer Scanner-Instanz nicht gibt.
   Zweimal derselbe Fehler an derselben Stelle — deshalb jetzt gar keine Liste
   mehr. */
$konstanten = (new ReflectionClass('SymDoScanner'))->getConstants();
$wurzel = dirname(__DIR__, 2) . '/';
preg_match_all('/require_once __DIR__ \. \'([^\']+)\'/',
    (string)file_get_contents($wurzel . 'SymDoScanner/module.php'), $req);
$teile = ['SymDoScanner/module.php'];
foreach ($req[1] as $pfad) {
    /* '/../libs/X.php' und '/../SymDoGateway/libs/X.php' — beide relativ zum
       Modulordner. */
    $teile[] = ltrim(str_replace('/../', '', $pfad), '/');
}
$fehlend = [];
foreach ($teile as $datei) {
    $q = (string)@file_get_contents($wurzel . $datei);
    if ($q === '') {
        $fehlend[] = 'Datei nicht lesbar: ' . $datei;
        continue;
    }
    /* Nur was in die Klasse KOMPONIERT wird. Eine eigene Klasse (AiJobRunner)
       bringt ihre Methoden selbst mit; ihr `$this` ist ein anderes. */
    if ($datei !== 'SymDoScanner/module.php' && !preg_match('/^\s*trait\s+\w+/m', $q)) {
        continue;
    }
    $rein = $ohneKommentare($q);
    preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $rein, $m);
    foreach (array_unique($m[1]) as $name) {
        if (!method_exists('SymDoScanner', $name)) {
            $fehlend[] = $name . '() (' . basename($datei) . ')';
        }
    }
    /* KONSTANTEN genauso. Eine undefinierte Konstante ist derselbe stille
       Fatal wie eine undefinierte Methode — und genau daran ist der
       WebUntis-Umzug gescheitert, NACHDEM dieser Riegel die Methoden schon
       prueifte: `self::UNTIS_HTTP_FRIST` stand in `WebUntis.php`, das eine
       Scanner-Instanz nicht hat. Zweimal derselbe Tod, einmal ueber eine
       Methode und einmal ueber eine Konstante. */
    preg_match_all('/self::([A-Z][A-Z0-9_]*)\b/', $rein, $c);
    foreach (array_unique($c[1]) as $name) {
        if (!array_key_exists($name, $konstanten)) {
            $fehlend[] = 'self::' . $name . ' (' . basename($datei) . ')';
        }
    }
}
sort($fehlend);
pruefe('Der Scanner findet alles, was er ruft', $fehlend, []);
/* Und die Probe muss beissen: ein erfundener Name darf nicht durchgehen. */
pruefe('Die Probe erkennt eine fehlende Methode',
    method_exists('SymDoScanner', 'GibtEsNichtAlsMethode'), false);
pruefe('… und eine fehlende Konstante',
    array_key_exists('GIBT_ES_NICHT', $konstanten), false);
/* Und die Dateiliste muss vollstaendig sein: sie kommt aus den `require_once`
   des Moduls, nicht aus einer Handnotiz. */
pruefe('Jede eingebundene Datei wird geprueft', count($teile) >= 9, true);

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
$anmelden('Scanner', $json['id'], 'ScannerProbe', ['implemented' => [$schnitt]]);

$gw = IPS_CreateInstance((string)$gwJson['id']);
$sc = IPS_CreateInstance((string)$json['id']);
$scanner = IPS\InstanceManager::getInstanceInterface($sc);
$gateway = IPS\InstanceManager::getInstanceInterface($gw);

/* Er haengt NICHT am Gateway — und findet es trotzdem. Genau darum geht es:
   der Anschluss ist weg, die Zuordnung bleibt (niedrigste Gateway-Kennung).
   Vor dem 15.09.2026 stand hier die umgekehrte Zusicherung. */
pruefe('Der Scanner haengt nicht am Gateway',
    (int)IPS_GetInstance($sc)['ConnectionID'], 0);

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

// ── Keine Wartezeit an einer Gateway-Sperre ────────────────────────────────
/* Alle Arbeit des Gateways laeuft in EINER Spur. Eine Sperre, die dort belegt
   ist, kann in derselben Spur nicht frei werden — das Warten laeuft also
   garantiert ins Leere und verzoegert nur. Jede Stelle beantwortet ein
   besetztes Schloss ohnehin mit „busy"; das ist die richtige Antwort, und zwar
   sofort. Vor dem 14.09.2026 standen hier 300 bis 10 000 ms.

   Wer eine Sperre spaeter AUCH aus der Scanner-Spur nimmt, muss sie auf
   KonfigID() taufen und darf dort warten — dieser Riegel gilt nur fuer den
   Gateway-Ordner. */
$sperrZeilen = [];
exec('grep -rnE "IPS_SemaphoreEnter\\([^,()]+, *[1-9]" '
    . escapeshellarg(dirname($modul) . '/SymDoGateway') . ' 2>/dev/null', $sperrZeilen);
pruefe('Keine Gateway-Sperre wartet', $sperrZeilen, []);

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

// ── Klassenseiten lesen ───────────────────────────────────────────────────
/* Ohne Netz laesst sich der Zerleger hier nicht fahren — geprueft wird der
   Weg, auf dem eine Seite NICHT in den Umschlag kommt. Das ist der wichtige:
   das Gateway gleicht je Seite ab, welche Karten verschwunden sind, und eine
   leere Seite hiesse dort „alle Karten dieser Klassenseite sind weg". */
pruefe('Ohne eingerichtete Seiten kommt nichts heraus',
    $scanner->pSeiten([])['seiten'], []);

$nichtErreichbar = $scanner->pSeiten([
    ['name' => '5b', 'url' => 'https://gibt-es-nicht.invalid/seite', 'userId' => 'u1'],
]);
pruefe('Eine unlesbare Seite kommt NICHT in den Umschlag', $nichtErreichbar['seiten'], []);
pruefe('… sie wird aber gemeldet', count($nichtErreichbar['fehler']), 1);
/* Die ADRESSE gehoert nicht in die Meldung: sie ist der Zugang zur
   Klassenseite, und das Protokoll ist weltlesbar. */
pruefe('… ohne die Adresse zu nennen',
    str_contains($nichtErreichbar['fehler'][0], 'gibt-es-nicht.invalid'), false);
pruefe('… und der Name steht davor',
    str_starts_with($nichtErreichbar['fehler'][0], '5b: '), true);

/* Der rohe Seitenrumpf wird ZWEIMAL gebraucht: fuer die Karten und fuer
   Verweise auf ANDERE Anlagen. Nur der Scanner hat ihn — das Gateway bekommt
   die Karten. Faellt die Verweis-Suche hier weg, findet niemand mehr eine neu
   verlinkte Klassenseite, ohne Fehler und ohne Meldung. Ein Prueflauf mit Netz
   ginge hier nicht, deshalb am Quelltext. Von den Pruefagenten gefunden. */
$leser = (string)file_get_contents($modul . '/module.php');
$lVon = (int)strpos($leser, 'private function EduSeitenLesen(');
$lBis = (int)strpos($leser, '    private function ', $lVon + 10);
$lesen = substr($leser, $lVon, $lBis - $lVon);
pruefe('Der Leser holt die Verweise aus dem rohen Rumpf',
    str_contains($lesen, 'EduKartenLinks($rumpf, $url)'), true);
pruefe('… und legt sie in den Umschlag', str_contains($lesen, "'funde'"), true);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
