<?php

declare(strict_types=1);

/**
 * Die Symcon-Seite des Knopfs „Set up Mailgun" und des Token-Knopfs.
 *
 * Gefahren wird der Knopf mit dem ECHTEN onClick aus module.php — der
 * Pruefstand baut die Eingabe nicht von Hand (Lehre aus dem Codereview vom
 * 14.09.2026: ein Pruefstand, der die Eingabe selbst baut, prueft seine
 * eigene Annahme). Mailgun ist die Attrappe aus MailgunAttrappe.php.
 *
 *  - Einrichten: gespeichert UND gegengelesen, Formular nachgezogen, Region
 *    gemerkt, kein Geheimnis in Status, Debug oder Protokoll.
 *  - Verweigert Symcon das Uebernehmen: Nachtrag per Einmal-Zeitgeber.
 *  - Neuer Token: mit Schluessel folgt die Route, ohne bleibt die Anleitung;
 *    scheitert Mailgun, bleibt der alte Token.
 *
 *   php SymDoGateway/tests/MailgunEinrichtungTest.php
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

// ══ B. Einrichten mit dem Knopf ═══════════════════════════════════════════
$KEY = 'key-probe-0123456789abcdef0123';
$mg = new MailgunAttrappe($KEY);
$mg->domains['us'][] = MailgunAttrappe::domain('sandbox12.mailgun.org');
$p->mailgun = $mg;
$p->users = [$lena, $tom];
$setze(['MailHookBase' => '', 'MailAddresses' => '[]', 'MailHookEnabled' => false, 'MailHookSecret' => '',
        'MailHookSigningKey' => '', 'MailHookApiKey' => '']);
$p->neu();

/* Den ECHTEN onClick aus module.php holen: vom Schluessel 'onClick' bis zum
   Ende des Eintrags. Er ist ein verketteter PHP-Ausdruck, der den Skripttext ergibt. */
$modul = (string)file_get_contents(__DIR__ . '/../module.php');
$marke = <<<'TXT'
'onClick' => 'IPS_RequestAction($id, \'MailHookMailgunSetup\'
TXT;
$von = strpos($modul, $marke);
$bis = $von === false ? false : strpos($modul, "\n                ],", $von);
$ausdruck = ($von === false || $bis === false) ? "''" : trim(substr($modul, $von + strlen("'onClick' => "), $bis - $von - strlen("'onClick' => ")));
/* eval ist hier sicher und gewollt: ausgefuehrt wird nur Quelltext aus dem
   eigenen module.php (ein String-Ausdruck bzw. der onClick-Text), genau wie die
   Symcon-Konsole den onClick ausfuehrt — keine fremde Eingabe. */
$onClick = (string)eval('return ' . $ausdruck . ';');
pruefe('Der Knopf ruft MailHookMailgunSetup mit den Formularwerten', [str_contains($onClick, "'MailHookMailgunSetup'"),
    str_contains($onClick, '$MailHookApiKey'), str_contains($onClick, 'iterator_to_array($MailAddresses)')], [true, true, true]);
$druecke = static function (array $werte) use ($onClick, $pid): void {
    (static function (string $skript, array $v): void {
        extract($v);
        eval($skript);   // der onClick aus dem eigenen module.php, s. o. — keine fremde Eingabe
    })($onClick, ['id' => $pid] + $werte);
};
// So sieht das offene Formular aus: ohne Domain, also ohne Vorbelegung.
$formZeilen = $p->pRows();
$druecke(['MailHookApiKey' => $KEY, 'MailHookBase' => '', 'MailHookSecret' => '',
          'MailAddresses' => new ArrayIterator($formZeilen)]);
$token = (string)IPS_GetProperty($pid, 'MailHookSecret');
pruefe('Gespeichert und GEGENGELESEN: Schluessel, Domain, Token, Signaturschluessel, Schalter', [
    IPS_GetProperty($pid, 'MailHookApiKey') === $KEY, IPS_GetProperty($pid, 'MailHookBase'), MailgunSetup::tokenGueltig($token),
    IPS_GetProperty($pid, 'MailHookSigningKey') === $mg->signatur, IPS_GetProperty($pid, 'MailHookEnabled')],
    [true, 'sandbox12.mailgun.org', true, true, true]);
