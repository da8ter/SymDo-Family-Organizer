#!/usr/bin/env python3
"""
Uebernimmt SymDoWebApp als SymDoEdumaps-Kachel.

Die Kachel IST die Web-App, wortgleich uebernommen — module.html UND locale.json.
Am Code wird NICHTS geaendert:

  - Die Beschraenkung auf den Bereich der Klassenseiten kommt aus dem Zustand
    (tabs in SymDoEdumaps/module.php), nicht aus dem HTML. Bei nur einem Bereich
    laesst applyTabVisibility() die Leiste ohnehin weg. Herausschneiden waere
    ohnehin falsch: setTab() laeuft ueber ALLE TABS und greift jedes
    #screen-<t> ungeprueft.
  - aiPost bleibt, wie es ist: die Karten liegen im Gateway und reisen ueber
    dessen AiCall-Relay. Genau dieser Weg soll hier funktionieren.

Deshalb ist das Skript eine Kopie mit Ankerpruefung: bricht die Web-App eine
der Stellen, auf denen die Kachel steht, faellt es HIER auf und nicht erst in
der Visu.

Die Uebersetzungen kommen mit: Symcon spielt window.translate aus der
locale.json DES MODULS ein. Ohne die Kopie stuenden in der Kachel die
englischen Schluessel.

Aufruf aus dem Repo-Wurzelverzeichnis (List/):
    python3 SymDoEdumaps/tools/uebernahme-webapp.py
"""

import io
import json
import os
import sys
from collections import OrderedDict

WEBAPP_HTML = 'SymDoWebApp/module.html'
WEBAPP_LOC  = 'SymDoWebApp/locale.json'
ZIEL_HTML   = 'SymDoEdumaps/module.html'
ZIEL_LOC    = 'SymDoEdumaps/locale.json'

# Stellen, auf denen die Kachel steht. Fehlt eine, hat sich die Web-App
# strukturell geaendert — dann erst pruefen, dann uebernehmen.
ANKER = [
    ('function handleMessage',              'Zustand kommt inline aus GetVisualizationTile'),
    ("requestAction('AiCall'",              'Relay zum Gateway (Karten, Anhaenge)'),
    ('function visibleTabs',                'Bereichswahl aus tabs'),
    ('t.edumaps && edumapsMoeglich()',      'Bereich der Klassenseiten haengt am Gateway'),
    ('id="screen-edumaps"',                 'Markup, das setTab ungeprueft greift'),
    ('function renderEdumaps',              'die Kartenansicht des Bereichs'),
    ('function notesBilderNachladen',       'Bilder ohne Token nachladen'),
    ("aiPost('/edumaps'",                   'Karten-Aktionen'),
]

# Nur die Namen des Moduls selbst — alles andere ist die Web-App. Sie stehen
# HIER und nicht in der Datei, damit die naechste Uebernahme sie nicht
# stillschweigend verliert.
EIGEN = {
    'de': {
        # Der Anzeigename nennt die SACHE zuerst und den Anbieter in Klammern:
        # der Inhalt heisst ueberall „Klassenseiten" (Formular, Bereich der
        # Web-App, Anleitung), und „Edumaps" bleibt darin auffindbar.
        'SymDo Edumaps': 'SymDo - Klassenseiten (Edumaps)',
        'Shows the class pages of the school as a tile — the same cards, the same view as in the app. There is nothing to set up here: the pages are entered in the SymDo Gateway.':
            'Zeigt die Klassenseiten der Schule als Kachel — dieselben Karten, dieselbe Ansicht wie in der App. Einzurichten ist hier nichts: die Seiten werden im SymDo Gateway eingetragen.',
        'Read only: the cards are a mirror of the class page. Renaming a page and deleting it (which blocks it) work; writing does not.':
            'Nur lesend: die Karten sind ein Spiegel der Klassenseite. Eine Seite umbenennen und loeschen (womit sie gesperrt wird) geht, schreiben nicht.',
        'Gateway: %1$s (#%2$d)': 'Gateway: %1$s (#%2$d)',
        'No SymDo Gateway found — the class pages live there. Create one first.':
            'Kein SymDo Gateway gefunden — dort liegen die Klassenseiten. Erst eines anlegen.',
        'Refresh tile': 'Kachel neu laden',
        'Could not reach the gateway.': 'Das Gateway war nicht erreichbar.',
        'Invalid Ident': 'Ungültiger Ident',
        'Creating': 'Wird erstellt',
        'Active': 'Aktiv',
        'Inactive': 'Inaktiv',
    },
    'en': {
        'SymDo Edumaps': 'SymDo - Class pages (Edumaps)',
    },
}


def main() -> int:
    if not os.path.exists(WEBAPP_HTML) or not os.path.exists(WEBAPP_LOC):
        print(f'FEHLER: {WEBAPP_HTML} nicht gefunden — aus List/ heraus aufrufen.', file=sys.stderr)
        return 1

    html = io.open(WEBAPP_HTML, encoding='utf-8').read()
    for such, was in ANKER:
        if such not in html:
            print(f'FEHLER: Ankerstelle fehlt ({was}): {such!r}\n'
                  f'        Die Web-App hat sich geaendert — pruefen, bevor uebernommen wird.',
                  file=sys.stderr)
            return 1
        print(f'  ok  {was}')

    io.open(ZIEL_HTML, 'w', encoding='utf-8').write(html)
    print(f'\n{ZIEL_HTML} geschrieben ({len(html.splitlines())} Zeilen, wortgleich).')

    loc = json.load(io.open(WEBAPP_LOC, encoding='utf-8'), object_pairs_hook=OrderedDict)
    tr = loc.get('translations', {})
    for sprache, eintraege in EIGEN.items():
        block = tr.setdefault(sprache, OrderedDict())
        # Der Name der Web-App hat in diesem Modul nichts zu suchen.
        block.pop('SymDo Web App', None)
        for schluessel, wert in eintraege.items():
            block[schluessel] = wert
    with io.open(ZIEL_LOC, 'w', encoding='utf-8') as f:
        json.dump(loc, f, ensure_ascii=False, indent=2)
        f.write('\n')
    print(f'{ZIEL_LOC} geschrieben ({sum(len(b) for b in tr.values())} Einträge).')
    print('\nDanach: MC_ReloadModule(<Bibliothek>, "List") und die Kachel gegenpruefen.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
