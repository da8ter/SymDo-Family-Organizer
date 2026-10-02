#!/usr/bin/env python3
"""
Uebernimmt SymDoWebApp als SymDoBriefing-Kachel (Tagesbriefing).

Die Kachel IST die Web-App, wortgleich uebernommen — module.html UND locale.json.
Am Code wird NICHTS geaendert:

  - Die Beschraenkung auf die Briefing-Karte kommt aus dem Zustand (tabs und
    nurBriefing in SymDoBriefing/module.php), nicht aus dem HTML.
  - KEIN window.__SYMDO_KACHEL__-Schalter (wie bei ToDo/Einkauf): im
    Listen-Modus sind die Relay-Aufrufe tot. Text und Ton reisen ueber das
    AiCall-Relay des Gateways.

Das Skript ist eine Kopie mit Ankerpruefung: bricht die Web-App eine der
Stellen, auf denen die Kachel steht, faellt es HIER auf und nicht erst in der
Visu.

Aufruf aus dem Repo-Wurzelverzeichnis (List/):
    python3 SymDoBriefing/tools/uebernahme-webapp.py
"""

import io
import json
import os
import sys
from collections import OrderedDict

WEBAPP_HTML = 'SymDoWebApp/module.html'
WEBAPP_LOC  = 'SymDoWebApp/locale.json'
ZIEL_HTML   = 'SymDoBriefing/module.html'
ZIEL_LOC    = 'SymDoBriefing/locale.json'

# Stellen, auf denen die Kachel steht. Fehlt eine, hat sich die Web-App
# strukturell geaendert — dann erst pruefen, dann uebernehmen.
ANKER = [
    ('function handleMessage',              'Zustand kommt inline aus GetVisualizationTile'),
    ("requestAction('AiCall'",              'Relay zum Gateway (Briefing, Ton)'),
    ('store.nurBriefing = data.nurBriefing === true', 'Schalter aus dem Zustand'),
    ('function briefingAllein',             'Nur die Briefing-Karte zeichnen'),
    ('function briefingVorlesen',           'Vorlesen'),
    ("data.type === 'briefingPlay'",        'Fernstart SDBR_PlayBriefing'),
    ("aiPost('/briefing'",                  'Briefing holen'),
    ("aiPost('/ttsclip'",                   'Tonschnipsel ohne Token'),
]

# Nur die Namen des Moduls selbst — alles andere ist die Web-App. Sie stehen
# HIER und nicht in der Datei, damit die naechste Uebernahme sie nicht
# stillschweigend verliert.
EIGEN = {
    'de': {
        'SymDo Briefing': 'SymDo - Briefing',
        'Shows the daily briefing of the SymDo app as a tile, with playback. Content, time, tone and voice are set up in the SymDo Gateway.':
            'Zeigt das Tagesbriefing der SymDo-App als Kachel, mit Wiedergabe. Inhalt, Uhrzeit, Ton und Stimme richtest du im SymDo Gateway ein.',
        'Gateway: %1$s (#%2$d)': 'Gateway: %1$s (#%2$d)',
        'No SymDo Gateway found — the briefing is written there. Create one first.':
            'Kein SymDo Gateway gefunden — dort entsteht das Briefing. Erst eines anlegen.',
        'Play from a script or event: SDBR_PlayBriefing(%d);':
            'Abspielen aus einem Skript oder Ereignis: SDBR_PlayBriefing(%d);',
        'Play briefing': 'Briefing abspielen',
        'Refresh tile': 'Kachel neu laden',
        'Could not reach the gateway.': 'Das Gateway war nicht erreichbar.',
        'Invalid Ident': 'Ungültiger Ident',
        'Creating': 'Wird erstellt',
        'Active': 'Aktiv',
        'Inactive': 'Inaktiv',
    },
    'en': {
        'SymDo Briefing': 'SymDo - Briefing',
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
