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
  /* Die Bühne. Sie läuft zu den Rändern hin aus, statt als Rechteck zu enden —
     in der Visu-Kachel füllt sie nur die Mitte, ein harter Kasten mitten im
     Tile sähe nach Fehler aus. Hell und dunkel kommen aus derselben Kennung,
     die beide Oberflächen ohnehin setzen (data-symdo-theme). */
  '.sym-blase{position:relative;display:flex;align-items:center;justify-content:center;',
  '  background:radial-gradient(120% 92% at 50% 34%,',
  '    rgba(40,48,86,.85) 0%,rgba(14,17,30,.75) 52%,rgba(6,7,14,0) 78%);',
  /* Die vier Toene setzt JS je Zustand aus der Akzentfarbe (siehe palette()) —
     hier stehen nur Rueckfallwerte, falls das Skript nicht dazu kommt. */
  '  --c1:#7fe3d8;--c2:#00cdab;--c3:#2f7f8f;--c4:#7a86c8;',
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
  /* Lider: eine Kuppel in der Körperfarbe — oben rund, unten nur leicht nach
     unten gebogen —, die von der Oberkante her über das Auge sinkt. Sie liegen
     NICHT in .augen, sonst drückte deren scaleY die Kuppel mit platt; den
     Blick-Versatz bekommen sie über die eigene Lage-Gruppe (nur translate,
     kein transform-box nötig). Wie weit sie zu sind, setzt JS je Bild als
     transform-Attribut (scale(1 lid), Ursprung = Oberkante). */
  '.sym-blase .liderLage{transform:translate(var(--augeX,0px),var(--augeY,0px));}',
  '.sym-blase .lid{fill:var(--c3);}',
  /* Der Schatten macht den räumlichen Eindruck: das Wesen steht nicht IM Bild,
     es schwebt darüber. Er schrumpft und verblasst, wenn es sich hebt. */
  '.sym-blase .schatten{fill:#070a14;opacity:var(--schatten,.42);}',
  '.sym-blase .lichtfleck{opacity:var(--licht,.35);mix-blend-mode:screen;}',
  'html[data-symdo-theme="light"] .sym-blase{background:radial-gradient(120% 92% at 50% 34%,',
  '    rgba(255,255,255,.95) 0%,rgba(233,238,248,.9) 52%,rgba(214,222,238,0) 78%);}',
  /* Auf hellem Grund braucht der Schatten mehr Weichheit und weniger Schwärze,
     sonst wirkt er wie ein Fleck; das Leuchten dagegen darf zurücktreten. */
  'html[data-symdo-theme="light"] .sym-blase .schatten{fill:#3a4568;opacity:var(--schattenHell,.26);}',
  'html[data-symdo-theme="light"] .sym-blase .schein{opacity:calc(var(--schein,.55) * .62);}',
  /* Auf Weiß trägt „screen" nichts bei, und „multiply" macht aus dem Leuchten
     einen grauen Fleck (im Bild verglichen). Also normal mischen: eine farbige
     Lichtpfütze auf hellem Boden. */
  'html[data-symdo-theme="light"] .sym-blase .lichtfleck{mix-blend-mode:normal;opacity:calc(var(--licht,.35) * .9);}',
  '.sym-blase .glanz{fill:rgba(180,220,255,.75);}',
  /* Schlaf: die „z", die nach oben wegfliegen. Deckkraft und Lage setzt JS je
     Bild; hier nur Schrift und Farbe. Sie fangen keine Tipps ab. */
  '.sym-blase .zzz{pointer-events:none;}',
  /* KEINE opacity im Stil: eine CSS-Eigenschaft schlägt das Attribut, das die
     Animation je Bild setzt — die „z" blieben unsichtbar (im Bild geprüft). */
  '.sym-blase .zzz .z{fill:rgba(235,242,255,.55);font-weight:700;',
  '  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}',
  /* Die Wolke um jedes „z": fast durchsichtig. Die Deckkraft liegt an der
     GRUPPE, damit sich die Kreise nicht gegenseitig verdunkeln, wo sie sich
     überlappen; die Flugbewegung animiert die äußere Gruppe. */
  '.sym-blase .wolke{fill:#eef3ff;opacity:.16;}',
  'html[data-symdo-theme="light"] .sym-blase .zzz .z{fill:rgba(58,69,104,.55);}',
  'html[data-symdo-theme="light"] .sym-blase .wolke{fill:#3a4568;opacity:.09;}',
  /* Zustandsfarben — dieselbe Sprache wie die Statuszeile der Kachel. */
  /* „bereit" traegt die volle Palette: nach dem Laden soll das Wesen leuchten
     und nicht abgedunkelt dasitzen. Unterschieden wird der Zustand ohnehin
     ueber die BEWEGUNG — im Ruhezustand atmet es langsam, beim Sprechen folgt
     es dem Ton. */
  '.sym-blase.z-fehler{}'
].join('');

