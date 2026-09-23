<?php

declare(strict_types=1);

/**
 * Prüfstand: einen KI-Vorschlag als Hausaufgabe übernehmen (23.09.2026).
 *
 * Drei gemeldete Fehler an derselben Stelle:
 *  1. Die Art umgestellt, dann „+" — es kam der Aufgaben-Dialog. Die Zeile war
 *     beim Umstellen in eine andere Gruppe GESPRUNGEN, unter dem Finger stand
 *     der nächste Fund.
 *  2. Das Hausaufgaben-Blatt zeigte eine „andere" Hausaufgabe: die Aufgabe
 *     selbst fehlte (die Notiz kam nur aus `info`), das Kind war das erste der
 *     Liste, das Fach das erste des Stundenplans.
 *  3. Der Scan öffnete dasselbe Blatt über eine eigene Abbildung und behielt
 *     den Fehler, als der KI-Eingang ihn schon los war.
 *
 * Die REGELN (Notiz, Kind) prüft tools/homework-parity.mjs mit denselben
 * Goldwerten wie das Gateway (tests/HomeworkTest.php). Hier steht, dass beide
 * Wege sie benutzen — und im Browser-Teil, dass das Blatt am Ende das Richtige
 * zeigt.
 *
 * Der Browser-Teil fährt die echte Web-App des Docker-Gateways, aber mit
 * FESTEN Vorschlägen: `window.__symdoApiPost` wird umhüllt, `/mail/proposals`
 * beantwortet die Hülle selbst und schickt nichts davon weiter. Deine echten
 * Vorschläge bleiben unberührt. Gelesen wird nur die Mitgliederliste.
 *
 *   php SymDoWebApp/tests/VorschlagArtWechselTest.php
 *   MIT_CHROME=1 SYMDO_TOKEN_FILE=… php SymDoWebApp/tests/VorschlagArtWechselTest.php
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
pruefe(substr_count($html, 'hwEntwurfAusFund(') === 3, 'KI-Eingang und Scan öffnen das Blatt über dieselbe Abbildung (1 Definition, 2 Aufrufe)');
pruefe(str_contains($html, 'function hwNotizAusFund(titel, text)') && str_contains($html, 'function hwKindFuerFund(besitzer, erkannt, zugewiesen, kinder)'),
    'die Regeln stehen DOM-frei (von homework-parity.mjs geprüft)');
pruefe(!str_contains($html, "note: String(e.t.note || e.t.info || '')"), 'die alte Abbildung des Scans ist weg');
pruefe(str_contains($html, 'function mailSortSorte(id, it)'), 'die Sortierung hält umgestellte Zeilen an ihrem Platz');
$locale = json_decode((string)file_get_contents(__DIR__ . '/../locale.json'), true)['translations']['de'] ?? [];
pruefe(($locale['Pick a subject'] ?? '') === 'Fach wählen', 'locale de: Pick a subject');
foreach (['ToDoList', 'ShoppingList', 'SymDoEdumaps', 'SymDoNotes', 'SymDoHomework'] as $kopie) {
    $k = (string)@file_get_contents(__DIR__ . '/../../' . $kopie . '/module.html');
    pruefe(str_contains($k, 'function hwEntwurfAusFund(it, besitzer)') && str_contains($k, 'function mailSortSorte(id, it)'),
        "Kopie $kopie ist nachgezogen");
}

// ── Teil 2: die echte Oberfläche mit festen Vorschlägen ─────────────────────
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

// Nur LESEN: die Mitglieder, damit die festen Vorschläge echte Kennungen tragen.
$ctx = stream_context_create(['http' => ['header' => 'Authorization: Bearer ' . $token, 'timeout' => 10]]);
$nutzer = json_decode((string)@file_get_contents($basis . '/hook/lists/app/v1/users', false, $ctx), true);
$nutzer = is_array($nutzer['users'] ?? null) ? $nutzer['users'] : (is_array($nutzer) ? $nutzer : []);
$kinder = array_values(array_filter($nutzer, static fn($u) => is_array($u) && ($u['persona'] ?? '') === 'child'));
$grosse = array_values(array_filter($nutzer, static fn($u) => is_array($u) && ($u['persona'] ?? '') !== 'child'));
if (count($kinder) < 2 || $grosse === []) {
    pruefe(false, 'das Prüfsystem braucht zwei Kinder und einen Erwachsenen (gefunden: ' . count($kinder) . ' / ' . count($grosse) . ')');
    schluss();
}
/* Das ZWEITE Kind trägt die Quelle: fiele das Blatt auf das erste der Liste
   zurück — der alte Fehler —, sähe man es sofort. */
