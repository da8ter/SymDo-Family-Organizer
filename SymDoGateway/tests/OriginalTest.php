<?php

declare(strict_types=1);

/**
 * Pruefstand: die Originale der KI-Vorschlaege (24.09.2026).
 *
 * Drei Teile:
 *  1. OriginalCalc — Kennung, Text, Anhangsart, Namen, Briefpapier, Aufraeumen.
 *  2. OriginalStore — die Ordner auf der Platte: Rechte, Rundreise, Stuecke,
 *     abgeleitete Originale, Aufraeumen halbfertiger Ordner.
 *  3. Das Gateway in Kurzform (MailScan + Originale): Ablegen beim Einpflegen,
 *     die Aktionen original/originalTeil/originalBehalten, die REST-Route,
 *     „Loeschen", das Aufraeumen mit und ohne Verweise, der Widerruf.
 *
 *   php SymDoGateway/tests/OriginalTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/MailAnalyseCalc.php';
require_once __DIR__ . '/../../libs/AiJobStore.php';
require_once __DIR__ . '/../libs/MailScan.php';
require_once __DIR__ . '/../libs/Originale.php';

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
    printf("%-4s %-78s%s\n", $ok ? 'OK' : 'FEHL', $name, $ok ? '' : "\n     ist:  $a\n     soll: $b");
}
function tmpOrdner(string $wofuer): string
{
    $d = rtrim(sys_get_temp_dir(), '/\\') . '/symdo_orig_' . $wofuer . '_' . bin2hex(random_bytes(4));
    register_shutdown_function(static function () use ($d): void {
        exec('rm -rf ' . escapeshellarg($d));
    });
    return $d;
}
$pdf = "%PDF-1.4\n" . str_repeat('A', 3000);
$jpg = "\xFF\xD8\xFF\xE0" . str_repeat('B', 5000);
$png = "\x89PNG\r\n\x1a\n" . str_repeat('C', 2000);

// ══ 1. OriginalCalc ═══════════════════════════════════════════════════════
$voll = str_repeat('Lernzeitplan Klasse 1a. ', 400);           // weit ueber 4000 Zeichen
pruefe('Kennung: 24 Hexziffern', preg_match('/^[0-9a-f]{24}$/', OriginalCalc::Kennung('edu:1:2', $voll, 4000)), 1);
pruefe('Kennung: gleich fuer vollen Text und die 4000 Zeichen aus dem Auftrag',
    OriginalCalc::Kennung('edu:1:2', $voll, 4000) === OriginalCalc::Kennung('edu:1:2', mb_substr($voll, 0, 4000), 4000), true);
pruefe('Kennung: eine wiederverwendete Vorschlags-ID mit anderem Text gibt eine andere Kennung',
    OriginalCalc::Kennung('77:42', 'Brief A', 4000) === OriginalCalc::Kennung('77:42', 'Brief B', 4000), false);
pruefe('KennungGueltig lehnt Pfad, Grossbuchstaben, Laenge und Nicht-Text ab',
    array_map([OriginalCalc::class, 'KennungGueltig'], ['../x', str_repeat('A', 24), str_repeat('a', 23),
        str_repeat('a', 25), '', 123, null, str_repeat('0', 24)]),
    [false, false, false, false, false, false, false, true]);
pruefe('NeueKennung ist gueltig und zufaellig',
    [OriginalCalc::KennungGueltig(OriginalCalc::NeueKennung()), OriginalCalc::NeueKennung() !== OriginalCalc::NeueKennung()], [true, true]);

$kaputt = "Gr\xFC\xDFe aus der Schule\r\nZeile 2\0";
pruefe('TextSauber: Latin-1 wird UTF-8, CRLF wird LF, NUL faellt', OriginalCalc::TextSauber($kaputt), "Grüße aus der Schule\nZeile 2");
$lang = OriginalCalc::TextSauber(str_repeat('ä', 20000));
pruefe('TextSauber: gedeckelt auf 32 KB und dabei gueltiges UTF-8',
    [strlen($lang) <= OriginalCalc::TEXT_MAX_BYTES, mb_check_encoding($lang, 'UTF-8')], [true, true]);

pruefe('AnhangArt allein an den Magic Bytes',
    [OriginalCalc::AnhangArt($pdf), OriginalCalc::AnhangArt($jpg), OriginalCalc::AnhangArt($png),
     OriginalCalc::AnhangArt('GIF89a...'), OriginalCalc::AnhangArt('')],
    ['pdf', 'jpg', 'png', '', '']);
pruefe('NameSauber: kein Pfad, keine Steuerzeichen, Rueckfall mit Nummer',
    [OriginalCalc::NameSauber('../../etc/passwd', 'pdf', 1), OriginalCalc::NameSauber("Brief\x07.pdf", 'pdf', 1),
     OriginalCalc::NameSauber('', 'pdf', 2), OriginalCalc::NameSauber('..', 'jpg', 3),
     mb_strlen(OriginalCalc::NameSauber(str_repeat('x', 300), 'pdf', 1))],
    ['passwd', 'Brief.pdf', 'Anhang 2.pdf', 'Anhang 3.jpg', 120]);

$namen = AnhangGrenzenCalc::Wirksam([])['decoNames'];
pruefe('IstDeko: PDFs nie, namenlose und Layout-Namen immer',
    [OriginalCalc::IstDeko('logo.pdf', 'pdf', 9, $namen, 2), OriginalCalc::IstDeko('', 'jpg', 0, $namen, 2),
     OriginalCalc::IstDeko('Logo_GGS.png', 'png', 0, $namen, 2), OriginalCalc::IstDeko('image001.jpg', 'jpg', 0, $namen, 2)],
    [false, true, true, true]);
pruefe('IstDeko: ein Scan beim ersten Mal nicht — beim zweiten Mal als Briefpapier',
    [OriginalCalc::IstDeko('Lernzeitplan.jpg', 'jpg', 0, $namen, 2), OriginalCalc::IstDeko('Lernzeitplan.jpg', 'jpg', 1, $namen, 2)],
    [false, true]);
pruefe('IstDeko: Wiederholungsregel aus (0) und eigene Namen',
    [OriginalCalc::IstDeko('Scan.jpg', 'jpg', 5, $namen, 0), OriginalCalc::IstDeko('Kopfzeile-Schule.jpg', 'jpg', 0, ['kopfzeile'], 0)],
    [false, true]);

$h = static fn(string $s): string => sha1($s);
$karte = OriginalCalc::Gedaechtnis([], [$h('a'), $h('a'), $h('b'), 'kein-hash'], 100);
pruefe('Gedaechtnis: je Original einmal gezaehlt, Unsinn faellt', [$karte[$h('a')][0] ?? 0, $karte[$h('b')][0] ?? 0, count($karte)], [1, 1, 2]);
$karte = OriginalCalc::Gedaechtnis($karte, [$h('a')], 200);
pruefe('Gedaechtnis: zaehlt weiter und merkt sich den Zeitpunkt', $karte[$h('a')], [2, 200]);
$voll5 = OriginalCalc::Gedaechtnis([], [$h('1')], 1);
foreach (['2', '3', '4', '5'] as $i => $x) {
    $voll5 = OriginalCalc::Gedaechtnis($voll5, [$h($x)], 2 + $i, 3);
}
pruefe('Gedaechtnis: gedeckelt, die am laengsten nicht gesehenen fallen', array_keys($voll5), [$h('5'), $h('4'), $h('3')]);

pruefe('Teile', [OriginalCalc::Teile(0, 100), OriginalCalc::Teile(100, 100), OriginalCalc::Teile(101, 100)], [1, 1, 2]);

$d = ['e' => ['mtime' => 10, 'bytes' => 5], 'alt' => ['mtime' => 10, 'bytes' => 5],
      'frisch' => ['mtime' => 9990, 'bytes' => 5], 'weg' => ['mtime' => 9990, 'bytes' => 5],
      'v1' => ['mtime' => 100, 'bytes' => 60], 'v2' => ['mtime' => 200, 'bytes' => 60], 'v3' => ['mtime' => 300, 'bytes' => 60]];
$weg = OriginalCalc::Wegraeumen($d, ['v1' => true, 'v2' => true, 'v3' => true], ['e' => true], ['weg' => true], 10000, 3600, 100);
sort($weg);
pruefe('Wegraeumen: Eintrag bleibt immer, Altes ohne Verweis faellt, Frisches wartet, Verworfenes sofort, Deckel nimmt die aeltesten Vorschlags-Originale',
    $weg, ['alt', 'v1', 'v2', 'weg']);
pruefe('Wegraeumen: unter dem Deckel bleiben alle Vorschlags-Originale',
    OriginalCalc::Wegraeumen(['v1' => ['mtime' => 1, 'bytes' => 10]], ['v1' => true], [], [], 10000, 3600, 100), []);

// ══ 2. OriginalStore ══════════════════════════════════════════════════════
$dir = tmpOrdner('ablage');
$ablage = OriginalStore::in($dir);
pruefe('Der Ordner entsteht mit 0700', substr(sprintf('%o', fileperms($dir)), -3), '700');
$id = OriginalCalc::Kennung('77:42', 'Text', 4000);
$satz = OriginalCalc::Satz(['id' => $id, 'proposalId' => '77:42', 'text' => 'Text', 'subject' => 'Ausflug',
    'atts' => [['n' => 1, 'name' => 'brief.pdf', 'kind' => 'pdf', 'bytes' => strlen($pdf)],
               ['n' => 2, 'name' => 'foto.jpg', 'kind' => 'jpg', 'bytes' => strlen($jpg)]]]);
pruefe('anlegen', $ablage->anlegen($id, $satz, [1 => $pdf, 2 => $jpg]), true);
pruefe('lesen: Rundreise', [$ablage->lesen($id)['subject'] ?? null, count($ablage->lesen($id)['atts'] ?? [])], ['Ausflug', 2]);
pruefe('Rechte: Ordner 0700, Dateien 0600',
    [substr(sprintf('%o', fileperms("$dir/$id")), -3), substr(sprintf('%o', fileperms("$dir/$id/original.json")), -3),
     substr(sprintf('%o', fileperms("$dir/$id/1.pdf")), -3)], ['700', '600', '600']);
pruefe('kein tmp-Rest', array_values(array_filter(scandir($dir), static fn($n) => str_starts_with($n, '.tmp-'))), []);
pruefe('Ersetzen gelingt (zweites anlegen)', $ablage->anlegen($id, $satz, [1 => $pdf, 2 => $jpg]), true);
pruefe('lesen: ungueltige oder unbekannte Kennung', [$ablage->lesen('../etc'), $ablage->lesen(str_repeat('f', 24))], [null, null]);

$gross = random_bytes(2621440);                                   // 2,5 MB
$idG = OriginalCalc::NeueKennung();
$ablage->anlegen($idG, OriginalCalc::Satz(['id' => $idG, 'atts' => [['n' => 1, 'name' => 'scan.pdf', 'kind' => 'pdf', 'bytes' => strlen($gross)]]]),
    [1 => $gross]);
$zusammen = '';
$teile = 0;
for ($k = 0; $k < 5; $k++) {
    $t = $ablage->teilLesen($idG, 1, $k, 1048576);
    if ($t === null) {
        break;
    }
    $teile = $t['teile'];
    $zusammen .= $t['daten'];
}
pruefe('teilLesen: 2,5 MB in drei Stuecken, Byte fuer Byte zusammengesetzt', [$teile, $k, $zusammen === $gross], [3, 3, true]);
pruefe('teilLesen: Nummer oder Stueck ausserhalb ⇒ null',
    [$ablage->teilLesen($idG, 1, 3, 1048576), $ablage->teilLesen($idG, 2, 0, 1048576), $ablage->teilLesen($idG, 0, 0, 1048576)],
    [null, null, null]);
pruefe('dateiLesen: nur unter der Grenze', [$ablage->dateiLesen($idG, 1, 1000), strlen($ablage->dateiLesen($idG, 1, 3000000)['daten'] ?? '')],
    [null, strlen($gross)]);

$neu = OriginalCalc::NeueKennung();
$satzNeu = OriginalCalc::Satz(array_merge($satz, ['id' => $neu, 'abgeleitetVon' => $id,
    'atts' => [['n' => 2, 'name' => 'foto.jpg', 'kind' => 'jpg', 'bytes' => strlen($jpg)]]]));
pruefe('ableiten: nur die gewaehlte Datei', [$ablage->ableiten($id, $neu, $satzNeu, [2]), is_file("$dir/$neu/2.jpg"), is_file("$dir/$neu/1.pdf")],
    [true, true, false]);
pruefe('ableiten: harter Verweis (gleicher Inode) oder Kopie mit gleichem Inhalt',
    fileinode("$dir/$neu/2.jpg") === fileinode("$dir/$id/2.jpg") || file_get_contents("$dir/$neu/2.jpg") === $jpg, true);
pruefe('ableiten: das Quell-Original bleibt unberuehrt', count($ablage->lesen($id)['atts'] ?? []), 2);

mkdir("$dir/.tmp-" . str_repeat('a', 24) . '-dead', 0700);
touch("$dir/.tmp-" . str_repeat('a', 24) . '-dead', time() - 7200);
$alle = $ablage->alle();
pruefe('alle: drei Originale mit Groesse, der alte tmp-Rest ist weg',
    [count($alle), ($alle[$idG]['bytes'] ?? 0) > 2621440, is_dir("$dir/.tmp-" . str_repeat('a', 24) . '-dead')], [3, true, false]);
pruefe('loeschen', [$ablage->loeschen($neu), is_dir("$dir/$neu"), $ablage->loeschen('../x')], [true, false, false]);

// ══ 3. Das Gateway in Kurzform ════════════════════════════════════════════
final class OriginalProbe extends IPSModuleStrict
{
    use MailScan;
    use Originale;

    // Die Adressliste der KI-Inbox (MailIntakePublic) fragt die Mitglieder.
    private function LoadUsers(): array { return []; }
    public array $attr = [];
    public array $kaputt = [];            // Attribute, deren Lesen scheitert
    public string $originalDir = '';
    public string $jobDir = '';
    public bool $einwilligung = true;
    public array $grenzen = [];
    public array $todo = [];              // ToDo-Instanzen: id => items|null (nicht bereit)
    public array $fehlerApi = [];

    protected function ReadAttributeString(string $Name): string
    {
        if (in_array($Name, $this->kaputt, true)) {
            throw new RuntimeException('Attribut nicht lesbar');
        }
        return (string)($this->attr[$Name] ?? '');
    }
    protected function WriteAttributeString(string $Name, string $Value): bool { $this->attr[$Name] = $Value; return true; }
    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    protected function RegisterOnceTimer(string $Ident, string $ScriptText): bool { return true; }
    public function Translate(string $Text): string { return $Text; }

    private function OriginalVerzeichnis(): string { return $this->originalDir; }
    private function AiPrivacyAccepted(): bool { return $this->einwilligung; }
    private function AiStripImage(string $b): string { return $b; }
    private function AiScaleImage(string $b, int $k = 1600): ?string { return null; }
    private function AnhangGrenzen(): array { return AnhangGrenzenCalc::Wirksam($this->grenzen); }
    private function ReadAttributeStringSafe(string $k, string $d): string { return (string)($this->attr[$k] ?? $d); }
    private function OutputLimit(): int { return 200000; }
    private function RelayLimitB64(): int { return 150000; }
    private function AiFileNameParams(string $n, bool $pdf, string $r = ''): string { return 'filename="x"'; }
    private function SendApiError(string $code, string $message, int $status): void { $this->fehlerApi[] = [$code, $status]; }
    private function AiJobLaden(bool $anlegen = true): AiJobStore { return AiJobStore::in($this->jobDir, $anlegen); }
    private function GetListInstances(): array
    {
        return array_map(static fn($id): array => ['id' => $id, 'kind' => 'todo'], array_keys($this->todo));
    }
    private function IsInstanceReady(int $id): bool { return ($this->todo[$id] ?? null) !== null; }
    private function CallInstanceGetAppState(int $id, string $kind): string
    {
        return (string)json_encode(['revision' => 1, 'kind' => 'todo', 'state' => ['items' => $this->todo[$id] ?? []]]);
    }
    // Nachbarn aus anderen Traits, die hier keine Rolle spielen
    private function MailDetectOriginStub(): void {}
    private function EduMerkerNachziehen(string $id): void {}
    private function MailNotifyProposal(int $a, int $t, int $n, string $u, string $q): void {}
    private function HomeworkKinder(): array { return []; }

    public function pAktion(array $body): array { return $this->MailHandleAction($body); }
    public function pEinpflegen(string $vid, string $text, array $anhaenge, array $erg = []): bool
    {
        return (bool)(new ReflectionMethod(self::class, 'MailVorschlagEinpflegen'))->invoke($this, $vid,
            ['Subject' => 'Lernzeitplan', 'SenderAddress' => 'schule@example.test', 'Date' => 1790000000],
            $text, $anhaenge, 'k1', 'LOGINEO',
            $erg + ['aufgaben' => [['kind' => 'task', 'title' => 'Arbeitsheft S. 4']], 'zahlen' => MailAnalyseCalc::Zaehlen([['kind' => 'task']]), 'summary' => '']);
    }
    public function pSichern(string $vid, string $text, array $anhaenge): string
    {
        return $this->OriginalSichern($vid, ['Subject' => 'B'], $text, $anhaenge, 'IMAP');
    }
    public function pAufraeumen(): int { return $this->OriginaleAufraeumen(); }
    public function pDatei(string $id, int $n, int $teil): string
    {
        // Kopfzeilen gehen auf der Kommandozeile nicht — die Warnung darueber gehoert nicht in die Nutzlast.
        set_error_handler(static fn(int $nr, string $text): bool => str_contains($text, 'header'));
        ob_start();
        try {
            $this->HandleOriginalFile($id, $n, $teil);
        } finally {
            $raus = (string)ob_get_clean();
            restore_error_handler();
        }
        return $raus;
    }
    public function pVorschlaege(): array { return json_decode($this->attr['MailProposals'] ?? '[]', true); }
    public function pAblage(): OriginalStore { return $this->OriginalAblage(false); }
}

$b64 = static fn(string $roh): string => base64_encode($roh);
$m = new OriginalProbe(4711);
$m->originalDir = tmpOrdner('gateway');
$m->jobDir = tmpOrdner('jobs') . '/';

// Ablegen beim Einpflegen (gerader Weg)
$anhaenge = [['kind' => 'pdf', 'name' => 'Lernzeitplan.pdf', 'base64' => $b64($pdf)],
             ['kind' => 'image', 'name' => 'logo_schule.png', 'base64' => $b64($png)],
             ['kind' => 'image', 'name' => 'Seite2.jpg', 'base64' => $b64($jpg)],
             ['kind' => 'file', 'name' => 'Elternbrief.docx', 'base64' => $b64('PK..docx')]];
pruefe('Einpflegen gelingt', $m->pEinpflegen('moodle:7071:1', 'Arbeitsheft S. 4 und 5 bis Montag.', $anhaenge), true);
$v = $m->pVorschlaege()[0] ?? [];
$oid = (string)($v['originalId'] ?? '');
pruefe('Der Vorschlag traegt eine gueltige Original-Kennung', OriginalCalc::KennungGueltig($oid), true);
$o = $m->pAktion(['action' => 'original', 'id' => $oid]);
pruefe('Aktion original: Text, Quelle, Absender', [$o['ok'] ?? null, $o['original']['text'] ?? null, $o['original']['source'] ?? null,
    $o['original']['from'] ?? null], [true, 'Arbeitsheft S. 4 und 5 bis Montag.', 'LOGINEO', 'schule@example.test']);
pruefe('… die Anhaenge mit Art und Briefpapier-Merker, das docx nur mit Namen',
    [array_map(static fn($a) => [$a['n'], $a['name'], $a['kind'], $a['deko']], $o['original']['atts'] ?? []), $o['original']['skipped'] ?? null],
    [[[1, 'Lernzeitplan.pdf', 'pdf', false], [2, 'logo_schule.png', 'png', true], [3, 'Seite2.jpg', 'jpg', false]], ['Elternbrief.docx']]);
pruefe('Aktion original: ungueltige und unbekannte Kennung',
    [$m->pAktion(['action' => 'original', 'id' => '../x'])['error']['code'] ?? null,
     $m->pAktion(['action' => 'original', 'id' => str_repeat('e', 24)])['error']['code'] ?? null], ['invalid_payload', 'not_found']);

// Stuecke fuer die Kachel und ueber REST
$t0 = $m->pAktion(['action' => 'originalTeil', 'id' => $oid, 'n' => 1, 'teil' => 0]);
pruefe('originalTeil: ein Stueck, Base64, mit Teilzahl', [$t0['ok'] ?? null, $t0['teile'] ?? null, base64_decode((string)($t0['data'] ?? '')) === $pdf],
    [true, 1, true]);
pruefe('REST: die rohen Bytes', $m->pDatei($oid, 3, 0) === $jpg, true);
$m->pDatei('../x', 1, 0);
$m->pDatei(str_repeat('e', 24), 1, 0);
pruefe('REST: ungueltig 400, unbekannt 404', $m->fehlerApi, [['invalid_payload', 400], ['not_found', 404]]);

// Behalten fuer einen Eintrag
$b = $m->pAktion(['action' => 'originalBehalten', 'id' => $oid, 'atts' => [3]]);
$bid = (string)($b['id'] ?? '');
$bo = $m->pAktion(['action' => 'original', 'id' => $bid]);
pruefe('originalBehalten: neuer Ordner nur mit dem gewaehlten Anhang, Vorschlag bleibt vermerkt',
    [$b['ok'] ?? null, array_column($bo['original']['atts'] ?? [], 'name'), $bo['original']['proposalId'] ?? null],
    [true, ['Seite2.jpg'], 'moodle:7071:1']);
pruefe('originalBehalten: eine Nummer, die es nicht gibt, oder Unsinn wird abgelehnt',
    [$m->pAktion(['action' => 'originalBehalten', 'id' => $oid, 'atts' => [9]])['error']['code'] ?? null,
     $m->pAktion(['action' => 'originalBehalten', 'id' => $oid, 'atts' => ['1; rm']])['error']['code'] ?? null],
    ['invalid_payload', 'invalid_payload']);
$leer = $m->pAktion(['action' => 'originalBehalten', 'id' => $oid, 'atts' => []]);
pruefe('originalBehalten ohne Anhaenge (Notiz): nur der Text', count($m->pAktion(['action' => 'original', 'id' => (string)$leer['id']])['original']['atts'] ?? [9]), 0);

// Briefpapier-Gedaechtnis: dasselbe Bild in einem zweiten Original
$zweit = $m->pSichern('77:43', 'Anderer Brief', [['kind' => 'image', 'name' => 'Scan.jpg', 'base64' => $b64($jpg)]]);
pruefe('Dasselbe Bild im zweiten Original ist Briefpapier (nicht vorgehakt)',
    $m->pAktion(['action' => 'original', 'id' => $zweit])['original']['atts'][0]['deko'] ?? null, true);

// Ohne Einwilligung entsteht nichts
$m->einwilligung = false;
pruefe('Ohne Einwilligung: kein Original', $m->pSichern('77:44', 'X', []), '');
$m->einwilligung = true;

// Die oeffentliche Liste nennt nur vorhandene Originale
$m->pAblage()->loeschen($zweit);
$m->attr['MailProposals'] = json_encode(array_merge($m->pVorschlaege(), [['id' => '77:43', 'created' => time(), 'originalId' => $zweit,
    'items' => [['title' => 'X', 'kind' => 'task']]]]));
$liste = $m->pAktion(['action' => 'list'])['proposals'] ?? [];
$nachId = array_column($liste, null, 'id');
pruefe('Liste: Kennung bleibt, wenn das Original da ist — und faellt, wenn nicht',
    [isset($nachId['moodle:7071:1']['originalId']), isset($nachId['77:43']['originalId'])], [true, false]);

// Aufraeumen: Verweise halten, Quellen muessen lesbar sein
$m->attr['HomeworkStore'] = json_encode(['v' => 1, 'items' => [['id' => 'h1', 'originalId' => $bid]]]);
$m->attr['NotesStore'] = json_encode(['notes' => []]);
$m->attr['CalOriginals'] = '{}';
$m->todo = [101 => [['id' => 't1', 'originalId' => (string)$leer['id']]]];
pruefe('Aufraeumen mit frischen Originalen: nichts faellt (Schonfrist, Verweise)', $m->pAufraeumen(), 0);
// verwaist und alt
$verwaist = OriginalCalc::NeueKennung();
$m->pAblage()->anlegen($verwaist, OriginalCalc::Satz(['id' => $verwaist, 'text' => 'alt']), []);
touch($m->originalDir . '/' . $verwaist . '/original.json', time() - 7200);
$m->kaputt = ['HomeworkStore'];
pruefe('Quelle nicht lesbar: das Aufraeumen loescht nichts', [$m->pAufraeumen(), $m->pAblage()->existiert($verwaist)], [0, true]);
$m->kaputt = [];
$m->todo = [101 => null];
pruefe('ToDo-Instanz nicht bereit: ebenso nichts', [$m->pAufraeumen(), $m->pAblage()->existiert($verwaist)], [0, true]);
$m->todo = [101 => [['id' => 't1', 'originalId' => (string)$leer['id']]]];
pruefe('Alles lesbar: das verwaiste, alte Original faellt', [$m->pAufraeumen(), $m->pAblage()->existiert($verwaist)], [1, false]);

// Loeschen: das Original des Vorschlags faellt sofort, die gespeicherten bleiben
pruefe('Loeschen des Vorschlags', $m->pAktion(['action' => 'dismiss', 'id' => 'moodle:7071:1'])['ok'] ?? null, true);
pruefe('… sein Original ist zum Sofort-Loeschen vermerkt', in_array($oid, json_decode($m->attr['OriginaleVerworfen'] ?? '[]', true), true), true);
$m->pAufraeumen();
pruefe('… und nach dem Aufraeumen weg — die fuer Hausaufgabe und Aufgabe gespeicherten bleiben',
    [$m->pAblage()->existiert($oid), $m->pAblage()->existiert($bid), $m->pAblage()->existiert((string)$leer['id'])], [false, true, true]);

// Widerruf: Vorschlags-Originale fallen sofort, gespeicherte bleiben
$m->pEinpflegen('77:50', 'Neuer Brief', []);
$neuO = (string)(array_column($m->pVorschlaege(), null, 'id')['77:50']['originalId'] ?? '');
$m->einwilligung = false;
$m->pAufraeumen();
pruefe('Widerruf: das Original des lebenden Vorschlags faellt, die gespeicherten bleiben',
    [$m->pAblage()->existiert($neuO), $m->pAblage()->existiert($bid)], [false, true]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
