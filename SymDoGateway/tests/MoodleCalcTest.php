<?php

declare(strict_types=1);

/**
 * Offline-Prüfstand für LOGINEO NRW LMS (Moodle).
 *
 * Der Kern dieses Prüfstands ist die WEISSE LISTE. Auf dem geprüften Schulkonto
 * (10.09.2026, Moodle 4.5.13) sind 435 Funktionen freigegeben — darunter
 * `core_calendar_create_calendar_events`, `core_calendar_delete_calendar_events`,
 * `mod_forum_add_discussion` und `mod_assign_save_grade`. Das Modul darf in die
 * Schule nichts schreiben; die einzige Sicherung dagegen ist, dass nur gerufen
 * wird, was namentlich auf der Liste steht. Also wird hier geprüft, dass die
 * Liste hält — und dass kein Name auf ihr schreibend aussieht.
 *
 * Läuft ohne Symcon gegen die Symcon-Stubs (anderer Pfad über SYMCON_STUBS):
 *
 *   php SymDoGateway/tests/MoodleCalcTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/MoodleCalc.php';

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

// ── Die weisse Liste ───────────────────────────────────────────────────────
pruefe('elf Funktionen sind erlaubt — neun nötige und zwei optionale',
    count(MoodleCalc::ERLAUBT), 11);
pruefe('Standortauskunft erlaubt', MoodleCalc::Erlaubt('core_webservice_get_site_info'), true);
pruefe('Kurse erlaubt', MoodleCalc::Erlaubt('core_enrol_get_users_courses'), true);
pruefe('Kursinhalt erlaubt', MoodleCalc::Erlaubt('core_course_get_contents'), true);
pruefe('Abgabestand erlaubt', MoodleCalc::Erlaubt('mod_assign_get_submission_status'), true);

/* Genau die Funktionen, die auf dem Schulkonto WIRKLICH freigegeben sind und
   etwas verändern würden. Jede einzelne muss abgewiesen werden. */
foreach (['core_calendar_create_calendar_events', 'core_calendar_delete_calendar_events',
          'core_calendar_submit_create_update_form', 'core_calendar_update_event_start_day',
          'mod_forum_add_discussion', 'mod_forum_add_discussion_post', 'mod_forum_view_forum',
          'mod_assign_save_grade', 'mod_assign_save_grades', 'mod_assign_submit_for_grading',
          'core_notes_create_notes', 'core_notes_delete_notes',
          'core_completion_mark_course_self_completed',
          'core_completion_update_activity_completion_status_manually',
          'core_message_delete_message', 'mod_quiz_start_attempt',
          'core_blog_add_entry', 'core_comment_add_comments'] as $f) {
    pruefe('abgewiesen: ' . $f, MoodleCalc::Erlaubt($f), false);
}
pruefe('leerer Name abgewiesen', MoodleCalc::Erlaubt(''), false);
pruefe('Grossschreibung hilft nicht', MoodleCalc::Erlaubt('CORE_COURSE_GET_CONTENTS'), false);
pruefe('angehängtes Zeichen hilft nicht', MoodleCalc::Erlaubt('core_course_get_contents;'), false);
pruefe('Leerzeichen um den Namen sind erlaubt',
    MoodleCalc::Erlaubt("  core_course_get_contents\n"), true);

// ── Die Probe ÜBER der Liste: kein Eintrag darf schreibend aussehen ───────
$verdaechtig = array_values(array_filter(MoodleCalc::ERLAUBT,
    static fn(string $f): bool => MoodleCalc::SchreibVerdacht($f)));
pruefe('kein Eintrag der weissen Liste sieht schreibend aus', $verdaechtig, []);
pruefe('der Verdacht erkennt „create"', MoodleCalc::SchreibVerdacht('core_calendar_create_calendar_events'), true);
pruefe('der Verdacht erkennt „view_"', MoodleCalc::SchreibVerdacht('mod_forum_view_forum'), true);
pruefe('der Verdacht erkennt „mark_"', MoodleCalc::SchreibVerdacht('core_completion_mark_course_self_completed'), true);
pruefe('der Verdacht schlägt bei „get" nicht an', MoodleCalc::SchreibVerdacht('core_course_get_contents'), false);

// ── Fehlende Funktionen auf einem fremden Server ─────────────────────────
pruefe('vollständiger Server: nichts fehlt',
    MoodleCalc::FehlendeFunktionen(MoodleCalc::ERLAUBT), []);
pruefe('nackter Server: alles fehlt',
    count(MoodleCalc::FehlendeFunktionen([])), 9);
pruefe('nur die Foren fehlen',
    MoodleCalc::FehlendeFunktionen(array_values(array_filter(MoodleCalc::ERLAUBT,
        static fn(string $f): bool => !str_contains($f, 'forum')))),
    ['mod_forum_get_forums_by_courses', 'mod_forum_get_forum_discussions']);

// ── Die Adresse ──────────────────────────────────────────────────────────
pruefe('Adresse ohne Schema wird https',
    MoodleCalc::SiteSauber('12345.logineonrw-lms.de'), 'https://12345.logineonrw-lms.de');
