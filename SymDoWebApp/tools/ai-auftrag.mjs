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
    konstante('AI_KACHEL_FRIST_S'),
    'let __aiTxn = 0; const __aiPending = {};',
    schneide('aiPost', 'async'),
    schneide('aiWegMitAuftrag'),
    schneide('aiAuftragAbwarten', 'async'),
    schneide('aiJobSchlafen'),
    schneide('aiWartenMelden'),
    // Der Weg der Visu-Kachel: dieselben Zeilen, die dort laufen.
    schneide('aiPostRoh'),
    schneide('aiKachelAufgeben'),
    schneide('aiKachelWartet'),
    schneide('handleMessage'),
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

/* Für den Kachel-Weg: requestAction schreibt mit, statt zu senden. */
let gesendet = [];
function requestAction(ident, wert) { gesendet.push({ ident, wert: JSON.parse(wert) }); }

/* Eine Schaltuhr, die sich stellen laesst.
   Kurze Wartezeiten laufen echt — der Takt des Nachfragens liegt bei ein bis
   fuenf Sekunden. LANGE (ab zehn Sekunden) laufen nicht ab, sondern werden
   gemerkt: die Frist der Kachel betraegt mindestens 130 Sekunden, und die
   will kein Pruefstand abwarten. `feuere()` loest sie von Hand aus. */
const langeTermine = new Map();
let terminNr = 0;
function fakeSetTimeout(fn, ms) {
    if ((Number(ms) || 0) < 10000) { return globalThis.setTimeout(fn, ms); }
    const nr = 'lang' + (++terminNr);
    langeTermine.set(nr, { fn, ms });
    return nr;
}
function fakeClearTimeout(h) {
    if (typeof h === 'string' && langeTermine.has(h)) { langeTermine.delete(h); return; }
    globalThis.clearTimeout(h);
}
/** Den gemerkten Termin mit der kleinsten Wartezeit auslösen. */
function feuere() {
    let kleinster = null;
    for (const [nr, t] of langeTermine) {
        if (!kleinster || t.ms < kleinster[1].ms) { kleinster = [nr, t]; }
    }
    if (!kleinster) { return null; }
    langeTermine.delete(kleinster[0]);
    kleinster[1].fn();
    return kleinster[1].ms;
}

/* Der Kachel-Zweig steckt in `aiPostRoh` selbst — für die HTTP-Fälle wird er
   durch die Attrappe ersetzt, für die Kachel-Fälle brauchen wir den echten.
   Deshalb zwei Ausgänge aus demselben Block. */
const fn = new Function('aiPostRohAttrappe', 'translate', 'window', 'CustomEvent', 'Date',
    'requestAction', 'setTimeout', 'clearTimeout',
    quelle.replace('function aiPostRoh(path, payload) {',
        'function aiPostRoh(path, payload) {\n  if (window.__attrappe) { return aiPostRohAttrappe(path, payload); }')
    + '\nreturn { aiPost, aiAuftragAbwarten, aiWegMitAuftrag, __aiJobWecker, aiPostRoh, handleMessage, __aiPending };');
const { aiPost, aiWegMitAuftrag, __aiJobWecker, aiPostRoh: aiPostEcht, handleMessage, __aiPending } =
    fn(aiPostRoh, globalThis.translate, fenster, globalThis.CustomEvent, Uhr,
       requestAction, fakeSetTimeout, fakeClearTimeout);
fenster.__attrappe = true;

// ── Prüfrahmen ──────────────────────────────────────────────────────────────
let fehler = 0, anzahl = 0;
function pruefe(name, ist, soll) {
    anzahl++;
    const a = JSON.stringify(ist), b = JSON.stringify(soll);
    const ok = a === b;
    if (!ok) fehler++;
    console.log(`${ok ? 'OK  ' : 'FEHL'} ${name.padEnd(60)}${ok ? '' : `\n     ist:  ${a}\n     soll: ${b}`}`);
}
function neu() { gerufen = []; antworten = []; gemeldet = []; gesendet = []; langeTermine.clear(); uhrSprung = 0; uhrSprungJeAbfrage = 0; }

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
uhrSprungJeAbfrage = 30000;
/* Die Folgeantworten tragen KEIN maxWait — sonst stellte jede von ihnen die
   Frist neu, und der Fall liefe nie ab. Genau das ist gewollt, solange der
   Server meldet: „wartet noch". */
