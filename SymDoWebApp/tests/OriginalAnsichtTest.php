<?php

declare(strict_types=1);

/**
 * Pruefstand: das Original eines KI-Vorschlags in der Web-App (24.09.2026).
 *
 * Teil 1 (immer): Verdrahtung — Blatt, Augen, Behaelter, Kopien, Uebersetzungen.
 * Teil 2 (MIT_CHROME=1): die echte Web-App des Docker-Gateways mit FESTEN Daten.
 * Die Huellen um window.__symdoApiPost, window.requestAction und fetch liefern
 * Vorschlaege, Original, Anhangsstuecke, Kalender und beantworten jede
 * schreibende Aktion selbst — nichts davon erreicht das Gateway.
 *
 * Gefahren wird, was der Mensch tut:
 *  - Auge an der Zeile (grau, links neben ✕) → Blatt mit maskiertem Text und
 *    Anhaengen; das PDF in drei Stuecken laden und zusammensetzen.
 *  - Aufgabe, Termin, Hausaufgabe, Notiz aus dem Vorschlag hinzufuegen: mit und
 *    ohne „Original speichern", mit den gewaehlten Anhaengen.
 *  - Ein gespeicherter Termin mit Original: das Auge im Dialog oeffnet das Blatt
 *    DARUEBER.
 *
 *   php SymDoWebApp/tests/OriginalAnsichtTest.php
 *   MIT_CHROME=1 SYMDO_TOKEN_FILE=… php SymDoWebApp/tests/OriginalAnsichtTest.php
 */

$fehler = 0;
$zahl = 0;
function pruefe(bool $ok, string $was): void
{
    global $fehler, $zahl;
    $zahl++;
    if (!$ok) {
        $fehler++;
        echo "  FEHLT: $was\n";
    }
}
function schluss(): never
{
    global $fehler, $zahl;
    echo $fehler === 0 ? "OK — $zahl Prüfungen bestanden\n" : "FEHLER — $fehler von $zahl Prüfungen gefallen\n";
    exit($fehler === 0 ? 0 : 1);
}

$html = (string)file_get_contents(__DIR__ . '/../module.html');

// ── Teil 1: Verdrahtung ─────────────────────────────────────────────────────
pruefe(str_contains($html, 'id="originalOverlay"') && str_contains($html, '#originalOverlay { z-index: 61; }'),
    'das Original-Blatt liegt ueber den Editoren (61 > 60)');
pruefe(str_contains($html, '#recipeImgOverlay { z-index: 62; }'), 'der Betrachter liegt ueber dem Original-Blatt');
foreach (['tdOriginalAuge', 'ceOriginalAuge', 'hwOriginalAuge', 'nvOriginalAuge'] as $auge) {
    pruefe(str_contains($html, 'id="' . $auge . '"'), "Auge im Dialog: $auge");
}
foreach (['tdOriginal', 'ceOriginal', 'hwOriginal', 'ntOriginal'] as $b) {
    pruefe(str_contains($html, 'id="' . $b . '" class="original-wahl"'), "Behaelter „Original speichern“: $b");
}
pruefe(str_contains($html, 'data-mail="original"') && str_contains($html, 'function originalZeigen(id)'), 'Auge an der Zeile und das Blatt');
pruefe(str_contains($html, "text.innerHTML = String(o.text || '').trim() !== '' ? notizTextHtml(String(o.text)) : '';"),
    'der Text geht durch notizTextHtml (erst maskiert)');
foreach (['ToDoList', 'ShoppingList', 'SymDoEdumaps', 'SymDoNotes', 'SymDoHomework'] as $kopie) {
    $k = (string)@file_get_contents(__DIR__ . '/../../' . $kopie . '/module.html');
    pruefe(str_contains($k, 'function originalZeigen(id)') && str_contains($k, 'id="originalOverlay"'), "Kopie $kopie ist nachgezogen");
}
$schluessel = ['Show original', 'Save original', 'Original message', 'The original is no longer available.',
               'The original could not be saved.', 'Shortened — the full text was not available.', 'Not kept',
               'too large for a note', 'Loading part %1 of %2'];
