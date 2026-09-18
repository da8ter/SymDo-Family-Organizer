<?php

declare(strict_types=1);

/**
 * Hintergrund-Auftraege auf dem ECHTEN Weg: einreihen → Rohantwort → AiJobFinish
 * → Empfaenger (Briefing, Post).
 *
 * Warum es diesen Pruefstand braucht, obwohl jeder Teil schon einen hat: die
 * Teilpruefstaende bauten den Auftragskopf VON HAND und reichten ihn dem
 * Empfaenger — mit `raw` drin, weil der Empfaenger `raw` las. Beides passte
 * zueinander, beides war falsch: `AiJobFinish` wirft `raw` weg, bevor es ruft.
 * Im echten Lauf war damit jedes fertige Hintergrund-Briefing ein Fehlschlag
 * mit leerem Fach, und der Auftrag stand trotzdem auf „fertig". Ein externer
 * Codereview fand es am 18.09.2026 (F12), nicht die Pruefstaende.
 *
 * Deshalb hier keine Handkoepfe: die Traits stehen zusammen in EINER Klasse,
 * und gefahren wird der Weg, den der Kopf im Gateway nimmt. Was Symcon
 * beruehrt, ist ein Zaehler; die Warteschlange ist echt (Temp-Verzeichnis).
 *
 *   php SymDoGateway/tests/HintergrundAuftragTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../../libs/AiProvider.php';
require_once __DIR__ . '/../../libs/AiJobStore.php';
require_once __DIR__ . '/../libs/AiJobs.php';
require_once __DIR__ . '/../libs/Briefing.php';
require_once __DIR__ . '/../libs/MailAnalyseCalc.php';
require_once __DIR__ . '/../libs/MailScan.php';

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

/* Das Kernmodul-IMAP gibt es in den Attrappen nicht. Die Postfach-Loeschung
   wird hier mitgeschrieben — sie ist die eine Handlung dieses Weges, die sich
   nicht zurueckdrehen laesst. */
if (!function_exists('IMAP_GetMailEx')) {
    function IMAP_GetMailEx(int $instanz, string $uid): array
    {
        return ['UID' => $uid, 'Text' => 'Elternbrief'];
    }
}
if (!function_exists('IMAP_DeleteMail')) {
    function IMAP_DeleteMail(int $instanz, string $uid): bool
    {
        HintergrundProbe::$aktuelle?->ereignis('geloescht:' . $uid);
        return true;
    }
}

/**
 * Das Gateway, auf die drei Traits zusammengestrichen. Jede Methode hier
 * ersetzt eine, die sonst Symcon oder einen Bestand braucht — und schreibt
 * mit, WAS und in WELCHER REIHENFOLGE passiert.
 */
final class HintergrundProbe
{
    use AiJobs;
    use Briefing;
    use MailScan;

    public static ?HintergrundProbe $aktuelle = null;

    public int $InstanceID = 4711;
    public string $dir;
    /** Briefing */
    public array $bestand = [];
    public array $faecher = [];
    /** Post */
    public array $attrs = [];
    public array $vorschlaege = [];
    public array $abgelegt = [];
    public bool $speichern = true;
    public bool $loeschen = true;
    public int $anhangAbrufe = 0;
    /** @var list<string> Reihenfolge der Handlungen */
    public array $ereignisse = [];
    public array $protokoll = [];
    public int $gezaehlt = 0;

    public function __construct()
    {
        $this->dir = rtrim(sys_get_temp_dir(), '/\\') . '/symdo_hintergrund_' . bin2hex(random_bytes(4)) . '/';
        self::$aktuelle = $this;
    }

    public function ereignis(string $was): void { $this->ereignisse[] = $was; }

    // ── AiJobs ──────────────────────────────────────────────────────────
    private function AiJobDir(): string { return $this->dir; }
    private function AiJobMoeglich(): bool { return true; }
    private function AiJobSweepSetzen(int $ms): void {}
    private function ScanAuftragGeben(string $quelle, array $auftrag = []): bool { return true; }
    private function MailCountDay(): void { $this->gezaehlt++; }
    private function AiParseTodos(string $text, array $arten = []): array
    {
        $d = json_decode($text, true);
        return is_array($d) ? $d : [];
    }
    /* Die ECHTE Form (AiExtract::AiErrorMessage): flach, code neben message. */
    private function AiErrorMessage(string $code, string $grund = '', string $detail = ''): array
    {
        return ['ok' => false, 'code' => $code, 'message' => 'Fehler ' . $code, 'status' => 502];
    }
    private function WsPushJob(string $id): void {}
    private function AiJobTileAntwort(array $kopf): void {}

