<?php

declare(strict_types=1);

/**
 * Das Briefing als KI-Auftrag — einreihen, nicht doppelt, und wo das Ergebnis
 * landet.
 *
 * Der Anbieteraufruf dauert drei bis sechzig Sekunden und lief bisher in der
 * Spur, die auch die App bedient. Er geht jetzt als Hintergrund-Auftrag hinaus.
 * Gesammelt und formuliert wird weiter im Gateway: der Sammler liest ein
 * Dutzend Bestände dieser Instanz, ihn mitzuschicken hieße, den halben Haushalt
 * in eine Datei zu schreiben.
 *
 * Zwei Dinge sind daran teuer, wenn sie schiefgehen:
 *
 *  - **Doppelt einreihen.** Der Zeitgeber schaut alle paar Minuten nach, und
 *    solange der Auftrag läuft, ist das Fach leer. Ohne Merker stünden am
 *    Morgen fünf bezahlte Briefings in der Schlange.
 *  - **Der Merker, der hängen bleibt.** Geht ein Auftrag verloren (Kernelstart
 *    mitten im Lauf), fiele das Briefing bis morgen aus.
 *
 *   php SymDoGateway/tests/BriefingAuftragTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../../libs/AiProvider.php';
require_once __DIR__ . '/../../libs/AiJobStore.php';
require_once __DIR__ . '/../libs/Briefing.php';

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

/** Der Bestand ist ein Feld, die Aufträge sind ein Zähler. */
final class BriefingProbe
{
    use Briefing;

    public int $InstanceID = 4711;
    public array $bestand = [];
    public array $faecher = [];
    /** @var list<array<string,mixed>> Was eingereiht wurde. */
    public array $eingereiht = [];
    public bool $laeuferDa = true;
    public bool $annehmen = true;
    public int $jetzt = 1789000000;
    public array $gesprochen = [];
    public int $geklingelt = 0;

    // ── was der Trait an Symcon braucht ──────────────────────────────────
    private function BriefingStore(): array { return $this->bestand; }
    private function BriefingWriteStore(array $stand): bool { $this->bestand = $stand; return true; }
    private function BriefingSlot(int $tage): array
    {
        return $this->faecher[$tage] ?? ['d' => '', 'text' => '', 'at' => 0, 'userId' => '', 'clips' => []];
    }
    private function BriefingWriteSlot(int $tage, array $inhalt): bool
    {
        $this->faecher[$tage] = $inhalt;
        return true;
    }
    /* Die Aufnahme ist der zweite lange Aufruf — sie bleibt im Gateway, weil
       die Medienobjekte hier haengen. Hier zaehlt sie nur mit. */
    private function BriefingAudio(string $text): array
    {
        $this->gesprochen[] = $text;
        return [['datei' => 'a.mp3', 'format' => 'mp3', 'text' => $text]];
    }
    private function WsPushDirty(): void { $this->geklingelt++; }
    private function BriefingTextVariable(): void {}
    private function BriefingAudioObjekt(): void {}
    private function SendDebug(string $a, string $b, int $c): void {}
    private function AiJobMoeglich(): bool { return $this->laeuferDa; }
    private function AiJobEnqueue(string $kind, array $job, array $parse, array $origin,
        string $nutzlast = '', string $geraet = ''): array
    {
        $this->eingereiht[] = ['job' => $job, 'origin' => $origin];
        return $this->annehmen
            ? ['ok' => true, 'id' => 'j' . count($this->eingereiht)]
            : ['ok' => false, 'code' => 'ai_busy'];
    }

    // ── Tueren ───────────────────────────────────────────────────────────
    public function Auftrag(int $tage, string $zielTag): array
    {
        return $this->BriefingAuftragGeben($tage, $zielTag, 'u1', 'SYS', 'NUTZER');
    }
    public function Fertig(array $kopf): void { $this->BriefingAuftragEinpflegen($kopf); }
    public function Ablegen(int $tage, string $zielTag, array $antwort): array
    {
        return $this->BriefingErgebnisAblegen($tage, $zielTag, 'u1', $antwort);
    }
    public function Frist(): int { return self::BRIEFING_AUFTRAG_FRIST; }
}

$p = new BriefingProbe();

// ── Einreihen ────────────────────────────────────────────────────────────
$erg = $p->Auftrag(0, '2026-09-16');
pruefe('Der Auftrag wird eingereiht', [$erg['ok'], $erg['message']], [true, 'queued']);
pruefe('… als Hintergrundarbeit mit der Kennung „briefing"',
    [$p->eingereiht[0]['origin']['type'], $p->eingereiht[0]['origin']['art']],
    [AiJobStore::HERKUNFT_HINTERGRUND, 'briefing']);
pruefe('… und traegt Zieltag und Fach mit',
    [$p->eingereiht[0]['origin']['zielTag'], $p->eingereiht[0]['origin']['tage']],
    ['2026-09-16', 0]);
pruefe('… der Prompt reist mit',
    [$p->eingereiht[0]['job']['system'], $p->eingereiht[0]['job']['user']], ['SYS', 'NUTZER']);
pruefe('… und der Merker steht', $p->bestand['pending']['d'] ?? null, '2026-09-16');

// ── Nicht doppelt ────────────────────────────────────────────────────────
/* DAS ist der teure Fall: der Zeitgeber schaut alle paar Minuten nach, und
   solange der Auftrag laeuft, ist das Fach leer. */
$erg = $p->Auftrag(0, '2026-09-16');
pruefe('Ein zweiter Anlauf reiht NICHT noch einmal ein', count($p->eingereiht), 1);
pruefe('… meldet aber „laeuft schon"', [$erg['ok'], $erg['message']], [true, 'queued']);

