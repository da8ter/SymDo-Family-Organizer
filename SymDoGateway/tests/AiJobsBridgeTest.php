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

    /* Das Briefing teilt sich den Hintergrund-Topf mit der Auswertung, landet
       aber in einem anderen Bestand. */
    public array $briefing = [];
    /** @var list<array<string,mixed>> die Koepfe, so wie sie hier ankommen */
    public array $briefingKoepfe = [];
    private function BriefingAuftragEinpflegen(array $kopf): void
    {
        $this->briefing[] = (string)$kopf['id'];
        $this->briefingKoepfe[] = $kopf;
    }

    // ── Tueren fuer den Pruefstand ────────────────────────────────────────
    public function pEinreihen(string $kind, array $job, array $parse, string $nutzlast, string $geraet): array
    {
        return $this->AiJobEnqueue($kind, $job, $parse, ['type' => 'rest'], $nutzlast, $geraet);
    }
    /** Ein Briefing-Auftrag — Hintergrund, aber ein anderer Bestand. */
    public function pBriefing(string $zielTag = '2026-09-16'): array
    {
        return $this->AiJobEnqueue('extract',
            ['system' => 's', 'user' => 'u'],
            ['type' => 'text'],
            ['type' => AiJobStore::HERKUNFT_HINTERGRUND, 'art' => 'briefing',
             'quelle' => 'Briefing', 'tage' => 0, 'zielTag' => $zielTag, 'userId' => 'u1']);
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
    public function pUngebucht(): int { return $this->AiJobUngebucht(); }

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

// ── Der Tagesdeckel reserviert, was schon wartet ─────────────────────────
/* Gebucht wird beim DEUTEN. Bis dahin sah jeder neue Auftrag denselben alten
   Stand — bei Tagesdeckel 1 kamen ZWEI Fotos durch, bei 20 alle, die in der
   Zeit eines Aufrufs eingereicht wurden. Der synchrone Weg hatte das nie: er
   prueft und bucht in derselben Runde. Also zaehlt die Bremse das Ausstehende
   mit; es ist eine RESERVIERUNG, kein Buchen — scheitert ein Auftrag, faellt
   sie von selbst weg. Von einem externen Codereview gemeldet (F6,
   nachgefasst am 15.09.2026). */
$r = new JobProbe(9911);
$r->Create();
$r->pLaden()->alleLoeschen();
pruefe('Nichts eingereiht, nichts reserviert', $r->pUngebucht(), 0);

$e1 = $r->pEinreihen('extract', ['system' => 's', 'user' => 'u'],
    ['type' => 'todos', 'arten' => ['task']], '', 'geraet1');
pruefe('Der erste Auftrag reserviert', $r->pUngebucht(), 1);
$e2 = $r->pEinreihen('extract', ['system' => 's', 'user' => 'u'],
    ['type' => 'todos', 'arten' => ['task']], '', 'geraet2');
pruefe('Der zweite auch — sie zaehlen zusammen', $r->pUngebucht(), 2);

/* Ein Diktat zaehlt NICHT: es ist die halbe Miete, das Zerlegen kommt danach
   als eigener Aufruf und wird dann gezaehlt. Wer es mitzaehlte, buchte dem
   Nutzer jedes Diktat doppelt. */
$r->pEinreihen('transcribe', ['system' => '', 'user' => '', 'mime' => 'audio/webm'],
    ['type' => 'text'], 'TON', 'geraet3');
pruefe('Ein Diktat reserviert nichts', $r->pUngebucht(), 2);

/* Hintergrundarbeit bucht schon bei der Annahme — sie darf nicht doppelt
   zaehlen. */
$r->pHintergrund();
pruefe('Hintergrundarbeit reserviert nichts mehr — sie hat gebucht',
    [$r->pUngebucht(), $r->gezaehlt], [2, 1]);

/* Eine Antwort, die noch nicht gedeutet ist, bleibt reserviert: gebucht wird
   erst beim Deuten. */
$k1 = $r->pLaden()->lesen((string)$e1['id']);
$k1['state'] = AiJobStore::ROH;
$k1['raw']   = ['ok' => true, 'text' => '[]', 'debug' => []];
$r->pLaden()->schreiben($k1);
pruefe('Eine ungedeutete Antwort bleibt reserviert', $r->pUngebucht(), 2);

$r->pFertig((string)$e1['id']);
pruefe('Erst das Deuten loest die Reservierung ab — und bucht',
    [$r->pUngebucht(), $r->gezaehlt], [1, 2]);

/* Ein gescheiterter Auftrag kostet den Nutzer nichts — die Reservierung faellt
   weg, gebucht wurde nie. Genau wie beim synchronen Weg. */
$k2 = $r->pLaden()->lesen((string)$e2['id']);
$k2['state'] = AiJobStore::ROH;
$k2['raw']   = ['ok' => false, 'code' => 'ai_unreachable', 'debug' => []];
$r->pLaden()->schreiben($k2);
$r->pFertig((string)$e2['id']);
pruefe('Ein Fehlschlag kostet kein Budget', [$r->pUngebucht(), $r->gezaehlt], [0, 2]);

/* Ohne Scanner gibt es gar keine Auftraege — dann darf die Bremse auch nichts
   dazurechnen, sonst wuerde der synchrone Weg gegen einen Phantomstand
   geprueft. */
$r->pHintergrund();
$r->uebernommen = false;
pruefe('Ohne Laeufer wird nichts reserviert', $r->pUngebucht(), 0);
$r->uebernommen = true;
$r->pLaden()->alleLoeschen();

/* Und die Bremse muss es auch wirklich benutzen. Sie steht in MailScan und
   laesst sich hier nicht fahren — also am Quelltext festgenagelt. */
$mail = (string)file_get_contents(__DIR__ . '/../libs/MailScan.php');
pruefe('Die Tagesbremse rechnet die Reservierungen mit',
    str_contains($mail, '$heute + $this->AiJobUngebucht() >= $grenze'), true);

// ── Das Briefing nimmt denselben Weg, landet aber woanders ───────────────
/* Der Anbieteraufruf des Briefings dauert drei bis sechzig Sekunden und lief
   bisher in der Gateway-Spur. Er geht jetzt als Hintergrund-Auftrag hinaus —
   und muss beim Fertigwerden in den BRIEFING-Bestand, nicht in die
   Vorschlagsliste. Eine Verwechslung faende niemand: der Text saehe wie ein
   Mail-Vorschlag aus und das Briefing bliebe leer. */
$br = new JobProbe(9912);
$br->Create();
$br->pLaden()->alleLoeschen();
$eins = $br->pBriefing();
pruefe('Das Briefing wird angenommen', (bool)($eins['ok'] ?? false), true);
pruefe('… und bucht sofort wie jede Hintergrundarbeit', $br->gezaehlt, 1);
$kopfBr = $br->pLaden()->lesen((string)$eins['id']);
$kopfBr['state'] = AiJobStore::ROH;
$kopfBr['raw']   = ['ok' => true, 'text' => 'Guten Morgen.', 'debug' => []];
$br->pLaden()->schreiben($kopfBr);
$br->pFertig((string)$eins['id']);
pruefe('Es landet im Briefing-Bestand', $br->briefing, [(string)$eins['id']]);
pruefe('… und NICHT in der Vorschlagsliste', $br->hintergrund, []);
/* WAS dort ankommt, ist die gedeutete Antwort — `raw` ist zu diesem Zeitpunkt
   schon weg. Der Empfaenger hat sie bis zum 18.09.2026 trotzdem gelesen und
   jedes fertige Briefing als Fehlschlag verbucht. Der Vertrag steht hier. */
pruefe('Der Empfaenger bekommt die gedeutete Antwort, nicht die rohe',
    [$br->briefingKoepfe[0]['result']['body'] ?? null, isset($br->briefingKoepfe[0]['raw'])],
    [['ok' => true, 'text' => 'Guten Morgen.'], false]);

/* Umgekehrt ebenso: eine Auswertung darf nicht im Briefing landen. */
$br->briefing = [];
$zwei = $br->pHintergrund();
$kopfZw = $br->pLaden()->lesen((string)$zwei['id']);
$kopfZw['state'] = AiJobStore::ROH;
$kopfZw['raw']   = ['ok' => true, 'text' => '[]', 'debug' => []];
$br->pLaden()->schreiben($kopfZw);
$br->pFertig((string)$zwei['id']);
pruefe('Eine Auswertung bleibt in der Vorschlagsliste',
    [$br->hintergrund, $br->briefing], [[(string)$zwei['id']], []]);
$br->pLaden()->alleLoeschen();

$g->pLaden()->alleLoeschen();
$h->pLaden()->alleLoeschen();
$b->pLaden()->alleLoeschen();

// ══ Wer den Anbieter noch DIREKT ruft ════════════════════════════════════
/* Vier Wege fuehren zur Auswertung: Klassenseiten, LOGINEO, Postfach und
   Webhook. Drei davon gehen ueber die Warteschlange; der Webhook bleibt
   bewusst synchron, weil sein Zustand die Spool-Datei ist (Begruendung steht
   dort im Kommentar). Die Zahl steht hier ausdruecklich, damit ein FUENFTER
   Weg auffaellt — und damit auffaellt, wenn einer der drei seine Weiche
   verliert. */
$dateien = [
    'EduMaps.php' => __DIR__ . '/../libs/EduMaps.php',
    'Moodle.php'  => __DIR__ . '/../libs/Moodle.php',
    'MailScan.php' => __DIR__ . '/../libs/MailScan.php',
];
$direkt = 0;
$eingereiht = 0;
foreach ($dateien as $datei) {
    $q = (string)file_get_contents($datei);
    $direkt     += substr_count($q, '$this->MailAnalyseRecord(');
    $eingereiht += substr_count($q, '$this->MailAnalyseAuftrag(');
}
pruefe('Drei Wege reihen ein', $eingereiht, 3);
pruefe('… und vier rufen im Rueckfall direkt', $direkt, 4);
$mail = (string)file_get_contents(__DIR__ . '/../libs/MailScan.php');
pruefe('Das Postfach fragt nach einem Laeufer',
    str_contains($mail, "if (\$this->AiJobMoeglich()) {\n            \$grund = '';\n            \$ok = \$this->MailAnalyseAuftrag(\$imapID . ':' . \$uid,"), true);
/* Loeschen erst NACH der Analyse: vorher waere die Mail weg und die Aufgabe
   mit ihr, falls der Auftrag scheitert. */
pruefe('Das Loeschen im Postfach reist mit dem Merker',
    str_contains($mail, "'loeschen' => \$loeschen], \$grund);"), true);
pruefe('… und passiert erst beim Abschluss',
    str_contains($mail, 'private function MailMerkerAbschliessen(array $merker): void'), true);
/* Und die Ruecknahme kennt alle drei Bestaende. */
pruefe('Die Ruecknahme unterscheidet die Quellen',
    str_contains($mail, "if (\$quelle === 'mail') {"), true);
/* Eine volle Schlange ist KEIN Fehlversuch der Mail. Wer sie als einen
   zaehlte, haette sie nach dreimal „gerade kein Platz" endgueltig
   uebersprungen — und die Aufgabe darin waere weg, obwohl nie jemand sie
   gelesen hat. */
pruefe('Eine volle Schlange meldet sich getrennt',
    str_contains($mail, "if (!\$ok && \$grund === 'ai_busy') {\n                return null;"), true);
pruefe('… und der Aufrufer zaehlt sie nicht als Fehlversuch',
    str_contains($mail, "if (\$ergebnis === null) {"), true);
pruefe('… die Analyse darf das ueberhaupt melden',
    str_contains($mail, 'private function MailAnalyse(int $imapID, array $kopf, string $userId): ?bool'), true);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
