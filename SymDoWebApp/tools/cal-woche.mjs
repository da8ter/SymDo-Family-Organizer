#!/usr/bin/env node
/* Prüfstand für die Wochenansicht des Kalenders (Familientafel): zieht die
   Rechenfunktionen wörtlich aus module.html und prüft sie ohne Browser.

   Hier wohnen die Fehler dieser Ansicht: Tagesarithmetik über die
   Zeitumstellung, das ausschließliche Ende ganztägiger Termine, mehrtägige
   Termine über die Wochengrenze und die Zuordnung zu den Zeilen. Deshalb
   stehen die Funktionen in module.html DOM-frei und als Top-Level-function —
   wer dort ein translate() oder einen Zugriff auf store hineinschreibt, macht
   diesen Prüfstand blind (schneide() wirft dann oder das new Function() bricht).

   Aufruf:  node tools/cal-woche.mjs
   Ein zweiter Lauf als Unterprozess mit TZ=Pacific/Kiritimati (UTC+14) prüft
   ein Gerät weit östlich, weil die Zeitzone je Prozess feststeht. */

import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const hier = dirname(fileURLToPath(import.meta.url));
const fernLauf = process.argv.includes('--fern');
if (!fernLauf) process.env.TZ = 'Europe/Berlin';

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
const NAMEN = ['calDayKey', 'calTagStart', 'calTagPlus', 'calWochenStart', 'calWochenTage',
    'calKalenderwoche', 'calEventEndeInklusiv', 'calEventTagKeys', 'calTafelVergleich',
    'calTafelBauen', 'calZellenAufteilung', 'calWochenFenster', 'calFensterDeckt'];
const quelle = NAMEN.map(schneide).join('\n');
const F = new Function(quelle + '\nreturn {' + NAMEN.join(', ') + '};')();

// ── Helfer ──────────────────────────────────────────────────────────────────
const s = (y, m, t, h = 0, min = 0) => Math.floor(new Date(y, m - 1, t, h, min).getTime() / 1000);
const tag = (y, m, t) => `${y}-${String(m).padStart(2, '0')}-${String(t).padStart(2, '0')}`;

let fehler = 0;
function pruefe(name, ist, soll) {
    const a = JSON.stringify(ist), b = JSON.stringify(soll);
    const ok = a === b;
    if (!ok) fehler++;
    console.log(`${ok ? 'OK  ' : 'FEHL'} ${name.padEnd(46)}${ok ? '' : ` ist: ${a}  soll: ${b}`}`);
}

// ── Wochengrenzen, auch über die Zeitumstellung ─────────────────────────────
// 08.09.2026 ist ein Dienstag; Montag der Woche ist der 07.09.
for (let i = 0; i < 7; i++) {
    const d = 7 + i;                                    // Mo 07.09. bis So 13.09.
    pruefe(`Wochenstart ab ${tag(2026, 9, d)}`,
        F.calDayKey(F.calWochenStart(s(2026, 9, d, 13), 0)), '2026-09-07');
}
pruefe('Wochenstart eine Woche zurueck', F.calDayKey(F.calWochenStart(s(2026, 9, 9), -1)), '2026-08-31');
pruefe('Wochenstart eine Woche vor', F.calDayKey(F.calWochenStart(s(2026, 9, 9), 1)), '2026-09-14');
pruefe('Wochenstart ist Ortsmitternacht',
    new Date(F.calWochenStart(s(2026, 9, 9, 23, 59), 0) * 1000).getHours(), 0);

// Sommerzeit beginnt am 29.03.2026 (Sonntag), endet am 25.10.2026 (Sonntag).
// Mit + 86400 gerechnet hätte die Woche zwei gleiche Tage und einen fehlenden.
const maerz = F.calWochenTage(F.calWochenStart(s(2026, 3, 25), 0));
pruefe('Woche der Sommerzeit-Umstellung', maerz.map(F.calDayKey),
    ['2026-03-23', '2026-03-24', '2026-03-25', '2026-03-26', '2026-03-27', '2026-03-28', '2026-03-29']);
