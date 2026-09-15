<?php

declare(strict_types=1);

/**
 * Keine Kachel haengt am Gateway — und keine darf es wieder tun.
 *
 * Am 15.09.2026 hat die Konsole beim Loeschen einer VRR-Instanz das Gateway
 * mitgeloescht. Im Protokoll der SymBox stehen beide in derselben Sekunde:
 *
 *     15:07:32 | 34095 | SymDo Gateway     | Entferne...
 *     15:07:32 | 51097 | SymDo VRR Transit | Entferne...
 *
 * Mit dem Gateway gingen alle Notizen, die gekoppelten Geraete und saemtliche
 * Zugangsdaten. Sechs weitere Kacheln hingen daran — es half nichts.
 *
 * Gemessen wurde danach zweierlei, und erst zusammen ergeben sie diesen
 * Pruefstand:
 *
 *  1. Ohne `parentRequirements` weist Symcon jedes `IPS_ConnectInstance` mit
 *     „Datenfluss ist inkompatibel" ab.
 *  2. Auch dann, wenn zusaetzlich die `childRequirements` des Gateways leer
 *     sind.
 *
 * Einen Anschluss OHNE diese Gefahr gibt es also nicht. Deshalb faellt er weg,
 * und deshalb prueft diese Datei drei Dinge, die zusammengehoeren: keine
 * Anforderung, kein Vorschlag, kein Anschluss.
 *
 * Gegenprobe: in einer beliebigen module.json die Schnittstellen-GUID zurueck
 * in `parentRequirements` schreiben → „Keine Anforderung" faellt. Ein
 * `GetCompatibleParents` wieder einsetzen → „Kein Vorschlag" faellt. Ein
 * `IPS_ConnectInstance` in ein Modul schreiben → „Kein Anschluss" faellt.
 *
 *   php SymDoGateway/tests/ElternanschlussTest.php
 */

$wurzel = dirname(__DIR__, 2);
/** Die Schnittstelle des Gateways. */
const SYMDO_GATEWAY_INTERFACE = '{5A13BDF9-E069-4AA9-9D9B-35C1BDC785B8}';

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
    printf("%-4s %-54s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

/** Quelltext ohne Kommentare — ein Wort im Kommentar ist kein Aufruf. */
function ohneKommentare(string $quelle): string
{
    $raus = '';
    foreach (token_get_all($quelle) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $raus .= $t[1];
            continue;
        }
        $raus .= $t;
    }
    return $raus;
}
// Die Wache selbst: sonst faende sie in einer leeren Datei „<?php " und
// jede „steht nicht darin"-Zusicherung waere wertlos.
pruefe('Der Kommentar-Filter laesst nichts stehen', ohneKommentare(''), '');

// ── Alle Module der Bibliothek einsammeln ────────────────────────────────
$module = [];
foreach (glob($wurzel . '/*/module.json') ?: [] as $json) {
    $ordner = basename(dirname($json));
    $d = json_decode((string)file_get_contents($json), true);
    if (is_array($d) && isset($d['id'])) {
        $module[$ordner] = $d;
    }
}
pruefe('Module gefunden', count($module) > 10, true);

// ── 1. Keine Anforderung ─────────────────────────────────────────────────
$mitAnforderung = [];
foreach ($module as $ordner => $d) {
    if (in_array(SYMDO_GATEWAY_INTERFACE, (array)($d['parentRequirements'] ?? []), true)) {
        $mitAnforderung[] = $ordner;
    }
}
pruefe('Keine Anforderung auf die Gateway-Schnittstelle', $mitAnforderung, []);

/* Das Gateway selbst behaelt seine `childRequirements`: sie kosten nichts und
   weisen einen von Hand versuchten Fremdanschluss weiterhin ab. */
$gw = $module['SymDoGateway'] ?? [];
pruefe('Das Gateway behaelt seine childRequirements',
    in_array(SYMDO_GATEWAY_INTERFACE, (array)($gw['childRequirements'] ?? []), true), true);

// ── 2. Kein Vorschlag, 3. kein Anschluss ─────────────────────────────────
$mitVorschlag = [];
$mitAnschluss = [];
$mitLoesen    = [];
foreach (array_keys($module) as $ordner) {
    $datei = $wurzel . '/' . $ordner . '/module.php';
    if (!is_file($datei)) {
        continue;
    }
    $code = ohneKommentare((string)file_get_contents($datei));
    if (str_contains($code, 'function GetCompatibleParents')) {
        $mitVorschlag[] = $ordner;
    }
    if (str_contains($code, 'IPS_ConnectInstance')) {
        $mitAnschluss[] = $ordner;
    }
    if (str_contains($code, 'function ElternanschlussLoesen')) {
        $mitLoesen[] = $ordner;
    }
}
pruefe('Kein Modul schlaegt noch ein Gateway vor', $mitVorschlag, []);
pruefe('Kein Modul verbindet sich noch', $mitAnschluss, []);

/* ALLE dreizehn muessen einen bestehenden Anschluss LOESEN — sonst bleibt die
   Gefahr in jeder bestehenden Installation stehen. Vier davon haben sich nie
   selbst angeschlossen (der Einrichtungs-Assistent tat es); sie standen in der
   ersten Fassung dieser Liste nicht drin, und im Docker hingen danach genau
   diese vier weiter am Gateway. */
$erwartet = ['Chores', 'MealPlan', 'Routines', 'ShoppingList', 'Stundenplan',
             'SymDoEdumaps', 'SymDoHomework', 'SymDoNotes', 'SymDoScanner',
             'SymDoVRRTransit', 'SymDoVoice', 'SymDoWebApp', 'ToDoList'];
sort($mitLoesen);
pruefe('Alle dreizehn loesen einen bestehenden Anschluss', $mitLoesen, $erwartet);

/* Und sie rufen es auch auf — eine Funktion, die niemand ruft, loest nichts. */
$ohneAufruf = [];
foreach ($erwartet as $ordner) {
    $code = ohneKommentare((string)file_get_contents($wurzel . '/' . $ordner . '/module.php'));
    if (!str_contains($code, '$this->ElternanschlussLoesen();')) {
        $ohneAufruf[] = $ordner;
    }
}
pruefe('… und rufen es auch auf', $ohneAufruf, []);

/* Das Loesen muss IPS_DisconnectInstance wirklich benutzen. */
$ohneTrennen = [];
foreach ($erwartet as $ordner) {
    $code = ohneKommentare((string)file_get_contents($wurzel . '/' . $ordner . '/module.php'));
    if (!str_contains($code, 'IPS_DisconnectInstance')) {
        $ohneTrennen[] = $ordner;
    }
}
pruefe('… und trennen wirklich', $ohneTrennen, []);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler > 0 ? 1 : 0);
