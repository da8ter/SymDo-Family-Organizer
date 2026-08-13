<?php

declare(strict_types=1);

trait ApiRouter
{
    private function HandleApiRequest(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // CORS: die über Connect (HTTPS) geladene Web-App darf im Heimnetz die
        // lokale HTTPS-API cross-origin aufrufen. Der Token (Bearer) ist die
        // Sicherheitsgrenze, nicht der Origin → "*" ist unbedenklich (kein Cookie,
        // credentials:'omit'). Preflight (OPTIONS) ohne Auth vor allem anderen.
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        if ($method === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Access-Control-Max-Age: 600');
            http_response_code(204);
            return;
        }

        $route  = $this->ResolveRoute();

        if (($route[0] ?? '') !== 'v1') {
            $this->SendApiError('unknown_route', 'Unknown API route', 404);
            return;
        }
        $resource = $route[1] ?? '';

        // Unauthenticated endpoints: pairing and a lightweight reachability ping
        // (the app validates a manually entered address before pairing).
        if ($resource === 'pair' && $method === 'POST') {
            $this->HandlePair();
            return;
        }
        if ($resource === 'ping' && $method === 'GET') {
            // Bewusst OHNE BuildServerInfo(): dieser Endpoint ist unauthentifiziert
            // und über die öffentliche Connect-URL erreichbar. Systemname, Symcon-
            // Version, LAN-IPs und Connect-URL liefern nur die authentifizierten
            // Antworten von /pair und /discovery.
            $this->SendJson([
                'ok'         => true,
                'apiVersion' => self::API_VERSION,
            ]);
            return;
        }

        // Der Token darf nur dort als ?t=-Query stehen, wo ein einfacher Bild-Loader
        // keine Header setzen kann: GET /assets/… und GET /users/{id}/avatar.
        // Überall sonst muss er im Header reisen, damit der langlebige Token nicht
        // in Proxy-/Access-Logs landet — insbesondere NICHT bei schreibenden POSTs.
        $queryTokenOk = ($resource === 'assets' && $method === 'GET')
            || ($resource === 'users' && $method === 'GET' && ($route[3] ?? '') === 'avatar')
            // GET /ai/media/{id} lädt der System-PDF-Viewer bzw. ein neuer Tab direkt,
            // ganz ohne unser JavaScript — dort kann kein Header gesetzt werden.
            || ($resource === 'ai' && $method === 'GET' && ($route[2] ?? '') === 'media');
        $device = $this->AuthenticateRequest($queryTokenOk);
        if ($device === null) {
            $this->SendApiError('unauthorized', 'Missing or invalid token', 401);
            return;
        }
        if (($device['revoked'] ?? false) === true) {
            $this->SendApiError('token_revoked', 'This device pairing has been revoked', 401);
            return;
        }

        switch ($resource) {
            case 'pair':
                if ($method === 'DELETE') {
                    $this->HandleUnpair($device);
                    return;
                }
                break;
            case 'unpair': // POST alias in case DELETE does not survive a proxy
                if ($method === 'POST') {
                    $this->HandleUnpair($device);
                    return;
                }
                break;
            case 'discovery':
                if ($method === 'GET') {
                    $this->HandleDiscovery();
                    return;
                }
                break;
            case 'revisions':
                if ($method === 'GET') {
                    $this->HandleRevisions();
                    return;
                }
                break;
            case 'instances':
                $this->RouteInstance($method, $route);
                return;
            case 'ai':
                if ($method === 'POST' && ($route[2] ?? '') === 'extract') {
                    $this->HandleAiExtract($device);
                    return;
                }
                if ($method === 'POST' && ($route[2] ?? '') === 'ingredients') {
                    $this->HandleAiIngredients($device);
                    return;
                }
                if ($method === 'POST' && ($route[2] ?? '') === 'savephoto') {
                    $this->HandleAiSavePhoto($device);
                    return;
                }
                if ($method === 'POST' && ($route[2] ?? '') === 'media') {
                    $this->HandleAiGetMedia($device);
                    return;
                }
                if ($method === 'GET' && ($route[2] ?? '') === 'media') {
                    $this->HandleAiMediaFile((int)($route[3] ?? 0));
                    return;
                }
                break;
            case 'assets':
                if ($method === 'GET') {
                    $this->HandleAsset(array_slice($route, 2));
                    return;
                }
                break;
            case 'users':
                if ($method === 'GET' && ($route[3] ?? '') === 'avatar') {
                    $this->HandleUserAvatar((string)($route[2] ?? ''));
                    return;
                }
                if ($method === 'POST' && ($route[2] ?? '') === '') {
                    $this->HandleUserCreate();
                    return;
                }
                if ($method === 'POST' && ($route[3] ?? '') === '') {
                    $this->HandleUserUpdate((string)$route[2]);
                    return;
                }
                if ($method === 'GET') {
                    $this->SendJson(['ok' => true, 'users' => json_decode($this->GetUsers(), true)]);
                    return;
                }
                break;
        }
        $this->SendApiError('unknown_route', 'Unknown API route', 404);
    }

