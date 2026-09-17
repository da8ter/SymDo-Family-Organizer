#!/usr/bin/env node
/* Prüfstand für die Hausaufgaben der Web-App: zieht die Rechenfunktionen
   wörtlich aus module.html und prüft sie ohne Browser.

   Dieselben Regeln stehen ein zweites Mal im Gateway
   (SymDoGateway/libs/HomeworkCalc.php, geprüft in tests/HomeworkTest.php).
   Das ist Absicht und keine Doppelung aus Versehen: der Client rechnet die
   Zuordnung zur Stunde selbst, weil die Zahl offener Aufgaben NICHT je Stunde
   durch die Stundenplan-Brücke wandern darf — die Signatur des Plans vergleicht
   den ganzen Plan, und jedes Abhaken löste sonst ein vollständiges Neuzeichnen
   aus. Wer hier Goldwerte ändert, zieht die PHP-Seite nach — und umgekehrt.

   Aufruf:  node tools/homework-parity.mjs   (Anker: 09.09.2026, Berlin) */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const hier = dirname(fileURLToPath(import.meta.url));
process.env.TZ = 'Europe/Berlin';

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
const NAMEN = ['hwFachTreffer', 'hwFachInfo', 'hwAusSchule', 'hwWartetAufSchule',
    'hwOffene', 'hwErledigte', 'hwGruppen', 'hwFuerSlot', 'hwFaecherFuerKind',
    'hwErledigtText'];
/* hwErledigtText greift nach zwei Nachbarn: der Uebersetzung und dem
   Faelligkeitstext. Beide werden hier ersetzt — geprueft wird diese Funktion,
   nicht ihre Umgebung. */
const UMGEBUNG = `
function translate(k) { return k === 'done %s' ? 'erledigt %s' : k; }
function hwFaelligText(d) { return 'faellig:' + String(d || ''); }
`;
const F = new Function(UMGEBUNG + NAMEN.map(schneide).join('\n') + '\nreturn {' + NAMEN.join(', ') + '};')();

let fehler = 0, anzahl = 0;
function pruefe(name, ist, soll) {
    anzahl++;
    const a = JSON.stringify(ist), b = JSON.stringify(soll);
    const ok = a === b;
    if (!ok) fehler++;
    console.log(`${ok ? 'OK  ' : 'FEHL'} ${name.padEnd(48)}${ok ? '' : ` ist: ${a}  soll: ${b}`}`);
}

// ── Fachvergleich: dieselben Goldwerte wie in HomeworkTest.php ──────────────
pruefe('gleiches Fach', F.hwFachTreffer('Mathematik', 'Mathematik'), true);
pruefe('Gross- und Kleinschreibung', F.hwFachTreffer('mathematik', 'Mathematik'), true);
pruefe('Kurzform trifft den Anfang', F.hwFachTreffer('Mathe', 'Mathematik'), true);
pruefe('Kurzform in der anderen Richtung', F.hwFachTreffer('Mathematik', 'Mathe'), true);
pruefe('zwei Buchstaben treffen nicht', F.hwFachTreffer('Ma', 'Mathematik'), false);
pruefe('anderes Fach trifft nicht', F.hwFachTreffer('Deutsch', 'Mathematik'), false);
pruefe('leeres Fach trifft nichts', F.hwFachTreffer('', 'Mathematik'), false);

const SUBJECTS = [
    { name: 'Mathematik', icon: 'fa-calculator', color: '#1E88E5' },
    { name: 'Deutsch', icon: 'fa-book', color: '#E53935' },
    { name: 'Sport', icon: 'fa-person-running', color: '' },
];
pruefe('Fachinfo aus dem Katalog', F.hwFachInfo('Mathe', SUBJECTS),
    { name: 'Mathematik', icon: 'fa-calculator', color: '#1E88E5' });
pruefe('unbekanntes Fach bleibt ohne Symbol', F.hwFachInfo('Werken', SUBJECTS),
    { name: 'Werken', icon: '', color: '' });
