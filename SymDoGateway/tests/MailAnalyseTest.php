<?php

declare(strict_types=1);

/**
 * Was aus einer analysierten Nachricht wird — Zählung, Protokollzeile, Satz.
 *
 * Warum es diesen Prüfstand geben MUSS: der Analyse-Weg wandert beim Umbau
 * „Gateway frei halten" zwischen zwei Instanzen, und drei seiner Eigenheiten
 * überleben so einen Umzug erfahrungsgemäß nicht von allein:
 *
 *  - **Zwei Aufgabenzahlen, die verschieden sein müssen.** Das Protokoll zieht
 *    Hausaufgaben ab, der Push nicht. Wer beide auf dieselbe Zahl zieht — der
 *    naheliegendste „Aufräumschritt" überhaupt —, ändert stillschweigend den
 *    Text der Meldung, die beim Nutzer auf dem Sperrbildschirm steht.
 *  - **`created` gehört dem Schreiben, nicht dem Rechnen.** Danach richtet sich
 *    die Aufbewahrung von 21 Tagen. Wird beim Rechnen gestempelt und liegt der
 *    Auftrag dann in einer Warteschlange, fällt der Vorschlag früher aus der
 *    Liste — der bezahlte Anbieter-Aufruf ist dann umsonst gewesen.
 *  - **`homework` als erlaubte Art hängt an den Kindern.** Ohne sie verwirft
 *    `AiValidateTodoRows` die Zeile still.
 *
 *   php SymDoGateway/tests/MailAnalyseTest.php
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

/**
 * Die rechnende Hälfte wirklich FAHREN, nicht nur ihren Quelltext ansehen.
 *
 * Warum das nötig ist: `$betreff` verschwand beim Herauslösen von
 * `MailAnalyseEingabe` aus dem Rumpf, die Verwendung blieb stehen. Mit
 * `strict_types` ist das ein TypeError — **nach** dem bezahlten Anbieteraufruf,
 * in einer Funktion ohne `catch`. Weder `MailRemember` noch `MailCountFailure`
 * liefen dann, und dieselbe Mail wäre bei jedem Lauf wieder die erste gewesen.
 *
 * Gemerkt hat es niemand: siebenundzwanzig Zusicherungen prüften den
 * Rechenkern, Quelltext-Riegel prüften, was die Funktion NICHT anfasst — aber
 * ausgeführt hat sie keiner. Ein externer Codereview fand es (15.09.2026).
 */
final class RechnenProbe extends IPSModuleStrict
{
    use MailScan;

    /** Was der Anbieter zu sehen bekam, und was er antwortet. */
    public array $anbieterRufe = [];
    public string $antwort = '[]';
    public array $protokoll = [];

    private function AiRunCompletion(string $system, string $user, ?string $bild = null,
        ?string $pdf = null): array
    {
        $this->anbieterRufe[] = ['user' => $user, 'bild' => $bild !== null, 'pdf' => $pdf !== null];
        return ['ok' => true, 'text' => $this->antwort];
    }
    private function AiMailSystemPrompt(string $heute, bool $mitAnhang, string $quelle): string
    {
        return 'System(' . ($mitAnhang ? 'mit' : 'ohne') . ',' . $quelle . ')';
    }
    private function AiParseTodos(string $t, array $arten = []): array
    {
        return is_array($d = json_decode($t, true)) ? $d : [];
    }
    private function HomeworkKinder(): array { return ['k1']; }

    public function pRechnen(array $kopf, string $text, array $anhaenge, string $quelle): array
    {
        return (array)(new ReflectionMethod(self::class, 'MailAnalyseRechnen'))
            ->invoke($this, $kopf, $text, $anhaenge, $quelle);
    }

