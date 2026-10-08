# Notizen: Bestand im Gateway

Notizen (Ordner, Titel, Text, Anhänge) liegen im SymDo Gateway, nicht in einem eigenen Listenmodul. Die Kachel `SymDoNotes` zeigt sie nur an. Diese Datei hält fest, warum das so gebaut ist und welche Entscheidungen man nicht umdrehen sollte.

## Entscheidungen

- **Gateway-Bestand, keine neue Listenart.** Ein `kind: "notes"` in `/v1/discovery` würde die ausgelieferte iOS-App zerlegen. Deren `ListKind` kennt nur `shopping` und `todo`, ist nicht optional und dekodiert nicht tolerant. Eine unbekannte Art lässt die GANZE Antwort scheitern, samt Listen und Mitgliedern. Notizen erscheinen deshalb nicht in `discovery.instances`. Die Kachel `SymDoNotes` (seit 04.09.2026) ist eine reine Anzeige: Sie ist die Web-App, und ihr Bereich ergibt sich aus `tabs` im Zustand (siehe `kacheln-uebernahme-skript.md`).
- **EIN Attribut `NotesStore` für Ordner, Notizen und `seen`, wegen der Atomarität.** Löscht man einen Ordner, ändern sich alle drei in einem Zug. Mit zwei Attributen wären es zwei Schreibvorgänge, und der zweite kann still scheitern.
- **Mitglieder-Ordner entstehen höchstens EINMAL.** Merkposten (`seen`) und Ordner entstehen im selben Schreibvorgang, deshalb kehrt ein gelöschter Mitglieds-Ordner nicht zurück, auch nicht, wenn beim Löschen das Schreiben scheitert. Eine Liste „bewusst gelöscht“ hätte genau diese Lücke.
- **`memberId` wird nie ausgetragen,** sondern erst beim Antworten gegen `LoadUsers()` aufgelöst. Ein Abgleich in `ApplyChanges` wäre eine Falle: `LoadUsers()` liefert `[]`, wenn die Mitgliederliste nicht lesbar ist, und nähme dann allen Ordnern ihr Mitglied, still und unumkehrbar.
- **Anhänge liegen unter einer eigenen Kategorie „Notizen“ am Gateway** (Attribut `NotesMediaCategory`). Die Ablage der Rezeptfotos taugt dafür nicht: Deren Kategorie hängt unter der Einkaufslisten-Instanz und fehlt ohne sie. Außerdem löscht die Einkaufsliste Medienobjekte, sobald deren Elternobjekt „Rezeptfotos“ heißt. Anhänge, auf die keine Notiz mehr verweist, räumt das Gateway bei `ApplyChanges` weg.
- **Mail-Anhänge werden nur auf ausdrücklichen Wunsch dauerhaft abgelegt** (`MailNoteAttachments`, Vorgabe aus). Der Datenschutzhinweis im Gateway-Formular nennt diese Ausnahme.
- **`ShowNotes` (Vorgabe an) steuert den Bereich in der Web-App.** `normalizeTabs` zeigt Notizen und Kalender nur bei ausdrücklichem `true`. Deshalb bleibt der Bereich in den Listen-Kacheln von selbst weg, ohne dass das Übernahme-Skript davon wissen muss.
- **Die KI-Vorschläge haben einen eigenen Bereich „KI“** (Schalter `ShowKi`) mit EINEM Abzeichen über alles Wartende, statt je einem Band in ToDos, Kalender und Notizen. Der Bereich bleibt auch leer sichtbar, sonst verschwände er beim Übernehmen des letzten Vorschlags unter dem Finger. Dort beginnen die Bänder aufgeklappt, weil sie der Inhalt sind. Das Push-Ziel neuer Vorschläge ist `ki`. Die Reihenfolge der Bereiche ist heute einstellbar (`tabOrder`), die Vorgabe steht in `TAB_REIHENFOLGE`.

## Fallen

- **Größengrenzen für Anhänge:** Eine Datei, die als Hook-Antwort ausgeliefert wird, darf höchstens so groß sein, wie `ScriptOutputBufferLimit` erlaubt. Als data:-URL oder über das Relay zählt die Base64-Länge, also rund ein Drittel mehr. Beide Grenzen werden heute aus der Kernoption abgelesen (`AppCore::OutputLimit`, `RelayLimitB64`) und stehen nicht mehr fest im Code. Ein Bild wird für das Relay über absteigende Kanten (1200/900/700/500 px) verkleinert, damit es auf JEDEM Weg ankommt. Ein PDF bekommt dort eine Absage und reist über die Datei-Route. Ist eine Datei zu groß, antwortet das Gateway mit 413 statt 404.
- **KI-Antworten mit echten Zeilenumbrüchen in JSON-Strings:** `json_decode` scheitert daran, und früher war die ganze Mail-Analyse still verloren („0 Aufgabe(n)“ im Protokoll, die Kosten beim Anbieter trotzdem angefallen). `AiDecodeJsonArray` macht deshalb einen zweiten Versuch mit ausgebesserten Strings.
- **`store_unwritable` hat zwei Ursachen.** Entweder ist das Attribut noch nicht registriert, weil das Modul nach dem Einführen neuer Attribute nicht neu geladen wurde. Oder der Bestand ist über den Deckel `NOTES_STORE_MAX` (rund 768 kB) gewachsen. Die Meldung legt das erste nahe, deshalb muss der Deckel über dem schlimmsten Fall aus `NOTES_MAX` × `NOTE_TEXT_MAX` liegen.
- Die iOS-App hat bewusst keine Notizen (nicht am Code prüfbar).

Stand: geprüft gegen den Code am 08.10.2026
