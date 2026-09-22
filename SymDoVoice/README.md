# SymDo - Sprachassistent

**Reden statt tippen: der Sprachdialog mit der SymDo-KI als Kachel für die Tile-Visualisierung.**

„Setz Milch auf die Einkaufsliste." — „Was hat Tim morgen für Unterricht?" — „Mach das Licht im Wohnzimmer aus, und in zehn Minuten das im Flur." Die Kachel hört zu, antwortet mit Stimme und erledigt es. Sie ist die Sprechstelle des Sprachdialogs; **alles andere liegt im SymDo Gateway**: dort wird der Dialog eingeschaltet, die Einwilligung erteilt, der Umfang festgelegt und protokolliert.

Der Sprachdialog spricht Deutsch, versteht natürliche Sätze und kann **nur**, was ihm die Werkzeuge des Gateways erlauben. Er erfindet nichts, führt nichts aus, wofür er kein Werkzeug hat, und sagt nie, etwas sei erledigt, bevor Symcon es bestätigt hat.

## Inhalt

- **1. Was der Assistent kann**
- **2. Voraussetzungen**
- **3. Installation und Einrichtung**
- **4. Konfiguration der Kachel**
- **5. Bedienung**
- **6. Freihändig mit Weckwort „Hey SymDo"**
- **7. Datenschutz und Grenzen**
- **8. PHP-Befehlsreferenz**

## 1. Was der Assistent kann

| Bereich | Beispiele |
|---|---|
| **Einkaufsliste** | „Was steht auf der Einkaufsliste?", „Setz zwei Liter Milch drauf", „Hak die Butter ab", „Was brauche ich für die Lasagne?" (Zutaten eines gespeicherten Rezepts auf die Liste) |
| **Aufgaben und Routinen** | „Was habe ich heute zu tun?", „Leg eine Aufgabe an: Müll rausbringen, morgen", „Hak Zähneputzen bei Mia ab" |
| **Termine** | „Was ist am Samstag los?", „Trag Zahnarzt am 12. um 9 ein", „Verschieb das Training auf 18 Uhr", „Lösch den Termin" (mit Rückfrage), auch Serientermine |
| **Notizen** | „Lies mir die Notiz zur Klassenfahrt vor", „Schreib eine Notiz für Tim: Sportzeug einpacken" |
| **Essensplan** | „Was gibt es morgen zu essen?", „Plan für Freitag Pizza ein" |
| **Stundenplan** | „Was hat Tim am Dienstag für Unterricht?", „Wann ist Mia morgen fertig?", „Fällt bei Tim etwas aus?" |
| **Hausaufgaben** | „Was hat Tim für morgen auf?", „Tim hat in Mathe Seite 42 bis Donnerstag auf", „Hak bei Mia Deutsch ab" — Fach, Fälligkeit und Notiz je Kind |
| **Tagesüberblick** | „Was steht heute an?" (Termine, fällige Aufgaben, Schule, Einkauf) |
| **Nachrichten** | „Sag Tim, er soll den Tisch decken" (Push auf die Geräte der Person) |
| **Symcon-Handbuch** | „Wie funktioniert IPS_SetEventCyclic?" — die Antwort kommt aus dem offiziellen Handbuch, vorgelesen statt verlinkt |
| **Geräte im Haus** | „Mach das Licht im Bad aus", „Stell die Heizung im Schlafzimmer auf 19,5 Grad", „Rollladen bei Tim runter", „Was ist im Wohnzimmer an?", „Starte die Szene Kinoabend" — nur für freigegebene Bereiche, siehe Gateway |
| **Licht in Farbe** | „LED-Streifen rot", „Nachtlicht auf türkis", „Wohnzimmer warmweiß", „etwas wärmer", „heller" — Farbe, Farbtemperatur und Helligkeit, das Wort entscheidet, welche Variable gemeint ist |
| **Zeitpläne** | „Schalte in 55 Minuten das Wasser aus", „Jeden Tag um 11 Uhr die Lampe an", „Werktags um 6:30 den Rollladen hoch", „Was ist geplant?", „Lösch den Timer für die Deckenlampe" |

Heikle Aktionen — Löschen, Geräte auf der Rückfrage-Liste, Zeitpläne dafür — bestätigt der Assistent immer erst mit einer gesprochenen Rückfrage. Ohne klares Ja passiert nichts.

## 2. Voraussetzungen

