<?php

declare(strict_types=1);

/**
 * Das Spiegeln einer Klassenseite in den Bestand — Schuebe, Medienbilanz,
 * Fehlschlag.
 *
 * Warum es diesen Prüfstand geben MUSS: der Umbau „ein Schreibvorgang je Seite
 * statt je Karte" hat drei Löcher gerissen, die im Betrieb erst nach Wochen
 * aufgefallen wären, und zwei davon kosten Dateien:
 *
 *  - Schlug der eine Schreibvorgang fehl, war die GANZE Seite verloren — und
 *    bei jedem weiteren Lauf aufs Neue. Der alte Weg behielt, was bis dahin
 *    geschrieben war.
 *  - Alle neuen Anhänge einer Seite entstanden, bevor auch nur einer der
 *    ersetzten frei wurde. Die Notizen-Kategorie hält 300 Objekte; eine Seite
 *    braucht im Grenzfall 600. Was dann nicht mehr abgelegt werden kann, fällt
 *    still weg — die Karte bekommt leeres `att`, aber neues `srcRev`, gilt
 *    fortan als unverändert, und ihre alten Anhänge werden gelöscht.
 *  - Die Vorschaubilder des Nachzugs standen nicht in der Rücknahmeliste.
 *
 * Gefahren wird der Trait gegen eine Attrappe: alles, was Symcon berührt,
 * ist hier ein Zähler. Geprüft wird die BILANZ — wie viele Schreibvorgänge,
 * welche Medien entstehen, welche verschwinden und wann.
 *
 *   php SymDoGateway/tests/EduSpiegelTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/EduStoreCalc.php';
require_once __DIR__ . '/../../libs/AiProvider.php';
require_once __DIR__ . '/../../libs/AiRecipePage.php';
require_once __DIR__ . '/../libs/EduEinpflegen.php';

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

/**
 * Die Attrappe. Sie tut nichts Echtes — sie zaehlt, was der Trait verlangt.
 */
final class Spiegelprobe
{
    use EduEinpflegen;

    /** Die Sperre traegt im echten Modul diesen Namen; sie kommt aus EduStore. */
    private const EDU_LOCK = 'TGW_Edu_';
    /** Der Kartendeckel je Seite — im echten Modul aus EduMaps. */
    private const EDU_KARTEN_MAX = 60;

    /* Die Weissliste fuer HTML. Im echten Modul zerlegt sie den Rumpf und
       laesst nur Absatz, Liste, fett, kursiv und http/https/mailto-Verweise
       stehen. Hier genuegt eine Markierung: geprueft wird, DASS der Umschlag
       hindurchgeht — was die Weissliste kann, ist ihre eigene Sache. */
    public array $gefiltert = [];
    private function EduHtml(string $rumpf): string
    {
        $this->gefiltert[] = $rumpf;
        return $rumpf === '' ? '' : '[weissliste]';
    }

    public int $InstanceID = 4711;
    private bool $eduOrdnerGeaendert = false;

    /** @var array<string,mixed> */
    public array $bestand;
    public array $geschrieben = [];   // je Schreibvorgang die Zahl der Notizen
    public array $angelegt    = [];   // Medien-Kennungen, die entstanden sind
    public array $geloescht   = [];   // Medien-Kennungen, die weg sind
    public array $meldungen   = [];
    public int $naechsteMedienId = 100;
    /** Ab diesem Schreibvorgang scheitert es (0 = nie). */
    public int $scheitertAb = 0;
    private int $schreibZaehler = 0;

    public function __construct()
    {
        $this->bestand = EduStoreCalc::Leer();
    }

    // ── was der Trait an Symcon braucht ──────────────────────────────────
    private function EduProp(string $n, mixed $v): mixed { return true; }
    /** Die Statuszeile des Konfigurationsformulars. */
    public string $status = '';
    public function WriteAttributeString(string $n, string $w): void
    {
        if ($n === 'EduStatus') { $this->status = $w; }
    }
    private function EduStorable(): bool { return true; }
    private function EduStoreRead(): array { return $this->bestand; }
    private function SendDebug(string $a, string $b, int $c): void {}
    public function LogMessage(string $t, int $s): void { $this->meldungen[] = $t; }

