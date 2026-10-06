/* SymDo Voice — Ton über das Tablet.
 *
 * Ein SymDo-Sprachgerät (ESP32) ist das Mikrofon, diese Kachel führt das
 * Gespräch, und die Antwort kommt aus dem Tablet. Das Gerät schickt nach dem
 * Weckwort 100-ms-Pakete (G.711 µ-law, 16 kHz) über Symcon hierher; daraus wird
 * ein MediaStream, den der Gesprächskern statt des eigenen Mikrofons nimmt.
 *
 *   Modul → {type:'espWake', nonce}       Gerät fragt, ob jemand übernimmt
 *   Kachel → EspMic {aktion:'start', nonce, client, schluessel, geheim}
 *   Modul → {type:'espGewaehlt', nonce, client}   nur EIN Fenster führt
 *   Modul → {type:'espAudio', n, d}       Pakete, XChaCha20 mit unserem Schlüssel
 *   Kachel → EspMic stop | keep (alle 15 s), nur mit dem Geheimnis aus der Zusage
 *   Modul → {type:'espEnde', nonce}       Taste am Gerät
 *
 * Ohne freigegebenen Ton (Autoplay-Sperre) meldet sich die Kachel nicht als
 * bereit: das Gerät spricht dann selbst.
 *
 * Kopplung: Nur ein gekoppelter Browser zählt als bereit und darf übernehmen —
 * wer übernimmt, hört den Raum. Den Code gibt es im Instanzformular; der
 * Browser erzeugt dazu einen eigenen Token und hebt ihn im localStorage auf.
 *   Kachel → EspKoppeln {code, client, token, name, rid}
 *   Modul → {type:'espKopplung', client, rid, ok, grund}   ('*' = alle entkoppelt)
 * rid ist eine zufällige Anfragekennung: Die Fensterkennung kennen alle Fenster,
 * die rid nur das fragende — so kann kein fremdes Fenster ein Tablet entkoppeln.
 *
 * Die Visu stellt jede Nachricht ZWEIMAL zu (handleMessage und postMessage,
 * gemessen 06.10.2026). Für Zustände egal, für Ton nicht: Pakete werden nach
 * ihrer Nummer entdoppelt, ein Weckruf nur einmal beantwortet. */
