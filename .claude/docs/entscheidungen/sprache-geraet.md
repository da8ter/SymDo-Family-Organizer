# Sprachgerät (SymDoESPVoice) und Gateway

Ein SymDo-Sprachgerät (ESP32) ist ein gekoppeltes Gerät des Gateways mit eigenem Sprachprofil; die Instanz `SymDoESPVoice` (Präfix SDEV) hängt an einem Symcon-MQTT-Server und bindet es ein. Hier stehen die Entscheidungen auf der Symcon-Seite; die Firmware ist nicht Teil dieser Bibliothek.

## Entscheidungen

- **Das Gespräch läuft wie bei der Kachel**: das Gerät öffnet seine Sitzung über die Aktionen des Sprachwegs (`open`, `livesdp`, `opened`, `ping`, `tool`, `close`) und spricht dann per WebRTC direkt mit dem Anbieter. Prompt, Rechte, Budget und Rückfragen bleiben im Gateway — es gibt keinen zweiten Dialog-Code für Geräte.
- **Profil am Geräteeintrag, nicht im Rumpf.** Mitglied, Raum, Schalterlaubnis, Weckwort, MQTT-Zugang und Befehlsschlüssel stehen am Eintrag in der Geräteliste (`TGW_SetDeviceVoiceProfile`) und überschreiben, was die Anfrage mitbringt. Ein Gerät ohne Bildschirm kann nicht wählen, für wen es spricht, und ein Token in fremder Hand soll sich kein Mitglied aussuchen können. Geräte ohne Profil (Web-App, iOS) bleiben unberührt.
- **Interne Felder (`_raum`, `_geraete`, `_weckwort`, `_mqtt`, `_cmdKey`, …) setzt nur das Profil**; aus dem Rumpf werden sie immer verworfen. `_raum` landet in der Anweisung an das Modell — aus einem fremden Rumpf wäre das Prompt-Injection.
- **Ein Gespräch je Gerät**: Geräte bekommen eine feste Kachelkennung oberhalb des Bereichs echter Objekt-IDs, damit die Regel „ein Gespräch je Kachel" je Gerät greift.
- **Weckwort hinter der Freihand-Einwilligung.** Die Aktion `profil` liefert das Weckwort nur, wenn Freisprechen im Haushalt erlaubt und eingewilligt ist, sonst „aus" (nur Taste). Weckwörter sind feste Kennungen des Geräts oder ein eigener englischer Ausdruck (2–5 Wörter, nur a–z), weil die Erkennung auf dem Chip nur diese kennt.
- **Eigener MQTT-Server nur für Sprachgeräte.** Symcons MQTT-Server kennt keine Themenrechte, und alle Geräte bekommen denselben Zugang. Hängt am Server etwas anderes, gibt die Instanz den Zugang nicht heraus und meldet Status 202 (das Profil wird trotzdem geschrieben).
- **Befehle signiert**: HMAC-SHA256 über `cmd|wert|ts` mit einem gerätegenauen Schlüssel. Der Zeitstempel ist in Millisekunden, weil das Gerät nur strikt steigende Werte (Fenster ±60 s) annimmt — so gehen zwei Befehle in derselben Sekunde (Lautstärke und Helligkeit) durch.
- **Mitschrift standardmäßig aus.** Frage und Antwort gehen nur mit Schalter über MQTT, denn alle Geräte am Server könnten mitlesen.
- **Durchsagen und Briefing**: das Gateway erzeugt den Ton (`TGW_TtsClip`, höchstens 600 Zeichen je Stück), das Gerät holt ihn mit seinem Token. Fürs Briefing nimmt die Instanz die fertigen Schnipsel, solange alle dasselbe spielbare Format haben, sonst lässt sie den Text in höchstens acht Stücken neu vertonen.
- **Firmware-Update über Symcon**: Quelle nur `https` oder eine Datei auf dem Symcon-Rechner (über `http` ließe sich die Datei unterwegs austauschen, und Symcon reichte sie sauber weiter). Die Instanz prüft Abbildkennung und Größe, legt die Datei unter ihrer SHA-256 in `user/symdo-esp/` ab (Symcon 9.1 liefert `/user/` ohne die 1-MB-Grenze eines Hooks aus; nicht am Code prüfbar), hält nur die aktuelle Datei bereit und schickt einen signierten `ota`-Befehl mit Pfad und Prüfsumme. Signaturprüfung der Firmware und Rückfall erledigt das Gerät (nicht am Code prüfbar).

## Sicherheit

- **Ein Sprachgerät hat einen vollwertigen Geräte-Token.** Er wird mit demselben Kopplungscode erzeugt wie für die App und gilt für die ganze App-API; das Profil schränkt nur den Sprachweg ein. Eine Umfangsbeschränkung je Token gibt es nicht. Die Plattformangabe (`esp32`) meldet das Gerät beim Koppeln selbst; das Formular filtert nur danach.
- **Befehlsschlüssel und MQTT-Passwort liegen in Klartext**: der Schlüssel als Attribut der Instanz und im Geräteeintrag des Gateways, beides in der `settings.json`. Wer sie lesen kann, kann Befehle an das Gerät signieren. Anders als Kopplungscode und Gesprächsschlüssel der Kachel (Instanz-Puffer, siehe `sprache-tablet-ton.md`) ist das nicht umgestellt.
- Gerät–Gateway läuft im Heimnetz per http; Token, Befehlsschlüssel und MQTT-Zugang reisen dort unverschlüsselt (bekannte Grenze, im README genannt).

## Fallen / gemessen

- **Module Strict: der MQTT-Server reicht die Nutzlast hex-kodiert durch**, auch beim Senden wird hex erwartet. Ohne Umkodierung kommen weder Status noch Befehle an.
- Der Empfangsfilter steht auf `^$`, solange kein Gerät gewählt ist — sonst liefe jede Nachricht des Servers durch die Instanz.
- Die Darstellungen folgen Symcon 9.1: Slider-Schrittweite heißt `STEP_SIZE`, der Gerätezustand ist eine Aufzählung.

## Offen

- Hörprobe mit echter Stimme am Gerät und Freihand-Weckwort im Betrieb (nicht am Code prüfbar).
- GPT-Live unterstützt das Gerät noch nicht; es braucht im Gateway das Modell Realtime.

Stand: geprüft gegen den Code am 08.10.2026
