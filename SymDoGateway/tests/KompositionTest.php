<?php

declare(strict_types=1);

/**
 * Laesst sich die Gateway-Klasse ueberhaupt zusammensetzen?
 *
 * `SymDoGateway` besteht aus ueber dreissig Traits. Zwei davon duerfen keine
 * Methode und keine Konstante desselben Namens mitbringen — PHP meldet das
 * nicht als Warnung, sondern als **fatalen Fehler beim Laden der Klasse**. Und
 * weil Symcon alle Module einer Bibliothek zusammen laedt, steht danach nicht
 * nur das Gateway still: JEDE Instanz der Bibliothek meldet „Class not found",
 * alle Praefix-Funktionen sind weg, und die App erreicht gar nichts mehr.
 * Genau so geschehen am 29.08.2026, ausgeloest von einem Bindestrich.
 *
 * Auffallen wuerde das sonst erst beim Deploy — nach `MC_ReloadModule`, mit
 * einer Konsole, die nichts mehr zeigt. Hier faellt es in einer Sekunde auf.
 *
 * Geprueft wird ausserdem, dass keine Trait-Haelfte des Umbaus „Gateway frei
 * halten" beim Aufraeumen verlorengeht: die Methoden, auf die der Lauf der
 * Klassenseiten sich stuetzt, muessen an der Klasse ankommen.
 *
 *   php SymDoGateway/tests/KompositionTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';

$fehler = 0;
$anzahl = 0;

/**
 * Der Rumpf EINER Funktion aus einer Datei — von ihrer Signatur bis zur
 * naechsten Funktion.
 *
 * Nicht an einer benannten Nachbarfunktion festmachen und keine feste
 * Zeichenzahl: beides rutscht, sobald jemand dazwischen etwas einfuegt oder
 * kuerzt, und der Riegel greift dann ins Leere, ohne dass es auffaellt. Genau
 * das ist am 15.09.2026 zweimal passiert.
 */
function rumpf(string $quelle, string $name): string
{
    $von = strpos($quelle, 'private function ' . $name . '(');
    if ($von === false) {
        return '';
    }
    /* Bis zur schliessenden Klammer der Funktion, NICHT bis zur naechsten
       Signatur: dazwischen laege deren Docblock, und ein `@return` darin
       verfaelscht jede Zaehlung von Rueckwegen. */
    $bis = strpos($quelle, "\n    }\n", $von);
    return substr($quelle, $von, ($bis === false ? strlen($quelle) : $bis + 7) - $von);
}
/**
 * Derselbe Rumpf ohne Kommentare.
 *
 * Ein Riegel, der nach Namen sucht, darf nicht auf die ERKLAERUNG anspringen.
 * `MoodleAufgabenZeilen` sagt in einem Kommentar ausdruecklich, warum zwei
 * Aufrufe von `HomeworkImportieren` mit derselben Quelle einander aufraeumen
 * wuerden — und genau dieser Satz liess den Riegel „fasst HomeworkImportieren
 * nicht an" fehlschlagen. Der Satz gehoert dorthin; die Suche nicht.
 */
function ohneKommentare(string $php): string
{
    $raus = '';
    foreach (token_get_all('<?php ' . $php) as $t) {
        if (is_array($t)) {
            /* T_OPEN_TAG muss MIT raus: sonst gibt diese Funktion fuer einen
               LEEREN Rumpf „<?php " zurueck, und jede Probe der Form
               „$rumpf !== ''" ist von da an wahr — auch wenn es die Funktion
               gar nicht mehr gibt. Genau so ist der LOGINEO-Riegel am
               15.09.2026 gruen geblieben, nachdem seine Haelften in eine
               andere Datei gezogen waren. */
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT || $t[0] === T_OPEN_TAG) {
                continue;
            }
            $raus .= $t[1];
            continue;
        }
        $raus .= $t;
    }
    return $raus;
}
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

/* Eine Warnung beim Laden ist hier so ernst wie ein Fehler: die Attrappen
   melden fehlende Symcon-Griffe auf diesem Weg. */
