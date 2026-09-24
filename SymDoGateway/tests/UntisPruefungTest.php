<?php

declare(strict_types=1);

/**
 * Pruefstand: Pruefungen aus WebUntis (24.09.2026).
 *
 *  1. UntisPruefungCalc — Doppelstunden, Schluessel, Abgleich (neu, verlegt,
 *     entfaellt, verschwunden), Briefingzeilen, Lern-Faelligkeit.
 *  2. Das Lesen (UntisLesen, mit festem Abruf statt Netz): der gemessene
 *     EXAM-Eintrag vom 08.10., das Planfenster bleibt unberuehrt, Kurswahl und
 *     Rangfolge, der Rueckfall macht die Pruefungen „unbekannt".
 *  3. Das Einpflegen im Gateway (WebUntis + Homework): erster Lauf still,
 *     Meldungen je einmal, Stand nicht schreibbar = still, Lern-Erinnerung
 *     anlegen / nachfuehren / loeschen / nicht wiederbeleben, der Endpunkt.
 *  4. Vorabend-Meldung (WebPush), Schulzeile (TimetableBridge), Briefing.
 *
 *   php SymDoGateway/tests/UntisPruefungTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/HomeworkCalc.php';
require_once __DIR__ . '/../libs/UntisLesen.php';
require_once __DIR__ . '/../libs/WebUntis.php';
require_once __DIR__ . '/../libs/Homework.php';
require_once __DIR__ . '/../libs/WebPush.php';
require_once __DIR__ . '/../libs/TimetableBridge.php';
require_once __DIR__ . '/../libs/Briefing.php';

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
    printf("%-4s %-80s%s\n", $ok ? 'OK' : 'FEHL', $name, $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

$HEUTE = date('Y-m-d');
$tag = static fn(int $n): string => date('Y-m-d', (int)strtotime($HEUTE . ' 12:00:00') + $n * 86400);
/** Der erste Werktag (Mo–Fr) ab $n Tagen. */
$werktag = static function (int $n) use ($tag): string {
    for ($i = $n; $i < $n + 7; $i++) {
        if ((int)date('N', (int)strtotime($tag($i))) <= 5) {
            return $tag($i);
        }
    }
    return $tag($n);
};
$p = static fn(array $x): array => $x + ['ids' => [], 'date' => '', 'start' => '08:00', 'end' => '09:00',
    'subject' => 'Deutsch', 'title' => '', 'room' => '', 'teacher' => '', 'status' => 'normal'];

// ══ 1. UntisPruefungCalc ═══════════════════════════════════════════════════
$d = '2026-10-08';
$zwei = UntisPruefungCalc::Zusammenfassen([
    $p(['ids' => [2], 'date' => $d, 'start' => '11:25', 'end' => '12:10', 'subject' => 'Englisch', 'title' => 'KA 2']),
    $p(['ids' => [1], 'date' => $d, 'start' => '10:35', 'end' => '11:20', 'subject' => 'Englisch', 'title' => 'KA 2', 'room' => '121']),
    $p(['ids' => [3], 'date' => $d, 'start' => '13:05', 'end' => '14:05', 'subject' => 'Englisch', 'title' => 'KA 2']),
    $p(['ids' => [4], 'date' => $d, 'start' => '08:00', 'end' => '09:00', 'subject' => 'Mathe', 'title' => 'Test']),
    ['date' => 'kaputt'],
]);
pruefe('Doppelstunde wird EINE Pruefung (Nummern, Ende, Raum)',
    array_map(static fn($x) => [$x['ids'], $x['start'], $x['end'], $x['room']], $zwei),
    [[[4], '08:00', '09:00', ''], [[1, 2], '10:35', '12:10', '121'], [[3], '13:05', '14:05', '']]);
pruefe('Teil entfaellt, Teil findet statt → findet statt', UntisPruefungCalc::Zusammenfassen([
    $p(['ids' => [1], 'date' => $d, 'start' => '10:35', 'end' => '11:20', 'status' => 'entfall']),
    $p(['ids' => [2], 'date' => $d, 'start' => '11:25', 'end' => '12:10']),
])[0]['status'], 'normal');
pruefe('Schluessel: erste Nummer, ohne Nummer eine Pruefsumme > 0',
    [UntisPruefungCalc::Schluessel(['ids' => [77, 78]]), UntisPruefungCalc::Schluessel($p(['date' => $d])) > 0],
    [77, true]);

// Abgleich
$a1 = $p(['ids' => [10], 'date' => $tag(10), 'start' => '09:30', 'end' => '10:30', 'subject' => 'Deutsch', 'title' => 'KA Diktat']);
$erst = UntisPruefungCalc::Abgleichen([], [$a1], 1000);
pruefe('Ohne Stand: alles neu, Schluessel = Nummer', [count($erst['neu']), $erst['liste'][0]['key'], $erst['liste'][0]['seit']],
    [1, 10, 1000]);
$stand = $erst['liste'];
$stand[0]['lern'] = 'hw1';
$stand[0]['lernFuer'] = $tag(10);
$gleich = UntisPruefungCalc::Abgleichen($stand, [$a1], 2000);
pruefe('Zweiter Lauf ohne Aenderung: nichts zu melden, Lern-Verweis und seit bleiben',
    [count($gleich['neu']), count($gleich['verlegt']), count($gleich['entfallen']), count($gleich['weg']),
     $gleich['liste'][0]['lern'], $gleich['liste'][0]['seit']], [0, 0, 0, 0, 'hw1', 1000]);
$bewegt = UntisPruefungCalc::Abgleichen($stand, [array_merge($a1, ['date' => $tag(11)])], 2000);
pruefe('Gleiche Nummer, anderer Tag: verlegt (vorher/nachher), Lern-Verweis bleibt',
    [count($bewegt['verlegt']), $bewegt['verlegt'][0]['vorher']['date'], $bewegt['verlegt'][0]['nachher']['date'],
     $bewegt['liste'][0]['lern']], [1, $tag(10), $tag(11), 'hw1']);
