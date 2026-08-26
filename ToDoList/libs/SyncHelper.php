<?php

declare(strict_types=1);

trait SyncHelper
{
    private function SyncResetItems(array $IdField, array $EtagField, array $SyncedField, string $LastSyncAttr, string $PendingDeletesAttr): string
    {
        $items = $this->LoadItems();
        $count = 0;
        foreach ($items as &$item) {
            $hasId = false;
            $hasSynced = false;
            foreach ($IdField as $f) {
                if (!empty($item[$f])) {
                    $hasId = true;
                    break;
                }
            }
            foreach ($SyncedField as $f) {
                if (($item[$f] ?? 0) > 0) {
                    $hasSynced = true;
                    break;
                }
            }
            if ($hasId || $hasSynced) {
                foreach ($IdField as $f) {
                    $item[$f] = '';
                }
                foreach ($EtagField as $f) {
                    $item[$f] = '';
                }
                foreach ($SyncedField as $f) {
                    $item[$f] = 0;
                }
                $item['localModified'] = time();
                $count++;
            }
        }
        unset($item);
        $this->SaveItems($items);
        $this->WriteAttributeInteger($LastSyncAttr, 0);
        $this->WriteAttributeString($PendingDeletesAttr, '{}');
        /* Meldung als RUECKGABE: Symcon 9.1 meldet ein `echo` innerhalb einer
           Funktion als Fehler. Die Knoepfe geben sie ohnehin schon aus
           (`echo TDL_CalDAVResetSync($id);`) — bisher kam dort nur nichts an,
           weil die Funktion void war und die Ausgabe von hier stammte. */
        return sprintf($this->Translate('%d items reset for re-sync'), $count);
    }