/* @property lässt sich nicht per CSSOM-Regel nachreichen; als Text im
   Stylesheet greift es überall dort, wo der Browser es kennt. */
var ANMELDUNG = [
  "@property --c1{syntax:'<color>';inherits:true;initial-value:#7fe3d8;}",
  "@property --c2{syntax:'<color>';inherits:true;initial-value:#00cdab;}",
  "@property --c3{syntax:'<color>';inherits:true;initial-value:#2f7f8f;}",
  "@property --c4{syntax:'<color>';inherits:true;initial-value:#7a86c8;}"
].join('');

/* Ein „z" in seiner Wolke. Das z ist mittig gesetzt (Grundlinie bei 0, die
   Kleinbuchstabenhöhe reicht bis etwa −7), die Wolke aus Kreisen umschließt es
   locker. Die Größe der drei Partikel unterscheidet der Maßstab je Bild. */
var WOLKE_Z = '<g class="zz" opacity="0">'
  +   '<g class="wolke">'
  +     '<circle cx="0" cy="-4.8" r="7.2"/><circle cx="-6.2" cy="-2.2" r="5"/>'
  +     '<circle cx="6.4" cy="-2.4" r="5.2"/><ellipse cx="0" cy="-1" rx="9.8" ry="3.9"/>'
  +   '</g>'
  +   '<text class="z" font-size="14" text-anchor="middle">z</text>'
  + '</g>';

/* Die Kuppel: Halbellipse über den oberen Bogen, unten ein flacher Bogen
   nach unten (Kontrollpunkt 2,3 Einheiten unter der Kante). */
var LID = '<path class="lid" d="M-6.7 7.6A6.7 7.6 0 0 1 6.7 7.6Q0 9.9 -6.7 7.6Z"/>';

var SVG = '<svg viewBox="-100 -100 200 200" preserveAspectRatio="xMidYMid meet" aria-hidden="true">'
  + '<defs>'
  + '<radialGradient class="gk" cx="35%" cy="25%" r="78%">'
  +   '<stop class="s1" offset="0%"/><stop class="s2" offset="30%"/>'
  +   '<stop class="s3" offset="58%"/><stop class="s4" offset="100%"/></radialGradient>'
  + '<radialGradient class="gs" cx="50%" cy="55%" r="62%">'
  +   '<stop class="s4" offset="0%" stop-opacity=".75"/>'
  +   '<stop class="s3" offset="45%" stop-opacity=".45"/>'
  +   '<stop class="s1" offset="100%" stop-opacity="0"/></radialGradient>'
  /* Das Licht, das das Wesen auf den Boden wirft — in SEINEN Farben, nicht
     grau. Innen die Akzentfarbe, nach außen auslaufend. */
  + '<radialGradient class="gl" cx="50%" cy="50%" r="50%">'
  +   '<stop class="s2" offset="0%" stop-opacity="1"/>'
  +   '<stop class="s4" offset="42%" stop-opacity=".6"/>'
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
  + '<filter class="fsch" x="-60%" y="-160%" width="220%" height="420%">'
  +   '<feGaussianBlur stdDeviation="6"/></filter>'
  + '<filter class="flicht" x="-70%" y="-200%" width="240%" height="500%">'
  +   '<feGaussianBlur stdDeviation="5.5"/></filter>'
  + '<filter class="fwolke" x="-40%" y="-40%" width="180%" height="180%">'
  +   '<feGaussianBlur stdDeviation="1.1"/></filter>'
  /* Die Lider leicht weich, damit sie wie Haut auf dem Körper liegen und nicht
     wie aufgeklebte Scheiben. Großzügiger Filterbereich: bei fast offenem Lid
     ist die Geometrie nur wenige Einheiten hoch. */
  + '<filter class="flid" x="-30%" y="-100%" width="160%" height="300%">'
  +   '<feGaussianBlur stdDeviation=".55"/></filter>'
  + '</defs>'
  /* Zuunterst der Lichtfleck, darin der enge Kontaktschatten: ein leuchtender
     Körper wirft Licht auf den Boden, und die dunkle Stelle bleibt nur dort,
     wo er ihn fast berührt. */
  + '<ellipse class="lichtfleck" cx="0" cy="80" rx="56" ry="13"/>'
  + '<ellipse class="schatten" cx="0" cy="79" rx="40" ry="8"/>'
  + '<g class="koerper">'
  + '<path class="schein"/><path class="schleier"/><path class="kern"/>'
  + '<g class="augen">'
  +   '<ellipse class="auge" cx="-19" cy="-5" rx="6.1" ry="7.3"/>'
  +   '<ellipse class="auge" cx="19" cy="-5" rx="6.1" ry="7.3"/>'
  +   '<ellipse class="glanz" cx="-20.8" cy="-7.4" rx="1.7" ry="1.8"/>'
  +   '<ellipse class="glanz" cx="17.2" cy="-7.4" rx="1.7" ry="1.8"/>'
  + '</g>'
  /* Je Auge ein Lid, etwas breiter als das Auge. Der Ursprung (0,0) liegt an
     der Oberkante des Auges; scale(1 lid) lässt die Kuppel von dort herabsinken.
     Das Auge selbst schließt sich dabei zur Linie, die unter der Kuppel liegt. */
  + '<g class="liderLage">'
  +   '<g class="lider" transform="translate(-19 -12.4)">' + LID + '</g>'
  +   '<g class="lider" transform="translate(19 -12.4)">' + LID + '</g>'
  + '</g></g>'
  /* Die „z" liegen AUSSERHALB des Körpers: sie sollen wegfliegen, nicht mit ihm
     wiegen. Drei Stück, versetzt, jedes eine Runde von unten rechts über dem
     Kopf nach oben und weg. */
  + '<g class="zzz">'
  +   WOLKE_Z + WOLKE_Z + WOLKE_Z
  + '</g></svg>';


