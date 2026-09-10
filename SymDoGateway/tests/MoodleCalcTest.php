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
pruefe('neun Funktionen sind erlaubt', count(MoodleCalc::ERLAUBT), 9);
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

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