    protected function getTime(): int { return time(); }
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
    printf("%-4s %-60s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

// ── Die Zählung ────────────────────────────────────────────────────────────
$funde = [
    ['kind' => 'task',     'due' => '2026-09-20'],
    ['kind' => 'task',     'due' => null],
    ['kind' => 'event',    'due' => '2026-09-21'],
    ['kind' => 'note'],
    ['kind' => 'homework', 'due' => '2026-09-22'],
    ['kind' => 'homework', 'due' => null],
];
$z = MailAnalyseCalc::Zaehlen($funde);
pruefe('Termine, Notizen und Hausaufgaben werden getrennt gezaehlt',
    [$z['termine'], $z['notizen'], $z['hausaufgaben']], [1, 1, 2]);
pruefe('Mit Datum zaehlt ueber alle Arten', $z['mitDatum'], 3);

/* DER Fall. Sechs Funde: 2 Aufgaben, 1 Termin, 1 Notiz, 2 Hausaufgaben.
   Das Protokoll sagt „2 Aufgabe(n)", der Push meldet 4 — weil er die
   Hausaufgaben mitzaehlt. Beides ist gewachsener Stand und bleibt so. */
pruefe('Das Protokoll zieht Hausaufgaben ab', $z['aufgabenProtokoll'], 2);
pruefe('Der Push zieht sie NICHT ab', $z['aufgabenPush'], 4);
pruefe('… und die beiden Zahlen sind damit verschieden',
    $z['aufgabenProtokoll'] === $z['aufgabenPush'], false);

pruefe('Eine Zeile ohne Art gilt als Aufgabe',
    MailAnalyseCalc::Zaehlen([['due' => null]])['aufgabenProtokoll'], 1);
pruefe('Nichts gefunden zaehlt ueberall null',
    array_values(MailAnalyseCalc::Zaehlen([])), [0, 0, 0, 0, 0, 0]);

// ── Die erlaubten Arten ────────────────────────────────────────────────────
pruefe('Ohne Kinder gibt es keine Hausaufgaben',
    MailAnalyseCalc::Arten(false), ['task', 'event', 'note']);
pruefe('Mit Kindern kommt homework dazu',
    MailAnalyseCalc::Arten(true), ['task', 'event', 'note', 'homework']);

// ── Die Protokollzeile ─────────────────────────────────────────────────────
$zeile = MailAnalyseCalc::Meldung('Kopiergeld', 'schule@example.test', 'IMAP', [], $z);
pruefe('Die Zeile nennt die Protokoll-Zahl, nicht die Push-Zahl',
    str_contains($zeile, '→ 2 Aufgabe(n), 1 Termin(e), 1 Notiz(en), 2 Hausaufgabe(n), davon 3 mit Datum'), true);
pruefe('Der IMAP-Weg nennt sich nicht', str_contains($zeile, '(IMAP)'), false);
/* Der Ersatz „?" gehoert dem AUFRUFER, nicht dieser Funktion: alt stand er im
   `??`, griff also nur bei FEHLENDEM Schluessel. Der Webhook-Weg setzt den
   Schluessel aber mit leerem Wert, wenn Mailgun weder From noch sender liefert
   — dort stand bisher eine Leerstelle. */
pruefe('Eine leere Adresse bleibt leer',
    str_contains(MailAnalyseCalc::Meldung('x', '', 'IMAP', [], $z), 'von  analysiert'), true);
pruefe('… und wird hier NICHT zu einem Fragezeichen',
    str_contains(MailAnalyseCalc::Meldung('x', '', 'IMAP', [], $z), 'von ? analysiert'), false);
pruefe('Ein anderer Eingang schon',
    str_contains(MailAnalyseCalc::Meldung('x', 'a@b.test', 'Edumaps', [], $z), '(Edumaps)'), true);
pruefe('Ohne Betreff steht das auch da',
    str_contains(MailAnalyseCalc::Meldung('', 'a@b.test', 'IMAP', [], $z), '„(ohne Betreff)"'), true);
pruefe('Anhaenge werden mit Namen genannt',
    str_contains(MailAnalyseCalc::Meldung('x', 'a@b.test', 'IMAP',
        [['name' => 'brief.pdf', 'kind' => 'pdf']], $z), '(mit 1 Anhang/Anhaengen: brief.pdf)'), true);
/* Kein Titel im Protokoll: es ist kein Ort fuer Inhalte. */
pruefe('Kein Aufgabentitel steht in der Zeile',
    str_contains(MailAnalyseCalc::Meldung('x', 'a@b.test', 'IMAP', [],
        MailAnalyseCalc::Zaehlen([['kind' => 'task', 'title' => 'Turnbeutel mitbringen']])),
        'Turnbeutel'), false);

// ── Der Satz ───────────────────────────────────────────────────────────────
$kopf = ['Date' => 1757000000, 'SenderAddress' => 'a@b.test', 'SenderName' => '5b',
         'Recipient' => 'eltern@example.test'];
/* Die Herkunft ist ein OBJEKT, so wie MailDetectOrigin sie liefert — kein Text.
   Mit einem Text lief dieser Prueflauf gruen, waehrend der Produktionsweg an
   einem TypeError starb: nach dem bezahlten Anbieter-Aufruf, ohne catch, und
   damit wurde weder MailRemember noch MailCountFailure erreicht. Dieselbe Mail
   waere bei jedem Lauf wieder die erste gewesen. */
$herkunft = ['name' => 'Frau Muster', 'address' => 'muster@example.test',
             'subject' => 'Kopiergeld'];
$satz = MailAnalyseCalc::Satz('v1', $kopf, 'Kopiergeld', 'u1', $herkunft,
    [['kind' => 'task', 'title' => 'Zahlen']], 1757999999);

pruefe('Das Datum der Liste ist das des DOKUMENTS', $satz['at'], 1757000000);
/* `created` fehlt absichtlich: es traegt die 21-Tage-Aufbewahrung und wird
   beim SCHREIBEN gestempelt. Steht es hier, zaehlt bei einem ausgelagerten
   Lauf die Wartezeit in der Schlange mit. */
pruefe('created bleibt draussen — es gehoert dem Schreiben',
    array_key_exists('created', $satz), false);
pruefe('Jeder Fund kommt unuebernommen herein', $satz['items'][0]['taken'], false);
pruefe('Die Herkunft bleibt ein Objekt', $satz['origin'], $herkunft);
/* Die Web-App liest `typeof p.origin === 'object'` und faellt sonst auf den
   aeusseren „Fwd:"-Kopf zurueck — also auf das weiterleitende Familienmitglied
   statt auf die Schule. Ein Text hier waere kein Absturz, nur eine falsche
   Absenderzeile an jeder Karte. */
pruefe('… und zwar genau das, was MailDetectOrigin liefert',
    array_keys($satz['origin']), ['name', 'address', 'subject']);
pruefe('Die Signatur verlangt ein Array, keinen Text',
    (string)(new ReflectionMethod(MailAnalyseCalc::class, 'Satz'))->getParameters()[4]->getType(),
    'array');
pruefe('Ohne Datum im Kopf gilt die gereichte Uhr',
    MailAnalyseCalc::Satz('v1', [], '', '', [], [], 1757999999)['at'], 1757999999);

// ── Anhaenge einhaengen ────────────────────────────────────────────────────
$abgelegt = [['id' => 42, 'name' => 'brief.pdf', 'kind' => 'pdf', 'bytes' => 9]];
$mit = MailAnalyseCalc::AnhaengeEinhaengen(
    [['kind' => 'task'], ['kind' => 'note'], ['kind' => 'note']], $abgelegt);
pruefe('Anhaenge haengen NUR an Notizen',
    [isset($mit[0]['atts']), isset($mit[1]['atts']), isset($mit[2]['atts'])],
    [false, true, true]);
/* `mediaId` bleibt neben `atts` stehen, damit Vorschlaege aus der Zeit davor
   weiter uebernommen werden koennen — NotesAdopt liest beides. */
pruefe('mediaId steht weiter daneben', $mit[1]['mediaId'], 42);
pruefe('Ohne abgelegte Anhaenge bleibt alles unberuehrt',
    MailAnalyseCalc::AnhaengeEinhaengen([['kind' => 'note']], []), [['kind' => 'note']]);

// ══ Die rechnende Haelfte, wirklich gefahren ═════════════════════════════
IPS\Kernel::reset();
$r = new RechnenProbe(777);
$r->Create();

/* Jede undefinierte Zelle in diesem Rumpf faellt hier auf — sie wird zur
   Warnung, und die Warnung macht der Prueflauf zum Fehler. */
set_error_handler(static function (int $n, string $m): bool {
    throw new RuntimeException($m);
});
try {
    $r->antwort = '[{"kind":"task","title":"Turnbeutel"},{"kind":"event","title":"Fest"}]';
    $e = $r->pRechnen(['Subject' => 'Kopiergeld', 'SenderAddress' => 'a@b.test', 'Date' => 1],
        'Bitte 5 Euro', [], 'Edumaps');
    pruefe('Der Lauf kommt ohne Warnung durch', true, true);
} catch (Throwable $t) {
    pruefe('Der Lauf kommt ohne Warnung durch', $t->getMessage(), true);
    $e = ['ok' => false, 'kiAufrufe' => 0, 'protokoll' => '', 'aufgaben' => [], 'zahlen' => []];
} finally {
    restore_error_handler();
}

pruefe('Er meldet Erfolg und genau einen Anbieter-Aufruf',
    [$e['ok'], $e['kiAufrufe']], [true, 1]);
pruefe('Der Betreff steht in der Protokollzeile',
    str_contains((string)$e['protokoll'], '„Kopiergeld"'), true);
pruefe('Die Zahlen stimmen',
    [$e['zahlen']['aufgabenProtokoll'], $e['zahlen']['termine']], [1, 1]);
/* Der Betreff geht AUCH an den Anbieter — davor stand er im Rumpf. */
pruefe('Der Betreff steht in der Eingabe an den Anbieter',
    str_starts_with((string)$r->anbieterRufe[0]['user'], 'Betreff: Kopiergeld'), true);
pruefe('Ohne Anhang wird der Systemtext ohne Anhang gebaut',
    [$r->anbieterRufe[0]['bild'], $r->anbieterRufe[0]['pdf']], [false, false]);

/* Ein Bild geht als Bild mit, ein PDF als PDF — und der Dateiname steht im
   Text, damit die KI weiss, dass etwas beiliegt. */
$r->anbieterRufe = [];
$r->antwort = '[]';
$e = $r->pRechnen(['Subject' => 'Elternbrief'], 'Text',
    [['kind' => 'pdf', 'name' => 'brief.pdf', 'base64' => 'AAA']], 'Edumaps');
pruefe('Ein PDF geht als PDF mit',
    [$r->anbieterRufe[0]['bild'], $r->anbieterRufe[0]['pdf']], [false, true]);
pruefe('Der Dateiname steht in der Eingabe',
    str_contains((string)$r->anbieterRufe[0]['user'], '(Beigefuegte Datei: brief.pdf)'), true);
/* Nichts gefunden ist kein Fehler — nur nichts zu tun. */
pruefe('Ohne Funde bleibt es ein Erfolg', [$e['ok'], $e['aufgaben']], [true, []]);

/* Die Zusammenfassung: ein Element {"kind":"summary"} im selben Array. Sie wird
   VOR der Eintrags-Pruefung gelesen, denn die verwirft die Zeile still. */
$antwort = '```json' . "\n" . '[{"kind":"summary","title":"  Die Klasse 5a lädt zum   Elternabend ein. "},'
    . '{"title":"Elternabend","kind":"event","due":"2026-10-01"}]' . "\n" . '```';
pruefe('Zusammenfassung wird aus dem Array gelesen, Leerraum gebuendelt',
    MailAnalyseCalc::Zusammenfassung($antwort), 'Die Klasse 5a lädt zum Elternabend ein.');
pruefe('Ohne summary-Element: leer; ohne Array: leer; Rueckfall auf text/summary-Feld',
    [MailAnalyseCalc::Zusammenfassung('[{"title":"x","kind":"task"}]'), MailAnalyseCalc::Zusammenfassung('nichts'),
     MailAnalyseCalc::Zusammenfassung('[{"kind":"SUMMARY","summary":"Kurz"}]')],
    ['', '', 'Kurz']);
pruefe('Die Zusammenfassung ist gedeckelt',
    mb_strlen(MailAnalyseCalc::Zusammenfassung('[{"kind":"summary","title":"' . str_repeat('a', 900) . '"}]')),
    MailAnalyseCalc::ZUSAMMENFASSUNG_MAX);
$satz = MailAnalyseCalc::Satz('m:1', ['Subject' => 'B'], 'B', 'u', [], [['title' => 'x']], 1, ' Worum es geht ');
pruefe('Der Satz traegt die Zusammenfassung; ohne Angabe bleibt sie leer',
    [$satz['summary'], MailAnalyseCalc::Satz('m:1', [], 'B', 'u', [], [], 1)['summary']], ['Worum es geht', '']);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
