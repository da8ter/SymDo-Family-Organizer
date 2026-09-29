<?php

declare(strict_types=1);

/**
 * Probe-Instanz mit dem Trait MailScan — gemeinsam fuer MailAdressenTest und
 * MailgunEinrichtungTest.
 *
 * Echte Eigenschaften und Attribute aus MailCreate, echtes Uebernehmen der
 * Attrappen (gestagt bis ApplyChanges, IPS_GetProperty zeigt den AKTIVEN
 * Stand). Statt curl bekommt MailgunSetup die Attrappe aus MailgunAttrappe.php.
 * Erwartet geladene Symcon-Attrappen (autoload.php und Kernel::reset).
 */

require_once __DIR__ . '/../libs/MailAnalyseCalc.php';
require_once __DIR__ . '/../../libs/AiJobStore.php';
require_once __DIR__ . '/../libs/MailgunSetup.php';
require_once __DIR__ . '/../libs/MailScan.php';
require_once __DIR__ . '/MailgunAttrappe.php';

final class MailgunProbe extends IPSModuleStrict
{
    use MailScan;

    private const HOOK_PATH   = 'lists/app';
    private const API_VERSION = 1;

    public array $users = [];
    public string $connect = 'https://abc123.ipmagic.de';
    public ?MailgunAttrappe $mailgun = null;
    /** @var list<array{0: string, 1: string, 2: mixed}> */
    public array $formular = [];
    public array $debug = [];
    public array $protokoll = [];
    public int $nachgetragen = 0;
    /** Wie ein Symcon, das das Uebernehmen verweigert. */
    public bool $klemmt = false;
    public bool $ki = true;

    public function Create(): void
    {
        parent::Create();
        $this->MailCreate();
    }
    public function ApplyChanges(): void
    {
        if (!$this->klemmt) {
            parent::ApplyChanges();
        }
    }
    public function RequestAction(string $Ident, mixed $Value): void
    {
        // In Symcon ist jeder Aufruf ein frischer PHP-Lauf; der Zwischenspeicher
        // der Konfiguration (MailProp) lebt nur so lange. Hier lebt das Objekt weiter.
        $this->mailConfigCache = null;
        $this->MailRequestAction($Ident, $Value);
    }
    protected function getTime(): int { return time(); }
    public int $uebersetzt = 0;
    public function Translate(string $Text): string { $this->uebersetzt++; return $Text; }
    protected function UpdateFormField(string $Field, string $Parameter, mixed $Value): bool
    {
        $this->formular[] = [$Field, $Parameter, $Value];
        return true;
    }
    protected function SendDebug(string $Message, string $Data, int $Format): bool
    {
        $this->debug[] = $Message . ' ' . $Data;
        return true;
    }
    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->protokoll[] = $Message;
        return true;
    }
    private function LoadUsers(): array { return $this->users; }
    private function GetConnectUrl(): string { return $this->connect; }
    private function AiProp(string $name): mixed { return $this->ki; }
    private function AiPrivacyAccepted(): bool { return $this->ki; }
    private function UebernehmenNachtragen(): void { $this->nachgetragen++; }
    private function MailgunNeu(string $schluessel): MailgunSetup
    {
        return new MailgunSetup($schluessel, $this->mailgun);
    }

    public function pRows(): array { $this->mailConfigCache = null; return $this->MailAddressRows(); }
    public function pMap(): array { $this->mailConfigCache = null; return $this->MailAddressMap(); }
    public function pIntake(): array { $this->mailConfigCache = null; return $this->MailIntakePublic(); }
    public function pJudge(string $an): array
    {
        $this->mailConfigCache = null;
        return $this->MailJudge(['Recipient' => $an, 'SenderAddress' => 'sekretariat@schule.example', 'Subject' => 'Elternbrief']);
    }
    public function pRegion(): string { return $this->ReadAttributeString('MailHookRegion'); }
    public function pRegionSetzen(string $r): void { $this->WriteAttributeString('MailHookRegion', $r); }
    /** Der letzte Wert, den das Formular fuer ein Feld bekam. */
    public function feld(string $name, string $was = 'value'): mixed
    {
        $wert = null;
        foreach ($this->formular as [$f, $p, $v]) {
            if ($f === $name && $p === $was) {
                $wert = $v;
            }
        }
        return $wert;
    }
    public function status(): string { return (string)$this->feld('MailHookStatus', 'caption'); }
    public function neu(): void
    {
        $this->formular = $this->debug = $this->protokoll = [];
        $this->nachgetragen = 0;
    }
}

/**
 * Die Probe als Instanz anlegen (eigener Modulordner im Temp-Verzeichnis).
 *
 * @return array{0: int, 1: MailgunProbe, 2: callable(array<string, mixed>): void}
 */
function mailProbeAnlegen(): array
{
    $modulDir = rtrim(sys_get_temp_dir(), '/\\') . '/symdo_mailgun_' . bin2hex(random_bytes(4));
    register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($modulDir)));
    @mkdir($modulDir . '/Probe', 0700, true);
    file_put_contents($modulDir . '/Probe/module.json', (string)json_encode(['id' => '{6D1B5F0A-0000-4000-8000-00000000B0B0}',
        'name' => 'MailgunProbe', 'type' => 3, 'vendor' => 'Stephan Sprick', 'aliases' => [], 'parentRequirements' => [],
        'childRequirements' => [], 'implemented' => [], 'prefix' => 'MGPROBE']));
    file_put_contents($modulDir . '/Probe/module.php', "<?php\n");
    IPS\ModuleLoader::loadSingleModule($modulDir . '/Probe', '{MAILGUN-PROBE-LIB}');
    $pid = IPS_CreateInstance('{6D1B5F0A-0000-4000-8000-00000000B0B0}');
    /** @var MailgunProbe $p */
    $p = IPS\InstanceManager::getInstanceInterface($pid);

    $setze = static function (array $werte) use ($pid): void {
        foreach ($werte as $n => $w) {
            IPS_SetProperty($pid, $n, $w);
        }
        IPS_ApplyChanges($pid);
    };
    return [$pid, $p, $setze];
}
