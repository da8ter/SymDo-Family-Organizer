<?php

declare(strict_types=1);

/**
 * Wo eine abgelegte Datei landet — und wem sie gehoert.
 *
 * Bis zum 15.09.2026 lag JEDE Datei in einer Kategorie „Notizen": das Foto, das
 * jemand an eine Notiz haengt, der Elternbrief aus Edumaps und das Arbeitsblatt
 * aus LOGINEO. Seitdem hat jede Schulquelle ihren eigenen Ordner unter
 * „Schule". Das ist eine Umstellung mit zwei Klassen von Fallen, und beide
 * kosten Dateien statt sie nur zu verlegen:
 *
 *  - **Die Berechtigungsfragen.** Vier Lesewege und der Aufraeumer fragten
 *    „liegt die Datei in DER Notizen-Kategorie". Mit dem zweiten Topf haette
 *    jeder von ihnen „nein" gesagt — jedes Kartenbild mit 403, und geloescht
 *    worden waere nie wieder eines. Deshalb pruefen sie jetzt alle ueber
 *    dieselbe Frage (`NotesMedienUnser`), und dieser Prüfstand fährt sie einzeln.
 *  - **Der Umzug des Bestands.** Er darf NUR anfassen, was schon in einem
 *    unserer Toepfe liegt. Eine verdorbene Kennung im Bestand risse sonst ein
 *    fremdes Medienobjekt aus seinem Baum — einen Avatar, einen Tonschnipsel.
 *
 * Gegenprobe: `NOTES_TOPF_NAMEN` leeren → „Quelle", „Quote je Topf" und
 * „Einsortieren" fallen. In `NotesMedienUnser` `$this->NotesMedienToepfe()`
 * durch `[$this->NotesMediaCategory(false)]` ersetzen → die Lesewege fallen.
 * Im Einsortieren die Wache `in_array($vater, $eigene, true)` entfernen → der
 * Fall „fremdes Objekt bleibt liegen" fällt.
 *
 *   php SymDoGateway/tests/NotesTopfTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/EduStoreCalc.php';
require_once __DIR__ . '/../libs/NotesMedia.php';

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
    printf("%-4s %-58s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/** Der Weg einer Datei im Baum, von oben: „Schule/Edumaps" oder „Notizen". */
function weg(int $mediaId): string
{
    $teile = [];
    $id = IPS_GetParent($mediaId);
    while ($id > 0 && IPS_CategoryExists($id)) {
        array_unshift($teile, IPS_GetName($id));
        $id = IPS_GetParent($id);
    }
    return implode('/', $teile);
}

/**
 * Die Attrappe. Alles, was nicht zur Ablage gehoert, ist hier ein fester Wert —
 * geprueft wird der Objektbaum, den der Trait wirklich baut.
 */
final class Topfprobe
{
    use NotesMedia;

    /** Namen der Sperren, im echten Modul aus Notes bzw. EduStore. */
    private const NOTES_LOCK      = 'TGW_Notes_';
    private const EDU_LOCK        = 'TGW_Edu_';
    private const NOTE_ATTACH_MAX = 5;

    public int $InstanceID = 0;
    /** Der Knoten, unter dem der Bestand haengt — im Betrieb die Gateway-Instanz. */
    public int $bestand = 0;
    /** Was der Bestand der Klassenseiten hergibt. */
    public array $eduKarten = [];
    /** Was die Notizen hergeben. */
    public array $notizen = [];
    public array $meldungen = [];
    private array $attribute = [];

    protected function BestandID(): int { return $this->bestand; }

    private function ReadAttributeStringSafe(string $n, string $v): string
    {
        return (string)($this->attribute[$n] ?? $v);
    }
    private function WriteAttributeString(string $n, string $v): void { $this->attribute[$n] = $v; }
    private function RegisterAttributeString(string $n, string $v): void { $this->attribute[$n] = $v; }

    private function NotesFehler(string $code): array
    {
        return ['ok' => false, 'error' => ['code' => $code]];
    }
    private function NotesTrim(string $s, int $n): string { return mb_substr($s, 0, $n); }
    private function AiStripImage(string $b64): string { return $b64; }
    /** Wie mit GD: das Bild kommt unveraendert zurueck. */
    private function AiScaleImage(string $roh, int $kante): ?string { return $roh; }
    private function AiFitImageForRelay(string $roh, int $max): ?string { return $roh; }
    private function OutputLimit(): int { return 1048576; }
    private function RelayLimitB64(): int { return 700000; }
    private function AiFileNameParams(string $n): string { return $n; }