- Symcon ab Version **8.1**
- Eine **SymDo - Gateway**-Instanz mit eingeschaltetem Sprachdialog (Bereich *Sprachdialog*) und erteilter Einwilligung
- Ein **OpenAI-API-Schlüssel** im Gateway — der Dialog läuft über die Realtime-Schnittstelle (Modelle `gpt-realtime-mini` oder `gpt-realtime`) oder über **GPT-Live** (`gpt-live-1`: ein Modell spricht, ein zweites denkt und ruft die Werkzeuge — im Gateway umschaltbar); Handbuch-Antworten liest ein Textmodell (Standard `gpt-4.1`)
- Ein Gerät mit **Mikrofon** und einem Browser, der WebRTC kann; die Kachel braucht einen **sicheren Kontext** (HTTPS oder localhost). In der Symcon-App auf iOS und Android und im Browser über Symcon Connect ist das gegeben; eine lokale http-Adresse liefert kein Mikrofon
- Für das Weckwort: Chrome oder Edge ab Version 139 mit deutschem Sprachpaket (Kapitel 6)

## 3. Installation und Einrichtung

1. Bibliothek über das Module Control installieren: `https://github.com/da8ter/SymDo-Family-Organizer.git`
2. Im **SymDo - Gateway** den Bereich *Sprachdialog* öffnen: **Sprachdialog aktivieren**, Modell und Stimme wählen, die **Datenschutz-Einwilligung** erteilen. Der OpenAI-Schlüssel ist der der KI-Funktionen
3. **Testverbindung** im Gateway drücken — sie prägt einen Zugang für zehn Sekunden und beweist Schlüssel und Modellfreigabe, ohne eine Sekunde Ton zu bezahlen
4. Eine Instanz **SymDo - Sprachassistent** anlegen und in der Kachel-Visualisierung einbinden
5. Die Kachel einmal öffnen: sie führt eine **Machbarkeitsprobe** durch (kann diese Umgebung WebRTC zum Anbieter aufbauen?) und meldet das Ergebnis im Formular unter *Messergebnisse*

Für die **Gerätesteuerung** zusätzlich im Gateway: *Gerätesteuerung per Sprache erlauben*, die freigegebenen Wurzel-Kategorien eintragen, die Rückfrage-Liste befüllen und die eigene Einwilligung dafür erteilen. Einzelheiten stehen in der [Gateway-Anleitung](../SymDoGateway/README.md), Kapitel *Sprachdialog*.

## 4. Konfiguration der Kachel

| Einstellung | Eigenschaft | Bedeutung |
|---|---|---|
| Diese Kachel gehört | `UserID` | Das Familienmitglied, als das die Kachel spricht: „meine Aufgaben" meint dann diese Person, Notizen landen in ihrem Ordner. Die Rolle entscheidet mit — ein **Kind** darf keine Geräte der Rückfrage-Liste schalten und keine dauerhaften Zeitpläne anlegen |
| Standard-Einkaufsliste | `DefaultShoppingID` | Wohin „setz Milch auf die Liste" schreibt, wenn keine Liste genannt wird |
| Standard-Aufgabenliste | `DefaultTodoID` | Wohin neue Aufgaben ohne genannte Liste kommen |
| Kacheldarstellung | `Darstellung` | **Gesprächsverlauf (Text)** zeigt Frage und Antwort als Text mit — oder **Animierte Blase**, die auf die Stimme reagiert und in der Akzentfarbe der Visualisierung leuchtet. Läuft 15 Sekunden kein Gespräch, schläft das Wesen ein: die Lider sinken über die Augen, es sinkt etwas Richtung Boden, kleine „z" in blassen Wölkchen steigen auf; ein Tipp oder das Weckwort weckt es sofort |

Alles Übrige — Gesprächsdauer, Tageskontingent, Weckwort, Geräte — wird im Gateway eingestellt und gilt für alle Sprach-Kacheln des Haushalts gemeinsam.

## 5. Bedienung

Ein Tipp auf das Mikrofon startet das Gespräch, ein zweiter beendet es. Während des Gesprächs hört die KI zu, erkennt selbst, wann ein Satz endet, und antwortet mit Stimme; man darf sie unterbrechen. Ein Gespräch dauert höchstens die im Gateway eingestellte Zeit (Standard drei Minuten), danach legt die Kachel auf — Sprechzeit kostet beim Anbieter Geld, und ein vergessenes offenes Mikrofon soll nicht die Nacht durchlaufen. Dazu gilt ein Tageskontingent für den ganzen Haushalt (Standard 15 Minuten).

