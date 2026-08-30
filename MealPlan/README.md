# SymDo - Essensplan

Ein Wochenraster für die Frage aller Fragen: **Was gibt es heute?** Je Tag ein
Gericht, blätterbar zwischen dieser und der nächsten Woche — und ein Klick
legt die Zutaten in den Einkaufswagen.

## Funktionen

- **Rezepte sind Favoritenlisten** der gewählten Einkaufsliste — eine Quelle,
  keine doppelte Pflege. Rezeptfotos erscheinen als Miniatur am Tag.
- **Vier Wege zum Gericht:** Favoritenliste wählen, freier Text („Reste",
  „Pizza bestellen"), **URL analysieren** oder **Foto/Datei analysieren** —
  die KI liest die Zutaten, das Rezept wird als neue Favoritenliste
  gespeichert und dem Tag zugewiesen.
- **Zutaten-Übernahme:** je Gericht ein Korb-Knopf — er öffnet erst eine
  Abwahl-Liste (alles vorgewählt, Vorrätiges abhaken), übernommen wird nur
  die Auswahl. Dazu „Für die Woche einkaufen" (jedes geplante Rezept einmal,
  Mengen führt die Einkaufsliste zusammen).
- **Gericht-Ansicht:** ein geplanter Tag öffnet erst die Detail-Ansicht —
  Bild groß, Titel, „Rezept öffnen" (URL, Foto oder PDF der Quelle) und der
  🛒-Knopf; der Stift oben rechts führt in die Bearbeitung.
- **Briefing:** das tägliche Briefing erwähnt das Gericht („Heute Abend:
  Lasagne"; die Abend-Vorschau nennt das von morgen).
- **KI-Gerichtsbilder (optional):** Auf Wunsch bekommt jedes eingeplante
  Rezept automatisch ein einheitliches Gerichtsbild — angerichteter Teller
  von oben, transparenter Hintergrund. Einmal je Rezept erzeugt und
  wiederverwendet; ein vorhandenes Rezeptfoto bleibt als Quelle erhalten
  („Rezept öffnen"). Braucht ein SymDo Gateway mit OpenAI-API-Key
  (`gpt-image-1`, ~4 Cent je Bild), Schalter in den Instanz-Einstellungen.

## Einrichtung

1. Instanz **SymDo - Essensplan** anlegen und die Einkaufsliste wählen.
2. Die Instanz in die Kachel-Visualisierung einbinden.
3. Tag antippen, Gericht wählen — fertig.

## PHP-Befehlsreferenz

| Funktion | Beschreibung |
|---|---|
| `MPL_GetMealForDate(int $InstanzID, string $Datum): string` | Das Gericht eines Tages („YYYY-MM-DD") als JSON `{title, listId, hasIngredients}` — leerer Titel heißt: nichts geplant. Nutzt auch das Briefing. |
