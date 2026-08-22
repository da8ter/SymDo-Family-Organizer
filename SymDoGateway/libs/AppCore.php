<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiRouter.php';
require_once __DIR__ . '/DeviceRegistry.php';
require_once __DIR__ . '/QrRenderer.php';
require_once __DIR__ . '/AiExtract.php';
require_once __DIR__ . '/Tts.php';

/**
 * Die App-Seite des Gateways: REST-API für iOS- und Web-App, Kopplung, Nutzer,
 * Push-Kanal und KI-Analyse.
 *
 * Der Lebenszyklus liegt in der Fassade (module.php); die Anteile, die von dort
 * hereingerufen werden, tragen das Präfix App…, damit sie sich nicht mit der
 * Sync-Seite beißen.
 *
 * Die Web-App wird aus Nachbarmodulen zusammengesetzt (SymDoWebApp liefert HTML,
 * Übersetzungen und Icons, ShoppingList die Produktbilder). Aus libs/ heraus sind
 * das zwei Ebenen: dirname(__DIR__, 2) — dieselbe Rechnung wie in ApiRouter.
 */
trait AppCore
{
    use ApiRouter;
    use DeviceRegistry;
    use QrRenderer;
    use AiExtract;
    use Tts;

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
    /**
     * Service Worker und Manifest. Eigener Hook mit Absicht: Ein Worker darf nur
     * fuer sein eigenes Verzeichnis und darunter zustaendig sein. Unter
     * `/hook/lists/pwa` ist das Verzeichnis `/hook/lists/` — genau der Bereich, in
     * dem die Seite (`/hook/lists/webapp`) liegt. Unter
     * `/hook/lists/webapp/sw.js` waere es `/hook/lists/webapp/`, und die Seite
     * selbst laege NICHT darin; das braeuchte den Sonderkopf
     * `Service-Worker-Allowed`, und dass der den Connect-Proxy unveraendert
     * uebersteht, ist nirgends zugesagt.
     */
    private const PWA_HOOK_PATH        = 'lists/pwa';
    private const WEBHOOK_CONTROL_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const API_VERSION          = 1;
    private const PAIRING_TTL          = 600;
    private const ACTION_DEDUP_TTL     = 86400;
    private const ACTION_DEDUP_MAX     = 200;
    private const ACTION_ID_MAX_LEN    = 64;

    /**
     * Properties und Attribute der App-Seite. Die Hooks fehlen hier bewusst: welche
     * Instanz die App bedient, entscheidet die Fassade in ApplyChanges.
     */
    private function AppCreate(): void
    {
        $this->RegisterAttributeString('PairedDevices', '[]');
        $this->RegisterAttributeString('PendingPairings', '[]');
        $this->RegisterAttributeString('ActionDedup', '{}');
        $this->RegisterAttributeString('AvatarCache', '{}');
        $this->RegisterAttributeString('HiddenInstances', '[]');
        // Name → Kennung der Familienmitglieder. Rettungsnetz: Verliert eine Zeile
        // im Formular ihre Kennung, bekommt sie die alte zurueck statt eine neue
        // (siehe EnsureUserIDs).
        $this->RegisterAttributeString('UserIDShadow', '{}');
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
        // KI „Foto → Aufgaben" (Web-App schickt das Foto, das Gateway ruft die KI).
        $this->RegisterPropertyBoolean('AiEnabled', true); // Master-Schalter für die KI-Analyse
        $this->RegisterPropertyString('AiProvider', 'anthropic'); // anthropic | openai | local
        // Sprachausgabe der Einkaufs-Ansage (eigener Trait, gleiche Zugangsdaten)
        $this->TtsCreate();
        $this->RegisterPropertyString('AiAnthropicKey', '');
        $this->RegisterPropertyString('AiOpenAIKey', '');
        $this->RegisterPropertyString('AiLocalBaseUrl', '');
        $this->RegisterPropertyString('AiLocalModel', '');
        $this->RegisterPropertyString('AiLocalKey', '');
    }

