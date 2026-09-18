<?php

declare(strict_types=1);

/**
 * Das Gluecksrad: ein Aemtchen, das heute ansteht, bekommt fuer heute eine
 * ausgeloste Person — einmal am Tag je Aemtchen.
 *
 * Gelost wird im Modul, nicht in der Kachel: sonst zeigte ein zweites Geraet
 * ein anderes Los, und „nochmal" wuerde zaehlen. Deshalb steht hier, was das
 * Modul zusichert: wer mitspielt, wer gewinnt, dass das Los am heutigen Platz
 * steht und beim Abhaken haengen bleibt, und dass der zweite Dreh am selben
 * Tag abgewiesen wird.
 *
 *   php Chores/tests/WuerfelTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/ChoreStore.php';

date_default_timezone_set('Europe/Berlin');

final class WuerfelHarness extends IPSModuleStrict
{
    use ChoreStore;

    public array $cfg = [];
    public array $attr = ['Week' => '{}', 'LastWeek' => '{}', 'Shift' => 0];
    public array $rollen = ['a' => 'child', 'b' => 'child', 'c' => 'mother', 'd' => 'father'];
    public array $namen  = ['a' => 'Anna', 'b' => 'Ben', 'c' => 'Clara', 'd' => 'Dirk'];

    private function Konfiguration(): array { return $this->cfg; }
    public function Translate(string $Text): string { return $Text; }
    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    protected function ReadAttributeString(string $Name): string { return (string)($this->attr[$Name] ?? ''); }
    protected function WriteAttributeString(string $Name, string $Value): bool { $this->attr[$Name] = $Value; return true; }
    protected function ReadAttributeInteger(string $Name): int { return (int)($this->attr[$Name] ?? 0); }
    protected function WriteAttributeInteger(string $Name, int $Value): bool { $this->attr[$Name] = $Value; return true; }
    private function Rollen(): array { return $this->rollen; }
    private function Mitglieder(): array
    {
        $raus = [];
        foreach ($this->namen as $id => $name) {
            $raus[$id] = ['name' => $name, 'avatar' => '', 'persona' => (string)($this->rollen[$id] ?? ''), 'color' => ''];
        }
        return $raus;
    }
    private function UebernehmenNachtragen(): void {}
    private function GatewayInstanz(): int { return 1; }

    public function pWuerfeln(string $w, string $c, int $t, ?callable $z = null): array { return $this->Wuerfeln($w, $c, $t, $z); }
    public function pWoche(int $t): array { return $this->WocheSicherstellen($t); }
    public function pPlaetze(array $w, array $a): array { return $this->PlaetzeFuer($w, $a); }
    public function pAemtchen(): array { return $this->AemtchenLesen(); }
    public function pAbhaken(string $w, string $c, string $s, bool $z, int $t): bool { return $this->Abhaken($w, $c, $s, $z, $t); }
    public function pPayload(int $t): array { return $this->PayloadBauen($t); }
    public function pKandidaten(array $a): array { return $this->WuerfelKandidaten($a); }
    public function pTag(int $t): string { return $this->TagKennung($t); }
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
    printf("%-4s %-62s%s\n", $ok ? 'OK' : 'FEHL', $name, $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/* Zwei Aemtchen: „tisch" steht an jedem Tag an (also auch heute, wann immer
   der Pruefstand laeuft), „garten" nie — er hat keinen Tag. */
function harness(array $teilnehmer): WuerfelHarness
{
    $h = new WuerfelHarness(12345);
    $alleTage = ['d1' => true, 'd2' => true, 'd3' => true, 'd4' => true, 'd5' => true, 'd6' => true, 'd7' => true];
    $h->cfg = [
        'Members'   => json_encode($teilnehmer),
        'Chores'    => json_encode([
            ['id' => 'tisch', 'name' => 'Tischdienst', 'circle' => 'child', 'rotate' => 'day'] + $alleTage,
            ['id' => 'muell', 'name' => 'Muell', 'circle' => 'all'] + $alleTage,
            ['id' => 'garten', 'name' => 'Garten', 'circle' => 'all', 'perWeek' => 0],
        ]),
        'WeekStart' => 1,
        'ResetTime' => '{"hour":3,"minute":0,"second":0}',
        'CarryOver' => false,
    ];
    return $h;
}
$alle = [['memberId' => 'a'], ['memberId' => 'b'], ['memberId' => 'c'], ['memberId' => 'd']];
$jetzt = time();
$h = harness($alle);
$woche = $h->pWoche($jetzt);
$kennung = (string)$woche['week'];
$heute = $h->pTag($jetzt);