$warnungen = [];
set_error_handler(static function (int $n, string $m) use (&$warnungen): bool {
    $warnungen[] = $m;
    return true;
});
require_once __DIR__ . '/../module.php';
restore_error_handler();

pruefe('Die Klasse laedt ohne Warnung', $warnungen, []);
pruefe('SymDoGateway existiert', class_exists('SymDoGateway', false), true);

$k = new ReflectionClass('SymDoGateway');
printf("%-4s %s\n", '--', sprintf('%d Traits, %d Methoden', count($k->getTraitNames()), count($k->getMethods())));

/* Die Haelften des Klassenseiten-Laufs. `EduSeiteSpiegeln` ist die
   Gateway-Haelfte: sie schreibt EINMAL je Seite. Faellt sie beim Aufraeumen
   weg, spiegelt der Lauf still gar nichts mehr — ohne Fehlermeldung, denn
   `EduSeiteLesen` zaehlt dann einfach null gespiegelte Karten. */
foreach (['EduSeiteSpiegeln', 'EduKarteEinpflegen', 'EduNachzug',
          'EduSeiteLesen', 'EduScanRun', 'EduOrdner', 'EduWriteStore',
          'MailAnalyseRecord', 'MailAnalyseRechnen', 'MailVorschlagEinpflegen',
          'ScanEinpflegen', 'AiJobEnqueue', 'DokuStand'] as $m) {
    pruefe('Die Klasse kennt ' . $m, $k->hasMethod($m), true);
}

/* Der Rechenkern gehoert NICHT in die Klasse: er ist Symcon-frei und wird
   ohne Kernel geprueft. Eine Trait-Methode gleichen Namens waere ein Zeichen,
   dass jemand ihn zurueckgeholt hat. */
foreach (['Bedarf', 'SatzBauen', 'NachzugRechnen'] as $m) {
    pruefe('Der Rechenkern bleibt draussen: kein ' . $m . ' an der Klasse',
        $k->hasMethod($m), false);
}
pruefe('… sondern steht in EduStoreCalc',
    [method_exists('EduStoreCalc', 'Bedarf'), method_exists('EduStoreCalc', 'SatzBauen'),
     method_exists('EduStoreCalc', 'NachzugRechnen')], [true, true, true]);

/* Vertraege zwischen Trait und Rechenkern.
 *
 * Ein Rechenkern liegt in einer eigenen Datei und weiss nichts von dem Trait,
 * der ihn fuettert. Aendert sich dort ein Rueckgabetyp, meldet das NIEMAND —
 * bis es zur Laufzeit knallt, und zwar an der teuersten Stelle: hinter dem
 * bezahlten Anbieter-Aufruf, in einer Funktion ohne `catch`. Die Mail waere
 * danach weder als erledigt noch als gescheitert vermerkt und bei jedem Lauf
 * wieder die erste.
 *
 * Genau so geschehen am 14.09.2026: `MailDetectOrigin` liefert ein Array,
 * `MailAnalyseCalc::Satz` verlangte einen String. Der eigene Prueflauf war
 * gruen, weil er einen String hereinreichte. */
$vertraege = [
    // [Klasse, Methode, Nr des Parameters, Trait-Methode, die ihn fuellt]
    ['MailAnalyseCalc', 'Satz', 4, 'MailDetectOrigin'],
];
foreach ($vertraege as [$klasse, $methode, $nr, $quelle]) {
    $ziel = (new ReflectionMethod($klasse, $methode))->getParameters()[$nr]->getType();
    $her  = $k->getMethod($quelle)->getReturnType();
    pruefe($quelle . '() passt auf ' . $klasse . '::' . $methode . '() Parameter ' . ($nr + 1),
        (string)$ziel, (string)$her);
}

/* Die rechnende Haelfte darf NICHTS schreiben — das ist der ganze Sinn der
   Teilung. Schleicht sich hier ein Attribut-, Medien- oder Sperrzugriff ein,
   laesst sich die Haelfte nicht mehr auslagern, und niemand merkt es, bis der
   Umzug im Betrieb Medien unter der falschen Instanz anlegt. */
