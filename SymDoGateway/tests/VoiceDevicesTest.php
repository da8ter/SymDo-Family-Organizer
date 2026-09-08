<?php

declare(strict_types=1);

/**
 * Offline-Prüfstand für die Gerätesteuerung des Sprachdialogs: Katalog,
 * Auflöser, Wertabbildung, Rückfrage, Kinder, Kandidaten.
 *
 * Läuft ohne Symcon gegen die Symcon-Stubs (symcon/module-tests), die im
 * Nachbar-Repo unter TileVisu-Raum-Titel-Kachel/tests/stubs liegen — anderer
 * Pfad über SYMCON_STUBS. Die Fälle hier sind die am 07./08.09.2026 LIVE am
 * Prüfstand und am Musterhaus gemessenen; wer den Auflöser ändert, sieht hier
 * zuerst, was kippt.
 *
 *   php SymDoGateway/tests/VoiceDevicesTest.php
 */

$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/../../../TileVisu-Raum-Titel-Kachel/tests/stubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, "Symcon-Stubs nicht gefunden unter $stubs — Pfad über SYMCON_STUBS setzen.\n");
    exit(2);
}
require_once $stubs . '/autoload.php';
require_once __DIR__ . '/../libs/VoiceResolve.php';
require_once __DIR__ . '/../libs/VoiceDevices.php';

IPS\Kernel::reset();

/** Attrappe für Instanzen im Baum (Dummy „Stehlampe" mit Unterinstanzen). */
class PruefInstanz extends IPSModuleStrict
{
}

/**
 * Das Gateway auf das Nötigste reduziert: die beiden Traits plus die Handvoll
 * Methoden aus Voice.php, die sie rufen — hier in Kurzform, ohne Attribute.
 */
class VoiceHarness extends IPSModuleStrict
{
    use VoiceResolve;
    use VoiceDevices;

    private static int $VOICE_MARKE_TTL = 90;

    /** @var list<int> */
    public array $roots = [];
    /** @var list<int> */
    public array $confirm = [];
    /** @var list<string> */
    public array $kinder = ['k1'];
    /** @var array<string,array<string,mixed>> */
    public array $marken = [];
    /** @var list<string> */
    public array $log = [];

    public function Translate(string $Text): string
    {
        static $de = null;
        if ($de === null) {
            $j = json_decode((string)file_get_contents(__DIR__ . '/../locale.json'), true);
            $de = is_array($j) ? ($j['translations']['de'] ?? []) : [];
        }
        return (string)($de[$Text] ?? $Text);
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->log[] = $Message;
        return true;
    }

    protected function SendDebug(string $Message, string $Data, int $Format): bool
    {
        return true;
    }

