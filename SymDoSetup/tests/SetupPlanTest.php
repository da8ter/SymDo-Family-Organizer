<?php

declare(strict_types=1);

/**
 * Offline-Prüfstand für den Installationsassistenten.
 *
 * Der Assistent setzt alles in EINEM Zug um — unbeobachtet und in einem
 * eingerichteten Haushalt. Was hier nicht geprüft ist, prüft niemand: der
 * scharfe Lauf ist kein Prüflauf. Deshalb steht die ganze Entscheidungslogik
 * in SetupPlan (ohne Symcon) und wird hier gegen die Stubs gefahren.
 *
 * Läuft ohne Symcon (Pfad über SYMCON_STUBS):
 *
 *   php SymDoSetup/tests/SetupPlanTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/SetupPlan.php';

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
    printf("%-4s %-58s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/** Die Schritte eines Plans als „art[:key]=aktion" — kurz und vergleichbar. */
function kurz(array $plan): array
{
    return array_map(static function (array $s): string {
        $kopf = (string)$s['art'] . (isset($s['key']) ? ':' . $s['key'] : '');
        return $kopf . '=' . (string)($s['aktion'] ?? '?');
    }, $plan['schritte']);
}

const BEREIT = 10103;

// ── Die Bausteintabelle ────────────────────────────────────────────────────
$guids = array_column(SetupPlan::BAUSTEINE, 'guid');
pruefe('elf Bausteine', count(SetupPlan::BAUSTEINE), 11);
pruefe('jede GUID nur einmal', count(array_unique($guids)), count($guids));
pruefe('jede GUID hat die Form einer GUID',
    count(array_filter($guids, static fn(string $g): bool
        => preg_match('/^\{[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}\}$/', $g) === 1)),
    count($guids));
pruefe('das Gateway steht NICHT in der Tabelle',
    in_array(SetupPlan::GATEWAY_GUID, $guids, true), false);
pruefe('jeder Baustein hat einen Namen',
    count(array_filter(array_column(SetupPlan::BAUSTEINE, 'name'), static fn($n) => trim((string)$n) !== '')), 11);
/* Die Reihenfolge ist der Vertrag: ein Verweisziel muss VOR dem Verweis
   stehen, sonst zeigt der Ausführer auf eine Instanz, die es noch nicht gibt. */
$reihe = array_keys(SetupPlan::BAUSTEINE);
foreach (SetupPlan::BAUSTEINE as $key => $b) {
    foreach ((array)($b['verweise'] ?? []) as $prop => $ziel) {
        pruefe("„$key" . "→$ziel" . '" steht in der richtigen Reihenfolge',
            array_search($ziel, $reihe, true) < array_search($key, $reihe, true), true);
    }
}
pruefe('die Web-App ist die letzte', $reihe[count($reihe) - 1], 'webapp');

// ── Der Schlüssel der Mitglieder-Identität ─────────────────────────────────
pruefe('Gross- und Kleinschreibung trennt nicht',
    SetupPlan::Schluessel('Tim'), SetupPlan::Schluessel('tim'));
pruefe('Leerzeichen am Rand trennen nicht',
    SetupPlan::Schluessel('  Mia '), 'mia');
pruefe('Umlaute bleiben', SetupPlan::Schluessel('Jörg'), 'jörg');

// ── Zusammenführen: der Kern ───────────────────────────────────────────────
$vorhanden = [
    ['name' => 'Tim', 'lastName' => 'Sprick', 'birthday' => ['year' => 2015, 'month' => 4, 'day' => 2],
     'persona' => 'child', 'photo' => 4711, 'visu' => 815, 'id' => '57648139'],
    ['name' => 'Anna', 'lastName' => '', 'birthday' => ['year' => 0, 'month' => 0, 'day' => 0],
     'persona' => '', 'photo' => 0, 'visu' => 0, 'id' => 'aa11bb22'],
];
$neue = [
    // dasselbe Kind: nichts darf sich ändern
    ['name' => 'tim', 'lastName' => 'Falsch', 'persona' => 'father'],
    // Anna: leere Felder ergänzen
    ['name' => 'Anna', 'lastName' => 'Sprick', 'persona' => 'mother'],
    // neu
    ['name' => 'Mia', 'lastName' => '', 'persona' => 'child',
     'birthday' => ['year' => 2019, 'month' => 9, 'day' => 1]],
    // fällt weg: ohne Vornamen ist es keine Person
    ['name' => '   ', 'persona' => 'child'],
    'unsinn',
];
$z = SetupPlan::MitgliederZusammenfuehren($vorhanden, $neue, ['cc33dd44']);
pruefe('drei Zeilen danach', count($z['users']), 3);
pruefe('eine neu, eine ergänzt, zwei übergangen',
    [$z['neu'], $z['ergaenzt'], $z['uebergangen']], [1, 1, 2]);