    // ── Briefing ────────────────────────────────────────────────────────
    private function BriefingStore(): array { return $this->bestand; }
    private function BriefingWriteStore(array $s): bool { $this->bestand = $s; return true; }
    private function BriefingWriteSlot(int $tage, array $s): bool { $this->faecher[$tage] = $s; return true; }
    private function BriefingAudio(string $t): array { return []; }
    private function BriefingTextVariable(): void {}
    private function BriefingAudioObjekt(): void {}
    private function WsPushDirty(): void {}

    // ── Post ────────────────────────────────────────────────────────────
    private function MailProp(string $k, mixed $d = null): mixed
    {
        return match ($k) { 'MailDeleteAfter' => $this->loeschen, 'MailReadAttachments' => true, default => $d };
    }
    private function PushProp(string $k, mixed $d = null): mixed { return $k === 'MailNoteAttachments' ? true : $d; }
    private function MailAttr(string $k, string $d = ''): string { return $this->attrs[$k] ?? $d; }
    private function MailWriteJsonAttr(string $k, mixed $v): bool { $this->attrs[$k] = (string)json_encode($v); return true; }
    private function MailPrepareText(array $v): string { return 'Ausflug am Freitag, bitte 5 Euro mitgeben.'; }
    private function MailFetchAttachments(int $id, string $uid): array
    {
        $this->anhangAbrufe++;
        $this->ereignis('anhaenge:' . $uid);
        return [['kind' => 'pdf', 'name' => 'brief.pdf', 'base64' => base64_encode('%PDF-1.4 BRIEF')]];
    }
    private function AiMailSystemPrompt(string $heute, bool $mitAnhang, string $quelle): string { return 'SYS'; }
    private function HomeworkKinder(): array { return []; }
    private function MailStoreProposal(array $satz): bool
    {
        $this->ereignis('gespeichert:' . ($this->speichern ? 'ja' : 'nein'));
        if ($this->speichern) {
            $this->vorschlaege[] = $satz;
        }
        return $this->speichern;
    }
    private function NotesSaveAttachment(string $b64, string $name): array
    {
        $this->abgelegt[] = $name;
        return ['ok' => true, 'id' => 123, 'kind' => 'pdf', 'bytes' => strlen((string)base64_decode($b64))];
    }
    private function MailNotifyProposal(int $a, int $t, int $n, string $u, string $q): void {}
    private function AiFetchPublicPage(string $url, int $frist = 15): array { return ['ok' => false, 'code' => 'ai_url_fetch']; }
    private function EduPushAuftragFertig(): void {}

    // ── Symcon ──────────────────────────────────────────────────────────
    private function Translate(string $s): string { return $s; }
    private function SendDebug(string $a, string $b, int $c): void {}
    private function LogMessage(string $m, int $l): void { $this->protokoll[] = $m; }

    // ── Tueren ──────────────────────────────────────────────────────────
    public function pBriefing(string $tag): array
    {
        return $this->BriefingAuftragGeben(0, $tag, 'u1', 'SYS', 'NUTZER');
    }
    public function pMail(): ?bool
    {
        return $this->MailAnalyse(77, ['UID' => '42', 'Subject' => 'Ausflug',
            'SenderAddress' => 'schule@example.test'], 'u1');
    }
    /** Die Kennung des einzigen offenen Auftrags. */
    public function pKennung(): string
    {
        $k = $this->AiJobLaden()->koepfe();
        return (string)($k[0]['id'] ?? '');
    }
    public function pKopf(string $id): ?array { return $this->AiJobLaden()->lesen($id); }
    /** Was der Laeufer tut: Rohantwort hinlegen. Dann der echte Fertigmelder. */
    public function pAntwort(string $id, array $roh): void
    {
        $l = $this->AiJobLaden();
        $k = $l->lesen($id);
        $k['state'] = AiJobStore::ROH;
        $k['raw']   = $roh;
        $l->schreiben($k);
        $this->AiJobFinish($id);
    }
    public function pAufraeumen(): void
    {
        $this->AiJobLaden(false)->alleLoeschen();
        @rmdir($this->dir);
    }
}

// ── F12: das Briefing kommt aus dem Hintergrund zurueck ───────────────────
$b = new HintergrundProbe();
register_shutdown_function(static fn() => $b->pAufraeumen());
$erg = $b->pBriefing('2026-09-18');
pruefe('Das Briefing wird eingereiht', [$erg['ok'], $erg['message']], [true, 'queued']);
$id = $b->pKennung();
pruefe('… als ein Auftrag mit Merker', [$id !== '', $b->bestand['pending']['id'] ?? null], [true, $id]);

$b->pAntwort($id, ['ok' => true, 'text' => 'Guten Morgen. Ausflug am Freitag.', 'debug' => []]);
pruefe('Das Fach ist gefuellt — mit der Antwort des Anbieters',
    $b->faecher[0]['text'] ?? null, 'Guten Morgen. Ausflug am Freitag.');
