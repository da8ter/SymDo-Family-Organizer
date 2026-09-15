<?php

declare(strict_types=1);

/**
 * Wer bei den Klassenseiten Geld ausgibt — und wann nicht.
 *
 * Seit der Uebergabe an den Scanner gibt es ZWEI Wege zu dieser Entscheidung:
 * den eigenen Lauf (`EduSeiteLesen`) und den Umschlag aus der zweiten Spur
 * (`EduUmschlagEinpflegen`). Beide muessen dieselben Deckel ziehen und denselben
 * Merker fuehren. Zwei Abschriften waeren zwei Politiken, und die teurere faellt
 * erst auf der Rechnung auf — deshalb gibt es genau eine Funktion,
 * `EduKartenAuswerten`, und dieser Prueflauf faehrt sie im Original.
 *
 * Der zweite Teil prueft die Kette des Knopfes „Alles auswerten": er reist mit
 * dem Auftrag hinueber und muss mit dem Ergebnis zurueckkommen. Faellt er auf
 * dem Rueckweg aus der weissen Liste, hat der Knopf nach der Uebergabe
 * dieselbe Wirkung wie „Jetzt pruefen" — und niemand merkt es.
 *
 *   php SymDoGateway/tests/EduAuswertungTest.php
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
require_once __DIR__ . '/../../libs/ScanKanalCalc.php';
require_once __DIR__ . '/../libs/EduMaps.php';

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
    printf("%-4s %-58s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/**
 * Die Attrappe: sie traegt die ECHTE Auswerteschleife aus dem Trait, und alles,
 * was daran Symcon oder Geld beruehrt, ist ein Zaehler. Eine Klasse schlaegt
 * die gleichnamige Methode ihres Traits — nur deshalb geht das.
 */
final class Auswerteprobe
{
    use EduMaps;

    /** @var list<string> Was im Merker steht ("topf|schluessel"). */
    public array $gesehen = [];
    /** @var list<string> Was in DIESEM Lauf neu vermerkt wurde. */
    public array $gemerkt = [];
    /** @var list<string> Welche Karten durch die KI gingen. */
    public array $analysiert = [];
    public bool $deckel = false;    // Tagesdeckel erreicht
    public bool $erfolg = true;     // was die Auswertung meldet

    private function EduTopfHatEintraege(string $topf): bool
    {
        foreach ($this->gesehen as $e) {
            if (str_starts_with($e, $topf . '|')) {
                return true;
            }
        }
        return false;
    }
    private function EduGesehen(string $topf, string $s): bool
    {
        return in_array($topf . '|' . $s, $this->gesehen, true);
    }
    private function EduMerken(string $topf, string $s): void
    {
        $this->gemerkt[] = $s;
        $this->gesehen[] = $topf . '|' . $s;
    }
    private function MailDayLimitReached(): bool { return $this->deckel; }
    private function EduKarteAnalysieren(array $seite, array $karte): bool
    {
        $this->analysiert[] = (string)$karte['boxid'];
        return $this->erfolg;
    }
    private function SendDebug(string $a, string $b, int $c): void {}

    public function Auswerten(array $seite, array $karten, int $schon = 0, bool $alles = false): array
    {
        return $this->EduKartenAuswerten($seite, $karten, $schon, $alles);
    }
    /** Der Deckel je Lauf, wie ihn der Trait kennt. */
    public function JeLaufMax(): int { return self::EDU_JE_LAUF_MAX; }
}

$seite = ['name' => '5b', 'url' => 'https://x.test/s', 'userId' => 'u1'];
function karten(int $n, int $rev = 1000): array
{
    $raus = [];
    for ($i = 1; $i <= $n; $i++) {
        $raus[] = ['boxid' => 'b' . $i, 'updated' => $rev, 'titel' => 'K' . $i,
                   'text' => '', 'abschnitt' => ''];
    }
    return $raus;
}

// ── 1. Der erste Lauf kostet NICHTS ───────────────────────────────────────
/* Sonst stuenden beim Einschalten zwoelf Vorschlaege auf einmal da, und jeder
   kostet Geld. */
$p = new Auswerteprobe();
$erg = $p->Auswerten($seite, karten(4));
pruefe('Erster Lauf: nichts ausgewertet', $p->analysiert, []);
pruefe('Erster Lauf: alles vermerkt',     $p->gemerkt, ['b1:1000', 'b2:1000', 'b3:1000', 'b4:1000']);
pruefe('Erster Lauf: vier als geaendert gezaehlt', $erg['geaendert'], 4);