pruefe('die Kennung des Kindes bleibt', $z['users'][0]['id'], '57648139');
pruefe('der Nachname wird NICHT überschrieben', $z['users'][0]['lastName'], 'Sprick');
pruefe('die Rolle wird NICHT überschrieben', $z['users'][0]['persona'], 'child');
pruefe('Foto und Visu bleiben unberührt',
    [$z['users'][0]['photo'], $z['users'][0]['visu']], [4711, 815]);
pruefe('bei Anna wird der leere Nachname ergänzt', $z['users'][1]['lastName'], 'Sprick');
pruefe('… und die leere Rolle', $z['users'][1]['persona'], 'mother');
pruefe('die neue Zeile bekommt die vorgegebene Kennung', $z['users'][2]['id'], 'cc33dd44');
pruefe('… und ihren Geburtstag als OBJEKT',
    $z['users'][2]['birthday'], ['year' => 2019, 'month' => 9, 'day' => 1]);
pruefe('… und Foto/Visu auf 0', [$z['users'][2]['photo'], $z['users'][2]['visu']], [0, 0]);
pruefe('gelöscht wird nie',
    array_column($z['users'], 'name'), ['Tim', 'Anna', 'Mia']);

$z2 = SetupPlan::MitgliederZusammenfuehren($vorhanden, [], []);
pruefe('ohne neue Zeilen ändert sich nichts', $z2['users'], $vorhanden);
pruefe('… und es gilt als unverändert', [$z2['neu'], $z2['ergaenzt']], [0, 0]);
$z3 = SetupPlan::MitgliederZusammenfuehren([], [['name' => 'Solo']], []);
pruefe('ohne Kennungsvorrat entsteht keine Zeile', count($z3['users']), 0);
pruefe('… und sie gilt als übergangen', $z3['uebergangen'], 1);
pruefe('ein Datum aus Nullen gilt als leer und wird ergänzt',
    SetupPlan::MitgliederZusammenfuehren(
        [['name' => 'Leer', 'birthday' => ['year' => 0, 'month' => 0, 'day' => 0], 'id' => 'x1']],
        [['name' => 'Leer', 'birthday' => ['year' => 2000, 'month' => 1, 'day' => 2]]],
        []
    )['users'][0]['birthday'], ['year' => 2000, 'month' => 1, 'day' => 2]);
pruefe('eine unbekannte Rolle wird zu „keine Angabe"', SetupPlan::Rolle('chef'), '');
pruefe('eine bekannte Rolle bleibt', SetupPlan::Rolle('grandmother'), 'grandmother');
pruefe('ein kaputter Geburtstag wird zu Nullen',
    SetupPlan::Geburtstag('2019-09-01'), ['year' => 0, 'month' => 0, 'day' => 0]);
pruefe('ein unmöglicher Monat wird gekappt',
    SetupPlan::Geburtstag(['year' => 2019, 'month' => 44, 'day' => 99]),
    ['year' => 2019, 'month' => 12, 'day' => 31]);

// ── Die Vorgabe-Kennung ────────────────────────────────────────────────────
pruefe('das erste KIND gewinnt, nicht die erste Zeile',
    SetupPlan::VorgabeKennung([
        ['name' => 'Anna', 'persona' => 'mother', 'id' => 'a1'],
        ['name' => 'Tim', 'persona' => 'child', 'id' => 't1'],
    ]), 't1');
pruefe('ohne Kind das erste Mitglied',
    SetupPlan::VorgabeKennung([['name' => 'Anna', 'persona' => 'mother', 'id' => 'a1']]), 'a1');
pruefe('ohne Mitglieder bleibt sie leer', SetupPlan::VorgabeKennung([]), '');
pruefe('Zeilen ohne Kennung zählen nicht',
    SetupPlan::VorgabeKennung([['name' => 'X', 'persona' => 'child', 'id' => '']]), '');

// ── Die harten Abbrüche ────────────────────────────────────────────────────
$leerAntwort = ['members' => [['name' => 'Tim']], 'bausteine' => []];
pruefe('vor KR_READY wird nicht geschrieben',
    SetupPlan::Absichten($leerAntwort, ['runlevel' => 10102, 'gateways' => []])['abbruch'],
    SetupPlan::ABBRUCH_KERNEL);
