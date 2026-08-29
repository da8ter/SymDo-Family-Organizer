# SymDo - Routinen

Tägliche Häkchenlisten für Kinder als Kachel: Zähne putzen 🪥, Ranzen packen 🎒,
Brotdose einpacken 🥪 — große Zeilen, großes Häkchen, Konfetti, wenn alles
geschafft ist. Die Häkchen setzen sich jeden Tag zur eingestellten Zeit von
selbst zurück.

## Funktionen

- **Beliebig viele Routinen in einer Instanz** — Morgen-, Abend-,
  Hausaufgabenroutine, je Kind eigene. Jede Routine hat eigene Schritte.
- **Anzeigezeiten je Routine (Von/Bis)** — die Kachel zeigt zur Uhrzeit die
  passende Routine; ohne Zeiten ist sie immer sichtbar, ein Fenster über
  Mitternacht funktioniert. Sind mehrere gleichzeitig aktiv, gibt es
  Umschalt-Chips.
- **Kind je Routine, optional** — Zuordnung zu einem SymDo-Mitglied; oben
  links erscheinen groß das Foto und „Hallo <Name>". Ohne Gateway läuft
  alles trotzdem.
- **Belohnungs-Münzen, optional** — je Schritt einstellbar (Vorgabe 5).
  Der Geldbeutel gehört dem Kind, übersteht den Tagesreset und lässt sich
  von den Eltern per `RTN_AdjustCoins($id, 'kennung', -50)` einlösen.
- **Heute-Aufgaben als Füller** — ist gerade keine Routine dran, zeigt die
  Kachel die heute fälligen (und überfälligen) Aufgaben der Kinder aus den
  SymDo-ToDo-Listen, direkt abhakbar.
- **Status-Variablen** — je Routine „… geschafft" (Bool) und je Geldbeutel
  ein Münz-Zähler, z. B. für Automationen („Licht wird grün, wenn die
  Morgenroutine fertig ist").

## Einrichtung

1. Instanz **SymDo - Routinen** anlegen.
2. Unter *Routinen* je Zeile Name, Emoji, Kind und das Von/Bis-Fenster setzen.
3. Unter *Schritte* die Schritte anlegen und der Routine zuordnen —
   die Reihenfolge hier ist die Reihenfolge auf der Kachel.
4. Die Instanz in die Kachel-Visualisierung einbinden.

## PHP-Befehlsreferenz

| Funktion | Beschreibung |
|---|---|
| `RTN_AdjustCoins(int $InstanzID, string $BeutelID, int $Delta): int` | Münzstand anpassen (z. B. `-50` beim Einlösen); Beutel-Kennung ist die Mitglieds-Kennung des Kindes, bei Routinen ohne Kind die Routine-Kennung. Liefert den neuen Stand, nie unter 0. |
