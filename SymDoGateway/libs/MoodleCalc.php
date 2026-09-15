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
        /* Abstimmungen: Rückmeldungen mit Frist („Fotos auf LOGINEO?",
           „Betreuung in den Winterferien?"). Beide Aufrufe sind lesend —
           `mod_choice_submit_choice_response` steht bewusst NICHT hier: eine
           Anmeldung des Kindes gehört nicht in einen Sechs-Stunden-Takt. */
        'mod_choice_get_choices_by_courses',
        'mod_choice_get_choice_options',
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
     * Erlaubt, aber nicht nötig.
     *
     * Abstimmungen sind ein Modul, das eine Schule benutzen kann oder nicht.
     * Fehlen sie, ist nichts kaputt — deshalb dürfen sie in der Statuszeile
     * nicht unter „fehlt" stehen: das läse sich wie ein Defekt.
     *
     * @var list<string>
     */
    public const OPTIONAL = [
        'mod_choice_get_choices_by_courses',
        'mod_choice_get_choice_options',
    ];

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
            if (!array_key_exists($f, $da) && !in_array($f, self::OPTIONAL, true)) {
                $fehlt[] = $f;
            }
        }
        return $fehlt;
    }

    /** Gibt dieser Server Abstimmungen her? */
    public static function KannAbstimmungen(array $vorhanden): bool
    {
        $da = [];
        foreach ($vorhanden as $f) {
            $da[trim((string)$f)] = true;
        }
        foreach (self::OPTIONAL as $f) {
            if (!array_key_exists($f, $da)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Eigener Zahlenraum für Abstimmungen.
     *
     * Aufgaben und Abstimmungen sind zwei Moodle-Tabellen mit je eigener
     * Zählung: Aufgabe 117 und Abstimmung 117 gibt es gleichzeitig. `srcId` im
     * Hausaufgaben-Bestand ist aber eine ZAHL (HomeworkImportieren prüft
     * `(int) > 0`), und beide Quellen heissen `moodle` — ohne diesen Abstand
     * hielte die Zusammenführung die eine für die andere und löschte sie.
     */
    public const ID_ABSTIMMUNG = 1000000000;

    /**
     * Eine Abstimmung als Aufgabe mit Frist — oder gar nicht.
     *
     * Vier Gründe, nichts daraus zu machen:
     *  - kein Name (dann ist auch nichts anzuzeigen),
     *  - keine Frist: `timeclose` 0 heisst „unbegrenzt offen". Das ist keine
     *    Aufgabe, sondern ein Angebot — es steht als Karte auf der Klassenseite,
     *    und eine Fälligkeit dazu wäre erfunden.
     *  - Frist vorbei: eine geschlossene Abstimmung kann niemand mehr
     *    beantworten. (Gemessen an der Grundschule: die eine Abstimmung dort
     *    lief bis 30.05.2025.)
     *  - keine Kennung.
     *
     * Erledigt ist, was BEANTWORTET ist: `mod_choice_get_choice_options`
     * liefert je Option `checked` für das eigene Konto. Kommt die Liste leer
     * (Moodle antwortet bei einer geschlossenen oder gesperrten Abstimmung mit
     * einer Warnung statt mit Optionen), gilt das als „nicht beantwortet" —
     * lieber eine Erinnerung zu viel als eine verpasste Frist.
     *
     * @param array<string,mixed> $ab        eine Abstimmung aus mod_choice_get_choices_by_courses
     * @param list<array<string,mixed>> $optionen aus mod_choice_get_choice_options
     * @return array{srcId:int,name:string,due:string,done:bool}|null
     */
    public static function AbstimmungAufgabe(array $ab, array $optionen, int $jetzt): ?array
    {
        $cmid = (int)($ab['coursemodule'] ?? 0);
        $name = trim((string)($ab['name'] ?? ''));
        $bis  = (int)($ab['timeclose'] ?? 0);
        if ($cmid <= 0 || $name === '' || $bis <= 0 || $bis < $jetzt) {
            return null;
        }
        $erledigt = false;
        foreach ($optionen as $o) {
            if (is_array($o) && ($o['checked'] ?? false)) {
                $erledigt = true;
                break;
            }
        }
        return [
            'srcId' => self::ID_ABSTIMMUNG + $cmid,
            'name'  => $name,
            'due'   => date('Y-m-d', $bis),
            'done'  => $erledigt,
        ];
    }

    /**
     * Zwei Terminlisten zu einer machen.
     *
     * Moodle hat zwei Wege zu Terminen, und sie überschneiden sich: die
     * Zeitleiste (`core_calendar_get_action_events_by_timesort`) nennt nur
     * FRISTEN von Aktivitäten, der Kalender (`core_calendar_get_calendar_events`)
     * auch Kurs-, Nutzer- und Seitentermine — Schließungstage etwa. Dieselbe
     * Kennung darf davon nur einmal ankommen.
     *
     * @param list<array<string,mixed>> $zeitleiste
     * @param list<array<string,mixed>> $kalender
     * @return list<array<string,mixed>>
     */
    public static function TermineVereinen(array $zeitleiste, array $kalender): array
    {
        $raus = [];
        $da = [];
        foreach ([$zeitleiste, $kalender] as $liste) {
            foreach ($liste as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $ts = (int)($e['timesort'] ?? ($e['timestart'] ?? 0));
                $id = (int)($e['id'] ?? 0);
                if ($ts <= 0 || trim((string)($e['name'] ?? '')) === '') {
                    continue;
                }
                /* Ohne Kennung wird am Namen unterschieden: zwei Seitentermine
                   zur selben Sekunde sind sonst einer. Moodle nummeriert seine
                   Termine, aber verlassen sollte man sich darauf nicht. */
                $schl = ($id > 0 ? 'i' . $id : 'n' . mb_strtolower(trim((string)$e['name'])))
                    . ':' . $ts;
                if (isset($da[$schl])) {
                    continue;
                }
                $da[$schl] = true;
                $raus[] = $e;
            }
        }
        return $raus;
    }

    /**
     * Den Token an eine Dateiadresse haengen — so will es der Web-Service der
     * Moodle-App.
     *
     * Das geschieht schon beim LESEN, nicht erst beim Herunterladen. Grund ist
     * der Umzug in die Scanner-Spur: die Karte reist als Datei hinueber, und
     * auf der anderen Seite gibt es keinen Zugang mehr — wohl aber die fertige
     * Adresse. Dieselbe Adresse benutzen danach BEIDE Wege: der Spiegel
     * (`EduDateiHolen`) und die KI-Nutzlast (`MoodleAnhaengeFuerKi`).
     *
     * Der Token steht damit in der Auftragsdatei. Die liegt mit 0600 im
     * Kernel-Verzeichnis — strenger als der Ort, an dem er ohnehin schon steht:
     * `settings.json` ist weltlesbar.
     */
    public static function MitToken(string $url, string $token): string
    {
        $url = trim($url);
        if ($url === '' || $token === '' || str_contains($url, 'token=')) {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
    }
}