// ── Wer spielt mit ─────────────────────────────────────────────────────────
$aemtchen = [];
foreach ($h->pAemtchen() as $a) { $aemtchen[$a['id']] = $a; }
pruefe('Beim Tischdienst (nur Kinder) spielen die beiden Kinder mit', $h->pKandidaten($aemtchen['tisch']), ['a', 'b']);
pruefe('Beim Muell alle vier', $h->pKandidaten($aemtchen['muell']), ['a', 'b', 'c', 'd']);
$p = harness([['memberId' => 'a', 'pause' => true], ['memberId' => 'b'], ['memberId' => 'c']]);
foreach ($p->pAemtchen() as $a) { if ($a['id'] === 'muell') { $m = $a; } }
pruefe('Wer aussetzt, spielt nicht mit', $p->pKandidaten($m), ['b', 'c']);

// ── Das Los ────────────────────────────────────────────────────────────────
$los = $h->pWuerfeln($kennung, 'tisch', $jetzt, static fn(int $n): int => 1);
pruefe('Der Zufall waehlt aus den Kandidaten — Index 1 ist Ben',
    [$los['ok'], $los['memberId'], $los['reason']], [true, 'b', '']);
$woche = $h->pWoche($jetzt);
pruefe('Das Los steht mit Tag und Platz in der eingefrorenen Woche',
    [$woche['wheel']['tisch']['memberId'], $woche['wheel']['tisch']['day'], $woche['wheel']['tisch']['slot'] !== ''],
    ['b', $heute, true]);
$heutePlatz = null;
foreach ($h->pPlaetze($woche, $aemtchen['tisch']) as $pl) {
    if ($pl['key'] === $los['slot']) { $heutePlatz = $pl; }
}
pruefe('Am heutigen Platz steht jetzt der Gewinner — markiert als gelost',
    [$heutePlatz['memberId'] ?? null, $heutePlatz['wheel'] ?? false], ['b', true]);
$andere = array_filter($h->pPlaetze($woche, $aemtchen['tisch']), static fn(array $pl): bool => $pl['key'] !== $los['slot']);
pruefe('Die anderen Tage bleiben unberuehrt',
    count(array_filter($andere, static fn(array $pl): bool => ($pl['wheel'] ?? false) === true)), 0);

// ── Einmal am Tag ──────────────────────────────────────────────────────────
$zweit = $h->pWuerfeln($kennung, 'tisch', $jetzt, static fn(int $n): int => 0);
pruefe('Ein zweiter Dreh am selben Tag wird abgewiesen', [$zweit['ok'], $zweit['reason']], [false, 'spun']);
pruefe('… und das Los bleibt', $h->pWoche($jetzt)['wheel']['tisch']['memberId'], 'b');
$morgen = $h->pWuerfeln($kennung, 'tisch', $jetzt + 86400, static fn(int $n): int => 0);
/* Morgen darf wieder gelost werden — sofern morgen noch dieselbe Woche ist.
   Am letzten Wochentag rollt die Woche um und die Kennung passt nicht mehr;
   dann ist die Antwort „week", und das ist genauso richtig. */
pruefe('Morgen darf wieder gelost werden (oder die Woche ist um)',
    $morgen['ok'] || $morgen['reason'] === 'week', true);

// ── Abhaken uebernimmt den Gewinner ────────────────────────────────────────
$h2 = harness($alle);
$w2 = $h2->pWoche($jetzt);
$los2 = $h2->pWuerfeln((string)$w2['week'], 'muell', $jetzt, static fn(int $n): int => 3);
pruefe('Muell: Index 3 ist Dirk', $los2['memberId'], 'd');
pruefe('Abhaken am gelosten Platz gelingt', $h2->pAbhaken((string)$w2['week'], 'muell', $los2['slot'], true, $jetzt), true);
$w2 = $h2->pWoche($jetzt);
pruefe('… und vermerkt DIRK als den, der es getan hat', $w2['done']['muell'][$los2['slot']]['m'] ?? null, 'd');
foreach ($h2->pAemtchen() as $a) { if ($a['id'] === 'muell') { $mu = $a; } }
$fertig = array_values(array_filter($h2->pPlaetze($w2, $mu), static fn(array $pl): bool => $pl['key'] === $los2['slot']))[0];
pruefe('Der abgehakte Platz zeigt weiter Dirk', [$fertig['done'], $fertig['memberId']], [true, 'd']);

