<?php

declare(strict_types=1);

/**
 * Die Warteschlange der KI-Auftraege und der Laeufer, der sie abarbeitet.
 *
 * Beides ist Politik, und Politik faellt im Betrieb nicht auf: ein Auftrag,
 * der nach einem Absturz als „laeuft" liegen bleibt, sieht aus wie einer, der
 * gerade arbeitet — nur kommt nie ein Ergebnis. Ein Foto, das nach dem Senden
 * nicht geloescht wird, faellt erst auf, wenn der Prozess an seiner
 * Speichergrenze stirbt. Und ein Laeufer, der bei „Anbieter belegt" sofort den
 * naechsten nimmt, dreht sich im Kreis, bis die Schlange leer gebremst ist.
 *
 * Deshalb steht das hier, wo es ohne Symcon, ohne Netz und in einer Sekunde
 * pruefbar ist.
 *
 *   php SymDoGateway/tests/AiJobTest.php
 */

require_once __DIR__ . '/../../libs/AiJobStore.php';
require_once __DIR__ . '/../../libs/AiJobRunner.php';

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
    printf("%-4s %-62s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

$wurzel = rtrim(sys_get_temp_dir(), '/\\') . '/symdo_aijobs_pruefstand';
exec('rm -rf ' . escapeshellarg($wurzel));
register_shutdown_function(static function () use ($wurzel): void {
    exec('rm -rf ' . escapeshellarg($wurzel));
});

$laden = AiJobStore::in($wurzel);
pruefe('Der Laden ist nutzbar', $laden->nutzbar(), true);

/** Ein Auftragskopf, wie ihn das Gateway baut. */
function kopf(string $id, int $erstellt, array $mehr = []): array
{
    return array_replace([
        'id'        => $id,
        'kind'      => 'extract',
        'state'     => AiJobStore::OFFEN,
        'createdAt' => $erstellt,
        'startedAt' => 0,
        'finishedAt'=> 0,
        'notBefore' => 0,
        'attempts'  => 0,
        'device'    => 'geraet-1',
        'origin'    => ['type' => 'rest'],
        'job'       => ['system' => 'S', 'user' => 'U', 'payloadKind' => '', 'mime' => '', 'url' => ''],
        'parse'     => ['type' => 'todos', 'arten' => ['task']],
    ], $mehr);
}

// ── Anlegen, lesen, Nutzlast getrennt ─────────────────────────────────────
$id1 = AiJobStore::neueKennung();
pruefe('Eine Kennung ist 24 Hexziffern und sonst nichts',
    [strlen($id1), AiJobStore::kennungGueltig($id1),
     AiJobStore::kennungGueltig('../../etc/passwd'), AiJobStore::kennungGueltig('ZZZZ')],
    [24, true, false, false]);

pruefe('Auftrag einreihen', $laden->anlegen(kopf($id1, 1000), 'BILDDATEN'), $id1);
pruefe('Kopf kommt zurueck', $laden->lesen($id1)['kind'], 'extract');
pruefe('Nutzlast liegt getrennt und ist lesbar', $laden->nutzlastLesen($id1), 'BILDDATEN');
/* Getrennt heisst auch: der Kopf traegt sie NICHT. Sonst laese jeder Blick in
   die Warteschlange zwoelf Megabyte mit. */
pruefe('Und steht nicht im Kopf',
    str_contains((string)file_get_contents($laden->verzeichnis() . $id1 . '.json'), 'BILDDATEN'), false);
pruefe('Eine erfundene Kennung liefert nichts',
    [$laden->lesen('ffffffffffffffffffffffff'), $laden->lesen('unfug')], [null, null]);

// ── Reihenfolge: der aelteste zuerst ──────────────────────────────────────
$id2 = AiJobStore::neueKennung();
$id3 = AiJobStore::neueKennung();
$laden->anlegen(kopf($id2, 900));            // aelter als id1
$laden->anlegen(kopf($id3, 1100));
pruefe('Drei warten', $laden->zaehleWartende(), 3);
pruefe('Der zweite steht vorn, der dritte hinten',
    [$laden->position($id2), $laden->position($id1), $laden->position($id3)], [1, 2, 3]);

$genommen = $laden->naechsten(2000);
pruefe('Genommen wird der aelteste', $genommen['id'], $id2);
pruefe('Und er steht danach als laufend auf der Platte, mit gezaehltem Versuch',
    [$laden->lesen($id2)['state'], $laden->lesen($id2)['attempts'], $laden->lesen($id2)['startedAt']],
    [AiJobStore::LAEUFT, 1, 2000]);
pruefe('Ein laufender ist die Nummer eins', $laden->position($id2), 1);
pruefe('Der naechste ist dann der zweitaelteste', $laden->naechsten(2000)['id'], $id1);

// ── Sperrfrist ────────────────────────────────────────────────────────────
$laden->zurueckstellen($laden->lesen($id1), 3000);
pruefe('Zurueckgestellt heisst wieder offen, aber nicht sofort',
    [$laden->lesen($id1)['state'], $laden->lesen($id1)['notBefore'], $laden->lesen($id1)['startedAt']],
    [AiJobStore::OFFEN, 3000, 0]);
pruefe('Vor der Frist wird er nicht genommen', $laden->naechsten(2500)['id'], $id3);
$laden->zurueckstellen($laden->lesen($id3), 0);
pruefe('Nach der Frist schon', $laden->naechsten(3000)['id'], $id1);

// ── Deckel je Absender ────────────────────────────────────────────────────
$fremd = AiJobStore::neueKennung();
$laden->anlegen(kopf($fremd, 1200, ['device' => 'geraet-2']));
pruefe('Gezaehlt wird auch je Absender — daran haengt der Deckel',
    [$laden->zaehleWartende('rest:geraet-1'), $laden->zaehleWartende('rest:geraet-2')], [3, 1]);
$kachel = AiJobStore::neueKennung();
$laden->anlegen(kopf($kachel, 1300, ['origin' => ['type' => 'tile', 'sdwa' => '4711', 'txn' => 'a']]));
pruefe('Eine Kachel ist ein eigener Absender', $laden->zaehleWartende('tile:4711'), 1);

// ── Aufraeumen ────────────────────────────────────────────────────────────
$laden->alleLoeschen();
pruefe('Alles weg', [$laden->zaehleWartende(), count($laden->koepfe())], [0, 0]);

/* Vier Arten von Leiche, und fuer jede ein eigener Umgang. */
$fertig  = AiJobStore::neueKennung();
$alt     = AiJobStore::neueKennung();
$haengt  = AiJobStore::neueKennung();
$haengt2 = AiJobStore::neueKennung();
$laden->anlegen(kopf($fertig, 100, ['state' => AiJobStore::FERTIG, 'finishedAt' => 100]));
$laden->anlegen(kopf($alt, 100));
$laden->anlegen(kopf($haengt, 100, ['state' => AiJobStore::LAEUFT, 'startedAt' => 100, 'attempts' => 1]), 'X');
$laden->anlegen(kopf($haengt2, 100, ['state' => AiJobStore::LAEUFT, 'startedAt' => 100, 'attempts' => 2]), 'X');
file_put_contents($laden->verzeichnis() . 'aaaaaaaaaaaaaaaaaaaaaaaa.payload', 'verwaist');

$angefasst = $laden->aufraeumen(10000, 600, 900, 400);
pruefe('Aufgeraeumt wurden fuenf Dinge', $angefasst, 5);
pruefe('Der abgeholte Fertige ist weg', $laden->lesen($fertig), null);
pruefe('Der zu lange Wartende gilt als gescheitert, nicht als offen',
    [$laden->lesen($alt)['state'], $laden->lesen($alt)['raw']['code']],
    [AiJobStore::GESCHEITERT, 'ai_timeout']);
/* Ein Lauf, der nie zurueckkam, bekommt EINE zweite Chance — der Nutzer hat
   sein Foto geschickt und soll ein Ergebnis sehen. Beim dritten Mal ist
   Schluss, sonst brächte ein Auftrag, der den Laeufer umbringt, ihn immer
   wieder um. */
pruefe('Ein haengender Lauf darf noch einmal', $laden->lesen($haengt)['state'], AiJobStore::OFFEN);
pruefe('Beim zweiten Mal ist Schluss', $laden->lesen($haengt2)['state'], AiJobStore::GESCHEITERT);
pruefe('Und seine Nutzlast ist weg', $laden->nutzlastLesen($haengt2), '');
pruefe('Eine Nutzlast ohne Kopf wird weggeraeumt',
    @is_file($laden->verzeichnis() . 'aaaaaaaaaaaaaaaaaaaaaaaa.payload'), false);

// ══════════════════════════════════════════════════════════════════════════
// Der Laeufer
// ══════════════════════════════════════════════════════════════════════════
$laden->alleLoeschen();

/** Eine Anbieter-Attrappe: sie merkt sich den Aufruf und antwortet nach Plan. */
final class AnbieterAttrappe
{
    public static array $rufe = [];
    public static array $antwort = ['ok' => true, 'text' => 'ERGEBNIS'];
    public static array $notizen = ['etwas gemerkt'];

    public static function bauen(): AiProvider
    {
        return AiProvider::ausKonfiguration(
            ['AiProvider' => 'openai', 'AiOpenAIKey' => 'k'],
            static function (string $url, array $kopf, string $rumpf, int $frist, int $verbinden): array {
                self::$rufe[] = ['url' => $url, 'rumpf' => $rumpf, 'frist' => $frist];
                return ['status' => 200, 'body' => (string)json_encode(
                    ['choices' => [['message' => ['content' => 'ERGEBNIS'], 'finish_reason' => 'stop']]]), 'err' => ''];
            });
    }
}

$gemeldet = [];
$sperreFrei = true;
$sperrenGeholt = 0;
$sperrenGegeben = 0;
$uhrzeit = 5000;

$bauen = static fn(): AiProvider => AnbieterAttrappe::bauen();
$holen = static function (int $ms) use (&$sperreFrei, &$sperrenGeholt): bool {
    $sperrenGeholt++;
    return $sperreFrei;
};
$geben = static function () use (&$sperrenGegeben): void { $sperrenGegeben++; };
$melden = static function (string $id) use (&$gemeldet): void { $gemeldet[] = $id; };
$uhr = static function () use (&$uhrzeit): int { return $uhrzeit; };

$laeufer = new AiJobRunner($laden, $bauen, $holen, $geben, $melden, $uhr);

// ── Der gewoehnliche Weg ──────────────────────────────────────────────────
AnbieterAttrappe::$rufe = [];
$a = AiJobStore::neueKennung();
$laden->anlegen(kopf($a, 4000, ['job' => ['system' => 'SYS', 'user' => 'NUTZ',
    'payloadKind' => 'image', 'mime' => '', 'url' => '']]), 'FOTOBYTES');
pruefe('Ein Durchgang arbeitet den Auftrag ab', $laeufer->abarbeiten(), 1);
pruefe('Der Anbieter wurde genau einmal gerufen', count(AnbieterAttrappe::$rufe), 1);
$gesendet = json_decode(AnbieterAttrappe::$rufe[0]['rumpf'], true);
pruefe('Mit dem Prompt aus dem Auftrag und dem Bild als Block',
    [$gesendet['messages'][0]['content'], $gesendet['messages'][1]['content'][1]['text'],
     str_contains((string)$gesendet['messages'][1]['content'][0]['image_url']['url'], 'FOTOBYTES')],
    ['SYS', 'NUTZ', true]);
pruefe('Danach ist der Auftrag roh und traegt die Antwort',
    [$laden->lesen($a)['state'], $laden->lesen($a)['raw']['ok'], $laden->lesen($a)['raw']['text']],
    [AiJobStore::ROH, true, 'ERGEBNIS']);
/* Die wichtigste Zeile dieses Pruefstands: das Foto ist weg, sobald es
   gesendet wurde. */
pruefe('Und die Nutzlast ist weg, sobald sie gesendet ist', $laden->nutzlastLesen($a), '');
pruefe('Das Ergebnis wurde gemeldet — und zwar NACH dem Schreiben', $gemeldet, [$a]);
pruefe('Die Sperre wurde geholt und wieder gegeben', [$sperrenGeholt, $sperrenGegeben], [1, 1]);

// ── Anbieter belegt ───────────────────────────────────────────────────────
$laden->alleLoeschen();
$gemeldet = [];
$sperreFrei = false;
$b = AiJobStore::neueKennung();
$c = AiJobStore::neueKennung();
$laden->anlegen(kopf($b, 4000));
$laden->anlegen(kopf($c, 4001));
pruefe('Bei belegter Sperre wird nichts fertig', $laeufer->abarbeiten(), 0);
pruefe('Der Auftrag wartet wieder, mit Frist und gemerktem Grund',
    [$laden->lesen($b)['state'], $laden->lesen($b)['notBefore'] - $uhrzeit, $laden->lesen($b)['lastCode']],
    [AiJobStore::OFFEN, AiJobRunner::BREMSE_S, 'ai_busy']);
/* Und der Laeufer hat sich NICHT durch die restliche Schlange gebremst: der
   zweite ist unberuehrt. */
pruefe('Der naechste wurde gar nicht erst angefasst',
    [$laden->lesen($c)['state'], $laden->lesen($c)['attempts']], [AiJobStore::OFFEN, 0]);

// ── Der Dienst antwortet mit einem Fehler ─────────────────────────────────
$laden->alleLoeschen();
$sperreFrei = true;
$gemeldet = [];
$fehlerhaft = new AiJobRunner($laden,
    static fn(): AiProvider => AiProvider::ausKonfiguration(['AiProvider' => 'openai', 'AiOpenAIKey' => 'k'],
        static fn(...$x): array => ['status' => 429, 'body' => '', 'err' => '']),
    $holen, $geben, $melden, $uhr);
$d = AiJobStore::neueKennung();
$laden->anlegen(kopf($d, 4000));
pruefe('Ein Rate-Limit vertagt statt aufzugeben',
    [$fehlerhaft->abarbeiten(), $laden->lesen($d)['state'], $laden->lesen($d)['attempts']],
    [0, AiJobStore::OFFEN, 1]);
/* Und es wird auch wirklich gewartet: solange die Frist laeuft, ruehrt der
   Laeufer den Auftrag nicht an. Ohne das drehte er sich im Kreis. */
pruefe('Vor Ablauf der Frist passiert gar nichts',
    [$fehlerhaft->abarbeiten(10), $laden->lesen($d)['attempts']], [0, 1]);

$uhrzeit = 9000;
pruefe('Nach der Frist der zweite Versuch',
    [$fehlerhaft->abarbeiten(), $laden->lesen($d)['attempts']], [0, 2]);
$uhrzeit = 13000;
$fehlerhaft->abarbeiten();
pruefe('Beim dritten Versuch ist Schluss, und der Grund steht im Ergebnis',
    [$laden->lesen($d)['state'], $laden->lesen($d)['attempts'], $laden->lesen($d)['raw']['code']],
    [AiJobStore::ROH, AiJobRunner::MAX_VERSUCHE, 'ai_rate_limited']);
pruefe('Auch ein Fehlschlag wird gemeldet — der Nutzer wartet ja', $gemeldet, [$d]);

// ── Ein Wurf darf nichts liegen lassen ────────────────────────────────────
$laden->alleLoeschen();
$gemeldet = [];
$sperrenGegeben = 0;
$wirft = new AiJobRunner($laden,
    static function (): AiProvider { throw new RuntimeException('etwas ging kaputt'); },
    $holen, $geben, $melden, $uhr);
$e = AiJobStore::neueKennung();
$laden->anlegen(kopf($e, 4000), 'X');
$wirft->abarbeiten();
pruefe('Nach einem Wurf bleibt kein Auftrag als „laeuft" liegen',
    [$laden->lesen($e)['state'], $laden->lesen($e)['raw']['code']], [AiJobStore::ROH, 'internal']);
pruefe('Der Grund steht dabei', $laden->lesen($e)['raw']['detail'], 'etwas ging kaputt');
pruefe('Die Sperre wurde trotzdem zurueckgegeben', $sperrenGegeben, 1);
pruefe('Und gemeldet wurde auch', $gemeldet, [$e]);

// ── Zurueckgezogene Einwilligung ──────────────────────────────────────────
$laden->alleLoeschen();
AnbieterAttrappe::$rufe = [];
$f = AiJobStore::neueKennung();
$laden->anlegen(kopf($f, 4000));
/* Der Laeufer nimmt ihn, und zwischen Nehmen und Anrufen loescht das Gateway
   die Schlange — genau das passiert beim Widerruf. Dann darf der Anruf NICHT
   mehr stattfinden. */
$genommen = $laden->naechsten($uhrzeit);
$laden->alleLoeschen();
pruefe('Ein geloeschter Auftrag wird nicht mehr angerufen',
    [$laeufer->einen($genommen), count(AnbieterAttrappe::$rufe)], [true, 0]);

// ── Diktat und Rezept-Adresse ─────────────────────────────────────────────
$laden->alleLoeschen();
AnbieterAttrappe::$rufe = [];
$g = AiJobStore::neueKennung();
$laden->anlegen(kopf($g, 4000, ['kind' => 'transcribe',
    'job' => ['system' => '', 'user' => '', 'payloadKind' => 'audio', 'mime' => 'audio/mp4', 'url' => '']]), 'TONBYTES');
$laeufer->abarbeiten();
pruefe('Diktat geht an den Transkriptionsweg, mit Ton und Dateiendung',
    [str_contains(AnbieterAttrappe::$rufe[0]['url'], '/audio/transcriptions'),
     str_contains(AnbieterAttrappe::$rufe[0]['rumpf'], 'TONBYTES'),
     str_contains(AnbieterAttrappe::$rufe[0]['rumpf'], 'diktat.m4a')],
    [true, true, true]);
pruefe('Auch hier ist der Ton danach weg', $laden->nutzlastLesen($g), '');

$laden->alleLoeschen();
$h = AiJobStore::neueKennung();
$laden->anlegen(kopf($h, 4000, ['kind' => 'ingredients',
    'job' => ['system' => 'S', 'user' => 'Rezept:', 'payloadKind' => '', 'mime' => '',
              'url' => 'http://192.168.1.1/geheim']]));
$laeufer->abarbeiten();
/* Der Riegel gegen das eigene Netz gilt auch hier — er wandert mit dem
   Auftrag in die andere Instanz und darf dabei nicht verlorengehen. */
pruefe('Eine Adresse ins eigene Netz wird auch im Laeufer abgewiesen',
    $laden->lesen($h)['raw']['code'], 'invalid_url');

// ── Ein Widerruf mitten im Aufruf bleibt ein Widerruf ─────────────────────
/* Der gefaehrlichste Fall der ganzen Warteschlange: der Nutzer zieht seine
   Einwilligung zurueck, WAEHREND der Anbieter antwortet. Schriebe der Laeufer
   den Kopf danach blind zurueck, legte er den geloeschten Auftrag wieder an —
   samt der Antwort, die aus dem Foto gewonnen wurde. */
$laden->alleLoeschen();
$gemeldet = [];
$widerruf = new AiJobRunner($laden,
    static function () use ($laden): AiProvider {
        return AiProvider::ausKonfiguration(['AiProvider' => 'openai', 'AiOpenAIKey' => 'k'],
            static function (...$x) use ($laden): array {
                // Mitten im Aufruf: der Nutzer widerruft.
                $laden->alleLoeschen();
                return ['status' => 200, 'body' => (string)json_encode(
                    ['choices' => [['message' => ['content' => 'GEHEIM'], 'finish_reason' => 'stop']]]), 'err' => ''];
            });
    },
    $holen, $geben, $melden, $uhr);
$w = AiJobStore::neueKennung();
$laden->anlegen(kopf($w, 4000), 'FOTO');
$widerruf->abarbeiten();
pruefe('Ein waehrend des Aufrufs widerrufener Auftrag kommt NICHT zurueck',
    [$laden->lesen($w), count($laden->koepfe())], [null, 0]);
pruefe('Und er wird auch nicht gemeldet — sonst deutete das Gateway ihn', $gemeldet, []);

/* Dasselbe beim Vertagen: auch ein zurueckgestellter Auftrag darf nicht
   wiederauferstehen. */
$laden->alleLoeschen();
$v = AiJobStore::neueKennung();
$laden->anlegen(kopf($v, 4000));
$genommen = $laden->naechsten($uhrzeit);
$laden->alleLoeschen();
pruefe('Zurueckstellen legt einen geloeschten Auftrag nicht neu an',
    [$laden->zurueckstellen($genommen, $uhrzeit + 30), count($laden->koepfe())], [false, 0]);

// ── Wer wartet, kommt zuerst ──────────────────────────────────────────────
/* Der eigene Annahme-Topf verhindert nur, dass Hintergrundarbeit jemanden mit
   „belegt" abweist. Der Laeufer arbeitet aber unter der Anbieter-Sperre einen
   nach dem anderen ab: reiht der Klassenseiten-Lauf um 6:00 fuenf Karten ein
   und fotografiert um 6:01 jemand einen Elternbrief, stuende sein Auftrag an
   sechster Stelle — bei einem lokalen Server bis zu fuenfundzwanzig Minuten,
   waehrend die App nach zehn aufgibt. */
$v = AiJobStore::in(sys_get_temp_dir() . '/symdo-vorrang-' . getmypid() . '/', true);
$v->alleLoeschen();
$hinten = static fn(int $at, array $origin): array => [
    'id' => AiJobStore::neueKennung(), 'kind' => 'extract', 'state' => AiJobStore::OFFEN,
    'createdAt' => $at, 'startedAt' => 0, 'finishedAt' => 0, 'notBefore' => 0,
    'attempts' => 0, 'device' => 'geraet-a', 'origin' => $origin, 'job' => [], 'parse' => [],
];
for ($i = 0; $i < 3; $i++) {
    $v->anlegen($hinten(1000 + $i, ['type' => AiJobStore::HERKUNFT_HINTERGRUND]), '');
}
$v->anlegen($hinten(2000, ['type' => 'rest']), '');   // JUENGER, aber ein Mensch wartet

$erster = $v->naechsten(3000);
pruefe('Der Auftrag eines Menschen geht vor, auch wenn er juenger ist',
    (string)(($erster['origin'] ?? [])['type'] ?? ''), 'rest');
$zweiter = $v->naechsten(3000);
pruefe('Danach kommt die aelteste Hintergrundarbeit',
    [(string)(($zweiter['origin'] ?? [])['type'] ?? ''), (int)$zweiter['createdAt']],
    [AiJobStore::HERKUNFT_HINTERGRUND, 1000]);

// ── Eine bezahlte Antwort wirft das Aufraeumen nicht weg ──────────────────
/* ROH heisst: der Anbieter hat geantwortet, die Antwort steht in `raw` und ist
   bezahlt. Wer sie nach der Schlangenfrist mit `ai_timeout` ueberschriebe,
   wuerfe Geld weg — und die Karte steht schon als gesehen im Merker. */
$v->alleLoeschen();
$rohKopf = $hinten(time() - 5000, ['type' => AiJobStore::HERKUNFT_HINTERGRUND]);
$rohKopf['state'] = AiJobStore::ROH;
$rohKopf['raw'] = ['ok' => true, 'text' => '[{"kind":"task"}]'];
$v->anlegen($rohKopf, '');
$v->aufraeumen(time(), 600, 900, 400);
$danach = $v->lesen((string)$rohKopf['id']);
pruefe('Ein ROH-Auftrag ueberlebt das Aufraeumen',
    [(string)($danach['state'] ?? ''), (bool)($danach['raw']['ok'] ?? false)],
    [AiJobStore::ROH, true]);
/* Ein OFFENER Auftrag derselben Frist verfaellt dagegen weiterhin — sonst
   liefe eine Schlange nie leer. */
$v->alleLoeschen();
$offenKopf = $hinten(time() - 5000, ['type' => AiJobStore::HERKUNFT_HINTERGRUND]);
$v->anlegen($offenKopf, '');
$v->aufraeumen(time(), 600, 900, 400);
pruefe('Ein offener Auftrag verfaellt weiterhin',
    (string)($v->lesen((string)$offenKopf['id'])['state'] ?? ''), AiJobStore::GESCHEITERT);
$v->alleLoeschen();

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
