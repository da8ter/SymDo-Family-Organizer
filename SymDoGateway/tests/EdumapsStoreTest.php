<?php

declare(strict_types=1);

/**
 * Offline-Prüfstand für die Klassenseiten: Projektion, Zählung und vor allem
 * der UMZUG aus dem Notizen-Bestand.
 *
 * Warum es diesen Prüfstand geben MUSS: für Notizen und Klassenseiten gab es
 * bis heute keinen einzigen Test, und 55 der 64 produktiven Notizen hängen an
 * `srcId`/`srcRev`/`eduKey`. Der Umzug ist der einzige Schritt des Umbaus, der
 * Daten verlieren könnte — deshalb ist er eine reine Funktion, und deshalb wird
 * er hier durchgerechnet, bevor er irgendwo läuft.
 *
 * Läuft ohne Symcon gegen die Symcon-Stubs (symcon/module-tests) unter
 * TileVisu-Raum-Titel-Kachel/tests/stubs — anderer Pfad über SYMCON_STUBS.
 *
 *   php SymDoGateway/tests/EdumapsStoreTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/EduStoreCalc.php';

IPS\Kernel::reset();
date_default_timezone_set('Europe/Berlin');

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
    printf("%-4s %-52s%s\n", $ok ? 'OK' : 'FEHL', $name,
        $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

$jetzt = (int)strtotime('2026-09-10 09:00:00');
$mitglieder = ['aaa11111' => 'Max', 'bbb22222' => 'Anna'];

/** Ein Notizen-Bestand, wie er heute wirklich aussieht. */
function bestand(): array
{
    return [
        'v' => 1, 'rev' => 371, 'seen' => ['aaa11111'],
        'folders' => [
            // Mitglieder-Ordner der NOTIZEN — bleibt, wo er ist.
            ['id' => 'f-max',  'name' => 'Max',  'memberId' => 'aaa11111', 'parentId' => ''],
            ['id' => 'f-anna', 'name' => 'Anna', 'memberId' => 'bbb22222', 'parentId' => ''],
            // Ebene 1 der Klassenseiten, unter dem Mitglied.
            ['id' => 'f-edu',  'name' => 'Edumaps', 'memberId' => '', 'parentId' => 'f-max',
             'eduKey' => 'edu:aaa11111', 'createdAt' => 100],
            // Ebene 2: zwei Seiten.
            ['id' => 'f-s1', 'name' => '5a Klasse', 'memberId' => '', 'parentId' => 'f-edu',
             'eduKey' => 'edupage:' . md5('https://example.invalid/a'), 'createdAt' => 110],
            ['id' => 'f-s2', 'name' => 'Englisch', 'memberId' => '', 'parentId' => 'f-edu',
             'eduKey' => 'edupage:' . md5('https://example.invalid/b'), 'createdAt' => 120],
            // Ein Ordner ohne Mitglied, dessen Ebene 1 fehlt (Altfall).
            ['id' => 'f-waise', 'name' => 'Fortbildungen', 'memberId' => '', 'parentId' => '',
             'eduKey' => 'edupage:' . md5('https://example.invalid/c'), 'createdAt' => 130],
        ],
        'notes' => [
            // Echte Karten
            ['id' => 'k1', 'folderId' => 'f-s1', 'title' => 'Zettel', 'text' => 'lang',
             'srcId' => 'edu:11', 'srcRev' => 5, 'source' => 'edumaps', 'section' => 'Info',
             'pos' => 0, 'html' => '<p>lang</p>', 'srcUrl' => 'https://example.invalid/a#box-11',
             'att' => [['id' => 900, 'kind' => 'pdf', 'name' => 'a.pdf', 'bytes' => 10, 'thumb' => 901, 'qr' => '']],
             'updatedAt' => 200],
            ['id' => 'k2', 'folderId' => 'f-s2', 'title' => 'Grammatik', 'text' => 'kurz',
             'srcId' => 'edu:12', 'srcRev' => 6, 'source' => 'edumaps',
             'booking' => ['anzahl' => 2, 'limit' => 5, 'preis' => 0.0, 'zeit' => ''],
             'att' => [], 'updatedAt' => 210],
            ['id' => 'k3', 'folderId' => 'f-waise', 'title' => 'Kurs', 'text' => 'x',
             'srcId' => 'edu:13', 'srcRev' => 7, 'source' => 'edumaps', 'att' => [], 'updatedAt' => 220],
            // Von Hand in einen Klassenseiten-Ordner geschrieben: KEINE Karte.
            ['id' => 'n1', 'folderId' => 'f-s1', 'title' => 'Eigene Notiz', 'text' => 'meins',
             'source' => 'manual', 'att' => [], 'updatedAt' => 230],
            // Echte Notizen, unberührt.
            ['id' => 'n2', 'folderId' => 'f-max', 'title' => 'Einkauf', 'text' => 'y',
             'source' => 'manual', 'att' => [['id' => 910, 'kind' => 'jpg', 'name' => 'b.jpg', 'bytes' => 5]],
             'updatedAt' => 240],
            // Trägt source=edumaps, liegt aber in einem EIGENEN Ordner.
            ['id' => 'n3', 'folderId' => 'f-anna', 'title' => 'Aus der Mail', 'text' => 'z',
             'source' => 'edumaps', 'att' => [], 'updatedAt' => 250],
        ],
        'eduBlocked' => [['key' => 'edupage:' . md5('https://example.invalid/z'),
                          'url' => 'https://example.invalid/z', 'name' => 'Alt', 'at' => 90]],
    ];
}