pruefe('… direkt uebernommen, kein Nachtrag noetig; Region gemerkt', [$p->nachgetragen, $p->pRegion()], [0, 'us']);
$route = array_values($mg->routen['us'])[0] ?? [];
pruefe('… die Route leitet auf die Hook-Adresse mit genau diesem Token', [count($mg->routen['us']), $route['actions'] ?? null,
    $route['description'] ?? null], [1, ['forward("https://abc123.ipmagic.de/hook/lists/app/v1/mail/hook/' . $token . '")', 'stop()'],
    'SymDo Gateway ' . $pid]);
pruefe('… das offene Formular ist nachgezogen', [$p->feld('MailHookBase'), $p->feld('MailHookSecret') === $token,
    $p->feld('MailHookSigningKey') === $mg->signatur, $p->feld('MailHookEnabled'),
    $p->feld('MailHookNotifyUrl') === 'https://abc123.ipmagic.de/hook/lists/app/v1/mail/hook/' . $token],
    ['sandbox12.mailgun.org', true, true, true, true]);
pruefe('… die Tabelle zeigt die neuen Standardadressen', $adressen(json_decode((string)$p->feld('MailAddresses', 'values'), true)),
    ['familie@sandbox12.mailgun.org', 'lena@sandbox12.mailgun.org', 'tom@sandbox12.mailgun.org']);
pruefe('… die Adressliste selbst wurde NICHT geschrieben, die Wache nimmt trotzdem an',
    [IPS_GetProperty($pid, 'MailAddresses'), $p->pJudge('lena@sandbox12.mailgun.org')['userId']], ['[]', 'aa11bb22']);
pruefe('Statuszeile', $p->status(), 'Mailgun is set up for sandbox12.mailgun.org (region US): route created, signing key stored, '
    . 'mail reception switched on. Self-check passed: Mailgun hands mail for this domain to Symcon.');
$alles = implode("\n", array_merge($p->debug, $p->protokoll, [$p->status()]));
pruefe('Kein Schluessel, Token oder Signaturschluessel in Status, Debug, Protokoll',
    [str_contains($alles, $KEY), str_contains($alles, $token), str_contains($alles, (string)$mg->signatur)], [false, false, false]);
pruefe('… das Protokoll nennt nur Domain und Region', $p->protokoll,
    ['SymDo: Mailgun eingerichtet — Domain sandbox12.mailgun.org, Region US, Route angelegt']);

// Noch einmal drücken (das Formular traegt jetzt den gespeicherten Token)
$p->neu();
$druecke(['MailHookApiKey' => $KEY, 'MailHookBase' => 'sandbox12.mailgun.org', 'MailHookSecret' => $token,
          'MailAddresses' => new ArrayIterator($p->pRows())]);
pruefe('Zweites Druecken: dieselbe Route (PUT), derselbe Token', [count($mg->routen['us']), count($mg->gerufen('PUT', '/v3/routes/')),
    IPS_GetProperty($pid, 'MailHookSecret') === $token, str_contains($p->status(), 'route updated')], [1, 1, true, true]);
pruefe('… die gemerkte Region wird zuerst gefragt — und genuegt', array_column(array_slice($mg->gerufen('GET', '/v4/domains'), 2), 'region'),
    ['us']);   // erster Lauf: US und EU (nichts eingetragen), zweiter: nur US

// ══ C. Neuer Token ════════════════════════════════════════════════════════
$setze(['MailHookEnabled' => false]);   // bewusst aus: der Token-Knopf darf ihn nicht einschalten
$p->neu();
$p->RequestAction('MailHookNewSecret', 0);
$neuerToken = (string)IPS_GetProperty($pid, 'MailHookSecret');
$route = array_values($mg->routen['us'])[0];
pruefe('Neuer Token mit Schluessel: gespeichert, Route zeigt darauf, Schalter bleibt aus', [
    $neuerToken !== $token && MailgunSetup::tokenGueltig($neuerToken), $route['actions'][0], count($mg->routen['us']),
    IPS_GetProperty($pid, 'MailHookEnabled'), $p->feld('MailHookEnabled')],
    [true, 'forward("https://abc123.ipmagic.de/hook/lists/app/v1/mail/hook/' . $neuerToken . '")', 1, false, null]);
