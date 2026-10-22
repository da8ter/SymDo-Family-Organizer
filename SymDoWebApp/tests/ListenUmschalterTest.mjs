// Listen-Umschalter der Einkaufs-Kachel (22.10.2026): welcher Stand gezeigt wird,
// wohin Aktionen und Abfragen gehen. Die Funktionen werden aus der Web-App gelesen
// und mit Attrappen für ls, requestAction und die Uhr gefahren; dazu Riegel für die
// Stellen in handleMessage und renderShopping und die Kopie in der Einkaufs-Kachel.
//
//   node SymDoWebApp/tests/ListenUmschalterTest.mjs
import { readFileSync } from 'node:fs';

const html = readFileSync(new URL('../module.html', import.meta.url), 'utf8');
let fehler = 0;
let anzahl = 0;
const pruefe = (name, ist, soll) => {
  anzahl++;
  const a = JSON.stringify(ist), b = JSON.stringify(soll);
  if (a !== b) { fehler++; console.log(`FEHL ${name}\n     ist:  ${a}\n     soll: ${b}`); } else { console.log(`OK   ${name}`); }
};
const stueck = (name) => {
  const m = html.match(new RegExp(`\\nfunction ${name}\\([^)]*\\) \\{[\\s\\S]*?\\n\\}`));
  if (!m) throw new Error('nicht gefunden: ' + name);
  return m[0];
};
const block = html.slice(html.indexOf('let kachelListen = [];'), html.indexOf('/** Auswahl in der Kopfzeile'));

const welt = () => {
  const speicher = {}, gesendet = [], uhren = [];
  const ls = { get: (k) => (k in speicher ? speicher[k] : null), set: (k, v) => { speicher[k] = v; } };
  const requestAction = (ident, wert) => gesendet.push([ident, wert]);
  const setTimeout = (f) => { uhren.push(f); return uhren.length; };
  const clearTimeout = () => {};
  const f = new Function('ls', 'requestAction', 'setTimeout', 'clearTimeout', 'KACHEL', `
    let kachelStateHash = '';
    ${block}
    ${stueck('requestState')}
    ${stueck('sdCall')}
    let txnCounter = 0;
    return { kachelListenUebernehmen, kachelFremd, kachelLaden, requestState, sdCall,
      zustand: () => ({ kachelListen, kachelHeim, kachelAktiv, kachelLaedt }),
      setze: (id) => { kachelAktiv = id; } };`);
  return { ...f(ls, requestAction, setTimeout, clearTimeout, { art: 'shopping' }), speicher, gesendet, uhren };
};
const listen = [{ id: 20000, name: 'Büro' }, { id: 10000, name: 'Zuhause' }];
const stand = (listId, mehr = {}) => ({ type: 'state', items: [], lists: listen, homeId: 10000, listId, ...mehr });

let w = welt();
pruefe('Ohne Umschalter-Angaben: Stand zeigen, kein Umschalter',
  [w.kachelListenUebernehmen({ type: 'state', items: [] }), w.zustand().kachelListen, w.kachelFremd()], [true, [], false]);

w = welt();
pruefe('Erster Stand der eigenen Liste: zeigen, eigene Liste gewählt',
  [w.kachelListenUebernehmen(stand(10000)), w.zustand().kachelAktiv, w.kachelFremd()], [true, 10000, false]);
pruefe('Push einer fremden Liste (für einen anderen Client): nicht zeigen', w.kachelListenUebernehmen(stand(20000)), false);

w = welt();
w.speicher['symdo.kachelListe.10000'] = '20000';
pruefe('Gemerkte Wahl: der Anfangsstand der eigenen Liste wird nicht gezeigt',
  [w.kachelListenUebernehmen(stand(10000)), w.zustand().kachelAktiv, w.kachelFremd()], [false, 20000, true]);
w.kachelLaden();
pruefe('Laden fragt den Stand der gewählten Liste an', w.gesendet.at(-1), ['GetState', JSON.stringify({ list: 20000 })]);
pruefe('Stand der gewählten Liste wird gezeigt', w.kachelListenUebernehmen(stand(20000)), true);
w.sdCall('self', 'AddItem', { name: 'Kaffee' });
pruefe('Aktion geht als ListCall an die gewählte Liste',
  w.gesendet.at(-1), ['ListCall', JSON.stringify({ list: 20000, action: 'AddItem', payload: JSON.stringify({ name: 'Kaffee' }) })]);
w.sdCall('self', 'DeleteItem', 'abc');
pruefe('Skalare Nutzlast bleibt Text', JSON.parse(w.gesendet.at(-1)[1]).payload, 'abc');

w = welt();
w.speicher['symdo.kachelListe.10000'] = '30000';
pruefe('Gemerkte Liste gibt es nicht mehr: zurück zur eigenen',
  [w.kachelListenUebernehmen(stand(10000)), w.zustand().kachelAktiv], [true, 10000]);
w.sdCall('self', 'AddItem', { name: 'Brot' });
pruefe('Eigene Liste: Aktion direkt wie bisher', w.gesendet.at(-1)[0], 'AddItem');

w = welt();
w.speicher['symdo.kachelListe.10000'] = '20000';
w.kachelListenUebernehmen(stand(10000));
w.kachelLaden();
w.uhren.at(-1)();
pruefe('Kommt kein Stand: nach der Frist zurück zur eigenen Liste',
  [w.zustand().kachelAktiv, w.gesendet.at(-1)], [10000, ['GetState', 0]]);

w = welt();
w.speicher['symdo.kachelListe.10000'] = '20000';
w.kachelListenUebernehmen(stand(20000));
pruefe('Umschalter abgeschaltet (Stand ohne lists): eigene Liste, kein ListCall mehr',
  [w.kachelListenUebernehmen({ type: 'state', items: [], listId: undefined }), w.kachelFremd()], [true, false]);

pruefe('Riegel: handleMessage filtert vor dem Prüfwert und kennt listChanged',
  [html.indexOf("if (data.type === 'listChanged')") > 0,
   html.indexOf('!kachelListenUebernehmen(data)') < html.indexOf("typeof data.stateHash === 'string') kachelStateHash = data.stateHash")],
  [true, true]);
pruefe('Riegel: renderShopping zeigt den Umschalter und „Laden"',
  [html.includes('if (KACHEL) kachelUmschalter();'), html.includes('if (KACHEL && kachelLaedt) {')], [true, true]);
const kopie = readFileSync(new URL('../../SymDoShoppingList/module.html', import.meta.url), 'utf8');
pruefe('Riegel: die Einkaufs-Kachel trägt den Umschalter', kopie.includes('function kachelListenUebernehmen('), true);

console.log(`\n${anzahl} Zusicherungen, ${fehler} Abweichung(en).`);
process.exit(fehler === 0 ? 0 : 1);
