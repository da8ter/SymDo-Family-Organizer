# SymDo

SymDo macht aus den Listen dieser Bibliothek eine **App für die ganze Familie**: eine Web-App fürs Handy (als Home-Screen-App installierbar) und eine Kachel für die Tile-Visualisierung — beide zeigen dieselbe Oberfläche. Dazu kommen Familienmitglieder mit Foto, ein tägliches Briefing mit Sprachausgabe, KI-Funktionen (Foto, PDF und E-Mail werden zu Aufgaben, Terminen und Notizen) und Push-Benachrichtigungen aufs Handy.

SymDo besteht aus zwei Instanzen:

| Instanz | Modul | Aufgabe |
|---|---|---|
| **SymDo Gateway** | Splitter (Präfix `TGW`) | Zentrale: liefert die Web-App aus, verwaltet Kopplung, Geräte und Familienmitglieder, KI, Briefing, Push. Zugleich Sync-Broker (Google Tasks, Microsoft To Do, CalDAV) für die ToDo-Listen |
| **SymDoWebApp** | Device (Präfix `SDWA`) | Kachel für die Tile-Visualisierung mit derselben Oberfläche; steuert, welche Bereiche und Bedienelemente App und Kachel zeigen |

> Die Einrichtung der Listen-Synchronisation (Google Tasks, Microsoft To Do, CalDAV) ist in der [ToDo-List-Anleitung](../ToDoList/README.md) beschrieben.

## Inhalt

- **1. Funktionsumfang**
- **2. Voraussetzungen**
- **3. Installation**
- **4. Einrichten der Instanzen in IP-Symcon**
- **5. Kopplung: Web-App und iOS-App**
- **6. Konfiguration: SymDo Gateway**
- **7. Konfiguration: SymDoWebApp (Kachel)**
- **8. Tägliches Briefing**
- **9. KI-Funktionen und Datenschutz**
- **10. Benachrichtigungen**
- **11. Statusvariablen**
- **12. PHP-Befehlsreferenz**

## 1. Funktionsumfang