// ── Abgewiesen ─────────────────────────────────────────────────────────────
pruefe('Ein Aemtchen ohne heutigen Platz laesst sich nicht verlosen',
    $h->pWuerfeln($kennung, 'garten', $jetzt)['reason'], 'no_slot');
pruefe('Ein unbekanntes Aemtchen ebenso', $h->pWuerfeln($kennung, 'gibtsnicht', $jetzt)['reason'], 'chore');
pruefe('Eine fremde Wochenkennung ebenso', $h->pWuerfeln('2000-01-03', 'muell', $jetzt)['reason'], 'week');
$leer = harness([['memberId' => 'c'], ['memberId' => 'd']]);
$wl = $leer->pWoche($jetzt);
pruefe('Ohne Kandidaten (nur Kinder, aber keine da) gibt es kein Los',
    $leer->pWuerfeln((string)$wl['week'], 'tisch', $jetzt)['reason'], 'nobody');
/* Der Zufall ist echt, wenn niemand ihn ersetzt: hundert Wuerfe treffen nur Kandidaten. */
$treffer = [];
for ($i = 0; $i < 100; $i++) {
    $hz = harness($alle);
    $wz = $hz->pWoche($jetzt);
    $treffer[$hz->pWuerfeln((string)$wz['week'], 'tisch', $jetzt)['memberId']] = true;
}
pruefe('Der echte Zufall trifft nur Kinder', array_diff(array_keys($treffer), ['a', 'b']), []);

// ── Die Nutzlast ───────────────────────────────────────────────────────────
/* Frisch: das Los von „morgen" oben hat den Eintrag ersetzt (je Aemtchen
   steht genau ein Los, das juengste) — fuer heute zaehlt es dann nicht mehr. */
$h = harness($alle);
$h->pWuerfeln((string)$h->pWoche($jetzt)['week'], 'tisch', $jetzt, static fn(int $n): int => 1);
$nutz = $h->pPayload($jetzt);
$tisch = array_values(array_filter($nutz['chores'], static fn(array $c): bool => $c['id'] === 'tisch'))[0];
$muell = array_values(array_filter($nutz['chores'], static fn(array $c): bool => $c['id'] === 'muell'))[0];
pruefe('Die Kachel bekommt die Kandidaten und das heutige Los',
    [$tisch['candidates'], $tisch['wheel']['memberId'] ?? null, $muell['wheel']], [['a', 'b'], 'b', null]);
pruefe('… den Schalter fuer den Kasten und die Texte',
    [$nutz['show']['wheel'], isset($nutz['texts']['wheelTitle']), isset($nutz['texts']['wheelSpun']), $nutz['day']],
    [true, true, true, $heute]);

// ── Die Kachel ─────────────────────────────────────────────────────────────
$html = (string)file_get_contents(__DIR__ . '/../module.html');
pruefe('Der Kasten steht neben dem Schlusswort und oeffnet das Blatt',
    [str_contains($html, '<div id="unten">'), str_contains($html, 'id="wuerfel"'), str_contains($html, 'id="blatt"'),
     str_contains($html, "requestAction('Spin'")], [true, true, true, true]);
/* Wenige Mitspieler stehen mehrmals auf dem Rad: einer 4x, zwei 3x, drei und
   vier 2x, ab fuenf 1x — abwechselnd, nie zwei gleiche nebeneinander. Die
   Regel steht im Skript; hier wird sie am Quelltext festgehalten. */
pruefe('Wenige Mitspieler stehen mehrmals auf dem Rad',
    [str_contains($html, 'function radFelder'),
     str_contains($html, 'const mal = n <= 1 ? 4 : (n === 2 ? 3 : (n <= 4 ? 2 : 1));'),
     str_contains($html, 'rad.felder.forEach((id, i) =>')], [true, true, true]);
pruefe('Das Rad dreht erst, wenn das Los des MODULS da ist',
    [str_contains($html, 'function radNachziehen'), str_contains($html, 'a.wheel && a.wheel.memberId')], [true, true]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
