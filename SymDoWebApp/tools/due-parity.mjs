#!/usr/bin/env node
/* Prüfstand für die Fälligkeits-Leiter: zieht dueDateOf/dayDiff/formatDueChip
   wörtlich aus module.html und prüft dieselben Goldwerte, die auf der
   iOS-Seite in DueBadgeTextTests stehen (ListsDomain, SymDo-iOS). Wer hier
   Goldwerte ändert, zieht die Swift-Tests nach — und umgekehrt.

   Aufruf:  node tools/due-parity.mjs        (Anker: 06.09.2026 12:00 Berlin)
   Der dueDay-Fall (Gerät westlich des Servers) läuft als Unterprozess mit
   TZ=America/New_York, weil die Zeitzone je Prozess feststeht. */

import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const hier = dirname(fileURLToPath(import.meta.url));
const nyLauf = process.argv.includes('--ny');
if (!nyLauf) process.env.TZ = 'Europe/Berlin';

// ── Funktionen wörtlich aus module.html schneiden ───────────────────────────
const html = readFileSync(join(hier, '..', 'module.html'), 'utf8');
function schneide(name) {
    const start = html.indexOf(`function ${name}(`);
    if (start < 0) throw new Error(`${name} nicht in module.html gefunden`);
    let i = html.indexOf('{', start), tiefe = 0;
    for (; i < html.length; i++) {
        if (html[i] === '{') tiefe++;
        else if (html[i] === '}' && --tiefe === 0) break;
    }
    return html.slice(start, i + 1);
}
const quelle = ['dueDateOf', 'dayDiff', 'formatDueChip'].map(schneide).join('\n');

// ── Feste Uhr und festes Deutsch, damit die Goldwerte maschinenfest sind ────
const NOW_MS = nyLauf
    ? Date.UTC(2026, 8, 6, 10, 0)          // 12:00 Berlin = 10:00 UTC = 06:00 New York
    : new Date(2026, 8, 6, 12, 0).getTime();
class FesteUhr extends Date {
    constructor(...a) { a.length ? super(...a) : super(NOW_MS); }
    static now() { return NOW_MS; }
    toLocaleTimeString(l, o) { return super.toLocaleTimeString(l ?? 'de-DE', o); }
    toLocaleDateString(l, o) { return super.toLocaleDateString(l ?? 'de-DE', o); }
}
const FestesIntl = {
    RelativeTimeFormat: class extends Intl.RelativeTimeFormat {
        constructor(l, o) { super(l ?? 'de', o); }
    },
};
const worte = { Today: 'Heute', Tomorrow: 'Morgen', Yesterday: 'Gestern' };
const chip = new Function('Date', 'Intl', 'translate',
    quelle + '\nreturn formatDueChip;')(FesteUhr, FestesIntl, (k) => worte[k] ?? k);

// ── Fälle ───────────────────────────────────────────────────────────────────
const epoch = (y, m, t, h = 0, min = 0) => Math.floor(new FesteUhr(y, m - 1, t, h, min).getTime() / 1000);
const nowSec = Math.floor(NOW_MS / 1000);
const faelle = nyLauf ? [
    // Ganztag 07.09., Epoch = Berliner Mitternacht (22:00Z), Gerät in New York:
    ['Epoch allein liest den Vortag', { due: Date.UTC(2026, 8, 6, 22) / 1000, dueAllDay: true }, 'Heute'],
    ['dueDay gewinnt ueber den Epoch', { due: Date.UTC(2026, 8, 6, 22) / 1000, dueAllDay: true, dueDay: '2026-09-07' }, 'Morgen'],
] : [
    ['ohne Faelligkeit', { due: 0 }, null],
    ['in 20 Minuten', { due: nowSec + 1200 }, 'in 20 Min.'],
    ['in 40 Sekunden (geklemmt)', { due: nowSec + 40 }, 'in 1 Min.'],
    ['vor 40 Sekunden (geklemmt)', { due: nowSec - 40 }, 'vor 1 Min.'],
    ['vor 90 Sekunden (JS-Rundung)', { due: nowSec - 90 }, 'vor 1 Min.'],
    ['in 90 Sekunden', { due: nowSec + 90 }, 'in 2 Min.'],
    ['heute ganztaegig', { due: epoch(2026, 9, 6), dueAllDay: true }, 'Heute'],
    ['heute 14:00', { due: epoch(2026, 9, 6, 14) }, 'Heute, 14:00'],
    ['heute 9:00 (vorbei)', { due: epoch(2026, 9, 6, 9) }, 'Heute, 9:00'],
    ['morgen ganztaegig', { due: epoch(2026, 9, 7), dueAllDay: true }, 'Morgen'],
    ['morgen 9:00', { due: epoch(2026, 9, 7, 9) }, 'Morgen, 9:00'],
    ['gestern ganztaegig', { due: epoch(2026, 9, 5), dueAllDay: true }, 'Gestern'],
    ['gestern 9:00 (ohne Zeit)', { due: epoch(2026, 9, 5, 9) }, 'Gestern'],
    ['vor 2 Tagen', { due: epoch(2026, 9, 4), dueAllDay: true }, 'vor 2 Tagen'],
    ['vor 34 Tagen', { due: epoch(2026, 8, 3), dueAllDay: true }, 'vor 34 Tagen'],
    ['vor 34 Tagen mit Uhrzeit', { due: epoch(2026, 8, 3, 9) }, 'vor 34 Tagen'],
    ['uebermorgen ganztaegig', { due: epoch(2026, 9, 8), dueAllDay: true }, '8. Sept.'],
    ['uebermorgen 9:00', { due: epoch(2026, 9, 8, 9) }, '8. Sept., 9:00'],
    ['in 46 Tagen', { due: epoch(2026, 10, 22), dueAllDay: true }, '22. Okt.'],
    ['laufendes Jahr ohne Jahreszahl', { due: epoch(2026, 12, 24), dueAllDay: true }, '24. Dez.'],
    ['fremdes Jahr mit Jahreszahl', { due: epoch(2027, 1, 15), dueAllDay: true }, '15. Jan. 2027'],
    ['fremdes Jahr mit Uhrzeit', { due: epoch(2027, 1, 15, 9) }, '15. Jan. 2027, 9:00'],
];

let fehler = 0;
for (const [name, item, soll] of faelle) {
    const ist = chip(item);
    const ok = ist === soll;
    if (!ok) fehler++;
    console.log(`${ok ? 'OK  ' : 'FEHL'} ${name.padEnd(32)} ist: ${JSON.stringify(ist)}  soll: ${JSON.stringify(soll)}`);
}

if (!nyLauf) {
    const aus = execFileSync(process.execPath, [fileURLToPath(import.meta.url), '--ny'],
        { env: { ...process.env, TZ: 'America/New_York' }, encoding: 'utf8' });
    process.stdout.write(aus.split('\n').map(z => z && '  NY ' + z).join('\n') + '\n');
    if (aus.includes('FEHL')) fehler++;
}
if (fehler) { console.error(`\n${fehler} Abweichung(en).`); process.exit(1); }
console.log(nyLauf ? '' : '\nAlle Faelle deckungsgleich mit DueBadgeTextTests.');
