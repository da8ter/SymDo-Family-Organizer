<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/OAuthHelper.php';
require_once __DIR__ . '/libs/AppCore.php';
require_once __DIR__ . '/libs/MailScan.php';
require_once __DIR__ . '/libs/MailFetch.php';
require_once __DIR__ . '/libs/CalendarBridge.php';
require_once __DIR__ . '/libs/Briefing.php';
require_once __DIR__ . '/libs/WebPush.php';
require_once __DIR__ . '/libs/Notes.php';
require_once __DIR__ . '/libs/NotesMedia.php';
require_once __DIR__ . '/libs/NotesAi.php';

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
    use Briefing;
    use WebPush;
    use Notes;
    use NotesMedia;
    use NotesAi;

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
        // Zwei Anmeldewege NEBENEINANDER:
        //  'code'   — wie bisher: eigene App-Registrierung, Client Secret, Umleitung
        //             ueber den Hook. Bleibt unveraendert.
        //  'device' — Gerätecode (RFC 8628). Braucht KEIN Secret und KEINE
        //             Umleitungs-URI, weil im ganzen Ablauf keine vorkommt. Genau
        //             daran scheiterte eine mitgelieferte Client-ID bisher: Azure
        //             verlangt jede Umleitungs-URI vorab, und die Connect-Adresse
        //             ist bei jedem Nutzer eine andere.
        $this->RegisterPropertyString('MicrosoftAuthMode', 'code');
        $this->RegisterAttributeString('MicrosoftDeviceFlow', '');
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
        // Tagesbriefing: Einstellungen, Ablage und sein Timer
        $this->BriefingCreate();
        // Web Push: Ablage des VAPID-Schluesselpaars
        $this->PushCreate();
        // Notizen: Ablage und die Kategorie der Anhaenge
        $this->NotesCreate();
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
            $this->RegisterHook(self::PWA_HOOK_PATH);
            $this->AppApplyChanges();
            $this->MailApplyChanges();
            $this->CalApplyChanges();
            $this->BriefingApplyChanges();
            $this->PushApplyChanges();
            // Zuletzt: zieht Mitglieder-Ordner nach und braucht dafuer die
            // Kennungen, die AppApplyChanges ueber EnsureUserIDs vergibt.
            $this->NotesApplyChanges();
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
        if ($this->BriefingRequestAction($Ident, $Value)) {
            return;
        }
        if ($this->PushRequestAction($Ident, $Value)) {
            return;
        }
        if ($this->TtsRequestAction($Ident, $Value)) {
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
        // Ein Netz um den GANZEN Aufbau.
        //
        // Grund, am 22.08.2026 beobachtet: unmittelbar nach dem Neuschreiben der
        // Modul-Dateien ist die Instanz-Schnittstelle fuer eine Anfrage noch nicht
        // gebunden. `Translate()` liefert dann `false` statt einer Zeichenkette —
        // und ein `sprintf(false, …)` ist bei strict_types ein Fatal. Der Nutzer
        // sah statt der Konfiguration eine PHP-Fehlerseite, obwohl ein erneutes
        // Oeffnen genuegt.
        //
        // Einzelne Stellen zu haerten hilft nicht: in diesem Zustand liefert JEDER
        // Uebersetzungsaufruf false, der Fatal wanderte nur zur naechsten Stelle.
        // Deshalb hier, an der einen Stelle, die alle umfasst.
        try {
            return $this->BuildConfigurationForm();
        } catch (Throwable $e) {
            $this->SendDebug('Form', 'Aufbau gescheitert: ' . $e->getMessage(), 0);
            return (string)json_encode(['elements' => [[
                'type'    => 'Label',
                'caption' => "Die Konfiguration konnte gerade nicht aufgebaut werden — "
                    . "das passiert unmittelbar nach einem Modul-Update. Bitte dieses "
                    . "Fenster schliessen und erneut oeffnen.\n\n" . $e->getMessage()
            ]]]);
        }
    }

    private function BuildConfigurationForm(): string
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
            // Muss VOR den Ueberschreibungen passieren: die suchen Felder per Name,
            // und die des Briefings entstehen erst hier.
            //
            // Die Reihenfolge der Aufrufe IST die Reihenfolge im Formular
            // (AppendFormItem haengt hinten an): erst das Briefing, dann die
            // Mailanalyse, dann die Benachrichtigungen. Die Mailanalyse stand
            // vorher auf der obersten Ebene ueber Google/Microsoft — sie gehoert
            // aber in den KI-Bereich, weil sie dessen Schalter und dessen
            // Tagesdeckel gehorcht.
            $this->AppendFormItem($elements, 'AiPanel', $this->GetBriefingPanel());
            $this->AppendFormItem($elements, 'AiPanel', $this->GetMailFormElements());
            $this->AppendFormItem($elements, 'AiPanel', $this->GetPushPanel());
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

    /**
     * Gerätecode anfordern (Schritt 1 von 2).
     *
     * Microsofts Doku ist an der entscheidenden Stelle ausdruecklich: mit einem
     * PRIVATEN Konto ueber /common oder /consumers muss man sich auf der
     * Anmeldeseite ein zweites Mal anmelden, weil das Gerät nicht an die Cookies
     * kommt. Sonst gilt derselbe Ablauf wie bei Geschaeftskonten.
     *
     * `verification_uri_complete` gibt es bei Microsoft NICHT — es fuehrt also kein
     * Ein-Klick-Link zum Ziel, der Code muss eingetippt werden.
     */
    public function MicrosoftDeviceStart(): string
    {
        $clientId = trim($this->ReadPropertyString('MicrosoftClientID'));
        if ($clientId === '') {
            return $this->Translate('Please enter Client ID first.');
        }
        $tenant = $this->MicrosoftGetTenant();
        $meta = $this->OAuthHttpRequestMeta(
            'POST',
            'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/devicecode',
            [],
            ['client_id' => $clientId, 'scope' => 'offline_access Tasks.ReadWrite'],
            false,
            'MicrosoftToDo'
        );
        $daten = is_array($meta) ? json_decode((string)($meta['body'] ?? ''), true) : null;
        if (!is_array($daten) || ($daten['device_code'] ?? '') === '' || ($daten['user_code'] ?? '') === '') {
            // Der haeufigste Fall zuerst: die Registrierung erlaubt keine
            // oeffentlichen Clientflows. Microsoft antwortet dann mit
            // AADSTS7000218 und verlangt ein Secret, das es hier nicht gibt.
            $fehler = is_array($daten) ? (string)($daten['error_description'] ?? $daten['error'] ?? '') : '';
            $this->SendDebug('MicrosoftToDo', 'Gerätecode abgelehnt: ' . $fehler, 0);
            return $this->Translate('Requesting the device code failed.') . ' ' . mb_substr($fehler, 0, 300);
        }
        $this->WriteAttributeString('MicrosoftDeviceFlow', json_encode([
            'code'     => (string)$daten['device_code'],
            'expires'  => time() + max(60, (int)($daten['expires_in'] ?? 900)),
            'interval' => max(1, (int)($daten['interval'] ?? 5)),
        ]));
        return sprintf(
            $this->Translate('Open %s and enter this code: %s — then click "Complete sign-in" here.'),
            (string)($daten['verification_uri'] ?? 'https://microsoft.com/devicelogin'),
            (string)$daten['user_code']
        );
    }

    /**
     * Anmeldung abschliessen (Schritt 2 von 2).
     *
     * Bewusst ohne Timer: ein neu registrierter Timer existiert vor dem naechsten
     * Kernel-Neustart nicht und warnt beim Setzen in die Ausgabe. Ein zweiter
     * Knopfdruck ist ehrlicher als ein Wartebalken, der beim ersten Mal nicht
     * laeuft — der Nutzer steht ohnehin im Formular.
     */
    public function MicrosoftDeviceFinish(): string
    {
        $clientId = trim($this->ReadPropertyString('MicrosoftClientID'));
        $stand = json_decode($this->ReadAttributeString('MicrosoftDeviceFlow'), true);
        if (!is_array($stand) || ($stand['code'] ?? '') === '') {
            return $this->Translate('No sign-in running — request a device code first.');
        }
        if (time() > (int)($stand['expires'] ?? 0)) {
            $this->WriteAttributeString('MicrosoftDeviceFlow', '');
            return $this->Translate('The device code has expired. Please request a new one.');
        }
        $meta = $this->OAuthHttpRequestMeta(
            'POST',
            'https://login.microsoftonline.com/' . rawurlencode($this->MicrosoftGetTenant()) . '/oauth2/v2.0/token',
            [],
            [
                'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
                'client_id'   => $clientId,
                'device_code' => (string)$stand['code'],
            ],
            false,
            'MicrosoftToDo'
        );
        $daten = is_array($meta) ? json_decode((string)($meta['body'] ?? ''), true) : null;
        if (!is_array($daten)) {
            return $this->Translate('Connection failed');
        }
        // Die vier dokumentierten Zwischenstaende. „pending" ist der NORMALFALL,
        // solange der Nutzer noch tippt — er darf nicht wie ein Fehler aussehen.
        $fehler = (string)($daten['error'] ?? '');
        // „slow_down" gehoert zu „pending": RFC 8628 schickt es, wenn zu schnell
        // nachgefragt wird. Der Vorgang ist intakt — der Stand darf NICHT geleert
        // werden, sonst kostet ein doppelter Knopfdruck den ganzen Gerätecode.
        if ($fehler === 'authorization_pending' || $fehler === 'slow_down') {
            return $this->Translate('Not confirmed yet — finish the sign-in in the browser, then click again.');
        }
        // „access_denied" ist die Absage des Nutzers am Anmeldebildschirm; RFC 8628
        // nennt sie so, Microsoft schickt daneben auch „authorization_declined".
        if ($fehler === 'authorization_declined' || $fehler === 'access_denied') {
            $this->WriteAttributeString('MicrosoftDeviceFlow', '');
            return $this->Translate('Sign-in was declined.');
        }
        if ($fehler === 'expired_token') {
            $this->WriteAttributeString('MicrosoftDeviceFlow', '');
            return $this->Translate('The device code has expired. Please request a new one.');
        }
        if ($fehler === 'bad_verification_code') {
            $this->WriteAttributeString('MicrosoftDeviceFlow', '');
            return $this->Translate('The device code was not recognized. Please request a new one.');
        }
        if (($daten['access_token'] ?? '') === '') {
            $this->SendDebug('MicrosoftToDo', 'Gerätecode-Token unbrauchbar: ' . mb_substr((string)($meta['body'] ?? ''), 0, 300), 0);
            return $this->Translate('Connection failed');
        }
        // Ab hier derselbe Speicherweg wie beim Umleitungs-Verfahren, mit demselben
        // Schluesselvorsatz, damit beide Wege dieselben Attribute benutzen und der
        // Rest des Moduls nichts merkt. Zur Ablage siehe OAuthSetEncryptedToken:
        // sie VERSCHLEIERT (XOR mit einem aus der Instanz-ID abgeleiteten Vorsatz),
        // sie verschluesselt nicht — wer settings.json lesen kann, rechnet das
        // zurueck. Auf mehr als Sichtschutz darf sich hier nichts verlassen.
        $this->OAuthSetEncryptedToken('MicrosoftAccessToken', (string)$daten['access_token'], 'MKey');
        $this->WriteAttributeInteger('MicrosoftTokenExpires', time() + max(60, (int)($daten['expires_in'] ?? 3600)) - 60);
        $this->WriteAttributeString('MicrosoftDeviceFlow', '');
        if (($daten['refresh_token'] ?? '') === '') {
            // Ohne Auffrischungs-Token endet der Zugriff in einer Stunde, und
            // MicrosoftIsConnected() prueft genau dieses Token — der Knopf duerfte
            // also keinen Erfolg melden, waehrend das Statuslabel „Nicht verbunden"
            // sagt. Ursache ist praktisch immer ein fehlendes offline_access.
            $this->SendDebug('MicrosoftToDo', 'Gerätecode: kein refresh_token in der Antwort', 0);
            return $this->Translate('Signed in, but without lasting access (offline_access is missing in the app registration).');
        }
        $this->OAuthSetEncryptedToken('MicrosoftRefreshToken', (string)$daten['refresh_token'], 'MKey');
        $this->SendDebug('MicrosoftToDo', 'Gerätecode: Anmeldung erfolgreich', 0);
        return $this->Translate('Connected to Microsoft.');
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
        // Auch einen offenen Gerätecode wegraeumen: er bleibt sonst bis zu 15 Minuten
        // gueltig, und ein Druck auf „Anmeldung abschliessen" wuerde das gerade
        // getrennte Konto wieder verbinden.
        @$this->WriteAttributeString('MicrosoftDeviceFlow', '');
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
        $error = (string)($_GET['error'] ?? '');
        $state = (string)($_GET['state'] ?? '');

        if ($error !== '') {
            // Die Beschreibung MUSS mit: „invalid_request" allein sagt nichts, der
            // Grund steht im AADSTS-Code darin (50011 = Umleitungs-URI passt nicht,
            // 7000218 = oeffentliche Clientflows nicht erlaubt, 50020 = privates
            // Konto in einem Mandanten gesucht). Ohne sie sucht man blind — genau
            // das ist am 22.08.2026 passiert.
            $beschreibung = trim((string)($_GET['error_description'] ?? ''));
            $spur = trim((string)($_GET['error_uri'] ?? ''));
            $this->SendDebug('OAuth', 'Rueckleitung mit Fehler: ' . $error . ' — ' . $beschreibung, 0);
            echo '<html><body style="font-family:sans-serif;padding:1.5rem;line-height:1.5">'
                . '<h1>' . $this->Translate('Authorization failed') . '</h1>'
                . '<p><b>' . htmlspecialchars($error) . '</b></p>'
                . ($beschreibung !== '' ? '<p>' . htmlspecialchars($beschreibung) . '</p>' : '')
                // NUR http/https verlinken. htmlspecialchars maskiert Anfuehrungs-
                // zeichen, aber kein Schema: ein `error_uri=javascript:…` waere ein
                // anklickbarer Link, der im Ursprung dieser Connect-Adresse laeuft —
                // demselben, in dem die Web-App ihr Kopplungs-Token ablegt. Alles
                // andere erscheint als reiner Text.
                . ($spur !== ''
                    ? (preg_match('#^https?://#i', $spur) === 1
                        ? '<p><a href="' . htmlspecialchars($spur) . '" rel="noreferrer noopener">' . htmlspecialchars($spur) . '</a></p>'
                        : '<p>' . htmlspecialchars($spur) . '</p>')
                    : '')
                . '</body></html>';
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
        // Ueber IPS_GetConfiguration und nicht ReadPropertyString: die Eigenschaft ist
        // neu und existiert vor dem naechsten Kernel-Neustart nicht — das Lesen
        // gaebe eine PHP-Warnung, die kein try/catch faengt.
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $msMode = (is_array($cfg) && ($cfg['MicrosoftAuthMode'] ?? '') === 'device') ? 'device' : 'code';
        return [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('Microsoft To Do'),
            'items' => [
                [
                    'type' => 'Select',
                    'name' => 'MicrosoftAuthMode',
                    'caption' => $this->Translate('Sign-in method'),
                    'width' => '400px',
                    // Ohne onChange wirkte die Auswahl erst NACH dem Speichern: die
                    // Sichtbarkeiten unten stammen aus der gespeicherten
                    // Konfiguration. Wer auf „Gerätecode" stellte, sah die beiden
                    // zugehoerigen Knoepfe nicht — sie schienen zu fehlen.
                    // Bewusst ueber IPS_RequestAction und nicht ueber eine neue
                    // public TGW_-Methode: die gaebe es erst nach einem
                    // Kernel-Neustart (Muster wie bei AppRequestAction).
                    'onChange' => 'IPS_RequestAction($id, "MicrosoftAuthMode", $MicrosoftAuthMode);',
                    'options' => [
                        ['caption' => $this->Translate('Redirect (needs Client Secret and Redirect URI)'), 'value' => 'code'],
                        ['caption' => $this->Translate('Device code (no secret, enter a code in the browser)'), 'value' => 'device'],
                    ]
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->Translate('The device code needs neither a secret nor a redirect URI — no redirect occurs in that flow. In the app registration, "Allow public client flows" must be set to Yes, otherwise Microsoft answers with AADSTS7000218 and asks for a secret. Private Microsoft accounts work with both methods; with the device code you sign in a second time on the verification page, because the box cannot reach your cookies.')
                ],
                [
                    'type' => 'ValidationTextBox',
                    // Name nur, damit onChange die Sichtbarkeit umschalten kann —
                    // es gibt keine Property dieses Namens (der Wert ist berechnet).
                    'name' => 'MsRedirectUriInfo',
                    'caption' => $this->Translate('Redirect URI'),
                    'value' => $this->OAuthGetRedirectUri('/hook/todogateway_microsoft/'),
                    'width' => '750px',
                    'enabled' => true,
                    'visible' => $msMode === 'code'
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
                    'width' => '400px',
                    'visible' => $msMode === 'code'
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'MicrosoftTenant',
                    'caption' => $this->Translate('Tenant'),
                    'width' => '400px'
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->Translate('Leave this on "common": then private Microsoft accounts (outlook.com, hotmail.com, live.com) can sign in as well as work and school accounts. "consumers" allows private accounts only, "organizations" only business ones. Putting a directory ID (GUID) here locks private accounts out — the sign-in then fails with AADSTS50020. Second condition, and it is set in the app registration, not here: under "Supported account types" it must say "Accounts in any organizational directory and personal Microsoft accounts".')
                ],
                [
                    'type' => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Button',
                            'name' => 'MsAuthorizeButton',
                            'caption' => $this->Translate('Authorize with Microsoft'),
                            'onClick' => 'echo TGW_MicrosoftGetAuthUrl($id);',
                            'visible' => $msMode === 'code'
                        ],
                        [
                            'type' => 'Button',
                            'name' => 'MsDeviceStartButton',
                            'caption' => $this->Translate('Request device code'),
                            'onClick' => 'echo TGW_MicrosoftDeviceStart($id);',
                            'visible' => $msMode === 'device'
                        ],
                        [
                            'type' => 'Button',
                            'name' => 'MsDeviceFinishButton',
                            'caption' => $this->Translate('Complete sign-in'),
                            'onClick' => 'echo TGW_MicrosoftDeviceFinish($id);',
                            'visible' => $msMode === 'device'
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
     * Panel „KI E-Mail Analyse".
     *
     * Aufbau: Einleitung, darunter die beiden Wege als je ein aufklappbares
     * Panel — zuerst die Postfaecher, dann Mailgun — und unten, was fuer beide
     * gilt. Bewusst in PHP gebaut und nicht in form.json: beide Tabellen fuehren
     * je Familienmitglied eine Zeile, und die stehen erst zur Laufzeit fest.
     */
    private function GetMailFormElements(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('AI e-mail analysis'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate("Many people know the problem: every day e-mails pour in from school, sports clubs, after-school care, day care or parent groups. Every one of them with some to-do, some information, some date. It is easy to lose track.\n\nThe AI e-mail analysis takes that work off your hands: no more typing out appointments and tasks. The AI reads the mail, recognises appointments and tasks and shows them as suggestions. Take them over into the calendar or the task list with one click.\n\nThe analysis runs only with the consent given under \"AI features\" — the privacy notice there also covers the e-mail paths.")                ],
                [
                    // Wegweiser vor die beiden Panels: eingeklappt verraten deren
                    // Beschriftungen nicht, worin sich die Wege unterscheiden.
                    'type'    => 'Label',
                    'caption' => $this->Translate("There are two ways to get mail in — use one of them or both:\n\n1. Mailbox via IMAP: Symcon polls a mailbox. A sender filter is possible but not required, and each family member can be given their own mailbox.\n\n2. Forward mail to Symcon: you forward the mail that matters to Symcon. There is one general family address, and every family member gets an own receiving address. This needs a free account with the Mailgun service. The upside: you keep full control over which mail reaches Symcon.")
                ],
                $this->GetMailBoxPanel(),
                $this->GetMailHookPanel(),
                [
                    'type'    => 'Label',
                    'bold'    => true,
                    'caption' => $this->Translate('Applies to both ways')
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'MailReadAttachments',
                    'caption' => $this->Translate('Also read PDF and image attachments')
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'MailNoteAttachments',
                    'caption' => $this->Translate('Keep the attachment when a note is suggested')
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('The file is then stored below "Notizen" as soon as the mail is analyzed — even if the suggestion is later discarded. Unused files are removed automatically.')
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
     * Erstes Panel: die Postfaecher.
     *
     * Eingeklappt als Vorgabe, damit der Bereich nicht mit zwei vollen
     * Einstellungssaetzen aufschlaegt.
     *
     * Zugeordnet wird hier ueber das POSTFACH, nicht ueber die Empfaengeradresse:
     * Wer ein eigenes Postfach hat, ist damit erkannt, und im Haushalts-Postfach
     * ist ohnehin niemand bestimmter gemeint. Eine Adressliste braucht dieser Weg
     * deshalb nicht mehr — sie steht beim Webhook, wo sie gebraucht wird.
     */
    private function GetMailBoxPanel(): array
    {
        // Ein Formularfeld auf eine Eigenschaft zu setzen, die es noch nicht gibt,
        // laesst „Uebernehmen" scheitern: Eigenschaften entstehen in Create() und
        // damit erst beim naechsten Kernel-Start. Bis dahin lieber ein Hinweis
        // als ein Feld, das die Eingabe schluckt.
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $allgemein = is_array($cfg) && array_key_exists('MailBoxGeneral', $cfg)
            ? [
                'type'         => 'SelectInstance',
                'name'         => 'MailBoxGeneral',
                'width'        => '800px',
                'validModules' => [self::IMAP_MODULE_GUID],
                'caption'      => $this->Translate('Household mailbox (no member assignment)')
            ]
            : [
                'type'    => 'Label',
                'caption' => $this->Translate('The field for the household mailbox appears after the next Symcon restart — it is a new setting, and those only exist once the kernel has loaded the module again.')
            ];

        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('1. Fetch mail from IMAP mailboxes'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'CheckBox',
                    'name'    => 'MailEnabled',
                    'caption' => $this->Translate('Analyze incoming mail')
                ],
                $allgemein,
                [
                    'type'      => 'ValidationTextBox',
                    'name'      => 'MailSenderAllow',
                    'caption'   => $this->Translate('Allowed senders for the household mailbox (one per line, "@domain.tld" for a whole domain; empty = all)'),
                    'multiline' => true,
                    'width'     => '800px'
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Careful with forwarded mail: it carries YOUR address as the sender, not the original one — so there your own address is the one that belongs in the list.')
                ],
                $this->GetMailBoxList()
            ]
        ];
    }

    /**
     * Die Zuordnungstabelle: je Familienmitglied eine Zeile.
     *
     * Die Zeilen entstehen aus der Mitgliederliste, nicht aus dem Gespeicherten —
     * deshalb `loadValuesFromConfiguration => false`. Die Konsole wuerde das
     * Gespeicherte sonst nach ZEILENNUMMER ueber die Vorgaben mischen; nach dem
     * Umbenennen oder Loeschen eines Mitglieds saesse die Einstellung dann beim
     * falschen. Zugeordnet wird hier ueber die Mitglieds-ID, die als unsichtbare
     * Spalte mitreist.
     */
    private function GetMailBoxList(): array
    {
        $gespeichert = [];
        foreach ((array)json_decode((string)$this->MailProp('MailBoxes', '[]'), true) as $zeile) {
            if (is_array($zeile)) {
                $gespeichert[trim((string)($zeile['UserID'] ?? ''))] = $zeile;
            }
        }

        $zeilen = [];
        foreach ($this->LoadUsers() as $u) {
            $id  = (string)($u['id'] ?? '');
            $alt = $gespeichert[$id] ?? [];
            $zeilen[] = [
                'UserID'      => $id,
                'Name'        => (string)($u['name'] ?? ''),
                'InstanceID'  => (int)($alt['InstanceID'] ?? 0),
                'SenderAllow' => (string)($alt['SenderAllow'] ?? '')
            ];
        }

        return [
            'type'     => 'List',
            'name'     => 'MailBoxes',
            'caption'  => $this->Translate('Member mailboxes: appointments and tasks are assigned to the matching family member automatically'),
            'rowCount' => max(2, count($zeilen)),
            'add'      => false,
            'delete'   => false,
            'loadValuesFromConfiguration' => false,
            'values'   => $zeilen,
            'columns'  => [
                [
                    'caption' => $this->Translate('Member'),
                    'name'    => 'Name',
                    'width'   => '160px',
                    'add'     => ''
                ],
                [
                    'caption' => $this->Translate('IMAP instance'),
                    'name'    => 'InstanceID',
                    'width'   => '280px',
                    'add'     => 0,
                    'edit'    => ['type' => 'SelectInstance', 'moduleID' => self::IMAP_MODULE_GUID]
                ],
                [
                    'caption' => $this->Translate('Allowed senders (empty = every mail is analysed)'),
                    'name'    => 'SenderAllow',
                    'width'   => 'auto',
                    'add'     => '',
                    'edit'    => ['type' => 'ValidationTextBox']
                ],
                [
                    // Traegt die Zuordnung, gehoert aber nicht ins Bild.
                    'caption' => 'UserID',
                    'name'    => 'UserID',
                    'width'   => '10px',
                    'visible' => false,
                    'add'     => '',
                    'edit'    => ['type' => 'ValidationTextBox', 'visible' => false]
                ]
            ]
        ];
    }

    /**
     * Zweites Panel: Mail per Webhook (Mailgun).
     *
     * Bewusst ein eigenes Panel und nicht zwischen die Postfach-Felder gemischt
     * — es sind zwei getrennte Wege in dieselbe Analyse, und der Nutzer soll
     * beim Suchen eines Fehlers wissen, welcher davon gemeint ist.
     *
     * Die Adresse und der Routen-Ausdruck werden hier berechnet: Sie stehen sonst
     * nirgends, und ein falsch abgetippter Token kostet eine Stunde Suche.
     */
    private function GetMailHookPanel(): array
    {
        $teile   = $this->MailHookSetupParts(trim((string)$this->MailProp('MailHookSecret', '')));
        $wartend = count($this->MailHookQueueFiles());

        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('2. Forward mail to Symcon'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Set up a free account at https://www.mailgun.com and configure it as described below.')
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'MailHookEnabled',
                    'caption' => $this->Translate('Activate mail analysis')
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
                            'caption' => $this->Translate('Webhook token')
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => $this->Translate('Generate new token'),
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
                $this->GetMailAddressList(),
                [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Generate receiving addresses'),
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
                    'type'    => 'NumberSpinner',
                    'name'    => 'MailHookMaxKB',
                    'minimum' => 64,
                    'width'   => '360px',
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

    /**
     * Adresstabelle des Webhook-Weges: erst das Mitglied, dann seine Adresse.
     *
     * Wie in der Postfach-Tabelle entstehen die Zeilen aus den Mitgliedern und
     * nicht aus dem Gespeicherten (`loadValuesFromConfiguration => false`) — die
     * Konsole wuerde sonst nach ZEILENNUMMER mischen. Deshalb braucht es hier
     * auch kein Hinzufuegen und kein Loeschen: Wer eine Adresse nicht will,
     * leert das Feld.
     */
    private function GetMailAddressList(): array
    {
        $zeilen = $this->MailAddressRows();

        return [
            'type'     => 'List',
            'name'     => 'MailAddresses',
            'caption'  => $this->Translate('Member → receiving address'),
            'rowCount' => max(2, count($zeilen)),
            'add'      => false,
            'delete'   => false,
            'loadValuesFromConfiguration' => false,
            'values'   => $zeilen,
            'columns'  => [
                [
                    'caption' => $this->Translate('Member'),
                    'name'    => 'Name',
                    'width'   => '160px',
                    'add'     => ''
                ],
                [
                    'caption' => $this->Translate('Recipient address'),
                    'name'    => 'Address',
                    'width'   => '280px',
                    'add'     => '',
                    'edit'    => ['type' => 'ValidationTextBox']
                ],
                [
                    'caption' => $this->Translate('Allowed senders (empty = every mail is analysed)'),
                    'name'    => 'SenderAllow',
                    'width'   => 'auto',
                    'add'     => '',
                    'edit'    => ['type' => 'ValidationTextBox']
                ],
                [
                    // Traegt die Zuordnung, gehoert aber nicht ins Bild.
                    'caption' => 'UserID',
                    'name'    => 'UserID',
                    'width'   => '10px',
                    'visible' => false,
                    'add'     => '',
                    'edit'    => ['type' => 'ValidationTextBox', 'visible' => false]
                ]
            ]
        ];
    }

    /**
     * Panel „Taegliches Briefing" — sitzt in den KI-Funktionen, weil es eine ist.
     *
     * In PHP gebaut und nicht in form.json: Die Auswahl „fuer wen" braucht die
     * Familienmitglieder als Optionen, und die stehen erst zur Laufzeit fest.
     */
    /**
     * Panel „Benachrichtigungen". Zeigt den Zustand und die drei Auslöser.
     *
     * Ein- und ausgeschaltet wird je Gerät in der Web-App (Glocke auf der
     * Übersicht) — hier steht nur, WAS eine Nachricht auslöst. Das ist die richtige
     * Trennung: Die Erlaubnis kann technisch nur der Browser des Geräts geben.
     */
    /**
     * „Personas bearbeiten": je Persona eine Zeile, je Anbieter eine Spalte.
     *
     * Die eingebauten Stimmen stehen in BriefingPersonas(); leer gelassene Zellen
     * heissen „wie eingebaut" und werden nicht gespeichert — so bleibt die Vorgabe
     * die Wahrheit, auch wenn sie sich in einer spaeteren Fassung aendert.
     *
     * Bei ElevenLabs kommen die Auswahlmoeglichkeiten aus dem Konto und nicht aus
     * einer festen Liste: die Kennungen sind kontoeigen. Sie stammen aus dem letzten
     * „Stimmen des Kontos abrufen" (Rubrik „Meine Stimmen"). Vor dem ersten Abruf
     * bleibt die Spalte leer und nennt den Grund — eine Auswahl mit erfundenen
     * Kennungen waere schlimmer als keine.
     */
    private function GetPersonaEditor(): array
    {
        // Vor dem Kernel-Neustart gibt es die Property nicht; ein Listenfeld darauf
        // liesse „Uebernehmen" scheitern. Dann nur der Hinweis (Muster wie oben).
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('BriefingVoices', $cfg)) {
            return [
                'type'    => 'Label',
                'caption' => $this->Translate('The persona editor appears after the next Symcon restart — it is a new setting, and those only exist once the kernel has loaded the module again.')
            ];
        }

        $openai = [];
        foreach (self::TTS_OPENAI_VOICES as $v) {
            $openai[] = ['caption' => $v, 'value' => $v];
        }
        $azure = [];
        foreach (self::TTS_AZURE_GERMAN_VOICES as $v) {
            // Ohne den Vorsatz „de-DE-" und das Anhaengsel „Neural" liest sich die
            // Spalte als Namensliste; gespeichert wird die vollstaendige Kennung.
            $azure[] = ['caption' => str_replace(['de-DE-', 'Neural'], '', $v), 'value' => $v];
        }
        $eleven = [];
        foreach ($this->TtsElevenCachedVoices() as $v) {
            $eleven[] = [
                'caption' => $v['name'] . ($v['info'] !== '' ? ' (' . $v['info'] . ')' : ''),
                'value'   => $v['id'],
            ];
        }
        // „Wie eingebaut" muss waehlbar BLEIBEN, sonst kann man eine Aenderung nicht
        // zurueknehmen. Steht bewusst oben.
        $wieEingebaut = ['caption' => $this->Translate('— as built in —'), 'value' => ''];
        array_unshift($openai, $wieEingebaut);
        array_unshift($azure, $wieEingebaut);
        array_unshift($eleven, ['caption' => $this->Translate('— the voice set above —'), 'value' => '']);

        // Zeilen: immer ALLE Personas, mit dem gespeicherten Stand gefuellt. Eine
        // fehlende Zeile waere sonst eine Persona, die man nicht einstellen kann.
        $stand = json_decode((string)($cfg['BriefingVoices'] ?? '[]'), true);
        $nach  = [];
        foreach (is_array($stand) ? $stand : [] as $z) {
            if (is_array($z) && trim((string)($z['tone'] ?? '')) !== '') {
                $nach[(string)$z['tone']] = $z;
            }
        }
        $zeilen = [];
        foreach ($this->BriefingPersonas() as $p) {
            $z = $nach[$p['key']] ?? [];
            $zeilen[] = [
                'tone'    => $p['key'],
                'persona' => $this->Translate($p['caption']),
                'openai'  => (string)($z['openai'] ?? ''),
                'azure'   => (string)($z['azure'] ?? ''),
                'eleven'  => (string)($z['eleven'] ?? ''),
                // Die eingebauten Stimmen als Anzeige daneben: sonst sieht man bei
                // „wie eingebaut" nicht, WAS eingebaut ist.
                'vorgabe' => $p['openai'] . ' / ' . str_replace(['de-DE-', 'Neural'], '', $p['azure']),
            ];
        }

        return [
            'type'    => 'PopupButton',
            'caption' => $this->Translate('Edit personas'),
            'popup'   => [
                'caption' => $this->Translate('Voice per persona and provider'),
                'items'   => [
                    [
                        'type'    => 'Label',
                        'caption' => $this->Translate('One row per persona, one column per provider. An empty cell means the built-in voice — the column "Built in" shows which that is (OpenAI / Azure). Only the provider currently selected above is actually used; the other columns are kept for a later switch.')
                    ],
                    [
                        'type'    => 'List',
                        'name'    => 'BriefingVoices',
                        'rowCount' => count($zeilen),
                        'add'     => false,
                        'delete'  => false,
                        'columns' => [
                            ['caption' => $this->Translate('Persona'), 'name' => 'persona', 'width' => '170px'],
                            ['caption' => $this->Translate('Built in'), 'name' => 'vorgabe', 'width' => '200px'],
                            ['caption' => 'OpenAI', 'name' => 'openai', 'width' => '160px',
                             'edit' => ['type' => 'Select', 'options' => $openai]],
                            ['caption' => 'Azure', 'name' => 'azure', 'width' => '200px',
                             'edit' => ['type' => 'Select', 'options' => $azure]],
                            ['caption' => 'ElevenLabs', 'name' => 'eleven', 'width' => '220px',
                             'edit' => ['type' => 'Select', 'options' => $eleven]],
                            // KEINE unsichtbare Schluessel-Spalte mehr: Symcon schreibt
                            // die Werte unsichtbarer Spalten nicht mit, die Zeilen kamen
                            // ohne `tone` zurueck. Die Zuordnung laeuft jetzt ueber die
                            // Reihenfolge (siehe BriefingVoiceMap) — der Schluessel steht
                            // trotzdem in den Werten, falls Symcon ihn doch einmal
                            // durchreicht.
                        ],
                        'values'  => $zeilen
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => count($eleven) <= 1
                            ? $this->Translate('The ElevenLabs column is empty: press "List voices of the account" under the speech provider first — the voices of an account are not generally known, they have to be fetched. Only voices from "My Voices" are offered.')
                            : sprintf($this->Translate('ElevenLabs: %d voices from "My Voices" available.'), count($eleven) - 1)
                    ],
                ]
            ]
        ];
    }

    private function GetPushPanel(): array
    {
        $einleitung = [
            'type'    => 'Label',
            'caption' => $this->Translate("Notifications reach the web app even when it is closed — on the phone, on the tablet, on the computer.\n\nSwitched on per device: open the web app, tap the bell on the overview and allow notifications. On an iPhone the web app has to be added to the home screen first; Apple only allows notifications for an installed web app.")
        ];

        // Felder auf Eigenschaften, die es noch nicht gibt, lassen „Uebernehmen"
        // scheitern — sie entstehen in Create() und damit erst beim naechsten
        // Kernel-Start. Bis dahin ein Hinweis statt eines Formulars, das die Eingabe
        // schluckt.
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('PushOnTaskDue', $cfg)) {
            return [
                'type'     => 'ExpansionPanel',
                'caption'  => $this->Translate('Notifications'),
                'expanded' => false,
                'items'    => [
                    $einleitung,
                    [
                        'type'    => 'Label',
                        'caption' => $this->Translate('The switches for what triggers a notification appear after the next Symcon restart — they are new settings, and those only exist once the kernel has loaded the module again.')
                    ],
                ],
            ];
        }

        $abos = $this->PushSubscriptions();
        $schluessel = $this->PushPublicKey();
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Notifications'),
            'expanded' => false,
            // Zwei Themen, denn es sind zwei voneinander unabhaengige Wege: Web Push
            // geht an die Geraete, die sich selbst angemeldet haben, und
            // VISU_PostNotification an EINE Symcon-Visualisierung. Sie teilen weder
            // Schalter noch Empfaenger — nebeneinander in einer Liste sah das aus wie
            // ein Weg mit vielen Einstellungen.
            'items'    => [
                [
                    'type'    => 'Label',
                    'bold'    => true,
                    'caption' => $this->Translate('1. Web app notifications')
                ],
                $einleitung,
                [
                    'type'    => 'Label',
                    'caption' => $schluessel === ''
                        // Ohne Schluessel geht gar nichts, und der Grund steht dann im
                        // Meldungsprotokoll — hier nur der Hinweis darauf.
                        ? $this->Translate('No key for notifications could be created. See the message log.')
                        : sprintf(
                            $this->Translate('Devices with notifications switched on: %d'),
                            count($abos)
                        )
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'PushOnTaskDue',
                    'caption' => $this->Translate('When a task becomes due (uses the reminder set on the task)')
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'PushOnBriefing',
                    'caption' => $this->Translate('When the daily briefing has been written')
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'PushOnMailProposal',
                    'caption' => $this->Translate('When the mail analysis has found appointments or tasks')
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('A task reminder goes to the devices of the member it is assigned to, plus every device that is not assigned to anyone. Assign a device in the web app under the bell.')
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type'     => 'Button',
                            'caption'  => $this->Translate('Send a test notification to all devices'),
                            'confirm'  => $this->Translate('Every device with notifications switched on will receive a test notification. Continue?'),
                            'onClick'  => 'IPS_RequestAction($id, \'PushTestAll\', 0);'
                        ],
                        [
                            // Messnachricht: klaert am Geraet, welche Bausteine iOS
                            // wirklich anzeigt (Bild, Knoepfe, eigenes Symbol, langer
                            // Text). Die Berichte im Netz widersprechen sich.
                            'type'     => 'Button',
                            'caption'  => $this->Translate('Test with all extras'),
                            'confirm'  => $this->Translate('Sends one notification carrying an image, buttons, an icon and a long text — to see what this device actually shows. Continue?'),
                            'onClick'  => 'IPS_RequestAction($id, \'PushTestFull\', 0);'
                        ],
                    ]
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'PushStatus',
                    'caption' => ' '
                ],
                [
                    'type'    => 'Label',
                    'bold'    => true,
                    'caption' => $this->Translate('2. Visualization notifications')
                ],
                // Bewusst ohne Erklaertexte (Wunsch vom 22.08.2026): Ueberschrift und
                // Feld. Was dieser Weg tut, sagt die Ueberschrift; was ohne Instanz
                // passiert, steht im Kommentar von CalNotifyReminder.
                [
                    'type'     => 'SelectInstance',
                    'name'     => 'CalNotifyVisuID',
                    'width'    => '600px',
                    // Nur die Kachel-Visualisierung versteht VISU_PostNotification;
                    // jede andere Instanz liesse den Erinnerungs-Timer werfen.
                    'validModules' => ['{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}'],
                    'caption'  => $this->Translate('Visualization instance')
                ],
            ],
        ];
    }

    private function GetBriefingPanel(): array
    {
        // Felder auf Eigenschaften, die es noch nicht gibt, lassen „Uebernehmen"
        // scheitern — sie entstehen in Create() und damit erst beim naechsten
        // Kernel-Start. Bis dahin ein Hinweis statt eines Formulars, das die
        // Eingabe schluckt.
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('BriefingEnabled', $cfg)) {
            return [
                'type'     => 'ExpansionPanel',
                'caption'  => $this->Translate('Daily briefing'),
                'expanded' => false,
                'items'    => [[
                    'type'    => 'Label',
                    'caption' => $this->Translate('The briefing settings appear after the next Symcon restart — they are new settings, and those only exist once the kernel has loaded the module again.')
                ]]
            ];
        }

        $wer = [['caption' => $this->Translate('— as set in the web app —'), 'value' => '']];
        foreach ($this->LoadUsers() as $u) {
            $wer[] = ['caption' => $u['name'], 'value' => $u['id']];
        }

        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Daily briefing'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate("Once a day the AI writes a short text for the dashboard: what is on today, which tasks are due, who has a birthday. It is generated once and then only read — so opening the app never waits for the AI.\n\nIt uses today's appointments, the tasks due today, what is still left over from the days before, and the family members' data. Nothing is created automatically; the text is just a text.")
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'BriefingEnabled',
                    'caption' => $this->Translate('Show a daily briefing on the dashboard')
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'BriefingUserID',
                    'width'   => '400px',
                    'caption' => $this->Translate('Written for'),
                    'options' => $wer
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'BriefingTone',
                    'width'   => '400px',
                    'caption' => $this->Translate('Tone'),
                    'options' => [
                        ['caption' => $this->Translate('Matter-of-fact'),   'value' => 'neutral'],
                        ['caption' => $this->Translate('Formal'),           'value' => 'formal'],
                        ['caption' => $this->Translate('Butler'),           'value' => 'butler'],
                        ['caption' => $this->Translate('Buddy'),            'value' => 'buddy'],
                        ['caption' => $this->Translate('Funny'),            'value' => 'funny'],
                        ['caption' => $this->Translate('Drill sergeant'),   'value' => 'drill'],
                        ['caption' => $this->Translate('Motivational coach'), 'value' => 'coach'],
                        ['caption' => $this->Translate('Whiner'),            'value' => 'jammerlappen'],
                        ['caption' => $this->Translate('Teen slang'),         'value' => 'digga']
                    ]
                ],
                $this->GetPersonaEditor(),
                [
                    'type'    => 'SelectTime',
                    'name'    => 'BriefingTime',
                    'caption' => $this->Translate('Generate at')
                ],
                ...(array_key_exists('BriefingAudioEnabled', (array)$cfg) ? [
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'BriefingAudioEnabled',
                        'caption' => $this->Translate('Read the briefing aloud')
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => $this->Translate('The recording is produced together with the text, so the play button starts instantly. It costs roughly four times as much as the text — switch it off and the button disappears.')
                    ],
                ] : []),
                // Ebenfalls erst nach einem Kernel-Neustart vorhanden.
                ...(array_key_exists('TtsProvider', (array)$cfg) ? [
                    [
                        'type'    => 'Select',
                        'name'    => 'TtsProvider',
                        'width'   => '400px',
                        'caption' => $this->Translate('Voice from'),
                        'options' => [
                            ['caption' => $this->Translate('OpenAI (same key as the AI)'), 'value' => 'openai'],
                            ['caption' => $this->Translate('Azure Speech (own key, German voices)'), 'value' => 'azure'],
                            ['caption' => $this->Translate('ElevenLabs (own key, paid account required)'), 'value' => 'elevenlabs'],
                        ]
                    ],
                    [
                        // PasswordTextBox wie jedes andere Geheimnis in diesem
                        // Formular (Client-Secrets, Mailgun-Schluessel, KI-Schluessel,
                        // CalDAV-Passwort). Als ValidationTextBox stand der Schluessel
                        // im Klartext auf dem Schirm — sichtbar bei jedem Blick ueber
                        // die Schulter und in jedem Bildschirmfoto der Konfiguration.
                        'type'    => 'PasswordTextBox',
                        'name'    => 'TtsAzureKey',
                        'width'   => '400px',
                        'caption' => $this->Translate('Azure Speech key')
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'TtsAzureRegion',
                        'width'   => '200px',
                        'caption' => $this->Translate('Azure region (e.g. westeurope)')
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => $this->Translate('Azure brings 17 German voices instead of 13 mixed-language ones, and its speech markup really does control tempo and pauses — with OpenAI the speed parameter is ignored. Free tier F0: 0.5 million characters per month, which is about ten times our consumption. Note: the speaking styles (cheerful, sad, shouting) that Azure advertises exist for German on one single voice, so the character comes from the choice of voice and from tempo, not from style names.')
                    ],
                    [
                        'type'    => 'PasswordTextBox',
                        'name'    => 'TtsElevenKey',
                        'width'   => '400px',
                        'caption' => $this->Translate('ElevenLabs API key')
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'TtsElevenVoice',
                        'width'   => '400px',
                        'caption' => $this->Translate('ElevenLabs voice ID')
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'TtsElevenModel',
                        'width'   => '400px',
                        'caption' => $this->Translate('ElevenLabs model (eleven_multilingual_v2 speaks German)')
                    ],
                    [
                        'type'    => 'Select',
                        'name'    => 'TtsElevenQuality',
                        'width'   => '400px',
                        'caption' => $this->Translate('Audio quality'),
                        'options' => [
                            ['caption' => $this->Translate('Automatic — best quality that still fits in one recording'), 'value' => 'auto'],
                            ['caption' => $this->Translate('Always high (128 kbit, like the ElevenLabs preview)'), 'value' => '128'],
                            ['caption' => $this->Translate('Always good (64 kbit)'), 'value' => '64'],
                            ['caption' => $this->Translate('Always thrifty (32 kbit at 22 kHz) — audibly dull'), 'value' => '32'],
                        ]
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => sprintf($this->Translate('Automatic works like the guard for photos and PDFs: it reads the Symcon core option ScriptOutputBufferLimit at runtime and picks the best quality whose recording still fits into ONE piece — every seam between two recordings is audible, because the intonation starts anew. Your limit is currently %s, which is enough for about %d characters at 128 kbit. Raise the option and the sound improves by itself; lower it and you still get a recording that arrives.'),
                            $this->BriefingLimitText(), (int)($this->OutputLimit() * 0.8 / 1300))
                    ],
                    [
                        'type'    => 'Select',
                        'name'    => 'TtsElevenScope',
                        'width'   => '400px',
                        'caption' => $this->Translate('Which voices to offer'),
                        'options' => [
                            ['caption' => $this->Translate('Own voices only (created or cloned by you)'), 'value' => 'personal'],
                            ['caption' => $this->Translate('Own and saved from the library'), 'value' => 'non-default'],
                            ['caption' => $this->Translate('All, including the default voices'), 'value' => 'all'],
                        ]
                    ],
                    [
                        'type'    => 'Button',
                        'caption' => $this->Translate('List voices of the account'),
                        'onClick' => 'IPS_RequestAction($id, \'TtsElevenVoices\', 0);'
                    ],
                    [
                        'type'    => 'Label',
                        'name'    => 'TtsElevenStatus',
                        'caption' => ' '
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => $this->Translate('ElevenLabs needs a PAID account: the free tier grants no commercial licence and requires every generated file to name ElevenLabs. It also has no voice per persona — which voices an account holds only that account knows, so all personas share the voice entered above and their character comes from the sliders (expressive against level). "List voices of the account" fetches the IDs available to your key.')
                    ],
                ] : []),
                // Erst nach einem Kernel-Neustart vorhanden — bis dahin wuerde ein
                // „Uebernehmen" auf diese Felder scheitern (siehe oben).
                ...(array_key_exists('BriefingPreviewEnabled', (array)$cfg) ? [
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'BriefingPreviewEnabled',
                        'caption' => $this->Translate('Show tomorrow\'s briefing in the evening')
                    ],
                    [
                        'type'    => 'SelectTime',
                        'name'    => 'BriefingPreviewFrom',
                        'caption' => $this->Translate('From this time on, show tomorrow instead of today')
                    ],
                ] : [
                    [
                        'type'    => 'Label',
                        'caption' => $this->Translate('The evening preview for tomorrow appears after the next Symcon restart — it brings new settings, and those only exist once the kernel has loaded the module again.')
                    ],
                ]),
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type'    => 'Button',
                            'caption' => $this->Translate('Generate briefing now'),
                            'onClick' => 'IPS_RequestAction($id, \'BriefingNow\', 0);'
                        ],
                        [
                            // Zeigt nur an, legt nichts ab: Der abgelegte Stand gilt fuer
                            // heute, und die Apps zeigen ihn als das Briefing des Tages.
                            'type'    => 'Button',
                            'caption' => $this->Translate('Preview for tomorrow'),
                            'onClick' => 'IPS_RequestAction($id, \'BriefingPreviewTomorrow\', 0);'
                        ]
                    ]
                ],
                [
                    // Zeigt nach dem Knopf das Ergebnis — bewusst ein Label und kein
                    // echo: eine Ausgabe aus RequestAction meldet Symcon als
                    // Skriptfehler samt Dateiname und Zeilennummer.
                    'type'    => 'Label',
                    'name'    => 'BriefingStatus',
                    'caption' => $this->BriefingText()
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