// ── Der Umzug ───────────────────────────────────────────────────────────────
$e = EduStoreCalc::Umzug(bestand(), $mitglieder, $jetzt);
$edu = $e['edu'];
$rest = $e['notes'];

$eduOrdner = [];
foreach ($edu['folders'] as $f) {
    $eduOrdner[$f['id']] = $f;
}
$restOrdner = array_map(static fn(array $f): string => (string)$f['id'], $rest['folders']);
$eduKarten = array_map(static fn(array $n): string => (string)$n['id'], $edu['notes']);
$restKarten = array_map(static fn(array $n): string => (string)$n['id'], $rest['notes']);
sort($eduKarten);
sort($restKarten);

pruefe('vier Ordner ziehen um', array_keys($eduOrdner), ['f-edu', 'f-s1', 'f-s2', 'f-waise']);
pruefe('die Mitglieder-Ordner der Notizen bleiben', $restOrdner, ['f-max', 'f-anna']);
pruefe('aus „Edumaps" wird der Ordner des Mitglieds',
    [$eduOrdner['f-edu']['name'], $eduOrdner['f-edu']['memberId'], $eduOrdner['f-edu']['parentId']],
    ['Max', 'aaa11111', '']);
pruefe('die Seiten behalten ihren Elternteil', $eduOrdner['f-s1']['parentId'], 'f-edu');
pruefe('ein Seitenordner ohne Ebene 1 steht oben', $eduOrdner['f-waise']['parentId'], '');
pruefe('Kennungen bleiben (sonst zeigen Karten ins Leere)',
    [$eduOrdner['f-s2']['id'], $eduOrdner['f-s2']['eduKey']],
    ['f-s2', 'edupage:' . md5('https://example.invalid/b')]);
pruefe('die Anlagezeit bleibt', $eduOrdner['f-s1']['createdAt'], 110);

pruefe('drei Karten ziehen um', $eduKarten, ['k1', 'k2', 'k3']);
pruefe('die Notizen bleiben, samt der handgeschriebenen', $restKarten, ['n1', 'n2', 'n3']);
pruefe('die Handnotiz hängt am überlebenden Vorfahren',
    (function (array $rest): string {
        foreach ($rest['notes'] as $n) {
            if ($n['id'] === 'n1') { return (string)$n['folderId']; }
        }
        return '?';
    })($rest), 'f-max');
