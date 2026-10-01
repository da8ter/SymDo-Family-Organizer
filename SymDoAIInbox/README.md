# SymDo - KI-Eingang

![SymDo — KI-Eingang](https://raw.githubusercontent.com/da8ter/images/main/SymDo%20KI-Eingang.png)

**Der KI-Eingang der SymDo-App als eigene Kachel für die Tile-Visualisierung.**

Dieselben Vorschläge, dieselbe Ansicht wie in der Web-App — nur ohne die übrigen Bereiche. Was die KI in weitergeleiteten E-Mails, Elternbriefen oder Karten der Klassenseiten gefunden hat, steht hier zum Prüfen bereit: übernehmen als Aufgabe, Termin, Hausaufgabe oder Notiz, anpassen oder verwerfen.

Einzurichten ist fast nichts: Die Vorschläge liegen im **SymDo Gateway**, die Kachel findet es von selbst. Mail-Eingang, Mailgun und KI-Anbieter werden dort eingestellt.

## Inhalt

- **1. Funktionsumfang**
- **2. Voraussetzungen**
- **3. Installation**
- **4. Konfiguration**
- **5. Funktionsweise**

## 1. Funktionsumfang

- **Vorschläge je Nachricht** mit Betreff, Absender, Familienmitglied und kurzer Zusammenfassung — aus weitergeleiteten Mails (IMAP oder Mailgun), Edumaps- und LOGINEO-Karten
- **Übernehmen** als **Aufgabe** (in eine ToDo-Liste), **Termin** (OpenCalendar), **Hausaufgabe** oder **Notiz** — der passende Dialog öffnet sich vorbelegt, gespeichert wird erst auf Knopfdruck
- **Art wechseln**, **verwerfen** oder eine Nachricht ganz **löschen**; übernommene Einträge bleiben einige Tage mit Haken sichtbar
- **Original ansehen**: Text und Anhänge so, wie die KI sie gelesen hat — auf Wunsch wird das Original mit dem Eintrag aufbewahrt
- **Zauberstab**: Foto, Datei oder Text direkt in der Kachel analysieren lassen, wenn die KI im Gateway eingeschaltet ist

## 2. Voraussetzungen

- Symcon ab Version **8.1**
- Nutzung in der **Kachel-Visualisierung** (Tile-Visualisierung)
- Eine **SymDo - Gateway**-Instanz mit eingeschalteter KI und mindestens einem Eingangsweg (Mail, Mailgun, Edumaps oder LOGINEO)
- Für „Als Aufgabe" eine **SymDo - ToDo Liste**, für Termine das Modul **OpenCalendar**

## 3. Installation

1. Bibliothek über das Module Control installieren: `https://github.com/da8ter/SymDo-Family-Organizer.git`
2. Falls noch nicht vorhanden: eine Instanz **SymDo - Gateway** anlegen und dort KI und Mail-Eingang einrichten
3. Eine Instanz **SymDo - KI-Eingang** anlegen und in der Kachel-Visualisierung einbinden

Beim Anlegen bietet die Konsole ein vorhandenes Gateway an oder legt eines an. Der **SymDo - Installationsassistent** legt die Kachel auf Wunsch mit an.

## 4. Konfiguration

| Einstellung | Eigenschaft | Bedeutung |
|---|---|---|
| Übernehmen als | `DefaultUserID` | Das Familienmitglied, unter dem übernommene Aufgaben und Notizen abgelegt werden — eine Visualisierung kann nicht fragen, wer vor ihr steht |
| Aufgabenliste | `TodoListID` | Die ToDo-Liste für „Als Aufgabe". Leer: alle ToDo-Listen stehen im Dialog zur Wahl |

Der Knopf **Kachel neu laden** stößt die Anzeige an. Die Statuszeile nennt das gefundene Gateway und warnt, wenn die KI dort ausgeschaltet ist.

## 5. Funktionsweise

Die Kachel hat **keine eigene Oberfläche**: `module.html` ist die Web-App aus **SymDo - Web App**, wortgleich übernommen (`tools/uebernahme-webapp.py`), samt Übersetzungen. Wer am KI-Eingang etwas ändern will, ändert ihn in der Web-App und übernimmt neu.

Die Vorschläge bleiben im Gateway — dort hängen Mail-Abruf, Tagesdeckel und Originale. Vorschläge, Termine, Notizen und Hausaufgaben reisen über das Relay des Gateways. Eine als Aufgabe übernommene Zeile geht direkt an die ToDo-Liste; die Kachel reicht dabei **nur das Anlegen** weiter und nur an die eingestellten Listen.

Neue Vorschläge erscheinen in der Kachel mit dem Minutentakt. Web-App und iOS-App zeigen den KI-Eingang unverändert weiter; ausführlich beschrieben sind Mail-Eingang und KI im README des [SymDo - Gateway](../SymDoGateway/README.md).
