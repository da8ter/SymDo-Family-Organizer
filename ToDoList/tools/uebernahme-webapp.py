#!/usr/bin/env python3
"""
Uebernimmt SymDoWebApp/module.html als ToDoList-Kachel.

Die Kachel IST die Web-App, wortgleich uebernommen. Damit sie in der Kachel EINER
ToDoList-Instanz statt am Gateway laeuft, aendert dieses Skript genau fuenf Stellen —
dieselben, die die Einkaufslisten-Kachel anpasst. Alles andere bleibt unberuehrt,
damit die naechste Uebernahme wieder wortgleich moeglich ist.

Aufruf aus dem Repo-Wurzelverzeichnis (List/):
    python3 ToDoList/tools/uebernahme-webapp.py
"""

import io
import os
import sys

WEBAPP = 'SymDoWebApp/module.html'
ZIEL = 'ToDoList/module.html'

ADAPTER = '''
/* ── Kachel-Adapter ────────────────────────────────────────────────────────────
   Diese Datei IST die Web-App, wortgleich uebernommen — damit die ToDo-Liste in der
   Kachel genauso aussieht und sich genauso verhaelt. Sie laeuft hier aber in der
   Kachel EINER ToDoList-Instanz statt am Gateway:

   - kein Payload mit mehreren Instanzen, sondern der Zustand dieser einen Liste,
   - kein Call-Relay mit instanceID, sondern requestAction direkt an diese Instanz,
   - keine Dashboard- und Einkaufs-Bereiche.

   Der Adapter setzt genau diese Beruehrungspunkte um; alles darueber bleibt
   unangetastet, damit die naechste Uebernahme aus der Web-App wieder wortgleich
   moeglich ist (siehe ToDoList/tools/uebernahme-webapp.py). Dashboard und Einkauf
   werden NICHT herausgeschnitten, sondern ueber tabs abgeschaltet:
   applyTabVisibility() blendet Knopf und Bereich aus und laesst bei nur einem
   Bereich die Leiste ganz weg. Herausschneiden haette in 6800 Zeilen mit hoher
   Wahrscheinlichkeit Behaltenes mitgerissen.

   AppCall des Moduls delegiert intern an RequestAction — dieselben Aktionsnamen und
   dieselben Nutzlasten. Deshalb genuegt in sdCall ein direkter requestAction. */
const SELF_ID = 'self';

/** Zustand dieser Liste in die Form bringen, die die Web-App erwartet. */
function kachelZustand(daten) {
  return {
    type: 'state',
    // Die Nutzer liefert die Kachel im Zustand mit (GetTileUsers); einen
    // "aktuellen Nutzer" wie in der App gibt es in der Visu nicht.
    users: daten.users || [],
    defaultUserID: '',
    gatewayAvailable: false,
    // Keine KI in der Kachel: sie lief in der Web-App ueber das Gateway.
    aiEnabled: false,
    tabs: { dashboard: false, shopping: false, todos: true },
    hiddenIDs: [],
    instances: [{ id: SELF_ID, kind: 'todo', name: '' }],
    states: { [SELF_ID]: { kind: 'todo', revision: 0, state: daten } },
    images: {},
    brands: {},
    shoppingExtras: {},
  };
}
'''

