# Ton über das Tablet

Seit 06.10.2026 kann ein Sprachgerät (SymDoESPVoice) das Mikrofon einer Voice-Kachel (SymDoVoice) sein: Weckwort und Fernfeld-Mikrofon kommen vom Gerät, das Gespräch führt die Kachel, die Antwort kommt aus dem Tablet. Code: `SymDoESPVoice/libs/TabletCalc.php`, `TabletWeiterleitung.php`, `SymDoVoice/libs/EspTablet.php`, `SymDoVoice/esp-mikro.js`.

## Ablauf

1. Gerät → MQTT `…/event`: Weckruf mit Nonce, signiert.
2. SDEV prüft Signatur, Zeitfenster und Wiederholung und ruft `SDVC_GeraetWeckruf`. Ist kein gekoppelter Browser bereit, antwortet die Kachel sofort `nein` und das Gerät spricht selbst.
3. Die Kachel pusht `espWake`; jeder bereite, gekoppelte Browser sagt per `EspMic start` zu und bringt einen frischen Gesprächsschlüssel und ein Geheimnis mit. Der erste gewinnt, alle Fenster erfahren per `espGewaehlt`, wer führt.
4. Gerät → `…/mic`: 100-ms-Pakete (G.711 µ-law, 16 kHz) mit Nummer und HMAC. SDEV prüft, `SDVC_GeraetTon` verschlüsselt mit dem Schlüssel des Gewinners und pusht `espAudio`.
5. Der Browser entschlüsselt, puffert und gibt den Strom als Mikrofon an den Gesprächskern. `keep` alle 15 s hält das Mikrofon am Gerät offen; `stop`, Taste am Gerät oder ausbleibende Lebenszeichen beenden.

## Entscheidungen

- **Über Symcon, nicht direkt Gerät–Tablet.** Gerät und Kachel sprechen nur über MQTT und Kachel-Pushes. Dadurch braucht die Kachel keine Mikrofonfreigabe im Browser.
- **Die Kachel führt das Gespräch, mit ihren Rechten.** Es gelten Mitglied, Standardlisten und Rolle der Kachel. Folge (aus dem Code): Raum und Schalterlaubnis aus dem Geräteprofil greifen in dieser Betriebsart nicht, denn der Sprachweg der Kachel läuft ohne Geräteprofil. Wer ein Gerät im Kinderzimmer sperren will, muss das an der Kachel tun.
- **Gerät ↔ Kachel nur über öffentliche Modulfunktionen** (`SDEV_MicSteuern`, `SDVC_Geraet*`), nicht über `RequestAction`: die Idents einer Kachel kann jeder Browser mit Zugang zur Visu aufrufen. Über `RequestAction` laufen nur die drei Browserwege `EspHier`, `EspKoppeln`, `EspMic`, und jeder davon prüft selbst.
- **Erster Browser gewinnt.** Mehrere offene Fenster bekommen denselben Weckruf; nur die erste Zusage für die laufende Nonce wird ans Gerät weitergegeben.
- **Rückfall statt Warten**: als bereit gilt eine Kachel 75 s nach dem letzten Lebenszeichen (der Browser meldet sich alle 30 s). Ohne frisches Zeichen lehnt sie sofort ab. Ohne freigegebenen Ton (Autoplay-Sperre) oder im Hintergrund meldet sich ein Browser gar nicht erst als bereit.
- **Echo-Sperre statt Dazwischenreden**: solange SymDo spricht und 800 ms danach, ersetzt die Kachel den Ton des Geräts durch Stille — die Pakete sind 0,2–0,5 s unterwegs, und das Gerät hört die Tablet-Lautsprecher. Unterbrechen geht in dieser Betriebsart deshalb nicht.
- **Zitterpuffer 150 ms Vorlauf, höchstens 600 ms**: wird es mehr, fällt das Alte weg. Verzögerung ist im Gespräch schlimmer als eine Lücke.
- **Kopplung je Browser.** Symcon unterscheidet die Browser einer Visu nicht; deshalb beweist jeder Browser seine Kopplung mit einem eigenen Token. Der Code dafür entsteht nur im Instanzformular (`SDVC_EspKoppelCode`), also nur für jemanden mit Konsolenzugang.

## Sicherheit

