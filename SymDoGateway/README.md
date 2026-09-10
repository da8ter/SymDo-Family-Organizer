# SymDo Gateway

**Der Familienorganizer für Symcon — mit einer KI, die den Papierkram übernimmt.**

Einkaufsliste, Aufgaben, Termine und Notizen an einem Ort: SymDo bringt den Familienalltag aufs Handy und auf die Wandvisualisierung — ohne App-Store, ohne Cloud-Zwang, direkt aus dem eigenen Symcon. Jedes Familienmitglied sieht auf einen Blick, was ansteht, wer dran ist und was noch in den Einkaufswagen muss.

Das Beste daran: die KI nimmt euch die Tipparbeit ab.

- **Fotografieren statt abtippen** — Elternbrief oder Terminzettel einfach knipsen: die KI macht daraus fertige Aufgaben, Termine und Notizen. Kurz prüfen, übernehmen, fertig.
- **Vom Rezept zum Einkauf** — Rezeptfoto, PDF oder Link analysieren, Portionen einstellen, fertig: die Zutaten stehen auf der Einkaufsliste. Oder gleich als Favoritenliste mit dem Rezept daran — Lieblingsgerichte landen beim nächsten Mal mit einem Klick im Wagen.
- **E-Mails, die sich selbst eintragen** — die Schulmail und die Erinnerung vom Zahnarzt landen als fertige Vorschläge im KI-Eingang, schon dem richtigen Familienmitglied zugeordnet.
- **Ein Briefing wie vom persönlichen Assistenten** — jeden Morgen fasst die KI zusammen, was heute zählt: Termine, fällige Aufgaben, die Einkaufsliste. Auf Wunsch vorgelesen, im Ton eurer Wahl — vom höflichen Butler bis zum Drillsergeant, der die Familie aus dem Bett scheucht.
- **Freihändig einkaufen** — im Laden sagt die Web-App die Einkaufsliste über die Kopfhörer an: ein Tastendruck hakt den Artikel ab und nennt den nächsten. Das Handy bleibt in der Tasche.
- **Nichts geht mehr unter** — Push aufs Handy, wenn eine Aufgabe fällig wird, das Briefing bereitsteht oder die KI etwas Neues gefunden hat.
- **Reden statt tippen** — der Sprachassistent (Kachel **SymDo - Sprachassistent** oder die Blase in der Web-App) versteht „Setz Milch auf die Liste", „Was hat Tim morgen für Unterricht?" und „Mach das Licht im Bad aus, und in zehn Minuten das im Flur" — und tut nur, was ihr im Gateway freigegeben habt.
- **Die Schule kommt von selbst** — der Stundenplan samt Vertretungen und Entfall aus **WebUntis**, die **Klassenseite** (Edumaps) als Vorschläge und gespiegelte Notizen. Das Briefing sagt morgens, was ausfällt und was dafür läuft.
- **Eure Daten bleiben eure** — die KI läuft mit dem eigenen Schlüssel beim Anbieter eurer Wahl oder komplett lokal im eigenen Netz. Und ohne eure ausdrückliche Einwilligung bleibt sie aus.

Die Oberfläche gibt es doppelt: als Web-App fürs Handy (per QR-Code gekoppelt, als Home-Screen-App installierbar) und als Kachel für die Tile-Visualisierung — beide zeigen denselben Stand. Technisch besteht SymDo aus zwei Instanzen:

| Instanz | Modul | Aufgabe |
|---|---|---|
| **SymDo - Gateway** | Splitter (Präfix `TGW`) | Zentrale: liefert die Web-App aus, verwaltet Kopplung, Geräte und Familienmitglieder, KI, Briefing, Push. Zugleich Sync-Broker (Google Tasks, Microsoft To Do, CalDAV) für die ToDo-Listen |
| **SymDo - Web App** | Device (Präfix `SDWA`) | Kachel für die Tile-Visualisierung mit derselben Oberfläche; steuert, welche Bereiche und Bedienelemente App und Kachel zeigen |
| **SymDo - Sprachassistent** | Device (Präfix `SDVC`) | Sprechstelle des Sprachdialogs als Kachel — eingeschaltet, eingewilligt und begrenzt wird im Gateway ([Anleitung](../SymDoVoice/README.md)) |
| **SymDo - Notizen** | Device (Präfix `SDNO`) | Der Notizbereich als eigene Kachel ([Anleitung](../SymDoNotes/README.md)) |

> Die Einrichtung der Listen-Synchronisation (Google Tasks, Microsoft To Do, CalDAV) ist in der [ToDo-List-Anleitung](../ToDoList/README.md) beschrieben.

## Inhalt

- **1. Funktionsumfang**
- **2. Voraussetzungen**
- **3. Installation**
- **4. Einrichten der Instanzen in Symcon**
- **5. Kopplung: Web-App und iOS-App**
- **6. Konfiguration: SymDo Gateway**
- **7. Konfiguration: SymDo - Web App (Kachel)**
- **8. Tägliches Briefing**
- **9. Einkaufs-Ansage**
- **10. Rezeptanalyse**
- **11. KI-Funktionen und Datenschutz**
- **12. Benachrichtigungen**
- **13. Sprachdialog und Gerätesteuerung**
- **14. Stundenplan aus WebUntis**
- **15. Hausaufgaben**
- **16. Klassenseiten (Edumaps)**
- **17. Statusvariablen**
- **18. PHP-Befehlsreferenz**

## 1. Funktionsumfang

