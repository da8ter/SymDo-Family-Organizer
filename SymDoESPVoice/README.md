# SymDo - Sprachgerät

**Ein kleiner Sprachassistent für den Raum, ohne Tablet und ohne Browser, in Symcon eingebunden.**

Ein ESP32-S3-Gerät mit Mikrofon, Lautsprecher und rundem Display, etwa die *Spotpear ESP32-S3 1.28" BOX*, spricht mit der SymDo-KI wie die Sprachkachel: Einkaufsliste, Aufgaben, Termine, Notizen, Stundenplan und, wenn freigegeben, Licht und Geräte. Diese Instanz bindet das Gerät in Symcon ein:
- Sie zeigt Zustand, Akku und Lautstärke.
- Sie legt fest, für wen und in welchem Raum das Gerät spricht.
- Sie nimmt Befehle aus Skripten entgegen.

> **Experimentell.** Die Geräte-Firmware ist in Entwicklung und nicht Teil dieser Bibliothek. Die Nutzung erfolgt auf eigene Gefahr.

## Inhalt

- **1. Funktionsumfang**
- **2. Voraussetzungen**
- **3. Installation**
- **4. Konfiguration**
- **5. Variablen**
- **6. Aus Skripten**
- **7. Funktionsweise und Sicherheit**

## 1. Funktionsumfang

- **Zustand des Geräts:** online, bereit / hört zu / denkt / spricht, Akku und Laden, Firmware-Version
- **Lautstärke und Display-Helligkeit** als Slider, wirken sofort am Gerät
- **Sprachprofil je Gerät:**
  - **Mitglied:** Für wen spricht das Gerät?
  - **Raum:** Ein „mach das Licht an“ ohne Raumangabe meint dieses Zimmer.
  - **Schalterlaubnis:** Darf das Gerät Geräte schalten? Zum Beispiel im Kinderzimmer nein.
  - **Weckwort:** „Hi ESP“, „Alexa“, „Jarvis“, „Computer“, ein eigener englischer Ausdruck oder aus (nur Taste).
- **Letzte Frage und Antwort** in Symcon, nur wenn eingeschaltet
- **Gespräch starten und Neustart** aus Symcon, z. B. von der Klingel
- **Firmware-Update über Symcon:** per WLAN, mit Prüfsumme und automatischem Rückfall

## 2. Voraussetzungen

- Symcon ab Version **8.1**
- Eine **SymDo - Gateway**-Instanz mit eingeschaltetem Sprachdialog, im Modell *Realtime*. GPT-Live unterstützt das Gerät noch nicht.
- Für das Weckwort: im Gateway **freihändiges Sprechen** freigegeben und eingewilligt
- Ein **eigener MQTT-Server** in Symcon nur für Sprachgeräte, siehe Abschnitt 7
- Ein SymDo-Sprachgerät (ESP32-S3) im selben Netz

## 3. Installation

1. Bibliothek installieren: im **Module Store** genau nach **SymDo - Family Organizer** suchen. Alternativ über das Module Control mit `https://github.com/da8ter/SymDo-Family-Organizer.git`
2. Eine Instanz **SymDo - Sprachgerät** anlegen. Als übergeordnete Instanz einen **neuen** MQTT-Server wählen, mit eigenem Benutzer und Passwort. Am zugehörigen Server Socket einen freien Port einstellen, z. B. 1890.
3. Im Formular **Neues Gerät koppeln** drücken. Es erscheint ein Kopplungscode.
4. Am Gerät die Taste 3 Sekunden halten. Das Display zeigt dann WLAN-Name und Passwort des Einrichtungs-Hotspots.
5. Mit dem Handy in diesen Hotspot wechseln und `http://192.168.4.1` öffnen. Dort WLAN, Symcon-Adresse (z. B. `192.168.0.6:3777`) und den Kopplungscode eintragen. Das Gerät startet neu und koppelt sich selbst.
6. Im Formular das gekoppelte Gerät auswählen und übernehmen.

## 4. Konfiguration