    /**
     * Läuft nur auf der Instanz, die die App bedient. Die drei Hooks registriert die
     * Fassade unmittelbar davor — bewusst in ApplyChanges und nicht in Create(): sie
     * sind flüchtig, und Create() läuft bei einem Modul-Reload ohne Kernel-Neustart
     * nicht erneut; der Pfad lieferte dann bis zum nächsten Neustart 404 (gemessen).
     */
    private function AppApplyChanges(): void
    {
        $this->EnsureUserIDs();
        $this->WsResubscribe();
    }

    /**
     * Abonniert die Stat-Variablen aller Listen-Instanzen, damit Änderungen den
     * Push-Kanal auslösen. Gleiches Muster wie in der Kachel (SymDoWebApp), aber
     * absichtlich eigenständig: das Gateway ist die API für Web- und iOS-App und
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

    /**
     * Vergibt den Zeilen der Mitgliederliste stabile Kennungen.
     *
     * Stabil ist hier das Wesentliche: An der Kennung haengt JEDE Zuordnung —
     * Aufgaben (`assignedTo`), Termine (Attribut `CalMembers`), Mail-Vorschlaege.
     * Eine neue Kennung fuer dasselbe Mitglied kappt sie alle stillschweigend;
     * sichtbar wird das erst daran, dass Avatare verschwinden.
     *
     * Deshalb zwei Sicherungen: Die Spalte im Formular traegt ein (unsichtbares)
     * Eingabefeld, damit die Konsole die Kennung beim Bearbeiten einer Zeile nicht
     * fallen laesst — und hier bekommt eine Zeile ohne Kennung die alte ihres
     * Namens zurueck, bevor eine neue erzeugt wird.
     */
    private function EnsureUserIDs(): void
    {
        $users = json_decode($this->ReadPropertyString('Users'), true);
        if (!is_array($users)) {
            return;
        }
        $schatten = json_decode($this->ReadAttributeStringSafe('UserIDShadow', '{}'), true);
        $schatten = is_array($schatten) ? $schatten : [];

        $changed = false;
        foreach ($users as &$user) {
            if (!is_array($user)) {
                continue;
            }
            if (trim((string)($user['id'] ?? '')) !== '') {
                continue;
            }
            $name = mb_strtolower(trim((string)($user['name'] ?? '')));
            $alt  = trim((string)($schatten[$name] ?? ''));
            if ($alt !== '') {
                $user['id'] = $alt;
                $this->LogMessage(sprintf(
                    'SymDo: Kennung von „%s" war aus dem Formular verschwunden und wurde wiederhergestellt — Zuordnungen bleiben erhalten',
                    trim((string)($user['name'] ?? ''))
                ), KL_NOTIFY);
            } else {
                $user['id'] = bin2hex(random_bytes(4));
            }
            $changed = true;
        }
        unset($user);

        // Schatten nachfuehren: Name → Kennung, fuer den naechsten Verlust.
        $neuerSchatten = [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $name = mb_strtolower(trim((string)($user['name'] ?? '')));
            $id   = trim((string)($user['id'] ?? ''));
            if ($name !== '' && $id !== '') {
                $neuerSchatten[$name] = $id;
            }
        }
        if ($neuerSchatten !== $schatten) {
            @$this->WriteAttributeString('UserIDShadow', json_encode($neuerSchatten, JSON_UNESCAPED_UNICODE));
        }

        if ($changed) {
            IPS_SetProperty($this->InstanceID, 'Users', json_encode($users, JSON_UNESCAPED_UNICODE));
            IPS_ApplyChanges($this->InstanceID); // re-runs once, then stable
        }
    }

