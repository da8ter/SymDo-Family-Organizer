<?php

declare(strict_types=1);

trait CalDAVSync
{
    private function UpdateCalDAVTimer(): void
    {
        $enabled = $this->GetSyncBackend() === 'caldav';
        $interval = $this->ReadPropertyInteger('CalDAVSyncInterval');
        if ($enabled && $interval > 0) {
            $this->SetTimerInterval('CalDAVSyncTimer', $interval * 60 * 1000);
        } else {
            $this->SetTimerInterval('CalDAVSyncTimer', 0);
        }

        if (!$enabled) {
            $this->SetTimerInterval('CalDAVOnChangeTimer', 0);
        }
    }

    private function GetCalDAVStatusLabel(): string
    {
        $lastSync = $this->ReadAttributeInteger('CalDAVLastSync');
        if ($lastSync <= 0) {
            return $this->Translate('Last sync') . ': ' . $this->Translate('Never');
        }
        return $this->Translate('Last sync') . ': ' . date('d.m.Y H:i:s', $lastSync);
    }

    public function CalDAVTestConnection(): bool
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            echo $this->Translate('Connection failed');
            return false;
        }
        return TGW_CalDAVTestConnection($gw);
    }

    public function CalDAVResetSync(): void
    {
        $this->SyncResetItems(
            ['caldavUid'],
            ['caldavEtag', 'caldavHref'],
            ['caldavSynced'],
            'CalDAVLastSync',
            'CalDAVPendingDeletes'
        );
    }

    public function CalDAVSync(): bool
    {
        $sem = 'TDL_CalDAVSync_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 0)) {
            $this->SendDebug('CalDAV', 'Sync skipped - already running', 0);
            return false;
        }

        try {
            return $this->CalDAVSyncInternal();
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    public function CalDAVSyncOnChange(): void
    {
        $this->SetTimerInterval('CalDAVOnChangeTimer', 0);

        if ($this->GetSyncBackend() !== 'caldav') {
            return;
        }
        $sem = 'TDL_CalDAVSync_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 0)) {
            $this->SetTimerInterval('CalDAVOnChangeTimer', 3000);
            return;
        }

        try {
            $this->CalDAVSyncInternal();
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    private function CalDAVSyncInternal(): bool
    {
        if ($this->GetSyncBackend() !== 'caldav') {
            $this->SendDebug('CalDAV', 'Sync skipped - not enabled', 0);
            return false;
        }

        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            $this->SendDebug('CalDAV', 'Sync skipped - no ToDoGateway', 0);
            return false;
        }

        $creds = TGW_CalDAVGetCredentials($gw);
        $url = $creds['url'] ?? '';
        $user = $creds['user'] ?? '';
        $pass = $creds['pass'] ?? '';
        $calendarPath = trim($this->ReadPropertyString('CalDAVCalendarPath'));

        if ($url === '' || $user === '' || $pass === '' || $calendarPath === '') {
            $this->SendDebug('CalDAV', 'Sync skipped - missing configuration', 0);
            return false;
        }

        $this->SendDebug('CalDAV', 'Starting sync... GW=' . $gw, 0);
        $this->SendDebug('CalDAV', 'ServerURL: ' . $url, 0);
        $this->SendDebug('CalDAV', 'CalendarPath: ' . $calendarPath, 0);

        try {
            $calendarUrl = $this->CalDAVResolveUrl($url, $calendarPath);
            $this->SendDebug('CalDAV', 'Calendar URL: ' . $calendarUrl, 0);

            // A1: skip the expensive full REPORT when the collection is unchanged (CTag) AND
            // there is nothing local to push. Only the idle case is short-circuited; any real
            // change on either side falls through to the full, deletion-safe merge below.
            $serverCTag = $this->CalDAVGetCTag($calendarUrl, $user, $pass);
            $storedCTag = $this->ReadAttributeString('CalDAVSyncToken');
            if ($serverCTag !== null && $serverCTag !== '' && $serverCTag === $storedCTag && !$this->CalDAVHasPendingWork()) {
                $this->SendDebug('CalDAV', 'Collection unchanged (CTag ' . $serverCTag . ') and no local changes – skipping full sync', 0);
                $this->WriteAttributeInteger('CalDAVLastSync', time());
                return true;
            }

            $serverItems = $this->CalDAVFetchItems($calendarUrl, $user, $pass);

            if ($serverItems === null) {
                $this->SendDebug('CalDAV', 'Failed to fetch items from server', 0);
                return false;
            }

            $pendingDeletes = json_decode((string)$this->ReadAttributeString('CalDAVPendingDeletes'), true);
            if (!is_array($pendingDeletes)) {
                $pendingDeletes = [];
            }
            if (count($pendingDeletes) > 0) {
                $pendingUids = array_keys($pendingDeletes);
                $serverItems = array_values(array_filter($serverItems, function (array $si) use ($pendingUids): bool {
                    return !in_array((string)($si['caldavUid'] ?? ''), $pendingUids, true);
                }));

                foreach ($pendingDeletes as $uid => $tombstone) {
                    $uid = (string)$uid;
                    if ($uid === '') {
                        unset($pendingDeletes[$uid]);
                        continue;
                    }
                    // Tombstone value is JSON {href, etag}; legacy tombstones stored a plain href.
                    $href = (string)$tombstone;
                    $etag = '';
                    $decoded = json_decode((string)$tombstone, true);
                    if (is_array($decoded)) {
                        $href = (string)($decoded['href'] ?? '');
                        $etag = (string)($decoded['etag'] ?? '');
                    }
                    $ok = $this->CalDAVDeleteItem($calendarUrl, $user, $pass, $uid, $href, $etag);
                    if ($ok) {
                        unset($pendingDeletes[$uid]);
                        $this->SendDebug('CalDAV', 'Deleted on server: ' . $uid, 0);
                    } else {
                        $this->SendDebug('CalDAV', 'Server delete failed (will retry): ' . $uid, 0);
                    }
                }
                $this->WriteAttributeString('CalDAVPendingDeletes', json_encode($pendingDeletes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            $localItems = $this->LoadItems();
            $conflictMode = $this->ReadPropertyString('CalDAVConflictMode');

            $result = $this->CalDAVMergeItems($localItems, $serverItems, $conflictMode);

            $items = $result['items'];
            $now = time();

            foreach ($result['toUpload'] as $uploadItem) {
                $success = $this->CalDAVUploadItem($calendarUrl, $user, $pass, $uploadItem);
                if ($success) {
                    $uid = $uploadItem['caldavUid'] ?? '';
                    for ($i = 0; $i < count($items); $i++) {
                        if (($items[$i]['caldavUid'] ?? '') === $uid) {
                            $items[$i]['caldavSynced'] = $now;
                            $items[$i]['localModified'] = 0;
                            break;
                        }
                    }
                    $this->SendDebug('CalDAV', 'Uploaded: ' . ($uploadItem['title'] ?? $uid), 0);
                } else {
                    $this->SendDebug('CalDAV', 'Upload failed: ' . ($uploadItem['title'] ?? ''), 0);
                }
            }

            $this->SaveItems($items);
            $this->WriteAttributeInteger('CalDAVLastSync', $now);

            // A1 (TOCTOU-safe): only trust the CTag for a future skip when the collection tag
            // did NOT change over the whole run — i.e. neither a third-party change raced in
            // during/after our REPORT nor our own uploads bumped it. $serverCTag was read
            // before the REPORT. If the tag changed since, our snapshot may be incomplete, so
            // store '' to force a full REPORT next run (which reconciles whatever we missed).
            $postCTag = $this->CalDAVGetCTag($calendarUrl, $user, $pass);
            if ($postCTag !== null && $serverCTag !== null && $postCTag === $serverCTag) {
                $this->WriteAttributeString('CalDAVSyncToken', $postCTag);
            } else {
                $this->WriteAttributeString('CalDAVSyncToken', '');
            }

            $this->SyncPostComplete();

            $this->SendDebug('CalDAV', 'Sync completed', 0);
            return true;
        } catch (Exception $e) {
            $this->SendDebug('CalDAV', 'Sync failed: ' . $e->getMessage(), 0);
            return false;
        }
    }

    private function ScheduleCalDAVSyncOnChange(): void
    {
        if ($this->GetSyncBackend() !== 'caldav') {
            return;
        }

        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            return;
        }
        $creds = TGW_CalDAVGetCredentials($gw);
        $calendarPath = trim($this->ReadPropertyString('CalDAVCalendarPath'));
        if (($creds['url'] ?? '') === '' || ($creds['user'] ?? '') === '' || ($creds['pass'] ?? '') === '' || $calendarPath === '') {
            return;
        }

        $this->SetTimerInterval('CalDAVOnChangeTimer', 3000);
    }

    private function CalDAVGatewayRequest(string $Method, string $Url, string $User, string $Pass, array $Headers, string $Body = '', int $Timeout = 15): array
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'url' => $Url];
        }
        return TGW_CalDAVRequest($gw, $Method, $Url, $User, $Pass, $Headers, $Body, $Timeout);
    }

    /**
     * A1: read the collection tag (CalendarServer getctag, or DAV:sync-token as a fallback)
     * via a cheap Depth:0 PROPFIND. Returns null when the server does not expose one, in
     * which case the caller falls back to a full REPORT every run (no regression).
     */
    private function CalDAVGetCTag(string $CalendarUrl, string $User, string $Pass): ?string
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/">'
            . '<d:prop><cs:getctag/><d:sync-token/></d:prop></d:propfind>';
        $res = $this->CalDAVGatewayRequest('PROPFIND', $CalendarUrl, $User, $Pass, [
            'Depth: 0',
            'Content-Type: application/xml; charset=utf-8'
        ], $body, 10);

        $status = (int)($res['status'] ?? 0);
        if ($status !== 207 && $status !== 200) {
            return null;
        }
        $xml = (string)($res['body'] ?? '');
        if (preg_match('/<[a-z0-9]*:?getctag[^>]*>([^<]+)<\/[a-z0-9]*:?getctag>/i', $xml, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/<[a-z0-9]*:?sync-token[^>]*>([^<]+)<\/[a-z0-9]*:?sync-token>/i', $xml, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * A1: true when there is something local to push (pending server-deletes, a locally
     * edited item, or a not-yet-uploaded new item). Used to keep the CTag short-circuit
     * from swallowing local changes.
     */
    private function CalDAVHasPendingWork(): bool
    {
        $pending = json_decode((string)$this->ReadAttributeString('CalDAVPendingDeletes'), true);
        if (is_array($pending) && count($pending) > 0) {
            return true;
        }
        foreach ($this->LoadItems() as $it) {
            if (($it['caldavUid'] ?? '') === '') {
                return true;
            }
            if ((int)($it['localModified'] ?? 0) >= (int)($it['caldavSynced'] ?? 0) && (int)($it['localModified'] ?? 0) > 0) {
                return true; // >= catches same-second edits
            }
        }
        return false;
    }

    private function CalDAVFetchItems(string $CalendarUrl, string $User, string $Pass): ?array
    {
        $res = $this->CalDAVGatewayRequest(
            'REPORT',
            $CalendarUrl,
            $User,
            $Pass,
            [
                'Depth: 1',
                'Content-Type: application/xml; charset=utf-8'
            ],
            '<?xml version="1.0" encoding="utf-8"?>' .
                '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' .
                '<d:prop><d:getetag/><c:calendar-data/></d:prop>' .
                '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VTODO"/></c:comp-filter></c:filter>' .
                '</c:calendar-query>',
            30
        );

        $statusCode = (int)($res['status'] ?? 0);
        $this->SendDebug('CalDAV Fetch', 'URL: ' . $CalendarUrl . ' | Status: ' . $statusCode, 0);

        if ($statusCode === 0) {
            $this->SendDebug('CalDAV Fetch', 'Request failed (status 0) - gateway returned: ' . json_encode(array_keys($res)), 0);
            return null;
        }

        if ($statusCode !== 207) {
            $this->SendDebug('CalDAV Fetch', 'Unexpected status ' . $statusCode . ' | Body: ' . substr((string)($res['body'] ?? ''), 0, 500), 0);
            return null;
        }

        return $this->CalDAVParseMultiStatus((string)($res['body'] ?? ''));
    }

    private function CalDAVParseMultiStatus(string $Xml): ?array
    {
        $items = [];

        // R2: a body we cannot parse must fail the whole fetch. Returning [] here would make
        // the merge treat every synced local item as deleted on the server (mass local
        // deletion from one malformed/truncated 207 response).
        $xml = @simplexml_load_string($Xml);
        if ($xml === false) {
            $this->SendDebug('CalDAV', 'Unparseable multistatus response – aborting fetch', 0);
            return null;
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        $responses = $xml->xpath('//d:response');
        foreach ($responses as $response) {
            $response->registerXPathNamespace('d', 'DAV:');
            $response->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
            
            $hrefNodes = $response->xpath('d:href');
            $href = !empty($hrefNodes) ? (string)$hrefNodes[0] : '';
            
            $etagNodes = $response->xpath('d:propstat/d:prop/d:getetag');
            $etag = !empty($etagNodes) ? (string)$etagNodes[0] : '';
            
            $calDataNodes = $response->xpath('d:propstat/d:prop/c:calendar-data');
            $calData = !empty($calDataNodes) ? (string)$calDataNodes[0] : '';

            if ($calData !== '') {
                $vtodo = $this->CalDAVParseVTodo($calData);
                if ($vtodo !== null) {
                    $vtodo['caldavHref'] = $href;
                    $vtodo['caldavEtag'] = $this->CalDAVNormalizeEtag($etag);
                    $items[] = $vtodo;
                }
            }
        }

        return $items;
    }

    private function CalDAVParseVTodo(string $ICalData): ?array
    {
        $rawLines = preg_split('/\r?\n/', $ICalData);
        if ($rawLines === false) {
            return null;
        }

        $lines = [];
        foreach ($rawLines as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && count($lines) > 0) {
                $lines[count($lines) - 1] .= substr($line, 1);
            } else {
                $lines[] = $line;
            }
        }

        $inVTodo = false;
        $nestedDepth = 0;
        $props = [];
        $tzids = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === 'BEGIN:VTODO') {
                $inVTodo = true;
                continue;
            }
            if (!$inVTodo) {
                continue;
            }
            // R9: skip nested components (VALARM etc.) entirely — their DESCRIPTION/SUMMARY/UID
            // lines must not overwrite the task's own properties.
            if (strncmp($line, 'BEGIN:', 6) === 0) {
                $nestedDepth++;
                continue;
            }
            if (strncmp($line, 'END:', 4) === 0) {
                if ($nestedDepth > 0) {
                    $nestedDepth--;
                    continue;
                }
                break; // END:VTODO
            }
            if ($nestedDepth > 0) {
                continue;
            }

            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $params = explode(';', $key);
                $propName = strtoupper($params[0]);
                $props[$propName] = $value;
                for ($pi = 1; $pi < count($params); $pi++) {
                    if (stripos($params[$pi], 'TZID=') === 0) {
                        // TZID values may be quoted (TZID="America/New_York").
                        $tzids[$propName] = trim(substr($params[$pi], 5), '"');
                    }
                }
            }
        }

        if (empty($props['UID'])) {
            return null;
        }

        $done = ($props['STATUS'] ?? '') === 'COMPLETED';
        $priority = 'normal';
        $priVal = (int)($props['PRIORITY'] ?? 0);
        if ($priVal >= 1 && $priVal <= 4) {
            $priority = 'high';
        } elseif ($priVal >= 6 && $priVal <= 9) {
            $priority = 'low';
        }

        return [
            'caldavUid' => $props['UID'],
            'title' => $this->CalDAVUnescapeText($props['SUMMARY'] ?? ''),
            'info' => $this->CalDAVUnescapeText($props['DESCRIPTION'] ?? ''),
            'done' => $done,
            'doneAt' => $done ? $this->CalDAVParseDateTime($props['COMPLETED'] ?? '', $tzids['COMPLETED'] ?? '') : 0,
            'due' => $this->CalDAVParseDateTime($props['DUE'] ?? '', $tzids['DUE'] ?? ''),
            'priority' => $priority,
            'createdAt' => $this->CalDAVParseDateTime($props['CREATED'] ?? '', $tzids['CREATED'] ?? ''),
            'caldavLastModified' => $this->CalDAVParseDateTime($props['LAST-MODIFIED'] ?? '', $tzids['LAST-MODIFIED'] ?? '')
        ];
    }

    private function CalDAVParseDateTime(string $Value, string $Tzid = ''): int
    {
        if ($Value === '') {
            return 0;
        }
        $Value = preg_replace('/[^0-9TZ]/', '', $Value) ?? $Value;

        $isUtc = str_ends_with($Value, 'Z');

        if ($isUtc) {
            $dt = DateTime::createFromFormat('Ymd\THis\Z', $Value, new DateTimeZone('UTC'));
            if ($dt !== false) {
                return $dt->getTimestamp();
            }
        }

        $tz = 'UTC';
        if (!$isUtc && $Tzid !== '') {
            try {
                new DateTimeZone($Tzid);
                $tz = $Tzid;
            } catch (Exception $e) {
                $tz = date_default_timezone_get() ?: 'UTC';
            }
        } elseif (!$isUtc) {
            $tz = date_default_timezone_get() ?: 'UTC';
        }

        // R20: '!' resets unspecified fields — without it a date-only value ('Ymd') picks up
        // the CURRENT wall-clock time, making the parsed timestamp nondeterministic.
        $formats = ['!Ymd\THis', '!Ymd'];
        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $Value, new DateTimeZone($tz));
            if ($dt !== false) {
                return $dt->getTimestamp();
            }
        }
        return 0;
    }

    private function CalDAVMergeItems(array $LocalItems, array $ServerItems, string $ConflictMode): array
    {
        $result = [];
        $toUpload = [];
        $toDelete = [];

        $serverByUid = [];
        foreach ($ServerItems as $si) {
            $serverByUid[$si['caldavUid']] = $si;
        }

        $localByUid = [];
        foreach ($LocalItems as $li) {
            if (!empty($li['caldavUid'])) {
                $localByUid[$li['caldavUid']] = $li;
            }
        }

        foreach ($LocalItems as $local) {
            $uid = $local['caldavUid'] ?? '';
            
            if ($uid === '') {
                $uid = 'symcon-' . $this->InstanceID . '-' . $local['id'];
                $local['caldavUid'] = $uid;
                $local['caldavHref'] = $local['caldavHref'] ?? '';
                $toUpload[] = $local;
                $result[] = $local;
                continue;
            }

            if (!isset($serverByUid[$uid])) {
                if (($local['caldavSynced'] ?? 0) > 0) {
                    continue;
                } else {
                    $toUpload[] = $local;
                    $result[] = $local;
                }
                continue;
            }

            $server = $serverByUid[$uid];
            unset($serverByUid[$uid]);

            $serverEtag = $server['caldavEtag'] ?? '';
            $localEtag = $local['caldavEtag'] ?? '';
            $localModified = $local['localModified'] ?? 0;
            $lastSynced = $local['caldavSynced'] ?? 0;

            if (!empty($server['caldavHref'])) {
                $local['caldavHref'] = $server['caldavHref'];
            }

            if ($serverEtag === $localEtag && $localModified < $lastSynced) {
                $result[] = $local;
                continue;
            }

            if ($localModified >= $lastSynced && $localModified > 0 && $serverEtag !== $localEtag) {
                $merged = $this->CalDAVResolveConflict($local, $server, $ConflictMode);
                if ($merged['uploadLocal']) {
                    $toUpload[] = $merged['item'];
                }
                $result[] = $merged['item'];
            } elseif ($serverEtag !== $localEtag) {
                $merged = $this->CalDAVApplyServerChanges($local, $server);
                $result[] = $merged;
            } else {
                $toUpload[] = $local;
                $result[] = $local;
            }
        }

        foreach ($serverByUid as $uid => $server) {
            $newItem = [
                'id' => $this->GetNextItemID(),
                'title' => $server['title'],
                'info' => $server['info'],
                'done' => $server['done'],
                'doneAt' => $server['doneAt'],
                'due' => $server['due'],
                'priority' => $server['priority'],
                'createdAt' => $server['createdAt'] ?: time(),
                'caldavUid' => $uid,
                'caldavEtag' => $server['caldavEtag'],
                'caldavHref' => $server['caldavHref'] ?? '',
                'caldavSynced' => time(),
                'localModified' => 0,
                'notification' => false,
                'notificationLeadTime' => 0,
                'notified' => false,
                'quantity' => 1,
                'recurrence' => 'none',
                'recurrenceCustomUnit' => '',
                'recurrenceCustomValue' => 0,
                'recurrenceReopenDays' => 0
            ];
            $result[] = $newItem;
        }

        return [
            'items' => $result,
            'toUpload' => $toUpload,
            'toDelete' => $toDelete
        ];
    }

    private function CalDAVResolveConflict(array $Local, array $Server, string $Mode): array
    {
        switch ($Mode) {
            case 'local_wins':
                return ['item' => $this->CalDAVAdoptServerEtag($Local, $Server), 'uploadLocal' => true];

            case 'newest_wins':
                $localMod = $Local['localModified'] ?? 0;
                $serverMod = $Server['caldavLastModified'] ?? 0;
                if ($localMod >= $serverMod) {
                    return ['item' => $this->CalDAVAdoptServerEtag($Local, $Server), 'uploadLocal' => true];
                }
                return ['item' => $this->CalDAVApplyServerChanges($Local, $Server), 'uploadLocal' => false];

            case 'server_wins':
            default:
                return ['item' => $this->CalDAVApplyServerChanges($Local, $Server), 'uploadLocal' => false];
        }
    }

    /**
     * R7/A3: an intended local-wins overwrite must carry the server's CURRENT ETag, so the
     * conditional PUT (If-Match) succeeds. Keeping the stale local ETag would make every
     * upload fail 412, retry the identical conflict next sync and never converge.
     */
    private function CalDAVAdoptServerEtag(array $Local, array $Server): array
    {
        if (($Server['caldavEtag'] ?? '') !== '') {
            $Local['caldavEtag'] = $Server['caldavEtag'];
        }
        if (!empty($Server['caldavHref'])) {
            $Local['caldavHref'] = $Server['caldavHref'];
        }
        return $Local;
    }

    private function CalDAVApplyServerChanges(array $Local, array $Server): array
    {
        $Local['title'] = $Server['title'];
        $Local['info'] = $Server['info'];
        $Local['done'] = $Server['done'];
        $Local['doneAt'] = $Server['doneAt'];
        $Local['due'] = $Server['due'];
        $Local['priority'] = $Server['priority'];
        $Local['caldavEtag'] = $Server['caldavEtag'];
        if (!empty($Server['caldavHref'])) {
            $Local['caldavHref'] = $Server['caldavHref'];
        }
        $Local['caldavSynced'] = time();
        $Local['localModified'] = 0;
        return $Local;
    }

    private function CalDAVUploadItem(string $CalendarUrl, string $User, string $Pass, array $Item): bool
    {
        $uid = $Item['caldavUid'] ?? '';
        if ($uid === '') {
            return false;
        }

        $vcal = $this->CalDAVBuildVTodo($Item);
        $href = $Item['caldavHref'] ?? '';
        if ($href !== '') {
            $itemUrl = $this->CalDAVResolveUrl($CalendarUrl, $href);
        } else {
            $itemUrl = rtrim($CalendarUrl, '/') . '/' . urlencode($uid) . '.ics';
        }

        $headers = [
            'Content-Type: text/calendar; charset=utf-8'
        ];

        $etag = $Item['caldavEtag'] ?? '';
        if ($etag !== '') {
            $headers[] = 'If-Match: ' . $this->CalDAVEtagHeaderValue($etag);
        } else {
            // A3/D1: creating a new resource — refuse to overwrite an existing one at this URL
            // (out-of-band create, lost create response, UID/name collision). A 412 makes the
            // create fail so the existing server copy is fetched and reconciled next sync.
            $headers[] = 'If-None-Match: *';
        }

        $res = $this->CalDAVGatewayRequest('PUT', $itemUrl, $User, $Pass, $headers, $vcal, 10);

        $statusCode = (int)($res['status'] ?? 0);
        $this->SendDebug('CalDAV Upload', 'URL: ' . ($res['url'] ?? $itemUrl), 0);
        $this->SendDebug('CalDAV Upload', 'Status: ' . $statusCode, 0);
        if ($statusCode < 200 || $statusCode >= 300) {
            $this->SendDebug('CalDAV Upload', 'Response: ' . (($res['body'] ?? '') ?: 'empty'), 0);
        }
        return ($statusCode >= 200 && $statusCode < 300);
    }

    private function CalDAVDeleteItem(string $CalendarUrl, string $User, string $Pass, string $Uid, string $Href = '', string $Etag = ''): bool
    {
        if ($Href !== '') {
            $itemUrl = $this->CalDAVResolveUrl($CalendarUrl, $Href);
        } else {
            $itemUrl = rtrim($CalendarUrl, '/') . '/' . urlencode($Uid) . '.ics';
        }

        // A3/DELETE: If-Match so a resource changed on the server since our last fetch is not
        // blindly removed. '1' is the legacy tombstone value (no ETag) → unconditional delete.
        $headers = ($Etag !== '' && $Etag !== '1') ? ['If-Match: ' . $this->CalDAVEtagHeaderValue($Etag)] : [];
        $res = $this->CalDAVGatewayRequest('DELETE', $itemUrl, $User, $Pass, $headers, '', 10);
        $statusCode = (int)($res['status'] ?? 0);

        if (($statusCode >= 200 && $statusCode < 300) || $statusCode === 404) {
            return true; // deleted, or already gone
        }
        if ($statusCode === 412) {
            // Concurrent server edit — give up the delete (server version survives, re-imported
            // next full sync). Drop the tombstone so we don't loop on a stale ETag.
            $this->SendDebug('CalDAV', 'Delete conflict (412) for ' . $Uid . ' – keeping server version', 0);
            return true;
        }
        return false; // transient (0/5xx) → keep tombstone, retry next sync
    }

    private function CalDAVBuildVTodo(array $Item): string
    {
        // R3: the .ics UID MUST be exactly the stored caldavUid. Writing a different UID than
        // the one persisted locally makes the next full REPORT treat this item as deleted on
        // the server (its UID is not in the response) and re-import the server copy as a brand
        // new task — losing every local-only field.
        $uid = (string)($Item['caldavUid'] ?? '');
        if ($uid === '') {
            // Defensive fallback only — the merge always assigns a UID before queueing uploads.
            $uid = 'symcon-' . $this->InstanceID . '-' . (int)($Item['id'] ?? 0);
        }

        $now = gmdate('Ymd\THis\Z');
        $created = ($Item['createdAt'] ?? 0) > 0 ? gmdate('Ymd\THis\Z', $Item['createdAt']) : $now;
        
        $status = ($Item['done'] ?? false) ? 'COMPLETED' : 'NEEDS-ACTION';
        $percentComplete = ($Item['done'] ?? false) ? 100 : 0;
        $priority = match($Item['priority'] ?? 'normal') {
            'high' => 1,
            'low' => 9,
            default => 0
        };

        // R8: local values are plain text — they are escaped exactly once below.
        $titleText = (string)($Item['title'] ?? '');
        $infoText = (string)($Item['info'] ?? '');

        // R19: no METHOD property — RFC 4791 §4.1 forbids it in calendar object resources
        // (it was previously injected for iCloud, which does not need it either).
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Symcon//ToDoList//EN',
            'CALSCALE:GREGORIAN'
        ];

        $lines = array_merge($lines, [
            'BEGIN:VTODO',
            'UID:' . $uid,
            'DTSTAMP:' . $now,
            'CREATED:' . $created,
            'LAST-MODIFIED:' . $now,
            'SEQUENCE:0',
            'SUMMARY:' . $this->CalDAVEscapeText($titleText),
            'STATUS:' . $status,
            'PERCENT-COMPLETE:' . $percentComplete
        ]);

        if ($priority > 0) {
            $lines[] = 'PRIORITY:' . $priority;
        }

        if ($infoText !== '') {
            $lines[] = 'DESCRIPTION:' . $this->CalDAVEscapeText($infoText);
        }

        if (($Item['due'] ?? 0) > 0) {
            // D2: always serialize DUE as a UTC instant (Z-form). Emitting DUE;TZID=<zone>
            // without a matching VTIMEZONE component is invalid per RFC 5545 and strict
            // servers/clients reject it or misread the time.
            $lines[] = 'DUE:' . gmdate('Ymd\THis\Z', $Item['due']);
        }

        if ($Item['done'] && ($Item['doneAt'] ?? 0) > 0) {
            $lines[] = 'COMPLETED:' . gmdate('Ymd\THis\Z', $Item['doneAt']);
        }

        $lines[] = 'END:VTODO';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    private function CalDAVEscapeText(string $Text): string
    {
        // R8: escape the backslash FIRST — otherwise the backslashes just inserted for
        // \n \, \; get doubled and the wire format becomes RFC-5545-invalid (other clients
        // then display a literal "\n" instead of a line break).
        $Text = str_replace('\\', '\\\\', $Text);
        return str_replace(["\r\n", "\n", "\r", ',', ';'], ['\\n', '\\n', '\\n', '\\,', '\\;'], $Text);
    }

    private function CalDAVUnescapeText(string $Text): string
    {
        if ($Text === '') {
            return '';
        }
        // R8: one left-to-right pass per RFC 5545 §3.3.11 — "\\" is a literal backslash and
        // must not be collapsed before "\n"/"\,"/"\;" are resolved (or vice versa).
        return preg_replace_callback('/\\\\([\\\\;,nN])/', static function (array $m): string {
            return ($m[1] === 'n' || $m[1] === 'N') ? "\n" : $m[1];
        }, $Text) ?? $Text;
    }

    /**
     * R16: normalize an ETag for storage/comparison. Strong ETags are stored without their
     * surrounding quotes (matches legacy stored values); a weak ETag (W/"...") is kept
     * verbatim so the If-Match header can be reconstructed instead of being mangled.
     */
    private function CalDAVNormalizeEtag(string $Etag): string
    {
        $Etag = trim($Etag);
        if (strncmp($Etag, 'W/', 2) === 0) {
            return $Etag;
        }
        return trim($Etag, '"');
    }

    private function CalDAVEtagHeaderValue(string $Etag): string
    {
        if (strncmp($Etag, 'W/', 2) === 0 || (strlen($Etag) >= 2 && $Etag[0] === '"')) {
            return $Etag; // already a complete entity-tag
        }
        return '"' . $Etag . '"';
    }

    public function CalDAVRefreshCalendarOptions(): void
    {
        $stored = $this->CalDAVFetchAndStoreCalendarOptions();
        if ($stored === null) {
            echo $this->Translate('Failed to fetch calendars.');
            return;
        }

        if (empty($stored)) {
            echo $this->Translate('No calendars found.');
            return;
        }

        $options = $this->GetCalDAVCalendarOptions();
        $this->UpdateFormField('CalDAVCalendarPath', 'options', json_encode($options));
        echo sprintf($this->Translate('Found %d calendar(s).'), count($stored));
    }

    public function CalDAVDiscoverCalendars(): string
    {
        ob_start();
        $this->CalDAVRefreshCalendarOptions();
        return (string)ob_get_clean();
    }

    private function CalDAVFetchAndStoreCalendarOptions(): ?array
    {
        $gw = $this->GetGatewayID();
        if ($gw === 0) {
            return null;
        }

        $calendars = TGW_CalDAVDiscoverCalendars($gw);
        if (!is_array($calendars) || empty($calendars)) {
            return $calendars === false ? null : [];
        }

        $stored = [];
        foreach ($calendars as $cal) {
            $stored[] = [
                'name' => $cal['name'] ?? '',
                'path' => $cal['path'] ?? '',
                'supportsTodo' => $cal['supportsTodo'] ?? false
            ];
        }

        $this->WriteAttributeString('CalDAVCalendarOptions', json_encode($stored));
        return $stored;
    }

    private function GetCalDAVCalendarOptions(): array
    {
        $options = [['caption' => $this->Translate('Please select...'), 'value' => '']];

        $stored = json_decode($this->ReadAttributeString('CalDAVCalendarOptions'), true);
        if (is_array($stored)) {
            foreach ($stored as $cal) {
                $name = $cal['name'] ?? 'Untitled';
                $path = $cal['path'] ?? '';
                $todo = !empty($cal['supportsTodo']);
                $suffix = $todo ? ' (VTODO)' : ' (' . $this->Translate('Events only') . ')';
                $options[] = [
                    'caption' => $name . $suffix,
                    'value' => $path
                ];
            }
        }

        $currentPath = $this->ReadPropertyString('CalDAVCalendarPath');
        if ($currentPath !== '') {
            $found = false;
            foreach ($options as $opt) {
                if ($opt['value'] === $currentPath) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $options[] = ['caption' => $currentPath, 'value' => $currentPath];
            }
        }

        return $options;
    }

    private function CalDAVResolveUrl(string $BaseUrl, string $Path): string
    {
        if (strpos($Path, '://') !== false) {
            return $Path;
        }

        $parsed = parse_url($BaseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $basePath = $parsed['path'] ?? '/';

        if ($Path === '') {
            $Path = $basePath;
        } elseif ($Path[0] !== '/') {
            $dir = $basePath;
            if ($dir === '') {
                $dir = '/';
            }
            if (substr($dir, -1) !== '/') {
                $dir .= '/';
            }
            $Path = $dir . ltrim($Path, '/');
        }

        if ($Path !== '' && $Path[0] !== '/') {
            $Path = '/' . $Path;
        }

        return $scheme . '://' . $host . $port . $Path;
    }

    private function GetCalDAVFormElements(string $SyncBackend): array
    {
        return [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('CalDAV Synchronization'),
            'visible' => $SyncBackend === 'caldav',
            'items' => [
                [
                    'type' => 'CheckBox',
                    'name' => 'CalDAVEnabled',
                    'caption' => $this->Translate('Enabled'),
                    'visible' => false
                ],
                [
                    'type' => 'Select',
                    'name' => 'CalDAVCalendarPath',
                    'caption' => $this->Translate('Calendar'),
                    'width' => '400px',
                    'options' => $this->GetCalDAVCalendarOptions()
                ],
                [
                    'type' => 'Select',
                    'name' => 'CalDAVSyncInterval',
                    'caption' => $this->Translate('Sync Interval'),
                    'width' => '200px',
                    'options' => $this->GetSyncIntervalOptions()
                ],
                [
                    'type' => 'Select',
                    'name' => 'CalDAVConflictMode',
                    'caption' => $this->Translate('On Conflict'),
                    'width' => '250px',
                    'options' => $this->GetConflictModeOptions()
                ],
                [
                    'type' => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Refresh Calendars'),
                            'onClick' => 'TDL_CalDAVRefreshCalendarOptions($id);'
                        ],
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Sync Now'),
                            'onClick' => 'TDL_CalDAVSync($id);'
                        ],
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('Reset Sync'),
                            'onClick' => 'echo TDL_CalDAVResetSync($id);'
                        ]
                    ]
                ],
                [
                    'type' => 'Label',
                    'caption' => $this->GetCalDAVStatusLabel()
                ]
            ]
        ];
    }
}
