<?php

declare(strict_types=1);

/**
 * Standardadressen fuer Mitglieder ohne eigene Zeile.
 *
 * Ein Mitglied OHNE gespeicherte Zeile in `MailAddresses` bekommt seine
 * Adresse von selbst — in den Formularzeilen, in der Karte des Webhooks, in der
 * KI-Inbox von App und Web-App. Eine gespeicherte LEERE Zeile bleibt leer
 * („Mail aus"), ausser die Liste traegt gar keine Adresse (so speichert die
 * Konsole sie, wenn „Uebernehmen" vor der Domain kam). Keine Adresse doppelt,
 * auch bei gleichen Namen; die Eigenschaft wird dafuer nie geschrieben.
 *
 *   php SymDoGateway/tests/MailAdressenTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
IPS\Kernel::reset();
require_once __DIR__ . '/MailProbe.php';

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, mixed $ist, mixed $soll): void
{
    global $fehler, $anzahl;
    $anzahl++;
    $a = json_encode($ist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $b = json_encode($soll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = $a === $b;
    if (!$ok) {
        $fehler++;
    }
    printf("%-4s %-66s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

[$pid, $p, $setze] = mailProbeAnlegen();

$lena  = ['id' => 'aa11bb22', 'name' => 'Lena'];
$tom   = ['id' => 'cc33dd44', 'name' => 'Tom'];
$lena2 = ['id' => 'ee55ff66', 'name' => 'Lena'];
$max   = ['id' => '99887766', 'name' => 'Max'];   // Kennung nur aus Ziffern: wird als Schluessel zur Zahl
$adressen = static fn(array $zeilen): array => array_column($zeilen, 'Address');

// ══ A. Standardadressen ═══════════════════════════════════════════════════
$p->users = [$lena, $tom, $lena2];
$setze(['MailHookBase' => 'mg.example.com', 'MailAddresses' => '[]', 'MailHookEnabled' => true]);
pruefe('Ohne gespeicherte Zeilen: jede Zeile vorbelegt, zwei Lenas verschieden', $adressen($p->pRows()),
    ['familie@mg.example.com', 'lena@mg.example.com', 'tom@mg.example.com', 'lena-ee55ff@mg.example.com']);
pruefe('… die Formularzeilen tragen nur die vier Spalten', array_keys($p->pRows()[1]), ['UserID', 'Name', 'Address', 'SenderAllow']);
pruefe('… die Familienzeile heisst im Formular „Family (general)"', $p->pRows()[0]['Name'], 'Family (general)');
$p->uebersetzt = 0;
$p->pMap();
pruefe('… der Webhook-Weg (Karte) kommt ohne Uebersetzung aus', $p->uebersetzt, 0);
pruefe('… die Karte des Webhooks kennt sie mit ihrem Mitglied', array_map(static fn(array $z): string => $z['UserID'], $p->pMap()),
    ['familie@mg.example.com' => '', 'lena@mg.example.com' => 'aa11bb22', 'tom@mg.example.com' => 'cc33dd44',
     'lena-ee55ff@mg.example.com' => 'ee55ff66']);
$j = $p->pJudge('Lena-EE55FF@mg.example.com');
pruefe('… die Webhook-Wache nimmt die Standardadresse an (Gross/klein egal)', [$j['skip'], $j['userId']], [false, 'ee55ff66']);
pruefe('… und die KI-Inbox meldet sie an App und Web-App', array_column($p->pIntake()['addresses'], 'address'),
    ['familie@mg.example.com', 'lena@mg.example.com', 'tom@mg.example.com', 'lena-ee55ff@mg.example.com']);

$gespeichert = (string)json_encode([
    ['UserID' => '', 'Name' => 'Familie', 'Address' => 'familie@mg.example.com', 'SenderAllow' => ''],
    ['UserID' => 'aa11bb22', 'Name' => 'Lena', 'Address' => 'lena@mg.example.com', 'SenderAllow' => '@schule.example'],
    ['UserID' => 'cc33dd44', 'Name' => 'Tom', 'Address' => '', 'SenderAllow' => ''],
]);
$setze(['MailAddresses' => $gespeichert]);
pruefe('Gespeichert leer = Mail aus; ohne Zeile = Standardadresse', $adressen($p->pRows()),
    ['familie@mg.example.com', 'lena@mg.example.com', '', 'lena-ee55ff@mg.example.com']);
pruefe('… Tom steht nicht in der Karte, die Wache weist ihn ab', [isset($p->pMap()['tom@mg.example.com']), $p->pJudge('tom@mg.example.com')['skip']],
    [false, true]);
pruefe('… Lenas Absenderliste bleibt an ihrer Zeile', $p->pMap()['lena@mg.example.com']['SenderAllow'], '@schule.example');
$p->users = [$lena, $tom, $lena2, $max];
pruefe('Neues Mitglied (Kennung aus Ziffern): sofort eine Adresse', [$p->pRows()[4]['Address'], $p->pMap()['max@mg.example.com']['UserID'] ?? null],
    ['max@mg.example.com', '99887766']);
pruefe('… ohne dass die Eigenschaft geschrieben wurde', IPS_GetProperty($pid, 'MailAddresses'), $gespeichert);

$setze(['MailAddresses' => (string)json_encode([
    ['UserID' => '', 'Name' => 'Familie', 'Address' => '', 'SenderAllow' => ''],
    ['UserID' => 'aa11bb22', 'Name' => 'Lena', 'Address' => '', 'SenderAllow' => '@schule.example'],
    ['UserID' => 'cc33dd44', 'Name' => 'Tom', 'Address' => '', 'SenderAllow' => ''],
])]);
pruefe('Alles leer gespeichert („Uebernehmen" vor der Domain): Standardadressen', $adressen($p->pRows()),
    ['familie@mg.example.com', 'lena@mg.example.com', 'tom@mg.example.com', 'lena-ee55ff@mg.example.com', 'max@mg.example.com']);
pruefe('… die gespeicherte Absenderliste gilt trotzdem', $p->pMap()['lena@mg.example.com']['SenderAllow'] ?? null, '@schule.example');

$setze(['MailAddresses' => (string)json_encode([
    ['UserID' => 'cc33dd44', 'Name' => 'Tom', 'Address' => 'Lena@mg.example.com', 'SenderAllow' => ''],
    ['UserID' => 'dead0000', 'Name' => 'Weg', 'Address' => 'alt@mg.example.com', 'SenderAllow' => ''],
])]);
$rows = $p->pRows();
pruefe('Keine Dubletten mit festen Adressen: beide Lenas weichen aus', $adressen($rows),
    ['familie@mg.example.com', 'lena-aa11bb@mg.example.com', 'Lena@mg.example.com', 'lena-ee55ff@mg.example.com', 'max@mg.example.com']);
pruefe('… jede Adresse genau einmal', count(array_unique(array_map('strtolower', array_filter($adressen($rows))))), 5);
pruefe('… die Zeile eines verschwundenen Mitglieds zaehlt weiter wie bisher',
    [$p->pMap()['alt@mg.example.com']['UserID'] ?? null, in_array('alt@mg.example.com', array_column($p->pIntake()['addresses'], 'address'), true)],
    ['dead0000', false]);

$setze(['MailHookBase' => 'post@mg.example.com', 'MailAddresses' => '[]']);
pruefe('Feste Adresse im Domain-Feld: Plus-Adressen', $adressen($p->pRows()),
    ['post@mg.example.com', 'post+lena@mg.example.com', 'post+tom@mg.example.com', 'post+lena-ee55ff@mg.example.com', 'post+max@mg.example.com']);
$setze(['MailHookBase' => '']);
pruefe('Ohne Domain: keine Vorbelegung, die Wache bleibt zu', [$adressen($p->pRows()), $p->pJudge('lena@mg.example.com')['skip']],
    [['', '', '', '', ''], true]);

// Knopf „Generate receiving addresses" mit den LEBENDEN Zeilen
$setze(['MailHookBase' => 'mg.example.com', 'MailAddresses' => (string)json_encode([
    ['UserID' => 'aa11bb22', 'Name' => 'Lena', 'Address' => 'lena@mg.example.com', 'SenderAllow' => ''],
    ['UserID' => 'cc33dd44', 'Name' => 'Tom', 'Address' => 'tom@mg.example.com', 'SenderAllow' => ''],
])]);
$p->neu();
$lebend = [['UserID' => '', 'Address' => 'familie@mg.example.com'], ['UserID' => 'aa11bb22', 'Address' => ''],
           ['UserID' => 'cc33dd44', 'Address' => ''], ['UserID' => 'ee55ff66', 'Address' => 'eigene@example.org'],
           ['UserID' => '99887766', 'Address' => '']];
$p->RequestAction('MailHookFillAddresses', json_encode($lebend));
$gefuellt = json_decode((string)$p->feld('MailAddresses', 'values'), true);
pruefe('Adressknopf fuellt nur Luecken — eine gerade geleerte bekommt ihre Adresse zurueck', $adressen($gefuellt),
    ['familie@mg.example.com', 'lena@mg.example.com', 'tom@mg.example.com', 'eigene@example.org', 'max@mg.example.com']);
pruefe('… und meldet sich unter der Tabelle, nicht oben', [$p->feld('MailAddressStatus', 'caption'), $p->feld('MailHookStatus', 'caption')],
    ['3 address(es) added — press Apply to save.', null]);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
