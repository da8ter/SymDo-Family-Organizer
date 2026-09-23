<?php

declare(strict_types=1);

/**
 * Prüfstand: die Art eines KI-Vorschlags umstellen, ohne dass die Zeile springt
 * (23.09.2026).
 *
 * Gemeldet: „Hausaufgaben im Auswahlfeld gewählt, dann auf + geklickt — es kam
 * der Aufgaben-Dialog." Ursache war NICHT die Übernahme, sondern die
 * Sortierung: die Funde einer Mail stehen in fester Sortenfolge (Aufgabe,
 * Termin, Hausaufgabe, Notiz), die umgestellte Zeile wechselte die Gruppe und
 * rutschte nach unten — unter dem Finger stand danach der nächste Fund, und
 * dessen + öffnete den Aufgaben-Dialog.
 *
 * Teil 1 sind Riegel, Teil 2 fährt die echte Web-App headless gegen das
 * Docker-Gateway (nur mit MIT_CHROME=1, dauert 2–3 min).
 *
 *   php SymDoWebApp/tests/VorschlagArtWechselTest.php
 *   MIT_CHROME=1 php SymDoWebApp/tests/VorschlagArtWechselTest.php
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

$html = (string)file_get_contents(__DIR__ . '/../module.html');

// ── Teil 1: Riegel ──────────────────────────────────────────────────────────
pruefe(str_contains($html, 'const mailSortMerker = new Map();'), 'Merker für die Einsortierung vorhanden');
pruefe(str_contains($html, 'function mailSortSorte(id, it)'), 'eigene Funktion für die Sortier-Sorte');
pruefe(str_contains($html, 'mailSortSorte(p.id, it) === sorte'), 'die Sortierung fragt den Merker, nicht die aktuelle Art');
// Genau EINE Stelle sortiert; mailArt bleibt für Symbol, Auswahlfeld und Übernahme zuständig.
pruefe(substr_count($html, 'mailSortSorte(') === 2, 'mailSortSorte nur definiert und einmal benutzt');
pruefe(str_contains($html, 'const sorte = mailArt(it);'), 'Symbol und Auswahlfeld folgen weiter der aktuellen Art');
pruefe(str_contains($html, "if (eintrag.kind === 'homework')"), 'Übernahme kennt den Hausaufgaben-Zweig');

// Alle fünf Kopien müssen den Merker haben — sonst springt die Zeile in der Kachel weiter.
foreach (['ToDoList', 'ShoppingList', 'SymDoEdumaps', 'SymDoNotes', 'SymDoHomework'] as $kopie) {
    $datei = __DIR__ . '/../../' . $kopie . '/module.html';
    pruefe(is_file($datei) && str_contains((string)file_get_contents($datei), 'function mailSortSorte(id, it)'),
        "Kopie $kopie hat den Merker");
}

// ── Teil 2: die echte Oberfläche ────────────────────────────────────────────
if (getenv('MIT_CHROME') !== '1') {
    echo $fehler === 0 ? "OK — $zahl Prüfungen bestanden (Browser-Teil übersprungen, MIT_CHROME=1 setzt ihn an)\n"
                       : "FEHLER — $fehler von $zahl Prüfungen gefallen\n";
    exit($fehler === 0 ? 0 : 1);
}

$chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
$basis  = getenv('SYMDO_BASE') ?: 'http://127.0.0.1:3778';
$token  = trim((string)@file_get_contents((getenv('SYMDO_TOKEN_FILE') ?: '')));
if (!is_file($chrome) || $token === '') {
    echo "  (Browser-Teil übersprungen: Chrome oder Token fehlt)\n";
    echo $fehler === 0 ? "OK — $zahl Prüfungen bestanden\n" : "FEHLER — $fehler von $zahl Prüfungen gefallen\n";
    exit($fehler === 0 ? 0 : 1);
}

$arbeit = sys_get_temp_dir() . '/symdo-artwechsel-' . getmypid();
@mkdir($arbeit, 0o777, true);
$seite = (string)file_get_contents($basis . '/hook/lists/webapp');
$seite = str_replace(["'/hook/lists/app/v1", '"/hook/lists/app/v1'],
                     ["'" . $basis . '/hook/lists/app/v1', '"' . $basis . '/hook/lists/app/v1'], $seite);
$vor = "<script>try{localStorage.setItem('symdo.token'," . json_encode($token) . ");"
     . "localStorage.setItem('symdo.tab','ki');}catch(e){}</script>";
$seite = substr_replace($seite, $vor, (int)strpos($seite, '<script'), 0);

/* Der Treiber stellt die Nutzerhandlung nach: Stelle der Zeile merken, Art auf
   „Hausaufgabe" umstellen, und dann den +-Knopf an DERSELBEN Stelle drücken —
   also den, der jetzt dort steht, nicht den der gemerkten Kennung. */
