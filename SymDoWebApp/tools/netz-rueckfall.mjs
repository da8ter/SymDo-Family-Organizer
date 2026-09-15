#!/usr/bin/env node
/* Prüfstand für den Netz-Rückfall des Web-App-Adapters: zieht fetchEP/apiGet/
   apiPost wörtlich aus SymDoGateway/libs/webapp-adapter.js und fährt sie gegen
   eine Attrappe, die den teuren Fall nachstellt — der Server HAT ausgeführt,
   nur die Antwort ging auf dem abbrechenden WLAN verloren.

   Warum das hier steht: der alte Rückfall wiederholte jeden Abruf über Connect,
   ohne nach der Methode zu fragen. Bei einem Schreibvorgang entstand damit ein
   zweites Objekt — eine zweite Notiz, ein zweiter (bezahlter) KI-Auftrag.
   Gemeldet von einem externen Codereview am 14.09.2026 (F3). Im Browser ist der
   Fall kaum zu treffen: er braucht genau den Moment zwischen Ausführung und
   Antwort.

   Aufruf:  node SymDoWebApp/tools/netz-rueckfall.mjs
*/

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const hier = dirname(fileURLToPath(import.meta.url));
const js = readFileSync(join(hier, '..', '..', 'SymDoGateway', 'libs', 'webapp-adapter.js'), 'utf8');

/** Eine Funktion wörtlich aus der Quelle schneiden (Muster aus ai-auftrag.mjs). */
function schneide(name) {
    const start = js.indexOf(`function ${name}(`);
    if (start < 0) throw new Error(`${name} nicht in webapp-adapter.js gefunden`);
    let i = js.indexOf('{', start), tiefe = 0;
    for (; i < js.length; i++) {
        if (js[i] === '{') tiefe++;
        else if (js[i] === '}' && --tiefe === 0) break;
    }
    return js.slice(start, i + 1);
}

let fehler = 0, anzahl = 0;
function pruefe(name, ist, soll) {
    anzahl++;
    const a = JSON.stringify(ist), b = JSON.stringify(soll);
    const ok = a === b;
    if (!ok) fehler++;
    console.log(`${ok ? 'OK  ' : 'FEHL'} ${name}${ok ? '' : `\n     ist:  ${a}\n     soll: ${b}`}`);
}

/* Die Attrappe: die lokale Basis nimmt die Anfrage an, FÜHRT SIE AUS und wirft
   dann — das ist der verlorene Antwortweg. Connect antwortet normal. */
function baue() {
    const server = [];      // was der Server tatsächlich ausgeführt hat
    const fetchRuf = [];    // was der Browser hinausgeschickt hat
    const fetchAttrappe = (url, opt) => {
        fetchRuf.push(url);
        server.push({ url, method: (opt && opt.method) || 'GET', body: (opt && opt.body) || '' });
        if (url.startsWith('http://lokal')) {
            return Promise.reject(new TypeError('Failed to fetch'));
        }
        return Promise.resolve({
            status: 200,
            json: () => Promise.resolve({ ok: true }),
        });
    };
    const rahmen = new Function('fetch', 'API', 'LOCAL', 'baseHeaders', 'onUnauthorized', 'ensureBase', `
        var EP = LOCAL;
        ${schneide('fetchEP')}
        ${schneide('apiGet')}
        ${schneide('apiPost')}
        return { apiGet: apiGet, apiPost: apiPost, basis: function () { return EP; } };
    `);
    const api = rahmen(fetchAttrappe, 'https://connect', 'http://lokal',
        () => ({ Accept: 'application/json' }), () => {}, () => Promise.resolve());
    return { api, server, fetchRuf };
}

const faelle = [];

// ── 1. Lesen darf wiederholt werden ────────────────────────────────────────
{
    const { api, fetchRuf } = baue();
    faelle.push(api.apiGet('/discovery').then(
        () => {
            pruefe('GET faellt auf Connect zurueck', fetchRuf,
                ['http://lokal/discovery', 'https://connect/discovery']);
            pruefe('… und die Basis steht danach auf Connect', api.basis(), 'https://connect');
        },
        e => pruefe('GET faellt auf Connect zurueck', 'Fehler: ' + e.message, 'zwei Abrufe')));
}

// ── 2. Schreiben OHNE Wiederholungsschutz wird NICHT wiederholt ────────────
{
    const { api, server, fetchRuf } = baue();
    faelle.push(api.apiPost('/notes', { title: 'Zettel' }).then(
        () => pruefe('Notiz ohne Schutz: kein zweiter Versuch', 'kam durch', 'Fehler'),
        () => {
            pruefe('Notiz ohne Schutz: nur EIN Schreibvorgang', server.length, 1);
            pruefe('… kein zweiter Abruf', fetchRuf, ['http://lokal/notes']);
            pruefe('… die Basis steht trotzdem schon auf Connect', api.basis(), 'https://connect');
        }));
}

// ── 3. KI-Auftrag ebenso — ein zweiter kostet Geld ────────────────────────
{
    const { api, server } = baue();
    faelle.push(api.apiPost('/ai/extract', { text: 'Milch' }).then(
        () => pruefe('KI-Auftrag: kein zweiter Versuch', 'kam durch', 'Fehler'),
        () => pruefe('KI-Auftrag: genau ein Anbieterauftrag', server.length, 1)));
}

// ── 4. Listenaktionen behalten ihren Rückfall ─────────────────────────────
/* Dort verhindert die clientActionId serverseitig die zweite Ausführung
   (ApiRouter::ReserveAction) — die Wiederholung ist also sicher UND nötig:
   der Ausgang steht im Postausgang und soll ankommen. */
{
    const { api, server, fetchRuf } = baue();
    faelle.push(api.apiPost('/instances/7/actions', { action: 'toggle', clientActionId: 'a1' }).then(
        () => {
            pruefe('Listenaktion faellt auf Connect zurueck', fetchRuf.length, 2);
            pruefe('… und traegt beide Male dieselbe clientActionId',
                server.every(r => JSON.parse(r.body).clientActionId === 'a1'), true);
        },
        e => pruefe('Listenaktion faellt auf Connect zurueck', 'Fehler: ' + e.message, 'zwei Abrufe')));
}

// ── 5. Eine leere clientActionId ist kein Schutz ──────────────────────────
{
    const { api, server } = baue();
    faelle.push(api.apiPost('/instances/7/actions', { action: 'toggle', clientActionId: '' }).then(
        () => pruefe('Leere clientActionId: kein zweiter Versuch', 'kam durch', 'Fehler'),
        () => pruefe('Leere clientActionId: nur EIN Schreibvorgang', server.length, 1)));
}

// ── 6. Auf Connect selbst wird nie wiederholt ─────────────────────────────
{
    const rahmen = new Function('fetch', 'API', 'LOCAL', 'baseHeaders', 'onUnauthorized', 'ensureBase', `
        var EP = API;
        ${schneide('fetchEP')}
        ${schneide('apiGet')}
        return { apiGet: apiGet };
    `);
    let rufe = 0;
    const api = rahmen(() => { rufe++; return Promise.reject(new TypeError('Failed to fetch')); },
        'https://connect', '', () => ({}), () => {}, () => Promise.resolve());
    faelle.push(api.apiGet('/discovery').then(
        () => pruefe('Connect-Fehler reicht durch', 'kam durch', 'Fehler'),
        () => pruefe('Connect-Fehler: genau ein Versuch', rufe, 1)));
}

await Promise.all(faelle);
console.log(`\n${anzahl} Zusicherungen, ${fehler} Abweichung(en).`);
process.exit(fehler === 0 ? 0 : 1);
