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
        return $this->OAuthDecryptToken($this->ReadAttributeString($Attribute), $KeyPrefix);
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

    private function OAuthGetRedirectUri(string $HookPath): string
    {
        $host = CC_GetConnectURL(IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0] ?? 0);
        if ($host === '' || $host === false) {
            $host = 'http://localhost:3777';
        }
        return rtrim($host, '/') . rtrim($HookPath, '/');
    }

    private function OAuthRegisterWebHook(string $HookPath): void
    {
        $hookPath = rtrim($HookPath, '/');
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            return;
        }
        $hookId = $ids[0];
        $hooks = json_decode(IPS_GetProperty($hookId, 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $hook) {
            if (($hook['Hook'] ?? '') === $hookPath) {
                return;
            }
        }
        $hooks[] = ['Hook' => $hookPath, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookId, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookId);
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

        $response = $this->OAuthHttpRequest('POST', $TokenUrl, [], $postData, false, $DebugLabel);
        if ($response === null) {
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['access_token'])) {
            $this->SendDebug($DebugLabel, 'Refresh failed: ' . $response, 0);
            return false;
        }

        $this->OAuthSetEncryptedToken($AccessAttr, (string)$data['access_token'], $KeyPrefix);
        if (isset($data['refresh_token'])) {
            $this->OAuthSetEncryptedToken($RefreshAttr, (string)$data['refresh_token'], $KeyPrefix);
        }
        $expiresIn = (int)($data['expires_in'] ?? 3600);
        $this->WriteAttributeInteger($ExpiresAttr, time() + $expiresIn - 60);

        $this->SendDebug($DebugLabel, 'Access token refreshed', 0);
        return true;
    }

    private function OAuthGetValidAccessToken(string $KeyPrefix, string $AccessAttr, string $RefreshAttr, string $ExpiresAttr, string $TokenUrl, string $ClientId, string $ClientSecret, string $DebugLabel, string $Scope = ''): string
    {
        $expires = $this->ReadAttributeInteger($ExpiresAttr);
        if ($expires > 0 && time() >= $expires) {
            if (!$this->OAuthRefreshToken($TokenUrl, $KeyPrefix, $AccessAttr, $RefreshAttr, $ExpiresAttr, $ClientId, $ClientSecret, $DebugLabel, $Scope)) {
                return '';
            }
        }
        return $this->OAuthGetDecryptedToken($AccessAttr, $KeyPrefix);
    }
}
