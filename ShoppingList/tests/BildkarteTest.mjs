#!/usr/bin/env node
/* Prüfstand für die Bildkarte der Einkaufs-Kachel: schneidet kachelZustand und
   den Lader (kachelBildkarte, kachelBildkarteZeigen, kachelBildkarteLos)
   wörtlich aus SymDoWebApp/module.html und fährt sie gegen eine Attrappe für
   fetch — ohne Browser, ohne Symcon.

   Geprüft wird, was an diesen Zeilen hängt: die Datei wird je Adresse EINMAL
   geholt, auch wenn mehrere Stände kommen; Bilder gelten währenddessen als an;
   das erste Bild wartet auf die Karte, aber nicht ewig; ein Fehler kostet nur
   die Bilder; eine alte Adresse, die spät fertig wird, überschreibt nichts; und
   ohne Adresse bleibt alles wie bisher.

   Aufruf:  node ShoppingList/tests/BildkarteTest.mjs [pfad/zu/module.html]
   (läuft auch aus ShoppingList/tests/BildkarteTest.php)
*/

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const hier = dirname(fileURLToPath(import.meta.url));
const datei = process.argv[2] || join(hier, '..', '..', 'SymDoWebApp', 'module.html');
const html = readFileSync(datei, 'utf8');

// ── Wörtlich aus module.html schneiden ──────────────────────────────────────
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
function zeile(anfang) {
    const start = html.indexOf(anfang);
    if (start < 0) throw new Error(`${anfang} nicht in module.html gefunden`);
    return html.slice(start, html.indexOf(';', start) + 1);
}

const quelle = [
    zeile('const SELF_ID ='),
    zeile('const kachelBildkarten ='),
    zeile('let kachelBildkarteSoll ='),
    zeile('let kachelBildkarteDa ='),
    zeile('const KACHEL_BILDKARTE_WARTE_MS ='),
    zeile('let kachelBildkarteHalt ='),
    schneide('kachelBildkarte'),
    schneide('kachelBildkarteZeigen'),
    schneide('kachelBildkarteLos'),
    schneide('kachelZustand'),
].join('\n');

// ── Umgebung: Server, Fenster, Uhr ──────────────────────────────────────────
let abrufe, antworten, gesynct, gezeichnet, klassen, termine, store;

/** Die Attrappe für fetch: je Adresse eine Antwort (Funktion → Versprechen). */
function fetchAttrappe(url, optionen) {
    abrufe.push({ url, optionen });
    const a = antworten[url];
    return a ? a() : Promise.reject(new TypeError('Failed to fetch'));
}
const antwort = (json, status = 200) => () => Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(json) });
/** Ein Versprechen, das der Prüfstand selbst einlöst. */
function spaeter() {
    let einloesen, brechen;
    const p = new Promise((a, b) => { einloesen = a; brechen = b; });
    return { antwort: () => p, einloesen, brechen };
}
const antwortVon = (json) => ({ ok: true, status: 200, json: () => Promise.resolve(json) });
/** Alle anstehenden Versprechen abarbeiten lassen. */
const ruhe = () => new Promise((r) => setImmediate(r));

/* Eine Uhr, die nur auf Zuruf läuft: die Frist des ersten Bilds soll hier nicht
   echt abgewartet werden. */
function uhrStellen(fn, ms) { const t = { fn, ms, an: true }; termine.push(t); return t; }
function uhrLoeschen(t) { if (t) t.an = false; }
function uhrAbgelaufen() { for (const t of termine) { if (t.an) { t.an = false; t.fn(); } } }

/** Ein frischer Modulbereich — so, als würde die Kachel neu geladen. */
function kachel(art = 'shopping') {
    abrufe = []; antworten = {}; gesynct = 0; gezeichnet = 0; termine = [];
    klassen = new Set(['kachel-warte']);   // steht beim Laden, bis der erste Stand gemalt ist
    store = { images: {}, brands: {} };
    const dokument = { documentElement: { classList: {
        contains: (k) => klassen.has(k), remove: (k) => klassen.delete(k), add: (k) => klassen.add(k) } } };
    // new Function nur mit Code aus der eigenen module.html, lokal in Node — keine fremde Eingabe.
    const bau = new Function('KACHEL', 'fetch', 'store', 'syncCompat', 'renderAll', 'window', 'document',
        'setTimeout', 'clearTimeout', quelle + `
        return { kachelZustand, stand: () => ({ soll: kachelBildkarteSoll, da: kachelBildkarteDa,
            offen: Object.keys(kachelBildkarten), halt: kachelBildkarteHalt !== null }) };`);
    return bau({ art }, (u, o) => fetchAttrappe(u, o), store, () => { gesynct++; }, () => { gezeichnet++; },
        { __imageHookUrl: '', __extApiHookUrl: '' }, dokument, uhrStellen, uhrLoeschen);
}

