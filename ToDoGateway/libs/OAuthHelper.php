<?php

declare(strict_types=1);

trait OAuthHelper
{
    private function OAuthGetEncryptionKey(string $Prefix): string
    {
        return 'TDL_' . $this->InstanceID . '_' . $Prefix;
    }

    private function OAuthEncryptToken(string $Token, string $KeyPrefix): string
    {
        if ($Token === '') {
            return '';
        }
        $key = $this->OAuthGetEncryptionKey($KeyPrefix);
        $data = base64_encode($Token);
        $encoded = '';
        for ($i = 0; $i < strlen($data); $i++) {
            $encoded .= chr(ord($data[$i]) ^ ord($key[$i % strlen($key)]));
        }
        return base64_encode($encoded);
    }

    private function OAuthDecryptToken(string $Encrypted, string $KeyPrefix): string
    {
        if ($Encrypted === '') {
            return '';
        }
        $key = $this->OAuthGetEncryptionKey($KeyPrefix);
        $decoded = base64_decode($Encrypted);
        if ($decoded === false) {
            return '';
        }
        $data = '';
        for ($i = 0; $i < strlen($decoded); $i++) {
            $data .= chr(ord($decoded[$i]) ^ ord($key[$i % strlen($key)]));
        }
        $result = base64_decode($data);
        return $result === false ? '' : $result;
    }

    private function OAuthSetEncryptedToken(string $Attribute, string $Token, string $KeyPrefix): void
    {
        $this->WriteAttributeString($Attribute, $this->OAuthEncryptToken($Token, $KeyPrefix));
    }

    private function OAuthGetDecryptedToken(string $Attribute, string $KeyPrefix): string
    {
        $encrypted = @$this->ReadAttributeString($Attribute);
        if (!is_string($encrypted) || $encrypted === '') {
            return '';
        }
        return $this->OAuthDecryptToken($encrypted, $KeyPrefix);
    }

    private function OAuthHttpRequest(string $Method, string $Url, array $Headers, mixed $Body = null, bool $UseAuth = true, string $DebugLabel = 'OAuth', ?string $BearerToken = null): ?string
    {
        $allHeaders = $Headers;

        if ($UseAuth && $BearerToken !== null) {
            if ($BearerToken === '') {
                $this->SendDebug($DebugLabel, 'No valid access token', 0);
                return null;
            }
            $allHeaders[] = 'Authorization: Bearer ' . $BearerToken;
        }

        if (is_array($Body)) {
            $allHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
            $bodyStr = http_build_query($Body);
        } elseif (is_string($Body) && $Body !== '') {
            $allHeaders[] = 'Content-Type: application/json';
            $bodyStr = $Body;
        } else {
            $bodyStr = '';
        }

        $opts = [
            'http' => [
                'method' => $Method,
                'header' => $allHeaders,
                'content' => $bodyStr,
                'ignore_errors' => true,
                'follow_location' => 0, // R15: no auto-follow — status/headers must belong to the final response
                'timeout' => 30
            ]
        ];

        $context = stream_context_create($opts);
        $result = @file_get_contents($Url, false, $context);

        if ($result === false) {
            $this->SendDebug($DebugLabel, 'HTTP request failed: ' . $Url, 0);
            return null;
        }

        return $result;
    }

