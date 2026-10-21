// „Zuletzt benutzt" sortieren (21.10.2026): A–Z als Vorgabe, „zuletzt gekauft
// zuerst" auf Wunsch, gemerkt je Gerät. Die beiden Funktionen werden aus der
// Web-App gelesen und hier mit einer Attrappe für `ls` gefahren; dazu Riegel,
// dass die Wagen-Liste und das Menü sie benutzen.
//
//   node SymDoWebApp/tests/WagenSortTest.mjs
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
  const m = html.match(new RegExp(`function ${name}\\([^)]*\\) \\{[\\s\\S]*?\\n\\}`));
  return m ? m[0] : '';
};
const speicher = {};
const ls = { get: (k) => (k in speicher ? speicher[k] : null), set: (k, v) => { speicher[k] = v; } };
const fabrik = new Function('ls', `${stueck('wagenSortierung')}\n${stueck('wagenSortiert')}\nreturn { wagenSortierung, wagenSortiert };`);
const { wagenSortierung, wagenSortiert } = fabrik(ls);

const wagen = [
  { name: 'Zwiebeln', boughtAt: 300 },
  { name: 'äpfel', boughtAt: 100 },
  { name: 'Brot 10', boughtAt: 0, addedAt: 500 },
  { name: 'Brot 9', boughtAt: 200 },
];
pruefe('Vorgabe ist A–Z', wagenSortierung(), 'az');
pruefe('A–Z: ohne Groß/Klein, Umlaut beim Grundbuchstaben, Zahlen natürlich',
  wagenSortiert(wagen).map((i) => i.name), ['äpfel', 'Brot 9', 'Brot 10', 'Zwiebeln']);
ls.set('symdo.cartSort', 'zuletzt');
pruefe('Zuletzt gekauft zuerst; ohne boughtAt zählt addedAt',
  wagenSortiert(wagen).map((i) => i.name), ['Brot 10', 'Zwiebeln', 'Brot 9', 'äpfel']);
ls.set('symdo.cartSort', 'quatsch');
pruefe('Unbekannter Wert fällt auf A–Z zurück', wagenSortierung(), 'az');
pruefe('Die Eingabe bleibt unverändert (Kopie wird sortiert)', wagen[0].name, 'Zwiebeln');

pruefe('Riegel: der Wagen wird sortiert, das Menü bietet beide Wahlen',
  [html.includes('const cart = wagenSortiert(('),
   html.includes("{ label: translate('A–Z'), checked: sortierung === 'az'"),
   html.includes("{ label: translate('Recently bought first'), checked: sortierung === 'zuletzt'")],
  [true, true, true]);
const kopie = readFileSync(new URL('../../SymDoShoppingList/module.html', import.meta.url), 'utf8');
pruefe('Riegel: die Einkaufs-Kachel trägt dieselbe Sortierung', kopie.includes('function wagenSortiert('), true);

console.log(`\n${anzahl} Zusicherungen, ${fehler} Abweichung(en).`);
process.exit(fehler === 0 ? 0 : 1);
