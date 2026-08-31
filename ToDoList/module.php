<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/SyncHelper.php';
require_once __DIR__ . '/libs/CalDAVSync.php';
require_once __DIR__ . '/libs/GoogleTasksSync.php';
require_once __DIR__ . '/libs/MicrosoftToDoSync.php';
require_once __DIR__ . '/../libs/ListSource.php';
require_once __DIR__ . '/../libs/ExternalListSync.php';
require_once __DIR__ . '/libs/ExtListHooksTodo.php';

class SymDoToDoList extends IPSModuleStrict
{

    use SyncHelper;
    use CalDAVSync;
    use GoogleTasksSync;
    use MicrosoftToDoSync;
    use ExternalListSync;
    use ExtListHooksTodo;

    /**
     * Erklaert die zwei Gateway-Rollen im Formular.
     *
     * Die Verwirrung ist verstaendlich: „das Gateway" gibt es nicht. Die
     * ELTERN-Instanz traegt die Konten-Synchronisation (Google, Microsoft,
     * CalDAV) und darf mehrfach existieren — ein Instanzsatz je Konto. Die
     * App-Haelfte dagegen gibt es nur einmal, auf der Instanz mit der
     * niedrigsten ID: ihre Hook-Pfade sind fest. Deshalb steht hier auch kein
     * Auswahlfeld, sondern ein Satz.
     */
    private function GatewayRollenText(): string
    {
        $eltern = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $app    = $this->GetAppGatewayID();
        if ($eltern <= 0 && $app <= 0) {
            return $this->Translate('No SymDo Gateway connected. Account synchronization needs one as a parent; without it this list works locally.');
        }
        if ($eltern <= 0) {
            return sprintf(
                $this->Translate('No parent gateway connected — account synchronization needs one. Family members and photos come from "%s" (#%d) either way: the app is always served by the gateway with the lowest instance ID.'),
                IPS_GetName($app), $app
            );
        }
        if ($app > 0 && $app !== $eltern) {
            return sprintf(
                $this->Translate('Account synchronization runs over the parent gateway "%s" (#%d). Family members, photos and notifications come from "%s" (#%d) instead — the app is always served by the gateway with the lowest instance ID. Both is normal with several accounts.'),
                IPS_GetName($eltern), $eltern, IPS_GetName($app), $app
            );
        }
        return sprintf(
            $this->Translate('Account synchronization runs over the parent gateway "%s" (#%d), which also serves the app. Which gateway is responsible is decided by the connection in the object tree, not here.'),
            IPS_GetName($eltern), $eltern
        );
    }

    private function GetGatewayID(): int
    {
        $instance = @IPS_GetInstance($this->InstanceID);
        $connId = (int)($instance['ConnectionID'] ?? 0);
        if ($connId > 0) {
            return $connId;
        }
        $gwIds = @IPS_GetInstanceListByModuleID('{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}');
        return !empty($gwIds) ? (int)$gwIds[0] : 0;
    }

