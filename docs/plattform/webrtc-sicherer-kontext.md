# WebRTC, Mikrofon und der sichere Kontext

Was Browser in einer Symcon-Visu nur über HTTPS freigeben, wie das scheitert und wie SymDo damit umgeht. Betrifft den Sprachdialog, das Diktat und jeden anderen Mikrofon- oder Kamera-Weg (Barcode-Scanner).

## Fakten

- `getUserMedia` (Mikrofon, Kamera) ist nur in einem **sicheren Kontext** freigegeben: `https://`, `http://localhost`, `http://127.0.0.1`. Eine lokale http-Adresse im Heimnetz zählt ausdrücklich nicht.
- **WebKit verweigert den Zugriff nicht, es liefert `navigator.mediaDevices` gar nicht erst aus.** Ein direkter Aufruf `navigator.mediaDevices.getUserMedia(…)` wirft dann einen synchronen `TypeError`, vor jeder Promise-Kette; ein `.catch()` fängt ihn nicht. Die Sprachkachel hing deshalb früher auf „verbinde".
- **`crypto.subtle` (WebCrypto) fehlt ebenfalls** außerhalb des sicheren Kontexts; `crypto.getRandomValues` bleibt verfügbar.
- **`RTCPeerConnection` selbst ist nicht an den sicheren Kontext gebunden**; ohne eigenes Mikrofon lässt sich also auch aus einer http-Visu eine Verbindung zum Anbieter aufbauen.
- Bleibt die Mikrofonfrage unbeantwortet, löst `getUserMedia` weder auf noch ab (headless nachgestellt).

## Entscheidungen

- **Erst prüfen, dann zugreifen.** `voice-core.js` fragt vor `getUserMedia` nach `isSecureContext` und `navigator.mediaDevices` und sagt dem Nutzer den einen Satz, der hilft: die Visu über die Connect-Adresse (https) öffnen statt über die lokale http-Adresse.
- **Frist für den Verbindungsaufbau** (25 s), damit die Anzeige bei unbeantworteter Mikrofonfrage nicht ewig auf „verbinde" stehen bleibt.
- **Mit fremder Tonquelle entfällt die Mikrofonprüfung.** Liefert ein Sprachgerät das Mikrofon (Ton über das Tablet), prüft der Kern nur noch, ob WebRTC vorhanden ist. Damit soll die Kachel auch in einer http-Visu funktionieren.
- **Eigene Kryptografie, wo WebCrypto fehlt**: die Entschlüsselung des Gerätetons ist in JavaScript von Hand implementiert und im Prüfstand gegen libsodium abgeglichen, weil eine http-Visu kein `crypto.subtle` hat.
- **Empfehlung für Nutzer**: in der Visu-App auf dem Smartphone die Connect-Adresse eintragen. Lokales TLS ginge auch, ist aber der aufwendigere Weg.

## Fallen

- Am Rechner fällt das Problem oft nicht auf, weil dort über Connect oder `localhost` getestet wird. Wer Sprache oder Scanner testet, muss mindestens einmal über die lokale http-Adresse eines anderen Geräts prüfen.
- Ein AudioContext bleibt ohne Berührung der Seite „suspended" (Autoplay-Sperre). Wer Ton ohne Geste abspielen will, muss die erste Berührung abfangen und den Kontext dann fortsetzen.
- `AudioWorklet` ist laut Spezifikation ebenfalls an den sicheren Kontext gebunden; `esp-mikro.js` nutzt einen `ScriptProcessor` (Grund im Code nicht vermerkt; nicht am Code prüfbar).

## Offen

- Ton über das Tablet in einer Visu über die lokale http-Adresse ist bisher nur im Prüfstand nachgestellt (`isSecureContext = false`), nicht live belegt (nicht am Code prüfbar).

Stand: geprüft gegen den Code am 08.10.2026
