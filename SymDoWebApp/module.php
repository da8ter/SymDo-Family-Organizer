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
    private const GATEWAY_MODULE_GUID  = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';
    private const TIMETABLE_MODULE_GUID = '{C22E0A96-1BC7-4029-B8C5-7E94E4F2A9D9}';

    // Stat-Variablen der Quell-Module: jede Mutation läuft durch SendState und
    // aktualisiert mindestens eine davon — unser Änderungs-Trigger ohne Polling.
    private const SHOPPING_TRIGGER_IDENTS = ['ItemCount', 'LastUsed'];
    private const TODO_TRIGGER_IDENTS     = ['OpenTasks', 'OverdueTasks', 'DueTodayTasks'];

    /** Revisionen des zuletzt gebauten Payloads (BuildFullPayload → PushFullState, gleicher Aufruf). */
    private array $lastBuiltRevisions = [];

    /**
     * Appweite Bedienelemente, einmal je PHP-Aufruf gelesen (StripState läuft je
     * Liste). Bewusst ein Objekt-Feld und kein static: Symcon baut das Modulobjekt
     * pro Aufruf neu, ein static im Worker könnte nach einem Übernehmen veralten.
     */
    private ?array $buttonFlagsCache = null;


    /**
     * Gesetzt, wenn ApplyChanges vom Kernelstart kommt und nicht von einem Übernehmen.
     * Dann darf die gespeicherte Ausblende-Liste NICHT ans Gateway durchgereicht werden:
     * dort kann inzwischen die App etwas ausgeblendet haben, wovon diese Property nichts
     * weiß — Durchreichen hieße überschreiben.
     */
    private bool $applyFromKernelStart = false;

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
        // Sichtbare Bereiche. Standard überall true — abschalten ist die Ausnahme.
        // Die Favoriten haben KEINEN eigenen Schalter mehr: sie gehören zur
        // Einkaufsliste und gehen als Blatt über das Herz in ihrer Kopfzeile auf.
        $this->RegisterPropertyBoolean('ShowDashboard', true);
        $this->RegisterPropertyBoolean('ShowShopping', true);
        $this->RegisterPropertyBoolean('ShowTodos', true);
        // Kalender: haengt am Store-Modul OpenCalendar. Vorgabe an — ist es nicht
        // installiert, blendet die Oberflaeche den Bereich ohnehin selbst aus.
        $this->RegisterPropertyBoolean('ShowCalendar', true);
        // Notizen: liegen im Gateway, brauchen also eines. Vorgabe an — ohne Gateway
        // blendet die Oberflaeche den Bereich selbst aus.
        $this->RegisterPropertyBoolean('ShowNotes', true);
        // KI-Eingangskorb: zeigt, was die Analyse aus Mails und Dateien gelesen hat.
        // Liegt im Gateway, deshalb blendet die Oberflaeche ihn ohne eines selbst aus.
        $this->RegisterPropertyBoolean('ShowKi', true);
        /* Welche Stundenplan-Instanzen die Oberflaeche zeigt — eine Zeile je
           Instanz. Stand bis hier im Gateway; dort sucht sie niemand: sichtbare
           Bereiche werden hier eingestellt, nicht dort. Eigenschaften registriert
           Symcon FEST in Create(), eine je Instanz ist damit unmoeglich — deshalb
           eine Liste, deren Zeilen beim Aufbau des Formulars aus den vorhandenen
           Instanzen entstehen und beim Uebernehmen zurueckgeschrieben werden. */
        $this->RegisterPropertyString('TimetableChoice', '[]');

        // Bedienelemente der Web-App. Sie gelten APPWEIT für alle Listen: die
        // gleichnamigen Schalter der ToDo- und Einkaufslisten-Instanzen werden hier
        // bewusst ignoriert, weil die Web-App alle Listen in einer Oberfläche zeigt
        // und ein Wechsel des Erscheinungsbilds von Liste zu Liste dort nur störte.
        // In der KACHEL der jeweiligen Liste gelten weiterhin deren eigene Schalter.
        // Vorgaben wie bisher: sichtbare Elemente bleiben an, die beiden neueren
        // Zeilenknöpfe bleiben aus.
        $this->RegisterPropertyBoolean('ShowOverview', true);
        $this->RegisterPropertyBoolean('ShowMemberBar', true);
        $this->RegisterPropertyBoolean('ShowCreateButton', true);
        $this->RegisterPropertyBoolean('ShowSorting', true);
        // Fuenf gleichrangige Abzeichen-Schalter, kein Hauptschalter.
        $this->RegisterPropertyBoolean('ShowQuantityBadge', true);
        $this->RegisterPropertyBoolean('ShowRecurrenceBadge', true);
        $this->RegisterPropertyBoolean('ShowDueBadge', true);
        $this->RegisterPropertyBoolean('ShowNotificationBadge', true);
        $this->RegisterPropertyBoolean('ShowPriorityBadge', true);
        $this->RegisterPropertyBoolean('ShowFavoriteHeart', true);
        $this->RegisterPropertyBoolean('ShowRowEditButton', false);
        $this->RegisterPropertyBoolean('ShowRowDeleteButton', false);
        $this->RegisterPropertyBoolean('ShowReorderHandle', true);

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
        // das Gateway liefert sie der SymDo-App/Web-App über die Discovery aus.
        $this->RegisterAttributeString('VisuTheme', '{}');
        // Briefkasten fuer den synchronen AiResult-Rueckruf des Gateways: der
        // Rueckruf laeuft auf einem ANDEREN Objekt derselben Instanz (gemessen
        // beim Essensplan-Bau) — ein Objektfeld ueberlebt die Grenze nicht,
        // ein Attribut schon. Das alte aiResultSeen-Feld blieb deshalb immer
        // false, und nach JEDEM erfolgreichen Kachel-KI-Aufruf ging zusaetzlich
        // ein ai_unavailable-Push raus (harmlos nur, weil die Kachel
        // aufgeloeste txn ignoriert).
        $this->RegisterAttributeString('AiSeenTxn', '');

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
        $this->SyncTimetableChoice();
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
     * Gateway nach.
     *
     * Hintergrund: Die Symcon-Formularoberfläche sichert beim Speichern nur Spalten,
     * die editierbar sind ODER `save` gesetzt haben (so dokumentiert; ohne `edit`
     * ist die Vorgabe `false`) — bei dieser Liste also lange allein `hide`.
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
            $this->UebernehmenNachtragen();
            return;   // ApplyChanges läuft gleich erneut, dann mit gültigen IDs
        }

        // Haushaltsweit durchreichen: das Häkchen soll überall wirken, nicht nur in
        // dieser Kachel. Das Gateway ist die maßgebliche Quelle (sein Attribut steuert
        // App und Web-App), deshalb gilt das Durchreichen NUR für ein echtes Übernehmen
        // im Formular. Beim Kernelstart würde dieselbe Schleife einen inzwischen in der
        // App gesetzten Zustand mit einer alten Property überschreiben.
        // Rest: der Reparaturpfad oben ruft IPS_ApplyChanges erneut, und der läuft in
        // einem neuen Objekt — dort ist das Kennzeichen wieder false. Trifft nur zu,
        // wenn gleichzeitig ungültige Instanz-IDs in der Property stehen. Eine echte
        // Delta-Erkennung bräuchte ein eigenes Attribut (Muster LastHiddenIDs) und damit
        // einen Kernel-Neustart.
        if ($this->applyFromKernelStart) {
            return;
        }
        $gatewayID = $this->GetAppGatewayID();
        if ($gatewayID <= 0 || !function_exists('TGW_SetListHidden')) {
            return;
        }
        foreach ($repaired as $row) {
            @TGW_SetListHidden($gatewayID, $row['instanceID'], $row['hide']);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->applyFromKernelStart = true;
                $this->ApplyChanges();
                $this->applyFromKernelStart = false;
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
            case 'PlayBriefing':
                // Derselbe Weg wie die öffentliche Funktion, nur ohne
                // Kernel-Neustart nutzbar: IPS_RequestAction($id, 'PlayBriefing', 0).
                $this->PlayBriefing();
                return;
            case 'AiResult':
                // Rückkanal vom Gateway → an die Kachel weiterreichen. Der
                // Briefkasten sagt HandleAiCall, dass die Antwort ankam
                // (@-Wächter: bis zum Kernel-Neustart fehlt das Attribut).
                $r = json_decode((string)$value, true);
                if (is_array($r)) {
                    @$this->WriteAttributeString('AiSeenTxn', (string)($r['txn'] ?? ''));
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
                // das Gateway liefert sie der App/Web-App über die Discovery aus.
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
     * "light":{...}}) — vom Gateway in der Discovery ausgeliefert.
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

        // Benutzer-Optionen aus dem SymDo Gateway
        $options  = [['caption' => $this->Translate('No user'), 'value' => '']];
        $gatewayID = $this->GetAppGatewayID();
        if ($gatewayID > 0 && function_exists('TGW_GetUsers')) {
            $users = json_decode((string)@TGW_GetUsers($gatewayID), true);
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
        $gatewayHidden = $this->GetGatewayHiddenIDs();
        $values       = [];
        foreach ($this->DiscoverInstances() as $inst) {
            $suffix   = in_array($inst['id'], $gatewayHidden, true)
                ? ' (' . $this->Translate('hidden in the app') . ')'
                : '';
            $values[] = [
                'instanceID' => $inst['id'],
                'name'       => IPS_GetName($inst['id']) . $suffix,
                'kind'       => $this->Translate($inst['kind'] === 'shopping' ? 'Shopping list' : 'ToDo list'),
                // Aus dem ZUSAMMENGEFÜHRTEN Zustand vorbelegen, nicht nur aus der eigenen
                // Property: beim Übernehmen wird jede Zeile ans Gateway geschrieben, und
                // ein ungesetztes Häkchen hätte das haushaltsweite Ausblenden stumm
                // aufgehoben. So entspricht das, was der Nutzer sieht, dem, was
                // zurückgeschrieben wird.
                'hide'       => ($savedHide[$inst['id']] ?? false)
                                || in_array($inst['id'], $gatewayHidden, true),
            ];
        }

        // Stundenplan-Zeilen: aus den VORHANDENEN Instanzen, das Haekchen aus der
        // Ablage. Eine neu angelegte Instanz taucht damit von selbst auf, eine
        // geloeschte verschwindet.
        $plaene = $this->TimetableRows();

        $this->SetFormValues($form['elements'], 'DefaultUserID', 'options', $options);
        $this->SetFormValues($form['elements'], 'Lists', 'values', $values);
        if ($plaene !== []) {
            /* Auf eine Eigenschaft, die es vor dem naechsten Kernel-Start nicht
               gibt, laesst „Uebernehmen" das GANZE Formular scheitern. Bis dahin
               steht dort ein Hinweis statt einer Liste, die nichts tut. */
            $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
            if (is_array($cfg) && array_key_exists('TimetableChoice', $cfg)) {
                $this->SetFormValues($form['elements'], 'TimetableChoice', 'values', $plaene);
                $this->SetFormValues($form['elements'], 'TimetableChoice', 'rowCount', max(2, count($plaene)));
                $this->SetFormValues($form['elements'], 'TimetableChoice', 'visible', true);
                $this->SetFormValues($form['elements'], 'TimetableHint', 'visible', true);
            } else {
                $this->SetFormValues($form['elements'], 'TimetableRestartHint', 'visible', true);
            }
        }
        if ($gatewayID <= 0) {
            $this->SetFormValues($form['elements'], 'GatewayHint', 'visible', true);
        }
        /* Mehrere Gateways: sagen, welches die App bedient. Waehlen laesst sich das
           NICHT — die Hook-Pfade sind fest, es bedient immer die Instanz mit der
           niedrigsten ID. Eine Auswahlliste boete hier einen Zustand an, den es
           nicht geben kann. Bei einem einzigen Gateway bleibt die Zeile weg; dann
           gibt es nichts zu unterscheiden. */
        $gateways = IPS_GetInstanceListByModuleID(self::GATEWAY_MODULE_GUID);
        if ($gatewayID > 0 && is_array($gateways) && count($gateways) > 1) {
            $this->SetFormValues($form['elements'], 'GatewayOwnerHint', 'caption', sprintf(
                $this->Translate('%d SymDo Gateway instances found. The app is served by "%s" (#%d) — the one with the lowest instance ID; the others only synchronise accounts.'),
                count($gateways), IPS_GetName($gatewayID), $gatewayID
            ));
            $this->SetFormValues($form['elements'], 'GatewayOwnerHint', 'visible', true);
        }

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Eine Zeile je Stundenplan-Instanz MIT EIGENEN DATEN.
     *
     * Instanzen, die ihre Daten aus einer anderen ziehen (`SourceInstanceID`),
     * bleiben weg: sie sind eine zweite Ansicht desselben Plans, und beide zu
     * zeigen hiesse jedes Kind doppelt.
     *
     * Gibt es keine, ist die Liste leer und das Formular blendet sie aus — ein
     * Schalter fuer etwas, das gar nicht existiert, ist Laerm.
     *
     * @return list<array<string,mixed>>
     */
    private function TimetableRows(): array
    {
        $wahl = $this->TimetableChoiceMap();
        $zeilen = [];
        foreach ($this->TimetableOwnInstances() as $id) {
            $cfg = json_decode((string)@IPS_GetConfiguration((int)$id), true);
            $kinder = [];
            foreach ((array)json_decode((string)($cfg['Children'] ?? '[]'), true) as $k) {
                if (is_array($k) && trim((string)($k['name'] ?? '')) !== '') {
                    $kinder[] = trim((string)$k['name']);
                }
            }
            $zeilen[] = [
                'id'     => (int)$id,
                'name'   => sprintf('%s (#%d)', IPS_GetName((int)$id), (int)$id),
                // Die Kinder mit anzeigen: nur so sieht man, dass zwei Instanzen
                // DIESELBEN fuehren — genau daran stand jedes Kind doppelt.
                'kinder' => $kinder === [] ? '—' : implode(', ', $kinder),
                'show'   => $wahl[(int)$id] ?? false,
            ];
        }
        return $zeilen;
    }

    /**
     * Die Stundenplan-Instanzen MIT EIGENEN DATEN, in der Reihenfolge, in der das
     * Formular sie zeigt.
     *
     * Diese Reihenfolge traegt die Zuordnung in SyncTimetableChoice() und darf
     * deshalb nur an EINER Stelle entstehen.
     *
     * @return list<int>
     */
    private function TimetableOwnInstances(): array
    {
        $ids = [];
        foreach (@IPS_GetInstanceListByModuleID(self::TIMETABLE_MODULE_GUID) as $id) {
            $cfg = json_decode((string)@IPS_GetConfiguration((int)$id), true);
            if (!is_array($cfg) || (int)($cfg['SourceInstanceID'] ?? 0) > 0) {
                continue;
            }
            $ids[] = (int)$id;
        }
        return $ids;
    }

    /**
     * Traegt die Instanz-Kennungen in die gespeicherte Stundenplan-Wahl nach.
     *
     * Dieselbe Falle wie bei SyncListVisibility: gesichert werden nur Spalten, die
     * editierbar sind oder `save` gesetzt haben — bei dieser Liste also lange allein
     * `show`. Auf einer Symbox mit 9.1 gemessen: nach dem Setzen des Haekchens stand
     * [{"show":true}] in der Eigenschaft, ohne `id`.
     *
     * Die Ursache ist behoben: beide Kennungsspalten tragen in form.json jetzt
     * `"save": true`. Diese Heilung bleibt fuer das, was vorher gespeichert wurde —
     * eine Anlage mit verstuemmelter Wahl repariert sich beim naechsten Uebernehmen
     * selbst, statt still einen leeren Stundenplan zu zeigen.
     * TimetableChoiceRows() verwirft eine Zeile ohne Kennung, die Karte blieb leer
     * — und weil eine unbekannte Instanz als AUS gilt, fehlte der Stundenplan in
     * der App, obwohl das Haekchen gesetzt war.
     *
     * Deshalb hier: fehlende Kennungen ueber die Position nachtragen. Das Formular
     * baut die Zeilen aus TimetableOwnInstances() in genau dieser Reihenfolge, und
     * gespeichert wird dieselbe Zeilenzahl. Bei abweichender Zahl (eine Instanz kam
     * dazu oder fiel weg, waehrend der Dialog offen war) wird NICHT geraten,
     * sondern nur uebernommen, was schon eine Kennung traegt.
     */
    private function SyncTimetableChoice(): void
    {
        // IPS_GetConfiguration statt ReadPropertyString: die Eigenschaft entsteht
        // in Create() und existiert erst beim naechsten Kernel-Start.
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('TimetableChoice', $cfg)) {
            return;
        }
        $rows = json_decode((string)$cfg['TimetableChoice'], true);
        if (!is_array($rows) || $rows === []) {
            return;
        }
        $eigene    = $this->TimetableOwnInstances();
        $repariert = [];
        $ergaenzt  = false;
        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 && count($rows) === count($eigene) && isset($eigene[$i])) {
                $id       = $eigene[$i];
                $ergaenzt = true;
            }
            if ($id <= 0) {
                continue;
            }
            $repariert[] = ['id' => $id, 'show' => (bool)($row['show'] ?? false)];
        }
        if (!$ergaenzt || $repariert === []) {
            return;
        }
        // Ohne Rueckschreiben waere die Reparatur bei jedem Aufruf noetig — und der
        // naechste Formular-Aufbau zeigte die Haekchen wieder falsch.
        IPS_SetProperty($this->InstanceID, 'TimetableChoice', json_encode($repariert));
        $this->UebernehmenNachtragen();
    }

    /**
     * Instanz-Kennung => anzeigen? aus der eigenen Ablage.
     *
     * Kennt die eigene Liste eine Instanz noch nicht, gilt die Angabe aus dem
     * GATEWAY — dort stand die Einstellung frueher. So ist die Wahl nach dem
     * Verschieben nicht verloren; mit dem ersten „Uebernehmen" hier steht sie
     * endgueltig in dieser Instanz.
     *
     * @return array<int,bool>
     */
    private function TimetableChoiceMap(): array
    {
        // IPS_GetConfiguration statt ReadPropertyString: die Eigenschaft entsteht
        // in Create() und existiert erst beim naechsten Kernel-Start.
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $karte = self::TimetableChoiceRows((string)($cfg['TimetableChoice'] ?? '[]'));
        $gatewayID = $this->GetAppGatewayID();
        if ($gatewayID <= 0) {
            return $karte;
        }
        $alt = json_decode((string)@IPS_GetConfiguration($gatewayID), true);
        foreach (self::TimetableChoiceRows((string)($alt['TimetableChoice'] ?? '[]')) as $id => $an) {
            if (!array_key_exists($id, $karte)) {
                $karte[$id] = $an;
            }
        }
        return $karte;
    }

    /**
     * Die gespeicherte Liste als Karte.
     *
     * @return array<int,bool>
     */
    private static function TimetableChoiceRows(string $roh): array
    {
        $karte = [];
        foreach ((array)json_decode($roh, true) as $z) {
            if (is_array($z) && (int)($z['id'] ?? 0) > 0) {
                $karte[(int)$z['id']] = (bool)($z['show'] ?? false);
            }
        }
        return $karte;
    }

    /** Aggregat-Payload der Kachel als JSON — Diagnose-Getter (GetVisualizationTile bekommt keinen Prefix-Wrapper). */
    private function GetTilePayload(): string
    {
        return (string)json_encode($this->BuildFullPayload(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            $this->LogMessage('GetVisualizationTile: module.html nicht lesbar, Pfad=' . $path, KL_WARNING);
            return '';
        }

        // Initial-Payload inline mitgeben, damit die Kachel ohne Roundtrip rendert
        // Siehe GetTilePayload: ein kaputtes Byte darf nicht die ganze Kachel leeren.
        $payload = (string)json_encode($this->BuildFullPayload(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
        $gatewayID  = $this->GetAppGatewayID();
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
            'gatewayAvailable' => $gatewayID > 0,
            'aiEnabled'       => ($gatewayID > 0 ? (bool)@IPS_GetProperty($gatewayID, 'AiEnabled') : false),
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
     * Todo-Benutzerliste kommt autoritativ vom Gateway.
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
        return $this->OverrideButtonFlags($kind, $state);
    }

    /**
     * Überschreibt die Bedienelement-Flags der Liste mit den appweiten dieser
     * Web-App. Die Listen schicken ihre eigenen Werte weiterhin mit — die gelten
     * für ihre eigene Kachel; hier werden sie verworfen.
     *
     * Gesetzt wird nur, was die jeweilige Oberfläche auch liest: die ToDo-Ansicht
     * kennt kein Favoritenherz, die Einkaufsansicht keine Mitglieder-Leiste.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function OverrideButtonFlags(string $kind, array $state): array
    {
        $flags = $this->ResolveButtonFlags();
        $relevant = $kind === 'shopping'
            ? ['showFavoriteHeart', 'showEditButton', 'showDeleteButton']
            : ['showOverview', 'showMemberBar', 'showCreateButton', 'showSorting',
               'showQuantityBadge', 'showRecurrenceBadge', 'showDueBadge',
               'showNotificationBadge', 'showPriorityBadge',
               'showEditButton', 'showDeleteButton', 'showReorderHandle'];
        foreach ($relevant as $name) {
            $state[$name] = $flags[$name];
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
    // Gateway (Benutzer, haushaltsweites Hidden-Flag) — tolerant bei Abwesenheit
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
     * @return array{dashboard:bool,shopping:bool,todos:bool,calendar:bool,notes:bool,ki:bool}
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
            'calendar'  => $read('ShowCalendar'),
            'notes'     => $read('ShowNotes'),
            'ki'        => $read('ShowKi'),
        ];
    }

    /**
     * Appweite Bedienelemente dieser Web-App.
     *
     * Einziger Auflösungspunkt. Die gleichnamigen Schalter der Listen-Instanzen
     * werden NICHT gelesen — die Web-App zeigt alle Listen in einer Oberfläche,
     * dort gilt ein einheitliches Erscheinungsbild. Die Werte überschreiben in
     * StripState() das, was die Listen im Zustand mitschicken.
     *
     * Gelesen wird wie bei GetVisibleTabs() über IPS_GetConfiguration: die
     * Eigenschaften entstehen in Create() und existieren erst beim nächsten
     * Kernel-Start. IPS_GetProperty liefert bis dahin `false` PLUS eine PHP-Warnung,
     * die kein try/catch fängt — und ein „an"-Schalter wäre für Bestandsnutzer
     * still aus, bevor er sich überhaupt bedienen lässt.
     *
     * @return array<string, bool>
     */
    private function ResolveButtonFlags(): array
    {
        if ($this->buttonFlagsCache !== null) {
            return $this->buttonFlagsCache;
        }
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $read = static function (string $name, bool $default) use ($cfg): bool {
            return (is_array($cfg) && array_key_exists($name, $cfg)) ? (bool)$cfg[$name] : $default;
        };
        $this->buttonFlagsCache = [
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
        return $this->buttonFlagsCache;
    }

    private function GetAppGatewayID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::GATEWAY_MODULE_GUID);
        if (!is_array($ids) || count($ids) === 0) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }

    /** @return array<int, array{id: string, name: string, avatar: string}> */
    private function GetUsers(): array
    {
        $gatewayID = $this->GetAppGatewayID();
        if ($gatewayID <= 0 || !function_exists('TGW_GetUsersForTile')) {
            return [];
        }
        $decoded = json_decode((string)@TGW_GetUsersForTile($gatewayID), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return int[] */
    private function GetGatewayHiddenIDs(): array
    {
        $gatewayID = $this->GetAppGatewayID();
        if ($gatewayID <= 0 || !function_exists('TGW_GetHiddenLists')) {
            return [];
        }
        $decoded = json_decode((string)@TGW_GetHiddenLists($gatewayID), true);
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

    /** @return int[] Gateway-Ausblendungen (App, haushaltsweit) ∪ Kachel-Ausblendungen */
    private function GetAllHiddenIDs(): array
    {
        return array_values(array_unique(array_merge($this->GetGatewayHiddenIDs(), $this->GetTileHiddenIDs())));
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
            : (string)json_encode($payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

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
     * KI-Relay für die Kachel: leitet {path,payload,txn} an das Gateway weiter
     * (TGW_AiRelay) und schickt das Ergebnis als 'aiResult'-Nachricht zur Kachel
     * zurück. So funktioniert die KI-Analyse auch ohne REST-Token.
     */
    /**
     * Spielt das Audio-Briefing in DIESER Kachel ab — für Skripte und Ereignisse:
     * `SDWA_PlayBriefing(<InstanzID>)`.
     *
     * Gezielt je Instanz: Die Nachricht geht über UpdateVisualizationValue, und das
     * erreicht ausschließlich die Betrachter dieser einen Kachel. Wer zwei Kacheln
     * hat (Küche und Flur), spricht sie einzeln an.
     *
     * Zwei Dinge, die der Rückgabewert NICHT verspricht:
     *
     * Erstens muss die Kachel gerade offen sein. Eine Visualisierung, die niemand
     * betrachtet, hat keinen Lautsprecher; die Nachricht verfällt dann.
     *
     * Zweitens darf der Browser Ton ohne Nutzergeste abweisen — das ist bei jedem
     * modernen Browser so, auch in der Symcon-App. In einer Kachel, die schon
     * angetippt wurde, spielt er; in einer frisch geladenen kann er stumm bleiben.
     * Die Kachel zeigt in diesem Fall einen Hinweis an, statt still zu bleiben.
     *
     * @return bool true = Nachricht ist hinausgegangen (kein Zustellnachweis).
     */
    public function PlayBriefing(): bool
    {
        $this->Push(['type' => 'briefingPlay']);
        return true;
    }

    private function HandleAiCall(string $json): void
    {
        $req = json_decode($json, true);
        if (!is_array($req)) {
            return;
        }
        $txn      = (string)($req['txn'] ?? '');
        $gatewayID = $this->GetAppGatewayID();
        // IsInstanceReady prüfen: bei inaktivem/veraltetem Gateway gibt
        // IPS_RequestAction nur eine PHP-Warning aus (keine Throwable), es käme
        // also nie ein AiResult und das txn-Promise der Kachel würde ewig warten.
        if ($gatewayID > 0 && $this->IsInstanceReady($gatewayID)) {
            @$this->WriteAttributeString('AiSeenTxn', '');
            try {
                // Das Gateway extrahiert und ruft danach IPS_RequestAction($this,'AiResult') → Push zur Kachel.
                IPS_RequestAction($gatewayID, 'AiTileRequest', json_encode([
                    'path'    => (string)($req['path'] ?? ''),
                    'payload' => $req['payload'] ?? [],
                    'txn'     => $txn,
                    'sdwa'    => $this->InstanceID,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                // Das Relay ist synchron: das Gateway pusht sein AiResult im selben
                // Aufruf — aber auf einem anderen Objekt, deshalb der Attribut-
                // Briefkasten. Steht dort nicht unsere txn, ist die Antwort
                // ausgefallen → Fehler melden.
                if ($txn !== '' && (string)@$this->ReadAttributeString('AiSeenTxn') === $txn) {
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
            'gatewayAvailable' => $this->GetAppGatewayID() > 0,
            // Muss mit: ein Meta-Push ist der einzige Push nach einer reinen
            // Sichtbarkeits-Änderung, sonst zöge die offene Kachel nicht nach.
            'tabs'            => $this->GetVisibleTabs(),
            // Und die Mitglieder: ein neu angelegtes Familienmitglied erreichte
            // eine offene Kachel bisher nie, es kam nur im vollen Zustand mit.
            'users'           => json_decode($this->GetUsers(), true),
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
            json_encode($payload, JSON_UNESCAPED_SLASHES)
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

    /**
     * Uebernehmen NACH dem laufenden Aufruf anstossen.
     *
     * Eine Selbstheilung, die eine Eigenschaft schreibt, muss sie auch wirksam
     * machen — und das geht nur ueber IPS_ApplyChanges. Steht der Aufruf aber
     * INNERHALB von ApplyChanges, ist es ein Aufruf in sich selbst: Symcon 9.1
     * lehnt ihn ab und bricht das Uebernehmen ab („Instanz ist durch eine selbst
     * gestartete Operation belegt", Code -32603). Bis 9.0 lief es stillschweigend
     * durch, deshalb ist es lange nicht aufgefallen.
     *
     * Der Einmal-Timer legt das Uebernehmen hinter den laufenden Aufruf. Beim
     * zweiten Durchgang ist die Selbstheilung erledigt, sie schreibt nichts mehr
     * und stoesst auch nichts mehr an — es bleibt bei genau einer Wiederholung.
     *
     * Ein Ident fuer alle Aufrufer: laufen mehrere Selbstheilungen im selben
     * Durchgang, genuegt EIN nachgelagertes Uebernehmen fuer alle.
     *
     * Der Timer ruft IPS_ApplyChanges unmittelbar und nicht ueber eine eigene
     * Funktion — so braucht es dafuer keine oeffentliche Schnittstelle.
     */
    private function UebernehmenNachtragen(): void
    {
        $this->RegisterOnceTimer('UebernehmenNachtragen', 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
    }

}
