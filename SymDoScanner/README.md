# SymDo - Scanner

**Der Arbeiter im Hintergrund: er holt und liest, was lange dauert — damit die
App weiter antwortet, während eine Klassenseite gelesen oder eine KI befragt
wird.**

Diese Instanzen gehören zu **SymDo** und werden gebraucht. Ohne sie läuft alles
weiter, aber die Oberfläche steht so lange still, wie ein Abruf dauert.

## Es gibt nichts einzurichten

Das **SymDo Gateway** legt seine Scanner selbst an, verbindet sie und trägt
ein, welche Quellen sie bedienen. Eingerichtet — Konten, Schalter, Zeiten —
wird alles am Gateway.

Im Objektbaum stehen sie **neben** dem Gateway, nicht darunter: ein gelöschtes
Gateway nähme sie sonst mit. Es sind **Splitter-Instanzen**, keine Geräte.

## Was sie tun

| Rolle | Arbeit |
|---|---|
| **Aufträge** | Alles, was die KI beschäftigt: Foto-Scan, Zutatenliste aus einem Rezept, Diktat, die Auswertung von Klassenseiten, Mails und dem Tagesbriefing |
| **Schule** | Klassenseiten und LOGINEO abrufen, Stundenplan und Hausaufgaben aus WebUntis holen, das Symcon-Handbuch durchsuchbar halten |

Jede Rolle bekommt eine eigene Instanz — so wartet ein Foto-Scan nie hinter
einem Schulabruf. Eine Rolle ohne Quellen bekommt gar keine.

Die Scanner **lesen und rechnen nur**. Gespeichert wird im Gateway: Notizen,
Aufgaben, Termine, Anhänge und Vorschläge stehen dort, wo sie immer standen.

## Wenn etwas klemmt

- **Nichts passiert.** `SDSC_Stand(<id>)` zeigt, was die Instanz sieht: ihre
  Rolle, ihre Quellen, das gefundene Gateway und wie viele Aufträge warten.
- **Eine Quelle soll zurück ans Gateway.** Die Zeile unter *Aufgaben*
  abschalten und übernehmen — das Gateway macht die Arbeit dann wieder selbst.
- **Eine Instanz gelöscht?** Kein Verlust: das Gateway übernimmt die Arbeit
  wieder und legt beim nächsten Übernehmen eine neue an.

## PHP-Befehlsreferenz

```php
echo SDSC_Auftrag(<id>, 'edu', '{}');   // einen Abruf von Hand anstoßen
echo SDSC_Stand(<id>);                  // Zustand dieser Instanz als JSON
```
