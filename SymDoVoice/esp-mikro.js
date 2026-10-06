/* SymDo Voice — Ton über das Tablet.
 *
 * Ein SymDo-Sprachgerät (ESP32) ist das Mikrofon, diese Kachel führt das
 * Gespräch, und die Antwort kommt aus dem Tablet. Das Gerät schickt nach dem
 * Weckwort 100-ms-Pakete (G.711 µ-law, 16 kHz) über Symcon hierher; daraus wird
 * ein MediaStream, den der Gesprächskern statt des eigenen Mikrofons nimmt.
 *
 *   Modul → {type:'espWake', nonce}       Gerät fragt, ob jemand übernimmt
 *   Kachel → EspMic {aktion:'start', nonce, client}
 *   Modul → {type:'espGewaehlt', nonce, client}   nur EIN Fenster führt
 *   Modul → {type:'espAudio', d}          Pakete, Base64
 *   Kachel → EspMic stop | keep (alle 15 s)
 *   Modul → {type:'espEnde', nonce}       Taste am Gerät
 *
 * Ohne freigegebenen Ton (Autoplay-Sperre) meldet sich die Kachel nicht als
 * bereit: das Gerät spricht dann selbst.
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

/** Paket aus Base64: 2 Byte Nummer, dann µ-law. */
function dekodieren(b64) {
  var roh;
  try { roh = atob(String(b64 || '')); } catch (e) { return null; }
  if (roh.length < 3) { return null; }
  var n = roh.length - 2;
  var werte = new Float32Array(n);
  for (var i = 0; i < n; i++) { werte[i] = ULAW[roh.charCodeAt(i + 2) & 0xff]; }
  return { nr: (roh.charCodeAt(0) << 8) | roh.charCodeAt(1), werte: werte };
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

  function jetzt() { return Date.now(); }

  function tonBereit() { return !!(ctx && ctx.state === 'running'); }

  function bereitMelden() {
    if (aktiv && tonBereit() && !document.hidden) { senden('EspHier', ''); }
  }

  function hinweisPruefen() {
    aufHinweis(aktiv && !tonBereit() ? 'Einmal tippen: Ton fürs Sprachgerät freigeben' : '');
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

  function aktivieren(ja) {
    aktiv = !!ja;
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
    if (sagGeraet) { senden('EspMic', JSON.stringify({ aktion: 'stop', nonce: laufend, client: client })); }
    laufend = '';
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
      if (laufend) { senden('EspMic', JSON.stringify({ aktion: 'keep', nonce: laufend, client: client })); }
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
        if (!aktiv || !tonBereit() || document.hidden || kern.istOffen() || laufend) { return true; }
        letzterWeckruf = angebot = String(d.nonce || '');
        senden('EspMic', JSON.stringify({ aktion: 'start', nonce: angebot, client: client }));
        return true;
      case 'espGewaehlt':
        if (!angebot || d.nonce !== angebot) { return true; }
        angebot = '';
        if (d.client === client) { uebernehmen(String(d.nonce)); }
        return true;
      case 'espAudio': {
        if (!laufend || !puffer) { return true; }
        var p = dekodieren(d.d);
        if (!p || gesehen.indexOf(p.nr) >= 0) { return true; }
        gesehen.push(p.nr);
        if (gesehen.length > 32) { gesehen.shift(); }
        if (spricht || jetzt() < sperreBis) { p.werte.fill(0); }
        puffer.rein(p.werte);
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
    laeuft: function () { return !!laufend; }
  };
}

wurzel.SymDoEspMikro = { erzeuge: erzeuge, _dekodieren: dekodieren, _Puffer: Puffer, _ULAW: ULAW };
})(typeof window !== 'undefined' ? window : globalThis);