| Einstellung | Bedeutung |
|---|---|
| Sprachgerät | Das gekoppelte Gerät aus dem Gateway |
| Spricht als Mitglied | Das Familienmitglied, für das „ich“, „meine Aufgaben“ usw. gelten. Leer = Haushalt |
| Raum | Vorgabe für Geräte ohne Raumangabe |
| Darf Geräte schalten | Aus = Licht, Rollläden usw. sind für dieses Gerät gesperrt |
| Weckwort | Fertiges Wort, eigener englischer Ausdruck (2–5 Wörter) oder aus |
| Letzte Frage und Antwort anzeigen | Aus = nichts davon verlässt das Gerät in Richtung MQTT |
| Firmware-Quelle | Adresse (http/https) oder Datei auf dem Symcon-Rechner für **Firmware aktualisieren** |

Beim Übernehmen schreibt die Instanz das Profil ins Gateway. Das Gerät holt es beim Start und danach alle 10 Minuten ab.

## 5. Variablen

| Variable | Typ | Schaltbar |
|---|---|---|
| Online | Bool | nein |
| Zustand | Bereit, Verbindet, Hört zu, Denkt, Führt aus, Spricht, Fehler, Einrichtung | nein |
| Akku, Lädt | % / Bool | nein |
| Lautstärke | 0–100 % | ja |
| Helligkeit | 5–100 % | ja |
| Letzte Frage, Letzte Antwort | Text | nein |
| Firmware | Text | nein |

## 6. Aus Skripten

```php
SDEV_StartConversation(12345); // Gespräch auslösen, als hätte jemand die Taste gedrückt
SDEV_Reboot(12345);            // Gerät neu starten
echo SDEV_UpdateFirmware(12345); // Firmware aus der eingetragenen Quelle einspielen
```

**Firmware-Update:** Die Instanz prüft die Datei (ESP32-Abbild, passt in die Partition) und legt sie unter ihrer SHA-256 in Symcons Ordner `user/symdo-esp/` ab. Dann schickt sie dem Gerät einen signierten Befehl mit Pfad und Prüfsumme. Das Gerät lädt die Datei von Symcon und schaltet nur bei stimmender Prüfsumme um. Meldet sich die neue Version nicht innerhalb von 90 Sekunden beim Gateway oder startet sie unbestätigt neu, kehrt das Gerät zur alten zurück.

## 7. Funktionsweise und Sicherheit

- **Gespräch:** Das Gerät öffnet seine Sitzung beim SymDo-Gateway. Das Gespräch läuft dann per WebRTC direkt zu OpenAI. Werkzeugaufrufe gehen ans Gateway zurück. Prompt, Rechte, Budget und Rückfragen bleiben dort, genau wie bei der Sprachkachel.
- **Profil:** Mitglied, Raum und Schalterlaubnis stehen am Geräteeintrag im Gateway. Das Gerät kann sie nicht selbst wählen.
- **Steuerkanal:** Zustand auf `symdo/esp/<gerät>/status` (retained, Last Will = offline), Befehle auf `symdo/esp/<gerät>/cmd`.
- **Eigener MQTT-Server:** Symcons MQTT-Server kennt keine Themenrechte, und alle Geräte bekommen seinen Zugang. Die Instanz gibt den Zugang deshalb nur heraus, wenn an diesem Server ausschließlich Sprachgeräte hängen. Sonst meldet sie Status 202.
- **Signierte Befehle:** Jeder Befehl trägt eine HMAC-SHA256-Signatur mit einem Schlüssel, den nur diese Instanz und dieses Gerät kennen, dazu einen Zeitstempel. Unsignierte, fremde oder wiederholte Befehle weist das Gerät ab. Ein anderes Gerät am selben Server kann also kein Mikrofon einschalten.
- **Bekannte Grenze:** Die Verbindung Gerät–Gateway läuft im Heimnetz per http, wie bei der App über die lokale Adresse. Token, Befehlsschlüssel und MQTT-Zugang sind damit im lokalen Netz nicht verschlüsselt.
