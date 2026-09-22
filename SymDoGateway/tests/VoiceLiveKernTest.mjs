// Prüfstand für den GPT-Live-Zweig des Browser-Kerns (23.09.2026).
// Lädt voice-core.js in Node mit dem nötigsten Fenster-Ersatz, speist die
// Live-Ereignisse in handleServerEvent und prüft, was der Kern zurücksendet.
//   node SymDoGateway/tests/VoiceLiveKernTest.mjs
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const hier = dirname(fileURLToPath(import.meta.url));
globalThis.window = globalThis;
globalThis.addEventListener = () => {};
globalThis.isSecureContext = true;
globalThis.document = { hidden: false, addEventListener() {} };
// Mikrofon-Attrappe: navigator ist in Node nur lesbar, daher neu definieren.
const spur = { enabled: true, stop() {} };
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: {
  mediaDevices: { getUserMedia: () => Promise.resolve({ getAudioTracks: () => [spur], getTracks: () => [spur] }) },
} });
// WebRTC-Attrappe: Angebot fertig, ICE sofort komplett, Antwort verbindet.
let letzterKanal = null;
globalThis.RTCPeerConnection = class {
  constructor() { this.localDescription = null; this.iceGatheringState = 'complete'; this.connectionState = 'new'; }
  addTrack() {}
  createDataChannel(name) { letzterKanal = { name, readyState: 'open', send() {}, close() {}, addEventListener() {} }; return letzterKanal; }
  createOffer() { return Promise.resolve({ type: 'offer', sdp: 'v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n' }); }
  setLocalDescription(a) { this.localDescription = a; return Promise.resolve(); }
  setRemoteDescription(a) { this.remoteDescription = a; this.connectionState = 'connected'; if (this.onconnectionstatechange) { this.onconnectionstatechange(); } return Promise.resolve(); }
  getSenders() { return []; }
  addEventListener() {} removeEventListener() {} close() {}
};
new Function(readFileSync(join(hier, '../libs/voice-core.js'), 'utf8'))();

let fehler = 0, zahl = 0;
function pruefe(ok, was) { zahl++; if (!ok) { fehler++; console.log('  FEHLT: ' + was); } }
const warte = (ms) => new Promise((r) => setTimeout(r, ms));

const posts = [], gesendet = [], zustaende = [], ereignisse = [];
const kern = window.SymDoVoiceKern.erzeuge({
  post(p) {
    posts.push(p);
    if (p.action === 'open') { return Promise.resolve({ ok: true, live: true, model: 'gpt-live-1', pingSeconds: 30, sessionSeconds: 300 }); }
    if (p.action === 'livesdp') {
      return Promise.resolve(String(p.sdp || '').startsWith('v=')
        ? { ok: true, sdp: 'v=0\r\no=- 2 2 IN IP4 0.0.0.0\r\n', callId: 'sess_live_1' }
        : { ok: false, sag: 'kein SDP' });
    }
    if (p.action === 'tool') { return Promise.resolve({ ok: true, sag: 'Milch steht drauf.' }); }
    return Promise.resolve({ ok: true });
  },
  onState(z, d) { zustaende.push(z + (d ? ':' + d : '')); },
  onEvent(e) { ereignisse.push(e); },
  tone: false,
});
kern._testSend = (o) => gesendet.push(o);

// 0. Aufbau: open → live:true → Angebot ans Gateway → Antwort gesetzt → opened mit live:true
const gestartet = await kern.start();
pruefe(gestartet === true, 'start() gelingt auf dem Live-Weg');
pruefe(kern.istLive() === true, 'Kern ist im Live-Modus');
pruefe(letzterKanal && letzterKanal.name === 'oai-events', 'Datenkanal oai-events angelegt');
const sdpPost = posts.find((p) => p.action === 'livesdp');
pruefe(!!sdpPost && String(sdpPost.sdp).startsWith('v=0'), 'Angebot ging als livesdp ans Gateway');
const opened = posts.find((p) => p.action === 'opened');
pruefe(!!opened && opened.live === true && opened.callId === 'sess_live_1', 'opened mit Live-Kennzeichen und Sitzungskennung');
pruefe(!posts.some((p) => p.action === 'open' && p.warm), 'kein Warmlauf nötig');
pruefe(!ereignisse.some((e) => e.art === 'bereit'), 'connected allein meldet im Live-Weg noch NICHT bereit');
pruefe(kern.istOffen() === true, 'Sitzung gilt als offen');