- **Alles vom Gerät ist signiert** (HMAC mit dem Befehlsschlüssel). Alle Sprachgeräte teilen den MQTT-Zugang; ohne Signatur könnte ein anderes Gerät Ton einspeisen. Weckruf und Ende: HMAC über `art|nonce|hf|ts`, Fenster ±60 s, jede Nonce nur einmal (die letzten 32 werden gemerkt). Pakete: 8 Byte HMAC über Nonce des laufenden Gesprächs, Nummer und Ton; SDEV nimmt nur Pakete des laufenden Gesprächs an.
- **Ton zur Kachel verschlüsselt** (XChaCha20, je Gespräch ein neuer Schlüssel des führenden Browsers), weil Kachel-Pushes jeden offenen Visu-Client erreichen. Ohne gültigen Schlüssel gibt es keine Zusage.
- **`stop`/`keep` nur mit dem Geheimnis aus der Zusage.** Die Fensterkennung allein reicht nicht, denn `espGewaehlt` nennt sie allen Fenstern.
- **Kopplungscode**: 6 Ziffern, 10 Minuten, einmal gültig, nach 5 Fehlversuchen verbraucht (1 zu 200 000). Prüfen und Zählen laufen unter einer Semaphore, sonst schafften parallele Anfragen mehr Rateversuche. Gespeichert wird nur der Hash des Browser-Tokens, höchstens 10 Kopplungen; der Token selbst wird nie gepusht.
- **Antworten an einen Browser tragen seine zufällige Anfragekennung (`rid`).** Nur das fragende Fenster kennt sie; ein fremdes Fenster kann so kein gekoppeltes Tablet zum Vergessen seines Tokens bringen.
- **Offener Code und Gesprächsschlüssel liegen in Instanz-Puffern, nicht in Attributen**: Attribute stehen im Klartext in der für alle lesbaren `settings.json`.
- **Aufheben aller Kopplungen** beendet ein laufendes Tablet-Gespräch sofort und schließt das Mikrofon am Gerät.

### Grenzen

- Die Verschlüsselung schützt vor anderen Visu-Clients, nicht vor dem Netz: der Schlüssel reist vom Browser per `RequestAction` zum Modul, in einer http-Visu also lesbar im Heimnetz, und vom Gerät zu Symcon läuft der Ton per MQTT ohnehin unverschlüsselt.
- XChaCha20 ist hier eine reine Stromchiffre ohne Authentisierung; sie schützt die Vertraulichkeit, nicht die Echtheit. Die Echtheit stützt sich auf die HMAC am Gerät und darauf, dass nur das Modul in die Kachel pushen kann.
- Wer übernimmt, hört den Raum. Darum zählen nur gekoppelte Browser.

## Fallen / gemessen

- **Doppelzustellung**: die Visu stellt jede Kachel-Nachricht zweimal zu (siehe `../plattform/kachel-nachrichten.md`). Pakete werden nach Nummer entdoppelt, ein Weckruf nur einmal beantwortet, Kopplungsantworten über `rid`. Ohne das gab es doppelte Zusagen und doppelt lange Tonstücke.
- **Pakete stauen sich hinter Werkzeugaufrufen**: Symcon arbeitet je Instanz nacheinander, und der synchrone Werkzeug-Relay derselben Kachel hält die Tonpakete auf. Daher der Zitterpuffer.
- **WebCrypto fehlt in einer http-Visu**, deshalb ist XChaCha20 in `esp-mikro.js` von Hand implementiert und im Prüfstand gegen libsodium geprüft.
- **Ein ScriptProcessor rechnet nur, wenn er am Ausgang hängt**: er hängt stumm (Verstärkung 0) an den Lautsprechern, damit das Tablet das Mikrofon nicht selbst abspielt.
- **Autoplay**: ein AudioContext bleibt ohne Berührung „suspended". Jede Berührung der Kachel darf ihn wecken; bis dahin zeigt die Kachel den Hinweis und meldet sich nicht als bereit.
- Prüfstände: `php SymDoESPVoice/tests/TabletTest.php`, `node SymDoVoice/tests/EspMikroTest.mjs` (beide mit doppelter Zustellung).

## Offen

- Ende-zu-Ende ist nur mit eingespeister Sprache belegt; echte Stimme, Echo am echten Tablet und die iOS-App als Kachelträger sind offen (nicht am Code prüfbar).
- Betrieb in einer Visu über die lokale http-Adresse ist nur im Prüfstand nachgestellt, nicht live belegt (nicht am Code prüfbar).
- Ob die Betriebsart mit GPT-Live läuft, ist nicht belegt; der Kern schließt es nicht aus.
- Die Wartezeiten des Geräts (höchstens 2,5 s auf eine Zusage, 45 s ohne Lebenszeichen) liegen in der Firmware (nicht am Code prüfbar).

Stand: geprüft gegen den Code am 08.10.2026