pruefe('Fach ohne Farbe', F.hwFachInfo('Sport', SUBJECTS).color, '');

// ── Offene ─────────────────────────────────────────────────────────────────
const items = [
    { id: '1', childId: 'k1', subject: 'Mathematik', due: '2026-09-08', done: false },
    { id: '2', childId: 'k1', subject: 'Deutsch', due: '2026-09-09', done: false },
    { id: '3', childId: 'k1', subject: 'Sport', due: '2026-09-10', done: false },
    { id: '4', childId: 'k1', subject: 'Mathematik', due: '2026-09-14', done: false },
    { id: '5', childId: 'k1', subject: 'Deutsch', due: '', done: false },
    { id: '6', childId: 'k1', subject: 'Musik', due: '2026-09-09', done: true },
    { id: '7', childId: 'k2', subject: 'Englisch', due: '2026-09-09', done: false },
];
pruefe('offene eines Kindes', F.hwOffene(items, 'k1').map(i => i.id), ['1', '2', '3', '4', '5']);
pruefe('offene aller Kinder', F.hwOffene(items, '').length, 6);
pruefe('erledigte fallen weg', F.hwOffene(items, 'k1').some(i => i.id === '6'), false);

// ── Gruppen ────────────────────────────────────────────────────────────────
const g = F.hwGruppen(F.hwOffene(items, 'k1'), '2026-09-09');
pruefe('ueberfaellig', g.ueberfaellig.map(i => i.id), ['1']);
pruefe('heute', g.heute.map(i => i.id), ['2']);
pruefe('morgen', g.morgen.map(i => i.id), ['3']);
pruefe('spaeter', g.spaeter.map(i => i.id), ['4']);
pruefe('ohne Datum', g.offen.map(i => i.id), ['5']);

// Monatsgrenze und Jahreswechsel: „morgen" muss auch über die Grenze stimmen.
const grenze = [{ id: 'a', childId: 'k1', subject: 'Deutsch', due: '2026-10-01', done: false }];
pruefe('morgen ueber die Monatsgrenze',
    F.hwGruppen(grenze, '2026-09-30').morgen.map(i => i.id), ['a']);
const jahr = [{ id: 'b', childId: 'k1', subject: 'Deutsch', due: '2027-01-01', done: false }];
pruefe('morgen ueber den Jahreswechsel',
    F.hwGruppen(jahr, '2026-12-31').morgen.map(i => i.id), ['b']);
// Sommerzeit-Ende: der 25.10.2026 hat 25 Stunden
const dst = [{ id: 'c', childId: 'k1', subject: 'Deutsch', due: '2026-10-25', done: false }];
pruefe('morgen ueber die Zeitumstellung',
    F.hwGruppen(dst, '2026-10-24').morgen.map(i => i.id), ['c']);

// ── Zuordnung zur Stunde ───────────────────────────────────────────────────
// Zwei Mathestunden am selben Tag: nur die ERSTE traegt die Zahl.
pruefe('erste Mathestunde traegt zwei',
    F.hwFuerSlot([
        { childId: 'k1', subject: 'Mathematik', due: '2026-09-10', done: false },
        { childId: 'k1', subject: 'Mathe', due: '2026-09-10', done: false },
    ], 'k1', '2026-09-10', 'Mathematik', 's1', 's1'), 2);
pruefe('zweite Mathestunde traegt nichts',
    F.hwFuerSlot([
        { childId: 'k1', subject: 'Mathematik', due: '2026-09-10', done: false },
    ], 'k1', '2026-09-10', 'Mathematik', 's1', 's3'), 0);
pruefe('erledigte zaehlen nicht',
    F.hwFuerSlot([
        { childId: 'k1', subject: 'Mathematik', due: '2026-09-10', done: true },
    ], 'k1', '2026-09-10', 'Mathematik', 's1', 's1'), 0);
