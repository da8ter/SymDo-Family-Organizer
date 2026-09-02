/* SymDo Voice — Weckwort „Hey SymDo", auf dem Gerät erkannt.
 *
 * Läuft wortgleich in der Visu-Kachel und in der Web-App, wie voice-blob.js.
 * Zwei Dinge, die diese Datei ausmachen:
 *
 * 1. Der Ton verlässt das Gerät NICHT. Chrome kann Spracherkennung seit 139
 *    lokal rechnen (`processLocally = true`); ohne diese Flagge schickt es den
 *    Raumton an Google. Genau das wäre die Umkehrung des Versprechens, das der
 *    Sprachdialog gibt — deshalb gibt es hier KEINEN Rückfall auf die Wolke.
 *    Ist die lokale Erkennung nicht zu haben, bleibt der Knopf, und das wird
 *    gesagt, statt stumm zu scheitern.
 *
 * 2. Das Sprachpaket ist ein Download von einigen hundert Megabyte. Er wird
 *    nie von selbst angestoßen: `verfuegbar()` meldet „ladbar", und erst ein
 *    ausdrücklicher Griff des Nutzers ruft `laden()`.
 *
 * Am 02.09.2026 in Chrome 152 nachgemessen:
 *   available({langs:['de-DE'], processLocally:true})  → "downloadable"
 *   available({langs:['de-DE'], processLocally:false}) → "available"  (Wolke!)
 *   new SpeechRecognitionPhrase('Hey SymDo', 2)        → geht (Betonung)
 */