pruefe('eine Notiz mit source=edumaps im eigenen Ordner bleibt',
    (function (array $rest): string {
        foreach ($rest['notes'] as $n) {
            if ($n['id'] === 'n3') { return (string)$n['folderId']; }
        }
        return '?';
    })($rest), 'f-anna');
pruefe('die Sperrliste zieht mit', count($edu['blocked']), 1);
pruefe('und ist aus den Notizen verschwunden', array_key_exists('eduBlocked', $rest), false);
pruefe('der Bericht zählt richtig',
    [$e['stat']['ordner'], $e['stat']['karten'], $e['stat']['umgehaengt'], $e['stat']['verwaist']],
    [4, 3, 1, 0]);

// ── Zweiter Lauf: es gibt nichts mehr zu holen ──────────────────────────────
$e2 = EduStoreCalc::Umzug($rest, $mitglieder, $jetzt + 60);
pruefe('zweiter Lauf holt nichts', [$e2['stat']['ordner'], $e2['stat']['karten']], [0, 0]);
pruefe('zweiter Lauf lässt die Notizen unverändert',
    json_encode($e2['notes']) === json_encode($rest), true);

// ── Der Fall, der wehtut: Handnotiz OHNE überlebenden Vorfahren ─────────────
$ohne = bestand();
// Die Seite hängt jetzt oben, nicht unter dem Mitglied — es gibt keinen Vorfahren.
foreach ($ohne['folders'] as $i => $f) {
    if ($f['id'] === 'f-s1') { $ohne['folders'][$i]['parentId'] = ''; }
}
$e3 = EduStoreCalc::Umzug($ohne, $mitglieder, $jetzt);
$e3Ordner = array_map(static fn(array $f): string => (string)$f['id'], $e3['notes']['folders']);
pruefe('ohne Vorfahren bleibt der Ordner in den Notizen', in_array('f-s1', $e3Ordner, true), true);
pruefe('und seine Karte zieht NICHT um',
    in_array('k1', array_map(static fn(array $n): string => (string)$n['id'], $e3['edu']['notes']), true),
    false);
pruefe('die Karte ist also nicht verloren',
    in_array('k1', array_map(static fn(array $n): string => (string)$n['id'], $e3['notes']['notes']), true),
    true);
pruefe('der Bericht nennt den Fall', $e3['stat']['verwaist'], 1);

// ── Ein Bestand ohne Klassenseiten ─────────────────────────────────────────
$leer = ['v' => 1, 'rev' => 3, 'folders' => [['id' => 'x', 'name' => 'X', 'parentId' => '']],
         'notes' => [], 'eduBlocked' => []];
$e4 = EduStoreCalc::Umzug($leer, $mitglieder, $jetzt);
pruefe('nichts zu holen: leerer neuer Bestand', $e4['edu']['notes'], []);
pruefe('nichts zu holen: Notizen wortgleich zurück', $e4['notes'], $leer);

// ── Anhangs-Kennungen: das Vorschaubild MUSS mitzählen ─────────────────────
pruefe('Anhang und Vorschaubild', EduStoreCalc::AnhangIds($edu['notes']), [900, 901]);
pruefe('leere Karten ergeben keine Kennung', EduStoreCalc::AnhangIds([]), []);
pruefe('kaputte Anhänge werden übersprungen',
    EduStoreCalc::AnhangIds([['att' => ['unsinn', ['id' => 5]]]]), [5]);

