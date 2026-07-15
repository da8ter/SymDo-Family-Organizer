<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/ApiRouter.php';
require_once __DIR__ . '/libs/DeviceRegistry.php';
require_once __DIR__ . '/libs/QrRenderer.php';

class SymDoBridge extends IPSModuleStrict
{
    use ApiRouter;
    use DeviceRegistry;
    use QrRenderer;

    private const MODULE_GUID          = '{F9B31B2B-ED34-4E88-B96D-D115E39F0B44}';
    private const SHOPPING_MODULE_GUID = '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}';
    private const TODO_MODULE_GUID     = '{E0E38D9B-31BC-4F5E-A6CA-91A2A60C7C46}';
    private const CONNECT_MODULE_GUID  = '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}';
    private const HOOK_PATH            = 'lists/app';
    private const API_VERSION          = 1;
    private const PAIRING_TTL          = 600;
    private const ACTION_DEDUP_TTL     = 86400;
    private const ACTION_DEDUP_MAX     = 200;

    private const STATUS_DUPLICATE_INSTANCE = 201;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterHook(self::HOOK_PATH);
        $this->RegisterAttributeString('PairedDevices', '[]');
        $this->RegisterAttributeString('PendingPairings', '[]');
        $this->RegisterAttributeString('ActionDedup', '{}');
        $this->RegisterAttributeString('AvatarCache', '{}');
        $this->RegisterPropertyString('Users', '[]');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // The hook path is fixed, so a second instance would shadow the first one
        $instances = IPS_GetInstanceListByModuleID(self::MODULE_GUID);
        sort($instances);
        if (count($instances) > 1 && $instances[0] !== $this->InstanceID) {
            $this->SetStatus(self::STATUS_DUPLICATE_INSTANCE);
            return;
        }

