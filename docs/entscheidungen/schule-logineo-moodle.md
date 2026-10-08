# Schule: LOGINEO NRW LMS (Moodle)

LOGINEO NRW LMS ist Moodle; das Gateway liest über den Web-Service der Moodle-App (`moodle_mobile_app`) Kurse, Dateien, Forumsbeiträge, Aufgaben, Abstimmungen und Termine. Inhalte landen als zweite Quelle im Klassenseiten-Bestand, Aufgaben und Abstimmungen im Hausaufgaben-Bestand. Code: `SymDoGateway/libs/Moodle.php` (Zugang, Ablage), `MoodleLesen.php` (lesende Hälfte), `MoodleCalc.php` (Rechenwerk, Weißliste).

## Entscheidungen

- **Nur lesen, über eine Weißliste** (`MoodleCalc::ERLAUBT`). Auf dem geprüften Schulkonto waren auch Schreibfunktionen freigegeben (`core_calendar_create_calendar_events`, `mod_forum_add_discussion`, `mod_assign_save_grade` u. a.). Nur die Liste verhindert, dass ein Tippfehler in der Schule etwas anrichtet. Zweiter Riegel: `MoodleCalc::SCHREIBEND` prüft im Prüfstand jeden Listeneintrag auf schreibende Wortstämme. Auch „nur gelesen"-Marken wie `mod_forum_view_forum` oder `core_completion_*` stehen bewusst nicht drauf – sie hinterlassen Spuren im Kurs. `mod_choice_submit_choice_response` ebenfalls nicht: eine Anmeldung des Kindes gehört nicht in einen Takt.
- **Ein Konto je Kind** (`MoodleAccounts`): LOGINEO kennt keine Elternzugänge. Fehlerzähler je Zugang (`MoodleFails`), damit ein Kind die Geschwister nicht anhält; nach 3 Fehlschlägen ruht der Zugang bis zum nächsten Griff von Hand.
- **Nur der Token wird gespeichert, nie das Kennwort.** Kennwort einmal ins Formularfeld, der Knopf tauscht es gegen den Token und leert das Feld sofort (`UpdateFormField`). Token im Attribut `MoodleTokens`, nicht in der Kontenliste (die schickt man eher versehentlich weiter). Der Token ist in Moodle widerrufbar, ein Kennwort nicht; Nebenwirkung: der Takt meldet sich nie an und kann deshalb kein Konto sperren.
- **Ablage ohne neuen Mechanismus:** ein Kurs bekommt seine echte Kursadresse (`…/course/view.php?id=…`) als „Seite". Damit greifen Ordnerlogik (`EduOrdner`, `edupage:<md5>` unter dem Mitgliedsordner), Sperrliste, Archiv und Medien-Aufräumer unverändert. Eine Karte je Modul (nicht je Datei), Foren je Beitrag.
- **Ein Hausaufgaben-Import je Lauf und Quelle:** Aufgaben und Abstimmungen gehen in einem Aufruf von `HomeworkImportieren` – zwei Aufrufe derselben Quelle im selben Lauf räumen sich gegenseitig auf (der zweite hält die Zeilen des ersten für verschwunden).
- **Eigener Zahlenraum für Abstimmungen** (`MoodleCalc::ID_ABSTIMMUNG = 1e9`): `srcId` im Hausaufgaben-Bestand ist eine Zahl, Aufgaben und Abstimmungen zählen in Moodle getrennt; ohne Versatz hielte die Zusammenführung die eine für die andere.
- **Termine ohne KI:** Kalendereinträge sind schon strukturiert und werden direkt zu Vorschlägen; der Merker wird erst nach erfolgreichem Ablegen gesetzt.
- **Dateigröße:** eigener Download statt `AiFetchPublicPage` (deckelt bei 2 MB und bricht ab). Deckel = `min(MOODLE_DATEI_MAX, OutputLimit())`: größere PDFs würden von `NotesSaveAttachment` mit `file_too_large` abgewiesen und wären umsonst geladen worden. Die Karte behält Text und Verweis.
- **Trockenlauf bleibt im Gateway**, auch wenn ein Scanner die Quelle übernommen hat: auf ihn wartet jemand vor dem Formular, ein Auftrag käme Minuten später und würde wirklich schreiben.

## Fallen / gemessen

Gemessen am 10.09.2026 an einer Schule (Moodle 4.5.13, 435 Funktionen freigegeben, Schülerkonto) – der Grund, warum dort mehrere Wege keinen Ertrag haben, der Code aber trotzdem steht:

| Bereich | Aufruf | Befund |
|---|---|---|
| Inhalte | `core_course_get_contents`, Foren | der eigentliche Ertrag: Dokumente (Elternpost, Lernzeitpläne, Speisepläne, Formulare) und Forumsbeiträge |
| Aufgaben | `mod_assign_*` | keine `assign`-Module an dieser Schule |
| Termine | `core_calendar_get_calendar_events`, `…_action_events_by_timesort` | 0 in beiden Wegen, ±1 Jahr, mit und ohne Kursfilter |
| Abstimmungen | `mod_choice_get_choices_by_courses` | 1, längst geschlossen |
| Abschnittsbeschreibungen | in `core_course_get_contents` | nur ein Kopfbild, 0 Zeichen Text nach `strip_tags` |
| Modularten | – | `forum`, `resource`, `choice`, dazu `subsection` (Moodle 4.5, ohne `url` und Inhalt → fällt als leere Karte weg) |
| Noten | `gradereport_user_get_grade_items` | freigegeben, nicht genutzt – sensibelste Daten, nur mit eigenem Schalter denkbar |
| Anwesenheit | `mod_attendance_*` | nicht freigegeben |

- `hasanswered` gibt es in dieser Version nicht; die eigene Antwort steht als `checked` je Option in `mod_choice_get_choice_options`. Bei einer geschlossenen Abstimmung kommt die Optionsliste leer mit Warnung (`warningcode 3`) – leer heißt dort „nicht mehr zu beantworten", nicht „unbeantwortet". Deshalb wird die Frist vor dem Aufruf geprüft.
- Ein Token kann jederzeit ungültig werden (gemessen: zwei Stunden nach dem Holen `invalidtoken`, das Kennwort galt weiter). Ein toter Token ist der Normalfall, kein Defekt; die Statuszeile fordert dann zum Neuholen auf.
- Zweiter Lauf eines Kurses schreibt nichts, wenn sich nichts geändert hat (Fassungsvergleich); der erste Lauf eines Kurses vermerkt nur und wertet nichts aus. (Laufzeiten nicht am Code prüfbar)

## Offen

- Noten und Anwesenheit sind bewusst nicht angebunden.

Stand: geprüft gegen den Code am 08.10.2026