// ── Projektion ─────────────────────────────────────────────────────────────
$k1 = null;
foreach ($edu['notes'] as $n) {
    if ($n['id'] === 'k1') { $k1 = $n; }
}
$ohneText = EduStoreCalc::KarteZeile($k1, false);
$mitText  = EduStoreCalc::KarteZeile($k1, true);
pruefe('ohne Volltext kommt eine Vorschau', [isset($ohneText['preview']), isset($ohneText['text'])], [true, false]);
pruefe('ohne Volltext kein html', isset($ohneText['html']), false);
pruefe('mit Volltext Text und html', [$mitText['text'], $mitText['html']], ['lang', '<p>lang</p>']);
pruefe('das Vorschaubild reist mit', $mitText['att'][0]['thumb'], 901);
pruefe('ein leerer QR-Merker geht die App nichts an', isset($mitText['att'][0]['qr']), false);
pruefe('Abschnitt und Lage', [$mitText['section'], $mitText['pos']], ['Info', 0]);
$k2 = EduStoreCalc::KarteZeile($edu['notes'][1], false);
pruefe('Buchungslage nur mit Grenze', $k2['booking'], ['count' => 2, 'limit' => 5, 'price' => 0.0, 'time' => '']);
pruefe('ohne Grenze keine Buchung',
    isset(EduStoreCalc::KarteZeile(['id' => 'z', 'booking' => ['limit' => 0]], false)['booking']), false);
pruefe('archiviert nur wenn gesetzt',
    [isset(EduStoreCalc::KarteZeile(['id' => 'z'], false)['archived']),
     EduStoreCalc::KarteZeile(['id' => 'z', 'archived' => 7], false)['archived']], [false, 7]);

// ── Zählen: Unterordner hoch, aber nicht doppelt ───────────────────────────
$zahl = EduStoreCalc::Zaehlen($edu);
pruefe('die Seite zählt ihre Karten', $zahl['f-s1'] ?? 0, 1);
pruefe('das Mitglied zählt die Karten seiner Seiten', $zahl['f-edu'] ?? 0, 2);
// Kind VOR Vater in der Liste — genau der Fall, der im Notizen-Bestand einmal
// doppelt gezählt hat.
$verdreht = ['folders' => [
        ['id' => 'kind', 'parentId' => 'vater'],
        ['id' => 'vater', 'parentId' => ''],
    ], 'notes' => [['id' => 'a', 'folderId' => 'kind'], ['id' => 'b', 'folderId' => 'kind']]];
pruefe('verdrehte Reihenfolge zählt nicht doppelt', EduStoreCalc::Zaehlen($verdreht)['vater'] ?? 0, 2);

// ── Seitenadresse aus den Karten ───────────────────────────────────────────
pruefe('Adresse ohne Anker', EduStoreCalc::SeitenUrl($edu['notes']), 'https://example.invalid/a');
pruefe('ohne srcUrl keine Adresse', EduStoreCalc::SeitenUrl([['id' => 'x']]), '');
pruefe('nichts Fremdes durchlassen',
    EduStoreCalc::SeitenUrl([['srcUrl' => 'javascript:alert(1)#box-1']]), '');

// ── Form eines gelesenen Bestands ──────────────────────────────────────────
pruefe('kaputt gelesen ergibt den leeren Bestand', EduStoreCalc::Formen('unsinn'), EduStoreCalc::Leer());
pruefe('fehlende Listen werden ergänzt',
    array_keys(EduStoreCalc::Formen(['rev' => 2])),
    ['rev', 'folders', 'notes', 'blocked', 'v', 'migratedAt']);
pruefe('der Umzugsstempel beginnt bei null', EduStoreCalc::Leer()['migratedAt'], 0);

// ══ Eine Karte in den Bestand ════════════════════════════════════════════
/* Die drei Griffe, mit denen ein Lauf entscheidet, was eine Karte kostet.
   Sie stehen hier und nicht im Trait, weil ein ausgelagerter Scanner sie
   stellen muss, ohne den Bestand zu kennen — und weil genau hier das Geld
   haengt: „voll" heisst Anhaenge laden, Vorschau holen, QR lesen. */