// ── Prüfrahmen ──────────────────────────────────────────────────────────────
let fehler = 0, anzahl = 0;
function pruefe(name, ist, soll) {
    anzahl++;
    const a = JSON.stringify(ist), b = JSON.stringify(soll);
    const ok = a === b;
    if (!ok) fehler++;
    console.log(`${ok ? 'OK  ' : 'FEHL'} ${name.padEnd(66)}${ok ? '' : `\n     ist:  ${a}\n     soll: ${b}`}`);
}

const U1 = '/hook/shoppinglist/assets/1/?t=tok&a=bildkarte&v=aaaaaaaaaaaaaaaa';
const U2 = '/hook/shoppinglist/assets/1/?t=tok&a=bildkarte&v=bbbbbbbbbbbbbbbb';
const K1 = { images: { milch: 'milch.png', 'käse': 'kaese.png' }, brands: { pringles: 'chips.png' } };
const K2 = { images: { brot: 'brot.png' }, brands: {} };
const stand = (extra) => ({ type: 'state', items: [], imageBase: '/hook/b/?t=tok&f=', ...extra });

// ── Der gewöhnliche Weg ─────────────────────────────────────────────────────
let k = kachel();
let offen = spaeter();
antworten[U1] = offen.antwort;
let z = k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true }));
pruefe('Stand mit Adresse: Karte vorerst leer, Bilder aber AN',
    [z.images, z.brands, z.shoppingExtras.self.imagesEnabled, z.shoppingExtras.self.imageBase],
    [{}, {}, true, '/hook/b/?t=tok&f=']);
pruefe('… genau ein Abruf, an die Adresse, mit den eigenen Zugangsdaten',
    [abrufe.length, abrufe[0].url, abrufe[0].optionen.credentials], [1, U1, 'same-origin']);
pruefe('… und das erste Bild wartet (kachel-warte bleibt)', [k.stand().halt, klassen.has('kachel-warte')], [true, true]);
z = k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true, items: [{ id: 'x' }] }));
pruefe('Ein zweiter Stand während des Ladens holt nicht noch einmal', [abrufe.length, z.images], [1, {}]);
offen.einloesen(antwortVon(K1));
await ruhe();
pruefe('Datei da: Karte gesetzt und EINMAL neu gezeichnet (zwei Stände warteten)',
    [store.images, store.brands, gesynct, gezeichnet], [K1.images, K1.brands, 1, 1]);
pruefe('… und das erste Bild ist frei', [k.stand().halt, klassen.has('kachel-warte')], [false, false]);
z = k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true }));
pruefe('Spätere Stände: Karte sofort, ohne Abruf', [z.images, z.brands, abrufe.length], [K1.images, K1.brands, 1]);

// ── Neue Version ────────────────────────────────────────────────────────────
antworten[U2] = antwort(K2);
z = k.kachelZustand(stand({ availableImagesUrl: U2, imagesEnabled: true }));
pruefe('Neue Adresse (neue Bilder im Modul): einmal neu geholt, die Kachel bleibt sichtbar',
    [abrufe.length, abrufe[1].url, k.stand().halt, klassen.has('kachel-warte')], [2, U2, false, false]);
await ruhe();
pruefe('… danach die neue Karte', [store.images, gezeichnet], [K2.images, 2]);

// ── Eine alte Adresse wird spät fertig ──────────────────────────────────────
k = kachel();
const alt = spaeter(), neu = spaeter();
antworten[U1] = alt.antwort;
antworten[U2] = neu.antwort;
k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true }));
k.kachelZustand(stand({ availableImagesUrl: U2, imagesEnabled: true }));
neu.einloesen(antwortVon(K2));
await ruhe();
alt.einloesen(antwortVon(K1));
await ruhe();
pruefe('Die Karte der alten Adresse überschreibt die neue nicht', [store.images, gezeichnet], [K2.images, 1]);
z = k.kachelZustand(stand({ availableImagesUrl: U2, imagesEnabled: true }));
pruefe('… und der nächste Stand bekommt die neue sofort', [z.images, abrufe.length], [K2.images, 2]);

// ── Fehler ──────────────────────────────────────────────────────────────────
k = kachel();
antworten[U1] = antwort({ error: 'x' }, 503);
z = k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true }));
await ruhe();
pruefe('HTTP-Fehler: ohne Bilder weiter, nichts neu gezeichnet, erstes Bild frei',
    [z.images, store.images, gezeichnet, klassen.has('kachel-warte'), k.stand().halt], [{}, {}, 0, false, false]);
pruefe('… die Adresse ist wieder frei für einen späteren Versuch', k.stand().offen, []);
antworten[U1] = antwort(K1);
k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true }));
await ruhe();
pruefe('Ein späterer Stand versucht es erneut — und dann klappt es', [abrufe.length, store.images], [2, K1.images]);

