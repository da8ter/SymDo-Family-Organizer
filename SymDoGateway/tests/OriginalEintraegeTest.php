<?php

declare(strict_types=1);

/**
 * Pruefstand: „Original speichern" an den Eintraegen des Gateways (24.09.2026).
 *
 *  - Termin: der Verweis in der Nebenablage CalOriginals — geschrieben beim
 *    Anlegen, mitgeliefert beim Lesen, entfernt nur mit dem GANZEN Termin.
 *  - Hausaufgabe: HomeworkCalc laesst nur eine gueltige Kennung durch.
 *  - Notiz: adopt haengt die angehakten Anhaenge aus dem ORIGINAL an und
 *    haelt ein abgeleitetes Original nur, wenn es zu DIESEM Vorschlag gehoert.
 *
 *   php SymDoGateway/tests/OriginalEintraegeTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/HomeworkCalc.php';
require_once __DIR__ . '/../libs/CalendarBridge.php';
require_once __DIR__ . '/../libs/Notes.php';
require_once __DIR__ . '/../libs/NotesAi.php';
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
    $d = rtrim(sys_get_temp_dir(), '/\\') . '/symdo_eintr_' . $wofuer . '_' . bin2hex(random_bytes(4));
    register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($d)));
    return $d;
}

/** Ein Original in die Ablage legen — so, wie OriginalSichern/OriginalBehalten es taeten. */
function originalAnlegen(string $dir, string $vid, array $dateien = []): string
{
    $id = OriginalCalc::NeueKennung();
    $atts = [];
    foreach ($dateien as $n => [$name, $roh]) {
        $atts[] = ['n' => $n, 'name' => $name, 'kind' => OriginalCalc::AnhangArt($roh), 'bytes' => strlen($roh)];
    }
    OriginalStore::in($dir)->anlegen($id, OriginalCalc::Satz(['id' => $id, 'proposalId' => $vid, 'text' => 'Brief', 'atts' => $atts]),
        array_map(static fn($d) => $d[1], $dateien));
    return $id;
}

// ══ Termin ════════════════════════════════════════════════════════════════
if (!function_exists('IPSKAL_CreateEvent')) {
    function IPSKAL_CreateEvent(int $id, string $json): string
    {
        return (string)json_encode(['success' => true, 'event' => ['uid' => 'uid-' . (++$GLOBALS['uidZaehler'])]]);
    }
}
if (!function_exists('IPSKAL_DeleteEvent')) {
    function IPSKAL_DeleteEvent(int $id, string $json): bool { return true; }
}
$GLOBALS['uidZaehler'] = 0;

final class KalenderProbe extends IPSModuleStrict
{
    use CalendarBridge;
    use Originale;

    public array $attr = [];
    public string $originalDir = '';
    public array $roh = [];

    protected function ReadAttributeString(string $Name): string { return (string)($this->attr[$Name] ?? ''); }
    protected function WriteAttributeString(string $Name, string $Value): bool { $this->attr[$Name] = $Value; return true; }
    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    protected function LogMessage(string $Message, int $Type): bool { return true; }
    public function Translate(string $Text): string { return $Text; }
    private function WsPushDirty(): void {}
    private function OriginalVerzeichnis(): string { return $this->originalDir; }
    private function CalWritableOne(int $calendarID, string $benoetigt): array
    {
        return ['ok' => true, 'calendar' => ['id' => $calendarID, 'name' => 'Familie', 'canWrite' => true]];
    }
    private function CalFindRaw(int $calendarID, string $id, string $uid, int $startTs): ?array { return $this->roh; }
    private function CalFreshRaw(int $calendarID, array $roh): ?array { return $roh; }
    private function CalScopeTarget(int $calendarID, array $roh, string $scope): array { return ['ok' => true, 'event' => $roh]; }

    public function pAnlegen(array $daten): array
    {
        return (array)(new ReflectionMethod(self::class, 'CalCreateEventChecked'))->invoke($this, 5, $daten,
            ['id' => 5, 'name' => 'Familie']);
    }
    public function pLoeschen(array $daten): array
    {
        return (array)(new ReflectionMethod(self::class, 'CalDeleteEvent'))->invoke($this, 5, $daten);
    }
    public function pNormal(array $e): array
    {
        return (array)(new ReflectionMethod(self::class, 'CalNormalize'))->invoke($this, $e, 5, [], [],
            json_decode($this->attr['CalOriginals'] ?? '{}', true));
    }
    public function pVerweise(): array { return json_decode($this->attr['CalOriginals'] ?? '{}', true); }
}

$k = new KalenderProbe(3001);
$k->originalDir = tmpOrdner('kal');
$orig = originalAnlegen($k->originalDir, '77:1');
$a = $k->pAnlegen(['title' => 'Drehtag Schulfilm', 'start' => '2026-09-21', 'allDay' => true, 'originalId' => $orig]);
pruefe('Termin mit Original: angelegt, der Verweis steht in der Nebenablage', [$a['ok'] ?? null, $k->pVerweise()], [true, ['5:uid-1' => $orig]]);
$k->pAnlegen(['title' => 'Ohne', 'start' => '2026-09-22', 'allDay' => true, 'originalId' => str_repeat('e', 24)]);
$k->pAnlegen(['title' => 'Unsinn', 'start' => '2026-09-23', 'allDay' => true, 'originalId' => '../x']);
pruefe('Ein Original, das es nicht gibt, oder Unsinn: kein Verweis', array_keys($k->pVerweise()), ['5:uid-1']);
pruefe('Lesen: der Termin traegt seine Original-Kennung, andere nicht',
    [$k->pNormal(['uid' => 'uid-1', 'summary' => 'Drehtag'])['originalId'] ?? null, isset($k->pNormal(['uid' => 'uid-2'])['originalId'])],
    [$orig, false]);
