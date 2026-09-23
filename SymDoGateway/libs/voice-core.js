/* SymDo Voice — der transportlose Gesprächskern.
 *
 * Läuft wortgleich in der Visu-Kachel und (später) in der Web-App; nur der
 * Transport unterscheidet sich und kommt als post() herein:
 *   Kachel:  requestAction('VoiceCall') → Gateway-Relay → Push zurück
 *   Web-App: fetch POST /v1/voice mit Bearer-Token
 *
 * Der Ton läuft NICHT über post(): die WebRTC-Verbindung geht direkt vom
 * Browser zu OpenAI. post() trägt nur Marke, Herzschlag und Werkzeugaufrufe.
 *
 * Alle Ereignisnamen des Realtime-Protokolls stehen ausschließlich HIER —
 * das Protokoll ändert sich schnell (die Beta hieß response.audio.delta,
 * GA heißt response.output_audio.delta), und ein Umbau darf genau eine
 * Datei treffen.
 */
(function (wurzel) {
'use strict';

function erzeuge(opt) {
  var post = opt.post;                 // (payload) => Promise<{ok,…}>
  var aufZustand = opt.onState || function () {};   // (zustand, detail)
  var aufEreignis = opt.onEvent || function () {};  // ({art, …}) für Verlauf/Protokoll
  var audioEl = opt.audioEl || null;

  var pc = null, dc = null, mic = null;
  var callId = '';
  var pingUhr = null, stilleUhr = null, verstecktSeit = 0, verstecktUhr = null;
  var offenSeit = 0;
  var beendet = true;
  /* GPT-Live (23.09.2026): zweiter Weg zum selben Anbieter. Der Server sagt
     beim Oeffnen `live:true`; dann tauscht das GATEWAY das SDP (keine Marke im
     Browser), die Ereignisse heissen anders, und Denken/Werkzeuge laufen in
     einem delegierten Backend-Modell, dessen Ereignisse in `response.event`
     eingepackt ankommen. Alles Live-Spezifische haengt an diesem Schalter. */
  var live = false;
  /* Live liefert Mitschriften nur als Deltas ohne Ende-Ereignis. Eine Pause
     schliesst die Blase: je Redezug eine Kennung, damit die Kachel nicht alles
     in eine Blase haengt. */
  var duUhr = null, duLauf = 0, duText = '';
  var sprechUhr = null, sprechLauf = 0, sprechText = '';
  /* Live: die Kennung der laufenden Backend-Antwort. Die eingepackten
     Responses-Ereignisse tragen KEINE response_id — nur response.created
     nennt sie, alles danach gehoert dazu. */
  var liveAntwortId = '';
  /* Frist fuer den Verbindungsaufbau. Ohne sie bleibt die Anzeige ewig auf
     "verbinde" stehen, wenn die Mikrofonfrage unbeantwortet bleibt:
     getUserMedia loest dann WEDER auf NOCH ab (headless nachgestellt und
     nach 5 s noch haengend). */
  var startUhr = 0;
  var werkzeugDeckelMs = opt.toolTimeoutMs || 8000;
  /* Langsame Werkzeuge bekommen mehr Luft: das Handbuch fragt Einbettung und
     Lesermodell beim Anbieter (gemessen 3,5–5 s, unter Last mehr). Ein Abbruch
     nach 8 s waere hier ein Fehler ohne Not — die Antwort kommt ja noch. */
  var werkzeugDeckelJe = { symcon_handbuch: 15000 };
  var stilleMs = (opt.silenceSeconds || 45) * 1000;

  /* Ein kurzer, freundlicher Zweiklang, sobald die Verbindung steht — das „Hi"
     des Assistenten. Bis dahin geht Gesprochenes verloren (WebRTC puffert
     nichts), und nach dem Weckwort schaut niemand auf die Blase: der Ton ist
     die einzige Auskunft, dass man jetzt sprechen kann. Rein lokal aus
     WebAudio, kein Netz, keine Datei; opt.tone === false schaltet ihn ab. */
  /* Vorgeprägte Marke: wer aufs Weckwort lauscht, hat sie schon in der Hand,
     wenn es fällt — der Gang zum Gateway (und von dort zum Anbieter) entfällt
     beim Start. Der Server prägt sie dafür mit längerer Frist (WARM_TTL); der
     Kern holt rechtzeitig eine neue und wirft die alte weg, sobald ein Gespräch
     läuft oder das Lauschen endet. Eine ungenutzte Marke kostet nichts. */
  var WARM_TTL = 300, WARM_TAKT = 240000;
  var warmGewuenscht = false, warmMarke = null, warmBis = 0, warmUhr = 0, warmLaeuft = false;
  function warmHolen() {
    if (!warmGewuenscht || !beendet || warmLaeuft) { return; }
    warmLaeuft = true;
    post({ action: 'open', warm: true, ttl: WARM_TTL }).then(function (r) {
      if (r && r.ok === true && (r.value || r.live === true)) {
        warmMarke = r;
        warmBis = Date.now() + ((r.ttl || WARM_TTL) - 15) * 1000;
      }
    }).catch(function () {}).then(function () {
      warmLaeuft = false;
      if (warmUhr) { clearTimeout(warmUhr); warmUhr = 0; }
      if (warmGewuenscht) { warmUhr = setTimeout(warmHolen, WARM_TAKT); }
    });
  }
  function vorwaermen(an) {
    warmGewuenscht = an === true;
    if (warmUhr) { clearTimeout(warmUhr); warmUhr = 0; }
    if (!warmGewuenscht) { warmMarke = null; return; }
    warmHolen();
  }
  /* Die vorgeprägte Marke, wenn sie noch trägt — einmal genommen, ist sie weg. */
  function warmNehmen() {
    var r = (warmMarke && Date.now() < warmBis) ? warmMarke : null;
    warmMarke = null;
    return r;
  }

  /* Mitschrift des Weckwort-Erkenners: der Text, der in die Aufbauzeit fiel.
     Bis er da ist, bleibt das Mikrofon stumm — sonst käme der Satz halb als
     Text und halb als Ton an, und das Modell antwortete auf beides. */
  var mitschrift = null;
  function mitschriftSetzen(p) { mitschrift = (p && typeof p.then === 'function') ? p : null; }
  function mikroFrei(ja) {
    try { if (mic) { mic.getAudioTracks().forEach(function (t) { t.enabled = ja; }); } } catch (e) {}
  }
  function wennKanalOffen(fn) {
    if (dc && dc.readyState === 'open') { fn(); return; }
    if (dc) { dc.addEventListener('open', fn, { once: true }); }
  }
  function mitschriftAbschliessen() {
    if (!mitschrift) { return; }
    var p = mitschrift;
    mitschrift = null;
    var frist = new Promise(function (res) { setTimeout(function () { res(''); }, 12000); });
    Promise.race([p.then(function (t) { return t || ''; }, function () { return ''; }), frist]).then(function (text) {
      text = String(text || '').trim();
      if (!beendet && text !== '') {
        wennKanalOffen(function () {
          // Live kennt kein conversation.*: getippter Text geht als Antwort-Element.
          senden({ type: live ? 'response.item.create' : 'conversation.item.create',
                   item: { type: 'message', role: 'user', content: [{ type: 'input_text', text: text }] } });
          senden({ type: 'response.create' });
          ereignis({ art: 'mitschrift', text: text });
        });
      }
      mikroFrei(true);
    });
  }

  var tonAn = opt.tone !== false;
  var tonCtx = null;
  function hiTon() {
    if (!tonAn) { return; }
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) { return; }
      if (!tonCtx) { tonCtx = new AC(); }
      if (tonCtx.state === 'suspended' && tonCtx.resume) { tonCtx.resume().catch(function () {}); }
      var t0 = tonCtx.currentTime + 0.02;
      // Zwei Töne aufwärts (E5 → A5), weich an- und abgeblendet, leicht überlappend.
      [[659.25, 0, 0.16], [880, 0.11, 0.26]].forEach(function (n) {
        var o = tonCtx.createOscillator(), g = tonCtx.createGain();
        o.type = 'sine'; o.frequency.value = n[0];
        g.gain.setValueAtTime(0.0001, t0 + n[1]);
        g.gain.exponentialRampToValueAtTime(0.2, t0 + n[1] + 0.03);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + n[1] + n[2]);
        o.connect(g); g.connect(tonCtx.destination);
        o.start(t0 + n[1]); o.stop(t0 + n[1] + n[2] + 0.02);
      });
    } catch (e) {}
  }

  /* Antwortzustand je response_id: Werkzeugaufrufe sammeln, Ergebnisse
     einstellen, und GENAU EIN response.create, wenn alles geliefert ist und
     die Antwort mit "completed" endete. Eine abgebrochene Antwort (der Nutzer
     hat dazwischengeredet) bekommt ihr Ergebnis in den Kontext, aber KEINE
     neue Antwort — sonst antwortet das Modell auf eine überholte Frage. */
  var antworten = {};   // response_id → {erwartet:null|int, geliefert:int, status:null, feuerte:false, laufend:int}

  function senden(obj) {
    try {
      if (kern._testSend) { kern._testSend(obj); return; }
      if (dc && dc.readyState === 'open') { dc.send(JSON.stringify(obj)); }
    } catch (e) { /* Kanal schon zu — das Ende räumt auf */ }
  }

  function zustand(z, detail) { try { aufZustand(z, detail || null); } catch (e) {} }
  function ereignis(e) { try { aufEreignis(e); } catch (e2) {} }

  function stilleZuruecksetzen() {
    if (stilleUhr) { clearTimeout(stilleUhr); }
    stilleUhr = setTimeout(function () { stop('Stille'); }, stilleMs);
  }

  /* ── Verbindungsaufbau ──────────────────────────────────────────────────── */

  /**
   * Kann dieser Ursprung überhaupt Sprache? Mikrofon und WebRTC sind für Browser
   * „powerful features" und nur in einem SICHEREN KONTEXT freigegeben: https,
   * localhost oder 127.0.0.1. Eine lokale http-Adresse (192.168.x.x:3777) zählt
   * ausdrücklich NICHT — auch nicht im eigenen WLAN.
   *
   * Die Probe muss sein, weil WebKit den Zugriff nicht verweigert, sondern
   * `navigator.mediaDevices` gar nicht erst ausliefert: ohne sie fiele das als
   * nacktes „TypeError" auf den Nutzer zurück, statt ihm den einen Satz zu sagen,
   * der das Problem löst. Trifft in der Visu-App auf dem iPhone jeden, der die
   * lokale Adresse eingetragen hat.
   *
   * @return {string} Fehlertext, oder '' wenn alles vorhanden ist.
   */
  function umgebungsFehler() {
    var sicher = (typeof window.isSecureContext === 'boolean') ? window.isSecureContext : true;
    if (!sicher) {
      return 'Sprache braucht eine verschlüsselte Verbindung. Öffne die Visu über die '
           + 'Connect-Adresse (https) statt über die lokale http-Adresse.';
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return 'Dieser Browser gibt kein Mikrofon frei.';
    }
    if (typeof RTCPeerConnection === 'undefined') {
      return 'Dieser Browser kann keine Sprachverbindung aufbauen.';
    }
    return '';
  }

  function start() {
    if (!beendet) { return Promise.resolve(false); }
    beendet = false;
    zustand('verbinde');

    var hindernis = umgebungsFehler();
    if (hindernis !== '') {
      beendet = true;
      zustand('fehler', hindernis);
      ereignis({ art: 'fehler', text: hindernis });
      return Promise.resolve(false);
    }

    if (startUhr) { clearTimeout(startUhr); }
    startUhr = setTimeout(function () {
      if (beendet) { return; }
      var text = 'Der Verbindungsaufbau hat zu lange gedauert — bitte den Mikrofonzugriff erlauben und es erneut versuchen.';
      beendet = true;
      aufraeumen();
      zustand('fehler', text);
      ereignis({ art: 'fehler', text: text });
    }, 25000);

    /* Mikrofon und Marke GLEICHZEITIG: nacheinander kostete es 0,3–1 s mehr,
       und jede Zehntelsekunde hier ist Sprache, die verloren geht. Die Marke
       verfällt nach 60 s — dauert die Berechtigungsfrage länger, wird eine
       frische geholt statt mit der alten zu scheitern. Eine nicht benutzte
       Marke kostet nichts: eine Sitzung entsteht erst mit dem Handschlag. */
    var vor = warmNehmen();
    var markeBis = vor ? warmBis : Date.now() + 45000;
    var markeP = vor ? Promise.resolve(vor)
      : post({ action: 'open' }).catch(function () { return { ok: false, verbindung: true }; });
    var frisch = function (r) {
      if (!r || r.ok !== true || (!r.value && r.live !== true)) {
        var text = (r && r.verbindung) ? 'Das Gateway war nicht erreichbar.'
          : ((r && (r.sag || (r.error && r.error.message))) || 'Keine Sitzung bekommen.');
        throw { eigene: true, message: text };
      }
      return r.live === true ? handschlagLive(r) : handschlag(r);
    };
    return Promise.all([
      navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
      }),
      markeP
    ]).then(function (paar) {
      var strom = paar[0], r = paar[1];
      if (beendet) { strom.getTracks().forEach(function (t) { t.stop(); }); return false; }
      mic = strom;
      if (Date.now() > markeBis) {
        return post({ action: 'open' }).then(frisch);
      }
      return frisch(r);
    }).then(function (ok) {
      if (ok === false) { return false; }
      return true;
    }).catch(function (e) {
      var text = e && e.eigene ? e.message : mikrofonFehlerText(e);
      /* Ein gescheiterter Start ist ein BEENDETER Zustand. Ohne diese Zeile bliebe
         `beendet` false: die Kachel zeigte „Gespräch läuft" samt leuchtendem
         Mikrofonpunkt, und der nächste Druck auf den Knopf hätte nur gestoppt,
         statt es erneut zu versuchen. */
      beendet = true;
      var id = callId;
      callId = '';
      aufraeumen();
      zustand('fehler', text);
      ereignis({ art: 'fehler', text: text });
      // Kam die Sitzung noch zustande, bevor es scheiterte, serverseitig auflegen —
      // aber OHNE 'ende'-Zustand, der die Fehlermeldung sonst überschriebe.
      if (id) { post({ action: 'close', callId: id }).catch(function () {}); }
      return false;
    });
  }

  /* Der gemeinsame Unterbau beider Wege: Verbindung, Tonspur, Datenkanal. */
  function verbindungAnlegen() {
    pc = new RTCPeerConnection();
    // ontrack VOR setRemoteDescription, sonst verpasst man die Spur.
    pc.ontrack = function (ev) {
      if (audioEl) {
        audioEl.srcObject = ev.streams[0];
        var p = audioEl.play(); if (p && p.catch) { p.catch(function () {}); }
      }
    };
    pc.onconnectionstatechange = function () {
      if (!pc) { return; }
      if (pc.connectionState === 'connected' && !live) {
        // Live meldet sich erst mit session.started als bereit (siehe dort).
        bereitMelden();
      }
      if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected'
          || pc.connectionState === 'closed') {
        if (!beendet) { stop('Verbindung verloren'); }
      }
    };
    mic.getAudioTracks().forEach(function (t) { t.enabled = !mitschrift; pc.addTrack(t, mic); });
    // Der Datenkanal MUSS vor createOffer existieren — sonst fehlt die
    // m=application-Zeile im Angebot und der Kanal kommt nie zustande.
    dc = pc.createDataChannel('oai-events');
    dc.onmessage = function (ev) {
      var daten; try { daten = JSON.parse(ev.data); } catch (e) { return; }
      kern.handleServerEvent(daten);
    };
    dc.onclose = function () { if (!beendet) { stop('Kanal geschlossen'); } };
  }

  function bereitMelden() {
    if (startUhr) { clearTimeout(startUhr); startUhr = 0; }
    hiTon();
    zustand('hoert'); stilleZuruecksetzen();
    ereignis({ art: 'bereit' });
    mitschriftAbschliessen();
  }

  function pingStarten(sitzung) {
    pingUhr = setInterval(function () {
      post({ action: 'ping', callId: callId }).then(function (r) {
        if (r && r.stop === true) { stop(r.grund || 'Zeitdeckel'); }
        else if (r && typeof r.secondsLeft === 'number') {
          ereignis({ art: 'rest', sekunden: r.secondsLeft });
        }
      }).catch(function () {});
    }, (sitzung.pingSeconds || 15) * 1000);
  }

  /* Auf das Ende der ICE-Sammlung warten (mit Frist). GPT-Live bekommt das
     Angebot nur EINMAL ueber das Gateway — Kandidaten, die erst danach
     eintrudeln, erreichen es nicht mehr. */
  function eisSammeln() {
    if (!pc || pc.iceGatheringState === 'complete') { return Promise.resolve(); }
    return new Promise(function (res) {
      var p = pc, uhr = setTimeout(fertig, 2500);
      function fertig() { clearTimeout(uhr); try { p.removeEventListener('icegatheringstatechange', pruefen); } catch (e) {} res(); }
      function pruefen() { if (p.iceGatheringState === 'complete') { fertig(); } }
      p.addEventListener('icegatheringstatechange', pruefen);
    });
  }

  /* GPT-Live: das Angebot geht ans Gateway, das die Sitzung beim Anbieter
     anlegt und die Antwort zurueckreicht. Der Schluessel bleibt so auf dem
     Server; der Ton laeuft danach wieder direkt Browser ↔ Anbieter. */
  function handschlagLive(sitzung) {
    live = true;
    verbindungAnlegen();
    return pc.createOffer().then(function (angebot) {
      return pc.setLocalDescription(angebot);
    }).then(eisSammeln).then(function () {
      if (beendet || !pc) { throw { eigene: true, message: 'abgebrochen' }; }
      return post({ action: 'livesdp', sdp: pc.localDescription.sdp })
        .catch(function () { return { ok: false, verbindung: true }; });
    }).then(function (r) {
      if (!r || r.ok !== true || !r.sdp) {
        var text = (r && r.verbindung) ? 'Das Gateway war nicht erreichbar.'
          : ((r && (r.sag || (r.error && r.error.message))) || 'Keine Live-Sitzung bekommen.');
        throw { eigene: true, message: text };
      }
      callId = r.callId || '';
      return pc.setRemoteDescription({ type: 'answer', sdp: r.sdp });
    }).then(function () {
      offenSeit = Date.now();
      post({ action: 'opened', callId: callId, live: true }).catch(function () {});
      pingStarten(sitzung);
      stilleZuruecksetzen();
      return true;
    });
  }

  function handschlag(sitzung) {
    live = false;
    verbindungAnlegen();
    return pc.createOffer().then(function (angebot) {
      return pc.setLocalDescription(angebot);
    }).then(function () {
      return fetch('https://api.openai.com/v1/realtime/calls', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + sitzung.value, 'Content-Type': 'application/sdp' },
        body: pc.localDescription.sdp
      });
    }).then(function (r) {
      if (!r.ok) {
        return r.text().then(function (t) {
          /* Der Grund STAND schon hier und wurde weggeworfen: der Text wurde
             gelesen und nicht benutzt. Beim Nutzer stand deshalb nur „HTTP
             429", und niemand konnte sagen, ob das Guthaben leer ist, zu viele
             Sitzungen offen sind oder das Modell ausgelastet ist. */
          var grund = '';
          try {
            var j = JSON.parse(t);
            grund = (j && j.error && (j.error.code || j.error.type || j.error.message)) || '';
            if (j && j.error && j.error.message && grund !== j.error.message) {
              grund += ' — ' + j.error.message;
            }
          } catch (e) { grund = String(t || '').slice(0, 160); }
          // Auch dem Gateway melden: im Browser ist die Meldung nach dem
          // Schliessen weg, im Protokoll steht sie noch morgen.
          post({ action: 'fehler', stelle: 'realtime/calls', status: r.status,
                 grund: String(grund).slice(0, 300) }).catch(function () {});
          // „insufficient_quota" sagt einem Menschen nichts — der Satz schon.
          var klartext = /insufficient_quota/i.test(String(grund))
            ? 'Beim KI-Anbieter ist kein Guthaben mehr — bitte das Konto aufladen.'
            : 'OpenAI lehnte die Verbindung ab (HTTP ' + r.status + ')'
              + (grund ? ': ' + String(grund).slice(0, 160) : '.');
          throw { eigene: true, message: klartext };
        });
      }
      callId = ((r.headers.get('Location') || '').split('/').pop()) || '';
      return r.text();
    }).then(function (antwortSdp) {
      return pc.setRemoteDescription({ type: 'answer', sdp: antwortSdp });
    }).then(function () {
      offenSeit = Date.now();
      // Ab hier läuft die Uhr serverseitig — der Wachhund kennt den Anruf.
      post({ action: 'opened', callId: callId }).catch(function () {});
      pingStarten(sitzung);
      stilleZuruecksetzen();
      return true;
    });
  }

  function mikrofonFehlerText(e) {
    var name = (e && e.name) || '';
    var inIframe = (function () { try { return window.self !== window.top; } catch (x) { return true; } })();
    if (name === 'NotAllowedError' || name === 'SecurityError') {
      return inIframe ? 'Mikrofon im Visu-Rahmen blockiert — bitte einmal die Berechtigung erteilen.'
                      : 'Mikrofonzugriff verweigert — bitte in den Browser-Einstellungen freigeben.';
    }
    if (name === 'NotFoundError') { return 'Kein Mikrofon gefunden.'; }
    return 'Das Gespräch ließ sich nicht starten (' + (name || (e && e.message) || 'unbekannt') + ').';
  }

  /* ── Laufender Dialog: Ereignisse vom Server ────────────────────────────── */

  function antwortZustand(id) {
    if (!antworten[id]) {
      antworten[id] = { erwartet: null, geliefert: 0, status: null, feuerte: false, laufend: 0 };
    }
    return antworten[id];
  }

  function vielleichtWeiter(id) {
    var a = antworten[id];
    if (!a || a.feuerte) { return; }
    if (a.erwartet === null || a.geliefert < a.erwartet || a.laufend > 0) { return; }
    // Nur eine VOLLSTÄNDIG gelungene Antwort löst die nächste aus. Abgebrochene
    // ("cancelled"/"incomplete") bekamen ihre Ergebnisse in den Kontext — mehr nicht.
    if (a.status === 'completed' && a.erwartet > 0) {
      a.feuerte = true;
      senden({ type: 'response.create' });
    }
  }

  function werkzeugAusfuehren(responseId, callIdFn, name, argumente) {
    var a = antwortZustand(responseId);
    a.laufend++;
    zustand('werkzeug', name);
    ereignis({ art: 'werkzeug', name: name });
    var fertig = false;
    var liefern = function (ergebnis) {
      if (fertig) { return; }
      fertig = true;
      senden({
        // Live: response.item.create — dasselbe Element, anderer Umschlag.
        type: live ? 'response.item.create' : 'conversation.item.create',
        item: { type: 'function_call_output', call_id: callIdFn,
                output: JSON.stringify(ergebnis) }
      });
      a.laufend--;
      a.geliefert++;
      ereignis({ art: 'werkzeugFertig', name: name, ok: ergebnis && ergebnis.ok === true,
                 sag: (ergebnis && ergebnis.sag) || '' });
      vielleichtWeiter(responseId);
    };
    // Ein Aufruf ohne Antwort ließe die Sitzung stumm stehen und kostet weiter
    // Sekunden — nach dem Deckel bekommt das Modell einen ehrlichen Fehler.
    var deckel = setTimeout(function () {
      liefern({ ok: false, error: { code: 'timeout' },
                sag: 'Das dauert zu lange — prüfe lesend nach, bevor du es erneut versuchst.' });
    }, werkzeugDeckelJe[name] || werkzeugDeckelMs);
    /* callIdFn mitschicken: das ist die Kennung DIESES Modellaufrufs (callId
       ist die der Sitzung). Der Server weist damit eine doppelt zugestellte
       Anfrage ab, statt zweimal einzutragen. */
    post({ action: 'tool', callId: callId, fnId: callIdFn, name: name, arguments: argumente })
      .then(function (r) { clearTimeout(deckel); liefern(r || { ok: false, sag: 'Keine Antwort.' }); })
      .catch(function () {
        clearTimeout(deckel);
        liefern({ ok: false, error: { code: 'unreachable' }, sag: 'Der Server war nicht erreichbar.' });
      });
  }

  /* Live-Mitschrift: Deltas sammeln, eine Pause schliesst die Blase. */
  function duMelden(delta) {
    if (!duUhr) { duLauf++; duText = ''; }
    else { clearTimeout(duUhr); }
    duText += delta;
    zustand('duSprichst'); stilleZuruecksetzen();
    ereignis({ art: 'duDelta', text: delta, item: 'live' + duLauf });
    duUhr = setTimeout(function () {
      duUhr = null;
      ereignis({ art: 'duFertig', text: duText, item: 'live' + duLauf });
      if (!beendet && !sprechUhr) { zustand('denkt'); }
    }, 1200);
  }
  function sprechenMelden(delta) {
    if (!sprechUhr) { sprechLauf++; sprechText = ''; zustand('spricht'); }
    else { clearTimeout(sprechUhr); }
    sprechText += delta;
    stilleZuruecksetzen();
    ereignis({ art: 'symdoDelta', text: delta, antwort: 'live' + sprechLauf });
    sprechUhr = setTimeout(function () {
      sprechUhr = null;
      ereignis({ art: 'symdoFertig', text: sprechText, antwort: 'live' + sprechLauf });
      if (!beendet) { zustand('hoert'); }
    }, 1500);
  }
  function liveSchlussText(grund) {
    switch (grund) {
      case 'close_requested': return 'beendet';
      case 'expired':         return 'Zeitdeckel des Anbieters';
      case 'content':         return 'vom Anbieter beendet (Inhalt)';
      case 'connection_lost': return 'Verbindung verloren';
      default:                return grund ? ('Sitzung geschlossen: ' + grund) : 'Sitzung geschlossen';
    }
  }
  /* Ende einer Backend-Antwort (Realtime: response.done, Live: response.completed
     im Umschlag) — zaehlen, wie viele Werkzeugergebnisse noch fehlen. */
  function antwortZuEnde(r, status) {
    var a = antwortZustand(r.id || liveAntwortId || 'r');
    var aufrufe = 0;
    if (live) {
      /* Gemessen 23.09.2026 und so dokumentiert: die weitergereichten
         Lebenszyklus-Ereignisse tragen `output: []`, AUCH wenn Werkzeugaufrufe
         offen sind. Die Zahl stammt deshalb aus den output_item.done-Ereignissen
         (siehe dort); aus `output` gelesen waere sie 0, response.create fiele
         nie, und das Gespraech bliebe nach der ersten Frage stumm. */
      aufrufe = a.erwartet || 0;
    } else {
      (r.output || []).forEach(function (it) { if (it && it.type === 'function_call') { aufrufe++; } });
    }
    a.erwartet = aufrufe;
    a.status = status;
    if (aufrufe === 0 && !sprechUhr) { zustand('hoert'); }
    vielleichtWeiter(r.id || 'r');
  }

  function handleServerEvent(ev) {
    var typ = (ev && ev.type) || '';
    switch (typ) {
      /* ── GPT-Live ── */
      case 'session.started':
        live = true;
        ereignis({ art: 'sitzung', modell: (ev.session && ev.session.model) || 'gpt-live-1' });
        bereitMelden();
        return;
      case 'session.input_transcript.delta':
        duMelden(ev.delta || '');
        return;
      case 'session.output_transcript.delta':
        sprechenMelden(ev.delta || '');
        return;
      case 'session.delegation.created':
        zustand('denkt'); stilleZuruecksetzen();
        return;
      case 'response.event':
        // Umschlag des Backend-Modells: den Inhalt wie ein eigenes Ereignis behandeln.
        if (ev.event && typeof ev.event === 'object') { handleServerEvent(ev.event); }
        return;
      case 'response.output_item.done':
        // Realtime meldet Werkzeugaufrufe ueber function_call_arguments.done — nur Live hier.
        if (live && ev.item && ev.item.type === 'function_call') {
          var idL = ev.response_id || liveAntwortId || 'r';
          var aL = antwortZustand(idL);
          aL.erwartet = (aL.erwartet || 0) + 1;   // die einzige verlaessliche Zaehlung (s. antwortZuEnde)
          werkzeugAusfuehren(idL, ev.item.call_id || '', ev.item.name || '', ev.item.arguments || '{}');
        }
        return;
      case 'response.completed':
        antwortZuEnde(ev.response || {}, 'completed');
        return;
      case 'response.incomplete':
      case 'response.failed':
        antwortZuEnde(ev.response || {}, (ev.response && ev.response.status) || 'incomplete');
        return;
      case 'session.closed':
        if (!beendet) { stop(liveSchlussText(ev.reason || '')); }
        return;
      /* ── Realtime ── */
      case 'session.created':
        ereignis({ art: 'sitzung', modell: (ev.session && ev.session.model) || '' });
        return;
      case 'input_audio_buffer.speech_started':
        // Läuft gerade eine Ausgabe, hat der Server sie schon abgeschnitten
        // (interrupt_response) — hier NICHTS senden, nur anzeigen.
        zustand('duSprichst');
        stilleZuruecksetzen();
        return;
      case 'input_audio_buffer.speech_stopped':
        zustand('denkt');
        return;
      case 'conversation.item.input_audio_transcription.delta':
        ereignis({ art: 'duDelta', text: ev.delta || '', item: ev.item_id || '' });
        return;
      case 'conversation.item.input_audio_transcription.completed':
        ereignis({ art: 'duFertig', text: ev.transcript || '', item: ev.item_id || '' });
        return;
      case 'response.created':
        if (live) { liveAntwortId = (ev.response && ev.response.id) || ''; }
        antwortZustand((ev.response && ev.response.id) || 'r');
        stilleZuruecksetzen();
        return;
      case 'response.output_item.added':
        if (ev.item && ev.item.type === 'function_call') { zustand('werkzeug', ev.item.name || ''); }
        return;
      case 'response.output_audio_transcript.delta':
        ereignis({ art: 'symdoDelta', text: ev.delta || '', antwort: ev.response_id || '' });
        return;
      case 'response.output_audio_transcript.done':
        ereignis({ art: 'symdoFertig', text: ev.transcript || '', antwort: ev.response_id || '' });
        return;
      case 'output_audio_buffer.started':
        zustand('spricht');
        stilleZuruecksetzen();
        return;
      case 'output_audio_buffer.stopped':
      case 'output_audio_buffer.cleared':
        zustand('hoert');
        return;
      case 'response.function_call_arguments.done':
        // Im Live-Umschlag traegt dieses Ereignis weder call_id noch name —
        // dort zaehlt allein output_item.done (Doku: „arguments-done alone is not sufficient").
        if (!live) { werkzeugAusfuehren(ev.response_id || 'r', ev.call_id || '', ev.name || '', ev.arguments || '{}'); }
        return;
      case 'response.done': {
        var r = ev.response || {};
        antwortZuEnde(r, r.status || 'completed');
        return;
      }
      case 'error':
        ereignis({ art: 'fehler', text: (ev.error && ev.error.message) || 'Fehler in der Sitzung' });
        return;
    }
  }

  /* ── Bedienung ──────────────────────────────────────────────────────────── */

  /** „Ruhe"-Knopf: laufende Ausgabe abbrechen und leeren, Sitzung bleibt. */
  function ruhe() {
    if (live) {
      // Live kennt kein Abbrechen des Tonpuffers; eine Anweisung mitten im
      // Gespraech unterbricht laut Doku die laufende Ausgabe.
      senden({ type: 'session.instructions.append', delegation_id: null,
               content: 'Hör sofort auf zu sprechen und warte schweigend auf die nächste Frage.' });
    } else {
      senden({ type: 'response.cancel' });
      senden({ type: 'output_audio_buffer.clear' });
    }
    zustand('hoert');
  }

  function aufraeumen() {
    if (startUhr) { clearTimeout(startUhr); startUhr = 0; }
    if (pingUhr) { clearInterval(pingUhr); pingUhr = null; }
    if (stilleUhr) { clearTimeout(stilleUhr); stilleUhr = null; }
    if (duUhr) { clearTimeout(duUhr); duUhr = null; }
    if (sprechUhr) { clearTimeout(sprechUhr); sprechUhr = null; }
    try { if (dc) { dc.close(); } } catch (e) {}
    try { if (pc) { pc.getSenders().forEach(function (s) { if (s.track) { s.track.stop(); } }); } } catch (e) {}
    try { if (pc) { pc.close(); } } catch (e) {}
    try { if (mic) { mic.getTracks().forEach(function (t) { t.stop(); }); } } catch (e) {}
    if (audioEl) { try { audioEl.srcObject = null; } catch (e) {} }
    pc = null; dc = null; mic = null;
    antworten = {};
  }

  function stop(grund) {
    if (beendet) { return; }
    beendet = true;
    var id = callId;
    callId = '';
    // Live: die Sitzung beim Anbieter selbst schliessen, bevor der Kanal faellt —
    // sonst laeuft sie (und die Abrechnung) bis zu ihrer eigenen Frist weiter.
    if (live) {
      senden({ type: 'session.close' });
      live = false;
      /* Den Kanal noch einen Moment offen lassen, damit session.close den
         Browser auch verlaesst — ein sofortiges close() wirft die letzte
         Nachricht mitunter weg. Das Uebrige (Mikrofon, Uhren) faellt sofort. */
      var altPc = pc, altDc = dc;
      pc = null; dc = null;
      setTimeout(function () {
        try { if (altDc) { altDc.close(); } } catch (e) {}
        try { if (altPc) { altPc.close(); } } catch (e) {}
      }, 600);
    }
    aufraeumen();
    zustand('ende', grund || '');
    ereignis({ art: 'ende', grund: grund || '' });
    if (id) { post({ action: 'close', callId: id }).catch(function () {}); }
    // Zurück ins Lauschen heißt: die nächste Marke schon bereitlegen.
    if (warmGewuenscht) { warmUhr = setTimeout(warmHolen, 800); }
  }

  /* Kachel verlassen oder lange unsichtbar: schließen. Sofort wäre falsch
     (Sperrschirm mitten im Gespräch kommt vor), nie wäre die Kostenfalle. */
  function sichtbarkeit() {
    if (document.hidden) {
      verstecktSeit = Date.now();
      verstecktUhr = setTimeout(function () { if (!beendet) { stop('Kachel verlassen'); } }, 20000);
    } else if (verstecktUhr) {
      clearTimeout(verstecktUhr); verstecktUhr = null;
    }
  }
  document.addEventListener('visibilitychange', sichtbarkeit);
  window.addEventListener('pagehide', function () { if (!beendet) { stop('Seite verlassen'); } });

  var kern = {
    start: start,
    stop: function (grund) { stop(grund || 'vom Nutzer beendet'); },
    ruhe: ruhe,
    istOffen: function () { return !beendet; },
    /* Beide Tonquellen für eine Visualisierung: das eigene Mikrofon und die
       Stimme der KI. Nur herausgereicht, nicht ausgewertet — der Kern bleibt
       transportlos und weiß nichts von Darstellungen. */
    stroeme: function () {
      return { mikro: mic, fern: (audioEl && audioEl.srcObject) || null };
    },
    laufzeit: function () { return offenSeit ? Math.floor((Date.now() - offenSeit) / 1000) : 0; },
    /* Marke vorprägen, solange auf das Weckwort gelauscht wird (an/aus). */
    vorwaermen: vorwaermen,
    /* Vor start(): das Versprechen des Weckwort-Erkenners auf den Nachsatz. */
    mitschrift: mitschriftSetzen,
    handleServerEvent: handleServerEvent,
    istLive: function () { return live; },
    _testSend: null
  };
  return kern;
}

wurzel.SymDoVoiceKern = { erzeuge: erzeuge };
})(typeof window !== 'undefined' ? window : globalThis);
