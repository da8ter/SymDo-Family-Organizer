<?php

declare(strict_types=1);

/**
 * Eckzeichen der Wochenansicht (30.09.2026): Haus bei Hausaufgaben, „i" bei
 * einem Hinweis der Lehrkraft, ein Tipp oeffnet das Blatt — in der
 * Stundenplan-Kachel und ihrem Zwilling in der Web-App.
 * Live gefahren im Docker (Kachel per IPS_GetVisualizationTile, Web-App per
 * Beacon); hier die Verdrahtung, damit sie nicht still verloren geht.
 *
 *   php SymDoTimetable/tests/EckzeichenTest.php
 */

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, bool $ok): void
{
    global $fehler, $anzahl;
    $anzahl++;
    if (!$ok) {
        $fehler++;
    }
    echo ($ok ? 'OK   ' : 'FEHL ') . $name . "\n";
}

$kachel = (string)file_get_contents(__DIR__ . '/../module.html');
$modul  = (string)file_get_contents(__DIR__ . '/../module.php');
$web    = (string)file_get_contents(__DIR__ . '/../../SymDoWebApp/module.html');

pruefe('Modul: je Karte die Hausaufgaben einzeln (hwListe)', str_contains($modul, "\$karte['hwListe'] = \$liste;"));
pruefe('Kachel: Eckzeichen statt roter Zahl und Notizzeile',
    str_contains($kachel, "+ eckzeichen(s, notiz, tag)") && !str_contains($kachel, "'<div class=\"wo notiz-kurz\">'"));
pruefe('Kachel: Haus und i als Knoepfe, Blatt und Klickweg in der Wochenansicht',
    str_contains($kachel, "symbol('fa-house')") && str_contains($kachel, "data-art=\"notiz\"")
    && str_contains($kachel, "html += infoBlattHtml();") && str_contains($kachel, "const knopf = e.target.closest('[data-info]');"));
pruefe('Web-App: dieselben Eckzeichen und ein eigenes Blatt',
    str_contains($web, '<span class="wp-ecke">') && str_contains($web, 'wpInfoBlattHtml()')
    && str_contains($web, "ev.target.closest('[data-wpinfo]')"));
pruefe('Web-App: Kind ueber userId (kind.id gibt es im Plan nicht)',
    str_contains($web, 'hwFuerSlot(hausaufgaben.items, kind.userId, t.date, sl.name,')
    && str_contains($web, "String(i.childId) === String(kind.userId || '')"));
pruefe('Unter dem Fachsymbol: gleiche Spalte (links 10px), untereinander, bei Enge nebeneinander',
    str_contains($kachel, "position: absolute; left: 10px; bottom: 6px;\n    display: flex; flex-direction: column;")
    && str_contains($web, '.wp-stunde .wp-ecke { position: absolute; left: 10px; bottom: 6px; display: flex; flex-direction: column;')
    && str_contains($kachel, "(eckeReihe ? ' ecke-reihe' : '')") && str_contains($web, "(hw && notiz && h < 84 ? ' wp-ecke-reihe' : '')"));

echo "\n$anzahl Zusicherungen, $fehler Abweichung(en).\n";
exit($fehler === 0 ? 0 : 1);