foreach (['SymDoWebApp', 'ToDoList', 'ShoppingList'] as $m) {
    $de = json_decode((string)file_get_contents(__DIR__ . '/../../' . $m . '/locale.json'), true)['translations']['de'] ?? [];
    pruefe(array_filter($schluessel, static fn($s) => !isset($de[$s])) === [], "Uebersetzungen in $m/locale.json");
}

// ── Teil 2: die echte Oberflaeche ───────────────────────────────────────────
if (getenv('MIT_CHROME') !== '1') {
    echo "  (Browser-Teil übersprungen, MIT_CHROME=1 setzt ihn an)\n";
    schluss();
}
$chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
$basis  = getenv('SYMDO_BASE') ?: 'http://127.0.0.1:3778';
$token  = trim((string)@file_get_contents((string)getenv('SYMDO_TOKEN_FILE')));
if (!is_file($chrome) || $token === '') {
    echo "  (Browser-Teil übersprungen: Chrome oder Token fehlt)\n";
    schluss();
}
$ctx = stream_context_create(['http' => ['header' => 'Authorization: Bearer ' . $token, 'timeout' => 10]]);
$nutzer = json_decode((string)@file_get_contents($basis . '/hook/lists/app/v1/users', false, $ctx), true);
$nutzer = is_array($nutzer['users'] ?? null) ? $nutzer['users'] : (is_array($nutzer) ? $nutzer : []);
$kinder = array_values(array_filter($nutzer, static fn($u) => is_array($u) && ($u['persona'] ?? '') === 'child'));
if ($kinder === []) {
    pruefe(false, 'das Pruefsystem braucht ein Kind');
    schluss();
}
$kind = (string)$kinder[0]['id'];
$oid = str_repeat('a', 24);
$bid = str_repeat('b', 24);
$jetzt = time();
$vorschlaege = [
    ['id' => 'probe:orig', 'source' => 'LOGINEO', 'userId' => $kind, 'at' => $jetzt, 'created' => $jetzt, 'originalId' => $oid,
     'from' => 'lehrerin@example.test', 'subject' => 'Lernzeitplan KW 40', 'items' => [
        ['i' => 0, 'kind' => 'task', 'title' => 'Arbeitsheft S. 4 und 5', 'info' => 'Lernzeitplan', 'due' => '2026-10-02',
         'allDay' => true, 'assignedTo' => [], 'priority' => 'normal', 'taken' => false],
        ['i' => 1, 'kind' => 'task', 'title' => 'Buntstifte mitbringen', 'info' => '', 'due' => null,
         'allDay' => true, 'assignedTo' => [], 'priority' => 'normal', 'taken' => false],
        ['i' => 2, 'kind' => 'event', 'title' => 'Wandertag', 'info' => 'Treffpunkt Schule', 'due' => '2026-10-07',
         'allDay' => true, 'assignedTo' => [], 'priority' => 'normal', 'taken' => false],
        ['i' => 3, 'kind' => 'homework', 'title' => 'Rechenheft S. 5', 'info' => null, 'note' => 'Rechenheft S. 5',
         'subject' => 'Mathematik', 'childId' => '', 'due' => '2026-10-01', 'allDay' => false, 'assignedTo' => [], 'priority' => 'normal', 'taken' => false],
        ['i' => 4, 'kind' => 'note', 'title' => 'Beitraege', 'text' => 'Tabelle der Beitraege', 'info' => null, 'due' => null,
         'allDay' => false, 'assignedTo' => [], 'priority' => 'normal', 'taken' => false],
    ]],
    ['id' => 'probe:ohne', 'source' => 'IMAP', 'userId' => '', 'at' => $jetzt - 60, 'created' => $jetzt - 60,
     'from' => 'x@example.test', 'subject' => 'Ohne Original', 'items' => [
        ['i' => 0, 'kind' => 'task', 'title' => 'Alte Mail', 'info' => '', 'due' => null, 'allDay' => true,
         'assignedTo' => [], 'priority' => 'normal', 'taken' => false],
    ]],
];
$original = ['id' => $oid, 'proposalId' => 'probe:orig', 'source' => 'LOGINEO', 'from' => 'lehrerin@example.test', 'fromName' => '',
    'subject' => 'Lernzeitplan KW 40', 'date' => $jetzt, 'origin' => null, 'truncated' => false,
    'text' => "Liebe Eltern,\n\nbitte <b>fett</b> beachten: Arbeitsheft S. 4 und 5.\nhttps://schule.example.test/plan",
    'atts' => [['n' => 1, 'name' => 'Lernzeitplan.pdf', 'kind' => 'pdf', 'bytes' => 30, 'deko' => false, 'notiz' => true],
               ['n' => 2, 'name' => 'logo_schule.png', 'kind' => 'png', 'bytes' => 9, 'deko' => true, 'notiz' => true]],
    'skipped' => ['Elternbrief.docx']];
