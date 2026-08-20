<?php

declare(strict_types=1);

trait DeviceRegistry
{
    /**
     * Erzeugt einen Einmal-Code (10 min gültig) und legt ihn als einzige
     * ausstehende Kopplung ab. Von App- und Browser-Pairing gemeinsam genutzt.
     * @return array{code: string, expiresAt: int}
     */
    private function MintPairingCode(): array
    {
        $code      = strtoupper(bin2hex(random_bytes(6)));
        $expiresAt = time() + self::PAIRING_TTL;
        // Single pending pairing at a time; a new code replaces the previous one.
        // Same semaphore as ConsumePairingCode to avoid interleaved writes.
        $semaphoreKey = 'TGW_Pairing_' . $this->InstanceID;
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
        return ['code' => $code, 'expiresAt' => $expiresAt];
    }

    public function CreatePairing(): string
    {
        $minted    = $this->MintPairingCode();
        $code      = $minted['code'];
        $expiresAt = $minted['expiresAt'];

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

    /**
     * Browser-Zugang: erzeugt einen Einmal-Code und einen HTTPS-QR-Code, der die
     * Web-App-Seite mit dem Code im URL-Fragment öffnet. Die Seite löst den Code
     * gegen ein Token ein (localStorage) — derselbe Pairing-Pfad wie die App,
     * nur öffnet der QR eine URL im Browser statt des symdo://-Deep-Links.
     */
    public function CreateWebAccess(): string
    {
        $minted     = $this->MintPairingCode();
        $code       = $minted['code'];
        $connectUrl = $this->GetConnectUrl();
        $localUrls  = $this->GetLocalUrls();
        $base       = $connectUrl !== '' ? $connectUrl : (string)($localUrls[0] ?? '');
        // Code im Fragment (#): erreicht den Server nicht (keine Logs), nur das JS.
        $webUrl     = $base !== '' ? $base . '/hook/' . self::WEBAPP_HOOK_PATH . '#c=' . $code : '';

        if ($webUrl !== '') {
            $dataUri = $this->RenderQrDataUri($webUrl);
            if ($dataUri !== '') {
                $this->UpdateFormField('WebAccessQrImage', 'image', $dataUri);
                $this->UpdateFormField('WebAccessQrImage', 'visible', true);
            }
            $caption = sprintf($this->Translate('Scan with the phone camera to open the web app (valid for 10 minutes). Link: %s'), $webUrl);
        } else {
            $caption = $this->Translate('Warning: No Symcon Connect URL found. Enable Symcon Connect to reach the web app over the internet.');
        }
        $this->UpdateFormField('WebAccessLabel', 'caption', $caption);
        $this->UpdateFormField('WebAccessLabel', 'visible', true);

        return json_encode([
            'code'      => $code,
            'expiresAt' => $minted['expiresAt'],
            'url'       => $webUrl,
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
        $semaphoreKey = 'TGW_Pairing_' . $this->InstanceID;
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

    /**
     * Zählt einen KI-Aufruf für das Gerät und meldet, ob das Limit noch frei war.
     * Gleitendes Fenster je Gerät; ohne Geräte-ID (Kachel-Relay) greift nur der
     * Concurrency-Guard. Fail-open bei Semaphore-Timeout — wie beim Dedup ist das
     * Limit „best effort" und darf legitime Nutzung nicht blockieren.
     */
    private function AiRateLimitAllows(string $deviceId, int $max, int $window): bool
    {
        return $this->DeviceRateAllows($deviceId, 'ai', $max, $window);
    }

    /**
     * Gleitendes Fenster je Gerät und je Zweck. `$zweck` benennt das Feldpaar
     * (`aiWindowStart`/`aiCount`, `pushWindowStart`/`pushCount`), damit sich die
     * KI-Aufrufe und die Benachrichtigungen nicht gegenseitig aufbrauchen.
     */
    private function DeviceRateAllows(string $deviceId, string $zweck, int $max, int $window): bool
    {
        if ($deviceId === '') {
            return true;
        }
        $feldStart = $zweck . 'WindowStart';
        $feldZahl  = $zweck . 'Count';
        $allowed = true;
        $this->ModifyPairedDevices(static function (array $devices) use ($deviceId, $max, $window, $feldStart, $feldZahl, &$allowed): array {
            $now = time();
            foreach ($devices as &$device) {
                if (($device['id'] ?? '') !== $deviceId) {
                    continue;
                }
                $start = (int)($device[$feldStart] ?? 0);
                $count = (int)($device[$feldZahl] ?? 0);
                if ($now - $start >= $window) {
                    $start = $now;
                    $count = 0;
                }
                if ($count >= $max) {
                    $allowed = false;
                } else {
                    $count++;
                }
                $device[$feldStart] = $start;
                $device[$feldZahl]  = $count;
                break;
            }
            unset($device);
            return $devices;
        });
        return $allowed;
    }

    // ───────────────────────── Push-Abos am Gerät ─────────────────────────

    /**
     * Abo eines Geräts ablegen. Ein Gerät hat höchstens EIN Abo: Der Browser
     * vergibt beim Erneuern einen neuen Endpunkt, der alte ist dann tot.
     *
     * `$userId` ist die Zuordnung zum Familienmitglied (leer = alle Nachrichten
     * des Haushalts). Sie hängt am Gerät und nicht am Abo, damit sie ein
     * Endpunkt-Wechsel überlebt.
     */
    private function PushStoreSubscription(string $deviceId, string $endpoint, string $p256dh, string $auth, string $userId): bool
    {
        if ($deviceId === '' || !str_starts_with($endpoint, 'https://')) {
            return false;
        }
        // Kennung des Schluessels, unter dem dieses Abo entstanden ist. Wechselt der
        // VAPID-Schluessel, sind alle Abos wertlos — mit der Kennung faellt das auf,
        // statt in 403-Antworten zu enden.
        $kennung = $this->PushVapidFingerprint();
        return $this->ModifyPairedDevices(static function (array $devices) use ($deviceId, $endpoint, $p256dh, $auth, $userId, $kennung): array {
            foreach ($devices as &$device) {
                if (($device['id'] ?? '') !== $deviceId) {
                    // Dasselbe Abo an einem anderen Gerät: Der Endpunkt gehört genau
                    // einem Browser. Taucht er woanders auf, ist der alte Eintrag ein
                    // Überrest (neu gekoppelt, Token gewechselt) und muss weg.
                    if (($device['pushEndpoint'] ?? '') === $endpoint) {
                        unset($device['pushEndpoint'], $device['pushP256dh'], $device['pushAuth'], $device['pushKeyId'], $device['pushSince'], $device['pushFails']);
                    }
                    continue;
                }
                $device['pushEndpoint'] = $endpoint;
                $device['pushP256dh']   = $p256dh;
                $device['pushAuth']     = $auth;
                $device['pushUserId']   = $userId;
                $device['pushKeyId']    = $kennung;
                $device['pushSince']    = (int)($device['pushSince'] ?? 0) > 0 ? (int)$device['pushSince'] : time();
                $device['pushFails']    = 0;
            }
            unset($device);
            return $devices;
        });
    }

    /** Abo entfernen — abgemeldet, abgelaufen oder vom Dienst als tot gemeldet. */
    private function PushDropSubscription(string $deviceId): bool
    {
        if ($deviceId === '') {
            return false;
        }
        return $this->ModifyPairedDevices(static function (array $devices) use ($deviceId): array {
            foreach ($devices as &$device) {
                if (($device['id'] ?? '') === $deviceId) {
                    unset($device['pushEndpoint'], $device['pushP256dh'], $device['pushAuth'], $device['pushKeyId'], $device['pushSince'], $device['pushFails']);
                }
            }
            unset($device);
            return $devices;
        });
    }

    /**
     * Alle Abos, optional nur die eines Familienmitglieds.
     *
     * Widerrufene Geräte bleiben außen vor: Wer den Zugang verloren hat, soll auch
     * keine Nachrichten mehr bekommen.
     *
     * @return list<array{deviceId: string, endpoint: string, p256dh: string, auth: string, userId: string, name: string}>
     */
    private function PushSubscriptions(string $userId = ''): array
    {
        $raus = [];
        foreach ($this->LoadPairedDevices() as $device) {
            if (($device['revoked'] ?? false) === true) {
                continue;
            }
            $endpunkt = (string)($device['pushEndpoint'] ?? '');
            if ($endpunkt === '') {
                continue;
            }
            $gehoert = (string)($device['pushUserId'] ?? '');
            // Ein Gerät OHNE Zuordnung ist ein Haushaltsgerät und bekommt alles —
            // auch das, was an ein Mitglied gerichtet ist. Andernfalls gäbe es eine
            // stille Falle: Solange niemand sein Gerät einem Mitglied zuordnet (die
            // Vorgabe!), verschwände jede Nachricht mit Ziel-Mitglied ins Nichts.
            // Wer nur seine eigenen Nachrichten will, ordnet sein Gerät zu; dann
            // bekommt es die des Haushalts und die eigenen, aber nicht die der
            // anderen.
            if ($userId !== '' && $gehoert !== '' && $gehoert !== $userId) {
                continue;
            }
            $raus[] = [
                'deviceId' => (string)($device['id'] ?? ''),
                'endpoint' => $endpunkt,
                'p256dh'   => (string)($device['pushP256dh'] ?? ''),
                'auth'     => (string)($device['pushAuth'] ?? ''),
                'userId'   => $gehoert,
                'keyId'    => (string)($device['pushKeyId'] ?? ''),
                'name'     => (string)($device['deviceName'] ?? ''),
            ];
        }
        return $raus;
    }

    /**
     * Fehlschlag vermerken. Ab `$grenze` Fehlschlägen fliegt das Abo — ein Gerät,
     * das dauerhaft nicht erreichbar ist, kostet sonst bei jeder Nachricht Zeit.
     */
    private function PushNoteFailure(string $deviceId, int $grenze = 5): void
    {
        if ($deviceId === '') {
            return;
        }
        $this->ModifyPairedDevices(static function (array $devices) use ($deviceId, $grenze): array {
            foreach ($devices as &$device) {
                if (($device['id'] ?? '') !== $deviceId) {
                    continue;
                }
                $zahl = (int)($device['pushFails'] ?? 0) + 1;
                if ($zahl >= $grenze) {
                    unset($device['pushEndpoint'], $device['pushP256dh'], $device['pushAuth'], $device['pushKeyId'], $device['pushSince'], $device['pushFails']);
                } else {
                    $device['pushFails'] = $zahl;
                }
                break;
            }
            unset($device);
            return $devices;
        });
    }

    /** Das Abo genau eines Geräts — für die Testnachricht an den Aufrufer. */
    private function PushSubscriptionOf(string $deviceId): ?array
    {
        foreach ($this->PushSubscriptions() as $abo) {
            if ($abo['deviceId'] === $deviceId) {
                return $abo;
            }
        }
        return null;
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
        $semaphoreKey = 'TGW_Devices_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('DeviceRegistry', 'Semaphore timeout on device modification', 0);
            return false;
        }
        try {
            $devices = $modifier($this->LoadPairedDevices());
            $json = json_encode(array_values($devices), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->WriteAttributeString('PairedDevices', (string)$json);
            // Rücklese-Probe: `WriteAttributeString` auf ein noch nicht registriertes
            // Attribut wirft nicht, es tut still nichts. Ohne diese Prüfung gäbe
            // RegisterPairedDevice() einen Token heraus, der nie abgelegt wurde —
            // genau das, was der Kommentar dort zu verhindern verspricht.
            if ($this->ReadAttributeString('PairedDevices') !== $json) {
                $this->LogMessage(
                    'Geräteliste nicht beschreibbar (Attribut PairedDevices fehlt) — '
                    . 'nach dem nächsten Neustart von IP-Symcon greift sie.',
                    KL_ERROR
                );
                return false;
            }
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
                'push'       => (string)($device['pushEndpoint'] ?? '') === ''
                    ? '—'
                    : $this->Translate('On'),
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