# (Suchtext, Ersetzung, Beschreibung)
EDITS = [
    (
        "// ── Store: Aggregat aller Listen (Schema siehe module.php BuildFullPayload) ─",
        ADAPTER.strip() + "\n\n// ── Store: Aggregat aller Listen (Schema siehe module.php BuildFullPayload) ─",
        'Adapter eingefuegt',
    ),
    (
        "function sdCall(instanceID, action, payload) {\n"
        "  const txn = 'tx' + (++txnCounter);\n"
        "  requestAction('Call', JSON.stringify({ instanceID, action, payload, txn }));\n"
        "}",
        "function sdCall(instanceID, action, payload) {\n"
        "  // instanceID ist in der Kachel bedeutungslos — es gibt genau diese eine Liste.\n"
        "  // Skalare Nutzlasten gehen unveraendert durch: DeleteItem erwartet im Modul\n"
        "  // einen String, kein Objekt.\n"
        "  requestAction(action, typeof payload === 'string' ? payload : JSON.stringify(payload || {}));\n"
        "}",
        'sdCall auf requestAction',
    ),
    (
        "  const revisions = {};\n"
        "  for (const inst of store.instances) {\n"
        "    if (inst.hidden) continue;\n"
        "    const entry = store.states[String(inst.id)];\n"
        "    revisions[String(inst.id)] = entry ? entry.revision : -1;\n"
        "  }\n"
        "  if (Object.keys(revisions).length > 0) {\n"
        "    requestAction('CheckRevisions', JSON.stringify({ revisions }));\n"
        "  } else {\n"
        "    requestState();\n"
        "  }",
        "  // Kein Revisionsabgleich ueber mehrere Instanzen: die Kachel holt schlicht den\n"
        "  // Zustand ihrer eigenen Liste.\n"
        "  requestState();",
        'checkRevisions vereinfacht',
    ),
    (
        "  if (!data || typeof data !== 'object') return;",
        "  if (!data || typeof data !== 'object') return;\n"
        "  // Die Kachel bekommt den Zustand ihrer Liste flach (items, sortMode, …), die\n"
        "  // Web-App erwartet ihn in store-Form. Erkennungsmerkmal ist items: das Gateway\n"
        "  // schickt an dieser Stelle instances/states.\n"
        "  if (data.type === 'state' && Array.isArray(data.items)) {\n"
        "    data = kachelZustand(data);\n"
        "  }",
        'handleMessage erkennt den flachen Zustand',
    ),
    (
        "  // Visu-Kachel (kein Token): über requestAction relayen; Antwort kommt via handleMessage('aiResult').",
        "  // In DIESER Kachel gibt es kein Gateway: das ToDoList-Modul kennt den Ident\n"
        "  // 'AiCall' nicht und wuerde \"Ungueltiger Ident\" werfen. Deshalb hier still\n"
        "  // scheitern statt zu relayen; die Aufrufer behandeln ein leeres Ergebnis (status 0).\n"
        "  if (!window.__symdoApiPost && !window.__SYMDO__) {\n"
        "    return Promise.resolve({ status: 0, json: null });\n"
        "  }",
        'aiPost scheitert still',
    ),

    # ── Ergaenzung, die es in der Web-App nicht gibt ───────────────────────────
    # recurrenceResetLeadTime: Vorlauf, mit dem eine erledigte Serie wieder geoeffnet
    # wird. Steht hier im Skript und nicht nur in der Datei, damit die naechste
    # Uebernahme sie nicht stillschweigend verliert.
    (
        "        <div id=\"tdLeadSection\" class=\"form-row inline\" style=\"display:none;\">\n"
        "          <label id=\"lblTodoLead\"></label>\n"
        "          <select id=\"tdLeadTime\"></select>\n"
        "        </div>\n"
        "      </div>",
        "        <div id=\"tdLeadSection\" class=\"form-row inline\" style=\"display:none;\">\n"
        "          <label id=\"lblTodoLead\"></label>\n"
        "          <select id=\"tdLeadTime\"></select>\n"
        "        </div>\n"
        "        <!-- Nur in der Kachel: die Web-App kennt recurrenceResetLeadTime nicht. -->\n"
        "        <div id=\"tdReopenSection\" class=\"form-row inline\" style=\"display:none;\">\n"
        "          <label id=\"lblTodoReopen\"></label>\n"
        "          <select id=\"tdReopen\"></select>\n"
        "        </div>\n"
        "      </div>",
        'Wieder-Oeffnen: Markup',
    ),
    (
        "const LEAD_TIMES = [0, 300, 600, 1800, 3600, 18000, 43200];",
        "const LEAD_TIMES = [0, 300, 600, 1800, 3600, 18000, 43200];\n"
        "// Nur in der Kachel: Vorlauf, mit dem eine erledigte Serie wieder geoeffnet wird.\n"
        "const REOPEN_TIMES = [0, 1800, 3600, 21600, 43200, 86400, 172800, 259200, 604800, 1209600, 2592000];\n"
        "function reopenLabel(sec) {\n"
        "  if (sec === 0) return translate('Do not reopen');\n"
        "  if (sec < 3600) return String(sec / 60) + ' ' + translate('minutes before');\n"
        "  if (sec < 86400) return String(sec / 3600) + ' ' + translate('hours before');\n"
        "  return String(sec / 86400) + ' ' + translate('days before');\n"
        "}",
        'Wieder-Oeffnen: Konstanten',
    ),
    (
        "  document.getElementById('tdLeadSection').style.display =\n"
        "    (hasDue && document.getElementById('tdNotification').checked) ? 'flex' : 'none';\n"
        "}",
        "  document.getElementById('tdLeadSection').style.display =\n"
        "    (hasDue && document.getElementById('tdNotification').checked) ? 'flex' : 'none';\n"
        "  // Wieder-Oeffnen ergibt nur bei einer Wiederholung Sinn.\n"
        "  document.getElementById('tdReopenSection').style.display =\n"
        "    (hasDue && document.getElementById('tdRecurrence').value !== 'none') ? 'flex' : 'none';\n"
        "}",
        'Wieder-Oeffnen: Sichtbarkeit',
    ),
    (
        "  document.getElementById('tdNotification').checked = !!(item && item.notification === true);",
        "  const reopenSel = document.getElementById('tdReopen');\n"
        "  reopenSel.innerHTML = REOPEN_TIMES.map(s => `<option value=\"${s}\">${reopenLabel(s)}</option>`).join('');\n"
        "  const reopen = item && item.recurrenceResetLeadTime !== undefined\n"
        "    ? parseInt(item.recurrenceResetLeadTime) : 604800;\n"
        "  reopenSel.value = String(REOPEN_TIMES.includes(reopen) ? reopen : 604800);\n\n"
        "  document.getElementById('tdNotification').checked = !!(item && item.notification === true);",
        'Wieder-Oeffnen: Wert beim Oeffnen',
    ),
    (
        "    fields.notificationLeadTime = parseInt(document.getElementById('tdLeadTime').value) || 0;",
        "    fields.notificationLeadTime = parseInt(document.getElementById('tdLeadTime').value) || 0;\n"
        "    fields.recurrenceResetLeadTime = (fields.recurrence && fields.recurrence !== 'none')\n"
        "      ? (parseInt(document.getElementById('tdReopen').value) || 0) : 0;",
        'Wieder-Oeffnen: Speichern',
    ),
    (
        "  set('lblTodoLead', translate('Lead time'));",
        "  set('lblTodoLead', translate('Lead time'));\n  set('lblTodoReopen', translate('Reopen'));",
        'Wieder-Oeffnen: Beschriftung',
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
          'ToDoList/locale.json uebernehmen und die Kachel gegenpruefen.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
