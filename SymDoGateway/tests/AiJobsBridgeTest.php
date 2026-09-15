<?php

declare(strict_types=1);

/**
 * Die Gateway-Seite der KI-Auftraege: einreihen, 202 antworten, abholen.
 *
 * Woran hier alles haengt: **die fertige Antwort muss genau so aussehen wie
 * die synchrone.** Die App kennt nur EINE Form. Steht im einen Weg `todos` und
 * im anderen `items`, sieht der Nutzer „darin war nichts zu finden" — und
 * niemand merkt, woran es lag, denn es stuerzt nichts ab und es steht nichts
 * im Protokoll.
 *
 * Geprueft wird gegen Doubles: die Deutung (`AiParseTodos`) und die Uebersetzung
 * sind woanders zu Hause und haben eigene Pruefstaende. Hier geht es um die
 * FORM und um die Zustaende dazwischen.
 *
 *   php SymDoGateway/tests/AiJobsBridgeTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../../libs/Konfig.php';
require_once __DIR__ . '/../../libs/AiProvider.php';
require_once __DIR__ . '/../libs/AiJobs.php';

IPS\Kernel::reset();
date_default_timezone_set('Europe/Berlin');

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

/**
 * Das Gateway, auf die Auftragsverwaltung zusammengestrichen. Alles, was
 * `AiJobs` sonst aus seiner Klasse ruft, steht hier als Double — so laesst
 * sich sehen, WAS gerufen wurde, ohne halb Symcon zu starten.
 */
final class JobProbe extends IPSModuleStrict
{
    use Konfig;
    use AiJobs;

    // Die beiden Konstanten leben sonst in AppCore.
    private const WEBHOOK_CONTROL_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const WS_HOOK_PATH         = 'lists/ws';

    /** @var array{status:int,body:array<string,mixed>}|null */
    public ?array $gesendet = null;
    public int $gezaehlt = 0;
    /** @var list<string> */
    public array $geklingelt = [];
    public bool $uebernommen = true;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterAttributeInteger('AiJobSweepMs', -1);
        $this->AiJobCreate();
    }

    // ── Doubles ───────────────────────────────────────────────────────────
    private function ScanQuelleUebernommen(string $quelle): bool
    {
        return $this->uebernommen;
    }

    private function ScanAuftragGeben(string $quelle, array $auftrag = []): bool
    {
        $this->geklingelt[] = $quelle;
        return true;
    }

    private function MailCountDay(): void
    {
        $this->gezaehlt++;
    }

    private function AiParseTodos(string $text, array $arten = ['task', 'event']): array
    {
        return [['title' => $text, 'arten' => $arten]];
    }

    private function AiParseRecipe(string $text): array
    {
        return ['title' => $text, 'servings' => 4, 'items' => [['name' => 'Mehl']]];
    }

    private function AiErrorMessage(string $code, string $grund = '', string $detail = ''): array
    {
        return ['ok' => false, 'code' => $code,
                'message' => trim($code . ' ' . $grund . ' ' . $detail),
                'status' => $code === 'ai_not_configured' ? 400 : 502];
    }

    private function AiAnbieter(): AiProvider
    {
        return AiProvider::ausKonfiguration(['AiProvider' => 'openai', 'AiOpenAIKey' => 'k']);
    }

    private function SendJson(array $payload, int $status = 200, string $cacheControl = 'no-store'): void
    {
        $this->gesendet = ['status' => $status, 'body' => $payload];
    }

    private function SendApiError(string $code, string $message, int $status): void
    {
        $this->gesendet = ['status' => $status,
            'body' => ['ok' => false, 'error' => ['code' => $code, 'message' => $message]]];
    }

    /* Die Gegenstelle der Hintergrundarbeit. Im echten Gateway pflegt sie den
       Vorschlag ein (MailScan); hier zaehlt sie nur mit, WAS ankommt. */
    public array $hintergrund = [];
    private function MailAuftragEinpflegen(array $kopf): void
    {
        $this->hintergrund[] = (string)$kopf['id'];
    }

    // ── Tueren fuer den Pruefstand ────────────────────────────────────────
    public function pEinreihen(string $kind, array $job, array $parse, string $nutzlast, string $geraet): array
    {
        return $this->AiJobEnqueue($kind, $job, $parse, ['type' => 'rest'], $nutzlast, $geraet);
    }
    /** Ein Auftrag, den kein Mensch angestossen hat. */
    public function pHintergrund(string $quelle = 'Edumaps'): array
    {
        return $this->AiJobEnqueue('extract',
            ['system' => 's', 'user' => 'u'],
            ['type' => 'todos', 'arten' => ['task']],
            ['type' => AiJobStore::HERKUNFT_HINTERGRUND, 'quelle' => $quelle,
             'vorschlag' => 'edu:1:2', 'kopf' => [], 'userId' => 'u1']);
    }
    public function pAccept(string $id): void { $this->AiJobAccept($id); }
    public function pStatus(array $device, string $id): void { $this->HandleAiJobStatus($device, $id); }
    public function pFertig(string $id): void { $this->AiJobFinish($id); }
    public function pSweep(): void { $this->AiJobSweep(); }
    public function pLaden(): AiJobStore { return $this->AiJobLaden(); }
    public function pMoeglich(): bool { return $this->AiJobMoeglich(); }
    public function pTimer(): int { return (int)$this->GetTimerInterval('AiJobSweep'); }
    public function pUebernehmen(): void { $this->AiJobApplyChanges(); }
    public function pTimerAus(): void { $this->SetTimerInterval('AiJobSweep', 0); }
    public function pFertigmelden(string $id): void { $this->AiJobFinish($id); }

    protected function getTime(): int { return time(); }
}