$kind2 = (string)$kinder[1]['id'];
$gross = (string)$grosse[0]['id'];
$jetzt = time();
$vorschlaege = [
    ['id' => 'probe:lzp', 'source' => 'LOGINEO', 'userId' => $kind2, 'at' => $jetzt, 'created' => $jetzt,
     'from' => 'Prüfstand', 'subject' => 'Lernzeitplan (Prüfstand)', 'items' => [
        ['i' => 0, 'kind' => 'task', 'title' => 'Arbeitsheft S. 4 und 5', 'info' => 'Lernzeitplan der GGS Knittkuhl, Klasse 1.',
         'due' => '2026-09-21', 'allDay' => true, 'assignedTo' => [], 'priority' => 'normal', 'taken' => false],
        ['i' => 1, 'kind' => 'task', 'title' => 'Leseteppich üben', 'info' => 'Täglich den Leseteppich üben.',
         'due' => null, 'allDay' => true, 'assignedTo' => [], 'priority' => 'normal', 'taken' => false],
        ['i' => 2, 'kind' => 'homework', 'title' => 'Mathetrainer für jeden Tag bearbeiten', 'info' => 'Siehe Rückseite des Lernzeitplans.',
         'note' => 'Siehe Rückseite des Lernzeitplans.', 'subject' => 'Mathematik', 'childId' => '',
         'due' => '2026-09-24', 'allDay' => false, 'assignedTo' => [], 'priority' => 'normal', 'taken' => false],
    ]],
    ['id' => 'probe:mail', 'source' => 'IMAP', 'userId' => $gross, 'at' => $jetzt - 60, 'created' => $jetzt - 60,
     'from' => 'Prüfstand', 'subject' => 'Mail an die Eltern (Prüfstand)', 'items' => [
        ['i' => 0, 'kind' => 'task', 'title' => 'Buchstabenheft S. 16', 'info' => null,
         'due' => '2026-09-25', 'allDay' => true, 'assignedTo' => [$kind2], 'priority' => 'normal', 'taken' => false],
    ]],
];
/* Was das Blatt je Fall zeigen muss. `umstellen`: vorher im Auswahlfeld auf
   Hausaufgabe stellen — so, wie es der Mensch tut. */
$faelle = [
    ['id' => 'probe:lzp', 'i' => 0, 'umstellen' => true, 'kind' => $kind2, 'fach' => '', 'due' => '2026-09-21',
     'note' => 'Arbeitsheft S. 4 und 5 — Lernzeitplan der GGS Knittkuhl, Klasse 1.'],
    ['id' => 'probe:lzp', 'i' => 1, 'umstellen' => true, 'kind' => $kind2, 'fach' => '', 'due' => '',
     'note' => 'Täglich den Leseteppich üben.'],
    ['id' => 'probe:lzp', 'i' => 2, 'umstellen' => false, 'kind' => $kind2, 'fach' => 'Math', 'due' => '2026-09-24',
     'note' => 'Mathetrainer für jeden Tag bearbeiten — Siehe Rückseite des Lernzeitplans.'],
    ['id' => 'probe:mail', 'i' => 0, 'umstellen' => true, 'kind' => $kind2, 'fach' => '', 'due' => '2026-09-25',
     'note' => 'Buchstabenheft S. 16'],
];

