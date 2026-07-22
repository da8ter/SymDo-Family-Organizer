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

  var CFG  = window.__SYMDO__ || {};
  var I18N = window.__SYMDO_I18N__ || {};
  var API  = CFG.apiBase || '/hook/lists/app/v1';
  var TOKEN_KEY = 'symdo.token';

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

  function apiGet(path) {
    return fetch(API + path, { headers: baseHeaders(), credentials: 'omit' }).then(function (r) {
      if (r.status === 401) { onUnauthorized(); throw new Error('unauthorized'); }
      return r.json();
    });
  }
  function apiPost(path, body) {
    var h = baseHeaders(); h['Content-Type'] = 'application/json';
    return fetch(API + path, { method: 'POST', headers: h, credentials: 'omit', body: JSON.stringify(body || {}) })
      .then(function (r) {
        if (r.status === 401) { onUnauthorized(); throw new Error('unauthorized'); }
        return r.json().then(function (j) { return { status: r.status, json: j }; });
      });
  }

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
      if (res.status === 200 && j.ok === true && j.token) { setToken(j.token); return true; }
      throw new Error((j.error && j.error.message) || 'pairing failed');
    });
  }
  window.__symdoUnauthorized = function () {
    clearToken();
    showPairScreen('Sitzung abgelaufen. Erstelle in der SymDo Bridge einen neuen Browser-Zugang und scanne den QR-Code.');
  };
  (function bootstrapPairing() {
    var hp = parseHash();
    if (hp.logout !== undefined) { clearToken(); stripHash(); }
    if (hp.c) {
      // Code einlösen, dann sauber neu laden, damit die UI mit Token startet.
      pairing = true;
      pairWithCode(hp.c)
        .then(function () { stripHash(); location.reload(); })
        .catch(function () { pairing = false; stripHash(); showPairScreen('Kopplung fehlgeschlagen oder abgelaufen. Bitte in der Bridge einen neuen Browser-Zugang erstellen.'); });
      return;
    }
    if (!token()) {
      showPairScreen('Nicht gekoppelt. Erstelle in der SymDo Bridge einen Browser-Zugang und scanne den QR-Code mit der iPhone-Kamera.');
    }
  })();

  // Instanz-Art aus der letzten discovery (für Call/CheckRevisions ohne Roundtrip)
  var kindOf = {};

  function deliver(payload) { if (typeof window.handleMessage === 'function') { window.handleMessage(payload); } }

  // Todo-States tragen die (autoritativen) Bridge-User schon top-level; Einkaufs-
  // States tragen availableImages/availableBrands, die einmalig gehoistet werden.
  function stripState(kind, state) {
    var s = Object.assign({}, state || {});
    if (kind === 'shopping') { delete s.availableImages; delete s.availableBrands; }
    else { delete s.users; }
    return s;
  }

  // ---- Aggregation: discovery + per-Instanz-state -> Kachel-'state' ------------
  function loadFullState() {
    return apiGet('/discovery').then(function (disc) {
      if (!disc || disc.ok !== true) { throw new Error('discovery failed'); }
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
            // Bilder folgen in Phase E über die Bridge-Asset-Endpoints; bis dahin
            // rendert die Einkaufsliste mit Initialen/Namen (imagesEnabled:false).
            shoppingExtras[String(r.inst.id)] = { imageBase: '', extApiBase: '', imagesEnabled: false };
          }
          states[String(r.inst.id)] = { kind: kind, revision: r.data.revision || 0, state: stripState(kind, st) };
        });
        return {
          type: 'state',
          users: disc.users || [],
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
      apiPost('/instances/' + id + '/actions', { action: d.action, payload: d.payload, clientActionId: d.txn })
        .then(function (res) {
          var j = res.json || {};
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
        .catch(function (e) { if (String(e && e.message) !== 'unauthorized') { console.warn('SymDo Call:', e); } });
      return;
    }
    if (ident === 'CheckRevisions') {
      var cd; try { cd = JSON.parse(value); } catch (e) { return; }
      var client = cd.revisions || {};
      apiGet('/revisions').then(function (rv) {
        var server = (rv && rv.revisions) || {};
        Object.keys(server).forEach(function (id) {
          if (String(server[id]) === String(client[id])) { return; }
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
      return;
    }
  };
})();