$umgelegt = UntisPruefungCalc::Abgleichen($stand, [
    array_merge($a1, ['status' => 'entfall']),
    $p(['ids' => [11], 'date' => $tag(12), 'start' => '08:00', 'end' => '09:00', 'subject' => 'Deutsch', 'title' => 'KA  diktat!']),
], 2000);
pruefe('Neue Nummer + alte gestrichen, gleicher Titel: verlegt, der Rest faellt aus der Liste',
    [count($umgelegt['verlegt']), count($umgelegt['entfallen']), count($umgelegt['neu']),
     array_map(static fn($x) => [$x['key'], $x['date'], $x['lern']], $umgelegt['liste'])],
    [1, 0, 0, [[10, $tag(12), 'hw1']]]);
$verschwunden = UntisPruefungCalc::Abgleichen($stand, [
    $p(['ids' => [12], 'date' => $tag(15), 'subject' => 'Deutsch', 'title' => 'KA Diktat']),
], 2000);
pruefe('Alte verschwunden, neue mit gleichem Titel: verlegt', [count($verschwunden['verlegt']), count($verschwunden['weg'])], [1, 0]);
$weg = UntisPruefungCalc::Abgleichen($stand, [], 2000);
pruefe('Verschwunden ohne Nachfolger: still (weg)', [count($weg['weg']), count($weg['neu']), count($weg['liste'])], [1, 0, 0]);
$faellt = UntisPruefungCalc::Abgleichen($stand, [array_merge($a1, ['status' => 'entfall'])], 2000);
pruefe('Status wird entfall: entfaellt, bleibt in der Liste', [count($faellt['entfallen']), $faellt['liste'][0]['status']], [1, 'entfall']);
$ohneTitel = UntisPruefungCalc::Abgleichen(
    [$p(['key' => 5, 'ids' => [5], 'date' => $tag(3), 'subject' => 'Mathe'])],
    [$p(['ids' => [6], 'date' => $tag(40), 'subject' => 'Mathe'])], 2000);
pruefe('Ohne Titel und weit auseinander: zwei Pruefungen, keine Verlegung',
    [count($ohneTitel['verlegt']), count($ohneTitel['neu']), count($ohneTitel['weg'])], [0, 1, 1]);
pruefe('Nur gestrichen gekannt: keine Meldung', count(UntisPruefungCalc::Abgleichen([],
    [$p(['ids' => [7], 'date' => $tag(4), 'status' => 'entfall'])], 1)['neu']), 0);

// Abstand, Briefingzeilen, Lern-Erinnerung
pruefe('Abstand ab heute', [UntisPruefungCalc::Abstand($tag(0), $HEUTE), UntisPruefungCalc::Abstand($tag(1), $HEUTE),
    UntisPruefungCalc::Abstand($tag(2), $HEUTE), UntisPruefungCalc::Abstand($tag(9), $HEUTE),
    UntisPruefungCalc::Abstand($tag(-1), $HEUTE)], ['heute', 'morgen', 'übermorgen', 'in 9 Tagen', '']);
$zeilen = UntisPruefungCalc::BriefingZeilen(['Joshua' => [
    $p(['date' => $tag(0), 'subject' => 'Sport']),                      // der Tag selbst: nicht hier
    $p(['date' => $tag(3), 'start' => '09:30', 'subject' => 'Englisch', 'title' => 'KA 1. KA Englisch']),
    $p(['date' => $tag(2), 'start' => '10:35', 'subject' => 'Deutsch', 'title' => 'Deutsch']),
    $p(['date' => $tag(5), 'subject' => 'Mathe', 'status' => 'entfall']),
    $p(['date' => $tag(6), 'subject' => 'Bio']),
    $p(['date' => $tag(7), 'subject' => 'Chemie']),
    $p(['date' => $tag(8), 'subject' => 'Physik']),                     // hinter der Woche
]], $tag(0), $tag(7), $HEUTE);
pruefe('Briefing: nach dem Tag bis +7, sortiert, hoechstens 4 + Rest, Titel nur wenn er mehr sagt',
    [count($zeilen), str_contains($zeilen[0], 'Prüfung Deutsch am') && str_contains($zeilen[0], '— übermorgen'),
     str_contains($zeilen[1], 'Prüfung Englisch („KA 1. KA Englisch“)') && str_contains($zeilen[1], '09:30 — in 3 Tagen'),
     str_ends_with($zeilen[2], '(entfällt)'), $zeilen[4]],
    [5, true, true, true, 'und 1 weitere Prüfung(en)']);
$vorschau = UntisPruefungCalc::BriefingZeilen(['J' => [$p(['date' => $tag(2), 'subject' => 'Deutsch'])]], $tag(1), $tag(8), $HEUTE);
pruefe('Briefing nennt das Thema', UntisPruefungCalc::BriefingZeilen(['J' => [$p(['date' => $tag(2), 'subject' => 'Englisch',
    'title' => '1. KA Englisch', 'topic' => 'Mich vorstellen'])]], $tag(0), $tag(7), $HEUTE)[0],
    'J: Prüfung Englisch („1. KA Englisch“), Thema: Mich vorstellen am ' . UntisPruefungCalc::Wochentag($tag(2)) . ', '
    . date('d.m.', (int)strtotime($tag(2))) . ' um 08:00 — übermorgen');
pruefe('Abendvorschau (Tag = morgen): Abstand trotzdem ab heute', str_contains($vorschau[0] ?? '', '— übermorgen'), true);
pruefe('Lern-Erinnerung faellig ab Datum−7 bis zum Vortag, nicht entfallen',
    [UntisPruefungCalc::LernFaellig($p(['date' => $tag(8)]), $HEUTE, 7),
     UntisPruefungCalc::LernFaellig($p(['date' => $tag(7)]), $HEUTE, 7),
     UntisPruefungCalc::LernFaellig($p(['date' => $tag(1)]), $HEUTE, 7),
     UntisPruefungCalc::LernFaellig($p(['date' => $tag(0)]), $HEUTE, 7),
     UntisPruefungCalc::LernFaellig($p(['date' => $tag(3), 'status' => 'entfall']), $HEUTE, 7),
     UntisPruefungCalc::LernFaellig($p(['date' => $tag(3)]), $HEUTE, 0)],
    [false, true, true, false, false, false]);
