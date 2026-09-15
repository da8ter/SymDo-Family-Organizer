<?php

declare(strict_types=1);

/**
 * LOGINEO-Karten im Klassenseiten-Bestand — derselbe Weg wie die Klassenseiten.
 *
 * Bis zum 15.09.2026 hatte LOGINEO seinen EIGENEN Spiegel
 * (`MoodleKarteSpiegeln`, `MoodleArchivAbgleichen`): 190 Zeilen, die dasselbe
 * taten wie `EduEinpflegen` — nur je Karte einen Schreibvorgang statt je Seite,
 * ohne Medien-Schübe und ohne die Rücknahme bei Fehlschlag. Jede Korrektur am
 * einen Weg musste am anderen nachgezogen werden; die drei Löcher, die B3b im
 * Edu-Weg fand, standen hier unverändert.
 *
 * Dieser Prüfstand hält fest, was dabei HERAUSKOMMEN muss — Satz für Satz, wie
 * der alte Weg es geschrieben hat. Er lief zuerst gegen die alte Fassung; die
 * Erwartungen unten sind DEREN Ausgabe, nicht meine Absicht.
 *
 *   php SymDoGateway/tests/MoodleSpiegelTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/EduStoreCalc.php';
require_once __DIR__ . '/../../libs/AiProvider.php';
require_once __DIR__ . '/../../libs/AiRecipePage.php';
require_once __DIR__ . '/../libs/MoodleCalc.php';
require_once __DIR__ . '/../libs/EduLesen.php';
require_once __DIR__ . '/../libs/EduMaps.php';
require_once __DIR__ . '/../libs/Moodle.php';
require_once __DIR__ . '/../libs/EduEinpflegen.php';

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, mixed $ist, mixed $soll): void
{
    global $fehler, $anzahl;
    $anzahl++;
    $a = json_encode($ist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $b = json_encode($soll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = $a === $b;
    if (!$ok) {
        $fehler++;
    }
    printf("%-4s %-58s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/** Alles, was Symcon berührt, ist hier ein Zähler. */
final class MoodleSpiegelProbe
{
    /* Die drei echten Hälften: lesen (EduMaps trägt Text- und Anhangsaufbau),
       einpflegen, und der LOGINEO-Teil. Was Symcon berührt, steht unten als
       Zähler — eine Klassenmethode schlägt die gleichnamige ihres Traits. */
    use EduLesen;
    use EduMaps;
    use Moodle;
    use EduEinpflegen;

    private const EDU_LOCK        = 'TGW_Edu_';

    public int $InstanceID = 4711;
    public array $bestand;
    private bool $eduOrdnerGeaendert = false;
    public int $medien = 100;
    /** @var list<string> Welche Dateien geladen wurden. */
    public array $geladen = [];
    public array $geschrieben = [];
    public array $geloescht = [];
    public bool $spiegelnAn = true;
    public int $ausgabegrenze = 1048576;

    public function __construct()
    {
        $this->bestand = EduStoreCalc::Leer();
    }

