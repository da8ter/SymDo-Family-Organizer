# ToDo Übersicht

Dieses Modul stellt eine kompakte **Übersichts-Kachel** für die Tile-Visualisierung bereit. Sie zeigt die drei Kennzahlen einer **ToDo List**-Instanz (**Offen**, **Überfällig**, **Heute**) und öffnet beim Klick ein frei wählbares Objekt bzw. eine Kategorie.

> Hintergrund: IP-Symcon rendert HTML-Modulinhalte **nicht** innerhalb einer Kategorie-Kachel. Damit die Übersicht z. B. neben einer Kategorie-Kachel platziert werden kann, gibt es dieses eigenständige Modul.

## Inhalt

- **1. Funktionsumfang**
- **2. Voraussetzungen**
- **3. Installation**
- **4. Konfiguration in IP-Symcon**
- **5. Responsives Verhalten**
- **6. Funktionsweise**

## 1. Funktionsumfang

- **Drei Kennzahlen** – Anzeige von **Offen**, **Überfällig** und **Heute** aus einer konfigurierten ToDo-List-Instanz
- **Einzeln ein-/ausblendbar** – Pro Wert ein Backend-Schalter (Standard: alle an)
- **Klick öffnet ein Objekt** – Beim Tippen auf die Kachel wird über `openObject()` ein frei wählbares Objekt/eine Kategorie geöffnet
- **Live-Aktualisierung** – Änderungen an der ToDo-Liste werden automatisch übernommen (ohne Neuladen)
- **Responsives Einklappen** – Passt sich der Kachelbreite an (3 → 2 → 1 Feld), Werte füllen die Kachel mit großen Zahlen
- **Roter Hintergrund bei Überfälligen** – Optional: Kachel wird in der kompakten Ansicht rot, wenn überfällige Tasks vorhanden sind

## 2. Voraussetzungen

- IP-Symcon ab Version **8.1**
- Nutzung in der **Kachel-Visualisierung** (Tile-Visualisierung)
- Eine vorhandene **ToDo List**-Instanz als Datenquelle

## 3. Installation

1. Bibliothek über das Module Control installieren: `https://github.com/da8ter/ToDo-List.git`
2. In IP-Symcon eine Instanz vom Typ **ToDo List** anlegen (Datenquelle), falls noch nicht vorhanden
3. Eine Instanz vom Typ **ToDo Übersicht** anlegen
4. Instanz in der Kachel-Visualisierung einbinden

## 4. Konfiguration in IP-Symcon

### Instanz-Eigenschaften

- **ToDo Liste Instanz** (`ToDoListInstanceID`)
  - Die ToDo-List-Instanz, deren Kennzahlen angezeigt werden.
- **Objekt/Kategorie bei Klick öffnen** (`OpenObjectID`)
  - Objekt, das beim Klick auf die Kachel geöffnet wird (z. B. eine Kategorie mit der vollständigen Liste, oder die ToDo-List-Instanz selbst). Ohne Auswahl ist die Kachel nicht klickbar.
- **Offen anzeigen** (`ShowOpen`)
  - Blendet den Wert **Offen** ein/aus (Standard: an).
- **Überfällig anzeigen** (`ShowOverdue`)
  - Blendet den Wert **Überfällig** ein/aus (Standard: an).
- **Heute anzeigen** (`ShowToday`)
  - Blendet den Wert **Heute** ein/aus (Standard: an).
- **Roter Kachel-Hintergrund bei Überfälligen (kompakt)** (`OverdueRedBackground`)
  - Färbt die Kachel in der kompakten Ansicht rot, sobald überfällige Tasks vorhanden sind (Standard: aus).

## 5. Responsives Verhalten

Die Anzeige richtet sich nach der Kachelbreite und arbeitet nur innerhalb der aktivierten Werte:

| Breite | Anzeige |
|--------|---------|
| breit | alle aktivierten Felder nebeneinander |
| ~150–240 px | bei drei aktiven Feldern: **Offen** + **Überfällig** oben, **Heute** volle Breite darunter |
| < ~150 px | nur ein Feld: **Überfällig** (wenn > 0), sonst **Offen** – jeweils aus der aktivierten Menge |

Der rote Hintergrund (falls aktiviert) erscheint in der kompakten Ansicht (ab ~240 px abwärts), wenn **Überfällig** aktiviert und > 0 ist.

## 6. Funktionsweise

- Das Modul liest die Statusvariablen **OpenTasks**, **OverdueTasks** und **DueTodayTasks** der konfigurierten ToDo-List-Instanz und abonniert deren Änderungen (`VM_UPDATE`).
- Es legt **keine eigenen Statusvariablen** an.
- Die Navigation beim Klick erfolgt clientseitig über die Tile-Visualisierungs-Funktion `openObject()` – ohne zusätzliche Aktion im Backend.