    private function EduWriteStore(array $store): bool
    {
        $this->schreibZaehler++;
        if ($this->scheitertAb > 0 && $this->schreibZaehler >= $this->scheitertAb) {
            return false;
        }
        $store['rev'] = (int)$store['rev'] + 1;
        $this->bestand = $store;
        $this->geschrieben[] = ['notizen' => count($store['notes']), 'rev' => $store['rev']];
        return true;
    }

    private function EduOrdner(array &$store, array $seite): string { return 'f1'; }
    private function EduNotizText(array $karte): string { return (string)($karte['text'] ?? ''); }
    private function EduArt(string $name): string { return str_ends_with($name, '.pdf') ? 'pdf' : ''; }
    private function EduQrVormerken(string $t): void { $this->eduQrSeiten[] = $t; }
    private function EduQrCode(int $id): string { return ''; }
    private function NotesNewId(): string { return 'n' . (++$this->naechsteMedienId); }
    private function NotesStore(): array { return ['notes' => []]; }

    /** Waise = alles, was uebergeben wird; strenger als echt, aber nachvollziehbar. */
    private function NotesUnreferencedMedia(array $store, array $ids): array { return $ids; }

    private function NotesDeleteMedia(array $ids): void
    {
        foreach ($ids as $id) {
            $this->geloescht[] = (int)$id;
        }
    }

    /** Je Anhang zwei Medien: die Datei und ihre Vorschau. */
    private function EduNotizAnhaenge(array $karte): array
    {
        $raus = [];
        if ($this->qrImAnhang !== '' && (array)($karte['anhaenge'] ?? []) !== []) {
            // Wie im Betrieb: EduQrCode liest das Bild und merkt den Fund vor.
            $this->eduQrSeiten[] = $this->qrImAnhang;
        }
        foreach ((array)($karte['anhaenge'] ?? []) as $a) {
            $id = ++$this->naechsteMedienId;
            $th = ++$this->naechsteMedienId;
            $this->angelegt[] = $id;
            $this->angelegt[] = $th;
            $raus[] = ['id' => $id, 'kind' => 'pdf', 'name' => (string)$a['name'],
                       'bytes' => 10, 'thumb' => $th];
        }
        return $raus;
    }

    private function EduVorschau(string $url, string $name): int
    {
        $id = ++$this->naechsteMedienId;
        $this->angelegt[] = $id;
        return $id;
    }

    /** Gesperrte Seiten — im echten Gateway aus dem Bestand (`blocked`). */
    public array $gesperrt = [];
    /** Was als verlinkte Anlage aufgenommen wurde. */
    public array $aufgenommen = [];
    private function EduGefundeneAufnehmen(array $seite, array $adressen): int
    {
        $this->aufgenommen[] = $adressen;
        return count($adressen);
    }
    /**
     * Was der QR-Leser beim naechsten Anhang findet.
     *
     * Gesetzt wird der Merker WAEHREND des Spiegelns, nicht davor: im Betrieb
     * laeuft `EduQrCode` aus `EduNotizAnhaenge` heraus, also mitten im Lauf.
     * Wer davor saet, wird vom Leeren am Seitenanfang erwischt — genau das ist
     * meinem ersten Prueflauf passiert.
     */
    public string $qrImAnhang = '';
    private array $eduQrSeiten = [];
    private function EduGesperrt(string $url): bool { return in_array($url, $this->gesperrt, true); }
    private function EduArchivAbgleichen(array $seite, array $karten): int { $this->archiv[] = $seite['url']; return 0; }
    public array $archiv = [];

    // ── Zugaenge fuer den Prueflauf ──────────────────────────────────────
    public function Spiegle(array $seite, array $karten): int
    {
        return $this->EduSeiteSpiegeln($seite, $karten);
    }
    public function Umschlag(array $u): int { return $this->EduUmschlagEinpflegen($u); }
    public function Seite(mixed $roh): ?array { return $this->EduUmschlagSeite($roh); }
}

$seite = ['name' => '5b', 'url' => 'https://x.test/s', 'userId' => 'u1'];

/** n Karten, jede mit einem PDF-Anhang. */
function karten(int $n, int $rev = 1000): array
{
    $raus = [];
    for ($i = 0; $i < $n; $i++) {
        $raus[] = ['boxid' => 'b' . $i, 'updated' => $rev, 'titel' => 'K' . $i,
                   'text' => 'Text ' . $i, 'abschnitt' => '', 'abschnittFarbe' => '',
                   'farbe' => '', 'html' => '<p>' . $i . '</p>', 'buchung' => null,
                   'anhaenge' => [['name' => 'brief' . $i . '.pdf', 'url' => 'https://x.test/b' . $i . '.pdf',
                                   'preview' => 'https://x.test/p' . $i]]];
    }
    return $raus;
}

