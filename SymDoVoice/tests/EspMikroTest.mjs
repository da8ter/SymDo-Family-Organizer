// Prüfstand für „Ton über das Tablet" (esp-mikro.js) und die fremde Tonquelle
// im Gesprächskern. Lädt beide Skripte in Node mit Fenster-, Audio- und
// WebRTC-Attrappen.
//   node SymDoVoice/tests/EspMikroTest.mjs
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { webcrypto } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const hier = dirname(fileURLToPath(import.meta.url));
let fehler = 0, zahl = 0;
function pruefe(ok, was) { zahl++; if (!ok) { fehler++; console.log('  FEHLT: ' + was); } }
const warte = (ms) => new Promise((r) => setTimeout(r, ms));

globalThis.window = globalThis;
globalThis.addEventListener = () => {};
globalThis.isSecureContext = false;   // lokale http-Visu: mit Sprachgerät trotzdem möglich
const dokListener = {};
globalThis.document = { hidden: false, addEventListener(art, fn) { (dokListener[art] = dokListener[art] || []).push(fn); } };
let gumGerufen = 0;
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: {
  mediaDevices: { getUserMedia: () => { gumGerufen++; return Promise.reject(new Error('kein Mikrofon')); } },
} });
let hinzugefuegt = [];
globalThis.RTCPeerConnection = class {
  constructor() { this.localDescription = null; this.iceGatheringState = 'complete'; this.connectionState = 'new'; }
  addTrack(t, s) { hinzugefuegt.push(s); }
  createDataChannel(name) { return { name, readyState: 'open', send() {}, close() {}, addEventListener() {} }; }
  createOffer() { return Promise.resolve({ type: 'offer', sdp: 'v=0\r\n' }); }
  setLocalDescription(a) { this.localDescription = a; return Promise.resolve(); }
  setRemoteDescription() { this.connectionState = 'connected'; if (this.onconnectionstatechange) { this.onconnectionstatechange(); } return Promise.resolve(); }
  getSenders() { return []; }
  addEventListener() {} removeEventListener() {} close() {}
};
globalThis.fetch = () => Promise.resolve({ ok: true, status: 201, headers: { get: () => '/v1/realtime/calls/rtc_1' }, text: () => Promise.resolve('v=0\r\n') });

// Audio-Attrappe: genug für ScriptProcessor → MediaStreamDestination
let tonZustand = 'suspended';
const verbunden = [];
class Knoten { connect(z) { verbunden.push([this.art, z.art]); } disconnect() {} }
globalThis.AudioContext = class {
  constructor() { this.sampleRate = 48000; this.destination = Object.assign(new Knoten(), { art: 'lautsprecher' }); this.onstatechange = null; }
  get state() { return tonZustand; }
  resume() { return Promise.resolve(); }
  createScriptProcessor() { const k = Object.assign(new Knoten(), { art: 'proc' }); globalThis.__proc = k; return k; }
  createMediaStreamDestination() {
    const spur = { enabled: true, stop() {} };
    return Object.assign(new Knoten(), { art: 'ziel', stream: { getAudioTracks: () => [spur], getTracks: () => [spur], __esp: true } });
  }
  createGain() { return Object.assign(new Knoten(), { art: 'stumm', gain: { value: 1 } }); }
};
globalThis.atob = (b) => Buffer.from(b, 'base64').toString('binary');
if (!globalThis.crypto) { globalThis.crypto = webcrypto; }

new Function(readFileSync(join(hier, '../../SymDoGateway/libs/voice-core.js'), 'utf8'))();
new Function(readFileSync(join(hier, '../esp-mikro.js'), 'utf8'))();
const E = window.SymDoEspMikro;

// ── µ-law: genau der Kodierer der Firmware (tablet.c), hier nachgebaut ──
function ulaw(s) {
  const vorz = (s >> 8) & 0x80;
  let v = vorz ? -s : s;
  if (v > 32635) { v = 32635; }
  v += 0x84;
  let exp = 7;
  for (let m = 0x4000; (v & m) === 0 && exp > 0; m >>= 1) { exp--; }
  const mant = (v >> (exp + 3)) & 0x0f;
  return (~(vorz | (exp << 4) | mant)) & 0xff;
}
let schlimmst = 0;
for (const s of [0, 1, -1, 100, -100, 1000, -1000, 8000, -8000, 20000, -20000, 32767, -32768]) {
  const zurueck = E._ULAW[ulaw(s)] * 32768;
  const erlaubt = Math.max(8, Math.abs(s) / 16);   // µ-law: ~6 % relativer Fehler
  schlimmst = Math.max(schlimmst, Math.abs(zurueck - Math.max(-32635, Math.min(32635, s))) / erlaubt);
}
pruefe(schlimmst <= 1, 'µ-law: Firmware-Kodierer und Kachel-Dekodierer passen zusammen');
pruefe(E._ULAW[ulaw(5000)] > 0 && E._ULAW[ulaw(-5000)] < 0, 'µ-law: Vorzeichen bleibt');

