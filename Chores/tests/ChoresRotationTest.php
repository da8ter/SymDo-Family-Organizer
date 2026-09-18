<?php

declare(strict_types=1);

/**
 * Offline-Prüfstand für den Ämtchenplan: Wochenrechnung, Rotation,
 * Wochenwechsel, Übertrag und Häkchen.
 *
 * Läuft ohne Symcon gegen die Symcon-Stubs (symcon/module-tests), die im
 * Nachbar-Repo unter TileVisu-Raum-Titel-Kachel/tests/stubs liegen — anderer
 * Pfad über SYMCON_STUBS.
 *
 * Die Rotation ist reine Arithmetik, und genau dort wohnen die Fehler:
 * Zeitumstellung, Jahreswechsel mit 53 Wochen, Pause ohne Phasenverschiebung,
 * Rücknahme nach Halterwechsel. Wer die Rotation ändert, sieht hier zuerst,
 * was kippt.
 *
 *   php Chores/tests/ChoresRotationTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/ChoreStore.php';

IPS\Kernel::reset();
date_default_timezone_set('Europe/Berlin');

/** Die Mitgliederauskunft des Gateways als Attrappe. */
$GLOBALS['gatewayUsers'] = [];
if (!function_exists('TGW_GetUsers')) {
    function TGW_GetUsers(int $InstanceID): string
    {
        return (string)json_encode($GLOBALS['gatewayUsers']);
    }
}

/**
 * Der Ämtchenplan auf das Rechenwerk reduziert. Konfiguration, Attribute und
 * die Gateway-Auskunft kommen aus Feldern; Klassenmethoden schlagen
 * Trait-Methoden, genau wie im Prüfstand des Sprachdialogs.
 */
class ChoresHarness extends IPSModuleStrict
{
    use ChoreStore;

    public array $cfg = [];
    public array $attr = ['Week' => '{}', 'LastWeek' => '{}', 'PurseMirror' => '{}', 'Shift' => 0];
    public array $rollen = [];
    public array $namen = [];
    public array $log = [];

    private function Konfiguration(): array
    {
        return $this->cfg;
    }

    public function Translate(string $Text): string
    {
        static $de = null;
        if ($de === null) {
            $j = json_decode((string)file_get_contents(__DIR__ . '/../locale.json'), true);
            $de = is_array($j) ? ($j['translations']['de'] ?? []) : [];
        }
        return (string)($de[$Text] ?? $Text);
    }

    protected function SendDebug(string $Message, string $Data, int $Format): bool
    {
        $this->log[] = $Message . ': ' . $Data;
        return true;
    }

    protected function ReadAttributeString(string $Name): string
    {
        return (string)($this->attr[$Name] ?? '');
    }

    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        $this->attr[$Name] = $Value;
        return true;
    }

    protected function ReadAttributeInteger(string $Name): int
    {
        return (int)($this->attr[$Name] ?? 0);
    }

    protected function WriteAttributeInteger(string $Name, int $Value): bool
    {
        $this->attr[$Name] = $Value;
        return true;
    }

    private function Rollen(): array
    {
        return $this->rollen;
    }

    private function Mitglieder(): array
    {
        $raus = [];
        foreach ($this->namen as $id => $name) {
            $raus[$id] = ['name' => $name, 'avatar' => '', 'persona' => (string)($this->rollen[$id] ?? '')];
        }
        return $raus;
    }

    private function UebernehmenNachtragen(): void
    {
    }

    /* Die Auswahl „Wer" fragt das Gateway ueber die Praefix-Funktion; im
       Pruefstand steht die Instanz fest und die Auskunft kommt aus $namen. */
    private function GatewayInstanz(): int
    {
        return 1;
    }

    // Zugänge für den Prüflauf
    public function pWochenStart(string $d, int $tag): string { return $this->WochenStart($d, $tag); }
    public function pWochenKennung(int $t): string { return $this->WochenKennung($t); }
    public function pWochenIndex(string $w): int { return $this->WochenIndex($w); }
    public function pWechselMs(int $t): int { return $this->NaechsterWechselMs($t); }
    public function pKreis(): array { return $this->Kreis(); }
    public function pZuweisung(int $i): array { return $this->Zuweisung($i, $this->rollen); }
    public function pWoche(int $t): array { return $this->WocheSicherstellen($t); }
    public function pAbhaken(string $w, string $c, string $s, bool $z, int $t): bool
    {
        return $this->Abhaken($w, $c, $s, $z, $t);
    }
    public function pPayload(int $t): array { return $this->PayloadBauen($t); }
    public function pVorschau(int $t, int $n): array { return $this->Vorschau($t, $n); }
    public function pPlaetze(array $w, array $a): array { return $this->PlaetzeFuer($w, $a); }
    public function pAemtchen(): array { return $this->AemtchenLesen(); }
    public function pZukunft(string $w, int $sp, int $t): bool { return $this->InDerZukunft($w, $sp, $t); }
    public function pHeuteSpalte(string $w, int $t): int { return $this->SpalteHeute($w, $t); }
    public function pMitgliederOptionen(): array { return $this->MitgliederOptionen(); }
}

