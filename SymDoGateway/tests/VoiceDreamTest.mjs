#!/usr/bin/env node
/* Prüfstand für die Traumbilder der Sprachblase: schneidet die Auslosung
   wörtlich aus SymDoGateway/libs/voice-blob.js und lässt sie zehntausend Mal
   laufen — ohne Browser und ohne Ton.

   Warum nicht im Browser: die Regel „ein Bild wiederholt sich erst nach
   TRAUM_PAUSE_Z Buchstaben und einem anderen Bild" braucht bei drei Partikeln
   und einer Rundendauer von 4,2 s über eine Minute Laufzeit JE Wiederholung.
   Headless mit virtueller Zeit dauerte das Minuten und prüfte am Ende weniger
   als dieser Lauf in einer Sekunde. Die Zeichnung selbst (Bild sichtbar, Maske,
   Neigung) ist dagegen im Browser geprüft — dort gehört sie hin.

   Aufruf:  node SymDoGateway/tests/VoiceDreamTest.mjs
*/

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const hier = dirname(fileURLToPath(import.meta.url));
const quelle = readFileSync(join(hier, '..', 'libs', 'voice-blob.js'), 'utf8');

/** Eine Top-Level-Konstante aus der Bibliothek lesen. */
function zahl(name) {
    const m = quelle.match(new RegExp('var\\s+' + name + '\\s*=\\s*([0-9.]+)\\s*;'));
    if (!m) throw new Error(name + ' nicht in voice-blob.js gefunden');
    return Number(m[1]);
}

/** Eine Funktion wörtlich ausschneiden — wie in den Web-App-Prüfständen. */
function schneide(name) {
    const start = quelle.indexOf('function ' + name + '(');
    if (start < 0) throw new Error(name + ' nicht in voice-blob.js gefunden');
    let i = quelle.indexOf('{', start), tiefe = 0;
    for (; i < quelle.length; i++) {
        if (quelle[i] === '{') tiefe++;
        else if (quelle[i] === '}' && --tiefe === 0) break;
    }
    return quelle.slice(start, i + 1);
}

const CHANCE = zahl('TRAUM_CHANCE');
const KIPP = zahl('TRAUM_KIPP');
const PAUSE = zahl('TRAUM_PAUSE_Z');
const ANZAHL_BILDER = (quelle.match(/data:image\/webp;base64,/g) || []).length;

/* Die kleinste Bühne, auf der die Auslosung spielen kann: drei Partikel mit je
   Wolke, Buchstabe und Bild. Nur die Aufrufe, die traumLosen wirklich macht. */
function partikel() {
    const machen = () => ({ attr: {}, style: { display: '' },
        setAttribute(k, v) { this.attr[k] = v; },
        setAttributeNS(_ns, k, v) { this.attr[k] = v; },
        getAttribute(k) { return this.attr[k]; } });
    const wolke = machen(), z = machen(), bild = machen();
    return {
        wolke, z, bild,
        querySelector(sel) {
            if (sel === '.wolke') return wolke;
            if (sel === '.z') return z;
            if (sel === '.traum') return bild;
            return null;
        }
    };
}

const F = new Function('TRAUM', 'TRAUM_CHANCE', 'TRAUM_KIPP', 'TRAUM_PAUSE_Z', 'zzz', `
    var zGeflogen = 0, traumZuletzt = [], traumAndere = [];
    ${schneide('traumLosen')}
    return { losen: traumLosen, stand: function () { return zGeflogen; } };
`);

let fehler = 0, anzahl = 0;
function pruefe(name, ist, soll) {
    anzahl++;
    const a = JSON.stringify(ist), b = JSON.stringify(soll);
    const ok = a === b;
    if (!ok) fehler++;
    console.log((ok ? 'OK  ' : 'FEHL') + ' ' + name.padEnd(52) + (ok ? '' : `\n     ist:  ${a}\n     soll: ${b}`));
}

