<?php

declare(strict_types=1);

trait GoogleTasksSync
{
    private function UpdateGoogleTasksTimer(): void
    {
        $gw = $this->GetGatewayID();
        $hasToken = $gw > 0 && TGW_GoogleIsConnected($gw);
        $this->SyncUpdateTimer('google', 'GoogleTasksSyncTimer', $this->ReadPropertyInteger('GoogleSyncInterval'), $hasToken);
    }

    private function GoogleApiRequest(string $Method, string $Endpoint, mixed $Body = null): ?array
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            $this->SendDebug('GoogleTasks', 'No ToDoGateway connected', 0);
            return null;
        }
        return TGW_GoogleApiRequest($gw, $Method, $Endpoint, $Body);
    }

    public function GoogleRefreshTaskListOptions(): void
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0 || !TGW_GoogleIsConnected($gw)) {
            echo $this->Translate('Not connected to Google. Please authorize first.');
            return;
        }

        $stored = $this->GoogleFetchAndStoreTaskListOptions();
        if ($stored === null) {
            echo $this->Translate('Failed to fetch task lists.');
            return;
        }

        $options = $this->GetGoogleTaskListOptions();
        $this->UpdateFormField('GoogleTaskListID', 'options', json_encode($options));
        echo sprintf($this->Translate('Found %d task list(s).'), count($stored));
    }

    public function GoogleDiscoverTasklists(): string
    {
        ob_start();
        $this->GoogleRefreshTaskListOptions();
        return (string)ob_get_clean();
    }

    private function GoogleSanitizeTaskListTitle(string $Title): string
    {
        $title = trim($Title);
        if ($title === '') {
            return $title;
        }

        $title = preg_replace('/[\x{FE0F}\x{200D}]/u', '', $title) ?? $title;
        $title = preg_replace('/[\x{2600}-\x{27BF}]/u', '', $title) ?? $title;
        $title = preg_replace('/[\x{1F000}-\x{1FAFF}]/u', '', $title) ?? $title;
        $title = preg_replace('/\s{2,}/u', ' ', $title) ?? $title;

        return trim($title);
    }

    private function GoogleFetchAndStoreTaskListOptions(): ?array
    {
        $data = $this->GoogleApiRequest('GET', '/tasks/v1/users/@me/lists');
        if ($data === null) {
            return null;
        }

        $items = $data['items'] ?? [];
        $stored = [];
        foreach ($items as $list) {
            if (!is_array($list)) {
                continue;
            }
            $id = (string)($list['id'] ?? '');
            $title = $this->GoogleSanitizeTaskListTitle((string)($list['title'] ?? 'Untitled'));
            if ($id !== '') {
                $stored[] = ['id' => $id, 'title' => $title];
            }
        }

        $this->WriteAttributeString('GoogleTaskListOptions', json_encode($stored));
        return $stored;
    }

    private function GetGoogleTaskListOptions(): array
    {
        $options = [['caption' => $this->Translate('Please select...'), 'value' => '']];

        $stored = json_decode($this->ReadAttributeString('GoogleTaskListOptions'), true);
        if (is_array($stored)) {
            foreach ($stored as $item) {
                $options[] = [
                    'caption' => $item['title'] ?? 'Untitled',
                    'value' => $item['id'] ?? ''
                ];
            }
        }

        $currentId = $this->ReadPropertyString('GoogleTaskListID');
        if ($currentId !== '') {
            $found = false;
            foreach ($options as $opt) {
                if ($opt['value'] === $currentId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $options[] = ['caption' => $currentId, 'value' => $currentId];
            }
        }

        return $options;
    }

    public function GoogleTestConnection(): bool
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            echo $this->Translate('Not connected. Please authorize first.');
            return false;
        }
        return TGW_GoogleTestConnection($gw);
    }

    public function GoogleTasksSync(): bool
    {
        $sem = 'TDL_GoogleSync_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 0)) {
            $this->SendDebug('GoogleTasks', 'Sync skipped - already running', 0);
            return false;
        }

        try {
            return $this->GoogleTasksSyncInternal();
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    public function GoogleResetSync(): void
    {
        $this->SyncResetItems(
            ['googleTaskId'],
            ['googleEtag'],
            ['googleSynced'],
            'GoogleLastSync',
            'GooglePendingDeletes'
        );
    }

    private function GoogleTasksSyncInternal(): bool
    {
        if ($this->GetSyncBackend() !== 'google') {
            $this->SendDebug('GoogleTasks', 'Sync skipped - not enabled', 0);
            return false;
        }

        $taskListId = trim($this->ReadPropertyString('GoogleTaskListID'));
        if ($taskListId === '') {
            $this->SendDebug('GoogleTasks', 'Sync skipped - no task list selected', 0);
            return false;
        }

        $gw = $this->GetGatewayID();
        if ($gw === 0 || !TGW_GoogleIsConnected($gw)) {
            $this->SendDebug('GoogleTasks', 'Sync skipped - not authenticated', 0);
            return false;
        }

        $this->SendDebug('GoogleTasks', 'Starting sync...', 0);

        $pendingDeletes = json_decode((string)$this->ReadAttributeString('GooglePendingDeletes'), true);
        if (!is_array($pendingDeletes)) {
            $pendingDeletes = [];
        }
        $this->SyncProcessPendingDeletes($pendingDeletes, fn($id) => $this->GoogleDeleteTask($taskListId, $id), 'GoogleTasks');
        $this->WriteAttributeString('GooglePendingDeletes', json_encode($pendingDeletes, JSON_UNESCAPED_SLASHES));

        $serverTasks = $this->GoogleFetchTasks($taskListId);
        if ($serverTasks === null) {
            $this->SendDebug('GoogleTasks', 'Failed to fetch tasks from Google', 0);
            return false;
        }

        $localItems = $this->LoadItems();
        $conflictMode = $this->ReadPropertyString('GoogleConflictMode');
        $result = $this->GoogleMergeItems($localItems, $serverTasks, $conflictMode);

        $items = $result['items'];
        $now = time();

        foreach ($result['toUpload'] as $uploadItem) {
            $uploadResult = $this->GoogleUploadTask($taskListId, $uploadItem);
            if ($uploadResult['success']) {
                $oldId = $uploadResult['oldId'];
                $newId = $uploadResult['newId'];
                for ($i = 0; $i < count($items); $i++) {
                    if (($items[$i]['googleTaskId'] ?? '') === $oldId || ($items[$i]['id'] ?? 0) === ($uploadItem['id'] ?? -1)) {
                        $items[$i]['googleTaskId'] = $newId;
                        $items[$i]['googleEtag'] = $uploadResult['etag'];
                        $items[$i]['googleSynced'] = $now;
                        $items[$i]['localModified'] = 0;
                        break;
                    }
                }
                $this->SendDebug('GoogleTasks', 'Uploaded: ' . ($uploadItem['title'] ?? $newId), 0);
            }
        }

        foreach ($items as &$item) {
            if (!empty($item['googleTaskId']) && ($item['googleSynced'] ?? 0) === 0) {
                $item['googleSynced'] = $now;
            }
        }
        unset($item);

        $this->SaveItems($items);
        $this->WriteAttributeInteger('GoogleLastSync', $now);
        $this->SyncPostComplete();

        $this->SendDebug('GoogleTasks', 'Sync completed', 0);
        return true;
    }

    private function GoogleFetchTasks(string $TaskListId): ?array
    {
        $allTasks = [];
        $pageToken = '';

        do {
            $endpoint = '/tasks/v1/lists/' . urlencode($TaskListId) . '/tasks?showCompleted=true&showHidden=true&showDeleted=true&maxResults=100';
            if ($pageToken !== '') {
                $endpoint .= '&pageToken=' . urlencode($pageToken);
            }

            $data = $this->GoogleApiRequest('GET', $endpoint);
            if ($data === null) {
                return null;
            }

            foreach ($data['items'] ?? [] as $task) {
                if (!empty($task['deleted'])) {
                    continue;
                }
                $this->SendDebug('GoogleTasksPayload', json_encode($task, JSON_UNESCAPED_SLASHES), 0);
                $allTasks[] = $this->GoogleTaskToLocal($task);
            }

            $pageToken = $data['nextPageToken'] ?? '';
        } while ($pageToken !== '');

        return $allTasks;
    }

    private function GoogleTaskToLocal(array $Task): array
    {
        $done = ($Task['status'] ?? '') === 'completed';
        $doneAt = 0;
        if ($done && isset($Task['completed'])) {
            $doneAt = strtotime($Task['completed']) ?: 0;
        }

        $due = 0;
        if (isset($Task['due'])) {
            $dueStr = (string) $Task['due'];
            if (preg_match('/^(\d{4}-\d{2}-\d{2})T00:00:00(\.000)?Z$/', $dueStr, $m)) {
                $due = strtotime($m[1] . ' 00:00:00') ?: 0;
            } else {
                $dt = new DateTime($dueStr);
                $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                $due = $dt->getTimestamp();
            }
        }

        $updated = 0;
        if (isset($Task['updated'])) {
            $updated = strtotime($Task['updated']) ?: 0;
        }

        return [
            'googleTaskId' => $Task['id'] ?? '',
            'googleEtag' => $Task['etag'] ?? '',
            'title' => $Task['title'] ?? '',
            'info' => $Task['notes'] ?? '',
            'done' => $done,
            'doneAt' => $doneAt,
            'due' => $due,
            'googleUpdated' => $updated
        ];
    }

    private function LocalToGoogleTask(array $Item): array
    {
        $task = [
            'title' => $Item['title'] ?? '',
            'notes' => $Item['info'] ?? '',
            'status' => ($Item['done'] ?? false) ? 'completed' : 'needsAction'
        ];

        $due = (int)($Item['due'] ?? 0);
        if ($due > 0) {
            $localDate = date('Y-m-d', $due);
            $task['due'] = $localDate . 'T00:00:00.000Z';
        }

        return $task;
    }

    private function MergeDueWithLocalTime(int $localDue, int $serverDue): int
    {
        if ($serverDue === 0) {
            return 0;
        }
        if ($localDue === 0) {
            return $serverDue;
        }

        $serverDate = date('Y-m-d', $serverDue);
        $localTime = date('H:i:s', $localDue);
        return strtotime($serverDate . ' ' . $localTime) ?: $serverDue;
    }

    private function GoogleMergeItems(array $LocalItems, array $ServerTasks, string $ConflictMode): array
    {
        $toUpload = [];
        $serverByGoogleId = [];
        foreach ($ServerTasks as $st) {
            $gid = $st['googleTaskId'] ?? '';
            if ($gid !== '') {
                $serverByGoogleId[$gid] = $st;
            }
        }

        $processedGoogleIds = [];

        foreach ($LocalItems as &$local) {
            $googleId = $local['googleTaskId'] ?? '';

            if ($googleId === '') {
                $newGoogleId = 'pending_' . $this->InstanceID . '_' . ($local['id'] ?? uniqid());
                $local['googleTaskId'] = $newGoogleId;
                $local['localModified'] = time();
                $toUpload[] = $local;
                continue;
            }

            $processedGoogleIds[$googleId] = true;

            if (!isset($serverByGoogleId[$googleId])) {
                $lastSynced = (int)($local['googleSynced'] ?? 0);
                $localMod = (int)($local['localModified'] ?? 0);
                $localChanged = $localMod > $lastSynced;

                if (strpos($googleId, 'pending_') !== 0) {
                    if ($ConflictMode === 'local_wins' && $localChanged) {
                        $newGoogleId = 'pending_' . $this->InstanceID . '_' . ($local['id'] ?? uniqid());
                        $local['googleTaskId'] = $newGoogleId;
                        $local['localModified'] = time();
                        $toUpload[] = $local;
                    } else {
                        $local['_googleDeleted'] = true;
                    }
                }
                continue;
            }

            $server = $serverByGoogleId[$googleId];
            $localMod = (int)($local['localModified'] ?? 0);
            $serverMod = (int)($server['googleUpdated'] ?? 0);
            $lastSynced = (int)($local['googleSynced'] ?? 0);

            $localChanged = $localMod > $lastSynced;
            $serverChanged = $serverMod > $lastSynced;

            if ($localChanged && $serverChanged) {
                $localWins = ($ConflictMode === 'local_wins') || ($ConflictMode === 'newest_wins' && $localMod > $serverMod);

                if ($localWins) {
                    $toUpload[] = $local;
                } else {
                    $this->GoogleApplyServerToLocal($local, $server);
                }
            } elseif ($localChanged) {
                $toUpload[] = $local;
            } else {
                $this->GoogleApplyServerToLocal($local, $server);
            }
        }
        unset($local);

        $filtered = [];
        foreach ($LocalItems as $it) {
            if (!empty($it['_googleDeleted'])) {
                continue;
            }
            unset($it['_googleDeleted']);
            $filtered[] = $it;
        }
        $LocalItems = $filtered;

        foreach ($ServerTasks as $server) {
            $pendingDeletes = json_decode((string)$this->ReadAttributeString('GooglePendingDeletes'), true);
            if (!is_array($pendingDeletes)) {
                $pendingDeletes = [];
            }
            $gid = $server['googleTaskId'] ?? '';
            if ($gid === '' || isset($processedGoogleIds[$gid])) {
                continue;
            }

            if (isset($pendingDeletes[$gid])) {
                continue;
            }

            $newItem = [
                'id' => $this->GetNextItemID(),
                'title' => $server['title'],
                'info' => $server['info'],
                'done' => $server['done'],
                'doneAt' => $server['doneAt'],
                'due' => $server['due'],
                'createdAt' => time(),
                'priority' => 'normal',
                'notification' => false,
                'quantity' => 0,
                'recurrence' => 'none',
                'googleTaskId' => $gid,
                'googleEtag' => $server['googleEtag'],
                'googleSynced' => time(),
                'localModified' => 0
            ];
            $LocalItems[] = $newItem;
        }

        return [
            'items' => $LocalItems,
            'toUpload' => $toUpload
        ];
    }

    private function GoogleApplyServerToLocal(array &$Local, array $Server): void
    {
        $Local['title'] = $Server['title'];
        $Local['info'] = $Server['info'];
        $Local['done'] = $Server['done'];
        $Local['doneAt'] = $Server['doneAt'];
        $Local['due'] = $this->MergeDueWithLocalTime((int)($Local['due'] ?? 0), (int)($Server['due'] ?? 0));
        $Local['googleEtag'] = $Server['googleEtag'];
        $Local['localModified'] = 0;
    }

    private function GoogleUploadTask(string $TaskListId, array $Item): array
    {
        $googleId = $Item['googleTaskId'] ?? '';
        $taskData = $this->LocalToGoogleTask($Item);

        if ($googleId === '' || strpos($googleId, 'pending_') === 0) {
            $data = $this->GoogleApiRequest('POST', '/tasks/v1/lists/' . urlencode($TaskListId) . '/tasks', $taskData);
            if ($data === null) {
                return ['success' => false, 'oldId' => $googleId, 'newId' => '', 'etag' => ''];
            }

            return [
                'success' => true,
                'oldId' => $googleId,
                'newId' => $data['id'] ?? '',
                'etag' => $data['etag'] ?? ''
            ];
        }

        $data = $this->GoogleApiRequest('PATCH', '/tasks/v1/lists/' . urlencode($TaskListId) . '/tasks/' . urlencode($googleId), $taskData);
        return [
            'success' => $data !== null,
            'oldId' => $googleId,
            'newId' => $googleId,
            'etag' => $data['etag'] ?? ''
        ];
    }

    private function GoogleDeleteTask(string $TaskListId, string $GoogleTaskId): bool
    {
        if ($GoogleTaskId === '' || strpos($GoogleTaskId, 'pending_') === 0) {
            return true;
        }

        $data = $this->GoogleApiRequest('DELETE', '/tasks/v1/lists/' . urlencode($TaskListId) . '/tasks/' . urlencode($GoogleTaskId));
        return $data !== null;
    }

    private function AddGooglePendingDelete(string $GoogleTaskId): void
    {
        $this->SyncAddPendingDelete($GoogleTaskId, 'pending_', 'GooglePendingDeletes');
    }

    private function GetGoogleTasksStatusLabel(): string
    {
        $gw = $this->GetGatewayID();
        $connected = $gw > 0 && TGW_GoogleIsConnected($gw);
        $lastSync = $this->ReadAttributeInteger('GoogleLastSync');
        return $this->SyncGetStatusLabel($connected ? 'connected' : '', $lastSync);
    }

    private function GetGoogleTasksFormElements(string $SyncBackend): array
    {
        return [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('Google Tasks Synchronization'),
            'visible' => $SyncBackend === 'google',
            'items' => [
                [
                    'type' => 'Label',
                    'caption' => $this->Translate('Due time and recurrences are not supported by the Google API.')
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'GoogleTasksEnabled',
                    'caption' => $this->Translate('Enabled'),
                    'visible' => false
                ],
                [
                    'type' => 'Select',
                    'name' => 'GoogleTaskListID',
                    'caption' => $this->Translate('Task List'),
                    'width' => '400px',
                    'options' => $this->GetGoogleTaskListOptions()
                ],
                [
                    'type' => 'Select',
                    'name' => 'GoogleSyncInterval',
                    'caption' => $this->Translate('Sync Interval'),
                    'width' => '200px',
                    'options' => $this->GetSyncIntervalOptions()
                ],
                [
                    'type' => 'Select',
                    'name' => 'GoogleConflictMode',
                    'caption' => $this->Translate('On Conflict'),
                    'width' => '250px',
                    'options' => $this->GetConflictModeOptions()
                ],
                [
                    'type' => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Refresh Task Lists'),
                            'onClick' => 'TDL_GoogleRefreshTaskListOptions($id);'
                        ],
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Sync Now'),
                            'onClick' => 'TDL_GoogleTasksSync($id);'
                        ],
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Reset Sync'),
                            'onClick' => 'echo TDL_GoogleResetSync($id);'
                        ]
                    ]
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->GetGoogleTasksStatusLabel()
                ]
            ]
        ];
    }
}