(function (wurzel) {
'use strict';

var KLASSE = wurzel.SpeechRecognition || wurzel.webkitSpeechRecognition || null;
var PHRASE = wurzel.SpeechRecognitionPhrase || null;
var SPRACHE = 'de-DE';

/* Erkannt wird gegen die zusammengeschobene Kleinschrift, weil Erkenner das
   Weckwort verschieden zerlegen („hey sym do", „hey simdo", „hei sim do").
   Die Silben werden deshalb einzeln erlaubt, müssen aber ANEINANDER hängen —
   ohne diese Nachbarschaft würde „Hey, Simon dort!" mitzählen. */
var MUSTER = /(hey|hei|hay|heu|hi|hallo|ok)(sym|sim|zym|zim|sem|sam|sum)(do|du|dow|doh|to|tu|dou)/;

function norm(t) {
  return String(t || '')
    .toLowerCase()
    .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
    .replace(/[^a-z]/g, '');
}

/** Steckt das Weckwort in diesem Stück Text? */
function trifft(text) {
  return MUSTER.test(norm(text));
}

/**
 * Kann dieses Gerät lokal lauschen?
 * @return Promise<{moeglich:boolean, stand:string, grund:string}>
 *   stand: 'bereit' | 'ladbar' | 'laedt' | 'unmoeglich'
 */
function verfuegbar() {
  if (!KLASSE) {
    return Promise.resolve({ moeglich: false, stand: 'unmoeglich',
      grund: 'Dieser Browser kann keine Spracherkennung. Freisprechen geht in Chrome oder Edge.' });
  }
  if (typeof KLASSE.available !== 'function') {
    /* Ältere Chrome-Stände haben die Erkennung, aber nur mit der Wolke. Damit
       waere das Datenschutzversprechen weg — also nein. */
    return Promise.resolve({ moeglich: false, stand: 'unmoeglich',
      grund: 'Dieser Browser kann nur mit Erkennung in der Cloud lauschen. Freisprechen braucht Chrome 139 oder neuer.' });
  }
  return KLASSE.available({ langs: [SPRACHE], processLocally: true }).then(function (a) {
    if (a === 'available') {
      return { moeglich: true, stand: 'bereit', grund: '' };
    }
    if (a === 'downloading') {
      return { moeglich: false, stand: 'laedt',
        grund: 'Die Spracherkennung wird noch auf dieses Gerät geladen.' };
    }
    if (a === 'downloadable') {
      return { moeglich: false, stand: 'ladbar',
        grund: 'Freisprechen braucht die deutsche Spracherkennung auf diesem Gerät. Das ist ein größerer Download.' };
    }
    return { moeglich: false, stand: 'unmoeglich',
      grund: 'Dieses Gerät kann Deutsch nicht lokal erkennen. Freisprechen ist hier nicht möglich.' };
  }).catch(function (e) {
    return { moeglich: false, stand: 'unmoeglich', grund: 'Die Prüfung schlug fehl: ' + (e && e.message || e) };
  });
}

/** Das Sprachpaket holen — NUR auf ausdrücklichen Wunsch. */
function laden() {
  if (!KLASSE || typeof KLASSE.install !== 'function') {
    return Promise.resolve(false);
  }
  return KLASSE.install({ langs: [SPRACHE], processLocally: true })
    .then(function (ok) { return ok !== false; })
    .catch(function () { return false; });
}

/**
 * Ein Lauscher. opt:
 *   onWake()               — Weckwort gehört (danach ist der Lauscher AUS)
 *   onZustand(z, grund)    — 'aus' | 'lauscht' | 'pause' | 'fehler'
 *   erkenner()             — nur für den Prüfstand: liefert einen Ersatz-Erkenner
 */
function erzeuge(opt) {
  opt = opt || {};
  var aufWake = opt.onWake || function () {};
  var aufZustand = opt.onZustand || function () {};
  var bauen = opt.erkenner || function () { return new KLASSE(); };

  var erk = null;
  var an = false;            // will der Nutzer lauschen?
  var laeuft = false;        // läuft der Erkenner gerade?
  var neustartUhr = 0;
  var fehlerFolge = 0;
  var zustandJetzt = 'aus';
  /* Einmal geweckt, bis zum naechsten Anschalten Ruhe: `abort()` beendet die
     Erkennung, aber ein schon unterwegs befindliches Ergebnis kann noch
     eintreffen — und das wuerde ein zweites Gespraech aufmachen. Im Pruefstand
     nachgestellt. */
  var geweckt = false;

  function melde(z, grund) {
    if (z === zustandJetzt && !grund) { return; }
    zustandJetzt = z;
    aufZustand(z, grund || '');
  }

  function anwerfen() {
    if (!an || laeuft) { return; }
    try {
      erk = bauen();
      erk.lang = SPRACHE;
      erk.continuous = true;
      erk.interimResults = true;      // schon das Zwischenergebnis darf wecken
      erk.maxAlternatives = 1;
      erk.processLocally = true;      // NIE ohne: sonst geht der Ton nach draußen
      if (PHRASE) {
        // Betonung: „SymDo" steht in keinem Wörterbuch.
        try { erk.phrases = [new PHRASE('Hey SymDo', 2)]; } catch (e) { /* egal */ }
      }
      erk.onresult = function (ev) {
        if (!an || geweckt) { return; }
        for (var i = ev.resultIndex; i < ev.results.length; i++) {
          var alt = ev.results[i][0];
          if (alt && trifft(alt.transcript)) {
            /* Sofort aus: ab hier gehört das Mikrofon dem Gespräch, und der
               Erkenner würde sonst auf die Stimme der KI aus dem Lautsprecher
               anspringen. Wieder an geht es, wenn das Gespräch endet. */
            geweckt = true;
            aus();
            aufWake();
            return;
          }
        }
      };
      erk.onerror = function (ev) {
        var art = (ev && ev.error) || 'unbekannt';
        if (art === 'no-speech' || art === 'aborted') {
          return;                     // beides normal, der Neustart kommt über onend
        }
        if (art === 'not-allowed' || art === 'service-not-allowed') {
          an = false; laeuft = false;
          melde('fehler', 'Das Mikrofon ist für diese Seite gesperrt.');
          return;
        }
        fehlerFolge++;
        melde('fehler', 'Die Erkennung meldet: ' + art);
      };
      erk.onend = function () {
        laeuft = false;
        if (!an) { melde('aus'); return; }
        /* Der Erkenner endet von selbst, auch mit continuous. Neu anwerfen,
           aber mit wachsender Pause, damit ein dauerhafter Fehler nicht in
           eine Endlosschleife läuft. */
        var pause = Math.min(30000, 400 * Math.pow(2, Math.min(6, fehlerFolge)));
        melde(fehlerFolge > 0 ? 'pause' : 'lauscht');
        neustartUhr = wurzel.setTimeout(anwerfen, pause);
      };
      erk.onstart = function () { fehlerFolge = 0; melde('lauscht'); };
      erk.start();
      laeuft = true;
    } catch (e) {
      laeuft = false;
      melde('fehler', 'Der Lauscher ließ sich nicht starten: ' + (e && e.message || e));
    }
  }

  function aus() {
    an = false;
    if (neustartUhr) { wurzel.clearTimeout(neustartUhr); neustartUhr = 0; }
    if (erk) {
      try { erk.onend = null; erk.abort(); } catch (e) { /* egal */ }
    }
    erk = null; laeuft = false;
    melde('aus');
  }

  return {
    /** Lauschen an oder aus. Gibt zurück, ob es angeht. */
    an: function (ja) {
      if (ja === false) { aus(); return Promise.resolve(false); }
      return verfuegbar().then(function (v) {
        if (!v.moeglich) { melde('fehler', v.grund); return false; }
        an = true; geweckt = false; fehlerFolge = 0; anwerfen(); return true;
      });
    },
    aus: aus,
    istAn: function () { return an; },
    zustand: function () { return zustandJetzt; }
  };
}

wurzel.SymDoVoiceWeckwort = {
  erzeuge: erzeuge,
  verfuegbar: verfuegbar,
  laden: laden,
  trifft: trifft,           // für den Prüfstand
  moeglichHier: !!KLASSE
};

})(typeof window !== 'undefined' ? window : this);