$arbeit = sys_get_temp_dir() . '/symdo-artwechsel-' . getmypid();
@mkdir($arbeit, 0o777, true);
$seite = (string)file_get_contents($basis . '/hook/lists/webapp');
$seite = str_replace(["'/hook/lists/app/v1", '"/hook/lists/app/v1'],
                     ["'" . $basis . '/hook/lists/app/v1', '"' . $basis . '/hook/lists/app/v1'], $seite);

/* Die Hülle MUSS vor dem Adapter stehen: er setzt window.__symdoApiPost, und
   der Setter hier nimmt ihn entgegen, statt ihn herzugeben. Die Hülle führt
   die Art-Wechsel an ihren eigenen Vorschlägen nach (wie das Gateway), damit
   ein Neuladen zwischendurch nichts zurückdreht. */
$huelle = '<script>try{localStorage.setItem("symdo.token",' . json_encode($token) . ');localStorage.setItem("symdo.tab","ki");}catch(e){}'
    . '(function(){var FIX=' . json_encode($vorschlaege, JSON_UNESCAPED_UNICODE) . ';var echt=null;window.__probeAktionen=[];'
    . 'function h(path,payload){if(/\/mail\/proposals$/.test(String(path))){var a=String((payload&&payload.action)||"list");'
    . 'window.__probeAktionen.push(a);if(a==="kind"){FIX.forEach(function(p){if(p.id===payload.id){p.items.forEach(function(it){if(it.i===payload.i){it.kind=payload.kind;}});}});}'
    . 'return Promise.resolve({status:200,json:a==="list"?{ok:true,proposals:JSON.parse(JSON.stringify(FIX))}:{ok:true}});}'
    . 'return echt?echt(path,payload):Promise.resolve({status:0,json:null});}'
    . 'Object.defineProperty(window,"__symdoApiPost",{configurable:true,get:function(){return h;},set:function(v){echt=v;}});})();</script>';
$seite = substr_replace($seite, $huelle, (int)strpos($seite, '<script'), 0);

$treiber = '<script>(function(){var FAELLE=' . json_encode($faelle, JSON_UNESCAPED_UNICODE) . ';' . <<<'JS'
  var raus = { faelle: [], sprung: null };
  function fertig(){ raus.aktionen = window.__probeAktionen || []; document.title = 'FERTIG ' + JSON.stringify(raus); }
  function zeile(f){ return document.querySelector('.mail-row[data-id="' + f.id + '"][data-i="' + f.i + '"]'); }
  function stelle(z){ return Array.from(document.querySelectorAll('.mail-row')).indexOf(z); }
  function wert(id){ var e = document.getElementById(id); return e ? String(e.value || '') : null; }
  function offen(id){ var o = document.getElementById(id); if (!o) return false; var s = getComputedStyle(o); return s.display !== 'none' && s.visibility !== 'hidden' && o.offsetHeight > 0; }
  function schritt(n){
    if (n >= FAELLE.length) { fertig(); return; }
    var f = FAELLE[n], z = zeile(f);
    if (!z) { raus.faelle.push({ n: n, fehler: 'Zeile fehlt' }); schritt(n + 1); return; }
    var vorher = stelle(z);
    function drücken(){
      var z2 = zeile(f);
      if (f.umstellen && n === 0) { raus.sprung = { vorher: vorher, nachher: stelle(z2) }; }
      var knopf = z2 && z2.querySelector('[data-mail="add"]');
      if (!knopf) { raus.faelle.push({ n: n, fehler: 'kein +' }); schritt(n + 1); return; }
      knopf.click();
      setTimeout(function(){
        raus.faelle.push({ n: n, hw: offen('hwOverlay'), todo: offen('todoOverlay'),
          kind: wert('hwChild'), fach: wert('hwSubject'), due: wert('hwDue'), note: wert('hwNote') });
        var zu = document.querySelector('#hwOverlay [data-close="hwOverlay"]');
        if (zu) zu.click();
        setTimeout(function(){ schritt(n + 1); }, 700);
      }, 1000);
    }
    if (f.umstellen) {
      var feld = z.querySelector('select.mail-kind-select');
      if (!feld) { raus.faelle.push({ n: n, fehler: 'kein Auswahlfeld' }); schritt(n + 1); return; }
      feld.value = 'homework';
      feld.dispatchEvent(new Event('change', { bubbles: true }));
      setTimeout(drücken, 1200);
    } else { drücken(); }
  }
  setTimeout(function(){ schritt(0); }, 6000);
})();</script>
JS;
$seite = substr_replace($seite, $treiber, (int)strrpos($seite, '</body>'), 0);
$datei = $arbeit . '/probe.html';
file_put_contents($datei, $seite);

