<?php

declare(strict_types=1);

/**
 * Keine Dauer-Animation im Leerlauf (Stufe 4, 28.09.2026).
 *
 * Gemessen: Schon EINE laufende Animation in einer sichtbaren Kachel – auch ein
 * 2×2-Pixel-Punkt – lässt die Visu die ganze Seite 60-mal je Sekunde neu
 * zusammensetzen (~0,15–0,3 CPU-Kerne). Deshalb:
 *  - Laufschrift (Artikel, Termine, Übersicht, Stundenplan): zwei Durchläufe,
 *    dann Ruhe mit Ellipse; Antippen startet sie erneut.
 *  - „Verspätet“ (Web-App, VRR): nur rot, kein Blinken (Nutzerentscheid).
 *  - Endlos laufen darf nur, was an eine Tätigkeit gebunden ist (Lauschen,
 *    Laden, Diktat, Scanner).
 *  - Die Sprach-Blase zeichnet im tiefen Schlaf nur rund 15 Bilder je Sekunde.
 *
 *   php SymDoWebApp/tests/DaueranimationTest.php
 */

$wurzel = dirname(__DIR__, 2);
$fehler = 0;
$anzahl = 0;
function pruefe(string $name, bool $ok, string $detail = ''): void
{
    global $fehler, $anzahl;
    $anzahl++;
    if ($ok) {
        echo "OK   $name\n";
        return;
    }
    $fehler++;
    echo "FEHL $name" . ($detail !== '' ? "\n     $detail" : '') . "\n";
}

/** Namen der endlos laufenden Animationen einer Datei. */
function endlos(string $html): array
{
    preg_match_all('~animation\s*:\s*([A-Za-z][\w-]*)[^;{}]*\binfinite\b~', $html, $m);
    preg_match_all('~animation-name\s*:\s*([A-Za-z][\w-]*)[^{}]*?animation-iteration-count\s*:\s*infinite~', $html, $n);
    return array_values(array_unique(array_merge($m[1], $n[1])));
}

// An eine Tätigkeit gebunden: laufen nur, solange etwas passiert
$erlaubt = ['voiceLauschen', 'ai-spin', 'diktatPuls', 'scanner-line-move', 'ladeDreh'];
$webApp = ['SymDoWebApp', 'ToDoList', 'ShoppingList', 'SymDoNotes', 'SymDoHomework', 'SymDoEdumaps'];

foreach ($webApp as $modul) {
    $html = (string)file_get_contents("$wurzel/$modul/module.html");
    $uebrig = array_values(array_diff(endlos($html), $erlaubt));
    pruefe("$modul: nur Tätigkeits-Animationen laufen endlos", $uebrig === [], 'endlos: ' . implode(', ', $uebrig));
    pruefe("$modul: Laufschrift läuft zweimal", substr_count($html, 'marquee-pingpong 6s ease-in-out 2;') === 2);
    pruefe("$modul: Laufschrift kommt zur Ruhe und startet beim Antippen neu",
        str_contains($html, "e.animationName !== 'marquee-pingpong'") && str_contains($html, "box.classList.add('ruht')")
        && str_contains($html, "closest('.strip-item, .plan-termin')"));
    pruefe("$modul: „Verspätet“ blinkt nicht", !str_contains($html, 'nvSpaetBlinken') && str_contains($html, '.nv-spaet { color: #f87171 !important; }'));
}

foreach (['ShoppingListOverview' => ['name-pingpong', '.iname', '.item'], 'Stundenplan' => ['termin-pingpong', '.termin-titel', '.termin']] as $modul => [$anim, $box, $halter]) {
    $html = (string)file_get_contents("$wurzel/$modul/module.html");
    pruefe("$modul: keine Dauer-Animation", endlos($html) === [], implode(', ', endlos($html)));
    pruefe("$modul: Laufschrift läuft zweimal und ruht dann", str_contains($html, "$anim 6s ease-in-out 2;")
        && str_contains($html, "e.animationName !== '$anim'") && str_contains($html, "closest('$halter')")
        && str_contains($html, "$box.laeuft.ruht"));
}

$vrr = (string)file_get_contents("$wurzel/SymDoVRRTransit/module.html");
pruefe('SymDoVRRTransit: keine Dauer-Animation, „Verspätet“ nur rot', endlos($vrr) === [] && !str_contains($vrr, 'spaetblinken'));

$blase = (string)file_get_contents("$wurzel/SymDoGateway/libs/voice-blob.js");
pruefe('Sprach-Blase: im tiefen Schlaf gedrosselt', (bool)preg_match('~if \(schlaf > \.98 && !kernOffen && raf\) \{\s*cancelAnimationFrame\(raf\);[\s\S]{0,200}setTimeout\([\s\S]{0,160}SCHLAF_TAKT\)~', $blase));
pruefe('Sprach-Blase: Anhalten löscht auch den Schlaftakt', str_contains($blase, 'if (schlafUhr) { clearTimeout(schlafUhr); schlafUhr = 0; }'));

echo "\n$anzahl Zusicherungen, $fehler Abweichung(en).\n";
exit($fehler === 0 ? 0 : 1);
