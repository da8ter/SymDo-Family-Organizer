#!/usr/bin/env python3
"""
Uebernimmt SymDoWebApp als SymDoHomework-Kachel.

Die Kachel IST die Web-App, wortgleich uebernommen — module.html UND locale.json.
Am Code wird NICHTS geaendert:

  - Die Beschraenkung auf den Hausaufgaben-Bereich kommt aus dem Zustand
    (tabs in SymDoHomework/module.php), nicht aus dem HTML. Bei nur einem
    Bereich laesst applyTabVisibility() die Leiste ohnehin weg.
    Herausschneiden waere ohnehin falsch: setTab() laeuft ueber ALLE TABS und
    greift jedes #screen-<t> ungeprueft.
  - aiPost bleibt, wie es ist: die Aufgaben und der Stundenplan liegen im
    Gateway und reisen ueber dessen AiCall-Relay. Genau dieser Weg soll hier
    funktionieren.

Deshalb ist das Skript eine Kopie mit Ankerpruefung: bricht die Web-App eine
der Stellen, auf denen die Kachel steht, faellt es HIER auf und nicht erst in
der Visu.

Die Uebersetzungen kommen mit: Symcon spielt window.translate aus der
locale.json DES MODULS ein. Ohne die Kopie stuenden in der Kachel die
englischen Schluessel.

Aufruf aus dem Repo-Wurzelverzeichnis (List/):
    python3 SymDoHomework/tools/uebernahme-webapp.py
"""

import io
import json
import os
import sys
from collections import OrderedDict

WEBAPP_HTML = 'SymDoWebApp/module.html'
WEBAPP_LOC  = 'SymDoWebApp/locale.json'
ZIEL_HTML   = 'SymDoHomework/module.html'
ZIEL_LOC    = 'SymDoHomework/locale.json'

# Stellen, auf denen die Kachel steht. Fehlt eine, hat sich die Web-App
# strukturell geaendert — dann erst pruefen, dann uebernehmen.
ANKER = [
    ('function handleMessage',                  'Zustand kommt inline aus GetVisualizationTile'),
    ("requestAction('AiCall'",                  'Relay zum Gateway (Aufgaben, Stundenplan)'),
    ('function visibleTabs',                    'Bereichswahl aus tabs'),
    ('t.homework && homeworkMoeglich()',        'Bereich der Hausaufgaben haengt am Gateway'),
    ('id="screen-homework"',                    'Markup, das setTab ungeprueft greift'),
    ('function renderHausaufgabenBereich',      'die Liste des Bereichs'),
    ('function hwListeHtml',                    'die Bloecke — geteilt mit dem Wochenplan'),
    ('function loadTimetable',                  'Faecher, Symbole und Farben kommen aus dem Plan'),
    ("store.tabs.dashboard || store.tabs.homework", 'der Plan wird fuer diese Kachel wirklich geholt'),
    ("aiPost('/homework'",                      'Aufgaben-Aktionen'),
]

# Nur die Namen des Moduls selbst — alles andere ist die Web-App. Sie stehen
# HIER und nicht in der Datei, damit die naechste Uebernahme sie nicht
# stillschweigend verliert.
EIGEN = {
    'de': {
        'SymDo Homework': 'SymDo - Hausaufgaben',
        'Shows the homework of the children as a tile — the same list, the same view as in the app. There is nothing to set up here: the homework lives in the SymDo Gateway.':
            'Zeigt die Hausaufgaben der Kinder als Kachel — dieselbe Liste, dieselbe Ansicht wie in der App. Einzurichten ist hier nichts: die Hausaufgaben liegen im SymDo Gateway.',
        'Homework from WebUntis is shown, not edited: what the school ticked off stays ticked off. Everything entered here can be changed and deleted here.':
            'Hausaufgaben aus WebUntis werden gezeigt, nicht bearbeitet: was die Schule abgehakt hat, bleibt abgehakt. Was hier eingetragen wurde, laesst sich hier auch aendern und loeschen.',
        'Gateway: %1$s (#%2$d)': 'Gateway: %1$s (#%2$d)',
        'No SymDo Gateway found — the homework lives there. Create one first.':
            'Kein SymDo Gateway gefunden — dort liegen die Hausaufgaben. Erst eines anlegen.',
        'Refresh tile': 'Kachel neu laden',
        'Could not reach the gateway.': 'Das Gateway war nicht erreichbar.',
        'Invalid Ident': 'Ungültiger Ident',
        'Creating': 'Wird erstellt',
        'Active': 'Aktiv',
        'Inactive': 'Inaktiv',
    },
    'en': {
        'SymDo Homework': 'SymDo - Homework',
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
