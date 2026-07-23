# Produktbild-Generator (ShoppingList)

Interne Wartungs-Skripte für die Produktbild-Bibliothek in `../assets/`. Erzeugen
transparente PNGs (1024×1024) über die OpenAI Images API (`gpt-image-1`) bzw.
Gemini. **Kein Laufzeit-Bestandteil des Moduls** — nur zum Nachpflegen fehlender
Artikelbilder.

> Der API-Key wird **ausschließlich** aus der Umgebung gelesen
> (`OPENAI_API_KEY` bzw. `GEMINI_API_KEY`). Niemals einen Key in diese Dateien
> oder ins Repo schreiben. `output/`-Ordner und Key-Dateien sind ge-`.gitignore`-t.

## Bilder erzeugen

```bash
export OPENAI_API_KEY="sk-..."
node generate-images.mjs --items="Olivenöl,Gemüsebrühe" --category="Konserven & Trocken"
```

Optionen: `--items="A,B,C"` (freie Artikel), `--category="…"` (wählt das
Prompt-Template), `--only="…"` (Teilmenge der eingebauten Liste), `--test` (ein
Sample je Kategorie), `--model="gpt-image-1"`. Ausgabe → `./output`.

Prompt-Templates je Kategorie: `Obst & Gemüse`, `Milch & Käse`, `Backwaren`,
`Fleisch & Wurst`, `Tiefkühl`, `Getränke`, `Konserven & Trocken`,
`Hygiene & Pflege`, `Haushalt & Reinigung`, `Baby & Tier`.

Gemini-Variante: `GEMINI_API_KEY` setzen, `node generate-images-gemini.mjs …`,
Ausgabe → `./output-gemini`.

## In das Modul übernehmen

Der Generator liefert 1024er-Bilder; die Modul-Assets sind **100×100 transparente
PNGs**. Ablauf pro Bild:

1. Skalieren und nach `../assets/` legen (Dateiname = Artikelname, kleingeschrieben,
   Umlaute erlaubt):
   ```bash
   sips -z 100 100 "output/olivenöl.png" --out "../assets/olivenöl.png"
   ```
2. Optional Aliase in `../assets/image-aliases.json` ergänzen
   (`"basisdateiname": ["alias1","alias2", …]`), damit verwandte Begriffe dasselbe
   Bild treffen. Marken → Abschnitt `_brands`.
3. Prüfen, dass Name + Aliase auflösen (die Bildsuche scannt den Ordner live,
   kein Modul-Reload nötig):
   ```php
   $s = json_decode(SL_GetAppState(<instanceID>), true);
   $imgs = $s['state']['availableImages'];   // erwartet: name => datei
   ```

Hinweis: `gpt-image-1` rendert für Glas/Reflektierendes gelegentlich einen
Studio-Gradienten „hinter" dem Objekt — der Alpha-Kanal ist trotzdem transparent
(per Composite über eine Farbe verifizieren). Solche Bilder sind verwendbar.