    private function NotesStore(): array { return ['notes' => $this->notizen]; }
    private function NotesWriteStore(array $s): bool { $this->notizen = $s['notes']; return true; }
    private function NotesIndexOf(array $n, string $id): int { return -1; }
    private function NotesStorable(): bool { return true; }
    private function MailProposalsReadable(): bool { return true; }
    private function MailProposals(): array { return []; }
    private function EduStorable(): bool { return true; }
    private function EduStoreRead(): array { return ['notes' => $this->eduKarten]; }
    private function EduAttachmentIds(): array { return EduStoreCalc::AnhangIds($this->eduKarten); }
    private function LogMessage(string $t, int $s): void { $this->meldungen[] = $t; }
    private function SendApiError(string $c, string $t, int $h): void {}

    // Zugaenge fuer den Prüfstand — im Betrieb sind das private Wege.
    public function ablegen(string $name, string $quelle): array
    {
        return $this->NotesSaveAttachment(base64_encode("\xFF\xD8\xFF" . str_repeat('x', 40)), $name, $quelle);
    }
    public function topf(string $quelle): int { return $this->NotesMedienTopf($quelle, false); }
    public function toepfe(): array { return $this->NotesMedienToepfe(); }
    public function unser(int $id): bool { return $this->NotesMedienUnser($id); }
    public function ausgeben(int $id): array { return $this->NotesMediaAusgeben($id); }
    public function loeschen(array $ids): void { $this->NotesDeleteMedia($ids); }
    public function einsortieren(): int { return $this->NotesMedienEinsortieren(); }
    public function aufraeumen(): int { return $this->NotesSweepOrphans(); }
}

$p = new Topfprobe();
$p->bestand = IPS_CreateCategory();
IPS_SetName($p->bestand, 'SymDo Gateway');

// ── 1. Jede Quelle in ihren Ordner ───────────────────────────────────────
$notiz   = $p->ablegen('foto.jpg', '');
$edu     = $p->ablegen('brief.jpg', 'edumaps');
$moodle  = $p->ablegen('blatt.jpg', 'moodle');
$untis   = $p->ablegen('plan.jpg', 'untis');
$fremd   = $p->ablegen('mail.jpg', 'mail');

pruefe('Ein Notiz-Anhang bleibt bei den Notizen', weg((int)$notiz['id']), 'SymDo Gateway/Notizen');
pruefe('Edumaps liegt unter Schule/Edumaps', weg((int)$edu['id']), 'SymDo Gateway/Schule/Edumaps');
pruefe('LOGINEO liegt unter Schule/Logineo', weg((int)$moodle['id']), 'SymDo Gateway/Schule/Logineo');
pruefe('WebUntis liegt unter Schule/WebUntis', weg((int)$untis['id']), 'SymDo Gateway/Schule/WebUntis');
pruefe('Eine unbekannte Quelle erzeugt keinen Ordner', weg((int)$fremd['id']), 'SymDo Gateway/Notizen');

// „Schule" entsteht EINMAL, nicht je Topf.
$schulknoten = [];
foreach (IPS_GetChildrenIDs($p->bestand) as $k) {
    if (IPS_CategoryExists($k) && IPS_GetName($k) === 'Schule') {
        $schulknoten[] = $k;
    }
}
pruefe('Es gibt genau einen Knoten „Schule"', count($schulknoten), 1);
pruefe('Vier Toepfe, alle gefunden', count($p->toepfe()), 4);

// ── 2. Die Quote gilt je Topf ────────────────────────────────────────────
$notizTopf = $p->topf('');
for ($i = count(IPS_GetChildrenIDs($notizTopf)); $i < 300; $i++) {
    $m = IPS_CreateMedia(MEDIATYPE_IMAGE);
    IPS_SetParent($m, $notizTopf);
}
pruefe('Volle Notizen nehmen nichts mehr an',
    (string)($p->ablegen('noch.jpg', '')['error']['code'] ?? ''), 'quota_exceeded');
pruefe('… und die Klassenseite legt trotzdem ab',
    ($p->ablegen('noch.jpg', 'edumaps')['ok'] ?? false), true);