k = kachel();
antworten[U1] = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.reject(new SyntaxError('kaputt')) });
z = k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true }));
await ruhe();
pruefe('Kaputtes JSON: dasselbe, kein Absturz', [z.shoppingExtras.self.imagesEnabled, store.images, gezeichnet,
    klassen.has('kachel-warte')], [true, {}, 0, false]);

k = kachel();
antworten[U1] = antwort({ images: ['falsch'], brands: 'auch falsch' });
k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true }));
await ruhe();
pruefe('Unerwartete Form: leere Karten statt fremder Typen', [store.images, store.brands], [{}, {}]);

/* fetch fehlt oder wirft sofort (alter Browser, ungültige Adresse): kachelZustand
   läuft mitten in handleMessage — ein Wurf dort hieße: gar keine Kachel. */
k = kachel();
let geworfen = null;
try {
    // new Function nur mit Code aus der eigenen module.html, lokal in Node — keine fremde Eingabe.
    const bauOhne = new Function('KACHEL', 'fetch', 'store', 'syncCompat', 'renderAll', 'window', 'document',
        'setTimeout', 'clearTimeout', quelle + '\nreturn kachelZustand;');
    const zustandOhne = bauOhne({ art: 'shopping' }, () => { throw new TypeError('fetch is not a function'); }, store,
        () => {}, () => {}, {}, { documentElement: { classList: { contains: () => false, remove() {} } } },
        uhrStellen, uhrLoeschen);
    z = zustandOhne(stand({ availableImagesUrl: U1, imagesEnabled: true }));
    await ruhe();
} catch (e) { geworfen = String(e); }
pruefe('fetch wirft sofort: kein Wurf nach aussen, Kachel ohne Karte', [geworfen, z.images], [null, {}]);

// ── Frist des ersten Bilds ──────────────────────────────────────────────────
k = kachel();
offen = spaeter();
antworten[U1] = offen.antwort;
k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true }));
pruefe('Das erste Bild wartet höchstens 400 ms', [termine.length, termine[0].ms], [1, 400]);
uhrAbgelaufen();
pruefe('… nach der Frist wird gemalt, auch ohne Karte', [klassen.has('kachel-warte'), k.stand().halt], [false, false]);
offen.einloesen(antwortVon(K1));
await ruhe();
pruefe('… und die Karte kommt eben danach', [store.images, gezeichnet], [K1.images, 1]);

k = kachel();
klassen.delete('kachel-warte');   // der Notausgang (2 s) hat schon geöffnet
antworten[U1] = antwort(K1);
k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true }));
pruefe('Ist die Kachel schon sichtbar, hält nichts an', [termine.length, k.stand().halt], [0, false]);
await ruhe();

// ── Ohne Adresse: wie bisher ────────────────────────────────────────────────
k = kachel();
const inline = { availableImages: { apfel: 'apfel.png' }, availableBrands: { nutella: 'nutella.png' } };
z = k.kachelZustand(stand(inline));
pruefe('Karte im Stand (ältere Modulfassung ohne Merker): unverändert übernommen',
    [z.images, z.brands, z.shoppingExtras.self.imagesEnabled, abrufe.length, termine.length],
    [inline.availableImages, inline.availableBrands, true, 0, 0]);
z = k.kachelZustand(stand({ availableImages: [], availableBrands: [], imagesEnabled: false }));
pruefe('Bilder aus: der Merker sagt aus', [z.shoppingExtras.self.imagesEnabled, abrufe.length], [false, 0]);
z = k.kachelZustand(stand({ availableImages: [], availableBrands: [] }));
pruefe('Ältere Fassung, Bilder aus (leere Karte, kein Merker): aus wie bisher', z.shoppingExtras.self.imagesEnabled, false);
z = k.kachelZustand(stand({ ...inline, availableImagesUrl: U1, imagesEnabled: true }));
pruefe('Karte UND Adresse (Rückfall des Moduls): die Karte im Stand gilt, kein Abruf', [z.images, abrufe.length],
    [inline.availableImages, 0]);

k = kachel();
offen = spaeter();
antworten[U1] = offen.antwort;
k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true }));
k.kachelZustand(stand(inline));
offen.einloesen(antwortVon(K1));
await ruhe();
pruefe('Kommt danach die Karte wieder im Stand, bleibt die Datei ungenutzt', [store.images, gezeichnet], [{}, 0]);

k = kachel('todo');
z = k.kachelZustand(stand({ availableImagesUrl: U1, imagesEnabled: true }));
pruefe('ToDo-Kachel: keine Bilder, kein Abruf', [z.images, z.brands, abrufe.length], [{}, {}, 0]);

// ── Verdrahtung in handleMessage ────────────────────────────────────────────
pruefe('handleMessage gibt das erste Bild nur frei, wenn keine Karte aussteht',
    html.includes("if (!kachelBildkarteHalt) document.documentElement.classList.remove('kachel-warte');"), true);

console.log(`\n${anzahl} Zusicherungen, ${fehler} Abweichung(en).`);
process.exit(fehler === 0 ? 0 : 1);