pruefe('… jeder Tag beginnt um 00:00', maerz.map(t2 => new Date(t2 * 1000).getHours()), [0, 0, 0, 0, 0, 0, 0]);
const okt = F.calWochenTage(F.calWochenStart(s(2026, 10, 21), 0));
pruefe('Woche der Winterzeit-Umstellung', okt.map(F.calDayKey),
    ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25']);
pruefe('… jeder Tag beginnt um 00:00', okt.map(t2 => new Date(t2 * 1000).getHours()), [0, 0, 0, 0, 0, 0, 0]);

// ── Kalenderwoche, inklusive Jahreswechsel mit 53 Wochen ────────────────────
pruefe('KW 04.01.2026 (Sonntag)', F.calKalenderwoche(s(2026, 1, 4)), 1);
pruefe('KW 05.01.2026 (Montag)', F.calKalenderwoche(s(2026, 1, 5)), 2);
pruefe('KW 31.12.2026', F.calKalenderwoche(s(2026, 12, 31)), 53);
pruefe('KW 01.01.2027 gehoert noch zu 53', F.calKalenderwoche(s(2027, 1, 1)), 53);
pruefe('KW 04.01.2027', F.calKalenderwoche(s(2027, 1, 4)), 1);
pruefe('KW 07.09.2026', F.calKalenderwoche(s(2026, 9, 7)), 37);

// ── Ende einschließlich ─────────────────────────────────────────────────────
pruefe('ganztaegig ein Tag',
    F.calEventEndeInklusiv({ start: s(2026, 9, 8), end: s(2026, 9, 9), allDay: true }),
    s(2026, 9, 9) - 1);
pruefe('ganztaegig drei Tage',
    F.calDayKey(F.calEventEndeInklusiv({ start: s(2026, 9, 8), end: s(2026, 9, 11), allDay: true })),
    '2026-09-10');
pruefe('Termin endet 24:00 bleibt am Vortag',
    F.calDayKey(F.calEventEndeInklusiv({ start: s(2026, 9, 8, 20), end: s(2026, 9, 9) })),
    '2026-09-08');
pruefe('Ende gleich Beginn', F.calEventEndeInklusiv({ start: s(2026, 9, 8, 9), end: s(2026, 9, 8, 9) }), s(2026, 9, 8, 9));
pruefe('Ende fehlt', F.calEventEndeInklusiv({ start: s(2026, 9, 8, 9) }), s(2026, 9, 8, 9));
pruefe('Ende null', F.calEventEndeInklusiv({ start: s(2026, 9, 8, 9), end: 0 }), s(2026, 9, 8, 9));
pruefe('Ende verdreht', F.calEventEndeInklusiv({ start: s(2026, 9, 8, 9), end: s(2026, 9, 7) }), s(2026, 9, 8, 9));
pruefe('Termin mit Uhrzeit unveraendert',
    F.calEventEndeInklusiv({ start: s(2026, 9, 8, 9), end: s(2026, 9, 8, 10, 30) }), s(2026, 9, 8, 10, 30));

// ── Tagesschlüssel ─────────────────────────────────────────────────────────
pruefe('eintaegig', F.calEventTagKeys({ start: s(2026, 9, 8, 9), end: s(2026, 9, 8, 10) }), ['2026-09-08']);
pruefe('ganztaegiger Dreitaeger sind DREI Tage',
    F.calEventTagKeys({ start: s(2026, 9, 8), end: s(2026, 9, 11), allDay: true }),
    ['2026-09-08', '2026-09-09', '2026-09-10']);
pruefe('Sa bis Di ueber die Wochengrenze',
    F.calEventTagKeys({ start: s(2026, 9, 12), end: s(2026, 9, 16), allDay: true }),
    ['2026-09-12', '2026-09-13', '2026-09-14', '2026-09-15']);
pruefe('ohne Beginn', F.calEventTagKeys({ start: 0, end: s(2026, 9, 9) }), []);
pruefe('Nacht ueber Mitternacht',
    F.calEventTagKeys({ start: s(2026, 9, 8, 22), end: s(2026, 9, 9, 2) }),
    ['2026-09-08', '2026-09-09']);
pruefe('Urlaub ueber die Umstellung',
    F.calEventTagKeys({ start: s(2026, 10, 24), end: s(2026, 10, 27), allDay: true }),
    ['2026-10-24', '2026-10-25', '2026-10-26']);

// ── Die Tafel ───────────────────────────────────────────────────────────────
const woche = F.calWochenTage(F.calWochenStart(s(2026, 9, 8), 0));
const keys = woche.map(F.calDayKey);
const mitglieder = ['aaa11111', 'bbb22222', 'ccc33333'];
const t1 = { id: 1, title: 'Zahnarzt', start: s(2026, 9, 8, 9), end: s(2026, 9, 8, 10), members: ['aaa11111'] };
const t2 = { id: 2, title: 'Kino', start: s(2026, 9, 10, 20), end: s(2026, 9, 10, 22), members: ['aaa11111', 'bbb22222'] };
const t3 = { id: 3, title: 'Muell', start: s(2026, 9, 7), end: s(2026, 9, 8), allDay: true, members: [] };
const t4 = { id: 4, title: 'Fremdtermin', start: s(2026, 9, 9, 8), end: s(2026, 9, 9, 9), members: ['zzz99999'] };
const t5 = { id: 5, title: 'Urlaub', start: s(2026, 9, 11), end: s(2026, 9, 15), allDay: true, members: ['ccc33333'] };
const t6 = { id: 6, title: 'Vorwoche', start: s(2026, 8, 31, 9), end: s(2026, 8, 31, 10), members: ['aaa11111'] };
const tafel = F.calTafelBauen([t1, t2, t3, t4, t5, t6], keys, mitglieder);

pruefe('Zeilenzahl gleich Mitgliederzahl', tafel.zeilen.length, 3);
pruefe('Zahnarzt bei Anna am Dienstag', tafel.zeilen[0].tage['2026-09-08'].map(e => e.id), [1]);
pruefe('Kino bei Anna', tafel.zeilen[0].tage['2026-09-10'].map(e => e.id), [2]);
pruefe('Kino auch bei Ben', tafel.zeilen[1].tage['2026-09-10'].map(e => e.id), [2]);
pruefe('Kino NICHT in der Familienzeile', tafel.familie.tage['2026-09-10'].map(e => e.id), []);
pruefe('Muell ohne Mitglied in der Familienzeile', tafel.familie.tage['2026-09-07'].map(e => e.id), [3]);
pruefe('unbekanntes Mitglied faellt an die Familie', tafel.familie.tage['2026-09-09'].map(e => e.id), [4]);
pruefe('Urlaub ueber vier Tage bei Mia',
    ['2026-09-11', '2026-09-12', '2026-09-13'].map(k => tafel.zeilen[2].tage[k].length), [1, 1, 1]);
pruefe('Urlaub reicht nicht in die Vorwoche', Object.keys(tafel.zeilen[2].tage).filter(k => tafel.zeilen[2].tage[k].length).length, 3);
pruefe('Termin der Vorwoche kommt nicht vor',
    keys.every(k => tafel.zeilen[0].tage[k].every(e => e.id !== 6)), true);
pruefe('jede Zeile hat sieben Tage', tafel.zeilen.map(z => Object.keys(z.tage).length), [7, 7, 7]);
pruefe('leere Zelle ist ein leeres Feld', Array.isArray(tafel.zeilen[1].tage['2026-09-08']), true);

// Ordnung in der Zelle: ganztägig zuerst, dann nach Beginn, Gleichstand stabil.
const g1 = { id: 'b', title: 'Abends', start: s(2026, 9, 9, 18), end: s(2026, 9, 9, 19), members: ['aaa11111'] };
const g2 = { id: 'a', title: 'Frueh', start: s(2026, 9, 9, 8), end: s(2026, 9, 9, 9), members: ['aaa11111'] };
const g3 = { id: 'c', title: 'Ganztags', start: s(2026, 9, 9), end: s(2026, 9, 10), allDay: true, members: ['aaa11111'] };
const g4 = { id: 'd', title: 'Frueh', start: s(2026, 9, 9, 8), end: s(2026, 9, 9, 9), members: ['aaa11111'] };
const sortiert = F.calTafelBauen([g1, g2, g3, g4], keys, mitglieder).zeilen[0].tage['2026-09-09'];
pruefe('ganztaegig zuerst, dann nach Zeit, Gleichstand stabil', sortiert.map(e => e.id), ['c', 'a', 'd', 'b']);
pruefe('umgekehrte Eingabe, gleiche Ordnung',
    F.calTafelBauen([g4, g3, g1, g2], keys, mitglieder).zeilen[0].tage['2026-09-09'].map(e => e.id),
    ['c', 'a', 'd', 'b']);

pruefe('Tafel ohne Mitglieder hat nur die Familienzeile',
    F.calTafelBauen([t1], keys, []).familie.tage['2026-09-08'].map(e => e.id), [1]);

// ── Zellenaufteilung ───────────────────────────────────────────────────────
const liste = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
pruefe('drei von drei passen', F.calZellenAufteilung(liste.slice(0, 3), 3, false), { sichtbar: [1, 2, 3], versteckt: 0 });
pruefe('vier bei Deckel drei', F.calZellenAufteilung(liste.slice(0, 4), 3, false), { sichtbar: [1, 2], versteckt: 2 });
pruefe('zwoelf bei Deckel drei', F.calZellenAufteilung(liste, 3, false), { sichtbar: [1, 2], versteckt: 10 });
pruefe('aufgeklappt zeigt alles', F.calZellenAufteilung(liste, 3, true).sichtbar.length, 12);
pruefe('leere Zelle', F.calZellenAufteilung([], 3, false), { sichtbar: [], versteckt: 0 });

// ── Abrufblock und Deckung ─────────────────────────────────────────────────
const start0 = F.calWochenStart(s(2026, 9, 8), 0);
const block = F.calWochenFenster(start0);
pruefe('Block beginnt eine Woche vorher', F.calDayKey(block.von), '2026-08-31');
pruefe('Block endet vier Wochen spaeter', F.calDayKey(block.bis), '2026-10-12');
const gebraucht = o => ({ von: F.calWochenStart(s(2026, 9, 8), o), bis: F.calTagPlus(F.calWochenStart(s(2026, 9, 8), o), 7) });
pruefe('laufende Woche gedeckt', F.calFensterDeckt(block, gebraucht(0).von, gebraucht(0).bis), true);
pruefe('eine Woche zurueck gedeckt', F.calFensterDeckt(block, gebraucht(-1).von, gebraucht(-1).bis), true);
pruefe('vier Wochen vor gedeckt', F.calFensterDeckt(block, gebraucht(4).von, gebraucht(4).bis), true);
pruefe('fuenf Wochen vor NICHT gedeckt', F.calFensterDeckt(block, gebraucht(5).von, gebraucht(5).bis), false);
pruefe('zwei Wochen zurueck NICHT gedeckt', F.calFensterDeckt(block, gebraucht(-2).von, gebraucht(-2).bis), false);
pruefe('ohne geladenes Fenster nichts gedeckt', F.calFensterDeckt(null, gebraucht(0).von, gebraucht(0).bis), false);

// ── Zweiter Lauf mit weit oestlicher Zeitzone ──────────────────────────────
if (!fernLauf) {
    const aus = execFileSync(process.execPath, [fileURLToPath(import.meta.url), '--fern'],
        { env: { ...process.env, TZ: 'Pacific/Kiritimati' }, encoding: 'utf8' });
    const zeilen = aus.split('\n').filter(z => z.startsWith('FEHL'));
    console.log(`\nZweiter Lauf TZ=Pacific/Kiritimati: ${zeilen.length ? zeilen.join('\n') : 'alle Faelle gleich'}`);
    if (zeilen.length) fehler += zeilen.length;
}

if (fehler) { console.error(`\n${fehler} Abweichung(en).`); process.exit(1); }
if (!fernLauf) console.log('\nWochenansicht: alle Faelle wie erwartet.');
