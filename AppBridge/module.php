<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/ApiRouter.php';
require_once __DIR__ . '/libs/DeviceRegistry.php';
require_once __DIR__ . '/libs/QrRenderer.php';
require_once __DIR__ . '/libs/AiExtract.php';

class SymDoBridge extends IPSModuleStrict
{
    use ApiRouter;
    use DeviceRegistry;
    use QrRenderer;
    use AiExtract;

    private const MODULE_GUID          = '{F9B31B2B-ED34-4E88-B96D-D115E39F0B44}';
    private const SHOPPING_MODULE_GUID = '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}';
    private const TODO_MODULE_GUID     = '{E0E38D9B-31BC-4F5E-A6CA-91A2A60C7C46}';
    private const CONNECT_MODULE_GUID  = '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}';
    private const SDWA_MODULE_GUID     = '{6703A24A-E9E9-44D3-AB21-27176BF224AA}';
    private const HOOK_PATH            = 'lists/app';
    private const WEBAPP_HOOK_PATH     = 'lists/webapp';
    private const API_VERSION          = 1;
    private const PAIRING_TTL          = 600;
    private const ACTION_DEDUP_TTL     = 86400;
    private const ACTION_DEDUP_MAX     = 200;
    private const ACTION_ID_MAX_LEN    = 64;

    private const STATUS_DUPLICATE_INSTANCE = 201;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterHook(self::HOOK_PATH);
        // Zweiter Hook: liefert die SymDo-Web-App als eigenständige Browser-Seite
        // (dieselbe UI wie die Kachel; Daten holt sie über die /hook/lists/app-API).
        $this->RegisterHook(self::WEBAPP_HOOK_PATH);
        $this->RegisterAttributeString('PairedDevices', '[]');
        $this->RegisterAttributeString('PendingPairings', '[]');
        $this->RegisterAttributeString('ActionDedup', '{}');
        $this->RegisterAttributeString('AvatarCache', '{}');
        $this->RegisterAttributeString('HiddenInstances', '[]');
        $this->RegisterAttributeString('RecipePhotoCategory', ''); // Kategorie-ID für „Rezeptfotos"
        $this->RegisterPropertyString('Users', '[]');
        // Optionale lokale HTTPS-Basis-URL (browservertrautes Zertifikat), damit die
        // über Connect geladene Web-App im Heimnetz auf die lokale API umschaltet.
        $this->RegisterPropertyString('LocalHttpsUrl', '');
        // KI „Foto → Aufgaben" (Web-App schickt das Foto, die Bridge ruft die KI).
        $this->RegisterPropertyBoolean('AiEnabled', true); // Master-Schalter für die KI-Analyse
        $this->RegisterPropertyString('AiProvider', 'anthropic'); // anthropic | openai | local
        $this->RegisterPropertyString('AiAnthropicKey', '');
        $this->RegisterPropertyString('AiOpenAIKey', '');
        $this->RegisterPropertyString('AiLocalBaseUrl', '');
        $this->RegisterPropertyString('AiLocalModel', '');
        $this->RegisterPropertyString('AiLocalKey', '');
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
        // IPS_GetProperty statt ReadPropertyString: sieht per API gestagte
        // Benutzer sofort — ApplyChanges läuft im Hook-Kontext erst verzögert.
        $users = json_decode((string) IPS_GetProperty($this->InstanceID, 'Users'), true);
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

    /** Legt einen Benutzer über die App-API an; Avatar optional als Base64-JPEG. */
    public function CreateAppUser(string $Name, string $AvatarBase64): string
    {
        $name = trim($Name);
        if ($name === '') {
            return json_encode(null);
        }
        $users = json_decode((string) IPS_GetProperty($this->InstanceID, 'Users'), true);
        if (!is_array($users)) {
            $users = [];
        }
        $id      = bin2hex(random_bytes(4));
        $mediaID = $AvatarBase64 !== '' ? $this->SaveAvatarMedia(0, $id, $name, $AvatarBase64) : 0;
        $users[] = ['name' => $name, 'id' => $id, 'photo' => $mediaID, 'visu' => 0];
        IPS_SetProperty($this->InstanceID, 'Users', json_encode($users, JSON_UNESCAPED_UNICODE));
        IPS_ApplyChanges($this->InstanceID);
        return json_encode(['id' => $id, 'name' => $name, 'hasAvatar' => $mediaID > 0], JSON_UNESCAPED_UNICODE);
    }