pruefe('Faellig am Vortag, frueestens heute', [UntisPruefungCalc::LernDue($tag(5), $HEUTE), UntisPruefungCalc::LernDue($tag(1), $HEUTE)],
    [$tag(4), $HEUTE]);
pruefe('Lernstart der Zeitleiste: N Tage vorher, ohne Lern-Erinnerung eine Woche',
    [UntisPruefungCalc::LernStart('2026-09-30', 8), UntisPruefungCalc::LernStart('2026-10-06', 7),
     UntisPruefungCalc::LernStart('2026-10-06', 0)], ['2026-09-22', '2026-09-29', '2026-09-29']);

// ══ 2. Lesen ═══════════════════════════════════════════════════════════════
function untisPos(string $typ, string $kurz, string $lang, string $status = 'REGULAR'): array
{
    return [['current' => ['type' => $typ, 'id' => crc32($typ . $kurz) % 1000, 'shortName' => $kurz,
                           'longName' => $lang, 'status' => $status], 'removed' => null]];
}
function rest(int $id, string $datum, string $von, string $bis, array $fach, string $typ = 'NORMAL_TEACHING_PERIOD',
              string $status = 'REGULAR', string $info = ''): array
{
    $e = ['ids' => [$id], 'duration' => ['start' => $datum . 'T' . $von, 'end' => $datum . 'T' . $bis],
          'type' => $typ, 'status' => $status, 'statusDetail' => null, 'name' => null, 'lessonText' => '',
          'lessonInfo' => $info !== '' ? $info : null, 'substitutionText' => '',
          'position1' => untisPos('SUBJECT', $fach[0], $fach[1]), 'position2' => untisPos('TEACHER', 'Fa', 'Franke'),
          'position3' => untisPos('ROOM', '121', 'Klassenraum 5a')];
    if ($info !== '') {
        $e['position4'] = untisPos('INFO', $info, $info);
    }
    return $e;
}

final class LeseProbe extends IPSModuleStrict
{
    use UntisLesen;

    public array $roh = [];
    public array $cfg = [];
    public bool $weitKaputt = false;
    public array $abrufe = [];

    public function Translate(string $Text): string { return $Text; }
    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    private function AiProp(string $name): mixed { return $this->cfg[$name] ?? null; }
    private function UntisPlanRest(int $typ, int $nr, int $von, int $bis): ?array
    {
        $this->abrufe[] = [$von, $bis];
        if ($this->weitKaputt && $bis > (int)date('Ymd', strtotime('+14 days'))) {
            return null;
        }
        $raus = [];
        foreach ($this->roh as $e) {
            $zeile = $this->UntisRestEintrag($e);
            if ($zeile !== null && $zeile['date'] >= $von && $zeile['date'] <= $bis) {
                $raus[] = $zeile;
            }
        }
        return $raus;
    }
    private function UntisRpc(string $methode, array $params = []): array
    {
        return ['ok' => true, 'result' => []];
    }
    public array $details = [];
    public string $detailDatum = '';
    /** Die Detailansicht (v2): liefert exam.description fuer E1, sonst nichts. */
    private function UntisRest(string $pfad, string $wurzel = 'api/rest/view/v1/'): ?array
    {
        $this->details[] = $wurzel . $pfad;
        if (str_contains($pfad, 'calendar-entry/detail') && str_contains($pfad, $this->detailDatum . 'T09:30:00') && $this->detailDatum !== '' && $wurzel === 'api/rest/view/v2/') {
            return ['calendarEntries' => [['exam' => ['name' => '1. KA Deutsch', 'description' => 'Diktat: Wörter mit ie',
                'typeLongName' => 'Klassenarbeit', 'id' => 5906]]]];
        }
        return ['calendarEntries' => [['exam' => null]]];
    }
    public function pEintrag(array $e): ?array { return (new ReflectionMethod(self::class, 'UntisRestEintrag'))->invoke($this, $e); }
    public function pErnten(array $kind): array
    {
        $this->untisIch = ['id' => 4744, 'type' => 5];
        return (array)(new ReflectionMethod(self::class, 'UntisKindErnten'))->invoke($this, $kind, [], false);
    }
    public function pWaehlen(array $slots, string $kurse): array
    {
        return (array)(new ReflectionMethod(self::class, 'UntisKurseWaehlen'))->invoke($this, $slots, $kurse, 0);
    }
}

$lp = new LeseProbe(4001);
$gemessen = json_decode('{"ids":[2985164],"duration":{"start":"2026-10-08T10:35","end":"2026-10-08T11:35"},'
    . '"type":"EXAM","status":"REGULAR","statusDetail":null,"name":null,"color":"ff5757","notesAll":"","icons":[],'
    . '"texts":[],"lessonText":"","lessonInfo":"KA Briefe schreiben","substitutionText":"","userName":null,"moved":null,'
    . '"durationTotal":null,"link":null,'
    . '"position1":[{"current":{"type":"SUBJECT","shortName":"D","longName":"Deutsch","status":"REGULAR"},"removed":null}],'
    . '"position2":[{"current":{"type":"TEACHER","shortName":"Fa","longName":"Franke","status":"REGULAR"},"removed":null}],'
    . '"position3":[{"current":{"type":"ROOM","shortName":"121","longName":"Klassenraum 5a","status":"REGULAR"},"removed":null}],'
    . '"position4":[{"current":{"type":"INFO","shortName":"KA Briefe schreiben","longName":"KA Briefe schreiben","status":"REGULAR"},"removed":null}]}', true);
$z = $lp->pEintrag($gemessen);
pruefe('Gemessener EXAM-Eintrag 08.10.: Merkmal, Titel, Nummer, Zeit, Fach',
    [$z['exam'], $z['examTitle'], $z['pid'], $z['startTime'], $z['su'][0]['longname'], $z['code']],
    [true, 'KA Briefe schreiben', 2985164, 1035, 'Deutsch', '']);
