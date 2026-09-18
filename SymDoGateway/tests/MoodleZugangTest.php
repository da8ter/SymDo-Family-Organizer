<?php

declare(strict_types=1);

/**
 * LOGINEO: kommt der Token beim Leser an — und was passiert, wenn die Schule
 * einen Abruf schuldig bleibt?
 *
 * Der Leser (`MoodleLesen`) kennt keinen Tokenspeicher; er laeuft auch in einer
 * Scanner-Instanz und bekommt den Token MIT dem Zugang. Im Gateway muss jeder
 * Weg zum Leser ihn deshalb dazulegen. Zwei taten es nicht (F10, externer
 * Codereview 18.09.2026): der synchrone Lauf meldete „nichts lesbar", die
 * Zugangspruefung „der Token funktioniert nicht" — bei gespeichertem Token,
 * ohne einen einzigen Ruf an die Schule.
 *
 * Gefahren wird gegen die ECHTEN Traits; nur der Draht (`MoodleHttp`) ist eine
 * Attrappe, die mitschreibt, was gerufen wurde und ob ein Token dabei war.
 *
 *   php SymDoGateway/tests/MoodleZugangTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../../libs/AiRecipePage.php';
require_once __DIR__ . '/../libs/MoodleCalc.php';
require_once __DIR__ . '/../libs/HomeworkCalc.php';
require_once __DIR__ . '/../libs/MoodleLesen.php';
require_once __DIR__ . '/../libs/Moodle.php';

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

/** Beide Haelften echt; der Draht und der Bestand sind Zaehler. */
final class MoodleProbe
{
    use MoodleLesen;
    use Moodle;

    public int $InstanceID = 4711;
    public array $konten = [];
    public array $attrs = [];
    /** @var list<string> gerufene Funktion, dazu ob ein Token mitging */
    public array $rufe = [];
    /** @var array<string,bool> welche Funktion die Schule schuldig bleibt */
    public array $schuldig = [];
    /** Die Schule antwortet — und hat wirklich keine Aufgaben. */
    public bool $keineAufgaben = false;
    /** @var list<array> der Hausaufgaben-Bestand */
    public array $hausaufgaben = [];
    /** @var list<array> jeder Import, der den Bestand abgleicht */
    public array $importe = [];

    // ── Draht ────────────────────────────────────────────────────────────
    private function MoodleHttp(string $url, array $felder): ?array
    {
        $fn = (string)($felder['wsfunction'] ?? 'login');
        $this->rufe[] = $fn . '@' . (trim((string)($felder['wstoken'] ?? '')) !== '' ? 'token' : 'ohne');
        if ($this->schuldig[$fn] ?? false) {
            return null;
        }
        $body = match ($fn) {
            'core_webservice_get_site_info' => ['userid' => 77, 'release' => '4.5',
                'functions' => [['name' => 'mod_choice_get_choices_by_courses'],
                                ['name' => 'mod_choice_get_choice_options']]],
            'core_enrol_get_users_courses' => [['id' => 7, 'shortname' => 'Mathe', 'fullname' => 'Mathematik']],
            'mod_assign_get_assignments' => ['courses' => [['id' => 7, 'shortname' => 'Mathe',
                'assignments' => $this->keineAufgaben ? []
                    : [['id' => 123, 'name' => 'Seite 12', 'duedate' => strtotime('+1 day')]]]]],
            'mod_assign_get_submission_status' => ['lastattempt' => ['submission' => ['status' => 'new']]],
            'mod_choice_get_choices_by_courses' => ['choices' => []],
            default => [],
        };
        return ['status' => 200, 'body' => (string)json_encode($body)];
    }