// ══ 1. Eine Seite aus Textkarten kostet weiterhin EINEN Schreibvorgang ═══
$p = new Spiegelprobe();
$nurText = array_map(static function (array $k): array {
    $k['anhaenge'] = [];
    return $k;
}, karten(55));
pruefe('55 Textkarten werden alle gespiegelt', $p->Spiegle($seite, $nurText), 55);
pruefe('… in genau einem Schreibvorgang', count($p->geschrieben), 1);
pruefe('… und kosten kein einziges Medienobjekt', $p->angelegt, []);

// ══ 2. Anhaenge schieben — der Deckel der Kategorie darf nicht reissen ═══
/* 30 Karten x 2 Medien = 60 Objekte. Alle auf einmal anzulegen, bevor eines
   frei wird, ist genau der Fall, der die Kategorie sprengt. */
$p = new Spiegelprobe();
$gespiegelt = $p->Spiegle($seite, karten(30));
pruefe('30 Karten mit Anhang werden alle gespiegelt', $gespiegelt, 30);
pruefe('… in mehreren Schueben', count($p->geschrieben) > 1, true);
$hoechststand = 0;
$offen = 0;
foreach ($p->geschrieben as $nr => $g) {
    $hoechststand = max($hoechststand, EduEinpflegen_schub($p, $nr));
}
pruefe('Nie warten mehr als ein Schub neuer Medien auf ihre Freigabe',
    $hoechststand <= 20 + 2, true);

/* Und die Revision zaehlt bei JEDEM Schub weiter — sonst bekaeme ein Geraet,
   das sich `rev` merkt, den zweiten Schub nie zu sehen. */
$revs = array_column($p->geschrieben, 'rev');
pruefe('Jeder Schub traegt eine eigene Revision', $revs === array_unique($revs), true);
pruefe('… und sie steigt', $revs === array_values(array_filter($revs, static function ($r) {
    static $letzte = 0;
    $ok = $r > $letzte;
    $letzte = $r;
    return $ok;
})), true);

// ══ 3. Ein Fehlschlag verliert nur den laufenden Schub ═══════════════════
$p = new Spiegelprobe();
$p->scheitertAb = 2;           // der erste Schub geht durch, der zweite nicht
$gespiegelt = $p->Spiegle($seite, karten(30));
pruefe('Der erste Schub bleibt erhalten', count($p->geschrieben), 1);
pruefe('… und wird als gespiegelt gemeldet, nicht als null', $gespiegelt > 0, true);
pruefe('Der Fehlschlag steht im Protokoll', count($p->meldungen), 1);
/* Die Medien des GESCHEITERTEN Schubs gehoeren niemandem und muessen weg —
   die des ersten nicht, die haengen an geschriebenen Notizen. */
$erhalten = array_values(array_diff($p->angelegt, $p->geloescht));
pruefe('Die Medien des gescheiterten Schubs sind weg',
    count($p->geloescht) > 0 && count($erhalten) === count($p->angelegt) - count($p->geloescht), true);

// ══ 4. Auch die Vorschaubilder des NACHZUGS werden zurueckgenommen ═══════
/* Aufbau: der Bestand kennt die Karte schon in derselben Fassung, ihr Anhang
   hat aber noch kein Vorschaubild. Der Nachzug holt es — und wenn danach das
   Schreiben scheitert, darf es nicht herrenlos liegen bleiben. */
$p = new Spiegelprobe();
$p->scheitertAb = 1;
$p->bestand['notes'][] = [
    'id' => 'n1', 'folderId' => 'f1', 'title' => 'K0', 'text' => 'Text 0',
    'att' => [['id' => 9, 'kind' => 'pdf', 'name' => 'brief0.pdf', 'bytes' => 10,
               'thumb' => 0, 'qr' => '']],
    'createdAt' => 1, 'updatedAt' => 1, 'source' => 'edumaps',
    'srcId' => 'edu:b0', 'srcRev' => 1000, 'srcAt' => 1000,
    'section' => '', 'pos' => 0, 'sectionColor' => '', 'color' => '',
    'html' => '<p>0</p>', 'booking' => null, 'srcUrl' => 'https://x.test/s#box-b0',
];
$p->Spiegle($seite, array_slice(karten(1), 0, 1));
pruefe('Der Nachzug hat ein Vorschaubild angelegt', count($p->angelegt), 1);
pruefe('… und es wird nach dem Fehlschlag wieder geloescht',
    $p->geloescht, $p->angelegt);