$ohneInfo = $gemessen;
$ohneInfo['lessonInfo'] = null;
pruefe('Ohne lessonInfo: Titel aus dem INFO-Element', $lp->pEintrag($ohneInfo)['examTitle'], 'KA Briefe schreiben');
pruefe('Normale Stunde: kein Merkmal', $lp->pEintrag(rest(1, $HEUTE, '08:00', '09:00', ['M', 'Mathematik']))['exam'], false);
pruefe('Fachfarbe aus WebUntis: „ff5757" wird #FF5757', $z['color'], '#FF5757');
$kaputt = $gemessen;
$kaputt['color'] = 'rot';
pruefe('Unbrauchbare Farbe bleibt leer', $lp->pEintrag($kaputt)['color'], '');

/* Der feste Abruf: 60 Tage, Mo–Fr Mathe 08:00, Deutsch 09:30, und um 13:05 zwei
   Religionskurse nebeneinander (Klassenplan). Dazu die Pruefungen:
   E1 im Planfenster (Deutsch statt der Deutschstunde), E2 dahinter in der
   Religion des FREMDEN Kurses, E3 eine Doppelstunde, E4 hinter einer
   entfallenen Deutschstunde, die vorne steht. */
$E1 = $werktag(4);
$E2 = $werktag(20);
$E3 = $werktag(26);
$E4 = $werktag(32);
$bau = static function (int $bisTag) use ($tag, $E1, $E2, $E3, $E4): array {
    $roh = [];
    for ($i = 0; $i <= $bisTag; $i++) {
        $d = $tag($i);
        if ((int)date('N', (int)strtotime($d)) > 5) {
            continue;
        }
        $roh[] = rest(10000 + $i * 10 + 1, $d, '08:00', '09:00', ['M', 'Mathematik']);
        if ($d === $E4) {
            $roh[] = rest(10000 + $i * 10 + 2, $d, '09:30', '10:30', ['D', 'Deutsch'], 'NORMAL_TEACHING_PERIOD', 'CANCELLED');
            $roh[] = rest(900005, $d, '09:30', '10:30', ['D', 'Deutsch'], 'EXAM', 'REGULAR', 'KA Aufsatz');
        } elseif ($d === $E1) {
            $roh[] = rest(900001, $d, '09:30', '10:30', ['D', 'Deutsch'], 'EXAM', 'REGULAR', 'KA Diktat');
        } else {
            $roh[] = rest(10000 + $i * 10 + 2, $d, '09:30', '10:30', ['D', 'Deutsch']);
        }
        if ($d === $E2) {
            $roh[] = rest(900002, $d, '13:05', '14:05', ['KR', 'Katholische Religion'], 'EXAM', 'REGULAR', 'Test KR');
        } else {
            $roh[] = rest(10000 + $i * 10 + 4, $d, '13:05', '14:05', ['KR', 'Katholische Religion']);
        }
        $roh[] = rest(10000 + $i * 10 + 3, $d, '13:05', '14:05', ['ER', 'Evangelische Religion']);
        if ($d === $E3) {
            $roh[] = rest(900003, $d, '10:35', '11:20', ['E', 'Englisch'], 'EXAM', 'REGULAR', 'KA 2');
            $roh[] = rest(900004, $d, '11:25', '12:10', ['E', 'Englisch'], 'EXAM', 'REGULAR', 'KA 2');
        }
    }
    return $roh;
};
$kind = ['name' => 'Joshua', 'stpl' => 0, 'child' => '', 'type' => 5, 'id' => 4744,
         'kurse' => 'Evangelische Religion', 'userId' => 'k1'];
$lp->detailDatum = $E1;
$lp->roh = $bau(60);
$weit = $lp->pErnten($kind);
$lp->roh = $bau(14);
$eng = $lp->pErnten($kind);
pruefe('Abruf: das weite Fenster (56 Tage) zuerst', $lp->abrufe[0][1], (int)date('Ymd', strtotime('+56 days')));
pruefe('Planfenster unberuehrt: Vorlage, datierte Tage, Meldungen, Kurswahl-Liste, Zahl gleich wie mit 14 Tagen',
    [$weit['tage'], $weit['datiert'], $weit['auffaellig'], $weit['slots'], $weit['stunden'], $weit['offen']],
    [$eng['tage'], $eng['datiert'], $eng['auffaellig'], $eng['slots'], $eng['stunden'], $eng['offen']]);
pruefe('Datierte Tage enden am Planfenster', max(array_keys($weit['datiert'])) <= $tag(14), true);
$vorlagePruefung = false;
foreach ($weit['tage'] as $slots) {
    foreach ($slots as $s) {
        $vorlagePruefung = $vorlagePruefung || ($s['exam'] ?? false) === true;
    }
}
pruefe('Die Wochenvorlage traegt kein Pruefungsmerkmal', $vorlagePruefung, false);
pruefe('Im datierten Tag steht die Pruefung mit Titel', array_values(array_filter($weit['datiert'][$E1] ?? [],
    static fn($s) => ($s['exam'] ?? false) === true))[0]['examTitle'] ?? null, 'KA Diktat');
pruefe('Pruefungen: E1, E3 (eine Doppelstunde), E4 — nicht die KR-Arbeit des fremden Kurses',
    array_map(static fn($x) => [$x['date'], $x['subject'], $x['title'], $x['ids'], $x['start'], $x['end'], $x['status']], $weit['pruefungen']),
    [[$E1, 'Deutsch', '1. KA Deutsch', [900001], '09:30', '10:30', 'normal'],
     [$E3, 'Englisch', 'KA 2', [900003, 900004], '10:35', '12:10', 'normal'],
     [$E4, 'Deutsch', 'KA Aufsatz', [900005], '09:30', '10:30', 'normal']]);
pruefe('Thema, Name und Art aus der Detailansicht (v2), wo es eines gibt',
    array_map(static fn($x) => [$x['title'], $x['topic'], $x['examType']], $weit['pruefungen']),
    [['1. KA Deutsch', 'Diktat: Wörter mit ie', 'Klassenarbeit'], ['KA 2', '', ''], ['KA Aufsatz', '', '']]);