pruefe('Schrägstrich fällt weg',
    MoodleCalc::SiteSauber('https://12345.logineonrw-lms.de/'), 'https://12345.logineonrw-lms.de');
pruefe('ein mitkopierter Pfad fällt weg',
    MoodleCalc::SiteSauber('https://12345.logineonrw-lms.de/course/view.php?id=77'),
    'https://12345.logineonrw-lms.de');
pruefe('http ist verboten', MoodleCalc::SiteSauber('http://12345.logineonrw-lms.de'), '');
pruefe('ein Host ohne Punkt ist untauglich', MoodleCalc::SiteSauber('https://localhost'), '');
pruefe('leer bleibt leer', MoodleCalc::SiteSauber('   '), '');

// ── Die Zugangszeilen ────────────────────────────────────────────────────
$roh = [
    ['name' => 'Vincent', 'site' => '12345.logineonrw-lms.de/', 'user' => 'kind.eins',
     'userId' => 'aaa11111', 'stpl' => 22469],
    // ohne Mitglied: fällt weg, denn niemand weiss, wessen Kurse das wären
    ['name' => 'ohne Kind', 'site' => 'https://12345.logineonrw-lms.de', 'user' => 'x', 'userId' => ''],
    // ohne Adresse: fällt ebenso weg
    ['name' => 'ohne Adresse', 'site' => '', 'user' => 'y', 'userId' => 'bbb22222'],
    // http: die Adresse wird verworfen, also fällt die Zeile weg
    ['name' => 'unsicher', 'site' => 'http://12345.logineonrw-lms.de', 'user' => 'z', 'userId' => 'ccc33333'],
    'unsinn',
];
$konten = MoodleCalc::Konten($roh);
pruefe('nur die brauchbare Zeile bleibt', count($konten), 1);
pruefe('die Zeile ist in Form', $konten[0],
    ['name' => 'Vincent', 'site' => 'https://12345.logineonrw-lms.de',
     'user' => 'kind.eins', 'userId' => 'aaa11111', 'stpl' => 22469]);
pruefe('kaputt gelesen ergibt keine Zeile', MoodleCalc::Konten('unsinn'), []);

// ── Der Tokenschlüssel ───────────────────────────────────────────────────
pruefe('Schlüssel aus Adresse und Mitglied',
    MoodleCalc::TokenSchluessel('https://12345.logineonrw-lms.de/', 'aaa11111'),
    'https://12345.logineonrw-lms.de|aaa11111');
pruefe('zwei Kinder an derselben Schule sind zwei Schlüssel',
    MoodleCalc::TokenSchluessel('12345.logineonrw-lms.de', 'aaa11111')
        !== MoodleCalc::TokenSchluessel('12345.logineonrw-lms.de', 'bbb22222'), true);
pruefe('ein Kind an zwei Schulen ebenso',
    MoodleCalc::TokenSchluessel('12345.logineonrw-lms.de', 'aaa11111')
        !== MoodleCalc::TokenSchluessel('99999.logineonrw-lms.de', 'aaa11111'), true);
pruefe('Gross- und Kleinschreibung der Adresse trennt nicht',
    MoodleCalc::TokenSchluessel('12345.LOGINEONRW-LMS.de', 'aaa11111'),
    MoodleCalc::TokenSchluessel('12345.logineonrw-lms.de', 'aaa11111'));

// ── Optionale Funktionen ─────────────────────────────────────────────────
pruefe('die beiden Abstimmungs-Aufrufe sind optional',
    MoodleCalc::OPTIONAL, ['mod_choice_get_choices_by_courses', 'mod_choice_get_choice_options']);
pruefe('ein Server ohne Abstimmungen meldet nichts als fehlend',
    MoodleCalc::FehlendeFunktionen(array_values(array_filter(MoodleCalc::ERLAUBT,
        static fn(string $f): bool => !str_contains($f, 'choice')))), []);
pruefe('… kann dafür aber keine Abstimmungen',
    MoodleCalc::KannAbstimmungen(array_values(array_filter(MoodleCalc::ERLAUBT,
        static fn(string $f): bool => !str_contains($f, 'choice')))), false);
pruefe('ein vollständiger Server kann sie', MoodleCalc::KannAbstimmungen(MoodleCalc::ERLAUBT), true);
pruefe('halb reicht nicht',
    MoodleCalc::KannAbstimmungen(['mod_choice_get_choices_by_courses']), false);

// ── Abstimmung → Aufgabe mit Frist ───────────────────────────────────────
$jetzt = mktime(12, 0, 0, 9, 10, 2026);
$offen = ['id' => 117, 'coursemodule' => 5331, 'course' => 99, 'name' => 'Fotos auf LOGINEO',
          'timeopen' => $jetzt - 86400, 'timeclose' => $jetzt + 5 * 86400];