antworten = [
    { status: 202, json: { ok: true, queued: true, id: 'abc', position: 1, pollAfter: 0.001, maxWait: 1 } },
    ...Array(40).fill(() => ({ status: 202, json: { ok: true, queued: true, id: 'abc', position: 1 } })),
];
r = await aiPost('/ai/extract', { image: 'X' });
pruefe('Läuft die Frist ab, kommt ein Fehler statt endlosen Wartens',
    [r.status, r.json.ok, r.json.error.code], [504, false, 'ai_timeout']);

// ── Die Untergrenze der Frist ─────────────────────────────────────────────
/* Der teuerste Fehler dieses Schritts waere gewesen, die Frist zu VERKUERZEN:
   die Schaetzung des Servers lag bei achtzig Sekunden, ein Diktat darf beim
   Anbieter aber hundertzwanzig dauern. Der Aufruf laeuft dann durch, wird
   bezahlt und aufs Tagesbudget gebucht — und niemand holt ihn ab. */
neu();
/* Die Uhr springt je Abfrage um dreissig Sekunden. Mit der Schaetzung des
   Servers (fuenf Sekunden) waere hier sofort Schluss; mit der Untergrenze von
   hundertdreissig reicht es fuer zwei Runden — und in der zweiten kommt das
   Ergebnis. */
uhrSprungJeAbfrage = 30000;
antworten = [
    { status: 202, json: { ok: true, queued: true, id: 'abc', position: 1, pollAfter: 0.001, maxWait: 5 } },
    { status: 202, json: { ok: true, queued: true, id: 'abc', position: 1 } },
    fertig,
];
r = await aiPost('/ai/extract', { image: 'X' });
pruefe('Eine zu knappe Schaetzung verkuerzt die Frist nicht unter die alte Uhr',
    [r.status, (((r.json || {}).todos || [])[0] || {}).title], [200, 'Turnbeutel']);

// ── Ein Fehler des Servers wird durchgereicht ───────────────────────────────
neu();
const abgelehnt = { status: 502, json: { ok: false, error: { code: 'ai_no_credit', message: 'kein Guthaben' } } };
antworten = [wartet(1), abgelehnt];
r = await aiPost('/ai/extract', { image: 'X' });
pruefe('Ein Fehlschlag kommt unverändert an',
    [r.status, r.json.error.code], [502, 'ai_no_credit']);

// ══════════════════════════════════════════════════════════════════════════
// Der Weg der Visu-Kachel
// ══════════════════════════════════════════════════════════════════════════
/* Sie hat keinen Token und kennt nur `requestAction`. Das Gateway entscheidet
   dort, ob der Aufruf als Auftrag hinausgeht — und antwortet dann ZWEIMAL:
   erst „angenommen", später mit dem Ergebnis. Wer beim ersten Mal auflöst,
   zeigt dem Nutzer eine Antwort ohne Inhalt. */
neu();
fenster.__attrappe = false;
delete fenster.__SYMDO__;
globalThis.window = fenster;

let kachelErgebnis = null;
const kachelLauf = aiPostEcht('/ai/extract', { image: 'FOTO' }).then((r) => { kachelErgebnis = r; });
await new Promise((f) => setTimeout(f, 5));
pruefe('Die Kachel schickt ihren Aufruf über requestAction',
    [gesendet.length, gesendet[0].ident, gesendet[0].wert.path], [1, 'AiCall', '/ai/extract']);
const kachelTxn = gesendet[0].wert.txn;