function roh(nr, werte) {
  const b = Buffer.alloc(2 + werte.length);
  b[0] = nr >> 8; b[1] = nr & 0xff;
  werte.forEach((s, i) => { b[2 + i] = ulaw(s); });
  return b;
}
const p = E._dekodieren(roh(258, new Array(1600).fill(4000)).toString('base64'));
pruefe(p && p.nr === 258 && p.werte.length === 1600, 'Paket: Nummer und 1600 Werte');
pruefe(E._dekodieren('') === null && E._dekodieren('%%%') === null, 'Paket: Unsinn wird verworfen');

// ── XChaCha20: dasselbe Ergebnis wie libsodium in PHP (TabletCalc::Verschluesseln) ──
const kHex = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
const nHex = '404142434445464748494a4b4c4d4e4f5051525354555657';
const klar = Buffer.from(Array.from({ length: 300 }, (_, i) => (i * 7) & 0xff));
const vonPhp = execFileSync('php', ['-r', `echo bin2hex(sodium_crypto_stream_xchacha20_xor(hex2bin('${klar.toString('hex')}'), hex2bin('${nHex}'), hex2bin('${kHex}')));`]).toString();
const vonJs = Buffer.from(E._xchacha20(Buffer.from(kHex, 'hex'), Buffer.from(nHex, 'hex'), klar)).toString('hex');
pruefe(vonPhp.length === 600 && vonJs === vonPhp, 'XChaCha20 der Kachel = libsodium (300 Byte, über Blockgrenzen)');

// ── Zitterpuffer ──
const P = new E._Puffer(2400, 9600);
const raus = new Float32Array(480);
P.rein(new Float32Array(1600).fill(0.5));
P.raus(raus, 1 / 3);
pruefe(raus.every((x) => x === 0), 'Puffer: unter dem Vorlauf kommt Stille');
P.rein(new Float32Array(1600).fill(0.5));
P.raus(raus, 1 / 3);
pruefe(raus.every((x) => Math.abs(x - 0.5) < 1e-6), 'Puffer: ab Vorlauf kommt der Ton, auf 48 kHz gestreckt');
for (let i = 0; i < 20; i++) { P.rein(new Float32Array(1600).fill(0.25)); }
pruefe(P.menge - P.pos <= 9600 + 1600, 'Puffer: Rückstau wird gekappt (Verzögerung wächst nicht)');
const leer = new E._Puffer(10, 100000);
leer.rein(new Float32Array(20).fill(1));
const lang = new Float32Array(200);
leer.raus(lang, 1);
pruefe(lang[0] === 1 && lang[199] === 0 && leer.laeuft === false, 'Puffer: leergelaufen → Stille und wieder Vorlauf');

// ── Ablauf mit Kern und Modul-Attrappe ──
const posts = [];
const kern = window.SymDoVoiceKern.erzeuge({
  post(pl) {
    posts.push(pl);
    if (pl.action === 'open') { return Promise.resolve({ ok: true, value: 'ek_1', model: 'gpt-realtime-mini', pingSeconds: 30, sessionSeconds: 300 }); }
    return Promise.resolve({ ok: true });
  },
  onState(z) { esp && esp.zustand(z); },
});
const gesendet = [];
let hinweis = '';
let esp = null;
// Kopplungsfeld und localStorage als Attrappen
const speicher = new Map();
globalThis.localStorage = { getItem: (k) => (speicher.has(k) ? speicher.get(k) : null), setItem: (k, v) => speicher.set(k, String(v)), removeItem: (k) => speicher.delete(k) };
const knopfListener = {};
const ui = {
  panel: { hidden: true },
  eingabe: { value: '', addEventListener() {} },
  knopf: { addEventListener(art, fn) { knopfListener[art] = fn; } },
};
esp = E.erzeuge({ kern, koppelUi: ui, senden: (i, w) => gesendet.push([i, w]), onHinweis: (t) => { hinweis = t; } });
const letzte = (ident) => { const z = gesendet.filter(([i]) => i === ident); return z.length ? JSON.parse(z[z.length - 1][1]) : null; };