// ── 3. Die eine Frage: gehoert es uns? ───────────────────────────────────
$fremdeKat = IPS_CreateCategory();
IPS_SetName($fremdeKat, 'Rezeptfotos');
IPS_SetParent($fremdeKat, $p->bestand);
$fremdesBild = IPS_CreateMedia(MEDIATYPE_IMAGE);
IPS_SetParent($fremdesBild, $fremdeKat);

pruefe('Eine Datei aus Schule/Logineo gehoert uns', $p->unser((int)$moodle['id']), true);
pruefe('Ein fremdes Medienobjekt nicht', $p->unser($fremdesBild), false);
pruefe('Ausgabe verweigert nur das Fremde',
    (string)($p->ausgeben($fremdesBild)['error']['code'] ?? ''), 'forbidden');
pruefe('Die Kartendatei kommt an der Berechtigung vorbei',
    (string)($p->ausgeben((int)$moodle['id'])['error']['code'] ?? ''), 'empty');

$p->loeschen([(int)$untis['id'], $fremdesBild]);
pruefe('Geloescht wird in jedem unserer Toepfe', IPS_MediaExists((int)$untis['id']), false);
pruefe('Fremdes bleibt unangetastet', IPS_MediaExists($fremdesBild), true);

// ── 4. Der Bestand zieht nach ────────────────────────────────────────────
// Ausgangslage wie vor der Umstellung: alle Kartendateien liegen bei den Notizen.
$altEdu    = IPS_CreateMedia(MEDIATYPE_DOCUMENT);
$altThumb  = IPS_CreateMedia(MEDIATYPE_IMAGE);
$altMoodle = IPS_CreateMedia(MEDIATYPE_DOCUMENT);
foreach ([$altEdu, $altThumb, $altMoodle] as $m) {
    IPS_SetParent($m, $notizTopf);
}
$p->eduKarten = [
    ['id' => 'k1', 'att' => [['id' => $altEdu, 'thumb' => $altThumb]]],
    ['id' => 'k2', 'quelle' => 'moodle', 'att' => [['id' => $altMoodle]]],
    // Eine verdorbene Zeile: sie zeigt auf ein fremdes Objekt.
    ['id' => 'k3', 'att' => [['id' => $fremdesBild]]],
    // Und eine Karte ohne jede Datei.
    ['id' => 'k4', 'quelle' => 'moodle', 'att' => []],
];
$umgezogen = $p->einsortieren();

pruefe('Drei Dateien sind umgezogen', $umgezogen, 3);
pruefe('Die Klassenseite landet unter Edumaps', weg($altEdu), 'SymDo Gateway/Schule/Edumaps');
pruefe('Ihr Vorschaubild ebenso', weg($altThumb), 'SymDo Gateway/Schule/Edumaps');
pruefe('LOGINEO landet unter Logineo', weg($altMoodle), 'SymDo Gateway/Schule/Logineo');
pruefe('Das fremde Objekt bleibt, wo es war', weg($fremdesBild), 'SymDo Gateway/Rezeptfotos');
pruefe('Der Notiz-Anhang bleibt bei den Notizen', weg((int)$notiz['id']), 'SymDo Gateway/Notizen');
pruefe('Ein zweiter Durchgang bewegt nichts mehr', $p->einsortieren(), 0);
pruefe('Der Umzug wird gemeldet', count($p->meldungen), 1);

// Die Quelle darf wechseln — dann folgt die Datei.
$p->eduKarten[0]['quelle'] = 'moodle';
pruefe('Wechselt die Quelle, zieht die Datei mit', $p->einsortieren(), 2);
pruefe('… und liegt danach unter Logineo', weg($altEdu), 'SymDo Gateway/Schule/Logineo');

// ── 5. Der Aufraeumer sieht alle Toepfe ──────────────────────────────────
$waise = IPS_CreateMedia(MEDIATYPE_IMAGE);
IPS_SetParent($waise, $p->topf('edumaps'));
$p->notizen = [];
$weg = $p->aufraeumen();
pruefe('Die Waise in Schule/Edumaps wird eingesammelt', IPS_MediaExists($waise), false);
pruefe('Der Durchgang raeumt in jedem Topf', $weg > 0, true);
pruefe('Die Dateien des Bestands ueberleben', IPS_MediaExists($altMoodle), true);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler > 0 ? 1 : 0);