(function (wurzel) {
'use strict';

var RATE = 16000;

/* G.711 µ-law → [-1, 1) */
var ULAW = (function () {
  var t = new Float32Array(256);
  for (var i = 0; i < 256; i++) {
    var u = ~i & 0xff;
    var mag = ((((u & 0x0f) << 3) + 0x84) << ((u >> 4) & 7)) - 0x84;
    t[i] = ((u & 0x80) ? -mag : mag) / 32768;
  }
  return t;
})();

function bytesAus(b64) {
  var roh;
  try { roh = atob(String(b64 || '')); } catch (e) { return null; }
  var b = new Uint8Array(roh.length);
  for (var i = 0; i < roh.length; i++) { b[i] = roh.charCodeAt(i) & 0xff; }
  return b;
}

/* XChaCha20 wie libsodium crypto_stream_xchacha20_xor: HChaCha20 leitet aus
   Schlüssel und den ersten 16 Byte der Nonce einen Teilschlüssel ab, dann
   ChaCha20 (64-Bit-Zähler ab 0, die letzten 8 Byte als Nonce). Hier, weil
   WebCrypto in einer http-Visu fehlt und kein ChaCha kann. */
var SIGMA = [0x61707865, 0x3320646e, 0x79622d32, 0x6b206574];
function rotl(v, c) { return (v << c) | (v >>> (32 - c)); }
function qr(x, a, b, c, d) {
  x[a] = (x[a] + x[b]) | 0; x[d] = rotl(x[d] ^ x[a], 16);
  x[c] = (x[c] + x[d]) | 0; x[b] = rotl(x[b] ^ x[c], 12);
  x[a] = (x[a] + x[b]) | 0; x[d] = rotl(x[d] ^ x[a], 8);
  x[c] = (x[c] + x[d]) | 0; x[b] = rotl(x[b] ^ x[c], 7);
}
function runden(x) {
  for (var i = 0; i < 10; i++) {
    qr(x, 0, 4, 8, 12); qr(x, 1, 5, 9, 13); qr(x, 2, 6, 10, 14); qr(x, 3, 7, 11, 15);
    qr(x, 0, 5, 10, 15); qr(x, 1, 6, 11, 12); qr(x, 2, 7, 8, 13); qr(x, 3, 4, 9, 14);
  }
}
function le32(b, o) { return b[o] | (b[o + 1] << 8) | (b[o + 2] << 16) | (b[o + 3] << 24); }
function xchacha20(k, n, m) {
  var i, x = new Int32Array(16);
  for (i = 0; i < 4; i++) { x[i] = SIGMA[i]; x[12 + i] = le32(n, 4 * i); }
  for (i = 0; i < 8; i++) { x[4 + i] = le32(k, 4 * i); }
  runden(x);
  var st = new Int32Array(16);
  for (i = 0; i < 4; i++) { st[i] = SIGMA[i]; st[4 + i] = x[i]; st[8 + i] = x[12 + i]; }
  st[14] = le32(n, 16); st[15] = le32(n, 20);
  var aus = new Uint8Array(m.length), blk = new Int32Array(16);
  for (var pos = 0; pos < m.length; pos += 64) {
    blk.set(st);
    runden(blk);
    for (i = 0; i < 16; i++) { blk[i] = (blk[i] + st[i]) | 0; }
    for (var j = 0; j < 64 && pos + j < m.length; j++) { aus[pos + j] = m[pos + j] ^ ((blk[j >> 2] >>> (8 * (j & 3))) & 0xff); }
    st[12] = (st[12] + 1) | 0;
    if (st[12] === 0) { st[13] = (st[13] + 1) | 0; }
  }
  return aus;
}

/** Paket: 2 Byte Nummer, dann µ-law. */
function dekodierenRoh(roh) {
  if (!roh || roh.length < 3) { return null; }
  var n = roh.length - 2;
  var werte = new Float32Array(n);
  for (var i = 0; i < n; i++) { werte[i] = ULAW[roh[i + 2]]; }
  return { nr: (roh[0] << 8) | roh[1], werte: werte };
}
function dekodieren(b64) { return dekodierenRoh(bytesAus(b64)); }

/** Verschlüsseltes Paket der Kachel: Nonce und Daten Base64. */
function entschluesseln(schluessel, nB64, dB64) {
  var n = bytesAus(nB64), d = bytesAus(dB64);
  if (!schluessel || !n || n.length !== 24 || !d) { return null; }
  return dekodierenRoh(xchacha20(schluessel, n, d));
}

/* Zitterpuffer: Pakete kommen über Symcon ungleichmäßig (Werkzeugaufrufe
   derselben Instanz halten sie auf). Erst ab VORLAUF abspielen; wird es mehr als
   HOECHSTENS, das Alte verwerfen — Verzögerung ist hier schlimmer als eine Lücke. */
function Puffer(vorlauf, hoechstens) {
  this.stuecke = [];
  this.menge = 0;
  this.pos = 0;          // Lesestelle im ersten Stück (in Abtastwerten, mit Bruchteil)
  this.laeuft = false;
  this.vorlauf = vorlauf;
  this.hoechstens = hoechstens;
}
Puffer.prototype.rein = function (werte) {
  this.stuecke.push(werte);
  this.menge += werte.length;
  while (this.menge - this.pos > this.hoechstens && this.stuecke.length > 1) {
    var alt = this.stuecke.shift();
    this.menge -= alt.length;
    this.pos = 0;
  }
  if (!this.laeuft && this.menge - this.pos >= this.vorlauf) { this.laeuft = true; }
};
/* n Ausgabewerte mit Schrittweite schritt (Eingangsrate / Ausgaberate), linear interpoliert. */
Puffer.prototype.raus = function (ziel, schritt) {
  for (var i = 0; i < ziel.length; i++) {
    if (!this.laeuft || this.stuecke.length === 0) { ziel[i] = 0; continue; }
    var s = this.stuecke[0];
    var k = Math.floor(this.pos);
    var a = s[k], b = (k + 1 < s.length) ? s[k + 1] : (this.stuecke[1] ? this.stuecke[1][0] : a);
    ziel[i] = a + (b - a) * (this.pos - k);
    this.pos += schritt;
    while (this.stuecke.length && this.pos >= this.stuecke[0].length) {
      this.pos -= this.stuecke[0].length;
      this.menge -= this.stuecke[0].length;
      this.stuecke.shift();
    }
    if (this.stuecke.length === 0) { this.laeuft = false; this.pos = 0; this.menge = 0; }
  }
};

function erzeuge(opt) {
  var senden = opt.senden;                       // (ident, wert) → requestAction
  var kern = opt.kern;
  var aufHinweis = opt.onHinweis || function () {};
  var ui = opt.koppelUi || null;          // {panel, eingabe, knopf}
  var client = Math.random().toString(36).slice(2, 12) + Date.now().toString(36).slice(-4);

  var aktiv = false;          // ein Sprachgerät hat diese Kachel gewählt
  var ctx = null, proc = null, ziel = null, stumm = null;
  var puffer = null;
  var angebot = '';           // nonce, für die wir zugesagt haben
  var laufend = '';           // nonce des laufenden Gesprächs
  var spricht = false, sperreBis = 0;
  var bereitUhr = 0, keepUhr = 0;
  var gesehen = [];           // zuletzt angenommene Paketnummern
  var letzterWeckruf = '';
  var schluessel = null;      // je Gespräch neu, verlässt das Fenster nur zum Modul
  var geheim = '';            // dito; beweist stop/keep — die Fensterkennung kennen alle
  var instanz = 0;
  var token = '';             // Kopplung dieses Browsers (nur Modul und localStorage kennen ihn)
  var neuerToken = '';        // während der Code-Eingabe
  var koppelRid = '';         // Anfragekennung der laufenden Code-Eingabe
  var hierRids = [];          // Anfragekennungen der letzten Lebenszeichen

  function jetzt() { return Date.now(); }
  function zufall(n) { var b = new Uint8Array(n); crypto.getRandomValues(b); return b; }
  function hex(b) { return Array.prototype.map.call(b, function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join(''); }

  function tonBereit() { return !!(ctx && ctx.state === 'running'); }

  function bereitMelden() {
    if (aktiv && token && tonBereit() && !document.hidden) {
      var rid = hex(zufall(16));
      hierRids.push(rid);
      if (hierRids.length > 8) { hierRids.shift(); }
      senden('EspHier', JSON.stringify({ token: token, client: client, rid: rid }));
    }
  }

  function hinweisPruefen() {
    if (ui) { ui.panel.hidden = !(aktiv && !token); }
    aufHinweis(aktiv && token && !tonBereit() ? 'Einmal tippen: Ton fürs Sprachgerät freigeben' : '');
  }

  function speicherSchluessel() { return 'symdo.espMikro.token.' + (instanz || 0); }
  function tokenLaden() {
    try { token = String(localStorage.getItem(speicherSchluessel()) || ''); } catch (e) { token = token || ''; }
    if (!/^[0-9a-f]{64}$/.test(token)) { token = ''; }
  }
  function tokenSetzen(t) {
    token = t;
    try { if (t) { localStorage.setItem(speicherSchluessel(), t); } else { localStorage.removeItem(speicherSchluessel()); } } catch (e) {}
  }

  /* Kurzer Name fürs Instanzformular, damit man die Kopplungen auseinanderhält. */
  function geraeteName() {
    var ua = String((navigator && navigator.userAgent) || '');
    var geraet = /iPad/.test(ua) ? 'iPad' : /iPhone/.test(ua) ? 'iPhone' : /Android/.test(ua) ? 'Android'
      : /Mac OS X/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : 'Gerät';
    var browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome'
      : /Safari\//.test(ua) ? 'Safari' : 'Browser';
    return geraet + ' ' + browser;
  }

  function koppeln() {
    var code = String((ui && ui.eingabe.value) || '').replace(/\D/g, '');
    if (code.length !== 6) { aufHinweis('Bitte den 6-stelligen Code aus der Instanz eingeben.'); return; }
    neuerToken = hex(zufall(32));
    koppelRid = hex(zufall(16));
    senden('EspKoppeln', JSON.stringify({ code: code, client: client, token: neuerToken, name: geraeteName(), rid: koppelRid }));
  }
  if (ui) {
    ui.knopf.addEventListener('click', koppeln);
    ui.eingabe.addEventListener('keydown', function (e) { if (e.key === 'Enter') { koppeln(); } });
  }

  /* Autoplay: ohne Geste bleibt ein AudioContext „suspended". Jede Berührung
     der Kachel darf ihn wecken; danach bleibt er für diese Seite frei. */
  function tonFreigeben() {
    if (!aktiv) { return; }
    try {
      if (!ctx) {
        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) { return; }
        ctx = new AC();
        ctx.onstatechange = function () { hinweisPruefen(); bereitMelden(); };
      }
      if (ctx.state !== 'running' && ctx.resume) { ctx.resume().catch(function () {}); }
    } catch (e) {}
    hinweisPruefen();
    bereitMelden();
  }

  function aktivieren(ja, inst) {
    aktiv = !!ja;
    if (inst && inst !== instanz) { instanz = inst; tokenLaden(); }
    if (bereitUhr) { clearInterval(bereitUhr); bereitUhr = 0; }
    if (aktiv) {
      tonFreigeben();
      bereitUhr = setInterval(bereitMelden, 30000);
    }
    hinweisPruefen();
  }

  function stromAnlegen() {
    puffer = new Puffer(RATE * 0.15, RATE * 0.6);
    var schritt = RATE / ctx.sampleRate;
    proc = ctx.createScriptProcessor(2048, 0, 1);
    proc.onaudioprocess = function (ev) { puffer.raus(ev.outputBuffer.getChannelData(0), schritt); };
    ziel = ctx.createMediaStreamDestination();
    /* Ein ScriptProcessor rechnet nur, wenn er am Ausgang hängt: stumm an die
       Lautsprecher, damit das Tablet das Mikrofon nicht selbst abspielt. */
    stumm = ctx.createGain();
    stumm.gain.value = 0;
    proc.connect(ziel);
    proc.connect(stumm);
    stumm.connect(ctx.destination);
    return ziel.stream;
  }

  function stromAbbauen() {
    try { if (proc) { proc.disconnect(); proc.onaudioprocess = null; } } catch (e) {}
    try { if (stumm) { stumm.disconnect(); } } catch (e) {}
    proc = ziel = stumm = null;
    puffer = null;
  }

  function beenden(sagGeraet) {
    if (!laufend) { return; }
    if (sagGeraet) { senden('EspMic', JSON.stringify({ aktion: 'stop', nonce: laufend, client: client, geheim: geheim })); }
    laufend = '';
    schluessel = null;
    geheim = '';
    if (keepUhr) { clearInterval(keepUhr); keepUhr = 0; }
    stromAbbauen();
  }

  function uebernehmen(nonce) {
    laufend = nonce;
    gesehen = [];
    spricht = false;
    sperreBis = 0;
    var strom;
    try { strom = stromAnlegen(); } catch (e) { beenden(true); return; }
    keepUhr = setInterval(function () {
      if (laufend) { senden('EspMic', JSON.stringify({ aktion: 'keep', nonce: laufend, client: client, geheim: geheim })); }
    }, 15000);
    kern.start({ mikro: strom }).then(function (ok) {
      if (ok === false) { beenden(true); }
    });
  }

  /* Echo: das Gerät hört die Tablet-Lautsprecher. Solange SymDo spricht, und
     noch kurz danach (die Pakete sind 0,2–0,5 s unterwegs), geht Stille raus. */
  function zustand(z) {
    if (z === 'spricht') {
      spricht = true;
    } else if (spricht) {
      spricht = false;
      sperreBis = jetzt() + 800;
    }
    if ((z === 'ende' || z === 'fehler') && laufend) { beenden(true); }
  }

  function nachricht(d) {
    switch (d.type) {
      case 'espWake':
        if (d.nonce === letzterWeckruf) { return true; }
        if (!aktiv || !token || !tonBereit() || document.hidden || kern.istOffen() || laufend) { return true; }
        letzterWeckruf = angebot = String(d.nonce || '');
        schluessel = zufall(32);
        geheim = hex(zufall(16));
        senden('EspMic', JSON.stringify({ aktion: 'start', nonce: angebot, client: client,
          schluessel: hex(schluessel), geheim: geheim, token: token }));
        return true;
      case 'espGewaehlt':
        if (!angebot || d.nonce !== angebot) { return true; }
        angebot = '';
        if (d.client === client) { uebernehmen(String(d.nonce)); } else { schluessel = null; geheim = ''; }
        return true;
      case 'espAudio': {
        if (!laufend || !puffer) { return true; }
        var p = entschluesseln(schluessel, d.n, d.d);
        if (!p || gesehen.indexOf(p.nr) >= 0) { return true; }
        gesehen.push(p.nr);
        if (gesehen.length > 32) { gesehen.shift(); }
        if (spricht || jetzt() < sperreBis) { p.werte.fill(0); }
        puffer.rein(p.werte);
        return true;
      }
      case 'espKopplung': {
        if (d.client === '*') {   // „Alle Kopplungen aufheben" im Instanzformular
          tokenSetzen('');
          hinweisPruefen();
          return true;
        }
        if (d.client !== client || !d.rid) { return true; }
        if (d.rid === koppelRid) {
          koppelRid = '';
          if (d.ok === true && neuerToken) {
            tokenSetzen(neuerToken);
            if (ui) { ui.eingabe.value = ''; }
            tonFreigeben();
            aufHinweis('Gekoppelt — das Sprachgerät kann jetzt über dieses Tablet sprechen.');
          } else {
            aufHinweis(d.grund === 'falsch' ? 'Code falsch.' : 'Kein gültiger Code — bitte in der Instanz einen neuen erzeugen.');
            if (ui) { ui.panel.hidden = false; }
          }
          neuerToken = '';
          return true;
        }
        var i = hierRids.indexOf(d.rid);
        if (i >= 0 && d.grund === 'unbekannt') {
          hierRids.splice(i, 1);
          tokenSetzen('');   // das Modul kennt diesen Token nicht (mehr)
          hinweisPruefen();
        }
        /* Alles andere: fremde Anfrage oder zweite Zustellung derselben Antwort. */
        return true;
      }
      case 'espEnde':
        if (laufend && d.nonce === laufend) {
          beenden(false);
          if (kern.istOffen()) { kern.stop('Taste am Sprachgerät'); }
        }
        return true;
    }
    return false;
  }

  ['pointerdown', 'touchstart', 'keydown'].forEach(function (art) {
    document.addEventListener(art, tonFreigeben, { capture: true, passive: true });
  });
  document.addEventListener('visibilitychange', bereitMelden);

  return {
    aktivieren: aktivieren,
    nachricht: nachricht,
    zustand: zustand,
    laeuft: function () { return !!laufend; },
    gekoppelt: function () { return !!token; }
  };
}

wurzel.SymDoEspMikro = { erzeuge: erzeuge, _dekodieren: dekodieren, _Puffer: Puffer, _ULAW: ULAW, _xchacha20: xchacha20 };
})(typeof window !== 'undefined' ? window : globalThis);
