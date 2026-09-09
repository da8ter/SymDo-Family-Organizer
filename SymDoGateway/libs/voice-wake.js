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
/* Dasselbe Muster auf dem ROHEN Text (mit Leerzeichen und Satzzeichen zwischen
   den Silben), um zu finden, wo das Weckwort endet — alles danach ist schon
   die Frage: „Hey SymDo, was steht heute an" → „was steht heute an". */
var ROH = /(hey|hei|hay|heu|hi|hallo|ok)[\s,.\-]*(sym|sim|zym|zim|sem|sam|sum)[\s,.\-]*(do|du|dow|doh|to|tu|dou)\b[\s,.!?\-]*/i;
function restNach(text) {
  var t = String(text || '');
  var m = ROH.exec(t);
  return m ? t.slice(m.index + m[0].length).trim() : '';
}

/* Ein frei gewähltes Weckwort. Ohne handgepflegte Silbenlisten bleibt nur die
   Buchstabennähe: der erkannte Text und das Wort werden auf Kleinschrift ohne
   Leerzeichen gebracht, und ein Fenster im Text darf um höchstens ein Fünftel
   der Länge abweichen. Wörter unter sechs Buchstaben lösten ständig falsch aus
   und gelten nicht — dann bleibt es bei „Hey SymDo". „Hey SymDo" selbst behält
   sein bewährtes Muster mit den Fehlhörern. */