// ── Prüfgerüst ──────────────────────────────────────────────────────────────
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
    printf("%s %-52s%s\n", $ok ? 'OK  ' : 'FEHL', $name, $ok ? '' : "  ist: $a  soll: $b");
}

function harness(array $teilnehmer, array $aemtchen, array $rest = []): ChoresHarness
{
    $h = new ChoresHarness(12345);
    $h->cfg = array_merge([
        'Members'   => json_encode($teilnehmer),
        'Chores'    => json_encode($aemtchen),
        'WeekStart' => 1,
        'ResetTime' => '{"hour":3,"minute":0,"second":0}',
        'CarryOver' => false,
        'ShowNextWeek' => true,
        'ShowLastWeek' => true,
    ], $rest);
    $h->rollen = ['a' => 'child', 'b' => 'child', 'c' => 'mother', 'd' => 'father'];
    $h->namen  = ['a' => 'Anna', 'b' => 'Ben', 'c' => 'Clara', 'd' => 'Dirk'];
    return $h;
}

$T = static fn(string $s): int => (int)strtotime($s);

// ── 1. Wochenstart, immer nach hinten ───────────────────────────────────────
$h = harness([], []);
foreach (['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13'] as $d) {
    pruefe("Wochenstart (Mo) ab $d", $h->pWochenStart($d, 1), '2026-09-07');
}
// Mit Sonntag als erstem Wochentag darf der Start NIE in der Zukunft liegen.
pruefe('Wochenstart (So) ab Mo 07.09.', $h->pWochenStart('2026-09-07', 7), '2026-09-06');
pruefe('Wochenstart (So) ab So 06.09.', $h->pWochenStart('2026-09-06', 7), '2026-09-06');
pruefe('Wochenstart (So) ab Sa 12.09.', $h->pWochenStart('2026-09-12', 7), '2026-09-06');

// ── 2. Wochenkennung mit Wechselzeit ────────────────────────────────────────
pruefe('So 23:00 gehoert zur alten Woche', $h->pWochenKennung($T('2026-09-13 23:00')), '2026-09-07');
pruefe('Mo 02:59 gehoert zur alten Woche', $h->pWochenKennung($T('2026-09-14 02:59')), '2026-09-07');
pruefe('Mo 03:01 ist die neue Woche', $h->pWochenKennung($T('2026-09-14 03:01')), '2026-09-14');

// ── 3./4. Wochenindex, auch ueber Umstellung und KW 53 ──────────────────────
pruefe('Index waechst um 1 (Maerz, Umstellung)',
    $h->pWochenIndex('2026-03-30') - $h->pWochenIndex('2026-03-23'), 1);
pruefe('Index waechst um 1 (Oktober, Umstellung)',
    $h->pWochenIndex('2026-10-26') - $h->pWochenIndex('2026-10-19'), 1);
pruefe('Index waechst um 1 ueber den Jahreswechsel',
    $h->pWochenIndex('2027-01-04') - $h->pWochenIndex('2026-12-28'), 1);
pruefe('Index laeuft 52 Wochen lang um 52',
    $h->pWochenIndex('2027-09-06') - $h->pWochenIndex('2026-09-07'), 52);
pruefe('Index vor der Epoche ist negativ und schrittweise',
    $h->pWochenIndex('2023-12-18') - $h->pWochenIndex('2023-12-25'), -1);

// ── 5. Nächster Wechsel ─────────────────────────────────────────────────────
$ms = $h->pWechselMs($T('2026-09-09 12:00'));
pruefe('Wechsel liegt in der Zukunft und binnen 7 Tagen', $ms >= 60000 && $ms <= 7 * 86400000, true);
pruefe('Wechsel trifft Montag 03:00',
    date('D H:i', $T('2026-09-09 12:00') + (int)($ms / 1000)), 'Mon 03:00');
$msNacht = $h->pWechselMs($T('2026-10-25 12:00'));
pruefe('Wechsel trifft 03:00 auch nach der Umstellung',
    date('D H:i', $T('2026-10-25 12:00') + (int)($msNacht / 1000)), 'Mon 03:00');

// ── 6. Kreis, in Runden verzahnt ────────────────────────────────────────────
$h = harness([
    ['memberId' => 'a', 'share' => 2, 'pause' => false],
    ['memberId' => 'b', 'share' => 1, 'pause' => false],
    ['memberId' => 'c', 'share' => 1, 'pause' => false],
], []);
pruefe('Kreis A(2),B,C ist verzahnt', $h->pKreis(), ['a', 'b', 'c', 'a']);

