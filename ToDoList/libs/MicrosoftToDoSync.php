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

    private function MicrosoftApiRequest(string $Method, string $Endpoint, mixed $Body = null, array $Headers = []): ?array
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            $this->SendDebug('MicrosoftToDo', 'No SymDo Gateway connected', 0);
            return null;
        }
        return TGW_MicrosoftApiRequest($gw, $Method, $Endpoint, $Body, $Headers);
    }

    private function MicrosoftApiStatus(string $Method, string $Endpoint, mixed $Body = null, array $Headers = []): int
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            return 0;
        }
        return TGW_MicrosoftApiStatus($gw, $Method, $Endpoint, $Body, $Headers);
    }

    /**
     * Meldung als RUECKGABE statt als Ausgabe: Symcon 9.1 betrachtet jeden
     * Funktionsaufruf fuer sich und meldet ein `echo` INNERHALB einer Funktion als
     * Fehler. Der Knopf im Formular gibt sie aus.
     */
    public function MicrosoftRefreshListOptions(): string
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0 || !TGW_MicrosoftIsConnected($gw)) {
            return $this->Translate('Not connected to Microsoft. Please authorize first.');
        }

        $stored = $this->MicrosoftFetchAndStoreListOptions();
        if ($stored === null) {
            return $this->Translate('Failed to fetch lists.');
        }

        $options = $this->GetMicrosoftListOptions();
        $this->UpdateFormField('MicrosoftListID', 'options', json_encode($options));
        return sprintf($this->Translate('Found %d list(s).'), count($stored));
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

    /** Meldung als RUECKGABE — siehe CalDAVTestConnection. */
    public function MicrosoftTestConnection(): string
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            return $this->Translate('Not connected. Please authorize first.');
        }
        return (string)TGW_MicrosoftTestConnection($gw);
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

    /** Meldung als RUECKGABE — der Knopf gibt sie aus (siehe SyncResetItems). */
    public function MicrosoftResetSync(): string
    {
        $meldung = $this->SyncResetItems(
            ['microsoftTaskId'],
            ['microsoftEtag'],
            ['microsoftSynced'],
            'MicrosoftLastSync',
            'MicrosoftPendingDeletes'
        );
        $this->WriteAttributeString('MicrosoftDeltaLink', ''); // A1: force a fresh full delta next sync
        return $meldung;
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

        // A1: use a Graph delta probe to detect whether anything changed on the server since
        // our stored cursor. When the server is idle and there is nothing local to push, skip
        // the full fetch+merge. Any change — or a probe error — falls through to the full,
        // deletion-safe fetch below (delta is only ever used as a change signal here, never to
        // drive a partial merge, so it cannot delete or duplicate tasks).
        $deltaLink = $this->ReadAttributeString('MicrosoftDeltaLink');
        $probe = $this->MicrosoftDeltaProbe($listId, $deltaLink);
        if ($probe !== null && $probe['deltaLink'] !== '' && $deltaLink !== '' && !$probe['hasChanges'] && !$this->MicrosoftHasPendingWork()) {
            if ($probe['deltaLink'] !== '') {
                $this->WriteAttributeString('MicrosoftDeltaLink', $probe['deltaLink']);
            }
            $this->WriteAttributeInteger('MicrosoftLastSync', time());
            $this->SendDebug('MicrosoftToDo', 'No server changes and nothing local – skipping full sync', 0);
            return true;
        }

        $serverTasks = $this->MicrosoftFetchTasks($listId);
        if ($serverTasks === null) {
            $this->SendDebug('MicrosoftToDo', 'Failed to fetch tasks from Microsoft', 0);
            return false;
        }

        // A3/Fresh-ETag: process pending server-deletes AFTER the fetch and condition the
        // DELETE on this run's etag instead of the (possibly stale) tombstone etag — a
        // spurious 412 would drop the tombstone and resurrect the task locally.
        $pendingDeletes = json_decode((string)$this->ReadAttributeString('MicrosoftPendingDeletes'), true);
        if (!is_array($pendingDeletes)) {
            $pendingDeletes = [];
        }
        if (count($pendingDeletes) > 0) {
            $etagById = [];
            foreach ($serverTasks as $st) {
                $tid = (string)($st['microsoftTaskId'] ?? '');
                if ($tid !== '' && ($st['microsoftEtag'] ?? '') !== '') {
                    $etagById[$tid] = (string)$st['microsoftEtag'];
                }
            }
            $beforeKeys = array_keys($pendingDeletes);
            $this->SyncProcessPendingDeletes($pendingDeletes, fn($id, $etag) => $this->MicrosoftDeleteTask($listId, $id, $etagById[$id] ?? $etag), 'MicrosoftToDo');
            $this->WriteAttributeString('MicrosoftPendingDeletes', json_encode($pendingDeletes, JSON_UNESCAPED_SLASHES));
            // The fetch snapshot predates these deletes — drop resolved ones so the merge does
            // not reimport what was just deleted (a 412-kept server version reimports next run).
            $resolved = array_diff($beforeKeys, array_keys($pendingDeletes));
            if (count($resolved) > 0) {
                $serverTasks = array_values(array_filter($serverTasks, fn($st) => !in_array((string)($st['microsoftTaskId'] ?? ''), $resolved, true)));
            }
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
                        $items[$i]['microsoftServerSynced'] = (int)($uploadResult['updated'] ?? 0); // A5 baseline from write response
                        $items[$i]['localModified'] = 0;
                        break;
                    }
                }
                // Persist the freshly assigned server id immediately. If a later upload
                // in this loop throws, the next run must not re-POST this item (which
                // would create a server-side duplicate). Lightweight write only — the
                // full SaveItems() with side effects still runs once at the end.
                $this->WriteAttributeString('Items', json_encode($items));
                $this->SendDebug('MicrosoftToDo', 'Uploaded: ' . ($uploadItem['title'] ?? $newId), 0);
            }
        }

        foreach ($items as &$item) {
            $mid = (string)($item['microsoftTaskId'] ?? '');
            // R4: never stamp a failed create (pending_) as synced — it still awaits upload.
            if ($mid !== '' && strpos($mid, 'pending_') !== 0 && ($item['microsoftSynced'] ?? 0) === 0) {
                $item['microsoftSynced'] = $now;
            }
        }
        unset($item);

        $this->SaveItems($items);
        $this->WriteAttributeInteger('MicrosoftLastSync', $now);
        // A1: store the delta cursor captured by the probe. (It predates this run's uploads,
        // so the next run may still do one full fetch before idle-skips resume — harmless, as
        // A5's server-timestamp baseline stops our own writes from being seen as conflicts.)
        if ($probe !== null && $probe['deltaLink'] !== '') {
            $this->WriteAttributeString('MicrosoftDeltaLink', $probe['deltaLink']);
        }
        $this->SyncPostComplete();

        $this->SendDebug('MicrosoftToDo', 'Sync completed', 0);
        return true;
    }

    /**
     * A1: traverse the Graph delta collection only to count how many entries changed and to
     * capture the next @odata.deltaLink. Used purely as a change-probe — the actual sync still
     * runs the full fetch + deletion-safe merge. Returns ['hasChanges'=>bool,'deltaLink'=>str]
     * or null on transport failure. A stored deltaLink that the server rejects (e.g. expired,
     * HTTP 410) transparently restarts from a full delta.
     */
    private function MicrosoftDeltaProbe(string $ListId, string $DeltaLink): ?array
    {
        $fullEndpoint = '/me/todo/lists/' . urlencode($ListId) . '/tasks/delta';
        $endpoint = $DeltaLink !== '' ? $DeltaLink : $fullEndpoint;
        $changes = 0;
        $newDeltaLink = '';
        $triedFallback = false;

        for ($page = 0; $page < 2000; $page++) {
            $data = $this->MicrosoftApiRequest('GET', $endpoint);
            if ($data === null) {
                if (!$triedFallback && $endpoint !== $fullEndpoint) {
                    // Stored delta link no longer accepted (typically 410 Gone) → start over
                    // from a fresh full delta so we still get a valid cursor.
                    $this->SendDebug('MicrosoftToDo', 'Delta link rejected – restarting full delta', 0);
                    $triedFallback = true;
                    $endpoint = $fullEndpoint;
                    $changes = 0;
                    continue;
                }
                return null;
            }

            $changes += count($data['value'] ?? []);

            $next = (string)($data['@odata.nextLink'] ?? '');
            if ($next !== '') {
                $endpoint = $next; // opaque absolute URL, followed verbatim by the gateway
                continue;
            }
            $newDeltaLink = (string)($data['@odata.deltaLink'] ?? '');
            break;
        }

        // After a full-delta fallback we cannot trust the incremental change count, so force a
        // full sync by reporting changes.
        return [
            'hasChanges' => $triedFallback ? true : ($changes > 0),
            'deltaLink' => $newDeltaLink
        ];
    }

    private function MicrosoftHasPendingWork(): bool
    {
        $pending = json_decode((string)$this->ReadAttributeString('MicrosoftPendingDeletes'), true);
        if (is_array($pending) && count($pending) > 0) {
            return true;
        }
        foreach ($this->LoadItems() as $it) {
            $id = (string)($it['microsoftTaskId'] ?? '');
            if ($id === '' || strpos($id, 'pending_') === 0) {
                return true; // new/unsynced local item awaiting upload
            }
            if ((int)($it['localModified'] ?? 0) >= (int)($it['microsoftSynced'] ?? 0) && (int)($it['localModified'] ?? 0) > 0) {
                return true; // locally edited since last sync (>= catches same-second edits)
            }
        }
        return false;
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
            $next = (string)($data['@odata.nextLink'] ?? '');
            if ($next === '') {
                break;
            }
            // R1: nextLink is an opaque absolute URL — follow it verbatim (the gateway passes
            // absolute URLs through unchanged, same as the delta probe). Never return a partial
            // list on an unusable link: the absence-based merge would treat every task beyond
            // the fetched pages as deleted on the server.
            if (strncmp($next, 'https://', 8) !== 0 && strncmp($next, 'http://', 7) !== 0) {
                $this->SendDebug('MicrosoftToDo', 'Unusable nextLink – aborting fetch', 0);
                return null;
            }
            $endpoint = $next;
        }
        return $all;
    }

    private function MicrosoftBuildDateTimeTimeZone(int $Timestamp): array
    {
        // B2: express the instant in the host's local time zone (Graph accepts IANA names
        // directly). Graph treats a task's due as date-only and keeps "midnight of the sent
        // date IN THE SENT ZONE" — so sending UTC made a due at local 00:30 (= previous day
        // in UTC) show up one day early. Sending the local zone anchors the correct calendar
        // day. Reminders keep their time and are likewise correct in local time.
        $tzid = date_default_timezone_get();
        if ($tzid !== '' && strtoupper($tzid) !== 'UTC') {
            try {
                $dt = (new DateTime('@' . $Timestamp))->setTimezone(new DateTimeZone($tzid));
                return [
                    'dateTime' => $dt->format('Y-m-d\TH:i:s.0000000'),
                    'timeZone' => $tzid
                ];
            } catch (Exception $e) {
                // fall through to UTC
            }
        }
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

    /**
     * B3: true when the item's local recurrence is semantically the same pattern as the raw
     * server recurrence (ignoring the due-date/startDate, which moves on every completion of
     * the series). Used to OMIT 'recurrence' from PATCH bodies — Graph rejects recurrence
     * writes on update, and omitting preserves the server pattern including details the
     * local model cannot represent ("Mo+We+Fr", "2nd Tuesday", series end).
     */
    private function MicrosoftRecurrencePatternUnchanged(array $Item): bool
    {
        $raw = $Item['microsoftRecurrenceRaw'] ?? null;
        if (!is_array($raw)) {
            return false;
        }
        $due = (int)($Item['due'] ?? 0);
        if ($due <= 0) {
            return false;
        }
        $parsed = $this->MicrosoftParseRecurrence($raw, $due);
        $itemRec = $this->NormalizeRecurrence($Item['recurrence'] ?? 'none', $due);
        $sameRec = $this->NormalizeRecurrence($parsed['recurrence'], $due) === $itemRec;
        $sameCustom = $itemRec !== 'custom'
            || (($parsed['recurrenceCustomUnit'] ?? 'w') === ($Item['recurrenceCustomUnit'] ?? 'w')
                && (int)($parsed['recurrenceCustomValue'] ?? 1) === (int)($Item['recurrenceCustomValue'] ?? 1));
        return $sameRec && $sameCustom;
    }

    private function MicrosoftBuildRecurrence(array $Item): ?array
    {
        $due = (int)($Item['due'] ?? 0);
        if ($due <= 0) {
            return null;
        }

        // B1: if this recurrence came from the server and the user has NOT changed it locally,
        // re-send the original server pattern verbatim. This preserves patterns the local model
        // cannot represent (multi-day weekly, "2nd Tuesday", …) instead of overwriting them with
        // a lossy rebuild. Only when the user actually edited the recurrence do we rebuild.
        $raw = $Item['microsoftRecurrenceRaw'] ?? null;
        if (is_array($raw)) {
            $rawStart = (string)($raw['range']['startDate'] ?? '');
            // R21: startDate is expressed in range.recurrenceTimeZone — compare the due date
            // in THAT zone, not in UTC, or a due near midnight makes an unchanged recurrence
            // look edited and triggers the lossy rebuild below.
            $rawTz = $this->MicrosoftWindowsToIana((string)($raw['range']['recurrenceTimeZone'] ?? 'UTC'));
            try {
                $dueDateInZone = (new DateTime('@' . $due))->setTimezone(new DateTimeZone($rawTz))->format('Y-m-d');
            } catch (Exception $e) {
                $dueDateInZone = gmdate('Y-m-d', $due);
            }
            $dueMatches = ($rawStart === '' || $rawStart === $dueDateInZone); // due not moved
            $parsed = $this->MicrosoftParseRecurrence($raw, $due);
            $itemRec = $this->NormalizeRecurrence($Item['recurrence'] ?? 'none', $due);
            $sameRec = $this->NormalizeRecurrence($parsed['recurrence'], $due) === $itemRec;
            $sameCustom = $itemRec !== 'custom'
                || (($parsed['recurrenceCustomUnit'] ?? 'w') === ($Item['recurrenceCustomUnit'] ?? 'w')
                    && (int)($parsed['recurrenceCustomValue'] ?? 1) === (int)($Item['recurrenceCustomValue'] ?? 1));
            if ($dueMatches && $sameRec && $sameCustom) {
                return $raw;
            }
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
        // B1: relative patterns ("2nd Tuesday of the month") cannot be represented exactly by
        // the local model — approximate them to monthly/quarterly/yearly for display. The exact
        // pattern is preserved on the server via the raw-recurrence round-trip in build.
        if ($type === 'relativemonthly') {
            return ['recurrence' => $interval === 3 ? 'q1' : 'm1', 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
        }
        if ($type === 'relativeyearly') {
            return ['recurrence' => 'y1', 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
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

        // Package 1/finding: an HTML body (Outlook/Exchange) is shown/edited as readable text;
        // the raw HTML is kept in microsoftBodyRaw and re-sent verbatim while unchanged, so the
        // first genuine note edit produces clean text instead of dumping raw HTML as plaintext.
        $bodyContent = (string)($Task['body']['content'] ?? '');
        $info = strtolower((string)($Task['body']['contentType'] ?? 'text')) === 'html'
            ? $this->MicrosoftHtmlToText($bodyContent)
            : $bodyContent;

        return [
            'microsoftTaskId' => $Task['id'] ?? '',
            'microsoftEtag' => $Task['@odata.etag'] ?? '',
            'microsoftUpdated' => $updated,
            'title' => $Task['title'] ?? '',
            'info' => $info,
            'done' => $done,
            'doneAt' => $doneAt,
            'due' => $due,
            'priority' => $priority,
            'notification' => $notification,
            'notificationLeadTime' => $notificationLeadTime,
            'recurrence' => $recurrence,
            'recurrenceCustomUnit' => $recurrenceCustomUnit,
            'recurrenceCustomValue' => $recurrenceCustomValue,
            'microsoftRecurrenceRaw' => is_array($Task['recurrence'] ?? null) ? $Task['recurrence'] : null, // B1
            // Package 1 — raw-field preservation: keep the exact server body/status/reminder so
            // an upload that leaves those fields unchanged does not flatten them (HTML body →
            // text, inProgress/waitingOnOthers → notStarted, exact reminder → rounded lead). The
            // snapshot is the imported baseline the upload diffs the local values against.
            'microsoftBodyRaw' => is_array($Task['body'] ?? null) ? $Task['body'] : null,
            'microsoftStatusRaw' => (string)($Task['status'] ?? ''),
            'microsoftReminderRaw' => is_array($Task['reminderDateTime'] ?? null) ? $Task['reminderDateTime'] : null,
            'microsoftSnapshot' => [
                'info' => $info,
                'done' => $done,
                'due' => $due,
                'notification' => $notification,
                'notificationLeadTime' => $notificationLeadTime
            ]
        ];
    }

    private function MicrosoftHtmlToText(string $Html): string
    {
        $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $Html) ?? $Html;
        $t = preg_replace('/<\/\s*(p|div|li|tr|h[1-6])\s*>/i', "\n", $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // collapse the runs of blank lines HTML block tags leave behind
        $t = preg_replace("/[ \t]+\n/", "\n", $t) ?? $t;
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    private function LocalToMicrosoftTask(array $Item): array
    {
        $snap = is_array($Item['microsoftSnapshot'] ?? null) ? $Item['microsoftSnapshot'] : null;
        $due = (int)($Item['due'] ?? 0);

        $task = ['title' => $Item['title'] ?? ''];

        // Package 1 — body: re-send the exact server body (preserves an HTML note) while the
        // local text is unchanged; only a real local edit downgrades it to a plain-text body.
        $bodyRaw = $Item['microsoftBodyRaw'] ?? null;
        if ($snap !== null && is_array($bodyRaw) && (string)($Item['info'] ?? '') === (string)($snap['info'] ?? '')) {
            $task['body'] = $bodyRaw;
        } else {
            $task['body'] = ['contentType' => 'text', 'content' => $Item['info'] ?? ''];
        }

        if ($due > 0) {
            $task['dueDateTime'] = $this->MicrosoftBuildDateTimeTimeZone($due);
        }

        $task['importance'] = $this->MicrosoftMapPriorityToImportance((string)($Item['priority'] ?? 'normal'));

        $notification = !empty($Item['notification']);
        if ($due > 0 && $notification) {
            $task['isReminderOn'] = true;
            // Package 1 — reminder: keep the exact server reminder time while notification/lead/
            // due are unchanged, so a foreign non-standard offset is not rounded to a local step.
            $remRaw = $Item['microsoftReminderRaw'] ?? null;
            $remUnchanged = $snap !== null && is_array($remRaw)
                && (bool)($snap['notification'] ?? false) === true
                && (int)($snap['notificationLeadTime'] ?? -1) === (int)($Item['notificationLeadTime'] ?? -2)
                && (int)($snap['due'] ?? 0) === $due;
            if ($remUnchanged) {
                $task['reminderDateTime'] = $remRaw;
            } else {
                $lead = $this->NormalizeNotificationLeadTime($Item['notificationLeadTime'] ?? null, $this->NormalizeNotificationLeadTimeDefault((int)$this->ReadPropertyInteger('NotificationLeadTime')));
                $remTs = max(0, $due - $lead);
                if ($remTs > 0) {
                    $task['reminderDateTime'] = $this->MicrosoftBuildDateTimeTimeZone($remTs);
                }
            }
        } else {
            $task['isReminderOn'] = false;
        }

        $recurrence = $this->MicrosoftBuildRecurrence($Item);
        if ($recurrence !== null) {
            $task['recurrence'] = $recurrence;
        }

        // Package 1 — status: keep the exact server status (IN-PROGRESS/waitingOnOthers/deferred)
        // while the local done-state is unchanged; only a real local toggle writes completed/
        // notStarted. (A recurring task's completion is still re-derived in MicrosoftUploadTask, B3.)
        $statusRaw = (string)($Item['microsoftStatusRaw'] ?? '');
        if ($snap !== null && $statusRaw !== '' && (bool)($Item['done'] ?? false) === (bool)($snap['done'] ?? false)) {
            $task['status'] = $statusRaw;
        } else {
            $task['status'] = !empty($Item['done']) ? 'completed' : 'notStarted';
        }

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
        // A date-only due arrives as midnight in the server-echoed zone. New format anchors it
        // to LOCAL midnight (date('H:i:s') == 00:00:00); the legacy UTC-format anchors it to
        // UTC midnight (gmdate(...) == 00:00:00). Detect both so the local time-of-day is kept.
        if (date('H:i:s', $ServerDue) === '00:00:00' || gmdate('H:i:s', $ServerDue) === '00:00:00') {
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
        $Local['microsoftRecurrenceRaw'] = $Server['microsoftRecurrenceRaw'] ?? null; // B1: keep raw server pattern
        // Package 1: refresh the raw-field baseline from the server state.
        $Local['microsoftBodyRaw'] = $Server['microsoftBodyRaw'] ?? null;
        $Local['microsoftStatusRaw'] = $Server['microsoftStatusRaw'] ?? '';
        $Local['microsoftReminderRaw'] = $Server['microsoftReminderRaw'] ?? null;
        $Local['microsoftSnapshot'] = $Server['microsoftSnapshot'] ?? null;
        $Local['microsoftEtag'] = $Server['microsoftEtag'];
        $Local['microsoftServerSynced'] = (int)($Server['microsoftUpdated'] ?? 0); // A5 baseline
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

        // IDs already owned by a local item via a real (non-pending) mapping.
        // These must never be adopted by the title-based self-healing below.
        $localOwnedIds = [];
        foreach ($LocalItems as $li) {
            $lid = (string)($li['microsoftTaskId'] ?? '');
            if ($lid !== '' && strpos($lid, 'pending_') !== 0) {
                $localOwnedIds[$lid] = true;
            }
        }

        foreach ($LocalItems as &$local) {
            $taskId = $local['microsoftTaskId'] ?? '';

            if ($taskId === '') {
                // Self-healing: an item without a Microsoft mapping is normally uploaded
                // as a new task. But if it lost its mapping (e.g. legacy data), that would
                // create a duplicate. First try to re-link it to an existing server task
                // with the same title. Only adopts on a single, unambiguous match.
                $adoptId = $this->MicrosoftFindServerMatchByTitle($local, $serverById, $processedIds, $localOwnedIds);
                if ($adoptId !== null) {
                    $processedIds[$adoptId] = true;
                    $local['microsoftTaskId'] = $adoptId;
                    $server = $serverById[$adoptId];
                    $localMod = (int)($local['localModified'] ?? 0);
                    $lastSynced = (int)($local['microsoftSynced'] ?? 0);
                    if ($localMod >= $lastSynced && $localMod > 0) { // >= catches same-second edits
                        // Local has genuine unsynced edits: push them to the existing task.
                        $toUpload[] = $local;
                    } else {
                        // Otherwise take the server state as source of truth.
                        $this->MicrosoftApplyServerToLocal($local, $server);
                    }
                    $this->SendDebug('MicrosoftToDo', 'Re-linked by title: ' . ($local['title'] ?? $adoptId), 0);
                    continue;
                }

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
                $localChanged = $localMod >= $lastSynced && $localMod > 0; // >= catches same-second edits

                if (strpos($taskId, 'pending_') !== 0) {
                    if ($ConflictMode === 'local_wins' && $localChanged) {
                        $newId = 'pending_' . $this->InstanceID . '_' . ($local['id'] ?? uniqid());
                        $local['microsoftTaskId'] = $newId;
                        $local['localModified'] = time();
                        $toUpload[] = $local;
                    } else {
                        $local['_microsoftDeleted'] = true;
                    }
                } else {
                    // R4: a create that failed earlier (throttle window, timeout, 5xx) left the
                    // item stranded on its pending_ id — requeue the upload instead of skipping
                    // it forever.
                    $toUpload[] = $local;
                }
                continue;
            }

            $server = $serverById[$taskId];
            $localMod = (int)($local['localModified'] ?? 0);
            $serverMod = (int)($server['microsoftUpdated'] ?? 0);
            $lastSynced = (int)($local['microsoftSynced'] ?? 0);
            // A5: detect a server change by comparing the server timestamp against the server
            // timestamp we last stored (server-vs-server). Comparing it against our local
            // last-sync time would let a host/server clock offset hide a real server edit.
            $serverSynced = (int)($local['microsoftServerSynced'] ?? $serverMod);

            $localChanged = $localMod >= $lastSynced && $localMod > 0; // >= catches same-second edits
            $serverChanged = $serverMod > $serverSynced;

            if ($localChanged && $serverChanged) {
                $localWins = ($ConflictMode === 'local_wins') || ($ConflictMode === 'newest_wins' && $localMod > $serverMod);

                if ($localWins) {
                    // A3: adopt the server's current ETag so the intended overwrite matches
                    // the If-Match precondition instead of being rejected as 412 forever.
                    $local['microsoftEtag'] = $server['microsoftEtag'] ?? ($local['microsoftEtag'] ?? '');
                    $toUpload[] = $local;
                } else {
                    $this->MicrosoftApplyServerToLocal($local, $server);
                }
            } elseif ($localChanged) {
                // Fresh-ETag: condition the PATCH on THIS run's fetch, not on the last sync's
                // snapshot — a stored etag can go stale without a server content change (at
                // Google sibling inserts bump etags; defensive parity here for Graph).
                if (($server['microsoftEtag'] ?? '') !== '') {
                    $local['microsoftEtag'] = $server['microsoftEtag'];
                }
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
                'microsoftRecurrenceRaw' => $server['microsoftRecurrenceRaw'] ?? null, // B1
                'microsoftBodyRaw' => $server['microsoftBodyRaw'] ?? null,             // Package 1
                'microsoftStatusRaw' => $server['microsoftStatusRaw'] ?? '',
                'microsoftReminderRaw' => $server['microsoftReminderRaw'] ?? null,
                'microsoftSnapshot' => $server['microsoftSnapshot'] ?? null,
                'microsoftTaskId' => $gid,
                'microsoftEtag' => $server['microsoftEtag'],
                'microsoftSynced' => time(),
                'microsoftServerSynced' => (int)($server['microsoftUpdated'] ?? 0), // A5 baseline
                'localModified' => 0
            ];
            $LocalItems[] = $newItem;
        }

        return [
            'items' => $LocalItems,
            'toUpload' => $toUpload
        ];
    }

    /**
     * Find a server task that matches the given local item by title, used to
     * re-link items that lost their Microsoft mapping instead of creating a
     * duplicate. Returns the matching microsoftTaskId, or null when there is no
     * match or the match is ambiguous (more than one candidate with that title).
     */
    private function MicrosoftFindServerMatchByTitle(array $Local, array $ServerById, array $ProcessedIds, array $OwnedIds): ?string
    {
        $title = $this->MicrosoftNormalizeTitle((string)($Local['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $match = null;
        foreach ($ServerById as $gid => $server) {
            if (isset($ProcessedIds[$gid]) || isset($OwnedIds[$gid])) {
                continue;
            }
            if ($this->MicrosoftNormalizeTitle((string)($server['title'] ?? '')) !== $title) {
                continue;
            }
            if ($match !== null) {
                return null; // ambiguous — do not guess
            }
            $match = $gid;
        }
        return $match;
    }

    private function MicrosoftNormalizeTitle(string $Title): string
    {
        $t = trim($Title);
        return function_exists('mb_strtolower') ? mb_strtolower($t) : strtolower($t);
    }

    private function MicrosoftParseLastModified(mixed $Value): int
    {
        $s = (string)($Value ?? '');
        if ($s === '') {
            return 0;
        }
        try {
            return (new DateTime($s, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception $e) {
            return strtotime($s) ?: 0;
        }
    }

    private function MicrosoftUploadTask(string $ListId, array $Item): array
    {
        $taskId = $Item['microsoftTaskId'] ?? '';
        $data = $this->LocalToMicrosoftTask($Item);

        if ($taskId === '' || strpos($taskId, 'pending_') === 0) {
            $res = $this->MicrosoftApiRequest('POST', '/me/todo/lists/' . urlencode($ListId) . '/tasks', $data);
            if ($res === null) {
                return ['success' => false, 'oldId' => $taskId, 'newId' => '', 'etag' => '', 'updated' => 0];
            }
            return [
                'success' => true,
                'oldId' => $taskId,
                'newId' => $res['id'] ?? '',
                'etag' => $res['@odata.etag'] ?? '',
                'updated' => $this->MicrosoftParseLastModified($res['lastModifiedDateTime'] ?? null)
            ];
        }

        $due = (int)($Item['due'] ?? 0);
        $rec = $this->NormalizeRecurrence($Item['recurrence'] ?? 'none', $due);
        $hadServerRecurrence = is_array($Item['microsoftRecurrenceRaw'] ?? null);
        if ($due <= 0) {
            $data['dueDateTime'] = null;
            $data['isReminderOn'] = false;
            $data['reminderDateTime'] = null;
            if ($hadServerRecurrence) {
                $data['recurrence'] = null; // recurrence removed together with the due date
            } else {
                unset($data['recurrence']);
            }
        } else {
            if (empty($Item['notification'])) {
                $data['isReminderOn'] = false;
                $data['reminderDateTime'] = null;
            }
            // B3: Graph rejects ANY 'recurrence' object in a PATCH with 400 (verified live —
            // even re-sending the server's own pattern verbatim fails). Omitting the property
            // preserves the server-side pattern by PATCH semantics, which also covers the B1
            // raw-round-trip goal. Only a genuine local pattern change is sent, with a
            // recurrence-less retry below so the remaining fields still arrive.
            if ($rec === 'none') {
                if ($hadServerRecurrence) {
                    $data['recurrence'] = null; // user removed the recurrence locally
                } else {
                    unset($data['recurrence']);
                }
            } elseif ($this->MicrosoftRecurrencePatternUnchanged($Item)) {
                unset($data['recurrence']);
            } else {
                $data['recurrence'] = $this->MicrosoftBuildRecurrence($Item);
            }
        }

        // B3: completing a recurring task locally advances the due date (the series rolls
        // forward). Microsoft's native model for the same event is "task stays open at the
        // next occurrence" — PATCHing status=completed would make Graph roll the series a
        // second time. Represent the local roll as notStarted + the new due date.
        if ($rec !== 'none' && !empty($Item['done'])) {
            $data['status'] = 'notStarted';
        }

        // A3: optimistic concurrency — send If-Match so a task changed on the server since
        // our last fetch yields a 412 (write fails, item stays dirty) instead of a silent
        // lost update. The conflict is then reconciled on the next sync.
        $etag = (string)($Item['microsoftEtag'] ?? '');
        $headers = $etag !== '' ? ['If-Match: ' . $etag] : [];
        $url = '/me/todo/lists/' . urlencode($ListId) . '/tasks/' . urlencode($taskId);
        $res = $this->MicrosoftApiRequest('PATCH', $url, $data, $headers);
        if ($res === null && array_key_exists('recurrence', $data)) {
            // B3 fallback: deliver the remaining fields even when Graph refuses the
            // recurrence write; the server-side pattern stays authoritative and the local
            // pattern is reconciled from the next fetch.
            $this->SendDebug('MicrosoftToDo', 'PATCH with recurrence failed – retrying without recurrence', 0);
            unset($data['recurrence']);
            $res = $this->MicrosoftApiRequest('PATCH', $url, $data, $headers);
        }
        return [
            'success' => $res !== null,
            'oldId' => $taskId,
            'newId' => $taskId,
            'etag' => $res['@odata.etag'] ?? '',
            'updated' => $this->MicrosoftParseLastModified($res['lastModifiedDateTime'] ?? null)
        ];
    }

    private function MicrosoftDeleteTask(string $ListId, string $TaskId, string $Etag = ''): bool
    {
        if ($TaskId === '' || strpos($TaskId, 'pending_') === 0) {
            return true; // never persisted on the server → tombstone is done
        }

        // A3/DELETE: send If-Match so a task edited on the server since our last fetch is not
        // blindly removed. '1' is the legacy tombstone value (no ETag) → unconditional delete.
        $headers = ($Etag !== '' && $Etag !== '1') ? ['If-Match: ' . $Etag] : [];
        $status = $this->MicrosoftApiStatus('DELETE', '/me/todo/lists/' . urlencode($ListId) . '/tasks/' . urlencode($TaskId), null, $headers);

        if (($status >= 200 && $status < 300) || $status === 404) {
            return true; // deleted, or already gone
        }
        if ($status === 412) {
            // Concurrent server edit — give up the delete; the server version survives and is
            // re-imported on the next full sync. Drop the tombstone so we don't loop on a stale ETag.
            $this->SendDebug('MicrosoftToDo', 'Delete conflict (412) for ' . $TaskId . ' – keeping server version', 0);
            return true;
        }
        // 0 (transport/throttle) or 5xx → transient, keep the tombstone and retry next sync.
        $this->SendDebug('MicrosoftToDo', 'Delete not confirmed (HTTP ' . $status . ') for ' . $TaskId . ' – will retry', 0);
        return false;
    }

    private function AddMicrosoftPendingDelete(string $TaskId, string $Etag = ''): void
    {
        $this->SyncAddPendingDelete($TaskId, 'pending_', 'MicrosoftPendingDeletes', $Etag);
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
                            'onClick' => 'echo TDL_MicrosoftRefreshListOptions($id);'
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