pruefe('anderes Kind zaehlt nicht',
    F.hwFuerSlot([
        { childId: 'k2', subject: 'Mathematik', due: '2026-09-10', done: false },
    ], 'k1', '2026-09-10', 'Mathematik', 's1', 's1'), 0);
pruefe('anderer Tag zaehlt nicht',
    F.hwFuerSlot([
        { childId: 'k1', subject: 'Mathematik', due: '2026-09-11', done: false },
    ], 'k1', '2026-09-10', 'Mathematik', 's1', 's1'), 0);
pruefe('ohne Datum zaehlt nicht',
    F.hwFuerSlot([
        { childId: 'k1', subject: 'Mathematik', due: '', done: false },
    ], 'k1', '2026-09-10', 'Mathematik', 's1', 's1'), 0);

// ── Fächerauswahl ──────────────────────────────────────────────────────────
const kind = { days: [
    { date: '2026-09-09', slots: [{ name: 'Mathematik' }, { name: 'Sport' }, { name: 'Mathematik' }] },
    { date: '2026-09-10', slots: [{ name: 'Deutsch' }] },
] };
pruefe('Faecher des Kindes zuerst, ohne Doppelte',
    F.hwFaecherFuerKind(kind, SUBJECTS.concat([{ name: 'Musik' }])),
    ['Mathematik', 'Sport', 'Deutsch', 'Musik']);
pruefe('ohne Kind bleibt der Katalog',
    F.hwFaecherFuerKind(null, SUBJECTS), ['Mathematik', 'Deutsch', 'Sport']);
pruefe('ohne Katalog nur die eigenen',
    F.hwFaecherFuerKind(kind, []), ['Mathematik', 'Sport', 'Deutsch']);

// ── Erledigte: neueste zuerst, fuer den eingeklappten Abschnitt ────────────
const fertig = [
    { id: 'f1', childId: 'k1', subject: 'Mathematik', due: '2026-09-08', done: true, doneAt: 100 },
    { id: 'f2', childId: 'k1', subject: 'Deutsch', due: '2026-09-09', done: true, doneAt: 300 },
    { id: 'f3', childId: 'k1', subject: 'Sport', due: '2026-09-07', done: true, doneAt: 200 },
    { id: 'f4', childId: 'k1', subject: 'Kunst', due: '2026-09-05', done: false, doneAt: 0 },
    { id: 'f5', childId: 'k2', subject: 'Musik', due: '2026-09-09', done: true, doneAt: 999 },
    // aus einem alten Bestand: ohne Zeitpunkt entscheidet die Faelligkeit
    { id: 'f6', childId: 'k1', subject: 'Physik', due: '2026-09-06', done: true },
    { id: 'f7', childId: 'k1', subject: 'Chemie', due: '2026-09-11', done: true },
];
pruefe('erledigte eines Kindes, neueste zuerst',
    F.hwErledigte(fertig, 'k1').map(i => i.id), ['f2', 'f3', 'f1', 'f7', 'f6']);
pruefe('offene sind nicht dabei', F.hwErledigte(fertig, 'k1').some(i => i.id === 'f4'), false);
pruefe('fremdes Kind bleibt draussen', F.hwErledigte(fertig, 'k1').some(i => i.id === 'f5'), false);
pruefe('ohne Kind alle erledigten', F.hwErledigte(fertig, '').length, 6);
pruefe('ein leerer Bestand ist leer', F.hwErledigte([], 'k1'), []);

/* ── Abgehakt, aber die Schule weiss noch nichts davon ─────────────────────
   Eine WebUntis-Aufgabe, die das Kind zu Hause abhakt, bleibt bei den OFFENEN,
   bis das Klassenbuch dasselbe sagt (doneBy === 'untis'). Eigene Aufgaben sind
   sofort erledigt — dort gibt es niemanden, auf den man wartet. */