    /**
     * Wie viel eine Antwort ausgeben darf — DIE zentrale Stelle dafuer.
     *
     * Die Grenze ist die Symcon-Kernoption `ScriptOutputBufferLimit`, Vorgabe
     * 1048576 Bytes (1 MiB), aenderbar mit `IPS_SetOption`. Sie zaehlt die SUMME der
     * Ausgabe einer Anfrage, nicht den einzelnen Schreibvorgang: `readfile()` schreibt
     * in 8-KB-Haeppchen und laeuft trotzdem dagegen.
     *
     * Wird sie ueberschritten, wird die Antwort nicht abgeschnitten, sondern ERSETZT —
     * durch 62 Bytes Text „Output-Buffer exceeds Limit (1048576 bytes). Operation
     * halted.", bei HTTP 200 und mit dem laengst gesendeten Content-Type. Der Client
     * sieht also eine kaputte Datei und keinen Fehler; jeder Riegel muss deshalb VOR
     * der Ausgabe greifen. Am 21.08.2026 bei 1048576 und 1048577 Bytes exakt
     * eingegrenzt.
     *
     * ABGELESEN und nicht festgeschrieben: Wer die Option hochsetzt, soll davon auch
     * etwas haben. Vorher standen an vier Stellen feste 900000 bzw. 960000, und
     * Aufnahmen, Rezeptdateien und Notiz-Anhaenge blieben klein, obwohl Platz war.
     * Etwas Luft fuer die Kopfzeilen bleibt abgezogen.
     */
    private function OutputLimit(): int
    {
        $grenze = 1048576;
        try {
            $o = (int)@IPS_GetOption('ScriptOutputBufferLimit');
            if ($o > 0) {
                $grenze = $o;
            }
        } catch (\Throwable $e) {
            // Aeltere Symcon-Fassung ohne die Option: bei der Vorgabe bleiben.
        }
        return max(200000, $grenze - 100000);
    }

    /**
     * Dasselbe fuer eine Nutzlast, die als Base64 in JSON reist (data:-URL, Relay).
     * Base64 blaeht um ein Drittel auf; die Grenze gilt fuer die AUFGEBLAEHTE Laenge,
     * plus etwas Luft fuer das JSON-Geruest ringsum.
     */
    private function RelayLimitB64(): int
    {
        return max(150000, $this->OutputLimit() - 50000);
    }

