# Kachel-Nachrichten der Tile-Visu

Wie Nachrichten aus `UpdateVisualizationValue` eine HTML-Kachel erreichen und was daraus für Kacheln mit Nebenwirkungen folgt. Gemessen am 06.10.2026 mit Symcon 9.1 beim Bau von „Ton über das Tablet" (siehe `../entscheidungen/sprache-tablet-ton.md`).

## Gemessen

- **Doppelzustellung**: jede Nachricht erreicht die Kachel zweimal, über `window.handleMessage` UND über das `message`-Ereignis. SymDo-Kacheln hören auf beides.
- **Durchsatz**: 50 Nachrichten im 100-ms-Takt kamen alle einzeln und in Reihenfolge an, ohne Zusammenfassen.
- **Empfänger**: Nachrichten gehen an jeden offenen Visu-Client mit dieser Kachel, auch an Kacheln, die gerade nicht sichtbar sind.

## Entscheidungen

- **Ereignisse mit Nebenwirkung entdoppeln, von Anfang an.** Für Zustands-Pushes ist die Doppelzustellung egal; für Zusagen, Antworten und Tonpakete nicht — ohne Entdoppelung gab es doppelte Antworten ans Modul und doppelt lange Tonstücke. Muster: Paketnummer, Nonce, Transaktions- oder Anfragekennung, die nach dem ersten Treffer verbraucht ist. Im Prüfstand jede solche Nachricht doppelt zustellen.
- **Nie etwas pushen, das nur ein Client sehen darf.** Weil jeder offene Client jede Nachricht bekommt, ist ein Push öffentlich innerhalb der Visu. Geheimes bleibt beim Modul (Token nur als Hash), Inhalte für einen einzelnen Browser gehen verschlüsselt mit einem Schlüssel, den nur dieser Browser kennt, und Antworten tragen eine zufällige Anfragekennung, die nur das fragende Fenster kennt. Eine Fensterkennung, die irgendwann gepusht wurde, beweist nichts mehr.
- **`RequestAction` einer Kachel ist eine öffentliche Schnittstelle.** Jeder Browser mit Zugang zur Visu kann jeden Ident der Kachel aufrufen. Was zwischen Modulen läuft, geht deshalb über öffentliche Modulfunktionen (Präfix-Funktionen), nicht über `RequestAction`; was über `RequestAction` hereinkommt, prüft selbst.
- **Nachrichten nur vom eigenen Fenster oder dem einbettenden Elternfenster annehmen** (`event.source`), sonst könnte ein fremder Frame einen ganzen Zustand einspielen.
- **Antworten auf Kachel-Anfragen zuordnen**: die Voice-Kachel schickt je Anfrage eine Transaktionskennung mit und löst nur die passende, noch offene Anfrage auf. Das entdoppelt nebenbei — die zweite Zustellung findet keine offene Anfrage mehr. Die Antwort selbst sehen trotzdem alle Clients der Kachel.
- **Nutzlast im Seitenaufbau mit `JSON_HEX_TAG`**: steht der Startzustand in einem `<script>`-Block, beendet ein „</script>" in einem Namen sonst den Block, und der Rest landet als HTML in der Visu.

## Fallen

- Symcon arbeitet je Instanz nacheinander: ein langsamer, synchroner Aufruf derselben Instanz hält auch ihre Pushes auf. Wer gleichmäßige Datenströme durch eine Kachel schickt, braucht im Browser einen Puffer.
- Kachel-iframes sind für die Hauptseite cross-origin. Wer eine Kachel im Browser fernsteuert, liest ihren Zustand über die verschachtelten Shadow-Roots und umhüllt Funktionen über das Objekt der Kachel, nicht über `window.handleMessage` (nicht am Code prüfbar).

Stand: geprüft gegen den Code am 08.10.2026