$probe = new JobProbe(16011);
$probe->Create();
$laden = $probe->pLaden();
$laden->alleLoeschen();
register_shutdown_function(static function () use ($laden): void {
    $laden->alleLoeschen();
    @rmdir($laden->verzeichnis());
});

$job   = ['system' => 'SYS', 'user' => 'NUTZ', 'payloadKind' => 'image', 'mime' => '', 'url' => ''];
$parse = ['type' => 'todos', 'arten' => ['task', 'event']];

// ── Einreihen ─────────────────────────────────────────────────────────────
$r = $probe->pEinreihen('extract', $job, $parse, 'FOTO', 'geraet-1');
pruefe('Einreihen liefert eine Kennung',
    [$r['ok'], AiJobStore::kennungGueltig((string)$r['id'])], [true, true]);
$id = (string)$r['id'];
pruefe('Der Auftrag steht offen in der Schlange, mit Prompt und Nutzlast',
    [$laden->lesen($id)['state'], $laden->lesen($id)['job']['system'], $laden->nutzlastLesen($id)],
    [AiJobStore::OFFEN, 'SYS', 'FOTO']);
/* Datei UND Klingel — eines allein liefe ins Leere: eine Auftragsdatei ohne
   Weckruf laege bis zum naechsten Takt des Scanners. */
pruefe('Und der Laeufer wurde geweckt', $probe->geklingelt, ['auftrag']);
pruefe('Der Aufraeum-Zeitgeber laeuft jetzt', $probe->pTimer() > 0, true);

// ── Die 202-Antwort ───────────────────────────────────────────────────────
$probe->pAccept($id);
$a = $probe->gesendet;
pruefe('Die Annahme ist ein 202 mit allem, was die App zum Warten braucht',
    [$a['status'], $a['body']['ok'], $a['body']['queued'], $a['body']['id'],
     $a['body']['state'], $a['body']['position'], $a['body']['pollAfter'], $a['body']['maxWait'] > 0],
    [202, true, true, $id, AiJobStore::OFFEN, 1, 2, true]);

// ── Nachfragen, solange er wartet ─────────────────────────────────────────
$probe->pStatus(['id' => 'geraet-1'], $id);
pruefe('Die Nachfrage antwortet weiter mit 202', $probe->gesendet['status'], 202);

