# SymDo Web App

Dieses Modul bringt die SymDo-Oberfläche als **Kachel** in die Tile-Visualisierung —
dieselbe Oberfläche, die auf dem Handy als Web-App läuft, nur ohne Kopplung und
ohne Token: an der Wandvisualisierung ist man ohnehin schon im Haus.

Und es ist die Stelle, an der eingestellt wird, **was App und Kachel zeigen**:
welche Bereiche es gibt, welche Listen dazugehören und welche Bedienelemente in
den Zeilen erscheinen.

> Ohne dieses Modul gibt es die SymDo-Oberfläche nur auf dem Handy. Ohne das
> **SymDo Gateway** gibt es hier nur die Listen — Kalender, Notizen, KI-Eingang
> und Briefing liegen dort und blenden sich sonst selbst aus.

## Inhalt

- **1. Funktionsumfang**
- **2. Voraussetzungen**
- **3. Installation**
- **4. Konfiguration in Symcon**
- **5. Was ohne Gateway geht**
- **6. Funktionsweise**
- **7. PHP-Funktionen**

## 1. Funktionsumfang

- **Dieselbe Oberfläche wie auf dem Handy** — Übersicht, Einkaufen, Favoriten, ToDos, Kalender, Notizen und KI-Eingang in einer Kachel, mit denselben Gesten (wischen zum Erledigen und Löschen) wie in der App
- **Findet die Listen selbst** — alle **SymDo ToDo List**- und **SymDo Shopping List**-Instanzen erscheinen im Formular; je Liste ein Schalter zum Ausblenden
- **Einstellungen für App *und* Kachel** — sichtbare Bereiche, Bedienelemente und Info-Abzeichen gelten für beide Oberflächen gemeinsam
- **Standard-Mitglied** — wem schnell angelegte Aufgaben gehören und wessen Aufgaben unter „Meine Aufgaben" stehen
- **Stundenplan der Kinder** — welche Stundenplan-Instanzen auf der Übersicht erscheinen; zwei Instanzen mit denselben Kindern zeigten jedes Kind doppelt, deshalb eine Zeile je Instanz
- **Live ohne Neuladen** — die Kachel hängt an den Kennzahlen der Listen und wird bei jeder Änderung nachgezogen, entprellt zu einem Push statt zu dreien
- **Farben der Visualisierung** — die Kachel meldet die Farben des Skins zurück; App und Web-App übernehmen sie, damit alles gleich aussieht
- **Briefing abspielen** — `SDWA_PlayBriefing()` gibt das Briefing in einer offenen Kachel als Sprachausgabe wieder

## 2. Voraussetzungen

- Symcon ab Version **8.1**
- Nutzung in der **Kachel-Visualisierung** (Tile-Visualisierung)
- Mindestens eine **SymDo ToDo List**- oder **SymDo Shopping List**-Instanz
- Für Kalender, Notizen, KI-Eingang und Briefing zusätzlich eine **SymDo Gateway**-Instanz, optional

## 3. Installation

1. Bibliothek über das Module Control installieren: `https://github.com/da8ter/ToDo-List.git`
2. Falls noch nicht vorhanden: **SymDo ToDo List**- und/oder **SymDo Shopping List**-Instanzen anlegen
3. Eine Instanz vom Typ **SymDo Web App** anlegen
4. Instanz in der Kachel-Visualisierung einbinden

Das Gateway wird automatisch gefunden — es gibt nichts zu verknüpfen. Läuft eines,
erscheinen Kalender, Notizen, KI-Eingang und Briefing von selbst.

## 4. Konfiguration in Symcon

| Einstellung | Eigenschaft | Bedeutung |
|---|---|---|
| Standard-Mitglied | `DefaultUserID` | Bekommt schnell angelegte Aufgaben und speist „Meine Aufgaben" |
| Listen | `Lists` | Je gefundener Liste eine Zeile mit Schalter „ausblenden" |
| Übersicht | `ShowDashboard` | Der Bereich mit Kennzahlen, Briefing und Stundenplan |
| Einkaufen | `ShowShopping` | Einkaufsliste samt Favoriten und Kaufhistorie |
| ToDos | `ShowTodos` | Aufgabenlisten |
| Kalender | `ShowCalendar` | Termine aus OpenCalendar |
| Notizen | `ShowNotes` | Ordner und Notizen aus dem Gateway |
| KI-Eingang | `ShowKi` | Was die KI aus Mails und Dateien gelesen hat |
| Stundenplan | `TimetableChoice` | Welche Stundenplan-Instanzen die Übersicht zeigt — je Instanz eine Zeile. Ohne Stundenplan-Modul fällt die Liste weg |
| Bedienelemente | `ShowMemberBar`, `ShowCreateButton`, `ShowSorting`, `ShowFavoriteHeart`, `ShowRowEditButton`, `ShowRowDeleteButton`, `ShowReorderHandle` | Mitglieder-Leiste, Anlegen-Knopf, Sortierung, Favoriten-Herz, Zeilen-Knöpfe, Verschiebe-Griff |
| Info-Abzeichen | `ShowQuantityBadge`, `ShowRecurrenceBadge`, `ShowDueBadge`, `ShowNotificationBadge`, `ShowPriorityBadge` | Menge, Wiederholung, Fälligkeit, Erinnerung, Priorität |