const warten = [
    { id: 'w1', childId: 'k1', subject: 'Mathematik', due: '2026-09-10', done: true, doneAt: 500,
      source: 'untis', srcId: 11, doneBy: 'user' },
    { id: 'w2', childId: 'k1', subject: 'Deutsch', due: '2026-09-10', done: true, doneAt: 600,
      source: 'untis', srcId: 12, doneBy: 'untis' },
    { id: 'w3', childId: 'k1', subject: 'Sport', due: '2026-09-10', done: true, doneAt: 700,
      source: 'app', doneBy: 'user' },
    { id: 'w4', childId: 'k1', subject: 'Kunst', due: '2026-09-10', done: true, doneAt: 800,
      source: 'moodle', srcId: 13, doneBy: 'user' },
    { id: 'w5', childId: 'k1', subject: 'Musik', due: '2026-09-10', done: false, doneAt: 0,
      source: 'untis', srcId: 14 },
];
pruefe('selbst abgehakte Schulaufgabe bleibt offen',
    F.hwOffene(warten, 'k1').map(i => i.id), ['w1', 'w4', 'w5']);
// Reihenfolge ist die des Abhakens, nicht die der Kennungen: w3 (700) vor w2 (600).
pruefe('… und steht noch nicht bei den erledigten',
    F.hwErledigte(warten, 'k1').map(i => i.id), ['w3', 'w2']);
pruefe('von der Schule bestaetigt wandert sie hinueber',
    F.hwWartetAufSchule(warten[1]), false);
pruefe('eine eigene Aufgabe wartet nie', F.hwWartetAufSchule(warten[2]), false);
pruefe('LOGINEO zaehlt genauso als Schule', F.hwWartetAufSchule(warten[3]), true);
pruefe('eine offene wartet nicht (sie ist ja nicht abgehakt)',
    F.hwWartetAufSchule(warten[4]), false);
pruefe('nichts ist nichts', F.hwWartetAufSchule(null), false);
pruefe('offene und wartende zusammen bleiben der Bestand',
    F.hwOffene(warten, 'k1').length + F.hwErledigte(warten, 'k1').length, warten.length);
pruefe('offene und erledigte ergeben zusammen den Bestand des Kindes',
    F.hwOffene(fertig, 'k1').length + F.hwErledigte(fertig, 'k1').length, 6);


// ── Wann wurde es erledigt? ────────────────────────────────────────────────
/* Gezeigt wird das Datum aus `doneAt`. Bei Aufgaben aus der Schule zieht das
   Gateway es auf den Zeitpunkt der Bestaetigung nach — hier zaehlt nur, dass
   die Zeile es formatiert und ohne Zeitpunkt auf die Faelligkeit zurueckfaellt. */
const AM_17_09 = Math.floor(new Date('2026-09-17T15:40:00+02:00').getTime() / 1000);
/* Das FORMAT gehoert dem Browser (toLocaleDateString) — auf Deutsch „17.9.",
   unter Node in der Standardsprache „9/17". Geprueft wird deshalb gegen
   dieselbe Formatierung, nicht gegen eine feste Schreibweise. */
const ERWARTET = 'erledigt ' + new Date(AM_17_09 * 1000)
    .toLocaleDateString(undefined, { day: 'numeric', month: 'numeric' });
pruefe('erledigt mit Datum',
    F.hwErledigtText({ done: true, doneAt: AM_17_09, due: '2026-09-11' }), ERWARTET);
pruefe('ohne Zeitpunkt bleibt die Faelligkeit',
    F.hwErledigtText({ done: true, doneAt: 0, due: '2026-09-11' }), 'faellig:2026-09-11');
pruefe('kaputter Zeitpunkt faellt ebenfalls zurueck',
    F.hwErledigtText({ done: true, doneAt: 'morgen', due: '2026-09-11' }), 'faellig:2026-09-11');

console.log(`\n${anzahl} Zusicherungen, ${fehler} Abweichung(en).`);
process.exit(fehler === 0 ? 0 : 1);
