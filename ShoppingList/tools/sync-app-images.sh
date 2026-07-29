#!/usr/bin/env bash
#
# Spiegelt die Produktbild-Bibliothek des Moduls in das Bundle der iOS-App.
#
# Warum es das Skript gibt: Beide Seiten lösen Artikelnamen über dieselbe Logik
# auf (module.php GetAvailableProductImages ↔ ListsDesign/ProductImageLibrary),
# aber die App liefert ihre Bilder mitgebracht aus. Wurde nur das Modul gepflegt,
# zeigt die App für neue Artikel ein generisches Bild oder gar keins — die Drift
# war zuletzt 220 Bilder und 27 Alias-Schlüssel groß.
#
# Kein Skalieren: die Modul-Assets sind bereits 100×100, genau wie das Bundle.
# Es wird ausschließlich kopiert, nie gelöscht.
#
# Aufruf:  ./sync-app-images.sh [--dry-run] [--app-pfad <Verzeichnis>]

set -euo pipefail

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../assets" && pwd)"
APP_DIR="${SYMDO_APP_IMAGES:-/Users/ssp/Developer/SymDo-iOS/Packages/ListsDesign/Sources/ListsDesign/Resources/ProductImages}"
DRY_RUN=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)  DRY_RUN=1; shift ;;
        --app-pfad) APP_DIR="$2"; shift 2 ;;
        *) echo "Unbekannte Option: $1" >&2; exit 2 ;;
    esac
done

[[ -d "$MODULE_DIR" ]] || { echo "Modul-Assets nicht gefunden: $MODULE_DIR" >&2; exit 1; }
[[ -d "$APP_DIR" ]]    || { echo "App-Bundle nicht gefunden: $APP_DIR" >&2; exit 1; }

echo "Modul: $MODULE_DIR"
echo "App:   $APP_DIR"
[[ $DRY_RUN -eq 1 ]] && echo "(Probelauf — es wird nichts geschrieben)"

# Dateinamen enthalten Umlaute. macOS speichert sie zerlegt (NFD), JSON und Git
# führen sie zusammengesetzt (NFC) — ohne Normalisierung gelten vorhandene
# Dateien fälschlich als fehlend und würden endlos neu kopiert.
python3 - "$MODULE_DIR" "$APP_DIR" "$DRY_RUN" <<'PY'
import os, shutil, sys, unicodedata

module_dir, app_dir, dry = sys.argv[1], sys.argv[2], sys.argv[3] == '1'

def index(directory):
    """NFC-Name → tatsächlicher Dateiname auf der Platte."""
    return {unicodedata.normalize('NFC', f): f
            for f in os.listdir(directory) if f.lower().endswith('.png')}

modul, app = index(module_dir), index(app_dir)
fehlend  = sorted(set(modul) - set(app))
nur_app  = sorted(set(app) - set(modul))

kopiert = 0
for name in fehlend:
    quelle = os.path.join(module_dir, modul[name])
    ziel   = os.path.join(app_dir, modul[name])
    if not dry:
        shutil.copy2(quelle, ziel)
    kopiert += 1

# Auch inhaltlich geänderte Bilder übertragen, nicht nur fehlende. Wird ein
# Bestandsbild ersetzt (gleicher Name, neuer Inhalt), behielt das Bundle sonst
# still die alte Fassung — der Abgleich meldete „0 kopiert" und wirkte erledigt.
aktualisiert = 0
for name in sorted(set(modul) & set(app)):
    quelle = os.path.join(module_dir, modul[name])
    ziel   = os.path.join(app_dir, app[name])
    if os.path.getsize(quelle) == os.path.getsize(ziel):
        # Gleiche Größe: erst dann den Inhalt vergleichen (spart 500 Hashes).
        if open(quelle, 'rb').read() == open(ziel, 'rb').read():
            continue
    if not dry:
        shutil.copy2(quelle, ziel)
    aktualisiert += 1

# Alias-Tabelle 1:1 übernehmen — die App-Datei ist eine Teilmenge, kein Eigenleben.
alias_quelle = os.path.join(module_dir, 'image-aliases.json')
alias_ziel   = os.path.join(app_dir, 'image-aliases.json')
alias_geaendert = False
if os.path.exists(alias_quelle):
    neu = open(alias_quelle, 'rb').read()
    alt = open(alias_ziel, 'rb').read() if os.path.exists(alias_ziel) else b''
    if neu != alt:
        alias_geaendert = True
        if not dry:
            shutil.copy2(alias_quelle, alias_ziel)

print(f"Bilder kopiert: {kopiert}")
print(f"Bilder aktualisiert: {aktualisiert}")
print(f"Alias-Tabelle: {'aktualisiert' if alias_geaendert else 'unverändert'}")
if nur_app:
    # Nicht löschen: könnten App-eigene Ergänzungen sein. Nur melden.
    print(f"WARNUNG: {len(nur_app)} Bilder existieren nur in der App: {nur_app[:10]}")
if not dry:
    print(f"App-Bundle jetzt: {len(index(app_dir))} PNGs")
PY