    /** Attribut lesen, das es vielleicht noch nicht gibt (siehe MailAttr). */
    private function ReadAttributeStringSafe(string $name, string $vorgabe): string
    {
        try {
            $wert = @$this->ReadAttributeString($name);
            return is_string($wert) && $wert !== '' ? $wert : $vorgabe;
        } catch (Throwable $e) {
            return $vorgabe;
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

    /** Speichert einen Avatar als Medienobjekt unterhalb der Instanz (erstellt bei Bedarf). */
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

    /** IPS_KERNELSTARTED behandelt die Fassade; hier bleibt nur der Push-Auslöser. */
    private function AppMessageSink(int $Message): void
    {
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

    /**
     * Laufzeit-Anpassungen an den Formularelementen der App-Hälfte. Die Fassade
     * liefert die bereits zusammengesetzte Elementliste und kodiert danach selbst.
     */
    private function AppFormOverrides(array &$elements): void
    {
        $form = ['elements' => &$elements];

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
        // Fehlt die Einwilligung, steht der Grund im Datenschutz-Bereich — der darf
        // dann nicht zugeklappt sein, sonst ist der gesperrte Schalter unerklaerlich.
        // Liegt sie vor, bleibt der Bereich eingeklappt und macht Platz.
        $this->SetFormElementProperty($form['elements'], 'AiPrivacyPanel', 'expanded', !$accepted && $storable);
        $this->SetFormElementProperty($form['elements'], 'AiPrivacyStatus', 'caption', $this->AiPrivacyStatusText());
        $this->SetFormElementProperty($form['elements'], 'AiPrivacyRevoke', 'visible', $accepted);
        $this->SetFormElementProperty($form['elements'], 'AiPrivacyAccept', 'enabled', !$accepted);
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
     * IPS_RequestAction, das Gateway extrahiert und schickt das Ergebnis per
     * IPS_RequestAction('AiResult') an die Kachel zurück. Bewusst über
     * RequestAction statt einer neuen public TGW_-Methode, damit ein einfacher
     * Modul-Reload (ohne Kernel-Neustart) genügt.
     */
    private function AppRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident === 'TestLocalUrl') {
            $this->TestLocalUrl(trim((string)$Value));
            return true;
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
                return true;
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
                return true;
            }
            // Widerruf schaltet die KI ab: sie ohne Einwilligung weiterlaufen zu
            // lassen wäre genau das, was die Sperre verhindern soll.
            if (!$accepted && $this->ReadPropertyBoolean('AiEnabled')) {
                IPS_SetProperty($this->InstanceID, 'AiEnabled', false);
                IPS_ApplyChanges($this->InstanceID);
            }
            // Mit dem Widerruf verlieren auch die noch nicht analysierten E-Mails
            // in der Webhook-Warteschlange ihre Grundlage — weg damit, statt sie
            // sieben Tage liegen zu lassen. Neue nimmt der Hook ab jetzt ohnehin
            // nicht mehr an (MailHookIsEnabled).
            if (!$accepted) {
                $entfernt = $this->MailHookClearQueue();
                if ($entfernt > 0) {
                    $this->LogMessage(sprintf('SymDo: %d wartende E-Mail(s) nach Einwilligungs-Widerruf verworfen', $entfernt), KL_NOTIFY);
                }
                // Dasselbe fuer das Briefing: Es ist aus Terminen, Aufgaben und
                // Namen entstanden und hat ohne Einwilligung keine Grundlage mehr.
                if ($this->BriefingClear()) {
                    $this->LogMessage('SymDo: Tagesbriefing nach Einwilligungs-Widerruf verworfen', KL_NOTIFY);
                }
            }
            $this->UpdateFormField('AiEnabled', 'enabled', $accepted);
            if (!$accepted) {
                $this->UpdateFormField('AiEnabled', 'value', false);
            }
            $this->UpdateFormField('AiPrivacyStatus', 'caption', $this->AiPrivacyStatusText());
            $this->UpdateFormField('AiPrivacyRevoke', 'visible', $accepted);
            $this->UpdateFormField('AiPrivacyAccept', 'enabled', !$accepted);
            return true;
        }
        if ($Ident === 'AiTileRequest') {
            $req = json_decode((string)$Value, true);
            if (!is_array($req)) {
                return true;
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
            return true;
        }
        return false;
    }

    /** Rückkanal nur an echte SymDoWebApp-Instanzen (Ident kommt von außen). */
    private function IsSymDoWebAppInstance(int $instanceID): bool
    {
        if (!IPS_InstanceExists($instanceID)) {
            return false;
        }
        return (IPS_GetInstance($instanceID)['ModuleInfo']['ModuleID'] ?? '') === self::SDWA_MODULE_GUID;
    }

    /** Alles unter /hook/lists/… — die OAuth-Pfade hat die Fassade vorher abgefangen. */
    private function AppProcessHook(): void
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

            // Service Worker und Manifest: ohne Token, wie die Icons. Beides ist
            // oeffentlich harmlos — der Worker kennt keine Geheimnisse, das Manifest
            // beschreibt nur Name, Symbole und Startadresse.
            if (str_starts_with($path, '/hook/' . self::PWA_HOOK_PATH)) {
                $this->ServePwaFile($path);
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
        $uiPath = dirname(__DIR__, 2) . '/SymDoWebApp/module.html';
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
        // 32 fuer den Browser-Tab, 180 fuer den iOS-Homescreen, 192 und 512 fuer
        // Android: Chrome will diese beiden Groessen fuer eine installierte Web-App
        // und fuer den Startbildschirm, sonst skaliert es das 180er hoch.
        if (!in_array($name, ['appicon-32.png', 'appicon-180.png', 'appicon-192.png', 'appicon-512.png'], true)) {
            return false;
        }
        $raw = @file_get_contents(dirname(__DIR__, 2) . '/SymDoWebApp/assets/' . $name);
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
     * Liefert den Service Worker oder das Manifest.
     *
     * Der Worker liegt GENAU auf `/hook/lists/pwa` (ohne Endung, ohne Unterpfad) —
     * daran haengt sein Zustaendigkeitsbereich, siehe PWA_HOOK_PATH. Der Dateiname
     * spielt keine Rolle, der Content-Type entscheidet.
     */
    private function ServePwaFile(string $path): void
    {
        $rest = trim(substr($path, strlen('/hook/' . self::PWA_HOOK_PATH)), '/');

        if ($rest === 'manifest.webmanifest') {
            $this->ServePwaManifest();
            return;
        }
        if ($rest !== '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found.';
            return;
        }

        $js = @file_get_contents(dirname(__DIR__, 2) . '/SymDoWebApp/assets/sw.js');
        if (!is_string($js) || $js === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Worker not found.';
            return;
        }
        http_response_code(200);
        header('Content-Type: application/javascript; charset=utf-8');
        // no-cache und nicht no-store: Der Browser DARF die Datei behalten, muss sie
        // aber jedes Mal gegenpruefen. So greift eine neue Fassung beim naechsten
        // Start, ohne dass der Worker bei jedem Ereignis neu geladen wird.
        header('Cache-Control: no-cache');
        header('X-Content-Type-Options: nosniff');
        echo $js;
    }

    /**
     * Das Web-App-Manifest. Ohne dieses erlaubt iOS gar keine Benachrichtigungen:
     * Push gibt es dort nur fuer eine zum Home-Bildschirm hinzugefuegte App, und
     * hinzufuegen laesst sich nur, was `display: standalone` erklaert.
     *
     * `id` und `start_url` sind auf Dauer festgelegt. Aendert sich eines davon,
     * gilt die App auf iOS als eine ANDERE: zweites Symbol, zweiter Speicher,
     * verwaistes Abo.
     *
     * `start_url` traegt bewusst KEIN Token. Der Weg, den die Seite heute nutzt
     * (Token im Adressfragment, damit „Zum Home-Bildschirm" es mitnimmt), laesst
     * sich mit einem Manifest nicht sauber verbinden: Safari holt ein zur Laufzeit
     * geaendertes Manifest nicht verlaesslich neu, und ein Token in einer
     * Manifest-Adresse stuende in jedem Zugriffsprotokoll. Die Home-Screen-App ist
     * ein eigener Client mit eigenem Speicher — sie koppelt sich einmal selbst und
     * bekommt damit ihren eigenen Eintrag in der Geraeteliste.
     *
     * Damit das nicht in einer Sackgasse endet, nimmt der Kopplungs-Bildschirm in
     * `webapp-adapter.js` einen Code an (Feld `symdo-pair-code`), und die
     * Beschriftung des Browser-Zugangs weist den Code einzeln aus. Wer das hier
     * aendert, muss dort nachsehen.
     */
    private function ServePwaManifest(): void
    {
        $seite = '/hook/' . self::WEBAPP_HOOK_PATH;
        $manifest = [
            'id'               => $seite,
            'name'             => 'SymDo',
            'short_name'       => 'SymDo',
            'start_url'        => $seite,
            // Umfasst Seite UND API, damit ein Klick aus der Benachrichtigung in der
            // App landet und nicht in einem Browser-Fenster daneben.
            'scope'            => '/hook/lists/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#1c1c1e',
            'theme_color'      => '#1c1c1e',
            // Absteigend, damit Android das passende zuerst findet. Bewusst OHNE
            // `purpose: maskable`: Das Motiv reicht bis nahe an den Rand, und Androids
            // Maske schneidet die aeusseren 20 Prozent weg — Einkaufswagen und
            // Hauskante waeren ab.
            'icons'            => [
                ['src' => $seite . '/appicon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => $seite . '/appicon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $seite . '/appicon-180.png', 'sizes' => '180x180', 'type' => 'image/png'],
                ['src' => $seite . '/appicon-32.png', 'sizes' => '32x32', 'type' => 'image/png'],
            ],
        ];
        http_response_code(200);
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: no-cache');
        echo (string)json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        $dec = json_decode((string)@file_get_contents(dirname(__DIR__, 2) . '/SymDoWebApp/locale.json'), true);
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
        // das Gateway hängt also nicht von ihr ab (siehe Kommentar bei WsResubscribe).
        $symdo['tabs'] = $this->GetWebAppTabs();
        // Oeffentlicher VAPID-Schluessel gleich mit: Safari erlaubt
        // Notification.requestPermission() nur mit gueltiger Nutzeraktivierung, und
        // JEDES await davor verbraucht sie. Muesste die Seite den Schluessel erst
        // holen, scheiterte die Erlaubnisfrage auf dem iPhone mit NotAllowedError.
        $vapid = $this->PushPublicKey();
        if ($vapid !== '') {
            $symdo['pushKey'] = $vapid;
        }
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
        // viewport-fit=cover ist die VORAUSSETZUNG dafuer, dass env(safe-area-inset-*)
        // ueberhaupt Werte liefert — ohne es sind alle vier null und die Seite bleibt
        // im Briefkasten zwischen Statusleiste und Home-Indikator. Der Adapter setzt
        // die Angabe zwar auch (webapp-adapter.js), aber erst per JavaScript; hier
        // steht sie ab dem ersten Aufbau, damit die Abstaende nicht von der
        // Reihenfolge abhaengen.
        $icons = '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<link rel="icon" type="image/png" sizes="32x32" href="' . $iconBase . '/appicon-32.png">'
            . '<link rel="icon" type="image/png" sizes="180x180" href="' . $iconBase . '/appicon-180.png">'
            . '<link rel="apple-touch-icon" sizes="180x180" href="' . $iconBase . '/appicon-180.png">'
            // Manifest und die beiden Meta-Angaben machen die Seite erst
            // installierbar — und ohne Installation gibt es auf iOS keinen Push.
            . '<link rel="manifest" href="/hook/' . self::PWA_HOOK_PATH . '/manifest.webmanifest">'
            . '<meta name="apple-mobile-web-app-capable" content="yes">'
            . '<meta name="mobile-web-app-capable" content="yes">'
            // Erst hierdurch beginnt die Seite bei Pixel 0. Ohne die Angabe gilt
            // „default": iOS reserviert die Statusleiste und die Web-Ansicht faengt
            // DARUNTER an — der Bereich hinter der Dynamic Island gehoert dann gar
            // nicht zur Seite. Der Inhalt weicht ihr per env(safe-area-inset-top)
            // aus (siehe .app in module.html), die Flaeche laeuft darunter durch.
            //
            // Der Preis: iOS kennt nur „default" (dunkler Text, deckend), „black"
            // und „black-translucent" (WEISSER Text). Die Schriftfarbe folgt NICHT
            // dem theme-color. Im hellen Thema steht die Uhr also weiss auf hellem
            // Grund — bewusst in Kauf genommen (Wunsch vom 22.08.2026), weil die
            // App im dunklen Thema laeuft.
            . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">'
            . '<meta name="theme-color" content="#1c1c1e">';

        $adapterJs = (string)@file_get_contents(__DIR__ . '/webapp-adapter.js');
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
            // Auch in Popups absteigen: der Einwilligungsknopf AiPrivacyAccept liegt unter
            // popup.items eines PopupButton und war ohne diesen Zweig nicht erreichbar.
            if (isset($element['popup']['items']) && is_array($element['popup']['items'])) {
                $this->SetFormElementProperty($element['popup']['items'], $name, $property, $value);
            }
        }
        unset($element);
    }

    /**
     * Haengt ein Element an die Items eines benannten Elements an.
     *
     * Gegenstueck zu SetFormElementProperty: Panels, deren Inhalt erst zur Laufzeit
     * feststeht (Mitgliederauswahl), lassen sich so in ein form.json-Panel
     * einsetzen, ohne die Datei zu verdoppeln.
     */
    private function AppendFormItem(array &$elements, string $name, array $item): bool
    {
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['name'] ?? '') === $name) {
                if (!isset($element['items']) || !is_array($element['items'])) {
                    $element['items'] = [];
                }
                $element['items'][] = $item;
                return true;
            }
            if (isset($element['items']) && is_array($element['items'])
                && $this->AppendFormItem($element['items'], $name, $item)) {
                return true;
            }
        }
        unset($element);
        return false;
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
     * LAN base URLs (http://<ip>:3777) so the app can reach the gateway on the
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
            $show($this->Translate('Works: SymDo Gateway reached over the local URL with a valid certificate. On the home network the web app will use it from now on.'));
            return;
        }
        $show(sprintf($this->Translate('Answered with HTTP %d, but this is not a SymDo Gateway endpoint. Does the URL point at this Symcon installation?'), $status));
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
        $dir = dirname(__DIR__, 2) . '/ShoppingList/assets';
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
        $library = json_decode((string)@file_get_contents(dirname(__DIR__, 2) . '/library.json'), true);
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

