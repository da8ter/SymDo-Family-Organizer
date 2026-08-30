<?php

declare(strict_types=1);

trait ApiRouter
{
    /* Grenzen des Bild-Buendels (siehe HandleAssetBundle). 60 Dateien decken einen
       vollen Satz Vorschlaege ab; 600 kB bleiben mit Abstand unter der
       Ausgabegrenze eines Symcon-Skripts, die im Auslieferungszustand bei 1 MB
       liegt und bei Ueberschreitung die Antwort ERSETZT statt zu melden. */
    private const ASSET_BUNDLE_MAX_FILES = 60;
    private const ASSET_BUNDLE_MAX_BYTES = 600000;

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

        // Der Mail-Webhook bringt keinen Geraete-Token mit: Mailgun kann keine
        // eigenen Kopfzeilen setzen. Er weist sich stattdessen mit einem Geheimnis
        // im Pfad UND Mailguns Signatur aus (siehe MailHookHandle) und muss deshalb
        // hier — vor AuthenticateRequest — abzweigen.
        if ($resource === 'mail' && ($route[2] ?? '') === 'hook' && $method === 'POST') {
            $this->MailHookHandle((string)($route[3] ?? ''));
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
            || ($resource === 'ai' && $method === 'GET' && ($route[2] ?? '') === 'media')
            // GET /notes/media/{id} ist derselbe Fall: ein Notiz-PDF oeffnet der
            // System-Viewer direkt, ohne unser JavaScript.
            || ($resource === 'notes' && $method === 'GET' && ($route[2] ?? '') === 'media')
            // GET /dishimage/{id} laedt ein schlichter Bildlader (iOS-Cache,
            // <img>) — auch der kann keinen Header setzen.
            || ($resource === 'dishimage' && $method === 'GET');
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
                if ($method === 'POST' && ($route[2] ?? '') === 'transcribe') {
                    $this->HandleAiTranscribe($device);
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
            case 'mail':
                // Vorschlaege aus weitergeleiteten E-Mails. Angelegt wird die Aufgabe
                // NICHT hier — dafuer ruft die Oberflaeche wie bisher AppCall/AddItem
                // der Zielliste; hier wird nur der Vorschlag verwaltet.
                //
                // EIN Pfad mit „action" im Body statt verschachtelter Routen: die
                // Visu-Kachel kann nur POSTs auf einen Pfad relayen (AiRelayBody),
                // und so trifft dieselbe Anfrage Browser, App und Kachel.
                if (($route[2] ?? '') === 'proposals') {
                    if ($method === 'GET') {
                        $this->SendJson(['ok' => true, 'proposals' => $this->MailProposalsPublic()]);
                        return;
                    }
                    if ($method === 'POST') {
                        $this->SendJson($this->MailHandleAction($this->ReadJsonBody()));
                        return;
                    }
                }
                break;
            case 'calendar':
                // Termine aus OpenCalendar. Ein Pfad, Aktion im Rumpf — wie bei den
                // Mail-Vorschlaegen, damit Browser, App und Visu-Kachel denselben
                // Aufruf benutzen koennen.
                if ($method === 'GET') {
                    $this->SendJson($this->CalHandleAction(['action' => 'calendars']));
                    return;
                }
                if ($method === 'POST') {
                    $this->SendJson($this->CalHandleAction($this->ReadJsonBody()));
                    return;
                }
                break;
            case 'notes':
                // Notizen. Ein Pfad, Aktion im Rumpf — wie Kalender und
                // Mail-Vorschlaege, damit Browser, App und Visu-Kachel denselben
                // Aufruf benutzen. Erscheint bewusst NICHT in /discovery: eine neue
                // Listenart dort zerlegt die ausgelieferte iOS-App (ihr ListKind
                // kennt nur shopping und todo und ist nicht optional).
                if ($method === 'GET' && ($route[2] ?? '') === 'media') {
                    $this->HandleNotesMediaFile((int)($route[3] ?? 0));
                    return;
                }
                if ($method === 'GET') {
                    $this->SendJson($this->NotesHandleAction(['action' => 'list'], $device));
                    return;
                }
                if ($method === 'POST') {
                    $this->SendJson($this->NotesHandleAction($this->ReadJsonBody(), $device));
                    return;
                }
                break;
            case 'push':
                // Benachrichtigungen dieses Geraets. Der oeffentliche VAPID-Schluessel
                // kommt NICHT von hier, sondern steht in window.__SYMDO__ — sonst
                // muesste die Seite vor der Erlaubnisfrage warten und verlöre auf
                // iOS die Nutzeraktivierung.
                if ($method === 'POST' && ($route[2] ?? '') === 'subscribe') {
                    $this->HandlePushSubscribe($device);
                    return;
                }
                if ($method === 'POST' && ($route[2] ?? '') === 'unsubscribe') {
                    $this->HandlePushUnsubscribe($device);
                    return;
                }
                if ($method === 'POST' && ($route[2] ?? '') === 'test') {
                    $this->HandlePushTest($device);
                    return;
                }
                break;
            case 'briefing':
                // Das Tagesbriefing, fertig abgelegt. GET fuer die iOS-App, POST fuer
                // die Web-App — die schickt alles ueber ihren einen POST-Helfer.
                // Bewusst KEIN Zweig im Kachel-Relay (AiRelayBody): so bleibt das
                // Briefing in der Symcon-Kachel unsichtbar, ohne Sonderfall dort.
                if ($method === 'POST') {
                    $rumpf = $this->ReadJsonBody();
                    // Vorschau auf einen anderen Tag: erzeugt JETZT einen Text und legt
                    // ihn NICHT ab. Kostet je Aufruf einen Anbieter-Aufruf, deshalb nur
                    // auf ausdrueckliche Anfrage und niemals im Regelbetrieb der Apps.
                    if (array_key_exists('previewDays', $rumpf)) {
                        $tage = max(0, min(7, (int)$rumpf['previewDays']));
                        $erg  = $this->BriefingPreview($tage);
                        $this->SendJson([
                            'ok'      => (bool)$erg['ok'],
                            'preview' => [
                                'days' => $tage,
                                'text' => (string)$erg['text'],
                                'note' => (string)$erg['message'],
                                'data' => $erg['daten'] ?? null,
                            ],
                        ]);
                        return;
                    }
                    $this->SendJson($this->BriefingPublic());
                    return;
                }
                if ($method === 'GET') {
                    $this->SendJson($this->BriefingPublic());
                    return;
                }
                break;
            case 'timetable':
                // Der Stundenplan der Kinder. Wie beim Briefing: GET fuer die
                // iOS-App, POST fuer die Web-App, die alles ueber ihren einen
                // POST-Helfer schickt. Rein lesend — gepflegt wird im Backend
                // des Stundenplan-Moduls, nicht hier.
                if ($method === 'GET' || $method === 'POST') {
                    $this->SendJson($this->TimetablePublic());
                    return;
                }
                break;
            case 'tts':
                // POST /v1/tts        → Schnipsel vorbereiten (erzeugt fehlende)
                // GET  /v1/tts/{hash} → die fertige Tondatei
                if ($method === 'POST' && ($route[2] ?? '') === '') {
                    $this->HandleTtsPrepare();
                    return;
                }
                if ($method === 'GET' && ($route[2] ?? '') !== '') {
                    $this->HandleTtsAudio((string)$route[2]);
                    return;
                }
                break;
            case 'assets':
                // Die Bildzuordnung als EIGENE Auskunft. Sie steckte im Zustand der
                // Einkaufsliste und machte dort 103 von 166 kB aus — bei jeder
                // Aenderung neu, obwohl sie sich fast nie aendert.
                if ($method === 'GET' && ($route[2] ?? '') === 'index') {
                    $this->HandleAssetIndex();
                    return;
                }
                if ($method === 'GET' && ($route[2] ?? '') === 'bundle') {
                    $this->HandleAssetBundle();
                    return;
                }
                if ($method === 'GET') {
                    $this->HandleAsset(array_slice($route, 2));
                    return;
                }
                break;
            case 'dishimage':
                // GET /dishimage/{mediaId}[?s=96] — das erzeugte Gerichtsbild
                // einer Rezept-Favoritenliste als PNG.
                if ($method === 'GET') {
                    $this->HandleDishImage((int)($route[2] ?? 0), (int)($_GET['s'] ?? 0));
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
        if ($sub === 'suggestions' && $method === 'GET') {
            $this->HandleSuggestions($id, $kind);
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

    /** Blendet eine Liste familienweit aus/ein — wirkt auf alle gekoppelten Geräte. */
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
        // Gekappt an der Systemgrenze: die vier Angaben kommen unbesehen vom Client
        // und landen unverkuerzt in der Geraeteliste (Attribut). Nur der eigene
        // Adapter kuerzt sie heute; ein fremder oder fehlerhafter Client koennte das
        // Attribut mit einem ueberlangen Namen aufblaehen, bis das Schreiben der
        // ganzen Liste scheitert. Die Werte sind reine Anzeige, 80 Zeichen genuegen.
        $kurz = static fn(mixed $w): string => mb_substr(trim((string)$w), 0, 80);
        $token = $this->RegisterPairedDevice([
            'deviceName' => $kurz($body['deviceName'] ?? ''),
            'model'      => $kurz($body['model'] ?? ''),
            'platform'   => $kurz($body['platform'] ?? ''),
            'appVersion' => $kurz($body['appVersion'] ?? ''),
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
            /* Die sichtbaren Bereiche gehoeren MIT in die Auskunft. Sie standen
               bisher nur in window.__SYMDO__, also nur im Seitenaufbau — wer
               einen Bereich abschaltete, erreichte eine offene Web-App nie. Die
               Kachel bekam das ueber ihren Meta-Push, die Web-App gar nicht. */
            'tabs'         => $this->GetWebAppTabs(),
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
        $weggelassen = $this->OmittedParts();
        /* Der ETag muss die Fassung mit unterscheiden. Sonst bekaeme ein Client,
           der einmal den vollen Zustand geholt hat und danach den schmalen
           anfordert, ein 304 auf einen Rumpf, der nicht dazu passt — und
           umgekehrt fehlten ihm die Bilder. */
        header('ETag: "' . $revision . ($weggelassen !== '' ? '-' . $weggelassen : '') . '"');
        if ($this->GetIfNoneMatchRevision($weggelassen) === $revision) {
            http_response_code(304);
            return;
        }
        $this->SendJson(['ok' => true] + $this->SlimState($data));
    }

    /**
     * Will der Client den Zustand ohne die Bildzuordnung? `?images=0`.
     *
     * Ausdruecklich als Wunsch des Clients und nicht als neue Vorgabe: eine
     * ausgelieferte App, die `availableImages` im Zustand erwartet, wuerde sonst
     * eine leere Einkaufsliste zeigen. Wer fragt, bekommt es schmal.
     */
    private function WantsSlimState(): bool
    {
        return $this->OmittedParts() !== '';
    }

    /**
     * Welche Teile der Client nicht im Zustand haben will, als Kuerzel: `i` fuer
     * die Bildzuordnung (`?images=0`), `s` fuer die Vorschlaege (`?suggestions=0`).
     * Die Reihenfolge ist fest, damit derselbe Wunsch immer denselben ETag ergibt.
     */
    private function OmittedParts(): string
    {
        $teile = '';
        if (isset($_GET['images']) && (string)$_GET['images'] === '0') {
            $teile .= 'i';
        }
        if (isset($_GET['suggestions']) && (string)$_GET['suggestions'] === '0') {
            $teile .= 's';
        }
        return $teile;
    }

    /**
     * Die Bildzuordnung aus dem Zustand nehmen, wenn der Client sie nicht will.
     * Sie steht dann unter GET /assets/index — einmal, statt bei jeder Aenderung.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function SlimState(array $data): array
    {
        $weggelassen = $this->OmittedParts();
        if ($weggelassen === '' || !is_array($data['state'] ?? null)) {
            return $data;
        }
        if (str_contains($weggelassen, 'i')) {
            unset($data['state']['availableImages'], $data['state']['availableBrands']);
            // Der Client soll wissen, dass hier etwas fehlt UND woran er merkt, ob
            // seine Kopie noch stimmt.
            $data['imagesOmitted'] = true;
            $data['assetsVersion'] = $this->GetAssetsVersion();
        }
        if (str_contains($weggelassen, 's')) {
            unset($data['state']['suggestions']);
            $data['suggestionsOmitted'] = true;
        }
        return $data;
    }

    /**
     * Die Vorschlaege einer Liste als eigene Auskunft.
     *
     * Sie machen bei einer gewachsenen Einkaufsliste rund 37 kB aus und werden
     * erst beim Tippen gebraucht. Als eigene Adresse mit ETag kann der Browser sie
     * revalidieren: unveraendert kostet sie ein paar hundert Byte statt 37 kB —
     * innerhalb des Zustands ginge das nicht, weil dessen ETag an der Revision der
     * ganzen Liste haengt und sich mit jedem Haken aendert.
     */
    private function HandleSuggestions(int $id, string $kind): void
    {
        if ($kind !== 'shopping') {
            $this->SendApiError('unknown_instance', 'Not a shopping list', 404);
            return;
        }
        $data  = json_decode((string)$this->CallInstanceGetAppState($id, $kind), true);
        $state = is_array($data['state'] ?? null) ? $data['state'] : [];
        $liste = is_array($state['suggestions'] ?? null) ? $state['suggestions'] : [];
        $etag  = '"' . md5((string)json_encode($liste)) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: private, no-cache');
        if ($this->IfNoneMatchHits($etag)) {
            http_response_code(304);
            return;
        }
        $this->SendJson(['ok' => true, 'suggestions' => $liste], 200, 'private, no-cache');
    }

    /**
     * Die Bildzuordnung aller Einkaufslisten: Datei je Artikelname, dazu die
     * Markenliste. Genommen wird der Zustand der ersten Einkaufsliste — die
     * Zuordnung ist modulweit dieselbe (so hat es auch der Web-Adapter bisher aus
     * dem ersten Zustand gehoben).
     */
    private function HandleAssetIndex(): void
    {
        $bilder = [];
        $marken = [];
        foreach ($this->GetListInstances() as $instance) {
            if ((string)$instance['kind'] !== 'shopping') {
                continue;
            }
            $data = json_decode((string)$this->CallInstanceGetAppState((int)$instance['id'], 'shopping'), true);
            $state = is_array($data['state'] ?? null) ? $data['state'] : [];
            if ($bilder === [] && is_array($state['availableImages'] ?? null)) {
                $bilder = $state['availableImages'];
            }
            if ($marken === [] && is_array($state['availableBrands'] ?? null)) {
                $marken = $state['availableBrands'];
            }
            if ($bilder !== [] && $marken !== []) {
                break;
            }
        }
        $version = $this->GetAssetsVersion();
        /* Der Client haengt die Version an die Adresse (`?v=…`), deshalb darf die
           Antwort lange gelten: eine neue Version ist eine neue Adresse. Der ETag
           bleibt trotzdem dabei, damit ein Client ohne Versionsangabe wenigstens
           revalidieren kann. */
        $etag = '"' . md5((string)json_encode([$bilder, $marken])) . '"';
        header('ETag: ' . $etag);
        if ($this->IfNoneMatchHits($etag)) {
            http_response_code(304);
            return;
        }
        $this->SendJson([
            'ok'      => true,
            'version' => $version,
            'images'  => $bilder === [] ? new \stdClass() : $bilder,
            'brands'  => $marken === [] ? new \stdClass() : $marken,
        ], 200, 'private, max-age=2592000');
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
            $this->SendJson($this->SlimState($data));
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
        /* Auch die Antwort auf eine Aktion traegt den vollen Zustand — und damit
           bisher die ganze Bildzuordnung. Ein Haken auf der Einkaufsliste kostete
           so 166 kB, davon 103 kB, die sich nie aendern. */
        $this->SendJson($this->SlimState($data), $status);
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

    /** Der Medientyp einer Bilddatei — Grundlage ist die Endung, nicht der Inhalt. */
    private function AssetMimeType(string $path): string
    {
        $map = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
        ];
        return $map[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /**
     * Viele Produktbilder in EINER Antwort, als Data-URIs.
     *
     * Der Grund ist gemessen: einzeln geholt schafft der Hook rund zehn Bilder je
     * Sekunde, und mehr Parallelitaet aendert daran nichts — die Grenze liegt beim
     * Server, nicht beim Netz. Ein frischer Satz Vorschlaege mit dreissig Bildern
     * brauchte damit rund drei Sekunden. Als Buendel ist es eine Anfrage.
     *
     * Zwei Grenzen, beide noetig: die Zahl der Dateien und die Menge der Bytes. Die
     * Ausgabe eines Symcon-Skripts ist begrenzt (ScriptOutputBufferLimit, im
     * Auslieferungszustand 1 MB), und wird die Grenze ueberschritten, ERSETZT
     * Symcon die Antwort — der Client bekaeme keinen Fehler, sondern etwas
     * anderes. Was nicht mehr hineinpasst, bleibt deshalb weg; der Client sieht
     * am fehlenden Schluessel, dass er nachfragen muss.
     */
    private function HandleAssetBundle(): void
    {
        $base = realpath(dirname(__DIR__, 2) . '/ShoppingList/assets');
        $roh  = (string)($_GET['f'] ?? '');
        if ($base === false || trim($roh) === '') {
            $this->SendApiError('asset_not_found', 'Asset not found', 404);
            return;
        }
        $namen = [];
        foreach (explode(',', $roh) as $eintrag) {
            $name = trim(rawurldecode($eintrag));
            if ($name !== '' && !in_array($name, $namen, true)) {
                $namen[] = $name;
            }
        }
        $namen = array_slice($namen, 0, self::ASSET_BUNDLE_MAX_FILES);

        $bilder = [];
        $bytes  = 0;
        foreach ($namen as $name) {
            $path = realpath($base . '/' . $name);
            if ($path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || is_dir($path)) {
                continue;
            }
            $inhalt = @file_get_contents($path);
            if ($inhalt === false) {
                continue;
            }
            $b64 = base64_encode($inhalt);
            // Das erste Bild kommt immer mit: eine leere Antwort waere schlechter
            // als eine zu grosse, und ein einzelnes Bild sprengt keine Grenze.
            if ($bilder !== [] && $bytes + strlen($b64) > self::ASSET_BUNDLE_MAX_BYTES) {
                break;
            }
            $bytes += strlen($b64);
            $bilder[$name] = 'data:' . $this->AssetMimeType($path) . ';base64,' . $b64;
        }

        /* Der Client sortiert die Namen, dieselbe Menge ergibt also dieselbe
           Adresse — und die darf lange gelten: die Dateien aendern sich nur mit
           einer neuen Asset-Version, und die steht mit in der Adresse. */
        $this->SendJson([
            'ok'     => true,
            'images' => $bilder === [] ? new \stdClass() : $bilder,
        ], 200, 'private, max-age=2592000');
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
        // Symcon caps a request's total output at ScriptOutputBufferLimit (1 MiB by
        // default) and REPLACES the body with an error line once exceeded — see the
        // TtsOutputLimit() comment in Tts.php. User photos are often multiple MB,
        // so downscale to a small square thumbnail (avatars render as tiny circles).
        $thumb = $this->ScaleAvatar($content, 256);
        if ($thumb !== null) {
            header('Content-Type: image/jpeg');
            echo $thumb;
            return;
        }
        // GD unavailable: only serve the original if it fits under the limit.
        if (strlen($content) > $this->OutputLimit()) {
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
            return $this->ApplyWebAppButtonFlags(SL_GetAppState($id), $kind);
        }
        if ($kind === 'todo' && function_exists('TDL_GetAppState')) {
            return $this->ApplyWebAppButtonFlags(TDL_GetAppState($id), $kind);
        }
        return '';
    }

    private function CallInstanceAppCall(int $id, string $kind, string $action, string $payload): string
    {
        if ($kind === 'shopping' && function_exists('SL_AppCall')) {
            return $this->ApplyWebAppButtonFlags(SL_AppCall($id, $action, $payload), $kind);
        }
        if ($kind === 'todo' && function_exists('TDL_AppCall')) {
            return $this->ApplyWebAppButtonFlags(TDL_AppCall($id, $action, $payload), $kind);
        }
        return '';
    }

    /**
     * Praegt die appweiten Bedienelemente der Web-App auf einen Listen-Zustand auf.
     *
     * Beide Trichter laufen hierdurch — GetAppState UND AppCall. Ohne den zweiten
     * fielen die Flags nach jeder Aktion auf die Werte der Liste zurueck, weil eine
     * Aktionsantwort den neuen Zustand mitliefert.
     *
     * Gesetzt wird nur, was die jeweilige Ansicht liest (wie in SymDoWebApp), und
     * nur, wenn eine Web-App-Instanz existiert. Ohne sie bleibt alles unberuehrt —
     * dann bedient nur die SymDo-App die API, und die liest diese Flags nicht.
     */
    private function ApplyWebAppButtonFlags(string $json, string $kind): string
    {
        $flags = $this->ResolveWebAppButtonFlags();
        if ($flags === null || $json === '') {
            return $json;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['state'] ?? null)) {
            return $json;
        }
        $relevant = $kind === 'shopping'
            ? ['showFavoriteHeart', 'showEditButton', 'showDeleteButton']
            : ['showOverview', 'showMemberBar', 'showCreateButton', 'showSorting',
               'showQuantityBadge', 'showRecurrenceBadge', 'showDueBadge',
               'showNotificationBadge', 'showPriorityBadge',
               'showEditButton', 'showDeleteButton', 'showReorderHandle'];
        foreach ($relevant as $name) {
            $data['state'][$name] = $flags[$name];
        }
        $neu = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($neu) ? $neu : $json;
    }

    private function GetIfNoneMatchRevision(string $weggelassen = ''): ?int
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
        // „5-is" ist Revision 5 ohne Bildzuordnung und ohne Vorschlaege. Ein
        // Vergleich ueber die Zahl allein wuerde alle Fassungen gleichsetzen.
        $teile = explode('-', $raw, 2);
        $raw   = $teile[0];
        if (($teile[1] ?? '') !== $weggelassen) {
            return null;
        }
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

    /**
     * @param string $cacheControl Vorgabe `no-store`: Zustaende und Auskuenfte
     *   aendern sich staendig. Wer laenger gelten will, sagt es HIER — bis hierher
     *   setzte diese Methode ihr `no-store` bedingungslos, und weil PHPs header()
     *   ersetzt statt zu ergaenzen, ueberschrieb sie stillschweigend jede Vorgabe,
     *   die ein Handler vorher gesetzt hatte. Die dreissig Tage der Bildzuordnung
     *   und die Revalidierung der Vorschlaege gab es deshalb nie: beide gingen als
     *   `no-store` hinaus und wurden bei JEDEM Start voll uebertragen.
     */
    private function SendJson(array $payload, int $status = 200, string $cacheControl = 'no-store'): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: ' . $cacheControl);
        /* JSON_INVALID_UTF8_SUBSTITUTE: ein einziges kaputtes Byte irgendwo in der
           Antwort — ein Produktname aus einer fremden Datenbank, ein Termin aus
           einem alten CalDAV-Server — liesse json_encode sonst `false` zurueckgeben.
           Der Client bekaeme einen LEEREN Rumpf und zeigte eine leere Liste, ohne
           dass irgendwo ein Fehler stuende. Lieber ein Ersatzzeichen an einer
           Stelle als eine Antwort, die es gar nicht gibt. */
        echo (string)json_encode($payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function SendApiError(string $code, string $message, int $status): void
    {
        $this->SendJson(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
    // ───────────────────────── Benachrichtigungen ─────────────────────────

    /**
     * POST /v1/push/subscribe — das Abo dieses Geraets ablegen.
     *
     * Zugeordnet wird ueber den Token, nicht ueber eine Kennung im Rumpf: Das
     * Geraet kennt seine eigene ID nicht, und es soll auch kein fremdes Abo
     * ueberschreiben koennen.
     */
    private function HandlePushSubscribe(array $device): void
    {
        $rumpf = $this->ReadJsonBody();
        $endpunkt = $this->BodyStr($rumpf, 'endpoint');
        $keys = is_array($rumpf['keys'] ?? null) ? $rumpf['keys'] : [];
        $p256dh = $this->BodyStr($keys, 'p256dh');
        $auth   = $this->BodyStr($keys, 'auth');
        if (!str_starts_with($endpunkt, 'https://') || $p256dh === '' || $auth === '') {
            $this->SendApiError('invalid_payload', 'Subscription incomplete', 422);
            return;
        }
        // Das Mitglied darf gesetzt werden, muss aber existieren — eine Nachricht
        // „nur fuer Papa" an eine erfundene Kennung ginge sonst an niemanden.
        $userId = $this->BodyStr($rumpf, 'userId');
        $bekannt = array_column($this->LoadUsers(), 'id');
        if ($userId !== '' && !in_array($userId, $bekannt, true)) {
            $this->SendApiError('invalid_payload', 'Unknown user', 422);
            return;
        }
        $ok = $this->PushStoreSubscription((string)($device['id'] ?? ''), $endpunkt, $p256dh, $auth, $userId);
        if (!$ok) {
            // Stiller Fehlschlag waere hier besonders boese: Die Oberflaeche haette
            // die Glocke eingeschaltet und nie wieder eine Nachricht bekommen.
            $this->SendApiError('internal', 'Subscription could not be stored', 500);
            return;
        }
        $this->RefreshDeviceListFormField();
        $this->SendJson(['ok' => true, 'userId' => $userId]);
    }

    /** POST /v1/push/unsubscribe — Abo dieses Geraets loeschen. */
    private function HandlePushUnsubscribe(array $device): void
    {
        $this->PushDropSubscription((string)($device['id'] ?? ''));
        $this->RefreshDeviceListFormField();
        $this->SendJson(['ok' => true]);
    }

    /**
     * POST /v1/push/test — eine Probenachricht, nur an das aufrufende Geraet.
     *
     * Fester Text mit Absicht: Ein Freitext aus dem Rumpf waere ein Kanal, mit dem
     * ein gekoppeltes Geraet beliebige Meldungen auf die Sperrbildschirme der
     * ganzen Familie schreiben koennte.
     */
    private function HandlePushTest(array $device): void
    {
        $abo = $this->PushSubscriptionOf((string)($device['id'] ?? ''));
        if ($abo === null) {
            $this->SendApiError('not_found', 'No subscription for this device', 404);
            return;
        }
        if (!$this->DeviceRateAllows((string)($device['id'] ?? ''), 'push', 30, 3600)) {
            $this->SendApiError('rate_limited', 'Too many notifications', 429);
            return;
        }
        $erg = $this->PushSendOne(
            $abo,
            ['title' => $this->Translate('Notifications are on'), 'body' => $this->Translate('This is a test notification.'), 'tab' => 'dashboard'],
            $this->PushContact()
        );
        if ($erg['gone']) {
            $this->PushDropSubscription((string)($device['id'] ?? ''));
        }
        $this->SendJson([
            'ok'     => (bool)$erg['ok'],
            'status' => (int)$erg['status'],
            'error'  => (string)$erg['error'],
        ], $erg['ok'] ? 200 : 502);
    }

}