$termin = ['id' => 'ev1', 'uid' => 'uid-ev1', 'calendarID' => 5, 'title' => 'Gespeicherter Termin', 'info' => '', 'location' => '',
    'start' => $jetzt + 86400, 'end' => $jetzt + 90000, 'allDay' => false, 'members' => [], 'reminder' => -1,
    'recurring' => false, 'canUpdateOccurrence' => true, 'canDeleteOccurrence' => true, 'originalId' => $oid];
$daten = ['vorschlaege' => $vorschlaege, 'original' => $original, 'termin' => $termin, 'bid' => $bid, 'kind' => $kind];

$arbeit = sys_get_temp_dir() . '/symdo-original-' . getmypid();
@mkdir($arbeit, 0o777, true);
$seite = (string)file_get_contents($basis . '/hook/lists/webapp');
$seite = str_replace(["'/hook/lists/app/v1", '"/hook/lists/app/v1'],
                     ["'" . $basis . '/hook/lists/app/v1', '"' . $basis . '/hook/lists/app/v1'], $seite);

$huelle = '<script>try{localStorage.setItem("symdo.token",' . json_encode($token) . ');localStorage.setItem("symdo.tab","ki");}catch(e){}'
    . 'window.__PROBE=' . json_encode($daten, JSON_UNESCAPED_UNICODE) . ';</script><script>' . <<<'JS'
(function(){
  var P = window.__PROBE, echtPost = null, echtAktion = null;
  window.__probe = { post: [], aktion: [], teile: [], blobs: [] };
  function merk(pfad, nutzlast) { window.__probe.post.push({ pfad: pfad, n: JSON.parse(JSON.stringify(nutzlast || {})) }); }
  function antwort(j) { return Promise.resolve({ status: 200, json: j }); }
  function huelle(pfad, nutzlast) {
    var a = String((nutzlast && nutzlast.action) || 'list');
    if (/\/mail\/proposals$/.test(pfad)) {
      merk(pfad, nutzlast);
      if (a === 'list') return antwort({ ok: true, proposals: JSON.parse(JSON.stringify(P.vorschlaege)) });
      if (a === 'original') return antwort(nutzlast.id === P.original.id ? { ok: true, original: P.original }
        : { ok: false, error: { code: 'not_found' } });
      if (a === 'originalBehalten') return antwort({ ok: true, id: P.bid });
      return antwort({ ok: true });
    }
    if (/\/calendar$/.test(pfad)) {
      if (a === 'calendars') return antwort({ ok: true, available: true, calendars: [{ id: 5, name: 'Probe', color: '#888888', canWrite: true }] });
      if (a === 'events') return antwort({ ok: true, events: [P.termin], truncated: false });
      merk(pfad, nutzlast);
      return antwort({ ok: true, created: 1 });
    }
    if (/\/homework$/.test(pfad) && a !== 'list') { merk(pfad, nutzlast); return antwort({ ok: true }); }
    if (/\/notes$/.test(pfad) && a === 'adopt') { merk(pfad, nutzlast); return antwort({ ok: true, rev: 1, note: { id: 'probe-note', att: [] } }); }
    return echtPost ? echtPost(pfad, nutzlast) : Promise.resolve({ status: 0, json: null });
  }
  Object.defineProperty(window, '__symdoApiPost', { configurable: true, get: function(){ return huelle; }, set: function(v){ echtPost = v; } });
  // sdCall/AddItem laeuft im Adapter ueber requestAction — mitschreiben und schlucken.
  function aktion(ident, wert) {
    if (ident === 'Call') {
      var c = {}; try { c = JSON.parse(wert); } catch (e) {}
      if (c.action === 'AddItem' || c.action === 'UpdateItem') { window.__probe.aktion.push(c); return; }
    }
    return echtAktion ? echtAktion(ident, wert) : undefined;
  }
  Object.defineProperty(window, 'requestAction', { configurable: true, get: function(){ return aktion; }, set: function(v){ echtAktion = v; } });
  // Die Stuecke der REST-Route: ein PDF aus drei Teilen.
  var echtFetch = window.fetch.bind(window);
  var stueck = ['%PDF-1.4 ', 'mitte-mitte ', 'ende-%%EOF'];
  window.fetch = function(url, opt){
    var u = String(url && url.url || url);
    var m = /\/original\/([0-9a-f]{24})\/(\d+)\?teil=(\d+)$/.exec(u);
    if (m) {
      var k = parseInt(m[3], 10);
      window.__probe.teile.push({ n: parseInt(m[2], 10), teil: k, auth: !!(opt && opt.headers && opt.headers.Authorization) });
      return Promise.resolve(new Response(stueck[k] || '', { status: 200, headers: { 'X-SymDo-Teile': '3', 'Content-Type': 'application/pdf' } }));
    }
    return echtFetch(url, opt);
  };
  var echtUrl = URL.createObjectURL.bind(URL);
  URL.createObjectURL = function(b){ try { window.__probe.blobs.push({ size: b.size, type: b.type }); } catch (e) {} return echtUrl(b); };
})();
</script>
JS;
$seite = substr_replace($seite, $huelle, (int)strpos($seite, '<script'), 0);

