#!/usr/bin/env python3
"""
Uebernimmt SymDoWebApp als SymDoNotes-Kachel.

Die Kachel IST die Web-App, wortgleich uebernommen — module.html UND locale.json.
Anders als bei der ToDo-Kachel wird am Code NICHTS geaendert:

  - Die Beschraenkung auf den Notizbereich kommt aus dem Zustand (tabs in
    SymDoNotes/module.php), nicht aus dem HTML. Bei nur einem Bereich laesst
    applyTabVisibility() die Leiste ohnehin weg.
  - aiPost bleibt, wie es ist: die Notizen liegen im Gateway und reisen ueber
    dessen AiCall-Relay. Genau dieser Weg soll hier funktionieren.

Deshalb ist das Skript eine Kopie mit Ankerpruefung: bricht die Web-App eine
der Stellen, auf denen die Kachel steht, faellt es HIER auf und nicht erst in
der Visu.

Die Uebersetzungen kommen mit: Symcon spielt window.translate aus der
locale.json DES MODULS ein. Ohne die Kopie stuenden in der Kachel die
englischen Schluessel.

Aufruf aus dem Repo-Wurzelverzeichnis (List/):
    python3 SymDoNotes/tools/uebernahme-webapp.py
"""

import io
import json
import os
import sys
from collections import OrderedDict

WEBAPP_HTML = 'SymDoWebApp/module.html'
WEBAPP_LOC  = 'SymDoWebApp/locale.json'
ZIEL_HTML   = 'SymDoNotes/module.html'
ZIEL_LOC    = 'SymDoNotes/locale.json'

# Stellen, auf denen die Kachel steht. Fehlt eine, hat sich die Web-App
# strukturell geaendert — dann erst pruefen, dann uebernehmen.
ANKER = [
    ('function handleMessage',              'Zustand kommt inline aus GetVisualizationTile'),
    ("requestAction('AiCall'",              'Relay zum Gateway (Notizen, Anhaenge)'),
    ('function visibleTabs',                'Bereichswahl aus tabs'),
    ('t.notes && notizenMoeglich()',        'Notizbereich haengt am Gateway'),
    ('function notesBilderNachladen',       'Bilder ohne Token nachladen'),
    ("aiPost('/notes'",                     'Notiz-Aktionen'),
]

# Nur die Namen des Moduls selbst — alles andere ist die Web-App. Sie stehen
# HIER und nicht in der Datei, damit die naechste Uebernahme sie nicht
# stillschweigend verliert.
EIGEN = {
    'de': {
        'SymDo Notes': 'SymDo - Notizen',
        'Shows the notes of the SymDo app as a tile — the same notes, the same view. There is nothing to set up: the notes come from the SymDo Gateway.':
            'Zeigt die Notizen der SymDo-App als Kachel — dieselben Notizen, dieselbe Ansicht. Einzurichten ist nichts: die Notizen kommen aus dem SymDo Gateway.',
        'Gateway: %1$s (#%2$d)': 'Gateway: %1$s (#%2$d)',
        'No SymDo Gateway found — the notes live there. Create one first.':
            'Kein SymDo Gateway gefunden — dort liegen die Notizen. Erst eines anlegen.',
        'Write as': 'Schreiben als',
        '— ask nobody —': '— niemand —',
        'A note written in this tile is filed under this member — a visualization cannot ask who is standing in front of it.':
            'Eine in dieser Kachel geschriebene Notiz gehört diesem Mitglied — eine Visualisierung kann nicht fragen, wer davor steht.',
        'Refresh tile': 'Kachel neu laden',
        'Could not reach the gateway.': 'Das Gateway war nicht erreichbar.',
        'Invalid Ident': 'Ungültiger Ident',
        'Creating': 'Wird erstellt',
        'Active': 'Aktiv',
        'Inactive': 'Inaktiv',
    },
    'en': {
        'SymDo Notes': 'SymDo - Notes',
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