// ── Die Werte in der Bibliothek ────────────────────────────────────────────
pruefe('drei Bilder eingebettet', ANZAHL_BILDER, 3);
pruefe('Häufigkeit unter einem Drittel', CHANCE > 0 && CHANCE <= 0.33, true);
pruefe('Neigung bis 20 Grad', KIPP, 20);
pruefe('Pause vor der Wiederholung', PAUSE, 50);

// ── Der Lauf ───────────────────────────────────────────────────────────────
const BILDER = ['a', 'b', 'c'];
const zzz = [partikel(), partikel(), partikel()];
const h = F(BILDER, CHANCE, KIPP, PAUSE, zzz);

const folge = [];           // 'z' oder der Index des Bildes, in der Reihenfolge
const neigungen = [];
const RUNDEN = 30000;
for (let n = 0; n < RUNDEN; n++) {
    const i = n % 3;
    h.losen(i);
    const p = zzz[i];
    const zeigtBild = p.bild.style.display !== 'none';
    if (zeigtBild) {
        folge.push(BILDER.indexOf(p.bild.getAttribute('href')));
        neigungen.push(Number(String(p.bild.getAttribute('transform')).replace(/[^-0-9.]/g, '').split('0')[0] || 0));
    } else {
        folge.push('z');
    }
    // Genau eines von beiden ist sichtbar — nie ein Bild MIT Buchstabe darauf.
    if ((p.z.style.display !== 'none') === zeigtBild) {
        fehler++; anzahl++;
        console.log('FEHL Buchstabe und Bild gleichzeitig in Runde ' + n);
        break;
    }
}

const bildstellen = folge.map((x, i) => (x === 'z' ? -1 : i)).filter(i => i >= 0);
pruefe('Bilder kommen vor', bildstellen.length > 20, true);
pruefe('Bilder bleiben selten (unter jeder fünften Runde)',
    bildstellen.length / RUNDEN < 0.2, true);

// Nie zweimal dasselbe Bild hintereinander.
let direkt = 0;
for (let k = 1; k < bildstellen.length; k++) {
    if (folge[bildstellen[k]] === folge[bildstellen[k - 1]]) direkt++;
}
pruefe('keine direkte Wiederholung', direkt, 0);

// Zwischen zwei gleichen Bildern: genug Buchstaben UND ein anderes Bild.
let zuFrueh = 0, ohneAnderes = 0, paare = 0;
for (let a = 0; a < bildstellen.length; a++) {
    for (let b = a + 1; b < bildstellen.length; b++) {
        if (folge[bildstellen[a]] !== folge[bildstellen[b]]) continue;
        paare++;
        let zs = 0;
        for (let m = bildstellen[a] + 1; m < bildstellen[b]; m++) if (folge[m] === 'z') zs++;
        const andere = new Set();
        for (let c = a + 1; c < b; c++) andere.add(folge[bildstellen[c]]);
        if (zs < PAUSE) zuFrueh++;
        if (andere.size < 1) ohneAnderes++;
        break;                          // nur das jeweils NÄCHSTE gleiche Bild
    }
}
pruefe('Wiederholungen überhaupt geprüft', paare > 10, true);
pruefe('nie vor ' + PAUSE + ' Buchstaben wiederholt', zuFrueh, 0);
pruefe('immer ein anderes Bild dazwischen', ohneAnderes, 0);

// Jedes Bild kommt dran — eine Sperre, die eines aussperrt, wäre kaputt.
const genutzt = new Set(bildstellen.map(i => folge[i]));
pruefe('alle drei Bilder kommen vor', [...genutzt].sort(), [0, 1, 2]);

// Die Neigung liegt im Fenster und ist nicht immer dieselbe.
const gedreht = neigungen.filter(x => Number.isFinite(x));
pruefe('Neigung im Fenster', gedreht.every(x => Math.abs(x) <= KIPP + 0.05), true);
pruefe('Neigung streut', new Set(gedreht.map(x => x.toFixed(1))).size > 5, true);

console.log(`\n${anzahl} Zusicherungen, ${fehler} Abweichung(en).`);
process.exit(fehler === 0 ? 0 : 1);
