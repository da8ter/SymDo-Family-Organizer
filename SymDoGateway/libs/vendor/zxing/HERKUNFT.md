# ZXing-Port (QR-Decoder) — Herkunft und Änderungen

Fremder Code. Diese Datei sagt, woher er kommt und was daran geändert wurde,
damit eine spätere Aktualisierung nachvollziehbar bleibt.

## Quelle

- Projekt: `khanamiryan/php-qrcode-detector-decoder`
- Adresse: https://github.com/khanamiryan/php-qrcode-detector-decoder
- Fassung: **2.0.3** (veröffentlicht 2025-07-10, Commit `17c570b`)
- Übernommen am: 2026-09-06
- Lizenz: MIT **und** Apache-2.0 (beide Dateien liegen daneben:
  `LICENSE-MIT`, `LICENSE-ASL-2.0`)
- Übernommen wurde ausschließlich das Verzeichnis `lib/` — ohne Tests,
  `composer.json`, `psalm.xml`, `rector.php` und `ecs.php`.

## Warum überhaupt

Ein QR-Decoder ist nichts, was man nebenbei selbst schreibt: Finder-Pattern
suchen, Perspektive entzerren, Raster abtasten und Reed-Solomon rechnen. Der
QR-**Generator** im selben Verzeichnis (`../qrcode.php`) ist aus demselben Grund
übernommen und nicht selbst gebaut.

Gebraucht wird er für die Anhänge der Klassenseiten: steckt in einem Bild ein
QR-Code, soll seine Adresse als Verweis unter dem Bild in der Notiz stehen.

## Die eine Änderung: Namensraum

Alle Klassen liegen jetzt unter **`da8ter\SymDo\Zxing`** statt unter `Zxing`.

Der Grund ist Symcon: alle Module laufen in EINEM PHP-Prozess. Vendort ein
zweites Modul dieselbe Bibliothek, träfen zwei gleichnamige Klassen aufeinander
und PHP bräche mit einem Fatal ab — und zwar nicht in unserem Modul, sondern
irgendwo. Mit eigenem Präfix kann das nicht passieren.

Umgesetzt mit einer Ersetzung über alle 50 Dateien:
`Zxing\` → `da8ter\SymDo\Zxing\` (Namensraum-Erklärungen, `use`-Zeilen und
vollqualifizierte Verweise). Gegengeprüft: keine nackten `Zxing\`-Verweise mehr.

**Sonst ist nichts geändert.** Bei einer Aktualisierung also: neue Fassung
auspacken, dieselbe Ersetzung laufen lassen, diese Datei fortschreiben.

## Zwei Dinge, die man wissen muss

1. **`Common/customFunctions.php` legt GLOBALE Funktionen an** — `arraycopy`,
   `hashCode`, `intval32Bits`, `uRShift`. Alle sind mit `function_exists`
   abgesichert, ein Fatal droht also nicht. Definiert ein anderes Modul aber
   eine dieser sehr allgemein benannten Funktionen zuerst, benutzt ZXing
   **dessen** Fassung. Bisher kein Problem, aber der Grund, warum die Datei
   bewusst nur an einer Stelle geladen wird.
2. **Die Bibliothek gibt PHP-WARNUNGEN aus**, wenn eine Datei fehlt oder kein
   Bild ist (`file_get_contents`, `imagecreatefromstring`). Eine Warnung in
   einem Hook landet mitten in der HTTP-Antwort und zerlegt sie. Der Aufrufer
   muss deshalb `@` verwenden — siehe `EduMaps.php`, `EduQrCode()`.

## Gemessen am 06.09.2026

An den echten Anhängen dieser Installation, gegengeprüft mit dem QR-Decoder von
macOS (Vision) als unabhängigem Messgerät:

| Bild | Ergebnis |
|---|---|
| erzeugter Test-QR (296×296) | gelesen, 41 ms |
| `symdo_note_15674.jpg` (500×500, Anhang) | gelesen, 64 ms |
| `symdo_note_36148.jpg` (175×255, PDF-Vorschau) | gelesen, 15 ms |
| 15 Bilder ohne Code | 15× nichts erkannt, keine Fehlalarme, 13–322 ms |

Beide Adressen stimmen zeichengenau mit dem Referenz-Decoder überein.
