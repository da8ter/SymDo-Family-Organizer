<?php

declare(strict_types=1);

trait MicrosoftToDoSync
{
    private function UpdateMicrosoftToDoTimer(): void
    {
        $gw = $this->GetGatewayID();
        $hasToken = $gw > 0 && TGW_MicrosoftIsConnected($gw);
        $this->SyncUpdateTimer('microsoft', 'MicrosoftToDoSyncTimer', $this->ReadPropertyInteger('MicrosoftSyncInterval'), $hasToken);
    }

    private function MicrosoftApiRequest(string $Method, string $Endpoint, mixed $Body = null): ?array
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            $this->SendDebug('MicrosoftToDo', 'No ToDoGateway connected', 0);
            return null;
        }
        return TGW_MicrosoftApiRequest($gw, $Method, $Endpoint, $Body);
    }

    public function MicrosoftRefreshListOptions(): void
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0 || !TGW_MicrosoftIsConnected($gw)) {
            echo $this->Translate('Not connected to Microsoft. Please authorize first.');
            return;
        }

        $stored = $this->MicrosoftFetchAndStoreListOptions();
        if ($stored === null) {
            echo $this->Translate('Failed to fetch lists.');
            return;
        }

        $options = $this->GetMicrosoftListOptions();
        $this->UpdateFormField('MicrosoftListID', 'options', json_encode($options));
        echo sprintf($this->Translate('Found %d list(s).'), count($stored));
    }

    private function MicrosoftFetchAndStoreListOptions(): ?array
    {
        $data = $this->MicrosoftApiRequest('GET', '/me/todo/lists');
        if ($data === null) {
            return null;
        }

        $items = $data['value'] ?? [];
        $stored = [];
        foreach ($items as $list) {
            $id = $list['id'] ?? '';
            $name = $list['displayName'] ?? 'Untitled';
            if ($id !== '') {
                $stored[] = ['id' => $id, 'name' => $name];
            }
        }

        $this->WriteAttributeString('MicrosoftListOptions', json_encode($stored));
        return $stored;
    }

    private function GetMicrosoftListOptions(): array
    {
        $options = [['caption' => $this->Translate('Please select...'), 'value' => '']];

        $stored = json_decode($this->ReadAttributeString('MicrosoftListOptions'), true);
        if (is_array($stored)) {
            foreach ($stored as $item) {
                $options[] = [
                    'caption' => $item['name'] ?? 'Untitled',
                    'value' => $item['id'] ?? ''
                ];
            }
        }

        $currentId = $this->ReadPropertyString('MicrosoftListID');
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

    public function MicrosoftTestConnection(): bool
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            echo $this->Translate('Not connected. Please authorize first.');
            return false;
        }
        return TGW_MicrosoftTestConnection($gw);
    }

    public function MicrosoftToDoSync(): bool
    {
        $sem = 'TDL_MicrosoftSync_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 0)) {
            $this->SendDebug('MicrosoftToDo', 'Sync skipped - already running', 0);
            return false;
        }

        try {
            return $this->MicrosoftToDoSyncInternal();
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    public function MicrosoftResetSync(): void
    {
        $this->SyncResetItems(
            ['microsoftTaskId'],
            ['microsoftEtag'],
            ['microsoftSynced'],
            'MicrosoftLastSync',
            'MicrosoftPendingDeletes'
        );
    }

    private function MicrosoftToDoSyncInternal(): bool
    {
        if ($this->GetSyncBackend() !== 'microsoft') {
            $this->SendDebug('MicrosoftToDo', 'Sync skipped - not enabled', 0);
            return false;
        }

        $listId = trim($this->ReadPropertyString('MicrosoftListID'));
        if ($listId === '') {
            $this->SendDebug('MicrosoftToDo', 'Sync skipped - no list selected', 0);
            return false;
        }

        $gw = $this->GetGatewayID();
        if ($gw === 0 || !TGW_MicrosoftIsConnected($gw)) {
            $this->SendDebug('MicrosoftToDo', 'Sync skipped - not authenticated', 0);
            return false;
        }

        $this->SendDebug('MicrosoftToDo', 'Starting sync...', 0);

        $pendingDeletes = json_decode((string)$this->ReadAttributeString('MicrosoftPendingDeletes'), true);
        if (!is_array($pendingDeletes)) {
            $pendingDeletes = [];
        }
        $this->SyncProcessPendingDeletes($pendingDeletes, fn($id) => $this->MicrosoftDeleteTask($listId, $id), 'MicrosoftToDo');
        $this->WriteAttributeString('MicrosoftPendingDeletes', json_encode($pendingDeletes, JSON_UNESCAPED_SLASHES));

        $serverTasks = $this->MicrosoftFetchTasks($listId);
        if ($serverTasks === null) {
            $this->SendDebug('MicrosoftToDo', 'Failed to fetch tasks from Microsoft', 0);
            return false;
        }

        $localItems = $this->LoadItems();
        $conflictMode = $this->ReadPropertyString('MicrosoftConflictMode');
        $result = $this->MicrosoftMergeItems($localItems, $serverTasks, $conflictMode);

        $items = $result['items'];
        $now = time();

        foreach ($result['toUpload'] as $uploadItem) {
            $uploadResult = $this->MicrosoftUploadTask($listId, $uploadItem);
            if ($uploadResult['success']) {
                $oldId = $uploadResult['oldId'];
                $newId = $uploadResult['newId'];
                for ($i = 0; $i < count($items); $i++) {
                    if (($items[$i]['microsoftTaskId'] ?? '') === $oldId || ($items[$i]['id'] ?? 0) === ($uploadItem['id'] ?? -1)) {
                        $items[$i]['microsoftTaskId'] = $newId;
                        $items[$i]['microsoftEtag'] = $uploadResult['etag'];
                        $items[$i]['microsoftSynced'] = $now;
                        $items[$i]['localModified'] = 0;
                        break;
                    }
                }
                $this->SendDebug('MicrosoftToDo', 'Uploaded: ' . ($uploadItem['title'] ?? $newId), 0);
            }
        }

        foreach ($items as &$item) {
            if (!empty($item['microsoftTaskId']) && ($item['microsoftSynced'] ?? 0) === 0) {
                $item['microsoftSynced'] = $now;
            }
        }
        unset($item);

        $this->SaveItems($items);
        $this->WriteAttributeInteger('MicrosoftLastSync', $now);
        $this->SyncPostComplete();

        $this->SendDebug('MicrosoftToDo', 'Sync completed', 0);
        return true;
    }

    private function MicrosoftFetchTasks(string $ListId): ?array
    {
        $all = [];
        $endpoint = '/me/todo/lists/' . urlencode($ListId) . '/tasks?$top=100';
        while (true) {
            $data = $this->MicrosoftApiRequest('GET', $endpoint);
            if ($data === null) {
                return null;
            }
            foreach ($data['value'] ?? [] as $task) {
                $all[] = $this->MicrosoftTaskToLocal($task);
            }
            $next = $data['@odata.nextLink'] ?? '';
            if ($next === '') {
                break;
            }
            $pos = strpos($next, '/v1.0');
            if ($pos === false) {
                break;
            }
            $endpoint = substr($next, $pos + 4);
        }
        return $all;
    }

    private function MicrosoftBuildDateTimeTimeZone(int $Timestamp): array
    {
        return [
            'dateTime' => gmdate('Y-m-d\TH:i:s.0000000', $Timestamp),
            'timeZone' => 'UTC'
        ];
    }

    private function MicrosoftParseDateTimeTimeZone(mixed $Value): int
    {
        if (!is_array($Value)) {
            return 0;
        }
        $dt = (string)($Value['dateTime'] ?? '');
        if ($dt === '') {
            return 0;
        }

        $tz = $this->MicrosoftWindowsToIana((string)($Value['timeZone'] ?? 'UTC'));

        try {
            $d = new DateTime($dt, new DateTimeZone($tz));
            return $d->getTimestamp();
        } catch (Exception $e) {
            try {
                $d = new DateTime($dt, new DateTimeZone('UTC'));
                return $d->getTimestamp();
            } catch (Exception $e2) {
                return 0;
            }
        }
    }

    private function MicrosoftWindowsToIana(string $WindowsTz): string
    {
        $map = [
            'UTC'                          => 'UTC',
            'W. Europe Standard Time'      => 'Europe/Berlin',
            'Romance Standard Time'        => 'Europe/Paris',
            'Central Europe Standard Time' => 'Europe/Budapest',
            'Central European Standard Time' => 'Europe/Warsaw',
            'E. Europe Standard Time'      => 'Europe/Chisinau',
            'FLE Standard Time'            => 'Europe/Kiev',
            'GTB Standard Time'            => 'Europe/Bucharest',
            'GMT Standard Time'            => 'Europe/London',
            'Greenwich Standard Time'      => 'Atlantic/Reykjavik',
            'Russian Standard Time'        => 'Europe/Moscow',
            'Eastern Standard Time'        => 'America/New_York',
            'Central Standard Time'        => 'America/Chicago',
            'Mountain Standard Time'       => 'America/Denver',
            'Pacific Standard Time'        => 'America/Los_Angeles',
            'China Standard Time'          => 'Asia/Shanghai',
            'Tokyo Standard Time'          => 'Asia/Tokyo',
            'AUS Eastern Standard Time'    => 'Australia/Sydney',
            'India Standard Time'          => 'Asia/Kolkata',
            'Arabian Standard Time'        => 'Asia/Dubai',
            'Israel Standard Time'         => 'Asia/Jerusalem',
            'Turkey Standard Time'         => 'Europe/Istanbul',
            'South Africa Standard Time'   => 'Africa/Johannesburg',
            'New Zealand Standard Time'    => 'Pacific/Auckland',
            'Hawaiian Standard Time'       => 'Pacific/Honolulu',
            'Alaskan Standard Time'        => 'America/Anchorage',
            'Atlantic Standard Time'       => 'America/Halifax',
            'SA Pacific Standard Time'     => 'America/Bogota',
            'SA Eastern Standard Time'     => 'America/Cayenne',
            'E. South America Standard Time' => 'America/Sao_Paulo',
            'Argentina Standard Time'      => 'America/Buenos_Aires',
            'Singapore Standard Time'      => 'Asia/Singapore',
            'Korea Standard Time'          => 'Asia/Seoul',
            'Taipei Standard Time'         => 'Asia/Taipei',
            'SE Asia Standard Time'        => 'Asia/Bangkok',
            'Samoa Standard Time'          => 'Pacific/Apia',
            'Tonga Standard Time'          => 'Pacific/Tongatapu'
        ];
        return $map[$WindowsTz] ?? 'UTC';
    }

    private function MicrosoftMapPriorityToImportance(string $Priority): string
    {
        $p = strtolower(trim($Priority));
        return in_array($p, ['low', 'high'], true) ? $p : 'normal';
    }

    private function MicrosoftMapImportanceToPriority(string $Importance): string
    {
        $i = strtolower(trim($Importance));
        return in_array($i, ['low', 'high'], true) ? $i : 'normal';
    }

    private function MicrosoftGetWeekday(int $Timestamp): string
    {
        $n = (int)gmdate('N', $Timestamp);
        return match ($n) {
            1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
            4 => 'thursday', 5 => 'friday', 6 => 'saturday',
            default => 'sunday'
        };
    }

    private function MicrosoftNearestLeadTime(int $Seconds, int $Default): int
    {
        $allowed = [0, 300, 600, 1800, 3600, 18000, 43200];
        $Seconds = max(0, $Seconds);

        $best = null;
        $bestDiff = null;
        foreach ($allowed as $v) {
            $diff = abs($v - $Seconds);
            if ($best === null || $diff < $bestDiff) {
                $best = $v;
                $bestDiff = $diff;
            }
        }
        return $best ?? $Default;
    }

    private function MicrosoftBuildRecurrence(array $Item): ?array
    {
        $due = (int)($Item['due'] ?? 0);
        if ($due <= 0) {
            return null;
        }

        $rec = $this->NormalizeRecurrence($Item['recurrence'] ?? 'none', $due);
        if ($rec === 'none') {
            return null;
        }

        $startDate = gmdate('Y-m-d', $due);
        $range = [
            'type' => 'noEnd',
            'startDate' => $startDate,
            'recurrenceTimeZone' => 'UTC'
        ];

        $pattern = [];
        if ($rec === 'w1' || $rec === 'w2' || $rec === 'w3') {
            $pattern = [
                'type' => 'weekly',
                'interval' => (int)substr($rec, 1),
                'daysOfWeek' => [$this->MicrosoftGetWeekday($due)],
                'firstDayOfWeek' => 'monday'
            ];
        } elseif ($rec === 'm1' || $rec === 'q1') {
            $pattern = [
                'type' => 'absoluteMonthly',
                'interval' => $rec === 'q1' ? 3 : 1,
                'dayOfMonth' => (int)gmdate('j', $due)
            ];
        } elseif ($rec === 'y1') {
            $pattern = [
                'type' => 'absoluteYearly',
                'interval' => 1,
                'month' => (int)gmdate('n', $due),
                'dayOfMonth' => (int)gmdate('j', $due)
            ];
        } elseif ($rec === 'custom') {
            $unit = $this->NormalizeRecurrenceCustomUnit($Item['recurrenceCustomUnit'] ?? null);
            $val = $this->NormalizeRecurrenceCustomValue($Item['recurrenceCustomValue'] ?? null);
            if ($unit === 'd') {
                $pattern = ['type' => 'daily', 'interval' => $val];
            } elseif ($unit === 'w') {
                $pattern = [
                    'type' => 'weekly',
                    'interval' => $val,
                    'daysOfWeek' => [$this->MicrosoftGetWeekday($due)],
                    'firstDayOfWeek' => 'monday'
                ];
            } elseif ($unit === 'm') {
                $pattern = [
                    'type' => 'absoluteMonthly',
                    'interval' => $val,
                    'dayOfMonth' => (int)gmdate('j', $due)
                ];
            } elseif ($unit === 'y') {
                $pattern = [
                    'type' => 'absoluteYearly',
                    'interval' => $val,
                    'month' => (int)gmdate('n', $due),
                    'dayOfMonth' => (int)gmdate('j', $due)
                ];
            } else {
                return null;
            }
        }

        if (count($pattern) === 0) {
            return null;
        }

        return [
            'pattern' => $pattern,
            'range' => $range
        ];
    }

    private function MicrosoftParseRecurrence(mixed $Value, int $Due): array
    {
        $default = ['recurrence' => 'none', 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
        if ($Due <= 0 || !is_array($Value)) {
            return $default;
        }
        $pattern = $Value['pattern'] ?? null;
        if (!is_array($pattern)) {
            return $default;
        }

        $type = strtolower((string)($pattern['type'] ?? ''));
        $interval = max(1, (int)($pattern['interval'] ?? 1));

        if ($type === 'weekly') {
            if (in_array($interval, [1, 2, 3], true)) {
                return ['recurrence' => 'w' . $interval, 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
            }
            return ['recurrence' => 'custom', 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => $interval];
        }
        if ($type === 'daily') {
            return ['recurrence' => 'custom', 'recurrenceCustomUnit' => 'd', 'recurrenceCustomValue' => $interval];
        }
        if ($type === 'absolutemonthly') {
            if ($interval === 1) {
                return ['recurrence' => 'm1', 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
            }
            if ($interval === 3) {
                return ['recurrence' => 'q1', 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
            }
            return ['recurrence' => 'custom', 'recurrenceCustomUnit' => 'm', 'recurrenceCustomValue' => $interval];
        }
        if ($type === 'absoluteyearly') {
            if ($interval === 1) {
                return ['recurrence' => 'y1', 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
            }
            return ['recurrence' => 'custom', 'recurrenceCustomUnit' => 'y', 'recurrenceCustomValue' => $interval];
        }

        return $default;
    }

    private function MicrosoftTaskToLocal(array $Task): array
    {
        $done = strtolower((string)($Task['status'] ?? '')) === 'completed';
        $doneAt = 0;
        if ($done && isset($Task['completedDateTime'])) {
            $doneAt = $this->MicrosoftParseDateTimeTimeZone($Task['completedDateTime']);
        }

        $due = $this->MicrosoftParseDateTimeTimeZone($Task['dueDateTime'] ?? null);
        $priority = $this->MicrosoftMapImportanceToPriority((string)($Task['importance'] ?? 'normal'));

        $notification = false;
        $notificationLeadTime = $this->NormalizeNotificationLeadTimeDefault((int)$this->ReadPropertyInteger('NotificationLeadTime'));
        if ($due > 0 && !empty($Task['isReminderOn'])) {
            $reminderTs = $this->MicrosoftParseDateTimeTimeZone($Task['reminderDateTime'] ?? null);
            if ($reminderTs > 0 && $reminderTs <= $due) {
                $notification = true;
                $notificationLeadTime = $this->MicrosoftNearestLeadTime($due - $reminderTs, $notificationLeadTime);
            }
        }

        $recData = $this->MicrosoftParseRecurrence($Task['recurrence'] ?? null, $due);
        $recurrence = $this->NormalizeRecurrence($recData['recurrence'], $due);
        $recurrenceCustomUnit = $this->NormalizeRecurrenceCustomUnit($recData['recurrenceCustomUnit'] ?? null);
        $recurrenceCustomValue = $this->NormalizeRecurrenceCustomValue($recData['recurrenceCustomValue'] ?? null);

        $updated = 0;
        if (isset($Task['lastModifiedDateTime'])) {
            $lm = (string)$Task['lastModifiedDateTime'];
            try {
                $d = new DateTime($lm, new DateTimeZone('UTC'));
                $updated = $d->getTimestamp();
            } catch (Exception $e) {
                $updated = strtotime($lm) ?: 0;
            }
        }

        return [
            'microsoftTaskId' => $Task['id'] ?? '',
            'microsoftEtag' => $Task['@odata.etag'] ?? '',
            'microsoftUpdated' => $updated,
            'title' => $Task['title'] ?? '',
            'info' => $Task['body']['content'] ?? '',
            'done' => $done,
            'doneAt' => $doneAt,
            'due' => $due,
            'priority' => $priority,
            'notification' => $notification,
            'notificationLeadTime' => $notificationLeadTime,
            'recurrence' => $recurrence,
            'recurrenceCustomUnit' => $recurrenceCustomUnit,
            'recurrenceCustomValue' => $recurrenceCustomValue
        ];
    }

    private function LocalToMicrosoftTask(array $Item): array
    {
        $task = [
            'title' => $Item['title'] ?? '',
            'body' => [
                'contentType' => 'text',
                'content' => $Item['info'] ?? ''
            ]
        ];

        $due = (int)($Item['due'] ?? 0);
        if ($due > 0) {
            $task['dueDateTime'] = $this->MicrosoftBuildDateTimeTimeZone($due);
        }

        $task['importance'] = $this->MicrosoftMapPriorityToImportance((string)($Item['priority'] ?? 'normal'));

        $notification = !empty($Item['notification']);
        if ($due > 0 && $notification) {
            $lead = $this->NormalizeNotificationLeadTime($Item['notificationLeadTime'] ?? null, $this->NormalizeNotificationLeadTimeDefault((int)$this->ReadPropertyInteger('NotificationLeadTime')));
            $remTs = max(0, $due - $lead);
            $task['isReminderOn'] = true;
            if ($remTs > 0) {
                $task['reminderDateTime'] = $this->MicrosoftBuildDateTimeTimeZone($remTs);
            }
        } else {
            $task['isReminderOn'] = false;
        }

        $recurrence = $this->MicrosoftBuildRecurrence($Item);
        if ($recurrence !== null) {
            $task['recurrence'] = $recurrence;
        }

        $task['status'] = !empty($Item['done']) ? 'completed' : 'notStarted';

        return $task;
    }

    private function MergeDuePreferServerTime(int $LocalDue, int $ServerDue): int
    {
        if ($ServerDue === 0) {
            return 0;
        }
        if ($LocalDue === 0) {
            return $ServerDue;
        }
        if (gmdate('H:i:s', $ServerDue) === '00:00:00') {
            return $this->MergeDueWithLocalTime($LocalDue, $ServerDue);
        }
        return $ServerDue;
    }

    private function MicrosoftApplyServerToLocal(array &$Local, array $Server): void
    {
        $Local['title'] = $Server['title'];
        $Local['info'] = $Server['info'];
        $Local['done'] = $Server['done'];
        $Local['doneAt'] = $Server['doneAt'];
        $Local['due'] = $this->MergeDuePreferServerTime((int)($Local['due'] ?? 0), (int)($Server['due'] ?? 0));
        $Local['priority'] = $Server['priority'] ?? ($Local['priority'] ?? 'normal');
        $Local['notification'] = $Server['notification'] ?? ($Local['notification'] ?? false);
        $Local['notificationLeadTime'] = $Server['notificationLeadTime'] ?? ($Local['notificationLeadTime'] ?? $this->NormalizeNotificationLeadTimeDefault((int)$this->ReadPropertyInteger('NotificationLeadTime')));
        $Local['recurrence'] = $Server['recurrence'] ?? ($Local['recurrence'] ?? 'none');
        $Local['recurrenceCustomUnit'] = $Server['recurrenceCustomUnit'] ?? ($Local['recurrenceCustomUnit'] ?? 'w');
        $Local['recurrenceCustomValue'] = $Server['recurrenceCustomValue'] ?? ($Local['recurrenceCustomValue'] ?? 1);
        $Local['microsoftEtag'] = $Server['microsoftEtag'];
        $Local['localModified'] = 0;
    }

    private function MicrosoftMergeItems(array $LocalItems, array $ServerTasks, string $ConflictMode): array
    {
        $toUpload = [];
        $serverById = [];
        foreach ($ServerTasks as $st) {
            $gid = $st['microsoftTaskId'] ?? '';
            if ($gid !== '') {
                $serverById[$gid] = $st;
            }
        }

        $processedIds = [];

        foreach ($LocalItems as &$local) {
            $taskId = $local['microsoftTaskId'] ?? '';

            if ($taskId === '') {
                $newId = 'pending_' . $this->InstanceID . '_' . ($local['id'] ?? uniqid());
                $local['microsoftTaskId'] = $newId;
                $local['localModified'] = time();
                $toUpload[] = $local;
                continue;
            }

            $processedIds[$taskId] = true;

            if (!isset($serverById[$taskId])) {
                $lastSynced = (int)($local['microsoftSynced'] ?? 0);
                $localMod = (int)($local['localModified'] ?? 0);
                $localChanged = $localMod > $lastSynced;

                if (strpos($taskId, 'pending_') !== 0) {
                    if ($ConflictMode === 'local_wins' && $localChanged) {
                        $newId = 'pending_' . $this->InstanceID . '_' . ($local['id'] ?? uniqid());
                        $local['microsoftTaskId'] = $newId;
                        $local['localModified'] = time();
                        $toUpload[] = $local;
                    } else {
                        $local['_microsoftDeleted'] = true;
                    }
                }
                continue;
            }

            $server = $serverById[$taskId];
            $localMod = (int)($local['localModified'] ?? 0);
            $serverMod = (int)($server['microsoftUpdated'] ?? 0);
            $lastSynced = (int)($local['microsoftSynced'] ?? 0);

            $localChanged = $localMod > $lastSynced;
            $serverChanged = $serverMod > $lastSynced;

            if ($localChanged && $serverChanged) {
                $localWins = ($ConflictMode === 'local_wins') || ($ConflictMode === 'newest_wins' && $localMod > $serverMod);

                if ($localWins) {
                    $toUpload[] = $local;
                } else {
                    $this->MicrosoftApplyServerToLocal($local, $server);
                }
            } elseif ($localChanged) {
                $toUpload[] = $local;
            } else {
                $this->MicrosoftApplyServerToLocal($local, $server);
            }
        }
        unset($local);

        $filtered = [];
        foreach ($LocalItems as $it) {
            if (!empty($it['_microsoftDeleted'])) {
                continue;
            }
            unset($it['_microsoftDeleted']);
            $filtered[] = $it;
        }
        $LocalItems = $filtered;

        $pendingDeletes = json_decode((string)$this->ReadAttributeString('MicrosoftPendingDeletes'), true);
        if (!is_array($pendingDeletes)) {
            $pendingDeletes = [];
        }

        foreach ($ServerTasks as $server) {
            $gid = $server['microsoftTaskId'] ?? '';
            if ($gid === '' || isset($processedIds[$gid])) {
                continue;
            }
            if (isset($pendingDeletes[$gid])) {
                continue;
            }

            $prio = (string)($server['priority'] ?? 'normal');
            if (!in_array($prio, ['low', 'normal', 'high'], true)) {
                $prio = 'normal';
            }
            $notification = (bool)($server['notification'] ?? false);
            $defaultLead = $this->NormalizeNotificationLeadTimeDefault((int)$this->ReadPropertyInteger('NotificationLeadTime'));
            $lead = $this->NormalizeNotificationLeadTime($server['notificationLeadTime'] ?? $defaultLead, $defaultLead);
            $due = (int)($server['due'] ?? 0);
            $recurrence = $this->NormalizeRecurrence($server['recurrence'] ?? 'none', $due);
            $recurrenceCustomUnit = $this->NormalizeRecurrenceCustomUnit($server['recurrenceCustomUnit'] ?? null);
            $recurrenceCustomValue = $this->NormalizeRecurrenceCustomValue($server['recurrenceCustomValue'] ?? null);
            $recurrenceResetLeadTime = $this->NormalizeRecurrenceResetLeadTime(null, $recurrence);

            $newItem = [
                'id' => $this->GetNextItemID(),
                'title' => $server['title'],
                'info' => $server['info'],
                'done' => $server['done'],
                'doneAt' => $server['doneAt'],
                'due' => $due,
                'createdAt' => time(),
                'priority' => $prio,
                'notification' => $notification,
                'notificationLeadTime' => $lead,
                'quantity' => 0,
                'recurrence' => $recurrence,
                'recurrenceCustomUnit' => $recurrenceCustomUnit,
                'recurrenceCustomValue' => $recurrenceCustomValue,
                'recurrenceResetLeadTime' => $recurrenceResetLeadTime,
                'microsoftTaskId' => $gid,
                'microsoftEtag' => $server['microsoftEtag'],
                'microsoftSynced' => time(),
                'localModified' => 0
            ];
            $LocalItems[] = $newItem;
        }

        return [
            'items' => $LocalItems,
            'toUpload' => $toUpload
        ];
    }

    private function MicrosoftUploadTask(string $ListId, array $Item): array
    {
        $taskId = $Item['microsoftTaskId'] ?? '';
        $data = $this->LocalToMicrosoftTask($Item);

        if ($taskId === '' || strpos($taskId, 'pending_') === 0) {
            $res = $this->MicrosoftApiRequest('POST', '/me/todo/lists/' . urlencode($ListId) . '/tasks', $data);
            if ($res === null) {
                return ['success' => false, 'oldId' => $taskId, 'newId' => '', 'etag' => ''];
            }
            return [
                'success' => true,
                'oldId' => $taskId,
                'newId' => $res['id'] ?? '',
                'etag' => $res['@odata.etag'] ?? ''
            ];
        }

        $due = (int)($Item['due'] ?? 0);
        if ($due <= 0) {
            $data['dueDateTime'] = null;
            $data['recurrence'] = null;
            $data['isReminderOn'] = false;
            $data['reminderDateTime'] = null;
        } else {
            if (empty($Item['notification'])) {
                $data['isReminderOn'] = false;
                $data['reminderDateTime'] = null;
            }
            $rec = $this->NormalizeRecurrence($Item['recurrence'] ?? 'none', $due);
            $data['recurrence'] = $rec === 'none' ? null : $this->MicrosoftBuildRecurrence($Item);
        }

        $res = $this->MicrosoftApiRequest('PATCH', '/me/todo/lists/' . urlencode($ListId) . '/tasks/' . urlencode($taskId), $data);
        return [
            'success' => $res !== null,
            'oldId' => $taskId,
            'newId' => $taskId,
            'etag' => $res['@odata.etag'] ?? ''
        ];
    }

    private function MicrosoftDeleteTask(string $ListId, string $TaskId): bool
    {
        if ($TaskId === '' || strpos($TaskId, 'pending_') === 0) {
            return true;
        }

        $data = $this->MicrosoftApiRequest('DELETE', '/me/todo/lists/' . urlencode($ListId) . '/tasks/' . urlencode($TaskId));
        return $data !== null;
    }

    private function AddMicrosoftPendingDelete(string $TaskId): void
    {
        $this->SyncAddPendingDelete($TaskId, 'pending_', 'MicrosoftPendingDeletes');
    }

    private function GetMicrosoftToDoStatusLabel(): string
    {
        $gw = $this->GetGatewayID();
        $connected = $gw > 0 && TGW_MicrosoftIsConnected($gw);
        $lastSync = $this->ReadAttributeInteger('MicrosoftLastSync');
        return $this->SyncGetStatusLabel($connected ? 'connected' : '', $lastSync);
    }

    private function GetMicrosoftToDoFormElements(string $SyncBackend): array
    {
        return [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('Microsoft To Do Synchronization'),
            'visible' => $SyncBackend === 'microsoft',
            'items' => [
                [
                    'type' => 'CheckBox',
                    'name' => 'MicrosoftToDoEnabled',
                    'caption' => $this->Translate('Enabled'),
                    'visible' => false
                ],
                [
                    'type' => 'Select',
                    'name' => 'MicrosoftListID',
                    'caption' => $this->Translate('List'),
                    'width' => '400px',
                    'options' => $this->GetMicrosoftListOptions()
                ],
                [
                    'type' => 'Select',
                    'name' => 'MicrosoftSyncInterval',
                    'caption' => $this->Translate('Sync Interval'),
                    'width' => '200px',
                    'options' => $this->GetSyncIntervalOptions()
                ],
                [
                    'type' => 'Select',
                    'name' => 'MicrosoftConflictMode',
                    'caption' => $this->Translate('On Conflict'),
                    'width' => '250px',
                    'options' => $this->GetConflictModeOptions()
                ],
                [
                    'type' => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Refresh Lists'),
                            'onClick' => 'TDL_MicrosoftRefreshListOptions($id);'
                        ],
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Sync Now'),
                            'onClick' => 'TDL_MicrosoftToDoSync($id);'
                        ],
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Reset Sync'),
                            'onClick' => 'echo TDL_MicrosoftResetSync($id);'
                        ]
                    ]
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->GetMicrosoftToDoStatusLabel()
                ]
            ]
        ];
    }
}
