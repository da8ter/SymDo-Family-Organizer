<?php

declare(strict_types=1);

/**
 * LOGINEO NRW LMS (Moodle) — das Rechenwerk, ohne Symcon.
 *
 * Keine Symcon-Aufrufe, kein Netz, keine Uhr: alles kommt als Parameter herein.
 * Damit läuft es im Prüfstand (SymDoGateway/tests/MoodleCalcTest.php).
 * Vorbild: SymDoGateway/libs/EduStoreCalc.php und HomeworkCalc.php.
 *
 * Hier steht vor allem die WEISSE LISTE. Sie ist die wichtigste Zeile dieses
 * Umbaus: Auf dem geprüften Schulkonto sind auch Schreibfunktionen freigegeben
 * (`core_calendar_create_calendar_events`, `core_calendar_delete_calendar_events`,
 * `mod_forum_add_discussion`, `mod_assign_save_grade` und weitere). Ein
 * Tippfehler im Funktionsnamen darf nichts in die Schule schreiben — deshalb
 * darf nur gerufen werden, was hier namentlich steht.
 */
class MoodleCalc
{
    /** Der Dienst, den die Moodle-App benutzt — nur er gibt einen Token her. */
    public const SERVICE = 'moodle_mobile_app';

    /**
     * Alles, was das Modul rufen darf. Ausschliesslich lesende Funktionen.
     *
     * Wer hier etwas hinzufügt, prüft zweierlei: Verändert der Aufruf etwas in
     * der Schule (auch „nur gelesen"-Marken wie `mod_forum_view_forum` oder
     * `core_completion_*` hinterlassen Spuren im Kurs)? Und braucht das Modul
     * ihn wirklich?
     *
     * @var list<string>
     */
    public const ERLAUBT = [
        // Wer bin ich, was darf ich, welche Funktionen gibt dieser Server her.
        'core_webservice_get_site_info',
        // Die Kurse des Kontos.
        'core_enrol_get_users_courses',
        // Abschnitte, Module und Dateien eines Kurses.
        'core_course_get_contents',
        // Foren und ihre Beiträge (Elternpost, Ankündigungen).
        'mod_forum_get_forums_by_courses',
        'mod_forum_get_forum_discussions',
        // Aufgaben mit Fälligkeit und der eigene Abgabestand.
        'mod_assign_get_assignments',
        'mod_assign_get_submission_status',
        // Termine: die Zeitleiste und der Kalender.
        'core_calendar_get_action_events_by_timesort',
        'core_calendar_get_calendar_events',
    ];

    /**
     * Wortstämme, an denen eine schreibende Funktion zu erkennen ist.
     *
     * Zweiter Riegel hinter der weissen Liste, und zwar mit Absicht doppelt:
     * die Liste schützt gegen Tippfehler, dieser Test gegen einen
     * unbedachten EINTRAG in die Liste. Er läuft im Prüfstand über jeden
     * Namen der Liste.
     *
     * @var list<string>
     */
    public const SCHREIBEND = ['create', 'delete', 'save', 'add_', 'update', 'set_',
                               'submit', 'mark_', 'start_', 'process_', 'view_',
                               'remove', 'edit_', 'send_', 'unenrol', 'upload'];

    /** Darf diese Funktion gerufen werden? */
    public static function Erlaubt(string $funktion): bool
    {
        return in_array(trim($funktion), self::ERLAUBT, true);
    }

    /**
     * Sieht dieser Funktionsname aus, als würde er etwas verändern?
     *
     * Nicht als Ersatz für die weisse Liste gedacht, sondern als Probe DARÜBER:
     * ein Name, der hier anschlägt, gehört nicht in die Liste.
     */
    public static function SchreibVerdacht(string $funktion): bool
    {
        $n = mb_strtolower(trim($funktion));
        foreach (self::SCHREIBEND as $wort) {
            if (str_contains($n, $wort)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Die Adresse eines Servers in Form bringen: „https://host" ohne Schrägstrich.
     *
     * Leer heisst „untauglich" — der Aufrufer bricht dann ab. Ohne Schema wird
     * https angenommen; http bleibt verboten, weil ein Token darüber reisen
     * würde.
     */
    public static function SiteSauber(string $roh): string
    {
        $s = trim($roh);
        if ($s === '') {
            return '';
        }
        if (!str_contains($s, '://')) {
            $s = 'https://' . $s;
        }
        if (!str_starts_with($s, 'https://')) {
            return '';
        }
        $teile = parse_url($s);
        $host  = is_array($teile) ? trim((string)($teile['host'] ?? '')) : '';
        if ($host === '' || !str_contains($host, '.')) {
            return '';
        }
        /* Ein Pfad wird abgeschnitten: die Web-Service-Adressen hängen an der
           WURZEL, und eine mitkopierte Kursadresse („…/course/view.php?id=77")
           machte daraus Unsinn. */
        return 'https://' . $host;
    }

    /**
     * Die Zugangszeilen in Form bringen.
     *
     * Eine Zeile ohne Adresse oder ohne Familienmitglied fällt weg: beides ist
     * die Zuordnung, ohne sie weiss niemand, WESSEN Kurse das sind.
     *
     * @param list<array<string,mixed>>|mixed $roh
     * @return list<array{name:string,site:string,user:string,userId:string,stpl:int}>
     */
    public static function Konten(mixed $roh): array
    {
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $z) {
            if (!is_array($z)) {
                continue;
            }
            $site   = self::SiteSauber((string)($z['site'] ?? ''));
            $userId = trim((string)($z['userId'] ?? ''));
            if ($site === '' || $userId === '') {
                continue;
            }
            $raus[] = [
                'name'   => trim((string)($z['name'] ?? '')),
                'site'   => $site,
                'user'   => trim((string)($z['user'] ?? '')),
                'userId' => $userId,
                'stpl'   => (int)($z['stpl'] ?? 0),
            ];
        }
        return $raus;
    }

    /**
     * Der Schlüssel, unter dem der Token eines Zugangs liegt.
     *
     * Adresse UND Mitglied: zwei Kinder an derselben Schule haben verschiedene
     * Konten, ein Kind an zwei Schulen zwei Zugänge.
     */
    public static function TokenSchluessel(string $site, string $userId): string
    {
        return mb_strtolower(self::SiteSauber($site)) . '|' . trim($userId);
    }

    /**
     * Fehlt eine gebrauchte Funktion auf diesem Server?
     *
     * @param list<string> $vorhanden Namen aus core_webservice_get_site_info
     * @return list<string> die fehlenden, in der Reihenfolge der weissen Liste
     */
    public static function FehlendeFunktionen(array $vorhanden): array
    {
        $da = [];
        foreach ($vorhanden as $f) {
            $da[trim((string)$f)] = true;
        }
        $fehlt = [];
        foreach (self::ERLAUBT as $f) {
            if (!array_key_exists($f, $da)) {
                $fehlt[] = $f;
            }
        }
        return $fehlt;
    }
}