/* Gerätegebunden: die Kennung ist nicht zu raten, aber sie reist durch
   Protokolle und Adresszeilen. Ein fremdes Geraet bekommt sie nicht. */
$probe->pStatus(['id' => 'geraet-2'], $id);
pruefe('Ein fremdes Geraet bekommt nichts',
    [$probe->gesendet['status'], $probe->gesendet['body']['error']['code']], [404, 'job_not_found']);
$probe->pStatus(['id' => 'geraet-1'], 'ffffffffffffffffffffffff');
pruefe('Eine unbekannte Kennung ebenso', $probe->gesendet['status'], 404);

// ── Die fertige Antwort: dieselbe Form wie der synchrone Weg ──────────────
$kopf = $laden->lesen($id);
$kopf['state'] = AiJobStore::ROH;
$kopf['raw']   = ['ok' => true, 'text' => 'ANTWORT', 'debug' => ['eine Notiz']];
$laden->schreiben($kopf);

$probe->pStatus(['id' => 'geraet-1'], $id);
pruefe('Foto zu Aufgaben: {ok, todos} — und die Kennung dabei',
    $probe->gesendet,
    ['status' => 200, 'body' => ['ok' => true,
        'todos' => [['title' => 'ANTWORT', 'arten' => ['task', 'event']]], 'id' => $id]]);
pruefe('Gezaehlt wird beim Deuten, und nur einmal', $probe->gezaehlt, 1);
pruefe('Danach ist er fertig und die Rohantwort ist weg',
    [$laden->lesen($id)['state'], isset($laden->lesen($id)['raw'])], [AiJobStore::FERTIG, false]);

// Noch einmal abholen darf nicht noch einmal zaehlen.
$probe->pStatus(['id' => 'geraet-1'], $id);
pruefe('Zweimal abholen zaehlt nicht zweimal',
    [$probe->gezaehlt, $probe->gesendet['status']], [1, 200]);

// ── Dieselbe Probe fuer die beiden anderen Arten ──────────────────────────
foreach ([
    ['ingredients', ['type' => 'recipe', 'arten' => []],
     ['ok' => true, 'title' => 'ANTWORT', 'servings' => 4, 'items' => [['name' => 'Mehl']]]],
    ['transcribe', ['type' => 'text', 'arten' => []],
     ['ok' => true, 'text' => 'ANTWORT']],
] as [$kind, $p, $erwartet]) {
    $laden->alleLoeschen();
    $neu = (string)$probe->pEinreihen($kind, $job, $p, 'X', 'geraet-1')['id'];
    $k = $laden->lesen($neu);
    $k['state'] = AiJobStore::ROH;
    $k['raw']   = ['ok' => true, 'text' => 'ANTWORT'];
    $laden->schreiben($k);
    $probe->pStatus(['id' => 'geraet-1'], $neu);
    pruefe($kind . ': dieselbe Form wie auf dem synchronen Weg',
        $probe->gesendet, ['status' => 200, 'body' => $erwartet + ['id' => $neu]]);
}

// ── Ein Fehlschlag ────────────────────────────────────────────────────────
$laden->alleLoeschen();
$probe->gezaehlt = 0;
$schlecht = (string)$probe->pEinreihen('extract', $job, $parse, 'X', 'geraet-1')['id'];
$k = $laden->lesen($schlecht);
$k['state'] = AiJobStore::ROH;
$k['raw']   = ['ok' => false, 'code' => 'ai_no_credit', 'grund' => '', 'detail' => ''];
$laden->schreiben($k);
$probe->pStatus(['id' => 'geraet-1'], $schlecht);
pruefe('Ein Fehlschlag kommt als Fehler, mit Code und Status',
    [$probe->gesendet['status'], $probe->gesendet['body']['error']['code']], [502, 'ai_no_credit']);