// ── 2. Unveraendert heisst: gar nichts ────────────────────────────────────
$p->gemerkt = [];
$erg = $p->Auswerten($seite, karten(4));
pruefe('Zweiter Lauf, nichts neu: keine Auswertung', $p->analysiert, []);
pruefe('… und nichts als geaendert gezaehlt',        $erg['geaendert'], 0);

// ── 3. Eine geaenderte Karte geht durch ───────────────────────────────────
/* Der Schluessel traegt den Zeitstempel: eine neue Fassung ist ein neuer
   Schluessel und damit unbekannt. */
$p->gemerkt = [];
$karten = karten(4);
$karten[1]['updated'] = 2000;
$erg = $p->Auswerten($seite, $karten);
pruefe('Geaenderte Karte wird ausgewertet', $p->analysiert, ['b2']);
pruefe('… und danach vermerkt',             $p->gemerkt, ['b2:2000']);
pruefe('… genau eine gilt als geaendert',   [$erg['geaendert'], $erg['analysiert']], [1, 1]);

// ── 4. Eine gescheiterte Auswertung wird NICHT vermerkt ──────────────────
/* Sonst waere die Karte fuer immer als gesehen abgelegt, ohne je ausgewertet
   worden zu sein — der Elternbrief kaeme nie wieder. */
$p->gemerkt = [];
$p->analysiert = [];
$p->erfolg = false;
$karten[2]['updated'] = 3000;
$erg = $p->Auswerten($seite, $karten);
pruefe('Gescheiterte Auswertung: nicht vermerkt', $p->gemerkt, []);
pruefe('… sie zaehlt auch nicht als ausgewertet', $erg['analysiert'], 0);
$p->erfolg = true;

// ── 5. Der Deckel je Lauf ────────────────────────────────────────────────
$p = new Auswerteprobe();
$p->gesehen = ['edu:' . md5($seite['url']) . '|alt:1'];   // nicht mehr der erste Lauf
$max = $p->JeLaufMax();
$erg = $p->Auswerten($seite, karten($max + 3, 5000));
pruefe('Deckel je Lauf greift', count($p->analysiert), $max);
pruefe('… die uebrigen bleiben unvermerkt und kommen wieder',
    $p->gemerkt, array_map(static fn(int $i): string => 'b' . $i . ':5000', range(1, $max)));
pruefe('… geaendert zaehlt trotzdem ALLE', $erg['geaendert'], $max + 3);

/* Der Deckel gilt fuer den DURCHGANG, nicht je Seite: der Umschlag bringt
   mehrere Seiten auf einmal, und der Zaehler laeuft ueber alle. */
$p = new Auswerteprobe();
$p->gesehen = ['edu:' . md5($seite['url']) . '|alt:1'];
$erg = $p->Auswerten($seite, karten(3, 6000), $max - 1);
pruefe('Schon Analysiertes zaehlt mit', count($p->analysiert), 1);

// ── 6. Der Tagesdeckel ───────────────────────────────────────────────────
$p = new Auswerteprobe();
$p->gesehen = ['edu:' . md5($seite['url']) . '|alt:1'];
$p->deckel = true;
$erg = $p->Auswerten($seite, karten(3, 7000));
pruefe('Tagesdeckel: nichts ausgewertet', $p->analysiert, []);
pruefe('… nichts vermerkt (sie kommen wieder)', $p->gemerkt, []);
pruefe('… und der Lauf sagt es',               $erg['gedeckelt'], true);

// ── 7. „Alles auswerten" ─────────────────────────────────────────────────
/* Der Griff von Hand. Er nimmt auch, was schon im Merker steht — sonst waere
   nach dem ersten Lauf, der nur vermerkt, nie etwas auszuwerten. Und er kennt
   den Deckel je Lauf nicht: wer ihn drueckt, will alles. */
$p = new Auswerteprobe();
$erg = $p->Auswerten($seite, karten(4, 8000));         // erster Lauf: nur vermerken
pruefe('Vor „Alles": nichts ausgewertet', $p->analysiert, []);
$p->analysiert = [];
$erg = $p->Auswerten($seite, karten(4, 8000), 0, true);
pruefe('„Alles auswerten" nimmt auch Gemerktes', count($p->analysiert), 4);
pruefe('… auch ueber den Deckel je Lauf hinaus', count($p->analysiert) > $p->JeLaufMax() - 2, true);
$p->deckel = true;
$p->analysiert = [];
$erg = $p->Auswerten($seite, karten(4, 8000), 0, true);
pruefe('… aber NICHT ueber den Tagesdeckel', $p->analysiert, []);

