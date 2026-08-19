<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/OAuthHelper.php';
require_once __DIR__ . '/libs/AppCore.php';
require_once __DIR__ . '/libs/MailScan.php';
require_once __DIR__ . '/libs/MailFetch.php';
require_once __DIR__ . '/libs/CalendarBridge.php';

/**
 * SymDo Gateway — die zentrale Dienst-Instanz der Listen-Familie.
 *
 * Zwei Aufgaben unter einem Dach:
 *  - Sync: Anmeldedaten und HTTP-Broker für Google Tasks, Microsoft To Do und CalDAV.
 *    Die ToDo-Listen rufen sie direkt als TGW_… auf.
 *  - App (trait AppCore): REST-API, Web-App, Push-Kanal und KI-Analyse für die
 *    iOS- und die Web-App.
 *
 * Die Sync-Seite darf mehrfach existieren (ein Instanzsatz pro Konto), die App-Seite
 * nicht — ihre Hook-Pfade sind fest. Deshalb bedient nur die Instanz mit der
 * niedrigsten ID die App; siehe OwnsAppApi().
 */
class SymDoGateway extends IPSModuleStrict
{
    use OAuthHelper;
    use AppCore;
    use MailScan;
    use MailFetch;
    use CalendarBridge;

    private const MODULE_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    /** SymDoWebApp — dort stehen die appweiten Bedienelemente der Oberflaeche. */
    private const WEBAPP_MODULE_GUID = '{6703A24A-E9E9-44D3-AB21-27176BF224AA}';

    /** Symcon-Kernmodul „E-Mail, Empfangen (IMAP)" — Quelle der weitergeleiteten Post. */
    private const IMAP_MODULE_GUID = '{CABFCCA1-FBFF-4AB7-B11B-9879E67E152F}';

    /**
     * Appweite Bedienelemente, einmal je PHP-Aufruf aufgeloest. Zwei Felder, weil
     * `null` ein gueltiges Ergebnis ist (keine Web-App-Instanz vorhanden) und sonst
     * bei jedem Aufruf erneut gesucht wuerde.
     */
    private ?array $webAppFlagsCache = null;
    private bool $webAppFlagsResolved = false;

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

        // Die Bedienelemente der Oberflaeche stehen NICHT mehr hier, sondern in der
        // SymDoWebApp-Instanz — dort, wo die Oberflaeche zusammengesetzt wird. Sie
        // gelten appweit fuer alle Listen; das Gateway reicht sie im ApiRouter nur
        // noch an die ausgelieferte Web-App durch.

