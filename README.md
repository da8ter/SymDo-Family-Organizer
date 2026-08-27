# SymDo — Family Organizer

Einkaufsliste, Aufgaben, Termine und Notizen an einem Ort: **SymDo** bringt den
Familienalltag aufs Handy und an die Wandvisualisierung — ohne App-Store, ohne
Cloud-Zwang, direkt aus dem eigenen Symcon. Jedes Familienmitglied sieht auf einen
Blick, was ansteht, wer dran ist und was noch in den Einkaufswagen muss.

Die Bibliothek bringt dafür sieben Module mit. Die beiden Listen laufen auch
einzeln als Kachel; alles Weitere kommt mit dem Gateway dazu.

## Was SymDo kann

- **Zwei Oberflächen, eine Datei** — die Web-App fürs Handy (per QR-Code
  gekoppelt, zum Home-Bildschirm hinzufügbar) und die Kachel in der
  Tile-Visualisierung sind buchstäblich dieselbe Seite. Sie können sich gar nicht
  auseinander entwickeln.
- **Bereiche**: Übersicht, Einkaufen (samt Favoriten), ToDos, Kalender, Notizen
  und KI-Eingang — jeder einzeln abschaltbar.
- **Familienmitglieder** mit Foto: Aufgaben und Termine lassen sich zuweisen, und
  die Avatar-Leiste filtert quer über alle Bereiche — wer oben eine Person
  antippt, sieht überall nur deren Einträge.
- **Fotografieren statt abtippen** — Elternbrief oder Terminzettel knipsen: die
  KI macht daraus fertige Vorschläge für Aufgaben, Termine und Notizen. Angelegt
  wird nichts von allein; jeder Fund wird im vorausgefüllten Dialog bestätigt.
  Dasselbe geht mit PDF, eingefügtem Text und mit weitergeleiteten E-Mails.
- **Tägliches Briefing** — die KI fasst morgens zusammen, was heute zählt:
  Termine, fällige Aufgaben, Schulzeiten der Kinder, Einkaufsliste, Geburtstage
  und Jahrestage. Auf Wunsch vorgelesen, im Ton eurer Wahl — vom höflichen Butler
  bis zum Drillsergeant. Sprachausgabe über OpenAI, Microsoft Azure, ElevenLabs
  oder Amazon Polly.
