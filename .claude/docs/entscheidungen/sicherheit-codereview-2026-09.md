# Sicherheit und Korrektheit: externer Codereview September 2026

Im September 2026 lief ein repository-weiter Review von außen über die ganze Bibliothek (vier Runden, Befunde F1–F15). Alle Befunde sind behoben, je mit eigenem Prüfstand und einer Gegenprobe (Fix zurückgedreht, Prüfstand fällt). Diese Datei hält fest, welche Entscheidungen in den Korrekturen stecken und welche Lehren daraus Regeln geworden sind.

## Befunde und Prüfstände

| | Befund | Prüfstand |
|---|---|---|
| F1 | CalDAV-Teilfehler wurden als Löschung gelesen | `SymDoToDoList/tests/CalDAVMultistatusTest.php` |
| F2 | Quellwechsel (Alexa/Bring) nicht von Löschung unterscheidbar | `SymDoToDoList/tests/QuellwechselTest.php` |
| F3 | Netz-Rückfall wiederholte Schreibvorgänge | `SymDoWebApp/tools/netz-rueckfall.mjs` |
| F4 | Wiederholungen an der Zeitumstellung | `SymDoToDoList/tests/WiederholungTest.php` |
| F5 | Routinen: Tageskennung und Reset-Timer meinten verschiedene Grenzen | `SymDoRoutines/tests/TagesgrenzeTest.php` |
| F6 | KI-Tagesdeckel ohne Reservierung | `SymDoGateway/tests/AiJobsBridgeTest.php` |
| F7 | Anbieteraufruf nach dem Widerruf der Einwilligung | `SymDoGateway/tests/AiJobTest.php` |
| F8 | Push-Endpunkt nur auf `https://` geprüft (SSRF) | `SymDoGateway/tests/PushZielTest.php` |
| F9 | Verspätetes Schulergebnis nach dem Widerruf | Nachweis in `2e64c39` |
| F10 | LOGINEO-Zugang erreichte den Leser ohne Token | `SymDoGateway/tests/MoodleZugangTest.php` |
| F11 | Ausgebliebener Abruf = leere Liste → Hausaufgaben gelöscht | `MoodleZugangTest.php` |
| F12 | Briefing-Empfänger las ein Feld, das vorher gelöscht wird | `SymDoGateway/tests/HintergrundAuftragTest.php` |
| F13 | IMAP-Anhänge fielen aus dem Auftragsweg | `HintergrundAuftragTest.php` |
| F14 | Mail im Postfach gelöscht, bevor der Vorschlag gespeichert war | `HintergrundAuftragTest.php` |

## Entscheidungen

- **F1 — Status nach Ort.** Am `response` heißen 404/410 „fort"; am `propstat` heißt ein 404 nur „Eigenschaft fehlt" (RFC 4918 §9.1.2), dort verwirft jeder Code ab 400 den Abruf. Ein Feld `calendar-data`, das da, aber leer ist (Status 200), beweist ebenfalls nichts.
- **F2 — stilllegen statt entfernen.** Kennungen einer alten Gegenstelle lassen sich nicht entfernen (`ExtListSetId` kann nur anhängen); sie werden in `ExtListFremdIds` stillgelegt (Deckel 2000 je Dienst). Folge eines Wechsels ist ein Hochladen der offenen Einträge, kein Verlust. Beim Altbestand ohne Quellkennung wird beim ersten Kontakt am Bestand der Gegenstelle gemessen. Bei Bring ist der Artikelname die Kennung — ein gemeinsamer Name beweist keine Listenidentität (`ListSource::KennungenEindeutig()`); ohne Eindeutigkeit wird stillgelegt statt geraten.
- **F3 — kein Idempotenz-Schlüssel, sondern ein engerer Rückfall.** Wiederholt wird nur, was eine Wiederholung verträgt: Lesen und Listenaktionen (dank `clientActionId`). Alles andere meldet den Fehler nach oben.
- **F6 — reservieren, nicht buchen.** Der Tagesdeckel rechnet ausstehende Aufträge mit (`AiJobStore::zaehleUngebucht()`); ein Fehlschlag kostet nichts.
- **F7 — der Wächter sitzt an der einzigen Transportstelle.** `AiProvider::post()` prüft vor jedem Ausgang und wirft `AiWiderrufen`; ein Fehlercode würde vertagt statt abgebrochen.
- **F8 — keine Liste erlaubter Push-Dienste** (Begründung in `webpush.md`).
- **F11 — `?array` statt Liste.** `null` heißt „nicht geantwortet", `[]` heißt „nichts da". Nur Letzteres darf einen Abgleich löschen lassen.
- **F14 — Reihenfolge der Handlungen:** erst speichern, Rückgabewert prüfen, dann im Postfach löschen.

## Lehren (als Regeln übernommen)

- **Die Klasse schließen, nicht den gemeldeten Fall.** F1 und F7 brauchten je drei Anläufe, weil jeweils nur der gemeldete Fall geschlossen wurde. Die richtige Frage ist: wo ist die EINE Stelle, durch die alles muss? Bei F7 war das `post()`.
- **Prüfstände, die die Eingabe von Hand bauen, prüfen die eigene Vorstellung.** F10, F12 und F13 blieben unentdeckt, weil Teilprüfstände die Nutzlast des Erzeugers nachbauten (ein Feld, das der echte Erzeuger vorher löscht; ein Zugang ohne Token; eine Fehlerform, die es nicht gibt — `AiErrorMessage` ist flach: `{ok:false, code, message, status}`). Gegenmittel: Traits in einer Klasse zusammensetzen und den echten Weg fahren (`HintergrundAuftragTest`, `MoodleZugangTest`); Attrappen liefern die echte Form.
- **Diff-Prüfungen finden Altfehler nicht.** F1, F2, F4 und F5 lagen in Code, der seit Monaten unverändert war. Dafür braucht es gelegentlich einen repository-weiten Durchgang.
- Ein Prüfstand, der seit Commits rot ist, ohne dass es jemand merkt, ist wertlos (ScanBridgeTest verlangte noch ein abgeschafftes Verhalten; behoben in `2193e2a`).

## Hausregeln für automatische Sicherheitsprüfungen

Die systemischen Regeln aus diesen Funden stehen in `.claude/claude-security-guidance.md`. Die Datei steht an ihrer Budgetgrenze von 8 KB (8192 Byte); bei Überlauf kappt das Prüfwerkzeug den Projektteil zuerst (nicht am Code prüfbar) — Ergänzungen also nur gegen Kürzungen an anderer Stelle. Einzelausnahmen werden als Kommentar an der Zeile begründet, nicht in die Regeldatei geschrieben.

Stand: geprüft gegen den Code am 08.10.2026