pruefe('… fuer den Zieltag', $b->faecher[0]['d'] ?? null, '2026-09-18');
pruefe('Kein Fehlschlag gezaehlt', (int)($b->bestand['fails'] ?? 0), 0);
pruefe('Der Merker ist weg', isset($b->bestand['pending']), false);
pruefe('Der Auftrag ist fertig, die Rohantwort geloescht',
    [$b->pKopf($id)['state'], isset($b->pKopf($id)['raw'])], [AiJobStore::FERTIG, false]);

/* Und ein echter Fehlschlag bleibt einer — mit dem Grund des Anbieters. */
$b2 = new HintergrundProbe();
register_shutdown_function(static fn() => $b2->pAufraeumen());
$b2->pBriefing('2026-09-18');
$id2 = $b2->pKennung();
$b2->pAntwort($id2, ['ok' => false, 'code' => 'ai_unreachable', 'grund' => '', 'detail' => 'timeout']);
pruefe('Ein Fehlschlag fuellt kein Fach und zaehlt einmal',
    [isset($b2->faecher[0]), (int)($b2->bestand['fails'] ?? 0)], [false, 1]);
pruefe('… der Auftrag steht auf gescheitert', $b2->pKopf($id2)['state'], AiJobStore::GESCHEITERT);

// ── F14: die Mail faellt erst, wenn der Vorschlag steht ───────────────────
/* Bis zum 18.09.2026 loeschte der Fertigmelder die Mail, SOBALD der Anbieter
   geantwortet hatte — und las nicht, ob das Speichern gelang. Ein
   fehlgeschlagenes Ablegen hiess: Original weg, Vorschlag nie entstanden.
   Das Loeschen ist die einzige Handlung dieses Weges, die sich nicht
   zurueckdrehen laesst; sie muss die letzte sein. */
$notiz = json_encode([['kind' => 'note', 'title' => 'Ausflug', 'info' => 'Freitag 5 Euro']]);
$nurHandlungen = static fn(array $e): array => array_values(array_filter($e,
    static fn(string $x): bool => str_starts_with($x, 'gespeichert') || str_starts_with($x, 'geloescht')));

$m1 = new HintergrundProbe();
register_shutdown_function(static fn() => $m1->pAufraeumen());
pruefe('Die Mail wird als Auftrag eingereiht', $m1->pMail(), true);
$m1->pAntwort($m1->pKennung(), ['ok' => true, 'text' => $notiz, 'debug' => []]);
pruefe('Gespeichert — und ERST DANN geloescht',
    $nurHandlungen($m1->ereignisse), ['gespeichert:ja', 'geloescht:42']);

$m2 = new HintergrundProbe();
register_shutdown_function(static fn() => $m2->pAufraeumen());
$m2->speichern = false;
$m2->pMail();
$m2->pAntwort($m2->pKennung(), ['ok' => true, 'text' => $notiz, 'debug' => []]);
pruefe('Nicht gespeichert: die Mail bleibt im Postfach',
    $nurHandlungen($m2->ereignisse), ['gespeichert:nein']);
/* … und sie kommt wieder dran: der Merker geht zurueck, der Fehlversuch
   zaehlt — dieselbe Politik wie ein gescheiterter Anbieteraufruf. */
$karte = json_decode($m2->attrs['MailSeenUIDs'] ?? '{}', true);
pruefe('… und zaehlt als Fehlversuch, damit sie wiederkommt',
    (int)($karte['#fehl']['77:42'] ?? 0), 1);
pruefe('… das Protokoll sagt, warum',
    (bool)array_filter($m2->protokoll, static fn(string $z): bool =>
        str_contains($z, 'konnte nicht gespeichert werden')), true);

/* Nichts gefunden ist trotzdem abgeschlossen: dann darf die Post fallen. */
$m3 = new HintergrundProbe();
register_shutdown_function(static fn() => $m3->pAufraeumen());
$m3->pMail();
$m3->pAntwort($m3->pKennung(), ['ok' => true, 'text' => '[]', 'debug' => []]);
pruefe('Sauber ausgewertet ohne Fund: die Mail wird geloescht',
    $nurHandlungen($m3->ereignisse), ['geloescht:42']);

/* Und ein gescheiterter Anbieteraufruf loescht NIE. */
$m4 = new HintergrundProbe();
register_shutdown_function(static fn() => $m4->pAufraeumen());
$m4->pMail();
$m4->pAntwort($m4->pKennung(), ['ok' => false, 'code' => 'ai_unreachable', 'grund' => '', 'detail' => '']);
pruefe('Anbieter-Fehlschlag: nichts gespeichert, nichts geloescht', $nurHandlungen($m4->ereignisse), []);
pruefe('… und das Protokoll nennt den Grund des Anbieters',
    (bool)array_filter($m4->protokoll, static fn(string $z): bool =>
        str_contains($z, 'Fehler ai_unreachable')), true);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
