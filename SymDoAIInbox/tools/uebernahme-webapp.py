#!/usr/bin/env python3
"""
Uebernimmt SymDoWebApp als SymDoAIInbox-Kachel (KI-Eingang).

Die Kachel IST die Web-App, wortgleich uebernommen — module.html UND locale.json.
Am Code wird NICHTS geaendert:

  - Die Beschraenkung auf den KI-Bereich kommt aus dem Zustand (tabs in
    SymDoAIInbox/module.php), nicht aus dem HTML.
  - KEIN window.__SYMDO_KACHEL__-Schalter (wie bei ToDo/Einkauf): im
    Listen-Modus sind die KI-Aufrufe tot. Die Vorschlaege reisen ueber das
    AiCall-Relay des Gateways, Aufgaben ueber sdCall → Call an die ToDo-Liste.

Das Skript ist eine Kopie mit Ankerpruefung: bricht die Web-App eine der
Stellen, auf denen die Kachel steht, faellt es HIER auf und nicht erst in der
Visu.

Aufruf aus dem Repo-Wurzelverzeichnis (List/):
    python3 SymDoAIInbox/tools/uebernahme-webapp.py
"""

import io
import json
import os
import sys
from collections import OrderedDict

WEBAPP_HTML = 'SymDoWebApp/module.html'
WEBAPP_LOC  = 'SymDoWebApp/locale.json'
ZIEL_HTML   = 'SymDoAIInbox/module.html'
ZIEL_LOC    = 'SymDoAIInbox/locale.json'

# Stellen, auf denen die Kachel steht. Fehlt eine, hat sich die Web-App
# strukturell geaendert — dann erst pruefen, dann uebernehmen.
ANKER = [
    ('function handleMessage',              'Zustand kommt inline aus GetVisualizationTile'),
    ("requestAction('AiCall'",              'Relay zum Gateway (Vorschlaege, Originale)'),
    ("requestAction('Call'",                'Aufgabe an die ToDo-Liste (sdCall)'),
    ('function visibleTabs',                'Bereichswahl aus tabs'),
    ('t.ki && mailAiAvailable()',           'KI-Bereich haengt am Gateway'),
    ('id="screen-ki"',                      'KI-Bereich im Markup'),
    ('function renderMailAi',               'Vorschlaege zeichnen'),
    ("aiPost('/mail/proposals'",            'Vorschlags-Aktionen'),
]

# Nur die Namen des Moduls selbst — alles andere ist die Web-App. Sie stehen
# HIER und nicht in der Datei, damit die naechste Uebernahme sie nicht
# stillschweigend verliert.
EIGEN = {
    'de': {
        'SymDo AIInbox': 'SymDo - KI-Eingang',
        'Shows the AI inbox of the SymDo app as a tile — the same suggestions, the same view. Mail intake and AI are set up in the SymDo Gateway.':
            'Zeigt den KI-Eingang der SymDo-App als Kachel — dieselben Vorschläge, dieselbe Ansicht. Mail-Eingang und KI richtest du im SymDo Gateway ein.',
        'Gateway: %1$s (#%2$d)': 'Gateway: %1$s (#%2$d)',
        'No SymDo Gateway found — the AI inbox lives there. Create one first.':
            'Kein SymDo Gateway gefunden — dort liegt der KI-Eingang. Erst eines anlegen.',
        'The AI is switched off in the gateway — the inbox stays empty until it is switched on there.':
            'Die KI ist im Gateway ausgeschaltet — der Eingang bleibt leer, bis sie dort eingeschaltet ist.',
        'Adopt as': 'Übernehmen als',
        '— ask nobody —': '— niemand —',
        'Tasks and notes adopted in this tile are filed under this member — a visualization cannot ask who is standing in front of it.':
            'In dieser Kachel übernommene Aufgaben und Notizen gehören diesem Mitglied — eine Visualisierung kann nicht fragen, wer davor steht.',
        'Task list': 'Aufgabenliste',
        'Suggestions adopted as a task go into this list. Leave it empty to choose from all lists.':
            'Als Aufgabe übernommene Vorschläge landen in dieser Liste. Leer lassen, um aus allen Listen zu wählen.',
        'Refresh tile': 'Kachel neu laden',
        'Could not reach the gateway.': 'Das Gateway war nicht erreichbar.',
        'Invalid Ident': 'Ungültiger Ident',
        'Creating': 'Wird erstellt',
        'Active': 'Aktiv',
        'Inactive': 'Inaktiv',
    },
    'en': {
        'SymDo AIInbox': 'SymDo - AI Inbox',
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