pruefe('… Formular und Status', [$p->feld('MailHookSecret') === $neuerToken, str_starts_with($p->status(),
    'New token stored — the route in Mailgun for sandbox12.mailgun.org now points to the new address.')], [true, true]);

$mg->erzwingen['us PUT /v3/routes/' . $route['id']] = [500, '{"message":"internal"}'];
$p->neu();
$p->RequestAction('MailHookNewSecret', 0);
pruefe('Mailgun scheitert: der alte Token bleibt, die Route auch', [IPS_GetProperty($pid, 'MailHookSecret') === $neuerToken,
    array_values($mg->routen['us'])[0]['actions'][0] === $route['actions'][0], $p->feld('MailHookSecret')], [true, true, null]);
pruefe('… und der Status sagt es', $p->status(),
    'The token was not changed: Saving the route: Mailgun reports a server error (HTTP 500) — please try again later.');
unset($mg->erzwingen['us PUT /v3/routes/' . $route['id']]);

$setze(['MailHookApiKey' => '']);
$vorherRufe = count($mg->rufe);
$p->neu();
$p->RequestAction('MailHookNewSecret', 0);
pruefe('Ohne Schluessel: die Anleitung wie bisher, kein Ruf an Mailgun, nichts gespeichert', [
    $p->status(), MailgunSetup::tokenGueltig((string)$p->feld('MailHookSecret')), count($mg->rufe) - $vorherRufe,
    IPS_GetProperty($pid, 'MailHookSecret') === $neuerToken], ['New token generated — press Apply to save it.', true, 0, true]);

// ══ D. Fehler und Grenzfaelle ═════════════════════════════════════════════
$p->neu();
$druecke(['MailHookApiKey' => 'key-falsch-0000000000000000000', 'MailHookBase' => '', 'MailHookSecret' => '',
          'MailAddresses' => new ArrayIterator([])]);
pruefe('Falscher Schluessel: Satz mit Rollenhinweis, nichts gespeichert', [$p->status(), IPS_GetProperty($pid, 'MailHookApiKey')],
    ['The Mailgun API key is invalid or lacks permissions (role Developer or Admin needed).', '']);
pruefe('… auch der falsche Schluessel steht nirgends', str_contains(implode("\n", array_merge($p->debug, $p->protokoll)), 'key-falsch'), false);

$p->neu();
$druecke(['MailHookApiKey' => "key abc\r\nX: y", 'MailHookBase' => '', 'MailHookSecret' => '', 'MailAddresses' => new ArrayIterator([])]);
pruefe('Schluessel mit Zeilenumbruch: abgewiesen, bevor etwas hinausgeht', $p->status(),
    'This does not look like a Mailgun API key — please copy it again.');
$p->neu();
$druecke(['MailHookApiKey' => '', 'MailHookBase' => '', 'MailHookSecret' => '', 'MailAddresses' => new ArrayIterator([])]);
pruefe('Ohne Schluessel: Bitte um den Schluessel', $p->status(), 'Please enter the Mailgun API key first.');

$p->connect = '';
$p->neu();
$vorherRufe = count($mg->rufe);
$druecke(['MailHookApiKey' => $KEY, 'MailHookBase' => '', 'MailHookSecret' => '', 'MailAddresses' => new ArrayIterator([])]);
pruefe('Ohne Connect-Adresse: klare Meldung, kein Ruf an Mailgun', [$p->status(), count($mg->rufe) - $vorherRufe],
    ['No Symcon Connect address found — without it Mailgun cannot reach this system. Set up Connect first.', 0]);
$p->connect = 'https://abc123.ipmagic.de';

$p->klemmt = true;
$p->neu();
$druecke(['MailHookApiKey' => $KEY, 'MailHookBase' => 'sandbox12.mailgun.org', 'MailHookSecret' => '',
          'MailAddresses' => new ArrayIterator([])]);
