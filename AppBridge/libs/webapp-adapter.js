/*
 * SymDo Web-App — Browser-Transport-Adapter.
 *
 * Wird von der Bridge (ServeWebApp/BuildWebHead) in den <head> der geteilten
 * UI-Quelle (SymDoWebApp/module.html) injiziert, VOR deren IIFE. Er stellt die
 * beiden Nahtstellen bereit, die die UI sonst von der Tile-Visualisierung bekommt:
 *   - window.translate(key)              (i18n)
 *   - window.requestAction(ident, value) (GetState / Call / CheckRevisions)
 * und liefert Ergebnisse über das von der UI exportierte window.handleMessage
 * zurück. Die UI bleibt unverändert; sie merkt nicht, ob sie in der Kachel oder
 * als Webseite läuft.
 *
 * Datenquelle ist die bestehende, token-gesicherte Bridge-REST-API
 * (/hook/lists/app/v1). Der Aggregat-Zustand (Kachel-Form 'state') wird hier
 * clientseitig aus /discovery + /instances/{id}/state zusammengebaut — die
 * Web-App ist damit ein reiner API-Client wie die iOS-App.
 */
(function () {
  'use strict';

  // Standalone-Browser: ohne Viewport-Meta rendert iOS Safari mit ~980px Layout-
  // breite und skaliert die ganze Seite winzig herunter. In der Visu-Kachel setzt
  // das der Host — hier fehlt es. Der Adapter läuft im <head> vor dem Body, also
  // greift das Meta noch fürs initiale Layout.
  (function ensureViewport() {
    var m = document.querySelector('meta[name="viewport"]');
    if (!m) { m = document.createElement('meta'); m.setAttribute('name', 'viewport'); (document.head || document.documentElement).appendChild(m); }
    m.setAttribute('content', 'width=device-width, initial-scale=1, viewport-fit=cover');
  })();

  // Seitlicher Rand zum Bildschirmrand: in der Visu liefert Symcon 'marginside'
  // (→ --sym-ms); standalone fehlt er, sonst klebt der scrollbare Inhalt an den
  // Kanten. applySystemMargins überschreibt --sym-ms nur bei vorhandenem URL-
  // Parameter, daher hier gefahrlos vorbelegen. env(): Notch/Home-Indikator im
  // Querformat respektieren, aber mind. 16px.
  try {
    document.documentElement.style.setProperty(
      '--sym-ms',
      'max(16px, env(safe-area-inset-left), env(safe-area-inset-right))'
    );
  } catch (e) {}

  // Scrollbalken ausblenden — auf dem Smartphone unnötig. Nur web-seitig (die
  // Kachel-CSS bleibt unberührt). Läuft im <head> vor measureScrollbar, sodass
  // die Breite als 0 gemessen wird und die Randberechnung stimmt. Gescrollt wird
  // weiterhin (overflow bleibt).
  (function hideScrollbars() {
    var s = document.createElement('style');
    s.textContent =
      '::-webkit-scrollbar{width:0!important;height:0!important;display:none!important;background:transparent!important}'
      + '*{scrollbar-width:none!important;-ms-overflow-style:none!important}'
      // Standalone-Fix (nur Web-App, nicht Kachel): body als fixen, nicht
      // scrollenden Rahmen verankern. Sonst fängt auf iOS die Dokument-/Bounce-
      // Ebene die ERSTE Wischgeste ab (Scrollen ging „erst beim zweiten Mal");
      // der innere .screen-Scroller übernimmt dann ab der ersten Berührung.
      + 'html,body{position:fixed;top:0;left:0;right:0;bottom:0;overflow:hidden;overscroll-behavior:none;}';
    (document.head || document.documentElement).appendChild(s);
  })();

  // ---- Theme -------------------------------------------------------------------
  // In der Kachel injiziert Symcon --card-color/--content-color/--accent-color;
  // standalone fehlen sie. Wir setzen sie nach der Geräte-Präferenz (hell/dunkel)
  // und verfeinern sie mit den ECHTEN Visu-Farben aus /discovery.theme (die die
  // ShoppingList-Kachel pro Schema meldet). So sieht die Web-App aus wie die Visu.
  var THEME_DEFAULTS = {
    dark:  { card: '#2b2c30', content: '#ffffff', accent: '#00cdab' },
    light: { card: '#ffffff', content: '#1c1c1e', accent: '#00cdab' }
  };
  var discoveryTheme = null;
  function prefersDark() {
    try { return !window.matchMedia || window.matchMedia('(prefers-color-scheme: dark)').matches; }
    catch (e) { return true; }
  }
  function setColors(c) {
    var r = document.documentElement.style;
    if (c && c.card)    { r.setProperty('--card-color', c.card); }
    if (c && c.content) { r.setProperty('--content-color', c.content); }
    if (c && c.accent)  { r.setProperty('--accent-color', c.accent); }
  }
  function applyTheme() {
    var scheme = prefersDark() ? 'dark' : 'light';
    var visu = discoveryTheme && (discoveryTheme[scheme] || discoveryTheme.dark || discoveryTheme.light);
    setColors((visu && visu.card && visu.content && visu.accent) ? visu : THEME_DEFAULTS[scheme]);
    // Zeilenfläche der Kacheln (--row): im Hellmodus dezenter, sonst wirken die
    // Kacheln zu dunkel; im Dunkelmodus wie in der Visu-Kachel. Bleibt DECKEND
    // (kein 50 %-Transparent), da hinter den Kacheln der rote Wisch-zum-Löschen-
    // Layer liegt und sonst durchscheinen würde.
    document.documentElement.style.setProperty('--row', scheme === 'light'
      ? 'color-mix(in srgb, var(--card-color) 95%, var(--content-color) 5%)'
      : 'color-mix(in srgb, var(--card-color) 92%, var(--content-color) 8%)');
  }
  // Canvas-Hintergrund (Overscroll/Safe-Areas) folgt der Kartenfarbe. Zuletzt
  // eingefügt → gewinnt über das statische Default aus BuildWebHead.
  (function () {
    var s = document.createElement('style');
    // !important, da die geteilte UI body{background:transparent} setzt (in der
    // Kachel liefert der Visu-Host den Hintergrund; standalone brauchen wir ihn).
    s.textContent = 'html,body{background:var(--card-color)!important}';
    (document.head || document.documentElement).appendChild(s);
  })();
  applyTheme(); // Sofort-Default (kein Flash); /discovery verfeinert gleich.
  try {
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme);
    }
  } catch (e) {}

  var CFG  = window.__SYMDO__ || {};
  var I18N = window.__SYMDO_I18N__ || {};
  var API  = CFG.apiBase || '/hook/lists/app/v1';
  var TOKEN_KEY = 'symdo.token';

  // Lokal-zuerst: die Seite lädt immer über Connect (überall erreichbar), prüft
  // aber beim ersten API-Call die konfigurierte lokale HTTPS-Basis und schaltet
  // im Heimnetz darauf um (schneller, kein Cloud-Umweg). Unterwegs bleibt Connect.
  var LOCAL = CFG.localBase ? String(CFG.localBase).replace(/\/+$/, '') : '';
  var EP = API;      // aktuell genutzte API-Basis
  var probe = null;  // einmalige Erreichbarkeitsprüfung der lokalen URL
  function ensureBase() {
    if (probe) { return probe; }
    if (!LOCAL) { probe = Promise.resolve(); return probe; }
    probe = new Promise(function (resolve) {
      var ctl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
      var to = setTimeout(function () { if (ctl) { try { ctl.abort(); } catch (e) {} } resolve(); }, 1200);
      // no-cors: reine Erreichbarkeitsprüfung (opaque genügt) → braucht keine CORS-Header
      var opt = { mode: 'no-cors', cache: 'no-store' };
      if (ctl) { opt.signal = ctl.signal; }
      fetch(LOCAL + '/ping', opt)
        .then(function () { clearTimeout(to); EP = LOCAL; resolve(); })
        .catch(function () { clearTimeout(to); resolve(); });
    });
    return probe;
  }

  // ---- i18n: Sprache aus dem Browser, Fallback de -> en -> Schlüssel ----------
  var lang = String(navigator.language || 'de').slice(0, 2).toLowerCase();
  var dict = I18N[lang] || I18N.de || I18N.en || {};
  window.translate = function (key) {
    return (dict && Object.prototype.hasOwnProperty.call(dict, key)) ? dict[key] : key;
  };

  // ---- Auth --------------------------------------------------------------------
  function token() { try { return localStorage.getItem(TOKEN_KEY) || ''; } catch (e) { return ''; } }
  function baseHeaders() {
    var h = { 'Accept': 'application/json' };
    var t = token();
    if (t) { h['Authorization'] = 'Bearer ' + t; }
    return h;
  }
  function onUnauthorized() {
    // Phase D (Pairing) ersetzt dies durch einen Re-Pair-Screen.
    if (window.__symdoUnauthorized) { window.__symdoUnauthorized(); }
  }

  // Netzwerkfehler auf der lokalen Basis (z. B. Heimnetz verlassen) → einmal auf
  // Connect zurückfallen und dort bleiben.
  function fetchEP(path, opt) {
    return fetch(EP + path, opt).catch(function (e) {
      if (EP !== API) { EP = API; return fetch(EP + path, opt); }
      throw e;
    });
  }
  function apiGet(path) {
    return ensureBase().then(function () {
      return fetchEP(path, { headers: baseHeaders(), credentials: 'omit' }).then(function (r) {
        if (r.status === 401) { onUnauthorized(); throw new Error('unauthorized'); }
        return r.json();
      });
    });
  }
  function apiPost(path, body) {
    var h = baseHeaders(); h['Content-Type'] = 'application/json';
    var opt = { method: 'POST', headers: h, credentials: 'omit', body: JSON.stringify(body || {}) };
    return ensureBase().then(function () {
      return fetchEP(path, opt).then(function (r) {
        if (r.status === 401) { onUnauthorized(); throw new Error('unauthorized'); }
        return r.json().then(function (j) { return { status: r.status, json: j }; });
      });
    });
  }
  // Für die UI: authentifizierter POST über dieselbe lokal-zuerst-Basis (z. B.
  // der KI-Foto-Upload), damit auch dieser daheim lokal statt über Connect läuft.
  window.__symdoApiPost = apiPost;

  // ---- Pairing (Browser-Zugang) -----------------------------------------------
  // Der in der Bridge erzeugte QR öffnet https://<connect>/hook/lists/webapp#c=<code>.
  // Der Einmal-Code steht im URL-Fragment (nicht in Server-Logs); wir tauschen ihn
  // gegen ein Device-Token, das wir per-Origin in localStorage ablegen.
  var pairing = false;
  function setToken(t) { try { localStorage.setItem(TOKEN_KEY, t); } catch (e) {} }
  function clearToken() { try { localStorage.removeItem(TOKEN_KEY); } catch (e) {} }
  function parseHash() {
    var out = {};
    String(location.hash || '').replace(/^#/, '').split('&').forEach(function (kv) {
      if (!kv) { return; }
      var p = kv.split('=');
      out[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || '');
    });
    return out;
  }
  function stripHash() {
    try { history.replaceState(null, '', location.pathname + location.search); }
    catch (e) { try { location.hash = ''; } catch (e2) {} }
  }
  function whenBody(fn) { if (document.body) { fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  function showPairScreen(msg) {
    whenBody(function () {
      var el = document.getElementById('symdo-pair-screen');
      if (!el) {
        el = document.createElement('div');
        el.id = 'symdo-pair-screen';
        el.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:28px;text-align:center;background:#1c1c1e;color:#fff;font:500 16px/1.55 -apple-system,BlinkMacSystemFont,system-ui,sans-serif';
        document.body.appendChild(el);
      }
      el.innerHTML = '<div style="max-width:340px"><div style="font-size:44px;margin-bottom:14px">🔗</div><div>' + msg + '</div></div>';
    });
  }
  function hidePairScreen() { var el = document.getElementById('symdo-pair-screen'); if (el && el.parentNode) { el.parentNode.removeChild(el); } }
  function pairWithCode(code) {
    return apiPost('/pair', {
      code: code,
      deviceName: 'Browser',
      model: String(navigator.userAgent || '').slice(0, 80),
      platform: 'browser',
      appVersion: 'web'
    }).then(function (res) {
      var j = res.json || {};
      if (res.status === 200 && j.ok === true && j.token) { setToken(j.token); return j.token; }
      throw new Error((j.error && j.error.message) || 'pairing failed');
    });
  }
  // iOS: ein vom Home-Bildschirm gestartetes Lesezeichen läuft in einem eigenen
  // (Speicher-)Kontext — das in Safari gespeicherte Token ist dort nicht sichtbar.
  // Deshalb das Token zusätzlich ins URL-Fragment spiegeln: "Zum Home-Bildschirm"
  // nimmt es mit, und der Home-Screen-Start liest es und legt es lokal ab. Das
  // Fragment erreicht den Server nicht.
  // Läuft die Seite als Home-Screen-App (eigenes Fenster, keine Adressleiste)?
  // Dann ist das Token schon im Lesezeichen gespeichert und muss zur Laufzeit
  // NICHT in der URL stehen — history.replaceState ändert das Lesezeichen nicht.
  function isStandalone() {
    try {
      if (window.navigator && window.navigator.standalone === true) { return true; }
      return !!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
    } catch (e) { return false; }
  }
  function ensureTokenInUrl(t) {
    // In der Home-Screen-App das Token NICHT spiegeln (unnötig) — im normalen Tab
    // dagegen schon, sonst nimmt „Zum Home-Bildschirm" kein Token mit.
    if (!t || isStandalone() || parseHash().t === t) { return; }
    try { history.replaceState(null, '', location.pathname + location.search + '#t=' + encodeURIComponent(t)); } catch (e) {}
  }
  function startApp() {
    pairing = false;
    if (typeof window.requestAction === 'function') { window.requestAction('GetState', 0); }
  }
  window.__symdoUnauthorized = function () {
    clearToken();
    showPairScreen('Sitzung abgelaufen. Erstelle in der SymDo Bridge einen neuen Browser-Zugang und scanne den QR-Code.');
  };
  (function bootstrapPairing() {
    var hp = parseHash();
    if (hp.logout !== undefined) {
      clearToken(); stripHash();
      showPairScreen('Abgemeldet. Erstelle in der SymDo Bridge einen neuen Browser-Zugang, um dich wieder zu verbinden.');
      return;
    }
    // Token direkt aus dem Fragment (Home-Screen-Lesezeichen mit #t=…). In der
    // Home-Screen-App danach aus der URL entfernen: das Lesezeichen behält seine
    // eigene Kopie (replaceState ändert es nicht), zur Laufzeit ist das Token also
    // entbehrlich — und steht dann nicht mehr in einer teilbaren URL. Im normalen
    // Tab bleibt es stehen, weil „Zum Home-Bildschirm" es sonst nicht mitnimmt.
    // Ist das Token entzogen, liefert die API 401 -> Pair-Screen.
    if (hp.t) {
      setToken(hp.t);
      if (isStandalone() && token() === hp.t) { stripHash(); }
      return;
    }
    // Bereits gekoppelt (Token im lokalen Speicher): fürs "Zum Home-Bildschirm"
    // ins Fragment spiegeln, sonst startet das Home-Screen-Lesezeichen ohne Token.
    if (token()) { ensureTokenInUrl(token()); return; }
    // Erstkopplung über den einmaligen QR-Code (#c=…).
    if (hp.c) {
      pairing = true;
      pairWithCode(hp.c)
        .then(function (t) { ensureTokenInUrl(t); startApp(); })
        .catch(function () { pairing = false; stripHash(); showPairScreen('Kopplung fehlgeschlagen oder abgelaufen. Bitte in der Bridge einen neuen Browser-Zugang erstellen.'); });
      return;
    }
    showPairScreen('Nicht gekoppelt. Erstelle in der SymDo Bridge einen Browser-Zugang und scanne den QR-Code mit der iPhone-Kamera.');
  })();

  // Instanz-Art aus der letzten discovery (für Call/CheckRevisions ohne Roundtrip)
  var kindOf = {};
  // Zuletzt an die UI gelieferte Revision je Instanz. Der Push-Kanal signalisiert
  // nur „etwas hat sich geändert" (ohne IDs), also braucht der Adapter selbst
  // einen Vergleichsstand — die UI reicht ihn nur beim Poll mit.
  var lastRev = {};

  function deliver(payload) {
    // Revisionen mitschreiben, egal ob Voll-State oder Einzel-Instanz.
    if (payload && payload.type === 'instanceState' && payload.instanceID) {
      lastRev[String(payload.instanceID)] = String(payload.revision || 0);
    } else if (payload && payload.state && payload.state.states) {
      Object.keys(payload.state.states).forEach(function (id) {
        lastRev[String(id)] = String(payload.state.states[id].revision || 0);
      });
    }
    if (typeof window.handleMessage === 'function') { window.handleMessage(payload); }
  }

  // Laufende eigene Aktionen. Solange eine offen ist (plus kurze Nachlaufzeit für
  // die Antwort, die schon unterwegs sein kann), unterdrückt der Push-Kanal seinen
  // Abgleich — die Antwort der Aktion liefert denselben Zustand ohnehin.
  var callsInFlight = 0;
  var callQuietUntil = 0;
  function releaseCall() {
    callsInFlight = Math.max(0, callsInFlight - 1);
    callQuietUntil = Date.now() + 400;
  }
  function ownActionPending() {
    return callsInFlight > 0 || Date.now() < callQuietUntil;
  }

  // Holt die Server-Revisionen und liefert nur die Instanzen nach, deren Revision
  // vom übergebenen Stand abweicht. Gemeinsamer Kern von Poll (CheckRevisions) und
  // Push-Signal — ein Codepfad, damit beide sich nicht auseinanderentwickeln.
  function syncChanged(clientRevs) {
    return apiGet('/revisions').then(function (rv) {
      var server = (rv && rv.revisions) || {};
      Object.keys(server).forEach(function (id) {
        if (String(server[id]) === String(clientRevs[id])) { return; }
        apiGet('/instances/' + id + '/state').then(function (s) {
          if (!s || s.ok !== true) { return; }
          var kind = s.kind || kindOf[String(id)] || '';
          deliver({
            type: 'instanceState',
            instanceID: parseInt(id, 10),
            kind: kind,
            revision: s.revision || 0,
            state: stripState(kind, s.state || {}),
            ok: true,
            error: null,
            txn: ''
          });
        }).catch(function () {});
      });
    }).catch(function () {});
  }

  // Global eindeutige Idempotenz-ID je Aktion. NICHT das UI-txn nehmen: das ist
  // ein pro Seitenladung zurückgesetzter Zähler (tx1, tx2, …) und würde nach einem
  // Reload mit bereits ausgeführten IDs kollidieren — die Bridge-Idempotenz hielte
  // die Aktion dann für einen Replay und würde sie STILL nicht ausführen (genau das
  // ließ Hinzufügen/Mengenänderung nach einem Reload scheitern).
  var actionSeq = 0;
  function uniqueActionId() {
    return 'web-' + Date.now().toString(36) + '-' + (++actionSeq) + '-' + Math.random().toString(36).slice(2, 8);
  }

  // Todo-States tragen die (autoritativen) Bridge-User schon top-level; Einkaufs-
  // States tragen availableImages/availableBrands, die einmalig gehoistet werden.
  function stripState(kind, state) {
    var s = Object.assign({}, state || {});
    if (kind === 'shopping') { delete s.availableImages; delete s.availableBrands; }
    else { delete s.users; }
    return s;
  }

  // discovery liefert nur {id,name,hasAvatar} (keine Bilddaten wie die Kachel-
  // Data-URI). Avatar-Foto daher über den token-gesicherten Bridge-Endpoint laden
  // (Query-Token für die 'users'-Route erlaubt). Der Browser cached es per ETag.
  function mapUsers(list) {
    return (list || []).map(function (u) {
      return {
        id: u.id,
        name: u.name,
        avatar: u.hasAvatar
          ? (API + '/users/' + encodeURIComponent(u.id) + '/avatar?t=' + encodeURIComponent(token()))
          : ''
      };
    });
  }

  // ---- Aggregation: discovery + per-Instanz-state -> Kachel-'state' ------------
  function loadFullState() {
    return apiGet('/discovery').then(function (disc) {
      if (!disc || disc.ok !== true) { throw new Error('discovery failed'); }
      // Echte Visu-Farben übernehmen (falls die ShoppingList-Kachel welche gemeldet hat).
      discoveryTheme = disc.theme || null;
      applyTheme();
      var instances = [], hiddenIDs = [], visible = [];
      (disc.instances || []).forEach(function (inst) {
        kindOf[String(inst.id)] = inst.kind;
        instances.push({ id: inst.id, kind: inst.kind, name: inst.name, hidden: !!inst.hidden });
        if (inst.hidden) { hiddenIDs.push(inst.id); } else { visible.push(inst); }
      });
      return Promise.all(visible.map(function (inst) {
        return apiGet('/instances/' + inst.id + '/state')
          .then(function (s) { return { inst: inst, data: (s && s.ok === true) ? s : null }; })
          .catch(function () { return { inst: inst, data: null }; });
      })).then(function (results) {
        var states = {}, images = {}, brands = {}, shoppingExtras = {};
        results.forEach(function (r) {
          if (!r.data) { return; }
          var kind = r.data.kind || r.inst.kind;
          var st = r.data.state || {};
          if (kind === 'shopping') {
            if (Object.keys(images).length === 0 && st.availableImages && Object.keys(st.availableImages).length) { images = st.availableImages; }
            if (Object.keys(brands).length === 0 && st.availableBrands && Object.keys(st.availableBrands).length) { brands = st.availableBrands; }
            // Produktbilder über den Bridge-Asset-Endpoint (same-origin, Token als
            // Query erlaubt). Die UI hängt encodeURIComponent(datei) an imageBase an
            // → …/assets?t=<token>&f=<datei>; HandleAsset liest $_GET['f'].
            // &v=<assetsVersion>: der Asset-Endpoint antwortet mit
            // Cache-Control max-age=2592000 (30 Tage). Wird ein Bestandsbild
            // ersetzt, bleibt der Dateiname gleich — ohne den Stempel zeigte die
            // Web-App bis zu 30 Tage die alte Fassung, ohne je nachzufragen (der
            // ETag greift erst, wenn der Eintrag nicht mehr frisch ist).
            shoppingExtras[String(r.inst.id)] = {
              imageBase: API + '/assets?t=' + encodeURIComponent(token()) +
                         '&v=' + encodeURIComponent(String((disc.server && disc.server.assetsVersion) || 0)) + '&f=',
              extApiBase: '',
              imagesEnabled: true
            };
          }
          states[String(r.inst.id)] = { kind: kind, revision: r.data.revision || 0, state: stripState(kind, st) };
        });
        return {
          type: 'state',
          users: mapUsers(disc.users),
          defaultUserID: '',
          bridgeAvailable: true,
          hiddenIDs: hiddenIDs,
          instances: instances,
          states: states,
          images: images,
          brands: brands,
          shoppingExtras: shoppingExtras
        };
      });
    });
  }

  // ---- Nahtstelle: window.requestAction (identisch zur Visu-Signatur) ----------
  window.requestAction = function (ident, value) {
    if (ident === 'GetState') {
      if (pairing) { return; }
      loadFullState().then(function (p) { hidePairScreen(); deliver(p); })
                     .catch(function (e) { if (String(e && e.message) !== 'unauthorized') { console.warn('SymDo GetState:', e); } });
      return;
    }
    if (ident === 'Call') {
      var d; try { d = JSON.parse(value); } catch (e) { return; }
      var id = d.instanceID, kind = kindOf[String(id)] || '';
      // Eigene Aktion: die Antwort bringt den maßgeblichen Zustand selbst mit.
      // Der Push, den dieselbe Mutation serverseitig auslöst, würde sonst — je
      // nachdem, was zuerst eintrifft — einen ZWEITEN Zustandsabruf und damit
      // einen zweiten kompletten Neuaufbau der Liste anstoßen. Sichtbar wird das
      // als Flackern aller Produktbilder. Solange ein Call läuft, ignorieren wir
      // die Türklingel; fremde Änderungen kommen weiter sofort durch.
      callsInFlight++;
      apiPost('/instances/' + id + '/actions', { action: d.action, payload: d.payload, clientActionId: uniqueActionId() })
        .then(function (res) {
          var j = res.json || {};
          releaseCall();
          deliver({
            type: 'instanceState',
            instanceID: id,
            kind: j.kind || kind,
            revision: j.revision || 0,
            state: (j.state != null) ? stripState(j.kind || kind, j.state) : null,
            ok: j.ok === true,
            error: j.error || null,
            txn: d.txn
          });
        })
        .catch(function (e) {
          releaseCall();
          if (String(e && e.message) !== 'unauthorized') { console.warn('SymDo Call:', e); }
        });
      return;
    }
    if (ident === 'CheckRevisions') {
      var cd; try { cd = JSON.parse(value); } catch (e) { return; }
      syncChanged(cd.revisions || {});
      return;
    }
  };

  // ---- Push-Kanal: WebSocket auf dem Bridge-Hook ------------------------------
  // Symcon zieht auf einem Hook-Pfad selbst einen WebSocket hoch (WebHook Control
  // ab 5.2) und sendet darüber per WC_PushMessage. Wir empfangen nur ein
  // inhaltsloses „dirty" und holen den Inhalt danach über die token-gesicherte
  // REST-API — auf dem Socket liegen also nie Nutzdaten. Deshalb braucht er auch
  // keine Authentifizierung: Symcon akzeptiert den Upgrade, bevor eigener Code
  // läuft, ein Broadcast erreicht damit auch fremde Zuhörer.
  //
  // Der 15-s-Poll der geteilten UI bleibt unangetastet und ist das Sicherheitsnetz:
  // fällt der Socket aus, verhält sich die App genau wie bisher. Die Kachel hält
  // ihren Poll neben dem eigenen Push ebenso.
  (function initPushChannel() {
    // Bewusst OHNE Prüfung auf 'pairing': der Kanal trägt keine Daten und braucht
    // keinen Token, ein Verbindungsaufbau vor der Kopplung ist also harmlos. Würde
    // hier auf die Kopplung gewartet, bliebe die Web-App nach einer ERSTMALIGEN
    // Kopplung bis zum nächsten Reload ohne Push (der Kopplungsablauf lädt die
    // Seite nicht neu) — genau der Moment, in dem man es zuerst ausprobiert.
    if (typeof window.WebSocket !== 'function') { return; }
    var url = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/hook/lists/ws';
    var sock = null;
    var backoff = 2000;   // 2s, verdoppelnd bis 60s
    var debounce = null;

    // Nicht ohne Backoff neu verbinden: die Connect-nginx antwortet bei vielen
    // gleichzeitigen Handshakes mit 503.
    function connect() {
      if (sock || document.visibilityState !== 'visible') { return; }
      try { sock = new WebSocket(url); } catch (e) { sock = null; retry(); return; }

      sock.onopen = function () { backoff = 2000; };
      sock.onmessage = function () {
        // Mehrere Signale kurz hintereinander zu einem Abgleich zusammenfassen.
        if (debounce) { return; }
        debounce = window.setTimeout(function () {
          debounce = null;
          if (document.visibilityState !== 'visible') { return; }
          // Läuft gerade eine eigene Aktion, bringt deren Antwort den Zustand mit —
          // ein zusätzlicher Abgleich wäre nur ein zweiter Neuaufbau der Liste.
          if (ownActionPending()) { return; }
          syncChanged(lastRev);
        }, 150);
      };
      sock.onclose = function () { sock = null; retry(); };
      sock.onerror = function () { /* onclose folgt und übernimmt den Reconnect */ };
    }

    function retry() {
      window.setTimeout(function () {
        backoff = Math.min(backoff * 2, 60000);
        connect();
      }, backoff);
    }

    // Mobile Browser kappen Sockets im Hintergrund — beim Zurückkehren neu
    // aufbauen und einmal sofort abgleichen, damit die Wartezeit nicht am Poll hängt.
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState !== 'visible') { return; }
      if (!sock || sock.readyState > 1) { sock = null; backoff = 2000; connect(); }
      syncChanged(lastRev);
    });

    connect();
  })();

  // Web-only: die geteilte UI hat kein onerror an Produktbildern. Fällt ein Bild
  // aus (fehlendes Asset oder externe Scan-URL, die der Asset-Endpoint nicht
  // liefert), blenden wir das kaputte Bild aus und zeigen einen neutralen Kreis
  // statt eines Broken-Image-Icons. 'error' bubbelt nicht → Capture-Phase.
  document.addEventListener('error', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG' && typeof t.closest === 'function' && t.closest('.item-thumb, .strip-thumb')) {
      if (t.parentNode) { t.parentNode.style.background = 'var(--surface2)'; }
      t.style.display = 'none';
    }
  }, true);
})();