    private function GetDefaultHtmlBoxCssBody(): string
    {
        $cssPath = __DIR__ . '/assets/default.css';
        $css = @file_get_contents($cssPath);
        return is_string($css) ? trim($css) : '';
    }

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);
        $this->RegisterPropertyInteger('VisualizationInstanceID', 0);
        $this->RegisterPropertyInteger('NotificationLeadTime', 600);
        $this->RegisterPropertyBoolean('ShowOverview', true);
        $this->RegisterPropertyBoolean('ShowMemberBar', true);
        $this->RegisterPropertyBoolean('ShowCreateButton', true);
        $this->RegisterPropertyBoolean('ShowSorting', true);
        $this->RegisterPropertyBoolean('ShowLargeQuantity', false);
        // Fuenf gleichrangige Abzeichen-Schalter, kein Hauptschalter darueber.
        // Vorgabe an, damit sich am Erscheinungsbild zunaechst nichts aendert.
        $this->RegisterPropertyBoolean('ShowQuantityBadge', true);
        $this->RegisterPropertyBoolean('ShowRecurrenceBadge', true);
        $this->RegisterPropertyBoolean('ShowDueBadge', true);
        $this->RegisterPropertyBoolean('ShowNotificationBadge', true);
        $this->RegisterPropertyBoolean('ShowPriorityBadge', true);
        // Zeilenknoepfe. Bewusst neue Namen: die alten ShowEditButton/ShowDeleteButton
        // steuerten nichts und stehen bei Bestandsinstanzen ueberwiegend auf true —
        // sie zu honorieren haette allen ungefragt zwei Knoepfe in die Zeile gesetzt.
        $this->RegisterPropertyBoolean('ShowRowEditButton', false);
        $this->RegisterPropertyBoolean('ShowRowDeleteButton', false);
        $this->RegisterPropertyBoolean('ShowReorderHandle', true);
        $this->RegisterPropertyBoolean('EnableSwipeGestures', true);
        $this->RegisterPropertyBoolean('HideCompletedTasks', false);
        $this->RegisterPropertyBoolean('DeleteCompletedTasks', false);
        $this->RegisterPropertyBoolean('EnableHtmlBox', false);
        $this->RegisterPropertyString('HtmlBoxCss', '');

        $this->RegisterPropertyString('SyncBackend', 'local');

        $this->RegisterPropertyBoolean('AutoSyncOnChange', false);
        $this->RegisterPropertyInteger('AutoSyncOnChangeDelay', 3);

        // CalDAV Sync
        $this->RegisterPropertyBoolean('CalDAVEnabled', false);
        $this->RegisterPropertyString('CalDAVServerURL', '');
        $this->RegisterPropertyString('CalDAVUsername', '');
        $this->RegisterPropertyString('CalDAVPassword', '');
        $this->RegisterPropertyString('CalDAVCalendarPath', '');
        $this->RegisterPropertyInteger('CalDAVSyncInterval', 0);
        $this->RegisterPropertyString('CalDAVConflictMode', 'server_wins');
        $this->RegisterAttributeInteger('CalDAVLastSync', 0);
        $this->RegisterAttributeString('CalDAVSyncToken', '');
        $this->RegisterAttributeString('CalDAVCalendarOptions', '[]');
        $this->RegisterAttributeString('LastCalDAVCalendarPath', '');

        // Google Tasks Sync
        $this->RegisterPropertyBoolean('GoogleTasksEnabled', false);
        $this->RegisterPropertyString('GoogleClientID', '');
        $this->RegisterPropertyString('GoogleClientSecret', '');
        $this->RegisterPropertyString('GoogleTaskListID', '');
        $this->RegisterPropertyInteger('GoogleSyncInterval', 0);
        $this->RegisterPropertyString('GoogleConflictMode', 'newest_wins');
        $this->RegisterAttributeString('GoogleAccessToken', '');
        $this->RegisterAttributeString('GoogleRefreshToken', '');
        $this->RegisterAttributeInteger('GoogleTokenExpires', 0);
        $this->RegisterAttributeInteger('GoogleLastSync', 0);
        $this->RegisterAttributeInteger('GoogleSyncCursor', 0); // A1: server-clock incremental cursor (max task 'updated')
        $this->RegisterAttributeInteger('GoogleLastFullSync', 0); // R6: last full fetch+merge (periodic reconcile)
        // Beide standen bisher NUR in ApplyChanges (mit @ stummgeschaltet). Dort ist
        // RegisterAttribute* wirkungslos: Symcon nimmt Attribute ausschliesslich in
        // Create() an. Folge war "Attribut ... nicht gefunden" bei jedem Reload und —
        // schlimmer — eine Migration, die sich nie merken konnte, dass sie gelaufen ist.
        $this->RegisterAttributeString('CalDAVPendingDeletes', '{}');
        $this->RegisterAttributeInteger('SyncBackendMigrationDone', 0);
        $this->RegisterAttributeString('GooglePendingDeletes', '{}');
        $this->RegisterAttributeString('GoogleTaskListOptions', '[]');
        $this->RegisterAttributeString('LastGoogleTaskListID', '');

        $this->RegisterPropertyBoolean('MicrosoftToDoEnabled', false);
        $this->RegisterPropertyString('MicrosoftClientID', '');
        $this->RegisterPropertyString('MicrosoftClientSecret', '');
        $this->RegisterPropertyString('MicrosoftTenant', 'common');
        $this->RegisterPropertyString('MicrosoftListID', '');
        $this->RegisterPropertyInteger('MicrosoftSyncInterval', 0);
        $this->RegisterPropertyString('MicrosoftConflictMode', 'newest_wins');
        $this->RegisterAttributeString('MicrosoftAccessToken', '');
        $this->RegisterAttributeString('MicrosoftRefreshToken', '');
        $this->RegisterAttributeInteger('MicrosoftTokenExpires', 0);
        $this->RegisterAttributeInteger('MicrosoftLastSync', 0);
        $this->RegisterAttributeString('MicrosoftDeltaLink', ''); // A1: Graph delta cursor
        $this->RegisterAttributeString('MicrosoftPendingDeletes', '{}');
        $this->RegisterAttributeString('MicrosoftListOptions', '[]');
        $this->RegisterAttributeString('LastMicrosoftListID', '');

        $this->ExtListCreateProperties();

        $this->RegisterAttributeString('Items', '[]');
        // Von der Kachel gemeldete Visu-Farben je Schema (ReportVisuTheme) —
        // das Gateway liefert sie der SymDo-App/Web-App über die Discovery aus.
        $this->RegisterAttributeString('VisuTheme', '{}');
        $this->RegisterAttributeInteger('NextID', 1);
        $this->RegisterAttributeInteger('OrderVersion', 0);
        $this->RegisterAttributeInteger('AppRevision', 0);
        $this->RegisterAttributeInteger('LastNotificationLeadTime', 600);
        $this->RegisterAttributeString('SortMode', 'created');
        $this->RegisterAttributeString('SortDir', 'desc');

        $this->RegisterTimer('NotificationTimer', 0, 'TDL_ProcessNotifications($_IPS[\'TARGET\']);');
        $this->RegisterTimer('RecurrenceTimer', 0, 'TDL_ProcessRecurrences($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CalDAVSyncTimer', 0, 'TDL_CalDAVSync($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CalDAVOnChangeTimer', 0, 'TDL_CalDAVSyncOnChange($_IPS[\'TARGET\']);');
        $this->RegisterTimer('GoogleTasksSyncTimer', 0, 'TDL_GoogleTasksSync($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MicrosoftToDoSyncTimer', 0, 'TDL_MicrosoftToDoSync($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SyncOnChangeTimer', 0, 'TDL_SyncOnChange($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StatisticsTimer', 0, 'TDL_RefreshStatistics($_IPS[\'TARGET\']);');

        $this->RegisterVariableInteger('OpenTasks', $this->Translate('Open Tasks'), '', 1);
        $this->RegisterVariableInteger('OverdueTasks', $this->Translate('Overdue'), '', 2);
        $this->RegisterVariableInteger('DueTodayTasks', $this->Translate('Due Today'), '', 3);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Status GANZ VORN melden, nicht am Ende: weiter unten steigt ApplyChanges
        // bei EnforceSyncBackend() vorzeitig aus, und die Sync-/Benachrichtigungs-
        // Arbeit dahinter kann werfen — beides ließ die Instanz auf IS_CREATING
        // (101) stehen. Zusätzlich nach IPS_KERNELSTARTED erneut melden: im
        // Hochlauf gesetzt hält der Wert nicht (gemessen 2026-08-10 — nach dem
        // Neustart standen alle fünf Listen wieder auf 101, obwohl
        // TDL_GetAppState einwandfrei Aufgaben lieferte).
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->SetStatus(IS_ACTIVE);

        // Hier standen sieben Register-Aufrufe mit @ davor — der Versuch, neue
        // Eigenschaften, Timer und Attribute ohne Kernel-Neustart nachzuruesten. Das
        // geht nicht: Symcon nimmt sie nur in Create() an, in ApplyChanges warnen sie
        // und tun nichts. Alle sieben stehen dort, die beiden Attribute seit heute.

        // R22: pre-gateway OAuth tokens lived in these child attributes (XOR-obfuscated) and
        // are unused since the gateway split — clear leftovers so refresh tokens no longer
        // linger in instance settings/backups.
        foreach (['GoogleAccessToken', 'GoogleRefreshToken', 'MicrosoftAccessToken', 'MicrosoftRefreshToken'] as $legacyTokenAttr) {
            if ($this->ReadAttributeString($legacyTokenAttr) !== '') {
                $this->WriteAttributeString($legacyTokenAttr, '');
            }
        }

        if ($this->EnforceSyncBackend()) {
            return;
        }

        $leadTime = $this->ReadPropertyInteger('NotificationLeadTime');
        $lastLeadTime = $this->ReadAttributeInteger('LastNotificationLeadTime');
        if ($leadTime !== $lastLeadTime) {
            $this->ResetNotificationMarkers();
            $this->WriteAttributeInteger('LastNotificationLeadTime', $leadTime);
        }

        $visuID = $this->ReadPropertyInteger('VisualizationInstanceID');
        $this->SetTimerInterval('NotificationTimer', $visuID > 0 ? 60000 : 0);

        $caldavChanged = $this->SyncHandleListChange('CalDAVCalendarPath', 'LastCalDAVCalendarPath', 'CalDAVLastSync', 'CalDAVPendingDeletes', 'CalDAV', ['CalDAVSyncToken' => '']);
        $googleChanged = $this->SyncHandleListChange('GoogleTaskListID', 'LastGoogleTaskListID', 'GoogleLastSync', 'GooglePendingDeletes', 'GoogleTasks', ['GoogleSyncCursor' => 0, 'GoogleLastFullSync' => 0]);
        $microsoftChanged = $this->SyncHandleListChange('MicrosoftListID', 'LastMicrosoftListID', 'MicrosoftLastSync', 'MicrosoftPendingDeletes', 'MicrosoftToDo', ['MicrosoftDeltaLink' => '']);

        $this->UpdateRecurrenceTimer();
        $this->UpdateStatisticsTimer();
        $this->UpdateCalDAVTimer();
        $this->UpdateGoogleTasksTimer();
        $this->UpdateMicrosoftToDoTimer();

        $syncBackend = $this->GetSyncBackend();
        if ($caldavChanged && $syncBackend === 'caldav') {
            $this->SetTimerInterval('CalDAVOnChangeTimer', 0);
            $this->SetTimerInterval('CalDAVOnChangeTimer', 2000);
        } elseif ($googleChanged && $syncBackend === 'google') {
            $this->SetTimerInterval('SyncOnChangeTimer', 0);
            $this->SetTimerInterval('SyncOnChangeTimer', 2000);
        } elseif ($microsoftChanged && $syncBackend === 'microsoft') {
            $this->SetTimerInterval('SyncOnChangeTimer', 0);
            $this->SetTimerInterval('SyncOnChangeTimer', 2000);
        }

        if ($this->ReadPropertyBoolean('EnableHtmlBox')) {
            $this->RegisterVariableString('TaskListHtml', $this->Translate('Task list (HTML)'), '~HTMLBox', 4);
        } else {
            $this->UnregisterVariable('TaskListHtml');
        }

        $this->UpdateStatistics();
        $this->UpdateTaskListHtml();
        $this->UpdateStatisticsTimer();
        $this->SendState();

        $this->ProcessNotifications();
        $this->ProcessRecurrences();

        $this->ExtListBindTrigger();
    }

    /**
     * Nur für den Statusnachtrag: der im Hochlauf gesetzte IS_ACTIVE hält nicht,
     * nach KR_READY hält er. Absichtlich NICHT das ganze ApplyChanges erneut —
     * das würde Sync und Benachrichtigungen ein zweites Mal anstoßen.
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->SetStatus(IS_ACTIVE);
            return;
        }

        // Eine externe Liste hat sich geaendert. ExtListIsTrigger prueft das
        // Changed-Flag, ein unveraenderter Fremd-Takt loest also nichts aus.
        if ($this->ExtListIsTrigger($SenderID, $Message, $Data)) {
            $this->ExtListSync();
        }
    }

    /** Abgleich mit der externen Liste von Hand — der Knopf im Formular. */
    public function ExtListSyncNow(): string
    {
        return $this->ExtListSyncNowText();
    }

    public function GetConfigurationForm(): string
    {

        $prefill = [];
        $css = trim((string)$this->ReadPropertyString('HtmlBoxCss'));
        if ($css === '') {
            $prefill['HtmlBoxCss'] = $this->GetDefaultHtmlBoxCssBody();
        }

        $syncBackend = $this->GetSyncBackend();

        $recurrenceUnitOptions = [
            ['caption' => $this->Translate('Hours'), 'value' => 'h'],
            ['caption' => $this->Translate('Days'), 'value' => 'd'],
            ['caption' => $this->Translate('Weeks'), 'value' => 'w'],
            ['caption' => $this->Translate('Months'), 'value' => 'm'],
            ['caption' => $this->Translate('Years'), 'value' => 'y']
        ];
        if ($syncBackend === 'microsoft') {
            $recurrenceUnitOptions = array_values(array_filter($recurrenceUnitOptions, fn($opt) => (string)($opt['value'] ?? '') !== 'h'));
        }

        $form = [
            'values' => $prefill,
            'elements' => [
                [
                    'type' => 'SelectInstance',
                    'name' => 'VisualizationInstanceID',
                    'width' => '400px',
                    'caption' => $this->Translate('Visualization instance to which the notification is sent')
                ],
                [
                    'type' => 'Select',
                    'name' => 'NotificationLeadTime',
                    'visible' => false,
                    'width' => '400px',
                    'caption' => $this->Translate('Notification Lead Time'),
                    'options' => $this->GetNotificationLeadTimeOptions()
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowOverview',
                    'caption' => $this->Translate('Task overview')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowMemberBar',
                    'caption' => $this->Translate('Member bar')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowCreateButton',
                    'caption' => $this->Translate('Add')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowSorting',
                    'caption' => $this->Translate('Sort')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowLargeQuantity',
                    'caption' => $this->Translate('Show large quantity'),
                    'visible' => false
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowRowEditButton',
                    'caption' => $this->Translate('Edit button')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowRowDeleteButton',
                    'caption' => $this->Translate('Delete button')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowReorderHandle',
                    'caption' => $this->Translate('Reorder handle')
                ],
                /* Auf eine Eigenschaft, die es vor dem naechsten Kernel-Start nicht
                   gibt, laesst „Uebernehmen" das GANZE Formular scheitern
                   (gemessen: IPS_SetConfiguration antwortet „Eigenschaft nicht
                   gefunden" und verwirft alles). Bis dahin steht hier ein Hinweis
                   statt eines Schalters, der nichts tun kann. */
                $this->PropertyExistiert('EnableSwipeGestures')
                    ? [
                        'type' => 'CheckBox',
                        'name' => 'EnableSwipeGestures',
                        'caption' => $this->Translate('Swipe gestures')
                    ]
                    : [
                        'type' => 'Label',
                        'caption' => $this->Translate('Swipe gestures restart hint')
                    ],
                [
                    'type' => 'Label',
                    'caption' => $this->Translate('Swipe gestures hint')
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->Translate('Row buttons hint')
                ],
                [
                    'type' => 'Label',
                    'caption' => ''
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->Translate('Info badges')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowQuantityBadge',
                    'caption' => $this->Translate('Badge: quantity')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowRecurrenceBadge',
                    'caption' => $this->Translate('Badge: repeat')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowDueBadge',
                    'caption' => $this->Translate('Badge: due date')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowNotificationBadge',
                    'caption' => $this->Translate('Badge: notification')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowPriorityBadge',
                    'caption' => $this->Translate('Badge: priority')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'HideCompletedTasks',
                    'caption' => $this->Translate('Hide completed tasks')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'DeleteCompletedTasks',
                    'caption' => $this->Translate('Delete completed tasks')
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => $this->Translate('HTMLBox layout'),
                    'items' => [
                        [
                            'type' => 'CheckBox',
                            'name' => 'EnableHtmlBox',
                            'caption' => $this->Translate('Enable HTMLBox variable')
                        ],
                        [
                            'type' => 'ScriptEditor',
                            'name' => 'HtmlBoxCss',
                            'caption' => $this->Translate('HTMLBox CSS'),
                            'rowCount' => 15
                        ]
                    ]
                ],

                /* Welches Gateway hier zustaendig ist, entscheidet die
                   ELTERN-Verbindung im Objektbaum — nicht ein Feld in diesem
                   Formular. Das ist die Stelle, an der man mehrere Konten
                   trennt. Die App-Haelfte (Mitglieder, Fotos, Push) laeuft
                   unabhaengig davon auf dem Gateway mit der niedrigsten ID;
                   diese Zeile sagt beides, weil es sonst niemand weiss. */
                [
                    'type'    => 'Label',
                    'name'    => 'GatewayRolesHint',
                    'caption' => $this->GatewayRollenText(),
                ],
                [
                    'type' => 'Select',
                    'name' => 'SyncBackend',
                    'caption' => $this->Translate('Synchronization backend'),
                    'width' => '400px',
                    'options' => [
                        ['caption' => $this->Translate('Local only'), 'value' => 'local'],
                        ['caption' => $this->Translate('CalDAV'), 'value' => 'caldav'],
                        ['caption' => $this->Translate('Google Tasks'), 'value' => 'google'],
                        ['caption' => $this->Translate('Microsoft To Do'), 'value' => 'microsoft']
                    ]
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->Translate('First sync: existing tasks from this list and from the server are merged – nothing is lost. Tasks with identical titles may appear twice or be combined.'),
                    'visible' => $syncBackend !== 'local'
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->Translate('Switching to another list or calendar replaces the local entries with the new list. Changes that have not been synchronized yet are lost.'),
                    'visible' => $syncBackend !== 'local'
                ],

                [
                    'type' => 'CheckBox',
                    'name' => 'AutoSyncOnChange',
                    'caption' => $this->Translate('Auto sync after changes'),
                    'visible' => $syncBackend !== 'local'
                ],
                [
                    'type' => 'NumberSpinner',
                    'name' => 'AutoSyncOnChangeDelay',
                    'caption' => $this->Translate('Auto sync delay (seconds)'),
                    'width' => '200px',
                    'minimum' => 1,
                    'maximum' => 60,
                    'visible' => $syncBackend !== 'local'
                ],

                $this->GetCalDAVFormElements($syncBackend),
                $this->GetGoogleTasksFormElements($syncBackend),
                $this->GetMicrosoftToDoFormElements($syncBackend),
                // Bewusst NACH den drei exklusiven Backends und ohne deren
                // Sichtbarkeitsregel: die externe Liste laeuft daneben, nicht statt.
                $this->GetExtListFormElements()
            ]
        ];

        $form['elements'] = array_merge($form['elements'], $this->GetDonationFormElements());

        return json_encode($form);
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

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'GetState':
                // Read-only push to the tile — must not bump AppRevision, otherwise
                // every tile open would invalidate the app clients' state caches.
                $this->PushCurrentState();
                return;
            case 'SetSortPrefs':
                $this->SetSortPrefs($this->DecodeValue($Value));
                $this->UpdateTaskListHtml();
                $this->SendState();
                return;
            case 'AddItem':
                $this->AddItem($this->DecodeValue($Value));
                $this->SendState();
                return;
            case 'UpdateItem':
                $this->UpdateItem($this->DecodeValue($Value));
                $this->SendState();
                return;
            case 'ToggleDone':
                $this->ToggleDone($this->DecodeValue($Value));
                $this->SendState();
                return;
            case 'DeleteItem':
                $this->DeleteItem($this->DecodeValue($Value));
                $this->SendState();
                return;
            case 'Reorder':
                $this->Reorder($this->DecodeValue($Value));
                $this->SendState();
                return;
            case 'ReportVisuTheme':
                // Stiller Speicher (kein SendState): die Kachel meldet die
                // CSS-Variablen der Visu, das Gateway liefert sie der App/Web-App aus.
                $data = json_decode((string)$Value, true);
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
            default:
                throw new Exception($this->Translate('Invalid Ident'));
        }
    }

    /**
     * Von der Kachel gemeldete Visu-Farben ({"dark":{accent,content,card},
     * "light":{...}}) — vom Gateway in der Discovery ausgeliefert.
     */
    public function GetVisuTheme(): string
    {
        return $this->ReadAttributeString('VisuTheme');
    }

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            $exists = file_exists($path) ? 'yes' : 'no';
            $readable = is_readable($path) ? 'yes' : 'no';
            $size = file_exists($path) ? (string)@filesize($path) : 'n/a';
            $err = error_get_last();
            $errMsg = is_array($err) && isset($err['message']) ? (string)$err['message'] : '';
            $this->LogMessage('GetVisualizationTile: module.html nicht lesbar, Pfad=' . $path . ' vorhanden=' . $exists . ' readable=' . $readable . ' size=' . $size . ' err=' . $errMsg, KL_WARNING);
            return '';
        }
        if (strlen($html) < 200) {
            $this->LogMessage('GetVisualizationTile: module.html gelesen, aber auffaellig kurz. Bytes=' . strlen($html) . ' head=' . substr($html, 0, 80), KL_WARNING);
        }
        return $html;
    }

    public function Export(): string
    {
        return json_encode($this->LoadItems(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function GetAppRevision(): int
    {
        return $this->ReadAttributeInteger('AppRevision');
    }

    public function GetAppState(): string
    {
        // Read the revision before building the state: a concurrent mutation in
        // between yields a state newer than the revision, which the next poll
        // corrects; the reverse order would let clients miss an update.
        $revision = $this->ReadAttributeInteger('AppRevision');
        return json_encode([
            'revision' => $revision,
            'kind'     => 'todo',
            // Ohne Avatare: die REST-Clients holen die Nutzer aus /v1/discovery.
            'state'    => $this->BuildStatePayload(false),
        /* Ein Eintrag kann Text aus fremder Hand tragen: ein Produktname aus einer
           Barcode-Datenbank, eine Aufgabe von einem CalDAV-Server. Ist darin ein
           Byte kein gueltiges UTF-8, gaebe json_encode `false` zurueck — die App
           bekaeme eine leere Zeichenkette und zeigte eine leere Liste. Das
           Ersatzzeichen ist die kleinere Stoerung. */
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function AppCall(string $Action, string $Payload): string
    {
        $allowed = ['AddItem', 'UpdateItem', 'ToggleDone', 'DeleteItem', 'Reorder', 'SetSortPrefs'];
        $ok    = true;
        $error = null;
        if (!in_array($Action, $allowed, true)) {
            $ok    = false;
            $error = ['code' => 'unknown_action', 'message' => 'Unknown action: ' . $Action];
        } else {
            try {
                $this->RequestAction($Action, $Payload);
            } catch (\Throwable $e) {
                $ok    = false;
                $error = ['code' => 'invalid_payload', 'message' => $e->getMessage()];
                $this->SendDebug('AppCall', $Action . ' failed: ' . $e->getMessage(), 0);
            }
        }
        $result = [
            'ok'       => $ok,
            'revision' => $this->ReadAttributeInteger('AppRevision'),
            'kind'     => 'todo',
            'state'    => $this->BuildStatePayload(false),
        ];
        if ($error !== null) {
            $result['error'] = $error;
        }
        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function AddItem(mixed $Data): int
    {
        $Data = $this->DecodeValue($Data);
        $items = $this->LoadItems();

        $title = trim((string)($Data['title'] ?? ''));
        if ($title === '') {
            throw new Exception($this->Translate('Invalid title'));
        }

        $id = $this->ReadAttributeInteger('NextID');
        $this->WriteAttributeInteger('NextID', $id + 1);

        $now = time();
        $due = (int)($Data['due'] ?? 0);
        // Package 3: an all-day task carries no time-of-day — normalize the due to local
        // midnight so it serializes cleanly as DUE;VALUE=DATE for CalDAV.
        $dueAllDay = ($due > 0) && (bool)($Data['dueAllDay'] ?? false);
        if ($dueAllDay) {
            $due = $this->AllDayDueFromPayload($due, $Data);
        }
        $recurrence = $this->NormalizeRecurrence($Data['recurrence'] ?? 'none', $due);
        $recurrenceResetLeadTime = $this->NormalizeRecurrenceResetLeadTime($Data['recurrenceResetLeadTime'] ?? 0, $recurrence);
        $recurrenceCustomUnit = 'w';
        $recurrenceCustomValue = 1;
        if ($recurrence === 'custom') {
            $recurrenceCustomUnit = $this->NormalizeRecurrenceCustomUnit($Data['recurrenceCustomUnit'] ?? null);
            $recurrenceCustomValue = $this->NormalizeRecurrenceCustomValue($Data['recurrenceCustomValue'] ?? null);
        }
        $notification = (bool)($Data['notification'] ?? false);
        if ($due <= 0) {
            $notification = false;
            $recurrence = 'none';
            $recurrenceResetLeadTime = 0;
        }

        $defaultLeadTime = $this->NormalizeNotificationLeadTimeDefault((int)$this->ReadPropertyInteger('NotificationLeadTime'));
        $itemLeadTime = $defaultLeadTime;
        if (array_key_exists('notificationLeadTime', $Data)) {
            $itemLeadTime = $this->NormalizeNotificationLeadTime($Data['notificationLeadTime'], $defaultLeadTime);
        }

        if ($due > 0) {
            $limit = $this->GetLeadTimeLimitSeconds($due, $now, $recurrence, $recurrenceCustomUnit, $recurrenceCustomValue);
            $itemLeadTime = $this->ClampLeadTimeToLimit($itemLeadTime, $limit, [0, 300, 600, 1800, 3600, 18000, 43200]);
        }

        if ($due > 0 && $recurrence !== 'none') {
            $interval = $this->GetRecurrenceIntervalSeconds($due, $recurrence, $recurrenceCustomUnit, $recurrenceCustomValue);
            $recurrenceResetLeadTime = $this->ClampLeadTimeToInterval($recurrenceResetLeadTime, $interval, [1800, 3600, 21600, 43200, 86400, 172800, 259200, 604800, 1209600, 2592000]);
        }

        $newItem = [
            'id'        => $id,
            'title'     => $title,
            'info'      => (string)($Data['info'] ?? ''),
            'done'      => (bool)($Data['done'] ?? false),
            'due'       => $due,
            'dueAllDay' => $dueAllDay,
            'recurrence' => $recurrence,
            'recurrenceCustomUnit' => $recurrenceCustomUnit,
            'recurrenceCustomValue' => $recurrenceCustomValue,
            'recurrenceResetLeadTime' => $recurrenceResetLeadTime,
            'priority'  => (string)($Data['priority'] ?? 'normal'),
            'assignedTo' => $this->NormalizeAssignedTo($Data['assignedTo'] ?? []),
            'quantity'  => (int)($Data['quantity'] ?? 0),
            'notification' => $notification,
            'notificationLeadTime' => $itemLeadTime,
            'notifiedFor'  => 0,
            'createdAt' => $now,
            'updatedAt' => $now,
            'localModified' => $now,
            'caldavUid' => '',
            'caldavEtag' => '',
            'caldavSynced' => 0
        ];

        array_unshift($items, $newItem);
        $this->SaveItems($items);
        $this->ScheduleSyncOnChange();
        $this->NotifyAssignedUsers($newItem['assignedTo'], $title, (string)($Data['actorUserId'] ?? ''));

        return $id;
    }

    public function UpdateItem(mixed $Data): void
    {
        $Data = $this->DecodeValue($Data);
        $id = (int)($Data['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $items = $this->LoadItems();
        $now = time();
        $deleteCompleted = $this->ReadPropertyBoolean('DeleteCompletedTasks');
        for ($i = 0; $i < count($items); $i++) {
            if (((int)($items[$i]['id'] ?? 0)) !== $id) {
                continue;
            }

            if ($deleteCompleted && array_key_exists('done', $Data) && (bool)$Data['done']) {
                if ($this->GetSyncBackend() === 'google') {
                    $googleId = (string)($items[$i]['googleTaskId'] ?? '');
                    if ($googleId !== '' && (int)($items[$i]['googleSynced'] ?? 0) > 0) {
                        $this->AddGooglePendingDelete($googleId, (string)($items[$i]['googleEtag'] ?? ''));
                    }
                }
                if ($this->GetSyncBackend() === 'microsoft') {
                    $msId = (string)($items[$i]['microsoftTaskId'] ?? '');
                    if ($msId !== '' && (int)($items[$i]['microsoftSynced'] ?? 0) > 0) {
                        $this->AddMicrosoftPendingDelete($msId, (string)($items[$i]['microsoftEtag'] ?? ''));
                    }
                }
                // R10: same CalDAV tombstone as in ToggleDone/DeleteItem — without it the
                // server VTODO survives and the task resurrects on the next sync.
                $uid = (string)($items[$i]['caldavUid'] ?? '');
                if ($uid !== '' && (int)($items[$i]['caldavSynced'] ?? 0) > 0) {
                    $pending = json_decode((string)$this->ReadAttributeString('CalDAVPendingDeletes'), true);
                    if (!is_array($pending)) {
                        $pending = [];
                    }
                    $pending[$uid] = json_encode(['href' => (string)($items[$i]['caldavHref'] ?? ''), 'etag' => (string)($items[$i]['caldavEtag'] ?? '')]);
                    $this->WriteAttributeString('CalDAVPendingDeletes', json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                unset($items[$i]);
                $this->SaveItems(array_values($items));
                $this->ScheduleSyncOnChange();
                return;
            }

            $resetNotify = false;
            if (array_key_exists('title', $Data)) {
                $items[$i]['title'] = trim((string)$Data['title']);
            }
            if (array_key_exists('info', $Data)) {
                $items[$i]['info'] = (string)$Data['info'];
            }
            if (array_key_exists('due', $Data)) {
                $newDue = (int)$Data['due'];
                // Package 3: honor an explicit all-day flag. When the payload omits it, stay
                // all-day ONLY if the new due is still at local midnight — a new due carrying a
                // time-of-day means the user set a specific time, so all-day is turned off.
                if (array_key_exists('dueAllDay', $Data)) {
                    $allDay = ($newDue > 0) && (bool)$Data['dueAllDay'];
                } else {
                    $allDay = ($newDue > 0) && (bool)($items[$i]['dueAllDay'] ?? false)
                        && date('H:i:s', $newDue) === '00:00:00';
                }
                if ($allDay) {
                    $newDue = $this->AllDayDueFromPayload($newDue, $Data);
                }
                $resetNotify = $resetNotify || ((int)($items[$i]['due'] ?? 0) !== $newDue) || ((bool)($items[$i]['dueAllDay'] ?? false) !== $allDay);
                $items[$i]['due'] = $newDue;
                $items[$i]['dueAllDay'] = $allDay;
            } elseif (array_key_exists('dueAllDay', $Data) && ($items[$i]['due'] ?? 0) > 0) {
                // Toggling all-day without changing the date — a stray payload dueDay
                // must not shift the stored day, so only the stored due is floored.
                $allDay = (bool)$Data['dueAllDay'];
                $newDue = $allDay ? $this->AllDayDueFromPayload((int)$items[$i]['due'], []) : (int)$items[$i]['due'];
                $resetNotify = $resetNotify || ((bool)($items[$i]['dueAllDay'] ?? false) !== $allDay);
                $items[$i]['due'] = $newDue;
                $items[$i]['dueAllDay'] = $allDay;
            }

            if (array_key_exists('recurrence', $Data) || array_key_exists('due', $Data)) {
                $due = (int)($items[$i]['due'] ?? 0);
                $items[$i]['recurrence'] = $this->NormalizeRecurrence($Data['recurrence'] ?? ($items[$i]['recurrence'] ?? 'none'), $due);
            } elseif (!array_key_exists('recurrence', $items[$i])) {
                $items[$i]['recurrence'] = 'none';
            }

            if (array_key_exists('recurrenceCustomUnit', $Data) || array_key_exists('recurrenceCustomValue', $Data) || array_key_exists('recurrence', $Data) || array_key_exists('due', $Data)) {
                if ((string)($items[$i]['recurrence'] ?? 'none') === 'custom') {
                    $items[$i]['recurrenceCustomUnit'] = $this->NormalizeRecurrenceCustomUnit($Data['recurrenceCustomUnit'] ?? ($items[$i]['recurrenceCustomUnit'] ?? null));
                    $items[$i]['recurrenceCustomValue'] = $this->NormalizeRecurrenceCustomValue($Data['recurrenceCustomValue'] ?? ($items[$i]['recurrenceCustomValue'] ?? null));
                } else {
                    $items[$i]['recurrenceCustomUnit'] = 'w';
                    $items[$i]['recurrenceCustomValue'] = 1;
                }
            } elseif (!array_key_exists('recurrenceCustomUnit', $items[$i])) {
                $items[$i]['recurrenceCustomUnit'] = 'w';
                $items[$i]['recurrenceCustomValue'] = 1;
            }

            if (array_key_exists('recurrenceResetLeadTime', $Data) || array_key_exists('recurrence', $Data) || array_key_exists('due', $Data)) {
                $rec = (string)($items[$i]['recurrence'] ?? 'none');
                $items[$i]['recurrenceResetLeadTime'] = $this->NormalizeRecurrenceResetLeadTime($Data['recurrenceResetLeadTime'] ?? ($items[$i]['recurrenceResetLeadTime'] ?? null), $rec);
            } elseif (!array_key_exists('recurrenceResetLeadTime', $items[$i])) {
                $items[$i]['recurrenceResetLeadTime'] = 0;
            }
            if (array_key_exists('priority', $Data)) {
                $items[$i]['priority'] = (string)$Data['priority'];
            }
            if (array_key_exists('assignedTo', $Data)) {
                $previous = is_array($items[$i]['assignedTo'] ?? null) ? $items[$i]['assignedTo'] : [];
                $items[$i]['assignedTo'] = $this->NormalizeAssignedTo($Data['assignedTo']);
                $this->NotifyAssignedUsers(
                    array_values(array_diff($items[$i]['assignedTo'], $previous)),
                    (string)($items[$i]['title'] ?? ''),
                    (string)($Data['actorUserId'] ?? '')
                );
            }
            if (array_key_exists('done', $Data)) {
                $items[$i]['done'] = (bool)$Data['done'];
            }
            if (array_key_exists('quantity', $Data)) {
                $items[$i]['quantity'] = (int)$Data['quantity'];
            }
            if (array_key_exists('notification', $Data)) {
                $resetNotify = $resetNotify || ((bool)($items[$i]['notification'] ?? false) !== (bool)$Data['notification']);
                $items[$i]['notification'] = (bool)$Data['notification'];
            }

            $defaultLeadTime = $this->NormalizeNotificationLeadTimeDefault((int)$this->ReadPropertyInteger('NotificationLeadTime'));
            if (array_key_exists('notificationLeadTime', $Data) || array_key_exists('notificationLeadTime', $items[$i])) {
                $currentStored = (int)($items[$i]['notificationLeadTime'] ?? $defaultLeadTime);
                $newLeadTime = $Data['notificationLeadTime'] ?? ($items[$i]['notificationLeadTime'] ?? $defaultLeadTime);
                $newLeadTime = $this->NormalizeNotificationLeadTime($newLeadTime, $defaultLeadTime);
                $resetNotify = $resetNotify || ($currentStored !== $newLeadTime);
                $items[$i]['notificationLeadTime'] = $newLeadTime;
            }

            $due = (int)($items[$i]['due'] ?? 0);
            $recurrence = (string)($items[$i]['recurrence'] ?? 'none');
            if ($due > 0 && $recurrence !== 'none') {
                $unit = (string)($items[$i]['recurrenceCustomUnit'] ?? 'w');
                $val = (int)($items[$i]['recurrenceCustomValue'] ?? 1);
                $interval = $this->GetRecurrenceIntervalSeconds($due, $recurrence, $unit, $val);
                $newReopen = $this->ClampLeadTimeToInterval((int)($items[$i]['recurrenceResetLeadTime'] ?? 0), $interval, [1800, 3600, 21600, 43200, 86400, 172800, 259200, 604800, 1209600, 2592000]);
                $items[$i]['recurrenceResetLeadTime'] = $newReopen;
            }

            // Clamp the lead only when the user actually touched reminder or due fields. On an
            // unrelated edit (title/info) a silent re-clamp would register as a reminder change
            // in the CalDAV merge and needlessly rewrite alarms (confirmed review finding).
            if ($due > 0 && array_key_exists('notificationLeadTime', $items[$i])
                && (array_key_exists('notificationLeadTime', $Data) || array_key_exists('notification', $Data) || array_key_exists('due', $Data))) {
                $unit = (string)($items[$i]['recurrenceCustomUnit'] ?? 'w');
                $val = (int)($items[$i]['recurrenceCustomValue'] ?? 1);
                $limit = $this->GetLeadTimeLimitSeconds($due, $now, $recurrence, $unit, $val);
                $newLeadTime = $this->ClampLeadTimeToLimit((int)$items[$i]['notificationLeadTime'], $limit, [0, 300, 600, 1800, 3600, 18000, 43200]);
                if ((int)$items[$i]['notificationLeadTime'] !== $newLeadTime) {
                    $resetNotify = true;
                    $items[$i]['notificationLeadTime'] = $newLeadTime;
                }
            }

            if (((int)($items[$i]['due'] ?? 0)) <= 0) {
                $items[$i]['notification'] = false;
                $resetNotify = true;
                $items[$i]['recurrence'] = 'none';
                $items[$i]['recurrenceResetLeadTime'] = 0;
                $items[$i]['recurrenceCustomUnit'] = 'w';
                $items[$i]['recurrenceCustomValue'] = 1;
            }

            if ($resetNotify) {
                $items[$i]['notifiedFor'] = 0;
            }

            $items[$i]['updatedAt'] = $now;
            $items[$i]['localModified'] = $now;
            break;
        }

        $this->SaveItems($items);
        $this->ScheduleSyncOnChange();
    }

    public function ToggleDone(mixed $Data): void
    {
        $Data = $this->DecodeValue($Data);
        $id = (int)($Data['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception($this->Translate('Invalid id'));
        }

        $items = $this->LoadItems();
        $deleteCompleted = $this->ReadPropertyBoolean('DeleteCompletedTasks');
        for ($i = 0; $i < count($items); $i++) {
            if (((int)($items[$i]['id'] ?? 0)) !== $id) {
                continue;
            }

            $oldDone = (bool)($items[$i]['done'] ?? false);
            $newDone = $oldDone;
            if (array_key_exists('done', $Data)) {
                $newDone = (bool)$Data['done'];
            } else {
                $newDone = !$oldDone;
            }

            $recurrence = (string)($items[$i]['recurrence'] ?? 'none');
            if ($newDone && $deleteCompleted && $this->NormalizeRecurrence($recurrence, (int)($items[$i]['due'] ?? 0)) === 'none') {
                if ($this->GetSyncBackend() === 'google') {
                    $googleId = (string)($items[$i]['googleTaskId'] ?? '');
                    if ($googleId !== '' && (int)($items[$i]['googleSynced'] ?? 0) > 0) {
                        $this->AddGooglePendingDelete($googleId, (string)($items[$i]['googleEtag'] ?? ''));
                    }
                }
                if ($this->GetSyncBackend() === 'microsoft') {
                    $msId = (string)($items[$i]['microsoftTaskId'] ?? '');
                    if ($msId !== '' && (int)($items[$i]['microsoftSynced'] ?? 0) > 0) {
                        $this->AddMicrosoftPendingDelete($msId, (string)($items[$i]['microsoftEtag'] ?? ''));
                    }
                }
                $uid = (string)($items[$i]['caldavUid'] ?? '');
                if ($uid !== '' && (int)($items[$i]['caldavSynced'] ?? 0) > 0) {
                    $pending = json_decode((string)$this->ReadAttributeString('CalDAVPendingDeletes'), true);
                    if (!is_array($pending)) {
                        $pending = [];
                    }
                    $pending[$uid] = json_encode(['href' => (string)($items[$i]['caldavHref'] ?? ''), 'etag' => (string)($items[$i]['caldavEtag'] ?? '')]);
                    $this->WriteAttributeString('CalDAVPendingDeletes', json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                unset($items[$i]);
                $this->SaveItems(array_values($items));
                $this->ScheduleSyncOnChange();
                return;
            }

            $items[$i]['done'] = $newDone;
            // Advance recurrence only on a real false→true transition, so replayed
            // requests (app outbox retry) cannot push the due date twice.
            if ($newDone && !$oldDone && $recurrence !== 'none') {
                $due = (int)($items[$i]['due'] ?? 0);
                if ($due > 0) {
                    $unit = (string)($items[$i]['recurrenceCustomUnit'] ?? 'w');
                    $val = (int)($items[$i]['recurrenceCustomValue'] ?? 1);
                    $items[$i]['due'] = $this->GetNextDue($due, $recurrence, $unit, $val, (bool)($items[$i]['dueAllDay'] ?? false));
                    $items[$i]['notifiedFor'] = 0;
                }

                if ((int)($items[$i]['recurrenceResetLeadTime'] ?? 0) === -1) {
                    $items[$i]['done'] = false;
                    $items[$i]['notifiedFor'] = 0;
                }
            }
            if ($oldDone !== $newDone) {
                $items[$i]['notifiedFor'] = 0;
            }
            $now = time();
            $items[$i]['updatedAt'] = $now;
            $items[$i]['localModified'] = $now;
            if ($newDone && !$oldDone) {
                $items[$i]['doneAt'] = $now;
            }
            break;
        }

        $this->SaveItems($items);
        $this->ScheduleSyncOnChange();
    }

    public function DeleteItem(mixed $Data): void
    {
        $Data = $this->DecodeValue($Data);
        $id = (int)($Data['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception($this->Translate('Invalid id'));
        }

        $items = $this->LoadItems();
        $deleted = null;
        for ($i = 0; $i < count($items); $i++) {
            if (((int)($items[$i]['id'] ?? 0)) !== $id) {
                continue;
            }
            $deleted = $items[$i];
            unset($items[$i]);
            break;
        }

        if (is_array($deleted)) {
            $uid = (string)($deleted['caldavUid'] ?? '');
            if ($uid !== '' && (int)($deleted['caldavSynced'] ?? 0) > 0) {
                $pending = json_decode((string)$this->ReadAttributeString('CalDAVPendingDeletes'), true);
                if (!is_array($pending)) {
                    $pending = [];
                }
                $pending[$uid] = json_encode(['href' => (string)($deleted['caldavHref'] ?? ''), 'etag' => (string)($deleted['caldavEtag'] ?? '')]);
                $this->WriteAttributeString('CalDAVPendingDeletes', json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            if ($this->GetSyncBackend() === 'google') {
                $googleId = (string)($deleted['googleTaskId'] ?? '');
                if ($googleId !== '' && (int)($deleted['googleSynced'] ?? 0) > 0) {
                    $this->AddGooglePendingDelete($googleId, (string)($deleted['googleEtag'] ?? ''));
                }
            }

            if ($this->GetSyncBackend() === 'microsoft') {
                $msId = (string)($deleted['microsoftTaskId'] ?? '');
                if ($msId !== '' && (int)($deleted['microsoftSynced'] ?? 0) > 0) {
                    $this->AddMicrosoftPendingDelete($msId, (string)($deleted['microsoftEtag'] ?? ''));
                }
            }
        }

        $items = array_values($items);
        $this->SaveItems($items);
        $this->ScheduleSyncOnChange();
    }

    private function Reorder(mixed $Data): void
    {
        $Data = $this->DecodeValue($Data);
        $order = $Data['order'] ?? [];
        if (!is_array($order)) {
            throw new Exception($this->Translate('Invalid order'));
        }

        $items = $this->LoadItems();
        $beforeIds = array_map(fn($it) => (int)($it['id'] ?? 0), $items);
        $map = [];
        foreach ($items as $it) {
            $map[(int)($it['id'] ?? 0)] = $it;
        }

        $newItems = [];
        foreach ($order as $id) {
            $id = (int)$id;
            if (isset($map[$id])) {
                $newItems[] = $map[$id];
                unset($map[$id]);
            }
        }

        foreach ($items as $it) {
            $id = (int)($it['id'] ?? 0);
            if (isset($map[$id])) {
                $newItems[] = $map[$id];
                unset($map[$id]);
            }
        }

        $afterIds = array_map(fn($it) => (int)($it['id'] ?? 0), $newItems);
        if ($beforeIds !== $afterIds) {
            $this->WriteAttributeInteger('OrderVersion', $this->ReadAttributeInteger('OrderVersion') + 1);
            $this->WriteAttributeString('SortMode', 'manual');
        }
        $this->SaveItems($newItems);
    }

    private function ScheduleSyncOnChange(): void
    {
        if (!$this->ReadPropertyBoolean('AutoSyncOnChange')) {
            return;
        }

        $backend = $this->GetSyncBackend();
        if ($backend === 'caldav') {
            $this->ScheduleCalDAVSyncOnChange();
            return;
        }
        if ($backend !== 'google' && $backend !== 'microsoft') {
            return;
        }

        $delay = (int)$this->ReadPropertyInteger('AutoSyncOnChangeDelay');
        if ($delay < 1) {
            $delay = 1;
        }

        $this->SetTimerInterval('SyncOnChangeTimer', 0);
        $this->SetTimerInterval('SyncOnChangeTimer', min(60000, $delay * 1000));
    }

    public function SyncOnChange(): void
    {
        $this->SetTimerInterval('SyncOnChangeTimer', 0);

        // R14: when a sync is already running (semaphore busy), re-arm the timer instead of
        // dropping the change — otherwise an edit made during a running sync would strand
        // until the next interval tick (or forever with "Manual only"). Mirrors the CalDAV
        // on-change handler.
        $backend = $this->GetSyncBackend();
        if ($backend === 'google') {
            $sem = 'TDL_GoogleSync_' . $this->InstanceID;
            if (!IPS_SemaphoreEnter($sem, 0)) {
                $this->SetTimerInterval('SyncOnChangeTimer', 3000);
                return;
            }
            try {
                $this->GoogleTasksSyncInternal();
            } finally {
                IPS_SemaphoreLeave($sem);
            }
            return;
        }
        if ($backend === 'microsoft') {
            $sem = 'TDL_MicrosoftSync_' . $this->InstanceID;
            if (!IPS_SemaphoreEnter($sem, 0)) {
                $this->SetTimerInterval('SyncOnChangeTimer', 3000);
                return;
            }
            try {
                $this->MicrosoftToDoSyncInternal();
            } finally {
                IPS_SemaphoreLeave($sem);
            }
            return;
        }
    }

    private function DecodeValue(mixed $Value): array
    {
        if (is_array($Value)) {
            return $Value;
        }

        if (is_string($Value)) {
            $decoded = json_decode($Value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function EmptySelectDateTime(): array
    {
        return [
            'year'   => 0,
            'month'  => 0,
            'day'    => 0,
            'hour'   => 0,
            'minute' => 0,
            'second' => 0
        ];
    }

    private function TimestampToSelectDateTime(int $Timestamp): array
    {
        if ($Timestamp <= 0) {
            return $this->EmptySelectDateTime();
        }

        return [
            'year'   => (int)date('Y', $Timestamp),
            'month'  => (int)date('n', $Timestamp),
            'day'    => (int)date('j', $Timestamp),
            'hour'   => (int)date('G', $Timestamp),
            'minute' => (int)date('i', $Timestamp),
            'second' => (int)date('s', $Timestamp)
        ];
    }

    private function SelectDateTimeToTimestamp(mixed $Value): int
    {
        if (is_string($Value)) {
            $decoded = json_decode($Value, true);
            if (is_array($decoded)) {
                $Value = $decoded;
            }
        }

        if (is_array($Value)) {
            $year = (int)($Value['year'] ?? 0);
            $month = (int)($Value['month'] ?? 0);
            $day = (int)($Value['day'] ?? 0);
            $hour = (int)($Value['hour'] ?? 0);
            $minute = (int)($Value['minute'] ?? 0);
            $second = (int)($Value['second'] ?? 0);

            if ($year <= 0 || $month <= 0 || $day <= 0) {
                return 0;
            }

            return (int)mktime($hour, $minute, $second, $month, $day, $year);
        }

        if (is_int($Value)) {
            return $Value;
        }

        return 0;
    }

    private function LoadItems(): array
    {
        $data = json_decode($this->ReadAttributeString('Items'), true);
        if (!is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        unset($item);

        return $items;
    }

    private function SaveItems(array $Items): void
    {
        $this->WriteAttributeString('Items', json_encode($Items));
        // Bump here as well so item mutations that bypass SendState (direct TDL_*
        // calls, sync backends) still invalidate app-side caches.
        $this->WriteAttributeInteger('AppRevision', $this->ReadAttributeInteger('AppRevision') + 1);
        $this->UpdateStatistics();
        $this->UpdateTaskListHtml($Items);
        $this->UpdateRecurrenceTimer($Items);
        $this->UpdateStatisticsTimer($Items);
    }

    private function UpdateTaskListHtml(?array $Items = null): void
    {
        if (!$this->ReadPropertyBoolean('EnableHtmlBox')) {
            return;
        }
        if ($Items === null) {
            $Items = $this->LoadItems();
        }
        $this->SetValue('TaskListHtml', $this->BuildTaskListHtml($Items));
    }

    private function BuildTaskListHtml(array $Items): string
    {
        $hideCompleted = $this->ReadPropertyBoolean('HideCompletedTasks');
        $showOverview = $this->ReadPropertyBoolean('ShowOverview');
        $showLargeQty = $this->ReadPropertyBoolean('ShowLargeQuantity');
        // Fuenf gleichrangige Schalter, gleiche Regel wie in der Weboberflaeche.
        $badges = [
            'quantity'     => $this->ReadBooleanPropertyOrDefault('ShowQuantityBadge', true),
            'recurrence'   => $this->ReadBooleanPropertyOrDefault('ShowRecurrenceBadge', true),
            'due'          => $this->ReadBooleanPropertyOrDefault('ShowDueBadge', true),
            'notification' => $this->ReadBooleanPropertyOrDefault('ShowNotificationBadge', true),
            'priority'     => $this->ReadBooleanPropertyOrDefault('ShowPriorityBadge', true),
        ];

        $openItems = [];
        $doneItems = [];
        foreach ($Items as $it) {
            if (!is_array($it)) {
                continue;
            }
            if (!empty($it['done'])) {
                if (!$hideCompleted) {
                    $doneItems[] = $it;
                }
            } else {
                $openItems[] = $it;
            }
        }

        $openItems = $this->SortItemsForHtmlBox($openItems);
        if (!$hideCompleted) {
            $doneItems = $this->SortItemsForHtmlBox($doneItems);
        }

        $open = 0;
        $overdue = 0;
        $today = 0;
        $now = time();
        $todayStart = strtotime('today');
        $todayEnd = strtotime('tomorrow');
        foreach ($openItems as $it) {
            $open++;
            $due = (int)($it['due'] ?? 0);
            if ($due > 0) {
                if ((bool)($it['dueAllDay'] ?? false)) {
                    if ($due < $todayStart) {
                        $overdue++;
                    } elseif ($due < $todayEnd) {
                        $today++;
                    }
                } elseif ($due < $now) {
                    $overdue++;
                } elseif ($due >= $todayStart && $due < $todayEnd) {
                    $today++;
                }
            }
        }

        if (count($openItems) === 0 && count($doneItems) === 0) {
            $t = htmlspecialchars($this->Translate('No items'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return '<div style="font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; font-size: 14px; color: #fff;">' . $t . '</div>';
        }

        $cssBody = trim((string)$this->ReadPropertyString('HtmlBoxCss'));
        if ($cssBody === '') {
            $cssBody = $this->GetDefaultHtmlBoxCssBody();
        }
        $cssBlock = '<style>' . $cssBody . '</style>';

        $statsHtml = '';
        if ($showOverview) {
            $statsHtml = '<div class="stats">' .
                '<div class="stat-box stat-open"><div class="label">' . htmlspecialchars($this->Translate('Open Tasks'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div><div class="value">' . $open . '</div></div>' .
                '<div class="stat-box stat-overdue"><div class="label">' . htmlspecialchars($this->Translate('Overdue'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div><div class="value">' . $overdue . '</div></div>' .
                '<div class="stat-box stat-today"><div class="label">' . htmlspecialchars($this->Translate('Due Today'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div><div class="value">' . $today . '</div></div>' .
                '</div>';
        }

        $listClass = 'list';

        $html = $cssBlock . '<div class="tdl-htmlbox wrap">';

        $html .= $statsHtml;
        $html .= '<div class="' . htmlspecialchars($listClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';

        foreach ($openItems as $it) {
            $html .= $this->BuildTaskRowHtml($it, false, $badges, $showLargeQty, $now, $todayStart, $todayEnd);
        }

        if (count($doneItems) > 0) {
            $html .= '<div class="section-header">' . htmlspecialchars($this->Translate('Completed'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
            foreach ($doneItems as $it) {
                $html .= $this->BuildTaskRowHtml($it, true, $badges, $showLargeQty, $now, $todayStart, $todayEnd);
            }
        }

        $html .= '</div></div>';
        return $html;
    }

    /** @param array<string, bool> $Badges quantity/recurrence/due/notification/priority */
    private function BuildTaskRowHtml(array $Item, bool $Done, array $Badges, bool $ShowLargeQty, int $Now, int $TodayStart, int $TodayEnd): string
    {
        $zeige = static function (string $sorte) use ($Badges): bool {
            return $Badges[$sorte] ?? true;
        };
        $title = htmlspecialchars((string)($Item['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $info = trim((string)($Item['info'] ?? ''));
        $infoHtml = '';
        if ($info !== '') {
            $infoHtml = '<div class="info">' . htmlspecialchars($info, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        }

        $prio = (string)($Item['priority'] ?? 'normal');
        if ($prio !== 'low' && $prio !== 'normal' && $prio !== 'high') {
            $prio = 'normal';
        }

        $qty = (int)($Item['quantity'] ?? 0);
        $dueTs = (int)($Item['due'] ?? 0);
        $notification = (bool)($Item['notification'] ?? false);
        $recurrence = (string)($Item['recurrence'] ?? 'none');
        $recurrenceUnit = (string)($Item['recurrenceCustomUnit'] ?? 'w');
        $recurrenceValue = (int)($Item['recurrenceCustomValue'] ?? 1);

        // Nur die grosse Menge wird im Inhaltsbereich gezeigt; die kleine Variante
        // hing am Einkaufslisten-Modus und war damit schon vorher unerreichbar.
        $qtyLargeHtml = '';
        if ($qty > 0 && $ShowLargeQty) {
            $qtyLargeHtml = '<div class="quantity-large-wrap"><span class="badge quantity large-qty">' . $qty . '×</span></div>';
        }

        $meta = [];
        if ($qty > 0 && !$ShowLargeQty && $zeige('quantity')) {
            $meta[] = '<span class="badge quantity">' . $qty . '×</span>';
        }

        if ($zeige('due') && $dueTs > 0) {
            $allDay = (bool)($Item['dueAllDay'] ?? false);
            $dueClass = '';
            if ($allDay) {
                // All-day: due-today for the whole day, overdue from the next day on
                if ($dueTs < $TodayStart) {
                    $dueClass = ' due-overdue';
                } elseif ($dueTs < $TodayEnd) {
                    $dueClass = ' due-today';
                }
            } elseif ($dueTs < $Now) {
                $dueClass = ' due-overdue';
            } elseif ($dueTs >= $TodayStart && $dueTs < $TodayEnd) {
                $dueClass = ' due-today';
            }
            $dueText = htmlspecialchars(date($allDay ? 'd.m.Y' : 'd.m.Y H:i', $dueTs), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $meta[] = '<span class="badge due-badge' . $dueClass . '" title="' . $dueText . '">' . $dueText . '</span>';
        }
        if ($zeige('notification') && $notification) {
            $bellSvg = '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><path d="M320 64C306.7 64 296 74.7 296 88L296 97.7C214.6 109.3 152 179.4 152 264L152 278.5C152 316.2 142 353.2 123 385.8L101.1 423.2C97.8 429 96 435.5 96 442.2C96 463.1 112.9 480 133.8 480L506.2 480C527.1 480 544 463.1 544 442.2C544 435.5 542.2 428.9 538.9 423.2L517 385.7C498 353.1 488 316.1 488 278.4L488 263.9C488 179.3 425.4 109.2 344 97.6L344 87.9C344 74.6 333.3 63.9 320 63.9zM488.4 432L151.5 432L164.4 409.9C187.7 370 200 324.6 200 278.5L200 264C200 197.7 253.7 144 320 144C386.3 144 440 197.7 440 264L440 278.5C440 324.7 452.3 370 475.5 409.9L488.4 432zM252.1 528C262 556 288.7 576 320 576C351.3 576 378 556 387.9 528L252.1 528z"/></svg>';
            $meta[] = '<span class="badge notify-badge" title="' . htmlspecialchars($this->Translate('Notification'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $bellSvg . '</span>';
        }
        if ($zeige('recurrence') && $recurrence !== 'none') {
            $rLabel = $this->GetRecurrenceLabel($recurrence, $recurrenceUnit, $recurrenceValue);
            $repeatSvg = '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><path d="M544.1 256L552 256C565.3 256 576 245.3 576 232L576 88C576 78.3 570.2 69.5 561.2 65.8C552.2 62.1 541.9 64.2 535 71L483.3 122.8C439 86.1 382 64 320 64C191 64 84.3 159.4 66.6 283.5C64.1 301 76.2 317.2 93.7 319.7C111.2 322.2 127.4 310 129.9 292.6C143.2 199.5 223.3 128 320 128C364.4 128 405.2 143 437.7 168.3L391 215C384.1 221.9 382.1 232.2 385.8 241.2C389.5 250.2 398.3 256 408 256L544.1 256zM573.5 356.5C576 339 563.8 322.8 546.4 320.3C529 317.8 512.7 330 510.2 347.4C496.9 440.4 416.8 511.9 320.1 511.9C275.7 511.9 234.9 496.9 202.4 471.6L249 425C255.9 418.1 257.9 407.8 254.2 398.8C250.5 389.8 241.7 384 232 384L88 384C74.7 384 64 394.7 64 408L64 552C64 561.7 69.8 570.5 78.8 574.2C87.8 577.9 98.1 575.8 105 569L156.8 517.2C201 553.9 258 576 320 576C449 576 555.7 480.6 573.4 356.5z"/></svg>';
            $meta[] = '<span class="badge recur-badge" title="' . htmlspecialchars($rLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $repeatSvg . '</span>';
        }
        if ($zeige('priority')) {
            $meta[] = '<span class="badge ' . htmlspecialchars($prio, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($this->GetPriorityLabel($prio), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        }

        $metaHtml = '<div class="meta">' . implode('', $meta) . '</div>';

        $rowClass = 'item' . ($Done ? ' done' : '');
        return '<div class="' . $rowClass . '">' .
            '<div class="main">' .
                '<div class="content">' .
                    '<div class="title">' . $title . '</div>' .
                    $infoHtml .
                    $qtyLargeHtml .
                '</div>' .
            '</div>' .
            '<div class="actions">' .
                $metaHtml .
            '</div>' .
        '</div>';
    }

    private function SortItemsForHtmlBox(array $Items): array
    {
        $sort = $this->GetSortPrefs();
        $mode = (string)($sort['mode'] ?? 'created');
        $dir = (string)($sort['dir'] ?? 'desc');

        if ($mode === 'manual') {
            return $Items;
        }

        $list = array_values($Items);

        $compareDue = function (array $a, array $b) use ($dir): int {
            $da = (int)($a['due'] ?? 0);
            $db = (int)($b['due'] ?? 0);
            if ($da <= 0 && $db <= 0) {
                return 0;
            }
            if ($da <= 0) {
                return 1;
            }
            if ($db <= 0) {
                return -1;
            }
            return ($dir === 'asc') ? ($da <=> $db) : ($db <=> $da);
        };

        $prioRankLowFirst = function (string $p): int {
            return match ($p) {
                'low' => 0,
                'high' => 2,
                default => 1
            };
        };

        $getIdKey = fn(array $it): int => (int)($it['id'] ?? 0);
        $getCreatedKey = fn(array $it): int => (int)($it['createdAt'] ?? 0);
        $getTitleKey = fn(array $it): string => mb_strtolower(trim((string)($it['title'] ?? '')));

        usort($list, function (array $a, array $b) use ($mode, $dir, $compareDue, $prioRankLowFirst, $getIdKey, $getCreatedKey, $getTitleKey): int {
            if ($mode === 'due') {
                $c = $compareDue($a, $b);
                if ($c !== 0) {
                    return $c;
                }
                return $getIdKey($a) <=> $getIdKey($b);
            }

            if ($mode === 'priority') {
                $pa = $prioRankLowFirst((string)($a['priority'] ?? 'normal'));
                $pb = $prioRankLowFirst((string)($b['priority'] ?? 'normal'));
                if ($pa !== $pb) {
                    return ($dir === 'asc') ? ($pa <=> $pb) : ($pb <=> $pa);
                }
                $c = $compareDue($a, $b);
                if ($c !== 0) {
                    return $c;
                }
                return $getIdKey($a) <=> $getIdKey($b);
            }

            if ($mode === 'title') {
                $ta = $getTitleKey($a);
                $tb = $getTitleKey($b);
                $c = strcasecmp($ta, $tb);
                if ($c !== 0) {
                    return $c;
                }
                return $getIdKey($a) <=> $getIdKey($b);
            }

            $ca = $getCreatedKey($a);
            $cb = $getCreatedKey($b);
            if ($ca !== $cb) {
                return ($dir === 'asc') ? ($ca <=> $cb) : ($cb <=> $ca);
            }
            return $getIdKey($a) <=> $getIdKey($b);
        });

        if ($mode === 'title' && $dir === 'desc') {
            $list = array_reverse($list);
        }

        return $list;
    }

    private function GetPriorityLabel(string $Prio): string
    {
        switch ($Prio) {
            case 'low':
                return $this->Translate('Low');
            case 'high':
                return $this->Translate('High');
            default:
                return $this->Translate('Normal');
        }
    }

    private function GetRecurrenceLabel(string $Recurrence, string $Unit, int $Value): string
    {
        $r = strtolower(trim($Recurrence));
        switch ($r) {
            case 'w1':
                return $this->Translate('Every week');
            case 'w2':
                return $this->Translate('Every 2 weeks');
            case 'w3':
                return $this->Translate('Every 3 weeks');
            case 'm1':
                return $this->Translate('Monthly');
            case 'q1':
                return $this->Translate('Quarterly');
            case 'y1':
                return $this->Translate('Yearly');
            case 'custom':
                $u = $this->Translate('Weeks');
                switch ($this->NormalizeRecurrenceCustomUnit($Unit)) {
                    case 'h':
                        $u = $this->Translate('Hours');
                        break;
                    case 'd':
                        $u = $this->Translate('Days');
                        break;
                    case 'w':
                        $u = $this->Translate('Weeks');
                        break;
                    case 'm':
                        $u = $this->Translate('Months');
                        break;
                    case 'y':
                        $u = $this->Translate('Years');
                        break;
                }
                $v = max(1, (int)$Value);
                return $this->Translate('Custom') . ': ' . $v . ' ' . $u;
            default:
                return $this->Translate('No repeat');
        }
    }

    private function SetSortPrefs(array $Data): void
    {
        $mode = (string)($Data['mode'] ?? '');
        $dir = (string)($Data['dir'] ?? '');

        $allowedModes = ['manual', 'created', 'due', 'priority', 'title'];
        if (!in_array($mode, $allowedModes, true)) {
            $mode = 'created';
        }
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = 'desc';
        }

        $this->WriteAttributeString('SortMode', $mode);
        $this->WriteAttributeString('SortDir', $dir);
    }

    private function GetSortPrefs(): array
    {
        $mode = (string)$this->ReadAttributeString('SortMode');
        $dir = (string)$this->ReadAttributeString('SortDir');

        $allowedModes = ['manual', 'created', 'due', 'priority', 'title'];
        if (!in_array($mode, $allowedModes, true)) {
            $mode = 'created';
        }
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = 'desc';
        }

        return ['mode' => $mode, 'dir' => $dir];
    }

    private function FormatLeadTime(int $Seconds): string
    {
        $Seconds = max(0, $Seconds);
        if ($Seconds % 3600 === 0) {
            $hours = (int)($Seconds / 3600);
            return $hours === 1 ? ('1 ' . $this->Translate('hour')) : ($hours . ' ' . $this->Translate('hours'));
        }
        if ($Seconds % 60 === 0) {
            $minutes = (int)($Seconds / 60);
            return $minutes === 1 ? ('1 ' . $this->Translate('minute')) : ($minutes . ' ' . $this->Translate('minutes'));
        }

        return (string)$Seconds;
    }

    public function ProcessNotifications(): void
    {
        $visuID = $this->ReadPropertyInteger('VisualizationInstanceID');
        if ($visuID <= 0) {
            return;
        }

        $defaultLeadTime = max(0, $this->ReadPropertyInteger('NotificationLeadTime'));
        $now = time();

        $items = $this->LoadItems();
        $changed = false;

        foreach ($items as &$item) {
            if (empty($item['notification'])) {
                continue;
            }
            if (!empty($item['done'])) {
                continue;
            }

            $due = (int)($item['due'] ?? 0);
            if ($due <= 0) {
                continue;
            }

            $leadTime = $defaultLeadTime;
            if (array_key_exists('notificationLeadTime', $item)) {
                $leadTime = max(0, (int)$item['notificationLeadTime']);
            }

            $trigger = $due - $leadTime;
            if ($now < $trigger) {
                continue;
            }

            $alreadyFor = (int)($item['notifiedFor'] ?? 0);
            if ($alreadyFor === $trigger) {
                continue;
            }

            $itemTitle = (string)($item['title'] ?? '');
            $title = $this->Translate('Task due');
            if ($leadTime > 0) {
                $leadTimeText = $this->FormatLeadTime($leadTime);
                $title = str_replace('{0}', $leadTimeText, $this->Translate('Task due in title'));
            }
            $title = substr($title, 0, 32);

            $text = substr($itemTitle, 0, 256);

            $result = @VISU_PostNotification($visuID, $title, $text, 'Info', $this->InstanceID);
            if ($result !== false) {
                $item['notifiedFor'] = $trigger;
                $changed = true;
            }
        }
        unset($item);

        if ($changed) {
            $this->SaveItems($items);
        }
    }

    private function ResetNotificationMarkers(): void
    {
        $items = $this->LoadItems();
        $changed = false;
        foreach ($items as &$item) {
            if ((int)($item['notifiedFor'] ?? 0) !== 0) {
                $item['notifiedFor'] = 0;
                $changed = true;
            }
        }
        unset($item);

        if ($changed) {
            $this->SaveItems($items);
        }
    }

    public function RefreshStatistics(): void
    {
        $items = $this->LoadItems();
        $this->UpdateStatistics();
        $this->UpdateTaskListHtml($items);
        $this->UpdateStatisticsTimer($items);
        $this->SendState();
    }

    private function UpdateStatisticsTimer(?array $Items = null): void
    {
        if ($Items === null) {
            $Items = $this->LoadItems();
        }

        $now = time();
        $next = strtotime('tomorrow');
        foreach ($Items as $item) {
            if (!empty($item['done'])) {
                continue;
            }
            $due = (int)($item['due'] ?? 0);
            if ($due > $now && $due < $next) {
                $next = $due;
            }
        }

        $this->SetTimerInterval('StatisticsTimer', max(1000, ($next - $now + 1) * 1000));
    }

    private function UpdateStatistics(): void
    {
        $items = $this->LoadItems();
        $now = time();
        $todayStart = strtotime('today');
        $todayEnd = strtotime('tomorrow');

        $open = 0;
        $overdue = 0;
        $dueToday = 0;

        foreach ($items as $item) {
            if (!empty($item['done'])) {
                continue;
            }
            $open++;
            $due = (int)($item['due'] ?? 0);
            if ($due > 0) {
                if ((bool)($item['dueAllDay'] ?? false)) {
                    if ($due < $todayStart) {
                        $overdue++;
                    } elseif ($due < $todayEnd) {
                        $dueToday++;
                    }
                } elseif ($due < $now) {
                    $overdue++;
                } elseif ($due >= $todayStart && $due < $todayEnd) {
                    $dueToday++;
                }
            }
        }

        $this->SetValue('OpenTasks', $open);
        $this->SetValue('OverdueTasks', $overdue);
        $this->SetValue('DueTodayTasks', $dueToday);
    }

    public function ProcessRecurrences(): void
    {
        $items = $this->LoadItems();
        $now = time();

        $interval = 60;

        $changed = false;

        foreach ($items as &$item) {
            if (empty($item['done'])) {
                continue;
            }

            $due = (int)($item['due'] ?? 0);
            if ($due <= 0) {
                continue;
            }

            $recurrence = $this->NormalizeRecurrence($item['recurrence'] ?? 'none', $due);
            if ($recurrence === 'none') {
                if (isset($item['recurrence']) && (string)$item['recurrence'] !== 'none') {
                    $item['recurrence'] = 'none';
                    $item['recurrenceResetLeadTime'] = 0;
                    $changed = true;
                }
                continue;
            }

            $allDay = (bool)($item['dueAllDay'] ?? false);
            $leadTime = $this->NormalizeRecurrenceResetLeadTime($item['recurrenceResetLeadTime'] ?? null, $recurrence);
            if ($leadTime === -1) {
                if ($due <= $now) {
                    $unit = (string)($item['recurrenceCustomUnit'] ?? 'w');
                    $val = (int)($item['recurrenceCustomValue'] ?? 1);
                    $newDue = $this->GetNextDue($due, $recurrence, $unit, $val, $allDay);
                    $guard = 0;
                    while ($newDue > 0 && $newDue <= $now && $guard < 24) {
                        $newDue = $this->GetNextDue($newDue, $recurrence, $unit, $val, $allDay);
                        $guard++;
                    }
                    if ($newDue !== $due) {
                        $item['due'] = $newDue;
                    }
                }
                $item['done'] = false;
                $item['notifiedFor'] = 0;
                $item['updatedAt'] = $now;
                // Package 2/finding: mark dirty so the reopened/advanced occurrence is pushed to
                // the CalDAV server (ToggleDone already does this; the timer path must too).
                $item['localModified'] = $now;
                $changed = true;
                continue;
            }
            $windowStart = $leadTime - $interval;
            if ($leadTime <= 0) {
                continue;
            }

            $left = $due - $now;
            if ($left <= $leadTime && $left >= $windowStart) {
                $item['done'] = false;
                $item['notifiedFor'] = 0;
                $item['updatedAt'] = $now;
                $item['localModified'] = $now;
                $changed = true;
                continue;
            }

            if ($left < $windowStart) {
                $unit = (string)($item['recurrenceCustomUnit'] ?? 'w');
                $val = (int)($item['recurrenceCustomValue'] ?? 1);
                $newDue = $this->GetNextDue($due, $recurrence, $unit, $val, $allDay);
                $guard = 0;
                while ($newDue > 0 && $newDue <= $now && $guard < 24) {
                    $newDue = $this->GetNextDue($newDue, $recurrence, $unit, $val, $allDay);
                    $guard++;
                }
                if ($newDue !== $due) {
                    $item['due'] = $newDue;
                    $item['notifiedFor'] = 0;
                    $item['updatedAt'] = $now;
                    $item['localModified'] = $now;
                    $changed = true;
                }
            }
        }
        unset($item);

        if ($changed) {
            $this->SaveItems($items);
            $this->SendState();
            $this->ScheduleSyncOnChange();
        }
    }

    private function UpdateRecurrenceTimer(?array $Items = null): void
    {
        if ($Items === null) {
            $Items = $this->LoadItems();
        }
        $has = false;
        foreach ($Items as $it) {
            if ($this->NormalizeRecurrence($it['recurrence'] ?? 'none', (int)($it['due'] ?? 0)) !== 'none') {
                $has = true;
                break;
            }
        }
        $this->SetTimerInterval('RecurrenceTimer', $has ? 60000 : 0);
    }

    // An all-day due is a calendar day, not an instant. A client in another timezone
    // cannot express "July 7th" reliably as an epoch, so payloads may carry the picked
    // day as a TZ-neutral 'Y-m-d' string which wins over the epoch when present.
    private function AllDayDueFromPayload(int $Due, array $Data): int
    {
        $day = (string)($Data['dueDay'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
            $ts = strtotime($day . ' 00:00:00');
            if ($ts !== false) {
                return $ts;
            }
        }
        return (int)strtotime(date('Y-m-d 00:00:00', $Due));
    }

    private function NormalizeRecurrence(mixed $Value, int $Due): string
    {
        if ($Due <= 0) {
            return 'none';
        }
        $r = is_string($Value) ? strtolower(trim($Value)) : 'none';
        $allowed = ['none', 'custom', 'w1', 'w2', 'w3', 'm1', 'q1', 'y1'];
        if (!in_array($r, $allowed, true)) {
            return 'none';
        }
        return $r;
    }

    private function NormalizeRecurrenceCustomUnit(mixed $Value): string
    {
        $u = is_string($Value) ? strtolower(trim($Value)) : '';
        $allowed = ['h', 'd', 'w', 'm', 'y'];
        if (!in_array($u, $allowed, true)) {
            return 'w';
        }
        return $u;
    }

    private function NormalizeRecurrenceCustomValue(mixed $Value): int
    {
        $v = null;
        if (is_int($Value)) {
            $v = $Value;
        } elseif (is_numeric($Value)) {
            $v = (int)$Value;
        }
        if ($v === null) {
            return 1;
        }
        if ($v <= 0) {
            return 1;
        }
        return min($v, 1000);
    }

    private function NormalizeRecurrenceResetLeadTime(mixed $Value, string $Recurrence): int
    {
        if ($this->NormalizeRecurrence($Recurrence, 1) === 'none') {
            return 0;
        }

        if ($Value === null) {
            return 604800;
        }

        $v = null;
        if (is_int($Value)) {
            $v = $Value;
        } elseif (is_numeric($Value)) {
            $v = (int)$Value;
        }

        if ($v === null) {
            return 604800;
        }
        if ($v === -1) {
            return -1;
        }
        if ($v === 0) {
            return 0;
        }
        if ($v < 0) {
            return 604800;
        }

        $allowed = [-1, 1800, 3600, 21600, 43200, 86400, 172800, 259200, 604800, 1209600, 2592000];
        if (!in_array($v, $allowed, true)) {
            return 604800;
        }
        return $v;
    }

    private function NormalizeNotificationLeadTimeDefault(int $Value): int
    {
        $v = max(0, $Value);
        $allowed = [0, 300, 600, 1800, 3600, 18000, 43200];
        if (!in_array($v, $allowed, true)) {
            return 600;
        }
        return $v;
    }

    private function NormalizeNotificationLeadTime(mixed $Value, int $Default): int
    {
        if ($Value === null) {
            return $Default;
        }

        $v = null;
        if (is_int($Value)) {
            $v = $Value;
        } elseif (is_numeric($Value)) {
            $v = (int)$Value;
        }
        if ($v === null) {
            return $Default;
        }
        if ($v < 0) {
            return $Default;
        }

        $allowed = [0, 300, 600, 1800, 3600, 18000, 43200];
        if (!in_array($v, $allowed, true)) {
            return $Default;
        }
        return $v;
    }

    private function GetRecurrenceIntervalSeconds(int $Due, string $Recurrence, string $CustomUnit = 'w', int $CustomValue = 1): int
    {
        if ($Due <= 0) {
            return 0;
        }
        $r = $this->NormalizeRecurrence($Recurrence, $Due);
        if ($r === 'none') {
            return 0;
        }
        $next = $this->GetNextDue($Due, $r, $CustomUnit, $CustomValue);
        $delta = $next - $Due;
        return $delta > 0 ? $delta : 0;
    }

    private function ClampLeadTimeToInterval(int $LeadTime, int $Interval, array $Allowed): int
    {
        if ($LeadTime === -1) {
            return -1;
        }
        $LeadTime = max(0, $LeadTime);
        if ($Interval <= 0) {
            return $LeadTime;
        }
        if ($LeadTime === 0) {
            return 0;
        }
        if ($LeadTime < $Interval) {
            return $LeadTime;
        }

        $best = 0;
        foreach ($Allowed as $v) {
            $v = (int)$v;
            if ($v < $Interval && $v > $best) {
                $best = $v;
            }
        }
        return $best;
    }

    private function GetLeadTimeLimitSeconds(int $Due, int $Now, string $Recurrence, string $CustomUnit = 'w', int $CustomValue = 1): int
    {
        if ($Due <= 0) {
            return 0;
        }
        $limit = max(0, $Due - $Now);
        if ($limit <= 0) {
            return 0;
        }

        $r = $this->NormalizeRecurrence($Recurrence, $Due);
        if ($r !== 'none') {
            $interval = $this->GetRecurrenceIntervalSeconds($Due, $r, $CustomUnit, $CustomValue);
            if ($interval > 0) {
                $limit = min($limit, $interval);
            }
        }

        return $limit;
    }

    private function ClampLeadTimeToLimit(int $LeadTime, int $Limit, array $Allowed): int
    {
        $LeadTime = max(0, $LeadTime);
        if ($LeadTime === 0) {
            return 0;
        }
        if ($Limit <= 0) {
            return 0;
        }
        if ($LeadTime < $Limit) {
            return $LeadTime;
        }

        $best = 0;
        foreach ($Allowed as $v) {
            $v = (int)$v;
            if ($v === 0) {
                continue;
            }
            if ($v < $Limit && $v > $best) {
                $best = $v;
            }
        }
        return $best;
    }

    private function GetNextDue(int $Due, string $Recurrence, string $CustomUnit = '', int $CustomValue = 0, bool $AllDay = false): int
    {
        if ($Due <= 0) {
            return 0;
        }
        $r = $this->NormalizeRecurrence($Recurrence, $Due);
        $next = $Due;
        switch ($r) {
            case 'custom':
                $u = $this->NormalizeRecurrenceCustomUnit($CustomUnit);
                $v = $this->NormalizeRecurrenceCustomValue($CustomValue);
                switch ($u) {
                    case 'h': $next = $Due + (3600 * $v); break;
                    case 'd': $next = $Due + (86400 * $v); break;
                    case 'w': $next = $Due + (604800 * $v); break;
                    case 'm': $next = $this->AddMonthsClamped($Due, $v); break;
                    case 'y': $next = $this->AddMonthsClamped($Due, 12 * $v); break;
                }
                break;
            case 'w1': $next = $Due + 604800; break;
            case 'w2': $next = $Due + 1209600; break;
            case 'w3': $next = $Due + 1814400; break;
            case 'm1': $next = $this->AddMonthsClamped($Due, 1); break;
            case 'q1': $next = $this->AddMonthsClamped($Due, 3); break;
            case 'y1': $next = $this->AddMonthsClamped($Due, 12); break;
        }
        // Re-floor an all-day due to local midnight: the fixed-seconds advance (e.g. +604800 for
        // a week) shifts the wall-clock by an hour across a DST boundary, which would move the
        // calendar day of a midnight-anchored all-day task.
        if ($AllDay && $next > 0) {
            $next = (int)strtotime(date('Y-m-d 00:00:00', $next));
        }
        return $next;
    }

    private function AddMonthsClamped(int $Due, int $Months): int
    {
        $year = (int)date('Y', $Due);
        $month = (int)date('n', $Due);
        $day = (int)date('j', $Due);
        $hour = (int)date('G', $Due);
        $minute = (int)date('i', $Due);
        $second = (int)date('s', $Due);

        $month += $Months;
        $year += intdiv($month - 1, 12);
        $month = (($month - 1) % 12) + 1;

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $day = min($day, $daysInMonth);

        return (int)mktime($hour, $minute, $second, $month, $day, $year);
    }

    /**
     * @param bool $WithUsers Avatare mitliefern. Nur die Symcon-Kachel liest sie; über die
     *                        REST-API holt sich jeder Client die Nutzer aus /v1/discovery
     *                        (iOS cacht sie im SyncCoordinator, die Web-App verwirft das
     *                        Feld). Gemessen sind die Avatare 28 KB pro Antwort — bei einer
     *                        kurzen Liste über 98 % der Nutzlast.
     */

    /**
     * Liest eine eigene Boolean-Property, faellt aber auf die Vorgabe zurueck, solange
     * sie in der Instanzkonfiguration fehlt.
     *
     * Notwendig, weil eine frisch registrierte Property vor dem ersten Kernel-Neustart
     * `false` liefert statt ihres Vorgabewerts — ein "an"-Schalter waere fuer
     * Bestandsnutzer nach dem Update also still aus.
     */
    /** Gibt es die Eigenschaft schon? Neue entstehen erst beim naechsten Kernel-Start. */
    private function PropertyExistiert(string $Name): bool
    {
        $config = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        return is_array($config) && array_key_exists($Name, $config);
    }

    private function ReadBooleanPropertyOrDefault(string $Name, bool $Default): bool
    {
        $config = json_decode((string)IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($config) || !array_key_exists($Name, $config)) {
            return $Default;
        }
        return $this->ReadPropertyBoolean($Name);
    }

    /**
     * Sichtbarkeit der Bedienelemente dieser Liste — ausschliesslich aus den eigenen
     * Eigenschaften. Es gibt keine Uebersteuerung von aussen mehr.
     *
     * Diese Werte gelten fuer die KACHEL dieser Liste. Die Web-App verwirft sie und
     * setzt ihre eigenen, appweiten Schalter (SymDoWebApp) — sie zeigt alle Listen in
     * einer Oberflaeche, dort waere ein Wechsel des Erscheinungsbilds von Liste zu
     * Liste stoerend. Ueberschrieben wird nicht hier, sondern beim Zusammensetzen der
     * Nutzlast: SymDoWebApp::StripState() und im Gateway ApplyWebAppButtonFlags().
     *
     * @return array{showOverview: bool, showMemberBar: bool, showCreateButton: bool, showSorting: bool, showEditButton: bool, showDeleteButton: bool, showReorderHandle: bool, swipeGestures: bool}
     */
    private function ResolveButtonFlags(): array
    {
        return [
            'showOverview'      => $this->ReadBooleanPropertyOrDefault('ShowOverview', true),
            'showMemberBar'     => $this->ReadBooleanPropertyOrDefault('ShowMemberBar', true),
            'showCreateButton'  => $this->ReadBooleanPropertyOrDefault('ShowCreateButton', true),
            'showSorting'       => $this->ReadBooleanPropertyOrDefault('ShowSorting', true),
            'showEditButton'    => $this->ReadBooleanPropertyOrDefault('ShowRowEditButton', false),
            'showDeleteButton'  => $this->ReadBooleanPropertyOrDefault('ShowRowDeleteButton', false),
            'showReorderHandle' => $this->ReadBooleanPropertyOrDefault('ShowReorderHandle', true),
            'swipeGestures'     => $this->ReadBooleanPropertyOrDefault('EnableSwipeGestures', true),
        ];
    }

    private function BuildStatePayload(bool $WithUsers = true): array
    {
        $sort = $this->GetSortPrefs();
        $knoepfe = $this->ResolveButtonFlags();
        $items = $this->LoadItems();
        foreach ($items as &$it) {
            // All-day dues encode server-local midnight; ship the calendar day TZ-neutral
            // so a client in another timezone renders the intended day.
            if (!empty($it['dueAllDay']) && (int)($it['due'] ?? 0) > 0) {
                $it['dueDay'] = date('Y-m-d', (int)$it['due']);
            }
        }
        unset($it);
        $payload = [
            'type'  => 'state',
            'items' => $items,
            // Der Kachel-Adapter setzt daraus den Titel der Kopfzeile; ueber die
            // Web-App liefert das Gateway den Namen ohnehin selbst.
            'listName' => IPS_GetName($this->InstanceID),
            'notificationLeadTimeDefault' => $this->ReadPropertyInteger('NotificationLeadTime'),
            'syncBackend' => $this->GetSyncBackend(),
            'sortMode' => $sort['mode'],
            'sortDir'  => $sort['dir'],
            'orderVersion' => $this->ReadAttributeInteger('OrderVersion'),
            'showOverview' => $knoepfe['showOverview'],
            'showMemberBar' => $knoepfe['showMemberBar'],
            'showCreateButton' => $knoepfe['showCreateButton'],
            'showSorting' => $knoepfe['showSorting'],
            'showLargeQuantity' => $this->ReadPropertyBoolean('ShowLargeQuantity'),
            'showQuantityBadge' => $this->ReadBooleanPropertyOrDefault('ShowQuantityBadge', true),
            'showRecurrenceBadge' => $this->ReadBooleanPropertyOrDefault('ShowRecurrenceBadge', true),
            'showDueBadge' => $this->ReadBooleanPropertyOrDefault('ShowDueBadge', true),
            'showNotificationBadge' => $this->ReadBooleanPropertyOrDefault('ShowNotificationBadge', true),
            'showPriorityBadge' => $this->ReadBooleanPropertyOrDefault('ShowPriorityBadge', true),
            'showEditButton' => $knoepfe['showEditButton'],
            'showDeleteButton' => $knoepfe['showDeleteButton'],
            'showReorderHandle' => $knoepfe['showReorderHandle'],
            'swipeGestures' => $knoepfe['swipeGestures'],
            'hideCompletedTasks' => $this->ReadPropertyBoolean('HideCompletedTasks'),
            'deleteCompletedTasks' => $this->ReadPropertyBoolean('DeleteCompletedTasks')
        ];
        if ($WithUsers) {
            $payload['users'] = $this->GetTileUsers();
        }
        return $payload;
    }

    /**
     * Die App-Hälfte läuft auf dem Gateway mit der niedrigsten Instanz-ID — nicht
     * zwingend auf dem eigenen Eltern-Gateway, falls jemand mehrere Konten fährt.
     */
    private function GetAppGatewayID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID('{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}');
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }

    /** Household users (with scaled avatar data URIs) from the SymDo Gateway. */
    private function GetTileUsers(): array
    {
        if (!function_exists('TGW_GetUsersForTile')) {
            return [];
        }
        $gateway = $this->GetAppGatewayID();
        if ($gateway === 0) {
            return [];
        }
        $data = json_decode((string)@TGW_GetUsersForTile($gateway), true);
        return is_array($data) ? $data : [];
    }

    /** Gateway user ids this task is assigned to (deduped, capped). */
    private function NormalizeAssignedTo(mixed $Value): array
    {
        if (!is_array($Value)) {
            return [];
        }
        $result = [];
        foreach ($Value as $entry) {
            $id = trim((string)$entry);
            if ($id !== '' && !in_array($id, $result, true)) {
                $result[] = $id;
            }
            if (count($result) >= 16) {
                break;
            }
        }
        return $result;
    }

    private function NotifyAssignedUsers(array $UserIDs, string $Title, string $Actor): void
    {
        if ($UserIDs === [] || !function_exists('TGW_NotifyAssignment')) {
            return;
        }
        $gateway = $this->GetAppGatewayID();
        if ($gateway > 0) {
            @TGW_NotifyAssignment($gateway, json_encode(array_values($UserIDs)), $Title, $Actor);
        }
    }

    private function SendState(): void
    {
        $this->WriteAttributeInteger('AppRevision', $this->ReadAttributeInteger('AppRevision') + 1);
        $this->PushCurrentState();
    }

    private function PushCurrentState(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->BuildStatePayload(), JSON_UNESCAPED_SLASHES));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CalDAV Synchronization
    // ──────────────────────────────────────────────────────────────────────────

    private function GetSyncBackend(): string
    {
        $backend = (string) $this->ReadPropertyString('SyncBackend');
        if (!in_array($backend, ['local', 'caldav', 'google', 'microsoft'], true)) {
            return 'local';
        }
        return $backend;
    }

    private function EnforceSyncBackend(): bool
    {
        static $running = false;
        if ($running) {
            return false;
        }
        $running = true;

        $backend = $this->GetSyncBackend();
        $calEnabled = $this->ReadPropertyBoolean('CalDAVEnabled');
        $googleEnabled = $this->ReadPropertyBoolean('GoogleTasksEnabled');
        $msEnabled = $this->ReadPropertyBoolean('MicrosoftToDoEnabled');

        // Bis zum naechsten Kernel-Neustart kann das Attribut in bestehenden Instanzen
        // fehlen; ReadAttribute* liefert dann false. Das darf NICHT als "Migration noch
        // offen" gelten: sie schriebe sonst bei jedem ApplyChanges Eigenschaften um,
        // koennte sich das Ergebnis nicht merken und stiesse ueber IPS_ApplyChanges das
        // naechste ApplyChanges an.
        $rohwert = @$this->ReadAttributeInteger('SyncBackendMigrationDone');
        $migrationDone = ($rohwert === false) ? 1 : (int)$rohwert;
        if ($migrationDone === 0) {
            if ($backend === 'local' && ($calEnabled || $googleEnabled || $msEnabled)) {
                if ($googleEnabled) {
                    $backend = 'google';
                } elseif ($msEnabled) {
                    $backend = 'microsoft';
                } else {
                    $backend = 'caldav';
                }
            }
            @$this->WriteAttributeInteger('SyncBackendMigrationDone', 1);
        }
        $wantCalDAV = $backend === 'caldav';
        $wantGoogle = $backend === 'google';
        $wantMicrosoft = $backend === 'microsoft';

        $changed = false;
        if ($this->ReadPropertyString('SyncBackend') !== $backend) {
            IPS_SetProperty($this->InstanceID, 'SyncBackend', $backend);
            $changed = true;
        }
        if ($this->ReadPropertyBoolean('CalDAVEnabled') !== $wantCalDAV) {
            IPS_SetProperty($this->InstanceID, 'CalDAVEnabled', $wantCalDAV);
            $changed = true;
        }
        if ($this->ReadPropertyBoolean('GoogleTasksEnabled') !== $wantGoogle) {
            IPS_SetProperty($this->InstanceID, 'GoogleTasksEnabled', $wantGoogle);
            $changed = true;
        }

        if ($this->ReadPropertyBoolean('MicrosoftToDoEnabled') !== $wantMicrosoft) {
            IPS_SetProperty($this->InstanceID, 'MicrosoftToDoEnabled', $wantMicrosoft);
            $changed = true;
        }

        if ($changed) {
            $this->UebernehmenNachtragen();
            $running = false;
            return true;
        }

        $running = false;
        return false;
    }

    private function GetNextItemID(): int
    {
        $nextId = $this->ReadAttributeInteger('NextID');
        $this->WriteAttributeInteger('NextID', $nextId + 1);
        return $nextId;
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
