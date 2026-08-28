# SymDo Shopping List Overview

Dieses Modul stellt eine kompakte **Übersichts-Kachel** für die Tile-Visualisierung
bereit: die noch offenen Artikel einer **SymDo Shopping List**-Instanz als
waagerecht scrollbare **Bild-Leiste** — dieselbe Vorschau wie auf der
SymDo-Übersicht, nur ohne Bedienelemente. Ein Tipp öffnet ein frei wählbares
Objekt.

> Hintergrund: Symcon rendert HTML-Modulinhalte **nicht** innerhalb einer
> Kategorie-Kachel. Damit sich die Vorschau z. B. neben eine Kategorie-Kachel
> stellen lässt, gibt es dieses eigenständige Modul.

## Inhalt

- **1. Funktionsumfang**
- **2. Voraussetzungen**
- **3. Installation**
- **4. Konfiguration in Symcon**
- **5. Funktionsweise**

## 1. Funktionsumfang

- **Zahl der offenen Artikel** — ganz links als quadratische Kachel in der Akzentfarbe, so hoch wie ein Artikel samt Namenszeile, darunter klein „Artikel" und der Einkaufskorb blass im Hintergrund
- **Nur was noch fehlt** — gezeigt werden ausschließlich die **offenen** Artikel; abgehakte verschwinden sofort aus der Leiste
- **Reihenfolge wie überall** — sortiert nach der Kategorien-Reihenfolge der Quell-Liste, also identisch zur Einkaufslisten-Kachel und zur App
- **Produktbilder** — dieselbe Auflösung wie in der Liste: das beim Scannen gefundene Bild, sonst das über den Artikelnamen ermittelte (samt Marken-Nachschlag). Ohne Treffer der **Anfangsbuchstabe** auf getöntem Grund, dessen Farbe sich aus dem Namen ergibt
- **Mengen-Abzeichen** — die Menge steht als kleine Pille unten rechts am Bild, genau wie im Artikelstreifen der SymDo-Übersicht. Ohne Angabe zeigt sie `1`; Angaben wie `500 g` stehen unverändert da
- **Lange Namen laufen** — passt ein Artikelname nicht unter sein Bild, wandert er langsam hin und her, statt abgeschnitten dazustehen. Wer passt, bleibt in Ruhe. Ist im System die Bewegungsreduzierung eingeschaltet, wird stattdessen mit Auslassungspunkten gekürzt
- **Größe einstellbar** — Bildhöhe und Schriftgröße frei wählbar
- **Waagerecht scrollbar** — ohne sichtbare Bildlaufleiste, auf dem Touchgerät mit Schwung
- **Tippen hakt ab** — ein Tipp auf einen Artikel legt ihn in den Einkaufswagen; er verblasst sofort und verschwindet mit dem nächsten Zustand aus der Leiste. Die Kachel schaltet dabei nicht um, sondern setzt „im Wagen" ausdrücklich: sie zeigt ohnehin nur offene Artikel, und eine doppelt zugestellte Anfrage soll ihn nicht zurückholen
- **Tippen daneben öffnet ein Objekt** — auf der freien Fläche wird über `openObject()` ein frei wählbares Objekt oder eine Kategorie geöffnet; ohne Ziel passiert dort nichts
- **Live-Aktualisierung** — jede Änderung an der Einkaufsliste zieht ohne Neuladen nach
- **Leere Liste sagt es** — steht nichts mehr an, erscheint „Liste ist leer" statt einer leeren Fläche

## 2. Voraussetzungen

- Symcon ab Version **8.1**
- Nutzung in der **Kachel-Visualisierung** (Tile-Visualisierung)
- Eine **SymDo Shopping List**-Instanz als Quelle
- Für Produktbilder: in der Quell-Liste müssen die **Produktbilder eingeschaltet** sein

## 3. Installation

1. Bibliothek über das Module Control installieren: `https://github.com/da8ter/ToDo-List.git`
2. Falls noch nicht vorhanden: eine **SymDo Shopping List**-Instanz anlegen
3. Eine Instanz vom Typ **SymDo Shopping List Overview** anlegen
4. Im Formular die Einkaufsliste wählen und die Instanz in der Kachel-Visualisierung einbinden

## 4. Konfiguration in Symcon

| Einstellung | Eigenschaft | Bedeutung |
|---|---|---|
| Einkaufslisten-Instanz | `ShoppingListInstanceID` | Quelle der Artikel. Die Auswahl listet alle gefundenen Einkaufslisten mit Namen und ID |
| Beim Antippen öffnen | `OpenObjectID` | Objekt oder Kategorie, die sich beim Tippen **neben** einen Artikel öffnet. `0` = kein Ziel; abgehakt wird trotzdem |
| Bildhöhe | `ImageHeight` | Kantenlänge der runden Vorschaubilder in Pixeln (Minimum 24) |
| Schriftgröße | `FontSize` | Schriftgröße der Artikelnamen in Pixeln (Minimum 7) |

Eine gespeicherte, aber nicht mehr auffindbare Einkaufsliste bleibt in der
Auswahl stehen — mit dem Zusatz *nicht gefunden*. Sie verschwindet sonst
kommentarlos, und beim nächsten Speichern wäre die Zuordnung weg, ohne dass
jemand es bemerkt hätte.

## 5. Funktionsweise

Die Kachel hängt an zwei Kennzahl-Variablen der Quell-Instanz: `ItemCount` und
`LastUsed`. Beide werden bei jeder Änderung geschrieben und dienen als Auslöser —
die Kachel zieht dann den vollständigen Zustand über `SL_GetAppState()` nach. Die
Abos werden bei jedem `ApplyChanges` sauber gelöst und neu gesetzt; ein Wechsel
der Quell-Instanz hinterlässt also keine verwaisten Abonnements.

Sowohl die Quell-Instanz als auch das Klick-Ziel werden als **Referenz**
eingetragen. Symcon warnt damit, bevor jemand ein Objekt löscht, an dem diese
Kachel hängt.

Beim ersten Rendern gibt `GetVisualizationTile()` den Zustand **inline** mit, statt
ihn nachzuliefern: sonst stünde die Kachel nach dem Öffnen der Visualisierung
einen Moment leer da.

Die Bilder liefert die Quell-Instanz über `SL_GetTileImageBase()` aus; die
Zuordnung Artikelname → Datei kommt als `availableImages` bzw. `availableBrands`
mit dem Zustand. Schlägt ein Bild fehl, ersetzt die Kachel es zur Laufzeit durch
den Anfangsbuchstaben — ein kaputtes Bildsymbol wäre die schlechtere Auskunft.