// ── 7. Drei Teilnehmer, drei Ämtchen ────────────────────────────────────────
$drei = [
    ['memberId' => 'a', 'share' => 1, 'pause' => false],
    ['memberId' => 'b', 'share' => 1, 'pause' => false],
    ['memberId' => 'c', 'share' => 1, 'pause' => false],
];
$aemtchen3 = [
    ['id' => 'm1', 'name' => 'Muell', 'perWeek' => 1, 'circle' => 'all'],
    ['id' => 'm2', 'name' => 'Tisch', 'perWeek' => 1, 'circle' => 'all'],
    ['id' => 'm3', 'name' => 'Staub', 'perWeek' => 1, 'circle' => 'all'],
];
$h = harness($drei, $aemtchen3);
$w0 = $h->pZuweisung(0);
pruefe('drei Aemtchen, drei verschiedene Personen', count(array_unique(array_values($w0))), 3);
$last = ['a' => 0, 'b' => 0, 'c' => 0];
for ($i = 0; $i < 3; $i++) {
    foreach ($h->pZuweisung($i) as $wer) {
        $last[$wer]++;
    }
}
pruefe('ueber drei Wochen genau gleich verteilt', $last, ['a' => 3, 'b' => 3, 'c' => 3]);
pruefe('jedes Aemtchen einmal je Person in drei Wochen',
    array_map(static fn(string $id): int => count(array_unique([
        $h->pZuweisung(0)[$id], $h->pZuweisung(1)[$id], $h->pZuweisung(2)[$id],
    ])), ['m1' => 'm1', 'm2' => 'm2', 'm3' => 'm3']),
    ['m1' => 3, 'm2' => 3, 'm3' => 3]);

// ── 8. Zwei Teilnehmer, drei Ämtchen ────────────────────────────────────────
$h = harness([
    ['memberId' => 'a', 'share' => 1, 'pause' => false],
    ['memberId' => 'b', 'share' => 1, 'pause' => false],
], $aemtchen3);
$zaehl = ['a' => 0, 'b' => 0];
for ($i = 0; $i < 2; $i++) {
    foreach ($h->pZuweisung($i) as $wer) {
        $zaehl[$wer]++;
    }
}
pruefe('zwei Personen, drei Aemtchen: ueber zwei Wochen 3 zu 3', $zaehl, ['a' => 3, 'b' => 3]);

// ── 9. Pause verschiebt die Reihe der anderen NICHT ─────────────────────────
$ohne = harness($drei, $aemtchen3);
$mitPause = harness([
    ['memberId' => 'a', 'share' => 1, 'pause' => false],
    ['memberId' => 'b', 'share' => 1, 'pause' => true],
    ['memberId' => 'c', 'share' => 1, 'pause' => false],
], $aemtchen3);
$zuOhne = $ohne->pZuweisung(5);
$zuMit  = $mitPause->pZuweisung(5);
$gleich = true;
foreach ($zuOhne as $id => $wer) {
    if ($wer !== 'b' && $zuMit[$id] !== $wer) {
        $gleich = false;
    }
}
pruefe('Pause laesst die anderen an ihrem Platz', $gleich, true);
pruefe('das Aemtchen des Pausierten geht weiter', in_array('b', $zuMit, true), false);

// ── 10. Alle in Pause ───────────────────────────────────────────────────────
$h = harness([
    ['memberId' => 'a', 'share' => 1, 'pause' => true],
    ['memberId' => 'b', 'share' => 1, 'pause' => true],
], $aemtchen3);
pruefe('alle in Pause: niemand ist dran', array_values($h->pZuweisung(3)), ['', '', '']);

// ── 11. Last fair verteilt ──────────────────────────────────────────────────
/* Gemessen über 60 Wochen, ein Vielfaches von 2, 3, 4 und 5: nur über einen
   VOLLEN Umlauf des Kreises kann die Verteilung aufgehen. Innerhalb eines
   angebrochenen Umlaufs darf sie um bis zu die Zahl der Ämtchen abweichen —
   das ist Arithmetik und kein Fehler (5 Personen, 3 Ämtchen, 12 Wochen ergibt
   36 Zuweisungen, und 36 ist durch 5 nicht teilbar). */
foreach ([2, 3, 4, 5] as $n) {
    $leute = [];
    for ($i = 0; $i < $n; $i++) {
        $leute[] = ['memberId' => chr(97 + $i), 'share' => 1, 'pause' => false];
    }
    $h = harness($leute, $aemtchen3);
    $h->rollen = [];
    $zaehl = array_fill_keys(array_column($leute, 'memberId'), 0);
    for ($w = 0; $w < 60; $w++) {
        foreach ($h->pZuweisung($w) as $wer) {
            $zaehl[$wer]++;
        }
    }
    pruefe("Kreisgroesse $n: ueber 60 Wochen genau gleich", max($zaehl) - min($zaehl), 0);
    pruefe("Kreisgroesse $n: jeder kommt vor", min($zaehl) > 0, true);
}

