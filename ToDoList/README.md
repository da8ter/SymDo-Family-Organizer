# ToDo List

Dieses Modul stellt eine ToDo-Liste für die Tile-Visualisierung bereit. Optional mit Synchronisation für Google Tasks, Microsoft To Do und CalDAV.

- Aufgaben direkt in der Visualisierung anlegen, bearbeiten und abhaken
- Zwei-Wege-Synchronisation mit Google Tasks, Microsoft To Do und CalDAV (z. B. Nextcloud)
- Push-Benachrichtigungen vor Fälligkeit, Vorlaufzeit pro Aufgabe einstellbar
- Wiederkehrende Aufgaben (wöchentlich bis jährlich oder eigenes Intervall); erledigte Aufgaben werden zum nächsten Termin automatisch wieder geöffnet
- Aufgaben mit Notiz, Priorität, Menge und Fälligkeit — mit Uhrzeit oder ganztägig
- Übersicht über offene, heute fällige und überfällige Aufgaben; optional als separate Übersichtskachel
- Sortierung nach Fälligkeit, Priorität, Datum oder Titel; manuelle Reihenfolge per Drag & Drop
- Löschen per Wischgeste; optional automatisches Entfernen erledigter Aufgaben
- Mehrere Listen über separate Instanzen
- Zählerwerte (offen/überfällig/heute fällig) als Variablen für eigene Automationen; alle Funktionen per PHP-Skript nutzbar
- Zusätzliche HTML-Ausgabe für IPSView