Der Assistent antwortet in ein bis zwei Sätzen, bei Handbuchfragen etwas länger. Er nennt nie Kennungen, Fehlercodes oder Adressen. Findet er mehrere passende Geräte, Termine oder Notizen, fragt er nach, welches gemeint ist, statt zu raten.

Die Blase ist als Dashboard-Kachel auch in der **SymDo Web-App** enthalten; dort gilt derselbe Dialog mit demselben Gateway.

## 6. Freihändig mit Weckwort

Optional startet das Gespräch ohne Berührung: die Kachel lauscht auf das Weckwort, standardmäßig **„Hey SymDo"**. Das ist ein Experiment und braucht im Gateway zwei Dinge — den Schalter *Freihändig erlauben* und eine **eigene Einwilligung**, getrennt von der für den Dialog: wer dem Knopf zugestimmt hat, hat nicht dem Dauerlauschen zugestimmt. Sind beide gesetzt, lauscht die Kachel **von selbst**, sobald sie geöffnet ist. Unten links steht dann „sag: ‚Hey SymDo'". Verweigert der Browser das Mikrofon ohne Berührung, bittet die Kachel um einen Tipp; fehlt das Sprachpaket, bleibt ein Ladeknopf.

Das **Weckwort ist einstellbar** (Gateway, Feld *Weckwort*): mindestens sechs Buchstaben, mehrere durch Komma. Zwei bis drei Silben mit klarem Anfang treffen am besten.

Nach dem Weckwort **schreibt der Erkenner weiter mit**, was gesagt wird, und reicht es als Text nach, sobald die Verbindung zum Anbieter steht — „Hey SymDo, was steht heute an" in einem Atemzug verliert nichts. Ein kurzer Zweiklang meldet, wann die Leitung offen ist.

Die Erkennung läuft **auf dem Gerät**. Solange das Weckwort nicht gefallen ist, verlässt kein Ton das Gerät; erst dann öffnet die Kachel das Gespräch mit dem Anbieter. Das setzt einen Browser mit lokaler Spracherkennung voraus (Chrome oder Edge ab 139, deutsches Sprachpaket installiert). Auf Geräten ohne diese Erkennung sagt die Kachel das — einen Rückfall auf eine Cloud-Erkennung gibt es bewusst nicht.

## 7. Datenschutz und Grenzen

- Während eines Gesprächs geht der **Raumton direkt vom Gerät zum KI-Anbieter** (WebRTC), samt den Stimmen aller Anwesenden. Deshalb die eigene Einwilligung im Gateway, jederzeit widerrufbar; ein Widerruf beendet laufende Gespräche sofort
- Werkzeugantworten können Auszüge aus Listen, Terminen, Notizen und Plänen enthalten; auf dem Symcon-Server wird **kein Ton gespeichert**
- Der Assistent kann **nur**, was die Werkzeuge hergeben: keine allgemeinen Wissens- oder Rechenfragen, keine Anrufe, keine E-Mails. Im Haus schaltet er ausschließlich, was das Gateway freigegeben hat
- Jede Schaltung, jeder angelegte und gelöschte Zeitplan steht im **Meldungsfenster** von Symcon; die letzten Werkzeugaufrufe stehen im Protokoll des Gateways
- Kinder (Rolle *Kind* des sprechenden Mitglieds) dürfen freigegebene Geräte schalten und einmalige Zeitpläne anlegen, aber nichts von der Rückfrage-Liste und keine dauerhaften Pläne; fremde Zeitpläne löschen sie nicht

## 8. PHP-Befehlsreferenz

```php
string SDVC_GetState(int $InstanzID);         // Zustand der Kachel (JSON): Nutzer, Listen, Darstellung, Gateway
string SDVC_GetProbeResults(int $InstanzID);  // Ergebnisse der Machbarkeitsprobe je Umgebung (JSON)
string SDVC_LastSeenTxn(int $InstanzID);      // Kennung der zuletzt verarbeiteten Transaktion
bool   SDVC_Hangup(int $InstanzID);           // laufendes Gespräch dieser Kachel beenden
```

Die Sprach-Werkzeuge selbst — was der Assistent lesen und tun darf — sind im Gateway festgelegt und nicht über PHP erreichbar; sie werden ausschließlich vom Sprachdialog aufgerufen.