$treiber = '<script>' . <<<'JS'
(function(){
  var raus = { schritte: [] };
  function fertig(fehler){ raus.fehler = fehler || null; raus.probe = window.__probe; document.title = 'FERTIG ' + JSON.stringify(raus); }
  function q(s, w){ return (w || document).querySelector(s); }
  function offen(id){ var o = document.getElementById(id); if (!o) return false; var s = getComputedStyle(o); return s.display !== 'none' && s.visibility !== 'hidden' && o.offsetHeight > 0; }
  function warte(bed, ms){ return new Promise(function(ok, nein){ var t0 = Date.now(); (function lauf(){ var r; try { r = bed(); } catch (e) {} if (r) return ok(r); if (Date.now() - t0 > (ms || 8000)) return nein(new Error('Zeit')); setTimeout(lauf, 100); })(); }); }
  function pause(ms){ return new Promise(function(ok){ setTimeout(ok, ms); }); }
  function zeile(id, i){ return q('.mail-row[data-id="' + id + '"][data-i="' + i + '"]'); }
  function zu(id){ var k = q('#' + id + ' [data-close="' + id + '"]'); if (k) k.click(); }
  function schalteOriginal(behaelter){ var k = q('#' + behaelter + ' [data-orig-an]'); if (k) { k.checked = true; k.dispatchEvent(new Event('change', { bubbles: true })); } return !!k; }
  function wahlStand(behaelter){ return Array.from(document.querySelectorAll('#' + behaelter + ' [data-orig-wahl]')).map(function(k){ return { n: k.dataset.origWahl, an: k.checked, gesperrt: k.disabled }; }); }
  var s = raus.schritte;
  warte(function(){ return zeile('probe:orig', 0); }, 15000).then(function(z){
    // 1. Auge an der Zeile
    var knoepfe = Array.from(z.querySelectorAll('.row-btn')).map(function(b){ return (b.dataset.mail || '') + ':' + b.className; });
    s.push({ zeile: knoepfe, ohne: Array.from(zeile('probe:ohne', 0).querySelectorAll('.row-btn')).map(function(b){ return b.dataset.mail; }) });
    q('[data-mail="original"]', z).click();
    return warte(function(){ return offen('originalOverlay') && q('#originalFiles .note-file'); });
  }).then(function(){
    var body = q('#originalBody');
    s.push({ titel: q('#originalTitle').textContent, meta: q('#originalMeta').textContent,
             fett: !!body.querySelector('b'), text: body.textContent.indexOf('<b>fett</b>') >= 0, link: !!body.querySelector('a[href^="https://"]'),
             dateien: Array.from(document.querySelectorAll('#originalFiles .note-file')).map(function(d){ return d.className + '|' + d.textContent; }) });
    q('#originalFiles [data-orig-n="1"]').click();
    return warte(function(){ return offen('recipeImgOverlay'); });
  }).then(function(){
    var pdf = q('#recipePdf');
    s.push({ betrachter: true, pdf: String(pdf.src || '').slice(0, 5), zIndex: [getComputedStyle(q('#recipeImgOverlay')).zIndex, getComputedStyle(q('#originalOverlay')).zIndex] });
    zu('recipeImgOverlay'); zu('originalOverlay');
    return pause(500);
  }).then(function(){
    // 2. Aufgabe MIT Original: Schalter an, das Logo bleibt aus
    q('[data-mail="add"]', zeile('probe:orig', 0)).click();
    return warte(function(){ return offen('todoOverlay') && q('#tdOriginal [data-orig-wahl]'); });
  }).then(function(){
    s.push({ aufgabeVorher: { sichtbar: offen('todoOverlay') && getComputedStyle(q('#tdOriginal')).display !== 'none',
             schalter: q('#tdOriginal [data-orig-an]').checked, wahl: wahlStand('tdOriginal') } });
    schalteOriginal('tdOriginal');
    s.push({ aufgabeNachher: wahlStand('tdOriginal') });
    q('#btnTodoSave').click();
    return warte(function(){ return window.__probe.aktion.length >= 1; });
  }).then(function(){
    // 3. Aufgabe OHNE Original
    return warte(function(){ return !offen('todoOverlay'); }).then(function(){
      q('[data-mail="add"]', zeile('probe:orig', 1)).click();
      return warte(function(){ return offen('todoOverlay'); });
    });
  }).then(function(){
    q('#btnTodoSave').click();
    return warte(function(){ return window.__probe.aktion.length >= 2; });
  }).then(function(){
    // 4. Termin mit Original
    return warte(function(){ return !offen('todoOverlay'); }).then(function(){
      q('[data-mail="add"]', zeile('probe:orig', 2)).click();
      return warte(function(){ return offen('calEditOverlay') && q('#ceOriginal [data-orig-an]'); });
    });
  }).then(function(){
    schalteOriginal('ceOriginal');
    q('#btnCalEditSave') ? q('#btnCalEditSave').click() : q('#calEditOverlay .overlay-footer .primary').click();
    return warte(function(){ return window.__probe.post.some(function(p){ return /\/calendar$/.test(p.pfad); }); });
  }).then(function(){
    // 5. Hausaufgabe mit Original
    return warte(function(){ return !offen('calEditOverlay'); }).then(function(){
      q('[data-mail="add"]', zeile('probe:orig', 3)).click();
      return warte(function(){ return offen('hwOverlay') && q('#hwOriginal [data-orig-an]'); });
    });
  }).then(function(){
    schalteOriginal('hwOriginal');
    var fach = q('#hwSubject'); if (fach && !fach.value && fach.options.length > 1) fach.value = fach.options[1].value;
    q('#btnHwSave').click();
    return warte(function(){ return window.__probe.post.some(function(p){ return /\/homework$/.test(p.pfad); }); });
  }).then(function(){
    // 6. Notiz: Anhaenge aus dem Original, „Original speichern" nur fuer den Text
    return warte(function(){ return !offen('hwOverlay'); }).then(function(){
      q('[data-mail="add"]', zeile('probe:orig', 4)).click();
      return warte(function(){ return offen('noteOverlay') && q('#ntFiles [data-wahl-n]'); });
    });
  }).then(function(){
    s.push({ notiz: { wahl: Array.from(document.querySelectorAll('#ntFiles [data-wahl-n]')).map(function(k){ return { n: k.dataset.wahlN, an: k.checked }; }),
             liste: !!q('#ntOriginal .original-anhaenge') } });
    schalteOriginal('ntOriginal');
    q('#btnNoteSave').click();
    return warte(function(){ return window.__probe.post.some(function(p){ return /\/notes$/.test(p.pfad); }); });
  }).then(function(){
    // 7. Ein gespeicherter Termin: das Auge im Dialog oeffnet das Blatt DARUEBER
    return warte(function(){ return !offen('noteOverlay'); }).then(function(){
      var tab = q('#tab-calendar'); if (tab) tab.click();
      // Ein Tipp auf die Zeile oeffnet den Termin — wie bei einer Aufgabe.
      return warte(function(){ return q('.cal-card[data-id="ev1"] .cal-event'); }, 10000);
    });
  }).then(function(k){
    k.click();
    return warte(function(){ return offen('calEditOverlay'); });
  }).then(function(){
    var auge = q('#ceOriginalAuge');
    s.push({ termin: { auge: getComputedStyle(auge).display !== 'none', wahl: getComputedStyle(q('#ceOriginal')).display } });
    auge.click();
    return warte(function(){ return offen('originalOverlay'); });
  }).then(function(){
    s.push({ stapel: [getComputedStyle(q('#originalOverlay')).zIndex, getComputedStyle(q('#calEditOverlay')).zIndex, offen('calEditOverlay')] });
    fertig();
  }).catch(function(e){ fertig(String(e && e.message || e) + ' nach ' + s.length + ' Schritten'); });
})();
</script>
JS;
$seite = substr_replace($seite, $treiber, (int)strrpos($seite, '</body>'), 0);
$datei = $arbeit . '/probe.html';
file_put_contents($datei, $seite);
$dom = (string)shell_exec(sprintf(
    'perl -e %s %s --headless=new --force-prefers-reduced-motion --disable-web-security --user-data-dir=%s '
    . '--window-size=500,1400 --virtual-time-budget=90000 --dump-dom %s 2>/dev/null',
    escapeshellarg('alarm 240; exec @ARGV'), escapeshellarg($chrome),
    escapeshellarg($arbeit . '/profil'), escapeshellarg('file://' . $datei)
));
@shell_exec('rm -rf ' . escapeshellarg($arbeit));
$erg = preg_match('~<title>FERTIG (.*?)</title>~s', $dom, $m) ? json_decode(html_entity_decode($m[1]), true) : null;
if (!is_array($erg)) {
    pruefe(false, 'Browser-Lauf lieferte kein Ergebnis');
    schluss();
}
pruefe(($erg['fehler'] ?? null) === null, 'der Lauf kam bis zum Ende: ' . json_encode($erg['fehler'] ?? null));
$s = [];
foreach ((array)($erg['schritte'] ?? []) as $x) {
    $s += (array)$x;
}
$probe = (array)($erg['probe'] ?? []);
$posts = (array)($probe['post'] ?? []);
$nach = static fn(string $muster, string $aktion) => array_values(array_filter($posts,
    static fn($p) => preg_match($muster, (string)$p['pfad']) === 1 && (string)($p['n']['action'] ?? '') === $aktion));