Drei Dinge, die man dabei wissen sollte:

- Diese Schalter gelten für **alle** Listen von App und Kachel gemeinsam. Die
  gleichnamigen Schalter in den einzelnen Listen-Instanzen bleiben gültig — aber
  für deren **eigene** Kachel. Eine Oberfläche, die alle Listen zusammen zeigt,
  soll nicht von Liste zu Liste anders aussehen.
- Ein ausgeblendeter Knopf entfernt nie eine Funktion: **Wischgesten bleiben
  immer aktiv**. Einzige Ausnahme ist der Verschiebe-Griff — ohne ihn entfällt
  das Ziehen, weil es auf dem Handy sonst mit dem Scrollen kollidierte.
- Die **Kachel** übernimmt eine Änderung sofort, die **Web-App** nach dem
  nächsten Neuladen.

Sind alle Bereiche abgeschaltet, bleibt die Übersicht stehen; bei nur einem
Bereich verschwindet die Tab-Leiste.

## 5. Was ohne Gateway geht

| Bereich | Ohne Gateway | Mit Gateway |
|---|---|---|
| Einkaufen, ToDos, Favoriten | ja | ja |
| Übersicht (Kennzahlen) | ja | ja, dazu Briefing und Stundenplan |
| Kalender, Notizen, KI-Eingang | nein — blenden sich aus | ja |
| Familienmitglieder mit Foto | nein | ja |

Die Kachel fragt nicht nach: fehlt das Gateway, verschwinden die Bereiche, die es
braucht. Ein Tab, der nichts anzeigen kann, ist schlimmer als keiner.

## 6. Funktionsweise

Kachel und Web-App sind **dieselbe Datei**: `SymDoWebApp/module.html`. Symcon
rendert sie hier als Kachel, das Gateway liefert genau diese Datei ans Handy aus.
Die beiden Oberflächen können sich deshalb gar nicht auseinander entwickeln — der
Unterschied liegt nur in der Anbindung: die Web-App spricht über den Hook mit dem
Gateway, die Kachel über `requestAction` direkt mit dieser Instanz.

Die Aktualisierung läuft über die Kennzahl-Variablen der Listen (`ItemCount`,
`OpenTasks`, `OverdueTasks`, `DueTodayTasks`). Weil ein einzelner Vorgang zwei bis
drei dieser Variablen schreibt, werden die Meldungen zusammengefasst: Ein
Abhaken ergibt **einen** Push, nicht drei. Kommen Listen hinzu oder fallen weg,
merkt die Instanz das und legt die Abos neu an.

Was die Kachel an Bildern speichert, liegt unter ihr: Rezeptfotos und -dateien aus
der Rezeptanalyse sammelt das Gateway in einer Kategorie **Rezeptfotos**
unterhalb dieser Instanz.

## 7. PHP-Funktionen

```php
bool SDWA_PlayBriefing(int $InstanzID);   // spielt das aktuelle Briefing in der Kachel ab
```

Zwei Bedingungen, die dabei außerhalb unserer Hand liegen: Die Kachel muss
**offen** sein — eine Visualisierung, die niemand betrachtet, hat keinen
Lautsprecher —, und der Browser darf Ton ohne vorherige Berührung abweisen. In
diesem Fall zeigt die Kachel einen Hinweis, statt still zu bleiben. Der Rückgabewert
sagt nur, dass die Nachricht hinausgegangen ist; ein Zustellnachweis ist er nicht.

Ausführlich beschrieben ist die Oberfläche selbst — Bereiche, Briefing, KI,
Notizen, Kalender — im README des **SymDo Gateway**.
