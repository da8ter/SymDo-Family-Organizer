<?php

declare(strict_types=1);

/**
 * Pruefstand: was die KI-Antwort zur HAUSAUFGABE macht (22.09.2026).
 *
 * Zwei Zusicherungen, beide aus einem echten Fall: der Lernzeitplan einer
 * ersten Klasse nennt Hefte statt Faecher („Buchstabenheft S. 16"). Bis heute
 * wurde eine Hausaufgabe ohne Fach zur AUFGABE herabgestuft — damit landete
 * ein ganzer Wochenplan des Kindes bei den Eltern. Jetzt bleibt sie eine
 * Hausaufgabe mit leerem Fach; das Fach traegt der Dialog beim Uebernehmen ab.
 * Dazu die Riegel am Prompt: die Hefte-Regel und der Satz, dass ein
 * Lernzeitplan AUCH Termine und Elternaufgaben enthaelt.
 *
 *   php SymDoGateway/tests/HausaufgabeErkennenTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/HomeworkCalc.php';
require_once __DIR__ . '/../libs/AiExtract.php';

final class HausaufgabeProbe extends IPSModuleStrict
{
    use AiExtract;

    /* AiExtract greift auf zwei Grenzen des Notiz-Bestands zu (Notes.php, ein
       anderer Trait desselben Moduls). Hier stehen sie mit denselben Werten,
       damit der Pruefstand ohne das ganze Modul laeuft. */
    private const NOTE_TEXT_MAX  = 3000;
    private const NOTE_TITLE_MAX = 120;

    public array $faecher = ['Deutsch', 'Mathematik', 'Sachunterricht'];
    public array $kinder  = ['k1'];

    private function HomeworkFaecher(): array { return $this->faecher; }
    private function HomeworkKinder(): array { return $this->kinder; }
    private function AiPersonZuBenutzer(string $name): array { return trim($name) === 'Lea' ? ['k1'] : []; }
    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    public function Translate(string $Text): string { return $Text; }

    /** @return list<array<string,mixed>> */
    public function pPruefen(array $rows): array
    {
        return (array)(new ReflectionMethod(self::class, 'AiValidateTodoRows'))
            ->invoke($this, $rows, ['task', 'event', 'note', 'homework']);
    }
    public function pPrompt(): string
    {
        return (string)(new ReflectionMethod(self::class, 'AiHomeworkKindRule'))->invoke($this);
    }
}

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
    printf("%-4s %-66s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

$m = new HausaufgabeProbe(1);
$art = static fn(array $zeilen): array => array_map(
    static fn(array $z): string => $z['kind'] . '/' . (string)($z['subject'] ?? '-'), $zeilen);

// ── Die Herabstufung ist weg ───────────────────────────────────────────────
$zeilen = $m->pPruefen([
    ['title' => 'Buchstabenheft S. 16', 'kind' => 'homework', 'due' => '2026-09-16'],
    ['title' => 'Mathetrainer', 'kind' => 'homework', 'subject' => 'Mathe', 'due' => '2026-09-18'],
    ['title' => 'Zettel unterschreiben', 'kind' => 'task', 'due' => '2026-09-18'],
    ['title' => 'Fototermin', 'kind' => 'event', 'due' => '2026-09-16'],
]);
pruefe('Ohne Fach bleibt es eine Hausaufgabe (leeres Fach), „Mathe" loest auf, Eltern-Zeilen bleiben unberuehrt',
    $art($zeilen), ['homework/', 'homework/Mathematik', 'task/-', 'event/-']);
pruefe('Die Hausaufgabe ohne Fach traegt trotzdem Frist, Notiz und keinen Takt',
    [$zeilen[0]['due'], $zeilen[0]['recurrence'], $zeilen[0]['time'], $zeilen[0]['allDay'], $zeilen[0]['assignedTo']],
    ['2026-09-16', null, null, false, []]);
pruefe('Das Kind wird nur aus einem KIND-Namen gesetzt',
    [$m->pPruefen([['title' => 'x', 'kind' => 'homework', 'person' => 'Lea']])[0]['childId'],
     $m->pPruefen([['title' => 'x', 'kind' => 'homework', 'person' => 'Papa']])[0]['childId']],
    ['k1', '']);
pruefe('Ohne Kinder im Haus steht „homework" nicht in der Weissliste — dann wird es eine Aufgabe',
    (function () use ($m): array {
        $r = (array)(new ReflectionMethod(HausaufgabeProbe::class, 'AiValidateTodoRows'))
            ->invoke($m, [['title' => 'x', 'kind' => 'homework']], ['task', 'event']);
        return array_map(static fn(array $z): string => $z['kind'], $r);
    })(), ['task']);

// ── Die Zusammenfassung ist kein Eintrag ───────────────────────────────────
pruefe('Das summary-Element wird verworfen, nicht zur Aufgabe gemacht',
    $art($m->pPruefen([
        ['kind' => 'summary', 'title' => 'Die Schule informiert über Dreharbeiten für den Schulfilm.'],
        ['kind' => 'SUMMARY', 'title' => 'Auch gross geschrieben raus.'],
        ['title' => 'Zweiter Drehtag', 'kind' => 'event', 'due' => '2027-09-25'],
    ])), ['event/-']);
pruefe('Eine unbekannte Art bleibt dagegen eine Aufgabe',
    $art($m->pPruefen([['kind' => 'quatsch', 'title' => 'Bleibt drin']])), ['task/-']);

// ── Riegel am Prompt ───────────────────────────────────────────────────────
$prompt = $m->pPrompt();
pruefe('Der Prompt schliesst das Fach aus dem Heft und erlaubt ein leeres Fach',
    [str_contains($prompt, 'Buchstabenheft'), str_contains($prompt, 'Forscherheft'),
     str_contains($prompt, 'Mathetrainer'), str_contains($prompt, 'Workbook'),
     str_contains($prompt, 'leerem "subject"')],
    [true, true, true, true, true]);
pruefe('Der Prompt trennt im Lernzeitplan die Kindsachen von Terminen und Elternaufgaben',
    [str_contains($prompt, 'EIN LERNZEITPLAN ENTHAELT BEIDES'), str_contains($prompt, 'unterschrieben'),
     str_contains($prompt, 'Fototermin')],
    [true, true, true]);


// ── Die Notiz nennt die Aufgabe (23.09.2026) ───────────────────────────────
/* Die Aufgabe steht im Titel, info ist Herkunft oder Hinweis. Bis heute kam die
   Notiz allein aus info — im Blatt stand „Siehe Rueckseite des Lernzeitplans."
   statt „Mathetrainer fuer jeden Tag bearbeiten". */
$z = $m->pPruefen([['kind' => 'homework', 'title' => 'Mathetrainer für jeden Tag bearbeiten',
                    'info' => 'Siehe Rückseite des Lernzeitplans.', 'subject' => 'Mathe']]);
pruefe('KI-Hausaufgabe: Notiz = Aufgabe + Hinweis', $z[0]['note'] ?? null,
    'Mathetrainer für jeden Tag bearbeiten — Siehe Rückseite des Lernzeitplans.');
$z = $m->pPruefen([['kind' => 'homework', 'title' => 'Buchstabenheft S. 16', 'subject' => '']]);
pruefe('KI-Hausaufgabe ohne info: der Titel ist die Notiz', $z[0]['note'] ?? null, 'Buchstabenheft S. 16');
$z = $m->pPruefen([['kind' => 'homework', 'title' => 'S. 42', 'info' => str_repeat('x', 900), 'subject' => 'Mathe']]);
pruefe('Lange Hinweise: gekappt auf NOTE_MAX, die Aufgabe vorne', [mb_strlen($z[0]['note'] ?? ''), mb_substr($z[0]['note'] ?? '', 0, 5)],
    [HomeworkCalc::NOTE_MAX, 'S. 42']);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