- **Web-App fürs Handy** — vom Gateway ausgeliefert, per QR-Code gekoppelt, zum Home-Bildschirm hinzufügbar; kein App-Store nötig
- **Kachel für die Tile-Visualisierung** — dieselbe Oberfläche als Instanz in der Visualisierung
- **Bereiche**: Übersicht, KI-Eingang, Einkaufen, ToDos, Kalender und Notizen — einzeln abschaltbar
- **Familienmitglieder** mit Name, Rolle und Foto; Aufgaben und Termine lassen sich ihnen zuweisen, die Avatar-Leiste filtert damit alle Bereiche
- **Tägliches Briefing** — die KI fasst morgens Termine, Aufgaben und Einkäufe zusammen, wahlweise mit Sprachausgabe; neun Personas vom Butler bis zum Drillsergeant
- **Rezeptanalyse** — Rezeptfoto, PDF oder Rezept-URL wird zur Zutatenliste: Portionen skalierbar, Zutaten direkt auf die Einkaufsliste oder als Favoritenliste gespeichert, auf Wunsch mit dem Rezept selbst daran
- **Einkaufs-Ansage** — die Web-App liest die offene Einkaufsliste Abteilung für Abteilung vor; bedient wird über die Kopfhörer-Taste, ein Druck hakt ab und sagt den nächsten Artikel an
- **KI-Analyse** — Foto, PDF oder eingefügter Text (z. B. eine WhatsApp-Nachricht) wird zu Aufgaben-, Termin- und Notiz-Vorschlägen; jeder Fund lässt sich zwischen Aufgabe, Termin und Notiz umstimmen und wird beim Übernehmen nacheinander im vorausgefüllten Dialog bestätigt (Ordner, Kalender und Zeiten anpassbar). Auf Wunsch werden auch E-Mails ausgewertet (IMAP-Abruf oder Weiterleitung)
- **Notizen** — Ordner je Familienmitglied und selbst angelegte, Notizen mit Text und Anhängen (Bild, PDF)
- **Kalender** — Termine aus dem Store-Modul [OpenCalendar](https://github.com/Burki24/OpenCalendar) lesen, anlegen und bearbeiten; bei einem Termin aus einer Serie fragt die App vor dem Speichern oder Löschen nach der Reichweite (nur dieser Termin, dieser und alle folgenden, die ganze Serie) — angeboten wird nur, was OpenCalendar an diesem Termin erlaubt
- **Wochenansicht** — im Kalender lässt sich zwischen der Agenda und einer **Familientafel** umschalten: eine Zeile je Familienmitglied, sieben Tagesspalten, Termine ohne Zuordnung in einer Zeile *Familie*. Gedacht für das Tablet in der Küche; die Wahl gilt je Gerät, das Handy kann bei der Agenda bleiben. Ein Plus in der Zelle legt einen Termin mit Tag und Person schon ausgefüllt an
- **Serientermine** — erkennt die KI in einem Elternbrief einen wiederkehrenden Termin („jeden Montag, bis zu den Ferien"), entsteht daraus **eine** Serie im Kalender, kein Stapel Einzeltermine. Kalender, die das Anlegen von Serien nicht unterstützen, bekommen weiterhin die Einzeltermine
- **Jahresereignisse** — Geburtstag, Jahrestag, Hochzeits- und Todestag lassen sich im Termin-Dialog anlegen: ein Schalter, die Art und das Ursprungsdatum, mehr nicht. Beginn, Ende und Wiederholung rechnet der Kalender selbst aus. In der Agenda tragen sie ein eigenes Abzeichen mit der Zahl der Jahre, und das Briefing nennt sie am Tag selbst
- **Web-Push** — Benachrichtigungen aufs gekoppelte Handy bei fälligen Aufgaben, neuem Briefing und neuen KI-Vorschlägen; Termin-Erinnerungen zusätzlich über die Kachel-Visualisierung
- **Geräteverwaltung** — Liste aller gekoppelten Geräte, einzelne Geräte sperrbar
- **Dashboard-Begrüßung** — „Hallo Familie Muster" ohne, „Hallo Tim" mit gewähltem Mitglied; abschaltbar (Kapitel 6)
- **Kindmodus** — wählt man in der Mitglieder-Leiste ein Kind, gehört ihm die Oberfläche: Einkauf und KI-Eingang fallen weg, sein Stundenplan kommt dazu
- **Sprachdialog** — 27 Werkzeuge vom Einkaufszettel bis zur Gerätesteuerung, mit Rückfragen bei allem Heiklen, Tageskontingent und drei getrennten Einwilligungen (Kapitel 13)
- **Gerätesteuerung per Sprache** — Licht, Rollläden, Heizung, Steckdosen, Szenen und Skripte in freigegebenen Bereichen schalten und lesen, dazu einmalige und dauerhafte Zeitpläne als gewöhnliche Symcon-Ereignisse (Kapitel 13)
- **Stundenplan aus WebUntis** — Unterricht, Vertretungen, Entfall, Raum und Lehrer je Kind, für die laufende und die kommende Woche (Kapitel 14)
- **Hausaufgaben** — Fach, Fälligkeit und Notiz je Kind: in der Web-App, an der Stunde in der Stundenplan-Kachel, per Sprache und als vierte Vorschlagsart der KI (Kapitel 15)
- **Klassenseiten (Edumaps)** — die Klassenseite der Schule als Quelle für KI-Vorschläge und, auf Wunsch, als gespiegelte Notizen mit Kartenansicht (Kapitel 15)
- **Essensplan** — die Kachel **SymDo - Essensplan** hängt am Gateway: Gerichte je Tag, Zutaten in den Einkaufswagen, KI-Rezeptbilder; der Sprachdialog liest und plant ihn mit

## 2. Voraussetzungen

- Symcon ab Version **8.1**
- **SymDo - ToDo Liste**- und/oder **SymDo - Einkaufsliste**-Instanzen dieser Bibliothek als Datenquellen
- Für die Web-App unterwegs: **Symcon Connect** (oder eine eigene HTTPS-Adresse)
- Für Web-Push auf dem iPhone: die Web-App muss zum **Home-Bildschirm** hinzugefügt sein (iOS 16.4 oder neuer)
- Für den Kalender-Bereich: das Modul **OpenCalendar** von Burkhard Kneiseler, optional — im **Symcon Module Store** unter „OpenCalendar" zu finden, Quelltext und Dokumentation unter [github.com/Burki24/OpenCalendar](https://github.com/Burki24/OpenCalendar) (Bibliothekskennung `de.burki24.opencalendar`)
- Für die E-Mail-Analyse: eine **E-Mail, Empfangen (IMAP)**-Instanz oder eine Mail-Weiterleitung, optional
- Für die KI-Funktionen: ein eigener API-Schlüssel (**Anthropic** oder **OpenAI**) oder ein **lokaler, OpenAI-kompatibler Server** (z. B. LM Studio)
- Für die Sprachausgabe (Briefing und Einkaufs-Ansage): **OpenAI**, **Microsoft Azure Speech**, **ElevenLabs** oder **Amazon Polly** (eigener Schlüssel; bei ElevenLabs ist ein kostenpflichtiger Zugang nötig, Polly rechnet je Zeichen ab)
- Für den Sprachdialog: ein **OpenAI**-Schlüssel (Realtime-Modelle) und ein Gerät mit Mikrofon in einem sicheren Kontext (HTTPS, Symcon-App oder Connect), optional
- Für den Stundenplan aus WebUntis: ein Eltern- oder Schülerkonto der Schule, optional
- Für die Klassenseiten: die Adresse der Edumaps-Klassenseite, optional

## 3. Installation

1. Bibliothek über das Module Control installieren: `https://github.com/da8ter/SymDo-Family-Organizer.git`
2. Falls noch nicht vorhanden: **SymDo - ToDo Liste**- und **SymDo - Einkaufsliste**-Instanzen anlegen
3. Eine Instanz **SymDo - Gateway** anlegen
4. Eine Instanz **SymDo - Web App** anlegen und in der Kachel-Visualisierung einbinden

## 4. Einrichten der Instanzen in Symcon

1. Im **SymDo - Gateway** unter *Familienmitglieder* die Mitglieder anlegen: Name, Nachname, Rolle (z. B. Vater, Mutter, Kind), Geburtstag und Foto. Rolle und Nachname nutzt auch das Briefing für die Anrede.
2. In der **SymDo - Web App**-Instanz die Listen auswählen, die App und Kachel zeigen sollen, und das Standard-Mitglied setzen. Das Gateway wird automatisch gefunden.
3. Web-App und/oder iOS-App koppeln (Kapitel 5).

Betreibt man mehrere Gateway-Instanzen (etwa für getrennte Synchronisation), bedient nur **eine** davon die App — die weiteren arbeiten als reine Sync-Broker und zeigen im Formular an, welche Instanz die App-Seite trägt.

## 5. Kopplung: Web-App und iOS-App

**Web-App:** Im Gateway unter *Web-App verbinden* auf **Browser-Zugang erstellen** klicken. Es erscheint ein QR-Code — mit der Handy-Kamera scannen, die Web-App öffnet sich im Browser und koppelt sich selbst. Der Code ist **10 Minuten** gültig. Danach: *Teilen → Zum Home-Bildschirm* — beim ersten Start vom Home-Bildschirm fragt die App den Kopplungscode einmal von Hand ab (er steht unter dem QR-Code im Formular).

Ohne Symcon Connect lässt sich unter *Lokale HTTPS-Adresse* (`LocalHttpsUrl`) eine eigene Adresse eintragen, z. B. hinter einem Reverse-Proxy.

**iOS-App:** Unter *iOS App verbinden* auf **Neues Gerät koppeln** klicken und den QR-Code mit der SymDo-App scannen.

**Geräte verwalten:** Unter *Web-App verbinden → Gekoppelte Geräte* stehen alle Geräte mit letzter Aktivität. Ein gesperrtes Gerät verliert den Zugriff sofort und muss neu gekoppelt werden; gesperrte Einträge lassen sich anschließend aufräumen.

Kopplungscodes werden nur als Hash gespeichert und verfallen nach 10 Minuten.

**Die Seite kommt komprimiert.** Bietet der Browser gzip an — jeder tut das —,
geht die Web-App gepackt über die Leitung: aus 1.037.525 werden 336.221 Bytes.
Über Symcon Connect gemessen dauert der Kaltstart damit 1,6 statt 4,2 Sekunden.
Bietet ein Client keine Kompression an, bekommt er die Seite unverändert; beide
Fassungen tragen eine eigene Kennung, damit kein Zwischenspeicher sie
verwechselt. Nebenwirkung, die wichtiger ist als die Geschwindigkeit: die
Ausgabe bleibt weit unter der Grenze, die Symcon für Skriptausgaben setzt
(`ScriptOutputBufferLimit`, ab Werk 1.048.576 Bytes) — ungepackt lag die Seite
nur elf Kilobyte darunter, und ein Überschreiten ersetzt die Antwort still.

## 6. Konfiguration: SymDo Gateway

### Familienmitglieder (`Users`)

Liste der Mitglieder mit Name, Nachname, Rolle, Geburtstag und Foto. Jedes Mitglied bekommt automatisch einen eigenen Notiz-Ordner mit seinem Foto. Die Rolle **Kind** wirkt an mehreren Stellen: im Kindmodus der App, im Stundenplan (nur Kinder bekommen einen) und im Sprachdialog (Kinder schalten keine Geräte der Rückfrage-Liste).

Darüber stehen **Begrüßung auf dem Dashboard anzeigen** (`GreetingEnabled`) und **Familienname für die Dashboard-Begrüßung** (`FamilyName`): ohne gewähltes Mitglied grüßt die Übersicht mit „Hallo Familie <Name>", mit gewähltem Mitglied mit dessen Vornamen.

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

## 7. Konfiguration: SymDo - Web App (Kachel)

- **Standard-Mitglied** (`DefaultUserID`) — Vorbelegung für schnell angelegte Aufgaben, KI-Übernahmen und E-Mail-Vorschläge ohne eigenen Empfänger
- **Listen** (`Lists`) — welche ToDo- und Einkaufslisten App und Kachel zeigen
- **Sichtbare Bereiche** — Übersicht (`ShowDashboard`), Einkaufen (`ShowShopping`), ToDos (`ShowTodos`), Kalender (`ShowCalendar`), Notizen (`ShowNotes`), KI-Eingang (`ShowKi`), dazu die Wahl der Stundenplan-Instanzen (`TimetableChoice`). Der Kalender-Bereich braucht zusätzlich mindestens einen OpenCalendar-Kalender. Sind alle Bereiche aus, bleibt die Übersicht stehen; bei nur einem Bereich verschwindet die Tab-Leiste
- **Bedienelemente** — Mitglieder-Leiste, Anlegen-Knopf, Sortierung, Favoriten-Herz, Zeilen-Knöpfe, Verschiebe-Griff (`ShowMemberBar`, `ShowCreateButton`, `ShowSorting`, `ShowFavoriteHeart`, `ShowRowEditButton`, `ShowRowDeleteButton`, `ShowReorderHandle`)
- **Info-Abzeichen** — Menge, Wiederholung, Fälligkeit, Erinnerung, Priorität (`ShowQuantityBadge`, `ShowRecurrenceBadge`, `ShowDueBadge`, `ShowNotificationBadge`, `ShowPriorityBadge`)

Diese Schalter gelten für **alle** Listen dieser Web-App und dieser Kachel gemeinsam; die gleichnamigen Schalter in den einzelnen Listen-Instanzen gelten weiterhin für deren eigene Kacheln. Wischgesten bleiben immer aktiv — ein ausgeblendeter Knopf entfernt nie die Funktion; einzige Ausnahme ist der Verschiebe-Griff. Die Web-App übernimmt Änderungen nach dem Neuladen, die Kachel sofort.

## 8. Tägliches Briefing

Die KI schreibt jeden Morgen zur eingestellten Uhrzeit (Standard 5:30 Uhr) eine kurze Zusammenfassung: Termine des Tages, fällige und überfällige Aufgaben, Stand der Einkaufsliste. Das Briefing erscheint auf der Übersicht der App und der Kachel — auf Wunsch mit Sprachausgabe zum Abspielen.

**Geburtstage und Jahrestage** kommen aus **zwei** Quellen: den Stammdaten der Familienmitglieder (Kapitel 5) und den Jahresereignissen aus **OpenCalendar** — Geburtstag, Jahrestag, Hochzeitstag, Todestag, gepflegt im Kalender-Programm oder direkt im Termin-Dialog der App. Steht derselbe Name in beiden, gewinnt die Stammdaten-Zeile; es wird nicht zweimal gratuliert. Ein Todestag wird ausdrücklich als solcher genannt, damit die KI dort nicht gratuliert.

- **Geschrieben für** — ein einzelnes Mitglied (persönliche Anrede) oder *— die ganze Familie —* (Haushalts-Briefing: alle werden gemeinsam angesprochen, jede Aufgabe mit dem Namen des Zuständigen). Lebt nur ein Mitglied im Haushalt, spricht auch das Familien-Briefing es automatisch persönlich an
- **Persona** — Tonfall des Texts **und** der Stimme: Sachlich, Förmlich, Butler, Lustig, Drillsergeant, Motivationstrainer, Jammerlappen, Digga. Die Persona *Förmlich* siezt Erwachsene mit Nachnamen („Herr Muster"), Kinder bleiben beim Vornamen und Du
- **Länge** — *Ausführlich* schreibt wie bisher einen Fließtext über den Tag.
  *Kompakt* hält eine feste Reihenfolge ein: kurze Begrüßung, dann Termine und
  Aufgaben in je einem knappen Hauptsatz, danach wie lange welches Kind Schule
  hat, zum Schluss die Anzahl der Artikel auf der Einkaufsliste. Die Schulzeiten
  stehen in **beiden** Fassungen; sie kommen aus dem Stundenplan-Modul — ohne es
  entfällt dieser Teil. **In den Ferien und an Feiertagen** nennt das Briefing nur
  die Ferienlage („Keine Schule, Sommerferien bis 01.09.") und keine
  Unterrichtszeiten — der Stundenplan gilt dann zwar weiter, interessiert aber
  niemanden. Der Tonfall
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

## 13. Sprachdialog und Gerätesteuerung

Der Sprachdialog ist ein Gespräch mit der KI in Echtzeit (OpenAI Realtime über WebRTC): Die Kachel **SymDo - Sprachassistent** oder die Blase in der Web-App nimmt den Ton auf, das Modell antwortet mit Stimme und ruft für alles, was es tun soll, **Werkzeuge** dieses Gateways auf. Es kann ausschließlich, was diese Werkzeuge hergeben; die Liste steht in der [Anleitung der Sprach-Kachel](../SymDoVoice/README.md).

### Einschalten und begrenzen

| Einstellung | Eigenschaft | Bedeutung |
|---|---|---|
| Sprachdialog aktivieren | `VoiceEnabled` | Hauptschalter; nutzt den OpenAI-Schlüssel der KI-Funktionen |
| Modell, Stimme | `VoiceModel`, `VoiceVoice` | `gpt-realtime-mini` (Standard) oder `gpt-realtime`; Stimme des Anbieters |
| Sprechzeit pro Tag | `VoiceDailyMinutes` | Kontingent für den ganzen Haushalt in Minuten (Standard 15, 0 = unbegrenzt) |
| Höchstdauer je Gespräch | `VoiceMaxSessionSeconds` | danach legt die Kachel auf (Standard 180 s) |
| Leser für Handbuchfragen | `VoiceDocModel` | ein Textmodell (Standard `gpt-4.1`) liest die Fundstellen aus dem Symcon-Handbuch und formuliert die Antwort; ohne Modell wird der Auszug vorgelesen |
| Freihändig mit Weckwort | `VoiceHandsFreeAllowed` | Experiment: das Weckwort startet das Gespräch; Erkennung auf dem Gerät, eigene Einwilligung nötig. Ist es erlaubt, lauschen Sprach-Kachel und Web-App von selbst — ohne Knopf; sichtbar bleibt das Lauschzeichen unten links |
| Weckwort | `VoiceWakeWord` | Standard „Hey SymDo". Frei wählbar, mindestens sechs Buchstaben, mehrere durch Komma („Hey SymDo, Hallo Haus"). Zwei bis drei Silben mit klarem Anfang treffen am besten; „Hey SymDo" behält sein eigens auf Fehlhörer geeichtes Muster, eigene Wörter werden über Buchstabennähe erkannt. Was direkt nach dem Weckwort gesagt wird, schreibt der Erkenner mit und reicht es als Text nach, sobald die Verbindung steht |

**Drei Einwilligungen**, mit Absicht getrennt und einzeln widerrufbar: für den Dialog selbst (der Raumton geht während des Gesprächs zum Anbieter), fürs Dauerlauschen mit Weckwort und für die Gerätesteuerung. Ein Widerruf beendet laufende Gespräche sofort. Die **Testverbindung** prägt einen Zugang für zehn Sekunden und prüft Schlüssel und Modellfreigabe, ohne Sprechzeit zu bezahlen; **Alle Sitzungen beenden** legt überall auf. Die Statuszeile nennt die heutige Sprechzeit und die offenen Gespräche.

### Gerätesteuerung

| Einstellung | Eigenschaft | Bedeutung |
|---|---|---|
| Gerätesteuerung per Sprache erlauben | `VoiceDevicesEnabled` | Schalter; ohne ihn kennt der Assistent kein Gerät und sagt das auch |
| Freigegebene Bereiche | `VoiceDeviceRoots` | **Wurzel-Kategorien**: alles darunter, das sichtbar ist und eine Aktion hat, ist steuerbar. Jede Kategorie darunter gilt als Raum, verschachtelte Kategorien als Etage und Raum („Obergeschoss › Bad"), Links werden verfolgt, Szenensteuerungen erkannt |
| Nur nach gesprochener Rückfrage | `VoiceDeviceConfirm` | Schloss, Alarmanlage, Garage: erst nach einem klaren Ja, **von Kindern nie**. Die Objekte müssen unter einer Wurzel liegen |

Dazu die eigene Einwilligung: **Jeder**, der mit der Kachel spricht — Gäste und Kinder eingeschlossen — kann danach die freigegebenen Geräte schalten und lesen. Deshalb bewusst eng freigeben und Heikles auf die Rückfrage-Liste. Es gilt ein Deckel von **60 Schaltungen je Stunde**; jede Schaltung steht mit Gerät, Wert und Person im **Meldungsfenster**. **Katalog anzeigen** listet, was der Assistent unter den Wurzeln gefunden hat, mit Raum, Namen und Rückfrage-Vermerk.

**Was der Assistent versteht:** Schalter (an, aus, umschalten, auch die Beschriftungen der Darstellung: „verriegeln", „scharf", „öffnen"), Regler (Zahlen mit Einheit, „voll", „halb"; außerhalb des Bereichs wird abgelehnt statt geklemmt, Werte rasten auf die Schrittweite), Auswahl-Darstellungen über ihre Beschriftungen („Stufe 2", „Eco"), Rollläden (hoch, runter, Prozent), Text, **Farbe** (Darstellung „Farbe" oder Profil `~HexColor`: Farbnamen wie rot, türkis, warmweiß, oder ein Hex-Wert), **Farbtemperatur** (Schieberegler mit Verwendungsart „Farbtemperatur", Suffix K oder Profil `~TWColor`: warmweiß, neutralweiß, kaltweiß, wärmer, kälter), bei Dimmern heller und dunkler. Wiedergabe liest er nur. Skripte startet er, Szenen der Szenensteuerung ruft er auf.

**Wie er das Gerät findet:** Der Server baut bei jedem Aufruf den Katalog aus dem Objektbaum. Der gesprochene Name entsteht aus dem Baum — Link-Name, sonst Variablenname; heißt die Variable nur „Status" oder „Wert", ist die Instanz das Gerät, und liegt die Instanz selbst in einer Instanz (Dummy „Lampenschirm" mit Modbus-Kindern „Schalter" und „Dimmwert"), wird deren Name Teil des Namens. Ein strenger Abgleich mit Synonymen (Licht, Lampe, Leuchte; Bad, Badezimmer; oben, Obergeschoss) trifft die klaren Fälle sofort. Bei mehreren oder keinem Treffer bekommt das Modell **Kandidaten** mit vollem Pfad, Typ und möglichen Werten und wählt — aber nur aus dem Katalog, nichts Erfundenes. Bei zwei gleichnamigen Geräten fragt der Assistent mit dem Weg nach („Bad Deckenlampe im Obergeschoss oder im Erdgeschoss?"). Haushalte bis 120 Geräte bekommen den ganzen Katalog in den Systemprompt und treffen ohne Suchlauf.

> **Tipp zur Benennung:** Der Assistent findet Geräte so gut, wie der Objektbaum sie benennt. Kategorien als Räume, sprechende Instanz- oder Link-Namen und Darstellungen mit Beschriftungen („Offen"/„Verriegelt" statt an/aus) machen den Unterschied. Drei Lampen, die alle „Licht" heißen, ergeben immer eine Rückfrage.

### Licht: Farbe, Farbtemperatur, Helligkeit

Symcon kennt bei Licht drei Dinge, und der Assistent bedient alle drei:

| Was | Wie Symcon es abbildet | Was der Assistent versteht |
|---|---|---|
| **Farbe** | Darstellung **Farbe** an einer Integer-Variable — der Wert ist die Farbe als Zahl (`0xRRGGBB`); bei Integer ist RGB die einzige Kodierung, andere Farbräume (HSV, CMYK, xy) gibt es nur an Text-Variablen. Das alte Profil `~HexColor` ist gleichwertig | 36 Farbnamen von rot bis lavendel, dazu warmweiß und kaltweiß als Farbton, Hex-Werte („#00ff88"), Tippfehler wie „türkiss". Gelesen wird der nächste Farbname („Türkis"), nicht die Zahl — Symcons `GetValueFormatted` liefert für die Darstellung „Farbe" keinen Text |
| **Farbtemperatur** | kein eigener Typ, sondern ein **Schieberegler in Kelvin** mit Verwendungsart „Farbtemperatur" oder Farbverlauf „Farbtemperatur"; alt das Profil `~TWColor` (1000–12000 K). Erkannt wird auch ein Regler mit Suffix „K" | warmweiß (2700 K), neutralweiß (4000 K), kaltweiß (6500 K), „wärmer" und „kälter" in 500-K-Schritten. Relative Worte werden am Anschlag geklemmt, eine ausdrückliche Zahl außerhalb („1000 Kelvin") weiter abgelehnt. Gelesen: „3200 K (Warmweiß)" |
| **Helligkeit** | Schieberegler in Prozent | Zahlen, „voll", „halb", „heller" und „dunkler" in Zehntelschritten des Bereichs |

**Ein Gerät, vier Variablen:** Eine Lampe ist in Symcon oft eine Instanz mit Schalter, Helligkeit, Farbe und Farbtemperatur darunter — oder ein Dummy mit vier Unterinstanzen. Der Assistent findet sie alle unter dem Gerätenamen, und **das Wort entscheidet**, welche gemeint ist: „LED-Streifen an" schaltet den Schalter, „LED-Streifen 40 Prozent" die Helligkeit, „LED-Streifen rot" die Farbe, „LED-Streifen warmweiß" die Farbtemperatur (nicht die Farbe). „An" an einer reinen Farbvariable setzt nichts, sondern fragt nach einer Farbe. Zeitpläne für Farben nutzen Symcons eigene Farbaktion („Nachtlicht Farbe um 19 Uhr auf rosa").

**Nicht abgedeckt:** Farbvariablen vom Typ Text (HSV, CMYK, xy) meldet der Assistent als nicht unterstützt. Eine Farbtemperatur in **Mired** statt Kelvin erkennt er nur, wenn die Darstellung die Verwendungsart „Farbtemperatur" trägt. Geprüft wurde an Variablen, die den Wert nur zurückschreiben — wie das jeweilige Zielmodul (Hue, Zigbee, …) einen Farbwert annimmt, entscheidet dessen Aktion.

### Zeitpläne

„Schalte in 55 Minuten das Wasser aus", „jeden Tag um 11 Uhr die Lampe an", „werktags um 6:30 den Rollladen hoch": Der Assistent legt dafür ein ganz gewöhnliches **zyklisches Symcon-Ereignis am Zielobjekt** an, ausgeblendet, mit der eingebauten Aktion „Auf Wert schalten" bzw. „Automation ausführen". Es schaltet direkt, ohne Umweg über das Gateway, steht in der Konsole am Gerät, lässt sich dort pausieren oder löschen und überlebt Neustarts. Der Sprachdialog erkennt seine Ereignisse an einer Kennung im Info-Feld; damit listet („Was ist geplant?") und löscht er sie auf Zuruf. Einmal angelegt, gehören sie dem Haus: Ein Widerruf der Einwilligung lässt sie stehen.

Höchstens 20 Pläne, relativ höchstens sieben Tage voraus, absolut höchstens ein Jahr. Gefeuerte Einmal-Aufträge bleiben 15 Minuten als „erledigt um 12:24" sichtbar, dann räumt die Liste sie weg. Kinder legen nur Einmaliges an und löschen nur Eigenes. Geräte der Rückfrage-Liste verlangen auch für einen Zeitplan das gesprochene Ja. Die Formularzeile nennt die Zahl der offenen Pläne, **Zeitpläne anzeigen** listet sie.

## 14. Stundenplan aus WebUntis

> Im Formular stehen WebUntis und die Klassenseiten zusammen unter
> **Schule** — *WebUntis (Stundenplan, Vertretungen und Hausaufgaben)* und
> *Klassenseiten (Edumaps)*.

Der Stundenplan im Modul **SymDo - Stundenplan** ist eine Wochenvorlage. Was dort nie ankommt — Vertretung, Entfall, Raumwechsel — steht in **WebUntis**, das die Schule ohnehin führt. Das Gateway holt es dort über die offiziell vorgesehene JSON-RPC-Schnittstelle und spielt es datiert in die Stundenplan-Instanz ein, für die laufende und die kommende Woche.

| Einstellung | Eigenschaft | Bedeutung |
|---|---|---|
| WebUntis aktivieren | `UntisEnabled` | Schalter |
| Server, Schule | `UntisServer`, `UntisSchool` | wie in der WebUntis-Adresse der Schule (z. B. `mese.webuntis.com`, Schulkürzel) |
| Benutzer, Passwort | `UntisUser`, `UntisPassword` | ein Eltern- oder Schülerkonto; das Passwort bleibt in der Instanz |
| Abrufintervall | `UntisIntervalMinutes` | wie oft nachgesehen wird |
| Kinder | `UntisStudents` | je Kind eine Zeile: Stundenplan-Instanz, Familienmitglied, **WebUntis Name** und Kurse. Der Name steht fast immer auf *— automatisch —*: bei einem Schülerkonto ist das Konto selbst das Kind, bei einem Elternkonto nimmt der Abruf das Kind, dessen Name zum Familienmitglied passt |
| Push bei Änderungen | `UntisPush` | meldet neue Vertretungen und Entfälle aufs Handy |
| Hausaufgaben mitholen | `UntisHomework` | übernimmt die Hausaufgaben, die die Schule eingetragen hat (Kapitel 15) |

**Schüler abrufen** (Knopf über der Liste) holt die Kinder, die am
angemeldeten Konto hängen (`app/data`), und trägt sie als Auswahl in die Spalte
*WebUntis Name* ein — eine Anmeldung je Druck. Nötig ist das nur, wenn die
automatische Zuordnung nicht eindeutig ist: Elternkonto, mehrere Kinder, und
keiner der WebUntis-Namen passt zum Familienmitglied.

> Bis zum 10.09.2026 stand hier eine **Schüler-Suche** über `getStudents`.
> Sie ist entfernt: setzt die Schule die Rechte weit, liefert dieser Aufruf die
> Schülerliste der GANZEN Schule — mehr, als dieses Modul braucht, und mehr,
> als in einem Formularfeld stehen sollte. Der Kontoabruf sieht nur die eigenen
> Kinder. Element-Typ und -Nummer sind damit aus dem Formular verschwunden;
> vorhandene Zeilen (etwa ein Klassenplan) laufen unverändert weiter, weil die
> alten Felder weiter gelesen werden.

**Testverbindung** meldet das Schuljahr und nennt die Kinder des Kontos. Das Kind muss im Stundenplan-Modul als Familienmitglied verknüpft sein, sonst weiß niemand, wohin die Stunden gehören. Nach wiederholt fehlgeschlagener Anmeldung pausiert der Abruf, damit das Konto nicht gesperrt wird; die Testverbindung startet ihn wieder.

Was daraus wird: Der Stundenplan zeigt **datierte Tage** mit Datum im Spaltenkopf und blättert durch beide Wochen; entfallene Stunden erscheinen als gestrichelte Kapsel, Vertretungen mit Kante; freie Tage nennen den Grund. Das **Briefing** nennt, was ausfällt und was dafür läuft. Der **Sprachdialog** beantwortet „Was hat Tim am Dienstag?" und „Fällt bei Mia etwas aus?". Eigene Zulieferer können denselben Weg nutzen: `STPL_ImportSlots()` im Stundenplan-Modul.

## 15. Hausaufgaben

Fach, bis wann, was zu tun ist — je Eintrag genau ein Kind. Bewusst **keine
Aufgabenliste**: eine Aufgabe hat kein Fach, und in einer Liste stünden
Hausaufgaben zwischen „Zimmer aufräumen". Der Bestand liegt im Gateway, wie die
Notizen.

**Wo sie erscheinen**

| Ort | Was man sieht |
|---|---|
| Web-App, Stundenplan-Bereich | Alle offenen des gezeigten Kindes, gruppiert nach überfällig, heute, morgen, später und ohne Datum. Ein Plus legt eine an, ein Tipp auf die Zeile ändert sie, der Kreis links hakt sie ab. Abgehaktes wandert nach unten in einen eingeklappten Abschnitt **Erledigt** und lässt sich dort zurücknehmen |
| Web-App, Übersicht | Eine Karte für die Eltern: was heute, morgen oder überfällig ist, mit Kind und Fach. Der Stundenplan-Bereich gehört dem Kindmodus, die Übersicht allen |
| Kachel **SymDo - Hausaufgaben** | Dieselbe Liste als eigene Kachel, im Aufbau der Aufgabenliste: Avatar-Leiste, drei Zahlen (offen, überfällig, heute fällig), die Liste, eine Eingabezeile. Für ein Tablett, das nur diese eine Frage beantworten soll |
| Stundenplan-Kachel | An der Stunde ein Abzeichen mit der Zahl (Wochenraster) bzw. ein Punkt am Balken (Zeitachse). Was zu keiner Stunde des Tages passt, steht als Fußzeile unter der Spalte |
| Sprachassistent | „Was hat Tim für morgen auf?", „Tim hat in Mathe Seite 42 bis Donnerstag auf", „Hak bei Mia Deutsch ab" |
| KI-Auswertung | Ein Foto des Hausaufgabenhefts und die Klassenseite liefern Hausaufgaben als vierte Vorschlagsart neben Aufgabe, Termin und Notiz |
| Push | Abends eine Meldung, wenn für morgen noch etwas offen ist (Kapitel 12) |

**Das Fach** kommt aus dem Fachkatalog des Stundenplans, mit Symbol und Farbe.
Gesagt oder geschrieben werden darf die Kurzform: „Mathe" wird zu
„Mathematik". Ein Fach ohne Stunde (AG, Ersatzfach) ist zulässig — es hat
trotzdem Hausaufgaben. Wird ein Fach umbenannt, verlieren alte Einträge Symbol
und Zuordnung; der Name ist die Verbindung, hier wie im ganzen Stundenplan.

**Aufbewahrung**: erledigte verschwinden nach 14 Tagen, offene ohne Erledigung
nach 60. Höchstens 300 Einträge; darüber fallen die ältesten heraus. Gemessen
wird beim Lesen, geschrieben erst bei der nächsten Änderung.

**Von der Schule holen** (Schalter *Hausaufgaben mitholen* unter *Schule →
WebUntis*,
ab Werk **aus**): Was die Lehrkräfte in WebUntis eintragen, kommt mit Fach,
Fälligkeit, Text und Häkchen herein — niemand muss es abtippen. Der Abruf
läuft im selben Durchlauf wie der Stundenplan und in derselben Anmeldung, also
ohne zusätzlichen Zugriff auf das Schulkonto. Vier Regeln, damit sich beides
nicht in die Quere kommt:

- **Von Hand angelegtes bleibt unangetastet.** Der Abruf ändert und löscht nur,
  was er selbst hereingeholt hat. Eigene Einträge sind an keiner Stelle in
  Gefahr, und **anlegen geht weiter wie bisher** — in der Web-App, per Sprache,
  über die KI.
- **Das Häkchen ist eine Sperrklinke.** Erledigt aus WebUntis setzt erledigt;
  ein zu Hause gesetztes Häkchen nimmt der Abruf nie zurück. Sonst stünde eine
  abgehakte Aufgabe eine Stunde später wieder offen da, nur weil die Lehrkraft
  es in WebUntis nicht nachgetragen hat.
- **Das Häkchen bleibt hier.** Es wird **nicht** nach WebUntis zurückgemeldet:
  ein Elternkonto darf Hausaufgaben dort nur lesen. Wer den Haken auch in
  WebUntis will, setzt ihn in WebUntis.
- **Zurückgezogen wird nur im abgerufenen Zeitraum.** Nennt die Schule eine
  übernommene Aufgabe nicht mehr, verschwindet sie auch in SymDo. Eine Aufgabe
  vor dem Zeitraum stand nie in der Antwort und bleibt deshalb stehen.

In der Web-App tragen übernommene Aufgaben ein kleines Schulzeichen, und ein
Tipp darauf öffnet ein **Anzeigeblatt statt des Editors**: Fach, Fälligkeit und
die Notiz in ganzer Länge, darunter *Quelle: UNTIS*. Bearbeiten geht dort nicht,
und das ist kein Riegel aus Vorsicht — eine Änderung hielte nicht, weil der
nächste Abruf wieder die Fassung der Schule schreibt. Ein Feld, dessen Inhalt
stillschweigend zurückfällt, wäre schlimmer als eines, das es nicht gibt. Wer
etwas anders braucht, legt eine eigene Aufgabe an; die zeigt *Quelle: SymDo* und
bleibt vollständig bearbeitbar.

**Wer abgehakt hat, steht in der Farbe des Häkchens**: orange, wenn es hier
gesetzt wurde, in der Akzentfarbe, wenn es aus WebUntis kam. Damit ist ohne
Nachfragen zu sehen, ob das Kind fertig ist oder die Lehrkraft die Aufgabe
abgeschlossen hat.

Die Statuszeile im Formular nennt nach jedem Durchlauf, wie viele Aufgaben
kamen, wie viele neu und wie viele zurückgezogen waren.

**Grenzen**: Hausaufgaben hängen an Mitgliedern mit der Rolle *Kind* und
brauchen deren Kennung. Sie erscheinen **nicht** in der Geräte-Erkundung der
App — dieselbe Rücksicht wie bei den Notizen, deren Listenart die
ausgelieferte iOS-App nicht kennt.

## 16. Klassenseiten (Edumaps)

> Im Formular unter **Schule → Klassenseiten (Edumaps)**, dort in vier
> Gruppen: *1. Seiten* (welche, wie oft, Verweise), *2. In SymDo zeigen* (der
> Spiegel für Bereich und Kachel), *3. KI-Vorschläge* (Auswertung, Push) und
> *4. Stand und Wartung* (Statuszeile, Sperren, Eingriffe).
>
> **Ein Wort je Sache:** die **Klassenseite** ist die Seite (bei Edumaps „Map"),
> die **Karte** ist ein Kasten darauf. Bis zum 10.09.2026 nannte die deutsche
> Oberfläche beides „Karte" — daher stand dort etwa „verlinkte Karten", wo
> verlinkte *Seiten* gemeint waren.

Die Klassenseite der Schule trägt, was im Haushalt sonst abgetippt wird: Termine, Elternbriefe mit Fristen, Materiallisten, den Stundenplan als PDF. Das Gateway liest die Seite regelmäßig, erkennt **geänderte Karten** und schickt jede einzeln durch dieselbe Kette wie eine Schulmail — KI-Analyse, dann ein Vorschlag im KI-Eingang, den jemand prüft und übernimmt. Nichts entsteht ungefragt.

| Einstellung | Eigenschaft | Bedeutung |
|---|---|---|
| Klassenseiten aktivieren | `EduEnabled` | Schalter |
| Klassenseiten | `EduPages` | je Seite die Adresse und das Kind, dem die Vorschläge gehören. **Die Adresse enthält den Zugang und wirkt wie ein Kennwort** — sie steht nur in dieser Instanz |
| Prüfintervall | `EduIntervalHours` | wie oft nachgesehen wird |
| Karten spiegeln | `EduToNotes` | jede Karte 1:1 in den eigenen Bestand, ein Ordner je Seite, Anhänge und QR-Codes als Verweise mit dabei; entfernte Karten wandern ins **Archiv** statt zu verschwinden |
| Verlinkten Seiten folgen | `EduFollowLinks` | Seiten, auf die eine eingetragene Seite verweist, kommen mit — gespiegelt **und** ausgewertet wie die eingetragenen. Die Kette bleibt eine Ebene tief; sie gehören dem Kind der Herkunftsseite |
| Push bei Neuem | `EduPush` | meldet neue Vorschläge aufs Handy |

**Wo die Karten liegen (seit 10.09.2026 anders).** Sie haben einen eigenen
Bestand (`EduStore`) und einen eigenen Endpunkt (`/edumaps`), der denselben
Draht-Vertrag spricht wie die Notizen. Vorher lagen sie als Notizen mit im
Notizen-Bestand — zwei Ordnerebenen, 55 von 64 Notizen und neun Felder, die nur
der Spiegel schrieb. Der Notizbereich zeigte damit fast nur Fremdes, und die
Notizen trugen Code, der nur für Klassenseiten da war.

Sichtbar sind die Karten jetzt an drei Stellen: im eigenen Bereich
**Klassenseiten** der Web-App (Schalter *Klassenseiten zeigen* an der Web-App-
Instanz, zusätzlich gekoppelt an diese Konfiguration hier), in der neuen Kachel
**SymDo - Klassenseiten (Edumaps)**, und als Kartenansicht mit den Farben der Seite,
aufklappbaren Bereichen, PDF-Vorschau und der Buchungslage buchbarer Karten. Die
Ordnung ist zwei Ebenen tief: je Kind ein Ordner, darin seine Seiten.

**Der Umzug lief einmal, von selbst.** Beim ersten Übernehmen nach dem Update
zogen Ordner, Karten und die Sperrliste in den eigenen Bestand; die
Medienobjekte der Anhänge blieben, wo sie sind, es zogen nur die Datensätze. Was
ein Mensch von Hand in einen Klassenseiten-Ordner geschrieben hatte, blieb eine
Notiz und hängt seither eine Ebene höher. Geschrieben wurde in dieser
Reihenfolge: erst der neue Bestand samt Gegenprobe, dann der Abtrag in den
Notizen — scheitert etwas dazwischen, sind die Notizen unangetastet, und der
nächste Durchlauf holt nach. Gemerkt ist der Umzug im Bestand selbst, nicht in
einem eigenen Schalter.

Eine **Sperrliste** hält einzelne Karten oder ganze Seiten von der Auswertung
fern, mit Einzelfreigabe; **Alle Karten auswerten** stößt eine vollständige
Analyse an. Die Vorschläge zählen auf das KI-Tageslimit — ist es erreicht, folgt
der Rest am nächsten Tag.

**Was im Bereich möglich ist:** eine Seite umbenennen (wiedererkannt wird sie an
ihrer Adresse, nicht am Namen), eine Seite löschen und damit sperren, und eine
archivierte Karte endgültig wegwerfen. Anlegen und Schreiben gibt es nicht — was
hier von Hand entstünde, wäre beim nächsten Durchlauf entweder weg oder
archiviert.

## 17. Statusvariablen

Das Gateway pflegt **eine** Statusvariable: **Briefing-Text** (`BriefingText`, String) trägt immer den Text des aktuell gezeigten Briefings — tagsüber das heutige, ab der Vorschauzeit das morgige — und eignet sich für eigene Automationen. Sie erscheint mit eingeschaltetem Briefing und verschwindet mit dem Schalter. Variablenprofile werden keine angelegt. Briefing-Audio, Notiz-Anhänge und gespeicherte Rezeptdateien werden als Medienobjekte in eigenen Kategorien unterhalb des Gateways abgelegt. Zeitpläne des Sprachdialogs sind ausgeblendete Ereignisse **an den Geräten selbst**, nicht unter dem Gateway (Kapitel 13).

## 18. PHP-Befehlsreferenz

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
TGW_GetHomework(int $InstanzID, string $KindID): string  // Hausaufgaben als JSON (leer = alle Kinder)
TGW_HomeworkRefreshTiles(int $InstanzID): void           // Stundenplan-Kacheln neu zeichnen lassen
```

Beispiel — eigene Push-Nachricht aus einem Skript:

```php
$anzahl = TGW_SendPush(12345, 'Müll rausbringen', 'Morgen ist Abfuhr — Tonne an die Straße.', '', 'todos');
IPS_LogMessage('SymDo', $anzahl . ' Gerät(e) erreicht');
```

Die weiteren öffentlichen Funktionen (`TGW_Google*`, `TGW_Microsoft*`, `TGW_CalDAV*`) dienen der Listen-Synchronisation und werden von den Listen-Instanzen intern aufgerufen.

### SymDo - Web App (`SDWA_`)

```php
bool SDWA_PlayBriefing(int $InstanzID);  // spielt das aktuelle Briefing in der Kachel ab
```