$treiber = <<<'JS'
<script>
(function(){
  function fertig(d){ document.title = 'FERTIG ' + JSON.stringify(d); }
  function zeilen(){ return Array.from(document.querySelectorAll('.mail-row')); }
  function offen(){
    return Array.from(document.querySelectorAll('[id$="Overlay"]')).filter(function(o){
      var s = getComputedStyle(o);
      return s.display !== 'none' && s.visibility !== 'hidden' && o.offsetHeight > 0;
    }).map(function(o){ return o.id; });
  }
  setTimeout(function(){
    var alle = zeilen();
    var feld = null, stelle = -1;
    for (var k = 0; k < alle.length; k++) {
      var f = alle[k].querySelector('select.mail-kind-select');
      if (f && Array.from(f.options).some(function(o){ return o.value === 'homework'; })
            && f.value !== 'homework') { feld = f; stelle = k; break; }
    }
    if (!feld) { fertig({ fehler: 'keine umstellbare Zeile', zeilen: alle.length }); return; }
    var kennung = alle[stelle].getAttribute('data-id') + '|' + alle[stelle].getAttribute('data-i');
    feld.value = 'homework';
    feld.dispatchEvent(new Event('change', { bubbles: true }));
    setTimeout(function(){
      var neu = zeilen();
      var jetzt = neu[stelle];
      var kennungJetzt = jetzt ? (jetzt.getAttribute('data-id') + '|' + jetzt.getAttribute('data-i')) : '';
      var art = jetzt ? (jetzt.querySelector('select.mail-kind-select') || {}).value : '';
      var knopf = jetzt && jetzt.querySelector('[data-mail="add"]');
      if (!knopf) { fertig({ fehler: 'kein +-Knopf', gleich: kennungJetzt === kennung }); return; }
      knopf.click();
      setTimeout(function(){
        fertig({ gleich: kennungJetzt === kennung, art: art, overlays: offen() });
      }, 1200);
    }, 1500);
  }, 6000);
})();
</script>
JS;
$seite = substr_replace($seite, $treiber, (int)strrpos($seite, '</body>'), 0);
$datei = $arbeit . '/probe.html';
file_put_contents($datei, $seite);

$cmd = sprintf(
    'perl -e %s %s --headless=new --disable-web-security --user-data-dir=%s --window-size=500,1400 '
    . '--virtual-time-budget=30000 --dump-dom %s 2>/dev/null',
    escapeshellarg('alarm 120; exec @ARGV'), escapeshellarg($chrome),
    escapeshellarg($arbeit . '/profil'), escapeshellarg('file://' . $datei)
);
$dom = (string)shell_exec($cmd);
$titel = preg_match('~<title>FERTIG (.*?)</title>~s', $dom, $m) ? json_decode(html_entity_decode($m[1]), true) : null;
@shell_exec('rm -rf ' . escapeshellarg($arbeit));

if (!is_array($titel)) {
    pruefe(false, 'Browser-Lauf lieferte kein Ergebnis');
} else {
    pruefe(!isset($titel['fehler']), 'Browser-Lauf ohne Abbruch: ' . json_encode($titel));
    pruefe(($titel['gleich'] ?? false) === true, 'die Zeile bleibt an ihrer Stelle stehen');
    pruefe(($titel['art'] ?? '') === 'homework', 'an dieser Stelle steht jetzt „Hausaufgabe"');
    pruefe(in_array('hwOverlay', (array)($titel['overlays'] ?? []), true), 'der +-Knopf öffnet das Hausaufgaben-Blatt');
    pruefe(!in_array('todoOverlay', (array)($titel['overlays'] ?? []), true), 'und NICHT den Aufgaben-Dialog');
}

echo $fehler === 0 ? "OK — $zahl Prüfungen bestanden\n" : "FEHLER — $fehler von $zahl Prüfungen gefallen\n";
exit($fehler === 0 ? 0 : 1);
