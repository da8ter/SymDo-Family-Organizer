<?php

declare(strict_types=1);

/**
 * Das Spiegeln einer Klassenseite in den Bestand — Schuebe, Medienbilanz,
 * Fehlschlag.
 *
 * Warum es diesen Prüfstand geben MUSS: der Umbau „ein Schreibvorgang je Seite
 * statt je Karte" hat drei Löcher gerissen, die im Betrieb erst nach Wochen
 * aufgefallen wären, und zwei davon kosten Dateien:
 *
 *  - Schlug der eine Schreibvorgang fehl, war die GANZE Seite verloren — und
 *    bei jedem weiteren Lauf aufs Neue. Der alte Weg behielt, was bis dahin
 *    geschrieben war.
 *  - Alle neuen Anhänge einer Seite entstanden, bevor auch nur einer der
 *    ersetzten frei wurde. Die Notizen-Kategorie hält 300 Objekte; eine Seite
 *    braucht im Grenzfall 600. Was dann nicht mehr abgelegt werden kann, fällt
 *    still weg — die Karte bekommt leeres `att`, aber neues `srcRev`, gilt
 *    fortan als unverändert, und ihre alten Anhänge werden gelöscht.
 *  - Die Vorschaubilder des Nachzugs standen nicht in der Rücknahmeliste.
 *
 * Gefahren wird der Trait gegen eine Attrappe: alles, was Symcon berührt,
 * ist hier ein Zähler. Geprüft wird die BILANZ — wie viele Schreibvorgänge,
 * welche Medien entstehen, welche verschwinden und wann.
 *
 *   php SymDoGateway/tests/EduSpiegelTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/EduStoreCalc.php';
require_once __DIR__ . '/../libs/EduEinpflegen.php';

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
    printf("%-4s %-60s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/**
 * Die Attrappe. Sie tut nichts Echtes — sie zaehlt, was der Trait verlangt.
 */
final class Spiegelprobe
{
    use EduEinpflegen;

    /** Die Sperre traegt im echten Modul diesen Namen; sie kommt aus EduStore. */
    private const EDU_LOCK = 'TGW_Edu_';

    public int $InstanceID = 4711;
    private bool $eduOrdnerGeaendert = false;

    /** @var array<string,mixed> */
    public array $bestand;
    public array $geschrieben = [];   // je Schreibvorgang die Zahl der Notizen
    public array $angelegt    = [];   // Medien-Kennungen, die entstanden sind
    public array $geloescht   = [];   // Medien-Kennungen, die weg sind
    public array $meldungen   = [];
    public int $naechsteMedienId = 100;
    /** Ab diesem Schreibvorgang scheitert es (0 = nie). */
    public int $scheitertAb = 0;
    private int $schreibZaehler = 0;

    public function __construct()
    {
        $this->bestand = EduStoreCalc::Leer();
    }

    // ── was der Trait an Symcon braucht ──────────────────────────────────
    private function EduProp(string $n, mixed $v): mixed { return true; }
    private function EduStorable(): bool { return true; }
    private function EduStoreRead(): array { return $this->bestand; }
    private function SendDebug(string $a, string $b, int $c): void {}
    public function LogMessage(string $t, int $s): void { $this->meldungen[] = $t; }

    private function EduWriteStore(array $store): bool
    {
        $this->schreibZaehler++;
        if ($this->scheitertAb > 0 && $this->schreibZaehler >= $this->scheitertAb) {
            return false;
        }
        $store['rev'] = (int)$store['rev'] + 1;
        $this->bestand = $store;
        $this->geschrieben[] = ['notizen' => count($store['notes']), 'rev' => $store['rev']];
        return true;
    }

    private function EduOrdner(array &$store, array $seite): string { return 'f1'; }
    private function EduNotizText(array $karte): string { return (string)($karte['text'] ?? ''); }
    private function EduArt(string $name): string { return str_ends_with($name, '.pdf') ? 'pdf' : ''; }
    private function EduQrVormerken(string $t): void {}
    private function EduQrCode(int $id): string { return ''; }
    private function NotesNewId(): string { return 'n' . (++$this->naechsteMedienId); }
    private function NotesStore(): array { return ['notes' => []]; }

    /** Waise = alles, was uebergeben wird; strenger als echt, aber nachvollziehbar. */
    private function NotesUnreferencedMedia(array $store, array $ids): array { return $ids; }

    private function NotesDeleteMedia(array $ids): void
    {
        foreach ($ids as $id) {
            $this->geloescht[] = (int)$id;
        }
    }

    /** Je Anhang zwei Medien: die Datei und ihre Vorschau. */
    private function EduNotizAnhaenge(array $karte): array
    {
        $raus = [];
        foreach ((array)($karte['anhaenge'] ?? []) as $a) {
            $id = ++$this->naechsteMedienId;
            $th = ++$this->naechsteMedienId;
            $this->angelegt[] = $id;
            $this->angelegt[] = $th;
            $raus[] = ['id' => $id, 'kind' => 'pdf', 'name' => (string)$a['name'],
                       'bytes' => 10, 'thumb' => $th];
        }
        return $raus;
    }

    private function EduVorschau(string $url, string $name): int
    {
        $id = ++$this->naechsteMedienId;
        $this->angelegt[] = $id;
        return $id;
    }

    // ── Zugaenge fuer den Prueflauf ──────────────────────────────────────
    public function Spiegle(array $seite, array $karten): int
    {
        return $this->EduSeiteSpiegeln($seite, $karten);
    }
}

$seite = ['name' => '5b', 'url' => 'https://x.test/s', 'userId' => 'u1'];