pruefe('Symcon verweigert das Uebernehmen: Nachtrag bestellt, Status bittet um „Uebernehmen"',
    [$p->nachgetragen, str_starts_with($p->status(), 'Mailgun is ready, but Symcon has not taken over the settings yet — please press Apply now.'),
     IPS_GetProperty($pid, 'MailHookApiKey')], [1, true, '']);
pruefe('… das Formular traegt die neuen Werte trotzdem (Uebernehmen speichert sie)', $p->feld('MailHookApiKey') === null
    && $p->feld('MailHookSigningKey') === $mg->signatur && MailgunSetup::tokenGueltig((string)$p->feld('MailHookSecret')), true);
$p->klemmt = false;
IPS_ApplyChanges($pid);   // was der Nachtrag taete
pruefe('… danach ist alles da', IPS_GetProperty($pid, 'MailHookApiKey'), $KEY);

// Feste Adresse bleibt, Domainwechsel zieht die Vorbelegung mit
$mg->domains['us'][] = MailgunAttrappe::domain('mg.example.com', 'unverified');
$setze(['MailHookBase' => 'sandbox12.mailgun.org', 'MailAddresses' => (string)json_encode([
    ['UserID' => 'cc33dd44', 'Name' => 'Tom', 'Address' => 'tom@sandbox12.mailgun.org', 'SenderAllow' => '']])]);
$p->neu();
$offen = $p->pRows();                       // Familie, Lena vorbelegt; Tom fest
$offen[1]['SenderAllow'] = '@kita.example';   // ungespeicherte Aenderung im Formular
$druecke(['MailHookApiKey' => $KEY, 'MailHookBase' => 'Post@MG.example.com', 'MailHookSecret' => '',
          'MailAddresses' => new ArrayIterator($offen)]);
$zeilen = json_decode((string)$p->feld('MailAddresses', 'values'), true);
pruefe('Domainwechsel: feste Adresse im Feld bleibt stehen', IPS_GetProperty($pid, 'MailHookBase'), 'Post@MG.example.com');
pruefe('… Vorbelegtes folgt der neuen Domain, Festes und Getipptes bleibt', [$adressen($zeilen), $zeilen[1]['SenderAllow']],
    [['Post@mg.example.com', 'Post+lena@mg.example.com', 'tom@sandbox12.mailgun.org'], '@kita.example']);
pruefe('… der Status nennt die Adresse auf der alten Domain und die unbestaetigte Domain', [
    str_contains($p->status(), '1 receiving address(es) are not on mg.example.com'),
    str_contains($p->status(), 'not yet verified')], [true, true]);

$p->ki = false;
$p->neu();
$druecke(['MailHookApiKey' => $KEY, 'MailHookBase' => 'mg.example.com', 'MailHookSecret' => '', 'MailAddresses' => new ArrayIterator([])]);
pruefe('Ohne KI-Einwilligung: Hinweis, dass noch nichts angenommen wird',
    str_ends_with($p->status(), 'Mail is only accepted once the AI features are switched on and their privacy notice is accepted.'), true);
$p->ki = true;

$p->pRegionSetzen('eu');
$mg->domains['eu'][] = MailgunAttrappe::domain('mg.example.eu');
$p->neu();
$vorherRufe = count($mg->rufe);
$druecke(['MailHookApiKey' => $KEY, 'MailHookBase' => 'mg.example.eu', 'MailHookSecret' => '', 'MailAddresses' => new ArrayIterator([])]);
pruefe('Gemerkte Region eu: EU zuerst, und die Route entsteht in EU', [$mg->rufe[$vorherRufe]['region'] ?? null, count($mg->routen['eu']),
    $p->pRegion()], ['eu', 1, 'eu']);

// ══ E. Kein Geheimnis im Formular-Code, keine neue Oeffentlichkeit ════════
$quelle = (string)file_get_contents(__DIR__ . '/../libs/MailScan.php');
pruefe('MailHookApiKey wird dem Formular nie zurueckgeschickt', str_contains($quelle, "UpdateFormField('MailHookApiKey'"), false);
pruefe('Kein neues Attribut fuer Geheimnisse: nur die Region kam dazu',
    preg_match_all("/RegisterAttributeString\('MailHook[A-Za-z]*'/", $quelle, $m) === 1 && $m[0][0] === "RegisterAttributeString('MailHookRegion'", true);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