pruefe('… je Pruefung ein Detailabruf', count(array_filter($lp->details, static fn($d) => str_contains($d, 'calendar-entry/detail'))), 3 + 1);
pruefe('Im engen Abruf fehlen sie nicht — dort sind es nur die im Fenster',
    array_column($eng['pruefungen'], 'date'), [$E1]);
$lp->weitKaputt = true;
$lp->roh = $bau(60);
$lp->abrufe = [];
$kaputt = $lp->pErnten($kind);
pruefe('Weiter Abruf scheitert: Plan mit 14 Tagen wiederholt, Pruefungen unbekannt (null)',
    [count($lp->abrufe), $kaputt['ok'], $kaputt['pruefungen'], $kaputt['tage'] === $eng['tage']],
    [2, true, null, true]);
$lp->weitKaputt = false;

// Ferien: keine Stunde im Planfenster, aber eine Pruefung danach
$lp->roh = [rest(900009, $werktag(20), '08:00', '09:00', ['M', 'Mathematik'], 'EXAM', 'REGULAR', 'Test')];
$ferien = $lp->pErnten($kind);
pruefe('Ferien: Absage „keine Stunden", die Pruefung danach reist trotzdem mit',
    [$ferien['ok'], count((array)$ferien['pruefungen'])], [false, 1]);

// Rangfolge und Ueberschneidung
$sl = static fn(array $x): array => $x + ['start' => '09:30', 'end' => '10:30', 'room' => '', 'teacher' => '',
    'status' => 'normal', 'insteadOf' => '', 'grund' => '', 'exam' => false, 'examTitle' => '', 'pid' => 0];
[$g] = $lp->pWaehlen([$sl(['subject' => 'Deutsch', 'status' => 'entfall']),
                      $sl(['subject' => 'Deutsch', 'exam' => true, 'examTitle' => 'KA'])], '');
pruefe('Gleiches Fach: die Pruefung gewinnt auch hinter einer entfallenen Stunde',
    [count($g), $g[0]['exam'], $g[0]['status']], [1, true, 'normal']);
[$g2] = $lp->pWaehlen([$sl(['subject' => 'Deutsch']), $sl(['subject' => 'Deutsch', 'exam' => true])], '');
pruefe('Gleiches Fach, beide finden statt: die Pruefung', $g2[0]['exam'], true);
[$g3, $offen3, $verw3, $weg3] = $lp->pWaehlen([$sl(['subject' => 'Katholische Religion', 'exam' => true]),
    $sl(['subject' => 'Evangelische Religion'])], '');
pruefe('Ungeklaerte Ueberschneidung: die Pruefung steht in verworfen und im 4. Rueckgabewert',
    [count($g3), $offen3, $verw3, count($weg3)], [0, 1, ['09:30 Exam Katholische Religion'], 1]);

// ══ 3. Einpflegen im Gateway ═══════════════════════════════════════════════
final class PflegeProbe extends IPSModuleStrict
{
    use UntisLesen, WebUntis, Homework {
        UntisLesen::UntisProp insteadof WebUntis;
    }

    public array $attr = [];
    public array $cfg = ['UntisExamPush' => true, 'UntisExamStudyDays' => 7];
    public array $gesendet = [];
    public array $abos = ['k1' => ['a'], '' => ['a']];
    public bool $schreibsperre = false;
    public int $dirty = 0;

    public function Translate(string $Text): string { return $Text; }
    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    protected function LogMessage(string $Message, int $Type): bool { return true; }
    protected function ReadAttributeString(string $Name): string { return (string)($this->attr[$Name] ?? ''); }
    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        if (!($this->schreibsperre && $Name === 'UntisPruefungen')) {
            $this->attr[$Name] = $Value;
        }
        return true;
    }
    private function ReadAttributeStringSafe(string $name, string $vorgabe): string
    {
        $w = $this->attr[$name] ?? '';
        return $w !== '' ? $w : $vorgabe;
    }
    private function AiProp(string $name): mixed { return $this->cfg[$name] ?? null; }
    private function WsPushDirty(): void { $this->dirty++; }
    protected function RegisterOnceTimer(string $Ident, string $ScriptText): bool { return true; }
    private function LoadUsers(): array
    {
        return [['id' => 'k1', 'name' => 'Joshua', 'persona' => 'child'], ['id' => 'e1', 'name' => 'Papa', 'persona' => 'adult']];
    }
    private function TimetableInstances(): array { return []; }
    private function OriginalExistiert(string $id): bool { return false; }
    private function PushSubscriptions(string $userId = ''): array { return $this->abos[$userId] ?? []; }
    private function PushBroadcast(string $titel, string $text, string $userId = '', string $tab = '', int $badge = -1, array $extra = []): array
    {
        $this->gesendet[] = [$titel, $text, $userId];
        return ['sent' => 1, 'failed' => 0, 'dropped' => 0, 'stale' => 0, 'blocked' => 0];
    }
    public function pEinpflegen(array $kind, array $pruef): string
    {
        return (string)(new ReflectionMethod(self::class, 'UntisPruefungenEinpflegen'))->invoke($this, $kind, $pruef);
    }
    public function pStand(): array { return json_decode($this->attr['UntisPruefungen'] ?? '{}', true) ?: []; }
    public function pItems(): array { return (new ReflectionMethod(self::class, 'HomeworkItems'))->invoke($this); }
    public function pAktion(array $body): array { return (new ReflectionMethod(self::class, 'HomeworkHandleAction'))->invoke($this, $body); }
}

$pf = new PflegeProbe(4002);
$kindG = ['name' => 'Joshua', 'userId' => 'k1'];
$P1 = $p(['ids' => [900001], 'date' => $tag(5), 'start' => '09:30', 'end' => '10:30', 'subject' => 'Deutsch', 'title' => 'KA Diktat']);
$P2 = $p(['ids' => [900003, 900004], 'date' => $tag(26), 'start' => '10:35', 'end' => '12:10', 'subject' => 'Englisch', 'title' => 'KA 2']);

pruefe('Attribut fehlt (vor dem Neuladen): nichts gemerkt, nichts gemeldet',
    [$pf->pEinpflegen($kindG, [$P1]), $pf->gesendet, $pf->pItems()],
    ['exams: waiting for the module to be reloaded', [], []]);
