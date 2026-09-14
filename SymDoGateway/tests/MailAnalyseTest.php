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

require_once __DIR__ . '/../libs/MailAnalyseCalc.php';

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

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
