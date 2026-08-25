# SymDo

**Der Familienorganizer für Symcon — mit einer KI, die den Papierkram übernimmt.**

Einkaufsliste, Aufgaben, Termine und Notizen an einem Ort: SymDo bringt den Familienalltag aufs Handy und auf die Wandvisualisierung — ohne App-Store, ohne Cloud-Zwang, direkt aus dem eigenen Symcon. Jedes Familienmitglied sieht auf einen Blick, was ansteht, wer dran ist und was noch in den Einkaufswagen muss.

Das Beste daran: die KI nimmt euch die Tipparbeit ab.

- **Fotografieren statt abtippen** — Elternbrief oder Terminzettel einfach knipsen: die KI macht daraus fertige Aufgaben, Termine und Notizen. Kurz prüfen, übernehmen, fertig.
- **Vom Rezept zum Einkauf** — Rezeptfoto, PDF oder Link analysieren, Portionen einstellen, fertig: die Zutaten stehen auf der Einkaufsliste. Oder gleich als Favoritenliste mit dem Rezept daran — Lieblingsgerichte landen beim nächsten Mal mit einem Klick im Wagen.
- **E-Mails, die sich selbst eintragen** — die Schulmail und die Erinnerung vom Zahnarzt landen als fertige Vorschläge im KI-Eingang, schon dem richtigen Familienmitglied zugeordnet.
- **Ein Briefing wie vom persönlichen Assistenten** — jeden Morgen fasst die KI zusammen, was heute zählt: Termine, fällige Aufgaben, die Einkaufsliste. Auf Wunsch vorgelesen, im Ton eurer Wahl — vom höflichen Butler bis zum Drillsergeant, der die Familie aus dem Bett scheucht.
- **Freihändig einkaufen** — im Laden sagt die Web-App die Einkaufsliste über die Kopfhörer an: ein Tastendruck hakt den Artikel ab und nennt den nächsten. Das Handy bleibt in der Tasche.
- **Nichts geht mehr unter** — Push aufs Handy, wenn eine Aufgabe fällig wird, das Briefing bereitsteht oder die KI etwas Neues gefunden hat.
- **Eure Daten bleiben eure** — die KI läuft mit dem eigenen Schlüssel beim Anbieter eurer Wahl oder komplett lokal im eigenen Netz. Und ohne eure ausdrückliche Einwilligung bleibt sie aus.

Die Oberfläche gibt es doppelt: als Web-App fürs Handy (per QR-Code gekoppelt, als Home-Screen-App installierbar) und als Kachel für die Tile-Visualisierung — beide zeigen denselben Stand. Technisch besteht SymDo aus zwei Instanzen:

| Instanz | Modul | Aufgabe |
|---|---|---|
| **SymDo Gateway** | Splitter (Präfix `TGW`) | Zentrale: liefert die Web-App aus, verwaltet Kopplung, Geräte und Familienmitglieder, KI, Briefing, Push. Zugleich Sync-Broker (Google Tasks, Microsoft To Do, CalDAV) für die ToDo-Listen |
| **SymDoWebApp** | Device (Präfix `SDWA`) | Kachel für die Tile-Visualisierung mit derselben Oberfläche; steuert, welche Bereiche und Bedienelemente App und Kachel zeigen |

> Die Einrichtung der Listen-Synchronisation (Google Tasks, Microsoft To Do, CalDAV) ist in der [ToDo-List-Anleitung](../ToDoList/README.md) beschrieben.

## Inhalt

- **1. Funktionsumfang**
- **2. Voraussetzungen**
- **3. Installation**
- **4. Einrichten der Instanzen in Symcon**
- **5. Kopplung: Web-App und iOS-App**
- **6. Konfiguration: SymDo Gateway**
- **7. Konfiguration: SymDoWebApp (Kachel)**
- **8. Tägliches Briefing**
- **9. Einkaufs-Ansage**
- **10. Rezeptanalyse**
- **11. KI-Funktionen und Datenschutz**
- **12. Benachrichtigungen**
- **13. Statusvariablen**
- **14. PHP-Befehlsreferenz**

## 1. Funktionsumfang