$quelle = (string)file_get_contents(__DIR__ . '/../libs/MailScan.php');
$rechnen = ohneKommentare(rumpf($quelle, 'MailAnalyseRechnen'));
pruefe('Beide Haelften stehen in der Datei',
    $rechnen !== '' && rumpf($quelle, 'MailVorschlagEinpflegen') !== '', true);
foreach (['WriteAttribute', 'IPS_SemaphoreEnter', 'NotesSaveAttachment',
          'MailStoreProposal', 'MailCountDay', 'MailNotifyProposal',
          'IPS_CreateMedia', 'LogMessage'] as $verboten) {
    pruefe('MailAnalyseRechnen fasst ' . $verboten . ' nicht an',
        str_contains($rechnen, $verboten), false);
}

/* Dieselbe Regel fuer LOGINEO: die lesenden Haelften sind das Teure (je
   Aufgabe ein eigener Abruf fuer den Abgabestand, zwei Abrufe fuer die
   Termine) und sollen in die Scanner-Spur. Wer hier ein Attribut oder einen
   Bestand anfasst, macht das unmoeglich — und es faellt erst auf, wenn der
   Umzug im Betrieb in die falsche Instanz schreibt. */
$moodle = (string)file_get_contents(__DIR__ . '/../libs/Moodle.php');
$lesenDatei = (string)file_get_contents(__DIR__ . '/../libs/MoodleLesen.php');
/* Und sie stehen in VERSCHIEDENEN Dateien: die lesende Haelfte in
   `MoodleLesen.php`, die der Scanner einbindet, die schreibende in
   `Moodle.php`, die nur das Gateway hat. Steht eine im falschen Haus, faellt
   das erst beim Deploy auf — als „Call to undefined method" mitten im Lauf. */
foreach (['MoodleAufgabenZeilen' => 'MoodleAufgabenEinpflegen',
          'MoodleTermineZeilen'  => 'MoodleTermineEinpflegen',
          'MoodleKontoErnten'    => 'MoodleErnteEinpflegen'] as $liest => $schreibt) {
    $lesen = ohneKommentare(rumpf($lesenDatei, $liest));
    pruefe('Die lesende Haelfte steht in MoodleLesen: ' . $liest, $lesen !== '', true);
    pruefe('… und die schreibende in Moodle: ' . $schreibt,
        rumpf($moodle, $schreibt) !== '', true);
    pruefe('… und nicht umgekehrt',
        [rumpf($moodle, $liest), rumpf($lesenDatei, $schreibt)], ['', '']);
    foreach (['WriteAttribute', 'IPS_SemaphoreEnter', 'HomeworkImportieren',
              'MailStoreProposal', 'MailNotifyProposal', 'MoodleMerken',
              'MoodleGesehen', 'NotesSaveAttachment'] as $verboten) {
        pruefe($liest . ' fasst ' . $verboten . ' nicht an',
            str_contains($lesen, $verboten), false);
    }
}
/* Und die alten, schreibenden Namen sind weg — sonst bliebe ein zweiter Weg
   stehen, den niemand mehr pflegt. */
foreach (['function MoodleAufgaben(', 'function MoodleTermine('] as $alt) {
    pruefe('Kein alter Mischweg mehr: ' . $alt,
        str_contains($moodle . $lesenDatei, $alt), false);
}
/* Die lesende Haelfte darf nirgends an einen Speicher fassen — sie laeuft in
   einer Instanz, die keinen hat. */
$lesenOhne = ohneKommentare($lesenDatei);
foreach (['ReadAttribute', 'WriteAttribute', 'IPS_Semaphore', 'MoodleTokenVon',
          'MoodleProp', 'EduStoreRead', 'NotesSaveAttachment'] as $verboten) {
    pruefe('MoodleLesen fasst ' . $verboten . ' nicht an',
        str_contains($lesenOhne, $verboten), false);
}
/* Die Probe selbst muss beissen: ein leerer Rumpf darf nicht als „da" gelten. */
pruefe('Ein leerer Rumpf bleibt leer', ohneKommentare(''), '');

