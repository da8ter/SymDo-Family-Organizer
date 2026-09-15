<?php

declare(strict_types=1);

/**
 * Was CalDAV aus einer 207-Antwort macht — und wann es sie verwirft.
 *
 * Warum es diesen Prüfstand geben MUSS: HTTP 207 sagt nur, dass eine
 * Multi-Status-Antwort kommt, **nicht**, dass alle Einträge geklappt haben
 * (RFC 4918 §13). Der Parser las die Statusangaben der einzelnen `response`-
 * und `propstat`-Elemente gar nicht. Fiel nach einem Serverfehler der Inhalt
 * von `calendar-data` weg, verschwand die Aufgabe still aus dem Ergebnis — und
 * der anschließende Abgleich hielt jede bereits synchronisierte, nun fehlende
 * Aufgabe für **gelöscht**. Auch mit `local_wins` und noch nicht übertragenen
 * lokalen Änderungen.
 *
 * Ein ausgelassener Durchgang kostet nichts. Eine falsche Löschung kostet die
 * lokale Fassung, und ein späterer erfolgreicher Abruf holt sie nicht zurück.
 *
 * Gemeldet von einem externen Codereview am 14.09.2026.
 *
 *   php ToDoList/tests/CalDAVMultistatusTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../module.php';

date_default_timezone_set('Europe/Berlin');
IPS\Kernel::reset();

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
    printf("%-4s %-58s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

$modul = (new ReflectionClass('SymDoToDoList'))->newInstanceArgs([4243]);
$lesen = new ReflectionMethod('SymDoToDoList', 'CalDAVParseMultiStatus');

/** Eine Antwort mit beliebig vielen `response`-Blöcken. */
function antwort(string ...$bloecke): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        . implode('', $bloecke) . '</d:multistatus>';
}

function aufgabe(string $href, string $uid, string $titel): string
{
    $ics = "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VTODO\nUID:$uid\nSUMMARY:$titel\nEND:VTODO\nEND:VCALENDAR";
    return '<d:response><d:href>' . $href . '</d:href><d:propstat>'
        . '<d:prop><d:getetag>"e1"</d:getetag>'
        . '<c:calendar-data>' . htmlspecialchars($ics) . '</c:calendar-data></d:prop>'
        . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
}

/** Ein Eintrag, dessen Kalenderdaten der Server nicht liefern konnte. */
function fehlschlag(string $href, string $status): string
{
    return '<d:response><d:href>' . $href . '</d:href><d:propstat>'
        . '<d:prop><c:calendar-data/></d:prop>'
        . '<d:status>HTTP/1.1 ' . $status . '</d:status></d:propstat></d:response>';
}

// ── Der gewöhnliche Fall ──────────────────────────────────────────────────
$gut = (array)$lesen->invoke($modul, antwort(
    aufgabe('/cal/1.ics', 'u1', 'Einkaufen'),
    aufgabe('/cal/2.ics', 'u2', 'Anrufen')));
pruefe('Zwei saubere Aufgaben kommen durch', count($gut), 2);
pruefe('… mit ihrem Weg', [$gut[0]['caldavHref'], $gut[1]['caldavHref']], ['/cal/1.ics', '/cal/2.ics']);

/* Die Sammlung selbst antwortet mit 200 und OHNE Kalenderdaten — das ist kein
   Fehler und darf den Abruf nicht verwerfen. */
$mitSammlung = $lesen->invoke($modul, antwort(
    '<d:response><d:href>/cal/</d:href><d:propstat><d:prop><d:getetag>"c"</d:getetag></d:prop>'
    . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>',
    aufgabe('/cal/1.ics', 'u1', 'Einkaufen')));
pruefe('Die Sammlung selbst stoert nicht', count((array)$mitSammlung), 1);

// ── Teilfehler: der ganze Abruf wird verworfen ────────────────────────────
/* DAS ist der Fall, um den es geht. Vorher kam hier eine Liste mit EINER
   Aufgabe zurück — und der Abgleich löschte die andere lokal. */
foreach (['500 Internal Server Error', '503 Service Unavailable', '403 Forbidden'] as $status) {
    pruefe('Teilfehler ' . substr($status, 0, 3) . ' verwirft den ganzen Abruf',
        $lesen->invoke($modul, antwort(
            aufgabe('/cal/1.ics', 'u1', 'Einkaufen'),
            fehlschlag('/cal/2.ics', $status))), null);
}

/* 404 bleibt draußen: eine Ressource, die zwischen Abfrage und Lesen
   verschwindet, IST gelöscht — das ist eine Aussage, kein Fehler. */
pruefe('404 verwirft den Abruf NICHT',
    count((array)$lesen->invoke($modul, antwort(
        aufgabe('/cal/1.ics', 'u1', 'Einkaufen'),
        fehlschlag('/cal/2.ics', '404 Not Found')))), 1);

/* Auch ein Fehler an der ganzen Ressource (ohne propstat) zählt. */
pruefe('Ein Fehler direkt an der Ressource zaehlt auch',
    $lesen->invoke($modul, antwort(
        aufgabe('/cal/1.ics', 'u1', 'Einkaufen'),
        '<d:response><d:href>/cal/2.ics</d:href>'
        . '<d:status>HTTP/1.1 500 Internal Server Error</d:status></d:response>')), null);

/* Kalenderdaten da, aber unlesbar: weglassen hieße „gibt es nicht mehr". */
pruefe('Unlesbare Kalenderdaten verwerfen den Abruf',
    $lesen->invoke($modul, antwort(
        aufgabe('/cal/1.ics', 'u1', 'Einkaufen'),
        '<d:response><d:href>/cal/2.ics</d:href><d:propstat>'
        . '<d:prop><c:calendar-data>kein ical</c:calendar-data></d:prop>'
        . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>')), null);

// ── Der schon vorhandene Riegel bleibt ────────────────────────────────────
pruefe('Ein unlesbarer Rumpf verwirft weiterhin',
    $lesen->invoke($modul, '<d:multistatus><kaputt'), null);
pruefe('Eine leere, aber gueltige Antwort ist eine leere Liste',
    $lesen->invoke($modul, antwort()), []);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