/**
 * Wie viele neue Medien hoechstens auf den Schub mit dieser Nummer warteten.
 * Rechnerisch aus der Zahl der Notizen je Schreibvorgang — je Karte zwei.
 */
function EduEinpflegen_schub(Spiegelprobe $p, int $nr): int
{
    $vorher = $nr === 0 ? 0 : (int)$p->geschrieben[$nr - 1]['notizen'];
    return ((int)$p->geschrieben[$nr]['notizen'] - $vorher) * 2;
}

// ══ Der Umschlag aus der Scanner-Spur ════════════════════════════════════
/* Er kommt als DATEI von einer fremden Instanz. Die weisse Liste des Kanals
   prueft nur, dass `seiten` eine Liste von Objekten ist — was DRIN steht,
   prueft niemand ausser dieser Stelle. */
$p = new Spiegelprobe();

pruefe('Kein Objekt — kein Einpflegen', $p->Seite('kaputt'), null);
pruefe('Ohne Adresse kein Einpflegen',
    $p->Seite(['seite' => ['name' => '5b'], 'karten' => [['boxid' => 'b1']]]), null);
/* Die Adresse wird als `srcUrl` an der Karte abgelegt, und die Oberflaeche
   macht daraus einen Verweis — ein fremdes Schema waere fremder Code in der
   App. Gegen Rufe ins eigene Netz schuetzt der Abruf selbst, nicht diese
   Stelle: eine DNS-Abfrage je Seite laege in der Gateway-Spur. */
pruefe('Eine Datei-Adresse wird abgewiesen',
    $p->Seite(['seite' => ['url' => 'file:///etc/passwd'], 'karten' => [['boxid' => 'b1']]]), null);
pruefe('Und ein Skript-Schema erst recht',
    $p->Seite(['seite' => ['url' => 'javascript:alert(1)'], 'karten' => [['boxid' => 'b1']]]), null);
pruefe('Eine Adresse ohne Rechner auch',
    $p->Seite(['seite' => ['url' => 'https:///pfad'], 'karten' => [['boxid' => 'b1']]]), null);

/* Null Karten heisst NICHT „alles archivieren": bricht das Markup der Schule,
   saehe der Archiv-Abgleich jede Karte der Seite als verschwunden an. */
pruefe('Eine Seite ohne Karten wird uebersprungen',
    $p->Seite(['seite' => ['url' => 'https://beispiel.test/s'], 'karten' => []]), null);

/* Eine Karte ohne `boxid` bekaeme die Kennung `edu:` — beim naechsten
   Umschlag faende sie sich selbst wieder und ueberschriebe sich. */
$e = $p->Seite(['seite' => ['url' => 'https://beispiel.test/s', 'name' => '5b', 'userId' => 'u1'],
                'karten' => [['boxid' => ''], ['boxid' => 'b2', 'titel' => 'Kopiergeld'], ['titel' => 'ohne']]]);
pruefe('Karten ohne Kennung fallen weg', count($e[1]), 1);
pruefe('Die uebrige bleibt', $e[1][0]['boxid'], 'b2');
/* Das Spiegeln greift ungeprueft auf diese Felder zu — eine fehlende Zelle
   waere dort eine PHP-Warnung mitten im Hook, und die zerlegt die Antwort. */
pruefe('Die Pflichtfelder sind belegt',
    [$e[1][0]['anhaenge'], $e[1][0]['updated'], $e[1][0]['html'], $e[1][0]['buchung']],
    [[], 0, '', null]);
pruefe('Name und Mitglied kommen mit', [$e[0]['name'], $e[0]['userId']], ['5b', 'u1']);

// ── Gesperrte Seiten ───────────────────────────────────────────────────────
/* Zwischen dem Auftrag und seinem Ergebnis koennen Minuten liegen. Loescht
   jemand in dieser Zeit den Seitenordner in der App, darf die Seite NICHT
   ueber den Umschlag zurueckkommen. Die Sperrliste steht im Bestand des
   Gateways — der Scanner kann sie gar nicht kennen. */