// ── 12. Kinder-Ämtchen erreicht keinen Erwachsenen ──────────────────────────
$h = harness([
    ['memberId' => 'a', 'share' => 1, 'pause' => false],
    ['memberId' => 'b', 'share' => 1, 'pause' => false],
    ['memberId' => 'c', 'share' => 1, 'pause' => false],
    ['memberId' => 'd', 'share' => 1, 'pause' => false],
], [
    ['id' => 'k1', 'name' => 'Spuelmaschine', 'perWeek' => 1, 'circle' => 'child'],
    ['id' => 'e1', 'name' => 'Rasen', 'perWeek' => 1, 'circle' => 'adult'],
    ['id' => 'f1', 'name' => 'Muell', 'perWeek' => 1, 'circle' => 'd'],
]);
$kinderTreffer = [];
$erwTreffer = [];
$festTreffer = [];
for ($w = 0; $w < 8; $w++) {
    $z = $h->pZuweisung($w);
    $kinderTreffer[$z['k1']] = true;
    $erwTreffer[$z['e1']] = true;
    $festTreffer[$z['f1']] = true;
}
pruefe('Kinder-Aemtchen nur an Kinder', array_keys($kinderTreffer), ['a', 'b']);
pruefe('Erwachsenen-Aemtchen nur an Erwachsene', array_keys($erwTreffer), ['c', 'd']);
pruefe('festes Aemtchen rotiert nicht', array_keys($festTreffer), ['d']);

// ── 13./21./22. Woche einfrieren, nachtragen, Waisen ────────────────────────
$h = harness($drei, $aemtchen3);
$jetzt = $T('2026-09-09 12:00');
$woche = $h->pWoche($jetzt);
pruefe('Woche eingefroren', $woche['week'], '2026-09-07');
$eingefroren = $woche['assign'];
// Ein vierter Teilnehmer kommt dazu: die LAUFENDE Woche bleibt, wie sie war.
$h->cfg['Members'] = json_encode(array_merge($drei, [['memberId' => 'd', 'share' => 1, 'pause' => false]]));
pruefe('laufende Woche unveraendert', $h->pWoche($jetzt)['assign'], $eingefroren);
/* Über die nächsten vier Wochen, nicht in genau einer: mit vier Personen und
   drei Ämtchen ist der Neue nicht in jeder Woche dran — welche Woche ihn
   trifft, hängt am Wochenindex. */
$vorschauNamen = [];
foreach ($h->pVorschau($jetzt, 4) as $w) {
    foreach (array_values($w['assign']) as $wer) {
        $vorschauNamen[$wer] = true;
    }
}
pruefe('naechste Wochen kennen den Neuen', isset($vorschauNamen['d']), true);
// Ämtchen mitten in der Woche angelegt
$h->cfg['Chores'] = json_encode(array_merge($aemtchen3,
    [['id' => 'm4', 'name' => 'Katze', 'perWeek' => 1, 'circle' => 'all']]));
$nach = $h->pWoche($jetzt);
pruefe('neues Aemtchen bekommt sofort einen Zustaendigen', $nach['assign']['m4'] !== '', true);
pruefe('alte Zuweisungen bleiben stehen', $nach['assign']['m1'], $eingefroren['m1']);
// Ämtchen gelöscht → Waise verschwindet
$h->cfg['Chores'] = json_encode($aemtchen3);
pruefe('Waise verschwindet aus der Zuweisung',
    array_key_exists('m4', $h->pWoche($jetzt)['assign']), false);

// ── 14. Wochenwechsel ───────────────────────────────────────────────────────
$h = harness($drei, $aemtchen3);
$h->pWoche($T('2026-09-09 12:00'));
$h->pAbhaken('2026-09-07', 'm1', '0', true, $T('2026-09-09 12:00'));
$neu = $h->pWoche($T('2026-09-16 12:00'));
pruefe('nach dem Wechsel sind die Haekchen weg', $neu['done'], []);
pruefe('die Vorwoche ist gemerkt', json_decode($h->attr['LastWeek'], true)['week'], '2026-09-07');

// ── 15. Übertrag ────────────────────────────────────────────────────────────
$h = harness($drei, [['id' => 'm1', 'name' => 'Muell', 'perWeek' => 2, 'circle' => 'all']],
    ['CarryOver' => true]);
$w1 = $h->pWoche($T('2026-09-09 12:00'));
$halter = $w1['assign']['m1'];
$h->pAbhaken('2026-09-07', 'm1', '0', true, $T('2026-09-09 12:00'));
$w2 = $h->pWoche($T('2026-09-16 12:00'));
pruefe('ein offener Platz wandert als Uebertrag mit', $w2['carry']['m1']['count'], 1);
pruefe('der Uebertrag bleibt beim ALTEN Halter', $w2['carry']['m1']['memberId'], $halter);
$w3 = $h->pWoche($T('2026-09-23 12:00'));
pruefe('Uebertraege stapeln sich nicht', $w3['carry']['m1']['count'], 2);
$hOhne = harness($drei, [['id' => 'm1', 'name' => 'Muell', 'perWeek' => 2, 'circle' => 'all']]);
$hOhne->pWoche($T('2026-09-09 12:00'));
pruefe('ohne Uebertrag bleibt nichts stehen', $hOhne->pWoche($T('2026-09-16 12:00'))['carry'], []);