/* Ein anderer Tag ist ein anderer Auftrag — abends die Vorschau auf morgen. */
$p->Auftrag(1, '2026-09-17');
pruefe('Ein anderer Zieltag darf eingereiht werden', count($p->eingereiht), 2);

// ── Der Merker verfaellt ─────────────────────────────────────────────────
/* Geht ein Auftrag verloren (Kernelstart mitten im Lauf), soll das Briefing
   nicht bis morgen ausfallen. */
$p2 = new BriefingProbe();
$p2->bestand = ['pending' => ['d' => '2026-09-16', 'at' => time() - $p2->Frist() + 60, 'id' => 'alt']];
$p2->Auftrag(0, '2026-09-16');
pruefe('Kurz vor Fristablauf wird nicht neu eingereiht', count($p2->eingereiht), 0);
$p2->bestand = ['pending' => ['d' => '2026-09-16', 'at' => time() - $p2->Frist() - 1, 'id' => 'alt']];
$p2->Auftrag(0, '2026-09-16');
pruefe('Nach Fristablauf schon', count($p2->eingereiht), 1);

// ── Abgelehnt ────────────────────────────────────────────────────────────
/* Ist die Schlange voll, darf KEIN Merker stehen bleiben — sonst waere das
   Briefing eine halbe Stunde lang blockiert, ohne dass je einer lief. */
$p3 = new BriefingProbe();
$p3->annehmen = false;
$erg = $p3->Auftrag(0, '2026-09-16');
pruefe('Ein abgelehnter Auftrag meldet den Fehlschlag',
    [$erg['ok'], $erg['retry']], [false, true]);
pruefe('… und hinterlaesst KEINEN Merker', isset($p3->bestand['pending']), false);

// ── Das Ergebnis ─────────────────────────────────────────────────────────
$p4 = new BriefingProbe();
$p4->bestand = ['pending' => ['d' => '2026-09-16', 'at' => time(), 'id' => 'j1']];
$p4->Fertig(['id' => 'j1', 'raw' => ['ok' => true, 'text' => "Guten Morgen.\n\n* Punkt"],
             'origin' => ['type' => AiJobStore::HERKUNFT_HINTERGRUND, 'art' => 'briefing',
                          'tage' => 0, 'zielTag' => '2026-09-16', 'userId' => 'u1']]);
pruefe('Das Fach ist gefuellt', $p4->faecher[0]['d'] ?? null, '2026-09-16');
pruefe('… durch denselben Putzlappen wie der synchrone Weg',
    str_contains((string)($p4->faecher[0]['text'] ?? ''), '*'), false);
pruefe('… die Aufnahme entsteht hier', count($p4->gesprochen), 1);
pruefe('… es wird geklingelt', $p4->geklingelt, 1);
pruefe('… und der Merker ist weg', isset($p4->bestand['pending']), false);

// ── Ein Fehlschlag ───────────────────────────────────────────────────────
$p5 = new BriefingProbe();
$p5->bestand = ['pending' => ['d' => '2026-09-16', 'at' => time(), 'id' => 'j1']];
$p5->Fertig(['id' => 'j1', 'raw' => ['ok' => false, 'code' => 'ai_unreachable'],
             'origin' => ['art' => 'briefing', 'tage' => 0, 'zielTag' => '2026-09-16']]);
pruefe('Ein Fehlschlag zaehlt mit', (int)($p5->bestand['fails'] ?? 0), 1);
pruefe('… fuellt kein Fach', isset($p5->faecher[0]), false);
/* Auch bei einem Fehlschlag MUSS der Merker weg — sonst bliebe das Briefing
   bis zum Fristablauf blockiert. */
pruefe('… und raeumt den Merker weg', isset($p5->bestand['pending']), false);

/* Ein Umschlag ohne Zieltag gehoert niemandem. */
$p6 = new BriefingProbe();
$p6->Fertig(['id' => 'j9', 'raw' => ['ok' => true, 'text' => 'X'], 'origin' => ['art' => 'briefing']]);
pruefe('Ohne Zieltag passiert nichts', [$p6->faecher, $p6->gesprochen], [[], []]);

// ── Beide Wege gehen durch DIESELBE Tuer ─────────────────────────────────
$quelle = (string)file_get_contents(__DIR__ . '/../libs/Briefing.php');
pruefe('Der synchrone Weg legt ueber dieselbe Stelle ab',
    str_contains($quelle, 'return $this->BriefingErgebnisAblegen($tage, $zielTag, (string)$daten[\'userId\'], $antwort);'), true);
pruefe('Der Auftrags-Weg auch',
    str_contains($quelle, '$erg = $this->BriefingErgebnisAblegen($tage, $zielTag,'), true);
/* Nur EINE Stelle darf ein Fach schreiben — sonst waeren es zwei Politiken. */
pruefe('Nur eine Stelle schreibt ein Fach',
    substr_count($quelle, '$this->BriefingWriteSlot('), 1);
/* ZWEI Stellen rufen den Anbieter direkt, und die zweite ist Absicht: die
   Vorschau auf einen anderen Tag (`BriefingPreview`) laeuft im HOOK, legt
   nichts ab und antwortet dem Wartenden sofort. Ein Auftrag haette dort nichts
   zu melden. Die Zahl steht hier ausdruecklich, damit eine DRITTE auffaellt. */
pruefe('Genau zwei Stellen rufen den Anbieter direkt',
    substr_count($quelle, '$this->AiRunCompletion('), 2);
$vorschau = substr($quelle, (int)strpos($quelle, 'private function BriefingPreview('));
pruefe('… und die zweite ist die Vorschau im Hook',
    str_contains(substr($vorschau, 0, 900), '$this->AiRunCompletion('), true);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