- **Web-App fürs Handy** — vom Gateway ausgeliefert, per QR-Code gekoppelt, zum Home-Bildschirm hinzufügbar; kein App-Store nötig
- **Kachel für die Tile-Visualisierung** — dieselbe Oberfläche als Instanz in der Visualisierung
- **Bereiche**: Übersicht, KI-Eingang, Einkaufen, ToDos, Kalender und Notizen — einzeln abschaltbar
- **Familienmitglieder** mit Name, Rolle und Foto; Aufgaben lassen sich Mitgliedern zuweisen („Meine Aufgaben")
- **Tägliches Briefing** — die KI fasst morgens Termine, Aufgaben und Einkäufe zusammen, wahlweise mit Sprachausgabe; neun Personas vom Butler bis zum Drillsergeant
- **KI-Analyse** — Foto oder PDF wird zu Aufgaben-, Termin- und Notiz-Vorschlägen; auf Wunsch werden auch E-Mails ausgewertet (IMAP-Abruf oder Weiterleitung)
- **Notizen** — Ordner je Familienmitglied und selbst angelegte, Notizen mit Text und Anhängen (Bild, PDF)
- **Kalender** — Termine aus dem Store-Modul OpenCalendar lesen, anlegen und bearbeiten
- **Web-Push** — Benachrichtigungen aufs gekoppelte Handy bei fälligen Aufgaben, neuem Briefing und neuen KI-Vorschlägen; Termin-Erinnerungen zusätzlich über die Kachel-Visualisierung
- **Geräteverwaltung** — Liste aller gekoppelten Geräte, einzelne Geräte sperrbar

## 2. Voraussetzungen

- IP-Symcon ab Version **8.1**
- **ToDo List**- und/oder **Einkaufsliste**-Instanzen dieser Bibliothek als Datenquellen
- Für die Web-App unterwegs: **Symcon Connect** (oder eine eigene HTTPS-Adresse)
- Für Web-Push auf dem iPhone: die Web-App muss zum **Home-Bildschirm** hinzugefügt sein (iOS 16.4 oder neuer)
- Für den Kalender-Bereich: das Store-Modul **OpenCalendar** (de.burki24.opencalendar), optional
- Für die E-Mail-Analyse: eine **E-Mail, Empfangen (IMAP)**-Instanz oder eine Mail-Weiterleitung, optional
- Für die KI-Funktionen: ein eigener API-Schlüssel (**Anthropic** oder **OpenAI**) oder ein **lokaler, OpenAI-kompatibler Server** (z. B. LM Studio)
- Für die Sprachausgabe des Briefings: **OpenAI**, **Microsoft Azure Speech** oder **ElevenLabs** (eigener Schlüssel; bei ElevenLabs ist ein kostenpflichtiger Zugang nötig)

## 3. Installation

1. Bibliothek über das Module Control installieren: `https://github.com/da8ter/ToDo-List.git`
2. Falls noch nicht vorhanden: **ToDo List**- und **Einkaufsliste**-Instanzen anlegen
3. Eine Instanz **SymDo Gateway** anlegen
4. Eine Instanz **SymDoWebApp** anlegen und in der Kachel-Visualisierung einbinden

## 4. Einrichten der Instanzen in IP-Symcon

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
- **Datenschutz** — die KI-Funktionen laufen erst nach erteilter Einwilligung; sie ist jederzeit widerrufbar (Kapitel 9)
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

- **Geschrieben für** — ein einzelnes Mitglied (persönliche Anrede) oder *— die ganze Familie —* (Haushalts-Briefing: alle werden gemeinsam angesprochen, jede Aufgabe mit dem Namen des Zuständigen)
- **Persona** — Tonfall des Texts **und** der Stimme: Sachlich, Förmlich, Butler, Kumpel, Lustig, Drillsergeant, Motivationstrainer, Jammerlappen, Digga. Die Persona *Förmlich* siezt Erwachsene mit Nachnamen („Herr Muster"), Kinder bleiben beim Vornamen und Du
- **Personas bearbeiten** — je Persona lässt sich für jeden Sprachausgabe-Anbieter eine eigene Stimme hinterlegen; ohne Eintrag gilt die eingebaute Vorgabe
- **Vorschau am Vorabend** — ab der eingestellten Uhrzeit (Standard 18:00 Uhr) zeigt die Übersicht das Briefing für morgen

**Sprachausgabe-Anbieter** (`TtsProvider`):

| Anbieter | Felder | Hinweis |
|---|---|---|
| OpenAI | Stimme, Modell, Sprechanweisung | nutzt den OpenAI-Schlüssel der KI-Funktionen |
| Azure Speech | Schlüssel, Region | deutsche Neural-Stimmen |
| ElevenLabs | Schlüssel, Stimme, Modell, Stimmen-Umfang, Tonqualität | **kostenpflichtiger Zugang nötig**; „Stimmen des Kontos abrufen" listet die eigenen Stimmen — Vorgabe ist die Rubrik *Meine Stimmen* wie auf der ElevenLabs-Webseite; Tonqualität `auto` wählt die beste Stufe, die zur Textlänge passt |

## 9. KI-Funktionen und Datenschutz

Alle KI-Funktionen (Foto-/PDF-Analyse, Mail-Analyse, Briefing) laufen über den **selbst gewählten** Anbieter mit dem **eigenen** Schlüssel — oder vollständig lokal über einen OpenAI-kompatiblen Server. Es gilt:

- Ohne erteilte **Einwilligung** (Formular → KI-Funktionen → Datenschutz) bleibt jede KI-Funktion aus; die Einwilligung ist jederzeit widerrufbar
- Das **Tageslimit** deckelt alle KI-Aufrufe zusammen (Standard: 100 pro Tag)
- API-Schlüssel werden nur für die Anfragen an den gewählten Anbieter verwendet
- Mail-Anhänge werden für die Analyse gelesen, aber nur dauerhaft gespeichert, wenn das ausdrücklich eingeschaltet ist

## 10. Benachrichtigungen

Web-Push erreicht jedes gekoppelte Gerät, auf dem die Web-App als Home-Screen-App installiert ist und Benachrichtigungen erlaubt sind. Angezeigt werden Titel, Text und App-Symbol; Bilder und Antwort-Knöpfe zeigt iOS in Web-Push-Nachrichten nicht an. Ein Tipp auf die Nachricht öffnet die App im passenden Bereich.

Termin-Erinnerungen aus dem Kalender lassen sich zusätzlich über eine Kachel-Visualisierungsinstanz zustellen (Kapitel 6, *Visualisierungs-Benachrichtigungen*).

## 11. Statusvariablen

Beide Module legen **keine Statusvariablen** und **keine Variablenprofile** an. Briefing-Audio und Notiz-Anhänge werden als Medienobjekte in eigenen Kategorien unterhalb des Gateways abgelegt.

## 12. PHP-Befehlsreferenz

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
