#!/usr/bin/env python3
"""
Uebernimmt SymDoWebApp/module.html als ToDo-Kachel.

Die Kachel IST die Web-App, wortgleich uebernommen. Was die Kachel einer einzelnen
ToDoList-Instanz anders macht (Zustand einer Liste statt mehrerer Instanzen,
requestAction direkt statt Call-Relay, nur der eigene Bereich, keine KI), steckt als
Kachel-Modus in der Web-App selbst (KACHEL in SymDoWebApp/module.html). Dieses Skript
setzt nur den Schalter window.__SYMDO_KACHEL__ = { art: 'todo' } vor das App-Skript.

Aufruf aus dem Repo-Wurzelverzeichnis (List/):
    python3 SymDoToDoList/tools/uebernahme-webapp.py
"""

import io
import os
import sys

WEBAPP = 'SymDoWebApp/module.html'
ZIEL = 'SymDoToDoList/module.html'

# Der Kachel-Modus steckt als Schalter in der Web-App selbst (KACHEL in SymDoWebApp/module.html).
# Hier wird nur noch der Schalter vor das App-Skript gesetzt; alles andere bleibt wortgleich.
# (Suchtext, Ersetzung, Beschreibung)
EDITS = [
    (
        "<script>\n// In einer IIFE gekapselt",
        "<script>window.__SYMDO_KACHEL__ = { art: 'todo' };</script>\n<script>\n// In einer IIFE gekapselt",
        'Kachel-Schalter gesetzt',
    ),
]


def main() -> int:
    if not os.path.exists(WEBAPP):
        print(f'FEHLER: {WEBAPP} nicht gefunden — aus List/ heraus aufrufen.', file=sys.stderr)
        return 1

    html = io.open(WEBAPP, encoding='utf-8').read()
    for such, ersatz, was in EDITS:
        if such not in html:
            print(f'FEHLER: Ankerstelle fehlt ({was}). Die Web-App hat sich geaendert —\n'
                  f'        das Skript muss angepasst werden, bevor uebernommen wird.', file=sys.stderr)
            return 1
        if html.count(such) != 1:
            print(f'FEHLER: Ankerstelle {html.count(such)}x vorhanden ({was}) — nicht eindeutig.', file=sys.stderr)
            return 1
        html = html.replace(such, ersatz)
        print(f'  ok  {was}')

    io.open(ZIEL, 'w', encoding='utf-8').write(html)
    print(f'\n{ZIEL} geschrieben ({len(html.splitlines())} Zeilen).')
    print('Danach: fehlende Uebersetzungen aus SymDoWebApp/locale.json nach '
          'SymDoToDoList/locale.json uebernehmen und die Kachel gegenpruefen.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
