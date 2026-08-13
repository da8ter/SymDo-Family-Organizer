<?php

declare(strict_types=1);

/**
 * SymDoWebApp — die SymDo-App als HTML-Kachel für die Tile-Visualisierung.
 *
 * Aggregiert alle ShoppingList-/ToDoList-Instanzen des Systems (Auto-Discovery)
 * und bildet die App-Tabs Übersicht / Einkaufen / ToDos / Favoriten nach.
 * Reine Fassade: sämtliche Geschäftslogik bleibt in den Listen-Modulen und
 * wird über deren AppCall-/GetAppState-Contract angesprochen.
 */
class SymDoWebApp extends IPSModuleStrict
{
    private const SHOPPING_MODULE_GUID = '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}';
    private const TODO_MODULE_GUID     = '{E0E38D9B-31BC-4F5E-A6CA-91A2A60C7C46}';
    private const BRIDGE_MODULE_GUID   = '{F9B31B2B-ED34-4E88-B96D-D115E39F0B44}';

    // Stat-Variablen der Quell-Module: jede Mutation läuft durch SendState und
    // aktualisiert mindestens eine davon — unser Änderungs-Trigger ohne Polling.
    private const SHOPPING_TRIGGER_IDENTS = ['ItemCount', 'LastUsed'];
    private const TODO_TRIGGER_IDENTS     = ['OpenTasks', 'OverdueTasks', 'DueTodayTasks'];

    /** Revisionen des zuletzt gebauten Payloads (BuildFullPayload → PushFullState, gleicher Aufruf). */
    private array $lastBuiltRevisions = [];

    /** Wird im 'AiResult'-Branch gesetzt; HandleAiCall erkennt daran eine ausgefallene Antwort. */
    private bool $aiResultSeen = false;

    public function Create(): void
    {
        parent::Create();

        // Pflicht, damit Symcon die HTML-Kachel aus GetVisualizationTile() rendert
        $this->SetVisualizationType(1);

        // Standard-Benutzer dieser Kachel: wird als actorUserId/Auto-Zuweisung
        // in Todo-Aktionen injiziert (Pendant zu "Wer bist du?" in der App)
        $this->RegisterPropertyString('DefaultUserID', '');
        // Formular-Liste: pro entdeckter Listen-Instanz eine Zeile mit Ausblenden-Flag
        $this->RegisterPropertyString('Lists', '[]');
        // Sichtbare Bereiche. Favoriten hängen an den Einkaufslisten: der Tab zeigt
        // deren Favoritenlisten und die Kaufhistorie, ohne Einkauf hätte er keinen
        // Inhalt. Standard überall true — abschalten ist die Ausnahme.
        $this->RegisterPropertyBoolean('ShowDashboard', true);
        $this->RegisterPropertyBoolean('ShowShopping', true);
        $this->RegisterPropertyBoolean('ShowTodos', true);

        // Merkt sich die aktuell abonnierten Variablen-IDs, um Abos sauber zu lösen
        $this->RegisterAttributeString('SubscribedVarIDs', '[]');
        // Zuletzt entdecktes Instanz-Set (Rescan-Erkennung)
        $this->RegisterAttributeString('KnownInstanceIDs', '[]');
        // Revision je Instanz beim letzten Push: gated VM_UPDATE-Pushes, damit
        // die 2-3 Stat-Variablen-Writes pro Aktion nur EINEN Push auslösen
        $this->RegisterAttributeString('LastPushedRevisions', '{}');
        // Zuletzt gemeldete Hidden-IDs: erkennt Sichtbarkeits-Änderungen im Poll
        $this->RegisterAttributeString('LastHiddenIDs', '[]');
        // Von der Kachel gemeldete Visu-Farben je Schema (ReportVisuTheme) —
        // die AppBridge liefert sie der SymDo-App/Web-App über die Discovery aus.
        $this->RegisterAttributeString('VisuTheme', '{}');

        // Entprellt VM_UPDATE: die Quell-Module schreiben 2-3 Stat-Variablen pro
        // Mutation (und der ToDo-Timer für jede Liste). Ohne Coalescing liefe für
        // jeden Write ein kompletter Discovery+Revisions-Durchlauf.
        $this->RegisterTimer('Coalesce', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'CoalescedPush\', 0);');
        // Voller Push, verzoegert — siehe ApplyChanges: dort darf er nicht direkt laufen.
        $this->RegisterTimer('DeferredPush', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'DeferredPush\', 0);');

        // Keine eigenen Variablen: dieses Modul liest ausschließlich Fremddaten.
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Kernel-Check: Kein Heavy Work vor KR_READY
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // Neu angelegte/gelöschte Listen erkennt der Client-Poll (CheckRevisions,
        // 15 s) bzw. das nächste Öffnen der Kachel über RescanIfChanged — daher
        // KEIN systemweites IM_CREATE/IM_DELETE-Abo auf Objekt 0 (das würde bei
        // jeder Objektänderung im GESAMTEN System feuern).

        $this->SyncListVisibility();
        $this->ResubscribeAll();

        // Den vollen Push NICHT hier ausfuehren, sondern verzoegert: bei einem
        // Modul-Reload laeuft ApplyChanges, WAEHREND die Schnittstellen der
        // Listen-Instanzen neu entstehen. TDL_GetAppState/SL_GetAppState trafen sie
        // dann im Rohbau — im Log stand "InstanceInterface is not available" aus dem
        // angerufenen Modul, und der InstanceManager meldete "Kann
        // Schnittstellen-Instanz nicht erstellen". Der Kernel-Check oben greift dabei
        // NICHT: bei einem Reload bleibt der Runlevel auf KR_READY.
        try {
            $this->SetTimerInterval('DeferredPush', 1500);
        } catch (Throwable $e) {
            // Timer noch nicht registriert (Reload ohne Kernel-Neustart). Dann
            // BEWUSST kein Ersatz-Push von hier — genau der war das Problem. Die
            // Kachel holt den Zustand beim naechsten Revisionsabgleich (15 s) oder
            // beim Oeffnen.
            $this->SendDebug('ApplyChanges', 'DeferredPush-Timer fehlt, Push entfaellt', 0);
        }
    }