/* Der Schalter wirkt SOFORT, auch auf eine Woche, die den Uebertrag schon
   eingefroren traegt: wer ihn ausschaltet, will die alten Kreise nicht mehr
   sehen. Geloescht wird dabei nichts — wieder eingeschaltet, sind sie zurueck. */
$a1 = $h->pAemtchen()[0];
$plaetze = $h->pPlaetze($w3, $a1);
$mitUebertrag = count(array_filter($plaetze, fn ($p) => ($p['carried'] ?? false) === true));
pruefe('mit Schalter: die Uebertraege stehen in der Woche', $mitUebertrag, 2);
$h->cfg['CarryOver'] = false;
$plaetze = $h->pPlaetze($w3, $a1);
pruefe('ohne Schalter: kein einziger Uebertrag mehr',
    count(array_filter($plaetze, fn ($p) => ($p['carried'] ?? false) === true)), 0);
pruefe('… die regulaeren Plaetze bleiben', count($plaetze), 2);
$h->cfg['CarryOver'] = true;
pruefe('wieder eingeschaltet: die Uebertraege sind zurueck',
    count(array_filter($h->pPlaetze($w3, $a1), fn ($p) => ($p['carried'] ?? false) === true)), 2);

// ── 16.–19. Häkchen ─────────────────────────────────────────────────────────
$h = harness($drei, $aemtchen3);
$jetzt = $T('2026-09-09 12:00');
$w = $h->pWoche($jetzt);
$wer = $w['assign']['m1'];
pruefe('Abhaken meldet Erfolg', $h->pAbhaken('2026-09-07', 'm1', '0', true, $jetzt), true);
/* Gespeichert wird, WER es getan hat — und seit dem 18.09.2026 wieder, was es
   ihm an Muenzen gebracht hat (nur Kinder; sonst 0). */
$erledigt = json_decode($h->attr['Week'], true)['done']['m1']['0'];
pruefe('der Haken merkt sich den Halter', $erledigt['m'], $wer);
pruefe('… und die Muenzen, sonst nichts', array_keys($erledigt), ['m', 'p']);
pruefe('doppeltes Abhaken bleibt erfolgreich',
    $h->pAbhaken('2026-09-07', 'm1', '0', true, $jetzt), true);
/* Halterwechsel per Handkurbel: der Haken bleibt beim GEMERKTEN Halter. */
$h = harness($drei, $aemtchen3);
$w = $h->pWoche($jetzt);
$alterHalter = $w['assign']['m1'];
$h->pAbhaken('2026-09-07', 'm1', '0', true, $jetzt);
$h->attr['Week'] = json_encode(array_merge($h->pWoche($jetzt), ['assign' => array_merge($w['assign'], ['m1' => 'c'])]));
$plaetze = $h->pPlaetze($h->pWoche($jetzt), $h->pAemtchen()[0]);
pruefe('der abgehakte Platz behaelt seinen Halter', $plaetze[0]['memberId'], $alterHalter);
pruefe('Zuruecknehmen geht', $h->pAbhaken('2026-09-07', 'm1', '0', false, $jetzt), true);
// Veraltete Wochenkennung
$h = harness($drei, $aemtchen3);
$h->pWoche($jetzt);
pruefe('Haekchen mit alter Wochenkennung wird verworfen',
    $h->pAbhaken('2026-08-31', 'm1', '0', true, $jetzt), false);
pruefe('… und setzt auch nichts', $h->pWoche($jetzt)['done'], []);
// ── 20. Unbekanntes Ämtchen, unbekannter Platz
pruefe('unbekanntes Aemtchen bleibt wirkungslos',
    $h->pAbhaken('2026-09-07', 'gibtsnicht', '0', true, $jetzt), false);
pruefe('unbekannter Platz bleibt wirkungslos',
    $h->pAbhaken('2026-09-07', 'm1', '9', true, $jetzt), false);

// ── 24. Nutzlast ────────────────────────────────────────────────────────────
$h = harness($drei, $aemtchen3);
$p = $h->pPayload($jetzt);
pruefe('Nutzlast ist ein Zustand', $p['type'], 'state');
pruefe('Nutzlast nennt die Woche', $p['week'], '2026-09-07');
pruefe('Nutzlast hat alle Aemtchen', count($p['chores']), 3);
pruefe('Nutzlast hat die Teilnehmer in Reihenfolge', $p['order'], ['a', 'b', 'c']);
pruefe('Fortschritt beginnt bei null', $p['progress'], 0);
$h->pAbhaken('2026-09-07', 'm1', '0', true, $jetzt);
pruefe('Fortschritt nach einem von drei', $h->pPayload($jetzt)['progress'], 33);
/* Rueckblick und Vorschau reisen NICHT mehr mit — die Kachel zeigt nur die
   laufende Woche. Wer in die Zukunft sehen will, fragt CHR_Preview(), und das
   kann sie weiterhin (siehe Abschnitt Vorschau weiter oben). */