$umschlag = ['quelle' => 'edu', 'status' => ['ok' => true, 'text' => 'Bericht'],
    'seiten' => [[
        'seite'  => ['url' => 'https://beispiel.test/s', 'name' => '5b', 'userId' => 'u1'],
        'karten' => [['boxid' => 'b1', 'updated' => 1, 'titel' => 'K', 'text' => 'T']],
    ]]];

$p = new Spiegelprobe();
$p->gesperrt = ['https://beispiel.test/s'];
pruefe('Eine gesperrte Seite kommt nicht zurueck', $p->Umschlag($umschlag), 0);
pruefe('… und wird auch nicht archiviert', $p->archiv, []);
pruefe('… es wird nichts geschrieben', count($p->geschrieben), 0);

$p = new Spiegelprobe();
pruefe('Eine freie Seite wird gespiegelt', $p->Umschlag($umschlag), 1);
pruefe('… und einmal archiviert', $p->archiv, ['https://beispiel.test/s']);
/* Der Bericht gehoert ins Attribut DIESER Instanz — das Formular liest ihn
   hier. Schriebe der Scanner ihn bei sich, stuende dort dauerhaft
   „Noch nicht nachgesehen". */
pruefe('Der Bericht landet in der Statuszeile',
    json_decode($p->status, true)['text'] ?? '', 'Bericht');

// ══ Die harte Formpruefung der Karten ════════════════════════════════════
/* Vorgaben ZU ERGAENZEN genuegt nicht: der PHP-Plus-Operator laesst einen
   vorhandenen Schluessel unberuehrt, ein FALSCHER Typ bliebe also stehen.
   Und was hier durchkommt, wird gleich darauf ungeprueft benutzt. */
$p = new Spiegelprobe();
function seite(array $karten): array
{
    return ['seite' => ['url' => 'https://beispiel.test/s', 'name' => '5b'], 'karten' => $karten];
}

/* `html` landet ueber den Bestand in einem innerHTML der App. Heute entsteht
   es AUSSCHLIESSLICH in EduHtml — einer Weissliste. Der Umschlagweg darf
   diese eine Pruefstelle nicht umgehen: sonst fuehrt jeder, der die
   Klassenseiten oeffnet, fremden Code aus, mit dem Token der Sitzung. */
$e = $p->Seite(seite([['boxid' => 'b1', 'html' => '<img src=x onerror=alert(1)>']]));
pruefe('Das HTML geht durch die Weissliste', $e[1][0]['html'], '[weissliste]');
pruefe('… und zwar genau einmal', count($p->gefiltert), 1);

/* Eine Zeichenkette statt einer Liste: EduNotizText macht daraus
   `(array)"keine"` = `["keine"]`, und `$a['name']` auf einer Zeichenkette
   wirft in PHP 8. Der Wurf verliesse das Einpflegen ungefangen. */
$e = $p->Seite(seite([['boxid' => 'b1', 'anhaenge' => 'keine']]));
pruefe('Anhaenge als Zeichenkette werden zu einer leeren Liste', $e[1][0]['anhaenge'], []);
$e = $p->Seite(seite([['boxid' => 'b1', 'anhaenge' => ['Elternbrief.pdf', ['name' => 'echt.pdf']]]]));
pruefe('Eintraege ohne Form fallen weg', count($e[1][0]['anhaenge']), 1);
pruefe('Der echte bleibt', $e[1][0]['anhaenge'][0]['name'], 'echt.pdf');

/* Jeder Eintrag kostet einen Abruf von bis zu fuenfzehn Sekunden in der
   Gateway-Spur — auch der, der scheitert. Ohne Deckel haengt die Spur
   stundenlang und die App bekommt keinen Hook mehr beantwortet. */
$viele = array_fill(0, 500, ['name' => 'a.pdf', 'url' => 'https://langsam.test/x']);
$e = $p->Seite(seite([['boxid' => 'b1', 'anhaenge' => $viele]]));
pruefe('Die Anhaenge sind gedeckelt',
    count($e[1][0]['anhaenge']), EduStoreCalc::ATTACH_MAX);

/* Die Kennung wird zu `edu:<boxid>` und traegt die Wiedererkennung UND den
   Sprung auf die Seite (`#box-<boxid>`). */