esp.aktivieren(true, 12173);
pruefe(ui.panel.hidden === false, 'ungekoppelt: Kopplungsfeld sichtbar');
tonZustand = 'running';
dokListener.pointerdown.forEach((f) => f());
pruefe(!gesendet.some(([i]) => i === 'EspHier'), 'ungekoppelt: kein Lebenszeichen, auch mit freigegebenem Ton (Gerät spricht selbst)');
esp.nachricht({ type: 'espWake', nonce: 'aaaa0000' });
pruefe(!gesendet.some(([i]) => i === 'EspMic'), 'ungekoppelt: Weckruf wird nicht angenommen');

ui.eingabe.value = '12 34';
knopfListener.click();
pruefe(!gesendet.some(([i]) => i === 'EspKoppeln') && hinweis.includes('6-stellig'), 'zu kurzer Code: nichts gesendet, Hinweis');
ui.eingabe.value = '123456';
knopfListener.click();
const kopp = letzte('EspKoppeln');
pruefe(/^[0-9a-f]{32}$/.test(kopp.rid || ''), 'Kopplung trägt eine zufällige Anfragekennung');
pruefe(kopp && kopp.code === '123456' && /^[0-9a-f]{64}$/.test(kopp.token) && kopp.name.length > 0, 'Kopplung: Code, selbst erzeugter Token und Gerätename gehen ans Modul');
esp.nachricht({ type: 'espKopplung', client: 'fremdesfenster', ok: true });
pruefe(!esp.gekoppelt(), 'Antwort für ein anderes Fenster zählt nicht');
esp.nachricht({ type: 'espKopplung', client: kopp.client, rid: kopp.rid, ok: false, grund: 'falsch' });
esp.nachricht({ type: 'espKopplung', client: kopp.client, rid: kopp.rid, ok: false, grund: 'falsch' });
pruefe(!esp.gekoppelt() && hinweis.includes('falsch') && ui.panel.hidden === false, 'falscher Code (doppelt zugestellt): Hinweis bleibt stehen');
knopfListener.click();
const kopp2 = letzte('EspKoppeln');
pruefe(kopp2.token !== kopp.token, 'jeder Versuch mit frischem Token');
esp.nachricht({ type: 'espKopplung', client: kopp2.client, rid: 'ffffffffffffffffffffffffffffffff', ok: true });
pruefe(!esp.gekoppelt(), 'Erfolg mit fremder Anfragekennung zählt nicht');
esp.nachricht({ type: 'espKopplung', client: kopp2.client, rid: kopp2.rid, ok: true });
esp.nachricht({ type: 'espKopplung', client: kopp2.client, rid: kopp2.rid, ok: true });
pruefe(esp.gekoppelt() && speicher.get('symdo.espMikro.token.12173') === kopp2.token && ui.panel.hidden === true, 'gekoppelt: Token im localStorage je Instanz, Feld weg');
const lebenszeichen = letzte('EspHier');
pruefe(lebenszeichen && lebenszeichen.token === kopp2.token && lebenszeichen.client === kopp2.client, 'gekoppelt: Lebenszeichen mit Token');

// Neu laden: Token kommt aus dem Speicher
const esp2 = E.erzeuge({ kern, koppelUi: { panel: { hidden: true }, eingabe: { value: '', addEventListener() {} }, knopf: { addEventListener() {} } }, senden: () => {} });
esp2.aktivieren(true, 12173);
pruefe(esp2.gekoppelt(), 'nach dem Neuladen gekoppelt (localStorage)');

esp.nachricht({ type: 'espWake', nonce: 'abcd1234' });
const zusage = gesendet.filter(([i]) => i === 'EspMic').map(([, w]) => JSON.parse(w));
pruefe(zusage.length === 1 && zusage[0].aktion === 'start' && zusage[0].nonce === 'abcd1234' && /^[a-z0-9]{6,32}$/.test(zusage[0].client), 'Weckwort: Zusage mit Nonce und Fensterkennung');
const ich = zusage[0].client;
pruefe(/^[0-9a-f]{64}$/.test(zusage[0].schluessel || ''), 'Zusage trägt einen frischen 32-Byte-Schlüssel');
pruefe(/^[0-9a-f]{32}$/.test(zusage[0].geheim || ''), 'Zusage trägt ein Geheimnis für stop/keep');
pruefe(zusage[0].token === kopp2.token, 'Zusage trägt den Kopplungs-Token');
const schluesselVon = (nonce) => {
  const z = gesendet.filter(([i, w]) => i === 'EspMic' && JSON.parse(w).aktion === 'start' && JSON.parse(w).nonce === nonce).map(([, w]) => JSON.parse(w));
  return z.length ? Buffer.from(z[z.length - 1].schluessel, 'hex') : null;
};
let aktSchluessel = null;
function paket(nr, werte) {
  const n = Buffer.from(Array.from({ length: 24 }, () => Math.floor(Math.random() * 256)));
  return { n: n.toString('base64'), d: Buffer.from(E._xchacha20(aktSchluessel, n, roh(nr, werte))).toString('base64') };
}

