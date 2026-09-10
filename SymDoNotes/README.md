# SymDo - Notizen

**Der Notizbereich der SymDo-App als eigene Kachel für die Tile-Visualisierung.**

Dieselben Notizen, dieselbe Ansicht wie in der Web-App — nur ohne die übrigen Bereiche. Die Kachel eignet sich für eine Wandvisualisierung, an der nur Notizen gebraucht werden: Einkaufszettel für den Nachmittag, die Liste fürs Wochenende, der Elternbrief als Bild oder PDF.

Es gibt **nichts einzurichten**: Die Notizen liegen im **SymDo Gateway**, die Kachel findet es von selbst und zeigt, was dort ist.

## Inhalt

- **1. Funktionsumfang**
- **2. Voraussetzungen**
- **3. Installation**
- **4. Konfiguration**
- **5. Funktionsweise**

## 1. Funktionsumfang

- **Ordner und Notizen** aus dem Gateway: ein Ordner je Familienmitglied mit dessen Foto, dazu selbst angelegte Ordner, auch **Ordner in Ordnern**; der Pfad oben ist anklickbar
- **Notizen mit Text und Anhängen** — Bilder und PDF-Dateien, aufgenommen oder aus der KI-Analyse übernommen; ein Anhang öffnet sich in der Kachel
- **Anlegen, ändern, löschen** direkt in der Kachel; **Favoriten** verlassen die Ordner und stehen oben
- **Klassenseiten** stehen seit September 2026 NICHT mehr hier: sie haben ihren eigenen Bestand, ihren eigenen Bereich in der App und eine eigene Kachel → [SymDo - Klassenseiten](../SymDoEdumaps/README.md)
- **Sprachdialog**: Ist der Sprachassistent eingerichtet, liest und schreibt auch er in diese Notizen („Schreib eine Notiz für Tim …")

## 2. Voraussetzungen

- Symcon ab Version **8.1**
- Nutzung in der **Kachel-Visualisierung** (Tile-Visualisierung)
- Eine **SymDo - Gateway**-Instanz — dort liegen die Notizen. Ohne Gateway zeigt die Kachel einen Hinweis statt einer leeren Liste

## 3. Installation

1. Bibliothek über das Module Control installieren: `https://github.com/da8ter/SymDo-Family-Organizer.git`
2. Falls noch nicht vorhanden: eine Instanz **SymDo - Gateway** anlegen und die Familienmitglieder eintragen
3. Eine Instanz **SymDo - Notizen** anlegen und in der Kachel-Visualisierung einbinden

Beim Anlegen bietet die Konsole ein vorhandenes Gateway an oder legt eines an. An **einem** Gateway hängen beliebig viele Notiz-Kacheln.

## 4. Konfiguration

| Einstellung | Eigenschaft | Bedeutung |
|---|---|---|
| Schreiben als | `DefaultUserID` | Das Familienmitglied, unter dem eine in dieser Kachel geschriebene Notiz abgelegt wird — eine Visualisierung kann nicht fragen, wer vor ihr steht. *— niemand —* legt Notizen ohne Zuordnung an |

Der Knopf **Kachel neu laden** stößt die Anzeige an, etwa nach einer Änderung an den Familienmitgliedern. Die Statuszeile nennt das gefundene Gateway.

## 5. Funktionsweise

Die Kachel hat **keine eigene Oberfläche**: `module.html` ist die Web-App aus **SymDo - Web App**, wortgleich übernommen (`tools/uebernahme-webapp.py`), samt Übersetzungen. Wer am Notizbereich etwas ändern will, ändert ihn in der Web-App und übernimmt neu — von Hand wird hier nichts nachgepflegt, sonst laufen die beiden Oberflächen auseinander.

Serverseitig sind nur drei Dinge anders als bei der Web-App-Kachel: Der Zustand nennt nur den Notizbereich, die Tab-Leiste fällt damit ganz weg; es gibt keine Listen-Instanzen; und die Notizen selbst liegen im Gateway und reisen über dessen Relay — einen eigenen Zugangs-Token hat die Kachel nicht, weil sie ohnehin im Haus hängt.

Ausführlich beschrieben ist der Notizbereich — Ordner, Anhänge, KI-Übernahme — im README des [SymDo - Gateway](../SymDoGateway/README.md).
