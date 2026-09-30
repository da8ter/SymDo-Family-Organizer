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
    str_contains($kachel, "+ eckzeichen(s, notiz, tag, pruefZeichen, eckeV)") && !str_contains($kachel, "'<div class=\"wo notiz-kurz\">'"));
pruefe('Pruefung: Eckzeichen statt Rahmen, Blatt mit Titel/Art/Themen, Einzelheiten vom Gateway',
    !str_contains($kachel, '.stunde.lage-pruefung { box-shadow') && !str_contains($web, '.wp-stunde.pruefung { box-shadow')
    && str_contains($kachel, 'data-art="pruefung"') && str_contains($web, 'data-art="pruefung"')
    && str_contains($kachel, '>Themen: ') && str_contains($modul, "\$plan = \$this->PruefungenAnhaengen(\$plan,")
    && str_contains((string)file_get_contents(__DIR__ . '/../../SymDoGateway/libs/Homework.php'), "'exams' => \$pruefungen"));
pruefe('Kachel: Haus und i als Knoepfe, Blatt und Klickweg in der Wochenansicht',
    str_contains($kachel, "symbol('fa-house')") && str_contains($kachel, "data-art=\"notiz\"")
    && str_contains($kachel, "html += infoBlattHtml();") && str_contains($kachel, "const knopf = e.target.closest('[data-info]');"));
pruefe('Web-App: dieselben Eckzeichen und ein eigenes Blatt',
    str_contains($web, "ecke = '<span class=\"wp-ecke\"'") && str_contains($web, 'wpInfoBlattHtml()')
    && str_contains($web, "ev.target.closest('[data-wpinfo]')"));
pruefe('Web-App: Kind ueber userId (kind.id gibt es im Plan nicht)',
    str_contains($web, 'hwFuerSlot(hausaufgaben.items, kind.userId, t.date, sl.name,')
    && str_contains($web, "String(i.childId) === String(kind.userId || '')"));
pruefe('Unter dem Fachsymbol: gleiche Spalte (links 10px), untereinander, bei Enge nebeneinander',
    str_contains($kachel, "position: absolute; left: 10px; bottom: 6px;\n    display: flex; flex-direction: column;")
    && str_contains($web, '.wp-stunde .wp-ecke { position: absolute; left: 10px; bottom: 6px; display: flex; flex-direction: column;')
    && str_contains($kachel, "(eckeReihe ? ' ecke-reihe' : '')") && str_contains($kachel, 'const eckeReihe = zeichen > 1 && h < 34 + 24 * zeichen;')
    && str_contains($web, 'const eckeReihe = zeichen > 1 && h < 34 + 24 * zeichen;')
    && str_contains($web, "(eckeReihe ? ' wp-ecke-reihe' : '')"));
pruefe('Zu eng fuer die Saeule: Stapel wie die Mitgliederleiste, Hover/erster Tipp faechert auf (beide Oberflaechen)',
    str_contains($kachel, 'transform: translateY(calc(var(--i, 0) * -1 * var(--ek-v, 8px)));')
    && str_contains($web, 'transform: translateY(calc(var(--i, 0) * -1 * var(--ek-v, 8px)));')
    && str_contains($kachel, '.stunde.ecke-reihe .ecke.offen button {') && str_contains($web, '.wp-stunde.wp-ecke-reihe .wp-ecke.offen button {')
    && str_contains($kachel, "const stapel = knopf && knopf.closest('.ecke-reihe .ecke');")
    && str_contains($web, "const stapel = knopf && knopf.closest('.wp-ecke-reihe .wp-ecke');")
    && substr_count($kachel, "matchMedia('(hover: hover)').matches") >= 1
    && str_contains($kachel, ';--karte:') && str_contains($web, ";--karte:'"));

// Versatz: der Stapel bleibt unter dem Fachsymbol (Rechnung beider Oberflaechen gleich).
$versatz = static fn(int $h, int $n, bool $eng): int
    => max(3, min(10, (int)floor(($h - ($eng ? 1 : 6) - 32 - ($eng ? 16 : 22)) / ($n - 1))));
pruefe('Versatz: 60-Minuten-Karte mit drei Zeichen passt unter das Fachsymbol, enge Karte faellt auf 3px',
    $versatz(72, 3, false) === 6 && 22 + 2 * $versatz(72, 3, false) <= 72 - 6 - 32 && $versatz(54, 3, true) === 3
    && str_contains($kachel, 'return Math.max(3, Math.min(10, Math.floor((frei - knopf) / (n - 1))));')
    && str_contains($web, 'Math.max(3, Math.min(10, Math.floor((h - (eng ? 1 : 6) - 32 - (eng ? 16 : 22)) / (zeichen - 1))))'));

echo "\n$anzahl Zusicherungen, $fehler Abweichung(en).\n";
exit($fehler === 0 ? 0 : 1);
