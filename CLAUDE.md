# SymDo – Family Organizer (Bibliothek „List“)

IP-Symcon-Bibliothek für den Familienalltag: Einkauf, Aufgaben, Termine, Notizen, Schule, Essensplan, KI-Vorschläge, Sprachdialog. Öffentliches Repo (`da8ter/SymDo-Family-Organizer`, Arbeitszweig `symdo-bridge` → `SymDo-Beta`). Dazu gehören eine iOS-App und eine Firmware für ESP32-Sprachgeräte in eigenen Repos.

Projektwissen (Entscheidungen, gemessene Symcon-Fakten, Test-Rezepte): **`docs/README.md`** – vor Änderungen an einem Bereich die passende Datei lesen. Offenes: `docs/stand.md`. Betriebsdaten dieses Rechners stehen in `CLAUDE.local.md` (nicht eingecheckt).

## Aufbau

- **`SymDoGateway/`** (TGW, Splitter): der Kern. Fast alles lebt hier als Trait in `libs/`: App-API (`ApiRouter`, `AppCore`, `DeviceRegistry`), Web-App-Auslieferung, KI (`Ai*`), Mail, Schule (`WebUntis`, `Moodle*`, `Edu*`, `Homework`), Notizen, Briefing, TTS, Sprachdialog (`Voice*`, `voice-core.js`), Web Push. Hooks `lists/app`, `lists/webapp`, `lists/ws`, `lists/pwa`.
- **`SymDoWebApp/module.html`**: die Web-App. Sieben Kacheln sind wortgleiche Kopien davon, erzeugt per `python3 <Modul>/tools/uebernahme-webapp.py` aus `List/` (AIInbox, Briefing, Edumaps, Homework, Notes, ShoppingList, ToDoList). **Nie die Kopie von Hand ändern**, immer die Web-App und dann das Skript.
- **Kacheln (Typ 3):** ShoppingList `SL`, ShoppingListOverview `SLLO`, ToDoList `TDL`, ToDoOverview `TDLO`, Notes `SDNO`, Briefing `SDBR`, AIInbox `SDAI`, Edumaps `SDEM`, Homework `SDHW`, Timetable `STPL`, MealPlan `MPL`, Routines `RTN`, Chores `CHR`, VRRTransit `SDVT`, Voice `SDVC`, WebApp `SDWA`, ESPVoice `SDEV` (Sprachgerät, Kind eines MQTT-Servers).
- **Weitere:** Scanner `SDSC` (zweite Spur für KI/Abrufe), Setup `SDSU` (Installationsassistent).
- **`libs/`**: gemeinsame Bausteine der Kachel-Module (Push, Stand, Bereiche, Abgleich externer Listen).
- Alle Module: `IPSModuleStrict`, Darstellungen statt Variablenprofilen, ab Symcon 9.1 Visualisierungstyp `HTML_FULLSCREEN` (mit `defined()`-Rückfall auf 1).

## Prüfen

```bash
php <Modul>/tests/<Name>Test.php      # PHP-Prüfstände, Attrappen statt Symcon
node <Modul>/tests/<Name>.mjs         # Browser-Teile in Node (Web-App, Kerne)
php -l <Datei>
```

Nach jeder Änderung die Prüfstände des Bereichs laufen lassen; bei Web-App-Änderungen zusätzlich die Kopien-Prüfstände in `SymDoWebApp/tests/` (KachelApp, KachelStand u. a.). Ein Prüfstand, der grün bleibt, obwohl der Fix fehlt, prüft das Falsche: Gegenprobe machen. Kachel-Layout im Browser prüfen: `docs/testen/`.

## Regeln

- **Commits:** nach jeder abgeschlossenen Änderung, ein Thema je Commit, deutsche Botschaft, **ohne** Co-Authored-By-Zeile. Prüfungen vorher.
- **Nie** `git checkout`/`git restore` auf Dateien: Arbeitskopien enthalten dauerhaft nicht committete Arbeit.
- **Push und Release nur auf Zuruf.** Release: `build` und `date` in `library.json` hochsetzen, Commit „Build N“.
- **Doku nachziehen:** Ändert ein Commit eine Entscheidung aus `docs/`, wird die Datei im selben Commit angepasst und ihr „Stand“-Datum erneuert. Neues gemessenes Symcon-Verhalten gehört nach `docs/plattform/`.
- **Öffentliches Repo:** keine IP-Adressen, Ports, Instanz-IDs, Token, Schlüssel, Pfade unter `/Users/`, echten Namen von Kindern, Schulen oder Klassen – weder im Code noch in Kommentaren, Tests oder Doku. Fixtures mit Platzhaltern.
- **Modulordner** heißen `SymDo<Klasse>`; `name` in `module.json` ohne Bindestrich (Klassenname = `name` ohne Leerzeichen), Anzeigename `SymDo - …` über `locale.json`, `aliases` leer, `vendor` „Stephan Sprick“.
- **Nutzertexte** sagen „Symcon“, nicht „IP-Symcon“. Übersetzungen über `locale.json` in beiden Sprachblöcken.
- **Web-App und iOS-App** im Verhalten gleich halten – Änderungen an gemeinsamem UI-Verhalten erst nach Rücksprache auf beide übertragen.
- **Kachel-Stil:** Referenz ist die Web-App (Knöpfe, Scrollleisten, Ränder aus den URL-Parametern), siehe `docs/entscheidungen/kacheln-stilregeln.md`.
- **Sicherheitsgrenzen:** `EduHtml()` ist die einzige HTML-Weißliste; Idents von Kachel-`RequestAction` sind für jeden Visu-Browser erreichbar – Modul-zu-Modul-Wege über öffentliche Funktionen; flüchtige Geheimnisse in Puffer, nicht in Attribute (`settings.json` ist Klartext). Hausregeln: `.claude/claude-security-guidance.md`.
- **Symcon-Fragen** am offiziellen Handbuch prüfen (`docs/plattform/doku-quellen.md`), nie aus dem Modulbestand ableiten.
