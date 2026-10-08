# Listen: ToDo-Synchronisierung ohne Verdopplung

Beim Einschalten der automatischen Microsoft-To-Do-Synchronisierung tauchten alle Aufgaben doppelt auf. Diese Datei hält die Ursache fest und begründet, warum die ToDo-Instanz seitdem keine Aufgabenliste mehr im Konfigurationsformular hat. Den API-Abgleich aller Sync-Wege beschreibt `SymDoToDoList/docs/SYNC-AUDIT.md`.

## Entscheidungen

- **Die Ursache war das Speichern des Formulars, nicht der Sync-Timer.** Früher baute `ApplyChanges` bei geändertem Hash der Formularliste die Aufgaben aus den Formularzeilen neu auf. Dabei gingen die Zuordnungsfelder verloren (`microsoftTaskId`, `microsoftEtag`, `microsoftSynced`, `localModified`, ebenso `google*` und `caldav*`), weil das Formular sie nie trug. Der nächste Sync lud deshalb alles als neu hoch und holte zugleich die verwaisten Server-Aufgaben als neue lokale Einträge zurück. „Jetzt synchronisieren“ umging `ApplyChanges` und lief deshalb fehlerfrei. Diese Abweichung führte bei der Fehlersuche in die Irre.
- **Die Aufgabenliste ist ganz aus dem Konfigurationsformular entfernt** (Juli 2026). Quelle der Wahrheit ist das Attribut `Items`. Aufgaben werden nur noch über Kachel, Web-App, App und Sync verwaltet (`AddItem`, `UpdateItem`, `ToggleDone`, `DeleteItem`, `Reorder`). Ein Formular, das Teile eines Datensatzes nicht kennt, darf ihn nicht zurückschreiben. Das ist die eigentliche Lehre.
- **Selbstheilung über einen eindeutigen Titel** (`MicrosoftFindServerMatchByTitle`). Einen Eintrag ohne `microsoftTaskId` verknüpft der Sync vor dem Hochladen erneut mit einer bestehenden Server-Aufgabe gleichen Titels, statt sie zu verdoppeln. Das geschieht nur bei genau einem Treffer, und bereits zugeordnete IDs sind geschützt (`$localOwnedIds`).
- **Inkrementelle Sicherung beim Hochladen.** Nach jedem erfolgreichen Upload wird `Items` sofort geschrieben. Bricht die Schleife mittendrin ab, lädt der nächste Lauf die schon angelegten Aufgaben nicht erneut hoch.

## Fallen

- **Bestehende Duplikate entfernt der Code nicht, er verhindert nur neue.** So wird aufgeräumt: Automatik stoppen, die Duplikate beim Dienst löschen, die lokale Liste leeren und `MicrosoftLastSync` auf 0 setzen, dann einmal von Hand synchronisieren, damit alles frisch importiert wird.
- **„Sync zurücksetzen“ nie bei beidseitig vorhandenen Daten.** Der Rücksetzer lädt alles erneut hoch und erzeugt die Duplikate gleich wieder.

## Offen

- Google Tasks und CalDAV haben keinen Titel-Rückfall. Dieselbe Klasse unabhängiger Verdopplungswege ist dort bewusst zurückgestellt.

Stand: geprüft gegen den Code am 08.10.2026
