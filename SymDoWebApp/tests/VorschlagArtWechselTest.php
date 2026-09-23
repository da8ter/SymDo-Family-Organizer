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
 *  4. Eine späte Antwort beim Speichern galt dem Blatt, das GERADE offen war:
 *     Speichern bei A, Abbrechen, „+" bei B — die Antwort für A schloss B und
 *     hakte B ab. Ein Doppeltipp legte zwei Hausaufgaben an.
 *
 * Die REGELN (Notiz, Kind) prüft tools/homework-parity.mjs mit denselben
 * Goldwerten wie das Gateway (tests/HomeworkTest.php). Hier steht, dass beide
 * Wege sie benutzen — und im Browser-Teil, dass das Blatt am Ende das Richtige
 * zeigt.
 *
 * Der Browser-Teil fährt die echte Web-App des Docker-Gateways, aber mit
 * FESTEN Vorschlägen: `window.__symdoApiPost` wird umhüllt, `/mail/proposals`
 * beantwortet die Hülle selbst und schickt nichts davon weiter, ebenso jede
 * schreibende Aktion auf `/homework` (mit 1,5 s Verzug, wie über das
 * Kachel-Relay). Deine echten Vorschläge und Hausaufgaben bleiben unberührt;
 * gelesen werden nur die Mitgliederliste und der Hausaufgaben-Bestand.
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
pruefe(str_contains($html, 'function hwBlattSchliessen(ctx)') && substr_count($html, 'hwBlattSchliessen(') === 4,
    'Speichern und Löschen schließen nur das Blatt, dem die Antwort gilt');
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
        ['i' => 3, 'kind' => 'homework', 'title' => 'Rechenheft S. 5', 'info' => null, 'note' => 'Rechenheft S. 5',
         'subject' => 'Mathematik', 'childId' => '', 'due' => '2026-09-26', 'allDay' => false, 'assignedTo' => [], 'priority' => 'normal', 'taken' => false],
        ['i' => 4, 'kind' => 'homework', 'title' => 'Zahlenheft S. 9', 'info' => null, 'note' => 'Zahlenheft S. 9',
         'subject' => 'Mathematik', 'childId' => '', 'due' => '2026-09-27', 'allDay' => false, 'assignedTo' => [], 'priority' => 'normal', 'taken' => false],
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
    . 'function merk(w,a,pl){window.__probeAktionen.push({w:w,a:a,id:pl&&pl.id!=null?String(pl.id):"",i:pl&&pl.i!=null?Number(pl.i):null});}'
    . 'function h(path,payload){var a=String((payload&&payload.action)||"list");'
    . 'if(/\/mail\/proposals$/.test(String(path))){merk("mail",a,payload);'
    . 'if(a==="kind"||a==="taken"){FIX.forEach(function(p){if(p.id===payload.id){p.items.forEach(function(it){if(it.i===payload.i){if(a==="kind"){it.kind=payload.kind;}else{it.taken=true;}}});}});}'
    . 'return Promise.resolve({status:200,json:a==="list"?{ok:true,proposals:JSON.parse(JSON.stringify(FIX))}:{ok:true}});}'
    . 'if(/\/homework$/.test(String(path))&&a!=="list"){merk("hw",a,payload);'
    . 'return new Promise(function(r){setTimeout(function(){r({status:200,json:{ok:true}});},1500);});}'
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
  function klick(sel, wurzel){ var e = (wurzel || document).querySelector(sel); if (e) e.click(); return !!e; }
  function plus(id, i){ var z = zeile({ id: id, i: i }); return !!(z && klick('[data-mail="add"]', z)); }
  function zu(){ klick('#hwOverlay [data-close="hwOverlay"]'); }
  function speichern(){ klick('#btnHwSave'); }
  /* Die Wettlaeufe (Befund 4). Jede Antwort auf /homework kommt 1,5 s spaet. */
  function wettlauf(){
    raus.wett = {};
    // R1: speichern bei A (lzp#2), abbrechen, „+" bei B (lzp#0) — die spaete Antwort fuer A.
    plus('probe:lzp', 2);
    setTimeout(function(){
      speichern(); zu();
      setTimeout(function(){
        plus('probe:lzp', 0);
        setTimeout(function(){
          raus.wett.r1 = { hw: offen('hwOverlay'), note: wert('hwNote') };
          zu();
          // R2: Doppeltipp auf Speichern bei lzp#3.
          setTimeout(function(){
            plus('probe:lzp', 3);
            setTimeout(function(){
              speichern(); speichern();
              setTimeout(function(){
                raus.wett.r2 = { hw: offen('hwOverlay') };
                // R3: speichern bei lzp#4, abbrechen, denselben Vorschlag gleich wieder oeffnen.
                plus('probe:lzp', 4);
                setTimeout(function(){
                  speichern(); zu();
                  setTimeout(function(){
                    var wieder = plus('probe:lzp', 4);
                    setTimeout(function(){
                      var k = document.getElementById('btnHwSave');
                      raus.wett.r3 = { wieder: wieder, gesperrt: !!(k && k.disabled) };
                      speichern();
                      setTimeout(function(){
                        raus.wett.r3.danachOffen = offen('hwOverlay');
                        fertig();
                      }, 2500);
                    }, 600);
                  }, 700);
                }, 900);
              }, 2500);
            }, 900);
          }, 700);
        }, 2500);
      }, 700);
    }, 900);
  }
  function schritt(n){
    if (n >= FAELLE.length) { wettlauf(); return; }
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
    /* Ohne Bewegung: die Schliess-Animation der Blaetter laeuft in echter Zeit,
       die Uhren der Seite in virtueller — ein geschlossenes Blatt galt sonst
       noch als offen. Mit reduzierter Bewegung schliesst closeOverlay sofort. */
    'perl -e %s %s --headless=new --force-prefers-reduced-motion --disable-web-security --user-data-dir=%s --window-size=500,1400 '
    . '--virtual-time-budget=70000 --dump-dom %s 2>/dev/null',
    escapeshellarg('alarm 200; exec @ARGV'), escapeshellarg($chrome),
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
$zaehle = static fn(string $w, string $a, ?string $id = null, ?int $i = null): int => count(array_filter($aktionen,
    static fn($x) => is_array($x) && $x['w'] === $w && $x['a'] === $a && ($id === null || $x['id'] === $id) && ($i === null || $x['i'] === $i)));
pruefe($zaehle('mail', 'list') > 0 && $zaehle('mail', 'kind') === 3,
    'die Hülle hat Laden und die drei Umstellungen abgefangen — nichts davon ging ans Gateway');

// Befund 4: die Wettläufe
$w = (array)($erg['wett'] ?? []);
pruefe(($w['r1']['hw'] ?? false) === true && ($w['r1']['note'] ?? '') === $faelle[0]['note'],
    'R1: die späte Antwort für A lässt das Blatt von B offen: ' . json_encode($w['r1'] ?? null, JSON_UNESCAPED_UNICODE));
pruefe($zaehle('mail', 'taken', 'probe:lzp', 2) === 1, 'R1: abgehakt wird A — der Vorschlag, der gespeichert wurde');
pruefe($zaehle('mail', 'taken', 'probe:lzp', 0) === 0, 'R1: B bleibt offen (nicht als übernommen gemeldet)');
pruefe($zaehle('hw', 'create') === 3, 'R2/R3: je Vorschlag genau EINE Hausaufgabe — Doppeltipp und Wiederöffnen legen nichts doppelt an (ist ' . $zaehle('hw', 'create') . ')');
pruefe(($w['r2']['hw'] ?? true) === false, 'R2: nach dem Speichern ist das Blatt zu');
pruefe(($w['r3']['wieder'] ?? false) === true && ($w['r3']['gesperrt'] ?? false) === true,
    'R3: der sofort wieder geöffnete Vorschlag hat einen gesperrten Speichern-Knopf: ' . json_encode($w['r3'] ?? null));
pruefe(($w['r3']['danachOffen'] ?? true) === false, 'R3: ist der Vorschlag übernommen, schließt sich auch das zweite Blatt');
pruefe($zaehle('mail', 'taken', 'probe:lzp', 3) === 1 && $zaehle('mail', 'taken', 'probe:lzp', 4) === 1,
    'R2/R3: beide Vorschläge genau einmal als übernommen gemeldet');
schluss();