// ── 8. „Alles" ueberlebt den Rueckweg ────────────────────────────────────
/* Die weisse Liste des Kanals laesst nur durch, was sie kennt. Stuende `alles`
   nicht darin, faende der Knopf nach der Uebergabe nie etwas auszuwerten. */
$roh = ['v' => ScanKanalCalc::VERSION, 'quelle' => 'edu', 'gateway' => 77, 'scanner' => 5,
        'at' => time(), 'status' => ['ok' => true, 'text' => '4 Karten gelesen'],
        'anlass' => 'hand', 'alles' => true, 'seiten' => []];
$g = ScanKanalCalc::PruefeErgebnis($roh, 77);
pruefe('Umschlag ist gueltig',            $g['ok'], true);
pruefe('„Alles" kommt zurueck',           $g['umschlag']['alles'] ?? null, true);
$roh['alles'] = false;
pruefe('… und „nein" ebenso',             ScanKanalCalc::PruefeErgebnis($roh, 77)['umschlag']['alles'] ?? null, false);
unset($roh['alles']);
pruefe('Ohne Angabe steht es nicht drin',
    array_key_exists('alles', ScanKanalCalc::PruefeErgebnis($roh, 77)['umschlag']), false);
$roh['alles'] = 'ja';
pruefe('Ein Text wird zu einem Wahrheitswert',
    ScanKanalCalc::PruefeErgebnis($roh, 77)['umschlag']['alles'], true);

// ── 9. Beide Wege gehen durch DIESELBE Tuer ──────────────────────────────
$maps = (string)file_get_contents(__DIR__ . '/../libs/EduMaps.php');
$pfl  = (string)file_get_contents(__DIR__ . '/../libs/EduEinpflegen.php');
$sc   = (string)file_get_contents(__DIR__ . '/../../SymDoScanner/module.php');
$br   = (string)file_get_contents(__DIR__ . '/../libs/ScanBridge.php');
pruefe('Der eigene Lauf ruft die Auswertung',
    str_contains($maps, '$aus = $this->EduKartenAuswerten($seite, $karten, $schonAnalysiert, $alles);'), true);
pruefe('Der Umschlag-Weg auch',
    str_contains($pfl, '$aus = $this->EduKartenAuswerten($seite, $karten, $analysiert, $alles);'), true);
/* Es darf nur EINE Schleife geben, die `EduKarteAnalysieren` ruft. Eine zweite
   waere eine zweite Politik. */
pruefe('Nur eine Stelle ruft die Auswertung einer Karte',
    substr_count($maps . $pfl, '$this->EduKarteAnalysieren('), 1);
/* Die Uhr bleibt beim Gateway: der Scanner hat keine eigene, er arbeitet nur
   ab, was im Kanal liegt. Ein abgeschalteter Zeitgeber liesse die
   Klassenseiten nach genau einem Lauf einschlafen. */
pruefe('Der Zeitgeber wird nach der Uebergabe NICHT abgeschaltet',
    preg_match("/SetTimerInterval\('EduScan', 0\);\s*\n\s*\\\$this->EduAuftragGeben/", $maps), 0);
pruefe('… stattdessen gibt er den Auftrag',
    str_contains($maps, "if (\$this->ScanQuelleUebernommen('edu')) {\n                \$this->EduAuftragGeben('timer');"), true);
pruefe('Der Scanner schickt „alles" zurueck',
    str_contains($sc, "\$nutzlast['alles'] = (\$auftrag['alles'] ?? false) === true;"), true);
pruefe('Die Rolle „Schule" bedient jetzt auch die Klassenseiten',
    str_contains($br, "'schule'   => ['doku', 'edu'],"), true);
/* Der Scanner darf die Merker NICHT anfassen: sie sind Attribute des Gateways,
   und zwei Besitzer hiessen doppelte Auswertung und zurueckgesetzte Haken. */
pruefe('Der Scanner fuehrt keinen eigenen Merker',
    str_contains($sc, 'EduSeen') || str_contains($sc, 'EduMerken') || str_contains($sc, 'EduFound'), false);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
