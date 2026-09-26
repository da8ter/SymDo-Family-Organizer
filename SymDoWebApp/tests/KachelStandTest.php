<?php

declare(strict_types=1);

/**
 * Kachelstand nur bei Aenderung (26.09.2026): KachelStand rechnet den
 * Pruefwert, die Kacheln schicken ihn beim Abgleich zurueck, die Module
 * senden bei Gleichheit nichts.
 *
 *   php SymDoWebApp/tests/KachelStandTest.php
 */

require_once __DIR__ . '/../../libs/KachelStand.php';

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, mixed $ist, mixed $soll): void
{
    global $fehler, $anzahl;
    $anzahl++;
    $ok = $ist === $soll;
    if (!$ok) {
        $fehler++;
    }
    printf("%-4s %s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  " . var_export($ist, true) . "\n     soll: " . var_export($soll, true));
}

$stand = ['type' => 'state', 'items' => [['id' => 'a', 'title' => 'Milch </script>']], 'extras' => (object)[]];
$mit = KachelStand::MitPruefwert($stand);
$h = $mit['stateHash'];
pruefe('Pruefwert: 16 Hexzeichen', preg_match('/^[0-9a-f]{16}$/', $h), 1);
pruefe('Derselbe Stand, derselbe Wert — auch wenn er den alten Wert schon traegt',
    KachelStand::Pruefwert($mit), $h);
pruefe('Geaenderter Stand, anderer Wert',
    KachelStand::Pruefwert(['type' => 'state', 'items' => [['id' => 'a', 'title' => 'Milch']], 'extras' => (object)[]]) !== $h, true);
pruefe('Kodier-Optionen aendern nichts: Anfangsstand (HEX_TAG) und Push tragen denselben Wert',
    [json_decode(json_encode($mit, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG), true)['stateHash'],
     json_decode(json_encode($mit, JSON_UNESCAPED_SLASHES), true)['stateHash']], [$h, $h]);

pruefe('Kachel kennt den Stand → nichts senden', KachelStand::Kennt($h, json_encode(['hash' => $h])), true);
pruefe('Auch im CheckRevisions-Rumpf der Web-App', KachelStand::Kennt($h, json_encode(['revisions' => [], 'hash' => $h])), true);
pruefe('Anderer Wert → senden', KachelStand::Kennt($h, '{"hash":"0000000000000000"}'), false);
pruefe('Leerer Wert (frische Kachel, Gateway-Kachel) → senden', KachelStand::Kennt($h, '{"hash":""}'), false);
pruefe('Formular-Knopf (0) und alte Kachel ohne Wert → senden',
    [KachelStand::Kennt($h, 0), KachelStand::Kennt($h, '0'), KachelStand::Kennt($h, '{"revisions":{}}'), KachelStand::Kennt($h, null)],
    [false, false, false, false]);
pruefe('Kaputtes JSON → senden', KachelStand::Kennt($h, '{kaputt'), false);

// Die Kacheln schicken den Wert mit — in der Web-App und in allen fuenf Kopien.
$web = (string)file_get_contents(__DIR__ . '/../module.html');
pruefe('Web-App merkt den Wert VOR jeder Umformung und schickt ihn beim Abgleich',
    [str_contains($web, "if (data && typeof data === 'object' && typeof data.stateHash === 'string') kachelStateHash = data.stateHash;\n  if (!data || typeof data !== 'object') return;"),
     str_contains($web, "requestAction('CheckRevisions', JSON.stringify({ revisions, hash: kachelStateHash }));"),
     str_contains($web, "requestAction('GetState', JSON.stringify({ hash: kachelStateHash }));")],
    [true, true, true]);
pruefe('Kein 15-s-Takt, wo der Stand einen Pruefwert traegt; der Abgleich beim Sichtbarwerden bleibt',
    [str_contains($web, "    if (kachelStateHash) return;\n    if (document.visibilityState === 'visible') checkRevisions();\n  }, 15000);"),
     str_contains($web, "if (document.visibilityState === 'visible') { checkRevisions(); gatewayNachladen(); }")], [true, true]);
foreach (['ToDoList', 'ShoppingList', 'SymDoNotes', 'SymDoHomework', 'SymDoEdumaps'] as $m) {
    $html = (string)file_get_contents(__DIR__ . '/../../' . $m . '/module.html');
    $php  = (string)file_get_contents(__DIR__ . '/../../' . $m . '/module.php');
    $alle = $php . (is_file(__DIR__ . '/../../' . $m . '/libs/ItemStore.php')
        ? (string)file_get_contents(__DIR__ . '/../../' . $m . '/libs/ItemStore.php') : '');
    pruefe($m . ': Kachel schickt den Wert, Modul prueft ihn und traegt ihn im Anfangsstand',
        [str_contains($html, 'hash: kachelStateHash'), str_contains($alle, 'KachelStand::Kennt('),
         str_contains($php, 'KachelStand::MitPruefwert(')],
        [true, true, true]);
}

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
