<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/OAuthHelper.php';

class ToDoGateway extends IPSModuleStrict
{
    use OAuthHelper;

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

        // CalDAV
        $this->RegisterPropertyString('CalDAVServerURL', '');
        $this->RegisterPropertyString('CalDAVUsername', '');
        $this->RegisterPropertyString('CalDAVPassword', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterGoogleWebHook();
        $this->RegisterMicrosoftWebHook();
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                $this->GetGoogleFormElements(),
                $this->GetMicrosoftFormElements(),
                $this->GetCalDAVFormElements()
            ]
        ];

        return json_encode($form);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Google Tasks
    // ──────────────────────────────────────────────────────────────────────────

    private function RegisterGoogleWebHook(): void
    {
        $this->OAuthRegisterWebHook('/hook/todogateway_google/');
    }

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
        $state = $this->InstanceID . '_' . bin2hex(random_bytes(8));

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

    private function GoogleGetValidAccessToken(): string
    {
        return $this->OAuthGetValidAccessToken(
            'GKey',
            'GoogleAccessToken', 'GoogleRefreshToken', 'GoogleTokenExpires',
            'https://oauth2.googleapis.com/token',
            trim($this->ReadPropertyString('GoogleClientID')),
            trim($this->ReadPropertyString('GoogleClientSecret')),
            'GoogleTasks'
        );
    }

    public function GoogleIsConnected(): bool
    {
        return $this->GoogleGetDecryptedToken('GoogleRefreshToken') !== '';
    }

    public function GoogleTestConnection(): bool
    {
        $token = $this->GoogleGetValidAccessToken();
        if ($token === '') {
            echo $this->Translate('Not connected to Google. Please authorize first.');
            return false;
        }

        $response = $this->OAuthHttpRequest('GET', 'https://tasks.googleapis.com/tasks/v1/users/@me/lists', [], null, true, 'GoogleTasks', $token);
        if ($response === null) {
            echo $this->Translate('Connection failed');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || isset($data['error'])) {
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

    public function GoogleApiRequest(string $Method, string $Endpoint, mixed $Body = null): ?array
    {
        $url = 'https://tasks.googleapis.com' . $Endpoint;
        $token = $this->GoogleGetValidAccessToken();
        $response = $this->OAuthHttpRequest($Method, $url, [], is_array($Body) ? json_encode($Body) : $Body, true, 'GoogleTasks', $token);

        if ($response === null) {
            return null;
        }

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

    public function GoogleFetchTaskLists(): array
    {
        $token = $this->GoogleGetValidAccessToken();
        if ($token === '') {
            return [];
        }

        $response = $this->OAuthHttpRequest('GET', 'https://tasks.googleapis.com/tasks/v1/users/@me/lists', [], null, true, 'GoogleTasks', $token);
        if ($response === null) {
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
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

    private function RegisterMicrosoftWebHook(): void
    {
        $this->OAuthRegisterWebHook('/hook/todogateway_microsoft/');
    }

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
        $state = $this->InstanceID . '_' . bin2hex(random_bytes(8));

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

    private function MicrosoftGetValidAccessToken(): string
    {
        $tenant = $this->MicrosoftGetTenant();
        return $this->OAuthGetValidAccessToken(
            'MKey',
            'MicrosoftAccessToken', 'MicrosoftRefreshToken', 'MicrosoftTokenExpires',
            'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
            trim($this->ReadPropertyString('MicrosoftClientID')),
            trim($this->ReadPropertyString('MicrosoftClientSecret')),
            'MicrosoftToDo',
            'offline_access Tasks.ReadWrite'
        );
    }

    public function MicrosoftIsConnected(): bool
    {
        return $this->MicrosoftGetDecryptedToken('MicrosoftRefreshToken') !== '';
    }

    public function MicrosoftTestConnection(): bool
    {
        $token = $this->MicrosoftGetValidAccessToken();
        if ($token === '') {
            echo $this->Translate('Not connected to Microsoft. Please authorize first.');
            return false;
        }

        $response = $this->OAuthHttpRequest('GET', 'https://graph.microsoft.com/v1.0/me/todo/lists', [], null, true, 'MicrosoftToDo', $token);
        if ($response === null) {
            echo $this->Translate('Connection failed');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || isset($data['error'])) {
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

    public function MicrosoftApiRequest(string $Method, string $Endpoint, mixed $Body = null): ?array
    {
        $url = 'https://graph.microsoft.com/v1.0' . $Endpoint;
        $token = $this->MicrosoftGetValidAccessToken();
        $response = $this->OAuthHttpRequest($Method, $url, [], is_array($Body) ? json_encode($Body) : $Body, true, 'MicrosoftToDo', $token);
        if ($response === null) {
            return null;
        }

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

    public function MicrosoftFetchLists(): array
    {
        $token = $this->MicrosoftGetValidAccessToken();
        if ($token === '') {
            return [];
        }

        $response = $this->OAuthHttpRequest('GET', 'https://graph.microsoft.com/v1.0/me/todo/lists', [], null, true, 'MicrosoftToDo', $token);
        if ($response === null) {
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
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
                $currentUrl = $this->ResolveUrl($currentUrl, $location);
                continue;
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

    public function ProcessHookData(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isGoogle = strpos($uri, '/hook/todogateway_google') !== false;
        $isMicrosoft = strpos($uri, '/hook/todogateway_microsoft') !== false;
        if (!$isGoogle && !$isMicrosoft) {
            return;
        }

        $code = $_GET['code'] ?? '';
        $error = $_GET['error'] ?? '';

        if ($error !== '') {
            echo '<html><body><h1>' . $this->Translate('Authorization failed') . '</h1><p>' . htmlspecialchars($error) . '</p></body></html>';
            return;
        }

        if ($code === '') {
            echo '<html><body><h1>' . $this->Translate('Authorization failed') . '</h1><p>' . $this->Translate('Please try again.') . '</p></body></html>';
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
                    'width' => '550px',
                    'enabled' => false
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