/* Und er kostet KEIN Tagesbudget — genau wie auf dem synchronen Weg. */
pruefe('Ein Fehlschlag zaehlt nicht', $probe->gezaehlt, 0);

// ── Die Deckel ────────────────────────────────────────────────────────────
$laden->alleLoeschen();
$probe->pEinreihen('extract', $job, $parse, '', 'geraet-1');
$probe->pEinreihen('extract', $job, $parse, '', 'geraet-1');
$dritter = $probe->pEinreihen('extract', $job, $parse, '', 'geraet-1');
pruefe('Ein Geraet darf die Schlange nicht fuellen',
    [$dritter['ok'], $dritter['code'], $dritter['status']], [false, 'ai_busy', 429]);
/* Ein anderes Geraet kommt trotzdem dran — der Deckel je Absender ist genau
   dafuer da. */
pruefe('Ein anderes Geraet kommt weiterhin dran',
    $probe->pEinreihen('extract', $job, $parse, '', 'geraet-2')['ok'], true);

// ── Ohne Laeufer kein zweiter Weg ─────────────────────────────────────────
pruefe('Mit Scanner ist der zweite Weg moeglich', $probe->pMoeglich(), true);
$probe->uebernommen = false;
pruefe('Ohne Scanner nicht — dann bleibt alles synchron', $probe->pMoeglich(), false);
$probe->uebernommen = true;

// ── Der Aufraeum-Zeitgeber stellt sich selbst ab ──────────────────────────
$laden->alleLoeschen();
$probe->pSweep();
pruefe('Ist die Schlange leer, stellt der Zeitgeber sich ab', $probe->pTimer(), 0);
$probe->geklingelt = [];
$probe->pEinreihen('extract', $job, $parse, '', 'geraet-1');
$probe->geklingelt = [];
$probe->pSweep();
/* Das Netz: klingelt noch einmal, falls der erste Weckruf ins Leere lief —
   der Scanner war beim Klingeln noch nicht bereit, der Kernel startete gerade. */
pruefe('Wartet etwas, klingelt das Netz erneut', $probe->geklingelt, ['auftrag']);
pruefe('Und der Zeitgeber laeuft weiter', $probe->pTimer() > 0, true);

// ── Nach einem Kernelstart muss der Zeitgeber wieder scharf werden ────────
/* Die Falle, die dieses Haus schon zweimal gestellt hat: der Zeitgeber
   ueberlebt einen Kernelstart NICHT, der gemerkte Wert im Attribut aber
   schon. Wer dann nur „bei Aenderung" setzt, setzt nie wieder — und der
   Auftrag in der Schlange wartet fuer immer, die Nutzlast bleibt liegen. */
$laden->alleLoeschen();
$probe->pEinreihen('extract', $job, $parse, 'FOTO', 'geraet-1');
$probe->pTimerAus();                 // so sieht es nach einem Kernelstart aus
pruefe('Nach dem Neustart steht der Zeigber erst einmal auf null', $probe->pTimer(), 0);
$probe->pUebernehmen();
pruefe('Das Uebernehmen macht ihn wieder scharf', $probe->pTimer() > 0, true);

// ── Ein fertiger Auftrag muss noch verfallen koennen ──────────────────────
/* Schaltete der Zeitgeber sich ab, sobald nichts mehr WARTET, bliebe ein
   fertiger Kopf fuer immer liegen: verfallen kann er nur, solange jemand
   aufraeumt. */
$laden->alleLoeschen();
$fertigId = (string)$probe->pEinreihen('extract', $job, $parse, '', 'geraet-1')['id'];
$k = $laden->lesen($fertigId);
$k['state'] = AiJobStore::ROH;
$k['raw']   = ['ok' => true, 'text' => 'X'];
$laden->schreiben($k);
$probe->pFertigmelden($fertigId);
pruefe('Der Auftrag ist fertig und wartet nicht mehr',
    [$laden->lesen($fertigId)['state'], $laden->hatWartende()], [AiJobStore::FERTIG, false]);