pruefe('Nutzlast traegt keine Vorschau mehr', isset($p['next']), false);
pruefe('Nutzlast traegt keinen Rueckblick mehr', isset($p['last']), false);
// Träger Nachhol-Pfad: eine Woche später zeichnet die Kachel und rollt dabei um
$spaeter = $h->pPayload($T('2026-09-16 12:00'));
pruefe('Zeichnen holt den Wochenwechsel nach', $spaeter['week'], '2026-09-14');
pruefe('… und die Haekchen sind weg', $spaeter['progress'], 0);

// ── 25. Handkurbel ──────────────────────────────────────────────────────────
$h = harness($drei, $aemtchen3);
$w = $h->pWoche($jetzt);
$vorher = $w['assign'];
$h->attr['Shift'] = 1;
pruefe('Handkurbel laesst die laufende Woche in Ruhe', $h->pWoche($jetzt)['assign'], $vorher);
$hNeu = harness($drei, $aemtchen3);
$hNeu->attr['Shift'] = 1;
pruefe('Handkurbel verschiebt die naechste Woche',
    $hNeu->pZuweisung(100) !== harness($drei, $aemtchen3)->pZuweisung(100), true);

// ── 26. Tagesspalten ────────────────────────────────────────────────────────
// Der 16.09.2026 ist ein Mittwoch; die Woche beginnt am Montag, also Spalte 2.
$mi = $T('2026-09-16 12:00');

/* Zeilen aus der Zeit VOR den Tagesspalten tragen keinen Haken. Fuer sie gilt
   die alte Angabe „n-mal pro Woche": sie bekommen die ersten n Tage. Ohne
   diesen Rueckfall saehe ein bestehender Plan nach dem Update anders aus. */
$altModus = [
    ['id' => 'a1', 'name' => 'Muell', 'emoji' => '', 'perWeek' => 2, 'circle' => 'all'],
];
$h = harness($drei, $altModus);
pruefe('alte Zeile ohne Tage: die ersten n Tage', $h->pAemtchen()[0]['days'], [1, 2]);

/* Mit Haken zaehlen die Haken — und „pro Woche" ist dann nur noch ihre Anzahl. */
$mitTagen = [
    ['id' => 'a1', 'name' => 'Muell', 'emoji' => '', 'perWeek' => 9,
     'circle' => 'all', 'd1' => true, 'd4' => true, 'd7' => true],
];
$h = harness($drei, $mitTagen);
pruefe('gehakte Tage schlagen die alte Zahl', $h->pAemtchen()[0]['days'], [1, 4, 7]);
pruefe('… und bestimmen, wie oft', $h->pAemtchen()[0]['perWeek'], 3);

/* Die Spalte ist der Abstand zum Wochenstart. Beginnt die Woche am SONNTAG,
   steht der Sonntag links und der Montag daneben — die Reihenfolge der Tage
   folgt der Anzeige, nicht der ISO-Nummer. */
$h = harness($drei, $mitTagen, ['WeekStart' => 7]);
pruefe('Woche ab Sonntag: Sonntag zuerst', $h->pAemtchen()[0]['days'], [7, 1, 4]);
$plaetze = $h->pPlaetze($h->pWoche($mi), $h->pAemtchen()[0]);
pruefe('… und die Spalten zaehlen ab Sonntag',
    array_column($plaetze, 'col'), [0, 1, 4]);

/* Was noch nicht dran war, kann nicht erledigt sein. */
$h = harness($drei, $mitTagen);
pruefe('Montag ist vorbei', $h->pZukunft('2026-09-14', 0, $mi), false);
pruefe('Mittwoch ist heute', $h->pZukunft('2026-09-14', 2, $mi), false);
pruefe('Donnerstag steht aus', $h->pZukunft('2026-09-14', 3, $mi), true);
pruefe('heute steht in Spalte 2', $h->pHeuteSpalte('2026-09-14', $mi), 2);
/* Vergangene Wochen sind ganz frei — dort wird nachgetragen. Kuenftige ganz zu. */
pruefe('vergangene Woche: alles nachtragbar', $h->pZukunft('2026-09-07', 6, $mi), false);
pruefe('kuenftige Woche: nichts abhakbar', $h->pZukunft('2026-09-21', 0, $mi), true);

/* Und die Sperre gilt auch, wenn die Anfrage am Hahn vorbei kommt: Platz „1"
   ist der Donnerstag, der steht am Mittwoch noch aus. */
$h = harness($drei, $mitTagen);
$w = $h->pWoche($mi);
pruefe('Abhaken am Montag geht', $h->pAbhaken($w['week'], 'a1', '0', true, $mi), true);
pruefe('Abhaken am Donnerstag wird abgewiesen',
    $h->pAbhaken($w['week'], 'a1', '1', true, $mi), false);
pruefe('… und der Donnerstag bleibt offen',
    $h->pPlaetze($h->pWoche($mi), $h->pAemtchen()[0])[1]['done'], false);