        // App-Seite
        $this->AppCreate();
        // Aufgaben aus weitergeleiteten E-Mails (eigener Trait, nutzt die KI der App-Seite)
        $this->MailCreate();
        // Kalender: Zuordnung, Erinnerungen und ihr Timer
        $this->CalCreateProps();
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
            $this->AppApplyChanges();
            $this->MailApplyChanges();
            $this->CalApplyChanges();
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
        // Zuerst die Postfach-Variablen: sie loesen einen Mail-Lauf aus, nicht das
        // „irgendetwas hat sich geaendert" der Listen.
        if ($this->MailMessageSink($Message, $SenderID)) {
            return;
        }
        $this->AppMessageSink($Message);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($this->MailRequestAction($Ident, $Value)) {
            return;
        }
        if ($this->CalRequestAction($Ident, $Value)) {
            return;
        }
        if ($this->AppRequestAction($Ident, $Value)) {
            return;
        }
        parent::RequestAction($Ident, $Value);
    }

    /**
     * Die App-Seite hängt an festen Hook-Pfaden, kann also nur einmal im System
     * laufen — die Sync-Seite dagegen beliebig oft. Es gewinnt die niedrigste
     * Instanz-ID. Bewusst kein Blick auf den Status einer Geschwister-Instanz: der
     * klebt in Symcon und taugt nicht als Wahrheit.
     */
    private function OwnsAppApi(): bool
    {
        return $this->AppApiOwnerID() === $this->InstanceID;
    }

    /** @return int Instanz, die die App bedient; 0, wenn keine ermittelbar ist. */
    private function AppApiOwnerID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::MODULE_GUID);
        sort($ids);
        return (int)($ids[0] ?? 0);
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
        $this->AppProcessHook();
    }

    public function GetConfigurationForm(): string
    {
        // form.json traegt die App-Seite. Symcon mergt NICHT: ist
        // GetConfigurationForm ueberschrieben, gewinnt die Methode vollstaendig —
        // die Datei muss also selbst gelesen werden.
        $owner   = $this->AppApiOwnerID();
        $isOwner = ($owner === 0 || $owner === $this->InstanceID);
        if (!$isOwner) {
            // Zweites Gateway: die App-Panels gehören hier nicht hin. Kopplung, Nutzer
            // und KI wirken ausschließlich auf der bedienenden Instanz — Knöpfe, die
            // hier ins Leere griffen, sind schlimmer als keine.
            $appElements = [[
                'type'    => 'Label',
                'caption' => sprintf(
                    $this->Translate('This instance only works as a sync broker. The app API — pairing, users and AI analysis — runs on instance #%d.'),
                    $owner
                ),
            ]];
        } else {
            $app = json_decode((string)@file_get_contents(__DIR__ . '/form.json'), true);
            $appElements = (is_array($app) && isset($app['elements']) && is_array($app['elements']))
                ? $app['elements']
                : [];
        }

        $elements = array_merge(
            $appElements,
            $isOwner ? [$this->GetMailFormElements()] : [],
            [
                $this->GetGoogleFormElements(),
                $this->GetMicrosoftFormElements(),
                $this->GetCalDAVFormElements()
            ],
            $this->GetDonationFormElements()
        );

        // Nur auf der bedienenden Instanz: die Überschreibungen fassen Felder an, die
        // hier gar nicht stehen, und AiPrivacyStorable() prüft mit einer Schreibsonde.
        if ($isOwner) {
            $this->AppFormOverrides($elements);
        }

        return json_encode(['elements' => $elements], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }


    /**
     * Appweite Bedienelemente der Oberflaeche, gelesen aus der SymDoWebApp-Instanz.
     *
     * Sie standen bis zuletzt hier im Gateway. Verschoben, weil die Web-App die
     * Oberflaeche zusammensetzt und die Einstellung dort hingehoert, wo sie wirkt.
     * Das Gateway liefert unter /hook/lists/webapp dieselbe Oberflaeche aus, bezieht
     * den Zustand aber ueber den ApiRouter direkt aus den Listen — deshalb muss es
     * die Flags hier nachtraeglich aufpraegen.
     *
     * Gibt null zurueck, wenn es keine SymDoWebApp-Instanz gibt; dann bleiben die
     * Werte der einzelnen Listen stehen. Bei mehreren Instanzen gewinnt die mit der
     * niedrigsten ID — dieselbe Regel wie bei der bedienenden App-Instanz.
     *
     * @return array<string, bool>|null
     */
    private function ResolveWebAppButtonFlags(): ?array
    {
        if ($this->webAppFlagsResolved) {
            return $this->webAppFlagsCache;
        }
        $this->webAppFlagsResolved = true;

        $ids = @IPS_GetInstanceListByModuleID(self::WEBAPP_MODULE_GUID);
        if (!is_array($ids) || $ids === []) {
            return null;
        }
        sort($ids);
        // IPS_GetConfiguration statt IPS_GetProperty: die Eigenschaften entstehen in
        // Create() der Web-App und existieren erst beim naechsten Kernel-Start; bis
        // dahin liefert das Lesen `false` statt der Vorgabe.
        $cfg = json_decode((string)@IPS_GetConfiguration((int)$ids[0]), true);
        if (!is_array($cfg)) {
            return null;
        }
        $read = static function (string $name, bool $default) use ($cfg): bool {
            return array_key_exists($name, $cfg) ? (bool)$cfg[$name] : $default;
        };
        $this->webAppFlagsCache = [
            'showOverview'      => $read('ShowOverview', true),
            'showMemberBar'     => $read('ShowMemberBar', true),
            'showCreateButton'  => $read('ShowCreateButton', true),
            'showSorting'       => $read('ShowSorting', true),
            'showQuantityBadge' => $read('ShowQuantityBadge', true),
            'showRecurrenceBadge' => $read('ShowRecurrenceBadge', true),
            'showDueBadge'      => $read('ShowDueBadge', true),
            'showNotificationBadge' => $read('ShowNotificationBadge', true),
            'showPriorityBadge' => $read('ShowPriorityBadge', true),
            'showFavoriteHeart' => $read('ShowFavoriteHeart', true),
            'showEditButton'    => $read('ShowRowEditButton', false),
            'showDeleteButton'  => $read('ShowRowDeleteButton', false),
            'showReorderHandle' => $read('ShowReorderHandle', true),
        ];
        return $this->webAppFlagsCache;
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

    /**
     * Panel „Aufgaben aus E-Mails".
     *
     * Bewusst in PHP gebaut und nicht in form.json: die Mitglieder-Spalte der
     * Adresstabelle braucht die Nutzer als Auswahloptionen, und die stehen erst zur
     * Laufzeit fest.
     */
    private function GetMailFormElements(): array
    {
        $optionen = [['caption' => $this->Translate('— no member —'), 'value' => '']];
        foreach ($this->LoadUsers() as $u) {
            $optionen[] = ['caption' => $u['name'], 'value' => $u['id']];
        }

        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Tasks from e-mails'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate("Forward mail to a mailbox that Symcon reads — one address per household member, e.g. via a catch-all domain or plus addresses. The AI derives tasks from the text; nothing is created automatically, the suggestions appear above the task list in the web app and are added with one tap.\n\nA PDF or image attachment is read along with the text — parent letters often carry the actual dates in the attachment. Symcon's IMAP module cannot deliver attachments, so this uses a separate read-only lookup in the same mailbox.")
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'MailEnabled',
                    'caption' => $this->Translate('Analyze incoming mail')
                ],
                [
                    'type'    => 'List',
                    'name'    => 'MailBoxes',
                    'caption' => $this->Translate('Mailboxes'),
                    'rowCount' => 3,
                    'add'     => true,
                    'delete'  => true,
                    'columns' => [
                        [
                            'caption' => $this->Translate('IMAP instance'),
                            'name'    => 'InstanceID',
                            'width'   => 'auto',
                            'add'     => 0,
                            'edit'    => ['type' => 'SelectInstance', 'moduleID' => self::IMAP_MODULE_GUID]
                        ]
                    ]
                ],
                [
                    'type'    => 'List',
                    'name'    => 'MailAddresses',
                    'caption' => $this->Translate('Recipient address → member'),
                    'rowCount' => 5,
                    'add'     => true,
                    'delete'  => true,
                    'columns' => [
                        [
                            'caption' => $this->Translate('Recipient address'),
                            'name'    => 'Address',
                            'width'   => '280px',
                            'add'     => '',
                            'edit'    => ['type' => 'ValidationTextBox']
                        ],
                        [
                            'caption' => $this->Translate('Member'),
                            'name'    => 'UserID',
                            'width'   => 'auto',
                            'add'     => '',
                            'edit'    => ['type' => 'Select', 'options' => $optionen]
                        ]
                    ]
                ],
                $this->GetMailHookPanel(),
                [
                    'type'      => 'ValidationTextBox',
                    'name'      => 'MailSenderAllow',
                    'caption'   => $this->Translate('Allowed senders (one per line, "@domain.tld" for a whole domain; empty = all)'),
                    'multiline' => true,
                    'width'     => '400px'
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('A forwarded mail carries YOUR address as the sender, not the original one — so entering your own addresses here is the strongest filter: only mail forwarded from the household is ever analysed.')
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'MailDailyLimit',
                    'caption' => $this->Translate('Maximum AI calls per day (0 = no limit)'),
                    'minimum' => 0,
                    'width'   => '120px'
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'MailReadAttachments',
                    'caption' => $this->Translate('Also read PDF and image attachments')
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'MailDeleteAfter',
                    'caption' => $this->Translate('Delete mail after analysis (mailbox only)')
                ],
                [
                    'type'     => 'SelectInstance',
                    'name'     => 'CalNotifyVisuID',
                    'width'    => '400px',
                    // Nur die Kachel-Visualisierung versteht VISU_PostNotification;
                    // jede andere Instanz liesse den Erinnerungs-Timer werfen.
                    'validModules' => ['{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}'],
                    'caption'  => $this->Translate('Visualization instance for appointment reminders')
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Reminders for appointments are delivered the same way as task reminders: as a notification to this visualization instance. Without an instance a reminder stays pending instead of being lost.')
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type'    => 'Button',
                            'caption' => $this->Translate('Fetch and analyze now'),
                            'onClick' => 'IPS_RequestAction($id, \'MailScanNow\', 0);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => $this->Translate('Forget processed mail'),
                            'onClick' => 'IPS_RequestAction($id, \'MailForget\', 0);'
                        ]
                    ]
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Analysed mail stays in the mailbox but is not looked at again. "Forget processed mail" clears that memory — use it after fixing an address or sender list, then everything still in the mailbox is analysed once more.')
                ]
            ]
        ];
    }

    /**
     * Zweiter Eingang: Mail per Webhook (Mailgun).
     *
     * Bewusst ein eigenes Panel und nicht zwischen die Postfach-Felder gemischt —
     * es sind zwei getrennte Wege in dieselbe Analyse, und der Nutzer soll beim
     * Suchen eines Fehlers wissen, welcher davon gemeint ist.
     *
     * Die Adresse und der Routen-Ausdruck werden hier berechnet: Sie stehen sonst
     * nirgends, und ein falsch abgetipptes Geheimnis kostet eine Stunde Suche.
     */
    private function GetMailHookPanel(): array
    {
        $teile = $this->MailHookSetupParts(trim((string)$this->MailProp('MailHookSecret', '')));

        // Ueberblick: welches Mitglied hat welche Adresse — und wer noch keine.
        $zeilen = [];
        $karte = [];
        $roh = json_decode((string)$this->MailProp('MailAddresses', '[]'), true);
        foreach (is_array($roh) ? $roh : [] as $z) {
            $adresse = trim((string)($z['Address'] ?? ''));
            if ($adresse !== '') {
                $karte[trim((string)($z['UserID'] ?? ''))][] = $adresse;
            }
        }
        foreach ($this->LoadUsers() as $u) {
            $id = (string)($u['id'] ?? '');
            foreach ($karte[$id] ?? [] as $adresse) {
                $zeilen[] = ['Member' => (string)($u['name'] ?? ''), 'Address' => $adresse];
            }
            if (($karte[$id] ?? []) === []) {
                $zeilen[] = ['Member' => (string)($u['name'] ?? ''), 'Address' => $this->Translate('— no address yet —')];
            }
        }
        foreach ($karte[''] ?? [] as $adresse) {
            $zeilen[] = ['Member' => $this->Translate('(no member)'), 'Address' => $adresse];
        }

        $wartend = count($this->MailHookQueueFiles());

        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Mail via webhook (Mailgun) — no mailbox needed'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Mailgun accepts mail for you and delivers it here immediately — no mailbox, no own domain. One catch-all route is enough: members are told apart by plus addresses (base+lena@…), and the assignment happens in the list above. Both ways run side by side; the mailbox settings above stay untouched.')
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'MailHookEnabled',
                    'caption' => $this->Translate('Accept mail via webhook')
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'MailHookBase',
                    'width'   => '400px',
                    'caption' => $this->Translate('Mailgun domain (e.g. sandbox….mailgun.org) — or a fixed address if you prefer plus tags')
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type'    => 'PasswordTextBox',
                            'name'    => 'MailHookSecret',
                            'width'   => '380px',
                            'caption' => $this->Translate('Secret in the address')
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => $this->Translate('Create new secret'),
                            'onClick' => 'IPS_RequestAction($id, \'MailHookNewSecret\', 0);'
                        ]
                    ]
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'MailHookSigningKey',
                    'width'   => '400px',
                    'caption' => $this->Translate('Mailgun HTTP webhook signing key (verifies every delivery)')
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'MailHookApiKey',
                    'width'   => '400px',
                    'caption' => $this->Translate('Mailgun API key (only for the "Store and notify" path — not needed with Forward)')
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'MailHookSetup',
                    'caption' => $teile['hinweis']
                ],
                [
                    // Bewusst ein normales Eingabefeld: aus einer Beschriftung laesst
                    // sich nichts markieren, und ein ausgegrautes Feld ebenso wenig.
                    // Aenderungen daran sind folgenlos — der Inhalt wird bei jedem
                    // Aufbau neu berechnet und gehoert zu keiner Eigenschaft.
                    'type'      => 'ValidationTextBox',
                    'name'      => 'MailHookNotifyUrl',
                    'caption'   => $this->Translate('Address for Mailgun ("Forward" → Destination) — select and copy'),
                    'width'     => '600px',
                    'multiline' => true,
                    'value'     => $teile['url']
                ],
                [
                    'type'     => 'List',
                    'name'     => 'MailHookAddresses',
                    'caption'  => $this->Translate('Addresses per member (from the list above)'),
                    'rowCount' => 5,
                    'add'      => false,
                    'delete'   => false,
                    'columns'  => [
                        ['caption' => $this->Translate('Member'),  'name' => 'Member',  'width' => '200px'],
                        ['caption' => $this->Translate('Address'), 'name' => 'Address', 'width' => 'auto']
                    ],
                    'values'   => $zeilen
                ],
                [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Fill in plus addresses for all members'),
                    'onClick' => 'IPS_RequestAction($id, \'MailHookFillAddresses\', 0);'
                ],
                [
                    // Rueckmeldung der beiden Knoepfe. Bewusst ein Label und kein
                    // echo: eine Ausgabe aus RequestAction meldet Symcon als
                    // Skriptfehler samt Dateiname und Zeilennummer.
                    'type'    => 'Label',
                    'name'    => 'MailHookStatus',
                    'caption' => ''
                ],
                [
                    'type'      => 'ValidationTextBox',
                    'name'      => 'MailHookSenderAllow',
                    'multiline' => true,
                    'width'     => '400px',
                    'caption'   => $this->Translate('Allowed senders for the webhook (one per line; empty = all). Careful: here the school writes DIRECTLY, so your own addresses do not belong in this list.')
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'MailHookMaxKB',
                    'minimum' => 64,
                    'width'   => '120px',
                    'caption' => $this->Translate('Maximum size per delivery (KB) — attachments come along, so allow a few MB')
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'MailHookQueueInfo',
                    'caption' => $wartend === 0
                        ? $this->Translate('Queue is empty.')
                        : sprintf($this->Translate('%d message(s) waiting for analysis.'), $wartend)
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
