<?php

declare(strict_types=1);

trait GoogleTasksSync
{
    private function UpdateGoogleTasksTimer(): void
    {
        $verbunden = $this->SyncGatewayVerbunden('TGW_GoogleIsConnected');
        if ($verbunden === null) {
            // Gateway-Schnittstelle entsteht gerade neu (Modul-Reload): den Timer
            // NICHT anfassen — siehe SyncGatewayVerbunden.
            return;
        }
        $this->SyncUpdateTimer('google', 'GoogleTasksSyncTimer', $this->ReadPropertyInteger('GoogleSyncInterval'), $verbunden);
    }

    private function GoogleApiRequest(string $Method, string $Endpoint, mixed $Body = null, array $Headers = []): ?array
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            $this->SendDebug('GoogleTasks', 'No SymDo Gateway connected', 0);
            return null;
        }
        return TGW_GoogleApiRequest($gw, $Method, $Endpoint, $Body, $Headers);
    }

    private function GoogleApiStatus(string $Method, string $Endpoint, mixed $Body = null, array $Headers = []): int
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            return 0;
        }
        return TGW_GoogleApiStatus($gw, $Method, $Endpoint, $Body, $Headers);
    }

    /**
     * Meldung als RUECKGABE statt als Ausgabe: Symcon 9.1 betrachtet jeden
     * Funktionsaufruf fuer sich und meldet ein `echo` INNERHALB einer Funktion als
     * Fehler. Der Knopf im Formular gibt sie aus.
     */
    public function GoogleRefreshTaskListOptions(): string
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0 || !TGW_GoogleIsConnected($gw)) {
            return $this->Translate('Not connected to Google. Please authorize first.');
        }

        $stored = $this->GoogleFetchAndStoreTaskListOptions();
        if ($stored === null) {
            return $this->Translate('Failed to fetch task lists.');
        }

        $options = $this->GetGoogleTaskListOptions();
        $this->UpdateFormField('GoogleTaskListID', 'options', json_encode($options));
        return sprintf($this->Translate('Found %d task list(s).'), count($stored));
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

    /** Meldung als RUECKGABE — der Knopf gibt sie aus (siehe SyncResetItems). */
    public function GoogleResetSync(): string
    {
        $meldung = $this->SyncResetItems(
            ['googleTaskId'],
            ['googleEtag'],
            ['googleSynced'],
            'GoogleLastSync',
            'GooglePendingDeletes'
        );
        $this->WriteAttributeInteger('GoogleSyncCursor', 0); // A1: force a full re-fetch next sync
        $this->WriteAttributeInteger('GoogleLastFullSync', 0);
        return $meldung;
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

        // A1: incremental probe. When the server has no changes since our cursor and there is
        // nothing local to push, skip the full fetch+merge. Any change on either side falls
        // through to the full, deletion-safe fetch (never delete-by-absence on a partial list).
        $cursor = $this->ReadAttributeInteger('GoogleSyncCursor');
        $lastFull = $this->ReadAttributeInteger('GoogleLastFullSync');
        if ($cursor > 0 && (time() - $lastFull) < 21600 && !$this->GoogleHasPendingWork()) {
            // R6: probe strictly AFTER the cursor second — the task that defined the cursor
            // always matches an inclusive updatedMin, so the idle-skip would otherwise never
            // fire. The 6h bound forces a periodic full reconcile as a safety net for anything
            // a second-granularity cursor could miss (sub-second edits, tombstone purges).
            $probe = $this->GoogleFetchTasks($taskListId, $cursor + 1);
            if ($probe === null) {
                $this->SendDebug('GoogleTasks', 'Incremental probe failed', 0);
                return false;
            }
            if (count($probe) === 0) {
                $this->WriteAttributeInteger('GoogleLastSync', time());
                $this->SendDebug('GoogleTasks', 'No changes since cursor and no local changes – skipping full sync', 0);
                return true;
            }
            $this->SendDebug('GoogleTasks', count($probe) . ' change(s) since cursor – running full sync', 0);
        }

        $serverTasks = $this->GoogleFetchTasks($taskListId);
        if ($serverTasks === null) {
            $this->SendDebug('GoogleTasks', 'Failed to fetch tasks from Google', 0);
            return false;
        }

        // A3/Fresh-ETag: process pending server-deletes AFTER the fetch and condition the
        // DELETE on this run's etag. Stored tombstone etags go stale when Google bumps a
        // task's etag on sibling inserts (position shift, 'updated' untouched) — the spurious
        // 412 would drop the tombstone and resurrect the task locally.
        $pendingDeletes = json_decode((string)$this->ReadAttributeString('GooglePendingDeletes'), true);
        if (!is_array($pendingDeletes)) {
            $pendingDeletes = [];
        }
        if (count($pendingDeletes) > 0) {
            $etagByGid = [];
            foreach ($serverTasks as $st) {
                $gid = (string)($st['googleTaskId'] ?? '');
                if ($gid !== '' && ($st['googleEtag'] ?? '') !== '') {
                    $etagByGid[$gid] = (string)$st['googleEtag'];
                }
            }
            $beforeKeys = array_keys($pendingDeletes);
            $this->SyncProcessPendingDeletes($pendingDeletes, fn($id, $etag) => $this->GoogleDeleteTask($taskListId, $id, $etagByGid[$id] ?? $etag), 'GoogleTasks');
            $this->WriteAttributeString('GooglePendingDeletes', json_encode($pendingDeletes, JSON_UNESCAPED_SLASHES));
            // The fetch snapshot predates these deletes — drop resolved ones so the merge does
            // not reimport what was just deleted (a 412-kept server version reimports next run).
            $resolved = array_diff($beforeKeys, array_keys($pendingDeletes));
            if (count($resolved) > 0) {
                $serverTasks = array_values(array_filter($serverTasks, fn($st) => !in_array((string)($st['googleTaskId'] ?? ''), $resolved, true)));
            }
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
                        $items[$i]['googleServerSynced'] = (int)($uploadResult['updated'] ?? 0); // A5 baseline from write response
                        $items[$i]['localModified'] = 0;
                        break;
                    }
                }
                $this->SendDebug('GoogleTasks', 'Uploaded: ' . ($uploadItem['title'] ?? $newId), 0);
            }
        }

        foreach ($items as &$item) {
            $gid = (string)($item['googleTaskId'] ?? '');
            // R4: never stamp a failed create (pending_) as synced — it still awaits upload.
            if ($gid !== '' && strpos($gid, 'pending_') !== 0 && ($item['googleSynced'] ?? 0) === 0) {
                $item['googleSynced'] = $now;
            }
        }
        unset($item);

        $this->SaveItems($items);
        $this->WriteAttributeInteger('GoogleLastSync', $now);
        // A1: advance the incremental cursor to the newest server-side 'updated' seen this run.
        $maxUpdated = $this->ReadAttributeInteger('GoogleSyncCursor');
        foreach ($serverTasks as $st) {
            $maxUpdated = max($maxUpdated, (int)($st['googleUpdated'] ?? 0));
        }
        $this->WriteAttributeInteger('GoogleSyncCursor', $maxUpdated);
        $this->WriteAttributeInteger('GoogleLastFullSync', $now); // R6: periodic-reconcile baseline
        $this->SyncPostComplete();

        $this->SendDebug('GoogleTasks', 'Sync completed', 0);
        return true;
    }

    private function GoogleHasPendingWork(): bool
    {
        $pending = json_decode((string)$this->ReadAttributeString('GooglePendingDeletes'), true);
        if (is_array($pending) && count($pending) > 0) {
            return true;
        }
        foreach ($this->LoadItems() as $it) {
            $gid = (string)($it['googleTaskId'] ?? '');
            if ($gid === '' || strpos($gid, 'pending_') === 0) {
                return true; // new local item awaiting upload (or a create that failed, R4)
            }
            if ((int)($it['localModified'] ?? 0) >= (int)($it['googleSynced'] ?? 0) && (int)($it['localModified'] ?? 0) > 0) {
                return true; // locally edited since last sync (>= catches same-second edits)
            }
        }
        return false;
    }

    private function GoogleFetchTasks(string $TaskListId, int $UpdatedMin = 0): ?array
    {
        $allTasks = [];
        $pageToken = '';

        do {
            $endpoint = '/tasks/v1/lists/' . urlencode($TaskListId) . '/tasks?showCompleted=true&showHidden=true&showDeleted=true&maxResults=100';
            if ($UpdatedMin > 0) {
                // A1: incremental probe — only tasks changed at/after the cursor (server clock).
                $endpoint .= '&updatedMin=' . urlencode(gmdate('Y-m-d\TH:i:s\Z', $UpdatedMin));
            }
            if ($pageToken !== '') {
                $endpoint .= '&pageToken=' . urlencode($pageToken);
            }

            $data = $this->GoogleApiRequest('GET', $endpoint);
            if ($data === null) {
                return null;
            }

            foreach ($data['items'] ?? [] as $task) {
                $this->SendDebug('GoogleTasksPayload', json_encode($task, JSON_UNESCAPED_SLASHES), 0);
                // A6: keep deleted tasks (marked) so the merge acts on Google's authoritative
                // deletion signal (deleted=true) rather than inferring deletion from absence.
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
            'googleUpdated' => $updated,
            '_deleted' => !empty($Task['deleted'])
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
            $task['due'] = date('Y-m-d', $due) . 'T00:00:00.000Z';
        } else {
            $gid = (string)($Item['googleTaskId'] ?? '');
            if ($gid !== '' && strpos($gid, 'pending_') !== 0) {
                // C2: an existing task whose due date was removed locally → clear it explicitly
                // (a PATCH that merely omits 'due' leaves the old server value, so it would
                // silently reappear). New tasks just omit 'due' rather than sending null.
                $task['due'] = null;
            }
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
                $localChanged = $localMod >= $lastSynced && $localMod > 0; // >= catches same-second edits

                if (strpos($googleId, 'pending_') !== 0) {
                    if ($ConflictMode === 'local_wins' && $localChanged) {
                        $newGoogleId = 'pending_' . $this->InstanceID . '_' . ($local['id'] ?? uniqid());
                        $local['googleTaskId'] = $newGoogleId;
                        $local['localModified'] = time();
                        $toUpload[] = $local;
                    } else {
                        $local['_googleDeleted'] = true;
                    }
                } else {
                    // R4: a create that failed earlier (throttle window, timeout, 5xx) left the
                    // item stranded on its pending_ id — requeue the upload instead of skipping
                    // it forever.
                    $toUpload[] = $local;
                }
                continue;
            }

            $server = $serverByGoogleId[$googleId];
            if (!empty($server['_deleted'])) {
                $lastSynced = (int)($local['googleSynced'] ?? 0);
                $localMod = (int)($local['localModified'] ?? 0);
                $localChanged = $localMod >= $lastSynced && $localMod > 0;
                if ($ConflictMode === 'local_wins' && $localChanged) {
                    // R13: under "Local wins" a locally edited task beats the server tombstone —
                    // recreate it (same as the delete-by-absence path below) instead of silently
                    // discarding the local edit.
                    $local['googleTaskId'] = 'pending_' . $this->InstanceID . '_' . ($local['id'] ?? uniqid());
                    $local['localModified'] = time();
                    $toUpload[] = $local;
                } else {
                    // A6: authoritative server-side deletion signal → remove the item locally.
                    $local['_googleDeleted'] = true;
                }
                continue;
            }
            $localMod = (int)($local['localModified'] ?? 0);
            $serverMod = (int)($server['googleUpdated'] ?? 0);
            $lastSynced = (int)($local['googleSynced'] ?? 0);
            // A5: compare server-vs-server so a host/server clock offset can't hide an edit.
            $serverSynced = (int)($local['googleServerSynced'] ?? $serverMod);

            $localChanged = $localMod >= $lastSynced && $localMod > 0; // >= catches same-second edits
            $serverChanged = $serverMod > $serverSynced;

            if ($localChanged && $serverChanged) {
                $localWins = ($ConflictMode === 'local_wins') || ($ConflictMode === 'newest_wins' && $localMod > $serverMod);

                if ($localWins) {
                    // A3: adopt the server's current ETag so the intended overwrite matches
                    // the If-Match precondition instead of being rejected as 412 forever.
                    $local['googleEtag'] = $server['googleEtag'] ?? ($local['googleEtag'] ?? '');
                    $toUpload[] = $local;
                } else {
                    $this->GoogleApplyServerToLocal($local, $server);
                }
            } elseif ($localChanged) {
                // Fresh-ETag: condition the PATCH on THIS run's fetch, not on the last sync's
                // snapshot. Google bumps a task's etag when siblings are inserted (position
                // shift) WITHOUT touching 'updated' — so the stored etag can be stale while
                // serverChanged stays false, which would 412 on every sync forever.
                if (($server['googleEtag'] ?? '') !== '') {
                    $local['googleEtag'] = $server['googleEtag'];
                }
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
            if (!empty($server['_deleted'])) {
                continue; // A6: never import a Google tombstone as a new local task
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
                'googleServerSynced' => (int)($server['googleUpdated'] ?? 0), // A5 baseline
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
        // GUARANTEE (package 7): the Google Tasks API has no priority, reminder, recurrence or
        // quantity. Those are local-only fields and MUST survive every sync — this method
        // therefore only ever writes the four fields Google actually round-trips
        // (title/info/done/due) and the sync bookkeeping. Do NOT add priority/notification/
        // recurrence*/quantity/notifiedFor here; overwriting them would silently destroy
        // local-only user data. (Verified live in all conflict modes.)
        $Local['title'] = $Server['title'];
        $Local['info'] = $Server['info'];
        $Local['done'] = $Server['done'];
        $Local['doneAt'] = $Server['doneAt'];
        $Local['due'] = $this->MergeDueWithLocalTime((int)($Local['due'] ?? 0), (int)($Server['due'] ?? 0));
        $Local['googleEtag'] = $Server['googleEtag'];
        $Local['googleServerSynced'] = (int)($Server['googleUpdated'] ?? 0); // A5 baseline
        $Local['localModified'] = 0;
    }

    private function GoogleUploadTask(string $TaskListId, array $Item): array
    {
        $googleId = $Item['googleTaskId'] ?? '';
        $taskData = $this->LocalToGoogleTask($Item);

        if ($googleId === '' || strpos($googleId, 'pending_') === 0) {
            $data = $this->GoogleApiRequest('POST', '/tasks/v1/lists/' . urlencode($TaskListId) . '/tasks', $taskData);
            if ($data === null) {
                return ['success' => false, 'oldId' => $googleId, 'newId' => '', 'etag' => '', 'updated' => 0];
            }

            return [
                'success' => true,
                'oldId' => $googleId,
                'newId' => $data['id'] ?? '',
                'etag' => $data['etag'] ?? '',
                'updated' => isset($data['updated']) ? (strtotime((string)$data['updated']) ?: 0) : 0
            ];
        }

        // A3: optimistic concurrency via If-Match, so a task changed on the server since our
        // last fetch is not silently overwritten (the write fails and is reconciled next sync).
        $etag = (string)($Item['googleEtag'] ?? '');
        $headers = $etag !== '' ? ['If-Match: ' . $etag] : [];
        $data = $this->GoogleApiRequest('PATCH', '/tasks/v1/lists/' . urlencode($TaskListId) . '/tasks/' . urlencode($googleId), $taskData, $headers);
        return [
            'success' => $data !== null,
            'oldId' => $googleId,
            'newId' => $googleId,
            'etag' => $data['etag'] ?? '',
            'updated' => isset($data['updated']) ? (strtotime((string)$data['updated']) ?: 0) : 0
        ];
    }

    private function GoogleDeleteTask(string $TaskListId, string $GoogleTaskId, string $Etag = ''): bool
    {
        if ($GoogleTaskId === '' || strpos($GoogleTaskId, 'pending_') === 0) {
            return true; // never persisted on the server → tombstone is done
        }

        // R23: NO If-Match on Google deletes ($Etag is deliberately unused). Google bumps the
        // etag of every remaining task whenever a sibling is inserted or DELETED (position
        // shift), so during bulk deletes each successful DELETE invalidates all other prepared
        // etags — conditional deletes then fail 412 almost deterministically (verified live)
        // without indicating any real content change. Google-side etags carry no usable
        // concurrency signal for deletes; MS/CalDAV keep If-Match (content-stable etags).
        $status = $this->GoogleApiStatus('DELETE', '/tasks/v1/lists/' . urlencode($TaskListId) . '/tasks/' . urlencode($GoogleTaskId), null, []);

        if (($status >= 200 && $status < 300) || $status === 404) {
            return true; // deleted, or already gone
        }
        $this->SendDebug('GoogleTasks', 'Delete not confirmed (HTTP ' . $status . ') for ' . $GoogleTaskId . ' – will retry', 0);
        return false;
    }

    private function AddGooglePendingDelete(string $GoogleTaskId, string $Etag = ''): void
    {
        $this->SyncAddPendingDelete($GoogleTaskId, 'pending_', 'GooglePendingDeletes', $Etag);
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
                            'onClick' => 'echo TDL_GoogleRefreshTaskListOptions($id);'
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