/* Einschlafen erst nach einer Weile ohne Gespräch — sonst nickte das Wesen
   zwischen zwei Sätzen schon weg. Über SymDoVoiceBlase.schlafNach einstellbar
   (der Prüfstand setzt Sekundenbruchteile). */
function schlafNach() {
  var v = wurzel.SymDoVoiceBlase && wurzel.SymDoVoiceBlase.schlafNach;
  return (typeof v === 'number' && v >= 0) ? v : 15000;
}
function lerp(a, b, f) { return a + (b - a) * f; }
/* Wer Bewegung reduziert haben will, bekommt stehende „z" statt fliegender. */
var ruhig = false;
try { ruhig = !!(wurzel.matchMedia && wurzel.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) { ruhig = false; }
/* Diese Zustände heißen „es passiert etwas" — sie wecken sofort. */
var WACH = ['verbinde', 'hoert', 'duSprichst', 'denkt', 'spricht', 'werkzeug'];

/* ── Farben aus der Akzentfarbe ───────────────────────────────────────────
   Das Wesen traegt die Akzentfarbe des Hauses. Die Zustaende sind Abwandlungen
   DERSELBEN Farbe, damit es erkennbar bleibt — mit zwei Ausnahmen: Werkzeug
   (Bernstein) und Fehler (Rot) sind Bedeutung, nicht Geschmack.

   Genau daraus entsteht ein Problem, das hier geloest wird: Ist der Akzent
   selbst orange, sieht "bereit" aus wie "Werkzeug"; ist er rot, wie "Fehler".
   Deshalb wird der Abstand GEMESSEN (in Oklab, das perzeptuell gleichabstaendig
   ist) und notfalls die Helligkeit der Warnfarbe so weit verschoben, bis er
   reicht. Die Bedeutung bleibt so erhalten, die Unterscheidbarkeit auch. */

function zuRgb(text) {
  var s = String(text || '').trim();
  var m = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.exec(s);
  if (m) {
    var h = m[1];
    if (h.length === 3) { h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]; }
    return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)];
  }
  m = /rgba?\(([^)]+)\)/i.exec(s);
  if (m) {
    var t = m[1].split(/[,\/\s]+/).filter(function (x) { return x !== ''; }).map(parseFloat);
    if (t.length >= 3 && t.every(function (x) { return isFinite(x); })) {
      return [t[0], t[1], t[2]];
    }
  }
  return null;
}

function rgbZuHsl(r, g, b) {
  r /= 255; g /= 255; b /= 255;
  var max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
  var h = 0, sT = 0, l = (max + min) / 2;
  if (d !== 0) {
    sT = d / (1 - Math.abs(2 * l - 1));
    if (max === r) { h = ((g - b) / d) % 6; }
    else if (max === g) { h = (b - r) / d + 2; }
    else { h = (r - g) / d + 4; }
    h *= 60; if (h < 0) { h += 360; }
  }
  return [h, sT * 100, l * 100];
}

function hslZuRgb(h, sT, l) {
  h = ((h % 360) + 360) % 360; sT = Math.max(0, Math.min(100, sT)) / 100; l = Math.max(0, Math.min(100, l)) / 100;
  var c = (1 - Math.abs(2 * l - 1)) * sT, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = l - c / 2;
  var q = [[c, x, 0], [x, c, 0], [0, c, x], [0, x, c], [x, 0, c], [c, 0, x]][Math.floor(h / 60) % 6];
  return [Math.round((q[0] + m) * 255), Math.round((q[1] + m) * 255), Math.round((q[2] + m) * 255)];
}

/* Oklab: gleiche Zahlenabstaende entsprechen ungefaehr gleichen Sichtabstaenden
   — anders als in RGB, wo Gruen alles dominiert. */
