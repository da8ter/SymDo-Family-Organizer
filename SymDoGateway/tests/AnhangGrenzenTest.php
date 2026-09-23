<?php

declare(strict_types=1);

/**
 * Pruefstand: die Experteneinstellungen fuer Anhaenge (24.09.2026).
 *
 *  - AnhangGrenzenCalc::Wirksam: ohne Einstellung genau die frueheren
 *    Konstanten; alles ausserhalb des Bereichs wird begrenzt.
 *  - MailRankAttachments liest die Einstellungen wirklich (Harness um MailFetch).
 *  - Das Formular: das Panel steht im KI-Bereich, jede Eigenschaft ist
 *    registriert, jede Beschriftung uebersetzt.
 *
 *   php SymDoGateway/tests/AnhangGrenzenTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/MailFetch.php';
require_once __DIR__ . '/../libs/Originale.php';

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
    printf("%-4s %-76s%s\n", $ok ? 'OK' : 'FEHL', $name, $ok ? '' : "\n     ist:  $a\n     soll: $b");
}

// ── Wirksam ────────────────────────────────────────────────────────────────
$w = AnhangGrenzenCalc::Wirksam([]);
pruefe('Ohne Einstellung: die frueheren Konstanten der Mail',
    [$w['imageMinBytes'], $w['imageMinPixel'], $w['maxCount'], $w['maxFileB64'], $w['maxTotalB64']],
    [40 * 1024, 600, 5, 8 * 1024 * 1024, 12 * 1024 * 1024]);
pruefe('… die Layout-Namen wie bisher',
    $w['decoNames'], ['image00', 'logo', 'signatur', 'signature', 'icon', 'spacer', 'footer', 'unnamed', 'banner']);
pruefe('… Klassenseiten 6 MiB (vorher 8 000 000 Base64 — bewusst 5 % mehr), Briefpapier ab 2, Deckel 250 MiB',
    [$w['eduTotalB64'], $w['decoRepeat'], $w['capBytes']], [8 * 1024 * 1024, 2, 250 * 1024 * 1024]);
$g = AnhangGrenzenCalc::Wirksam(['AttImageMinKB' => -5, 'AttImageMinPixel' => 99999, 'AttMaxCount' => 0,
    'AttMaxFileMB' => 'viel', 'OrigDecoRepeat' => 1, 'OrigCapMB' => 10]);
pruefe('Begrenzt: unter 0, ueber dem Hoechstwert, Unsinn = Standard, 1 wird 2, Deckel mindestens 50 MB',
    [$g['imageMinBytes'], $g['imageMinPixel'], $g['maxCount'], $g['maxFileB64'], $g['decoRepeat'], $g['capBytes']],
    [0, 4000, 1, 8 * 1024 * 1024, 2, 50 * 1024 * 1024]);
pruefe('Briefpapier-Regel abschaltbar (0)', AnhangGrenzenCalc::Wirksam(['OrigDecoRepeat' => 0])['decoRepeat'], 0);
pruefe('Namen: getrimmt, klein, ohne Doppelte, zu lange fallen, leeres Feld heisst keine Regel',
    [AnhangGrenzenCalc::Namen(" Logo ; LOGO,\nBanner,  ," . str_repeat('x', 31)), AnhangGrenzenCalc::Namen('')],
    [['logo', 'banner'], []]);
pruefe('Namen: hoechstens 30', count(AnhangGrenzenCalc::Namen(implode(',', array_map(static fn($i) => 'n' . $i, range(1, 40))))), 30);
pruefe('Base64-Laenge', [AnhangGrenzenCalc::Base64Laenge(3), AnhangGrenzenCalc::Base64Laenge(4), AnhangGrenzenCalc::Base64Laenge(6 * 1048576)],
    [4, 8, 8388608]);

// ── MailRankAttachments liest die Einstellungen ────────────────────────────
final class RangProbe
{
    use MailFetch;
    public array $roh = [];
    private function AnhangGrenzen(): array { return AnhangGrenzenCalc::Wirksam($this->roh); }
    private function SendDebug(string $a, string $b, int $c): void {}
    public function pRang(array $teile): array
    {
        return (array)(new ReflectionMethod(self::class, 'MailRankAttachments'))->invoke($this, $teile);
    }
}
$teil = static fn(string $name, string $type, string $sub, int $kb): array =>
    ['part' => '2', 'name' => $name, 'type' => $type, 'subtype' => $sub, 'size' => $kb * 1024, 'inline' => false, 'cid' => ''];
$teile = [$teil('Brief.pdf', 'application', 'pdf', 300), $teil('Scan.jpg', 'image', 'jpeg', 30),
          $teil('Seite.jpg', 'image', 'jpeg', 200), $teil('Kopfzeile.jpg', 'image', 'jpeg', 120)];
$r = new RangProbe();
pruefe('Standard: das 30-KB-Bild faellt (Signaturlogo-Groesse)', array_column($r->pRang($teile), 'name'),
    ['Brief.pdf', 'Seite.jpg', 'Kopfzeile.jpg']);
$r->roh = ['AttImageMinKB' => 20];
pruefe('AttImageMinKB = 20: es kommt durch', in_array('Scan.jpg', array_column($r->pRang($teile), 'name'), true), true);
$r->roh = ['AttMaxCount' => 2];
pruefe('AttMaxCount = 2: nur zwei', count($r->pRang($teile)), 2);
$r->roh = ['AttDecoNames' => 'kopfzeile'];
pruefe('Eigener Layout-Name: das Bild rutscht ans Ende und traegt den Merker',
    [array_column($r->pRang($teile), 'name'), array_column($r->pRang($teile), 'deko')],
    [['Brief.pdf', 'Seite.jpg', 'Kopfzeile.jpg'], [false, false, true]]);
$r->roh = ['AttMaxFileMB' => 1];
pruefe('AttMaxFileMB = 1: grosse Dateien fallen vor dem Laden',
    array_column($r->pRang([$teil('Riesig.pdf', 'application', 'pdf', 2000), $teil('Klein.pdf', 'application', 'pdf', 100)]), 'name'),
    ['Klein.pdf']);

// ── Formular ───────────────────────────────────────────────────────────────
final class PanelProbe extends IPSModuleStrict
{
    use Originale;
    protected function getTime(): int { return time(); }
    public function Translate(string $Text): string { return $Text; }
    public function pAnlegen(): void { (new ReflectionMethod(self::class, 'OriginaleCreate'))->invoke($this); }
    public function pPanel(): array { return (array)(new ReflectionMethod(self::class, 'GetExpertenPanel'))->invoke($this); }
}
$modulDir = rtrim(sys_get_temp_dir(), '/\\') . '/symdo_panel_' . bin2hex(random_bytes(4));
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($modulDir)));
@mkdir($modulDir . '/Panel', 0700, true);
file_put_contents($modulDir . '/Panel/module.json', (string)json_encode(['id' => '{8E1C2B7A-0000-4000-8000-00000000A1B2}',
    'name' => 'PanelProbe', 'type' => 3, 'vendor' => 'Stephan Sprick', 'aliases' => [], 'parentRequirements' => [],
    'childRequirements' => [], 'implemented' => [], 'prefix' => 'PANELPROBE']));
file_put_contents($modulDir . '/Panel/module.php', "<?php\n");
IPS\ModuleLoader::loadSingleModule($modulDir . '/Panel', '{PANEL-PROBE-LIB}');
$pid = IPS_CreateInstance('{8E1C2B7A-0000-4000-8000-00000000A1B2}');
$panelProbe = IPS\InstanceManager::getInstanceInterface($pid);
$vorher = $panelProbe->pPanel();
pruefe('Vor dem Neuladen: nur ein Hinweis, keine Felder ohne Eigenschaft',
    [count($vorher['items'] ?? []), $vorher['items'][0]['type'] ?? null], [1, 'Label']);
$panelProbe->pAnlegen();
$panel = $panelProbe->pPanel();
$felder = [];
$texte = [$panel['caption']];
foreach ($panel['items'] ?? [] as $e) {
    if (isset($e['name'])) {
        $felder[] = $e['name'];
    }
    if (isset($e['caption'])) {
        $texte[] = $e['caption'];
    }
}
pruefe('Jede Eigenschaft hat ihr Feld', $felder, array_keys(AnhangGrenzenCalc::STANDARD));
pruefe('Die Zahlenfelder tragen den Bereich aus BEREICH',
    array_values(array_map(static fn($e) => [$e['name'], $e['minimum'], $e['maximum']],
        array_filter($panel['items'], static fn($e) => ($e['type'] ?? '') === 'NumberSpinner'))),
    array_values(array_map(static fn($k, $b) => [$k, $b[0], $b[1]], array_keys(AnhangGrenzenCalc::BEREICH), AnhangGrenzenCalc::BEREICH)));
$de = json_decode((string)file_get_contents(__DIR__ . '/../locale.json'), true)['translations']['de'];
$ohne = array_values(array_filter($texte, static function (string $t) use ($de): bool {
    // Die Standardwerte sind per sprintf eingesetzt — gesucht wird die Vorlage.
    $vorlage = preg_replace('/\(default \d+, 0 = off\)/', '(default %s, 0 = off)', $t);
    $vorlage = preg_replace('/\(default \d+\)/', '(default %s)', (string)$vorlage);
    return !isset($de[$t]) && !isset($de[(string)$vorlage]);
}));
pruefe('Jede Beschriftung ist uebersetzt (de)', $ohne, []);
$modul = (string)file_get_contents(__DIR__ . '/../module.php');
pruefe('Das Panel haengt im KI-Bereich, direkt hinter den Mail-Einstellungen',
    str_contains($modul, "\$this->AppendFormItem(\$elements, 'AiPanel', \$this->GetMailFormElements());\n"
        . "            \$this->AppendFormItem(\$elements, 'AiPanel', \$this->GetExpertenPanel());"), true);

printf("\n%d Zusicherungen, %d Abweichung(en).\n", $anzahl, $fehler);
exit($fehler === 0 ? 0 : 1);