// 1. Zeile und Blatt
$z = (array)($s['zeile'] ?? []);
pruefe(($z[0] ?? '') === 'original:row-btn' && str_starts_with((string)($z[1] ?? ''), 'drop:'),
    'Auge an der Zeile: grau (row-btn ohne Farbe), direkt links neben ✕: ' . json_encode($z));
pruefe(!in_array('original', (array)($s['ohne'] ?? []), true), 'ohne Original kein Auge');
pruefe(($s['titel'] ?? '') === 'Lernzeitplan KW 40' && str_contains((string)($s['meta'] ?? ''), 'lehrerin@example.test'),
    'Blatt: Betreff als Titel, Absender in der Kopfzeile');
pruefe(($s['fett'] ?? true) === false && ($s['text'] ?? false) === true, 'Blatt: <b> bleibt Text, wird nicht zu HTML');
pruefe(($s['link'] ?? false) === true, 'Blatt: die Adresse wird ein Link');
pruefe(count((array)($s['dateien'] ?? [])) === 3 && str_contains((string)(($s['dateien'] ?? [])[2] ?? ''), 'is-skipped'),
    'Blatt: zwei Anhaenge und der nicht aufbewahrte: ' . json_encode($s['dateien'] ?? null, JSON_UNESCAPED_UNICODE));