![ToDo List](https://github.com/da8ter/images/blob/main/todo.png)

## Inhalt

- **1. Funktionsumfang**
- **2. Voraussetzungen**
- **3. Installation**
- **4. Konfiguration in Symcon**
- **5. Visualisierung (Tile/HTML-SDK)**
- **6. Statusvariablen**
- **7. PHP-Befehlsreferenz**
- **8. Benachrichtigungen**
- **9. Synchronisation**

## 1. Funktionsumfang

- **Tasks**
  - Anlegen, Bearbeiten, Löschen
  - **Nach links wischen**, um einen Task direkt zu löschen — beim Wischen erscheint eine rote Lösch-Fläche mit Mülleimer-Symbol
  - Erledigt-Status
  - Titel / Info / Anzahl / Priorität / Fälligkeit
  - Wiederkehrend basierend auf Fälligkeit (Individuell: Stunden/Tage/Wochen/Monate/Jahre, sowie 1/2/3 Wochen, monatlich, quartalsweise, jährlich)
  - Wiederkehrende Tasks werden automatisch vor Fälligkeit wieder auf offen gesetzt (pro Task konfigurierbar)
  - Wieder öffnen: zusätzlich "Sofort" (direkt nach dem Erledigen wieder öffnen)
  - Optional: Benachrichtigung vor Fälligkeit
- **IPSView / HTMLBox**
  - Read-only HTML-Ausgabe der Taskliste über die Statusvariable **TaskListHtml** (für `~HTMLBox`)
  - Sortierung folgt den Sortier-Einstellungen aus dem Frontend
- **Sortierung**
  - Datum, Fälligkeit, Priorität, Titel
  - Manuell (automatisch aktiv, wenn per Drag&Drop umsortiert wurde)
  - Drag&Drop für manuelle Reihenfolge (Frontend)
- **Detailansicht**
  - Öffnen per Klick auf Titel/Info

## 2. Voraussetzungen

- Symcon ab Version **8.1**
- Nutzung in der **Kachel-Visualisierung** (Tile-Visualisierung)

## 3. Installation

1. Repository/Library installieren über das Module Control (https://github.com/da8ter/ToDo-List.git)
2. Instanz anlegen: **ToDo Gateway** (Zentrale Instanz für die Synchronisation. Bitte auch erstellen wenn keine Synchronisation verwendet wird)
3. Instanz anlegen: **ToDo List** (Für jede Liste wird eine Instanz benötigt)

## 4. Konfiguration in Symcon

### Instanz-Eigenschaften

- **Visualisierungsinstanz** (`VisualizationInstanceID`)
  - ID einer Kachel-Visualisierung, an die Push-Benachrichtigungen gesendet werden.
- **Benachrichtigungs-Vorlaufzeit** (`NotificationLeadTime`)
  - Standard-Vorlaufzeit in Sekunden (z. B. 600 = 10 Minuten).
- **Übersicht einblenden** (`ShowOverview`)
  - Blendet im Frontend die Statistik-Kacheln ein/aus.
- **Erstellen Button einblenden** (`ShowCreateButton`)
  - Blendet im Frontend den Button **„Neuer Task“** ein/aus.
- **Sortieren Button einblenden** (`ShowSorting`)
  - Blendet im Frontend die Sortier-Bedienelemente (Dropdown + Auf/Ab) ein/aus.
- **Info-Badges einblenden** (`ShowInfoBadges`)
  - Blendet in der Hauptansicht die Badges für Priorität, Fälligkeit und Benachrichtigung ein/aus.
- **Löschen Button einblenden** (`ShowDeleteButton`)
  - Blendet den Löschen-Button in der Hauptansicht ein/aus (im Edit-Dialog ist er immer verfügbar).
- **Editier Button einblenden** (`ShowEditButton`)
  - Blendet den Editier-Button in der Hauptansicht ein/aus.
- **Erledigte Tasks ausblenden** (`HideCompletedTasks`)
  - Blendet erledigte Tasks im Frontend aus.
- **Erledigte Tasks löschen** (`DeleteCompletedTasks`)
  - Löscht einen Task automatisch, sobald er als erledigt markiert wird.
- **HTMLBox CSS** (`HtmlBoxCss`)
  - Vollständiges CSS für die HTMLBox-Ausgabe (`TaskListHtml`).
- **Items** (Listenelement im Konfigurationsformular)
  - Ermöglicht Bearbeitung der Tasks im Backend.
  - **Wiederholen** wird im Bearbeiten-Dialog immer angezeigt. **Wieder öffnen** wird nur angezeigt, wenn **Wiederholen** nicht **Keine** ist.
  - Wenn **Wiederholen = Individuell**, werden **Einheit** (Stunden/Tage/Wochen/Monate/Jahre) und **Intervall** eingeblendet.
  - Drag&Drop zum Umsortieren ist aktiviert.
  - Die Übernahme ins Frontend erfolgt beim **„Übernehmen“** der Instanz.

## 5. Visualisierung (Tile/HTML-SDK)

- Die Instanz kann direkt als Kachel eingebunden werden.
- Die Taskliste scrollt innerhalb der Kachel selbst (internes Scrolling), ohne das übergeordnete Visualisierungsfenster mitzuscrollen.
- Gleiches Verhalten gilt für Touch-Eingaben auf Smartphones (kein Scroll-Durchreichen am Listenrand).
- Außerhalb der definierten Scrollbereiche (Liste/Overlays) werden Wheel- und Touch-Scroll-Events unterdrückt.
- Bei kleiner Ansicht kann in der Listenansicht das Fälligkeits-Badge aus Platzgründen als Icon dargestellt werden; in der Detailansicht wird das Datum immer vollständig angezeigt.

## 6. Statusvariablen

Folgende Statusvariablen werden von der Instanz angelegt:

- **OpenTasks**
  - Anzahl offener Tasks
- **OverdueTasks**
  - Anzahl überfälliger Tasks
- **DueTodayTasks**
  - Anzahl heute fälliger Tasks
  - Die Statistik wird bei Änderungen sowie automatisch zum nächsten relevanten Fälligkeitszeitpunkt aktualisiert.

- **TaskListHtml** (`~HTMLBox`)
  - Read-only HTML-Ausgabe der Taskliste für IPSView (HTMLBox).
  - Wird bei Änderungen der Tasks sowie bei Änderungen der Sortier-Einstellungen aktualisiert.

Optional kann das CSS der HTMLBox über die Instanz-Eigenschaften angepasst werden.

- **HTMLBox CSS**
  - Vollständiges CSS für die HTMLBox.

## 7. PHP-Befehlsreferenz

Die folgenden Funktionen stehen in der Instanz zur Verfügung:

- **`Export()`**
  - Exportiert die Taskliste als JSON-String.
- **`AddItem(array $Item)`**
  - Fügt einen Task hinzu und gibt die neue ID zurück.
- **`UpdateItem(array $Data)`**
  - Aktualisiert einen Task.
- **`ToggleDone(array $Data)`**
  - Setzt/ändert den Erledigt-Status.
- **`DeleteItem(array $Data)`**
  - Löscht einen Task.
- **`Reorder(array $Data)`**
  - Setzt die Reihenfolge anhand einer ID-Liste.
- **`ProcessNotifications()`**
  - Prüft fällige Benachrichtigungen und sendet diese (sofern konfiguriert).
- **`ProcessRecurrences()`**
  - Verarbeitet wiederkehrende Tasks (5-Tage-Regel und Terminfortschreibung).

### Beispielskripte

Die folgenden Skripte zeigen die typischen Aufgaben mit den `TDL_`-Funktionen.
In jedem Skript muss `$instanzID` durch die Objekt-ID der eigenen ToDoList-Instanz
ersetzt werden. Alle Funktionen akzeptieren die Daten wahlweise als Array oder
als JSON-String.

#### Alle Tasks auslesen

```php
<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  ToDo-Liste: ALLE Tasks auslesen
 * ============================================================================
 *
 *  Funktion: TDL_Export(int $InstanzID): string
 *
 *  TDL_Export liefert die komplette Aufgabenliste als JSON-String zurück.
 *  Nach json_decode() erhält man ein Array von Task-Objekten. Jeder Task
 *  hat u.a. folgende Felder:
 *
 *  ┌──────────────────────┬─────────┬───────────────────────────────────────┐
 *  │ Feld                 │ Typ     │ Bedeutung                             │
 *  ├──────────────────────┼─────────┼───────────────────────────────────────┤
 *  │ id                   │ int     │ Eindeutige Task-ID (vom Modul vergeben)│
 *  │ title                │ string  │ Titel des Tasks                       │
 *  │ info                 │ string  │ Zusatz-/Notiztext                     │
 *  │ done                 │ bool    │ true = erledigt                       │
 *  │ due                  │ int     │ Fälligkeit als Unix-Timestamp         │
 *  │                      │         │ (0 = keine Fälligkeit gesetzt)        │
 *  │ dueAllDay            │ bool    │ true = ganztägig (ohne Uhrzeit)       │
 *  │ priority             │ string  │ 'low' | 'normal' | 'high'             │
 *  │ quantity             │ int     │ Anzahl/Menge (0 = nicht gesetzt)      │
 *  │ notification         │ bool    │ Benachrichtigung aktiv                │
 *  │ notificationLeadTime │ int     │ Vorlaufzeit in Sekunden               │
 *  │ recurrence           │ string  │ 'none','w1','w2','w3','m1','q1','y1', │
 *  │                      │         │ 'custom' (siehe "Task erstellen")     │
 *  │ createdAt/updatedAt  │ int     │ Unix-Timestamps (angelegt/geändert)   │
 *  │ doneAt               │ int     │ Zeitpunkt der Erledigung              │
 *  └──────────────────────┴─────────┴───────────────────────────────────────┘
 *
 *  Hinweis: Die Liste enthält offene UND erledigte Tasks (sofern erledigte
 *  nicht per Instanz-Option "Erledigte Tasks löschen" entfernt werden).
 * ============================================================================
 */

// >>> HIER ANPASSEN: Objekt-ID der ToDo-Liste-Instanz (aus dem Objektbaum) <<<
$instanzID = 12345;

// Komplette Liste als JSON-String abholen und in ein PHP-Array wandeln
$json  = TDL_Export($instanzID);
$tasks = json_decode($json, true);

if (!is_array($tasks)) {
    echo "Fehler: Export konnte nicht gelesen werden.\n";
    return;
}

echo count($tasks) . " Task(s) in der Liste:\n\n";

foreach ($tasks as $task) {
    // Fälligkeit hübsch formatieren (0 = keine gesetzt)
    $due = ((int)$task['due'] > 0)
        ? date($task['dueAllDay'] ? 'd.m.Y' : 'd.m.Y H:i', (int)$task['due'])
        : '—';

    printf(
        "[%s] #%d  %-30s  Fällig: %-16s  Priorität: %-6s  %s\n",
        $task['done'] ? 'x' : ' ',          // [x] = erledigt, [ ] = offen
        $task['id'],
        mb_substr($task['title'], 0, 30),
        $due,
        $task['priority'] ?? 'normal',
        ($task['info'] ?? '') !== '' ? 'Info: ' . $task['info'] : ''
    );
}

// ── Typische Auswertungen ───────────────────────────────────────────────────

// Nur offene Tasks
$offene = array_filter($tasks, fn(array $t): bool => empty($t['done']));
echo "\nDavon offen: " . count($offene) . "\n";

// Nur überfällige Tasks (Fälligkeit gesetzt, in der Vergangenheit, nicht erledigt)
$ueberfaellig = array_filter($tasks, fn(array $t): bool =>
    empty($t['done']) && (int)$t['due'] > 0 && (int)$t['due'] < time());
echo "Davon überfällig: " . count($ueberfaellig) . "\n";
```

#### Einzelnen Task auslesen

```php
<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  ToDo-Liste: EINZELNEN Task auslesen
 * ============================================================================
 *
 *  Funktion: TDL_Export(int $InstanzID): string
 *
 *  Das Modul hat bewusst keine eigene "GetItem"-Funktion — man holt die
 *  Liste per TDL_Export() und filtert nach der Task-ID (oder nach dem Titel).
 *  Die Task-ID sieht man z.B. in der Ausgabe von "Alle Tasks auslesen" oder
 *  als Rückgabewert von TDL_AddItem() beim Anlegen.
 * ============================================================================
 */

// >>> HIER ANPASSEN <<<
$instanzID = 12345;   // Objekt-ID der ToDo-Liste-Instanz
$taskID    = 7;       // ID des gesuchten Tasks

// ── Variante A: Task über seine ID finden ───────────────────────────────────
$tasks = json_decode(TDL_Export($instanzID), true) ?: [];

$gefunden = null;
foreach ($tasks as $task) {
    if ((int)$task['id'] === $taskID) {
        $gefunden = $task;
        break;
    }
}

if ($gefunden === null) {
    echo "Task #{$taskID} existiert nicht (mehr).\n";
    return;
}

// Einzelne Felder gezielt verwenden …
echo "Titel     : " . $gefunden['title'] . "\n";
echo "Status    : " . ($gefunden['done'] ? 'erledigt' : 'offen') . "\n";
echo "Fällig    : " . ((int)$gefunden['due'] > 0
        ? date('d.m.Y H:i', (int)$gefunden['due'])
        : 'keine Fälligkeit') . "\n";
echo "Priorität : " . ($gefunden['priority'] ?? 'normal') . "\n";
echo "Info      : " . (($gefunden['info'] ?? '') !== '' ? $gefunden['info'] : '—') . "\n";

// … oder den kompletten Task als lesbares JSON ausgeben (alle Felder)
echo "\nAlle Felder:\n";
echo json_encode($gefunden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// ── Variante B: Task über den Titel finden ──────────────────────────────────
// Praktisch, wenn die ID nicht bekannt ist. Achtung: Titel sind nicht
// zwingend eindeutig — hier wird der erste Treffer verwendet.
$suchTitel = 'Milch kaufen';

foreach ($tasks as $task) {
    if (mb_strtolower($task['title']) === mb_strtolower($suchTitel)) {
        echo "\nTreffer über Titel: Task #" . $task['id'] . " („" . $task['title'] . "“)\n";
        break;
    }
}
```

#### Task erstellen

```php
<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  ToDo-Liste: Task ERSTELLEN
 * ============================================================================
 *
 *  Funktion: TDL_AddItem(int $InstanzID, mixed $Data): int
 *
 *  $Data ist ein assoziatives Array (alternativ auch ein JSON-String).
 *  Rückgabewert ist die ID des neu angelegten Tasks — diese ID braucht man
 *  später zum Bearbeiten, Erledigen oder Löschen.
 *
 *  Mögliche Felder in $Data (alle optional außer 'title'):
 *
 *  ┌──────────────────────┬──────────────────────────────────────────────────┐
 *  │ title  (PFLICHT)     │ Titel; leerer Titel wirft eine Exception         │
 *  │ info                 │ Zusatz-/Notiztext                                │
 *  │ due                  │ Fälligkeit als Unix-Timestamp (0 = keine).       │
 *  │                      │ Bequem per strtotime('...') erzeugen.            │
 *  │ dueAllDay            │ true = ganztägiger Task (Uhrzeit wird auf        │
 *  │                      │ 00:00 normalisiert); wirkt nur mit due > 0       │
 *  │ priority             │ 'low' | 'normal' | 'high'   (Standard: normal)   │
 *  │ quantity             │ Anzahl/Menge als int (Einkaufslisten-Modus)      │
 *  │ done                 │ true = direkt als erledigt anlegen               │
 *  │ notification         │ true = Benachrichtigung bei Fälligkeit           │
 *  │                      │ (erfordert due > 0)                              │
 *  │ notificationLeadTime │ Vorlaufzeit in Sekunden: 0, 300, 600, 1800,      │
 *  │                      │ 3600, 18000 oder 43200 (0 Min … 12 Std vorher)   │
 *  │ recurrence           │ Wiederholung (erfordert due > 0):                │
 *  │                      │   'none' = keine        'w1' = wöchentlich       │
 *  │                      │   'w2' = alle 2 Wochen  'w3' = alle 3 Wochen     │
 *  │                      │   'm1' = monatlich      'q1' = quartalsweise     │
 *  │                      │   'y1' = jährlich       'custom' = individuell   │
 *  │ recurrenceCustomUnit │ nur bei 'custom': 'h','d','w','m','y'            │
 *  │                      │ (Stunden/Tage/Wochen/Monate/Jahre)               │
 *  │ recurrenceCustomValue│ nur bei 'custom': Intervall als int (z.B. 2)     │
 *  └──────────────────────┴──────────────────────────────────────────────────┘
 *
 *  Hinweise:
 *  - Ohne Fälligkeit (due = 0) werden notification und recurrence
 *    automatisch deaktiviert — das Modul räumt unplausible Kombis selbst auf.
 *  - Ist die Instanz mit CalDAV / Google Tasks / Microsoft To Do gekoppelt,
 *    wird der neue Task automatisch zum Server synchronisiert.
 * ============================================================================
 */

// >>> HIER ANPASSEN <<<
$instanzID = 12345;   // Objekt-ID der ToDo-Liste-Instanz

// ── Beispiel 1: Minimaler Task (nur Titel) ──────────────────────────────────
$neueID = TDL_AddItem($instanzID, [
    'title' => 'Milch kaufen',
]);
echo "Task angelegt, ID = {$neueID}\n";

// ── Beispiel 2: Task mit Fälligkeit, Priorität und Benachrichtigung ─────────
$neueID = TDL_AddItem($instanzID, [
    'title'                => 'Heizungswartung beauftragen',
    'info'                 => 'Firma Müller anrufen, Tel. 01234/56789',
    'due'                  => strtotime('next friday 09:00'), // Unix-Timestamp
    'priority'             => 'high',
    'notification'         => true,   // Benachrichtigung aktivieren …
    'notificationLeadTime' => 3600,   // … 1 Stunde vor Fälligkeit
]);
echo "Task angelegt, ID = {$neueID}\n";

// ── Beispiel 3: Ganztägiger, jährlich wiederkehrender Task ──────────────────
$neueID = TDL_AddItem($instanzID, [
    'title'      => 'Rauchmelder testen',
    'due'        => strtotime('1 december'), // Datum reicht — Uhrzeit wird
    'dueAllDay'  => true,                    // bei dueAllDay auf 00:00 gesetzt
    'recurrence' => 'y1',                    // jährlich wiederholen
]);
echo "Task angelegt, ID = {$neueID}\n";

// ── Beispiel 4: Individuelle Wiederholung (alle 2 Tage) ─────────────────────
$neueID = TDL_AddItem($instanzID, [
    'title'                 => 'Blumen gießen',
    'due'                   => strtotime('tomorrow 18:00'),
    'recurrence'            => 'custom',
    'recurrenceCustomUnit'  => 'd',   // Einheit: Tage
    'recurrenceCustomValue' => 2,     // alle 2 Tage
]);
echo "Task angelegt, ID = {$neueID}\n";

// ── Fehlerbehandlung: leerer Titel wirft eine Exception ─────────────────────
try {
    TDL_AddItem($instanzID, ['title' => '']);
} catch (Exception $e) {
    echo 'Erwarteter Fehler: ' . $e->getMessage() . "\n"; // "Ungültiger Titel"
}
```

#### Task bearbeiten (Update)

```php
<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  ToDo-Liste: Task BEARBEITEN (Update)
 * ============================================================================
 *
 *  Funktion: TDL_UpdateItem(int $InstanzID, mixed $Data): void
 *
 *  WICHTIG — Teil-Update-Prinzip:
 *  $Data muss die 'id' des Tasks enthalten. Geändert werden NUR die Felder,
 *  die im Array vorkommen — alles andere bleibt unangetastet. Man schickt
 *  also nie den kompletten Task, sondern nur die gewünschten Änderungen.
 *
 *  Erlaubte Felder: title, info, due, dueAllDay, priority, quantity, done,
 *  notification, notificationLeadTime, recurrence, recurrenceCustomUnit,
 *  recurrenceCustomValue  (Bedeutung und Wertebereiche: siehe "Task erstellen").
 *
 *  Verhalten, das man kennen sollte:
 *  - Existiert die ID nicht, passiert schlicht nichts (kein Fehler).
 *  - due = 0 entfernt die Fälligkeit; Benachrichtigung und Wiederholung
 *    werden dann automatisch mit abgeschaltet.
 *  - Ist in der Instanz "Erledigte Tasks löschen" aktiviert, führt ein
 *    Update mit done = true zum sofortigen LÖSCHEN des Tasks!
 *  - Zum reinen Erledigen/Wiedereröffnen ist TDL_ToggleDone die bessere
 *    Wahl, weil es Wiederholungen korrekt weiterschaltet.
 *  - Änderungen werden automatisch zum Sync-Backend übertragen
 *    (CalDAV / Google Tasks / Microsoft To Do, falls konfiguriert).
 * ============================================================================
 */

// >>> HIER ANPASSEN <<<
$instanzID = 12345;   // Objekt-ID der ToDo-Liste-Instanz
$taskID    = 7;       // ID des zu bearbeitenden Tasks

// ── Beispiel 1: Nur den Titel ändern ────────────────────────────────────────
TDL_UpdateItem($instanzID, [
    'id'    => $taskID,
    'title' => 'Milch und Butter kaufen',
]);

// ── Beispiel 2: Fälligkeit verschieben und Priorität anheben ────────────────
TDL_UpdateItem($instanzID, [
    'id'       => $taskID,
    'due'      => strtotime('+2 days 08:00'),
    'priority' => 'high',
]);

// ── Beispiel 3: Notiz ergänzen und Benachrichtigung einschalten ─────────────
TDL_UpdateItem($instanzID, [
    'id'                   => $taskID,
    'info'                 => 'Angebot nur bis Samstag gültig',
    'notification'         => true,
    'notificationLeadTime' => 1800,   // 30 Minuten vorher erinnern
]);

// ── Beispiel 4: Fälligkeit komplett entfernen ───────────────────────────────
// (deaktiviert automatisch auch Benachrichtigung und Wiederholung)
TDL_UpdateItem($instanzID, [
    'id'  => $taskID,
    'due' => 0,
]);

// ── Kontrolle: geänderten Task auslesen ─────────────────────────────────────
$tasks = json_decode(TDL_Export($instanzID), true) ?: [];
foreach ($tasks as $task) {
    if ((int)$task['id'] === $taskID) {
        echo "Task nach Update:\n";
        echo json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        break;
    }
}
```

#### Task als erledigt markieren (bzw. wieder öffnen)

```php
<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  ToDo-Liste: Task als ERLEDIGT markieren (bzw. wieder öffnen)
 * ============================================================================
 *
 *  Funktion: TDL_ToggleDone(int $InstanzID, mixed $Data): void
 *
 *  $Data enthält die 'id' des Tasks und optional 'done':
 *  - MIT  'done' => true/false : Status wird gezielt GESETZT (idempotent —
 *    empfohlen für Skripte/Automationen, mehrfacher Aufruf schadet nicht).
 *  - OHNE 'done'               : Status wird UMGESCHALTET (toggle),
 *    wie ein Klick auf die Checkbox in der Visualisierung.
 *
 *  Warum TDL_ToggleDone statt TDL_UpdateItem(done=true)?
 *  ToggleDone enthält die komplette Erledigen-Logik der Kachel:
 *  - Bei WIEDERKEHRENDEN Tasks (recurrence != 'none') wird der Task nicht
 *    einfach abgehakt, sondern die Fälligkeit auf den nächsten Termin
 *    weitergeschaltet (z.B. 'w1' -> +1 Woche) und ggf. direkt wieder
 *    geöffnet — genau wie beim Abhaken in der Visualisierung.
 *  - Ist in der Instanz "Erledigte Tasks löschen" aktiv, wird ein (nicht
 *    wiederkehrender) Task beim Erledigen direkt aus der Liste entfernt.
 *  - Der Erledigt-Zeitpunkt wird in 'doneAt' festgehalten.
 *
 *  Fehlerverhalten: Eine ungültige/fehlende ID wirft eine Exception
 *  ("Ungültige ID") — bei Bedarf mit try/catch absichern.
 * ============================================================================
 */

// >>> HIER ANPASSEN <<<
$instanzID = 12345;   // Objekt-ID der ToDo-Liste-Instanz
$taskID    = 7;       // ID des Tasks

// ── Beispiel 1: Task gezielt als erledigt markieren (empfohlen) ─────────────
TDL_ToggleDone($instanzID, [
    'id'   => $taskID,
    'done' => true,
]);
echo "Task #{$taskID} als erledigt markiert.\n";

// ── Beispiel 2: Task wieder öffnen ──────────────────────────────────────────
TDL_ToggleDone($instanzID, [
    'id'   => $taskID,
    'done' => false,
]);
echo "Task #{$taskID} wieder geöffnet.\n";

// ── Beispiel 3: Status umschalten (Toggle wie in der Visualisierung) ────────
TDL_ToggleDone($instanzID, [
    'id' => $taskID,
]);

// ── Beispiel 4: Task anhand des Titels erledigen ────────────────────────────
// Erst die ID über den Titel suchen, dann erledigen.
$suchTitel = 'Milch kaufen';
$tasks = json_decode(TDL_Export($instanzID), true) ?: [];

foreach ($tasks as $task) {
    if (empty($task['done']) && mb_strtolower($task['title']) === mb_strtolower($suchTitel)) {
        TDL_ToggleDone($instanzID, ['id' => (int)$task['id'], 'done' => true]);
        echo "„{$task['title']}“ (#" . $task['id'] . ") erledigt.\n";
        break;
    }
}
```

#### Task löschen

```php
<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  ToDo-Liste: Task LÖSCHEN
 * ============================================================================
 *
 *  Funktion: TDL_DeleteItem(int $InstanzID, mixed $Data): void
 *
 *  $Data enthält die 'id' des zu löschenden Tasks. Der Task wird endgültig
 *  aus der Liste entfernt — es gibt KEINEN Papierkorb.
 *
 *  Sync-Verhalten: Ist die Instanz mit CalDAV, Google Tasks oder
 *  Microsoft To Do gekoppelt, merkt sich das Modul die Löschung und
 *  entfernt den Task beim nächsten Sync auch auf dem Server. Er taucht
 *  also nicht beim nächsten Abgleich wieder auf.
 *
 *  Fehlerverhalten:
 *  - id <= 0 oder fehlend  -> Exception "Ungültige ID"
 *  - unbekannte (positive) ID -> kein Fehler, es wird nichts gelöscht
 * ============================================================================
 */

// >>> HIER ANPASSEN <<<
$instanzID = 12345;   // Objekt-ID der ToDo-Liste-Instanz
$taskID    = 7;       // ID des zu löschenden Tasks

// ── Beispiel 1: Einzelnen Task löschen ──────────────────────────────────────
TDL_DeleteItem($instanzID, [
    'id' => $taskID,
]);
echo "Task #{$taskID} gelöscht.\n";

// ── Beispiel 2: Löschen mit vorheriger Existenz-Prüfung ─────────────────────
// (sinnvoll, wenn das Skript wiederholt laufen kann)
$tasks   = json_decode(TDL_Export($instanzID), true) ?: [];
$vorhanden = false;
foreach ($tasks as $task) {
    if ((int)$task['id'] === $taskID) {
        $vorhanden = true;
        break;
    }
}

if ($vorhanden) {
    TDL_DeleteItem($instanzID, ['id' => $taskID]);
    echo "Task #{$taskID} gelöscht.\n";
} else {
    echo "Task #{$taskID} existiert nicht (mehr) — nichts zu tun.\n";
}

// ── Beispiel 3: Alle ERLEDIGTEN Tasks aufräumen ─────────────────────────────
// Liste holen, erledigte Tasks herausfiltern und einzeln löschen.
$tasks = json_decode(TDL_Export($instanzID), true) ?: [];
$geloescht = 0;

foreach ($tasks as $task) {
    if (!empty($task['done'])) {
        TDL_DeleteItem($instanzID, ['id' => (int)$task['id']]);
        $geloescht++;
    }
}
echo "{$geloescht} erledigte(r) Task(s) entfernt.\n";
```

## 8. Benachrichtigungen

Pro Task kann über die Checkbox **"Benachrichtigung"** festgelegt werden, ob eine Push-Benachrichtigung verschickt werden soll.

Im Konfigurationsformular der Instanz:

- **"Visualisierungs Instanz"**
  ID einer Kachel-Visualisierung, an die die Push-Benachrichtigung gesendet wird.
- **"Benachrichtigung Vorlauf"**
  Zeit vor dem Fälligkeitstermin, zu der die Benachrichtigung gesendet wird.

Benachrichtigung:

- Titel (Vorlaufzeit = 0): **"Task fällig"**
- Titel (Vorlaufzeit > 0): **"Task in {Vorlaufzeit} fällig"**
- Text: Task-Titel
- Type: **Info**
- TargetID: Instanz-ID der ToDoList

## 9. Synchronisation

Das Modul unterstützt die bidirektionale Synchronisation mit CalDAV-Servern (ownCloud, Nextcloud, etc.), Microsoft To Do und Google Tasks.

Die Zugangsdaten werden zentral im **ToDo Gateway** (Splitter-Modul) verwaltet. In der ToDoList-Instanz wird nur das Backend, die Liste/der Kalender und die Sync-Einstellungen konfiguriert.

Die OAuth-Webhook-Endpunkte (`/hook/todogateway_google` und `/hook/todogateway_microsoft`) registriert das Gateway automatisch bei jedem Start über die native `RegisterHook`-Methode.

Anleitungen zur Konfiguration:

- [CalDAV Synchronisation](https://github.com/da8ter/ToDo-List/blob/main/ToDoList/assets/Readme_CalDav_Sync.md)
- [Google Tasks Synchronisation](https://github.com/da8ter/ToDo-List/blob/main/ToDoList/assets/Readme_Google_Sync.md)
- [Microsoft To Do Synchronisation](https://github.com/da8ter/ToDo-List/blob/main/ToDoList/assets/Readme_Microsoft_Sync.md)

### Anbindung an externe Listen (Alexa)

Der Abgleich läuft **neben**
CalDAV, Google und Microsoft — die externe Liste ist keines der exklusiven
Sync-Backends, eine Liste mit Google-Abgleich kann also zusätzlich Alexa hören.


Per Sprache auf die Liste setzen — „Alexa, setze Milch auf die Einkaufsliste" —
und die Liste bleibt in beide Richtungen gleich: was gesprochen wird, erscheint
hier; was hier entsteht, erscheint bei Alexa, sodass „Alexa, was steht auf
meiner Einkaufsliste?" vollständig vorliest. Abhaken wirkt auf beiden Seiten.

**Was installiert und angelegt werden muss:**

1. Bibliothek „Echo Remote" über das Module Control: `https://github.com/roastedelectrons/IPSymconEchoRemote`
2. Instanz **Echo IO** — meldet sich am Amazon-Konto an (einmal für alles)
3. Instanz **AlexaList** — für Aufgaben mit *Liste* = „Aufgabenliste (Standard)". Die Einkaufsliste braucht eine **eigene** Instanz
4. Dort das Aktualisierungsintervall auf **1–2 Minuten** stellen (Vorgabe ist 60) — dieser Takt entscheidet, wie schnell Gesprochenes ankommt

**Einrichtung:** Im Bereich *Anbindung an externe Listen* den Abgleich einschalten
und im Feld *Alexa-Aufgabenliste* die Instanz wählen.

**Grenzen, ehrlich benannt:**

- Der Verzug ist der Takt des Fremdmoduls. Sofort geht nur über den Knopf.
- Die Schnittstelle ist **inoffiziell** und kann ohne Ankündigung wegfallen.
- Alexa dedupliziert nicht: „Milch" und „3 Milch" stehen dort gleichzeitig.
- Steht in der Alexa-Instanz *Lösche erledigte Einträge* auf an, ist „abgehakt"
  von „gelöscht" nicht unterscheidbar. Beides bedeutet hier „von der Liste".
- Ein fehlender Eintrag gilt nur dann als „von der Liste genommen", wenn die
  Antwort vollständig war. Alexa liefert höchstens 100 Einträge ohne Hinweis auf
  weitere — bei einer längeren Liste wird deshalb nichts abgehakt.
- Ist die Liste gerade nicht lesbar (Netz, Anmeldung), wird **nichts** geändert.
- Neue Einstellungen brauchen einen **Kernel-Neustart**, bevor sie sich
  speichern lassen.

Für Aufgaben gibt es **keine** Mengen-Aufteilung: „Drei Angebote einholen" soll
so heißen und nicht *Angebote einholen* mit Menge 3. Gleichnamige Aufgaben
werden auch nicht zusammengefasst — das ist bei jeder Quelle so. Optional lassen
sich gesprochene Aufgaben einem Familienmitglied zuweisen.

Der Abgleich läuft **neben** CalDAV, Google und Microsoft: die Sprachliste ist
keines der exklusiven Sync-Backends, eine Liste mit Google-Abgleich kann also
zusätzlich Alexa hören.