/* Ein bezahlter Anbieter-Aufruf darf NIE ungezaehlt bleiben.
 *
 * Der Aufrufer bucht `kiAufrufe`, und er bucht nur, was zurueckkommt. Bis zum
 * 14.09.2026 stand der Zaehler vor dem Deuten der Antwort; seit der Teilung
 * liegt er dahinter. Verliesse ein Wurf beim Deuten die Funktion, waere der
 * Aufruf bezahlt und unsichtbar — und schlimmer: MailAnalyseRecord hat kein
 * `catch`, also liefe weder MailRemember noch MailCountFailure, und dieselbe
 * Mail waere bei jedem Lauf wieder die erste. */
pruefe('Das Deuten der Antwort faengt seine Wuerfe',
    str_contains($rechnen, 'catch (\\Throwable'), true);
/* Geprueft wird JEDES `return`, nicht nur die mit einem Array-Literal:
   `return $leer;` traegt kiAufrufe = 0 und waere genau der Fehler. */
$ohneZaehler = [];
foreach (explode('return ', $rechnen) as $nr => $stueck) {
    if ($nr === 0) {
        continue;   // der Kopf vor dem ersten return
    }
    $kopf = substr($stueck, 0, (int)max(1, strpos($stueck . ';', ';')));
    if (!str_contains($kopf, 'kiAufrufe')) {
        $ohneZaehler[] = 'return ' . trim(preg_replace('/\s+/', ' ', $kopf));
    }
}
pruefe('Jeder Rueckweg meldet die Zahl der Anbieter-Aufrufe', $ohneZaehler, []);

/* Die LESENDE Haelfte der Klassenseiten darf nichts anfassen, was einer
   Instanz gehoert — kein Attribut, kein Medienobjekt, keine Sperre, keine
   Eigenschaft. Nur so kann sie in einer Scanner-Instanz laufen. Schleicht sich
   eines davon ein, faellt es sonst erst im Betrieb auf: als Medienobjekt unter
   der falschen Instanz oder als Sperre, die die Gateway-Hooks nicht ausschliesst. */
$lesen = (string)file_get_contents(__DIR__ . '/../libs/EduLesen.php');
foreach (['ReadAttribute', 'WriteAttribute', 'ReadProperty', 'IPS_', 'EduProp',
          'EduStoreRead', 'EduWriteStore', 'NotesSaveAttachment', 'Semaphore',
          'InstanceID', 'SendDebug'] as $verboten) {
    pruefe('EduLesen fasst ' . $verboten . ' nicht an', str_contains($lesen, $verboten), false);
}
/* Und sie traegt die eine Weissliste fuer HTML. Das Feld landet in einem
   innerHTML der App; es gibt keinen CSP-Kopf und der Token liegt im
   localStorage. */
pruefe('Die HTML-Weissliste steht in der lesenden Haelfte',
    $k->hasMethod('EduHtml') && str_contains($lesen, 'private function EduHtml('), true);

/* Die Uebergabe der Klassenseiten an einen Scanner.
 *
 * Bleibt dabei der Auftrag aus, faellt der Lauf ERSATZLOS aus: kein Fehler,
 * keine Meldung, die Karten veralten still. Und der Zeitgeber muss LAUFEN
 * bleiben — er ist die einzige Uhr dieses Laufs, der Scanner hat keine eigene.
 * Genau diese Kombination wird hier festgenagelt. Ein voller Prueflauf dafuer
 * hiesse, die ganze Gateway-Klasse mit Kernel zu fahren; die Teile darunter
 * (Umschlag pruefen, Sperrliste, Einpflegen, Auswerten) haben ihre eigenen. */
foreach (['EduAuftragGeben', 'EduVonHand', 'EduKartenAuswerten'] as $m) {
    pruefe('Die Klasse kennt ' . $m, $k->hasMethod($m), true);
}
$edu = (string)file_get_contents(__DIR__ . '/../libs/EduMaps.php');
$von = strpos($edu, "if (\$Ident === 'EduScan') {");
$zweig = substr($edu, (int)$von, 1200);
pruefe('Der eigene Lauf fragt, ob ein Scanner uebernommen hat',
    str_contains($zweig, "ScanQuelleUebernommen('edu')"), true);
pruefe('… und legt dann einen Auftrag ab',
    str_contains($zweig, "EduAuftragGeben('timer')"), true);