$teile = array_map(static fn($t) => $t['teil'], (array)($probe['teile'] ?? []));
pruefe($teile === [0, 1, 2] && array_filter((array)($probe['teile'] ?? []), static fn($t) => $t['auth'] !== true) === [],
    'PDF in drei Stuecken, jedes mit Token im Kopf: ' . json_encode($probe['teile'] ?? null));
pruefe(in_array(['size' => 31, 'type' => 'application/pdf'], (array)($probe['blobs'] ?? []), true),
    '… zusammengesetzt zu EINER Datei der richtigen Groesse: ' . json_encode($probe['blobs'] ?? null));
pruefe(($s['pdf'] ?? '') === 'blob:' && (int)(($s['zIndex'] ?? [0, 0])[0]) > (int)(($s['zIndex'] ?? [0, 0])[1]),
    'der Betrachter zeigt das PDF und liegt ueber dem Blatt: ' . json_encode($s['zIndex'] ?? null));

// 2./3. Aufgabe
$vor = (array)($s['aufgabeVorher'] ?? []);
pruefe(($vor['sichtbar'] ?? false) === true && ($vor['schalter'] ?? true) === false, 'Aufgabe: „Original speichern" sichtbar und leer');
pruefe(($vor['wahl'] ?? null) === [['n' => '1', 'an' => true, 'gesperrt' => true], ['n' => '2', 'an' => false, 'gesperrt' => true]],
    'Aufgabe: Anhaenge erst mit Haken waehlbar, das Logo nicht vorgehakt: ' . json_encode($vor['wahl'] ?? null));