pruefe('zwei Gateways brechen ab',
    SetupPlan::Absichten($leerAntwort, ['runlevel' => BEREIT, 'gateways' => [16011, 41635]])['abbruch'],
    SetupPlan::ABBRUCH_ZWEI_GATEWAYS);
pruefe('… und es entsteht KEIN einziger Schritt',
    SetupPlan::Absichten($leerAntwort, ['runlevel' => BEREIT, 'gateways' => [16011, 41635]])['schritte'], []);
pruefe('zwei Web-Apps brechen ab',
    SetupPlan::Absichten($leerAntwort, ['runlevel' => BEREIT, 'gateways' => [16011],
        'instanzen' => ['webapp' => [111, 222]]])['abbruch'],
    SetupPlan::ABBRUCH_ZWEI_WEBAPPS);
pruefe('ohne ein Mitglied mit Namen bricht es ab',
    SetupPlan::Absichten(['members' => [['name' => ' ']], 'bausteine' => []],
        ['runlevel' => BEREIT, 'gateways' => [16011]])['abbruch'],
    SetupPlan::ABBRUCH_KEIN_MITGLIED);
pruefe('ein Mitglied im BESTAND genügt auch',
    SetupPlan::Absichten(['members' => [], 'bausteine' => []],
        ['runlevel' => BEREIT, 'gateways' => [16011],
         'users' => [['name' => 'Tim', 'id' => 't1']]])['abbruch'], '');
pruefe('KI ohne Schlüssel bricht ab',
    SetupPlan::Absichten($leerAntwort + ['ai' => ['provider' => 'anthropic', 'hasKey' => false]],
        ['runlevel' => BEREIT, 'gateways' => [16011]])['abbruch'],
    SetupPlan::ABBRUCH_KI_OHNE_SCHLUESSEL);
pruefe('KI mit Schlüssel geht durch',
    SetupPlan::Absichten($leerAntwort + ['ai' => ['provider' => 'anthropic', 'hasKey' => true]],
        ['runlevel' => BEREIT, 'gateways' => [16011]])['abbruch'], '');

// ── Der eingerichtete Haushalt: nichts zu tun ──────────────────────────────
/* Das ist die wichtigste Probe des ganzen Prüfstands. Genau dieser Fall trifft
   die Maschine des Nutzers: alles vorhanden, und der Assistent darf NICHTS
   anlegen — schon gar kein zweites Gateway. */
$haushalt = [
    'runlevel'  => BEREIT,
    'gateways'  => [16011],
    'instanzen' => ['todo' => [30001], 'shopping' => [30002], 'webapp' => [30003], 'timetable' => [22469, 59557]],
    'users'     => [['name' => 'Tim', 'persona' => 'child', 'id' => '57648139']],
];
$alles = ['familyName' => 'Sprick', 'members' => [['name' => 'Tim', 'persona' => 'child']],
          'bausteine' => ['todo' => true, 'shopping' => true, 'webapp' => true, 'timetable' => true],
          'access' => false, 'school' => 'none'];
$p = SetupPlan::Absichten($alles, $haushalt);
pruefe('kein Abbruch', $p['abbruch'], '');
pruefe('das Gateway wird übernommen, nicht angelegt', $p['schritte'][0],
    ['art' => 'gateway', 'aktion' => 'vorhanden', 'id' => 16011]);
pruefe('die Mitglieder sind unverändert', $p['schritte'][1]['aktion'], 'unveraendert');
pruefe('nichts wird angelegt',
    array_values(array_filter(kurz($p), static fn(string $s): bool => str_ends_with($s, '=anlegen'))), []);
pruefe('die Bausteine gelten als vorhanden', kurz($p), [
    'gateway=vorhanden', 'mitglieder=unveraendert', 'gateway-daten=schreiben',
    'baustein:shopping=vorhanden', 'baustein:todo=vorhanden',
    'baustein:timetable=vorhanden', 'baustein:webapp=vorhanden',
]);
pruefe('beim Stundenplan gewinnt die niedrigere Instanz',
    array_values(array_filter($p['schritte'],
        static fn(array $s): bool => ($s['key'] ?? '') === 'timetable'))[0]['id'], 22469);