        $this->EnsureUserIDs();
        $this->SetStatus(IS_ACTIVE);
    }

    /** Assigns stable ids to user rows created in the form (runs once per new row). */
    private function EnsureUserIDs(): void
    {
        $users = json_decode($this->ReadPropertyString('Users'), true);
        if (!is_array($users)) {
            return;
        }
        $changed = false;
        foreach ($users as &$user) {
            if (!is_array($user)) {
                continue;
            }
            if (trim((string)($user['id'] ?? '')) === '') {
                $user['id'] = bin2hex(random_bytes(4));
                $changed = true;
            }
        }
        unset($user);
        if ($changed) {
            IPS_SetProperty($this->InstanceID, 'Users', json_encode($users, JSON_UNESCAPED_UNICODE));
            IPS_ApplyChanges($this->InstanceID); // re-runs once, then stable
        }
    }

    private function LoadUsers(): array
    {
        $users = json_decode($this->ReadPropertyString('Users'), true);
        if (!is_array($users)) {
            return [];
        }
        $result = [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $name = trim((string)($user['name'] ?? ''));
            $id   = trim((string)($user['id'] ?? ''));
            if ($name === '' || $id === '') {
                continue;
            }
            $mediaID = (int)($user['photo'] ?? 0);
            $result[] = [
                'id'        => $id,
                'name'      => $name,
                'mediaID'   => $mediaID,
                'visuID'    => (int)($user['visu'] ?? 0),
                'hasAvatar' => $mediaID > 0 && IPS_MediaExists($mediaID),
            ];
        }
        return $result;
    }

    /** Users as JSON for other modules (e.g. the tile visualization). */
    public function GetUsers(): string
    {
        $users = array_map(
            static fn(array $u): array => ['id' => $u['id'], 'name' => $u['name'], 'hasAvatar' => $u['hasAvatar']],
            $this->LoadUsers()
        );
        return json_encode($users, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Users with a scaled avatar as a data URI, for embedding in a tile state
     * payload (the tile cannot authenticate against the app hook). The scaled
     * thumbnail is cached per media object + update time, so the expensive
     * decode/resize only runs when a photo actually changes.
     */
    public function GetUsersForTile(): string
    {
        $cache    = json_decode($this->ReadAttributeString('AvatarCache'), true);
        $cache    = is_array($cache) ? $cache : [];
        $newCache = [];
        $result   = [];
        foreach ($this->LoadUsers() as $u) {
            $entry = ['id' => $u['id'], 'name' => $u['name'], 'avatar' => ''];
            if ($u['hasAvatar']) {
                $media = IPS_GetMedia($u['mediaID']);
                $key   = $u['mediaID'] . ':' . (string)($media['MediaUpdated'] ?? 0);
                if (isset($cache[$key]) && is_string($cache[$key]) && $cache[$key] !== '') {
                    $entry['avatar'] = $cache[$key];
                } else {
                    $binary = base64_decode(IPS_GetMediaContent($u['mediaID']), true);
                    $thumb  = $binary !== false ? $this->ScaleAvatar($binary, 128) : null;
                    if ($thumb !== null) {
                        $entry['avatar'] = 'data:image/jpeg;base64,' . base64_encode($thumb);
                    }
                }
                if ($entry['avatar'] !== '') {
                    $newCache[$key] = $entry['avatar'];
                }
            }
            $result[] = $entry;
        }
        if ($newCache !== $cache) {
            $this->WriteAttributeString('AvatarCache', json_encode($newCache));
        }
        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Pushes an assignment notice to each user's visualization (official Symcon app). */
    public function NotifyAssignment(string $UserIDs, string $Title, string $ActorUserID): void
    {
        $ids = json_decode($UserIDs, true);
        if (!is_array($ids) || !function_exists('VISU_PostNotification')) {
            return;
        }
        foreach ($this->LoadUsers() as $user) {
            if (!in_array($user['id'], $ids, true) || $user['id'] === $ActorUserID) {
                continue;
            }
            $visuID = $user['visuID'];
            if ($visuID > 0 && IPS_InstanceExists($visuID)) {
                @VISU_PostNotification($visuID, $this->Translate('New task assigned'), $Title, 'Talk', 0);
            }
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string)@file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form) || !isset($form['elements'])) {
            return '{}';
        }

        $connectUrl = $this->GetConnectUrl();
        $info = $connectUrl !== ''
            ? sprintf($this->Translate('Symcon Connect: %s'), $connectUrl)
            : $this->Translate('No Symcon Connect instance found — remote access for the app is unavailable.');
        $this->SetFormElementProperty($form['elements'], 'ConnectInfo', 'caption', $info);
        $this->SetFormElementProperty($form['elements'], 'PairedDevicesList', 'values', $this->BuildDeviceRows());

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function ProcessHookData(): void
    {
        try {
            $this->HandleApiRequest();
        } catch (\Throwable $e) {
            $this->SendDebug('Hook', 'Unhandled error: ' . $e->getMessage(), 0);
            $this->LogMessage($e->getMessage(), KL_ERROR);
            $this->SendApiError('internal', 'Internal server error', 500);
        }
    }

    private function SetFormElementProperty(array &$elements, string $name, string $property, mixed $value): void
    {
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['name'] ?? '') === $name) {
                $element[$property] = $value;
                return;
            }
            if (isset($element['items']) && is_array($element['items'])) {
                $this->SetFormElementProperty($element['items'], $name, $property, $value);
            }
        }
        unset($element);
    }

    private function GetConnectUrl(): string
    {
        foreach (IPS_GetInstanceListByModuleID(self::CONNECT_MODULE_GUID) as $id) {
            $url = @CC_GetURL($id);
            if (is_string($url) && str_starts_with($url, 'http')) {
                return rtrim($url, '/');
            }
        }
        return '';
    }

    private function GetSystemName(): string
    {
        $name = IPS_GetName(0);
        return $name !== '' ? $name : 'IP-Symcon';
    }

    /**
     * LAN base URLs (http://<ip>:3777) so the app can reach the bridge on the
     * home network when Symcon Connect is unavailable. Best effort — assumes
     * the default web server port.
     */
    private function GetLocalUrls(): array
    {
        if (!function_exists('net_get_interfaces')) {
            return [];
        }
        $urls = [];
        foreach (net_get_interfaces() as $iface) {
            foreach (($iface['unicast'] ?? []) as $addr) {
                $ip = (string)($addr['address'] ?? '');
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    continue;
                }
                if (str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.')) {
                    continue;
                }
                $urls[] = 'http://' . $ip . ':3777';
            }
        }
        return array_values(array_unique($urls));
    }

    private function BuildServerInfo(): array
    {
        return [
            'name'           => $this->GetSystemName(),
            'symconVersion'  => (string)IPS_GetKernelVersion(),
            'libraryVersion' => $this->GetLibraryVersion(),
            'apiVersion'     => self::API_VERSION,
            'connectUrl'     => $this->GetConnectUrl(),
            'localUrls'      => $this->GetLocalUrls(),
            'hookPath'       => '/hook/' . self::HOOK_PATH,
        ];
    }

    private function GetLibraryVersion(): string
    {
        $library = json_decode((string)@file_get_contents(dirname(__DIR__) . '/library.json'), true);
        return is_array($library) ? (string)($library['version'] ?? '0') : '0';
    }

    private function GetListInstances(): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID(self::SHOPPING_MODULE_GUID) as $id) {
            $result[] = ['id' => $id, 'kind' => 'shopping'];
        }
        foreach (IPS_GetInstanceListByModuleID(self::TODO_MODULE_GUID) as $id) {
            $result[] = ['id' => $id, 'kind' => 'todo'];
        }
        return $result;
    }

    private function GetInstanceKind(int $id): ?string
    {
        if ($id <= 0 || !IPS_InstanceExists($id)) {
            return null;
        }
        $moduleId = (string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '');
        if ($moduleId === self::SHOPPING_MODULE_GUID) {
            return 'shopping';
        }
        if ($moduleId === self::TODO_MODULE_GUID) {
            return 'todo';
        }
        return null;
    }

    private function GetInstanceRevision(int $id, string $kind): int
    {
        if ($kind === 'shopping' && function_exists('SL_GetAppRevision')) {
            return SL_GetAppRevision($id);
        }
        if ($kind === 'todo' && function_exists('TDL_GetAppRevision')) {
            return TDL_GetAppRevision($id);
        }
        return 0;
    }

    private function DescribeInstance(int $id, string $kind): array
    {
        $features = new \stdClass();
        if ($kind === 'shopping') {
            $features = [
                'scannerEnabled'  => (bool)@IPS_GetProperty($id, 'ScannerEnabled'),
                'extApiEnabled'   => (bool)@IPS_GetProperty($id, 'ExtApiEnabled'),
                'extApiShowPrice' => (bool)@IPS_GetProperty($id, 'ExtApiShowPrice'),
            ];
        }
        return [
            'id'       => $id,
            'kind'     => $kind,
            'name'     => IPS_GetName($id),
            'location' => IPS_GetLocation($id),
            'revision' => $this->GetInstanceRevision($id, $kind),
            'features' => $features,
        ];
    }
}