    // ── was die Traits an Symcon brauchen ────────────────────────────────
    private function MoodleProp(string $k, mixed $d = null): mixed
    {
        return match ($k) {
            'MoodleEnabled'  => true,
            'MoodleAccounts' => (string)json_encode($this->konten),
            'MoodleHomework' => true,
            'MoodleEvents'   => false,
            default          => $d,
        };
    }
    private function ReadAttributeString(string $k): string { return $this->attrs[$k] ?? '{}'; }
    private function WriteAttributeString(string $k, string $v): void { $this->attrs[$k] = $v; }
    private function EduStoreRead(): array { return ['blocked' => []]; }
    private function LoadUsers(): array { return []; }
    private function HomeworkImportieren(string $kind, array $zeilen, string $von, string $bis, string $quelle): array
    {
        $this->importe[] = $zeilen;
        $e = HomeworkCalc::Zusammenfuehren($this->hausaufgaben, $zeilen, $kind, $von, $bis, time(), $quelle);
        $this->hausaufgaben = $e['items'];
        return $e + ['ok' => true];
    }
    private function Translate(string $s): string { return $s; }
    private function SendDebug(string $a, string $b, int $c): void {}
    private function LogMessage(string $m, int $l): void {}

    // ── Tueren ───────────────────────────────────────────────────────────
    public function pTokenSetzen(array $konto, string $token): void
    {
        $this->attrs['MoodleTokens'] = (string)json_encode(
            [MoodleCalc::TokenSchluessel($konto['site'], $konto['userId']) => ['token' => $token]]);
    }
    /** Der synchrone Lauf, trocken: liest, schreibt nichts. */
    public function pLauf(): string { return $this->MoodleScanRun(true); }
    public function pPruefen(array $konto): string { return $this->MoodleZugangPruefen($konto); }
    public function pErnten(array $konto): array { return $this->MoodleKontoErnten($konto, [], true, false); }
    public function pEinpflegen(array $ernte): string { return $this->MoodleErnteEinpflegen($ernte, false); }
}

$konto = ['site' => 'https://schule.example.test', 'name' => 'Grundschule', 'user' => 'kind', 'userId' => 'u1'];

// ── F10: der gespeicherte Token kommt beim Leser an ───────────────────────
$p = new MoodleProbe();
$p->konten = [$konto];
$p->pTokenSetzen($konto, 'T-GEHEIM');
$bericht = $p->pLauf();
pruefe('Der synchrone Lauf ruft die Schule — und zwar MIT Token',
    $p->rufe[0] ?? null, 'core_webservice_get_site_info@token');
pruefe('… kein Ruf geht ohne Token hinaus',
    array_values(array_filter($p->rufe, static fn(string $r): bool => str_ends_with($r, '@ohne'))), []);
pruefe('… und der Bericht sagt nicht „nichts lesbar"', str_contains($bericht, 'nothing readable'), false);

$p->rufe = [];
$pruefung = $p->pPruefen($konto);
pruefe('Die Zugangspruefung findet den gespeicherten Token',
    str_contains($pruefung, 'does not work'), false);
pruefe('… nennt, was das Konto hergibt', str_contains($pruefung, '1 course(s): Mathe'), true);
pruefe('… mit Token am Draht', $p->rufe[0] ?? null, 'core_webservice_get_site_info@token');

/* Ein Zugang, der seinen Token schon traegt (Auftragsweg), behaelt ihn. */
$p->rufe = [];
$p->pErnten($konto + ['token' => 'T-AUFTRAG']);
pruefe('Ein mitgebrachter Token wird nicht ersetzt',
    str_ends_with($p->rufe[0] ?? '', '@token'), true);

/* Ohne gespeicherten Token bleibt der Draht stumm — kein Ruf, der scheitern koennte. */
$q = new MoodleProbe();
$q->konten = [$konto];
$berichtOhne = $q->pLauf();
pruefe('Ohne Token wird die Schule gar nicht gerufen',
    [str_contains($berichtOhne, 'no token'), $q->rufe], [true, []]);

/* Die Klasse, nicht der Fall: JEDER Weg aus dem Gateway zum Leser fuehrt ueber
   MoodleMitToken. Es gibt genau zwei — die Pruefung (zwei Rufe) und der Lauf. */
$quelle = (string)file_get_contents(__DIR__ . '/../libs/Moodle.php');
$pruefen = substr($quelle, (int)strpos($quelle, 'private function MoodleZugangPruefen('));
$pruefen = substr($pruefen, 0, (int)strpos($pruefen, "\n    }\n"));
$lesen = substr($quelle, (int)strpos($quelle, 'private function MoodleKontoLesen('));
$lesen = substr($lesen, 0, (int)strpos($lesen, "\n    }\n"));
pruefe('Das Gateway ruft den Leser nur an zwei Stellen',
    [substr_count($quelle, '$this->MoodleRest('), substr_count($quelle, '$this->MoodleKontoErnten(')], [2, 1]);
