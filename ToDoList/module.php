<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/SyncHelper.php';
require_once __DIR__ . '/libs/CalDAVSync.php';
require_once __DIR__ . '/libs/GoogleTasksSync.php';
require_once __DIR__ . '/libs/MicrosoftToDoSync.php';

class ToDoList extends IPSModuleStrict
{
    use SyncHelper;
    use CalDAVSync;
    use GoogleTasksSync;
    use MicrosoftToDoSync;

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type'      => 'connect',
            'moduleIDs' => ['{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}']
        ]);
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
        $this->RegisterPropertyString('ItemsTable', '[]');
        $this->RegisterPropertyInteger('VisualizationInstanceID', 0);
        $this->RegisterPropertyInteger('NotificationLeadTime', 600);
        $this->RegisterPropertyBoolean('ShowOverview', true);
        $this->RegisterPropertyBoolean('ShowCreateButton', true);
        $this->RegisterPropertyBoolean('ShowSorting', true);
        $this->RegisterPropertyBoolean('UseGridView', false);
        $this->RegisterPropertyBoolean('ShowLargeQuantity', false);
        $this->RegisterPropertyBoolean('GridShoppingListMode', false);
        $this->RegisterPropertyBoolean('ShowInfoBadges', true);
        $this->RegisterPropertyBoolean('ShowDeleteButton', true);
        $this->RegisterPropertyBoolean('ShowEditButton', true);
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
        $this->RegisterAttributeString('MicrosoftPendingDeletes', '{}');
        $this->RegisterAttributeString('MicrosoftListOptions', '[]');
        $this->RegisterAttributeString('LastMicrosoftListID', '');

        $this->RegisterAttributeString('Items', '[]');
        $this->RegisterAttributeInteger('NextID', 1);
        $this->RegisterAttributeInteger('LastConfigFormRequest', 0);
        $this->RegisterAttributeString('LastItemsTableHash', '');
        $this->RegisterAttributeInteger('OrderVersion', 0);
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

        $this->RegisterVariableInteger('OpenTasks', $this->Translate('Open Tasks'), '', 1);
        $this->RegisterVariableInteger('OverdueTasks', $this->Translate('Overdue'), '', 2);
        $this->RegisterVariableInteger('DueTodayTasks', $this->Translate('Due Today'), '', 3);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        @$this->RegisterPropertyBoolean('AutoSyncOnChange', false);
        @$this->RegisterPropertyInteger('AutoSyncOnChangeDelay', 3);
        @$this->RegisterTimer('SyncOnChangeTimer', 0, 'TDL_SyncOnChange($_IPS[\'TARGET\']);');

        @$this->RegisterAttributeString('CalDAVPendingDeletes', '{}');
        @$this->RegisterAttributeInteger('SyncBackendMigrationDone', 0);

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
        $googleChanged = $this->SyncHandleListChange('GoogleTaskListID', 'LastGoogleTaskListID', 'GoogleLastSync', 'GooglePendingDeletes', 'GoogleTasks');
        $microsoftChanged = $this->SyncHandleListChange('MicrosoftListID', 'LastMicrosoftListID', 'MicrosoftLastSync', 'MicrosoftPendingDeletes', 'MicrosoftToDo');

        $this->UpdateRecurrenceTimer();
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

        $itemsTable = $this->ReadPropertyString('ItemsTable');
        $hash = md5($itemsTable);
        $lastHash = $this->ReadAttributeString('LastItemsTableHash');
        if ($hash !== $lastHash) {
            $this->SyncItemsFromConfiguration();
            $this->WriteAttributeString('LastItemsTableHash', $hash);
        }

        $this->UpdateStatistics();
        $this->UpdateTaskListHtml();
        $this->SendState();

        $this->ProcessNotifications();
        $this->ProcessRecurrences();
    }

    public function GetConfigurationForm(): string
    {
        $this->WriteAttributeInteger('LastConfigFormRequest', time());
        $items = $this->LoadItems();
        $values = $this->BuildItemsTableValues($items);

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
                    'caption' => $this->Translate('Show overview')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowCreateButton',
                    'caption' => $this->Translate('Show create button')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowSorting',
                    'caption' => $this->Translate('Show sorting')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'UseGridView',
                    'caption' => $this->Translate('Use grid view'),
                    'visible' => false
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowLargeQuantity',
                    'caption' => $this->Translate('Show large quantity'),
                    'visible' => false
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'GridShoppingListMode',
                    'caption' => $this->Translate('Grid shopping list mode'),
                    'visible' => false
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowInfoBadges',
                    'caption' => $this->Translate('Show info badges')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowDeleteButton',
                    'caption' => $this->Translate('Show delete button')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowEditButton',
                    'caption' => $this->Translate('Show edit button')
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
                    'type' => 'List',
                    'name' => 'ItemsTable',
                    'caption' => $this->Translate('Items'),
                    'rowCount' => 10,
                    'changeOrder' => true,
                    'add' => true,
                    'delete' => true,
                    'columns' => [
                        [
                            'caption' => $this->Translate('ID'),
                            'name' => 'id',
                            'width' => '60px',
                            'add' => 0,
                            'save' => true
                        ],
                        [
                            'caption' => $this->Translate('Done'),
                            'name' => 'done',
                            'width' => '90px',
                            'add' => false,
                            'edit' => [
                                'type' => 'CheckBox'
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Title'),
                            'name' => 'title',
                            'width' => '350px',
                            'add' => '',
                            'edit' => [
                                'type' => 'ValidationTextBox'
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Info'),
                            'name' => 'info',
                            'width' => 'auto',
                            'add' => '',
                            'edit' => [
                                'type' => 'ValidationTextBox'
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Notification'),
                            'name' => 'notification',
                            'width' => '120px',
                            'visible' => false,
                            'add' => false,
                            'edit' => [
                                'type' => 'CheckBox'
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Notification Lead Time'),
                            'name' => 'notificationLeadTime',
                            'width' => '200px',
                            'visible' => false,
                            'add' => 0,
                            'edit' => [
                                'type' => 'Select',
                                'options' => $this->GetNotificationLeadTimeOptions()
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Quantity'),
                            'name' => 'quantity',
                            'width' => '90px',
                            'visible' => false,
                            'add' => 0,
                            'edit' => [
                                'type' => 'NumberSpinner',
                                'minimum' => 0
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Due'),
                            'name' => 'due',
                            'width' => '140px',
                            'visible' => false,
                            'add' => json_encode($this->EmptySelectDateTime()),
                            'edit' => [
                                'type' => 'SelectDateTime'
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Repeat'),
                            'name' => 'recurrence',
                            'width' => '140px',
                            'visible' => false,
                            'add' => 'none',
                            'edit' => [
                                'type' => 'Select',
                                'options' => [
                                    ['caption' => $this->Translate('No repeat'), 'value' => 'none'],
                                    ['caption' => $this->Translate('Custom'), 'value' => 'custom'],
                                    ['caption' => $this->Translate('Every week'), 'value' => 'w1'],
                                    ['caption' => $this->Translate('Every 2 weeks'), 'value' => 'w2'],
                                    ['caption' => $this->Translate('Every 3 weeks'), 'value' => 'w3'],
                                    ['caption' => $this->Translate('Monthly'), 'value' => 'm1'],
                                    ['caption' => $this->Translate('Quarterly'), 'value' => 'q1'],
                                    ['caption' => $this->Translate('Yearly'), 'value' => 'y1']
                                ]
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Unit'),
                            'name' => 'recurrenceCustomUnit',
                            'width' => '120px',
                            'visible' => false,
                            'add' => 'w',
                            'edit' => [
                                'type' => 'Select',
                                'options' => $recurrenceUnitOptions
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Interval'),
                            'name' => 'recurrenceCustomValue',
                            'width' => '90px',
                            'visible' => false,
                            'add' => 1,
                            'edit' => [
                                'type' => 'NumberSpinner',
                                'minimum' => 1
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Reopen'),
                            'name' => 'recurrenceResetLeadTime',
                            'width' => '140px',
                            'visible' => false,
                            'add' => 172800,
                            'edit' => [
                                'type' => 'Select',
                                'options' => [
                                    ['caption' => $this->Translate('Disabled'), 'value' => 0],
                                    ['caption' => $this->Translate('30 minutes'), 'value' => 1800],
                                    ['caption' => $this->Translate('1 hour'), 'value' => 3600],
                                    ['caption' => $this->Translate('6 hours'), 'value' => 21600],
                                    ['caption' => $this->Translate('12 hours'), 'value' => 43200],
                                    ['caption' => $this->Translate('1 day before'), 'value' => 86400],
                                    ['caption' => $this->Translate('2 days before'), 'value' => 172800],
                                    ['caption' => $this->Translate('3 days before'), 'value' => 259200],
                                    ['caption' => $this->Translate('1 week before'), 'value' => 604800],
                                    ['caption' => $this->Translate('2 weeks before'), 'value' => 1209600],
                                    ['caption' => $this->Translate('1 month before'), 'value' => 2592000]
                                ]
                            ]
                        ],
                        [
                            'caption' => $this->Translate('Priority'),
                            'name' => 'priority',
                            'width' => '120px',
                            'visible' => false,
                            'add' => 'normal',
                            'edit' => [
                                'type' => 'Select',
                                'options' => [
                                    ['caption' => $this->Translate('Low'), 'value' => 'low'],
                                    ['caption' => $this->Translate('Normal'), 'value' => 'normal'],
                                    ['caption' => $this->Translate('High'), 'value' => 'high']
                                ]
                            ]
                        ]
                    ],
                    'form' => $this->GetItemsTableEditFormScript(),
                    'values' => $values,
                    'loadValuesFromConfiguration' => false
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
                $this->GetMicrosoftToDoFormElements($syncBackend)
            ]
        ];

        return json_encode($form);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'GetState':
                $this->SendState();
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
            case 'ItemsTableRecurrenceChanged':
                $this->UpdateItemsTableRecurrenceVisibility($Value);
                return;
            default:
                throw new Exception($this->Translate('Invalid Ident'));
        }
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
            IPS_LogMessage('ToDoList', 'GetVisualizationTile: module.html could not be loaded. path=' . $path . ' exists=' . $exists . ' readable=' . $readable . ' size=' . $size . ' err=' . $errMsg);
            return '';
        }
        if (strlen($html) < 200) {
            IPS_LogMessage('ToDoList', 'GetVisualizationTile: module.html loaded but is very short. bytes=' . strlen($html) . ' head=' . substr($html, 0, 80));
        }
        return $html;
    }

    public function Export(): string
    {
        return json_encode($this->LoadItems(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function DebugRecurrence(): string
    {
        $items = $this->LoadItems();
        $now = time();
        $interval = 60;
        $timerInterval = $this->GetTimerInterval('RecurrenceTimer');

        $debug = [
            'now' => date('Y-m-d H:i:s', $now),
            'nowTs' => $now,
            'timerActive' => $timerInterval > 0,
            'timerInterval' => $timerInterval,
            'items' => []
        ];

        foreach ($items as $item) {
            $due = (int)($item['due'] ?? 0);
            $recurrence = $this->NormalizeRecurrence($item['recurrence'] ?? 'none', $due);
            $leadTime = $this->NormalizeRecurrenceResetLeadTime($item['recurrenceResetLeadTime'] ?? null, $recurrence);
            $windowStart = $leadTime - $interval;
            $left = $due - $now;

            $wouldReopen = false;
            $reason = '';

            if (empty($item['done'])) {
                $reason = 'Task ist nicht erledigt (done=false)';
            } elseif ($due <= 0) {
                $reason = 'Keine Fälligkeit gesetzt (due=0)';
            } elseif ($recurrence === 'none') {
                $reason = 'Keine Wiederholung gesetzt (recurrence=none)';
            } elseif ($leadTime <= 0) {
                $reason = 'Wieder öffnen deaktiviert (recurrenceResetLeadTime=0)';
            } elseif ($left > $leadTime) {
                $reason = 'Noch nicht im Fenster (left > leadTime): ' . $left . ' > ' . $leadTime;
            } elseif ($left < $windowStart) {
                $reason = 'Fenster verpasst (left < windowStart): ' . $left . ' < ' . $windowStart . ' -> Due wird weitergeschoben';
            } else {
                $wouldReopen = true;
                $reason = 'WÜRDE JETZT WIEDER GEÖFFNET (left=' . $left . ', Fenster=' . $windowStart . '-' . $leadTime . ')';
            }

            $debug['items'][] = [
                'id' => $item['id'] ?? 0,
                'title' => $item['title'] ?? '',
                'done' => $item['done'] ?? false,
                'due' => $due > 0 ? date('Y-m-d H:i:s', $due) : 'nicht gesetzt',
                'dueTs' => $due,
                'recurrence' => $item['recurrence'] ?? 'none',
                'recurrenceNormalized' => $recurrence,
                'recurrenceResetLeadTime' => $item['recurrenceResetLeadTime'] ?? 'nicht gesetzt',
                'recurrenceResetLeadTimeNormalized' => $leadTime,
                'left' => $left,
                'windowStart' => $windowStart,
                'wouldReopen' => $wouldReopen,
                'reason' => $reason
            ];
        }

        return json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function AddItem(array $Data): int
    {
        $items = $this->LoadItems();

        $title = trim((string)($Data['title'] ?? ''));
        if ($title === '') {
            throw new Exception($this->Translate('Invalid title'));
        }

        $id = $this->ReadAttributeInteger('NextID');
        $this->WriteAttributeInteger('NextID', $id + 1);

        $now = time();
        $due = (int)($Data['due'] ?? 0);
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
            'recurrence' => $recurrence,
            'recurrenceCustomUnit' => $recurrenceCustomUnit,
            'recurrenceCustomValue' => $recurrenceCustomValue,
            'recurrenceResetLeadTime' => $recurrenceResetLeadTime,
            'priority'  => (string)($Data['priority'] ?? 'normal'),
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

        return $id;
    }

    public function UpdateItem(array $Data): void
    {
        $id = (int)($Data['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception($this->Translate('Invalid id'));
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
                        $this->AddGooglePendingDelete($googleId);
                    }
                }
                if ($this->GetSyncBackend() === 'microsoft') {
                    $msId = (string)($items[$i]['microsoftTaskId'] ?? '');
                    if ($msId !== '' && (int)($items[$i]['microsoftSynced'] ?? 0) > 0) {
                        $this->AddMicrosoftPendingDelete($msId);
                    }
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
                $resetNotify = $resetNotify || ((int)($items[$i]['due'] ?? 0) !== (int)$Data['due']);
                $items[$i]['due'] = (int)$Data['due'];
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

            if ($due > 0 && array_key_exists('notificationLeadTime', $items[$i])) {
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

    public function ToggleDone(array $Data): void
    {
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
                        $this->AddGooglePendingDelete($googleId);
                    }
                }
                if ($this->GetSyncBackend() === 'microsoft') {
                    $msId = (string)($items[$i]['microsoftTaskId'] ?? '');
                    if ($msId !== '' && (int)($items[$i]['microsoftSynced'] ?? 0) > 0) {
                        $this->AddMicrosoftPendingDelete($msId);
                    }
                }
                $uid = (string)($items[$i]['caldavUid'] ?? '');
                if ($uid !== '' && (int)($items[$i]['caldavSynced'] ?? 0) > 0) {
                    $pending = json_decode((string)$this->ReadAttributeString('CalDAVPendingDeletes'), true);
                    if (!is_array($pending)) {
                        $pending = [];
                    }
                    $pending[$uid] = (string)($items[$i]['caldavHref'] ?? '');
                    $this->WriteAttributeString('CalDAVPendingDeletes', json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                unset($items[$i]);
                $this->SaveItems(array_values($items));
                $this->ScheduleSyncOnChange();
                return;
            }

            $items[$i]['done'] = $newDone;
            if ($newDone && $recurrence !== 'none') {
                $due = (int)($items[$i]['due'] ?? 0);
                if ($due > 0) {
                    $unit = (string)($items[$i]['recurrenceCustomUnit'] ?? 'w');
                    $val = (int)($items[$i]['recurrenceCustomValue'] ?? 1);
                    $items[$i]['due'] = $this->GetNextDue($due, $recurrence, $unit, $val);
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
            if ($newDone) {
                $items[$i]['doneAt'] = $now;
            }
            break;
        }

        $this->SaveItems($items);
        $this->ScheduleSyncOnChange();
    }

    public function DeleteItem(array $Data): void
    {
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
                $pending[$uid] = (string)($deleted['caldavHref'] ?? '');
                $this->WriteAttributeString('CalDAVPendingDeletes', json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            if ($this->GetSyncBackend() === 'google') {
                $googleId = (string)($deleted['googleTaskId'] ?? '');
                if ($googleId !== '' && (int)($deleted['googleSynced'] ?? 0) > 0) {
                    $this->AddGooglePendingDelete($googleId);
                }
            }

            if ($this->GetSyncBackend() === 'microsoft') {
                $msId = (string)($deleted['microsoftTaskId'] ?? '');
                if ($msId !== '' && (int)($deleted['microsoftSynced'] ?? 0) > 0) {
                    $this->AddMicrosoftPendingDelete($msId);
                }
            }
        }

        $items = array_values($items);
        $this->SaveItems($items);
        $this->ScheduleSyncOnChange();
    }

    public function Reorder(array $Data): void
    {
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

    private function SyncItemsFromConfiguration(): void
    {
        $last = $this->ReadAttributeInteger('LastConfigFormRequest');
        if ($last <= 0 || (time() - $last) > 3600) {
            return;
        }

        $rows = json_decode($this->ReadPropertyString('ItemsTable'), true);
        if (!is_array($rows)) {
            return;
        }

        $itemsBefore = $this->LoadItems();
        $beforeIds = array_map(fn($it) => (int)($it['id'] ?? 0), $itemsBefore);

        $existing = [];
        foreach ($itemsBefore as $it) {
            $existing[(int)($it['id'] ?? 0)] = $it;
        }

        $nextID = $this->ReadAttributeInteger('NextID');
        $now = time();
        $items = [];
        $newItems = [];
        $deleteCompleted = $this->ReadPropertyBoolean('DeleteCompletedTasks');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($deleteCompleted && !empty($row['done'])) {
                continue;
            }

            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                $id = $nextID++;
            } else {
                $nextID = max($nextID, $id + 1);
            }

            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $prio = (string)($row['priority'] ?? 'normal');
            if (!in_array($prio, ['low', 'normal', 'high'], true)) {
                $prio = 'normal';
            }

            $createdAt = $now;
            if (isset($existing[$id]) && isset($existing[$id]['createdAt'])) {
                $createdAt = (int)$existing[$id]['createdAt'];
            }

            $old = $existing[$id] ?? [];
            $dueTs = $this->SelectDateTimeToTimestamp($row['due'] ?? null);
            $recurrence = $this->NormalizeRecurrence($row['recurrence'] ?? ($old['recurrence'] ?? 'none'), $dueTs);
            $recurrenceCustomUnit = (string)($old['recurrenceCustomUnit'] ?? 'w');
            $recurrenceCustomValue = (int)($old['recurrenceCustomValue'] ?? 1);
            if ($recurrence === 'custom') {
                $recurrenceCustomUnit = $this->NormalizeRecurrenceCustomUnit($row['recurrenceCustomUnit'] ?? $recurrenceCustomUnit);
                $recurrenceCustomValue = $this->NormalizeRecurrenceCustomValue($row['recurrenceCustomValue'] ?? $recurrenceCustomValue);
            }
            $notification = (bool)($row['notification'] ?? false);
            $defaultLeadTime = $this->NormalizeNotificationLeadTimeDefault((int)$this->ReadPropertyInteger('NotificationLeadTime'));
            $notificationLeadTime = (int)($old['notificationLeadTime'] ?? $defaultLeadTime);
            if ($notification && array_key_exists('notificationLeadTime', $row) && is_numeric($row['notificationLeadTime'])) {
                $notificationLeadTime = $this->NormalizeNotificationLeadTime($row['notificationLeadTime'], $defaultLeadTime);
            } else {
                $notificationLeadTime = $this->NormalizeNotificationLeadTime($notificationLeadTime, $defaultLeadTime);
            }

            $recurrenceResetLeadTime = $row['recurrenceResetLeadTime'] ?? ($old['recurrenceResetLeadTime'] ?? null);
            $recurrenceResetLeadTime = $this->NormalizeRecurrenceResetLeadTime($recurrenceResetLeadTime, $recurrence);
            $notifiedFor = (int)($old['notifiedFor'] ?? 0);
            if ((int)($old['due'] ?? 0) !== $dueTs || (bool)($old['notification'] ?? false) !== $notification || (int)($old['notificationLeadTime'] ?? $notificationLeadTime) !== $notificationLeadTime) {
                $notifiedFor = 0;
            }

            if ($dueTs > 0) {
                $limit = $this->GetLeadTimeLimitSeconds($dueTs, $now, $recurrence, $recurrenceCustomUnit, $recurrenceCustomValue);
                $notificationLeadTime = $this->ClampLeadTimeToLimit($notificationLeadTime, $limit, [0, 300, 600, 1800, 3600, 18000, 43200]);
            }

            if ($dueTs > 0 && $recurrence !== 'none') {
                $interval = $this->GetRecurrenceIntervalSeconds($dueTs, $recurrence, $recurrenceCustomUnit, $recurrenceCustomValue);
                $recurrenceResetLeadTime = $this->ClampLeadTimeToInterval($recurrenceResetLeadTime, $interval, [1800, 3600, 21600, 43200, 86400, 172800, 259200, 604800, 1209600, 2592000]);
            }

            if ($dueTs <= 0) {
                $notification = false;
                $recurrence = 'none';
                $recurrenceResetLeadTime = 0;
                $recurrenceCustomUnit = 'w';
                $recurrenceCustomValue = 1;
                $notifiedFor = 0;
            }

            $items[] = [
                'id'        => $id,
                'title'     => $title,
                'info'      => (string)($row['info'] ?? ''),
                'done'      => (bool)($row['done'] ?? false),
                'quantity'  => (int)($row['quantity'] ?? 0),
                'notification' => $notification,
                'notificationLeadTime' => $notificationLeadTime,
                'notifiedFor'  => $notifiedFor,
                'due'       => $dueTs,
                'recurrence' => $recurrence,
                'recurrenceCustomUnit' => $recurrenceCustomUnit,
                'recurrenceCustomValue' => $recurrenceCustomValue,
                'recurrenceResetLeadTime' => $recurrenceResetLeadTime,
                'priority'  => $prio,
                'createdAt' => $createdAt,
                'updatedAt' => $now
            ];
        }

        foreach ($items as $it) {
            $id = (int)($it['id'] ?? 0);
            if (isset($existing[$id])) {
                continue;
            }
            $newItems[] = $it;
        }
        if (count($newItems) > 0) {
            $existingItems = array_values(array_filter($items, fn($it) => isset($existing[(int)($it['id'] ?? 0)])));
            $items = array_merge($newItems, $existingItems);
        }

        $afterIds = array_map(fn($it) => (int)($it['id'] ?? 0), $items);
        $beforeSet = $beforeIds;
        $afterSet = $afterIds;
        sort($beforeSet);
        sort($afterSet);
        if ($beforeSet === $afterSet && $beforeIds !== $afterIds) {
            $this->WriteAttributeInteger('OrderVersion', $this->ReadAttributeInteger('OrderVersion') + 1);
        }

        $this->WriteAttributeInteger('NextID', $nextID);

        $beforeMap = [];
        foreach ($itemsBefore as $it) {
            $beforeMap[(int)($it['id'] ?? 0)] = $it;
        }
        $afterSet = array_fill_keys($afterIds, true);
        $pending = json_decode((string)$this->ReadAttributeString('CalDAVPendingDeletes'), true);
        if (!is_array($pending)) {
            $pending = [];
        }
        foreach ($beforeMap as $bid => $bit) {
            if (isset($afterSet[$bid])) {
                continue;
            }
            $uid = (string)($bit['caldavUid'] ?? '');
            if ($uid !== '' && (int)($bit['caldavSynced'] ?? 0) > 0) {
                $pending[$uid] = (string)($bit['caldavHref'] ?? '');
            }
        }
        $this->WriteAttributeString('CalDAVPendingDeletes', json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->SaveItems($items);
        $this->ScheduleSyncOnChange();
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

        $backend = $this->GetSyncBackend();
        if ($backend === 'google') {
            $this->GoogleTasksSync();
            return;
        }
        if ($backend === 'microsoft') {
            $this->MicrosoftToDoSync();
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
        $this->UpdateStatistics();
        $this->UpdateTaskListHtml($Items);
        $this->UpdateRecurrenceTimer($Items);
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
        $showInfoBadges = $this->ReadPropertyBoolean('ShowInfoBadges');
        $showLargeQty = $this->ReadPropertyBoolean('ShowLargeQuantity');
        $useGridView = $this->ReadPropertyBoolean('UseGridView');
        $shoppingMode = $useGridView && $this->ReadPropertyBoolean('GridShoppingListMode');

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
        $todayStart = strtotime('today');
        $todayEnd = $todayStart + 86400;
        foreach ($openItems as $it) {
            $open++;
            $due = (int)($it['due'] ?? 0);
            if ($due > 0) {
                if ($due < $todayStart) {
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

        $listClass = 'list' . ($useGridView ? ' grid' : '') . ($shoppingMode ? ' shopping' : '');

        $html = $cssBlock . '<div class="tdl-htmlbox wrap">';

        $html .= $statsHtml;
        $html .= '<div class="' . htmlspecialchars($listClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';

        foreach ($openItems as $it) {
            $html .= $this->BuildTaskRowHtml($it, false, $showInfoBadges, $showLargeQty, $useGridView, $shoppingMode, $todayStart, $todayEnd);
        }

        if (count($doneItems) > 0) {
            $html .= '<div class="section-header">' . htmlspecialchars($this->Translate('Completed'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
            foreach ($doneItems as $it) {
                $html .= $this->BuildTaskRowHtml($it, true, $showInfoBadges, $showLargeQty, $useGridView, $shoppingMode, $todayStart, $todayEnd);
            }
        }

        $html .= '</div></div>';
        return $html;
    }

    private function BuildTaskRowHtml(array $Item, bool $Done, bool $ShowInfoBadges, bool $ShowLargeQty, bool $UseGridView, bool $ShoppingMode, int $TodayStart, int $TodayEnd): string
    {
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

        $qtyLargeHtml = '';
        $qtyShoppingHtml = '';
        if ($qty > 0) {
            if ($ShowLargeQty) {
                $qtyLargeHtml = '<div class="quantity-large-wrap"><span class="badge quantity large-qty">' . $qty . '×</span></div>';
            } else {
                $qtyShoppingHtml = '<div class="quantity-large-wrap"><span class="badge quantity">' . $qty . '×</span></div>';
            }
        }

        $meta = [];
        if ($qty > 0 && (!$ShowLargeQty || $ShoppingMode)) {
            $meta[] = '<span class="badge quantity">' . $qty . '×</span>';
        }

        if ($ShowInfoBadges && $dueTs > 0) {
            $dueClass = '';
            if ($dueTs < $TodayStart) {
                $dueClass = ' due-overdue';
            } elseif ($dueTs >= $TodayStart && $dueTs < $TodayEnd) {
                $dueClass = ' due-today';
            }
            $dueText = htmlspecialchars(date('d.m.Y H:i', $dueTs), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $meta[] = '<span class="badge due-badge' . $dueClass . '" title="' . $dueText . '">' . $dueText . '</span>';
        }
        if ($ShowInfoBadges && $notification) {
            $bellSvg = '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><path d="M320 64C306.7 64 296 74.7 296 88L296 97.7C214.6 109.3 152 179.4 152 264L152 278.5C152 316.2 142 353.2 123 385.8L101.1 423.2C97.8 429 96 435.5 96 442.2C96 463.1 112.9 480 133.8 480L506.2 480C527.1 480 544 463.1 544 442.2C544 435.5 542.2 428.9 538.9 423.2L517 385.7C498 353.1 488 316.1 488 278.4L488 263.9C488 179.3 425.4 109.2 344 97.6L344 87.9C344 74.6 333.3 63.9 320 63.9zM488.4 432L151.5 432L164.4 409.9C187.7 370 200 324.6 200 278.5L200 264C200 197.7 253.7 144 320 144C386.3 144 440 197.7 440 264L440 278.5C440 324.7 452.3 370 475.5 409.9L488.4 432zM252.1 528C262 556 288.7 576 320 576C351.3 576 378 556 387.9 528L252.1 528z"/></svg>';
            $meta[] = '<span class="badge notify-badge" title="' . htmlspecialchars($this->Translate('Notification'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $bellSvg . '</span>';
        }
        if ($ShowInfoBadges && $recurrence !== 'none') {
            $rLabel = $this->GetRecurrenceLabel($recurrence, $recurrenceUnit, $recurrenceValue);
            $repeatSvg = '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><path d="M544.1 256L552 256C565.3 256 576 245.3 576 232L576 88C576 78.3 570.2 69.5 561.2 65.8C552.2 62.1 541.9 64.2 535 71L483.3 122.8C439 86.1 382 64 320 64C191 64 84.3 159.4 66.6 283.5C64.1 301 76.2 317.2 93.7 319.7C111.2 322.2 127.4 310 129.9 292.6C143.2 199.5 223.3 128 320 128C364.4 128 405.2 143 437.7 168.3L391 215C384.1 221.9 382.1 232.2 385.8 241.2C389.5 250.2 398.3 256 408 256L544.1 256zM573.5 356.5C576 339 563.8 322.8 546.4 320.3C529 317.8 512.7 330 510.2 347.4C496.9 440.4 416.8 511.9 320.1 511.9C275.7 511.9 234.9 496.9 202.4 471.6L249 425C255.9 418.1 257.9 407.8 254.2 398.8C250.5 389.8 241.7 384 232 384L88 384C74.7 384 64 394.7 64 408L64 552C64 561.7 69.8 570.5 78.8 574.2C87.8 577.9 98.1 575.8 105 569L156.8 517.2C201 553.9 258 576 320 576C449 576 555.7 480.6 573.4 356.5z"/></svg>';
            $meta[] = '<span class="badge recur-badge" title="' . htmlspecialchars($rLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $repeatSvg . '</span>';
        }
        if ($ShowInfoBadges && !$ShoppingMode) {
            $meta[] = '<span class="badge ' . htmlspecialchars($prio, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($this->GetPriorityLabel($prio), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        }

        $metaHtml = '<div class="meta">' . implode('', $meta) . '</div>';

        $rowClass = 'item' . ($Done ? ' done' : '');
        return '<div class="' . $rowClass . '">' .
            '<div class="main">' .
                '<div class="content">' .
                    '<div class="title">' . $title . '</div>' .
                    $infoHtml .
                    ($ShoppingMode ? $qtyShoppingHtml : $qtyLargeHtml) .
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

    private function BuildItemsTableValues(array $Items): array
    {
        $values = [];
        foreach ($Items as $it) {
            $due = (int)($it['due'] ?? 0);
            $notification = (bool)($it['notification'] ?? false);
            $values[] = [
                'id'       => (int)($it['id'] ?? 0),
                'done'     => (bool)($it['done'] ?? false),
                'title'    => (string)($it['title'] ?? ''),
                'info'     => (string)($it['info'] ?? ''),
                'notification' => $notification,
                'notificationLeadTime' => (int)($it['notificationLeadTime'] ?? 0),
                'quantity' => (int)($it['quantity'] ?? 0),
                'due'      => json_encode($this->TimestampToSelectDateTime($due)),
                'recurrence' => (string)($it['recurrence'] ?? 'none'),
                'recurrenceCustomUnit' => (string)($it['recurrenceCustomUnit'] ?? 'w'),
                'recurrenceCustomValue' => (int)($it['recurrenceCustomValue'] ?? 1),
                'recurrenceResetLeadTime' => (int)($it['recurrenceResetLeadTime'] ?? 172800),
                'priority' => (string)($it['priority'] ?? 'normal')
            ];
        }
        return $values;
    }

    private function GetItemsTableEditFormScript(): string
    {
        $tID = json_encode($this->Translate('ID'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tDone = json_encode($this->Translate('Done'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tTitle = json_encode($this->Translate('Title'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tInfo = json_encode($this->Translate('Info'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tNotification = json_encode($this->Translate('Notification'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tNotificationLeadTime = json_encode($this->Translate('Notification Lead Time'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tQuantity = json_encode($this->Translate('Quantity'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tDue = json_encode($this->Translate('Due'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tRepeat = json_encode($this->Translate('Repeat'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tReopen = json_encode($this->Translate('Reopen'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tPriority = json_encode($this->Translate('Priority'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $tNoRepeat = json_encode($this->Translate('No repeat'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tCustom = json_encode($this->Translate('Custom'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tEveryWeek = json_encode($this->Translate('Every week'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tEvery2Weeks = json_encode($this->Translate('Every 2 weeks'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tEvery3Weeks = json_encode($this->Translate('Every 3 weeks'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tMonthly = json_encode($this->Translate('Monthly'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tQuarterly = json_encode($this->Translate('Quarterly'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tYearly = json_encode($this->Translate('Yearly'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $tUnit = json_encode($this->Translate('Unit'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tInterval = json_encode($this->Translate('Interval'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tHours = json_encode($this->Translate('Hours'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tDays = json_encode($this->Translate('Days'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tWeeks = json_encode($this->Translate('Weeks'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tMonths = json_encode($this->Translate('Months'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tYears = json_encode($this->Translate('Years'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $tDisabled = json_encode($this->Translate('Disabled'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tImmediate = json_encode($this->Translate('Immediate'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t1DayBefore = json_encode($this->Translate('1 day before'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t2DaysBefore = json_encode($this->Translate('2 days before'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t3DaysBefore = json_encode($this->Translate('3 days before'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t1WeekBefore = json_encode($this->Translate('1 week before'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t2WeeksBefore = json_encode($this->Translate('2 weeks before'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t1MonthBefore = json_encode($this->Translate('1 month before'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $tLow = json_encode($this->Translate('Low'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tNormal = json_encode($this->Translate('Normal'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tHigh = json_encode($this->Translate('High'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $t0Min = json_encode($this->Translate('0 minutes'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t5Min = json_encode($this->Translate('5 minutes'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t10Min = json_encode($this->Translate('10 minutes'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t30Min = json_encode($this->Translate('30 minutes'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t1H = json_encode($this->Translate('1 hour'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t5H = json_encode($this->Translate('5 hours'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t6H = json_encode($this->Translate('6 hours'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t12H = json_encode($this->Translate('12 hours'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return '$recurrenceValue = (string)($ItemsTable[\'recurrence\'] ?? \'none\');' . PHP_EOL .
            '$showReopen = $recurrenceValue !== \'none\';' . PHP_EOL .
            '$showCustom = $recurrenceValue === \'custom\';' . PHP_EOL .
            'return [' . PHP_EOL .
            '  [\'type\' => \'NumberSpinner\', \'name\' => \'id\', \'caption\' => ' . $tID . ', \'visible\' => false, \'enabled\' => false],' . PHP_EOL .
            '  [\'type\' => \'CheckBox\', \'name\' => \'done\', \'caption\' => ' . $tDone . '],' . PHP_EOL .
            '  [\'type\' => \'ValidationTextBox\', \'name\' => \'title\', \'caption\' => ' . $tTitle . '],' . PHP_EOL .
            '  [\'type\' => \'ValidationTextBox\', \'name\' => \'info\', \'caption\' => ' . $tInfo . '],' . PHP_EOL .
            '  [\'type\' => \'NumberSpinner\', \'name\' => \'quantity\', \'caption\' => ' . $tQuantity . ', \'minimum\' => 0],' . PHP_EOL .
            '  [\'type\' => \'Select\', \'name\' => \'priority\', \'caption\' => ' . $tPriority . ', \'options\' => [' . PHP_EOL .
            '    [\'caption\' => ' . $tLow . ', \'value\' => \'low\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tNormal . ', \'value\' => \'normal\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tHigh . ', \'value\' => \'high\']' . PHP_EOL .
            '  ]],' . PHP_EOL .
            '  [\'type\' => \'SelectDateTime\', \'name\' => \'due\', \'caption\' => ' . $tDue . '],' . PHP_EOL .
            '  [\'type\' => \'Select\', \'name\' => \'recurrence\', \'caption\' => ' . $tRepeat . ', \'onChange\' => \'IPS_RequestAction(' . $this->InstanceID . ', "ItemsTableRecurrenceChanged", $recurrence);\', \'options\' => [' . PHP_EOL .
            '    [\'caption\' => ' . $tNoRepeat . ', \'value\' => \'none\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tCustom . ', \'value\' => \'custom\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tEveryWeek . ', \'value\' => \'w1\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tEvery2Weeks . ', \'value\' => \'w2\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tEvery3Weeks . ', \'value\' => \'w3\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tMonthly . ', \'value\' => \'m1\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tQuarterly . ', \'value\' => \'q1\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tYearly . ', \'value\' => \'y1\']' . PHP_EOL .
            '  ]],' . PHP_EOL .
            '  [\'type\' => \'Select\', \'name\' => \'recurrenceCustomUnit\', \'caption\' => ' . $tUnit . ', \'visible\' => $showCustom, \'options\' => [' . PHP_EOL .
            '    [\'caption\' => ' . $tHours . ', \'value\' => \'h\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tDays . ', \'value\' => \'d\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tWeeks . ', \'value\' => \'w\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tMonths . ', \'value\' => \'m\'],' . PHP_EOL .
            '    [\'caption\' => ' . $tYears . ', \'value\' => \'y\']' . PHP_EOL .
            '  ]],' . PHP_EOL .
            '  [\'type\' => \'NumberSpinner\', \'name\' => \'recurrenceCustomValue\', \'caption\' => ' . $tInterval . ', \'visible\' => $showCustom, \'minimum\' => 1],' . PHP_EOL .
            '  [\'type\' => \'Select\', \'name\' => \'recurrenceResetLeadTime\', \'caption\' => ' . $tReopen . ', \'visible\' => $showReopen, \'options\' => [' . PHP_EOL .
            '    [\'caption\' => ' . $tDisabled . ', \'value\' => 0],' . PHP_EOL .
            '    [\'caption\' => ' . $tImmediate . ', \'value\' => -1],' . PHP_EOL .
            '    [\'caption\' => ' . $t30Min . ', \'value\' => 1800],' . PHP_EOL .
            '    [\'caption\' => ' . $t1H . ', \'value\' => 3600],' . PHP_EOL .
            '    [\'caption\' => ' . $t6H . ', \'value\' => 21600],' . PHP_EOL .
            '    [\'caption\' => ' . $t12H . ', \'value\' => 43200],' . PHP_EOL .
            '    [\'caption\' => ' . $t1DayBefore . ', \'value\' => 86400],' . PHP_EOL .
            '    [\'caption\' => ' . $t2DaysBefore . ', \'value\' => 172800],' . PHP_EOL .
            '    [\'caption\' => ' . $t3DaysBefore . ', \'value\' => 259200],' . PHP_EOL .
            '    [\'caption\' => ' . $t1WeekBefore . ', \'value\' => 604800],' . PHP_EOL .
            '    [\'caption\' => ' . $t2WeeksBefore . ', \'value\' => 1209600],' . PHP_EOL .
            '    [\'caption\' => ' . $t1MonthBefore . ', \'value\' => 2592000]' . PHP_EOL .
            '  ]],' . PHP_EOL .
            '  [\'type\' => \'CheckBox\', \'name\' => \'notification\', \'caption\' => ' . $tNotification . '],' . PHP_EOL .
            '  [\'type\' => \'Select\', \'name\' => \'notificationLeadTime\', \'caption\' => ' . $tNotificationLeadTime . ', \'options\' => [' . PHP_EOL .
            '    [\'caption\' => ' . $t0Min . ', \'value\' => 0],' . PHP_EOL .
            '    [\'caption\' => ' . $t5Min . ', \'value\' => 300],' . PHP_EOL .
            '    [\'caption\' => ' . $t10Min . ', \'value\' => 600],' . PHP_EOL .
            '    [\'caption\' => ' . $t30Min . ', \'value\' => 1800],' . PHP_EOL .
            '    [\'caption\' => ' . $t1H . ', \'value\' => 3600],' . PHP_EOL .
            '    [\'caption\' => ' . $t5H . ', \'value\' => 18000],' . PHP_EOL .
            '    [\'caption\' => ' . $t12H . ', \'value\' => 43200]' . PHP_EOL .
            '  ]]' . PHP_EOL .
            '];';
    }

    private function UpdateItemsTableRecurrenceVisibility(mixed $RecurrenceValue): void
    {
        $recurrence = (string)$RecurrenceValue;
        $showReopen = $recurrence !== 'none';
        $showCustom = $recurrence === 'custom';
        $this->UpdateFormField('recurrenceResetLeadTime', 'visible', $showReopen);
        $this->UpdateFormField('recurrenceCustomUnit', 'visible', $showCustom);
        $this->UpdateFormField('recurrenceCustomValue', 'visible', $showCustom);
        if (!$showReopen) {
            $this->UpdateFormField('recurrenceResetLeadTime', 'value', 0);
        }
        if (!$showCustom) {
            $this->UpdateFormField('recurrenceCustomUnit', 'value', 'w');
            $this->UpdateFormField('recurrenceCustomValue', 'value', 1);
        }
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

    private function SyncItemsTableFormValues(): void
    {
        $last = $this->ReadAttributeInteger('LastConfigFormRequest');
        if ($last <= 0 || (time() - $last) > 3600) {
            return;
        }

        $this->UpdateFormField('ItemsTable', 'values', json_encode($this->BuildItemsTableValues($this->LoadItems())));
    }

    private function UpdateStatistics(): void
    {
        $items = $this->LoadItems();
        $todayStart = strtotime('today');
        $todayEnd = $todayStart + 86400;

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
                if ($due < $todayStart) {
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

            $leadTime = $this->NormalizeRecurrenceResetLeadTime($item['recurrenceResetLeadTime'] ?? null, $recurrence);
            if ($leadTime === -1) {
                if ($due <= $now) {
                    $unit = (string)($item['recurrenceCustomUnit'] ?? 'w');
                    $val = (int)($item['recurrenceCustomValue'] ?? 1);
                    $newDue = $this->GetNextDue($due, $recurrence, $unit, $val);
                    $guard = 0;
                    while ($newDue > 0 && $newDue <= $now && $guard < 24) {
                        $newDue = $this->GetNextDue($newDue, $recurrence, $unit, $val);
                        $guard++;
                    }
                    if ($newDue !== $due) {
                        $item['due'] = $newDue;
                    }
                }
                $item['done'] = false;
                $item['notifiedFor'] = 0;
                $item['updatedAt'] = $now;
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
                $changed = true;
                continue;
            }

            if ($left < $windowStart) {
                $unit = (string)($item['recurrenceCustomUnit'] ?? 'w');
                $val = (int)($item['recurrenceCustomValue'] ?? 1);
                $newDue = $this->GetNextDue($due, $recurrence, $unit, $val);
                $guard = 0;
                while ($newDue > 0 && $newDue <= $now && $guard < 24) {
                    $newDue = $this->GetNextDue($newDue, $recurrence, $unit, $val);
                    $guard++;
                }
                if ($newDue !== $due) {
                    $item['due'] = $newDue;
                    $item['notifiedFor'] = 0;
                    $item['updatedAt'] = $now;
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

    private function GetNextDue(int $Due, string $Recurrence, string $CustomUnit = '', int $CustomValue = 0): int
    {
        if ($Due <= 0) {
            return 0;
        }
        $r = $this->NormalizeRecurrence($Recurrence, $Due);
        switch ($r) {
            case 'custom':
                $u = $this->NormalizeRecurrenceCustomUnit($CustomUnit);
                $v = $this->NormalizeRecurrenceCustomValue($CustomValue);
                switch ($u) {
                    case 'h':
                        return $Due + (3600 * $v);
                    case 'd':
                        return $Due + (86400 * $v);
                    case 'w':
                        return $Due + (604800 * $v);
                    case 'm':
                        return $this->AddMonthsClamped($Due, $v);
                    case 'y':
                        return $this->AddMonthsClamped($Due, 12 * $v);
                    default:
                        return $Due;
                }
            case 'w1':
                return $Due + 604800;
            case 'w2':
                return $Due + 1209600;
            case 'w3':
                return $Due + 1814400;
            case 'm1':
                return $this->AddMonthsClamped($Due, 1);
            case 'q1':
                return $this->AddMonthsClamped($Due, 3);
            case 'y1':
                return $this->AddMonthsClamped($Due, 12);
            default:
                return $Due;
        }
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

    private function SendState(): void
    {
        $sort = $this->GetSortPrefs();
        $this->UpdateVisualizationValue(json_encode([
            'type'  => 'state',
            'items' => $this->LoadItems(),
            'notificationLeadTimeDefault' => $this->ReadPropertyInteger('NotificationLeadTime'),
            'syncBackend' => $this->GetSyncBackend(),
            'sortMode' => $sort['mode'],
            'sortDir'  => $sort['dir'],
            'orderVersion' => $this->ReadAttributeInteger('OrderVersion'),
            'showOverview' => $this->ReadPropertyBoolean('ShowOverview'),
            'showCreateButton' => $this->ReadPropertyBoolean('ShowCreateButton'),
            'showSorting' => $this->ReadPropertyBoolean('ShowSorting'),
            'useGridView' => $this->ReadPropertyBoolean('UseGridView'),
            'showLargeQuantity' => $this->ReadPropertyBoolean('ShowLargeQuantity'),
            'gridShoppingListMode' => $this->ReadPropertyBoolean('GridShoppingListMode'),
            'showInfoBadges' => $this->ReadPropertyBoolean('ShowInfoBadges'),
            'showDeleteButton' => $this->ReadPropertyBoolean('ShowDeleteButton'),
            'showEditButton' => $this->ReadPropertyBoolean('ShowEditButton'),
            'hideCompletedTasks' => $this->ReadPropertyBoolean('HideCompletedTasks'),
            'deleteCompletedTasks' => $this->ReadPropertyBoolean('DeleteCompletedTasks')
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

        $migrationDone = (int)$this->ReadAttributeInteger('SyncBackendMigrationDone');
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
            $this->WriteAttributeInteger('SyncBackendMigrationDone', 1);
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
            IPS_ApplyChanges($this->InstanceID);
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

}
