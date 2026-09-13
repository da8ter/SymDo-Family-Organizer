#!/usr/bin/env node
/* Prüfstand für das Warten auf einen KI-Auftrag: zieht aiPost/aiAuftragAbwarten/
   aiJobSchlafen wörtlich aus module.html und fährt sie gegen eine Attrappe des
   Servers.

   Warum das hier steht und nicht im Browser: an diesen sechzig Zeilen hängt,
   ob der Nutzer sein Ergebnis sieht oder ein „darin war nichts zu finden".
   Ein KI-Aufruf dauert bis zu 45 Sekunden — die Wege, die dabei schiefgehen
   können, sind im Browser kaum zu treffen: ein alter Server ohne Auftragsweg,
   eine Klingel, die früher kommt als der Takt, eine Frist, die abläuft.

   Aufruf:  node tools/ai-auftrag.mjs
*/

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const hier = dirname(fileURLToPath(import.meta.url));
const html = readFileSync(join(hier, '..', 'module.html'), 'utf8');

// ── Funktionen wörtlich aus module.html schneiden ───────────────────────────
function schneide(name, art = 'function') {
    const marke = art === 'async' ? `async function ${name}(` : `function ${name}(`;
    const start = html.indexOf(marke);
    if (start < 0) throw new Error(`${name} nicht in module.html gefunden`);
    let i = html.indexOf('{', start), tiefe = 0;
    for (; i < html.length; i++) {
        if (html[i] === '{') tiefe++;
        else if (html[i] === '}' && --tiefe === 0) break;
    }
    return html.slice(start, i + 1);
}
function konstante(name) {
    const start = html.indexOf(`const ${name} =`);
    if (start < 0) throw new Error(`${name} nicht in module.html gefunden`);
    const ende = html.indexOf(';', start);
    return html.slice(start, ende + 1);
}

const quelle = [
    konstante('AI_AUFTRAG_WEGE'),
    konstante('__aiJobWecker'),
    schneide('aiPost', 'async'),
    schneide('aiWegMitAuftrag'),
    schneide('aiAuftragAbwarten', 'async'),
    schneide('aiJobSchlafen'),
    schneide('aiWartenMelden'),
].join('\n');

// ── Umgebung: ein Fenster, eine Übersetzung, ein Server ─────────────────────
let gerufen = [];
let antworten = [];
let gemeldet = [];

const fenster = {
    __SYMDO__: { apiBase: '/hook/lists/app/v1' },
    dispatchEvent(ev) { gemeldet.push(ev && ev.detail); return true; },
};
globalThis.window = fenster;
globalThis.CustomEvent = class { constructor(name, init) { this.type = name; this.detail = init && init.detail; } };
globalThis.translate = (s) => s;

/** Die Attrappe des Servers: gibt der Reihe nach zurück, was gebraucht wird. */
function aiPostRoh(path, payload) {
    gerufen.push({ path, payload });
    const naechste = antworten.shift();
    return Promise.resolve(typeof naechste === 'function' ? naechste() : naechste);
}
globalThis.aiPostRoh = aiPostRoh;

/* Eine stellbare Uhr. Der Frist-Fall wartet sonst die eingebaute Untergrenze
   von fuenfzehn Sekunden ab — echte fuenfzehn Sekunden, in jedem Lauf. Die
   Uhr wird als Parameter hereingereicht und verdeckt damit die globale. */
let uhrSprung = 0;
let uhrSprungJeAbfrage = 0;
const echteNow = Date.now.bind(Date);
class Uhr extends Date {
    static now() {
        uhrSprung += uhrSprungJeAbfrage;
        return echteNow() + uhrSprung;
    }
}

const fn = new Function('aiPostRoh', 'translate', 'window', 'CustomEvent', 'Date',
    quelle + '\nreturn { aiPost, aiAuftragAbwarten, aiWegMitAuftrag, __aiJobWecker };');
const { aiPost, aiWegMitAuftrag, __aiJobWecker } = fn(aiPostRoh, globalThis.translate, fenster, globalThis.CustomEvent, Uhr);

// ── Prüfrahmen ──────────────────────────────────────────────────────────────
let fehler = 0, anzahl = 0;
function pruefe(name, ist, soll) {
    anzahl++;
    const a = JSON.stringify(ist), b = JSON.stringify(soll);
    const ok = a === b;
    if (!ok) fehler++;
    console.log(`${ok ? 'OK  ' : 'FEHL'} ${name.padEnd(60)}${ok ? '' : `\n     ist:  ${a}\n     soll: ${b}`}`);
}
function neu() { gerufen = []; antworten = []; gemeldet = []; uhrSprung = 0; uhrSprungJeAbfrage = 0; }

const fertig = { status: 200, json: { ok: true, todos: [{ title: 'Turnbeutel' }], id: 'abc' } };
const wartet = (pos = 1) => ({ status: 202, json: { ok: true, queued: true, id: 'abc',
    state: 'queued', position: pos, pollAfter: 0.001, maxWait: 30 } });