$pf->attr['UntisPruefungen'] = '{}';
$pf->attr['HomeworkStore'] = '';
$t1 = $pf->pEinpflegen($kindG, [$P1, $P2]);
$st = $pf->pStand();
pruefe('Erster Lauf: gemerkt, KEINE Meldung, Statustext',
    [$t1, count($st['kinder']['k1']['exams']), $pf->gesendet, $pf->dirty > 0], ['2 exam(s)', 2, [], true]);
$items = $pf->pItems();
pruefe('Lern-Erinnerung nur fuer die Pruefung in 5 Tagen (7-Tage-Vorlauf), faellig am Vortag, Herkunft exam',
    [count($items), $items[0]['subject'] ?? '', $items[0]['due'] ?? '', $items[0]['source'] ?? '', $items[0]['srcId'] ?? 0,
     str_starts_with((string)($items[0]['note'] ?? ''), 'Study for the exam: KA Diktat (')],
    [1, 'Deutsch', $tag(4), 'exam', 900001, true]);
pruefe('Der Verweis steht an der Pruefung', [$st['kinder']['k1']['exams'][0]['lern'], $st['kinder']['k1']['exams'][0]['lernFuer']],
    [$items[0]['id'], $tag(5)]);
$rev = (int)$st['rev'];
$dirtyVorher = $pf->dirty;
$pf->pEinpflegen($kindG, [$P1, $P2]);
pruefe('Zweiter Lauf ohne Aenderung: nicht geschrieben, nichts gemeldet, keine zweite Erinnerung',
    [(int)$pf->pStand()['rev'], $pf->gesendet, count($pf->pItems()), $pf->dirty], [$rev, [], 1, $dirtyVorher]);

// verlegt + neu + entfaellt in EINER Meldung
$P1v = array_merge($P1, ['date' => $tag(6), 'start' => '08:00']);
$P2e = array_merge($P2, ['status' => 'entfall']);
$P3 = $p(['ids' => [900010], 'date' => $tag(30), 'start' => '08:00', 'end' => '09:00', 'subject' => 'Mathematik', 'title' => 'Test Brüche']);
$pf->pEinpflegen($kindG, [$P1v, $P2e, $P3]);
pruefe('Verlegt, entfaellt, neu: EINE Sammelmeldung an das Kind',
    [count($pf->gesendet), $pf->gesendet[0][0] ?? '', $pf->gesendet[0][2] ?? '', explode("\n", $pf->gesendet[0][1] ?? '')],
    [1, 'Exams Joshua', 'k1', [
        'In the plan: ' . date('d.m.', (int)strtotime($tag(30))) . ' 08:00 Mathematik — Test Brüche',
        'Moved: ' . date('d.m.', (int)strtotime($tag(6))) . ' 08:00 Deutsch — KA Diktat (before ' . date('d.m.', (int)strtotime($tag(5))) . ' 09:30)',
        'Cancelled: ' . date('d.m.', (int)strtotime($tag(26))) . ' 10:35 Englisch — KA 2',
    ]]);
$items = $pf->pItems();
pruefe('Verlegt: die offene Lern-Erinnerung zieht mit (Faelligkeit, Notiz)',
    [count($items), $items[0]['due'], str_contains($items[0]['note'], date('d.m.', (int)strtotime($tag(6))) . ', 08:00')],
    [1, $tag(5), true]);

// Kind ohne Geraet: an den Haushalt
$pf->gesendet = [];
$pf->abos = ['' => ['a']];
$pf->pEinpflegen($kindG, [$P1v, $P2e]);          // P3 verschwindet: still
pruefe('Verschwunden: still', $pf->gesendet, []);
$pf->pEinpflegen($kindG, [$P1v, $P2e, $p(['ids' => [900011], 'date' => $tag(31), 'subject' => 'Bio'])]);
pruefe('Kind ohne Geraet: die Meldung geht an den Haushalt', $pf->gesendet[0][2] ?? 'x', '');
$pf->abos = ['k1' => ['a'], '' => ['a']];

// Abgehakt bleibt, selbst geloescht kommt nicht wieder, entfaellt → weg
$lern = $pf->pItems()[0]['id'];
$pf->pAktion(['action' => 'done', 'id' => $lern, 'done' => true]);
$pf->pEinpflegen($kindG, [array_merge($P1v, ['date' => $tag(7)])]);
$it = $pf->pItems();
pruefe('Abgehakt und verlegt: die Erinnerung bleibt, wie sie ist', [count($it), $it[0]['done'], $it[0]['due']], [1, true, $tag(5)]);
$pf->pAktion(['action' => 'delete', 'id' => $lern]);
$pf->pEinpflegen($kindG, [array_merge($P1v, ['date' => $tag(7)])]);
pruefe('Selbst geloescht: wird nicht neu angelegt', count($pf->pItems()), 0);
$P5 = $p(['ids' => [900020], 'date' => $tag(3), 'subject' => 'Englisch', 'title' => 'Vokabeltest']);
$pf->pEinpflegen($kindG, [$P5]);
$l5 = $pf->pItems()[0]['id'] ?? '';
$pf->pEinpflegen($kindG, [array_merge($P5, ['status' => 'entfall'])]);
pruefe('Entfaellt: die offene Erinnerung wird geloescht', array_column($pf->pItems(), 'id'), []);
$pf->pEinpflegen($kindG, [$P5]);
$pf->pEinpflegen($kindG, []);
pruefe('Verschwunden: die offene Erinnerung wird geloescht', count($pf->pItems()), 0);

// UNTIS-Hausaufgaben bleiben unberuehrt
$store = json_decode($pf->attr['HomeworkStore'], true);
$store['items'][] = ['id' => 'u1', 'srcId' => 555, 'childId' => 'k1', 'subject' => 'Deutsch', 'due' => $tag(2), 'done' => false,
    'doneAt' => 0, 'doneBy' => '', 'note' => 'S. 4', 'source' => 'untis', 'createdAt' => time(), 'updatedAt' => time()];