$karte = [
    'boxid' => 'b7', 'updated' => 1757000000, 'titel' => 'Kopiergeld',
    'abschnitt' => 'Elternbriefe', 'abschnittFarbe' => '#ffcc00', 'farbe' => '#ffffff',
    'html' => '<p>Bitte 5 €</p>', 'buchung' => null, 'anhaenge' => [],
];
$notiz = [
    'id' => 'n1', 'folderId' => 'f-seite', 'title' => 'Kopiergeld', 'text' => 'Bitte 5 €',
    'att' => [], 'createdAt' => 1756000000, 'updatedAt' => 1756000000, 'source' => 'edumaps',
    'srcId' => 'edu:b7', 'srcRev' => 1757000000, 'srcAt' => 1757000000,
    'section' => 'Elternbriefe', 'pos' => 3, 'sectionColor' => '#ffcc00', 'color' => '#ffffff',
    'html' => '<p>Bitte 5 €</p>', 'booking' => null, 'srcUrl' => 'https://x.test/s#box-b7',
];

pruefe('Eine neue Karte braucht den vollen Satz', EduStoreCalc::Bedarf(null, $karte), 'voll');
pruefe('Dieselbe Fassung braucht nur den Nachzug',
    EduStoreCalc::Bedarf($notiz, $karte), 'nachzug');
pruefe('Eine neue Fassung der Schule braucht wieder alles',
    EduStoreCalc::Bedarf($notiz, ['updated' => 1757000099] + $karte), 'voll');

// ── Der volle Satz ─────────────────────────────────────────────────────────
$neu = EduStoreCalc::SatzBauen(null, $karte, 3, 'f-seite', 'https://x.test/s',
    'Bitte 5 €', [], 'neu123', $jetzt);
pruefe('Eine neue Karte bekommt die gereichte Kennung', $neu['id'], 'neu123');
pruefe('… und wird jetzt angelegt', [$neu['createdAt'], $neu['updatedAt']], [$jetzt, $jetzt]);
/* Vom Nutzer gemeldet: „warum steht bei allen Karten 10.09.?" — `srcAt` ist
   das Datum der SCHULE, nicht das des Spiegelns. */
pruefe('Das Quelldatum ist das der Schule, nicht das des Spiegelns',
    $neu['srcAt'], 1757000000);
pruefe('Der Weg zeigt auf die Karte, nicht nur auf die Seite',
    $neu['srcUrl'], 'https://x.test/s#box-b7');

$ersetzt = EduStoreCalc::SatzBauen($notiz, $karte, 3, 'f-seite', 'https://x.test/s',
    'Bitte 5 €', [], 'DARF-NICHT', $jetzt);
pruefe('Eine ersetzte Karte behaelt Kennung und Anlagedatum',
    [$ersetzt['id'], $ersetzt['createdAt']], ['n1', 1756000000]);

// ── Der Nachzug ────────────────────────────────────────────────────────────
[$n, $g] = EduStoreCalc::NachzugRechnen($notiz, $karte, 3, 'f-seite',
    'https://x.test/s', 'Bitte 5 €', []);
pruefe('Eine wirklich unveraenderte Karte schreibt nicht', $g, false);

/* Die Buchungslage aendert sich, OHNE dass `data-updated` weiterspringt.
   Ohne diesen Nachzug stuende „12 von 16" da, wenn die AG laengst voll ist. */
[$n, $g] = EduStoreCalc::NachzugRechnen($notiz, ['buchung' => ['frei' => 0, 'max' => 16]] + $karte,
    3, 'f-seite', 'https://x.test/s', 'Bitte 5 €', []);
pruefe('Eine geaenderte Buchungslage wird nachgezogen', [$g, $n['booking']['frei']], [true, 0]);

$ohneAbschnitt = $notiz; unset($ohneAbschnitt['section']);
[$n, $g] = EduStoreCalc::NachzugRechnen($ohneAbschnitt, $karte, 3, 'f-seite',
    'https://x.test/s', 'Bitte 5 €', []);