esp.nachricht({ type: 'espGewaehlt', nonce: 'abcd1234', client: 'anderesfenster' });
await warte(20);
pruefe(!kern.istOffen() && !esp.laeuft(), 'anderes Fenster gewählt: dieses bleibt still');

const vorWeck = gesendet.length;
esp.nachricht({ type: 'espWake', nonce: 'beef0001' });
esp.nachricht({ type: 'espWake', nonce: 'beef0001' });
pruefe(gesendet.length === vorWeck + 1, 'doppelt zugestellter Weckruf: nur eine Zusage');
esp.nachricht({ type: 'espGewaehlt', nonce: 'beef0001', client: ich });
esp.nachricht({ type: 'espGewaehlt', nonce: 'beef0001', client: ich });
aktSchluessel = schluesselVon('beef0001');
await warte(50);
pruefe(esp.laeuft() && kern.istOffen(), 'gewählt: Gespräch läuft');
pruefe(gumGerufen === 0, 'kein Browser-Mikrofon gefragt (auch auf http)');
pruefe(hinzugefuegt.some((s) => s && s.__esp), 'der Ton des Geräts geht als Mikrofon zum Anbieter');
pruefe(verbunden.some(([a, b]) => a === 'proc' && b === 'ziel') && verbunden.some(([a, b]) => a === 'stumm' && b === 'lautsprecher'),
  'Prozessor hängt am Strom, Lautsprecher nur stumm');

// Pakete fließen in den Puffer, Echo-Sperre während SymDo spricht
const proc = globalThis.__proc;
const ausgabe = { outputBuffer: { getChannelData: () => buf } };
let buf = new Float32Array(2048);
for (let i = 0; i < 3; i++) { esp.nachricht({ type: 'espAudio', ...paket(i, new Array(1600).fill(8000)) }); }
proc.onaudioprocess(ausgabe);
pruefe(buf.some((x) => x > 0.2), 'Pakete kommen als Ton am Strom an');
// Ein Paket mit fremdem Schlüssel ist Rauschen, kein Ton aus dem Raum
{
  const echt = aktSchluessel; aktSchluessel = Buffer.alloc(32, 7);
  const fremd = E._xchacha20(echt, Buffer.alloc(24, 1), E._xchacha20(aktSchluessel, Buffer.alloc(24, 1), roh(1, new Array(1600).fill(8000))));
  aktSchluessel = echt;
  pruefe(Buffer.from(fremd).subarray(2).some((b) => b !== ulaw(8000)), 'falscher Schlüssel ergibt keinen lesbaren Ton');
}
// Doppelzustellung der Visu: dasselbe Paket zweimal darf nicht zweimal in den Puffer
const zaehle = () => { let n = 0; const b = new Float32Array(48000); const alt = buf; buf = b; proc.onaudioprocess(ausgabe); buf = alt; for (const x of b) { if (x !== 0) { n++; } } return n; };
zaehle();   // Puffer leeren
for (let i = 0; i < 3; i++) { const pk = paket(100 + i, new Array(1600).fill(8000)); esp.nachricht({ type: 'espAudio', ...pk }); esp.nachricht({ type: 'espAudio', ...pk }); }
const tonWerte = zaehle();
pruefe(Math.abs(tonWerte - 3 * 1600 * 3) <= 3, 'doppelt zugestellte Pakete zählen einmal (' + tonWerte + ' Werte bei 48 kHz, erwartet 14400)');
kern.handleServerEvent({ type: 'output_audio_buffer.started' });
for (let i = 0; i < 10; i++) { esp.nachricht({ type: 'espAudio', ...paket(10 + i, new Array(1600).fill(8000)) }); }
for (let i = 0; i < 12; i++) { buf = new Float32Array(2048); proc.onaudioprocess(ausgabe); }
pruefe(buf.every((x) => x === 0), 'während SymDo spricht: Stille statt Echo');
kern.handleServerEvent({ type: 'output_audio_buffer.stopped' });
esp.nachricht({ type: 'espAudio', ...paket(30, new Array(1600).fill(8000)) });
buf = new Float32Array(2048); proc.onaudioprocess(ausgabe);
pruefe(buf.every((x) => x === 0), 'kurz nach dem Sprechen: noch gesperrt (Pakete sind unterwegs)');