    private function SyncAddPendingDelete(string $TaskId, string $PendingPrefix, string $PendingDeletesAttr, string $Value = '1'): void
    {
        if ($TaskId === '' || strpos($TaskId, $PendingPrefix) === 0) {
            return;
        }
        $pending = json_decode((string)$this->ReadAttributeString($PendingDeletesAttr), true);
        if (!is_array($pending)) {
            $pending = [];
        }
        // The stored value is the item's ETag (for a conditional DELETE); '1' means "no ETag".
        $pending[$TaskId] = ($Value !== '') ? $Value : '1';
        $this->WriteAttributeString($PendingDeletesAttr, json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function SyncProcessPendingDeletes(array &$PendingDeletes, callable $DeleteFn, string $DebugLabel): void
    {
        foreach ($PendingDeletes as $taskId => $value) {
            // $value is the stored ETag ('1' = none). The callback returns true to drop the
            // tombstone (deleted, already gone, or a 412 we deliberately give up on) and false
            // to keep it for a later retry (transient failure).
            if ($DeleteFn((string)$taskId, (string)$value)) {
                unset($PendingDeletes[$taskId]);
                $this->SendDebug($DebugLabel, 'Delete resolved: ' . $taskId, 0);
            }
        }
    }

    private function SyncGetStatusLabel(string $RefreshToken, int $LastSync, string $Provider = ''): string
    {
        if ($RefreshToken === '') {
            return $this->Translate('Status') . ': ' . $this->Translate('Not connected');
        }

        $status = $this->Translate('Status') . ': ' . $this->Translate('Connected');
        if ($LastSync <= 0) {
            return $status . ' | ' . $this->Translate('Last sync') . ': ' . $this->Translate('Never');
        }

        return $status . ' | ' . $this->Translate('Last sync') . ': ' . date('d.m.Y H:i:s', $LastSync);
    }

    private function SyncScheduleOnChange(string $Backend, string $TimerName): void
    {
        if ($this->GetSyncBackend() !== $Backend) {
            return;
        }

        $delay = (int)$this->ReadPropertyInteger('AutoSyncOnChangeDelay');
        if ($delay < 1) {
            $delay = 1;
        }

        $this->SetTimerInterval($TimerName, 0);
        $this->SetTimerInterval($TimerName, min(60000, $delay * 1000));
    }

    private function SyncUpdateTimer(string $Backend, string $TimerName, int $IntervalMinutes, bool $HasToken = true): void
    {
        $enabled = $this->GetSyncBackend() === $Backend;
        if ($enabled && $IntervalMinutes > 0 && $HasToken) {
            $this->SetTimerInterval($TimerName, $IntervalMinutes * 60 * 1000);
        } else {
            $this->SetTimerInterval($TimerName, 0);
        }
    }

    private function SyncApplyServerFields(array &$Local, array $Server, array $FieldMap): void
    {
        foreach ($FieldMap as $localField => $serverField) {
            if ($serverField === null) {
                continue;
            }
            $Local[$localField] = $Server[$serverField] ?? ($Local[$localField] ?? null);
        }
    }

    private function SyncHandleDeleteOnComplete(array $Item, string $Backend): void
    {
        $syncBackend = $this->GetSyncBackend();

        if ($syncBackend === 'google' && ($Backend === 'google' || $Backend === 'all')) {
            $googleId = (string)($Item['googleTaskId'] ?? '');
            if ($googleId !== '' && (int)($Item['googleSynced'] ?? 0) > 0) {
                $this->SyncAddPendingDelete($googleId, 'pending_', 'GooglePendingDeletes', (string)($Item['googleEtag'] ?? ''));
            }
        }

        if ($syncBackend === 'microsoft' && ($Backend === 'microsoft' || $Backend === 'all')) {
            $msId = (string)($Item['microsoftTaskId'] ?? '');
            if ($msId !== '' && (int)($Item['microsoftSynced'] ?? 0) > 0) {
                $this->SyncAddPendingDelete($msId, 'pending_', 'MicrosoftPendingDeletes', (string)($Item['microsoftEtag'] ?? ''));
            }
        }

        if ($syncBackend === 'caldav' && ($Backend === 'caldav' || $Backend === 'all')) {
            $uid = (string)($Item['caldavUid'] ?? '');
            if ($uid !== '' && (int)($Item['caldavSynced'] ?? 0) > 0) {
                $pending = json_decode((string)$this->ReadAttributeString('CalDAVPendingDeletes'), true);
                if (!is_array($pending)) {
                    $pending = [];
                }
                $pending[$uid] = json_encode(['href' => (string)($Item['caldavHref'] ?? ''), 'etag' => (string)($Item['caldavEtag'] ?? '')]);
                $this->WriteAttributeString('CalDAVPendingDeletes', json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }
    }

    private function SyncHandleListChange(string $PropertyName, string $LastValueAttr, string $LastSyncAttr, string $PendingDeletesAttr, string $DebugLabel, array $ExtraAttrs = []): bool
    {
        $current = trim($this->ReadPropertyString($PropertyName));
        $last = $this->ReadAttributeString($LastValueAttr);
        $changed = false;

        if ($current !== $last) {
            if ($last !== '' && $current !== '') {
                $this->SendDebug($DebugLabel, 'List changed from [' . $last . '] to [' . $current . '] – clearing local items', 0);
                $this->SaveItems([]);
                $this->WriteAttributeInteger($LastSyncAttr, 0);
                $this->WriteAttributeString($PendingDeletesAttr, '{}');
                foreach ($ExtraAttrs as $attr => $default) {
                    if (is_int($default)) {
                        $this->WriteAttributeInteger($attr, $default);
                    } else {
                        $this->WriteAttributeString($attr, (string)$default);
                    }
                }
                $changed = true;
            }
            $this->WriteAttributeString($LastValueAttr, $current);
        }

        return $changed;
    }

    private function SyncPostComplete(): void
    {
        $this->UpdateStatistics();
        $this->UpdateTaskListHtml();
        $this->UpdateStatisticsTimer();
        $this->SendState();
    }

    private function GetSyncIntervalOptions(): array
    {
        return [
            ['caption' => $this->Translate('Manual only'), 'value' => 0],
            ['caption' => $this->Translate('Every 5 minutes'), 'value' => 5],
            ['caption' => $this->Translate('Every 15 minutes'), 'value' => 15],
            ['caption' => $this->Translate('Every 30 minutes'), 'value' => 30],
            ['caption' => $this->Translate('Every hour'), 'value' => 60]
        ];
    }

    private function GetConflictModeOptions(): array
    {
        return [
            ['caption' => $this->Translate('Server wins'), 'value' => 'server_wins'],
            ['caption' => $this->Translate('Local wins'), 'value' => 'local_wins'],
            ['caption' => $this->Translate('Newest wins'), 'value' => 'newest_wins']
        ];
    }

    private function GetNotificationLeadTimeOptions(): array
    {
        return [
            ['caption' => $this->Translate('0 minutes'), 'value' => 0],
            ['caption' => $this->Translate('5 minutes'), 'value' => 300],
            ['caption' => $this->Translate('10 minutes'), 'value' => 600],
            ['caption' => $this->Translate('30 minutes'), 'value' => 1800],
            ['caption' => $this->Translate('1 hour'), 'value' => 3600],
            ['caption' => $this->Translate('5 hours'), 'value' => 18000],
            ['caption' => $this->Translate('12 hours'), 'value' => 43200]
        ];
    }
}
