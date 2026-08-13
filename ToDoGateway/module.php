<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/OAuthHelper.php';
require_once __DIR__ . '/libs/BridgeImport.php';
require_once __DIR__ . '/BridgeCore.php';

/**
 * SymDo Gateway — eine zentrale Dienst-Instanz für die ganze Listen-Familie.
 *
 * Zwei Hälften unter einem Dach:
 *  - Gateway: Anmeldedaten und HTTP-Broker für Google Tasks, Microsoft To Do, CalDAV.
 *    Die ToDo-Listen rufen sie direkt als TGW_… auf.
 *  - App (trait BridgeCore): REST-API, Web-App, Push und KI für iOS- und Web-App.
 *
 * Die Gateway-Hälfte darf mehrfach existieren (ein Instanzsatz pro Konto), die
 * App-Hälfte nicht — die Hook-Pfade sind fest. Deshalb bedient nur die Instanz mit
 * der niedrigsten ID die App; siehe OwnsAppApi().
 */
class SymDoGateway extends IPSModuleStrict
{
    use OAuthHelper;
    use BridgeImport;
    use BridgeCore;

    private const MODULE_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    public function Create(): void
    {
        parent::Create();

        // Google Tasks
        $this->RegisterPropertyString('GoogleClientID', '');
        $this->RegisterPropertyString('GoogleClientSecret', '');
        $this->RegisterAttributeString('GoogleAccessToken', '');
        $this->RegisterAttributeString('GoogleRefreshToken', '');
        $this->RegisterAttributeInteger('GoogleTokenExpires', 0);

        // Microsoft To Do
        $this->RegisterPropertyString('MicrosoftClientID', '');
        $this->RegisterPropertyString('MicrosoftClientSecret', '');
        $this->RegisterPropertyString('MicrosoftTenant', 'common');
        $this->RegisterAttributeString('MicrosoftAccessToken', '');
        $this->RegisterAttributeString('MicrosoftRefreshToken', '');
        $this->RegisterAttributeInteger('MicrosoftTokenExpires', 0);

        // Throttling back-off windows (A2): "do not call before" timestamps per backend
        $this->RegisterAttributeInteger('GoogleRetryAfter', 0);
        $this->RegisterAttributeInteger('MicrosoftRetryAfter', 0);
        $this->RegisterAttributeInteger('CalDAVRetryAfter', 0);

        // R5: pending OAuth state parameters (one-time, validated in ProcessHookData)
        $this->RegisterAttributeString('GoogleOAuthState', '');
        $this->RegisterAttributeString('MicrosoftOAuthState', '');

        // CalDAV
        $this->RegisterPropertyString('CalDAVServerURL', '');
        $this->RegisterPropertyString('CalDAVUsername', '');
        $this->RegisterPropertyString('CalDAVPassword', '');

        // OAuth redirect endpoints (volatile, re-registered on every start)
        $this->RegisterHook('todogateway_google');
        $this->RegisterHook('todogateway_microsoft');

        // App-Hälfte
        $this->BridgeCreate();
        $this->RegisterAttributeString('BridgeImportDone', '');
        $this->RegisterTimer('BridgeImport', 0, 'TGW_RunPendingBridgeImport($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        if ($this->OwnsAppApi()) {
            // Flüchtig, deshalb hier und nicht in Create(): ein Modul-Reload ohne
            // Kernel-Neustart führt Create() nicht erneut aus.
            $this->RegisterHook(self::HOOK_PATH);
            $this->RegisterHook(self::WEBAPP_HOOK_PATH);
            $this->RegisterHook(self::WS_HOOK_PATH);
            $this->BridgeApplyChanges();
        }

        // Verzögert: IPS_ApplyChanges darf nicht aus ApplyChanges heraus laufen.
        if ($this->BridgeImportPending()) {
            try {
                $this->SetTimerInterval('BridgeImport', 1000);
            } catch (Throwable $e) {
                // Timer noch nicht registriert (Modul-Reload ohne Kernel-Neustart, der
                // Runlevel bleibt dabei KR_READY). Bewusst kein Ersatzpfad: die Übernahme
                // wartet auf den Neustart, der Knopf im Formular bleibt der Weg.
                $this->SendDebug('ApplyChanges', 'BridgeImport-Timer fehlt, Uebernahme wartet auf Kernel-Neustart', 0);
            }
        }
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        $this->BridgeMessageSink($Message);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($this->BridgeRequestAction($Ident, $Value)) {
            return;
        }
        parent::RequestAction($Ident, $Value);
    }

