/* SymDo Voice — die Blase: eine tonreagierende Darstellung des Gesprächs.
 *
 * Läuft wortgleich in der Visu-Kachel (SymDoVoice) und in der Web-App
 * (Dashboard-Kachel). Sie bringt ihr Aussehen selbst mit — Markup, Stil und
 * Bewegung —, damit es nur EINE Quelle gibt und nichts von Hand nachgezogen
 * werden muss.
 *
 *   var blase = SymDoVoiceBlase.erzeuge(behaelter, kern);
 *   blase.zustand('spricht');     // färbt und treibt
 *   blase.an(true) / .an(false);  // zeichnen oder ruhen
 *
 * `kern` ist der Gesprächskern (voice-core.js): davon werden nur istOffen()
 * und stroeme() gelesen. Der Kern selbst weiß nichts von Darstellungen.
 *
 * Der Ton läuft NICHT durch den Analyse-Graphen zum Lautsprecher: das
 * Audio-Element des Kerns spielt ihn bereits, ein zweiter Weg zur destination
 * verdoppelte ihn.
 */
(function (wurzel) {
'use strict';

var STIL_ID = 'symdo-blase-stil';

var STIL = [
  '.sym-blase{position:relative;display:flex;align-items:center;justify-content:center;',
  '  --c1:#28dcff;--c2:#397cff;--c3:#583aff;--c4:#db35ff;',
  /* Der Farbwechsel beim Zustandswechsel soll überblenden, nicht umspringen.
     Eine gewöhnliche Custom Property kann das nicht — sie ist für den Browser
     ein Text ohne Typ. Erst die Anmeldung per @property (unten) macht sie zu
     einer Farbe, die sich überblenden lässt. Wo @property fehlt, springt die
     Farbe wie zuvor: kein Bruch, nur härter. */
  '  transition:--c1 1.4s ease,--c2 1.4s ease,--c3 1.4s ease,--c4 1.4s ease;}',
  /* Kein festes Maß: das SVG füllt seinen Kasten, und preserveAspectRatio
     rechnet den viewBox mittig auf die KLEINERE Seite. Damit passt die Blase
     ohne Zutun in eine hohe wie in eine flache Kachel.
     ABSOLUT gesetzt, nicht als Flex-Kind mit width/height 100%: dort behielt es
     sein quadratisches Eigenmaß und wurde höher als der Kasten (gemessen:
     500 px SVG in einer 463 px hohen Bühne). */
  '.sym-blase>svg{position:absolute;inset:0;width:100%;height:100%;display:block;}',
  '.sym-blase .s1{stop-color:var(--c1);}.sym-blase .s2{stop-color:var(--c2);}',
  '.sym-blase .s3{stop-color:var(--c3);}.sym-blase .s4{stop-color:var(--c4);}',
  '.sym-blase .schein{opacity:var(--schein,.55);}',
  '.sym-blase .augen{transform-box:fill-box;transform-origin:center;',
  '  transform:translate(var(--augeX,0px),var(--augeY,0px)) scaleY(var(--augeAuf,1));}',
  '.sym-blase .auge{fill:#0b1030;}',
  '.sym-blase .glanz{fill:rgba(180,220,255,.75);}',
  /* Zustandsfarben — dieselbe Sprache wie die Statuszeile der Kachel. */
  '.sym-blase.z-bereit{--c1:#3a4a6a;--c2:#3b5aa0;--c3:#4a3a8a;--c4:#6a3a9a;}',
  /* Zuhören ist ruhiger und kühler als Antworten — sonst sähe beides gleich aus. */
  '.sym-blase.z-hoert{--c1:#6fe9ff;--c2:#3aa6f0;--c3:#4a63d0;--c4:#8a4ad0;}',
  '.sym-blase.z-duSprichst{--c1:#2bffd0;--c2:#22c3ff;--c3:#3a7aff;--c4:#7a5cff;}',
  '.sym-blase.z-denkt{--c1:#9a7bff;--c2:#7a5cff;--c3:#5a3ad0;--c4:#c035ff;}',
  '.sym-blase.z-werkzeug{--c1:#ffd36b;--c2:#ffa63a;--c3:#ff7a3a;--c4:#ff4fa0;}',
  '.sym-blase.z-fehler{--c1:#ff9a9a;--c2:#ff5c5c;--c3:#d02a4a;--c4:#a01a5a;}'
].join('');

/* @property lässt sich nicht per CSSOM-Regel nachreichen; als Text im
   Stylesheet greift es überall dort, wo der Browser es kennt. */
var ANMELDUNG = [
  "@property --c1{syntax:'<color>';inherits:true;initial-value:#28dcff;}",
  "@property --c2{syntax:'<color>';inherits:true;initial-value:#397cff;}",
  "@property --c3{syntax:'<color>';inherits:true;initial-value:#583aff;}",
  "@property --c4{syntax:'<color>';inherits:true;initial-value:#db35ff;}"
].join('');

var SVG = '<svg viewBox="-100 -100 200 200" preserveAspectRatio="xMidYMid meet" aria-hidden="true">'
  + '<defs>'
  + '<radialGradient class="gk" cx="35%" cy="25%" r="78%">'
  +   '<stop class="s1" offset="0%"/><stop class="s2" offset="30%"/>'
  +   '<stop class="s3" offset="58%"/><stop class="s4" offset="100%"/></radialGradient>'
  + '<radialGradient class="gs" cx="50%" cy="55%" r="62%">'
  +   '<stop class="s4" offset="0%" stop-opacity=".75"/>'
  +   '<stop class="s3" offset="45%" stop-opacity=".45"/>'
  +   '<stop class="s1" offset="100%" stop-opacity="0"/></radialGradient>'
  + '<radialGradient class="gv" cx="60%" cy="70%" r="70%">'
  +   '<stop class="s4" offset="0%" stop-opacity=".42"/>'
  +   '<stop class="s2" offset="60%" stop-opacity=".22"/>'
  +   '<stop class="s1" offset="100%" stop-opacity="0"/></radialGradient>'
  /* Weichzeichner in NUTZEREINHEITEN: skaliert mit der Kachel mit, anders als
     ein CSS-Blur in px, der bei kleinen Kacheln alles zudeckte. */
  + '<filter class="fs" x="-45%" y="-45%" width="190%" height="190%">'
  +   '<feGaussianBlur stdDeviation="5.5"/></filter>'
  + '<filter class="fv" x="-30%" y="-30%" width="160%" height="160%">'
  +   '<feGaussianBlur stdDeviation="3"/></filter>'
  + '<filter class="fk" x="-25%" y="-25%" width="150%" height="150%">'
  +   '<feGaussianBlur stdDeviation=".7"/></filter>'
  + '</defs>'
  + '<path class="schein"/><path class="schleier"/><path class="kern"/>'
  + '<g class="augen">'
  +   '<ellipse class="auge" cx="-19" cy="-5" rx="6.1" ry="7.3"/>'
  +   '<ellipse class="auge" cx="19" cy="-5" rx="6.1" ry="7.3"/>'
  +   '<ellipse class="glanz" cx="-20.8" cy="-7.4" rx="1.7" ry="1.8"/>'
  +   '<ellipse class="glanz" cx="17.2" cy="-7.4" rx="1.7" ry="1.8"/>'
  + '</g></svg>';

var zaehler = 0;

function stilEinmal(dok) {
  if (dok.getElementById(STIL_ID)) { return; }
  var s = dok.createElement('style');
  s.id = STIL_ID;
  s.textContent = ANMELDUNG + STIL;
  (dok.head || dok.documentElement).appendChild(s);
}

function erzeuge(behaelter, kern) {
  if (!behaelter) { return null; }
  var dok = behaelter.ownerDocument || document;
  stilEinmal(dok);
  behaelter.classList.add('sym-blase');
  behaelter.innerHTML = SVG;

  /* Verläufe und Filter werden über eine ID angesprochen, die im ganzen
     Dokument eindeutig sein muss — in der Web-App kann es mehr als eine Blase
     geben. Deshalb je Blase ein eigener Zählerwert. */
  var nr = ++zaehler;
  var svg = behaelter.firstChild;
  ['gk', 'gs', 'gv', 'fs', 'fv', 'fk'].forEach(function (k) {
    var e = svg.querySelector('.' + k);
    if (e) { e.setAttribute('id', 'symblase-' + k + '-' + nr); }
  });
  var pKern = svg.querySelector('.kern');
  var pSchleier = svg.querySelector('.schleier');
  var pSchein = svg.querySelector('.schein');
  pKern.setAttribute('fill', 'url(#symblase-gk-' + nr + ')');
  pKern.setAttribute('filter', 'url(#symblase-fk-' + nr + ')');
  pSchleier.setAttribute('fill', 'url(#symblase-gv-' + nr + ')');
  pSchleier.setAttribute('filter', 'url(#symblase-fv-' + nr + ')');
  pSchein.setAttribute('fill', 'url(#symblase-gs-' + nr + ')');
  pSchein.setAttribute('filter', 'url(#symblase-fs-' + nr + ')');

  var aktiv = false, laeuft = false, raf = 0, letzteForm = 0;
  var ac = null, analyser = null, roh = null, verbunden = [];
  var zustandJetzt = 'bereit', stummSeit = 0, kunst = false, blinzelt = false, blinzelUhr = 0;
  var N = 56, TAU = Math.PI * 2;

  function setz(name, wert) { behaelter.style.setProperty(name, wert); }
  function offen() { return !!(kern && kern.istOffen && kern.istOffen()); }

  function tonAnzapfen() {
    if (!aktiv || typeof AudioContext === 'undefined' || !kern || !kern.stroeme) { return; }
    try {
      if (!ac) {
        ac = new AudioContext();
        analyser = ac.createAnalyser();
        analyser.fftSize = 256;
        analyser.smoothingTimeConstant = .78;
        roh = new Uint8Array(analyser.frequencyBinCount);
      }
      if (ac.state === 'suspended') { ac.resume(); }
      var s = kern.stroeme();
      [s.mikro, s.fern].forEach(function (strom) {
        if (!strom || verbunden.indexOf(strom) !== -1) { return; }
        try { ac.createMediaStreamSource(strom).connect(analyser); verbunden.push(strom); }
        catch (e) { /* Ein Strom ohne Tonspur ist kein Grund aufzugeben. */ }
      });
    } catch (e) { analyser = null; }
  }

  function mittel(von, bis) {
    var summe = 0;
    for (var i = von; i < bis; i++) { summe += roh[i]; }
    return summe / (bis - von) / 255;
  }

  /* Geschlossene, glatte Kurve durch alle Punkte: Catmull-Rom, umgerechnet in
     kubische Bézier-Stücke. Der Umweg über den Pfad ist der ganze Grund für das
     SVG — border-radius formt immer eine glatte Superellipse, nie eine Kontur
     mit Lappen. */
  function kurve(p) {
    var n = p.length, d = 'M' + p[0][0].toFixed(1) + ' ' + p[0][1].toFixed(1);
    for (var i = 0; i < n; i++) {
      var a = p[(i - 1 + n) % n], b = p[i], c = p[(i + 1) % n], e = p[(i + 2) % n];
      d += 'C' + (b[0] + (c[0] - a[0]) / 6).toFixed(1) + ' ' + (b[1] + (c[1] - a[1]) / 6).toFixed(1)
         + ' ' + (c[0] - (e[0] - b[0]) / 6).toFixed(1) + ' ' + (c[1] - (e[1] - b[1]) / 6).toFixed(1)
         + ' ' + c[0].toFixed(1) + ' ' + c[1].toFixed(1);
    }
    return d + 'Z';
  }

  /* Lappen statt Ellipse: mehrere Moden überlagern sich, jede mit eigener
     Lappenzahl, Amplitude, Drift und Phase. Ungerade Zahlen (3, 5, 7) wirken
     organisch, gerade ergäben eine gespiegelte, künstliche Form.
     Jede Mode: [Lappen, Amplitude, Drift, Phase]. */
  function form(R, sx, sy, moden, t) {
    var p = [];
    for (var i = 0; i < N; i++) {
      var th = i / N * TAU, r = R;
      for (var m = 0; m < moden.length; m++) {
        r += R * moden[m][1] * Math.sin(moden[m][0] * th + t * moden[m][2] + moden[m][3]);
      }
      p.push([Math.cos(th) * r * sx, Math.sin(th) * r * sy]);
    }
    return kurve(p);
  }

  function bild(t) {
    if (!laeuft) { raf = 0; return; }
    raf = requestAnimationFrame(bild);
    if (offen()) { tonAnzapfen(); }

    var bass = 0, mitten = 0, hoehen = 0;
    if (analyser && roh) {
      analyser.getByteFrequencyData(roh);
      bass = mittel(0, 8); mitten = mittel(8, 30); hoehen = mittel(30, 70);
    }
    var energie = bass * .45 + mitten * .40 + hoehen * .15;
    var spricht = zustandJetzt === 'spricht' || zustandJetzt === 'duSprichst';

    /* Rückfall: manche Browser geben für einen ENTFERNTEN WebRTC-Strom dauerhaft
       Nullen zurück (bekannte WebKit-Eigenheit). Bleibt es beim Sprechen still,
       wird die Bewegung erzeugt statt gemessen — eine lebendige Blase ist
       ehrlicher als eine erstarrte, die Betrieb vortäuscht. */
    if (spricht) {
      if (energie > .02) { stummSeit = 0; kunst = false; }
      else if (!stummSeit) { stummSeit = t; }
      else if (!kunst && t - stummSeit > 1200) { kunst = true; }
    } else { stummSeit = 0; }
    if (kunst && spricht) {
      bass   = .26 + Math.sin(t * .0041) * .17 + Math.sin(t * .0017) * .06;
      mitten = .28 + Math.sin(t * .0063 + 1.2) * .19;
      hoehen = .17 + Math.sin(t * .0111 + .5) * .11;
      energie = bass * .45 + mitten * .40 + hoehen * .15;
    }
    /* Ruhe: ohne Gespräch atmet sie langsam von selbst (rund 7 s je Zug). */
    if (!offen() || zustandJetzt === 'bereit') {
      var atem = (Math.sin(t * .0009) + 1) / 2;
      bass = atem * .13; mitten = atem * .09; hoehen = .02; energie = atem * .12;
    }

    setz('--schein', .40 + energie * .60);
    var sx = 1 + bass * .20;
    var sy = (1 + mitten * .16) * .90;   // etwas breiter als hoch, wie in der Vorlage

    /* Die Kontur wird seltener neu gerechnet als gezeichnet — sie soll fließen,
       nicht zappeln, und drei Pfade je Bild neu zu bauen wäre Verschwendung. */
    if (t - letzteForm > 33) {
      letzteForm = t;
      pKern.setAttribute('d', form(66, sx, sy, [
        [3, .052 + bass   * .075,  .00041, 0.0],
        [5, .034 + mitten * .060,  .00062, 2.1],
        [7, .019 + hoehen * .055,  .00087, 4.3]
      ], t));
      pSchleier.setAttribute('d', form(71, sx * 1.02, sy * 1.02, [
        [3, .055 + mitten * .070, -.00035, 1.2],
        [6, .028 + hoehen * .050,  .00058, 3.4]
      ], t));
      pSchein.setAttribute('d', form(76, sx * 1.04, sy * 1.04, [
        [2, .058 + bass * .085,  .00029, 2.6],
        [5, .026 + bass * .040, -.00047, 5.1]
      ], t));
    }

    setz('--augeX', (Math.sin(t * .0011) * 3) + 'px');
    setz('--augeY', (Math.sin(t * .0017) * 2) + 'px');
    // Während des Blinzelns NICHT überschreiben — sonst bliebe es unsichtbar.
    if (!blinzelt) { setz('--augeAuf', 1 - energie * .12); }
  }

  function blinzeln() {
    if (!aktiv) { return; }
    blinzelt = true;
    setz('--augeAuf', .08);
    setTimeout(function () { blinzelt = false; setz('--augeAuf', 1); }, 120);
    blinzelUhr = setTimeout(blinzeln, 2200 + Math.random() * 4500);
  }

  function anhalten() { laeuft = false; if (raf) { cancelAnimationFrame(raf); raf = 0; } }
  function anwerfen() {
    if (!aktiv || laeuft || dok.hidden) { return; }
    laeuft = true; raf = requestAnimationFrame(bild);
  }

  dok.addEventListener('visibilitychange', function () {
    if (dok.hidden) { anhalten(); } else { anwerfen(); }
  });

  return {
    an: function (ja) {
      aktiv = ja !== false;
      behaelter.className = 'sym-blase z-' + zustandJetzt;
      if (aktiv) { anwerfen(); if (!blinzelUhr) { blinzeln(); } }
      else { anhalten(); }
    },
    zustand: function (z) {
      zustandJetzt = z || 'bereit';
      if (!aktiv) { return; }
      behaelter.className = 'sym-blase z-' + zustandJetzt;
      // Der Griff zum Ton braucht die Nutzergeste — 'verbinde' kommt noch
      // innerhalb des Klicks auf den Knopf.
      if (zustandJetzt === 'verbinde') { tonAnzapfen(); }
      if (zustandJetzt === 'ende' || zustandJetzt === 'fehler') { verbunden = []; }
      anwerfen();
    },
    istAn: function () { return aktiv; }
  };
}

wurzel.SymDoVoiceBlase = { erzeuge: erzeuge };
})(typeof window !== 'undefined' ? window : globalThis);