pruefe(($s['aufgabeNachher'] ?? null) === [['n' => '1', 'an' => true, 'gesperrt' => false], ['n' => '2', 'an' => false, 'gesperrt' => false]],
    'Aufgabe: mit Haken sind die Anhaenge waehlbar');
$behalten = $nach('~/mail/proposals$~', 'originalBehalten');
pruefe(($behalten[0]['n']['atts'] ?? null) === [1] && ($behalten[0]['n']['id'] ?? '') === str_repeat('a', 24),
    'Aufgabe: erst originalBehalten mit genau dem PDF: ' . json_encode($behalten[0]['n'] ?? null));
$aktionen = (array)($probe['aktion'] ?? []);
pruefe(($aktionen[0]['payload']['originalId'] ?? null) === str_repeat('b', 24), 'Aufgabe: AddItem traegt die neue Kennung');
pruefe(!array_key_exists('originalId', (array)($aktionen[1]['payload'] ?? ['originalId' => 1])),
    'Aufgabe ohne Haken: keine Kennung im AddItem');
pruefe(count($behalten) === 4, 'originalBehalten genau viermal (Aufgabe, Termin, Hausaufgabe, Notiz) — nicht fuer die Aufgabe ohne Haken: ' . count($behalten));

// 4. Termin, 5. Hausaufgabe, 6. Notiz
$termin = $nach('~/calendar$~', 'create');
pruefe(($termin[0]['n']['event']['originalId'] ?? null) === str_repeat('b', 24), 'Termin: die Kennung reist im Ereignis');
$hw = $nach('~/homework$~', 'create');
pruefe(($hw[0]['n']['originalId'] ?? null) === str_repeat('b', 24), 'Hausaufgabe: die Kennung reist mit');
$nz = (array)($s['notiz'] ?? []);
pruefe(($nz['wahl'] ?? null) === [['n' => '1', 'an' => true], ['n' => '2', 'an' => false]] && ($nz['liste'] ?? true) === false,
    'Notiz: Anhaenge aus dem Original (Logo nicht vorgehakt), „Original speichern" ohne eigene Liste: ' . json_encode($nz));
$adopt = $nach('~/notes$~', 'adopt');
pruefe(($adopt[0]['n']['keepOriginal'] ?? null) === [1] && ($adopt[0]['n']['originalId'] ?? null) === str_repeat('b', 24)
    && ($adopt[0]['n']['keep'] ?? null) === [], 'Notiz: adopt mit keepOriginal [1], eigener Kennung, leerem keep');
$notizBehalten = array_values(array_filter($behalten, static fn($b) => ($b['n']['atts'] ?? null) === []));
pruefe(count($notizBehalten) === 1, 'Notiz: das eigene Original nimmt nur den Text (keine Anhaenge)');

// 7. gespeicherter Termin
$t = (array)($s['termin'] ?? []);
pruefe(($t['auge'] ?? false) === true && ($t['wahl'] ?? '') === 'none', 'gespeicherter Termin: Auge da, keine Wahl „Original speichern"');
$st = (array)($s['stapel'] ?? []);
pruefe((int)($st[0] ?? 0) > (int)($st[1] ?? 99) && ($st[2] ?? false) === true, 'das Blatt oeffnet UEBER dem Termin-Dialog: ' . json_encode($st));
schluss();