$z = MoodleCalc::AbstimmungAufgabe($offen, [], $jetzt);
pruefe('eine offene Abstimmung wird eine Aufgabe', $z !== null, true);
pruefe('die Frist ist die Fälligkeit', $z['due'], date('Y-m-d', $jetzt + 5 * 86400));
pruefe('ohne Antwort ist sie offen', $z['done'], false);
pruefe('die Kennung liegt im eigenen Zahlenraum', $z['srcId'], MoodleCalc::ID_ABSTIMMUNG + 5331);
pruefe('… und kann keiner Aufgabe gleichen', $z['srcId'] > 100000000, true);
pruefe('der Name kommt unverändert mit', $z['name'], 'Fotos auf LOGINEO');

pruefe('eine angekreuzte Option heisst erledigt',
    MoodleCalc::AbstimmungAufgabe($offen, [['id' => 1, 'text' => 'ja', 'checked' => false],
                                           ['id' => 2, 'text' => 'nein', 'checked' => true]], $jetzt)['done'], true);
pruefe('keine angekreuzte Option heisst offen',
    MoodleCalc::AbstimmungAufgabe($offen, [['id' => 1, 'text' => 'ja', 'checked' => false]], $jetzt)['done'], false);
/* Moodle antwortet bei einer gesperrten Abstimmung mit einer WARNUNG und einer
   leeren Optionsliste. Dann lieber erinnern als schweigen. */
pruefe('leere Optionsliste gilt als „noch nicht beantwortet"',
    MoodleCalc::AbstimmungAufgabe($offen, [], $jetzt)['done'], false);
pruefe('Unsinn in der Optionsliste stürzt nicht',
    MoodleCalc::AbstimmungAufgabe($offen, ['kaputt', 42], $jetzt)['done'], false);

pruefe('ohne Frist ist es keine Aufgabe',
    MoodleCalc::AbstimmungAufgabe(array_merge($offen, ['timeclose' => 0]), [], $jetzt), null);
pruefe('eine abgelaufene Abstimmung ebenso',
    MoodleCalc::AbstimmungAufgabe(array_merge($offen, ['timeclose' => $jetzt - 60]), [], $jetzt), null);
pruefe('die Frist von heute Nacht gilt noch',
    MoodleCalc::AbstimmungAufgabe(array_merge($offen, ['timeclose' => $jetzt + 60]), [], $jetzt) !== null, true);
pruefe('ohne Namen nichts', MoodleCalc::AbstimmungAufgabe(array_merge($offen, ['name' => '  ']), [], $jetzt), null);
pruefe('ohne Modulkennung nichts',
    MoodleCalc::AbstimmungAufgabe(array_merge($offen, ['coursemodule' => 0]), [], $jetzt), null);
pruefe('eine leere Antwort ergibt nichts', MoodleCalc::AbstimmungAufgabe([], [], $jetzt), null);

// ── Zwei Terminwege, eine Liste ──────────────────────────────────────────
$zeitleiste = [
    ['id' => 5, 'name' => 'Abgabe Lesetagebuch', 'timesort' => $jetzt + 3600],
    ['id' => 6, 'name' => 'Rückmeldung Fotos', 'timesort' => $jetzt + 7200],
];
$kalender = [
    ['id' => 6, 'name' => 'Rückmeldung Fotos', 'timestart' => $jetzt + 7200],   // dasselbe Ereignis
    ['id' => 9, 'name' => 'Schließtag OGS', 'timestart' => $jetzt + 86400],
    ['id' => 0, 'name' => 'ohne Kennung', 'timestart' => $jetzt],
    ['id' => 10, 'name' => '', 'timestart' => $jetzt],                          // ohne Namen
    ['id' => 11, 'name' => 'ohne Zeit', 'timestart' => 0],
    'unsinn',
];
$vereint = MoodleCalc::TermineVereinen($zeitleiste, $kalender);
/* Vier: die beiden der Zeitleiste, der Schliesstag — und der Termin ohne
   Kennung, der am Namen unterschieden wird. Ohne Namen oder ohne Zeit faellt
   einer weg, Unsinn ebenso. */
pruefe('vier Termine bleiben übrig', count($vereint), 4);
pruefe('die Zeitleiste steht vorn', $vereint[0]['name'], 'Abgabe Lesetagebuch');
pruefe('das doppelte Ereignis kommt nur einmal',
    count(array_filter($vereint, static fn(array $e): bool => (int)$e['id'] === 6)), 1);
pruefe('der Schließtag kommt aus dem Kalender', $vereint[2]['name'], 'Schließtag OGS');
pruefe('ein Ereignis ohne Kennung fällt nicht weg, wenn es Name und Zeit hat',
    count(MoodleCalc::TermineVereinen([], [['id' => 0, 'name' => 'x', 'timestart' => $jetzt]])), 1);
pruefe('zwei verschiedene Zeiten desselben Termins sind zwei Einträge',
    count(MoodleCalc::TermineVereinen(
        [['id' => 7, 'name' => 'Serie', 'timesort' => $jetzt]],
        [['id' => 7, 'name' => 'Serie', 'timestart' => $jetzt + 86400]])), 2);
pruefe('zwei leere Listen ergeben nichts', MoodleCalc::TermineVereinen([], []), []);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
