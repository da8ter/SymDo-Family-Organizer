<?php

declare(strict_types=1);

/**
 * Pruefstand: uebernommene KI-Vorschlaege bleiben mit Haken stehen (19.09.2026).
 *
 * Server: `taken` heisst seit heute „uebernommen" (bleibt sieben Tage sichtbar,
 * wenn die Oberflaeche withTaken fragt) — `dropped` heisst „verworfen" (weg).
 * Die App ohne withTaken bekommt wie immer nur die offenen. Dazu Riegel am
 * Quelltext der Web-App: die drei Stellen, an denen ein Vorschlag als
 * uebernommen gilt, melden es dem Server — und der Kalenderdialog nimmt dafuer
 * den Kontext von VOR dem Schliessen (daran blieb „Drehtag Schulfilm" offen).
 *
 *   php SymDoGateway/tests/VorschlagErledigtTest.php
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

final class VorschlagProbe extends IPSModuleStrict
{
    use MailScan;

    public array $attr = [];
    protected function ReadAttributeString(string $Name): string { return (string)($this->attr[$Name] ?? ''); }
    protected function WriteAttributeString(string $Name, string $Value): bool { $this->attr[$Name] = $Value; return true; }
    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    public function Translate(string $Text): string { return $Text; }
    private function HomeworkKinder(): array { return []; }
    public string $kiAntwort = '';
    public array $kiRufe = [];
    private function AiRunCompletion(string $system, string $userText, ?string $imageBase64, ?string $pdfBase64 = null): array
    {
        $this->kiRufe[] = $userText;
        return $this->kiAntwort === 'FEHLER' ? ['ok' => false] : ['ok' => true, 'text' => $this->kiAntwort];
    }

    public function pAktion(array $body): array { return $this->MailHandleAction($body); }
    public function pSetzen(array $liste): void { $this->attr['MailProposals'] = json_encode($liste, JSON_UNESCAPED_UNICODE); }
    public function pRoh(): array { return json_decode($this->attr['MailProposals'] ?? '[]', true); }
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
$titel = static fn(array $antwort): array => array_map(
    static fn(array $it): string => $it['title'] . ($it['taken'] ?? false ? '✓' : ''),
    $antwort['proposals'][0]['items'] ?? []);

$m = new VorschlagProbe(1);
$jetzt = time();
$m->pSetzen([[
    'id' => 'edu:1', 'at' => $jetzt, 'created' => $jetzt, 'userId' => 'k1', 'subject' => 'Brief',
    'items' => [
        ['title' => 'Offen',      'kind' => 'event'],
        ['title' => 'Uebernommen','kind' => 'task', 'taken' => true, 'takenAt' => $jetzt - 3600],
        ['title' => 'Verworfen',  'kind' => 'task', 'taken' => true, 'takenAt' => $jetzt - 3600, 'dropped' => true],
        ['title' => 'Alt',        'kind' => 'task', 'taken' => true, 'takenAt' => $jetzt - 8 * 86400],
        ['title' => 'Vorher',     'kind' => 'task', 'taken' => true],
    ],
]]);
pruefe('Ohne withTaken (die App): nur die offenen Eintraege',
    $titel($m->pAktion(['action' => 'list'])), ['Offen']);
pruefe('Mit withTaken (Web-App): dazu die uebernommenen der letzten sieben Tage — nicht verworfene, alte, oder solche von vor dem Haken',
    $titel($m->pAktion(['action' => 'list', 'withTaken' => true])), ['Offen', 'Uebernommen✓']);
pruefe('Die Nummer i bleibt die des gespeicherten Eintrags',
    array_column($m->pAktion(['action' => 'list', 'withTaken' => true])['proposals'][0]['items'], 'i'), [0, 1]);

// Uebernehmen
pruefe('taken ohne dropped: uebernommen — Haken, Zeitstempel, nicht verworfen',
    [$m->pAktion(['action' => 'taken', 'id' => 'edu:1', 'i' => 0])['ok'],
     $m->pRoh()[0]['items'][0]['taken'], $m->pRoh()[0]['items'][0]['dropped'], abs($m->pRoh()[0]['items'][0]['takenAt'] - time()) < 5],
    [true, true, false, true]);
pruefe('Danach steht er mit Haken in der Liste — und faellt aus der App-Liste',
    [$titel($m->pAktion(['action' => 'list', 'withTaken' => true])), $titel($m->pAktion(['action' => 'list']))],
    [['Offen✓', 'Uebernommen✓'], []]);
// Verwerfen
$m->pSetzen([['id' => 'edu:2', 'at' => $jetzt, 'created' => $jetzt, 'items' => [['title' => 'A', 'kind' => 'task'], ['title' => 'B', 'kind' => 'task']]]]);
pruefe('taken mit dropped: verworfen — verschwindet auch mit withTaken',
    [$m->pAktion(['action' => 'taken', 'id' => 'edu:2', 'i' => 0, 'dropped' => true])['ok'],
     $m->pRoh()[0]['items'][0]['dropped'], $titel($m->pAktion(['action' => 'list', 'withTaken' => true]))],
    [true, true, ['B']]);
pruefe('Unbekannte Nummer oder Kennung: false, nichts geaendert',
    [$m->pAktion(['action' => 'taken', 'id' => 'edu:2', 'i' => 9])['ok'], $m->pAktion(['action' => 'taken', 'id' => 'x', 'i' => 0])['ok'],
     count($m->pRoh()[0]['items'])],
    [false, false, 2]);

// ── Reihenfolge: zuletzt eingetroffen oben ─────────────────────────────────
$m->pSetzen([
    ['id' => 'a', 'at' => $jetzt + 9 * 86400, 'created' => $jetzt - 5 * 86400, 'items' => [['title' => 'Alt, Quelldatum in der Zukunft', 'kind' => 'task']]],
    ['id' => 'b', 'at' => $jetzt - 3 * 86400, 'created' => $jetzt,             'items' => [['title' => 'Frisch', 'kind' => 'task']]],
    ['id' => 'c', 'at' => $jetzt - 1 * 86400,                                  'items' => [['title' => 'Ohne created', 'kind' => 'task']]],
]);
pruefe('Sortiert wird nach dem EINTREFFEN (created), nicht nach dem Quelldatum — ohne created gilt at',
    array_column($m->pAktion(['action' => 'list'])['proposals'], 'id'), ['b', 'c', 'a']);

// ── Art umstellen (wie beim Dokumentenscan) ────────────────────────────────
$m->pSetzen([['id' => 'edu:3', 'at' => $jetzt, 'created' => $jetzt, 'items' => [
    ['title' => 'A', 'kind' => 'task'], ['title' => 'B', 'kind' => 'task', 'taken' => true, 'takenAt' => $jetzt]]]]);
pruefe('kind: eine offene Zeile wird zum Termin — gespeichert, in der Liste sichtbar',
    [$m->pAktion(['action' => 'kind', 'id' => 'edu:3', 'i' => 0, 'kind' => 'event'])['ok'], $m->pRoh()[0]['items'][0]['kind'],
     $m->pAktion(['action' => 'list'])['proposals'][0]['items'][0]['kind']],
    [true, 'event', 'event']);
pruefe('kind: unbekannte Art und uebernommene Zeile werden abgewiesen',
    [$m->pAktion(['action' => 'kind', 'id' => 'edu:3', 'i' => 0, 'kind' => 'shopping'])['ok'],
     $m->pAktion(['action' => 'kind', 'id' => 'edu:3', 'i' => 1, 'kind' => 'event'])['ok'], $m->pRoh()[0]['items'][1]['kind']],
    [false, false, 'task']);

// ── Zusammenfassung nachholen ──────────────────────────────────────────────
$m->pSetzen([['id' => 'mail:9', 'at' => $jetzt, 'created' => $jetzt, 'subject' => 'Fwd: Info', 'fromName' => 'Papa',
    'origin' => ['name' => 'Grundschule Musterstadt', 'subject' => 'Elternabend 5a'],
    'items' => [['title' => 'Elternabend', 'info' => 'Am 1.10. um 19 Uhr in der Aula', 'kind' => 'event']]]]);
$m->kiAntwort = "  \"Die Grundschule Musterstadt lädt zum   Elternabend der 5a ein.\" ";
pruefe('summarize: Antwort gesaeubert gespeichert, Stoff nennt Betreff, Absender und Eintraege',
    [$m->pAktion(['action' => 'summarize', 'id' => 'mail:9'])['ok'], $m->pRoh()[0]['summary'],
     str_contains($m->kiRufe[0], 'Betreff: Elternabend 5a'), str_contains($m->kiRufe[0], 'Von: Grundschule Musterstadt'),
     str_contains($m->kiRufe[0], '- Elternabend: Am 1.10.')],
    [true, 'Die Grundschule Musterstadt lädt zum Elternabend der 5a ein.', true, true, true]);
$m->kiAntwort = '[{"kind":"summary"}]';
pruefe('summarize: JSON, Leeres oder Fehler des Anbieters aendern nichts',
    [$m->pAktion(['action' => 'summarize', 'id' => 'mail:9'])['ok'],
     (function () use ($m) { $m->kiAntwort = ''; return $m->pAktion(['action' => 'summarize', 'id' => 'mail:9'])['ok']; })(),
     (function () use ($m) { $m->kiAntwort = 'FEHLER'; return $m->pAktion(['action' => 'summarize', 'id' => 'mail:9'])['ok']; })(),
     $m->pAktion(['action' => 'summarize', 'id' => 'gibtsnicht'])['ok'], $m->pRoh()[0]['summary']],
    [false, false, false, false, 'Die Grundschule Musterstadt lädt zum Elternabend der 5a ein.']);

// ── Riegel an der Web-App ──────────────────────────────────────────────────
$wurzel = __DIR__ . '/../..';
$lesen = static fn(string $p): string => (string)@file_get_contents($wurzel . '/' . $p);
$html = $lesen('SymDoWebApp/module.html');
$cal = substr($html, strpos($html, 'function calSpeichernSenden('), 3000);
pruefe('Kalenderdialog: meldet den Vorschlag ueber den VOR dem Schliessen gesicherten Kontext (ctx), nicht ueber calCtx',
    [str_contains($cal, 'if (ctx && ctx.proposal) mailErledigt('), str_contains($cal, 'calCtx.proposal.id')], [true, false]);
pruefe('Alle vier Uebernahme-Wege melden „uebernommen": Kalender, Aufgabe, Hausaufgabe, Notiz',
    [substr_count($html, 'mailErledigt(') >= 5, str_contains($html, 'if (p) mailErledigt(p.id, p.i);'),
     str_contains($html, 'mailErledigt(noteCtx.proposal.id, noteCtx.proposal.i)'), str_contains($html, 'if (todoCtx.proposal) mailErledigt(')],
    [true, true, true, true]);
pruefe('Verwerfen meldet dropped; die Liste wird mit withTaken geholt; Abzeichen zaehlt nur Offene',
    [str_contains($html, "i: Number(i), dropped: true"), str_contains($html, "aiPost('/mail/proposals', { withTaken: true })"),
     str_contains($html, "if (it.taken === true) return;")],
    [true, true, true]);
pruefe('Zeile: Haken statt Knoepfe, keine Wischgeste, kein Uebernehmen an erledigten',
    [str_contains($html, 'class="mail-done"'), str_contains($html, "if (zeile.classList.contains('erledigt')) return;"),
     str_contains($html, "if (!eintrag || eintrag.taken === true) return;")],
    [true, true, true]);
$kopien = ['ShoppingList', 'ToDoList', 'SymDoEdumaps', 'SymDoNotes', 'SymDoHomework'];
pruefe('Zeile: Art-Wahl als Auswahlfeld an offenen Zeilen, change setzt sie hier und beim Server',
    [str_contains($html, 'function mailArtWahlHtml'), str_contains($html, '<select class="mail-kind-select" data-mail="kind"'),
     str_contains($html, "e.target.closest('select[data-mail=\"kind\"]')"), str_contains($html, 'class="mail-summary"'),
     str_contains($html, "aiPost('/mail/proposals', { action: 'kind', id: id, i: Number(i), kind: kind })"),
     str_contains($html, ": `<div class=\"mail-row-kind\">\${mailArtWahlHtml(sorte)}</div>`)")],
    [true, true, true, true, true, true]);
$php = $lesen('SymDoGateway/libs/MailScan.php') . $lesen('SymDoGateway/libs/AiJobs.php') . $lesen('SymDoGateway/libs/AiExtract.php');
pruefe('Server: die Zusammenfassung reist auf BEIDEN Wegen (synchron und Auftrag) und der Prompt bittet darum',
    [substr_count($php, 'MailAnalyseCalc::Zusammenfassung(') >= 2, substr_count($php, "'summary' => \$zusammen]") === 2,
     substr_count($php, '$this->AiSummaryRule()') === 3],
    [true, true, true]);
pruefe('Fusszeile: „Löschen" rechts, links die Quelle — mit Rueckfall auf die Kennung, sonst gar nichts',
    [str_contains($html, 'function mailQuellWort'), str_contains($html, "id.startsWith('edu:')"),
     str_contains($html, "id.startsWith('moodle')"), str_contains($html, "translate('Source: %1')"),
     str_contains($html, 'class="mail-quelle"'), str_contains($html, "<i class=\"fa-light fa-trash-can\"></i>\${escapeHtml(translate('Delete'))}"),
     str_contains($lesen('SymDoGateway/libs/MailScan.php'), "(string)(\$erg['summary'] ?? ''), \$quelle)")],
    [true, true, true, true, true, true, true]);
pruefe('Die Quellen-Woerter stehen in allen locale.json',
    array_map(static fn($k) => array_map(static fn($w) => json_decode($lesen("$k/locale.json"), true)['translations']['de'][$w] ?? null,
        ['Source: %1', 'Class page', 'Delete']), array_merge(['SymDoWebApp'], $kopien)),
    array_fill(0, 6, ['Quelle: %1', 'Klassenseite', 'Löschen']));
pruefe('Alle fuenf Kachel-Kopien tragen den Haken',
    array_map(static fn($k) => str_contains($lesen("$k/module.html"), 'function mailErledigt'), $kopien), array_fill(0, 5, true));
pruefe('„Taken over" heisst ueberall „Uebernommen"',
    array_map(static fn($k) => json_decode($lesen("$k/locale.json"), true)['translations']['de']['Taken over'] ?? null,
        array_merge(['SymDoWebApp'], $kopien)), array_fill(0, 6, 'Übernommen'));

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