function oklab(rgb) {
  function lin(v) { v /= 255; return v <= .04045 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }
  var r = lin(rgb[0]), g = lin(rgb[1]), b = lin(rgb[2]);
  var l = Math.cbrt(.4122214708 * r + .5363325363 * g + .0514459929 * b);
  var m = Math.cbrt(.2119034982 * r + .6806995451 * g + .1073969566 * b);
  var s2 = Math.cbrt(.0883024619 * r + .2817188376 * g + .6299787005 * b);
  return [.2104542553 * l + .7936177850 * m - .0040720468 * s2,
          1.9779984951 * l - 2.4285922050 * m + .4505937099 * s2,
          .0259040371 * l + .7827717662 * m - .8086757660 * s2];
}
function abstand(a, b) {
  var x = oklab(a), y = oklab(b);
  return Math.sqrt(Math.pow(x[0] - y[0], 2) + Math.pow(x[1] - y[1], 2) + Math.pow(x[2] - y[2], 2));
}

/* So weit auseinander muessen Grundfarbe und Warnfarbe mindestens liegen.
   0.25 in Oklab ist ein deutlicher, auf einen Blick sichtbarer Unterschied. */
var MINDESTABSTAND = .25;

/* Die Warnfarbe von der Grundfarbe wegziehen, bis der Abstand reicht.
   Gesucht wird die Fassung, die dem Original am NAECHSTEN bleibt und den
   Mindestabstand trotzdem schafft — nicht die erstbeste. Nur eine Richtung zu
   probieren genuegte nicht: bei orangem Akzent lief die Suche ins Weiss und
   blieb bei 0,24 stecken (gemessen), waehrend ein dunkles Bernstein muehelos
   0,3 erreicht. Der Farbton darf sich hoechstens leicht bewegen — er traegt
   die Bedeutung. */
function abheben(basis, grundRgb) {
  var kandidaten = [];
  var tonVersatz = [0, -8, 8, -16, 16];
  for (var ti = 0; ti < tonVersatz.length; ti++) {
    for (var l = 14; l <= 92; l += 6) {
      for (var si = 0; si < 2; si++) {
        var sT = Math.min(100, basis[1] + si * 8);
        kandidaten.push({
          hsl: [basis[0] + tonVersatz[ti], sT, l],
          weh: Math.abs(tonVersatz[ti]) * 2.2 + Math.abs(l - basis[2]) * .5 + si * 3
        });
      }
    }
  }
  kandidaten.sort(function (a, b) { return a.weh - b.weh; });
  var bestes = null, bestAbst = -1;
  for (var i = 0; i < kandidaten.length; i++) {
    var k = kandidaten[i];
    var d = abstand(hslZuRgb(k.hsl[0], k.hsl[1], k.hsl[2]), grundRgb);
    if (d >= MINDESTABSTAND) { return k.hsl; }
    if (d > bestAbst) { bestAbst = d; bestes = k.hsl; }
  }
  return bestes || basis;   // nichts reicht (fast unmoeglich) — das Beste nehmen
}