function lev(a, b) {
  var m = a.length, n = b.length, i, j, prev, cur, tmp;
  if (!m) { return n; } if (!n) { return m; }
  prev = []; for (j = 0; j <= n; j++) { prev[j] = j; }
  for (i = 1; i <= m; i++) {
    cur = [i];
    for (j = 1; j <= n; j++) {
      tmp = prev[j - 1] + (a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1);
      cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, tmp);
    }
    prev = cur;
  }
  return prev[n];
}
function matcherFuer(weckwort) {
  var worte = String(weckwort || '').split(',').map(function (w) { return w.trim(); }).filter(Boolean);
  var eigene = worte.map(function (w) { return { roh: w, n: norm(w) }; })
    .filter(function (w) { return w.n.length >= 6 && w.n !== 'heysymdo'; });
  var standard = eigene.length === 0 || worte.some(function (w) { return norm(w) === 'heysymdo'; });
  function tol(w) { return Math.max(1, Math.floor(w.n.length * 0.2)); }
  function trifftEigen(text) {
    var n = norm(text);
    return eigene.some(function (w) {
      var t = tol(w);
      for (var L = Math.max(1, w.n.length - t); L <= w.n.length + t; L++) {
        for (var i = 0; i + L <= n.length; i++) {
          if (lev(n.substr(i, L), w.n) <= t) { return true; }
        }
      }
      return false;
    });
  }
  function restEigen(text) {
    var woerter = String(text || '').trim().split(/\s+/);
    for (var i = 0; i < woerter.length; i++) {
      for (var j = i; j < Math.min(woerter.length, i + 6); j++) {
        var cand = norm(woerter.slice(i, j + 1).join(''));
        for (var k = 0; k < eigene.length; k++) {
          var t = tol(eigene[k]);
          if (Math.abs(cand.length - eigene[k].n.length) <= t && lev(cand, eigene[k].n) <= t) {
            return woerter.slice(j + 1).join(' ').replace(/^[\s,.!?\-]+/, '').trim();
          }
        }
      }
    }
    return '';
  }
  return {
    trifft: function (t) { return (standard && trifft(t)) || (eigene.length > 0 && trifftEigen(t)); },
    restNach: function (t) { return (standard && trifft(t)) ? restNach(t) : restEigen(t); },
    phrasen: (standard ? ['Hey SymDo'] : []).concat(eigene.map(function (w) { return w.roh; })),
    anzeige: standard ? 'Hey SymDo' : eigene[0].roh
  };
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
 *   onWake(nachsatz)       — Weckwort gehört. `nachsatz` ist ein Promise<string>:
 *                            der Erkenner schreibt noch mit, was direkt nach dem
 *                            Weckwort gesagt wird (bis zum Satzende oder
 *                            nachlaufMs), und löst damit auf. Der Gesprächskern
 *                            reicht den Text nach, sobald die Verbindung steht —
 *                            so geht nichts verloren, was in die Aufbauzeit fällt.
 *   onZustand(z, grund)    — 'aus' | 'lauscht' | 'pause' | 'fehler'
 *   nachlaufMs             — wie lange nach dem Weckwort höchstens mitgeschrieben wird (10 s)
 *   weckwort               — das eingestellte Weckwort (mehrere durch Komma); leer = „Hey SymDo"
 *   onHoert(text)          — was der Erkenner gerade versteht (Zwischenergebnis), zum Anzeigen:
 *                            ohne diese Auskunft weiß niemand, WARUM ein Weckwort nicht zieht
 *   erkenner()             — nur für den Prüfstand: liefert einen Ersatz-Erkenner
 */
function erzeuge(opt) {
  opt = opt || {};
  var aufWake = opt.onWake || function () {};
  var aufHoert = opt.onHoert || function () {};
  var aufZustand = opt.onZustand || function () {};
  var bauen = opt.erkenner || function () { return new KLASSE(); };
  var nachlaufMs = opt.nachlaufMs || 10000;
  var matcher = matcherFuer(opt.weckwort);

  /* Mitschrift nach dem Weckwort: Index des Ergebnisses, in dem es fiel, der
     gesammelte Text und das Versprechen, das ihn liefert. */
  var wakeIndex = -1, mitText = '', mitLoesen = null, mitUhr = 0;
  function mitschriftStart() {
    var loesen;
    var p = new Promise(function (res) { loesen = res; });
    mitLoesen = function (text) {
      if (!mitLoesen) { return; }
      mitLoesen = null;
      if (mitUhr) { wurzel.clearTimeout(mitUhr); mitUhr = 0; }
      loesen(String(text || '').trim());
    };
    mitUhr = wurzel.setTimeout(function () { var t = mitText; mitLoesen && mitLoesen(t); aus(); }, nachlaufMs);
    return p;
  }
  function mitschriftSammeln(ev) {
    var teile = [];
    var fertig = false;
    for (var i = wakeIndex; i < ev.results.length; i++) {
      var r = ev.results[i];
      var alt = r && r[0];
      if (!alt) { continue; }
      var t = i === wakeIndex ? matcher.restNach(alt.transcript) : String(alt.transcript || '').trim();
      if (t) { teile.push(t); }
      if (i === ev.results.length - 1 && r.isFinal && teile.length) { fertig = true; }
    }
    mitText = teile.join(' ').trim();
    /* Ein abgeschlossener Satz mit Inhalt: übergeben und aus. Nur „Hey SymDo"
       allein ist noch kein Satz — dann weiter zuhören, die Frage kommt gleich. */
    if (fertig) { var text = mitText; mitLoesen && mitLoesen(text); aus(); }
  }

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
        // Betonung: „SymDo" steht in keinem Wörterbuch — ein eigenes Weckwort meist auch nicht.
        try { erk.phrases = matcher.phrasen.map(function (p) { return new PHRASE(p, 2); }); } catch (e) { /* egal */ }
      }
      erk.onresult = function (ev) {
        if (!an) { return; }
        try {
          var letztes = ev.results[ev.results.length - 1];
          if (letztes && letztes[0]) { aufHoert(String(letztes[0].transcript || '').trim()); }
        } catch (e) { /* nur Anzeige */ }
        if (geweckt) { mitschriftSammeln(ev); return; }
        for (var i = ev.resultIndex; i < ev.results.length; i++) {
          var alt = ev.results[i][0];
          if (alt && matcher.trifft(alt.transcript)) {
            /* Geweckt — aber NICHT sofort aus: der Erkenner schreibt weiter
               mit, was direkt nach dem Weckwort kommt, denn die Verbindung
               zum Anbieter steht erst in ein bis zwei Sekunden, und bis dahin
               ginge die Frage verloren. Aus geht er mit dem Satzende oder nach
               nachlaufMs; der Kern hält das Mikrofon bis dahin stumm, damit
               nichts doppelt ankommt. Wieder an geht es, wenn das Gespräch endet. */
            geweckt = true;
            wakeIndex = i;
            mitText = matcher.restNach(alt.transcript);
            var nachsatz = mitschriftStart();
            if (ev.results[i].isFinal && mitText) { mitLoesen(mitText); aus(); }
            aufWake(nachsatz);
            return;
          }
        }
      };
      erk.onspeechend = function () {
        // Der Sprecher ist fertig: was da ist, ist der Nachsatz.
        if (geweckt && mitLoesen) { var t = mitText; mitLoesen(t); aus(); }
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
    if (mitLoesen) { var t = mitText; mitLoesen(t); }   // ein Versprechen bleibt nie offen
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
        an = true; geweckt = false; wakeIndex = -1; mitText = ''; fehlerFolge = 0; anwerfen(); return true;
      });
    },
    aus: aus,
    istAn: function () { return an; },
    zustand: function () { return zustandJetzt; },
    /** Das Weckwort, auf das dieser Lauscher hört — für die Anzeige. */
    weckwort: function () { return matcher.anzeige; }
  };
}

wurzel.SymDoVoiceWeckwort = {
  erzeuge: erzeuge,
  verfuegbar: verfuegbar,
  laden: laden,
  trifft: trifft,           // für den Prüfstand
  restNach: restNach,       // dito
  matcherFuer: matcherFuer, // dito
  moeglichHier: !!KLASSE
};

})(typeof window !== 'undefined' ? window : this);
