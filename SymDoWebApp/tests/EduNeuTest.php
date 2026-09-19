<?php

declare(strict_types=1);

/**
 * Pruefstand fuer das „Neu"-Abzeichen der Klassenseiten.
 *
 * Zwei Teile: Riegel am Quelltext (Web-App, alle fuenf Kachel-Kopien, die
 * Uebersetzungen) und — wenn ein Chrome da ist — ein Lauf der echten Seite
 * im Headless-Browser: Grundlinie, Zaehler, Pille, Oeffnen als Gesehen.
 *
 *   php SymDoWebApp/tests/EduNeuTest.php
 *   MIT_CHROME=1 php SymDoWebApp/tests/EduNeuTest.php   (dazu der Browser-Lauf, Minuten)
 */

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, mixed $ist, mixed $soll): void
{
    global $fehler, $anzahl;
    $anzahl++;
    $a = json_encode($ist, JSON_UNESCAPED_UNICODE);
    $b = json_encode($soll, JSON_UNESCAPED_UNICODE);
    $ok = $a === $b;
    if (!$ok) {
        $fehler++;
    }
    printf("%-4s %-66s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}
$wurzel = realpath(__DIR__ . '/../..');
$lesen = static fn(string $p): string => (string)@file_get_contents($wurzel . '/' . $p);

// ── Riegel ─────────────────────────────────────────────────────────────────
$html = $lesen('SymDoWebApp/module.html');
pruefe('Web-App: Zustand, Praedikate, Abzeichen und Pille vorhanden',
    [str_contains($html, 'function eduIstNeu'), str_contains($html, "'symdo.eduGesehen'"),
     str_contains($html, 'id="tabEdumapsBadge"'), str_contains($html, 'id="tabSchoolBadge"'),
     str_contains($html, 'class="edu-neu"'), str_contains($html, 'function setEduBadges'),
     str_contains($html, 'function eduNeuZaehlen'), str_contains($html, 'function eduGesehenAbgleichen')],
    [true, true, true, true, true, true, true, true]);
$oeffnen = substr($html, strpos($html, 'function eduOrdnerOeffnen('), 400);
pruefe('Eine andere Seite leert die Sitzung — in eduOrdnerOeffnen',
    str_contains($oeffnen, 'eduNeuSitzung.clear()'), true);
pruefe('Die Grundlinie entsteht beim LADEN, nicht erst beim Zeichnen',
    str_contains(substr($html, strpos($html, 'function ladeEdumaps('), 2500), 'eduGesehenAbgleichen();'), true);
pruefe('Das App-Symbol zaehlt weiter allein die KI-Vorschlaege (eine Aufrufstelle)',
    substr_count($html, 'appBadgeSetzen('), 2);
pruefe('Die Zaehlung liest den Bestand direkt, nicht ueber die Notiz-Helfer',
    [str_contains(substr($html, strpos($html, 'function eduNeuZaehlen('), 900), 'edumaps.folders'),
     str_contains(substr($html, strpos($html, 'function eduNeuZaehlen('), 900), 'notesImOrdner')],
    [true, false]);
$kopien = ['ShoppingList', 'ToDoList', 'SymDoEdumaps', 'SymDoNotes', 'SymDoHomework'];
pruefe('Alle fuenf Kachel-Kopien tragen das Abzeichen (Uebernahme gelaufen)',
    array_map(static fn($m) => str_contains($lesen("$m/module.html"), 'function eduIstNeu'), $kopien),
    array_fill(0, 5, true));
pruefe('Wortgleiche Kopien sind wortgleich',
    array_map(static fn($m) => $lesen("$m/module.html") === $html, ['SymDoEdumaps', 'SymDoNotes', 'SymDoHomework']),
    [true, true, true]);
pruefe('„New" heisst ueberall „Neu"',
    array_map(static fn($m) => json_decode($lesen("$m/locale.json"), true)['translations']['de']['New'] ?? null,
        array_merge(['SymDoWebApp'], $kopien)),
    array_fill(0, 6, 'Neu'));

// ── Headless-Lauf ──────────────────────────────────────────────────────────
/* Der Browser-Lauf ist AUFRUF-Sache (MIT_CHROME=1): er dauert auf einem
   belegten Rechner Minuten, und ein haengendes Chrome soll den Pruefstand
   nicht mitreissen — deshalb auch die harte Frist je Lauf. */
$chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
if (!getenv('MIT_CHROME') || !is_file($chrome)) {
    printf("\n%d Zusicherungen, %d Abweichung(en). (Headless-Lauf uebersprungen — MIT_CHROME=1 schaltet ihn ein)\n", $anzahl, $fehler);
    exit($fehler === 0 ? 0 : 1);
}
$tmp = sys_get_temp_dir() . '/eduneu-' . getmypid();
@mkdir($tmp, 0777, true);

/* Der Treiber: stubbt die API (window.__symdoApiPost ist genau dafuer da), liefert
   eine feste Klassenseiten-Liste, setzt den gemerkten Stand und faehrt den Bereich.
   Karten: A neu (Stempel 200 > gemerkt 100), B neu aber archiviert, C gesehen,
   D neu beim zweiten Kind. */
$treiber = <<<'JS'
<script>
window.requestAnimationFrame = (f) => setTimeout(f, 0);
/* Standalone-Modus: window.__symdoApiPost ist der vorgesehene Haken, um die
   JSON-API zu ersetzen — jede Anfrage bekommt sofort eine feste Antwort. */
window.__SYMDO__ = { apiBase: '/x', tabs: { dashboard: false, shopping: false, todos: false, edumaps: true }, aiEnabled: false };
const LISTE = { ok: true, rev: 1,
  folders: [
    { id: 'edu:k1', name: 'Kind Eins', parentId: '', memberId: 'k1', count: 3, source: 'edumaps' },
    { id: 'edupage:p1', name: 'Klasse 3a', parentId: 'edu:k1', memberId: '', count: 3, source: 'edumaps' },
    { id: 'edu:k2', name: 'Kind Zwei', parentId: '', memberId: 'k2', count: 1, source: 'edumaps' },
    { id: 'edupage:p2', name: 'Klasse 5b', parentId: 'edu:k2', memberId: '', count: 1, source: 'edumaps' }
  ],
  notes: [
    { id: 'A', folderId: 'edupage:p1', title: 'A neu', updatedAt: 200, section: 'Info', pos: 1, source: 'edumaps', text: 'a' },
    { id: 'B', folderId: 'edupage:p1', title: 'B archiv', updatedAt: 200, archived: 1, section: 'Info', pos: 2, source: 'edumaps', text: 'b' },
    { id: 'C', folderId: 'edupage:p1', title: 'C gesehen', updatedAt: 100, section: 'Info', pos: 3, source: 'edumaps', text: 'c' },
    { id: 'D', folderId: 'edupage:p2', title: 'D neu', updatedAt: 100, srcAt: 200, section: 'Info', pos: 1, source: 'edumaps', text: 'd' }
  ], memberFolders: {}, limits: {} };
window.__symdoApiPost = (path) => Promise.resolve({ status: 200, json: path === '/edumaps' ? LISTE : { ok: true } });
const MODUS = new URLSearchParams(location.search).get('m') || location.hash.slice(1) || 'stand';
try { localStorage.clear(); localStorage.setItem('symdo.eduOffen', JSON.stringify({ Info: true }));
      if (MODUS === 'stand') localStorage.setItem('symdo.eduGesehen', JSON.stringify({ A: 100, B: 100, C: 100, D: 100 })); } catch (e) {}
const out = { modus: MODUS };
const badge = (id) => { const el = document.getElementById(id); return el && el.style.display !== 'none' ? el.textContent : ''; };
const zahl = (sel) => { const el = document.querSelectorAll ? null : document.querySelector(sel); return el ? el.textContent.trim() : ''; };
window.addEventListener('load', () => setTimeout(() => {
  const knopf = document.getElementById('tab-edumaps'); if (knopf) knopf.click();
  setTimeout(() => {
    out.a = { badge: badge('tabEdumapsBadge'), k1: zahl('[data-key="em:edu:k1"] .edu-neu-zahl'), k2: zahl('[data-key="em:edu:k2"] .edu-neu-zahl'),
              schluessel: !!localStorage.getItem('symdo.eduGesehen') };
    const seite = document.querySelector('[data-folder="edu:k1"]'); if (seite) seite.click();   // Kind-Ordner oeffnen
    setTimeout(() => {
      const p1 = document.querySelector('[data-folder="edupage:p1"]'); if (p1) p1.click();     // Seite oeffnen
      setTimeout(() => {
        const g = JSON.parse(localStorage.getItem('symdo.eduGesehen') || '{}');
        out.b = { pillen: document.querySelectorAll('.edu-neu').length, badge: badge('tabEdumapsBadge'),
                  abschnitt: zahl('[data-key="ea:Info"] .edu-neu-zahl'), gA: g.A, gB: g.B, k1: zahl('[data-key="em:edu:k1"] .edu-neu-zahl') };
        const zur = document.querySelector('[data-folder="edu:k1"], #btnEduBack'); if (zur) zur.click();
        setTimeout(() => {
          const p1b = document.querySelector('[data-folder="edupage:p1"]'); if (p1b) p1b.click();
          setTimeout(() => { out.c = { pillen: document.querySelectorAll('.edu-neu').length, badge: badge('tabEdumapsBadge') };
            document.title = 'MESS ' + JSON.stringify(out); }, 300);
        }, 300);
      }, 400);
    }, 300);
  }, 900);
}, 400));
</script>
JS;
$treiber = str_replace('document.querSelectorAll ? null : ', '', $treiber);
$fixture = substr_replace($html, $treiber, strrpos($html, '</body>'), 0);
/* Beide Laeufe PARALLEL: einer dauert auf einem belegten Rechner Minuten. Start,
   dann Warten auf beide (harte Frist 600 s je Lauf, danach gilt er als ohne
   Ergebnis). */
$starten = static function (string $modus) use ($chrome, $tmp, $fixture): void {
    $f = "$tmp/f-$modus.html";
    file_put_contents($f, str_replace("location.hash.slice(1) || 'stand'", "'$modus'", $fixture));
    $aus = "$tmp/dom-$modus.html";
    $cmd = escapeshellarg($chrome) . ' --headless=new --disable-gpu --user-data-dir=' . escapeshellarg("$tmp/p-$modus")
        . ' --window-size=1200,900 --virtual-time-budget=6000 --dump-dom ' . escapeshellarg("file://$f")
        . ' > ' . escapeshellarg($aus) . ' 2>/dev/null';
    shell_exec('( (' . $cmd . ') & CH=$!; ( sleep 600; kill -9 $CH 2>/dev/null ) & W=$!; wait $CH; kill $W 2>/dev/null; touch '
        . escapeshellarg("$tmp/fertig-$modus") . ' ) >/dev/null 2>&1 &');
};
$lesen2 = static function (string $modus) use ($tmp): ?array {
    $bis = time() + 660;
    while (!is_file("$tmp/fertig-$modus") && time() < $bis) {
        sleep(2);
    }
    $dom = (string)@file_get_contents("$tmp/dom-$modus.html");
    if (!preg_match('/MESS (\{.*?\})<\/title>/s', $dom, $m)) {
        return null;
    }
    return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
};
$starten('stand');
$starten('leer');
$lauf = $lesen2;
$r = $lauf('stand');
pruefe('(a) Mit gemerktem Stand: Kind Eins 1 neu, Kind Zwei 1 neu, Tab-Abzeichen 2 (kein Kind gewaehlt)',
    [$r['a']['k1'] ?? null, $r['a']['k2'] ?? null, $r['a']['badge'] ?? null], ['1', '1', '2']);
pruefe('(b) Seite offen: genau eine Pille (A, nicht B im Archiv), Abzeichen 1, Ordnerzahl weg, Stempel gemerkt',
    [$r['b']['pillen'] ?? null, $r['b']['badge'] ?? null, $r['b']['k1'] ?? null, $r['b']['gA'] ?? null, $r['b']['gB'] ?? null, $r['b']['abschnitt'] ?? null],
    [1, '1', '', 200, 200, '1']);
pruefe('(c) Seite verlassen und wieder oeffnen: keine Pille mehr',
    [$r['c']['pillen'] ?? null, $r['c']['badge'] ?? null], [0, '1']);
$r0 = $lauf('leer');
pruefe('(d) Ohne gemerkten Stand: Grundlinie — keine Abzeichen, Schluessel danach da',
    [$r0['a']['badge'] ?? null, $r0['a']['k1'] ?? null, $r0['a']['schluessel'] ?? null, $r0['b']['pillen'] ?? null], ['', '', true, 0]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