function hex(rgb) {
  return '#' + rgb.map(function (v) {
    return ('0' + Math.max(0, Math.min(255, Math.round(v))).toString(16)).slice(-2);
  }).join('');
}

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
  ['gk', 'gs', 'gv', 'gl', 'fs', 'fv', 'fk', 'fsch', 'flicht', 'fwolke', 'flid'].forEach(function (k) {
    var e = svg.querySelector('.' + k);
    if (e) { e.setAttribute('id', 'symblase-' + k + '-' + nr); }
  });
  var lichtfleck = svg.querySelector('.lichtfleck');
  lichtfleck.setAttribute('fill', 'url(#symblase-gl-' + nr + ')');
  lichtfleck.setAttribute('filter', 'url(#symblase-flicht-' + nr + ')');
  var schatten = svg.querySelector('.schatten');
  var koerper = svg.querySelector('.koerper');
  schatten.setAttribute('filter', 'url(#symblase-fsch-' + nr + ')');
  var pKern = svg.querySelector('.kern');
  var pSchleier = svg.querySelector('.schleier');
  var pSchein = svg.querySelector('.schein');
  pKern.setAttribute('fill', 'url(#symblase-gk-' + nr + ')');
  pKern.setAttribute('filter', 'url(#symblase-fk-' + nr + ')');
  pSchleier.setAttribute('fill', 'url(#symblase-gv-' + nr + ')');
  pSchleier.setAttribute('filter', 'url(#symblase-fv-' + nr + ')');
  pSchein.setAttribute('fill', 'url(#symblase-gs-' + nr + ')');
  pSchein.setAttribute('filter', 'url(#symblase-fs-' + nr + ')');
  Array.prototype.forEach.call(svg.querySelectorAll('.wolke'), function (w) {
    w.setAttribute('filter', 'url(#symblase-fwolke-' + nr + ')');
  });
  Array.prototype.forEach.call(svg.querySelectorAll('.lid'), function (l) {
    l.setAttribute('filter', 'url(#symblase-flid-' + nr + ')');
  });

  var aktiv = false, laeuft = false, raf = 0, letzteForm = 0;
  var ac = null, analyser = null, roh = null, verbunden = [];
  var zustandJetzt = 'bereit', stummSeit = 0, kunst = false, blinzelt = false, blinzelUhr = 0;
  /* Blick: Von-Position, Ziel, Beginn und Dauer der Sakkade sowie das Ende
     der anschließenden Fixation. */
  var blickVon = [0, 0], blickZu = [0, 0], blickAb = 0, blickDauer = 110, blickHalt = 0;
  var N = 56, TAU = Math.PI * 2;
  /* Schlaf: seit wann kein Gespräch läuft, wie tief es schläft (0 wach … 1
     schläft, je Bild weich nachgeführt) und wann die „z" zu fliegen begannen. */
  var ruheSeit = 0, schlaf = 0, zzzStart = 0;
  var zzz = Array.prototype.slice.call(svg.querySelectorAll('.zzz .zz'));
  var ZZ_MASS = [.8, 1, 1.2];   // Grundgröße der drei Partikel
  /* Lider: 0 offen … 1 zu, je Bild weich zum Ziel geführt. Blinzeln und
     Schlaf laufen über dieselben Lider — das Blinzeln zieht sie kurz ganz zu. */
  var lider = Array.prototype.slice.call(svg.querySelectorAll('.lider'));
  var liderTx = lider.map(function (l) { return l.getAttribute('transform'); });
  var lidJetzt = 0;

  function setz(name, wert) { behaelter.style.setProperty(name, wert); }

  /* NUR die eigenen Klassen anfassen. Ein className-Zuweisen wischte alles weg,
     was der Wirt an den Kasten gehängt hat — in einer Vergleichsseite verschwand
     dadurch dessen Höhenangabe, und die Blase war unsichtbar. */
  var ZUSTAENDE = ['bereit', 'verbinde', 'hoert', 'duSprichst', 'denkt', 'spricht',
                   'werkzeug', 'fehler', 'ende'];
  function klasseSetzen() {
    ZUSTAENDE.forEach(function (z) { behaelter.classList.remove('z-' + z); });
    behaelter.classList.add('sym-blase');
    behaelter.classList.add('z-' + zustandJetzt);
    paletteSetzen();
  }

  /* Die Akzentfarbe frisch lesen (sie kann sich mit dem Theme ändern) und die
     vier Töne des Zustands daraus rechnen. Gesetzt wird auf dem Element — der
     Übergang aus dem Stylesheet greift trotzdem, weil --c1..--c4 angemeldete
     Farben sind. */
  function paletteSetzen() {
    var roh = '';
    try { roh = getComputedStyle(behaelter).getPropertyValue('--accent-color').trim(); } catch (e) {}
    var akzRgb = zuRgb(roh) || zuRgb('#00cdab');
    var p = palette(zustandJetzt, akzRgb);
    for (var i = 0; i < 4; i++) { setz('--c' + (i + 1), p[i]); }
  }

  /* Vier Töne aus einer Farbe: heller Kern, die Akzentfarbe selbst, eine
     tiefere Tiefe und ein Rand mit etwas Farbdrift — das ergibt den Schimmer.
     Die Zustände verschieben Ton, Sättigung und Helligkeit. */
  function palette(zustand, akzRgb) {
    var hsl = rgbZuHsl(akzRgb[0], akzRgb[1], akzRgb[2]);
    var h = hsl[0], sT = Math.max(35, hsl[1]), l = Math.min(70, Math.max(38, hsl[2]));
    /* In welche Richtung der Schimmer driftet. Immer nach "oben" zu drehen war
       falsch: bei einem warmen Akzent landete er im Gelbgruenen — Orange sah
       gruen aus, Rot braeunlich (im Bild verglichen). Warme Toene driften
       deshalb zum Magenta hin, kalte zum Violett. So bleibt es in beiden
       Faellen ein Abendrot statt einer Farbverirrung. */
    var drift = (h >= 0 && h <= 95) ? -1 : 1;
    var grund = hslZuRgb(h, sT, l);
    var v;   // [Ton-Versatz, Saettigung, Helligkeit] je Ton
    switch (zustand) {
      case 'hoert':      v = [[-14, sT - 12, l + 30], [-4, sT - 4, l + 16], [20, sT, l - 8], [48, sT - 6, l + 6]]; break;
      case 'duSprichst': v = [[-16, sT + 6, l + 30], [4, sT + 6, l + 14], [26, sT + 2, l - 6], [56, sT, l + 8]]; break;
      case 'denkt':      v = [[-6, sT - 6, l + 20], [16, sT - 2, l + 2], [34, sT, l - 14], [62, sT - 4, l - 2]]; break;
      case 'spricht':    v = [[-10, sT + 10, l + 32], [2, sT + 8, l + 8], [24, sT + 4, l - 10], [58, sT + 6, l + 4]]; break;
      case 'werkzeug':
      case 'fehler': {
        // Bedeutung vor Geschmack: Bernstein bzw. Rot bleiben — aber MIT
        // gemessenem Abstand zur Grundfarbe (sonst ist ein oranger Akzent von
        // „Werkzeug" und ein roter von „Fehler" nicht zu unterscheiden).
        var basis = zustand === 'werkzeug' ? [38, 92, 58] : [2, 84, 60];
        var w = abheben(basis, grund);
        // Ohne Drift: der Farbton IST hier die Bedeutung.
        return [[w[0] + 8, w[1] - 6, w[2] + 18], [w[0], w[1], w[2]],
                [w[0] - 12, w[1], w[2] - 14], [w[0] - 34, w[1] - 4, w[2] - 4]]
               .map(function (t) { return hex(hslZuRgb(t[0], t[1], t[2])); });
      }
      default:           v = [[-12, sT + 4, l + 28], [0, sT, l], [22, sT, l - 12], [52, sT + 2, l + 2]];
    }
    return v.map(function (t) { return hex(hslZuRgb(h + t[0] * drift, t[1], t[2])); });
  }
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

    /* Schlaf. „Kein Gespräch" heißt: der Kern ist zu — NICHT der Zustand
       „bereit", den der Kern nie meldet (nach einem Gespräch bleibt „ende").
       Nach schlafNach() ohne Gespräch sinkt das Wesen langsam weg (rund 1,5 s),
       ein Gespräch weckt es in einer Drittelsekunde; zustand() setzt bei den
       WACH-Zuständen zusätzlich sofort auf null, damit schon der erste Frame
       nach dem Weckwort offene Augen zeigt. */
    var kernOffen = offen();
    if (kernOffen || WACH.indexOf(zustandJetzt) >= 0) { ruheSeit = 0; }
    else if (!ruheSeit) { ruheSeit = t; }
    var schlafZiel = (!kernOffen && ruheSeit && t - ruheSeit > schlafNach()) ? 1 : 0;
    schlaf = schlafZiel ? schlaf + (1 - schlaf) * .02 : schlaf * .82;
    if (schlaf < .002) { schlaf = 0; }
    var schlaeft = schlaf > .5;
    if (schlaeft !== behaelter.classList.contains('schlaeft')) { behaelter.classList.toggle('schlaeft', schlaeft); }
    if (schlaf > 0) {
      // Im Schlaf ein langsamer, flacher Atem — Schein und Puls stehen fast still.
      var atemS = (Math.sin(t * .0014) + 1) / 2;
      bass = lerp(bass, atemS * .05, schlaf); mitten = lerp(mitten, atemS * .04, schlaf);
      hoehen = lerp(hoehen, .01, schlaf); energie = lerp(energie, atemS * .05, schlaf);
    }

    setz('--schein', .40 + energie * .60);
    var sx = (1 + bass * .20) * (1 + .04 * schlaf);            // im Schlaf etwas breiter …
    var sy = (1 + mitten * .16) * .90 * (1 - .05 * schlaf);    // … und flacher, wie zusammengesunken

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

    /* Anheben und Schatten: beides zusammen macht den Raum. Je lauter, desto
       höher schwebt es — und der Schatten wird kleiner und blasser, genau wie
       bei echtem Licht von oben. Dazu ein langsames Wiegen, damit es auch in
       der Stille nicht klebt. */
    var hub = energie * 7 + Math.sin(t * .0009) * 2.5;
    // Schlafend sinkt es unter die Ruhelage, Richtung Boden, und wiegt nur noch leicht.
    hub = lerp(hub, -6 + Math.sin(t * .0014) * 1.2, schlaf);
    koerper.setAttribute('transform', 'translate(0 ' + (-hub).toFixed(1) + ')');
    // Geklemmt: unter der Ruhelage (negativer Hub) bleibt „am Boden" am Boden.
    var naehe = 1 - Math.max(0, Math.min(1, hub / 12));   // 1 = am Boden, 0 = weit oben
    schatten.setAttribute('rx', (38 * sx * (0.82 + naehe * 0.18)).toFixed(1));
    schatten.setAttribute('ry', (7.5 * (0.78 + naehe * 0.22)).toFixed(1));
    setz('--schatten', (0.42 + naehe * 0.20).toFixed(3));
    setz('--schattenHell', (0.18 + naehe * 0.12).toFixed(3));
    /* Steigt das Wesen, wird sein Lichtfleck GRÖSSER und schwächer — wie bei
       einer Lampe, die man anhebt. Und er atmet mit der Lautstärke, weil das
       Wesen selbst heller wird. */
    lichtfleck.setAttribute('rx', (54 * sx * (1.0 + (1 - naehe) * 0.18)).toFixed(1));
    lichtfleck.setAttribute('ry', (12.5 * (1.0 + (1 - naehe) * 0.15)).toFixed(1));
    setz('--licht', (0.78 + energie * 0.22 - (1 - naehe) * 0.12).toFixed(3));

    if (schlaf < .5) {
      blick(t, energie);
    } else {
      // Geschlossene Augen wandern nicht: zur Mitte, ein wenig nach unten.
      setz('--augeX', '0px');
      setz('--augeY', '1.5px');
    }
    liderZeichnen();
    /* Lauter Ton kneift die Augen ein wenig zusammen; mit den Lidern schließt
       sich das Auge zur Linie, die unter der Kuppel verschwindet. */
    setz('--augeAuf', lerp(1 - energie * .12, .07, lidJetzt).toFixed(3));
    zzzZeichnen(t);
  }

  /* Lider je Bild nachführen: Blinzeln zieht sie ganz zu, sonst folgen sie der
     Schlaftiefe. Schnell genug, dass ein Blinzeln in gut 100 ms zu und wieder
     auf ist; als Kurve, damit es nicht klappt. */
  function liderZeichnen() {
    var ziel = blinzelt ? 1 : schlaf;
    lidJetzt += (ziel - lidJetzt) * .45;
    if (Math.abs(ziel - lidJetzt) < .004) { lidJetzt = ziel; }
    var f = lidJetzt < .02 ? 0 : lidJetzt;   // ganz offen: keine Sichtkante
    for (var i = 0; i < lider.length; i++) {
      lider[i].setAttribute('transform', liderTx[i] + ' scale(1 ' + f.toFixed(3) + ')');
    }
  }

  /* Die drei „z": jedes läuft eine Runde von rechts über dem Kopf nach oben
     und weg, wird dabei größer, blendet ein und wieder aus; die drei sind um
     ein Drittel der Runde versetzt. Sie erscheinen erst, wenn das Wesen
     wirklich schläft, und verschwinden mit dem Aufwachen. Bei reduzierter
     Bewegung stehen sie still an ihrem Platz. */
  function zzzZeichnen(t) {
    if (schlaf <= .3) {
      if (zzzStart) {
        zzzStart = 0;
        for (var k = 0; k < zzz.length; k++) { zzz[k].setAttribute('opacity', '0'); }
      }
      return;
    }
    if (!zzzStart) { zzzStart = t; }
    var RUNDE = 4200;
    for (var i = 0; i < zzz.length; i++) {
      var ph = (t - zzzStart) / RUNDE - i / zzz.length;
      if (ph < 0) { zzz[i].setAttribute('opacity', '0'); continue; }
      var p = ph % 1, x, y, s, o;
      if (ruhig) {
        x = 34 + i * 9; y = -62 - i * 12; s = .8 + i * .15; o = .35 * schlaf;
      } else {
        x = 34 + 22 * p + Math.sin(p * 6) * 3;
        y = -58 - 38 * p;
        s = .7 + .5 * p;
        o = Math.sin(p * Math.PI) * .7 * schlaf;   // nie ganz deckend: Träume, kein Schild
      }
      s *= ZZ_MASS[i] || 1;
      zzz[i].setAttribute('transform', 'translate(' + x.toFixed(1) + ' ' + y.toFixed(1) + ') scale(' + s.toFixed(2) + ')');
      zzz[i].setAttribute('opacity', o.toFixed(3));
    }
  }

  /* Blickrichtung. Echte Augen driften nicht gleichmäßig, sie SPRINGEN: eine
     Sakkade von rund einer Zehntelsekunde, dann eine Fixation von einer halben
     bis gut zwei Sekunden. Vorher lag hier eine Sinuskurve — die läuft ewig
     dieselbe Acht und liest sich als Maschine.

     Je Zustand ein anderer Charakter, weil Blickverhalten Bedeutung trägt:
     beim Zuhören bleibt der Blick beim Gegenüber (kleine Ausschläge, oft in
     die Mitte), beim Denken wandert er weg und nach oben (so schaut jeder,
     der etwas sucht), beim Sprechen liegt er ruhig vorn, und im Leerlauf
     schaut das Wesen sich um. Werte: Ausschlag x/y, Anteil Mitte,
     Aufwärtsneigung (positiv = nach oben, y zeigt in SVG nach unten),
     Haltedauer von/bis. */
  var BLICK = {
    hoert:      [3.4, 1.8, .45,  .10,  900, 2600],
    duSprichst: [3.0, 1.6, .55,  .05, 1100, 2800],
    denkt:      [5.0, 3.0, .10,  .55,  700, 1900],
    verbinde:   [4.2, 2.6, .15,  .35,  600, 1500],
    spricht:    [2.6, 1.5, .50,  .00,  800, 2200],
    werkzeug:   [4.6, 2.8, .12,  .45,  600, 1600],
    fehler:     [2.2, 2.4, .30,  .40, 1200, 3000],
    bereit:     [4.4, 2.6, .22,  .00, 1000, 3200],
    ende:       [3.6, 2.2, .25,  .15, 1400, 3600]
  };

  function blick(t, energie) {
    var b = BLICK[zustandJetzt] || BLICK.bereit;
    if (t >= blickHalt) {
      blickVon = [blickZu[0], blickZu[1]];
      if (Math.random() < b[2]) {
        blickZu = [0, 0];                       // zurück zur Mitte
      } else {
        /* Nicht rein zufällig: ein Ziel nah am eben verlassenen wäre kein
           sichtbarer Blickwechsel. Daher mindestens ein Drittel Ausschlag
           Abstand, und die Seite wird gewechselt, wenn es zu nah wäre. */
        var zx = (Math.random() * 2 - 1) * b[0];
        if (Math.abs(zx - blickVon[0]) < b[0] * .55) { zx = -zx; }
        var zy = (Math.random() * 2 - 1) * b[1] - b[3] * b[1];
        blickZu = [zx, Math.max(-b[1] * 1.4, Math.min(b[1] * 1.4, zy))];
      }
      /* Weite Sprünge dauern länger — und ziehen oft ein Blinzeln mit sich,
         genau wie beim Menschen (blickgekoppeltes Blinzeln). */
      var weg = Math.abs(blickZu[0] - blickVon[0]) + Math.abs(blickZu[1] - blickVon[1]);
      blickDauer = 70 + Math.min(90, weg * 11);
      blickAb    = t;
      blickHalt  = t + blickDauer + b[4] + Math.random() * (b[5] - b[4]);
      if (weg > b[0] * 1.1 && !blinzelt && Math.random() < .45) { blinzeln(); }
    }
    var f = Math.min(1, (t - blickAb) / blickDauer);
    f = f * f * (3 - 2 * f);                    // sanft an- und abbremsen
    /* Auf die Fixation ein winziges Zittern: ein völlig stehendes Auge sieht
       aus wie ein Standbild. Amplitude bewusst unter einem Pixel. */
    var zit = Math.sin(t * .0071) * .35 + Math.sin(t * .0123) * .2;
    setz('--augeX', (blickVon[0] + (blickZu[0] - blickVon[0]) * f + zit).toFixed(2) + 'px');
    setz('--augeY', (blickVon[1] + (blickZu[1] - blickVon[1]) * f
                     + Math.sin(t * .0091) * .25 - energie * .6).toFixed(2) + 'px');
  }

  function blinzeln() {
    if (!aktiv) { return; }
    /* Die eigene Uhr zuerst löschen: seit der Blick bei weiten Sprüngen selbst
       blinzeln lässt, käme sonst je Aufruf eine ZWEITE Kette dazu und die
       Blinzelrate würde immer weiter steigen. */
    if (blinzelUhr) { clearTimeout(blinzelUhr); blinzelUhr = 0; }
    /* Im Schlaf kein Zwinkern — die Kette läuft aber weiter, damit sie beim
       Aufwachen ohne neuen Anstoß wieder da ist. */
    if (schlaf > .5) { blinzelUhr = setTimeout(blinzeln, 3000); return; }
    blinzelt = true;
    setTimeout(function () { blinzelt = false; }, 120);   // die Lider führt bild() nach
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
      klasseSetzen();
      if (aktiv) { anwerfen(); if (!blinzelUhr) { blinzeln(); } }
      else { anhalten(); }
    },
    zustand: function (z) {
      zustandJetzt = z || 'bereit';
      // Etwas passiert: sofort wach, nicht erst nach der weichen Nachführung.
      if (WACH.indexOf(zustandJetzt) >= 0) {
        ruheSeit = 0; schlaf = 0;
        behaelter.classList.remove('schlaeft');
      }
      if (!aktiv) { return; }
      klasseSetzen();
      // Der Griff zum Ton braucht die Nutzergeste — 'verbinde' kommt noch
      // innerhalb des Klicks auf den Knopf.
      if (zustandJetzt === 'verbinde') { tonAnzapfen(); }
      if (zustandJetzt === 'ende' || zustandJetzt === 'fehler') { verbunden = []; }
      anwerfen();
    },
    istAn: function () { return aktiv; }
  };
}

wurzel.SymDoVoiceBlase = { erzeuge: erzeuge, schlafNach: 15000 };
})(typeof window !== 'undefined' ? window : globalThis);