    // ── was die Traits an Symcon brauchen ────────────────────────────────
    private function EduStorable(): bool { return true; }
    private function EduStoreRead(): array { return $this->bestand; }
    private function EduWriteStore(array $s): bool
    {
        $s['rev'] = (int)$s['rev'] + 1;
        $this->bestand = $s;
        $this->geschrieben[] = count($s['notes']);
        return true;
    }
    private function EduOrdner(array &$store, array $seite): string
    {
        $schluessel = 'edupage:' . md5((string)$seite['url']);
        foreach ($store['folders'] as $f) {
            if ((string)($f['eduKey'] ?? '') === $schluessel) {
                return (string)$f['id'];
            }
        }
        $store['folders'][] = ['id' => 'f1', 'eduKey' => $schluessel, 'name' => $seite['name']];
        $this->eduOrdnerGeaendert = true;
        return 'f1';
    }
    private function EduArt(string $n): string
    {
        return str_ends_with($n, '.pdf') ? 'pdf' : (str_ends_with($n, '.jpg') ? 'image' : '');
    }
    private function EduText(string $h): string { return strip_tags($h); }
    private function EduHtml(string $h): string { return $h === '' ? '' : '[weiss]' . strip_tags($h); }
    private function EduGesperrt(string $u): bool { return false; }
    private function EduVorschau(string $url, string $name): int { return 0; }
    private function EduQrCode(int $id): string { return ''; }
    private function EduQrVormerken(string $t): void { $this->eduQrSeiten[] = $t; }
    private function NotesNewId(): string { return 'n' . (++$this->medien); }
    private function NotesStore(): array { return ['notes' => []]; }
    private function NotesUnreferencedMedia(array $s, array $ids): array { return $ids; }
    private function NotesDeleteMedia(array $ids): void
    {
        foreach ($ids as $id) { $this->geloescht[] = (int)$id; }
    }
    private function NotesSaveAttachment(string $b64, string $name): array
    {
        $id = ++$this->medien;
        return ['ok' => true, 'id' => $id, 'kind' => $this->EduArt($name),
                'name' => $name, 'bytes' => strlen($b64)];
    }
    private function MoodleDateiVonUrl(string $url): ?string
    {
        $this->geladen[] = $url;
        return 'INHALT-' . $url;
    }
    private function MoodleDatei(array $zugang, string $url): ?string
    {
        return $this->MoodleDateiVonUrl($url);
    }
    private function OutputLimit(): int { return $this->ausgabegrenze; }
    public function Translate(string $t): string { return $t; }
    private function SendDebug(string $a, string $b, int $c): void {}
    private function LogMessage(string $t, int $s): void {}
    private function MoodleProp(string $n, mixed $v): mixed
    {
        return $n === 'MoodleToCards' ? $this->spiegelnAn : $v;
    }
    private function EduProp(string $n, mixed $v): mixed { return $n === 'EduToNotes' ? true : $v; }
    private function EduGefundeneAufnehmen(array $seite, array $adressen): int { return 0; }
    private function EduPushKarten(string $u, string $s, int $k): void {}
    private function EduPushAbschluss(): void {}
    private function EduKartenAuswerten(array $seite, array $karten, int $schon, bool $alles): array
    {
        return ['geaendert' => 0, 'analysiert' => 0, 'gedeckelt' => false];
    }

    // ── Türen ────────────────────────────────────────────────────────────
    public function Spiegle(array $seite, array $karten): int
    {
        return $this->EduSeiteSpiegeln($seite, $karten);
    }
    public function Archiv(array $seite, array $karten): int
    {
        return $this->EduArchivAbgleichen($seite, $karten);
    }
    public function Modul(array $modul, string $abschnitt): ?array
    {
        return $this->MoodleModulKarte($modul, $abschnitt);
    }
}

$seite = ['name' => 'Mathe 5b', 'url' => 'https://lms.test/course/view.php?id=7', 'userId' => 'u1'];

/** Eine LOGINEO-Karte, wie der Leser sie liefert. */
function mkarte(array $ueber = []): array
{
    return $ueber + [
        'quelle'    => 'moodle',
        'srcId'     => 'moodle:42',
        'updated'   => 1700,
        'srcAt'     => 1700,
        'srcUrl'    => 'https://lms.test/mod/resource/view.php?id=42',
        'titel'     => 'Elternbrief',
        'text'      => 'Bitte unterschreiben',
        'html'      => '[weiss]Bitte unterschreiben',
        'abschnitt' => 'Organisation',
        'abschnittFarbe' => '',
        'farbe'     => '',
        'buchung'   => null,
        'anhaenge'  => [['name' => 'brief.pdf', 'datei' => 'brief.pdf',
                         'url' => 'https://lms.test/pluginfile.php/1/brief.pdf',
                         'preview' => '', 'bytes' => 5000]],
    ];
}