// ── Die leere Anlage: alles muss entstehen ─────────────────────────────────
$leer = ['runlevel' => BEREIT, 'gateways' => [], 'instanzen' => [], 'users' => []];
$wunsch = ['familyName' => 'Sprick',
           'members' => [['name' => 'Anna', 'persona' => 'mother'], ['name' => 'Tim', 'persona' => 'child']],
           'bausteine' => ['todo' => true, 'shopping' => true, 'meal' => true, 'voice' => true, 'webapp' => true],
           'access' => true, 'school' => 'untis'];
$q = SetupPlan::Absichten($wunsch, $leer, ['id-anna', 'id-tim']);
pruefe('das Gateway wird angelegt', $q['schritte'][0]['aktion'], 'anlegen');
pruefe('die Reihenfolge steht', kurz($q), [
    'gateway=anlegen', 'mitglieder=schreiben', 'gateway-daten=schreiben',
    'baustein:shopping=anlegen', 'baustein:todo=anlegen', 'baustein:meal=anlegen',
    'baustein:voice=anlegen', 'baustein:webapp=anlegen', 'zugang=erzeugen',
]);
pruefe('der Zugang ist der LETZTE Schritt',
    $q['schritte'][count($q['schritte']) - 1]['art'], 'zugang');
$meal = array_values(array_filter($q['schritte'], static fn(array $s): bool => ($s['key'] ?? '') === 'meal'))[0];
pruefe('der Essensplan trägt seinen Verweis auf die Einkaufsliste',
    $meal['verweise'], ['ShoppingListInstanceID' => 'shopping']);
$voice = array_values(array_filter($q['schritte'], static fn(array $s): bool => ($s['key'] ?? '') === 'voice'))[0];
pruefe('der Sprachassistent bekommt das KIND als Nutzer',
    $voice['nutzer'], ['UserID' => 'id-tim']);
pruefe('… und beide Listen als Vorgabe',
    $voice['verweise'], ['DefaultTodoID' => 'todo', 'DefaultShoppingID' => 'shopping']);
pruefe('die Mitglieder werden geschrieben, zwei neu',
    [$q['schritte'][1]['aktion'], $q['schritte'][1]['neu']], ['schreiben', 2]);
pruefe('die Schulwahl steht in den Stammdaten', $q['schritte'][2]['school'], 'untis');
pruefe('die KI bleibt AUS, auch wenn ein Anbieter gewählt ist',
    SetupPlan::Absichten($wunsch + ['ai' => ['provider' => 'openai', 'hasKey' => true]],
        $leer, ['a', 'b'])['schritte'][2]['ai'], ['provider' => 'openai', 'enabled' => false]);

// ── Ein fehlendes Modul und eine fremde Liste ──────────────────────────────
$halb = ['runlevel' => BEREIT, 'gateways' => [16011],
         'instanzen' => ['shopping' => [40001]],
         'module'    => ['voice' => false],
         'users'      => [['name' => 'Tim', 'id' => 't1']]];
$r = SetupPlan::Absichten(['members' => [], 'bausteine' => ['shopping' => true, 'voice' => true]], $halb);
/* Eine anders benannte Einkaufsliste zählt TROTZDEM als vorhanden. Im ersten
   Prüflauf auf dem echten System hat die Erkennung am Namen eine zweite Liste
   neben die drei bestehenden gesetzt — „angelegt wird nur, was fehlt" heisst
   eben: irgendeine genügt. */
pruefe('eine anders benannte Einkaufsliste zählt als vorhanden',
    array_values(array_filter($r['schritte'], static fn(array $s): bool => ($s['key'] ?? '') === 'shopping'))[0]['aktion'],
    'vorhanden');
$v = array_values(array_filter($r['schritte'], static fn(array $s): bool => ($s['key'] ?? '') === 'voice'))[0];
pruefe('ein fehlendes Modul wird übersprungen', $v['aktion'], 'uebersprungen');
pruefe('… mit Grund', $v['weil'], 'modul_fehlt');

// ── Nicht gewählte Bausteine kommen gar nicht vor ──────────────────────────
$s = SetupPlan::Absichten(['members' => [], 'bausteine' => ['notes' => true]],
    ['runlevel' => BEREIT, 'gateways' => [16011], 'users' => [['name' => 'Tim', 'id' => 't1']]]);
pruefe('nur der gewählte Baustein steht im Plan',
    array_values(array_filter(kurz($s), static fn(string $x): bool => str_starts_with($x, 'baustein:'))),
    ['baustein:notes=anlegen']);
pruefe('ohne Zugangswunsch kein Zugangsschritt',
    in_array('zugang=erzeugen', kurz($s), true), false);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