- **Web-App fürs Handy** — vom Gateway ausgeliefert, per QR-Code gekoppelt, zum Home-Bildschirm hinzufügbar; kein App-Store nötig
- **Kachel für die Tile-Visualisierung** — dieselbe Oberfläche als Instanz in der Visualisierung
- **Bereiche**: Übersicht, KI-Eingang, Einkaufen, ToDos, Kalender und Notizen — einzeln abschaltbar
- **Familienmitglieder** mit Name, Rolle und Foto; Aufgaben lassen sich Mitgliedern zuweisen („Meine Aufgaben")
- **Tägliches Briefing** — die KI fasst morgens Termine, Aufgaben und Einkäufe zusammen, wahlweise mit Sprachausgabe; neun Personas vom Butler bis zum Drillsergeant
- **Rezeptanalyse** — Rezeptfoto, PDF oder Rezept-URL wird zur Zutatenliste: Portionen skalierbar, Zutaten direkt auf die Einkaufsliste oder als Favoritenliste gespeichert, auf Wunsch mit dem Rezept selbst daran
- **Einkaufs-Ansage** — die Web-App liest die offene Einkaufsliste Abteilung für Abteilung vor; bedient wird über die Kopfhörer-Taste, ein Druck hakt ab und sagt den nächsten Artikel an
- **KI-Analyse** — Foto, PDF oder eingefügter Text (z. B. eine WhatsApp-Nachricht) wird zu Aufgaben-, Termin- und Notiz-Vorschlägen; jeder Fund lässt sich zwischen Aufgabe, Termin und Notiz umstimmen und wird beim Übernehmen nacheinander im vorausgefüllten Dialog bestätigt (Ordner, Kalender und Zeiten anpassbar). Auf Wunsch werden auch E-Mails ausgewertet (IMAP-Abruf oder Weiterleitung)
- **Notizen** — Ordner je Familienmitglied und selbst angelegte, Notizen mit Text und Anhängen (Bild, PDF)
- **Kalender** — Termine aus dem Store-Modul OpenCalendar lesen, anlegen und bearbeiten
- **Web-Push** — Benachrichtigungen aufs gekoppelte Handy bei fälligen Aufgaben, neuem Briefing und neuen KI-Vorschlägen; Termin-Erinnerungen zusätzlich über die Kachel-Visualisierung
- **Geräteverwaltung** — Liste aller gekoppelten Geräte, einzelne Geräte sperrbar

## 2. Voraussetzungen

- Symcon ab Version **8.1**
- **ToDo List**- und/oder **Einkaufsliste**-Instanzen dieser Bibliothek als Datenquellen
- Für die Web-App unterwegs: **Symcon Connect** (oder eine eigene HTTPS-Adresse)
- Für Web-Push auf dem iPhone: die Web-App muss zum **Home-Bildschirm** hinzugefügt sein (iOS 16.4 oder neuer)
- Für den Kalender-Bereich: das Store-Modul **OpenCalendar** (de.burki24.opencalendar), optional
- Für die E-Mail-Analyse: eine **E-Mail, Empfangen (IMAP)**-Instanz oder eine Mail-Weiterleitung, optional
- Für die KI-Funktionen: ein eigener API-Schlüssel (**Anthropic** oder **OpenAI**) oder ein **lokaler, OpenAI-kompatibler Server** (z. B. LM Studio)
- Für die Sprachausgabe (Briefing und Einkaufs-Ansage): **OpenAI**, **Microsoft Azure Speech**, **ElevenLabs** oder **Amazon Polly** (eigener Schlüssel; bei ElevenLabs ist ein kostenpflichtiger Zugang nötig, Polly rechnet je Zeichen ab)

## 3. Installation

1. Bibliothek über das Module Control installieren: `https://github.com/da8ter/ToDo-List.git`
2. Falls noch nicht vorhanden: **ToDo List**- und **Einkaufsliste**-Instanzen anlegen
3. Eine Instanz **SymDo Gateway** anlegen
4. Eine Instanz **SymDoWebApp** anlegen und in der Kachel-Visualisierung einbinden

## 4. Einrichten der Instanzen in Symcon

1. Im **SymDo Gateway** unter *Familienmitglieder* die Mitglieder anlegen: Name, Nachname, Rolle (z. B. Vater, Mutter, Kind), Geburtstag und Foto. Rolle und Nachname nutzt auch das Briefing für die Anrede.
2. In der **SymDoWebApp**-Instanz die Listen auswählen, die App und Kachel zeigen sollen, und das Standard-Mitglied setzen. Das Gateway wird automatisch gefunden.
3. Web-App und/oder iOS-App koppeln (Kapitel 5).

Betreibt man mehrere Gateway-Instanzen (etwa für getrennte Synchronisation), bedient nur **eine** davon die App — die weiteren arbeiten als reine Sync-Broker und zeigen im Formular an, welche Instanz die App-Seite trägt.

## 5. Kopplung: Web-App und iOS-App

**Web-App:** Im Gateway unter *Web-App verbinden* auf **Browser-Zugang erstellen** klicken. Es erscheint ein QR-Code — mit der Handy-Kamera scannen, die Web-App öffnet sich im Browser und koppelt sich selbst. Der Code ist **10 Minuten** gültig. Danach: *Teilen → Zum Home-Bildschirm* — beim ersten Start vom Home-Bildschirm fragt die App den Kopplungscode einmal von Hand ab (er steht unter dem QR-Code im Formular).

Ohne Symcon Connect lässt sich unter *Lokale HTTPS-Adresse* (`LocalHttpsUrl`) eine eigene Adresse eintragen, z. B. hinter einem Reverse-Proxy.

**iOS-App:** Unter *iOS App verbinden* auf **Neues Gerät koppeln** klicken und den QR-Code mit der SymDo-App scannen.

**Geräte verwalten:** Unter *Gekoppelte Geräte* stehen alle Geräte mit letzter Aktivität. Ein gesperrtes Gerät verliert den Zugriff sofort und muss neu gekoppelt werden; gesperrte Einträge lassen sich anschließend aufräumen.

Kopplungscodes werden nur als Hash gespeichert und verfallen nach 10 Minuten.

## 6. Konfiguration: SymDo Gateway

### Familienmitglieder (`Users`)

Liste der Mitglieder mit Name, Nachname, Rolle, Geburtstag und Foto. Jedes Mitglied bekommt automatisch einen eigenen Notiz-Ordner mit seinem Foto.

### KI-Funktionen

- **KI-Analyse aktivieren** (`AiEnabled`) — Hauptschalter für alle KI-Funktionen (Standard: an)
- **Tageslimit** (`MailDailyLimit`) — höchstens so viele KI-Aufrufe pro Tag, ein gemeinsamer Topf für Foto-/PDF-Analyse, Mail-Analyse und Briefing (Standard: 100)
- **Datenschutz** — die KI-Funktionen laufen erst nach erteilter Einwilligung; sie ist jederzeit widerrufbar (Kapitel 11)
- **KI-Anbieter** (`AiProvider`) — `Anthropic`, `OpenAI` oder ein lokaler OpenAI-kompatibler Server; je nach Wahl Schlüssel bzw. Server-Adresse und Modellname eintragen

### Tägliches Briefing

Siehe Kapitel 8. Felder: Briefing aktivieren (`BriefingEnabled`), *Geschrieben für* (`BriefingUserID`), Uhrzeit (`BriefingTime`), Persona (`BriefingTone`), **Personas bearbeiten** (`BriefingVoices`), Sprachausgabe (`BriefingAudioEnabled`), Vorschau am Vorabend (`BriefingPreviewEnabled`, `BriefingPreviewFrom`) sowie der Sprachausgabe-Anbieter (`TtsProvider` mit den zugehörigen Feldern je Anbieter).

### KI-E-Mail-Analyse (`MailEnabled`)

Die KI liest eingehende Mails und macht daraus Vorschläge — Aufgabe, Termin oder Notiz — die im **KI-Bereich** der App zum Übernehmen bereitliegen. Zwei Wege, einzeln oder kombiniert:

1. **Mails aus IMAP-Postfächern abrufen** — eine gemeinsame `E-Mail, Empfangen (IMAP)`-Instanz (`MailBoxGeneral`) und/oder je Mitglied ein eigenes Postfach (`MailBoxes`). Anhänge werden auf Wunsch mitgelesen (`MailReadAttachments`) und bei Notiz-Vorschlägen dauerhaft abgelegt (`MailNoteAttachments`, Standard: aus). Verarbeitete Mails lassen sich löschen (`MailDeleteAfter`).
2. **Mails an Symcon weiterleiten** (`MailHookEnabled`) — ein WebHook nimmt weitergeleitete Mails an; abgesichert über Secret, Signaturschlüssel und Größenlimit (`MailHookSecret`, `MailHookSigningKey`, `MailHookBase`, `MailHookMaxKB`, `MailHookApiKey`).

Zuordnung und Filter: `MailAddresses` ordnet Absender-Adressen den Mitgliedern zu (der Vorschlag landet beim richtigen Mitglied), `MailSenderAllow` beschränkt die Auswertung auf erlaubte Absender.

### Benachrichtigungen

- **Web-App-Benachrichtigungen** — Push aufs gekoppelte Handy bei fälligen Aufgaben (`PushOnTaskDue`), neuem Briefing (`PushOnBriefing`) und neuen Mail-Vorschlägen (`PushOnMailProposal`); dazu ein Testknopf an alle Geräte
- **Visualisierungs-Benachrichtigungen** — Termin-Erinnerungen zusätzlich über eine Kachel-Visualisierungsinstanz (`CalNotifyVisuID`)

### Synchronisation (Google Tasks, Microsoft To Do, CalDAV)

Das Gateway ist zugleich der Sync-Broker der ToDo-Listen. Einrichtung und Ablauf: [ToDo-List-Anleitung](../ToDoList/README.md).

## 7. Konfiguration: SymDoWebApp (Kachel)

- **Standard-Mitglied** (`DefaultUserID`) — wird schnell angelegten Aufgaben zugewiesen und speist den Bereich „Meine Aufgaben"
- **Listen** (`Lists`) — welche ToDo- und Einkaufslisten App und Kachel zeigen
- **Sichtbare Bereiche** — Übersicht (`ShowDashboard`), Einkaufen (`ShowShopping`), ToDos (`ShowTodos`), Kalender (`ShowCalendar`), Notizen (`ShowNotes`), KI-Eingang (`ShowKi`). Der Kalender-Bereich braucht zusätzlich mindestens einen OpenCalendar-Kalender. Sind alle Bereiche aus, bleibt die Übersicht stehen; bei nur einem Bereich verschwindet die Tab-Leiste
- **Bedienelemente** — Mitglieder-Leiste, Anlegen-Knopf, Sortierung, Favoriten-Herz, Zeilen-Knöpfe, Verschiebe-Griff (`ShowMemberBar`, `ShowCreateButton`, `ShowSorting`, `ShowFavoriteHeart`, `ShowRowEditButton`, `ShowRowDeleteButton`, `ShowReorderHandle`)
- **Info-Abzeichen** — Menge, Wiederholung, Fälligkeit, Erinnerung, Priorität (`ShowQuantityBadge`, `ShowRecurrenceBadge`, `ShowDueBadge`, `ShowNotificationBadge`, `ShowPriorityBadge`)

Diese Schalter gelten für **alle** Listen dieser Web-App und dieser Kachel gemeinsam; die gleichnamigen Schalter in den einzelnen Listen-Instanzen gelten weiterhin für deren eigene Kacheln. Wischgesten bleiben immer aktiv — ein ausgeblendeter Knopf entfernt nie die Funktion; einzige Ausnahme ist der Verschiebe-Griff. Die Web-App übernimmt Änderungen nach dem Neuladen, die Kachel sofort.

## 8. Tägliches Briefing

Die KI schreibt jeden Morgen zur eingestellten Uhrzeit (Standard 5:30 Uhr) eine kurze Zusammenfassung: Termine des Tages, fällige und überfällige Aufgaben, Stand der Einkaufsliste. Das Briefing erscheint auf der Übersicht der App und der Kachel — auf Wunsch mit Sprachausgabe zum Abspielen.

- **Geschrieben für** — ein einzelnes Mitglied (persönliche Anrede) oder *— die ganze Familie —* (Haushalts-Briefing: alle werden gemeinsam angesprochen, jede Aufgabe mit dem Namen des Zuständigen). Lebt nur ein Mitglied im Haushalt, spricht auch das Familien-Briefing es automatisch persönlich an
- **Persona** — Tonfall des Texts **und** der Stimme: Sachlich, Förmlich, Butler, Lustig, Drillsergeant, Motivationstrainer, Jammerlappen, Digga. Die Persona *Förmlich* siezt Erwachsene mit Nachnamen („Herr Muster"), Kinder bleiben beim Vornamen und Du
- **Länge** — *Ausführlich* schreibt wie bisher einen Fließtext über den Tag.
  *Kompakt* hält eine feste Reihenfolge ein: kurze Begrüßung, dann Termine und
  Aufgaben in je einem knappen Hauptsatz, danach wie lange welches Kind Schule
  hat, zum Schluss die Anzahl der Artikel auf der Einkaufsliste. Die Schulzeiten
  stehen in **beiden** Fassungen; sie kommen aus dem Stundenplan-Modul — ohne es
  entfällt dieser Teil. Der Tonfall
  gilt weiterhin, die Kürze hat aber Vorrang: mit *Sachlich* sind es rund 600
  Zeichen, mit *Lustig* bleibt es länger, weil die Persona Bilder braucht
- **Einkaufsliste im Briefing** — sie wird erst ab **fünf** offenen Artikeln
  erwähnt. Darunter steht im Auftrag an die KI ausdrücklich, dass sie die Liste
  nicht zum Thema machen darf; das bloße Weglassen der Zahl genügte nicht, dann
  hat sich das Modell eine ausgedacht
- **Abendvorschau** — das Briefing auf morgen beginnt nicht mit „Guten Morgen",
  sondern schaut voraus („Für morgen ist Folgendes geplant"). Es entsteht abends
  und spricht über den nächsten Tag; ein Tagesgruß wäre dort falsch
- **Personas bearbeiten** — je Persona lässt sich für jeden Sprachausgabe-Anbieter eine eigene Stimme hinterlegen; ohne Eintrag gilt die eingebaute Vorgabe
- **Vorschau am Vorabend** — ab der eingestellten Uhrzeit (Standard 18:00 Uhr) zeigt die Übersicht das Briefing für morgen
- **Für eigene Automationen** liegen Text und Aufnahme des gezeigten Briefings dauerhaft bereit: Statusvariable *Briefing-Text* und Medienobjekt *Briefing-Audio* mit fester Objekt-ID (Kapitel 13)

**Sprachausgabe-Anbieter** (`TtsProvider`):

| Anbieter | Felder | Hinweis |
|---|---|---|
| OpenAI | Stimme, Modell, Sprechanweisung | nutzt den OpenAI-Schlüssel der KI-Funktionen |
| Azure Speech | Schlüssel, Region | deutsche Neural-Stimmen |
| ElevenLabs | Schlüssel, Stimme, Modell, Stimmen-Umfang, Tonqualität | **kostenpflichtiger Zugang nötig**; „Stimmen des Kontos abrufen" listet die eigenen Stimmen — Vorgabe ist die Rubrik *Meine Stimmen* wie auf der ElevenLabs-Webseite; Tonqualität `auto` wählt die beste Stufe, die zur Textlänge passt |
| Amazon Polly | Zugriffsschlüssel-ID, Geheimschlüssel, Region, Stimme, Engine | rechnet je Zeichen ab, ohne Monatsmindestbetrag, und läuft in Frankfurt (`eu-central-1`). Braucht **zwei** Geheimnisse, weil jede Anfrage unterschrieben wird (Signature Version 4). Anzulegen in der AWS-Konsole unter IAM; die Berechtigung braucht nur `polly:SynthesizeSpeech` und `polly:DescribeVoices`. „Deutsche Stimmen abrufen" füllt die Auswahl. Die **Engine** entscheidet über Klang und Preis: `neural` ist die Vorgabe, `generative` klingt am natürlichsten und kostet mit Abstand am meisten, `standard` ist am günstigsten und hörbar blecherner |

## 9. Einkaufs-Ansage

Im Laden liest die Web-App die offene Einkaufsliste vor — Abteilung für Abteilung, jeder Artikel mit Menge und Notiz („500 Gramm Butter, salzig"). Gestartet wird im Menü der Einkaufsliste über **Einkauf starten**; ein hinterlegter *Hinweis für diesen Einkauf* wird als Erstes angesagt.

Bedient wird mit der Kopfhörer-Taste, das Handy bleibt in der Tasche: Während der Ansage hält ein Druck an und setzt wieder fort — nach einem angesagten Artikel hakt er ihn ab und sagt den nächsten an. Sind alle Artikel im Wagen, meldet die Ansage den Einkauf als komplett und endet von selbst.

Die Tondateien erzeugt der eingestellte Sprachausgabe-Anbieter (Kapitel 8). Jede Ansage wird als Medienobjekt zwischengespeichert und nur einmal erzeugt — „2 Kilo Äpfel" kostet beim Anbieter nur beim ersten Mal. Die Funktion steht in der gekoppelten Web-App zur Verfügung; die Kachel in der Visualisierung hat sie nicht.

## 10. Rezeptanalyse

In der Einkaufsliste öffnet der KI-Knopf das Blatt **Rezept oder Foto scannen**: analysiert wird ein Foto, eine PDF-Datei oder eine Rezept-URL — die Seite holt das Gateway serverseitig. Die KI liefert Titel, Portionszahl und die Zutaten mit Menge und Kategorie.

In der Prüfansicht lässt sich die Portionszahl ändern, alle Mengen skalieren mit (nennt das Rezept keine Portionen, gibt es stattdessen einen ganzzahligen Faktor). Zutaten sind einzeln an- und abwählbar; **Hinzufügen** setzt die Auswahl direkt auf die Einkaufsliste.

**In Favoriten speichern** legt die Zutaten stattdessen als Favoritenliste ab — als neue Liste (der Name ist mit dem Rezepttitel vorbelegt) oder in eine bestehende. Bei einer neuen Liste wird auf Wunsch die Quelle mitgespeichert: Foto oder PDF als Medienobjekt unter „Rezeptfotos", eine URL als Verweis. Die Favoritenliste trägt dann den Knopf **Rezept öffnen** — beim nächsten Mal wandern die Zutaten mit einem Klick komplett auf die Einkaufsliste, und das Rezept zum Kochen ist gleich dabei.

Für gespeicherte Rezeptdateien gilt eine Obergrenze; ist eine Datei zu groß für die Ablage, wird die Favoritenliste trotzdem gespeichert und die App sagt es dazu.

## 11. KI-Funktionen und Datenschutz

Alle KI-Funktionen (Foto-/PDF-Analyse, Mail-Analyse, Briefing) laufen über den **selbst gewählten** Anbieter mit dem **eigenen** Schlüssel — oder vollständig lokal über einen OpenAI-kompatiblen Server. Es gilt:

- Ohne erteilte **Einwilligung** (Formular → KI-Funktionen → Datenschutz) bleibt jede KI-Funktion aus; die Einwilligung ist jederzeit widerrufbar
- Das **Tageslimit** deckelt alle KI-Aufrufe zusammen (Standard: 100 pro Tag)
- API-Schlüssel werden nur für die Anfragen an den gewählten Anbieter verwendet
- Mail-Anhänge werden für die Analyse gelesen, aber nur dauerhaft gespeichert, wenn das ausdrücklich eingeschaltet ist

### Was der gewählte Anbieter kann

| KI-Funktion | Anthropic | OpenAI | Lokaler Server |
|---|:---:|:---:|:---:|
| Text analysieren (eingefügte Nachricht) | ✓ | ✓ | ✓ |
| Tägliches Briefing | ✓ | ✓ | ✓ |
| Rezept-URL auswerten | ✓ | ✓ | ✓ |
| E-Mail-Analyse (Mailtext) | ✓ | ✓ | ✓ |
| Foto analysieren (Aufgaben, Rezept, Notiz, Mail-Anhang) | ✓ | ✓ | ✓ ¹ |
| PDF analysieren — digital erzeugt (mit Textebene) | ✓ | ✓ | ✓ ² |
| PDF analysieren — gescannt (nur Bilder) | ✓ | ✓ | ✓ ¹ ³ |

¹ Nur mit einem Modell, das **Bilder versteht** (Vision). Ein reines Textmodell liefert leere oder erfundene Ergebnisse — deshalb im lokalen Betrieb ein Vision-Modell wählen (z. B. in LM Studio).
² Der Text wird aus dem PDF gezogen und als Text übergeben — das trägt jedes digital erzeugte PDF, unabhängig vom Modell.
³ Ein gescanntes PDF ohne Textebene geht als Seitenbilder an das Modell; dafür muss zusätzlich das Werkzeug `pdftoppm` (Poppler) auf dem Symcon-Rechner installiert sein, sonst wird die Datei abgelehnt.

Verwendete Modelle: Anthropic `claude-sonnet-4-5`, OpenAI `gpt-4o` (PDFs über `gpt-5.6-terra`), lokal das im Formular eingetragene Modell.

> Die **Sprachausgabe** des Briefings und der Einkaufs-Ansage hängt nicht am KI-Anbieter — sie hat ihre eigene Wahl (OpenAI, Azure Speech, ElevenLabs oder Amazon Polly, Kapitel 8).

## 12. Benachrichtigungen

Web-Push erreicht jedes gekoppelte Gerät, auf dem die Web-App als Home-Screen-App installiert ist und Benachrichtigungen erlaubt sind. Angezeigt werden Titel, Text und App-Symbol; Bilder und Antwort-Knöpfe zeigt iOS in Web-Push-Nachrichten nicht an. Ein Tipp auf die Nachricht öffnet die App im passenden Bereich.

Termin-Erinnerungen aus dem Kalender lassen sich zusätzlich über eine Kachel-Visualisierungsinstanz zustellen (Kapitel 6, *Visualisierungs-Benachrichtigungen*).

## 13. Statusvariablen

Das Gateway pflegt **eine** Statusvariable: **Briefing-Text** (`BriefingText`, String) trägt immer den Text des aktuell gezeigten Briefings — tagsüber das heutige, ab der Vorschauzeit das morgige — und eignet sich für eigene Automationen. Sie erscheint mit eingeschaltetem Briefing und verschwindet mit dem Schalter. Variablenprofile werden keine angelegt. Briefing-Audio und Notiz-Anhänge werden als Medienobjekte in eigenen Kategorien unterhalb des Gateways abgelegt, gespeicherte Rezeptdateien unter der Kategorie „Rezeptfotos" unterhalb der SymDoWebApp-Instanz.

## 14. PHP-Befehlsreferenz

### SymDo Gateway (`TGW_`)

```php
// Kopplung
string TGW_CreatePairing(int $InstanzID);      // Kopplungscode für die iOS-App (JSON: code, expiresAt, connectUrl, qrPayload)
string TGW_CreateWebAccess(int $InstanzID);    // Browser-Zugang für die Web-App (JSON: code, expiresAt, url)

// Geräte
string TGW_GetPairedDevices(int $InstanzID);              // alle gekoppelten Geräte (JSON)
void   TGW_RevokeDevice(int $InstanzID, string $DeviceId); // Gerät sperren
void   TGW_RemoveRevokedDevices(int $InstanzID);           // gesperrte Einträge entfernen

// Familienmitglieder
string TGW_GetUsers(int $InstanzID);                                                    // Mitglieder (JSON)
string TGW_CreateAppUser(int $InstanzID, string $Name, string $AvatarBase64);           // Mitglied anlegen
string TGW_UpdateAppUser(int $InstanzID, string $UserID, string $Name, string $AvatarBase64); // Mitglied ändern

// Benachrichtigungen
int TGW_SendPush(int $InstanzID, string $Titel, string $Text, string $UserID = '', string $Tab = '');
// sendet Web-Push an alle Geräte (oder nur an die eines Mitglieds); $Tab öffnet beim Tippen
// den Bereich ('dashboard', 'ki', 'todos', 'shopping', 'calendar', 'notes'); Rückgabe: erreichte Geräte

// Listen in der App ausblenden
string TGW_GetHiddenLists(int $InstanzID);
void   TGW_SetListHidden(int $InstanzID, int $ListenID, bool $Versteckt);
```

Beispiel — eigene Push-Nachricht aus einem Skript:

```php
$anzahl = TGW_SendPush(12345, 'Müll rausbringen', 'Morgen ist Abfuhr — Tonne an die Straße.', '', 'todos');
IPS_LogMessage('SymDo', $anzahl . ' Gerät(e) erreicht');
```

Die weiteren öffentlichen Funktionen (`TGW_Google*`, `TGW_Microsoft*`, `TGW_CalDAV*`) dienen der Listen-Synchronisation und werden von den Listen-Instanzen intern aufgerufen.

### SymDoWebApp (`SDWA_`)

```php
bool SDWA_PlayBriefing(int $InstanzID);  // spielt das aktuelle Briefing in der Kachel ab
```