$pf->attr['HomeworkStore'] = json_encode($store);
$pf->pEinpflegen($kindG, [$p(['ids' => [900030], 'date' => $tag(4), 'subject' => 'Deutsch', 'title' => 'KA'])]);
$pf->pEinpflegen($kindG, []);
pruefe('UNTIS-Hausaufgabe bleibt, nur die Lern-Erinnerung kommt und geht', array_column($pf->pItems(), 'id'), ['u1']);

// Verlorene Kennung: ueber die srcId wiedergefunden, nicht doppelt angelegt
$pf->pEinpflegen($kindG, [$p(['ids' => [900040], 'date' => $tag(4), 'subject' => 'Deutsch', 'title' => 'KA'])]);
$stand = $pf->pStand();
$stand['kinder']['k1']['exams'][0]['lern'] = '';
$pf->attr['UntisPruefungen'] = json_encode($stand);
$pf->pEinpflegen($kindG, [$p(['ids' => [900040], 'date' => $tag(4), 'subject' => 'Deutsch', 'title' => 'KA'])]);
pruefe('Verlorene Kennung: wiedergefunden, keine zweite Erinnerung',
    [count(array_filter($pf->pItems(), static fn($i) => $i['source'] === 'exam')), $pf->pStand()['kinder']['k1']['exams'][0]['lern'] !== ''],
    [1, true]);

// Stand nicht schreibbar: keine Meldung (sonst jede Stunde dieselbe)
$pf->gesendet = [];
$pf->schreibsperre = true;
$pf->pEinpflegen($kindG, [$p(['ids' => [900040], 'date' => $tag(4), 'subject' => 'Deutsch', 'title' => 'KA']),
                          $p(['ids' => [900050], 'date' => $tag(20), 'subject' => 'Physik'])]);
pruefe('Stand nicht schreibbar: keine Meldung', $pf->gesendet, []);
$pf->schreibsperre = false;

// Push-Schalter aus
$pf->cfg['UntisExamPush'] = false;
$pf->pEinpflegen($kindG, [$p(['ids' => [900040], 'date' => $tag(4), 'subject' => 'Deutsch', 'title' => 'KA']),
                          $p(['ids' => [900060], 'date' => $tag(21), 'subject' => 'Chemie'])]);
pruefe('Schalter aus: gemerkt, nicht gemeldet', [$pf->gesendet, count($pf->pStand()['kinder']['k1']['exams'])], [[], 2]);
$pf->cfg['UntisExamPush'] = true;

// Endpunkt
$liste = $pf->pAktion(['action' => 'list']);
pruefe('Hausaufgaben-Endpunkt: exams (ab heute, mit Kind) und examRev',
    [array_column($liste['exams'], 'subject'), $liste['exams'][0]['childId'], $liste['examRev'] === (int)$pf->pStand()['rev']],
    [['Deutsch', 'Chemie'], 'k1', true]);
pruefe('… nach Kind gefiltert', $pf->pAktion(['action' => 'list', 'childId' => 'k2'])['exams'], []);
pruefe('… Lernstart = der Tag, an dem die Pruefung im Plan erschien (seit)', $liste['exams'][0]['learnFrom'], $HEUTE);
$st = $pf->pStand();
$st['kinder']['k1']['exams'][0]['seit'] = 0;
$pf->attr['UntisPruefungen'] = json_encode($st);
pruefe('… ohne diesen Tag: der Vorlauf der Lern-Erinnerung', $pf->pAktion(['action' => 'list'])['exams'][0]['learnFrom'], $tag(4 - 7));

// ══ 4. Vorabend-Meldung, Schulzeile, Briefing ═══════════════════════════════
final class VorabendProbe extends IPSModuleStrict
{
    use WebPush, WebUntis, UntisLesen {
        UntisLesen::UntisProp insteadof WebUntis;
    }

    public array $attr = [];
    public array $cfg = [];
    public array $gesendet = [];
    public array $leer = [];

    public function Translate(string $Text): string { return $Text; }
    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    protected function LogMessage(string $Message, int $Type): bool { return true; }
    protected function ReadAttributeString(string $Name): string { return (string)($this->attr[$Name] ?? ''); }
    protected function WriteAttributeString(string $Name, string $Value): bool { $this->attr[$Name] = $Value; return true; }
    private function ReadAttributeStringSafe(string $name, string $vorgabe): string
    {
        $w = $this->attr[$name] ?? '';
        return $w !== '' ? $w : $vorgabe;
    }
    private function PushProp(string $name, mixed $vorgabe): mixed { return array_key_exists($name, $this->cfg) ? $this->cfg[$name] : $vorgabe; }
    private function PushSubscriptions(string $userId = ''): array { return $userId === 'k1' ? [] : ['a']; }
    private function LoadUsers(): array { return [['id' => 'k1', 'name' => 'Joshua', 'persona' => 'child']]; }
    private function PushBroadcast(string $titel, string $text, string $userId = '', string $tab = '', int $badge = -1, array $extra = []): array
    {
        if ($this->leer !== []) {
            array_shift($this->leer);
            return ['sent' => 0, 'failed' => 0, 'dropped' => 0, 'stale' => 0, 'blocked' => 0];
        }
        $this->gesendet[] = [$titel, $text, $userId];
        return ['sent' => 1, 'failed' => 0, 'dropped' => 0, 'stale' => 0, 'blocked' => 0];
    }
    public function pLauf(): void { (new ReflectionMethod(self::class, 'PushExamRun'))->invoke($this); }
    public function pArmBedingung(): bool { return (new ReflectionMethod(self::class, 'PushExamAn'))->invoke($this); }
}