    private function OAuthHttpRequestMeta(string $Method, string $Url, array $Headers, mixed $Body = null, bool $UseAuth = true, string $DebugLabel = 'OAuth', ?string $BearerToken = null): ?array
    {
        $allHeaders = $Headers;
        if ($UseAuth && $BearerToken !== null) {
            if ($BearerToken === '') {
                $this->SendDebug($DebugLabel, 'No valid access token', 0);
                return null;
            }
            $allHeaders[] = 'Authorization: Bearer ' . $BearerToken;
        }

        if (is_array($Body)) {
            $allHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
            $bodyStr = http_build_query($Body);
        } elseif (is_string($Body) && $Body !== '') {
            $allHeaders[] = 'Content-Type: application/json';
            $bodyStr = $Body;
        } else {
            $bodyStr = '';
        }

        $opts = [
            'http' => [
                'method' => $Method,
                'header' => $allHeaders,
                'content' => $bodyStr,
                'ignore_errors' => true,
                'follow_location' => 0, // R15: no auto-follow — status/headers must belong to the final response
                'timeout' => 30
            ]
        ];
        $context = stream_context_create($opts);

        $result = @file_get_contents($Url, false, $context);
        if ($result === false) {
            $this->SendDebug($DebugLabel, 'HTTP request failed: ' . $Url, 0);
            return null;
        }

        $headers = $http_response_header ?? [];
        $status = 0;
        if (isset($headers[0]) && preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string)$headers[0], $m)) {
            $status = (int)$m[1];
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $result
        ];
    }

    // ── Throttling / back-off (A2) ─────────────────────────────────────────────

    private function OAuthIsThrottled(string $RetryAttr): bool
    {
        return $RetryAttr !== '' && time() < $this->ReadAttributeInteger($RetryAttr);
    }

    private function OAuthParseRetryAfter(array $Headers, int $DefaultSeconds): int
    {
        foreach ($Headers as $h) {
            if (stripos((string)$h, 'Retry-After:') === 0) {
                $value = trim(substr((string)$h, strlen('Retry-After:')));
                if ($value !== '' && ctype_digit($value)) {
                    return max(1, (int)$value);
                }
                $ts = strtotime($value);
                if ($ts !== false) {
                    return max(1, $ts - time());
                }
            }
        }
        return $DefaultSeconds;
    }

    /**
     * Record a back-off window after a 429/503/504. Honors Retry-After when present,
     * otherwise falls back to a fixed delay. Capped at one hour.
     */
    private function OAuthNoteThrottle(string $RetryAttr, array $Headers, string $DebugLabel): void
    {
        if ($RetryAttr === '') {
            return;
        }
        $delay = min(3600, max(1, $this->OAuthParseRetryAfter($Headers, 60)));
        $this->WriteAttributeInteger($RetryAttr, time() + $delay);
        $this->SendDebug($DebugLabel, 'Throttled by server – backing off for ' . $delay . 's', 0);
    }

    private function OAuthGetRedirectUri(string $HookPath): string
    {
        $host = CC_GetConnectURL(IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0] ?? 0);
        if ($host === '' || $host === false) {
            $host = 'http://localhost:3777';
        }
        return rtrim($host, '/') . rtrim($HookPath, '/');
    }

    private function OAuthExchangeToken(string $TokenUrl, array $PostData, string $KeyPrefix, string $AccessAttr, string $RefreshAttr, string $ExpiresAttr, string $DebugLabel): bool
    {
        $response = $this->OAuthHttpRequest('POST', $TokenUrl, [], $PostData, false, $DebugLabel);
        if ($response === null) {
            $this->SendDebug($DebugLabel, 'Token exchange failed', 0);
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['access_token'])) {
            $this->SendDebug($DebugLabel, 'Invalid token response: ' . $response, 0);
            return false;
        }

        $this->OAuthSetEncryptedToken($AccessAttr, (string)$data['access_token'], $KeyPrefix);
        if (isset($data['refresh_token'])) {
            $this->OAuthSetEncryptedToken($RefreshAttr, (string)$data['refresh_token'], $KeyPrefix);
        }
        $expiresIn = (int)($data['expires_in'] ?? 3600);
        $this->WriteAttributeInteger($ExpiresAttr, time() + $expiresIn - 60);

        $this->SendDebug($DebugLabel, 'OAuth tokens received successfully', 0);
        return true;
    }

    private function OAuthRefreshToken(string $TokenUrl, string $KeyPrefix, string $AccessAttr, string $RefreshAttr, string $ExpiresAttr, string $ClientId, string $ClientSecret, string $DebugLabel, string $Scope = '', ?string $RefreshTokenOverride = null): bool
    {
        // R17: serialize refreshes per gateway+provider. Two concurrent callers would otherwise
        // refresh with the same refresh token; if the loser is answered with invalid_grant it
        // deletes the fresh tokens the winner just stored (spontaneous disconnect + forced
        // reauthorization). Microsoft rotates refresh tokens, so this is not hypothetical.
        $preAccessToken = $this->OAuthGetDecryptedToken($AccessAttr, $KeyPrefix);
        $sem = 'TGW_Refresh_' . $this->InstanceID . '_' . $KeyPrefix;
        if (!IPS_SemaphoreEnter($sem, 10000)) {
            $this->SendDebug($DebugLabel, 'Refresh skipped – another refresh is in progress', 0);
            return false;
        }
        try {
            if ($RefreshTokenOverride === null) {
                $current = $this->OAuthGetDecryptedToken($AccessAttr, $KeyPrefix);
                if ($current !== '' && $current !== $preAccessToken) {
                    return true; // another caller completed a refresh while we waited for the lock
                }
            }
            return $this->OAuthRefreshTokenLocked($TokenUrl, $KeyPrefix, $AccessAttr, $RefreshAttr, $ExpiresAttr, $ClientId, $ClientSecret, $DebugLabel, $Scope, $RefreshTokenOverride);
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    private function OAuthRefreshTokenLocked(string $TokenUrl, string $KeyPrefix, string $AccessAttr, string $RefreshAttr, string $ExpiresAttr, string $ClientId, string $ClientSecret, string $DebugLabel, string $Scope = '', ?string $RefreshTokenOverride = null): bool
    {
        $refreshToken = $RefreshTokenOverride ?? $this->OAuthGetDecryptedToken($RefreshAttr, $KeyPrefix);
        if ($refreshToken === '' || $ClientId === '' || $ClientSecret === '') {
            return false;
        }

        $postData = [
            'refresh_token' => $refreshToken,
            'client_id' => $ClientId,
            'client_secret' => $ClientSecret,
            'grant_type' => 'refresh_token'
        ];
        if ($Scope !== '') {
            $postData['scope'] = $Scope;
        }

        $meta = $this->OAuthHttpRequestMeta('POST', $TokenUrl, [], $postData, false, $DebugLabel);
        if ($meta === null) {
            // Transport-level failure (network/timeout): transient, keep the tokens.
            return false;
        }

        $status = (int)($meta['status'] ?? 0);
        $body = (string)($meta['body'] ?? '');
        $data = json_decode($body, true);

        if (is_array($data) && isset($data['access_token'])) {
            $this->OAuthSetEncryptedToken($AccessAttr, (string)$data['access_token'], $KeyPrefix);
            if (isset($data['refresh_token'])) {
                $this->OAuthSetEncryptedToken($RefreshAttr, (string)$data['refresh_token'], $KeyPrefix);
            }
            $expiresIn = (int)($data['expires_in'] ?? 3600);
            $this->WriteAttributeInteger($ExpiresAttr, time() + $expiresIn - 60);
            $this->SendDebug($DebugLabel, 'Access token refreshed', 0);
            return true;
        }

        // Only a dead refresh token (invalid_grant) is permanent. invalid_client /
        // unauthorized_client point at a wrong client secret, not a dead token — do NOT
        // clear the token there (a corrected secret must keep working with the same token).
        $error = is_array($data) ? (string)($data['error'] ?? '') : '';
        $permanent = in_array($status, [400, 401], true) && $error === 'invalid_grant';

        if ($permanent) {
            // Refresh token is no longer valid (revoked, password change, expiry). Clear the
            // stored tokens so IsConnected() reports disconnected and the user is prompted to
            // re-authorize, instead of every sync silently returning null until manual reset.
            $this->OAuthSetEncryptedToken($AccessAttr, '', $KeyPrefix);
            $this->OAuthSetEncryptedToken($RefreshAttr, '', $KeyPrefix);
            $this->WriteAttributeInteger($ExpiresAttr, 0);
            $this->SendDebug($DebugLabel, 'Refresh rejected (' . $error . ') – reauthorization required', 0);
            $this->LogMessage($this->Translate('The ToDo authorization has expired. Please reconnect the account in the ToDo gateway.'), KL_WARNING);
            return false;
        }

        // Any other outcome (5xx, throttling, malformed body) is treated as transient.
        $this->SendDebug($DebugLabel, 'Refresh failed transiently (HTTP ' . $status . '): ' . $body, 0);
        return false;
    }

    private function OAuthGetValidAccessToken(string $KeyPrefix, string $AccessAttr, string $RefreshAttr, string $ExpiresAttr, string $TokenUrl, string $ClientId, string $ClientSecret, string $DebugLabel, string $Scope = ''): string
    {
        $expires = $this->ReadAttributeInteger($ExpiresAttr);
        // Refresh proactively when the token is expired OR the expiry is unknown (0 after a
        // fresh install / migration / reset) — but only if we actually hold a refresh token.
        if (($expires <= 0 || time() >= $expires)
            && $this->OAuthGetDecryptedToken($RefreshAttr, $KeyPrefix) !== '') {
            if (!$this->OAuthRefreshToken($TokenUrl, $KeyPrefix, $AccessAttr, $RefreshAttr, $ExpiresAttr, $ClientId, $ClientSecret, $DebugLabel, $Scope)) {
                return '';
            }
        }
        return $this->OAuthGetDecryptedToken($AccessAttr, $KeyPrefix);
    }

    /**
     * Authenticated API request with reactive token refresh. On HTTP 401 it forces one token
     * refresh (bypassing the expiry timer) and retries the request once, so a token invalidated
     * before its stored expiry can recover within the same run instead of failing for ~1 hour.
     * Returns the response meta ['status','headers','body'] or null on transport failure /
     * missing token.
     */
    private function OAuthAuthorizedRequest(string $Method, string $Url, mixed $Body, string $KeyPrefix, string $AccessAttr, string $RefreshAttr, string $ExpiresAttr, string $TokenUrl, string $ClientId, string $ClientSecret, string $DebugLabel, string $Scope = '', array $Headers = [], string $RetryAttr = ''): ?array
    {
        // A2: while inside a server-requested back-off window, do not touch the network
        // at all — this is what stops a fixed sync timer from re-hammering a throttled API.
        if ($this->OAuthIsThrottled($RetryAttr)) {
            $this->SendDebug($DebugLabel, 'Skipped – backing off until ' . date('H:i:s', $this->ReadAttributeInteger($RetryAttr)), 0);
            return null;
        }

        $token = $this->OAuthGetValidAccessToken($KeyPrefix, $AccessAttr, $RefreshAttr, $ExpiresAttr, $TokenUrl, $ClientId, $ClientSecret, $DebugLabel, $Scope);
        if ($token === '') {
            return null;
        }

        $bodyStr = is_array($Body) ? json_encode($Body) : $Body;
        $meta = $this->OAuthHttpRequestMeta($Method, $Url, $Headers, $bodyStr, true, $DebugLabel, $token);
        if ($meta === null) {
            return null;
        }

        if ((int)($meta['status'] ?? 0) === 401) {
            $this->SendDebug($DebugLabel, 'HTTP 401 – forcing token refresh and retrying once', 0);
            if ($this->OAuthRefreshToken($TokenUrl, $KeyPrefix, $AccessAttr, $RefreshAttr, $ExpiresAttr, $ClientId, $ClientSecret, $DebugLabel, $Scope)) {
                $token = $this->OAuthGetDecryptedToken($AccessAttr, $KeyPrefix);
                if ($token !== '') {
                    $retry = $this->OAuthHttpRequestMeta($Method, $Url, $Headers, $bodyStr, true, $DebugLabel, $token);
                    if ($retry !== null) {
                        $meta = $retry;
                    }
                }
            }
        }

        // A2: record a back-off window on throttling / transient server overload. Google also
        // signals quota exhaustion as HTTP 403 with a rate/quota reason in the body (R12) —
        // only those 403s back off; permission errors pass through unchanged.
        $status = (int)($meta['status'] ?? 0);
        $isQuota403 = $status === 403 && preg_match(
            '/rateLimitExceeded|userRateLimitExceeded|quotaExceeded|dailyLimitExceeded|usageLimits/i',
            (string)($meta['body'] ?? '')
        ) === 1;
        if (in_array($status, [429, 503, 504], true) || $isQuota403) {
            $this->OAuthNoteThrottle($RetryAttr, $meta['headers'] ?? [], $DebugLabel);
            return null;
        }

        return $meta;
    }
}