$probe->pSweep();
pruefe('Trotzdem laeuft der Zeitgeber weiter — der Kopf muss noch verfallen',
    $probe->pTimer() > 0, true);
$laden->alleLoeschen();
$probe->pSweep();
pruefe('Erst wenn gar nichts mehr da ist, stellt er sich ab', $probe->pTimer(), 0);

// ── Das Diktat zaehlt kein Tagesbudget ────────────────────────────────────
/* Der synchrone Weg zaehlt es nicht: das Diktat ist die halbe Miete, das
   Zerlegen kommt danach als eigener Aufruf und wird dann gezaehlt. Wer hier
   mitzaehlte, buchte dem Nutzer jedes Diktat doppelt. */
$laden->alleLoeschen();
$probe->gezaehlt = 0;
$diktat = (string)$probe->pEinreihen('transcribe',
    ['system' => '', 'user' => '', 'payloadKind' => 'audio', 'mime' => 'audio/webm', 'url' => ''],
    ['type' => 'text', 'arten' => []], 'TON', 'geraet-1')['id'];
$k = $laden->lesen($diktat);
$k['state'] = AiJobStore::ROH;
$k['raw']   = ['ok' => true, 'text' => 'gesprochen'];
$laden->schreiben($k);
$probe->pFertigmelden($diktat);
pruefe('Ein Diktat kostet kein Tagesbudget — wie auf dem synchronen Weg',
    [$probe->gezaehlt, $laden->lesen($diktat)['result']['body']['text']], [0, 'gesprochen']);

// ── Hintergrundarbeit hat ihren eigenen Topf ──────────────────────────────
/* Ohne den haette sie zwei Wirkungen, die beide falsch waeren: fuenf
   Klassenseiten-Karten je Lauf kaemen am Deckel je Herkunft (zwei) nicht
   vorbei, und sie fuellten die Schlange so weit, dass das Foto, das gerade
   jemand hochlaedt, mit „belegt" abgewiesen wuerde. Was niemand angestossen
   hat, darf niemandem im Weg stehen. */
$g = new JobProbe(9911);
$g->Create();
$g->pUebernehmen();
/* Der Spool liegt auf der Platte und ueberlebt den Prueflauf. Ohne dieses
   Leeren traegt ein Durchgang den Stand des vorigen — und der naechste meldet
   „Schlange voll", ohne dass sich etwas geaendert haette. */
$g->pLaden()->alleLoeschen();

$eingereiht = 0;
for ($i = 0; $i < 8; $i++) {
    if (($g->pHintergrund()['ok'] ?? false) === true) {
        $eingereiht++;
    }
}
pruefe('Acht Hintergrund-Auswertungen kommen durch', $eingereiht, 8);
pruefe('… und sie zaehlen alle auf dieselbe Herkunft',
    $g->pLaden()->zaehleWartende(AiJobStore::HERKUNFT_HINTERGRUND), 8);

/* Der entscheidende Fall: die Schlange ist voll mit Hintergrundarbeit, und
   jemand fotografiert einen Elternbrief. */
$mensch = $g->pEinreihen('extract', ['system' => 's', 'user' => 'u'],
    ['type' => 'todos'], '', 'geraet-a');
pruefe('Ein Mensch kommt trotzdem dran', $mensch['ok'] ?? false, true);

/* Umgekehrt gilt der Deckel je Geraet weiter — ein Telefon mit einem Stapel
   Fotos soll die Schlange nicht fuellen. */
$g->pEinreihen('extract', ['system' => 's', 'user' => 'u'], ['type' => 'todos'], '', 'geraet-a');
$dritter = $g->pEinreihen('extract', ['system' => 's', 'user' => 'u'],
    ['type' => 'todos'], '', 'geraet-a');
pruefe('Der Deckel je Geraet gilt weiter', $dritter['code'] ?? '', 'ai_busy');
/* … und er gilt NICHT fuer die Hintergrundarbeit, sonst waere bei zwei
   Karten Schluss. */
