// Prüfstand: handleMessage der VRR-Kachel nimmt nur echte Fahrplanstände an.
// Das Tile-SDK reicht jede Fensternachricht weiter — bei Touch auch seine eigenen
// Scroll-/Positionsmeldungen. Vorher machten sie die Tafel leer („Keine Haltestelle
// eingerichtet"), Nutzermeldung 06.10.2026.
//   node SymDoVRRTransit/tests/KachelNachrichtTest.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const html = readFileSync(new URL('../module.html', import.meta.url), 'utf8');
const fn = html.match(/function handleMessage\(daten\) \{[\s\S]*?\n\}/);
let fehler = 0, zahl = 0;
const pruefe = (ok, was) => { zahl++; if (!ok) { fehler++; console.log('  FEHLT: ' + was); } };
pruefe(!!fn, 'handleMessage gefunden');

const ctx = {};
vm.createContext(ctx);
vm.runInContext('var zustand = { v: 1, stops: [], routes: [] }; var kachelStateHash = ""; var gezeichnet = 0;'
  + 'function zeichne(){ gezeichnet++; } function nachfassen(){}' + fn[0], ctx);
const lauf = (code) => vm.runInContext(code, ctx);

lauf('handleMessage({ v: 1, now: 1, view: "departures", stops: [{ id: "s1", name: "Hbf" }], routes: [] })');
pruefe(lauf('zustand.stops.length') === 1, 'echter Stand wird angenommen');
lauf('handleMessage(JSON.stringify({ v: 1, now: 2, stops: [{ id: "s1" }, { id: "s2" }], routes: [] }))');
pruefe(lauf('zustand.stops.length') === 2, 'Stand als JSON-Text wird angenommen');

const vorher = lauf('gezeichnet');
for (const fremd of [
  '{ type: "position", position: 120, positionType: "move", token: "x" }',
  '{ type: "scroll", delta: 4, token: "x" }',
  '{ type: "openObject", objectID: 12345, token: "x" }',
  '{ ident: "GetState", value: "{}", token: "x" }',
  '{ v: "1", stops: [] }',
  '{ v: 1 }',
  '"kein json"',
  'null',
]) {
  lauf('handleMessage(' + fremd + ')');
}
pruefe(lauf('zustand.stops.length') === 2, 'SDK-, Fremd- und kaputte Nachrichten ändern den Stand nicht');
pruefe(lauf('gezeichnet') === vorher, 'und lösen kein Neuzeichnen aus');

lauf('handleMessage({ v: 1, now: 3, stops: [], routes: [{ id: "r1" }] })');
pruefe(lauf('zustand.routes.length') === 1 && lauf('zustand.stops.length') === 0, 'ein Stand nur mit Strecken ist gültig');

console.log((fehler === 0 ? 'OK' : 'FEHLER') + ': ' + zahl + ' Prüfungen, ' + fehler + ' fehlgeschlagen');
process.exit(fehler === 0 ? 0 : 1);
