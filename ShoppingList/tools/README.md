# Produktbild-Generator (ShoppingList)

Internes Wartungs-Skript für die Produktbild-Bibliothek in `../assets/`. Erzeugt
transparente PNGs (1024×1024) über die OpenAI Images API (`gpt-image-1`).
**Kein Laufzeit-Bestandteil des Moduls** — nur zum Nachpflegen fehlender
Artikelbilder.

> Der API-Key wird **ausschließlich** aus der Umgebung gelesen (`OPENAI_API_KEY`).
> Niemals einen Key in dieses Skript oder ins Repo schreiben. Dafür lokal eine
> `.env` mit `OPENAI_API_KEY=…` anlegen — die Datei ist ge-`.gitignore`-t und
> liegt bewusst nicht im Repo. Auch die `output/`-Ordner sind ge-`.gitignore`-t —
> nur das fertige 100×100-Asset + der Alias kommen ins Repo.

## Bilder erzeugen

```bash
set -a; source .env; set +a        # lädt OPENAI_API_KEY aus .env
node generate-images.mjs --items="Olivenöl,Gemüsebrühe" --category="Konserven & Trocken"
```

Optionen: `--items="A,B,C"` (freie Artikel), `--category="…"` (wählt das
Prompt-Template), `--only="…"` (Teilmenge der eingebauten Liste), `--test` (ein
Sample je Kategorie), `--model="gpt-image-1"`. Ausgabe → `./output`.

Prompt-Templates je Kategorie: `Obst & Gemüse`, `Milch & Käse`, `Backwaren`,
`Fleisch & Wurst`, `Tiefkühl`, `Getränke`, `Konserven & Trocken`,
`Hygiene & Pflege`, `Haushalt & Reinigung`, `Baby & Tier`.

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
