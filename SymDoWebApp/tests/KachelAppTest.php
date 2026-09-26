<?php

declare(strict_types=1);

/**
 * Gemeinsames App-Skript der SymDo-Kacheln (libs/KachelApp.php): Abspalten, Version, Auslagern nur bei
 * gleichem Skript. Gegen die echten Dateien der Bibliothek.
 *
 *   php SymDoWebApp/tests/KachelAppTest.php
 */
require_once __DIR__ . '/../../libs/KachelApp.php';

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, bool $ok): void
{
    global $fehler, $anzahl;
    $anzahl++;
    if (!$ok) {
        $fehler++;
    }
    echo ($ok ? 'ok   ' : 'FEHL ') . $name . "\n";
}

$root = dirname(__DIR__, 2);
$webapp = (string) file_get_contents($root . '/SymDoWebApp/module.html');
$skript = KachelApp::Skript($webapp);
pruefe('App-Skript der Web-App gefunden (über 500 kB)', strlen($skript) > 500000);
pruefe('App-Skript beginnt mit der IIFE-Kapselung', str_starts_with($skript, "\n// In einer IIFE gekapselt"));
pruefe('App-Skript enthält kein schließendes Script-Tag', stripos($skript, '</script') === false);
pruefe('Version: 16 Hexzeichen, stabil', preg_match('/^[0-9a-f]{16}$/', KachelApp::Version($skript)) === 1 && KachelApp::Version($skript) === KachelApp::Version($skript));

foreach (['ToDoList', 'ShoppingList', 'SymDoNotes', 'SymDoHomework', 'SymDoEdumaps'] as $modul) {
    $kachel = (string) file_get_contents($root . '/' . $modul . '/module.html');
    pruefe("$modul: gleiches App-Skript wie die Web-App (Übernahme gelaufen)", KachelApp::Skript($kachel) === $skript);
    $aus = KachelApp::Auslagern($kachel, $skript);
    pruefe("$modul: ausgelagert rund 780 kB kleiner", strlen($kachel) - strlen($aus) > 700000);
    pruefe("$modul: genau ein Verweis auf " . KachelApp::PFAD . '?v=<Version>', substr_count($aus, '<script src="' . KachelApp::PFAD . '?v=' . KachelApp::Version($skript) . '"></script>') === 1);
    pruefe("$modul: nur das App-Skript fehlt, der Rest ist unverändert", str_replace('<script src="' . KachelApp::PFAD . '?v=' . KachelApp::Version($skript) . '"></script>', '<script>' . $skript . '</script>', $aus) === $kachel);
}
$todo = KachelApp::Auslagern((string) file_get_contents($root . '/ToDoList/module.html'), $skript);
pruefe('ToDo-Kachel: der Schalter steht vor dem Verweis', strpos($todo, "window.__SYMDO_KACHEL__ = { art: 'todo' }") < strpos($todo, KachelApp::PFAD));
pruefe('Ohne Gateway-Skript bleibt alles inline', KachelApp::Auslagern($webapp, '') === $webapp);
pruefe('Anderes Gateway-Skript (Übernahme fehlt): bleibt inline', KachelApp::Auslagern($webapp, $skript . ' ') === $webapp);
pruefe('Ohne App-Skript im Dokument: unverändert', KachelApp::Auslagern('<html><body>x</body></html>', $skript) === '<html><body>x</body></html>');
pruefe('Doppelter Anker: nicht angefasst', KachelApp::Skript(KachelApp::ANKER . 'a</script>' . KachelApp::ANKER . 'b</script>') === '');

echo "\n$anzahl Prüfungen, $fehler Fehler\n";
exit($fehler > 0 ? 1 : 0);
