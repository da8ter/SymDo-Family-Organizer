# Listen: Produktbilder der Einkaufsliste

Die Einkaufsliste zeigt zu Artikeln kleine Produktbilder aus `SymDoShoppingList/assets/`. Wie man sie erzeugt, steht in `SymDoShoppingList/tools/README.md`. Diese Datei hält die Konventionen fest, die dort fehlen, und erklärt, wie es zu falschen Bildern kommt.

## Entscheidungen

- **Nur OpenAI `gpt-image-1` als Generator** (`f1fe37e`, die Gemini-Variante ist entfernt). Der Generator ist ein Wartungswerkzeug und kein Laufzeitbestandteil. Der API-Key kommt nur aus der Umgebung bzw. einer lokalen `.env`. `output/`, `.env` und die Wegwerf-Skripte `regen*` sind ignoriert, ins Repo kommen nur das fertige 100×100-PNG und der Alias.
- **Prompt-Regeln gehören in zwei Erweiterungspunkte von `generate-images.mjs`, nicht in hart überschriebene Prompts:**
  - `repeatedSmallItems`: Kleine Lebensmittel (Kerne, Beeren, Trockenfutter, Datteln …) erscheinen als „mehrere Stücke“ statt als ein einzelnes. Ausnahme Himbeere: Dort verschmolzen mehrere Stücke zu einem Klumpen, sie bleibt eine Einzelbeere.
  - `itemSpecificPromptAdditions` (Name → Zusatztext): Der Inhalt wird symbolisch abgebildet. Getränke und Saucen sind eine Flasche mit Inhalt oder Zutat auf dem Etikett plus ein, zwei Stück daneben, ohne Text (Kirschsaft → Kirschen, Tonic Water → Limette). Haushaltssprays tragen ein Symbol auf dem Etikett. Fertiggerichte werden konkret beschrieben (Calamari → frittierte Tintenfischringe).
  - Nach einer Regeländerung mit `--items=…` und `OVERWRITE=1` neu erzeugen. Die Regeln greifen über den Artikelnamen.
- **Dateiname = Artikelname, kleingeschrieben, Umlaute erlaubt.** Auf macOS liegen die Namen in NFD vor. Das Modul normalisiert nach NFC, mit einem eigenen Rückfall, falls die PHP-Klasse `Normalizer` fehlt. Nicht jede Symcon-PHP-Umgebung bringt sie mit.
- **Die iOS-App bringt die Bilder mit.** `SymDoShoppingList/tools/sync-app-images.sh` spiegelt `assets/` ins App-Bundle (nur kopieren, nie löschen). Ohne den Abgleich driftete die App zuletzt um 220 Bilder und 27 Alias-Schlüssel.

## Fallen

- **Falsches Bild durch eine Kollision im Abgleich der Namen.** `_piResolve` in der Web-App (gespiegelt in der App als `ProductImageLibrary`) arbeitet in Stufen:
  1. exakter Treffer, mit Umlaut- und Pluralvarianten aus `_piCandidates`
  2. Marke als ganzes Wort
  3. **Wortende** (`candidate.endsWith(key)`)
  4. ganzes Wort als Teilstück

  Die Stufen 3 und 4 treffen auch kurze Schlüssel aus Aliasen als Ende fremder deutscher Wörter. So zeigte „Himbeere“ wegen des Bier-Alias `beer` ein Bier, und aus „Limette“ wurde wegen `mett` Hackfleisch. Abhilfe, in dieser Reihenfolge: ein eigenes Bild erzeugen (der exakte Treffer gewinnt vor der Kollision) oder den zu kurzen, mehrdeutigen Alias entfernen (`b7eb171`).
- **Der Backend-Normalizer `NormalizeProductName` streicht Pluralendungen** (`nen`, `en`, `er`, `es`, `se`, `n`, `e`, `s`). Ein Alias „helles“ erzeugt dadurch automatisch den Schlüssel „hell“, und der traf „Balsamessig, hell“. Kurze, vor allem englische Aliase sind in einer deutschen Liste riskant.
- **Transparenz nicht nach Augenschein beurteilen.** Bei Glas und spiegelnden Dingen rendert `gpt-image-1` oft einen Studio-Verlauf in die RGB-Kanäle, der Alphakanal ist trotzdem transparent. Prüfen lässt sich das nur per Composite über eine Farbe. Manche Bildbetrachter zeigen das RGB unter dem Alpha und wirken deshalb „nicht transparent“.
- Neue Bilder brauchen kein Neuladen des Moduls, denn der Ordner wird live gelesen. Laufende Oberflächen zeigen das Bild mit dem nächsten Stand. Prüfen lässt sich das über `SL_GetAppState`: `state.availableImages` (unter `state`, nicht auf oberster Ebene) muss Name und Aliase auf die Datei auflösen.

Stand: geprüft gegen den Code am 08.10.2026