    /**
     * Ist diese Instanz ansprechbar? Gegatet wird der KERNEL-Runlevel, nicht der
     * Instanzstatus — derselbe Grund wie in SymDoWebApp::IsInstanceReady(): Symcon
     * setzt den Status bei der Erzeugung und berechnet ihn nie neu, nach einem
     * Neustart stehen einwandfrei arbeitende Listen auf IS_CREATING.
     *
     * Ohne diese Pruefung rief das Gateway waehrend eines Modul-Reloads oder im
     * Hochlauf in Instanzen hinein, deren Schnittstelle noch nicht stand. Im Log stand
     * dann "Kann Schnittstellen-Instanz nicht erstellen" samt "InstanceInterface is
     * not available" aus dem angerufenen Modul.
     */
    private function IsInstanceReady(int $id): bool
    {
        return $id > 0
            && IPS_InstanceExists($id)
            && IPS_GetKernelRunlevel() === KR_READY;
    }

    private function GetInstanceRevision(int $id, string $kind): int
    {
        if (!$this->IsInstanceReady($id)) {
            return 0;
        }
        try {
            if ($kind === 'shopping' && function_exists('SL_GetAppRevision')) {
                return SL_GetAppRevision($id);
            }
            if ($kind === 'todo' && function_exists('TDL_GetAppRevision')) {
                return TDL_GetAppRevision($id);
            }
        } catch (Throwable $e) {
            $this->SendDebug('GetAppRevision', $id . ': ' . $e->getMessage(), 0);
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

    /** @return int[] Instanz-IDs, die in der App ausgeblendet sind (familienweit) */
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
     * @return array{dashboard:bool,shopping:bool,todos:bool,calendar:bool,notes:bool,ki:bool}
     */
    private function GetWebAppTabs(): array
    {
        $all = ['dashboard' => true, 'shopping' => true, 'todos' => true, 'calendar' => true,
                'notes' => true, 'ki' => true];
        $ids = IPS_GetInstanceListByModuleID(self::SDWA_MODULE_GUID);
        if (!$ids) {
            return $all;
        }
        $cfg = json_decode((string)@IPS_GetConfiguration($ids[0]), true);
        if (!is_array($cfg)) {
            return $all;
        }
        foreach (['dashboard' => 'ShowDashboard', 'shopping' => 'ShowShopping', 'todos' => 'ShowTodos',
                  'calendar' => 'ShowCalendar', 'notes' => 'ShowNotes', 'ki' => 'ShowKi'] as $key => $prop) {
            if (array_key_exists($prop, $cfg)) {
                $all[$key] = (bool)$cfg[$prop];
            }
        }
        return $all;
    }

    /** JSON-Array der familienweit ausgeblendeten Listen-Instanz-IDs (für Companion-Kacheln). */
    public function GetHiddenLists(): string
    {
        return json_encode($this->GetHiddenInstances());
    }

    /**
     * Sichtbarkeit einer Liste familienweit setzen — Gegenstück zur REST-Route
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