$dom = (string)shell_exec(sprintf(
    'perl -e %s %s --headless=new --disable-web-security --user-data-dir=%s --window-size=500,1400 '
    . '--virtual-time-budget=45000 --dump-dom %s 2>/dev/null',
    escapeshellarg('alarm 150; exec @ARGV'), escapeshellarg($chrome),
    escapeshellarg($arbeit . '/profil'), escapeshellarg('file://' . $datei)
));
@shell_exec('rm -rf ' . escapeshellarg($arbeit));
$erg = preg_match('~<title>FERTIG (.*?)</title>~s', $dom, $m) ? json_decode(html_entity_decode($m[1]), true) : null;
if (!is_array($erg)) {
    pruefe(false, 'Browser-Lauf lieferte kein Ergebnis');
    schluss();
}

$sprung = (array)($erg['sprung'] ?? []);
pruefe(isset($sprung['vorher']) && $sprung['vorher'] >= 0 && $sprung['vorher'] === ($sprung['nachher'] ?? -2),
    'die umgestellte Zeile bleibt an ihrer Stelle: ' . json_encode($sprung));
foreach ($faelle as $n => $f) {
    $ist = (array)($erg['faelle'][$n] ?? []);
    $was = "Fall $n ({$f['id']}#{$f['i']})";
    if (isset($ist['fehler'])) {
        pruefe(false, "$was: {$ist['fehler']}");
        continue;
    }
    pruefe(($ist['hw'] ?? false) === true && ($ist['todo'] ?? true) === false, "$was: das Hausaufgaben-Blatt öffnet, nicht der Aufgaben-Dialog");
    pruefe(($ist['kind'] ?? null) === $f['kind'], "$was: das Kind der Quelle ist gewählt (ist " . json_encode($ist['kind'] ?? null) . ')');
    pruefe($f['fach'] === '' ? ($ist['fach'] ?? null) === '' : str_starts_with((string)($ist['fach'] ?? ''), $f['fach']),
        "$was: Fach " . ($f['fach'] === '' ? 'bleibt offen' : 'aus dem Fund') . ' (ist ' . json_encode($ist['fach'] ?? null, JSON_UNESCAPED_UNICODE) . ')');
    pruefe(($ist['due'] ?? null) === $f['due'], "$was: Frist (ist " . json_encode($ist['due'] ?? null) . ')');
    pruefe(($ist['note'] ?? null) === $f['note'], "$was: Notiz (ist " . json_encode($ist['note'] ?? null, JSON_UNESCAPED_UNICODE) . ')');
}
$aktionen = (array)($erg['aktionen'] ?? []);
pruefe(in_array('list', $aktionen, true) && count(array_filter($aktionen, static fn($a) => $a === 'kind')) === 3,
    'die Hülle hat Laden und die drei Umstellungen abgefangen — nichts ging ans Gateway: ' . json_encode($aktionen));
schluss();