// Taste am Gerät beendet
esp.nachricht({ type: 'espEnde', nonce: 'falsch00' });
pruefe(kern.istOffen(), 'Ende mit fremder Nonce wird ignoriert');
esp.nachricht({ type: 'espEnde', nonce: 'beef0001' });
await warte(20);
pruefe(!kern.istOffen() && !esp.laeuft(), 'Taste am Gerät beendet das Gespräch');
pruefe(!gesendet.some(([i, w]) => i === 'EspMic' && JSON.parse(w).aktion === 'stop'), 'nach Taste kein stop zurück (Gerät ist schon aus)');

// Ende in der Kachel → stop ans Gerät
esp.nachricht({ type: 'espWake', nonce: 'cafe0002' });
esp.nachricht({ type: 'espGewaehlt', nonce: 'cafe0002', client: ich });
await warte(50);
kern.stop('vom Nutzer beendet');
await warte(20);
const stop = gesendet.filter(([i]) => i === 'EspMic').map(([, w]) => JSON.parse(w)).filter((m) => m.aktion === 'stop');
const zusageCafe = gesendet.map(([, w]) => { try { return JSON.parse(w); } catch (e) { return {}; } }).find((m) => m.aktion === 'start' && m.nonce === 'cafe0002');
pruefe(stop.length === 1 && stop[0].nonce === 'cafe0002' && stop[0].client === ich && stop[0].geheim === zusageCafe.geheim, 'Gespräch in der Kachel beendet → stop mit dem Geheimnis der Zusage');

// Läuft schon ein Gespräch, wird kein zweites angenommen
await warte(10);
globalThis.isSecureContext = true;
const eigeneSpur = { enabled: true, stop() {} };
navigator.mediaDevices.getUserMedia = () => Promise.resolve({ getAudioTracks: () => [eigeneSpur], getTracks: () => [eigeneSpur] });
kern.start();
await warte(30);
pruefe(kern.istOffen(), 'eigenes Gespräch läuft (Vorbedingung)');
const vorher = gesendet.length;
esp.nachricht({ type: 'espWake', nonce: 'dead0003' });
pruefe(gesendet.length === vorher, 'laufendes Gespräch (eigenes Mikrofon): Weckwort des Geräts wird nicht angenommen');

// Angriff: ein fremdes Fenster kennt unsere Kennung (aus espGewaehlt) und schickt
// ein ungültiges Lebenszeichen damit — die Antwort trägt SEINE rid, nicht unsere
esp.nachricht({ type: 'espKopplung', client: ich, rid: '0123456789abcdef0123456789abcdef', ok: false, grund: 'unbekannt' });
esp.nachricht({ type: 'espKopplung', client: ich, ok: false, grund: 'unbekannt' });
pruefe(esp.gekoppelt(), 'fremde „unbekannt"-Antwort entkoppelt dieses Tablet nicht');
// Echt: das Modul kennt unseren Token nicht mehr und antwortet auf UNSER Lebenszeichen
dokListener.visibilitychange.forEach((f) => f());
const meinHier = letzte('EspHier');
esp.nachricht({ type: 'espKopplung', client: ich, rid: meinHier.rid, ok: false, grund: 'unbekannt' });
pruefe(!esp.gekoppelt() && ui.panel.hidden === false, 'Antwort auf das eigene Lebenszeichen: Token verworfen, Feld wieder da');
// wieder koppeln für den Rest
ui.eingabe.value = '654321';
knopfListener.click();
const kopp3 = letzte('EspKoppeln');
esp.nachricht({ type: 'espKopplung', client: kopp3.client, rid: kopp3.rid, ok: true });
pruefe(esp.gekoppelt(), 'erneut gekoppelt');

// Alle Kopplungen aufgehoben: Token weg, Feld wieder da
esp.nachricht({ type: 'espKopplung', client: '*', ok: false });
pruefe(!esp.gekoppelt() && !speicher.has('symdo.espMikro.token.12173') && ui.panel.hidden === false, 'entkoppelt: Token gelöscht, Kopplungsfeld wieder sichtbar');

console.log((fehler === 0 ? 'OK' : 'FEHLER') + ' — ' + zahl + ' Prüfungen, ' + fehler + ' fehlgeschlagen');
process.exit(fehler === 0 ? 0 : 1);
