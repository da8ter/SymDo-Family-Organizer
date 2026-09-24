<?php

declare(strict_types=1);

/**
 * Pruefstand: Pruefungen aus WebUntis in der Web-App (24.09.2026).
 *
 * Teil 1 (immer): Verdrahtung — Block ueber den Hausaufgaben, beide Revisionen,
 * Markierung in Balken und Raster, Stundenplan-Kachel, Kopien, Uebersetzungen.
 * Teil 2 (MIT_CHROME=1): die echte Web-App des Docker-Gateways mit FESTEN
 * Daten. Die Huelle um window.__symdoApiPost liefert Stundenplan und
 * Hausaufgaben (samt Pruefungen) selbst — nichts davon erreicht das Gateway.
 *
 * Gefahren wird, was der Mensch tut: Kind waehlen, auf der Uebersicht den
 * Tagesbalken lesen, in die Schule wechseln (Hausaufgaben mit dem Block oben,
 * Stundenplan mit dem Wochenraster), und eine neue Pruefung kommt NUR mit
 * geaenderter examRev an.
 *
 *   php SymDoWebApp/tests/PruefungenTest.php
 *   MIT_CHROME=1 SYMDO_TOKEN_FILE=… php SymDoWebApp/tests/PruefungenTest.php
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
pruefe(str_contains($html, "&& (j.examRev || 0) === (hausaufgaben.examRev || 0)) { fertig(); return; }"),
    'ladeHausaufgaben vergleicht BEIDE Revisionen');
pruefe(str_contains($html, "exams: Array.isArray(j.exams) ? j.exams : []"), 'die Pruefungen kommen an den Bestand');
pruefe(str_contains($html, 'const pruefungen = hwPruefungenHtml(ids, mitAvatar, subjects);')
    && str_contains($html, '  let html = pruefungen;'), 'der Block steht OBEN in hwListeHtml (beide Orte)');
$start = strpos($html, 'function hwPruefungenHtml(');
$koerper = $start === false ? '' : substr($html, $start, (int)strpos($html, "\n}\n", $start) - $start);
pruefe($koerper !== '' && !str_contains($koerper, 'hw-zeile') && !str_contains($koerper, 'data-hw'),
    'der Block traegt weder .hw-zeile noch data-hw (sonst binde hwBinden Abhaken und Wischen)');
pruefe(str_contains($koerper, ">= heute"), 'gelesen wird nur ab heute');
/* Build 209 liess im Hausaufgaben-Kasten der Uebersicht einen Verweis auf die
   entfernte Variable `sprung` stehen — er warf erst ab der Zeile „n weitere"
   und brach damit das ganze Zeichnen der Uebersicht ab (Stundenplan, Termine,
   Briefing fehlten, die Mitgliederwahl schien zu haengen). */
$karte = substr($html, (int)strpos($html, 'function hwKarteHtml('), 9000);
$karte = substr($karte, 0, (int)strpos($karte, "\n}\n"));
pruefe(!preg_match('/\bsprung\b/', $karte) && str_contains($karte, '<div class="more-row"><span>${escapeHtml(label)}</span></div>')
    && !str_contains($karte, '<button class="more-row"'),
    'Hausaufgaben-Kasten: „n weitere" ist nur Text, kein Knopf (kein Rest von sprung)');
foreach (['.plan-stueck.pruefung {', '.wp-stunde.pruefung {', '.hw-pruefung {', '.hw-pruef-balken > span {',
          '.hw-pruef-rund i, .hw-pruef-rund svg'] as $css) {
    pruefe(str_contains($html, $css), "Stil: $css");
}
pruefe(str_contains($html, "const zustand = pruefung ? ' pruefung'") && str_contains($html, "const lage = pruefung ? ' pruefung'"),
    'Balken und Raster: die Pruefung schlaegt die Vertretung');