    /**
     * Die App-Hälfte hängt an festen Hook-Pfaden, kann also nur einmal im System
     * laufen — die Gateway-Hälfte dagegen beliebig oft. Es gewinnt die niedrigste
     * Instanz-ID. Bewusst kein Blick auf den Status einer Geschwister-Instanz: der
     * klebt in Symcon und taugt nicht als Wahrheit.
     */
    private function OwnsAppApi(): bool
    {
        $ids = IPS_GetInstanceListByModuleID(self::MODULE_GUID);
        sort($ids);
        return ((int)($ids[0] ?? 0)) === $this->InstanceID;
    }

    protected function ProcessHookData(): void
    {
        // rtrim, weil die Redirect-URI auf einen Schraegstrich endet.
        $uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = rtrim((string)(parse_url($uri, PHP_URL_PATH) ?? ''), '/');
        if ($path === '/hook/todogateway_google' || $path === '/hook/todogateway_microsoft') {
            $this->ProcessOAuthHookData($path === '/hook/todogateway_google');
            return;
        }
        $this->BridgeProcessHook();
    }

    public function GetConfigurationForm(): string
    {
        // form.json traegt die App-Haelfte. Symcon mergt NICHT: ist
        // GetConfigurationForm ueberschrieben, gewinnt die Methode vollstaendig —
        // die Datei muss also selbst gelesen werden.
        $app = json_decode((string)@file_get_contents(__DIR__ . '/form.json'), true);
        $appElements = (is_array($app) && isset($app['elements']) && is_array($app['elements']))
            ? $app['elements']
            : [];

        $elements = array_merge(
            $this->GetBridgeImportFormElements(),
            $appElements,
            [
                $this->GetGoogleFormElements(),
                $this->GetMicrosoftFormElements(),
                $this->GetCalDAVFormElements()
            ],
            $this->GetDonationFormElements()
        );

        $this->BridgeFormOverrides($elements);

        return json_encode(['elements' => $elements], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function GetDonationFormElements(): array
    {
        $formPath = dirname(__DIR__) . '/ToDoOverview/form.json';
        if (!is_readable($formPath)) {
            return [];
        }

        $form = json_decode((string)file_get_contents($formPath), true);
        if (!is_array($form) || !isset($form['elements']) || !is_array($form['elements'])) {
            return [];
        }

        foreach ($form['elements'] as $index => $element) {
            if (!is_array($element)) {
                continue;
            }

            if (($element['name'] ?? '') === 'DonationHeader') {
                return array_slice($form['elements'], max(0, $index - 1));
            }
        }

        return [];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Google Tasks
    // ──────────────────────────────────────────────────────────────────────────

    private function GoogleSetEncryptedToken(string $Attribute, string $Token): void
    {
        $this->OAuthSetEncryptedToken($Attribute, $Token, 'GKey');
    }

    private function GoogleGetDecryptedToken(string $Attribute): string
    {
        return $this->OAuthGetDecryptedToken($Attribute, 'GKey');
    }

    public function GoogleGetAuthUrl(): string
    {
        $clientId = trim($this->ReadPropertyString('GoogleClientID'));
        if ($clientId === '') {
            return $this->Translate('Please enter Client ID first.');
        }

        $redirectUri = $this->OAuthGetRedirectUri('/hook/todogateway_google/');
        $state = $this->InstanceID . '_' . bin2hex(random_bytes(16));
        // R5: persist for one-time validation in ProcessHookData (login-CSRF protection).
        $this->WriteAttributeString('GoogleOAuthState', json_encode(['state' => $state, 'expires' => time() + 600]));

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/tasks',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function GoogleHandleCallback(string $Code): bool
    {
        $clientId = trim($this->ReadPropertyString('GoogleClientID'));
        $clientSecret = trim($this->ReadPropertyString('GoogleClientSecret'));
        $redirectUri = $this->OAuthGetRedirectUri('/hook/todogateway_google/');

        if ($clientId === '' || $clientSecret === '' || $Code === '') {
            $this->SendDebug('GoogleTasks', 'HandleCallback: Missing credentials or code', 0);
            return false;
        }

        $postData = [
            'code' => $Code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ];

        return $this->OAuthExchangeToken(
            'https://oauth2.googleapis.com/token',
            $postData, 'GKey',
            'GoogleAccessToken', 'GoogleRefreshToken', 'GoogleTokenExpires',
            'GoogleTasks'
        );
    }

    public function GoogleIsConnected(): bool
    {
        return $this->GoogleGetDecryptedToken('GoogleRefreshToken') !== '';
    }

    public function GoogleTestConnection(): bool
    {
        if (!$this->GoogleIsConnected()) {
            echo $this->Translate('Not connected to Google. Please authorize first.');
            return false;
        }

        // R18: routed through GoogleApiRequest so the 401-retry and the back-off window
        // apply here too (the raw helper bypassed both).
        $data = $this->GoogleApiRequest('GET', '/tasks/v1/users/@me/lists');
        if ($data === null) {
            echo $this->Translate('Connection failed');
            return false;
        }

        $count = count($data['items'] ?? []);
        echo sprintf($this->Translate('Connection successful. Found %d task list(s).'), $count);
        return true;
    }

    public function GoogleDisconnect(): void
    {
        $this->GoogleSetEncryptedToken('GoogleAccessToken', '');
        $this->GoogleSetEncryptedToken('GoogleRefreshToken', '');
        $this->WriteAttributeInteger('GoogleTokenExpires', 0);
        echo $this->Translate('Disconnected from Google.');
    }

    public function GoogleApiRequest(string $Method, string $Endpoint, mixed $Body = null, array $Headers = []): ?array
    {
        $url = 'https://tasks.googleapis.com' . $Endpoint;
        $meta = $this->OAuthAuthorizedRequest(
            $Method, $url, $Body,
            'GKey', 'GoogleAccessToken', 'GoogleRefreshToken', 'GoogleTokenExpires',
            'https://oauth2.googleapis.com/token',
            trim($this->ReadPropertyString('GoogleClientID')),
            trim($this->ReadPropertyString('GoogleClientSecret')),
            'GoogleTasks', '', $Headers, 'GoogleRetryAfter'
        );
        if ($meta === null) {
            return null;
        }

        $response = (string)($meta['body'] ?? '');
        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->SendDebug('GoogleTasks', 'Invalid JSON response', 0);
            return null;
        }

        if (isset($data['error'])) {
            $this->SendDebug('GoogleTasks', 'API error: ' . json_encode($data['error']), 0);
            return null;
        }

        return $data;
    }

    /**
     * Perform a request and return only the HTTP status code (0 on transport failure / active
     * back-off). Used by the delete path so a 412 (concurrent server edit) can be distinguished
     * from a transient failure instead of being retried forever with a stale ETag.
     */
    public function GoogleApiStatus(string $Method, string $Endpoint, mixed $Body = null, array $Headers = []): int
    {
        $url = (strncmp($Endpoint, 'https://', 8) === 0 || strncmp($Endpoint, 'http://', 7) === 0)
            ? $Endpoint
            : 'https://tasks.googleapis.com' . $Endpoint;
        $meta = $this->OAuthAuthorizedRequest(
            $Method, $url, $Body,
            'GKey', 'GoogleAccessToken', 'GoogleRefreshToken', 'GoogleTokenExpires',
            'https://oauth2.googleapis.com/token',
            trim($this->ReadPropertyString('GoogleClientID')),
            trim($this->ReadPropertyString('GoogleClientSecret')),
            'GoogleTasks', '', $Headers, 'GoogleRetryAfter'
        );
        return $meta === null ? 0 : (int)($meta['status'] ?? 0);
    }

    public function GoogleFetchTaskLists(): array
    {
        // R18: routed through GoogleApiRequest (401-retry + back-off window).
        $data = $this->GoogleApiRequest('GET', '/tasks/v1/users/@me/lists');
        if ($data === null) {
            return [];
        }

        $lists = [];
        foreach ($data['items'] ?? [] as $item) {
            $lists[] = [
                'id' => $item['id'] ?? '',
                'title' => $item['title'] ?? ''
            ];
        }
        return $lists;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Microsoft To Do
    // ──────────────────────────────────────────────────────────────────────────

    private function MicrosoftGetTenant(): string
    {
        $tenant = trim($this->ReadPropertyString('MicrosoftTenant'));
        return $tenant === '' ? 'common' : $tenant;
    }

    private function MicrosoftSetEncryptedToken(string $Attribute, string $Token): void
    {
        $this->OAuthSetEncryptedToken($Attribute, $Token, 'MKey');
    }

    private function MicrosoftGetDecryptedToken(string $Attribute): string
    {
        return $this->OAuthGetDecryptedToken($Attribute, 'MKey');
    }

    public function MicrosoftGetAuthUrl(): string
    {
        $clientId = trim($this->ReadPropertyString('MicrosoftClientID'));
        if ($clientId === '') {
            return $this->Translate('Please enter Client ID first.');
        }

        $tenant = $this->MicrosoftGetTenant();
        $redirectUri = $this->OAuthGetRedirectUri('/hook/todogateway_microsoft/');
        $state = $this->InstanceID . '_' . bin2hex(random_bytes(16));
        // R5: persist for one-time validation in ProcessHookData (login-CSRF protection).
        $this->WriteAttributeString('MicrosoftOAuthState', json_encode(['state' => $state, 'expires' => time() + 600]));

        $params = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => 'offline_access Tasks.ReadWrite',
            'state' => $state
        ];

        return 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/authorize?' . http_build_query($params);
    }

    public function MicrosoftHandleCallback(string $Code): bool
    {
        $clientId = trim($this->ReadPropertyString('MicrosoftClientID'));
        $clientSecret = trim($this->ReadPropertyString('MicrosoftClientSecret'));
        $tenant = $this->MicrosoftGetTenant();
        $redirectUri = $this->OAuthGetRedirectUri('/hook/todogateway_microsoft/');

        if ($clientId === '' || $clientSecret === '' || $Code === '') {
            $this->SendDebug('MicrosoftToDo', 'HandleCallback: Missing credentials or code', 0);
            return false;
        }

        $postData = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $Code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => 'offline_access Tasks.ReadWrite'
        ];

        return $this->OAuthExchangeToken(
            'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
            $postData, 'MKey',
            'MicrosoftAccessToken', 'MicrosoftRefreshToken', 'MicrosoftTokenExpires',
            'MicrosoftToDo'
        );
    }

    public function MicrosoftIsConnected(): bool
    {
        return $this->MicrosoftGetDecryptedToken('MicrosoftRefreshToken') !== '';
    }

    public function MicrosoftTestConnection(): bool
    {
        if (!$this->MicrosoftIsConnected()) {
            echo $this->Translate('Not connected to Microsoft. Please authorize first.');
            return false;
        }

        // R18: routed through MicrosoftApiRequest so the 401-retry and the back-off window
        // apply here too (the raw helper bypassed both).
        $data = $this->MicrosoftApiRequest('GET', '/me/todo/lists');
        if ($data === null) {
            echo $this->Translate('Connection failed');
            return false;
        }

        $count = count($data['value'] ?? []);
        echo sprintf($this->Translate('Connection successful. Found %d list(s).'), $count);
        return true;
    }

    public function MicrosoftDisconnect(): void
    {
        $this->MicrosoftSetEncryptedToken('MicrosoftAccessToken', '');
        $this->MicrosoftSetEncryptedToken('MicrosoftRefreshToken', '');
        $this->WriteAttributeInteger('MicrosoftTokenExpires', 0);
        echo $this->Translate('Disconnected from Microsoft.');
    }

    public function MicrosoftApiRequest(string $Method, string $Endpoint, mixed $Body = null, array $Headers = []): ?array
    {
        $tenant = $this->MicrosoftGetTenant();
        // Follow opaque absolute Graph URLs (@odata.nextLink / @odata.deltaLink) verbatim;
        // otherwise treat $Endpoint as a path relative to the v1.0 base.
        $url = (strncmp($Endpoint, 'https://', 8) === 0 || strncmp($Endpoint, 'http://', 7) === 0)
            ? $Endpoint
            : 'https://graph.microsoft.com/v1.0' . $Endpoint;
        $meta = $this->OAuthAuthorizedRequest(
            $Method, $url, $Body,
            'MKey', 'MicrosoftAccessToken', 'MicrosoftRefreshToken', 'MicrosoftTokenExpires',
            'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
            trim($this->ReadPropertyString('MicrosoftClientID')),
            trim($this->ReadPropertyString('MicrosoftClientSecret')),
            'MicrosoftToDo',
            'offline_access Tasks.ReadWrite', $Headers, 'MicrosoftRetryAfter'
        );
        if ($meta === null) {
            return null;
        }

        $response = (string)($meta['body'] ?? '');
        if (trim($response) === '') {
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->SendDebug('MicrosoftToDo', 'Invalid JSON response', 0);
            return null;
        }

        if (isset($data['error'])) {
            $this->SendDebug('MicrosoftToDo', 'API error: ' . json_encode($data['error']), 0);
            return null;
        }

        return $data;
    }

    public function MicrosoftApiStatus(string $Method, string $Endpoint, mixed $Body = null, array $Headers = []): int
    {
        $tenant = $this->MicrosoftGetTenant();
        $url = (strncmp($Endpoint, 'https://', 8) === 0 || strncmp($Endpoint, 'http://', 7) === 0)
            ? $Endpoint
            : 'https://graph.microsoft.com/v1.0' . $Endpoint;
        $meta = $this->OAuthAuthorizedRequest(
            $Method, $url, $Body,
            'MKey', 'MicrosoftAccessToken', 'MicrosoftRefreshToken', 'MicrosoftTokenExpires',
            'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
            trim($this->ReadPropertyString('MicrosoftClientID')),
            trim($this->ReadPropertyString('MicrosoftClientSecret')),
            'MicrosoftToDo',
            'offline_access Tasks.ReadWrite', $Headers, 'MicrosoftRetryAfter'
        );
        return $meta === null ? 0 : (int)($meta['status'] ?? 0);
    }

    public function MicrosoftFetchLists(): array
    {
        // R18: routed through MicrosoftApiRequest (401-retry + back-off window).
        $data = $this->MicrosoftApiRequest('GET', '/me/todo/lists');
        if ($data === null) {
            return [];
        }

        $lists = [];
        foreach ($data['value'] ?? [] as $item) {
            $lists[] = [
                'id' => $item['id'] ?? '',
                'displayName' => $item['displayName'] ?? ''
            ];
        }
        return $lists;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CalDAV
    // ──────────────────────────────────────────────────────────────────────────

    public function CalDAVGetCredentials(): array
    {
        return [
            'url' => trim($this->ReadPropertyString('CalDAVServerURL')),
            'user' => trim($this->ReadPropertyString('CalDAVUsername')),
            'pass' => trim($this->ReadPropertyString('CalDAVPassword'))
        ];
    }

    public function CalDAVTestConnection(): bool
    {
        $creds = $this->CalDAVGetCredentials();
        if ($creds['url'] === '' || $creds['user'] === '' || $creds['pass'] === '') {
            echo $this->Translate('Please fill in server URL, username and password.');
            return false;
        }

        $testUrl = rtrim($creds['url'], '/') . '/';
        $res = $this->CalDAVRequest(
            'PROPFIND',
            $testUrl,
            $creds['user'],
            $creds['pass'],
            [
                'Depth: 0',
                'Content-Type: application/xml; charset=utf-8'
            ],
            '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>',
            10
        );

        $statusCode = (int)($res['status'] ?? 0);
        if ($statusCode === 0) {
            echo $this->Translate('Connection failed');
            return false;
        }
        if ($statusCode === 207 || $statusCode === 200) {
            echo $this->Translate('Connection successful');
            return true;
        }
        if ($statusCode === 401) {
            echo $this->Translate('Authentication failed');
            return false;
        }

        echo $this->Translate('Connection failed') . ' (HTTP ' . $statusCode . ')';
        return false;
    }

    public function CalDAVRequest(string $Method, string $Url, string $User, string $Pass, array $Headers, string $Body = '', int $Timeout = 15): array
    {
        // A2: honor an active back-off window without hitting the server.
        if ($this->OAuthIsThrottled('CalDAVRetryAfter')) {
            $this->SendDebug('CalDAV', 'Skipped – backing off until ' . date('H:i:s', $this->ReadAttributeInteger('CalDAVRetryAfter')), 0);
            return ['status' => 429, 'body' => '', 'headers' => [], 'url' => $Url];
        }

        $maxRedirects = 5;
        $currentUrl = $Url;

        for ($i = 0; $i <= $maxRedirects; $i++) {
            $reqHeaders = array_merge([
                'Authorization: Basic ' . base64_encode($User . ':' . $Pass),
                'User-Agent: IP-Symcon ToDoList'
            ], $Headers);

            $opts = [
                'http' => [
                    'method' => $Method,
                    'header' => $reqHeaders,
                    'content' => $Body,
                    'ignore_errors' => true,
                    // R15: PHP's wrapper must not auto-follow — otherwise a redirected
                    // PUT/DELETE executes twice (auto-follow + this manual loop) and the
                    // parsed status belongs to the first response of the chain.
                    'follow_location' => 0,
                    'timeout' => $Timeout
                ]
            ];

            $context = stream_context_create($opts);
            $body = @file_get_contents($currentUrl, false, $context);
            $respHeaders = $http_response_header ?? [];
            $statusCode = $this->GetHttpStatusCode($respHeaders);

            if (in_array($statusCode, [301, 302, 307, 308], true)) {
                $location = $this->GetHttpHeaderValue($respHeaders, 'Location');
                if ($location === '') {
                    break;
                }
                $nextUrl = $this->ResolveUrl($currentUrl, $location);
                // R15: never follow to a different host — the Basic credentials would leak.
                if ((parse_url($nextUrl, PHP_URL_HOST) ?: '') !== (parse_url($currentUrl, PHP_URL_HOST) ?: '')) {
                    $this->SendDebug('CalDAV', 'Refusing cross-host redirect to ' . $nextUrl, 0);
                    break;
                }
                $currentUrl = $nextUrl;
                continue;
            }

            if (in_array($statusCode, [429, 503, 504], true)) {
                $this->OAuthNoteThrottle('CalDAVRetryAfter', $respHeaders, 'CalDAV');
            }

            return [
                'status' => $statusCode,
                'body' => ($body === false) ? '' : $body,
                'headers' => $respHeaders,
                'url' => $currentUrl
            ];
        }

        return [
            'status' => 0,
            'body' => '',
            'headers' => [],
            'url' => $currentUrl
        ];
    }

    public function CalDAVDiscoverCalendars(): array
    {
        $creds = $this->CalDAVGetCredentials();
        if ($creds['url'] === '' || $creds['user'] === '' || $creds['pass'] === '') {
            return [];
        }

        $principal = $this->CalDAVGetPrincipal($creds['url'], $creds['user'], $creds['pass']);
        if ($principal === null) {
            return [];
        }

        $calendarHome = $this->CalDAVGetCalendarHome($creds['url'], $principal, $creds['user'], $creds['pass']);
        if ($calendarHome === null) {
            return [];
        }

        return $this->CalDAVListCalendars($creds['url'], $calendarHome, $creds['user'], $creds['pass']);
    }

    private function CalDAVGetPrincipal(string $BaseUrl, string $User, string $Pass): ?string
    {
        $testUrl = rtrim($BaseUrl, '/') . '/';
        $res = $this->CalDAVRequest(
            'PROPFIND',
            $testUrl,
            $User,
            $Pass,
            [
                'Depth: 0',
                'Content-Type: application/xml; charset=utf-8'
            ],
            '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>',
            15
        );

        if (($res['status'] ?? 0) !== 207 && ($res['status'] ?? 0) !== 200) {
            return null;
        }

        $xml = @simplexml_load_string((string)($res['body'] ?? ''));
        if ($xml === false) {
            return null;
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $principals = $xml->xpath('//d:current-user-principal/d:href');

        if (!empty($principals)) {
            return (string)$principals[0];
        }

        return null;
    }

    private function CalDAVGetCalendarHome(string $BaseUrl, string $Principal, string $User, string $Pass): ?string
    {
        $principalUrl = $this->ResolveUrl($BaseUrl, $Principal);

        $res = $this->CalDAVRequest(
            'PROPFIND',
            $principalUrl,
            $User,
            $Pass,
            [
                'Depth: 0',
                'Content-Type: application/xml; charset=utf-8'
            ],
            '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-home-set/></d:prop></d:propfind>',
            15
        );

        if (($res['status'] ?? 0) !== 207) {
            return null;
        }

        $xml = @simplexml_load_string((string)($res['body'] ?? ''));
        if ($xml === false) {
            return null;
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        $homes = $xml->xpath('//c:calendar-home-set/d:href');

        if (!empty($homes)) {
            return (string)$homes[0];
        }

        return null;
    }

    private function CalDAVListCalendars(string $BaseUrl, string $CalendarHome, string $User, string $Pass): array
    {
        $homeUrl = $this->ResolveUrl($BaseUrl, $CalendarHome);

        $res = $this->CalDAVRequest(
            'PROPFIND',
            $homeUrl,
            $User,
            $Pass,
            [
                'Depth: 1',
                'Content-Type: application/xml; charset=utf-8'
            ],
            '<?xml version="1.0" encoding="utf-8"?>' .
                '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">' .
                '<d:prop><d:displayname/><d:resourcetype/><c:supported-calendar-component-set/></d:prop>' .
                '</d:propfind>',
            15
        );

        if (($res['status'] ?? 0) !== 207) {
            return [];
        }

        $xml = @simplexml_load_string((string)($res['body'] ?? ''));
        if ($xml === false) {
            return [];
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        $calendars = [];
        $responses = $xml->xpath('//d:response');

        foreach ($responses as $response) {
            $response->registerXPathNamespace('d', 'DAV:');
            $response->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

            $hrefNodes = $response->xpath('d:href');
            $href = !empty($hrefNodes) ? (string)$hrefNodes[0] : '';

            $displayNameNodes = $response->xpath('d:propstat/d:prop/d:displayname');
            $displayName = !empty($displayNameNodes) ? (string)$displayNameNodes[0] : '';

            $resourceTypes = $response->xpath('d:propstat/d:prop/d:resourcetype/c:calendar');
            if (empty($resourceTypes)) {
                continue;
            }

            $supportsTodo = false;
            $components = $response->xpath('d:propstat/d:prop/c:supported-calendar-component-set/c:comp');
            foreach ($components as $comp) {
                $name = (string)($comp->attributes()['name'] ?? '');
                if (strtoupper($name) === 'VTODO') {
                    $supportsTodo = true;
                    break;
                }
            }

            $path = $href;
            if (strpos($href, '://') !== false) {
                $parsed = parse_url($href);
                $path = $parsed['path'] ?? $href;
            }

            $baseParsed = parse_url($BaseUrl);
            $basePath = rtrim($baseParsed['path'] ?? '', '/');
            if ($basePath !== '' && strpos($path, $basePath) === 0) {
                $path = substr($path, strlen($basePath));
            }
            $path = ltrim($path, '/');

            $calendars[] = [
                'name' => $displayName ?: basename($path),
                'path' => $path,
                'href' => $href,
                'supportsTodo' => $supportsTodo
            ];
        }

        usort($calendars, fn($a, $b) => ($b['supportsTodo'] <=> $a['supportsTodo']) ?: strcasecmp($a['name'], $b['name']));

        return $calendars;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // OAuth Webhook Handler
    // ──────────────────────────────────────────────────────────────────────────

    /** OAuth-Rueckleitung von Google bzw. Microsoft. Weiche siehe ProcessHookData(). */
    private function ProcessOAuthHookData(bool $isGoogle): void
    {
        $code = $_GET['code'] ?? '';
        $error = $_GET['error'] ?? '';
        $state = (string)($_GET['state'] ?? '');

        if ($error !== '') {
            echo '<html><body><h1>' . $this->Translate('Authorization failed') . '</h1><p>' . htmlspecialchars($error) . '</p></body></html>';
            return;
        }

        if ($code === '') {
            echo '<html><body><h1>' . $this->Translate('Authorization failed') . '</h1><p>' . $this->Translate('Please try again.') . '</p></body></html>';
            return;
        }

        // R5: one-time state validation (RFC 6749 §10.12). Without it a forged callback could
        // bind an attacker's account to this gateway (login CSRF) and all local items would
        // silently sync into the attacker's list.
        $stateAttr = $isGoogle ? 'GoogleOAuthState' : 'MicrosoftOAuthState';
        $stored = json_decode((string)$this->ReadAttributeString($stateAttr), true);
        $storedState = is_array($stored) ? (string)($stored['state'] ?? '') : '';
        $storedExpires = is_array($stored) ? (int)($stored['expires'] ?? 0) : 0;
        $this->WriteAttributeString($stateAttr, ''); // consume — a state is valid exactly once
        if ($storedState === '' || $state === '' || !hash_equals($storedState, $state) || time() > $storedExpires) {
            $this->SendDebug('OAuth', 'Callback rejected: state missing/mismatched/expired', 0);
            echo '<html><body><h1>' . $this->Translate('Authorization failed') . '</h1><p>' . $this->Translate('Invalid or expired authorization state. Please start the authorization again from the gateway form.') . '</p></body></html>';
            return;
        }

        $success = $isGoogle ? $this->GoogleHandleCallback($code) : $this->MicrosoftHandleCallback($code);
        if ($success) {
            echo '<html><body><h1>' . $this->Translate('Authorization successful') . '</h1><p>' . $this->Translate('You can close this window now.') . '</p><script>setTimeout(function(){window.close();},3000);</script></body></html>';
        } else {
            echo '<html><body><h1>' . $this->Translate('Authorization failed') . '</h1><p>' . $this->Translate('Please try again.') . '</p></body></html>';
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HTTP Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function GetHttpHeaderValue(array $Headers, string $Name): string
    {
        $needle = strtolower($Name) . ':';
        foreach ($Headers as $h) {
            $lh = strtolower($h);
            if (strpos($lh, $needle) === 0) {
                return trim(substr($h, strlen($needle)));
            }
        }
        return '';
    }

    private function GetHttpStatusCode(array $Headers): int
    {
        foreach ($Headers as $h) {
            if (preg_match('/^HTTP\/\d+\.?\d*\s+(\d+)/', $h, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    private function ResolveUrl(string $BaseUrl, string $Path): string
    {
        if (strpos($Path, '://') !== false) {
            return $Path;
        }

        $parsed = parse_url($BaseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $basePath = $parsed['path'] ?? '/';

        if ($Path === '') {
            $Path = $basePath;
        } elseif ($Path[0] !== '/') {
            $dir = $basePath;
            if ($dir === '') {
                $dir = '/';
            }
            if (substr($dir, -1) !== '/') {
                $dir .= '/';
            }
            $Path = $dir . ltrim($Path, '/');
        }

        if ($Path !== '' && $Path[0] !== '/') {
            $Path = '/' . $Path;
        }

        return $scheme . '://' . $host . $port . $Path;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Status Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function GetGoogleStatusLabel(): string
    {
        $connected = $this->GoogleIsConnected();
        return $this->Translate('Status') . ': ' . ($connected ? $this->Translate('Connected') : $this->Translate('Not connected'));
    }

    private function GetMicrosoftStatusLabel(): string
    {
        $connected = $this->MicrosoftIsConnected();
        return $this->Translate('Status') . ': ' . ($connected ? $this->Translate('Connected') : $this->Translate('Not connected'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Form Builders
    // ──────────────────────────────────────────────────────────────────────────

    private function GetGoogleFormElements(): array
    {
        return [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('Google Tasks'),
            'items' => [
                [
                    'type' => 'ValidationTextBox',
                    'caption' => $this->Translate('Redirect URI'),
                    'value' => $this->OAuthGetRedirectUri('/hook/todogateway_google/'),
                    'width' => '550px',
                    'enabled' => true
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'GoogleClientID',
                    'caption' => $this->Translate('Client ID'),
                    'width' => '400px'
                ],
                [
                    'type' => 'PasswordTextBox',
                    'name' => 'GoogleClientSecret',
                    'caption' => $this->Translate('Client Secret'),
                    'width' => '400px'
                ],
                [
                    'type' => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Authorize with Google'),
                            'onClick' => 'echo TGW_GoogleGetAuthUrl($id);'
                        ],
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Test Connection'),
                            'onClick' => 'TGW_GoogleTestConnection($id);'
                        ],
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Disconnect'),
                            'onClick' => 'TGW_GoogleDisconnect($id);'
                        ]
                    ]
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->GetGoogleStatusLabel()
                ]
            ]
        ];
    }

    private function GetMicrosoftFormElements(): array
    {
        return [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('Microsoft To Do'),
            'items' => [
                [
                    'type' => 'ValidationTextBox',
                    'caption' => $this->Translate('Redirect URI'),
                    'value' => $this->OAuthGetRedirectUri('/hook/todogateway_microsoft/'),
                    'width' => '750px',
                    'enabled' => true
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'MicrosoftClientID',
                    'caption' => $this->Translate('Client ID'),
                    'width' => '400px'
                ],
                [
                    'type' => 'PasswordTextBox',
                    'name' => 'MicrosoftClientSecret',
                    'caption' => $this->Translate('Client Secret'),
                    'width' => '400px'
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'MicrosoftTenant',
                    'caption' => $this->Translate('Tenant'),
                    'width' => '400px'
                ],
                [
                    'type' => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Authorize with Microsoft'),
                            'onClick' => 'echo TGW_MicrosoftGetAuthUrl($id);'
                        ],
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Test Connection'),
                            'onClick' => 'TGW_MicrosoftTestConnection($id);'
                        ],
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Disconnect'),
                            'onClick' => 'TGW_MicrosoftDisconnect($id);'
                        ]
                    ]
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->GetMicrosoftStatusLabel()
                ]
            ]
        ];
    }

    private function GetCalDAVFormElements(): array
    {
        return [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('CalDAV'),
            'items' => [
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'CalDAVServerURL',
                    'caption' => $this->Translate('Server URL'),
                    'width' => '400px'
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'CalDAVUsername',
                    'caption' => $this->Translate('Username'),
                    'width' => '250px'
                ],
                [
                    'type' => 'PasswordTextBox',
                    'name' => 'CalDAVPassword',
                    'caption' => $this->Translate('Password'),
                    'width' => '250px'
                ],
                [
                    'type' => 'Button',
                    'caption' => $this->Translate('Test Connection'),
                    'onClick' => 'TGW_CalDAVTestConnection($id);'
                ]
            ]
        ];
    }
}