- **Kalender** — Termine aus [OpenCalendar](https://github.com/Burki24/OpenCalendar)
  lesen, anlegen und bearbeiten. Bei einer Serie fragt die App nach der Reichweite
  (nur dieser Termin, dieser und alle folgenden, die ganze Serie), und
  Jahresereignisse — Geburtstag, Jahrestag, Hochzeits- und Todestag — brauchen nur
  Art und Ursprungsdatum; den Rest rechnet der Kalender.
- **Notizen** — Ordner je Familienmitglied und selbst angelegte, Notizen mit Text
  und Anhängen (Bild, PDF).
- **Stundenplan der Kinder** auf der Übersicht: je Kind ein Balken mit dem
  Schultag, ein Strich für die aktuelle Zeit, ein Umschalter durch die Woche.
- **Web-Push aufs Handy** bei fälligen Aufgaben, neuem Briefing und neuen
  KI-Vorschlägen; Termin-Erinnerungen zusätzlich über die Visualisierung.
- **Einkaufs-Ansage** — die App liest die Einkaufsliste Abteilung für Abteilung
  vor; ein Druck auf die Kopfhörer-Taste hakt ab und sagt den nächsten Artikel an.
- **Rezeptanalyse** — Rezeptfoto, PDF oder Rezept-URL wird zur Zutatenliste,
  Portionen skalierbar, Zutaten direkt auf die Einkaufsliste.

## Die Module

| Modul | Art | Wofür |
|---|---|---|
| **SymDo Gateway** | Splitter | Die Zentrale: liefert die Web-App aus, verwaltet Kopplung, Geräte und Familienmitglieder, KI, Briefing und Push. Zugleich Sync-Broker für Google Tasks, Microsoft To Do und CalDAV |
| **SymDo Web App** | Device | Dieselbe Oberfläche als Kachel — und die Stelle, an der eingestellt wird, welche Bereiche und Bedienelemente App und Kachel zeigen |
| **SymDo ToDo List** | Device | Eine Aufgabenliste, als eigene Kachel und als Datenquelle für SymDo |
| **SymDo Shopping List** | Device | Eine Einkaufsliste, ebenso |
| **SymDo Stundenplan** | Device | Der Wochenplan der Kinder |
| **SymDo ToDo Overview** | Device | Kennzahlen einer Aufgabenliste als kleine Kachel |
| **SymDo Shopping List Overview** | Device | Die offenen Artikel als Bild-Leiste |

## Schnellstart

1. Bibliothek über das Module Control installieren: `https://github.com/da8ter/ToDo-List.git`
2. **SymDo ToDo List**- und/oder **SymDo Shopping List**-Instanzen anlegen — sie
   sind die Datenquellen
3. Eine **SymDo Gateway**-Instanz anlegen; Familienmitglieder eintragen
4. Eine **SymDo Web App**-Instanz anlegen und in die Kachel-Visualisierung
   einbinden; dort auswählen, welche Listen und Bereiche erscheinen
5. Im Gateway unter *Web-App verbinden* den Browser-Zugang erzeugen und den
   QR-Code mit dem Handy scannen

Ohne Gateway funktionieren die Listen-Kacheln vollständig; Kalender, Notizen,
KI-Eingang und Briefing kommen erst mit ihm.

## Voraussetzungen

- Symcon ab Version **8.1**
- Für die Web-App unterwegs: **Symcon Connect** oder eine eigene HTTPS-Adresse
- Für den Kalender: das Store-Modul **OpenCalendar** von Burkhard Kneiseler,
  optional
- Für Ferien und Feiertage im Stundenplan: **OpenHolidaysAPI** (nichts zu
  installieren) oder das Store-Modul **Jahreskalender (Almanac)** von Wilkware,
  optional
- Für die KI: ein eigener API-Schlüssel (**Anthropic** oder **OpenAI**) oder ein
  lokaler, OpenAI-kompatibler Server (z. B. LM Studio), optional
- Für die Sprachausgabe: **OpenAI**, **Azure Speech**, **ElevenLabs** oder
  **Amazon Polly**, optional

---

# Die Kacheln im Einzelnen

## SymDo ToDo List

Aufgabenverwaltung für die Kachel-Visualisierung — und Datenquelle für SymDo.

- Anlegen, bearbeiten, abhaken und löschen direkt in der Visualisierung; Löschen
  per Wischgeste
- Aufgaben mit Notiz, Priorität, Menge und Fälligkeit (mit Uhrzeit oder ganztägig)
- Wiederkehrende Aufgaben, wöchentlich bis jährlich oder mit eigenem Intervall
- Zwei-Wege-Synchronisation mit **Google Tasks**, **Microsoft To Do** und
  **CalDAV** (z. B. Nextcloud), dazu Abgleich mit der **Alexa**-Aufgabenliste
- Push-Benachrichtigung vor Fälligkeit, Vorlaufzeit je Aufgabe
- Zählerwerte (offen, überfällig, heute fällig) als Variablen für eigene
  Automationen

→ [Ausführliche Anleitung](ToDoList/README.md)

![ToDo List](https://github.com/da8ter/images/blob/main/todo.png)

## SymDo Shopping List

Einkaufsliste für die Kachel-Visualisierung — und Datenquelle für SymDo.

- Artikel anlegen, abhaken und löschen; Vorschläge aus häufig gekauften Artikeln
- Gruppierung nach Kategorien, Reihenfolge frei wählbar, optionale Produktbilder
- **Barcode-Scanner** über die Gerätekamera mit Produkterkennung
- Abgleich mit **Alexa** und **Bring!** — beide gleichzeitig, in beide Richtungen
- **Favoritenlisten** (z. B. Wocheneinkauf) mit einem Klick auf die Liste setzen
- Abgehakte Artikel landen unter „Zuletzt benutzt" und lassen sich von dort
  zurückholen
- Druckfunktion mit drei Layouts

→ [Ausführliche Anleitung](ShoppingList/README.md)

![Shopping List](https://github.com/da8ter/images/blob/main/shoppinglist.png)

## SymDo Stundenplan

Der Wochenplan der Kinder — als eigene Kachel und, je Instanz zuschaltbar, auf
der SymDo-Übersicht.

- **Wochenraster** mit fester Zeitachse und farbigen Stunden, heutiger Tag
  hervorgehoben — oder **Timeline** je Kind mit Strich für die aktuelle Zeit
- Fächer selbst gepflegt: Name, Symcon-Symbol und Farbe; die Farbe einer Stunde
  kommt immer vom Fach
- Eingetragen wird je Kind, darin eine schmale Liste je Wochentag mit Zeitwähler
- Betreuung je Kind und Tag, getrennt vom Unterricht gezählt
- Samstag je Kind zuschaltbar, auch nur in geraden oder ungeraden Wochen
- Ferien und Feiertage über **OpenHolidaysAPI** (kostenlos, ohne Konto) oder das
  Store-Modul [**Jahreskalender**](https://github.com/Wilkware/Almanac) von Wilkware;
  an freien Tagen wird der Balken grau und nennt den Anlass

→ [Ausführliche Anleitung](Stundenplan/README.md)

## SymDo Web App

Die SymDo-Oberfläche als Kachel — und das Backend, in dem steht, was App und
Kachel zeigen: sichtbare Bereiche, Listenauswahl, Bedienelemente, Info-Abzeichen
und die Stundenplan-Instanzen.

→ [Ausführliche Anleitung](SymDoWebApp/README.md)

## SymDo Gateway

Die Zentrale hinter allem: Web-App-Auslieferung, Kopplung und Geräteverwaltung,
Familienmitglieder, KI, Briefing, Push — und der Sync-Broker, über den die
ToDo-Listen mit Google, Microsoft und CalDAV sprechen.

→ [Ausführliche Anleitung](SymDoGateway/README.md)

## SymDo ToDo Overview

Kompakte Kachel mit den drei Kennzahlen einer Aufgabenliste (offen, überfällig,
heute), Farben je Feld, optional roter Hintergrund bei Überfälligen. Ein Tipp
öffnet ein frei wählbares Objekt.

→ [Ausführliche Anleitung](ToDoOverview/README.md)

## SymDo Shopping List Overview

Kompakte Kachel mit den offenen Artikeln einer Einkaufsliste als waagerecht
scrollbare Bild-Leiste — dieselbe Vorschau wie auf der SymDo-Übersicht. Ein Tipp
öffnet ein frei wählbares Objekt.