$jetztMin = (int)date('H') * 60 + (int)date('i');
if ($jetztMin >= 2 && $jetztMin <= 1437) {
    $vp = new VorabendProbe(4003);
    $vp->attr['UntisPruefungen'] = json_encode(['v' => 1, 'rev' => 1, 'kinder' => ['k1' => ['seit' => 1, 'name' => 'Joshua', 'exams' => [
        $p(['key' => 1, 'ids' => [1], 'date' => $tag(1), 'start' => '10:35', 'subject' => 'Deutsch', 'title' => 'KA Briefe schreiben']),
        $p(['key' => 2, 'ids' => [2], 'date' => $tag(1), 'start' => '12:00', 'subject' => 'Sport', 'status' => 'entfall']),
        $p(['key' => 3, 'ids' => [3], 'date' => $tag(2), 'start' => '08:00', 'subject' => 'Mathe']),
    ]]]]);
    $zeit = static fn(int $m): string => json_encode(['hour' => intdiv($m, 60), 'minute' => $m % 60, 'second' => 0]);
    $vp->cfg = ['UntisExamPush' => true, 'UntisEnabled' => true, 'PushHomeworkTime' => $zeit($jetztMin + 1)];
    $vp->pLauf();
    pruefe('Vorabend: vor der Uhrzeit nichts', $vp->gesendet, []);
    $vp->cfg['PushHomeworkTime'] = $zeit($jetztMin - 1);
    $vp->leer = [1];
    $vp->pLauf();
    pruefe('Laufender Versand (alle Zaehler 0): nicht gemerkt', [$vp->gesendet, isset(json_decode($vp->attr['PushSent'] ?? '{}', true)['ex:' . $tag(1) . ':k1'])], [[], false]);
    $vp->pLauf();
    $vp->pLauf();
    pruefe('Danach genau EINE Meldung: morgen, ohne die entfallene, an den Haushalt (Kind ohne Geraet)',
        $vp->gesendet, [['Exam tomorrow', 'Joshua: 10:35 Deutsch — KA Briefe schreiben', '']]);
    $vp->cfg['UntisEnabled'] = false;
    pruefe('Ohne WebUntis haengt der Minutentakt nicht an der Pruefung', $vp->pArmBedingung(), false);
} else {
    echo "     (Vorabend-Pruefung um Mitternacht uebersprungen)\n";
}
$push = (string)file_get_contents(__DIR__ . '/../libs/WebPush.php');
pruefe('PushArm schaltet den Takt auch fuer die Pruefung', str_contains($push, '|| $this->PushExamAn())'), true);
pruefe('Der Minutenlauf ruft die Vorabend-Meldung', str_contains($push, '$this->PushHomeworkRun();' . "\n" . '            $this->PushExamRun();'), true);

final class SchulProbe
{
    use TimetableBridge;

    public function pZeile(array $tag): string
    {
        return (string)(new ReflectionMethod(self::class, 'TimetableSchoolLine'))->invoke($this, 'Joshua', $tag, []);
    }
}
$sp = new SchulProbe();
$slot = static fn(array $x): array => $x + ['name' => 'Mathe', 'start' => '08:00', 'end' => '09:00', 'from' => 480, 'to' => 540,
    'care' => false, 'status' => '', 'exam' => false, 'examTitle' => ''];
pruefe('Schulzeile nennt die Pruefung des Tages mit Titel',
    $sp->pZeile(['slots' => [$slot([]), $slot(['name' => 'Deutsch', 'start' => '10:35', 'end' => '11:35', 'from' => 635, 'to' => 695,
        'exam' => true, 'examTitle' => 'KA Briefe schreiben', 'status' => 'vertretung'])]]),
    'Joshua: Schule von 08:00 bis 11:35, Prüfung in Deutsch um 10:35 („KA Briefe schreiben“)');
pruefe('… und eine entfallene Pruefung nicht als „Deutsch entfällt"',
    $sp->pZeile(['slots' => [$slot([]), $slot(['name' => 'Deutsch', 'start' => '10:35', 'end' => '11:35', 'from' => 635, 'to' => 695,
        'exam' => true, 'status' => 'entfall'])]]),
    'Joshua: Schule von 08:00 bis 09:00, die Prüfung in Deutsch entfällt');

final class BriefingProbe
{
    use Briefing;

    public string $stil = 'detailed';
    private function BriefingProp(string $name, mixed $vorgabe): mixed { return $name === 'BriefingStyle' ? $this->stil : $vorgabe; }
    private function BriefingHaushalt(): bool { return true; }
    private function BriefingToneRule(): string { return ''; }
    public function pNutzer(array $daten): string { return (new ReflectionMethod(self::class, 'BriefingUserText'))->invoke($this, $daten); }
    public function pSystem(): string { return (new ReflectionMethod(self::class, 'BriefingSystemPrompt'))->invoke($this, 'heute', 0); }
}
$bp = new BriefingProbe();
$daten = ['tage' => 0, 'name' => 'Familie', 'haushalt' => true, 'termine' => [], 'aufgaben' => [], 'ueberfaellig' => [],
    'geburtstage' => [], 'rollen' => [], 'einkauf' => ['anzahl' => 0, 'liste' => ''], 'schule' => ['Joshua: Schule von 08:00 bis 13:00'],
    'pruefungen' => ['Joshua: Prüfung Englisch am Dienstag, 06.10. um 09:30 — in 3 Tagen']];
$nutzer = $bp->pNutzer($daten);
pruefe('Briefing: Block KOMMENDE PRÜFUNGEN hinter den Schulzeiten',
    [str_contains($nutzer, "KOMMENDE PRÜFUNGEN (die einzige Angabe über andere Tage — Tag und Abstand genau so übernehmen): \n- Joshua: Prüfung Englisch"),
     strpos($nutzer, 'SCHULZEITEN') < strpos($nutzer, 'KOMMENDE PRÜFUNGEN')], [true, true]);
pruefe('… ohne Pruefungen kein Block', str_contains($bp->pNutzer(['pruefungen' => []] + $daten), 'KOMMENDE'), false);
foreach (['detailed', 'compact'] as $stil) {
    $bp->stil = $stil;
    $sys = $bp->pSystem();
    pruefe("Prompt ($stil): MUSS-Regel fuer Pruefungen und die eine Ausnahme vom Tagesbezug",
        [str_contains($sys, 'PRÜFUNG, MUSS'), str_contains($sys, 'KOMMENDE PRÜFUNGEN'),
         str_contains($sys, 'Die EINZIGE Ausnahme davon sind die KOMMENDEN PRÜFUNGEN')], [true, true, true]);
}

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