    /** Ändert Name und/oder Avatar eines Benutzers (App-API: nur das eigene Profil). */
    public function UpdateAppUser(string $UserID, string $Name, string $AvatarBase64): string
    {
        $users = json_decode((string) IPS_GetProperty($this->InstanceID, 'Users'), true);
        if (!is_array($users)) {
            return json_encode(null);
        }
        $found = null;
        $propertyChanged = false;
        foreach ($users as &$user) {
            if (!is_array($user) || trim((string)($user['id'] ?? '')) !== $UserID) {
                continue;
            }
            $name = trim($Name);
            if ($name !== '' && $name !== (string)($user['name'] ?? '')) {
                $user['name'] = $name;
                $propertyChanged = true;
            }
            if ($AvatarBase64 !== '') {
                $mediaID = $this->SaveAvatarMedia(
                    (int)($user['photo'] ?? 0), $UserID, (string)($user['name'] ?? ''), $AvatarBase64
                );
                if ($mediaID > 0 && $mediaID !== (int)($user['photo'] ?? 0)) {
                    $user['photo'] = $mediaID;
                    $propertyChanged = true;
                }
            }
            $found = $user;
            break;
        }
        unset($user);
        if ($found === null) {
            return json_encode(null);
        }
        if ($propertyChanged) {
            IPS_SetProperty($this->InstanceID, 'Users', json_encode($users, JSON_UNESCAPED_UNICODE));
            IPS_ApplyChanges($this->InstanceID);
        }
        $mediaID = (int)($found['photo'] ?? 0);
        return json_encode([
            'id'        => $UserID,
            'name'      => (string)($found['name'] ?? ''),
            'hasAvatar' => $mediaID > 0 && IPS_MediaExists($mediaID),
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Speichert einen Avatar als Medienobjekt unterhalb der Bridge (erstellt bei Bedarf). */
    private function SaveAvatarMedia(int $mediaID, string $userID, string $name, string $base64): int
    {
        $binary = base64_decode($base64, true);
        // Obergrenze großzügig; die App liefert bereits 256px-JPEGs (~15 KB)
        if ($binary === false || strlen($binary) < 100 || strlen($binary) > 1024 * 1024) {
            return 0;
        }
        if ($mediaID <= 0 || !IPS_MediaExists($mediaID)) {
            $mediaID = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetName($mediaID, 'Avatar ' . ($name !== '' ? $name : $userID));
            IPS_SetMediaFile($mediaID, 'media/symdo_avatar_' . $userID . '.jpg', false);
        }
        IPS_SetMediaContent($mediaID, base64_encode($binary));
        return $mediaID;
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

        // KI-Formular: nur die Felder des gewählten Anbieters zeigen (Anfangszustand).
        foreach ($this->AiFieldVisibility($this->ReadPropertyString('AiProvider')) as $name => $visible) {
            $this->SetFormElementProperty($form['elements'], $name, 'visible', $visible);
        }

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Welche KI-Felder je Anbieter sichtbar sind. */
    private function AiFieldVisibility(string $provider): array
    {
        return [
            'AiAnthropicKey' => $provider === 'anthropic',
            'AiOpenAIKey'    => $provider === 'openai',
            'AiLocalBaseUrl' => $provider === 'local',
            'AiLocalModel'   => $provider === 'local',
            'AiLocalKey'     => $provider === 'local',
        ];
    }

    /** Vom Select onChange aufgerufen: blendet die Felder des gewählten Anbieters ein/aus. */
    public function UpdateAiFormVisibility(string $Provider): void
    {
        foreach ($this->AiFieldVisibility($Provider) as $name => $visible) {
            $this->UpdateFormField($name, 'visible', $visible);
        }
    }

    /**
     * KI-Relay für die Visu-Kachel (kein REST-Token): SymDoWebApp ruft dies per
     * IPS_RequestAction, die Bridge extrahiert und schickt das Ergebnis per
     * IPS_RequestAction('AiResult') an die Kachel zurück. Bewusst über
     * RequestAction statt einer neuen public LAB_-Methode, damit ein einfacher
     * Modul-Reload (ohne Kernel-Neustart) genügt.
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'AiTileRequest') {
            $req = json_decode((string)$Value, true);
            if (!is_array($req)) {
                return;
            }
            $sdwa = (int)($req['sdwa'] ?? 0);
            $txn  = (string)($req['txn'] ?? '');
            // Die Kachel wartet synchron auf ein AiResult. Wirft hier irgendetwas
            // (IPS_CreateMedia & Co. können das), darf die Antwort NICHT ausfallen —
            // sonst hängt das txn-Promise der Web-App bis zum Timeout.
            try {
                $payload     = $req['payload'] ?? [];
                $payloadJson = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $body        = json_decode($this->AiRelayBody((string)($req['path'] ?? ''), (string)$payloadJson), true);
            } catch (\Throwable $e) {
                $this->LogMessage('AI relay failed: ' . $e->getMessage(), KL_ERROR);
                $body = ['ok' => false, 'error' => ['code' => 'internal', 'message' => $this->Translate('AI request failed.')]];
            }
            if (!is_array($body)) {
                $body = ['ok' => false, 'error' => ['code' => 'internal', 'message' => $this->Translate('AI request failed.')]];
            }
            if ($sdwa > 0 && $this->IsSymDoWebAppInstance($sdwa)) {
                @IPS_RequestAction($sdwa, 'AiResult', json_encode([
                    'txn' => $txn,
                    // Status spiegelt das Ergebnis, statt immer 200 zu behaupten.
                    'status' => (($body['ok'] ?? false) === true) ? 200 : 502,
                    'json'   => $body,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            return;
        }
        parent::RequestAction($Ident, $Value);
    }

    /** Rückkanal nur an echte SymDoWebApp-Instanzen (Ident kommt von außen). */
    private function IsSymDoWebAppInstance(int $instanceID): bool
    {
        if (!IPS_InstanceExists($instanceID)) {
            return false;
        }
        return (IPS_GetInstance($instanceID)['ModuleInfo']['ModuleID'] ?? '') === self::SDWA_MODULE_GUID;
    }

    protected function ProcessHookData(): void
    {
        try {
            $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            // Web-App-Seite: eigener Hook, liefert HTML (kein Token nötig — die
            // Seite authentifiziert sich danach selbst gegen die JSON-API).
            if (str_starts_with($path, '/hook/' . self::WEBAPP_HOOK_PATH)) {
                $this->ServeWebApp();
                return;
            }
            $this->HandleApiRequest();
        } catch (\Throwable $e) {
            $this->SendDebug('Hook', 'Unhandled error: ' . $e->getMessage(), 0);
            $this->LogMessage($e->getMessage(), KL_ERROR);
            $this->SendApiError('internal', 'Internal server error', 500);
        }
    }

    /**
     * Liefert die SymDo-Web-App aus. UI-Quelle ist unverändert die Kachel-Datei
     * (`SymDoWebApp/module.html`) — eine gemeinsame Quelle, keine Divergenz. Nur
     * der host-spezifische Kopf (`/icons.js`) wird durch den Web-Adapter ersetzt,
     * der `window.requestAction`/`window.translate` auf die REST-API umbiegt und
     * das Theme/die Icons bereitstellt.
     */
    private function ServeWebApp(): void
    {
        $uiPath = __DIR__ . '/../SymDoWebApp/module.html';
        $html   = @file_get_contents($uiPath);
        if (!is_string($html)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'SymDo Web-App: UI source not found.';
            return;
        }
        // /icons.js beibehalten: liegt an der Host-Wurzel und ist über denselben
        // Connect-Host erreichbar (root-absolut). Nur den Web-Kopf ANHÄNGEN, statt
        // den Icon-Kit zu ersetzen. (Sollte /icons.js über Connect wider Erwarten
        // nicht erreichbar sein, wird hier später ein gebündeltes Icon-Set ergänzt.)
        $html = str_replace(
            '<script src="/icons.js"></script>',
            '<script src="/icons.js"></script>' . $this->BuildWebHead(),
            $html
        );
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $html;
    }

    /**
     * Host-Kopf für den Browser-Betrieb. Ersetzt die visu-spezifische
     * `/icons.js`-Zeile. (Phase A: Theme-Defaults; der REST-Adapter + Icons +
     * i18n folgen in den nächsten Phasen.)
     */
    private function BuildWebHead(): string
    {
        // Konkrete Theme-Werte, da die Visu-Variablen (--card-color etc.) außerhalb
        // der Visualisierung fehlen. Dunkles Standard-Theme wie in der App.
        $theme = '<style>:root{'
            . '--card-color:#2b2c30;--content-color:#ffffff;--accent-color:#00cdab;'
            . '}html,body{background:#1c1c1e;}</style>';

        // Übersetzungen aus der geteilten UI-Quelle einbetten (kein Extra-Request).
        $translations = '{}';
        $dec = json_decode((string)@file_get_contents(__DIR__ . '/../SymDoWebApp/locale.json'), true);
        if (is_array($dec) && isset($dec['translations'])) {
            $translations = json_encode($dec['translations'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Lokale HTTPS-Basis (optional): die Web-App probt sie und schaltet im
        // Heimnetz auf die lokale API um (Connect bleibt Fallback unterwegs).
        $localBase = rtrim(trim($this->ReadPropertyString('LocalHttpsUrl')), '/');
        $symdo = ['apiBase' => '/hook/' . self::HOOK_PATH . '/v' . self::API_VERSION];
        // KI-Schalter: die Web-App blendet die KI-Buttons aus, wenn deaktiviert.
        $symdo['aiEnabled'] = $this->ReadPropertyBoolean('AiEnabled');
        if ($localBase !== '') {
            $symdo['localBase'] = $localBase . '/hook/' . self::HOOK_PATH . '/v' . self::API_VERSION;
        }
        $config = '<script>window.__SYMDO__=' . json_encode($symdo, JSON_UNESCAPED_SLASHES)
            . ';window.__SYMDO_I18N__=' . $translations . ';</script>';

        $adapterJs = (string)@file_get_contents(__DIR__ . '/libs/webapp-adapter.js');
        $adapter = '<script>' . $adapterJs . '</script>';

        return $theme . $config . $adapter;
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
            'hidden'   => in_array($id, $this->GetHiddenInstances(), true),
        ];
    }

    /** @return int[] Instanz-IDs, die in der App ausgeblendet sind (haushaltsweit) */
    private function GetHiddenInstances(): array
    {
        $decoded = json_decode($this->ReadAttributeString('HiddenInstances'), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_map('intval', $decoded));
    }

    private function SetInstanceHidden(int $id, bool $hidden): void
    {
        $list = array_values(array_diff($this->GetHiddenInstances(), [$id]));
        if ($hidden) {
            $list[] = $id;
        }
        $this->WriteAttributeString('HiddenInstances', json_encode($list));
    }

    /** JSON-Array der haushaltsweit ausgeblendeten Listen-Instanz-IDs (für Companion-Kacheln). */
    public function GetHiddenLists(): string
    {
        return json_encode($this->GetHiddenInstances());
    }
}