// ── Der volle Satz: Feld für Feld wie der alte Weg ───────────────────────
$m = new MoodleSpiegelProbe();
$geschrieben = $m->Spiegle($seite, [mkarte()]);
pruefe('Eine Karte wird gespiegelt', $geschrieben, 1);
$n = $m->bestand['notes'][0];
$jetzt = $n['createdAt'] ?? 0;
pruefe('Der Satz stimmt Feld fuer Feld', $n, [
    'id'        => 'n102',
    'folderId'  => 'f1',
    'title'     => 'Elternbrief',
    'text'      => 'Bitte unterschreiben',
    'att'       => [['id' => 101, 'kind' => 'pdf', 'name' => 'brief.pdf', 'bytes' => 68]],
    'createdAt' => $jetzt,
    'updatedAt' => $jetzt,
    'source'    => 'moodle',
    'srcId'     => 'moodle:42',
    'srcRev'    => 1700,
    'srcAt'     => 1700,
    'section'   => 'Organisation',
    'pos'       => 0,
    'sectionColor' => '',
    'color'     => '',
    'html'      => '[weiss]Bitte unterschreiben',
    'booking'   => null,
    'srcUrl'    => 'https://lms.test/mod/resource/view.php?id=42',
]);
pruefe('Die Datei wurde einmal geladen', $m->geladen,
    ['https://lms.test/pluginfile.php/1/brief.pdf']);

// ── Unveraendert: kein Schreiben, keine neue Datei ───────────────────────
$m->geladen = [];
$m->geschrieben = [];
pruefe('Derselbe Stand spiegelt nicht neu', $m->Spiegle($seite, [mkarte()]), 0);
pruefe('… und laedt nichts nach', $m->geladen, []);
/* EINEN Schreibvorgang gibt es doch: der Nachzug traegt nach, was beim ersten
   Mal noch nicht dastand — hier der Vermerk „in diesem Anhang steckt kein
   QR-Code". Das ist neu gegenueber dem alten LOGINEO-Weg, der QR-Codes gar
   nicht kannte, und faellt je Karte genau EINMAL an. */
pruefe('… aber der Nachzug traegt einmal nach', count($m->geschrieben), 1);
$m->geschrieben = [];
pruefe('… beim dritten Lauf ist Ruhe', [$m->Spiegle($seite, [mkarte()]), $m->geschrieben], [0, []]);

// ── Nachzug: Platz und Abschnitt ziehen nach, ohne neue Datei ────────────
$m->geladen = [];
$m->Spiegle($seite, [mkarte(['abschnitt' => 'Termine']), mkarte(['srcId' => 'moodle:43'])]);
pruefe('Der Abschnitt zieht nach', $m->bestand['notes'][0]['section'], 'Termine');
pruefe('… ohne die Datei neu zu laden',
    $m->geladen, ['https://lms.test/pluginfile.php/1/brief.pdf']);   // nur die NEUE Karte
pruefe('… und die zweite Karte steht auf Platz 1', $m->bestand['notes'][1]['pos'], 1);

// ── Eine neue Fassung ersetzt die Datei ──────────────────────────────────
$m->geladen = [];
$m->geloescht = [];
$m->Spiegle($seite, [mkarte(['updated' => 1800, 'srcAt' => 1800])]);
pruefe('Die neue Fassung steht im Bestand', $m->bestand['notes'][0]['srcRev'], 1800);
pruefe('… die Datei wurde neu geladen', count($m->geladen), 1);
pruefe('… und die alte freigegeben', $m->geloescht, [101]);

// ── Der Schalter „in den Bestand" ────────────────────────────────────────
$aus = new MoodleSpiegelProbe();
$aus->spiegelnAn = false;
pruefe('Ohne den LOGINEO-Schalter passiert nichts', $aus->Spiegle($seite, [mkarte()]), 0);
pruefe('… und der Bestand bleibt leer', count($aus->bestand['notes']), 0);

// ── Das Archiv trennt die Quellen ────────────────────────────────────────
/* Eine Edumaps-Karte im selben Kind-Ordner darf ein LOGINEO-Lauf nicht
   anfassen — und umgekehrt. */