// Serie: ein Vorkommen loeschen laesst den Verweis stehen, die ganze Serie nimmt ihn mit
$k->roh = ['uid' => 'uid-1', 'recurring' => true, 'canDeleteOccurrence' => true, 'canDeleteSeries' => true, 'summary' => 'Drehtag'];
$k->pLoeschen(['uid' => 'uid-1', 'scope' => 'occurrence']);
pruefe('Ein Vorkommen geloescht: die Serie haelt ihr Original weiter', array_keys($k->pVerweise()), ['5:uid-1']);
$k->pLoeschen(['uid' => 'uid-1', 'scope' => 'series']);
pruefe('Die ganze Serie geloescht: der Verweis ist weg', $k->pVerweise(), []);
$k->pAnlegen(['title' => 'Einzeln', 'start' => '2026-09-24', 'allDay' => true, 'originalId' => $orig]);
$k->roh = ['uid' => 'uid-4', 'summary' => 'Einzeln'];
$k->pLoeschen(['uid' => 'uid-4']);
pruefe('Ein Einzeltermin geloescht: der Verweis ist weg', $k->pVerweise(), []);

// ══ Hausaufgabe ═══════════════════════════════════════════════════════════
$hw = static fn(array $roh): ?array => HomeworkCalc::Normalisieren($roh + ['childId' => 'k1', 'subject' => 'Deutsch'],
    ['Deutsch'], ['k1'], '2026-09-24', 1790000000);
pruefe('Hausaufgabe: eine gueltige Kennung bleibt, Unsinn faellt, ohne bleibt ohne',
    [$hw(['originalId' => str_repeat('a', 24)])['originalId'] ?? null, isset($hw(['originalId' => '../../x'])['originalId']),
     isset($hw([])['originalId'])],
    [str_repeat('a', 24), false, false]);

// ══ Notiz ═════════════════════════════════════════════════════════════════
final class NotizProbe extends IPSModuleStrict
{
    use Notes;
    use NotesAi;
    use Originale;

    public string $originalDir = '';
    public array $vorschlaege = [];
    public array $abgelegt = [];
    public int $grenze = 1048576;

    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    public function Translate(string $Text): string { return $Text; }
    private function OriginalVerzeichnis(): string { return $this->originalDir; }
    private function MailProposals(): array { return $this->vorschlaege; }
    private function OutputLimit(): int { return $this->grenze; }
    private function NotesMedienUnser(int $id): bool { return true; }
    private function NotesSaveAttachment(string $b64, string $name, string $quelle = ''): array
    {
        $this->abgelegt[] = [$name, strlen((string)base64_decode($b64))];
        return ['ok' => true, 'id' => 500 + count($this->abgelegt), 'kind' => 'pdf', 'name' => $name,
                'bytes' => strlen((string)base64_decode($b64))];
    }
    private function NotesWriteStore(array $s): bool { return true; }

    public function pAdopt(array $body): array
    {
        $store = ['v' => 1, 'rev' => 0, 'seen' => [], 'folders' => [['id' => 'f1', 'name' => 'Schule']], 'notes' => []];
        return (array)(new ReflectionMethod(self::class, 'NotesAdopt'))->invoke($this, $store, $body, 1790000000);
    }
}

$n = new NotizProbe(3002);
$n->originalDir = tmpOrdner('notiz');
$pdfKlein = "%PDF-1.4\n" . str_repeat('K', 1000);
$pdfGross = "%PDF-1.4\n" . str_repeat('G', 5000);
$origV = originalAnlegen($n->originalDir, 'moodle:1:2', [1 => ['Lernzeitplan.pdf', $pdfKlein], 2 => ['Scan.pdf', $pdfGross]]);
$n->vorschlaege = [['id' => 'moodle:1:2', 'originalId' => $origV, 'items' => [['kind' => 'note', 'title' => 'Beitraege', 'text' => 'Tabelle']]]];
$n->grenze = 3000;   // das grosse PDF passt nicht in eine Notiz
$r = $n->pAdopt(['proposalId' => 'moodle:1:2', 'i' => 0, 'folderId' => 'f1', 'keepOriginal' => [1, 2]]);
pruefe('Notiz: die angehakten Anhaenge kommen aus dem Original — ein zu grosses bleibt draussen',
    [$r['ok'] ?? null, $n->abgelegt, array_column($r['note']['att'] ?? [], 'name')],
    [true, [['Lernzeitplan.pdf', strlen($pdfKlein)]], ['Lernzeitplan.pdf']]);
$n->abgelegt = [];
$r = $n->pAdopt(['proposalId' => 'moodle:1:2', 'i' => 0, 'folderId' => 'f1', 'keepOriginal' => []]);
pruefe('Notiz: nichts angehakt heisst nichts angehaengt', [$n->abgelegt, $r['note']['att'] ?? null], [[], []]);
$fremd = originalAnlegen($n->originalDir, 'andererVorschlag:9');
$eigen = originalAnlegen($n->originalDir, 'moodle:1:2');
pruefe('Notiz: ein abgeleitetes Original DIESES Vorschlags wird gehalten, ein fremdes nicht',
    [$n->pAdopt(['proposalId' => 'moodle:1:2', 'i' => 0, 'folderId' => 'f1', 'originalId' => $eigen])['note']['originalId'] ?? null,
     isset($n->pAdopt(['proposalId' => 'moodle:1:2', 'i' => 0, 'folderId' => 'f1', 'originalId' => $fremd])['note']['originalId'])],
    [$eigen, false]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