// Erstes AiResult: angenommen.
handleMessage({ type: 'aiResult', txn: kachelTxn, status: 202,
    json: { ok: true, queued: true, id: 'job1', position: 2, pollAfter: 2, maxWait: 40 } });
await new Promise((f) => setTimeout(f, 5));
pruefe('„Angenommen" löst noch nichts auf — der Aufrufer wartet weiter',
    [kachelErgebnis, kachelTxn in __aiPending], [null, true]);
pruefe('Und die Oberfläche erfährt den Platz in der Schlange', gemeldet[0].position, 2);

// Zweites AiResult: das Ergebnis.
handleMessage({ type: 'aiResult', txn: kachelTxn, status: 200,
    json: { ok: true, todos: [{ title: 'Turnbeutel' }] } });
await kachelLauf;
pruefe('Das zweite AiResult löst den Aufruf auf',
    [kachelErgebnis.status, kachelErgebnis.json.todos[0].title], [200, 'Turnbeutel']);
pruefe('Und der Eintrag ist abgeräumt', kachelTxn in __aiPending, false);

// ── Die Rückfall-Nachfrage ────────────────────────────────────────────────
/* Geht das zweite AiResult verloren, fragt die Kachel auf halber Strecke
   einmal selbst nach — unter derselben Vorgangskennung, damit die Antwort
   denselben Aufruf auflöst. */
neu();
let zweiter = null;
const lauf2 = aiPostEcht('/ai/extract', { image: 'X' }).then((r) => { zweiter = r; });
await new Promise((f) => setTimeout(f, 5));
const txn2 = gesendet[0].wert.txn;
handleMessage({ type: 'aiResult', txn: txn2, status: 202,
    json: { ok: true, queued: true, id: 'job2', position: 1, pollAfter: 1, maxWait: 15 } });
/* Die halbe Strecke von mindestens 130 Sekunden wartet kein Pruefstand ab —
   die Schaltuhr loest den Termin aus, statt ihn abzusitzen. */
const wann = feuere();
await new Promise((f) => globalThis.setTimeout(f, 5));
pruefe('Auf halber Strecke fragt die Kachel selbst nach',
    [gesendet.length, gesendet[1].wert.path, gesendet[1].wert.payload.id, gesendet[1].wert.txn],
    [2, '/ai/jobs', 'job2', txn2]);
pruefe('Und zwar nach der halben Frist, die nie unter der alten Uhr liegt',
    wann, 65000);
handleMessage({ type: 'aiResult', txn: txn2, status: 200, json: { ok: true, text: 'gesprochen' } });
await lauf2;
pruefe('Und deren Antwort löst denselben Aufruf auf', zweiter.json.text, 'gesprochen');

// ── Und keine Kachel darf das vergessen ───────────────────────────────────
/* Sieben Kacheln sprechen über dasselbe Relay, und zwei davon haben ihre
   EIGENE Umsetzung (MealPlan; die anderen fünf sind Spiegel dieser Datei).
   Die Essensplan-Kachel hätte das „angenommen" beim ersten Mal aufgelöst und
   dem Nutzer eine Zutatenliste ohne Zutaten gezeigt — es fällt nicht auf,
   weil nichts abstürzt. Deshalb diese Probe über ALLE Kacheln. */
import { readdirSync, existsSync } from 'node:fs';
const wurzel = join(hier, '..', '..');
const ohne = [];
for (const modul of readdirSync(wurzel)) {
    const datei = join(wurzel, modul, 'module.html');
    if (!existsSync(datei)) { continue; }
    const inhalt = readFileSync(datei, 'utf8');
    if (!inhalt.includes("'AiCall'")) { continue; }
    if (!inhalt.includes('queued === true')) { ohne.push(modul); }
}
pruefe('Jede Kachel mit Relay wartet auf die zweite Antwort', ohne, []);

console.log(`\n${anzahl} Zusicherungen, ${fehler} Abweichung(en).`);
process.exit(fehler === 0 ? 0 : 1);