    private function ResolveRoute(): array
    {
        if (isset($_GET['r'])) {
            $path = (string)$_GET['r'];
        } else {
            $uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
            $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '');
            $prefix = '/hook/' . self::HOOK_PATH;
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }
        }
        $segments = explode('/', trim($path, '/'));
        return array_values(array_filter($segments, static fn(string $s): bool => $s !== ''));
    }

    private function RouteInstance(string $method, array $route): void
    {
        $id   = (int)($route[2] ?? 0);
        $kind = $this->GetInstanceKind($id);
        if ($kind === null) {
            $this->SendApiError('unknown_instance', 'Unknown instance: ' . $id, 404);
            return;
        }
        $sub = $route[3] ?? '';
        if ($sub === 'state' && $method === 'GET') {
            $this->HandleState($id, $kind);
            return;
        }
        if ($sub === 'actions' && $method === 'POST') {
            $this->HandleActions($id, $kind);
            return;
        }
        if ($sub === 'barcode' && $method === 'GET') {
            $ean = (string)($route[4] ?? ($_GET['ean'] ?? ''));
            $this->HandleBarcode($id, $kind, $ean);
            return;
        }
        if ($sub === 'visibility' && $method === 'POST') {
            $this->HandleVisibility($id);
            return;
        }
        $this->SendApiError('unknown_route', 'Unknown API route', 404);
    }

    /** Blendet eine Liste haushaltsweit aus/ein — wirkt auf alle gekoppelten Geräte. */
    private function HandleVisibility(int $id): void
    {
        $body = $this->ReadJsonBody();
        if (!array_key_exists('hidden', $body) || !is_bool($body['hidden'])) {
            $this->SendApiError('invalid_payload', 'Expected boolean field: hidden', 422);
            return;
        }
        $this->SetInstanceHidden($id, (bool)$body['hidden']);
        $this->SendJson(['ok' => true, 'hidden' => (bool)$body['hidden']]);
    }

    private function HandlePair(): void
    {
        $body = $this->ReadJsonBody();
        $code = strtoupper(trim((string)($body['code'] ?? '')));
        if ($code === '' || !$this->ConsumePairingCode($code)) {
            $this->SendApiError('pairing_invalid', 'Pairing code invalid or expired', 403);
            return;
        }
        $token = $this->RegisterPairedDevice([
            'deviceName' => trim((string)($body['deviceName'] ?? '')),
            'model'      => trim((string)($body['model'] ?? '')),
            'platform'   => trim((string)($body['platform'] ?? '')),
            'appVersion' => trim((string)($body['appVersion'] ?? '')),
        ]);
        if ($token === null) {
            // Device was not persisted — the code stays valid within its grace
            // window, so the app can simply retry.
            $this->SendApiError('internal', 'Device could not be stored, please retry', 500);
            return;
        }
        $this->SendJson([
            'ok'         => true,
            'token'      => $token,
            'apiVersion' => self::API_VERSION,
            'server'     => $this->BuildServerInfo(),
        ]);
    }

    private function HandleUnpair(array $device): void
    {
        $this->RemoveDevice((string)($device['id'] ?? ''));
        $this->SendJson(['ok' => true]);
    }

    private function HandleDiscovery(): void
    {
        $instances = [];
        foreach ($this->GetListInstances() as $instance) {
            $instances[] = $this->DescribeInstance((int)$instance['id'], (string)$instance['kind']);
        }
        $theme = $this->ReadReportedTheme();
        $this->SendJson([
            'ok'           => true,
            'apiVersion'   => self::API_VERSION,
            'server'       => $this->BuildServerInfo(),
            'capabilities' => ['barcode' => true, 'images' => true, 'websocket' => false],
            'users'        => json_decode($this->GetUsers(), true),
            'instances'    => $instances,
            'theme'        => $theme,
        ]);
    }

    /**
     * Visu-Farben von der ERSTEN Kachel, die sie gemeldet hat — egal welcher Typ.
     * Reihenfolge: Einkaufs-/ToDo-Listen (GetListInstances), dann die SymDo-App-
     * Kachel selbst. So genügt IRGENDEINE platzierte List-Kachel in der Visu; es
     * muss nicht mehr zwingend eine ShoppingList-Kachel geben. Kein Reporter
     * platziert → null → der Web-Adapter nutzt seine THEME_DEFAULTS.
     */
    private function ReadReportedTheme(): ?array
    {
        foreach ($this->GetListInstances() as $instance) {
            $id = (int)$instance['id'];
            $reported = null;
            if ($instance['kind'] === 'shopping' && function_exists('SL_GetVisuTheme')) {
                $reported = json_decode((string)@SL_GetVisuTheme($id), true);
            } elseif ($instance['kind'] === 'todo' && function_exists('TDL_GetVisuTheme')) {
                $reported = json_decode((string)@TDL_GetVisuTheme($id), true);
            }
            if (is_array($reported) && $reported !== []) {
                return $reported;
            }
        }
        // Zusätzlich die SymDoWebApp-Kachel(n) selbst
        if (function_exists('SDWA_GetVisuTheme')) {
            foreach (IPS_GetInstanceListByModuleID(self::SDWA_MODULE_GUID) as $id) {
                $reported = json_decode((string)@SDWA_GetVisuTheme((int)$id), true);
                if (is_array($reported) && $reported !== []) {
                    return $reported;
                }
            }
        }
        return null;
    }

    private function HandleRevisions(): void
    {
        $revisions = [];
        foreach ($this->GetListInstances() as $instance) {
            $revisions[(string)$instance['id']] = $this->GetInstanceRevision((int)$instance['id'], (string)$instance['kind']);
        }
        $this->SendJson([
            'ok'         => true,
            'revisions'  => $revisions === [] ? new \stdClass() : $revisions,
            'serverTime' => time(),
        ]);
    }

    private function HandleState(int $id, string $kind): void
    {
        $stateJson = $this->CallInstanceGetAppState($id, $kind);
        $data = json_decode((string)$stateJson, true);
        if (!is_array($data)) {
            $this->SendApiError('internal', 'Instance returned no state', 500);
            return;
        }
        $revision = (int)($data['revision'] ?? 0);
        header('ETag: "' . $revision . '"');
        if ($this->GetIfNoneMatchRevision() === $revision) {
            http_response_code(304);
            return;
        }
        $this->SendJson(['ok' => true] + $data);
    }

    private function HandleActions(int $id, string $kind): void
    {
        $body   = $this->ReadJsonBody();
        $action = trim((string)($body['action'] ?? ''));
        if ($action === '') {
            $this->SendApiError('invalid_payload', 'Missing action', 400);
            return;
        }
        $payload = $body['payload'] ?? '';
        if (!is_string($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($payload)) {
                $this->SendApiError('invalid_payload', 'Payload not encodable', 400);
                return;
            }
        }

        // Idempotency: a clientActionId already executed successfully is not
        // dispatched again (app outbox replays after lost responses) — the
        // client just gets the current state back.
        $rawActionId = $body['clientActionId'] ?? '';
        if (!is_string($rawActionId) && !is_int($rawActionId)) {
            $this->SendApiError('invalid_payload', 'clientActionId must be a string', 422);
            return;
        }
        $clientActionId = trim((string)$rawActionId);
        // Längen-Cap: der Wert landet als Array-Key im ActionDedup-Attribut. Ohne
        // Cap bläht eine Schleife mit langen IDs das Attribut auf Megabytes auf,
        // das dann bei JEDER weiteren Aktion dekodiert wird.
        if (strlen($clientActionId) > self::ACTION_ID_MAX_LEN) {
            $this->SendApiError('invalid_payload', 'clientActionId too long', 422);
            return;
        }
        $dedupKey = $clientActionId !== '' ? $id . '|' . $clientActionId : '';
        // Prüfen und reservieren atomar (siehe ReserveAction).
        if ($dedupKey !== '' && !$this->ReserveAction($dedupKey)) {
            $stateData = json_decode((string)$this->CallInstanceGetAppState($id, $kind), true);
            $data = is_array($stateData) ? $stateData : [];
            $data = ['ok' => true, 'replayed' => true] + $data;
            $data['clientActionId'] = $clientActionId;
            header('ETag: "' . (int)($data['revision'] ?? 0) . '"');
            $this->SendJson($data);
            return;
        }

        $resultJson = $this->CallInstanceAppCall($id, $kind, $action, $payload);
        $data = json_decode((string)$resultJson, true);
        if (!is_array($data)) {
            $this->SendApiError('internal', 'Instance returned no result', 500);
            return;
        }
        if (($data['ok'] ?? false) !== true && $dedupKey !== '') {
            // Fehlgeschlagen → Reservierung zurücknehmen, sonst wäre ein Retry
            // desselben clientActionId für immer als „schon erledigt" abgetan.
            $this->ReleaseAction($dedupKey);
        }
        if ($clientActionId !== '') {
            $data['clientActionId'] = $clientActionId;
        }
        header('ETag: "' . (int)($data['revision'] ?? 0) . '"');
        // Instance-level failures surface as 4xx so a single status check on the
        // client covers router and instance errors alike; the body still carries
        // revision + state for reconciliation.
        $status = 200;
        if (($data['ok'] ?? false) !== true) {
            $status = (($data['error']['code'] ?? '') === 'unknown_action') ? 400 : 422;
        }
        $this->SendJson($data, $status);
    }

    /**
     * Prüfen UND reservieren in einem Schritt unter derselben Semaphore, mit der
     * die Dedup-Tabelle geschrieben wird. Vorher war das ein Read ohne Sperre: zwei
     * parallele Retries desselben clientActionId kamen beide durch und führten die
     * Aktion doppelt aus — genau das, was der Dedup verhindern soll.
     * @return bool true = frisch reserviert (ausführen), false = schon bekannt (Replay)
     */
    private function ReserveAction(string $key): bool
    {
        $semaphoreKey = 'TGW_Dedup_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            return true; // best effort: im Zweifel ausführen (wie bisher)
        }
        try {
            $dedup = json_decode($this->ReadAttributeString('ActionDedup'), true);
            if (!is_array($dedup)) {
                $dedup = [];
            }
            if (isset($dedup[$key])) {
                return false;
            }
            $dedup[$key] = time();
            $this->WriteAttributeString('ActionDedup', json_encode($this->PruneDedup($dedup)));
            return true;
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    /** Entfernt eine Reservierung, deren Aktion fehlgeschlagen ist (Retry erlauben). */
    private function ReleaseAction(string $key): void
    {
        $semaphoreKey = 'TGW_Dedup_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            return;
        }
        try {
            $dedup = json_decode($this->ReadAttributeString('ActionDedup'), true);
            if (is_array($dedup) && isset($dedup[$key])) {
                unset($dedup[$key]);
                $this->WriteAttributeString('ActionDedup', json_encode($dedup));
            }
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    /** TTL-Ablauf + Mengen-Cap für die Dedup-Tabelle. */
    private function PruneDedup(array $dedup): array
    {
        $now   = time();
        $dedup = array_filter($dedup, static fn($ts): bool => is_int($ts) && $now - $ts < self::ACTION_DEDUP_TTL);
        if (count($dedup) > self::ACTION_DEDUP_MAX) {
            asort($dedup);
            $dedup = array_slice($dedup, count($dedup) - self::ACTION_DEDUP_MAX, null, true);
        }
        return $dedup;
    }

    private function HandleBarcode(int $id, string $kind, string $ean): void
    {
        if ($kind !== 'shopping') {
            $this->SendApiError('unknown_route', 'Barcode lookup is only available for shopping lists', 404);
            return;
        }
        $ean = trim($ean);
        if (!preg_match('/^\d{8,14}$/', $ean)) {
            $this->SendApiError('invalid_payload', 'Invalid EAN', 400);
            return;
        }
        if (!function_exists('SL_LookupBarcode')) {
            $this->SendApiError('internal', 'Shopping List module is outdated', 500);
            return;
        }
        $data = json_decode((string)SL_LookupBarcode($id, $ean), true);
        if (!is_array($data)) {
            $this->SendApiError('internal', 'Barcode lookup failed', 500);
            return;
        }
        $this->SendJson(['ok' => true] + $data);
    }

    private function HandleAsset(array $segments): void
    {
        // Path segments come from the raw REQUEST_URI and need decoding;
        // $_GET['f'] is already decoded by PHP — decoding it again would break
        // filenames containing '%' or '+'.
        $file = rawurldecode(implode('/', $segments));
        if ($file === '') {
            $file = (string)($_GET['f'] ?? '');
        }
        $base = realpath(dirname(__DIR__, 2) . '/ShoppingList/assets');
        if ($file === '' || $base === false) {
            $this->SendApiError('asset_not_found', 'Asset not found', 404);
            return;
        }
        $path = realpath($base . '/' . $file);
        if ($path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || is_dir($path)) {
            $this->SendApiError('asset_not_found', 'Asset not found', 404);
            return;
        }

        $etag = '"' . md5($path . '|' . (string)@filemtime($path) . '|' . (string)@filesize($path)) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=2592000');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }

        $mimeMap = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
        ];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
        readfile($path);
    }

    /** POST /v1/users — Benutzer anlegen (Name + optionaler Avatar als Base64-JPEG). */
    private function HandleUserCreate(): void
    {
        $body = $this->ReadJsonBody();
        $name = trim($this->BodyStr($body, 'name'));
        if ($name === '') {
            $this->SendApiError('invalid_payload', 'Expected field: name', 422);
            return;
        }
        $user = json_decode($this->CreateAppUser($name, $this->BodyStr($body, 'avatar')), true);
        if (!is_array($user)) {
            $this->SendApiError('internal', 'User could not be stored', 500);
            return;
        }
        $this->SendJson(['ok' => true, 'user' => $user]);
    }

    /** POST /v1/users/{id} — Name/Avatar eines Benutzers ändern. */
    private function HandleUserUpdate(string $userID): void
    {
        $body = $this->ReadJsonBody();
        $user = json_decode($this->UpdateAppUser(
            $userID,
            (string)($body['name'] ?? ''),
            $this->BodyStr($body, 'avatar')
        ), true);
        if (!is_array($user)) {
            $this->SendApiError('unknown_user', 'Unknown user: ' . $userID, 404);
            return;
        }
        $this->SendJson(['ok' => true, 'user' => $user]);
    }

    private function HandleUserAvatar(string $userID): void
    {
        $user = null;
        foreach ($this->LoadUsers() as $candidate) {
            if ($candidate['id'] === $userID) {
                $user = $candidate;
                break;
            }
        }
        if ($user === null || !$user['hasAvatar']) {
            $this->SendApiError('asset_not_found', 'Avatar not found', 404);
            return;
        }
        $media = IPS_GetMedia($user['mediaID']);
        // v2 salt: forces clients that cached the pre-scaling response to refetch.
        $etag  = '"' . md5($user['mediaID'] . '|' . (string)($media['MediaUpdated'] ?? 0) . '|v2') . '"';
        header('ETag: ' . $etag);
        // no-cache = always revalidate via ETag (cheap 304); avatars change rarely
        // but we must never serve a stale copy again.
        header('Cache-Control: no-cache');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        $content = base64_decode(IPS_GetMediaContent($user['mediaID']), true);
        if ($content === false || $content === '') {
            $this->SendApiError('asset_not_found', 'Avatar not readable', 404);
            return;
        }
        // Symcon caps WebHook output at 1 MB; user photos are often multiple MB,
        // so downscale to a small square thumbnail (avatars render as tiny circles).
        $thumb = $this->ScaleAvatar($content, 256);
        if ($thumb !== null) {
            header('Content-Type: image/jpeg');
            echo $thumb;
            return;
        }
        // GD unavailable: only serve the original if it fits under the limit.
        if (strlen($content) > 900000) {
            $this->SendApiError('avatar_too_large', 'Avatar exceeds the 1 MB limit and could not be scaled', 500);
            return;
        }
        $ext = strtolower(pathinfo((string)($media['MediaFile'] ?? ''), PATHINFO_EXTENSION));
        $mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif', 'webp' => 'image/webp'];
        header('Content-Type: ' . ($mimeMap[$ext] ?? 'image/jpeg'));
        echo $content;
    }

    /** Center-crops to a square and scales to $size px; returns JPEG bytes or null. */
    private function ScaleAvatar(string $binary, int $size): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = max(1, min($w, $h));
        $srcX = (int)(($w - $side) / 2);
        $srcY = (int)(($h - $side) / 2);
        $dst = imagecreatetruecolor($size, $size);
        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $size, $size, $side, $side);
        ob_start();
        imagejpeg($dst, null, 85);
        $out = (string)ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $out === '' ? null : $out;
    }

    private function CallInstanceGetAppState(int $id, string $kind): string
    {
        if ($kind === 'shopping' && function_exists('SL_GetAppState')) {
            return SL_GetAppState($id);
        }
        if ($kind === 'todo' && function_exists('TDL_GetAppState')) {
            return TDL_GetAppState($id);
        }
        return '';
    }

    private function CallInstanceAppCall(int $id, string $kind, string $action, string $payload): string
    {
        if ($kind === 'shopping' && function_exists('SL_AppCall')) {
            return SL_AppCall($id, $action, $payload);
        }
        if ($kind === 'todo' && function_exists('TDL_AppCall')) {
            return TDL_AppCall($id, $action, $payload);
        }
        return '';
    }

    private function GetIfNoneMatchRevision(): ?int
    {
        $raw = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($raw === '' && isset($_GET['rev'])) {
            $raw = (string)$_GET['rev'];
        }
        // Proxies may downgrade to weak ETags (W/"5") — accept those as well
        if (preg_match('~^W/~i', $raw)) {
            $raw = substr($raw, 2);
        }
        $raw = trim($raw, " \t\"");
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }
        return (int)$raw;
    }

    private function GetBearerToken(bool $allowQuery): string
    {
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $matches)) {
            return $matches[1];
        }
        $header = (string)($_SERVER['HTTP_X_SYMDO_TOKEN'] ?? '');
        if ($header !== '') {
            return $header;
        }
        return $allowQuery ? (string)($_GET['t'] ?? '') : '';
    }

    /**
     * Liest einen String aus einem JSON-Body. Nicht-Skalare (Array/Objekt) ergeben
     * '' statt des Literals "Array" — ein (string)-Cast auf ein Array löst sonst
     * eine PHP-Warning aus und schickt "Array" an die KI bzw. in ein Medienobjekt.
     */
    private function BodyStr(array $body, string $key): string
    {
        $value = $body[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }

    private function ReadJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function SendJson(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function SendApiError(string $code, string $message, int $status): void
    {
        $this->SendJson(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