pruefe('Eine Karte aus der Zeit vor der Kartenansicht bekommt ihren Abschnitt',
    [$g, $n['section']], [true, 'Elternbriefe']);

[$n, $g] = EduStoreCalc::NachzugRechnen($notiz, $karte, 9, 'f-seite',
    'https://x.test/s', 'Bitte 5 €', []);
pruefe('Ein anderer Platz auf der Seite zaehlt als Aenderung', [$g, $n['pos']], [true, 9]);

[$n, $g] = EduStoreCalc::NachzugRechnen($notiz, $karte, 3, 'f-ANDERS',
    'https://x.test/s', 'Bitte 5 €', []);
pruefe('Ein Ordnerwechsel zaehlt — und wird auch gesetzt',
    [$g, $n['folderId']], [true, 'f-ANDERS']);

/* Klarnamen nur bei GLEICHER Anzahl: sonst bekaeme ein Anhang den Namen
   eines anderen — die Zuordnung laeuft ueber die Reihenfolge. */
$mitAnhang = ['att' => [['id' => 9, 'name' => '1757001234.pdf', 'kind' => 'pdf', 'thumb' => 0, 'qr' => '']]] + $notiz;
[$n, $g] = EduStoreCalc::NachzugRechnen($mitAnhang, $karte, 3, 'f-seite',
    'https://x.test/s', 'Bitte 5 €', ['Elternbrief.pdf']);
pruefe('Ein roher Dateiname bekommt den Klarnamen',
    [$g, $n['att'][0]['name']], [true, 'Elternbrief.pdf']);
[$n, $g] = EduStoreCalc::NachzugRechnen($mitAnhang, $karte, 3, 'f-seite',
    'https://x.test/s', 'Bitte 5 €', ['A.pdf', 'B.pdf']);
pruefe('Bei ungleicher Anzahl bleibt der Name, wie er ist',
    [$g, $n['att'][0]['name']], [false, '1757001234.pdf']);

/* Ein ergebnisloser QR-Versuch wird als '' vermerkt — sonst liefe der Leser
   bei JEDEM Lauf ueber jedes Bild. */
$ohneQr = $mitAnhang; unset($ohneQr['att'][0]['qr']);
[$n, $g] = EduStoreCalc::NachzugRechnen($ohneQr, $karte, 3, 'f-seite',
    'https://x.test/s', 'Bitte 5 €', ['1757001234.pdf'], [], [0 => '']);
pruefe('Auch ein leerer QR-Fund wird vermerkt',
    [$g, array_key_exists('qr', $n['att'][0])], [true, true]);
[$n, $g] = EduStoreCalc::NachzugRechnen($n, $karte, 3, 'f-seite',
    'https://x.test/s', 'Bitte 5 €', ['1757001234.pdf'], [], [0 => 'https://y.test']);
pruefe('… und beim naechsten Lauf nicht noch einmal ueberschrieben',
    [$g, $n['att'][0]['qr']], [false, '']);

[$n, $g] = EduStoreCalc::NachzugRechnen($mitAnhang, $karte, 3, 'f-seite',
    'https://x.test/s', 'Bitte 5 €', ['1757001234.pdf'], [0 => 4711]);
pruefe('Ein nachgeholtes Vorschaubild haengt AM Anhang',
    [$g, $n['att'][0]['thumb']], [true, 4711]);

/* Zu langer Text wird NICHT uebernommen: das Kappen ist Sache des vollen
   Satzes, der Nachzug darf keinen halben Text hinterlassen. */
[$n, $g] = EduStoreCalc::NachzugRechnen($notiz, $karte, 3, 'f-seite',
    'https://x.test/s', str_repeat('x', EduStoreCalc::TEXT_MAX + 1), []);
pruefe('Ein zu langer Text bleibt draussen', [$g, $n['text']], [false, 'Bitte 5 €']);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