foreach (['<script>', '../andere', "a\nb", str_repeat('x', 65)] as $boese) {
    $e = $p->Seite(seite([['boxid' => $boese], ['boxid' => 'gut']]));
    pruefe('Eine unsaubere Kennung faellt weg: ' . mb_substr($boese, 0, 12),
        count($e[1]), 1);
}

/* Die Farben gehen in ein style-Attribut. */
$e = $p->Seite(seite([['boxid' => 'b1', 'farbe' => 'red;background:url(x)', 'abschnittFarbe' => '#FFCC00']]));
pruefe('Eine Farbe ist #RRGGBB oder gar nichts',
    [$e[1][0]['farbe'], $e[1][0]['abschnittFarbe']], ['', '#FFCC00']);

$e = $p->Seite(seite([['boxid' => 'b1', 'buchung' => 'voll']]));
pruefe('Eine Buchung ist ein Objekt oder null', $e[1][0]['buchung'], null);

/* Derselbe Deckel, den EduKarten beim Zerlegen zieht. */
$e = $p->Seite(seite(array_map(static fn(int $i): array => ['boxid' => 'b' . $i], range(1, 200))));
pruefe('Die Karten sind gedeckelt', count($e[1]), 60);

// ══ Verlinkte Anlagen und QR-Funde ═══════════════════════════════════════
/* Im Gateway-Weg wertet EduSeiteLesen den ROHEN Seitenrumpf zweimal aus: einmal
   fuer die Karten, einmal fuer Verweise auf ANDERE Anlagen. Der Scanner hat den
   Rumpf, das Gateway nicht — die Funde muessen also mitreisen. Sonst faende
   niemand mehr eine neu verlinkte Klassenseite, ohne Fehler und ohne Meldung.
   Von den Pruefagenten gefunden, 15.09.2026. */
$p = new Spiegelprobe();
$mitFunden = ['quelle' => 'edu', 'status' => ['ok' => true, 'text' => 'x'],
    'seiten' => [[
        'seite'  => ['url' => 'https://beispiel.test/s', 'name' => '5b'],
        'karten' => [['boxid' => 'b1', 'updated' => 1, 'titel' => 'K']],
        'funde'  => ['https://nrw.edumaps.de/1/2/abc/def'],
    ]]];
$p->Umschlag($mitFunden);
pruefe('Ein Verweis aus dem Umschlag wird aufgenommen',
    $p->aufgenommen, [['https://nrw.edumaps.de/1/2/abc/def']]);

/* Dieselbe weisse Liste wie bei der Seitenadresse: der Umschlag ist eine Datei
   einer fremden Instanz, und was hier durchkommt, ruft das Gateway ab. */
$p = new Spiegelprobe();
$p->Umschlag(['quelle' => 'edu', 'status' => ['ok' => true, 'text' => 'x'],
    'seiten' => [[
        'seite'  => ['url' => 'https://beispiel.test/s', 'name' => '5b'],
        'karten' => [['boxid' => 'b1', 'updated' => 1, 'titel' => 'K']],
        'funde'  => ['file:///etc/passwd', 'javascript:alert(1)', 42],
    ]]]);
pruefe('Unbrauchbare Verweise fallen weg', $p->aufgenommen, []);

/* Der QR-Vormerker fuellt sich waehrend des Spiegelns (EduQrCode laeuft beim
   Anlegen eines Anhangs und beim Nachzug). Geleert und abgeholt wird er im
   Gateway-Weg von EduSeiteLesen — und das laeuft nach der Uebergabe nicht
   mehr. Ohne diese Abholung fuellte sich die Liste immer weiter und die
   Adresse im QR-Code kaeme nie an. */
$p = new Spiegelprobe();
$p->qrImAnhang = 'https://nrw.edumaps.de/9/9/qr/code';
$p->Umschlag(['quelle' => 'edu', 'status' => ['ok' => true, 'text' => 'x'],
    'seiten' => [[
        'seite'  => ['url' => 'https://beispiel.test/s', 'name' => '5b'],
        'karten' => [['boxid' => 'b1', 'updated' => 1, 'titel' => 'K',
                      'anhaenge' => [['name' => 'plakat.pdf']]]],
    ]]]);
pruefe('Ein QR-Fund wird abgeholt',
    $p->aufgenommen, [['https://nrw.edumaps.de/9/9/qr/code']]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