    // ---- Ersatz für Voice.php / VoiceTools.php ----
    /** Wörtlich aus VoiceTools.php — dort hängt es an 2.800 Zeilen Werkzeugen, die hier nichts zu suchen haben. */
    private function VoiceNorm(string $t): string
    {
        $t = mb_strtolower(trim($t));
        return strtr($t, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }

    private function VoiceErr(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message], 'sag' => $message];
    }

    private function VoiceObjektListe(string $property): array
    {
        return $property === 'VoiceDeviceRoots' ? $this->roots : $this->confirm;
    }

    private function VoiceGeraetMitRueckfrage(int $objectID): bool
    {
        return in_array($objectID, $this->confirm, true);
    }

    private function VoiceIstKind(array $ctx): bool
    {
        return in_array((string)($ctx['userId'] ?? ''), $this->kinder, true);
    }

    private function VoiceMarkeErzeugen(array $ziel, int $ttl): string
    {
        $id = 'marke' . count($this->marken);
        $this->marken[$id] = $ziel;
        return $id;
    }

    private function VoiceMarkeEinloesen(string $id): ?array
    {
        $z = $this->marken[$id] ?? null;
        unset($this->marken[$id]);
        return $z;
    }

    private function VoiceGeraeteDeckelOffen(): bool
    {
        return true;
    }

    private function VoiceGeraeteZaehlen(): void
    {
    }

    private function LoadUsers(): array
    {
        return [['id' => 'k1', 'name' => 'Tim', 'persona' => 'child'], ['id' => 'e1', 'name' => 'Max', 'persona' => 'father']];
    }

    // ---- Zugänge für den Prüfstand ----
    public function pKatalog(): array
    {
        $this->voiceGeraeteMemo = null;
        return $this->VoiceGeraeteKatalog();
    }

    public function pSteuern(array $args, string $userId = ''): array
    {
        $this->voiceGeraeteMemo = null;
        return $this->VoiceToolGeraetSteuern($args + ['raum' => null, 'id' => null, 'marke' => null], ['userId' => $userId, 'defaults' => []], 'geraet');
    }

    public function pSzene(array $args, string $userId = ''): array
    {
        $this->voiceGeraeteMemo = null;
        return $this->VoiceToolGeraetSteuern($args + ['raum' => null, 'id' => null, 'marke' => null], ['userId' => $userId, 'defaults' => []], 'szene');
    }

    public function pLesen(array $args): array
    {
        $this->voiceGeraeteMemo = null;
        return $this->VoiceToolGeraeteLesen($args + ['geraet' => null, 'raum' => null, 'filter' => 'alle', 'id' => null], ['userId' => '', 'defaults' => []]);
    }

    public function pSuchen(array $args): array
    {
        $this->voiceGeraeteMemo = null;
        return $this->VoiceToolGeraeteSuchen($args + ['suche' => null, 'raum' => null], ['userId' => '', 'defaults' => []]);
    }

    public function pPunkte(string $such, string $kand): int
    {
        return $this->VoiceGeraetPunkte($this->VoiceNorm($such), $this->VoiceNorm($kand));
    }
}

// ─────────────────────────── Fixture-Baum ───────────────────────────
const PRES_SWITCH  = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
const PRES_SLIDER  = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
const PRES_ENUM    = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
const PRES_SHUTTER = '{6075FC22-69AF-B110-3749-C24138883082}';
const PRES_COLOR   = '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}';

$aktion = IPS_CreateScript(0);
IPS_SetName($aktion, 'Aktion');
IPS_SetScriptContent($aktion, 'SetValue($_IPS[\'VARIABLE\'], $_IPS[\'VALUE\']);');

function kat(string $name, int $eltern): int
{
    $id = IPS_CreateCategory();
    IPS_SetParent($id, $eltern);
    IPS_SetName($id, $name);
    return $id;
}
function inst(string $name, int $eltern): int
{
    $id = IPS\ObjectManager::registerObject(1);
    IPS\InstanceManager::createInstance($id, ['ModuleID' => '{00000000-0000-0000-0000-00000000DE01}', 'ModuleName' => 'PruefInstanz', 'ModuleType' => 3, 'Class' => 'PruefInstanz']);
    IPS_SetParent($id, $eltern);
    IPS_SetName($id, $name);
    return $id;
}
function var_(string $name, int $eltern, int $typ, array $pres, mixed $wert, bool $aktion = true): int
{
    global $aktion_id;
    $id = IPS_CreateVariable($typ);
    IPS_SetParent($id, $eltern);
    IPS_SetName($id, $name);
    IPS_SetVariableCustomPresentation($id, $pres);
    if ($aktion) {
        IPS_SetVariableCustomAction($id, $aktion_id);
    }
    SetValue($id, $wert);
    return $id;
}
$aktion_id = $aktion;
$schalter = fn(string $n, int $e, bool $w = false, bool $akt = true) => var_($n, $e, 0, ['PRESENTATION' => PRES_SWITCH, 'CAPTION_ON' => 'An', 'CAPTION_OFF' => 'Aus'], $w, $akt);

