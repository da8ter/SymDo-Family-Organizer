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
  /* Frist fuer den Verbindungsaufbau. Ohne sie bleibt die Anzeige ewig auf
     "verbinde" stehen, wenn die Mikrofonfrage unbeantwortet bleibt:
     getUserMedia loest dann WEDER auf NOCH ab (headless nachgestellt und
     nach 5 s noch haengend). */
  var startUhr = 0;
  var werkzeugDeckelMs = opt.toolTimeoutMs || 8000;
  var stilleMs = (opt.silenceSeconds || 45) * 1000;

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

    // ZUERST das Mikrofon, DANN die Marke: die Berechtigungsfrage kann
    // Sekunden dauern, und die Marke verfällt nach 60 s.
    return navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
    }).then(function (strom) {
      if (beendet) { strom.getTracks().forEach(function (t) { t.stop(); }); return false; }
      mic = strom;
      return post({ action: 'open' }).then(function (r) {
        if (!r || r.ok !== true || !r.value) {
          var text = (r && (r.sag || (r.error && r.error.message))) || 'Keine Sitzung bekommen.';
          throw { eigene: true, message: text };
        }
        return handschlag(r);
      });
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

  function handschlag(sitzung) {
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
      if (pc.connectionState === 'connected') {
        if (startUhr) { clearTimeout(startUhr); startUhr = 0; }
        zustand('hoert'); stilleZuruecksetzen();
      }
      if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected'
          || pc.connectionState === 'closed') {
        if (!beendet) { stop('Verbindung verloren'); }
      }
    };
    mic.getAudioTracks().forEach(function (t) { pc.addTrack(t, mic); });
    // Der Datenkanal MUSS vor createOffer existieren — sonst fehlt die
    // m=application-Zeile im Angebot und der Kanal kommt nie zustande.
    dc = pc.createDataChannel('oai-events');
    dc.onmessage = function (ev) {
      var daten; try { daten = JSON.parse(ev.data); } catch (e) { return; }
      kern.handleServerEvent(daten);
    };
    dc.onclose = function () { if (!beendet) { stop('Kanal geschlossen'); } };

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
      pingUhr = setInterval(function () {
        post({ action: 'ping', callId: callId }).then(function (r) {
          if (r && r.stop === true) { stop(r.grund || 'Zeitdeckel'); }
          else if (r && typeof r.secondsLeft === 'number') {
            ereignis({ art: 'rest', sekunden: r.secondsLeft });
          }
        }).catch(function () {});
      }, (sitzung.pingSeconds || 15) * 1000);
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
        type: 'conversation.item.create',
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
    }, werkzeugDeckelMs);
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

  function handleServerEvent(ev) {
    var typ = (ev && ev.type) || '';
    switch (typ) {
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
        antwortZustand(ev.response && ev.response.id || 'r');
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
        werkzeugAusfuehren(ev.response_id || 'r', ev.call_id || '', ev.name || '', ev.arguments || '{}');
        return;
      case 'response.done': {
        var r = ev.response || {};
        var a = antwortZustand(r.id || 'r');
        var aufrufe = 0;
        (r.output || []).forEach(function (it) { if (it && it.type === 'function_call') { aufrufe++; } });
        a.erwartet = aufrufe;
        a.status = r.status || 'completed';
        if (aufrufe === 0) { zustand('hoert'); }
        vielleichtWeiter(r.id || 'r');
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
    senden({ type: 'response.cancel' });
    senden({ type: 'output_audio_buffer.clear' });
    zustand('hoert');
  }

  function aufraeumen() {
    if (startUhr) { clearTimeout(startUhr); startUhr = 0; }
    if (pingUhr) { clearInterval(pingUhr); pingUhr = null; }
    if (stilleUhr) { clearTimeout(stilleUhr); stilleUhr = null; }
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
    aufraeumen();
    zustand('ende', grund || '');
    ereignis({ art: 'ende', grund: grund || '' });
    if (id) { post({ action: 'close', callId: id }).catch(function () {}); }
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
    handleServerEvent: handleServerEvent,
    _testSend: null
  };
  return kern;
}

wurzel.SymDoVoiceKern = { erzeuge: erzeuge };
})(typeof window !== 'undefined' ? window : globalThis);