pruefe(str_contains($html, "if (q === 'exam') return translate('Source: exam (UNTIS)');"), 'Quellenwort der Lern-Erinnerung');
$kachel = (string)file_get_contents(__DIR__ . '/../../Stundenplan/module.html');
pruefe(str_contains($kachel, ".stunde.lage-pruefung {") && str_contains($kachel, "symbol(pruefung ? 'fa-file-pen'"),
    'Stundenplan-Kachel: Markierung wie in der Web-App');
foreach (['ToDoList', 'ShoppingList', 'SymDoEdumaps', 'SymDoNotes', 'SymDoHomework'] as $kopie) {
    $k = (string)@file_get_contents(__DIR__ . '/../../' . $kopie . '/module.html');
    pruefe(str_contains($k, 'function hwPruefungenHtml(') && str_contains($k, ".wp-stunde.pruefung {"), "Kopie $kopie ist nachgezogen");
}
$schluessel = ['Exams', 'Exam', 'exam cancelled', 'Source: exam (UNTIS)', 'Next exam: %1', '%1 day(s) until the exam',
               'Exam today', 'Study start'];
foreach (['SymDoWebApp', 'ToDoList', 'ShoppingList', 'SymDoHomework', 'SymDoNotes', 'SymDoEdumaps'] as $m) {
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
$heute = date('Y-m-d');
$tag = static fn(int $n): string => date('Y-m-d', (int)strtotime($heute . ' 12:00:00') + $n * 86400);

/* Die Woche: Mo–Fr dieser Woche. Der Balken der Uebersicht zeigt „heute", am
   Wochenende den ersten Tag — dorthin kommt die Klassenarbeit. */
$montag = date('Y-m-d', (int)strtotime('monday this week', (int)strtotime($heute . ' 12:00:00')));
$wtHeute = (int)date('N');
$gezeigt = $wtHeute <= 5 ? $wtHeute - 1 : 0;
$slot = static fn(string $name, string $icon, string $farbe, int $von, int $bis, array $mehr = []): array => $mehr + [
    'name' => $name, 'icon' => $icon, 'color' => $farbe, 'start' => sprintf('%02d:%02d', intdiv($von, 60), $von % 60),
    'end' => sprintf('%02d:%02d', intdiv($bis, 60), $bis % 60), 'from' => $von, 'to' => $bis, 'care' => false,
    'room' => '121', 'teacher' => 'Fa', 'status' => '', 'insteadOf' => '', 'exam' => false, 'examTitle' => ''];
$tage = [];
for ($i = 0; $i < 5; $i++) {
    $datum = date('Y-m-d', (int)strtotime($montag . ' 12:00:00') + $i * 86400);
    $slots = [$slot('Mathematik', 'fa-calculator', '#ff5757', 480, 540)];
    if ($i === $gezeigt) {
        $slots[] = $slot('Deutsch', 'fa-book', '#4da9ff', 570, 630, ['exam' => true, 'examTitle' => 'KA Briefe schreiben']);
    } elseif ($i === ($gezeigt + 1) % 5) {
        // Eine GEAENDERTE Pruefung: sie bleibt Pruefung, nicht Vertretung.
        $slots[] = $slot('Englisch', 'fa-language', '#f09fe0', 570, 630,
            ['exam' => true, 'examTitle' => 'Vokabeltest', 'status' => 'vertretung', 'insteadOf' => 'Richters']);
    } elseif ($i === ($gezeigt + 2) % 5) {
        $slots[] = $slot('Deutsch', 'fa-book', '#4da9ff', 570, 630, ['exam' => true, 'examTitle' => 'Diktat', 'status' => 'entfall']);
    } else {
        $slots[] = $slot('Deutsch', 'fa-book', '#4da9ff', 570, 630);
    }
    $tage[] = ['weekday' => $i + 1, 'label' => ['Mo', 'Di', 'Mi', 'Do', 'Fr'][$i], 'date' => $datum,
               'today' => $datum === $heute, 'minutes' => 120, 'holiday' => null, 'slots' => $slots, 'events' => []];
}
$plan = ['span' => [480, 900], 'now' => (int)date('G') * 60 + (int)date('i'), 'holiday' => null, 'dated' => true,
    'subjects' => [['name' => 'Deutsch', 'icon' => 'fa-book', 'color' => '#4da9ff'],
                   ['name' => 'Englisch', 'icon' => 'fa-language', 'color' => '#f09fe0'],
                   ['name' => 'Mathematik', 'icon' => 'fa-calculator', 'color' => '#ff5757']],
    'children' => [['name' => 'Probekind', 'color' => '#1E88E5', 'userId' => $kind, 'next' => '', 'weekOver' => false, 'days' => $tage]]];
$pr = static fn(string $id, string $datum, string $fach, string $titel, string $status = 'normal', ?string $wer = null,
                ?string $lern = null): array => [
    'id' => $id, 'childId' => $wer ?? $kind, 'date' => $datum, 'start' => '09:30', 'end' => '10:30', 'subject' => $fach,
    'title' => $titel, 'room' => '121', 'teacher' => 'Fa', 'status' => $status,
    'learnFrom' => $lern ?? date('Y-m-d', (int)strtotime($datum . ' 12:00:00') - 7 * 86400)];
$hw = ['ok' => true, 'rev' => 7, 'limits' => ['items' => 300, 'note' => 500], 'examRev' => 1,
    'items' => [['id' => 'h1', 'srcId' => 0, 'childId' => $kind, 'subject' => 'Mathematik', 'due' => $tag(1), 'done' => false,
                 'doneAt' => 0, 'doneBy' => '', 'note' => 'S. 12', 'source' => 'app', 'createdAt' => 1, 'updatedAt' => 1]],
    'exams' => [
        $pr('2', $tag(1), 'Englisch', 'Englisch'),                  // Titel = Fach: nur das Fach
        array_merge($pr('1', $tag(3), 'Deutsch', 'KA Briefe schreiben'), ['topic' => 'Mich vorstellen, meine Familie']),  // Lernstart vor 4 Tagen: 4/7
        $pr('3', $tag(6), 'Mathematik', 'Test', 'entfall'),
        $pr('7', $tag(20), 'Englisch', 'KA 1. KA Englisch'),        // Lernstart erst in 13 Tagen: 0 %
        $pr('4', $tag(-1), 'Deutsch', 'Gestern'),                   // vorbei: nicht zeigen
        $pr('5', $tag(2), 'Deutsch', 'Fremd', 'normal', 'anderes-kind'),
    ]];
$neu = $pr('6', $tag(9), 'Englisch', 'KA 2', 'normal', null, '');   // ohne Lernstart: eine Woche
$daten = ['plan' => $plan, 'hw' => $hw, 'neu' => $neu, 'kind' => $kind];

$arbeit = sys_get_temp_dir() . '/symdo-pruefung-' . getmypid();
@mkdir($arbeit, 0o777, true);
$seite = (string)file_get_contents($basis . '/hook/lists/webapp');
$seite = str_replace(["'/hook/lists/app/v1", '"/hook/lists/app/v1'],
                     ["'" . $basis . '/hook/lists/app/v1', '"' . $basis . '/hook/lists/app/v1'], $seite);
$huelle = '<script>try{localStorage.setItem("symdo.token",' . json_encode($token) . ');localStorage.setItem("symdo.tab","dashboard");'
    . 'localStorage.removeItem("symdo.schuleTab");}catch(e){}'
    . 'window.__PROBE=' . json_encode($daten, JSON_UNESCAPED_UNICODE) . ';</script><script>' . <<<'JS'
(function(){
  var P = window.__PROBE, echtPost = null;
  window.__probe = { hwListen: 0, schreib: [] };
  function antwort(j) { return Promise.resolve({ status: 200, json: JSON.parse(JSON.stringify(j)) }); }
  function huelle(pfad, nutzlast) {
    var a = String((nutzlast && nutzlast.action) || 'list');
    if (/\/timetable$/.test(pfad)) return antwort({ ok: true, timetable: P.plan });
    if (/\/homework$/.test(pfad)) {
      if (a === 'list') { window.__probe.hwListen++; return antwort(P.hw); }
      window.__probe.schreib.push(nutzlast); return antwort({ ok: true });
    }
    return echtPost ? echtPost(pfad, nutzlast) : Promise.resolve({ status: 0, json: null });
  }
  Object.defineProperty(window, '__symdoApiPost', { configurable: true, get: function(){ return huelle; }, set: function(v){ echtPost = v; } });
})();
</script>
JS;
$seite = substr_replace($seite, $huelle, (int)strpos($seite, '<script'), 0);

$treiber = '<script>' . <<<'JS'
(function(){
  var raus = { schritte: [] }, s = raus.schritte, P = window.__PROBE;
  function fertig(fehler){ raus.fehler = fehler || null; raus.probe = window.__probe; document.title = 'FERTIG ' + JSON.stringify(raus); }
  function q(x, w){ return (w || document).querySelector(x); }
  function qa(x, w){ return Array.from((w || document).querySelectorAll(x)); }
  function sichtbar(el){ return !!el && el.offsetParent !== null; }
  function warte(bed, ms){ return new Promise(function(ok, nein){ var t0 = Date.now(); (function lauf(){ var r; try { r = bed(); } catch (e) {} if (r) return ok(r); if (Date.now() - t0 > (ms || 8000)) return nein(new Error('Zeit')); setTimeout(lauf, 100); })(); }); }
  function block(){ return qa('.hw-pruefung').filter(sichtbar); }
  function blockStand(){
    return block().map(function(z){ var b = q('.hw-pruef-balken > span', z);
      return { name: q('.hw-name', z).textContent.trim(), stand: q('.hw-pruef-stand', z).textContent,
      balken: b ? b.style.width : null, balkenBreite: b ? Math.round(b.getBoundingClientRect().width) : null,
      spur: b ? Math.round(b.parentElement.getBoundingClientRect().width) : null,
      thema: (q('.hw-pruef-thema', z) || {}).textContent || '',
      enden: qa('.hw-pruef-enden > span', z).map(function(x){ return x.textContent; }),
      weg: z.classList.contains('entfallen'), farbe: getComputedStyle(z).getPropertyValue('--pf').trim(),
      zeile: !!z.querySelector('.hw-zeile, [data-hw], [data-hw-done]') || z.classList.contains('hw-zeile'),
      rund: !!z.querySelector('.hw-pruef-rund i, .hw-pruef-rund svg') }; });
  }
  warte(function(){ return q('.member-bar [data-member="' + P.kind + '"]'); }, 15000).then(function(b){
    b.click();
    return warte(function(){ return qa('.plan-stueck.pruefung').filter(sichtbar).length > 0; }, 10000);
  }).then(function(){
    // 1. Tagesbalken der Uebersicht
    var st = qa('.plan-stueck.pruefung').filter(sichtbar)[0];
    s.push({ balken: { titel: (q('.plan-titel', st) || {}).textContent || '', tipp: st.getAttribute('title'),
                       sym: !!st.querySelector('.fa-file-pen, [data-icon="file-pen"]'), klasse: st.className } });
    var schule = q('#tab-school');
    if (!sichtbar(schule)) throw new Error('kein Knopf Schule');
    schule.click();
    return warte(function(){
      var hw = q('[data-schule="homework"]'); if (hw && !hw.classList.contains('active')) hw.click();
      return block().length > 0;
    }, 10000);
  }).then(function(){
    // 2. Der Block oben in den Hausaufgaben
    var liste = block()[0].parentElement;
    var kinderFolge = Array.from(liste.children).map(function(k){ return k.className; });
    s.push({ block: blockStand(), kopf: kinderFolge.indexOf('hw-gruppe hw-pruef-kopf'),
             naechste: (q('.hw-pruef-naechste') || {}).textContent || '',
             ersteHw: kinderFolge.findIndex(function(c){ return /hw-zeile|hw-card/.test(c); }),
             ersterBlock: kinderFolge.indexOf(block()[0].className) });
    // Ein Tipp auf die Pruefung darf nichts oeffnen und nichts abhaken.
    block()[0].click();
    s.push({ nachTipp: { hwOverlay: sichtbar(q('#hwOverlay')), schreib: window.__probe.schreib.length } });
    // 3. Eine neue Pruefung — gleiche Hausaufgaben-Revision, neue examRev
    P.hw.exams.push(P.neu);
    P.hw.examRev = 2;
    document.dispatchEvent(new Event('visibilitychange'));
    return warte(function(){ return block().length === 5; }, 20000);
  }).then(function(){
    s.push({ neu: blockStand().map(function(x){ return x.name; }) });
    // 4. Das Wochenraster
    var plan = q('[data-schule="plan"]') || q('#tab-plan');
    if (!plan) throw new Error('kein Stundenplan-Knopf');
    plan.click();
    return warte(function(){ return qa('.wp-stunde.pruefung').filter(sichtbar).length >= 3; }, 10000);
  }).then(function(){
    s.push({ raster: qa('.wp-stunde.pruefung').filter(sichtbar).map(function(w){
      return { klasse: w.className, fach: q('.wp-fach', w).textContent,
               zeilen: qa('.wp-wo', w).map(function(x){ return x.textContent; }), tipp: w.getAttribute('title') }; }) });
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
$morgen = ['Morgen', 'Tomorrow'];

// 1. Balken
$b = (array)($s['balken'] ?? []);
pruefe(($b['titel'] ?? '') === 'KA Briefe schreiben' && ($b['sym'] ?? false) === true,
    'Tagesbalken: Titel der Klassenarbeit im Balken, eigenes Zeichen: ' . json_encode($b, JSON_UNESCAPED_UNICODE));
pruefe(str_contains((string)($b['tipp'] ?? ''), 'KA Briefe schreiben') && !str_contains((string)($b['klasse'] ?? ''), 'vertretung'),
    'Tagesbalken: Titel auch im Tipp');

// 2. Block
$blk = (array)($s['block'] ?? []);
$lang = static fn(string $iso): string => implode('.', array_reverse(explode('-', $iso)));
pruefe(array_column($blk, 'name') === ['Englisch', 'Deutsch · KA Briefe schreiben', 'Mathematik · Test', 'KA 1. KA Englisch'],
    'Block: ab heute, nur dieses Kind, nach Datum; Fach · Titel, der Titel allein, wenn er das Fach nennt: '
    . json_encode(array_column($blk, 'name'), JSON_UNESCAPED_UNICODE));
pruefe(in_array((string)($s['naechste'] ?? ''), ['Nächste Prüfung: ' . $lang($tag(1)), 'Next exam: ' . $lang($tag(1))], true),
    'Kopf: „Nächste Prüfung" mit Datum: ' . json_encode($s['naechste'] ?? null, JSON_UNESCAPED_UNICODE));
$stand = array_column($blk, 'stand');
pruefe(preg_match('/^1 (Tag\(e\) bis Prüfung|day\(s\) until the exam) · 86%$/u', (string)($stand[0] ?? '')) === 1
    && preg_match('/^3 (Tag\(e\) bis Prüfung|day\(s\) until the exam) · 57%$/u', (string)($stand[1] ?? '')) === 1
    && preg_match('/^20 (Tag\(e\) bis Prüfung|day\(s\) until the exam) · 0%$/u', (string)($stand[3] ?? '')) === 1,
    'Zeitleiste: Tage bis zur Pruefung und Anteil der Lernphase (6/7, 4/7, vor dem Lernstart 0): ' . json_encode($stand, JSON_UNESCAPED_UNICODE));
pruefe(array_column($blk, 'balken') === ['86%', '57%', null, '0%'],
    'Balken so breit wie der Anteil, keiner bei der entfallenen: ' . json_encode(array_column($blk, 'balken')));
pruefe(abs((int)($blk[1]['balkenBreite'] ?? 0) - (int)round(0.57 * (int)($blk[1]['spur'] ?? 0))) <= 2,
    'Balken wirklich gezeichnet (Breite im Bild): ' . json_encode([$blk[1]['balkenBreite'] ?? null, $blk[1]['spur'] ?? null]));
pruefe(($blk[1]['enden'] ?? null) === null ? false
    : (str_contains($blk[1]['enden'][0], $lang($tag(-4))) && str_contains($blk[1]['enden'][1], $lang($tag(3)) . ', 09:30')),
    'Unter dem Balken: Lernstart und Pruefung mit Uhrzeit: ' . json_encode($blk[1]['enden'] ?? null, JSON_UNESCAPED_UNICODE));
pruefe(in_array($blk[1]['thema'] ?? '', ['Themen: Mich vorstellen, meine Familie', 'Topics: Mich vorstellen, meine Familie'], true)
    && ($blk[0]['thema'] ?? 'x') === '',
    'Das Thema steht unter dem Namen, nur wo es eines gibt');
pruefe(($blk[2]['weg'] ?? false) === true && in_array($blk[2]['stand'] ?? '', ['entfällt', 'cancelled'], true) && ($blk[2]['enden'] ?? []) === [],
    'Entfallen: durchgestrichen, „entfällt", ohne Zeitleiste');
pruefe(($blk[1]['farbe'] ?? '') === '#4da9ff' && ($blk[0]['farbe'] ?? '') === '#f09fe0',
    'Die Farbe ist die des Fachs: ' . json_encode(array_column($blk, 'farbe')));
pruefe(array_filter($blk, static fn($z) => $z['zeile'] === true) === [] && array_filter($blk, static fn($z) => $z['rund'] !== true) === [],
    'Block: keine Hausaufgaben-Zeile, jede mit rundem Fachzeichen');
pruefe((int)($s['kopf'] ?? -1) >= 0 && (int)($s['kopf'] ?? 0) < (int)($s['ersterBlock'] ?? 0)
    && (int)($s['ersterBlock'] ?? 0) < (int)($s['ersteHw'] ?? -1), 'Block: Kopf „Prüfungen", dann die Pruefungen, DANN die Hausaufgaben');
pruefe(($s['nachTipp']['hwOverlay'] ?? true) === false && ($s['nachTipp']['schreib'] ?? 1) === 0,
    'Ein Tipp auf die Pruefung oeffnet nichts und hakt nichts ab');

// 3. examRev
pruefe(($s['neu'] ?? null) === ['Englisch', 'Deutsch · KA Briefe schreiben', 'Mathematik · Test', 'Englisch · KA 2', 'KA 1. KA Englisch'],
    'Neue Pruefung mit gleicher Hausaufgaben-Revision kommt ueber examRev an: ' . json_encode($s['neu'] ?? null, JSON_UNESCAPED_UNICODE));

// 4. Raster
$r = (array)($s['raster'] ?? []);
$nachFach = [];
foreach ($r as $w) {
    $nachFach[$w['zeilen'][1] ?? ''] = $w;
}
pruefe(count($r) === 3, 'Raster: drei Pruefungen markiert: ' . count($r));
$ka = $nachFach['KA Briefe schreiben'] ?? [];
pruefe(in_array($ka['zeilen'][0] ?? '', ['Prüfung', 'Exam'], true), 'Raster: Zustandszeile „Prüfung", darunter der Titel');
$vok = $nachFach['Vokabeltest'] ?? [];
pruefe(str_contains((string)($vok['klasse'] ?? ''), 'pruefung') && !str_contains((string)($vok['klasse'] ?? ''), 'vertretung')
    && !str_contains((string)($vok['tipp'] ?? ''), 'Richters'), 'Raster: eine geaenderte Pruefung bleibt Pruefung, nicht Vertretung');
$dik = $nachFach['Diktat'] ?? [];
pruefe(str_contains((string)($dik['klasse'] ?? ''), 'pruefung entfall') && in_array($dik['zeilen'][0] ?? '', ['Prüfung entfällt', 'exam cancelled'], true),
    'Raster: entfallene Pruefung mit beiden Klassen und „Prüfung entfällt": ' . json_encode($dik, JSON_UNESCAPED_UNICODE));
schluss();