// 1. Sitzung startet → bereit, Live-Schalter an
kern.handleServerEvent({ type: 'session.started', session: { id: 'sess_1', model: 'gpt-live-1' } });
pruefe(kern.istLive() === true, 'session.started schaltet auf Live');
pruefe(zustaende.includes('hoert'), 'nach session.started: hört');
pruefe(ereignisse.some((e) => e.art === 'bereit'), 'bereit gemeldet');
pruefe(ereignisse.some((e) => e.art === 'sitzung' && e.modell === 'gpt-live-1'), 'Sitzungsereignis mit Modell');

// 2. Mitschrift des Nutzers: Deltas → eine Blase je Redezug
kern.handleServerEvent({ type: 'session.input_transcript.delta', delta: 'Was steht ' });
kern.handleServerEvent({ type: 'session.input_transcript.delta', delta: 'auf der Einkaufsliste?' });
const du = ereignisse.filter((e) => e.art === 'duDelta');
pruefe(du.length === 2 && du[0].item === du[1].item, 'zwei Deltas, EINE Blasenkennung');
pruefe(zustaende[zustaende.length - 1] === 'duSprichst', 'Zustand duSprichst');

// 3. Delegation → denkt; Werkzeugaufruf kommt im Umschlag
kern.handleServerEvent({ type: 'session.delegation.created', response_id: 'resp_1', target: 'responses' });
pruefe(zustaende[zustaende.length - 1] === 'denkt', 'Delegation → denkt');
kern.handleServerEvent({ type: 'response.event', delegation_id: 'd1', event: { type: 'response.created', response: { id: 'resp_1' } } });
kern.handleServerEvent({ type: 'response.event', delegation_id: 'd1', event: {
  type: 'response.output_item.done', response_id: 'resp_1',
  item: { type: 'function_call', call_id: 'call_1', name: 'liste_lesen', arguments: '{"liste":"Einkauf"}' } } });
await warte(5);
const werkzeug = posts.find((p) => p.action === 'tool');
pruefe(!!werkzeug && werkzeug.name === 'liste_lesen' && werkzeug.fnId === 'call_1', 'Werkzeug ans Gateway gepostet (Name + Aufrufkennung)');
const ergebnis = gesendet.find((o) => o.type === 'response.item.create');
pruefe(!!ergebnis && ergebnis.item.type === 'function_call_output' && ergebnis.item.call_id === 'call_1', 'Ergebnis als response.item.create (nicht conversation.item.create)');
pruefe(!gesendet.some((o) => o.type === 'conversation.item.create'), 'kein Realtime-Umschlag im Live-Weg');
pruefe(!gesendet.some((o) => o.type === 'response.create'), 'noch KEIN response.create vor response.completed');

// 4. Antwort abgeschlossen → genau ein response.create
kern.handleServerEvent({ type: 'response.event', delegation_id: 'd1', event: {
  type: 'response.completed', response: { id: 'resp_1', status: 'completed',
  output: [{ type: 'function_call', call_id: 'call_1', name: 'liste_lesen' }] } } });
pruefe(gesendet.filter((o) => o.type === 'response.create').length === 1, 'genau ein response.create nach dem Ergebnis');
// nochmal completed (Doppelzustellung) → kein zweites
kern.handleServerEvent({ type: 'response.event', event: { type: 'response.completed', response: { id: 'resp_1', status: 'completed', output: [{ type: 'function_call', call_id: 'call_1' }] } } });
pruefe(gesendet.filter((o) => o.type === 'response.create').length === 1, 'Doppelzustellung löst kein zweites response.create aus');

// 5. Antwort ohne Werkzeug → hört
kern.handleServerEvent({ type: 'response.event', event: { type: 'response.completed', response: { id: 'resp_2', status: 'completed', output: [{ type: 'message' }] } } });
pruefe(zustaende[zustaende.length - 1] === 'hoert', 'Antwort ohne Werkzeug → hört');
pruefe(gesendet.filter((o) => o.type === 'response.create').length === 1, 'ohne Werkzeug kein response.create');