pruefe('Ein neunter Hintergrund-Auftrag wird abgewiesen, nicht der zweite',
    $g->pHintergrund()['code'] ?? '', 'ai_busy');

// ── Ein fertiger Hintergrund-Auftrag geht in den Bestand ──────────────────
/* Niemand wartet auf eine Antwort: der Vorschlag wird eingepflegt, statt eine
   Klingel an die App zu schicken. */
$h = new JobProbe(9912);
$h->Create();
$h->pUebernehmen();
$h->pLaden()->alleLoeschen();
$neu = $h->pHintergrund();
$kopf = $h->pLaden()->lesen((string)$neu['id']);
$kopf['state'] = AiJobStore::ROH;
$kopf['raw'] = ['ok' => true, 'text' => '[]', 'debug' => []];
$h->pLaden()->schreiben($kopf);
$h->pFertig((string)$neu['id']);
pruefe('Der fertige Auftrag erreicht das Einpflegen', $h->hintergrund, [(string)$neu['id']]);

// ── Das Tagesbudget wird bei der ANNAHME gebucht ──────────────────────────
/* Der synchrone Weg prueft und bucht in derselben Runde — Karte zwei sieht
   schon den Stand nach Karte eins. Ueber Auftraege laeuft das Buchen erst,
   wenn der Ruf zurueckkommt, und der kommt fruehestens nach dem Lauf: alle
   fuenf Karten pruefen sonst gegen denselben veralteten Zaehler. Bei Deckel 20
   und Stand 19 ginge synchron genau EINER durch — sonst fuenf, und der Tag
   endete bei 24. Vom externen Codereview gemeldet (F6), durch den
   Auftragsweg verschaerft. */
$b = new JobProbe(9913);
$b->Create();
$b->pUebernehmen();
$b->pLaden()->alleLoeschen();
$b->gezaehlt = 0;
$b->pHintergrund();
$b->pHintergrund();
pruefe('Zwei Hintergrund-Auftraege buchen sofort zwei Aufrufe', $b->gezaehlt, 2);

/* Und beim Deuten NICHT noch einmal — sonst zaehlte jeder doppelt. */
$eins = $b->pHintergrund();
$b->gezaehlt = 0;
$kopfB = $b->pLaden()->lesen((string)$eins['id']);
$kopfB['state'] = AiJobStore::ROH;
$kopfB['raw'] = ['ok' => true, 'text' => '[]', 'debug' => []];
$b->pLaden()->schreiben($kopfB);
$b->pFertig((string)$eins['id']);
pruefe('Beim Deuten wird nicht noch einmal gebucht', $b->gezaehlt, 0);

// ── Eine bezahlte Antwort bleibt liegen, bis sie gedeutet ist ─────────────
/* Der Laeufer schreibt die Antwort als ROH und meldet sie mit einem Ruf ins
   Gateway. Faellt der Ruf aus — Kernel-Neustart dazwischen —, fragt bei
   Hintergrundarbeit NIEMAND nach: kein Geraet, keine Kachel. Ohne den Kehrgang
   waere die bezahlte Antwort fuer immer verloren. */
$b->pLaden()->alleLoeschen();
$liegen = $b->pHintergrund();
$kopfL = $b->pLaden()->lesen((string)$liegen['id']);
$kopfL['state'] = AiJobStore::ROH;
$kopfL['raw'] = ['ok' => true, 'text' => '[]', 'debug' => []];
/* Aelter als die Schlangenfrist — das Aufraeumen sieht ihn sich an. */
$kopfL['createdAt'] = time() - 4000;
$b->pLaden()->schreiben($kopfL);
$b->hintergrund = [];
$b->pSweep();
pruefe('Der Kehrgang deutet eine liegengebliebene Antwort',
    $b->hintergrund, [(string)$liegen['id']]);
$b->pLaden()->alleLoeschen();

$g->pLaden()->alleLoeschen();
$h->pLaden()->alleLoeschen();
$b->pLaden()->alleLoeschen();

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