pruefe('… beide liegen in den Funktionen, die den Token dazulegen',
    [substr_count($pruefen, '$this->MoodleRest('), substr_count($lesen, '$this->MoodleKontoErnten(')], [2, 1]);
pruefe('… und beide legen ihn dazu, bevor der Leser ihn sieht',
    [strpos($pruefen, 'MoodleMitToken(') < strpos($pruefen, '$this->MoodleRest('),
     str_contains($lesen, 'MoodleKontoErnten($this->MoodleMitToken($zugang)')], [true, true]);

// ── F11: ein schuldig gebliebener Abruf ist keine leere Liste ─────────────
/* `HomeworkImportieren` gleicht im Fenster ab: was die Schule nicht mehr
   nennt, gilt als zurueckgezogen. Bis zum 18.09.2026 wurde aus einem
   fehlgeschlagenen Aufgaben-Abruf ein leeres Feld — und der Abgleich loeschte
   die Hausaufgaben des Kindes. Fehler und bestaetigte Leere muessen zwei
   verschiedene Antworten bleiben. Der Scanner nutzt denselben Leser. */
$morgen  = date('Y-m-d', strtotime('+1 day'));
$bestand = [['id' => 'h1', 'srcId' => 123, 'source' => 'moodle', 'childId' => 'u1',
             'due' => $morgen, 'subject' => 'Mathe', 'note' => 'Seite 12', 'done' => false]];
$mitToken = $konto + ['token' => 'T'];

foreach (['mod_assign_get_assignments', 'mod_choice_get_choices_by_courses'] as $fn) {
    $r = new MoodleProbe();
    $r->hausaufgaben = $bestand;
    $r->schuldig[$fn] = true;
    $ernte = $r->pErnten($mitToken);
    pruefe(substr($fn, 4) . ' bleibt aus: die Ernte ist trotzdem gueltig', $ernte['ok'], true);
    pruefe('… traegt aber KEINE Hausaufgabenliste, sondern den Fehler',
        [$ernte['hausaufgaben'], $ernte['aufgabenFehler']], [[], 1]);
    $text = $r->pEinpflegen($ernte);
    pruefe('… der Bestand bleibt, wie er war — kein Abgleich',
        [count($r->hausaufgaben), $r->importe], [1, []]);
    pruefe('… und die Statuszeile sagt es',
        str_contains($text, 'homework not readable this time'), true);
}

/* Der Kontrollfall: eine BESTAETIGT leere Liste raeumt auf — das ist gewollt,
   die Schule hat die Aufgabe zurueckgezogen. */
$k = new MoodleProbe();
$k->hausaufgaben = $bestand;
$k->keineAufgaben = true;
$ernte = $k->pErnten($mitToken);
$text = $k->pEinpflegen($ernte);
pruefe('Eine bestaetigt leere Liste zieht die Aufgabe zurueck',
    [count($k->hausaufgaben), count($k->importe), $ernte['aufgabenFehler']], [0, 1, 0]);
pruefe('… ohne Fehlerhinweis', str_contains($text, 'not readable'), false);

/* Und der Normalfall: die Aufgabe kommt an und bleibt. */
$n = new MoodleProbe();
$n->pEinpflegen($n->pErnten($mitToken));
pruefe('Im Normalfall steht die Aufgabe im Bestand',
    [count($n->hausaufgaben), $n->hausaufgaben[0]['srcId'] ?? null, $n->hausaufgaben[0]['note'] ?? null],
    [1, 123, 'Seite 12']);

/* Die Klasse: der Leser unterscheidet ueberall zwischen „nicht geantwortet"
   (null) und „nichts da" ([]) — beide Listenabrufe geben ?array zurueck. */
$leser = (string)file_get_contents(__DIR__ . '/../libs/MoodleLesen.php');
pruefe('Beide Listenabrufe koennen „nicht geantwortet" sagen',
    [str_contains($leser, 'array $zusatz = []): ?array'),
     str_contains($leser, 'array $funktionen): ?array')], [true, true]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