// ── Ein Weg ohne Auftragsmöglichkeit bleibt unberührt ───────────────────────
neu();
antworten = [fertig];
let r = await aiPost('/notes', { action: 'list' });
pruefe('Ein gewöhnlicher Aufruf bekommt kein async angehängt',
    [gerufen[0].path, 'async' in gerufen[0].payload, r.status], ['/notes', false, 200]);

/* Die Visu-Kachel relayt über requestAction und kann keine Aufträge stellen —
   dort bleibt alles synchron, bis auch das Relay umgestellt ist. */
neu();
delete fenster.__SYMDO__;
pruefe('Ohne HTTP-Weg gibt es keinen Auftragsweg', aiWegMitAuftrag(), false);
antworten = [fertig];
r = await aiPost('/ai/extract', { text: 'x' });
pruefe('Und der KI-Aufruf läuft dort unverändert',
    ['async' in gerufen[0].payload, r.status], [false, 200]);
fenster.__SYMDO__ = { apiBase: '/hook/lists/app/v1' };

// ── Ein alter Server antwortet direkt ───────────────────────────────────────
neu();
antworten = [fertig];
r = await aiPost('/ai/extract', { text: 'x' });
pruefe('Ein alter Server antwortet direkt — die Antwort geht unverändert durch',
    [gerufen.length, gerufen[0].payload.async, r.json.todos[0].title], [1, true, 'Turnbeutel']);

// ── Der gewöhnliche Weg: einreihen, nachfragen, Ergebnis ────────────────────
neu();
antworten = [wartet(1), { status: 202, json: { ok: true, queued: true, id: 'abc', position: 1 } }, fertig];
r = await aiPost('/ai/extract', { image: 'FOTO' });
pruefe('Der Auftrag wird eingereiht und danach abgeholt',
    [gerufen[0].path, gerufen[0].payload.async, gerufen[1].path, gerufen[1].payload,
     gerufen[2].path, r.status, r.json.todos[0].title],
    ['/ai/extract', true, '/ai/jobs', { id: 'abc' }, '/ai/jobs', 200, 'Turnbeutel']);
/* Die Rückgabe hat dieselbe Form wie immer: {status, json}. Genau deshalb
   ändert sich an runAiExtract, runAiIngredients und diktatFertig nichts. */
pruefe('Und behält die Form, die alle Aufrufer kennen',
    Object.keys(r).sort(), ['json', 'status']);

// ── Der Fortschritt wird gemeldet ───────────────────────────────────────────
neu();
antworten = [wartet(3), fertig];
await aiPost('/ai/extract', { image: 'X' });
pruefe('Die Oberfläche erfährt, der wievielte man ist',
    [gemeldet[0].position, gemeldet[gemeldet.length - 1]], [3, null]);

// ── Die Klingel ist schneller als der Takt ──────────────────────────────────
neu();
/* Der Takt steht hier auf zwanzig Sekunden. Kommt die Meldung „fertig" über
   die Klingel, darf NICHT so lange gewartet werden — sonst wäre der
   WebSocket wertlos. */
antworten = [
    { status: 202, json: { ok: true, queued: true, id: 'abc', position: 1, pollAfter: 20, maxWait: 60 } },
    fertig,
];
const los = Date.now();
const lauf = aiPost('/ai/extract', { image: 'X' });
await new Promise((f) => setTimeout(f, 20));
pruefe('Der Wecker steht bereit', typeof __aiJobWecker['abc'], 'function');
__aiJobWecker['abc']();
r = await lauf;
pruefe('Die Klingel holt das Ergebnis sofort, statt den Takt abzuwarten',
    [r.status, Date.now() - los < 2000], [200, true]);
pruefe('Und der Wecker ist danach abgeräumt', 'abc' in __aiJobWecker, false);

// ── Die Frist läuft ab ──────────────────────────────────────────────────────
neu();
/* Die Uhr springt bei jeder Abfrage um zehn Sekunden — so ist die
   Fuenfzehn-Sekunden-Untergrenze nach zwei Runden erreicht, statt nach
   fuenfzehn echten Sekunden. */
uhrSprungJeAbfrage = 10000;
antworten = [
    { status: 202, json: { ok: true, queued: true, id: 'abc', position: 1, pollAfter: 0.001, maxWait: 1 } },
    ...Array(20).fill(() => ({ status: 202, json: { ok: true, queued: true, id: 'abc', position: 1 } })),
];
r = await aiPost('/ai/extract', { image: 'X' });
pruefe('Läuft die Frist ab, kommt ein Fehler statt endlosen Wartens',
    [r.status, r.json.ok, r.json.error.code], [504, false, 'ai_timeout']);

// ── Ein Fehler des Servers wird durchgereicht ───────────────────────────────
neu();
const abgelehnt = { status: 502, json: { ok: false, error: { code: 'ai_no_credit', message: 'kein Guthaben' } } };
antworten = [wartet(1), abgelehnt];
r = await aiPost('/ai/extract', { image: 'X' });
pruefe('Ein Fehlschlag kommt unverändert an',
    [r.status, r.json.error.code], [502, 'ai_no_credit']);

console.log(`\n${anzahl} Zusicherungen, ${fehler} Abweichung(en).`);
process.exit(fehler === 0 ? 0 : 1);