$haus = kat('Haus', 0);
$eg   = kat('Erdgeschoss', $haus);
$kue  = kat('Küche', $eg);
$kueDecke = $schalter('Deckenlampe', $kue);
$dunst = var_('Dunstabzug', $kue, 1, ['PRESENTATION' => PRES_ENUM, 'OPTIONS' => json_encode([
    ['Value' => 0, 'Caption' => 'Aus'], ['Value' => 1, 'Caption' => 'Stufe 1'], ['Value' => 2, 'Caption' => 'Stufe 2'],
])], 0);
$guteNacht = IPS_CreateScript(0);
IPS_SetParent($guteNacht, $kue);
IPS_SetName($guteNacht, 'Gute Nacht');
$wz   = kat('Wohnzimmer', $eg);
$steh = inst('Stehlampe', $wz);
$stehSchalter = $schalter('Wert', inst('Schalter', $steh));
$stehHell = var_('Wert', inst('Helligkeit', $steh), 1, ['PRESENTATION' => PRES_SLIDER, 'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 5, 'SUFFIX' => ' %'], 0);
$led = inst('LED-Streifen', $kue);   // in der Küche, damit „Schalter im Wohnzimmer" eindeutig bleibt
$ledSchalter = $schalter('Wert', inst('Schalter', $led));
$ledFarbe = var_('Wert', inst('Farbe', $led), 1, ['PRESENTATION' => PRES_COLOR], 0xFF8800);
$ledCt = var_('Wert', inst('Farbtemperatur', $led), 1, ['PRESENTATION' => PRES_SLIDER, 'MIN' => 2000, 'MAX' => 6500, 'STEP_SIZE' => 100, 'SUFFIX' => ' K', 'USAGE_TYPE' => 1, 'GRADIENT_TYPE' => 2], 4000);
$nachtFarbe = var_('Nachtlicht Farbe', $kue, 1, ['PRESENTATION' => PRES_COLOR], 0x0000FF);
$tv   = $schalter('Fernseher', $wz);
$heiz = var_('Heizung', $wz, 2, ['PRESENTATION' => PRES_SLIDER, 'MIN' => 5, 'MAX' => 30, 'STEP_SIZE' => 0.5, 'DIGITS' => 1, 'SUFFIX' => ' °C'], 21.0);
$rollo = var_('Rollladen', $wz, 1, ['PRESENTATION' => PRES_SHUTTER, 'MIN' => 0, 'MAX' => 100, 'SUFFIX' => ' %'], 0);
$melder = $schalter('Bewegungsmelder', $wz, false, false);   // nur lesen
$badEg = kat('Bad', $eg);
$badEgDecke = $schalter('Deckenlampe', $badEg);
$og   = kat('Obergeschoss', $haus);
$badOg = kat('Bad', $og);
$badOgDecke = $schalter('Deckenlampe', $badOg);
$technik = kat('Technik', $haus);
IPS_SetHidden($technik, true);
$geheim = $schalter('Geheim', $technik);
// Außerhalb der Wurzel, per Link in der Küche erreichbar
$aussen = kat('Aussen', 0);
$terrasse = $schalter('Terrassenlicht Aussen', $aussen);
$link = IPS_CreateLink();
IPS_SetParent($link, $kue);
IPS_SetName($link, 'Terrassenstrahler');   // kein „licht" im Namen, sonst träfe „Licht in der Küche" berechtigt zwei
IPS_SetLinkTargetID($link, $terrasse);
$direktAussen = $schalter('Kaffeemaschine', $aussen);   // NICHT erreichbar

// ─────────────────────────── Harness ───────────────────────────
$hid = IPS\ObjectManager::registerObject(1);
ob_start();
IPS\InstanceManager::createInstance($hid, ['ModuleID' => '{00000000-0000-0000-0000-00000000DE02}', 'ModuleName' => 'VoiceHarness', 'ModuleType' => 3, 'Class' => 'VoiceHarness']);
ob_end_clean();
/** @var VoiceHarness $h */
$h = IPS\InstanceManager::getInstanceInterface($hid);
$h->roots = [$haus];
$h->confirm = [$tv];

// ─────────────────────────── Prüfrahmen ───────────────────────────
$fehler = 0;
function pruefe(string $name, bool $ok, string $ist = ''): void
{
    global $fehler;
    if (!$ok) {
        $fehler++;
    }
    echo($ok ? 'OK   ' : 'FEHL ') . $name . ($ok ? '' : '   ist: ' . $ist) . "\n";
}
$code = static fn(array $r): string => (string)($r['error']['code'] ?? ($r['ok'] ? 'ok' : '?'));
$kurz = static fn(array $r): string => mb_substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 220);

// ---- Katalog ----
$kat = $h->pKatalog();
$ids = array_column($kat, 'id');
$byId = array_column($kat, null, 'id');
pruefe('Katalog: Versteckter Teilbaum fehlt', !in_array($geheim, $ids, true));
pruefe('Katalog: Objekt außerhalb der Wurzel fehlt', !in_array($direktAussen, $ids, true));
pruefe('Katalog: Link-Ziel unter Link-Namen dabei', in_array($terrasse, $ids, true) && ($byId[$terrasse]['name'] ?? '') === 'Terrassenstrahler' && ($byId[$terrasse]['raum'] ?? '') === 'Küche', $kurz($byId[$terrasse] ?? []));
pruefe('Katalog: Hülle im Namen („Stehlampe Schalter")', ($byId[$stehSchalter]['name'] ?? '') === 'Stehlampe Schalter' && in_array('Stehlampe', $byId[$stehSchalter]['alias'] ?? [], true), $kurz($byId[$stehSchalter] ?? []));
pruefe('Katalog: Pfad mit Etage und Raum', ($byId[$badOgDecke]['pfad'] ?? '') === 'Obergeschoss › Bad › Deckenlampe', (string)($byId[$badOgDecke]['pfad'] ?? ''));
pruefe('Katalog: Skript als Eintrag', in_array($guteNacht, $ids, true) && ($byId[$guteNacht]['typ'] ?? '') === 'skript');
pruefe('Katalog: Nur-lesen-Variable ohne Aktion', isset($byId[$melder]) && $byId[$melder]['akt'] === false);

// ---- Punkte (Regeln aus dem Musterhaus) ----
pruefe('Punkte: „heizung" ist kein Präfix-Treffer für „heizungsanlage"', $h->pPunkte('heizungsanlage', 'heizung') < 70, (string)$h->pPunkte('heizungsanlage', 'heizung'));
pruefe('Punkte: reine Ähnlichkeit bleibt unter 70 (stehlicht~licht)', $h->pPunkte('stehlicht', 'licht') < 70, (string)$h->pPunkte('stehlicht', 'licht'));
pruefe('Punkte: Tippfehler-Präfix „deckenlamp" trifft', $h->pPunkte('deckenlamp', 'deckenlampe') >= 80, (string)$h->pPunkte('deckenlamp', 'deckenlampe'));

// ---- Auflösen und Schalten ----
$r = $h->pSteuern(['geraet' => 'Deckenlampe', 'raum' => 'Küche', 'wert' => 'an']);
pruefe('Steuern: Deckenlampe Küche an', $code($r) === 'ok' && GetValue($kueDecke) === true, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Deckenlampe', 'raum' => 'Küche', 'wert' => 'an']);
pruefe('Steuern: „war schon" bei gleichem Zustand', $code($r) === 'ok' && str_contains((string)$r['sag'], 'schon'), $kurz($r));
$r = $h->pSteuern(['geraet' => 'Deckenlamp', 'raum' => 'Küche', 'wert' => 'umschalten']);
pruefe('Steuern: Tippfehler + umschalten', $code($r) === 'ok' && GetValue($kueDecke) === false, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Licht', 'raum' => 'Küche', 'wert' => 'an']);
pruefe('Steuern: Synonym Licht → Deckenlampe', $code($r) === 'ok' && GetValue($kueDecke) === true, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Deckenlampe', 'raum' => 'Bad', 'wert' => 'an']);
pruefe('Steuern: zwei Bäder → mehrdeutig mit Kandidaten und Weg', $code($r) === 'mehrdeutig' && count($r['kandidaten'] ?? []) >= 2 && str_contains((string)$r['sag'], 'Obergeschoss'), $kurz($r));
$r = $h->pSteuern(['geraet' => 'Deckenlampe', 'raum' => 'Bad oben', 'wert' => 'an']);
pruefe('Steuern: „Bad oben" → Obergeschoss eindeutig', $code($r) === 'ok' && GetValue($badOgDecke) === true && GetValue($badEgDecke) === false, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Deckenlampe', 'raum' => 'Erdgeschoss', 'wert' => 'an']);
pruefe('Steuern: „Erdgeschoss" allein ist zweideutig (Küche und Bad)', $code($r) === 'mehrdeutig' && count($r['kandidaten'] ?? []) >= 2, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Deckenlampe', 'raum' => 'Erdgeschoss Bad', 'wert' => 'an']);
pruefe('Steuern: „Erdgeschoss Bad" → Bad unten', $code($r) === 'ok' && GetValue($badEgDecke) === true && GetValue($kueDecke) === true, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Deckenlampe', 'raum' => 'Bad unten', 'wert' => 'aus']);
pruefe('Steuern: „Bad unten" aus', $code($r) === 'ok' && GetValue($badEgDecke) === false, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Deckenlampe', 'wert' => 'an', 'id' => $badOgDecke]);
pruefe('Steuern: per id aus dem Katalog', $code($r) === 'ok', $kurz($r));
$r = $h->pSteuern(['geraet' => 'x', 'wert' => 'an', 'id' => $direktAussen]);
pruefe('Steuern: fremde id abgelehnt', $code($r) === 'nicht_gefunden', $kurz($r));
$r = $h->pSteuern(['geraet' => 'x', 'wert' => 'an', 'id' => $geheim]);
pruefe('Steuern: id aus verstecktem Teilbaum abgelehnt', $code($r) === 'nicht_gefunden', $kurz($r));
$r = $h->pSteuern(['geraet' => 'Kaffeemaschine', 'wert' => 'an']);
pruefe('Steuern: außerhalb der Wurzel → nicht gefunden', $code($r) === 'nicht_gefunden' && GetValue($direktAussen) === false, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Terrassenstrahler', 'wert' => 'an']);
pruefe('Steuern: über Link erreichbar', $code($r) === 'ok' && GetValue($terrasse) === true, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Bewegungsmelder', 'wert' => 'an']);
pruefe('Steuern: Sensor ohne Aktion → nicht_steuerbar', $code($r) === 'nicht_steuerbar', $kurz($r));

// ---- Hülle und Wert entscheidet ----
$r = $h->pSteuern(['geraet' => 'Stehlampe', 'wert' => 'an']);
pruefe('Hülle: „Stehlampe an" → Schalter', $code($r) === 'ok' && GetValue($stehSchalter) === true && GetValue($stehHell) === 0, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Stehlampe', 'wert' => '42 Prozent']);
pruefe('Hülle: „Stehlampe 42 Prozent" → Helligkeit, auf 40 gerastet', $code($r) === 'ok' && GetValue($stehHell) === 40, $kurz($r) . ' hell=' . GetValue($stehHell));
$r = $h->pSteuern(['geraet' => 'Schalter', 'raum' => 'Wohnzimmer', 'wert' => 'aus']);
pruefe('Hülle: Name der Unterinstanz allein', $code($r) === 'ok' && GetValue($stehSchalter) === false, $kurz($r));

// ---- Werte ----
$r = $h->pSteuern(['geraet' => 'Helligkeit', 'wert' => '150']);
pruefe('Wert: außerhalb wird abgelehnt, nicht geklemmt', $code($r) === 'ausserhalb' && GetValue($stehHell) === 40, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Helligkeit', 'wert' => 'voll']);
pruefe('Wert: „voll" → Maximum', $code($r) === 'ok' && GetValue($stehHell) === 100, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Heizung', 'wert' => '21,3 Grad']);
pruefe('Wert: Thermostat 21,3 → 21,5 (Schritt 0,5)', $code($r) === 'ok' && abs(GetValue($heiz) - 21.5) < 1e-6, $kurz($r) . ' heiz=' . GetValue($heiz));
$r = $h->pSteuern(['geraet' => 'Heizung', 'wert' => 'an']);
pruefe('Wert: „an" am °C-Regler ist kein Wert', $code($r) !== 'ok' && abs(GetValue($heiz) - 21.5) < 1e-6, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Dunstabzug', 'wert' => 'Stufe 2']);
pruefe('Wert: Auswahl über Beschriftung', $code($r) === 'ok' && GetValue($dunst) === 2, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Dunstabzug', 'wert' => 'turbo']);
pruefe('Wert: unbekannte Auswahl nennt die Optionen', $code($r) !== 'ok' && str_contains((string)$r['sag'], 'Stufe 1'), $kurz($r));
$r = $h->pSteuern(['geraet' => 'Rollladen', 'wert' => 'runter']);
$runter = GetValue($rollo);
$r2 = $h->pSteuern(['geraet' => 'Rollladen', 'wert' => 'hoch']);
pruefe('Wert: Rollladen runter/hoch sind die beiden Enden', $code($r) === 'ok' && $code($r2) === 'ok' && $runter !== GetValue($rollo) && in_array($runter, [0, 100], true) && in_array(GetValue($rollo), [0, 100], true), $kurz($r) . ' runter=' . $runter . ' hoch=' . GetValue($rollo));

// ---- Farbe und Farbtemperatur ----
$r = $h->pSteuern(['geraet' => 'LED-Streifen', 'wert' => 'rot']);
pruefe('Farbe: „LED-Streifen rot" → Farbe, nicht Schalter', $code($r) === 'ok' && GetValue($ledFarbe) === 0xFF0000 && GetValue($ledSchalter) === false, $kurz($r));
$r = $h->pSteuern(['geraet' => 'LED-Streifen', 'wert' => 'an']);
pruefe('Farbe: „an" bleibt beim Schalter, färbt nichts', $code($r) === 'ok' && GetValue($ledSchalter) === true && GetValue($ledFarbe) === 0xFF0000, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Nachtlicht Farbe', 'wert' => 'an']);
pruefe('Farbe: „an" an reiner Farbvariable fragt nach einer Farbe', $code($r) === 'ungueltiger_wert' && GetValue($nachtFarbe) === 0x0000FF, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Nachtlicht Farbe', 'wert' => '#00ff88']);
pruefe('Farbe: Hex-Wert', $code($r) === 'ok' && GetValue($nachtFarbe) === 0x00FF88, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Nachtlicht Farbe', 'wert' => 'türkiss']);
pruefe('Farbe: Tippfehler „türkiss"', $code($r) === 'ok' && GetValue($nachtFarbe) === 0x40E0D0, $kurz($r));
$r = $h->pSteuern(['geraet' => 'LED-Streifen', 'wert' => 'warmweiß']);
pruefe('Farbtemperatur: „warmweiß" → 2700 K, nicht die Farbe', $code($r) === 'ok' && GetValue($ledCt) === 2700 && GetValue($ledFarbe) === 0xFF0000, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Farbtemperatur', 'wert' => 'kälter']);
pruefe('Farbtemperatur: „kälter" = +500 K', $code($r) === 'ok' && GetValue($ledCt) === 3200, $kurz($r) . ' ct=' . GetValue($ledCt));
SetValue($ledCt, 2000);
$r = $h->pSteuern(['geraet' => 'Farbtemperatur', 'wert' => 'wärmer']);
pruefe('Farbtemperatur: „wärmer" am Anschlag klemmt statt abzulehnen', $code($r) === 'ok' && GetValue($ledCt) === 2000, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Farbtemperatur', 'wert' => '1000 Kelvin']);
pruefe('Farbtemperatur: Zahl außerhalb wird abgelehnt', $code($r) === 'ausserhalb', $kurz($r));
$r = $h->pSteuern(['geraet' => 'Helligkeit', 'wert' => 'heller']);
pruefe('Dimmer: „heller" = +10 %', $code($r) === 'ok' && GetValue($stehHell) === 100, $kurz($r) . ' hell=' . GetValue($stehHell));
$r = $h->pLesen(['geraet' => 'LED-Streifen Farbe']);
pruefe('Farbe lesen: Name statt Zahl', $code($r) === 'ok' && ($r['wert'] ?? '') === 'Rot', $kurz($r));
$r = $h->pLesen(['geraet' => 'Farbtemperatur']);
pruefe('Farbtemperatur lesen: Kelvin mit Weißton', $code($r) === 'ok' && str_contains((string)$r['wert'], 'Warmweiß'), $kurz($r));
$r = $h->pSteuern(['geraet' => 'Temperatur', 'raum' => 'Wohnzimmer', 'wert' => '22 Grad']);
pruefe('Synonym: „Temperatur" meint die Heizung', $code($r) === 'ok' && abs(GetValue($heiz) - 22.0) < 1e-6, $kurz($r));

// ---- Rückfrage, Marke, Kinder ----
$r = $h->pSteuern(['geraet' => 'Fernseher', 'wert' => 'an'], 'e1');
pruefe('Rückfrage: Fernseher verlangt Bestätigung, nichts geschaltet', $code($r) === 'bestaetigung_noetig' && ($r['marke'] ?? '') !== '' && GetValue($tv) === false, $kurz($r));
$marke = (string)($r['marke'] ?? '');
$r = $h->pSteuern(['geraet' => 'Fernseher', 'wert' => 'an', 'marke' => $marke], 'k1');
pruefe('Rückfrage: fremde Person löst nicht ein', $code($r) === 'nicht_erlaubt' && GetValue($tv) === false, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Fernseher', 'wert' => 'an'], 'e1');
$marke = (string)($r['marke'] ?? '');
$r = $h->pSteuern(['geraet' => 'Fernseher', 'wert' => 'an', 'marke' => $marke], 'e1');
pruefe('Rückfrage: Marke eingelöst → geschaltet', $code($r) === 'ok' && GetValue($tv) === true, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Fernseher', 'wert' => 'aus', 'marke' => $marke], 'e1');
pruefe('Rückfrage: dieselbe Marke zweimal → abgelaufen', $code($r) === 'marke_abgelaufen' && GetValue($tv) === true, $kurz($r));
$r = $h->pSteuern(['geraet' => 'Fernseher', 'wert' => 'aus'], 'k1');
pruefe('Kind: Rückfrage-Gerät verboten', $code($r) === 'nicht_erlaubt', $kurz($r));
$r = $h->pSteuern(['geraet' => 'Deckenlampe', 'raum' => 'Küche', 'wert' => 'aus'], 'k1');
pruefe('Kind: freigegebenes Gerät erlaubt', $code($r) === 'ok' && GetValue($kueDecke) === false, $kurz($r));
pruefe('Protokoll: Schaltungen im Meldungsfenster', count(array_filter($h->log, static fn(string $z): bool => str_contains($z, 'geschaltet'))) >= 5, (string)count($h->log));

// ---- Szene/Skript ----
$r = $h->pSzene(['name' => 'Gute Nacht']);
pruefe('Skript: startet', $code($r) === 'ok' && isset($r['skript']), $kurz($r));

// ---- Lesen und Suchen ----
$r = $h->pLesen(['raum' => 'Wohnzimmer']);
pruefe('Lesen: Raum listet Geräte', $code($r) === 'ok' && count($r['geraete'] ?? []) >= 5, $kurz($r));
$r = $h->pLesen(['geraet' => 'Heizung']);
pruefe('Lesen: Zustand eines Geräts („Heizung" trifft nicht die Farbtemperatur)', $code($r) === 'ok' && str_contains((string)$r['wert'], '22,0'), $kurz($r));
$r = $h->pLesen(['id' => $terrasse]);
pruefe('Lesen: per id', $code($r) === 'ok' && ($r['geraet'] ?? '') === 'Küche Terrassenstrahler', $kurz($r));
$r = $h->pSuchen(['suche' => 'Stehlampe']);
$pfade = implode('|', array_column($r['kandidaten'] ?? [], 'pfad'));
pruefe('Suchen: Kandidaten mit Pfad, Typ und Werten', $code($r) === 'ok' && count($r['kandidaten'] ?? []) >= 2 && str_contains($pfade, 'Stehlampe › Helligkeit') && isset($r['kandidaten'][0]['werte']), $kurz($r));
$r = $h->pSuchen(['suche' => 'Lampe', 'raum' => 'Obergeschoss']);
pruefe('Suchen: Etage im Raumfeld hebt die OG-Lampe nach vorn', $code($r) === 'ok' && (int)($r['kandidaten'][0]['id'] ?? 0) === $badOgDecke, $kurz($r));

echo "\n" . ($fehler === 0 ? 'Alle Fälle bestanden.' : "$fehler Fall/Fälle FEHLGESCHLAGEN.") . "\n";
exit($fehler === 0 ? 0 : 1);
