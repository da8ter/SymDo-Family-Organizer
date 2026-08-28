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

    /** Meldung als RUECKGABE — der Knopf gibt sie aus (siehe SyncResetItems). */
    public function CalDAVResetSync(): string
    {
        return $this->SyncResetItems(
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
            $this->SendDebug('CalDAV', 'Sync skipped - no SymDo Gateway', 0);
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
                $up = $this->CalDAVUploadItem($calendarUrl, $user, $pass, $uploadItem);
                if ($up['ok']) {
                    $uid = $uploadItem['caldavUid'] ?? '';
                    for ($i = 0; $i < count($items); $i++) {
                        if (($items[$i]['caldavUid'] ?? '') === $uid) {
                            $items[$i]['caldavSynced'] = $now;
                            $items[$i]['localModified'] = 0;
                            // Adopt the new ETag + the just-uploaded body as the in-sync base,
                            // so the next merge builds on it and no phantom "server changed"
                            // pass (or data-losing conflict) is triggered by a stale ETag.
                            if ($up['etag'] !== '') {
                                $items[$i]['caldavEtag'] = $up['etag'];
                            }
                            $items[$i]['caldavRaw'] = $up['vcal'];
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
        /* iCalendar IST laut RFC 5545 UTF-8 — aber nicht jeder Server haelt sich
           daran, und aeltere schicken ISO-8859-1 (frueher mit einem CHARSET-Zusatz,
           den RFC 5545 gar nicht mehr kennt). Ein einziges solches Byte in einem
           Aufgabentitel reichte, damit json_encode beim Ausliefern des Zustands
           `false` zurueckgab und die App eine LEERE Liste zeigte.

           Symcon hat solche Bytes bis zur C++-Fassung geduldet
           („CompatibilitySloppyUTF8"); die Rust-Fassung tut das nicht mehr. Deshalb
           hier, am Eingang: gueltiges UTF-8 bleibt unangetastet, alles andere wird
           als ISO-8859-1 gelesen — das kann nicht scheitern, denn dort ist jedes
           Byte ein gueltiges Zeichen. */
        if (!mb_check_encoding($ICalData, 'UTF-8')) {
            $this->SendDebug('CalDAV', 'Antwort ist kein UTF-8 — als ISO-8859-1 gelesen', 0);
            $ICalData = (string)mb_convert_encoding($ICalData, 'UTF-8', 'ISO-8859-1');
        }
        $lines = $this->CalDAVUnfold($ICalData);

        $inVTodo = false;
        $nestedDepth = 0;
        $props = [];
        $tzids = [];
        $valueDate = []; // propName => true when the property carries a VALUE=DATE parameter

        // Two-way VALARM: capture the first relative DISPLAY alarm's lead time so an alarm set
        // in another client (Apple Reminders, Tasks.org, …) becomes a Symcon reminder.
        $valarmLead = null;
        $markedLead = null; // lead from our own (marked) alarm — preferred, keeps round-trips stable
        $inValarm = false;
        $valarmTrigger = '';
        $valarmAction = '';
        $valarmMarked = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === 'BEGIN:VTODO') {
                $inVTodo = true;
                continue;
            }
            if (!$inVTodo) {
                continue;
            }
            // R9: nested components (VALARM etc.) must not have their DESCRIPTION/SUMMARY/UID
            // overwrite the task's own properties — they are skipped for the property map, but
            // a DISPLAY VALARM's TRIGGER is captured for the notification mapping.
            if (strncmp($line, 'BEGIN:', 6) === 0) {
                if ($nestedDepth === 0 && strcasecmp($line, 'BEGIN:VALARM') === 0) {
                    $inValarm = true;
                    $valarmTrigger = '';
                    $valarmAction = '';
                    $valarmMarked = false;
                }
                $nestedDepth++;
                continue;
            }
            if (strncmp($line, 'END:', 4) === 0) {
                if ($nestedDepth > 0) {
                    $nestedDepth--;
                    if ($nestedDepth === 0 && $inValarm) {
                        // Only a DISPLAY alarm maps to a Symcon (display) notification — an
                        // EMAIL/AUDIO alarm is preserved but must not create a display reminder.
                        if ($valarmTrigger !== '' && strcasecmp($valarmAction, 'DISPLAY') === 0) {
                            $lead = $this->CalDAVTriggerToLead($valarmTrigger);
                            if ($lead !== null) {
                                if ($valarmMarked && $markedLead === null) {
                                    $markedLead = $lead; // our own alarm wins
                                }
                                if ($valarmLead === null) {
                                    $valarmLead = $lead; // first display alarm (marked or foreign)
                                }
                            }
                        }
                        $inValarm = false;
                    }
                    continue;
                }
                break; // END:VTODO
            }
            if ($nestedDepth > 0) {
                if ($inValarm && $nestedDepth === 1) {
                    if (stripos($line, 'TRIGGER') === 0) {
                        $valarmTrigger = $line;
                    } elseif (stripos($line, 'ACTION:') === 0) {
                        $valarmAction = trim(substr($line, 7));
                    } elseif (stripos($line, 'X-SYMCON-ALARM') === 0) {
                        $valarmMarked = true;
                    }
                }
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
                    } elseif (strcasecmp(trim($params[$pi]), 'VALUE=DATE') === 0) {
                        $valueDate[$propName] = true; // all-day (date-only) property
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

        // Prefer our own marked alarm's lead over a foreign one, so a Symcon-created reminder
        // round-trips to the exact same lead time regardless of any additional foreign alarms.
        $effectiveLead = $markedLead ?? $valarmLead;
        $notification = $effectiveLead !== null;

        // Package 2: map the RRULE to the local recurrence model (approximate for complex rules;
        // the exact RRULE is preserved by the merge while the local recurrence is unchanged).
        $rrule = $this->CalDAVParseRRule((string)($props['RRULE'] ?? ''));

        return [
            'caldavUid' => $props['UID'],
            'title' => $this->CalDAVUnescapeText($props['SUMMARY'] ?? ''),
            'info' => $this->CalDAVUnescapeText($props['DESCRIPTION'] ?? ''),
            'done' => $done,
            'doneAt' => $done ? $this->CalDAVParseDateTime($props['COMPLETED'] ?? '', $tzids['COMPLETED'] ?? '') : 0,
            'due' => $this->CalDAVParseDateTime($props['DUE'] ?? '', $tzids['DUE'] ?? ''),
            'dueAllDay' => !empty($valueDate['DUE']) && !empty($props['DUE']), // package 3: VALUE=DATE
            'recurrence' => $rrule['recurrence'],                             // package 2: RRULE
            'recurrenceCustomUnit' => $rrule['recurrenceCustomUnit'],
            'recurrenceCustomValue' => $rrule['recurrenceCustomValue'],
            'priority' => $priority,
            'notification' => $notification,
            'notificationLeadTime' => $notification ? $this->CalDAVNearestLeadTime($effectiveLead) : 0,
            'createdAt' => $this->CalDAVParseDateTime($props['CREATED'] ?? '', $tzids['CREATED'] ?? ''),
            'caldavLastModified' => $this->CalDAVParseDateTime($props['LAST-MODIFIED'] ?? '', $tzids['LAST-MODIFIED'] ?? ''),
            // Property-Preserving Merge: keep the full raw object so an upload replaces only the
            // module-managed properties and leaves VALARM/RRULE/CATEGORIES/X-*/… untouched.
            'caldavRaw' => $ICalData
        ];
    }

    private function CalDAVUnfold(string $Data): array
    {
        $rawLines = preg_split('/\r?\n/', $Data);
        if ($rawLines === false) {
            return [];
        }
        $lines = [];
        foreach ($rawLines as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && count($lines) > 0) {
                $lines[count($lines) - 1] .= substr($line, 1);
            } else {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private function CalDAVParseDateTime(string $Value, string $Tzid = ''): int
    {
        if ($Value === '') {
            return 0;
        }
        $Value = preg_replace('/[^0-9TZ]/', '', $Value) ?? $Value;

        // A pure DATE value is a calendar day, not an instant. RFC 5545 forbids TZID on
        // VALUE=DATE, so a bogus TZID must not shift the day into a foreign zone — always
        // anchor date-only values at local (server) midnight.
        if (preg_match('/^\d{8}$/', $Value) === 1) {
            $dt = DateTime::createFromFormat('!Ymd', $Value, new DateTimeZone(date_default_timezone_get() ?: 'UTC'));
            return $dt !== false ? $dt->getTimestamp() : 0;
        }

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

            // Migration back-fill (pre-v2.7/v2.9 items): items synced before the
            // property-preserving merge lack the raw object and the parse-derived fields.
            // Adopt them from the fetched server copy so the next local edit merges in-place
            // instead of rebuilding — otherwise the first edit after the upgrade would destroy
            // foreign VALARM/RRULE/… , and the stale local defaults (recurrence 'none',
            // dueAllDay false) would register as "local changes" that strip the server's
            // RRULE / VALUE=DATE. Local non-default values are kept (user intent).
            if (($local['caldavRaw'] ?? '') === '' && ($server['caldavRaw'] ?? '') !== '') {
                $local['caldavRaw'] = $server['caldavRaw'];
                if (!empty($server['dueAllDay']) && empty($local['dueAllDay'])) {
                    $local['dueAllDay'] = true;
                }
                if ((string)($local['recurrence'] ?? 'none') === 'none'
                    && (string)($server['recurrence'] ?? 'none') !== 'none') {
                    $local['recurrence'] = $server['recurrence'];
                    $local['recurrenceCustomUnit'] = $server['recurrenceCustomUnit'] ?? 'w';
                    $local['recurrenceCustomValue'] = $server['recurrenceCustomValue'] ?? 1;
                }
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
                'dueAllDay' => $server['dueAllDay'] ?? false, // package 3
                'priority' => $server['priority'],
                'createdAt' => $server['createdAt'] ?: time(),
                'caldavUid' => $uid,
                'caldavEtag' => $server['caldavEtag'],
                'caldavHref' => $server['caldavHref'] ?? '',
                'caldavSynced' => time(),
                'caldavRaw' => $server['caldavRaw'] ?? '',
                'localModified' => 0,
                'notification' => $server['notification'] ?? false,
                'notificationLeadTime' => $server['notificationLeadTime'] ?? 0,
                'notifiedFor' => 0,
                'quantity' => 1,
                'recurrence' => $server['recurrence'] ?? 'none',           // package 2: from RRULE
                'recurrenceCustomUnit' => $server['recurrenceCustomUnit'] ?? 'w',
                'recurrenceCustomValue' => $server['recurrenceCustomValue'] ?? 1,
                'recurrenceResetLeadTime' => $this->NormalizeRecurrenceResetLeadTime(null, $server['recurrence'] ?? 'none')
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
        // Merge the local field changes onto the server's CURRENT raw object, so a concurrent
        // foreign addition (alarm, category, …) is not clobbered by a local-wins upload.
        if (isset($Server['caldavRaw'])) {
            $Local['caldavRaw'] = $Server['caldavRaw'];
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
        $Local['dueAllDay'] = $Server['dueAllDay'] ?? false; // package 3
        // Package 2: adopt the server's recurrence (parsed from RRULE).
        $Local['recurrence'] = $Server['recurrence'] ?? ($Local['recurrence'] ?? 'none');
        $Local['recurrenceCustomUnit'] = $Server['recurrenceCustomUnit'] ?? ($Local['recurrenceCustomUnit'] ?? 'w');
        $Local['recurrenceCustomValue'] = $Server['recurrenceCustomValue'] ?? ($Local['recurrenceCustomValue'] ?? 1);
        $Local['priority'] = $Server['priority'];
        $Local['notification'] = $Server['notification'] ?? ($Local['notification'] ?? false);
        $Local['notificationLeadTime'] = $Server['notificationLeadTime'] ?? ($Local['notificationLeadTime'] ?? 0);
        $Local['caldavEtag'] = $Server['caldavEtag'];
        if (!empty($Server['caldavHref'])) {
            $Local['caldavHref'] = $Server['caldavHref'];
        }
        $Local['caldavRaw'] = $Server['caldavRaw'] ?? '';
        $Local['caldavSynced'] = time();
        $Local['localModified'] = 0;
        return $Local;
    }

    /**
     * Uploads the item and returns ['ok'=>bool, 'etag'=>string, 'vcal'=>string]. The new ETag
     * (from the PUT response) and the uploaded body let the caller record an in-sync base.
     */
    private function CalDAVUploadItem(string $CalendarUrl, string $User, string $Pass, array $Item): array
    {
        $uid = $Item['caldavUid'] ?? '';
        if ($uid === '') {
            return ['ok' => false, 'etag' => '', 'vcal' => ''];
        }

        // Property-Preserving Merge: if we hold the raw server object, edit only the
        // module-managed properties in place (keeps VALARM/RRULE/CATEGORIES/X-*); otherwise
        // (a locally created task) build a fresh VTODO.
        $raw = (string)($Item['caldavRaw'] ?? '');
        $vcal = $raw !== '' ? $this->CalDAVMergeVTodo($raw, $Item) : $this->CalDAVBuildVTodo($Item);
        $fail = ['ok' => false, 'etag' => '', 'vcal' => $vcal];
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
            return $fail;
        }
        // Capture the new ETag so the next sync recognizes our own write instead of misreading
        // a stale ETag as a foreign change (which under server_wins would overwrite a concurrent
        // local edit → data loss, and otherwise causes churn). Prefer the PUT-response header;
        // some servers omit it, so fall back to a targeted PROPFIND for the item's ETag.
        $newEtag = $this->CalDAVExtractEtag($res['headers'] ?? []);
        if ($newEtag === '') {
            $newEtag = $this->CalDAVFetchItemEtag($itemUrl, $User, $Pass);
        }
        return ['ok' => true, 'etag' => $newEtag, 'vcal' => $vcal];
    }

    private function CalDAVExtractEtag(array $Headers): string
    {
        foreach ($Headers as $h) {
            if (stripos((string)$h, 'ETag:') === 0) {
                return $this->CalDAVNormalizeEtag(trim(substr((string)$h, 5)));
            }
        }
        return '';
    }

    private function CalDAVFetchItemEtag(string $Url, string $User, string $Pass): string
    {
        $body = '<' . '?xml version="1.0" encoding="utf-8"?' . '>'
            . '<d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>';
        $res = $this->CalDAVGatewayRequest('PROPFIND', $Url, $User, $Pass, [
            'Depth: 0',
            'Content-Type: application/xml; charset=utf-8'
        ], $body, 10);
        $status = (int)($res['status'] ?? 0);
        if ($status !== 207 && $status !== 200) {
            return '';
        }
        if (preg_match('/<[a-z0-9]*:?getetag[^>]*>([^<]+)<\/[a-z0-9]*:?getetag>/i', (string)($res['body'] ?? ''), $m) === 1) {
            return $this->CalDAVNormalizeEtag(trim($m[1]));
        }
        return '';
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

        // Package 2: emit the local recurrence as an RRULE. A recurring task is never written as
        // COMPLETED (B3 safety — the module advances the DUE locally and keeps it open).
        $rrule = $this->CalDAVBuildRRule($Item);
        $recurring = $rrule !== null;
        $completed = (bool)($Item['done'] ?? false) && !$recurring;
        $status = $completed ? 'COMPLETED' : 'NEEDS-ACTION';
        $percentComplete = $completed ? 100 : 0;
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

        $vtimezone = [];
        if (($Item['due'] ?? 0) > 0) {
            [$dueLine, $vtimezone] = $this->CalDAVDueLine((int)$Item['due'], (bool)($Item['dueAllDay'] ?? false));
            $lines[] = $dueLine;
        }

        if ($rrule !== null) {
            $lines[] = 'RRULE:' . $rrule;
        }

        if ($completed && ($Item['doneAt'] ?? 0) > 0) {
            $lines[] = 'COMPLETED:' . gmdate('Ymd\THis\Z', $Item['doneAt']);
        }

        // Two-way VALARM: a local reminder becomes a DISPLAY alarm with a relative trigger, so
        // other CalDAV clients (Apple Reminders, Tasks.org, …) surface it too.
        if (!empty($Item['notification']) && ($Item['due'] ?? 0) > 0) {
            $lines = array_merge($lines, $this->CalDAVBuildValarm((int)($Item['notificationLeadTime'] ?? 0), $titleText));
        }

        $lines[] = 'END:VTODO';
        $lines[] = 'END:VCALENDAR';

        if (count($vtimezone) > 0) {
            // VTIMEZONE must precede the VTODO that references its TZID.
            $pos = array_search('BEGIN:VTODO', $lines, true);
            if ($pos !== false) {
                array_splice($lines, $pos, 0, $vtimezone);
            }
        }

        return $this->CalDAVFoldLines($lines);
    }

    /**
     * Serialize a DUE property. In a non-UTC host zone it is written as local wall-clock with a
     * TZID and the matching VTIMEZONE lines are returned as the second element; on a UTC host or
     * an unresolvable/transition-less zone it falls back to the RFC-safe UTC Z-form (D2).
     * Returns [dueLine, vtimezoneLines].
     */
    private function CalDAVDueLine(int $Due, bool $AllDay = false): array
    {
        // Package 3: a true all-day task is serialized as DUE;VALUE=DATE (no time, no zone),
        // so other clients show it as an all-day item instead of a task due at 00:00.
        if ($AllDay) {
            return ['DUE;VALUE=DATE:' . date('Ymd', $Due), []];
        }
        $tzid = date_default_timezone_get();
        if ($tzid !== '' && strtoupper($tzid) !== 'UTC') {
            $vtz = $this->CalDAVBuildVTimezone($tzid);
            if (count($vtz) > 0) {
                try {
                    $wall = (new DateTime('@' . $Due))->setTimezone(new DateTimeZone($tzid))->format('Ymd\THis');
                    return ['DUE;TZID=' . $tzid . ':' . $wall, $vtz];
                } catch (Exception $e) {
                    // fall through to UTC
                }
            }
        }
        return ['DUE:' . gmdate('Ymd\THis\Z', $Due), []];
    }

    /**
     * Property-Preserving Merge: rewrite ONLY the module-managed properties of the stored raw
     * VTODO and leave everything else (VALARM, RRULE, CATEGORIES, ATTACH, X-*, foreign
     * VTIMEZONE, recurrence overrides) byte-for-byte intact. A property is only rewritten when
     * the local value actually differs from the imported one — so unchanged fields keep their
     * exact server form (this also preserves date-only DUE, IN-PROCESS/partial STATUS and the
     * precise PRIORITY, i.e. D3/D5/D6). Falls back to a fresh build if the raw has no VTODO.
     */
    private function CalDAVMergeVTodo(string $RawVCal, array $Item): string
    {
        $lines = $this->CalDAVUnfold($RawVCal);

        $vs = -1;
        for ($i = 0; $i < count($lines); $i++) {
            if (trim($lines[$i]) === 'BEGIN:VTODO') {
                $vs = $i;
                break;
            }
        }
        if ($vs === -1) {
            return $this->CalDAVBuildVTodo($Item);
        }
        $ve = -1;
        $d = 0;
        for ($i = $vs; $i < count($lines); $i++) {
            $t = trim($lines[$i]);
            if (strncmp($t, 'BEGIN:', 6) === 0) {
                $d++;
            } elseif (strncmp($t, 'END:', 4) === 0) {
                $d--;
                if ($d === 0) {
                    $ve = $i;
                    break;
                }
            }
        }
        if ($ve === -1) {
            return $this->CalDAVBuildVTodo($Item);
        }

        $head = array_slice($lines, 0, $vs);
        $body = array_slice($lines, $vs + 1, $ve - $vs - 1);
        $tail = array_slice($lines, $ve + 1);

        $snap = $this->CalDAVParseVTodo($RawVCal) ?? [];
        $now = gmdate('Ymd\THis\Z');

        // Decide, per managed property, whether the user changed it (→ rewrite) or not (→ keep
        // the raw line). $valueOnly keeps SUMMARY/DESCRIPTION values whose parameters
        // (LANGUAGE/ALTREP) are preserved from the original line; $targets holds full
        // replacement lines (null = remove the property).
        $valueOnly = [];
        $targets = [];
        $vtzToAdd = [];
        // The recurring-completion safety must key on the ACTUAL recurrence (local or the raw's
        // parsed one), NOT on the due: NormalizeRecurrence(...,$due) returns 'none' whenever
        // due<=0, so an imported recurring task without a parseable DUE would otherwise be
        // written COMPLETED + RRULE — exactly the double-roll this guard prevents.
        $isRecurring = $this->NormalizeRecurrence($Item['recurrence'] ?? 'none', 1) !== 'none'
            || (string)($snap['recurrence'] ?? 'none') !== 'none';

        if ((string)($Item['title'] ?? '') !== (string)($snap['title'] ?? '')) {
            $valueOnly['SUMMARY'] = $this->CalDAVEscapeText((string)($Item['title'] ?? ''));
        }
        if ((string)($Item['info'] ?? '') !== (string)($snap['info'] ?? '')) {
            $info = (string)($Item['info'] ?? '');
            if ($info === '') {
                $targets['DESCRIPTION'] = null;
            } else {
                $valueOnly['DESCRIPTION'] = $this->CalDAVEscapeText($info);
            }
        }
        if ((bool)($Item['done'] ?? false) !== (bool)($snap['done'] ?? false)) {
            // Package 2 safety: a recurring task is never written as COMPLETED — that would make
            // some clients roll the series a second time (the B3 problem). The module advances
            // the DUE locally and keeps the occurrence open (NEEDS-ACTION).
            $done = (bool)($Item['done'] ?? false) && !$isRecurring;
            $targets['STATUS'] = 'STATUS:' . ($done ? 'COMPLETED' : 'NEEDS-ACTION');
            $targets['PERCENT-COMPLETE'] = 'PERCENT-COMPLETE:' . ($done ? '100' : '0');
            $targets['COMPLETED'] = ($done && ($Item['doneAt'] ?? 0) > 0)
                ? 'COMPLETED:' . gmdate('Ymd\THis\Z', (int)$Item['doneAt'])
                : null;
        }
        if ((int)($Item['due'] ?? 0) !== (int)($snap['due'] ?? 0)
            || (bool)($Item['dueAllDay'] ?? false) !== (bool)($snap['dueAllDay'] ?? false)) {
            $due = (int)($Item['due'] ?? 0);
            if ($due > 0) {
                [$dueLine, $vtzToAdd] = $this->CalDAVDueLine($due, (bool)($Item['dueAllDay'] ?? false));
                $targets['DUE'] = $dueLine;
            } else {
                $targets['DUE'] = null;
            }
        }
        if ((string)($Item['priority'] ?? 'normal') !== (string)($snap['priority'] ?? 'normal')) {
            $pv = match ($Item['priority'] ?? 'normal') {
                'high' => 1,
                'low' => 9,
                default => 0
            };
            $targets['PRIORITY'] = $pv > 0 ? 'PRIORITY:' . $pv : null;
        }
        // Package 2 — RRULE is managed: rewrite it only when the local recurrence actually
        // differs from the imported one; otherwise the raw RRULE (incl. complex BYDAY/COUNT/
        // UNTIL that the local model only approximates) is preserved verbatim.
        $recChanged = (string)($Item['recurrence'] ?? 'none') !== (string)($snap['recurrence'] ?? 'none')
            || ((string)($Item['recurrence'] ?? '') === 'custom'
                && ((string)($Item['recurrenceCustomUnit'] ?? 'w') !== (string)($snap['recurrenceCustomUnit'] ?? 'w')
                    || (int)($Item['recurrenceCustomValue'] ?? 1) !== (int)($snap['recurrenceCustomValue'] ?? 1)));
        if ($recChanged) {
            $rr = $this->CalDAVBuildRRule($Item);
            $targets['RRULE'] = $rr !== null ? 'RRULE:' . $rr : null;
        }
        $notifChanged = ((bool)($Item['notification'] ?? false) !== (bool)($snap['notification'] ?? false))
            || ((int)($Item['notificationLeadTime'] ?? 0) !== (int)($snap['notificationLeadTime'] ?? 0));
        // Option B — "off is authoritative": ONLY the explicit on→off transition governs
        // foreign reminder-typed alarms. Lead changes and turning ON never touch foreign
        // alarms (they only manage the own, marked alarm).
        $reminderOff = $notifChanged
            && !(bool)($Item['notification'] ?? false)
            && (bool)($snap['notification'] ?? false);

        $tokens = $this->CalDAVTokenizeBody($body);
        $props = []; // property lines — emitted BEFORE all components (RFC 5545 §3.6.2)
        $comps = []; // component blocks (VALARM etc.), each an array of lines
        $seen = [];
        $ownAlarmSeen = false;

        foreach ($tokens as $tok) {
            if ($tok['type'] === 'prop') {
                $name = $this->CalDAVPropName($tok['line']);
                if ($name === 'DTSTAMP' || $name === 'LAST-MODIFIED') {
                    if (empty($seen[$name])) {
                        $props[] = $name . ':' . $now;
                        $seen[$name] = true;
                    }
                    continue;
                }
                if ($name === 'SEQUENCE') {
                    if (empty($seen['SEQUENCE'])) {
                        $seq = (int)trim(substr($tok['line'], strpos($tok['line'], ':') + 1));
                        $props[] = 'SEQUENCE:' . ($seq + 1);
                        $seen['SEQUENCE'] = true;
                    }
                    continue;
                }
                if (isset($valueOnly[$name])) {
                    // Changed SUMMARY/DESCRIPTION → keep the original line's parameters.
                    if (empty($seen[$name])) {
                        $props[] = $this->CalDAVReplaceValue($tok['line'], $valueOnly[$name]);
                        $seen[$name] = true;
                    }
                    continue; // duplicate occurrence in the raw → drop
                }
                if (array_key_exists($name, $targets)) {
                    if (empty($seen[$name])) {
                        if ($targets[$name] !== null) {
                            $props[] = $targets[$name];
                        }
                        $seen[$name] = true;
                    }
                    continue; // removed, or duplicate occurrence dropped
                }
                $props[] = $tok['line']; // unmanaged, or unchanged-managed → preserve verbatim
            } else {
                if (strcasecmp($tok['name'], 'VALARM') === 0) {
                    if ($this->CalDAVIsOwnAlarm($tok['lines'])) {
                        // Our own (marked) alarm — replace/remove only when the reminder changed.
                        if ($notifChanged) {
                            if (!$ownAlarmSeen) {
                                $ownAlarmSeen = true;
                                if ((bool)($Item['notification'] ?? false) && (int)($Item['due'] ?? 0) > 0) {
                                    $comps[] = $this->CalDAVBuildValarm((int)($Item['notificationLeadTime'] ?? 0), (string)($Item['title'] ?? ''));
                                }
                            }
                            continue; // drop this (and any further) own alarm
                        }
                        $ownAlarmSeen = true; // unchanged → keep it (falls through to preserve)
                    } elseif ($reminderOff && $this->CalDAVIsRelativeDisplayAlarm($tok['lines'])) {
                        // Option B — the user explicitly turned the reminder OFF, and this
                        // foreign relative DISPLAY alarm is exactly what the imported reminder
                        // state was derived from. Keeping it would (a) keep ringing in every
                        // other client despite the off-switch and (b) re-import as ON on the
                        // next server change (flip-flop). EMAIL/AUDIO and absolute-trigger
                        // alarms are never touched; unrelated edits still preserve everything.
                        continue;
                    }
                }
                $comps[] = $tok['lines'];
            }
        }

        // Managed values that changed but were not present in the raw → append.
        foreach ($valueOnly as $name => $val) {
            if (empty($seen[$name])) {
                $props[] = $name . ':' . $val;
            }
        }
        foreach ($targets as $name => $line) {
            if ($line !== null && empty($seen[$name])) {
                $props[] = $line;
            }
        }
        if (empty($seen['DTSTAMP'])) {
            $props[] = 'DTSTAMP:' . $now;
        }
        if (empty($seen['LAST-MODIFIED'])) {
            $props[] = 'LAST-MODIFIED:' . $now;
        }
        if (empty($seen['SEQUENCE'])) {
            $props[] = 'SEQUENCE:1';
        }
        // Reminder turned ON but there was no own alarm to replace → add one.
        if ($notifChanged && !$ownAlarmSeen && (bool)($Item['notification'] ?? false) && (int)($Item['due'] ?? 0) > 0) {
            $comps[] = $this->CalDAVBuildValarm((int)($Item['notificationLeadTime'] ?? 0), (string)($Item['title'] ?? ''));
        }

        // Ensure a VTIMEZONE for a newly written DUE;TZID exists. Compare by the TZID VALUE so a
        // parameterized identifier ('TZID;X-LIC-LOCATION=…:Europe/Berlin') counts as present and
        // no duplicate (RFC 5545 §3.6.5) is appended — searching the whole document (head+tail).
        if (count($vtzToAdd) > 0) {
            $wantZone = '';
            foreach ($vtzToAdd as $l) {
                if (strncmp($l, 'TZID:', 5) === 0) {
                    $wantZone = trim(substr($l, 5));
                    break;
                }
            }
            $present = false;
            if ($wantZone !== '') {
                foreach (array_merge($head, $tail) as $hl) {
                    $ht = trim($hl);
                    if ($this->CalDAVPropName($ht) === 'TZID') {
                        $c = strpos($ht, ':');
                        if ($c !== false && trim(substr($ht, $c + 1)) === $wantZone) {
                            $present = true;
                            break;
                        }
                    }
                }
            }
            if (!$present) {
                $head = array_merge($head, $vtzToAdd);
            }
        }

        // RFC 5545 §3.6.2: all properties precede all sub-components inside the VTODO.
        $out = $props;
        foreach ($comps as $block) {
            $out = array_merge($out, $block);
        }

        $all = array_merge($head, ['BEGIN:VTODO'], $out, ['END:VTODO'], $tail);
        return $this->CalDAVFoldLines($all);
    }

    private function CalDAVReplaceValue(string $OrigLine, string $NewRawValue): string
    {
        $c = strpos($OrigLine, ':');
        $prefix = $c !== false ? substr($OrigLine, 0, $c) : $OrigLine; // keeps "NAME;params"
        return $prefix . ':' . $NewRawValue;
    }

    private function CalDAVTokenizeBody(array $Body): array
    {
        $tokens = [];
        $n = count($Body);
        $i = 0;
        while ($i < $n) {
            $t = trim($Body[$i]);
            if (strncmp($t, 'BEGIN:', 6) === 0) {
                $name = substr($t, 6);
                $block = [$Body[$i]];
                $depth = 1;
                $i++;
                while ($i < $n && $depth > 0) {
                    $bt = trim($Body[$i]);
                    if (strncmp($bt, 'BEGIN:', 6) === 0) {
                        $depth++;
                    } elseif (strncmp($bt, 'END:', 4) === 0) {
                        $depth--;
                    }
                    $block[] = $Body[$i];
                    $i++;
                }
                $tokens[] = ['type' => 'comp', 'name' => $name, 'lines' => $block];
            } else {
                $tokens[] = ['type' => 'prop', 'line' => $Body[$i]];
                $i++;
            }
        }
        return $tokens;
    }

    private function CalDAVPropName(string $Line): string
    {
        $end = strlen($Line);
        $c = strpos($Line, ':');
        if ($c !== false) {
            $end = min($end, $c);
        }
        $s = strpos($Line, ';');
        if ($s !== false) {
            $end = min($end, $s);
        }
        return strtoupper(trim(substr($Line, 0, $end)));
    }

    /**
     * A VALARM is "module-owned" only when it carries our marker. Foreign alarms (from Apple
     * Reminders, DAVx5, …) never match and are therefore always preserved byte-for-byte — the
     * previous heuristic (any DISPLAY + relative trigger) misclassified and destroyed them.
     */
    private function CalDAVIsOwnAlarm(array $Lines): bool
    {
        foreach ($Lines as $l) {
            if (stripos(trim($l), 'X-SYMCON-ALARM') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Option B: a relative DISPLAY alarm is "reminder-typed" — it is what the imported
     * notification state is derived from, so an explicit reminder-off removes it (foreign or
     * not). EMAIL/AUDIO alarms and absolute (date-time) triggers never match.
     */
    private function CalDAVIsRelativeDisplayAlarm(array $Lines): bool
    {
        $isDisplay = false;
        $relative = false;
        foreach ($Lines as $l) {
            $t = trim($l);
            if (stripos($t, 'ACTION:') === 0 && stripos($t, 'DISPLAY') !== false) {
                $isDisplay = true;
            }
            if (stripos($t, 'TRIGGER') === 0 && $this->CalDAVTriggerToLead($t) !== null) {
                $relative = true;
            }
        }
        return $isDisplay && $relative;
    }

    private function CalDAVBuildValarm(int $LeadSeconds, string $Title): array
    {
        $lead = max(0, $LeadSeconds);
        $trigger = $lead === 0 ? 'PT0S' : '-' . $this->CalDAVSecondsToDuration($lead);
        $desc = $Title !== '' ? $Title : 'Reminder';
        return [
            'BEGIN:VALARM',
            // Marker: identifies THIS alarm as module-owned. Only marked alarms are ever
            // rewritten/removed on a reminder change — foreign alarms are always preserved.
            'X-SYMCON-ALARM:1',
            'ACTION:DISPLAY',
            'DESCRIPTION:' . $this->CalDAVEscapeText($desc),
            // RELATED=END anchors the relative trigger to DUE. A VTODO has no DTSTART, so a
            // default (RELATED=START) trigger would be unanchored and strict clients (Apple
            // Reminders, DAVx5/Tasks.org) would not fire the alarm at all.
            'TRIGGER;RELATED=END:' . $trigger,
            'END:VALARM'
        ];
    }

    private function CalDAVSecondsToDuration(int $Sec): string
    {
        $Sec = max(1, $Sec);
        $days = intdiv($Sec, 86400);
        $Sec %= 86400;
        $hours = intdiv($Sec, 3600);
        $Sec %= 3600;
        $mins = intdiv($Sec, 60);
        $secs = $Sec % 60;
        $out = 'P';
        if ($days > 0) {
            $out .= $days . 'D';
        }
        $time = '';
        if ($hours > 0) {
            $time .= $hours . 'H';
        }
        if ($mins > 0) {
            $time .= $mins . 'M';
        }
        if ($secs > 0) {
            $time .= $secs . 'S';
        }
        if ($time !== '') {
            $out .= 'T' . $time;
        }
        return $out === 'P' ? 'PT0S' : $out;
    }

    /**
     * Map an iCalendar alarm TRIGGER line to a "minutes/seconds before due" lead time. Returns
     * the positive lead in seconds for a relative "before" trigger, 0 for at/after, or null for
     * an absolute (date-time) trigger that cannot be expressed as a lead.
     */
    private function CalDAVTriggerToLead(string $TriggerLine): ?int
    {
        $pos = strpos($TriggerLine, ':');
        if ($pos === false) {
            return null;
        }
        $params = substr($TriggerLine, 0, $pos);
        $val = trim(substr($TriggerLine, $pos + 1));
        if ($val === '') {
            return null;
        }
        // Absolute date-time trigger → not a lead time.
        if (stripos($params, 'VALUE=DATE-TIME') !== false || preg_match('/^\d{8}T/', $val) === 1) {
            return null;
        }
        $before = $val[0] === '-';
        $dur = ltrim($val, '+-');
        $sec = $this->CalDAVDurationToSeconds($dur);
        if ($sec === null) {
            return null;
        }
        return $before ? $sec : 0;
    }

    private function CalDAVDurationToSeconds(string $Dur): ?int
    {
        if (preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $Dur, $m) !== 1) {
            return null;
        }
        return ((int)($m[1] ?? 0)) * 604800
            + ((int)($m[2] ?? 0)) * 86400
            + ((int)($m[3] ?? 0)) * 3600
            + ((int)($m[4] ?? 0)) * 60
            + ((int)($m[5] ?? 0));
    }

    private function CalDAVNearestLeadTime(int $Seconds): int
    {
        $allowed = [0, 300, 600, 1800, 3600, 18000, 43200];
        $best = 0;
        $bestDiff = PHP_INT_MAX;
        foreach ($allowed as $v) {
            $diff = abs($v - $Seconds);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $v;
            }
        }
        return $best;
    }

    private function CalDAVFoldLines(array $Lines): string
    {
        $out = [];
        foreach ($Lines as $l) {
            $out[] = $this->CalDAVFold((string)$l);
        }
        // RFC 5545 §3.1: every content line ends with CRLF, including the last. Normalize to
        // exactly one trailing CRLF regardless of whether the source raw carried one.
        return rtrim(implode("\r\n", $out), "\r\n") . "\r\n";
    }

    /**
     * RFC 5545 §3.1 content-line folding at 75 octets, UTF-8-safe (never splits a multi-byte
     * character). Continuation lines begin with a single space.
     */
    private function CalDAVFold(string $Line): string
    {
        if (strlen($Line) <= 75) {
            return $Line;
        }
        $chars = preg_split('//u', $Line, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return $Line;
        }
        $result = '';
        $cur = '';
        $curLen = 0;
        $first = true;
        foreach ($chars as $ch) {
            $chLen = strlen($ch);
            $limit = $first ? 75 : 74; // continuation lines carry a leading space
            if ($curLen + $chLen > $limit) {
                $result .= ($first ? '' : ' ') . $cur . "\r\n";
                $first = false;
                $cur = $ch;
                $curLen = $chLen;
            } else {
                $cur .= $ch;
                $curLen += $chLen;
            }
        }
        return $result . ($first ? '' : ' ') . $cur;
    }

    /**
     * Build a VTIMEZONE component for the given IANA zone from PHP transition data, so DUE can
     * be serialized as local wall-clock time with a valid TZID reference (Option 2). Returns []
     * for UTC or when the zone yields no usable transitions (caller keeps the UTC Z-form).
     */
    private function CalDAVBuildVTimezone(string $Tzid): array
    {
        if ($Tzid === '' || strtoupper($Tzid) === 'UTC') {
            return [];
        }
        try {
            $tz = new DateTimeZone($Tzid);
        } catch (Exception $e) {
            return [];
        }

        $now = time();
        $window = 5 * 366 * 86400; // ±5 years captures the current DST rule
        $transitions = $tz->getTransitions($now - $window, $now + $window);
        if (!is_array($transitions) || count($transitions) === 0) {
            return [];
        }

        $fmt = static function (int $sec): string {
            $sign = $sec < 0 ? '-' : '+';
            $sec = abs($sec);
            return sprintf('%s%02d%02d', $sign, intdiv($sec, 3600), intdiv($sec % 3600, 60));
        };

        // Keep the most recent DAYLIGHT and STANDARD onset (with its preceding offset).
        $daylight = null;
        $standard = null;
        for ($i = 1; $i < count($transitions); $i++) {
            $comp = [
                'from' => (int)$transitions[$i - 1]['offset'],
                'to'   => (int)$transitions[$i]['offset'],
                'ts'   => (int)$transitions[$i]['ts'],
                'abbr' => (string)($transitions[$i]['abbr'] ?? '')
            ];
            if (!empty($transitions[$i]['isdst'])) {
                $daylight = $comp;
            } else {
                $standard = $comp;
            }
        }

        $mkComp = static function (string $type, array $c) use ($fmt): array {
            $localTs = $c['ts'] + $c['from']; // onset in TZOFFSETFROM local time
            $days = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
            $dom = (int)gmdate('j', $localTs);
            $dim = (int)gmdate('t', $localTs);
            $ord = ($dom > $dim - 7) ? -1 : (int)ceil($dom / 7); // -1 = last, else nth weekday
            $lines = ['BEGIN:' . $type];
            $lines[] = 'TZOFFSETFROM:' . $fmt($c['from']);
            $lines[] = 'TZOFFSETTO:' . $fmt($c['to']);
            if ($c['abbr'] !== '') {
                $lines[] = 'TZNAME:' . $c['abbr'];
            }
            $lines[] = 'DTSTART:' . gmdate('Ymd\THis', $localTs);
            $lines[] = 'RRULE:FREQ=YEARLY;BYMONTH=' . (int)gmdate('n', $localTs)
                . ';BYDAY=' . $ord . $days[(int)gmdate('w', $localTs)];
            $lines[] = 'END:' . $type;
            return $lines;
        };

        if ($daylight === null || $standard === null) {
            // Fixed-offset zone (no DST): a single STANDARD observance, no RRULE.
            $off = (int)$transitions[count($transitions) - 1]['offset'];
            return [
                'BEGIN:VTIMEZONE',
                'TZID:' . $Tzid,
                'BEGIN:STANDARD',
                'TZOFFSETFROM:' . $fmt($off),
                'TZOFFSETTO:' . $fmt($off),
                'DTSTART:19700101T000000',
                'END:STANDARD',
                'END:VTIMEZONE'
            ];
        }

        return array_merge(
            ['BEGIN:VTIMEZONE', 'TZID:' . $Tzid],
            $mkComp('DAYLIGHT', $daylight),
            $mkComp('STANDARD', $standard),
            ['END:VTIMEZONE']
        );
    }

    /**
     * Package 2 — translate the local recurrence model to an RRULE value (without the "RRULE:"
     * prefix), or null for a non-recurring task. CalDAV is the only backend that can express the
     * hourly custom interval.
     */
    private function CalDAVBuildRRule(array $Item): ?string
    {
        $due = (int)($Item['due'] ?? 0);
        $rec = $this->NormalizeRecurrence($Item['recurrence'] ?? 'none', $due);
        switch ($rec) {
            case 'w1': return 'FREQ=WEEKLY';
            case 'w2': return 'FREQ=WEEKLY;INTERVAL=2';
            case 'w3': return 'FREQ=WEEKLY;INTERVAL=3';
            case 'm1': return 'FREQ=MONTHLY';
            case 'q1': return 'FREQ=MONTHLY;INTERVAL=3';
            case 'y1': return 'FREQ=YEARLY';
            case 'custom':
                $unit = $this->NormalizeRecurrenceCustomUnit($Item['recurrenceCustomUnit'] ?? null);
                $val = max(1, $this->NormalizeRecurrenceCustomValue($Item['recurrenceCustomValue'] ?? null));
                $freq = ['h' => 'HOURLY', 'd' => 'DAILY', 'w' => 'WEEKLY', 'm' => 'MONTHLY', 'y' => 'YEARLY'][$unit] ?? null;
                if ($freq === null) {
                    return null;
                }
                return $val > 1 ? 'FREQ=' . $freq . ';INTERVAL=' . $val : 'FREQ=' . $freq;
        }
        return null;
    }

    /**
     * Package 2 — map an RRULE value to the local recurrence model. Only simple FREQ+INTERVAL
     * rules map exactly; anything with BYDAY lists / BYMONTHDAY / COUNT / UNTIL is approximated
     * to the closest local recurrence for display (the exact RRULE is preserved verbatim by the
     * merge as long as the local recurrence is not changed).
     */
    private function CalDAVParseRRule(string $Rrule): array
    {
        $default = ['recurrence' => 'none', 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
        if (trim($Rrule) === '') {
            return $default;
        }
        $parts = [];
        foreach (explode(';', $Rrule) as $p) {
            $kv = explode('=', $p, 2);
            if (count($kv) === 2) {
                $parts[strtoupper(trim($kv[0]))] = strtoupper(trim($kv[1]));
            }
        }
        $freq = $parts['FREQ'] ?? '';
        $interval = max(1, (int)($parts['INTERVAL'] ?? 1));
        $freqMap = ['HOURLY' => 'h', 'DAILY' => 'd', 'WEEKLY' => 'w', 'MONTHLY' => 'm', 'YEARLY' => 'y'];
        if (!isset($freqMap[$freq])) {
            return $default;
        }
        // A bounded series (COUNT/UNTIL) is NOT mapped to a local recurrence: the local engine
        // has no notion of a series end and would roll it forever past its limit. It stays
        // 'none' locally (the module never rolls it) while the exact RRULE is preserved on the
        // server by the merge (recurrence unchanged → raw RRULE kept verbatim).
        if (isset($parts['COUNT']) || isset($parts['UNTIL'])) {
            return $default;
        }
        if ($freq === 'WEEKLY' && in_array($interval, [1, 2, 3], true)) {
            return ['recurrence' => 'w' . $interval, 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
        }
        if ($freq === 'MONTHLY' && $interval === 1) {
            return ['recurrence' => 'm1', 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
        }
        if ($freq === 'MONTHLY' && $interval === 3) {
            return ['recurrence' => 'q1', 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
        }
        if ($freq === 'YEARLY' && $interval === 1) {
            return ['recurrence' => 'y1', 'recurrenceCustomUnit' => 'w', 'recurrenceCustomValue' => 1];
        }
        return ['recurrence' => 'custom', 'recurrenceCustomUnit' => $freqMap[$freq], 'recurrenceCustomValue' => $interval];
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

    /**
     * Meldung als RUECKGABE statt als Ausgabe: Symcon 9.1 betrachtet jeden
     * Funktionsaufruf fuer sich und meldet ein `echo` INNERHALB einer Funktion als
     * Fehler. Der Knopf im Formular gibt sie aus.
     */
    public function CalDAVRefreshCalendarOptions(): string
    {
        $stored = $this->CalDAVFetchAndStoreCalendarOptions();
        if ($stored === null) {
            return $this->Translate('Failed to fetch calendars.');
        }

        if (empty($stored)) {
            return $this->Translate('No calendars found.');
        }

        $options = $this->GetCalDAVCalendarOptions();
        $this->UpdateFormField('CalDAVCalendarPath', 'options', json_encode($options));
        return sprintf($this->Translate('Found %d calendar(s).'), count($stored));
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
                            'onClick' => 'echo TDL_CalDAVRefreshCalendarOptions($id);'
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
