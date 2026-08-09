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
    // Eigener Pfad für den Push-WebSocket. Bewusst getrennt von HOOK_PATH, damit
    // WebSocket-Frames gar nicht erst in den REST-Router geraten (Symcon ruft das
    // Hook-Ziel pro Frame mit REQUEST_METHOD=GET und Body in php://input auf).
    private const WS_HOOK_PATH         = 'lists/ws';
    private const WEBHOOK_CONTROL_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
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
        // Einwilligung in die Datenschutzerklärung der KI-Analyse. Attribut statt
        // Property: kein Zustand, den man im Formular „mal eben" umschaltet, und
        // er wird ohne Übernehmen sofort wirksam.
        $this->RegisterAttributeBoolean('AiPrivacyAccepted', false);
        $this->RegisterAttributeString('AiPrivacyAcceptedAt', '');
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

        // Push-Kanal verdrahten. Die Hook-Registrierung steht bewusst HIER und
        // nicht in Create() wie die beiden anderen Hooks: sie ist flüchtig, und
        // Create() läuft bei einem Modul-Reload ohne Kernel-Neustart nicht erneut
        // — der Pfad lieferte dann bis zum nächsten Neustart 404 (gemessen).
        // ApplyChanges läuft in beiden Fällen, die Registrierung ist idempotent.
        $this->RegisterHook(self::WS_HOOK_PATH);
        $this->WsResubscribe();

        $this->SetStatus(IS_ACTIVE);
    }

    /**
     * Abonniert die Stat-Variablen aller Listen-Instanzen, damit Änderungen den
     * Push-Kanal auslösen. Gleiches Muster wie in der Kachel (SymDoWebApp), aber
     * absichtlich eigenständig: die Bridge ist die API für Web- und iOS-App und
     * darf nicht davon abhängen, dass eine SymDoWebApp-Instanz existiert.
     *
     * Der Abo-Stand wird NICHT in einem Attribut gemerkt, sondern jedes Mal aus der
     * Instanzliste neu abgeleitet. Grund: ein in Create() registriertes Attribut
     * existiert nach einem reinen Modul-Reload noch nicht, ReadAttributeString
     * liefert dann `false` — das hat ApplyChanges zerlegt. Ohne Attribut ist die
     * Methode nach jedem Reload sofort funktionsfähig. Preis: das Abo einer
     * Variablen, deren Instanz gelöscht wurde, bleibt bis zum nächsten
     * Kernel-Neustart bestehen (harmlos, sie feuert nicht mehr).
     */
    private function WsResubscribe(): void
    {
        foreach ($this->WsTriggerVariables() as $varID) {
            // Erst abmelden, dann neu anmelden — hält das Abo bei mehrfachem
            // ApplyChanges eindeutig, ohne einen gespeicherten Stand zu brauchen.
            $this->UnregisterMessage($varID, VM_UPDATE);
            $this->RegisterMessage($varID, VM_UPDATE);
        }
    }

    /** @return list<int> Stat-Variablen aller Listen-Instanzen, die eine Änderung anzeigen. */
    private function WsTriggerVariables(): array
    {
        $result = [];
        foreach ($this->GetListInstances() as $inst) {
            $idents = $inst['kind'] === 'shopping'
                ? ['ItemCount', 'LastUsed']
                : ['OpenTasks', 'OverdueTasks', 'DueTodayTasks'];
            foreach ($idents as $ident) {
                $varID = @IPS_GetObjectIDByIdent($ident, (int)$inst['id']);
                if (is_int($varID) && $varID > 0 && IPS_VariableExists($varID)) {
                    $result[] = $varID;
                }
            }
        }
        return $result;
    }

    /**
     * Sendet die „Türklingel" an alle auf dem WS-Hook verbundenen Clients.
     *
     * Bewusst OHNE Nutzdaten: der Upgrade wird von Symcon akzeptiert, bevor eigener
     * Code läuft — es gibt also keine Authentifizierung beim Verbindungsaufbau und
     * kein Disconnect-Ereignis. Ein Broadcast erreicht damit auch nicht angemeldete
     * Clients. Deshalb enthält er nur „irgendetwas hat sich geändert"; den Inhalt
     * holt der Client danach über die token-authentifizierte REST-API.
     */
    private function WsPushDirty(): void
    {
        $controls = IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL_GUID);
        if ($controls === []) {
            return;
        }
        // Der Pfad MUSS mit '/hook/' beginnen — andere Schreibweisen liefern true,
        // senden aber nichts. Der Rückgabewert ist ohnehin kein Zustellnachweis.
        @WC_PushMessage($controls[0], '/hook/' . self::WS_HOOK_PATH, json_encode(['t' => 'dirty']));
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
            return;
        }
        if ($Message === VM_UPDATE) {
            // Direkt senden, ohne Entprell-Timer. Eine Mutation schreibt 2-3
            // Stat-Variablen, ergibt also 2-3 Signale — das ist gewollt billig:
            // ein Signal ist ein leerer WebSocket-Frame (kernelseitig ~0,03 ms),
            // und der Client fasst sie mit 150 ms Entprellung ohnehin zu EINEM
            // Abgleich zusammen. Ein serverseitiger Timer wäre nur Frame-Kosmetik,
            // müsste aber in Create() registriert werden — und existiert damit
            // nach einem reinen Modul-Reload nicht, was hier still Signale
            // verschluckt hätte.
            $this->WsPushDirty();
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
        $this->SetFormElementProperty($form['elements'], 'LocalUrlHint', 'caption', $this->LocalUrlHint());
        $this->SetFormElementProperty($form['elements'], 'PairedDevicesList', 'values', $this->BuildDeviceRows());

        // KI-Formular: nur die Felder des gewählten Anbieters zeigen (Anfangszustand).
        foreach ($this->AiFieldVisibility($this->ReadPropertyString('AiProvider')) as $name => $visible) {
            $this->SetFormElementProperty($form['elements'], $name, 'visible', $visible);
        }

        // Ohne Einwilligung bleibt der KI-Schalter gesperrt — außer der Speicher
        // dafür existiert noch gar nicht (Modul aktualisiert, Kernel noch nicht
        // neu gestartet). Dann wäre die Sperre eine Falle: zustimmen ginge nicht,
        // abschalten auch nicht. Bis dahin bleibt alles bedienbar.
        $accepted = $this->AiPrivacyAccepted();
        $storable = $this->AiPrivacyStorable();
        $this->SetFormElementProperty($form['elements'], 'AiEnabled', 'enabled', $accepted || !$storable);
        $this->SetFormElementProperty($form['elements'], 'AiPrivacyStatus', 'caption', $this->AiPrivacyStatusText());
        $this->SetFormElementProperty($form['elements'], 'AiPrivacyRevoke', 'visible', $accepted);
        $this->SetFormElementProperty($form['elements'], 'AiPrivacyAccept', 'enabled', !$accepted);

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Einwilligung in die Datenschutzerklärung der KI-Analyse.
     *
     * Defensiv gelesen: Ein Modul-Reload führt `Create()` nicht erneut aus, das
     * Attribut fehlt also bis zum nächsten Kernel-Neustart. Ohne diesen Schutz
     * würde das Konfigurationsformular dort mit einem Fehler abbrechen.
     */
    private function AiPrivacyAccepted(): bool
    {
        try {
            return (bool)@$this->ReadAttributeBoolean('AiPrivacyAccepted');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Kann die Einwilligung überhaupt gespeichert werden?
     *
     * `WriteAttribute*` auf ein Attribut, das es noch nicht gibt, **wirft nicht** —
     * es tut still nichts (dieselbe Falle wie beim Bild-Cache: `ReadAttribute*`
     * liefert dann `false` statt zu scheitern). Deshalb wird hier wirklich
     * geschrieben und zurückgelesen; der vorherige Wert wird danach restauriert.
     */
    private function AiPrivacyStorable(): bool
    {
        $previous = @$this->ReadAttributeString('AiPrivacyAcceptedAt');
        $probe    = 'probe-' . uniqid('', true);
        @$this->WriteAttributeString('AiPrivacyAcceptedAt', $probe);
        $ok = (@$this->ReadAttributeString('AiPrivacyAcceptedAt') === $probe);
        if ($ok) {
            @$this->WriteAttributeString('AiPrivacyAcceptedAt', is_string($previous) ? $previous : '');
        }
        return $ok;
    }

    private function AiPrivacyStatusText(): string
    {
        if (!$this->AiPrivacyStorable()) {
            return $this->Translate('The consent cannot be stored until the Symcon kernel has been restarted once after this module update. Until then the AI analysis stays switched on as configured.');
        }
        if (!$this->AiPrivacyAccepted()) {
            return $this->Translate('Consent required: open the privacy notice and agree — until then the AI analysis cannot be switched on.');
        }
        $at = '';
        try {
            $at = (string)@$this->ReadAttributeString('AiPrivacyAcceptedAt');
        } catch (\Throwable $e) {
            $at = '';
        }
        $when = $at !== '' ? date('d.m.Y H:i', (int)strtotime($at)) : '';
        return $when !== ''
            ? sprintf($this->Translate('Consent given on %s.'), $when)
            : $this->Translate('Consent given.');
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
        if ($Ident === 'TestLocalUrl') {
            $this->TestLocalUrl(trim((string)$Value));
            return;
        }

        // Einwilligung aus dem Konfigurationsformular (Popup-Knopf bzw. Widerruf).
        if ($Ident === 'AiPrivacyConsent') {
            $accepted = ($Value === true || $Value === 1 || $Value === '1' || $Value === 'true');
            // Zuerst prüfen, OB gespeichert werden kann. Beim Widerruf sähen
            // „gewünscht = false" und „gelesen = false" sonst gleich aus — die
            // Rücklese-Prüfung unten liefe ins Leere und der Widerruf würde die
            // KI abschalten, obwohl die Zustimmung nie gespeichert werden konnte.
            if (!$this->AiPrivacyStorable()) {
                $this->UpdateFormField(
                    'AiPrivacyStatus',
                    'caption',
                    $this->Translate('The consent cannot be stored until the Symcon kernel has been restarted once after this module update. Until then the AI analysis stays switched on as configured.')
                );
                return;
            }
            @$this->WriteAttributeBoolean('AiPrivacyAccepted', $accepted);
            @$this->WriteAttributeString('AiPrivacyAcceptedAt', $accepted ? date('c') : '');
            // Schreiben und zurücklesen: fehlt das Attribut (Kernel seit dem
            // Modul-Update nicht neu gestartet), schlägt das Schreiben STILL fehl.
            // Ohne diese Prüfung bliebe der Schalter gesperrt und niemand wüsste
            // warum — und ein Widerruf würde die KI abschalten, ohne dass sich
            // die Zustimmung je wieder erteilen ließe.
            if ($this->AiPrivacyAccepted() !== $accepted) {
                $this->UpdateFormField(
                    'AiPrivacyStatus',
                    'caption',
                    $this->Translate('The consent cannot be stored until the Symcon kernel has been restarted once after this module update. Until then the AI analysis stays switched on as configured.')
                );
                return;
            }
            // Widerruf schaltet die KI ab: sie ohne Einwilligung weiterlaufen zu
            // lassen wäre genau das, was die Sperre verhindern soll.
            if (!$accepted && $this->ReadPropertyBoolean('AiEnabled')) {
                IPS_SetProperty($this->InstanceID, 'AiEnabled', false);
                IPS_ApplyChanges($this->InstanceID);
            }
            $this->UpdateFormField('AiEnabled', 'enabled', $accepted);
            if (!$accepted) {
                $this->UpdateFormField('AiEnabled', 'value', false);
            }
            $this->UpdateFormField('AiPrivacyStatus', 'caption', $this->AiPrivacyStatusText());
            $this->UpdateFormField('AiPrivacyRevoke', 'visible', $accepted);
            $this->UpdateFormField('AiPrivacyAccept', 'enabled', !$accepted);
            return;
        }
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

            // Push-Kanal: reiner Ausgangskanal. Symcon ruft das Hook-Ziel bei JEDEM
            // eingehenden Frame auf — erkennbar an HTTP_UPGRADE (dann ist
            // REQUEST_METHOD=GET, aber php://input trägt den Frame). Der Client
            // sendet nichts; alles hier Eintreffende wird verworfen, damit ein
            // Frame nie als REST-Aufruf fehlinterpretiert wird. `echo` erreicht
            // einen WebSocket-Client ohnehin nicht.
            $isWebSocket = strtolower((string)($_SERVER['HTTP_UPGRADE'] ?? '')) === 'websocket';
            if ($isWebSocket || str_starts_with($path, '/hook/' . self::WS_HOOK_PATH)) {
                return;
            }

            // Web-App-Seite: eigener Hook, liefert HTML (kein Token nötig — die
            // Seite authentifiziert sich danach selbst gegen die JSON-API).
            if (str_starts_with($path, '/hook/' . self::WEBAPP_HOOK_PATH)) {
                if ($this->ServeWebAppIcon($path)) {
                    return;
                }
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
     * App-Icon für Browser-Tab und iOS-Homescreen. Bewusst ohne Token: Favicon-
     * und apple-touch-icon-Anfragen stellt der Browser selbst, ganz ohne unser
     * JavaScript und damit ohne Authorization-Header. Der Inhalt ist lediglich
     * das öffentliche App-Icon, also nichts Schützenswertes.
     *
     * Der Dateiname wird gegen eine feste Whitelist geprüft (nach basename()),
     * damit über diesen Pfad kein anderes Modulverzeichnis lesbar wird.
     *
     * @return bool true, wenn die Anfrage ein Icon war und beantwortet wurde
     */
    private function ServeWebAppIcon(string $path): bool
    {
        $name = basename($path);
        if (!in_array($name, ['appicon-32.png', 'appicon-180.png'], true)) {
            return false;
        }
        $raw = @file_get_contents(__DIR__ . '/../SymDoWebApp/assets/' . $name);
        if (!is_string($raw) || $raw === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Icon not found.';
            return true;
        }
        $etag = '"' . md5($raw) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=604800');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return true;
        }
        http_response_code(200);
        header('Content-Type: image/png');
        header('X-Content-Type-Options: nosniff');
        echo $raw;
        return true;
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
        // Sichtbare Bereiche. Die Schalter stehen im Formular der SymDoWebApp-Kachel,
        // weil sie deren Oberfläche betreffen — diese Seite hier IST diese Oberfläche.
        // Gelesen mit sicherem Standard: ohne Kachel-Instanz gilt „alles sichtbar",
        // die Bridge hängt also nicht von ihr ab (siehe Kommentar bei WsResubscribe).
        $symdo['tabs'] = $this->GetWebAppTabs();
        if ($localBase !== '') {
            $symdo['localBase'] = $localBase . '/hook/' . self::HOOK_PATH . '/v' . self::API_VERSION;
        }
        $config = '<script>window.__SYMDO__=' . json_encode($symdo, JSON_UNESCAPED_SLASHES)
            . ';window.__SYMDO_I18N__=' . $translations . ';</script>';

        // App-Icon wie in der iOS-App: 32 px für den Browser-Tab, 180 px für den
        // iOS-Homescreen. Root-absolut wie /icons.js, damit die URL auch über
        // Connect trägt. rel="apple-touch-icon" ohne -precomposed, denn iOS
        // maskiert die Ecken selbst — sonst gäbe es doppelte Rundungen.
        $iconBase = '/hook/' . self::WEBAPP_HOOK_PATH;
        $icons = '<link rel="icon" type="image/png" sizes="32x32" href="' . $iconBase . '/appicon-32.png">'
            . '<link rel="icon" type="image/png" sizes="180x180" href="' . $iconBase . '/appicon-180.png">'
            . '<link rel="apple-touch-icon" sizes="180x180" href="' . $iconBase . '/appicon-180.png">';

        $adapterJs = (string)@file_get_contents(__DIR__ . '/libs/webapp-adapter.js');
        $adapter = '<script>' . $adapterJs . '</script>';

        return $theme . $icons . $config . $adapter;
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
    /**
     * Prüft die eingetragene lokale URL und schreibt das Ergebnis ins Formular.
     *
     * Der wichtigste Fall ist die Warnung bei `http://`: der Browser blockiert
     * einen solchen Aufruf aus der über Connect geladenen Seite als Mixed Content,
     * und zwar OHNE Fehlermeldung. Ohne diesen Hinweis würde man endlos suchen,
     * warum die Umschaltung nicht greift.
     *
     * Die Erreichbarkeitsprüfung erfolgt vom Server aus. Sie beweist nicht, dass
     * der BROWSER dem Zertifikat vertraut — das kann nur er selbst entscheiden.
     * Deshalb wird auch ein Zertifikatsfehler nur berichtet, nicht übergangen.
     */
    private function TestLocalUrl(string $url): void
    {
        $show = function (string $text): void {
            $this->UpdateFormField('LocalUrlResult', 'visible', true);
            $this->UpdateFormField('LocalUrlResult', 'caption', $text);
        };

        if ($url === '') {
            $show($this->Translate('No local URL configured — the web app always uses Symcon Connect.'));
            return;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme === 'http') {
            $show($this->Translate('This URL uses http://. A page loaded over Symcon Connect may not call it — the browser blocks it silently as mixed content. HTTPS is required.'));
            return;
        }
        if ($scheme !== 'https') {
            $show($this->Translate('Please enter a full URL starting with https://.'));
            return;
        }

        $probe = rtrim($url, '/') . '/hook/' . self::HOOK_PATH . '/v' . self::API_VERSION . '/ping';
        $ch = curl_init($probe);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        $errNo  = curl_errno($ch);

        // Zertifikatsfehler an den nackten libcurl-Nummern erkennen, nicht über
        // benannte Konstanten: CURLE_PEER_FAILED_VERIFICATION existiert in diesem
        // PHP (8.5.5) NICHT, und eine undefinierte Konstante ist seit PHP 8 ein
        // Fatal — die Funktion starb dadurch bei jeder HTTPS-URL, noch bevor sie
        // ein Ergebnis anzeigen konnte. 60 ist die heutige Nummer, 51 die alte
        // (früher CURLE_SSL_PEER_CERTIFICATE).
        if (in_array($errNo, [51, 60], true)) {
            $show(sprintf($this->Translate('Reachable, but the certificate is not trusted: %s — a browser would refuse it too.'), $err));
            return;
        }
        if ($body === false) {
            $show(sprintf($this->Translate('Not reachable from Symcon: %s'), $err));
            return;
        }
        $decoded = json_decode((string)$body, true);
        if ($status === 200 && is_array($decoded) && ($decoded['ok'] ?? false) === true) {
            $show($this->Translate('Works: SymDo Bridge reached over the local URL with a valid certificate. On the home network the web app will use it from now on.'));
            return;
        }
        $show(sprintf($this->Translate('Answered with HTTP %d, but this is not a SymDo Bridge endpoint. Does the URL point at this Symcon installation?'), $status));
    }

    /**
     * Hinweistext unter dem Feld: nennt die erkannten LAN-Adressen dieses Servers
     * und sagt, was daran noch fehlt.
     *
     * Ein automatischer Eintrag ist bewusst NICHT möglich: der Browser lädt die
     * Seite über Connect per HTTPS und verweigert danach jeden `http://`-Aufruf
     * (Mixed Content, stillschweigend). Nötig ist also ein HTTPS-Endpunkt mit
     * browservertrautem Zertifikat — und für eine private IP stellt keine CA ein
     * solches Zertifikat aus. Die Adressen taugen daher nur als Ausgangspunkt für
     * einen Reverse Proxy bzw. eine WebServer-Instanz mit eigenem Zertifikat.
     */
    private function LocalUrlHint(): string
    {
        $ips = [];
        foreach ($this->GetLocalUrls() as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $ips[] = $host;
            }
        }
        if ($ips === []) {
            return $this->Translate('No local network address detected.');
        }
        return sprintf(
            $this->Translate('Detected local addresses of this server: %s — the iOS app uses these directly. The browser needs HTTPS with a trusted certificate, so enter the host name of a reverse proxy or a WebServer instance here, not the bare IP address.'),
            implode(', ', $ips)
        );
    }

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
            'assetsVersion'  => $this->GetAssetsVersion(),
        ];
    }

    /**
     * Jüngste Änderungszeit im Produktbild-Ordner, als Cache-Version für die
     * Bild-URLs der Web-App.
     *
     * Nötig, weil HandleAsset mit `Cache-Control: private, max-age=2592000`
     * antwortet — 30 Tage. Der ETag dort hilft nicht: solange der Eintrag frisch
     * ist, fragt der Browser gar nicht nach. Wird ein Bestandsbild ersetzt, bleibt
     * der Dateiname gleich, und die Web-App zeigte weiter die alte Fassung (genau
     * so beim neuen Nudeln-Bild passiert).
     *
     * Maximum über die DATEIEN, nicht die mtime des Ordners: die bleibt beim
     * reinen Überschreiben einer Datei unverändert. Gleiche Begründung wie in
     * ShoppingList::GetAssetsVersion() — dort für die Kachel, hier für die App.
     */
    private function GetAssetsVersion(): int
    {
        $dir = dirname(__DIR__) . '/ShoppingList/assets';
        $max = (int)@filemtime($dir);
        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $mtime = @filemtime($dir . '/' . $entry);
            if ($mtime !== false && $mtime > $max) {
                $max = $mtime;
            }
        }
        return $max;
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

    /**
     * Sichtbare Bereiche aus der SymDoWebApp-Instanz.
     *
     * IPS_GetConfiguration statt IPS_GetProperty: die drei Eigenschaften entstehen in
     * deren Create() und existieren vor dem nächsten Kernel-Start nicht.
     * IPS_GetProperty liefert dann `false` plus eine PHP-Warnung (gemessen), und eine
     * Warnung fängt kein try/catch — die Bereiche wären ausgeblendet, bevor sich der
     * Schalter bedienen lässt. Ein fehlender Schlüssel ist hier eindeutig von einem
     * gesetzten false unterscheidbar.
     *
     * Ohne Kachel-Instanz: alles sichtbar. Bei mehreren entscheidet die erste — es
     * gibt nur eine Standalone-Web-App, eine Zuordnung je Kachel gäbe es also nicht.
     *
     * @return array{dashboard:bool,shopping:bool,todos:bool}
     */
    private function GetWebAppTabs(): array
    {
        $all = ['dashboard' => true, 'shopping' => true, 'todos' => true];
        $ids = IPS_GetInstanceListByModuleID(self::SDWA_MODULE_GUID);
        if (!$ids) {
            return $all;
        }
        $cfg = json_decode((string)@IPS_GetConfiguration($ids[0]), true);
        if (!is_array($cfg)) {
            return $all;
        }
        foreach (['dashboard' => 'ShowDashboard', 'shopping' => 'ShowShopping', 'todos' => 'ShowTodos'] as $key => $prop) {
            if (array_key_exists($prop, $cfg)) {
                $all[$key] = (bool)$cfg[$prop];
            }
        }
        return $all;
    }

    /** JSON-Array der haushaltsweit ausgeblendeten Listen-Instanz-IDs (für Companion-Kacheln). */
    public function GetHiddenLists(): string
    {
        return json_encode($this->GetHiddenInstances());
    }

    /**
     * Sichtbarkeit einer Liste haushaltsweit setzen — Gegenstück zur REST-Route
     * für die Symcon-Konfiguration.
     *
     * Bisher ließ sich das Ausblenden ausschließlich aus der App heraus ändern (die
     * Route verlangt ein Gerätetoken). Die SymDoWebApp-Kachel hat dafür ein
     * Häkchen im Formular, konnte es aber nur lokal auswerten; über diese Funktion
     * reicht sie es an die maßgebliche Stelle durch.
     */
    public function SetListHidden(int $ListID, bool $Hidden): void
    {
        if ($ListID <= 0) {
            return;
        }
        $this->SetInstanceHidden($ListID, $Hidden);
    }
}
