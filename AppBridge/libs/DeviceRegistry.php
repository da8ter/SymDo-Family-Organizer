<?php

declare(strict_types=1);

trait DeviceRegistry
{
    public function CreatePairing(): string
    {
        $code      = strtoupper(bin2hex(random_bytes(6)));
        $expiresAt = time() + self::PAIRING_TTL;
        // Single pending pairing at a time; a new code replaces the previous one.
        // Same semaphore as ConsumePairingCode to avoid interleaved writes.
        $semaphoreKey = 'LAB_Pairing_' . $this->InstanceID;
        if (IPS_SemaphoreEnter($semaphoreKey, 500)) {
            try {
                $this->WriteAttributeString('PendingPairings', json_encode([[
                    'codeHash'  => hash('sha256', $code),
                    'expiresAt' => $expiresAt,
                ]]));
            } finally {
                IPS_SemaphoreLeave($semaphoreKey);
            }
        }

        $connectUrl = $this->GetConnectUrl();
        $localUrls  = $this->GetLocalUrls();
        $b64url     = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $qrPayload  = 'symdo://pair?v=1'
            . '&u=' . $b64url($connectUrl)
            . ($localUrls !== [] ? '&l=' . $b64url($localUrls[0]) : '')
            . '&c=' . $code
            . '&n=' . rawurlencode($this->GetSystemName());

        $dataUri = $this->RenderQrDataUri($qrPayload);
        if ($dataUri !== '') {
            $this->UpdateFormField('PairingQrImage', 'image', $dataUri);
            $this->UpdateFormField('PairingQrImage', 'visible', true);
        }

        $caption = sprintf($this->Translate('Pairing code: %s (valid for 10 minutes)'), $code);
        if ($connectUrl === '') {
            $caption .= "\n" . $this->Translate('Warning: No Symcon Connect URL found. Enter the server address manually in the app.');
        }
        $this->UpdateFormField('PairingCodeLabel', 'caption', $caption);
        $this->UpdateFormField('PairingCodeLabel', 'visible', true);

        return json_encode([
            'code'       => $code,
            'expiresAt'  => $expiresAt,
            'connectUrl' => $connectUrl,
            'qrPayload'  => $qrPayload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function GetPairedDevices(): string
    {
        return json_encode($this->BuildDeviceRows(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function RevokeDevice(string $DeviceId): void
    {
        $deviceId = trim($DeviceId);
        if ($deviceId === '') {
            return;
        }
        $this->ModifyPairedDevices(function (array $devices) use ($deviceId): array {
            foreach ($devices as &$device) {
                if (($device['id'] ?? '') === $deviceId) {
                    $device['revoked'] = true;
                }
            }
            unset($device);
            return $devices;
        });
        $this->RefreshDeviceListFormField();
    }

    public function RemoveRevokedDevices(): void
    {
        $this->ModifyPairedDevices(static function (array $devices): array {
            return array_values(array_filter($devices, static fn(array $d): bool => ($d['revoked'] ?? false) !== true));
        });
        $this->RefreshDeviceListFormField();
    }

    private function RemoveDevice(string $deviceId): void
    {
        if ($deviceId === '') {
            return;
        }
        $this->ModifyPairedDevices(static function (array $devices) use ($deviceId): array {
            return array_values(array_filter($devices, static fn(array $d): bool => ($d['id'] ?? '') !== $deviceId));
        });
    }

    private function ConsumePairingCode(string $code): bool
    {
        $semaphoreKey = 'LAB_Pairing_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            return false;
        }
        try {
            $pending = json_decode($this->ReadAttributeString('PendingPairings'), true);
            if (!is_array($pending)) {
                $pending = [];
            }
            $codeHash = hash('sha256', $code);
            $now      = time();
            $found    = false;
            $keep     = [];
            $changed  = false;
            foreach ($pending as $entry) {
                if (!is_array($entry) || (int)($entry['expiresAt'] ?? 0) < $now) {
                    $changed = true; // drop expired entries
                    continue;
                }
                if (!$found && hash_equals((string)($entry['codeHash'] ?? ''), $codeHash)) {
                    $consumedAt = (int)($entry['consumedAt'] ?? 0);
                    if ($consumedAt === 0) {
                        // First use: keep the code alive for a short grace window so
                        // the app can retry when the pairing response gets lost.
                        $found = true;
                        $entry['consumedAt'] = $now;
                        $entry['expiresAt']  = min((int)$entry['expiresAt'], $now + 90);
                        $keep[] = $entry;
                        $changed = true;
                    } elseif ($now - $consumedAt <= 90) {
                        $found = true;
                        $keep[] = $entry;
                    } else {
                        $changed = true; // grace window over: burn the code
                    }
                    continue;
                }
                $keep[] = $entry;
            }
            if ($changed) {
                // Only write when something changed — /v1/pair is unauthenticated
                // and must not cause attribute writes on every probe.
                $this->WriteAttributeString('PendingPairings', json_encode($keep));
            }
            return $found;
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function RegisterPairedDevice(array $info): ?string
    {
        $token = bin2hex(random_bytes(32));
        $device = [
            'id'         => bin2hex(random_bytes(4)),
            'tokenHash'  => hash('sha256', $token),
            'deviceName' => (string)($info['deviceName'] ?? ''),
            'model'      => (string)($info['model'] ?? ''),
            'platform'   => (string)($info['platform'] ?? ''),
            'appVersion' => (string)($info['appVersion'] ?? ''),
            'createdAt'  => time(),
            'lastSeenAt' => time(),
            'revoked'    => false,
        ];
        $stored = $this->ModifyPairedDevices(static function (array $devices) use ($device): array {
            $devices[] = $device;
            return $devices;
        });
        if (!$stored) {
            return null; // must not hand out a token that was never persisted
        }
        $this->RefreshDeviceListFormField();
        return $token;
    }

    private function AuthenticateRequest(bool $allowQueryToken): ?array
    {
        $token = $this->GetBearerToken($allowQueryToken);
        if ($token === '') {
            return null;
        }
        $tokenHash = hash('sha256', $token);
        foreach ($this->LoadPairedDevices() as $device) {
            if (hash_equals((string)($device['tokenHash'] ?? ''), $tokenHash)) {
                if (($device['revoked'] ?? false) !== true) {
                    $this->TouchDeviceLastSeen((string)($device['id'] ?? ''));
                }
                return $device;
            }
        }
        return null;
    }

    private function TouchDeviceLastSeen(string $deviceId): void
    {
        if ($deviceId === '') {
            return;
        }
        // Throttle attribute writes: one update per device per 5 minutes is plenty
        foreach ($this->LoadPairedDevices() as $device) {
            if (($device['id'] ?? '') === $deviceId) {
                if (time() - (int)($device['lastSeenAt'] ?? 0) < 300) {
                    return;
                }
                break;
            }
        }
        $this->ModifyPairedDevices(static function (array $devices) use ($deviceId): array {
            foreach ($devices as &$device) {
                if (($device['id'] ?? '') === $deviceId) {
                    $device['lastSeenAt'] = time();
                }
            }
            unset($device);
            return $devices;
        });
    }

    private function LoadPairedDevices(): array
    {
        $data = json_decode($this->ReadAttributeString('PairedDevices'), true);
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_filter($data, static fn($d): bool => is_array($d)));
    }

    private function ModifyPairedDevices(callable $modifier): bool
    {
        $semaphoreKey = 'LAB_Devices_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('DeviceRegistry', 'Semaphore timeout on device modification', 0);
            return false;
        }
        try {
            $devices = $modifier($this->LoadPairedDevices());
            $this->WriteAttributeString('PairedDevices', json_encode(array_values($devices), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return true;
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function BuildDeviceRows(): array
    {
        $rows = [];
        foreach ($this->LoadPairedDevices() as $device) {
            $lastSeen = (int)($device['lastSeenAt'] ?? 0);
            $rows[] = [
                'deviceId'   => (string)($device['id'] ?? ''),
                'deviceName' => (string)($device['deviceName'] ?? ''),
                'model'      => (string)($device['model'] ?? ''),
                'platform'   => (string)($device['platform'] ?? ''),
                'appVersion' => (string)($device['appVersion'] ?? ''),
                'createdAt'  => date('d.m.Y H:i', (int)($device['createdAt'] ?? 0)),
                'lastSeenAt' => $lastSeen > 0 ? date('d.m.Y H:i', $lastSeen) : '—',
                'status'     => ($device['revoked'] ?? false) === true
                    ? $this->Translate('Revoked')
                    : $this->Translate('Active'),
            ];
        }
        return $rows;
    }

    private function RefreshDeviceListFormField(): void
    {
        $this->UpdateFormField(
            'PairedDevicesList',
            'values',
            json_encode($this->BuildDeviceRows(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