    /**
     * Repariert die Zeilen der Formular-Liste und trägt die Ausblendungen in die
     * Bridge nach.
     *
     * Hintergrund: Die Symcon-Formularoberfläche schreibt beim Speichern nur Spalten
     * zurück, die eine `edit`-Definition haben — bei dieser Liste also allein `hide`.
     * Die `instanceID` ging dadurch verloren, und GetTileHiddenIDs() konnte keine
     * Zeile mehr zuordnen: das Häkchen war wirkungslos (gemessen: die gespeicherte
     * Eigenschaft war [{"hide":false} × 7]). Die Eigenschaft SELBST kann die IDs
     * halten — über IPS_SetProperty geschrieben überstehen sie ApplyChanges —, es
     * fehlt also nur der Rückweg aus dem Formular.
     *
     * Deshalb hier: fehlende IDs über die Position nachtragen. Das Formular baut die
     * Zeilen aus DiscoverInstances() in genau dieser Reihenfolge, und gespeichert
     * wird dieselbe Zeilenzahl. Bei abweichender Zahl (Liste kam dazu oder fiel weg,
     * während der Dialog offen war) wird NICHT geraten, sondern nur das übernommen,
     * was schon eine ID trägt.
     */
    private function SyncListVisibility(): void
    {
        $rows = json_decode($this->ReadPropertyString('Lists'), true);
        if (!is_array($rows) || $rows === []) {
            return;
        }
        $discovered = $this->DiscoverInstances();
        $repaired   = [];
        $changed    = false;
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['instanceID'] ?? 0);
            if ($id <= 0 && count($rows) === count($discovered) && isset($discovered[$i]['id'])) {
                $id      = (int)$discovered[$i]['id'];
                $changed = true;
            }
            if ($id <= 0) {
                continue;
            }
            $repaired[] = ['instanceID' => $id, 'hide' => (bool)($row['hide'] ?? false)];
        }
        if ($changed && $repaired !== []) {
            // Ohne Rückschreiben wäre die Reparatur bei jedem Aufruf nötig — und der
            // nächste Formular-Aufbau zeigte die Häkchen wieder falsch.
            IPS_SetProperty($this->InstanceID, 'Lists', json_encode($repaired));
            IPS_ApplyChanges($this->InstanceID);
            return;   // ApplyChanges läuft gleich erneut, dann mit gültigen IDs
        }

        // Haushaltsweit durchreichen: das Häkchen soll überall wirken, nicht nur in
        // dieser Kachel. Die Bridge ist die maßgebliche Quelle (ihr Attribut steuert
        // App und Web-App). Neue public-Funktionen gibt es erst nach einem
        // Kernel-Neustart — bis dahin bleibt es bei der lokalen Wirkung.
        $bridgeID = $this->GetBridgeID();
        if ($bridgeID <= 0 || !function_exists('LAB_SetListHidden')) {
            return;
        }
        foreach ($repaired as $row) {
            @LAB_SetListHidden($bridgeID, $row['instanceID'], $row['hide']);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                return;
            case VM_UPDATE:
                // Nicht direkt pushen: 250 ms Fenster sammeln, damit die mehreren
                // Stat-Variablen-Writes einer Mutation EINEN Push ergeben. Fällt der
                // Timer aus (z.B. Modul-Reload ohne Kernel-Neustart → noch nicht
                // registriert), direkt pushen statt die Updates zu verlieren.
                try {
                    $this->SetTimerInterval('Coalesce', 250);
                } catch (Throwable $e) {
                    $this->PushChangedInstanceStates();
                }
                return;
        }
    }

    public function RequestAction(string $ident, mixed $value): void
    {
        switch ($ident) {
            case 'GetState':
                $this->RescanIfChanged();
                $this->PushFullState();
                return;
            case 'Call':
                $this->HandleCall($this->DecodeValue($value));
                return;
            case 'AiCall':
                $this->HandleAiCall((string)$value);
                return;
            case 'AiResult':
                // Rückkanal von der AppBridge → an die Kachel weiterreichen
                $this->aiResultSeen = true;
                $r = json_decode((string)$value, true);
                if (is_array($r)) {
                    $this->Push([
                        'type'   => 'aiResult',
                        'txn'    => (string)($r['txn'] ?? ''),
                        'status' => (int)($r['status'] ?? 200),
                        'json'   => $r['json'] ?? null,
                    ]);
                }
                return;
            case 'CoalescedPush':
                try { $this->SetTimerInterval('Coalesce', 0); } catch (Throwable $e) {}
                $this->PushChangedInstanceStates();
                return;
            // Verzoegerter voller Push (siehe ApplyChanges).
            case 'DeferredPush':
                try { $this->SetTimerInterval('DeferredPush', 0); } catch (Throwable $e) {}
                $this->PushFullState();
                return;
            case 'CheckRevisions':
                $this->HandleCheckRevisions($this->DecodeValue($value));
                return;
            case 'ReportVisuTheme':
                // Stiller Speicher: die Kachel meldet die CSS-Variablen der Visu,
                // die AppBridge liefert sie der App/Web-App über die Discovery aus.
                $data = json_decode((string)$value, true);
                if (!is_array($data)) {
                    return;
                }
                $scheme = ($data['scheme'] ?? '') === 'dark' ? 'dark' : 'light';
                $colors = [];
                foreach (['accent', 'content', 'card'] as $key) {
                    $color = strtolower(trim((string)($data[$key] ?? '')));
                    if (preg_match('/^#[0-9a-f]{6}$/', $color)) {
                        $colors[$key] = $color;
                    }
                }
                if (count($colors) === 3) {
                    $theme = json_decode($this->ReadAttributeString('VisuTheme'), true);
                    if (!is_array($theme)) {
                        $theme = [];
                    }
                    $theme[$scheme] = $colors;
                    $this->WriteAttributeString('VisuTheme', json_encode($theme));
                }
                return;
        }
        throw new Exception($this->Translate('Invalid Ident'));
    }

    /**
     * Von der Kachel gemeldete Visu-Farben ({"dark":{accent,content,card},
     * "light":{...}}) — von der AppBridge in der Discovery ausgeliefert.
     */
    public function GetVisuTheme(): string
    {
        return $this->ReadAttributeString('VisuTheme');
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string)@file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            $form = ['elements' => []];
        }

        // Benutzer-Optionen aus der SymDo Bridge
        $options  = [['caption' => $this->Translate('No user'), 'value' => '']];
        $bridgeID = $this->GetBridgeID();
        if ($bridgeID > 0 && function_exists('LAB_GetUsers')) {
            $users = json_decode((string)@LAB_GetUsers($bridgeID), true);
            if (is_array($users)) {
                foreach ($users as $user) {
                    if (!is_array($user) || !isset($user['id'], $user['name'])) {
                        continue;
                    }
                    $options[] = ['caption' => (string)$user['name'], 'value' => (string)$user['id']];
                }
            }
        }

        // Listen-Zeilen: aktuelles Discovery-Set, gespeicherte Ausblenden-Flags mergen
        $savedHide = [];
        $savedRows = json_decode($this->ReadPropertyString('Lists'), true);
        if (is_array($savedRows)) {
            foreach ($savedRows as $row) {
                if (is_array($row) && isset($row['instanceID'])) {
                    $savedHide[(int)$row['instanceID']] = (bool)($row['hide'] ?? false);
                }
            }
        }
        $bridgeHidden = $this->GetBridgeHiddenIDs();
        $values       = [];
        foreach ($this->DiscoverInstances() as $inst) {
            $suffix   = in_array($inst['id'], $bridgeHidden, true)
                ? ' (' . $this->Translate('hidden in the app') . ')'
                : '';
            $values[] = [
                'instanceID' => $inst['id'],
                'name'       => IPS_GetName($inst['id']) . $suffix,
                'kind'       => $this->Translate($inst['kind'] === 'shopping' ? 'Shopping list' : 'ToDo list'),
                'hide'       => $savedHide[$inst['id']] ?? false,
            ];
        }

        $this->SetFormValues($form['elements'], 'DefaultUserID', 'options', $options);
        $this->SetFormValues($form['elements'], 'Lists', 'values', $values);
        if ($bridgeID <= 0) {
            $this->SetFormValues($form['elements'], 'BridgeHint', 'visible', true);
        }

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Aggregat-Payload der Kachel als JSON — Diagnose-Getter (GetVisualizationTile bekommt keinen Prefix-Wrapper). */
    public function GetTilePayload(): string
    {
        return json_encode($this->BuildFullPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            $this->LogMessage('GetVisualizationTile: module.html could not be loaded. path=' . $path, KL_WARNING);
            return '';
        }

        // Initial-Payload inline mitgeben, damit die Kachel ohne Roundtrip rendert
        $payload = json_encode($this->BuildFullPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $html . '<script>handleMessage(' . $payload . ');</script>';
    }

    // ---------------------------------------------------------------------
    // Discovery & Abos
    // ---------------------------------------------------------------------

    /** @return array<int, array{id: int, kind: string}> */
    private function DiscoverInstances(): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID(self::SHOPPING_MODULE_GUID) as $id) {
            $result[] = ['id' => (int)$id, 'kind' => 'shopping'];
        }
        foreach (IPS_GetInstanceListByModuleID(self::TODO_MODULE_GUID) as $id) {
            $result[] = ['id' => (int)$id, 'kind' => 'todo'];
        }
        return $result;
    }

    private function GetInstanceKind(int $id): ?string
    {
        // Bounds prüfen bevor IPS_InstanceExists — sonst warnt Symcon bei
        // einer aus der Kachel gesendeten Fantasie-ID (> 60000)
        if ($id <= 0 || $id > 60000 || !IPS_InstanceExists($id)) {
            return null;
        }
        $moduleID = IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '';
        return match ($moduleID) {
            self::SHOPPING_MODULE_GUID => 'shopping',
            self::TODO_MODULE_GUID     => 'todo',
            default                    => null,
        };
    }

    private function ResubscribeAll(): void
    {
        // Alte Abos/Referenzen sauber lösen (kein Leak bei Instanz-Änderungen)
        $previous = json_decode($this->ReadAttributeString('SubscribedVarIDs'), true);
        if (is_array($previous)) {
            foreach ($previous as $oldID) {
                $oldID = (int)$oldID;
                if ($oldID > 0) {
                    $this->UnregisterMessage($oldID, VM_UPDATE);
                }
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        $subscribed = [];
        $known      = [];
        foreach ($this->DiscoverInstances() as $inst) {
            $known[] = $inst['id'];
            $idents  = $inst['kind'] === 'shopping'
                ? self::SHOPPING_TRIGGER_IDENTS
                : self::TODO_TRIGGER_IDENTS;
            $this->RegisterReference($inst['id']);
            foreach ($idents as $ident) {
                $varID = @IPS_GetObjectIDByIdent($ident, $inst['id']);
                if (is_int($varID) && $varID > 0 && IPS_VariableExists($varID)) {
                    $this->RegisterMessage($varID, VM_UPDATE);
                    $subscribed[] = $varID;
                }
            }
        }
        sort($known);

        $this->WriteAttributeString('SubscribedVarIDs', json_encode($subscribed));
        $this->WriteAttributeString('KnownInstanceIDs', json_encode($known));
    }

    /** Vergleicht das Discovery-Set mit dem letzten Stand; bei Änderung wird neu abonniert. */
    private function RescanIfChanged(): bool
    {
        $current = array_map(static fn(array $inst): int => $inst['id'], $this->DiscoverInstances());
        sort($current);
        $known = json_decode($this->ReadAttributeString('KnownInstanceIDs'), true);
        $known = is_array($known) ? array_map('intval', $known) : [];
        if ($current === $known) {
            return false;
        }
        $this->ResubscribeAll();
        return true;
    }

    // ---------------------------------------------------------------------
    // Payload-Bau
    // ---------------------------------------------------------------------

    private function BuildFullPayload(): array
    {
        $bridgeID  = $this->GetBridgeID();
        $hiddenIDs = $this->GetAllHiddenIDs();

        $instances      = [];
        $states         = [];
        $images         = [];
        $brands         = [];
        $shoppingExtras = [];
        $revisions      = [];

        foreach ($this->DiscoverInstances() as $inst) {
            $id       = $inst['id'];
            $kind     = $inst['kind'];
            $isHidden = in_array($id, $hiddenIDs, true);

            $instances[] = [
                'id'     => $id,
                'kind'   => $kind,
                'name'   => IPS_GetName($id),
                'hidden' => $isHidden,
            ];
            if ($isHidden) {
                continue;
            }

            $parsed = $this->GetInstanceStateParsed($id, $kind);
            if ($parsed === null) {
                continue;
            }
            // Bilder sind per Liste über ShowProductImages schaltbar: nur wenn
            // DIESE Liste eigene Bilder liefert, darf die Kachel welche zeigen
            $imagesEnabled = $kind === 'shopping'
                && is_array($parsed['state']['availableImages'] ?? null)
                && $parsed['state']['availableImages'] !== [];
            $state = $this->StripState($kind, $parsed['state'], $images, $brands);

            $states[(string)$id]    = ['kind' => $kind, 'revision' => $parsed['revision'], 'state' => $state];
            $revisions[(string)$id] = $parsed['revision'];

            if ($kind === 'shopping' && function_exists('SL_GetTileImageBase')) {
                $shoppingExtras[(string)$id] = [
                    'imageBase'     => (string)@SL_GetTileImageBase($id),
                    'extApiBase'    => function_exists('SL_GetTileExtApiBase') ? (string)@SL_GetTileExtApiBase($id) : '',
                    'imagesEnabled' => $imagesEnabled,
                ];
            }
        }

        // WICHTIG: BuildFullPayload ist NEBENWIRKUNGSFREI bzgl. der Push-Gates.
        // Weder LastPushedRevisions noch LastHiddenIDs werden hier geschrieben —
        // sonst würde das bloße Öffnen einer neuen Kachel (GetVisualizationTile)
        // Änderungen für bereits offene Betrachter verschlucken. Nur echte
        // Broadcasts (PushFullState/PushMeta) aktualisieren die Gates.
        $this->lastBuiltRevisions = $revisions;

        return [
            'type'            => 'state',
            'users'           => $this->GetUsers(),
            'defaultUserID'   => $this->ReadPropertyString('DefaultUserID'),
            'bridgeAvailable' => $bridgeID > 0,
            'aiEnabled'       => ($bridgeID > 0 ? (bool)@IPS_GetProperty($bridgeID, 'AiEnabled') : false),
            'tabs'            => $this->GetVisibleTabs(),
            'hiddenIDs'       => $hiddenIDs,
            'instances'       => $instances,
            'states'          => (object)$states,
            'images'          => (object)$images,
            'brands'          => (object)$brands,
            'shoppingExtras'  => (object)$shoppingExtras,
        ];
    }

    /**
     * Trifft ein Aufruf eine Instanz, deren Interface (noch) nicht bereit ist,
     * emittiert Symcon „InstanceInterface is not available"/„Attribut … nicht
     * gefunden" als PHP-WARNING — das fängt kein try/catch (Warning ≠
     * Throwable). Deshalb vorab gaten, damit ein nicht-bereites Geschwister
     * still übersprungen wird statt das Log zu fluten.
     *
     * Gegatet wird der KERNEL-Runlevel, NICHT der Instanzstatus. Der Status ist
     * hier unbrauchbar: Symcon setzt ihn bei der Erzeugung und berechnet ihn nie
     * neu, und ein `SetStatus(IS_ACTIVE)` aus `ApplyChanges` überlebt den
     * Hochlauf nicht. Gemessen 2026-08-10 nach einem Kernel-Neustart: alle fünf
     * ToDo-Listen auf IS_CREATING (101), während `TDL_GetAppState` einwandfrei
     * 4/0/39/47/10 Aufgaben lieferte. Die alte Abfrage auf IS_ACTIVE hat
     * deshalb nach JEDEM Neustart sämtliche ToDo-Listen aus dem Payload
     * geworfen — die Kachel zeigte keine Aufgaben mehr. Der Runlevel trifft
     * genau den Fall, um den es oben geht: die Warnungen entstehen im Hochlauf.
     */
    private function IsInstanceReady(int $id): bool
    {
        return $id > 0
            && IPS_InstanceExists($id)
            && IPS_GetKernelRunlevel() === KR_READY;
    }

    /** @return array{revision: int, state: array<string, mixed>}|null */
    private function GetInstanceStateParsed(int $id, string $kind): ?array
    {
        if (!$this->IsInstanceReady($id)) {
            return null;
        }
        try {
            if ($kind === 'shopping' && function_exists('SL_GetAppState')) {
                $json = SL_GetAppState($id);
            } elseif ($kind === 'todo' && function_exists('TDL_GetAppState')) {
                $json = TDL_GetAppState($id);
            } else {
                return null;
            }
        } catch (Throwable $e) {
            $this->SendDebug('GetAppState', $id . ': ' . $e->getMessage(), 0);
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !is_array($decoded['state'] ?? null)) {
            return null;
        }
        return ['revision' => (int)($decoded['revision'] ?? 0), 'state' => $decoded['state']];
    }

    /**
     * Entschlackt einen Instanz-State fürs Aggregat: Bild-Maps werden EINMAL
     * top-level gehoisted (identische Asset-Basis aller Einkaufslisten), die
     * Todo-Benutzerliste kommt autoritativ von der Bridge.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $images
     * @param array<string, mixed> $brands
     * @return array<string, mixed>
     */
    private function StripState(string $kind, array $state, array &$images, array &$brands): array
    {
        if ($kind === 'shopping') {
            if ($images === [] && is_array($state['availableImages'] ?? null) && $state['availableImages'] !== []) {
                $images = $state['availableImages'];
            }
            if ($brands === [] && is_array($state['availableBrands'] ?? null) && $state['availableBrands'] !== []) {
                $brands = $state['availableBrands'];
            }
            unset($state['availableImages'], $state['availableBrands']);
        } else {
            unset($state['users']);
        }
        return $state;
    }

    private function GetInstanceRevision(int $id, string $kind): int
    {
        if (!$this->IsInstanceReady($id)) {
            return 0;
        }
        try {
            if ($kind === 'shopping' && function_exists('SL_GetAppRevision')) {
                return (int)SL_GetAppRevision($id);
            }
            if ($kind === 'todo' && function_exists('TDL_GetAppRevision')) {
                return (int)TDL_GetAppRevision($id);
            }
        } catch (Throwable $e) {
            $this->SendDebug('GetAppRevision', $id . ': ' . $e->getMessage(), 0);
        }
        return 0;
    }

    // ---------------------------------------------------------------------
    // Bridge (Benutzer, haushaltsweites Hidden-Flag) — tolerant bei Abwesenheit
    // ---------------------------------------------------------------------

    /**
     * Sichtbare Bereiche als Payload-Block.
     *
     * Gelesen wird über IPS_GetConfiguration statt IPS_GetProperty, weil die drei
     * Eigenschaften in Create() entstehen und erst beim nächsten Kernel-Start
     * existieren. IPS_GetProperty liefert bis dahin `false` PLUS eine PHP-Warnung
     * (gemessen) — und eine Warnung fängt kein try/catch. Der Standard hier ist
     * true, sonst wären alle Bereiche verschwunden, bevor sich der Schalter
     * überhaupt bedienen lässt.
     *
     * @return array{dashboard:bool,shopping:bool,todos:bool}
     */
    private function GetVisibleTabs(): array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $read = static function (string $name) use ($cfg): bool {
            return (is_array($cfg) && array_key_exists($name, $cfg)) ? (bool)$cfg[$name] : true;
        };
        return [
            'dashboard' => $read('ShowDashboard'),
            'shopping'  => $read('ShowShopping'),
            'todos'     => $read('ShowTodos'),
        ];
    }

    private function GetBridgeID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::BRIDGE_MODULE_GUID);
        if (!is_array($ids) || count($ids) === 0) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }

    /** @return array<int, array{id: string, name: string, avatar: string}> */
    private function GetUsers(): array
    {
        $bridgeID = $this->GetBridgeID();
        if ($bridgeID <= 0 || !function_exists('LAB_GetUsersForTile')) {
            return [];
        }
        $decoded = json_decode((string)@LAB_GetUsersForTile($bridgeID), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return int[] */
    private function GetBridgeHiddenIDs(): array
    {
        $bridgeID = $this->GetBridgeID();
        if ($bridgeID <= 0 || !function_exists('LAB_GetHiddenLists')) {
            return [];
        }
        $decoded = json_decode((string)@LAB_GetHiddenLists($bridgeID), true);
        return is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
    }

    /** @return int[] Ausblendungen dieser Kachel (Formular-Liste) */
    private function GetTileHiddenIDs(): array
    {
        $rows = json_decode($this->ReadPropertyString('Lists'), true);
        if (!is_array($rows)) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row) && (bool)($row['hide'] ?? false) && (int)($row['instanceID'] ?? 0) > 0) {
                $result[] = (int)$row['instanceID'];
            }
        }
        return $result;
    }

    /** @return int[] Bridge-Ausblendungen (App, haushaltsweit) ∪ Kachel-Ausblendungen */
    private function GetAllHiddenIDs(): array
    {
        return array_values(array_unique(array_merge($this->GetBridgeHiddenIDs(), $this->GetTileHiddenIDs())));
    }

    // ---------------------------------------------------------------------
    // Kachel-Protokoll
    // ---------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function HandleCall(array $data): void
    {
        $instanceID = (int)($data['instanceID'] ?? 0);
        $action     = (string)($data['action'] ?? '');
        $txn        = (string)($data['txn'] ?? '');
        $kind       = $this->GetInstanceKind($instanceID);
        if ($kind === null || $action === '' || !$this->IsInstanceReady($instanceID)) {
            // Fehler als Push beantworten statt werfen: IPS_RequestAction schluckt
            // Exceptions, die Kachel würde sonst ewig auf die txn warten
            $this->Push([
                'type'       => 'instanceState',
                'instanceID' => $instanceID,
                'kind'       => $kind ?? '',
                'revision'   => 0,
                'state'      => null,
                'ok'         => false,
                'error'      => 'invalid_call',
                'txn'        => $txn,
            ]);
            return;
        }

        // Payload kommt als Objekt oder Roh-String; die Action-Whitelist
        // liegt bewusst im Ziel-Modul (SL_/TDL_AppCall)
        $payload     = $data['payload'] ?? '';
        $payloadJson = is_string($payload)
            ? $payload
            : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $result = $kind === 'shopping'
                ? SL_AppCall($instanceID, $action, (string)$payloadJson)
                : TDL_AppCall($instanceID, $action, (string)$payloadJson);
            $decoded = json_decode($result, true);
        } catch (Throwable $e) {
            $decoded = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!is_array($decoded)) {
            $decoded = ['ok' => false, 'error' => 'invalid_response'];
        }
        $this->PushInstanceState($instanceID, $kind, $decoded, $txn);
    }

    /**
     * KI-Relay für die Kachel: leitet {path,payload,txn} an die AppBridge weiter
     * (LAB_AiRelay) und schickt das Ergebnis als 'aiResult'-Nachricht zur Kachel
     * zurück. So funktioniert die KI-Analyse auch ohne REST-Token.
     */
    private function HandleAiCall(string $json): void
    {
        $req = json_decode($json, true);
        if (!is_array($req)) {
            return;
        }
        $txn      = (string)($req['txn'] ?? '');
        $bridgeID = $this->GetBridgeID();
        // IsInstanceReady prüfen: bei inaktiver/veralteter Bridge gibt
        // IPS_RequestAction nur eine PHP-Warning aus (keine Throwable), es käme
        // also nie ein AiResult und das txn-Promise der Kachel würde ewig warten.
        if ($bridgeID > 0 && $this->IsInstanceReady($bridgeID)) {
            $this->aiResultSeen = false;
            try {
                // Bridge extrahiert und ruft danach IPS_RequestAction($this,'AiResult') → Push zur Kachel.
                IPS_RequestAction($bridgeID, 'AiTileRequest', json_encode([
                    'path'    => (string)($req['path'] ?? ''),
                    'payload' => $req['payload'] ?? [],
                    'txn'     => $txn,
                    'sdwa'    => $this->InstanceID,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                // Das Relay ist synchron: die Bridge pusht ihr AiResult im selben
                // Aufruf. Kam keins, ist die Antwort ausgefallen → Fehler melden.
                if ($this->aiResultSeen) {
                    return;
                }
            } catch (Throwable $e) {
                // fällt unten in die Fehlerantwort
            }
        }
        $this->Push(['type' => 'aiResult', 'txn' => $txn, 'status' => 200, 'json' => [
            'ok' => false, 'error' => ['code' => 'ai_unavailable', 'message' => $this->Translate('Could not reach the AI service.')],
        ]]);
    }

    /** @param array<string, mixed> $data */
    private function HandleCheckRevisions(array $data): void
    {
        // Meta neu pushen bei geändertem Instanz-Set ODER geänderter
        // Sichtbarkeit (z. B. Liste in der App aus-/eingeblendet) — sonst
        // bliebe das hidden-Flag einer bereits offenen Kachel dauerhaft stale
        if ($this->RescanIfChanged() || $this->HiddenChanged()) {
            $this->PushMeta();
        }
        $clientRevisions = is_array($data['revisions'] ?? null) ? $data['revisions'] : [];
        $hiddenIDs       = $this->GetAllHiddenIDs();

        foreach ($this->DiscoverInstances() as $inst) {
            if (in_array($inst['id'], $hiddenIDs, true)) {
                continue;
            }
            $current = $this->GetInstanceRevision($inst['id'], $inst['kind']);
            $client  = (int)($clientRevisions[(string)$inst['id']] ?? -1);
            if ($current === $client) {
                continue;
            }
            $parsed = $this->GetInstanceStateParsed($inst['id'], $inst['kind']);
            if ($parsed !== null) {
                $this->PushInstanceState($inst['id'], $inst['kind'], ['ok' => true] + $parsed);
            }
        }
    }

    /** VM_UPDATE: nur Instanzen pushen, deren Revision sich seit dem letzten Push geändert hat. */
    private function PushChangedInstanceStates(): void
    {
        $pushed = json_decode($this->ReadAttributeString('LastPushedRevisions'), true);
        $pushed = is_array($pushed) ? $pushed : [];

        $hiddenIDs = $this->GetAllHiddenIDs();
        $changed   = false;
        foreach ($this->DiscoverInstances() as $inst) {
            if (in_array($inst['id'], $hiddenIDs, true)) {
                continue;
            }
            $current = $this->GetInstanceRevision($inst['id'], $inst['kind']);
            if ((int)($pushed[(string)$inst['id']] ?? -1) === $current) {
                continue;
            }
            $parsed = $this->GetInstanceStateParsed($inst['id'], $inst['kind']);
            if ($parsed === null) {
                continue;
            }
            $this->PushInstanceState($inst['id'], $inst['kind'], ['ok' => true] + $parsed);
            $pushed[(string)$inst['id']] = $parsed['revision'];
            $changed = true;
        }
        if ($changed) {
            $this->WriteAttributeString('LastPushedRevisions', json_encode($pushed));
        }
    }

    /** @param array<string, mixed> $data AppCall-/GetAppState-Ergebnis mit state+revision */
    private function PushInstanceState(int $instanceID, string $kind, array $data, string $txn = ''): void
    {
        $images = [];
        $brands = [];
        $state  = is_array($data['state'] ?? null)
            ? $this->StripState($kind, $data['state'], $images, $brands)
            : null;
        $revision = (int)($data['revision'] ?? 0);
        $this->RememberRevision($instanceID, $revision);

        $this->Push([
            'type'       => 'instanceState',
            'instanceID' => $instanceID,
            'kind'       => $kind,
            'revision'   => $revision,
            'state'      => $state,
            'ok'         => (bool)($data['ok'] ?? true),
            'error'      => $data['error'] ?? null,
            'txn'        => $txn,
        ]);
    }

    private function PushMeta(): void
    {
        $hiddenIDs = $this->GetAllHiddenIDs();
        $instances = [];
        foreach ($this->DiscoverInstances() as $inst) {
            $instances[] = [
                'id'     => $inst['id'],
                'kind'   => $inst['kind'],
                'name'   => IPS_GetName($inst['id']),
                'hidden' => in_array($inst['id'], $hiddenIDs, true),
            ];
        }
        $this->WriteAttributeString('LastHiddenIDs', json_encode($hiddenIDs));
        $this->Push([
            'type'            => 'meta',
            'instances'       => $instances,
            'hiddenIDs'       => $hiddenIDs,
            'bridgeAvailable' => $this->GetBridgeID() > 0,
            // Muss mit: ein Meta-Push ist der einzige Push nach einer reinen
            // Sichtbarkeits-Änderung, sonst zöge die offene Kachel nicht nach.
            'tabs'            => $this->GetVisibleTabs(),
        ]);
    }

    private function PushFullState(): void
    {
        $payload = $this->BuildFullPayload();
        // Echter Broadcast an ALLE Betrachter → beide Gates dürfen jetzt auf den
        // gebauten Stand gesetzt werden (anders als beim bloßen Bauen für die
        // Injektion in GetVisualizationTile)
        $this->WriteAttributeString('LastPushedRevisions', json_encode($this->lastBuiltRevisions));
        $this->WriteAttributeString('LastHiddenIDs', json_encode($this->GetAllHiddenIDs()));
        $this->Push($payload);
    }

    /** Ob sich die (haushaltsweit+kachel-)ausgeblendeten IDs seit dem letzten Meta-Push geändert haben. */
    private function HiddenChanged(): bool
    {
        $current = $this->GetAllHiddenIDs();
        sort($current);
        $last = json_decode($this->ReadAttributeString('LastHiddenIDs'), true);
        $last = is_array($last) ? array_values(array_map('intval', $last)) : [];
        sort($last);
        return $current !== $last;
    }

    /** @param array<string, mixed> $payload */
    private function Push(array $payload): void
    {
        $this->UpdateVisualizationValue(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function RememberRevision(int $instanceID, int $revision): void
    {
        $pushed = json_decode($this->ReadAttributeString('LastPushedRevisions'), true);
        $pushed = is_array($pushed) ? $pushed : [];
        if ((int)($pushed[(string)$instanceID] ?? -1) === $revision) {
            return;
        }
        $pushed[(string)$instanceID] = $revision;
        $this->WriteAttributeString('LastPushedRevisions', json_encode($pushed));
    }

    // ---------------------------------------------------------------------
    // Helfer
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function DecodeValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Setzt eine Eigenschaft (options/values/visible/…) auf dem Formular-Element
     * mit dem angegebenen Namen — rekursiv über verschachtelte Elemente.
     *
     * @param array<int, mixed> $elements
     */
    private function SetFormValues(array &$elements, string $name, string $key, mixed $value): void
    {
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['name'] ?? '') === $name) {
                $element[$key] = $value;
                return;
            }
            if (is_array($element['items'] ?? null)) {
                $this->SetFormValues($element['items'], $name, $key, $value);
            }
        }
        unset($element);
    }
}