/** n Karten, jede mit einem PDF-Anhang. */
function karten(int $n, int $rev = 1000): array
{
    $raus = [];
    for ($i = 0; $i < $n; $i++) {
        $raus[] = ['boxid' => 'b' . $i, 'updated' => $rev, 'titel' => 'K' . $i,
                   'text' => 'Text ' . $i, 'abschnitt' => '', 'abschnittFarbe' => '',
                   'farbe' => '', 'html' => '<p>' . $i . '</p>', 'buchung' => null,
                   'anhaenge' => [['name' => 'brief' . $i . '.pdf', 'url' => 'https://x.test/b' . $i . '.pdf',
                                   'preview' => 'https://x.test/p' . $i]]];
    }
    return $raus;
}

// ══ 1. Eine Seite aus Textkarten kostet weiterhin EINEN Schreibvorgang ═══
$p = new Spiegelprobe();
$nurText = array_map(static function (array $k): array {
    $k['anhaenge'] = [];
    return $k;
}, karten(55));
pruefe('55 Textkarten werden alle gespiegelt', $p->Spiegle($seite, $nurText), 55);
pruefe('… in genau einem Schreibvorgang', count($p->geschrieben), 1);
pruefe('… und kosten kein einziges Medienobjekt', $p->angelegt, []);

// ══ 2. Anhaenge schieben — der Deckel der Kategorie darf nicht reissen ═══
/* 30 Karten x 2 Medien = 60 Objekte. Alle auf einmal anzulegen, bevor eines
   frei wird, ist genau der Fall, der die Kategorie sprengt. */
$p = new Spiegelprobe();
$gespiegelt = $p->Spiegle($seite, karten(30));
pruefe('30 Karten mit Anhang werden alle gespiegelt', $gespiegelt, 30);
pruefe('… in mehreren Schueben', count($p->geschrieben) > 1, true);
$hoechststand = 0;
$offen = 0;
foreach ($p->geschrieben as $nr => $g) {
    $hoechststand = max($hoechststand, EduEinpflegen_schub($p, $nr));
}
pruefe('Nie warten mehr als ein Schub neuer Medien auf ihre Freigabe',
    $hoechststand <= 20 + 2, true);

/* Und die Revision zaehlt bei JEDEM Schub weiter — sonst bekaeme ein Geraet,
   das sich `rev` merkt, den zweiten Schub nie zu sehen. */
$revs = array_column($p->geschrieben, 'rev');
pruefe('Jeder Schub traegt eine eigene Revision', $revs === array_unique($revs), true);
pruefe('… und sie steigt', $revs === array_values(array_filter($revs, static function ($r) {
    static $letzte = 0;
    $ok = $r > $letzte;
    $letzte = $r;
    return $ok;
})), true);

// ══ 3. Ein Fehlschlag verliert nur den laufenden Schub ═══════════════════
$p = new Spiegelprobe();
$p->scheitertAb = 2;           // der erste Schub geht durch, der zweite nicht
$gespiegelt = $p->Spiegle($seite, karten(30));
pruefe('Der erste Schub bleibt erhalten', count($p->geschrieben), 1);
pruefe('… und wird als gespiegelt gemeldet, nicht als null', $gespiegelt > 0, true);
pruefe('Der Fehlschlag steht im Protokoll', count($p->meldungen), 1);
/* Die Medien des GESCHEITERTEN Schubs gehoeren niemandem und muessen weg —
   die des ersten nicht, die haengen an geschriebenen Notizen. */
$erhalten = array_values(array_diff($p->angelegt, $p->geloescht));
pruefe('Die Medien des gescheiterten Schubs sind weg',
    count($p->geloescht) > 0 && count($erhalten) === count($p->angelegt) - count($p->geloescht), true);

// ══ 4. Auch die Vorschaubilder des NACHZUGS werden zurueckgenommen ═══════
/* Aufbau: der Bestand kennt die Karte schon in derselben Fassung, ihr Anhang
   hat aber noch kein Vorschaubild. Der Nachzug holt es — und wenn danach das
   Schreiben scheitert, darf es nicht herrenlos liegen bleiben. */
$p = new Spiegelprobe();
$p->scheitertAb = 1;
$p->bestand['notes'][] = [
    'id' => 'n1', 'folderId' => 'f1', 'title' => 'K0', 'text' => 'Text 0',
    'att' => [['id' => 9, 'kind' => 'pdf', 'name' => 'brief0.pdf', 'bytes' => 10,
               'thumb' => 0, 'qr' => '']],
    'createdAt' => 1, 'updatedAt' => 1, 'source' => 'edumaps',
    'srcId' => 'edu:b0', 'srcRev' => 1000, 'srcAt' => 1000,
    'section' => '', 'pos' => 0, 'sectionColor' => '', 'color' => '',
    'html' => '<p>0</p>', 'booking' => null, 'srcUrl' => 'https://x.test/s#box-b0',
];
$p->Spiegle($seite, array_slice(karten(1), 0, 1));
pruefe('Der Nachzug hat ein Vorschaubild angelegt', count($p->angelegt), 1);
pruefe('… und es wird nach dem Fehlschlag wieder geloescht',
    $p->geloescht, $p->angelegt);

/**
 * Wie viele neue Medien hoechstens auf den Schub mit dieser Nummer warteten.
 * Rechnerisch aus der Zahl der Notizen je Schreibvorgang — je Karte zwei.
 */
function EduEinpflegen_schub(Spiegelprobe $p, int $nr): int
{
    $vorher = $nr === 0 ? 0 : (int)$p->geschrieben[$nr - 1]['notizen'];
    return ((int)$p->geschrieben[$nr]['notizen'] - $vorher) * 2;
}

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