/* Eine Kennung aus lauter Ziffern darf die Auswahl nicht zerlegen.
   PHP macht aus einem solchen ARRAY-SCHLUESSEL eine Zahl; ohne Ruecknahme in
   eine Zeichenkette trug die Auswahl eine Zahl, der strenge Vergleich griff
   nicht, und das Formular haengte eine zweite Zeile „57648139 (nicht
   gefunden)" an — obwohl genau dieses Mitglied eine Zeile darueber stand. */
$GLOBALS['gatewayUsers'] = [
    ['id' => '57648139', 'name' => 'Tim',  'persona' => 'child'],
    ['id' => 'fa0ad897', 'name' => 'Mia',  'persona' => 'child'],
    ['id' => '1e4e8bac', 'name' => 'Anna', 'persona' => 'mother'],
];
$h = harness($drei, $mitTagen);
$h->cfg['Members'] = json_encode([
    ['memberId' => '57648139'], ['memberId' => 'fa0ad897'], ['memberId' => '1e4e8bac'],
]);
$optionen = $h->pMitgliederOptionen();
$werte = array_column($optionen, 'value');
pruefe('Auswahl hat vier Zeilen (— keins — und drei Mitglieder)', count($optionen), 4);
pruefe('die Ziffern-Kennung steht genau einmal drin',
    count(array_filter($werte, fn ($v) => $v === '57648139')), 1);
pruefe('… und zwar als Zeichenkette',
    array_sum(array_map(fn ($v) => is_string($v) ? 0 : 1, $werte)), 0);
pruefe('keine Zeile „(nicht gefunden)"',
    count(array_filter($optionen, fn ($o) => str_contains((string)$o['caption'], 'nicht gefunden'))), 0);
/* Gegenprobe: eine Kennung, die das Gateway wirklich nicht kennt, MUSS
   auftauchen — sonst verschwaende eine Zuordnung unbemerkt. */
$h->cfg['Members'] = json_encode([
    ['memberId' => '57648139'], ['memberId' => 'fa0ad897'],
    ['memberId' => '1e4e8bac'], ['memberId' => 'abgemeldet'],
]);
$optionen = $h->pMitgliederOptionen();
pruefe('unbekannte Kennung wird ehrlich gezeigt',
    count(array_filter($optionen, fn ($o) => str_contains((string)$o['caption'], 'nicht gefunden'))), 1);

// ── Wechsel innerhalb der Woche ─────────────────────────────────────────────
/* „Woechentlich" heisst: eine Person traegt das Aemtchen an allen ihren Tagen.
   „Taeglich" heisst: der Kreis rueckt an jedem dieser Tage weiter — angesetzt
   an der Person, die diese Woche ohnehin dran waere. */
$vier = [
    ['memberId' => 'a', 'share' => 1, 'pause' => false],
    ['memberId' => 'b', 'share' => 1, 'pause' => false],
    ['memberId' => 'c', 'share' => 1, 'pause' => false],
    ['memberId' => 'd', 'share' => 1, 'pause' => false],
];
$vierTage = ['d1' => true, 'd2' => true, 'd3' => true, 'd4' => true];
$woechentlich = [array_merge(['id' => 't1', 'name' => 'Tisch', 'circle' => 'all',
    'rotate' => 'week'], $vierTage)];
$taeglich = [array_merge(['id' => 't1', 'name' => 'Tisch', 'circle' => 'all',
    'rotate' => 'day'], $vierTage)];

$h = harness($vier, $woechentlich);
$w = $h->pWoche($mi);
$traeger = array_column($h->pPlaetze($w, $h->pAemtchen()[0]), 'memberId');
pruefe('woechentlich: vier Tage, eine Person', count(array_unique($traeger)), 1);

$h = harness($vier, $taeglich);
$w = $h->pWoche($mi);
$a = $h->pAemtchen()[0];
$traeger = array_column($h->pPlaetze($w, $a), 'memberId');
pruefe('taeglich: vier Tage, vier verschiedene Personen', count(array_unique($traeger)), 4);
pruefe('taeglich: der erste Tag gehoert dem, der die Woche traegt',
    $traeger[0], $w['assign']['t1']);
/* Die Reihe folgt dem Kreis a,b,c,d — angesetzt an der Person dieser Woche.
   Welche das ist, sagt der Wochenindex; die Probe rechnet deshalb relativ. */
$kreis = ['a', 'b', 'c', 'd'];
$ab = array_search($w['assign']['t1'], $kreis, true);
$erwartet = [];
for ($i = 0; $i < 4; $i++) { $erwartet[] = $kreis[($ab + $i) % 4]; }
pruefe('taeglich: die Reihe folgt dem Kreis', $traeger, $erwartet);

/* Nur Kinder: die Reihe bleibt IM Kreis, sie faellt nicht auf die Eltern. */
$nurKinder = [array_merge(['id' => 't1', 'name' => 'Tisch', 'circle' => 'child',
    'rotate' => 'day'], ['d1' => true, 'd2' => true, 'd3' => true,
    'd4' => true, 'd5' => true, 'd6' => true, 'd7' => true])];