// 6. SymDo spricht: Deltas → spricht, Pause → symdoFertig + hört
kern.handleServerEvent({ type: 'session.output_transcript.delta', delta: 'Milch ' });
kern.handleServerEvent({ type: 'session.output_transcript.delta', delta: 'und Brot.' });
pruefe(zustaende[zustaende.length - 1] === 'spricht', 'Ausgabe-Delta → spricht');
const sy = ereignisse.filter((e) => e.art === 'symdoDelta');
pruefe(sy.length === 2 && sy[0].antwort === sy[1].antwort, 'Ausgabe-Deltas in einer Blase');

// 7. Realtime-Ereignis output_item.done darf im Live-Weg ohne function_call nichts tun
kern.handleServerEvent({ type: 'response.output_item.done', item: { type: 'message' } });
pruefe(posts.filter((p) => p.action === 'tool').length === 1, 'kein zweiter Werkzeugaufruf durch Nachrichten-Element');

// 8. Anbieter schließt → Kern beendet mit Klartext, meldet dem Gateway
kern.handleServerEvent({ type: 'session.closed', reason: 'expired' });
await warte(5);
pruefe(zustaende.some((z) => z.startsWith('ende:')) , 'session.closed → ende');
pruefe(kern.istLive() === false, 'nach dem Ende ist der Live-Schalter aus');

// 8b. … und dem Gateway das Ende gemeldet, session.close ging vorher raus
pruefe(gesendet.some((o) => o.type === 'session.close'), 'session.close beim Ende der Live-Sitzung');
pruefe(posts.some((p) => p.action === 'close' && p.callId === 'sess_live_1'), 'Gateway bekommt close mit der Sitzungskennung');
pruefe(kern.istOffen() === false, 'Sitzung ist zu');

// 9. Ruhe-Knopf im Live-Weg (zweite Sitzung): Anweisung statt response.cancel
const gesendet2 = [], posts2 = [];
const kern2 = window.SymDoVoiceKern.erzeuge({
  post(p) { posts2.push(p); return Promise.resolve(p.action === 'open' ? { ok: true, live: true } : p.action === 'livesdp' ? { ok: true, sdp: 'v=0', callId: 'sess_2' } : { ok: true }); },
  onState() {}, onEvent() {}, tone: false });
kern2._testSend = (o) => gesendet2.push(o);
await kern2.start();
kern2.ruhe();
pruefe(gesendet2.some((o) => o.type === 'session.instructions.append' && o.delegation_id === null), 'Ruhe im Live-Weg: Anweisung statt response.cancel');
pruefe(!gesendet2.some((o) => o.type === 'response.cancel'), 'Ruhe im Live-Weg: kein response.cancel');
kern2.stop('Test');
pruefe(gesendet2.filter((o) => o.type === 'session.close').length === 1, 'stop() aus der Kachel: genau ein session.close');

// 10. Gateway verweigert das SDP → Start scheitert sauber mit Text, keine Sitzung
const zust3 = [];
const kern3 = window.SymDoVoiceKern.erzeuge({
  post(p) { return Promise.resolve(p.action === 'open' ? { ok: true, live: true } : { ok: false, sag: 'Die tägliche Sprechzeit ist aufgebraucht.' }); },
  onState(z, d) { zust3.push([z, d]); }, onEvent() {}, tone: false });
kern3._testSend = () => {};
const ok3 = await kern3.start();
pruefe(ok3 === false && zust3.some((z) => z[0] === 'fehler' && /Sprechzeit/.test(z[1] || '')), 'abgelehntes livesdp → Fehlerzustand mit dem Grund des Gateways');
pruefe(kern3.istOffen() === false, 'nach dem Fehlschlag ist die Sitzung zu');

console.log(fehler === 0 ? `OK — ${zahl} Prüfungen bestanden` : `FEHLER — ${fehler} von ${zahl} Prüfungen gefallen`);
process.exit(fehler === 0 ? 0 : 1);