/* Nicht abschalten: der Scanner arbeitet nur ab, was im Kanal liegt. Ohne
   diesen Schlag legte niemand je etwas hinein, und die Klassenseiten
   schliefen nach genau einem Lauf ein. */
pruefe('… ohne seinen Zeitgeber abzuschalten',
    str_contains($zweig, "SetTimerInterval('EduScan', 0)"), false);

$auftrag = rumpf($edu, 'EduAuftragGeben');
/* Die Sperrliste steht im Bestand des Gateways — ein Scanner kann sie gar
   nicht kennen. Ohne diesen Filter klapperte er eine Seite ab, die in der App
   geloescht wurde. (Die zweite Probe beim Einpflegen faengt, was sich zwischen
   Auftrag und Ergebnis noch aendert.) */
pruefe('Der Auftrag laesst gesperrte Seiten weg',
    str_contains($auftrag, 'EduGesperrt('), true);
pruefe('… und traegt die Seiten mit',
    str_contains($auftrag, "'seiten' => \$seiten"), true);
/* Ohne Einwilligung und ohne eingeschaltete Klassenseiten wird gar nichts
   beauftragt — sonst liefe der Scanner gegen die Schule, obwohl der Nutzer
   abgeschaltet hat. */
pruefe('… nur bei eingeschalteten Klassenseiten',
    str_contains($auftrag, 'EduIsEnabled()'), true);

/* Die Sammelmeldung eines Auswerte-Laufs.
 *
 * Der synchrone Lauf sammelt im Objektfeld und schickt am Ende EINE Meldung.
 * Ueber Auftraege hinweg geht das nicht — jeder fertige Auftrag kommt in einem
 * eigenen Objekt an. Deshalb liegt der Zwischenstand im Attribut, und
 * hinausgeschickt wird erst, wenn keine Hintergrundarbeit mehr wartet.
 *
 * Das Abraeumen MUSS auf jedem Weg laufen: findet der letzte Auftrag nichts
 * oder scheitert er, bliebe die Meldung sonst fuer immer im Attribut liegen
 * und die Karten davor waeren stumm eingepflegt. */
foreach (['MailAnalyseAuftrag', 'MailAuftragEinpflegen', 'MailAnhaengeNachladen',
          'EduPushAuftrag', 'EduPushAuftragFertig'] as $m) {
    pruefe('Die Klasse kennt ' . $m, $k->hasMethod($m), true);
}
$einpflegen = rumpf($quelle, 'MailAuftragEinpflegen');
pruefe('Das Abraeumen der Meldung haengt an einem finally',
    (bool)preg_match('/finally \{.*EduPushAuftragFertig\(\)/s', $einpflegen), true);
$fertig = rumpf($edu, 'EduPushAuftragFertig');
pruefe('Geschickt wird erst, wenn keine Hintergrundarbeit mehr wartet',
    str_contains($fertig, 'zaehleWartende(AiJobStore::HERKUNFT_HINTERGRUND)'), true);
/* Und der Zwischenstand liegt auf der Platte, nicht im Objektfeld. */
/* Geschrieben wird ueber EduPushUebertragen — eine Stelle fuer beide Wege:
   den Lauf, der noch auf seine Auftraege wartet, und den fertigen Auftrag. */
pruefe('Der Zwischenstand steht im Attribut',
    str_contains(rumpf($edu, 'EduPushUebertragen'), "WriteAttributeString('EduPushOffen'"), true);
pruefe('Beide Wege schreiben ueber dieselbe Stelle',
    [str_contains(rumpf($edu, 'EduPushAuftrag'), 'EduPushUebertragen('),
     str_contains(rumpf($edu, 'EduPushAbschluss'), 'EduPushUebertragen(')], [true, true]);
/* Und der Lauf schickt NICHT, solange noch Auswertungen unterwegs sind —
   sonst kaemen zwei Meldungen, die zweite ohne den Namen der Seite. */
pruefe('Der Lauf wartet, wenn noch Hintergrundarbeit laeuft',
    str_contains(rumpf($edu, 'EduPushAbschluss'),
        'zaehleWartende(AiJobStore::HERKUNFT_HINTERGRUND)'), true);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