$a = new MoodleSpiegelProbe();
$a->Spiegle($seite, [mkarte(), mkarte(['srcId' => 'moodle:43', 'titel' => 'Zweitens'])]);
$a->bestand['notes'][] = ['id' => 'x1', 'folderId' => 'f1', 'source' => 'edumaps',
    'srcId' => 'edu:b9', 'title' => 'Fremd', 'att' => []];
pruefe('Die verschwundene LOGINEO-Karte wandert ins Archiv',
    $a->Archiv($seite, [mkarte()]), 1);
pruefe('… genau sie', [(int)($a->bestand['notes'][0]['archived'] ?? 0) > 0,
                        (int)($a->bestand['notes'][1]['archived'] ?? 0) > 0], [false, true]);
pruefe('… und die Edumaps-Karte bleibt unberuehrt',
    isset($a->bestand['notes'][2]['archived']), false);
pruefe('Kommt sie zurueck, endet das Archiv',
    [$a->Archiv($seite, [mkarte(), mkarte(['srcId' => 'moodle:43'])]),
     isset($a->bestand['notes'][1]['archived'])], [0, false]);
/* Eine leere Kartenliste heisst NICHT „alles weg" — eher hat der Abruf nichts
   gelesen. Wer dann archiviert, raeumt den ganzen Kurs ab. */
pruefe('Eine leere Liste archiviert nichts', $a->Archiv($seite, []), 0);

// ── Grosse Dateien werden gar nicht erst geladen ─────────────────────────
/* Gemessen an dieser Schule: eine Elternabend-Praesentation hat 6,3 MB und
   passt nicht in den Bestand. Sie waere umsonst geladen worden. */
$g = new MoodleSpiegelProbe();
$g->Spiegle($seite, [mkarte(['anhaenge' => [
    ['name' => 'gross.pdf', 'datei' => 'gross.pdf', 'url' => 'https://lms.test/gross.pdf',
     'preview' => '', 'bytes' => 6300000],
    ['name' => 'klein.pdf', 'datei' => 'klein.pdf', 'url' => 'https://lms.test/klein.pdf',
     'preview' => '', 'bytes' => 1000]]])]);
pruefe('Die zu grosse Datei wird nicht geladen',
    $g->geladen, ['https://lms.test/klein.pdf']);
pruefe('… die Karte steht trotzdem im Bestand', count($g->bestand['notes']), 1);

// ── Der Leser baut die Karte in der neuen Form ───────────────────────────
$k = (new MoodleSpiegelProbe())->Modul([
    'id' => 42, 'name' => 'Elternbrief', 'description' => '<p>Bitte unterschreiben</p>',
    'timemodified' => 1700, 'url' => 'https://lms.test/mod/resource/view.php?id=42',
    'contents' => [['type' => 'file', 'filename' => 'brief.pdf',
                    'fileurl' => 'https://lms.test/pluginfile.php/1/brief.pdf',
                    'filesize' => 5000, 'timemodified' => 1650]],
], 'Organisation');
pruefe('Der Leser nennt die Quelle', $k['quelle'] ?? null, 'moodle');
pruefe('… die vollstaendige Kennung', $k['srcId'] ?? null, 'moodle:42');
pruefe('… die Fassung als `updated`', $k['updated'] ?? null, 1700);
pruefe('… das Quelldatum', $k['srcAt'] ?? null, 1700);
pruefe('… den eigenen Weg', $k['srcUrl'] ?? null, 'https://lms.test/mod/resource/view.php?id=42');
pruefe('… und die Dateien als `anhaenge`', $k['anhaenge'] ?? null, [
    ['name' => 'brief.pdf', 'datei' => 'brief.pdf',
     'url' => 'https://lms.test/pluginfile.php/1/brief.pdf', 'preview' => '', 'bytes' => 5000]]);

// ── Der alte, eigene Spiegel ist weg ─────────────────────────────────────
$quelle = (string)file_get_contents(__DIR__ . '/../libs/Moodle.php');
foreach (['MoodleKarteSpiegeln', 'MoodleArchivAbgleichen'] as $weg) {
    pruefe('Kein eigener Weg mehr: ' . $weg, str_contains($quelle, 'function ' . $weg), false);
}

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