$h = harness($vier, $nurKinder);
$w = $h->pWoche($mi);
$traeger = array_column($h->pPlaetze($w, $h->pAemtchen()[0]), 'memberId');
pruefe('taeglich, nur Kinder: sieben Tage', count($traeger), 7);
pruefe('… und darin nur die beiden Kinder',
    array_values(array_diff(array_unique($traeger), ['a', 'b'])), []);
$wechselt = true;
for ($i = 1; $i < count($traeger); $i++) {
    if ($traeger[$i] === $traeger[$i - 1]) { $wechselt = false; }
}
pruefe('… und jeden Tag ein anderes', $wechselt, true);
pruefe('… also viermal das eine, dreimal das andere Kind',
    [count(array_keys($traeger, $traeger[0], true)),
     count(array_keys($traeger, $traeger[1], true))], [4, 3]);

// ── Wer nicht mehr in den Kreis gehoert, wird ersetzt ───────────────────────
/* Die laufende Woche ist eingefroren — aber „eingefroren" heisst nicht, dass
   die Regel nicht mehr gilt. Wird „Wer" mitten in der Woche auf „Nur Kinder"
   gestellt, darf die Mutter das Aemtchen nicht bis Sonntag behalten. */
$offen = [array_merge(['id' => 't1', 'name' => 'Tisch', 'circle' => 'all',
    'rotate' => 'week'], $vierTage)];
$h = harness($vier, $offen);
$w = $h->pWoche($mi);
$h->attr['Week'] = json_encode(array_merge($w, ['assign' => ['t1' => 'c']]));   // Clara, die Mutter
$h->cfg['Chores'] = json_encode([array_merge(['id' => 't1', 'name' => 'Tisch',
    'circle' => 'child', 'rotate' => 'week'], $vierTage)]);
$w2 = $h->pWoche($mi);
pruefe('Mutter verliert ein Aemtchen, das nur Kindern gehoert',
    in_array($w2['assign']['t1'], ['a', 'b'], true), true);
/* Gegenprobe: wer weiter in den Kreis gehoert, BLEIBT — sonst waere die Woche
   nicht mehr eingefroren. */
$h = harness($vier, $offen);
$w = $h->pWoche($mi);
$h->attr['Week'] = json_encode(array_merge($w, ['assign' => ['t1' => 'd']]));
$w2 = $h->pWoche($mi);
pruefe('wer im Kreis bleibt, behaelt sein Aemtchen', $w2['assign']['t1'], 'd');

// ── Ein leerer Kreis darf die Woche nicht leeren ────────────────────────────
/* Faellt die Mitgliederauskunft aus (das Gateway ist beschaeftigt), kennt das
   Modul keine Rollen mehr — „Nur Kinder" ergibt dann einen LEEREN Kreis. Das
   heisst „ich kann es gerade nicht sagen" und nicht „hier gehoert niemand hin".
   Vorher loeschte genau dieser Fall die Zuweisung der ganzen Woche. */
$nurKind = [array_merge(['id' => 't1', 'name' => 'Tisch', 'circle' => 'child',
    'rotate' => 'week'], $vierTage)];
$h = harness($vier, $nurKind);
$w = $h->pWoche($mi);
$halterVorher = $w['assign']['t1'];
pruefe('mit Rollen ist ein Kind zustaendig', in_array($halterVorher, ['a', 'b'], true), true);
$h->rollen = [];                       // Gateway antwortet nicht mehr
$h->namen  = [];
$w2 = $h->pWoche($mi);
pruefe('ohne Auskunft bleibt die Zuweisung stehen', $w2['assign']['t1'], $halterVorher);
/* Und eine Zuweisung, die schon leer IST, wird repariert, sobald die Auskunft
   wieder da ist — sonst bliebe der Schaden fuer den Rest der Woche. */
$h2 = harness($vier, $nurKind);
$w3 = $h2->pWoche($mi);
$h2->attr['Week'] = json_encode(array_merge($w3, ['assign' => ['t1' => '']]));
pruefe('eine leere Zuweisung wird wieder gefuellt',
    in_array($h2->pWoche($mi)['assign']['t1'], ['a', 'b'], true), true);

// ── Schalter der Anzeige ────────────────────────────────────────────────────
/* Alles an, solange nichts anderes dasteht — und jeder Kasten einzeln aus. */
$h = harness($drei, $aemtchen3);
$show = $h->pPayload($jetzt)['show'];
pruefe('ohne Angabe ist alles an', $show, ['members' => true, 'progress' => true,
    'upNext' => true, 'banner' => true, 'wheel' => true]);
$h = harness($drei, $aemtchen3, ['ShowMembers' => false, 'ShowUpNext' => false]);
$show = $h->pPayload($jetzt)['show'];
pruefe('abgeschaltete Kaesten stehen als false in der Nutzlast',
    [$show['members'], $show['upNext']], [false, false]);
pruefe('… und die uebrigen bleiben an', [$show['progress'], $show['banner']], [true, true]);

// ── Ergebnis ────────────────────────────────────────────────────────────────
printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
