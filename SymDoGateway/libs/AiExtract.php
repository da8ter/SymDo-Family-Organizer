<?php

declare(strict_types=1);

/**
 * KI-Extraktion für die Web-App. Zwei Einsatzzwecke, gemeinsame Provider-Logik:
 *
 *  1. „Foto → Aufgaben" (ToDo): ein hochgeladenes Foto geht direkt an ein
 *     Vision-LLM (Anthropic, OpenAI oder lokaler OpenAI-kompatibler Server),
 *     das daraus ToDo-Aufgaben ableitet.
 *  2. „Foto/URL → Zutaten" (Einkaufsliste): entweder ein Foto (Rezeptseite,
 *     handschriftliche Liste, Aushang, Verpackung) an ein Vision-LLM, oder eine
 *     Rezept-URL — das Gateway holt die Seite serverseitig (SSRF-geschützt),
 *     macht daraus Text und lässt das LLM die Zutatenliste extrahieren.
 *
 * Der API-Key bleibt in beiden Fällen serverseitig in der Gateway-Config. Es wird
 * nichts automatisch angelegt — die Vorschläge gehen zur Bestätigung an die
 * Web-App (Review-Overlay).
 */
trait AiExtract
{
    // Vision-fähige Default-Modelle der Cloud-Anbieter (wie die iOS-App).
    private const AI_ANTHROPIC_MODEL = 'claude-sonnet-4-5';
    private const AI_OPENAI_MODEL    = 'gpt-4o';
    // OpenAI-Modell für PDF-Dateien (nimmt PDF-file-Input an; gpt-4o kann kein PDF).
    private const AI_OPENAI_PDF_MODEL = 'gpt-5.6-terra';
    private const AI_MAX_TOKENS      = 2000;
    // Größeres Budget für PDF: bei Reasoning-Modellen zählt das versteckte
    // Reasoning mit, ein zu kleiner Cap liefert sonst eine leere Antwort.
    private const AI_MAX_TOKENS_PDF  = 32000;
    private const AI_TIMEOUT         = 45;
    /**
     * Der eigene Rechner darf laenger brauchen als eine Cloud: er rechnet mit dem,
     * was da ist, und ein Reasoning-Modell denkt vor der Antwort sichtbar lange
     * (gemessen: 23 s fuer einen einseitigen Elternbrief). 45 s waeren hier ein
     * Abbruch mitten in der Arbeit.
     */
    private const AI_LOCAL_TIMEOUT   = 300;
    private const AI_CONNECT_TIMEOUT = 5;
    /**
     * PDF fuer einen lokalen Server aufbereiten (der nimmt keine PDF-Dateien an).
     * Erst der Textweg — schnell, genau und billig; er traegt jedes digital
     * erzeugte PDF. Bleibt zu wenig Text uebrig, ist es ein Scan, und die Seiten
     * gehen als Bilder an ein Vision-Modell.
     */
    private const AI_PDF_MIN_TEXT     = 200;
    /** So viele Seiten gehen als Bild mit — ein Halbjahresplaner sprengt sonst jede Zeit. */
    private const AI_PDF_PAGES_MAX    = 3;
    /** Kantenlaenge der Seitenbilder: mehr kostet Tokens und Zeit, ohne mehr zu zeigen. */
    private const AI_PDF_IMAGE_WIDTH  = 1400;
    // Missbrauchsschutz: pro Gerät N KI-Aufrufe je Zeitfenster; zusätzlich darf
    // immer nur EIN Anbieter-Aufruf gleichzeitig laufen (sonst blockieren
    // parallele Requests den Symcon-Webserver).
    private const AI_RATE_MAX        = 60;
    private const AI_RATE_WINDOW     = 3600;
    // Rezept-URL-Analyse
    private const AI_MAX_INGREDIENTS  = 100;
    private const AI_HTTP_GET_TIMEOUT = 15;
    private const AI_RECIPE_TEXT_MAX  = 12000;
    // PDF-Upload: base64-Größenlimit (Datei ~3/4 davon).
    private const AI_MAX_PDF_B64      = 20 * 1024 * 1024;
    private const AI_MAX_IMAGE_B64    = 12 * 1024 * 1024;
    // Obergrenze für gespeicherte Rezeptfotos/-dateien unter „Rezeptfotos".
    private const AI_MEDIA_MAX        = 200;

    // ────────────────────────────── ToDo (Foto → Aufgaben) ──────────────────────────────

    private function HandleAiExtract(array $device): void
    {
        if (!$this->AiIsEnabled() || !$this->AiRateLimitOk($device)) {
            return;
        }
        $body = $this->ReadJsonBody();
        $pdf  = $this->AiStripImage($this->BodyStr($body, 'pdf'));
        if ($pdf !== '') {
            if (strlen($pdf) > self::AI_MAX_PDF_B64) {
                $this->SendApiError('invalid_payload', $this->Translate('File too large.'), 413);
                return;
            }
            $result = $this->AiExtractTodos('', $pdf);
        } else {
            $image = $this->AiReadImage($body);
            if ($image === null) {
                return; // Fehler wurde bereits gesendet
            }
            $result = $this->AiExtractTodos($image);
        }
        if (($result['ok'] ?? false) !== true) {
            $this->SendAiErrorResult($result);
            return;
        }
        $this->SendJson(['ok' => true, 'todos' => $result['todos']]);
    }

    /** @return array ok:true+todos | ok:false+code+message+status */
    private function AiExtractTodos(string $imageBase64 = '', string $pdfBase64 = ''): array
    {
        $r = $this->AiRunCompletion(
            $this->AiSystemPrompt(date('Y-m-d')),
            $pdfBase64 !== '' ? 'Extrahiere die Aufgaben aus dieser Datei.' : 'Extrahiere die Aufgaben aus diesem Dokument.',
            $imageBase64 !== '' ? $imageBase64 : null,
            $pdfBase64 !== '' ? $pdfBase64 : null
        );
        if (($r['ok'] ?? false) !== true) {
            return $r;
        }
        return ['ok' => true, 'todos' => $this->AiParseTodos((string)$r['text'])];
    }

    // ─────────────────────── Einkaufsliste (Foto/URL → Zutaten) ───────────────────────

    private function HandleAiIngredients(array $device): void
    {
        if (!$this->AiIsEnabled() || !$this->AiRateLimitOk($device)) {
            return;
        }
        $body = $this->ReadJsonBody();
        $url  = trim($this->BodyStr($body, 'url'));
        $pdf  = $this->AiStripImage($this->BodyStr($body, 'pdf'));

        // Kategorien, aus denen das Modell waehlen darf — siehe AiAllowedCategories().
        $kategorien = $this->AiAllowedCategories($body);

        if ($pdf !== '') {
            if (strlen($pdf) > self::AI_MAX_PDF_B64) {
                $this->SendApiError('invalid_payload', $this->Translate('File too large.'), 413);
                return;
            }
            $result = $this->AiExtractIngredientsFromPdf($pdf, $kategorien);
        } elseif (($body['image'] ?? '') !== '') {
            $image = $this->AiReadImage($body);
            if ($image === null) {
                return;
            }
            $result = $this->AiExtractIngredientsFromImage($image, $kategorien);
        } elseif ($url !== '') {
            $result = $this->AiExtractIngredientsFromUrl($url, $kategorien);
        } else {
            $this->SendApiError('invalid_payload', $this->Translate('No image or URL provided.'), 422);
            return;
        }

        if (($result['ok'] ?? false) !== true) {
            $this->SendAiErrorResult($result);
            return;
        }
        $this->SendJson([
            'ok'       => true,
            'title'    => $result['title'] ?? null,
            'servings' => $result['servings'] ?? null,
            'items'    => $result['items'],
        ]);
    }

    /** REST: Rezeptfoto als Medienobjekt speichern → {ok, mediaId}. */
    private function HandleAiSavePhoto(array $device): void
    {
        if (!$this->AiRateLimitOk($device)) {
            return;
        }
        $r = json_decode($this->AiRelayBody('/savephoto', json_encode($this->ReadJsonBody())), true);
        $this->SendJson(is_array($r) ? $r : ['ok' => false, 'error' => ['code' => 'ai_error', 'message' => 'error']]);
    }

    /** REST: Rezeptfoto als data:-URL liefern → {ok, dataUrl}. */
    private function HandleAiGetMedia(array $device): void
    {
        $r = json_decode($this->AiRelayBody('/media', json_encode($this->ReadJsonBody())), true);
        $this->SendJson(is_array($r) ? $r : ['ok' => false, 'error' => ['code' => 'ai_error', 'message' => 'error']]);
    }

    /**
     * Relay für die Visu-Kachel: dieselbe KI-Extraktion wie der REST-Endpoint,
     * aber als Rückgabewert statt HTTP-Antwort (die Kachel hat keinen Token). Wird
     * von der RequestAction('AiTileRequest') des Gateways aufgerufen. $path ist der
     * REST-Pfad ('…/ingredients' oder '…/extract'); $payloadJson enthält {image}
     * bzw. {url}. Rückgabe: JSON-Body wie ihn die Web-App erwartet
     * ({ok:true,…} oder {ok:false,error:{code,message}}).
     */
    private function AiRelayBody(string $path, string $payloadJson): string
    {
        $body = json_decode($payloadJson, true);
        if (!is_array($body)) {
            $body = [];
        }
        $image = $this->AiStripImage($this->BodyStr($body, 'image'));
        if ($image !== '' && strlen($image) > self::AI_MAX_IMAGE_B64) {
            return $this->AiRelayError('invalid_payload', $this->Translate('Image too large.'));
        }
        $pdf = $this->AiStripImage($this->BodyStr($body, 'pdf'));
        if ($pdf !== '' && strlen($pdf) > self::AI_MAX_PDF_B64) {
            return $this->AiRelayError('invalid_payload', $this->Translate('File too large.'));
        }

        // Medien-Operationen laufen unabhängig vom KI-Schalter (auch zum Öffnen
        // bereits gespeicherter Rezeptfotos/-dateien).
        if (str_ends_with($path, 'savephoto')) {
            $result = ($pdf !== '')
                ? $this->AiSaveMedia($pdf, $this->BodyStr($body, 'name'), true)
                : $this->AiSaveMedia($image, $this->BodyStr($body, 'name'), false);
            return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (str_ends_with($path, 'media')) {
            return json_encode(
                $this->AiGetMedia((int)($body['mediaId'] ?? 0), ($body['meta'] ?? false) === true),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        // Mail-Vorschlaege verwalten: bewusst VOR dem KI-Schalter. Vorhandene
        // Vorschlaege ansehen, uebernehmen und verwerfen muss auch dann gehen, wenn
        // die Analyse inzwischen abgeschaltet wurde.
        if (str_ends_with($path, 'mail/proposals')) {
            return json_encode($this->MailHandleAction($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Kalender lesen: hat mit der KI nichts zu tun, reist aber ueber denselben
        // Relay-Pfad, weil die Visu-Kachel nur diesen einen Weg nach draussen hat.
        if (str_ends_with($path, 'calendar')) {
            return json_encode($this->CalHandleAction($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (!$this->ReadPropertyBoolean('AiEnabled')) {
            return $this->AiRelayError('ai_disabled', $this->Translate('AI analysis is disabled.'));
        }

        if (str_ends_with($path, 'ingredients')) {
            $url        = trim($this->BodyStr($body, 'url'));
            $kategorien = $this->AiAllowedCategories($body);
            if ($pdf !== '') {
                $r = $this->AiExtractIngredientsFromPdf($pdf, $kategorien);
            } elseif ($image !== '') {
                $r = $this->AiExtractIngredientsFromImage($image, $kategorien);
            } elseif ($url !== '') {
                $r = $this->AiExtractIngredientsFromUrl($url, $kategorien);
            } else {
                return $this->AiRelayError('invalid_payload', $this->Translate('No image or URL provided.'));
            }
            if (($r['ok'] ?? false) !== true) {
                return $this->AiRelayError((string)($r['code'] ?? 'ai_error'), (string)($r['message'] ?? 'AI error'));
            }
            return json_encode(['ok' => true, 'title' => $r['title'] ?? null, 'servings' => $r['servings'] ?? null, 'items' => $r['items']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (str_ends_with($path, 'extract')) {
            if ($pdf !== '') {
                $r = $this->AiExtractTodos('', $pdf);
            } elseif ($image !== '') {
                $r = $this->AiExtractTodos($image);
            } else {
                return $this->AiRelayError('invalid_payload', $this->Translate('No image provided.'));
            }
            if (($r['ok'] ?? false) !== true) {
                return $this->AiRelayError((string)($r['code'] ?? 'ai_error'), (string)($r['message'] ?? 'AI error'));
            }
            return json_encode(['ok' => true, 'todos' => $r['todos']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $this->AiRelayError('unknown_route', 'Unknown AI route');
    }

    private function AiRelayError(string $code, string $message): string
    {
        return json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function AiStripImage(string $image): string
    {
        $comma = strpos($image, 'base64,');
        if ($comma !== false) {
            $image = substr($image, $comma + 7);
        }
        return trim($image);
    }

    // ────────────────────────────── Rezeptfotos (Medienobjekte) ──────────────────────────────

    /** Kategorie „Rezeptfotos" unterhalb der SymDoWebApp-Instanz (einmal anlegen, dann gecached). */
    private function AiRecipePhotoCategory(): int
    {
        $catId = (int)$this->ReadAttributeString('RecipePhotoCategory');
        if ($catId > 0 && IPS_CategoryExists($catId)) {
            return $catId;
        }
        $sdwa   = IPS_GetInstanceListByModuleID(self::SDWA_MODULE_GUID);
        $parent = (is_array($sdwa) && count($sdwa) > 0) ? (int)$sdwa[0] : 0;
        if ($parent <= 0) {
            return 0;
        }
        foreach (IPS_GetChildrenIDs($parent) as $child) {
            if (IPS_CategoryExists($child) && IPS_GetName($child) === 'Rezeptfotos') {
                $this->WriteAttributeString('RecipePhotoCategory', (string)$child);
                return $child;
            }
        }
        $catId = IPS_CreateCategory();
        IPS_SetParent($catId, $parent);
        IPS_SetName($catId, 'Rezeptfotos');
        $this->WriteAttributeString('RecipePhotoCategory', (string)$catId);
        return $catId;
    }

    /** Speichert Foto (JPEG) ODER PDF als Medienobjekt unter „Rezeptfotos". @return array ok+mediaId | ok:false+error */
    private function AiSaveMedia(string $base64, string $name, bool $isPdf): array
    {
        if ($base64 === '') {
            return ['ok' => false, 'error' => ['code' => 'invalid_payload', 'message' => $this->Translate($isPdf ? 'No file provided.' : 'No image provided.')]];
        }
        // Inhalt prüfen, bevor irgendetwas angelegt wird: nur echte JPEG/PDF-Daten
        // dürfen in den Objektbaum. base64_decode(strict) fängt Müll-Payloads ab.
        $raw = base64_decode($base64, true);
        if ($raw === false || $raw === '') {
            return ['ok' => false, 'error' => ['code' => 'invalid_payload', 'message' => $this->Translate($isPdf ? 'No file provided.' : 'No image provided.')]];
        }
        $magicOk = $isPdf ? str_starts_with($raw, '%PDF-') : str_starts_with($raw, "\xFF\xD8\xFF");
        if (!$magicOk) {
            return ['ok' => false, 'error' => ['code' => 'invalid_payload', 'message' => $this->Translate('Unsupported file type.')]];
        }
        $cat = $this->AiRecipePhotoCategory();
        if ($cat <= 0) {
            return ['ok' => false, 'error' => ['code' => 'no_category', 'message' => 'Rezeptfotos category unavailable']];
        }
        // Mengen-Quota: verhindert, dass eine Schleife Platte und Objektbaum füllt.
        if (count(IPS_GetChildrenIDs($cat)) >= self::AI_MEDIA_MAX) {
            return ['ok' => false, 'error' => ['code' => 'quota_exceeded', 'message' => $this->Translate('Too many stored recipe files — please delete some first.')]];
        }
        $mid = IPS_CreateMedia($isPdf ? MEDIATYPE_DOCUMENT : MEDIATYPE_IMAGE);
        IPS_SetParent($mid, $cat);
        $name = trim($name);
        IPS_SetName($mid, $name !== '' ? $name : $this->Translate($isPdf ? 'Recipe file' : 'Recipe photo'));
        // Ein Medienobjekt braucht erst eine Datei, bevor Content gesetzt werden kann.
        IPS_SetMediaFile($mid, 'media/recipe_' . $mid . ($isPdf ? '.pdf' : '.jpg'), false);
        IPS_SetMediaContent($mid, $base64);
        return ['ok' => true, 'mediaId' => $mid];
    }

    /**
     * Prüft ein Rezept-Medienobjekt und liefert Typ (+ optional Base64-Inhalt).
     * Gemeinsame Basis für die JSON-Antwort (data:-URL) und die Rohdatei-Route.
     * Ohne $withContent bleibt der Plattenzugriff aus — für reine Typabfragen.
     */
    private function AiReadMedia(int $mediaId, bool $withContent = true): array
    {
        if ($mediaId <= 0 || !IPS_MediaExists($mediaId)) {
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'Media not found']];
        }
        $cat = (int)$this->ReadAttributeString('RecipePhotoCategory');
        if ($cat <= 0 || IPS_GetParent($mediaId) !== $cat) {
            return ['ok' => false, 'error' => ['code' => 'forbidden', 'message' => 'Not a recipe photo']];
        }
        $media   = @IPS_GetMedia($mediaId);
        $content = '';
        if ($withContent) {
            $content = @IPS_GetMediaContent($mediaId);
            if (!is_string($content) || $content === '') {
                return ['ok' => false, 'error' => ['code' => 'empty', 'message' => 'Empty media']];
            }
        }
        return [
            'ok'      => true,
            'base64'  => $content,
            'isPdf'   => is_array($media) && ((int)($media['MediaType'] ?? MEDIATYPE_IMAGE) === MEDIATYPE_DOCUMENT),
            'updated' => is_array($media) ? (int)($media['MediaUpdated'] ?? 0) : 0,
        ];
    }

    /**
     * Liefert ein Rezept-Medienobjekt als data:-URL (Bild oder PDF) — nur Objekte
     * unter „Rezeptfotos". Mit $metaOnly nur den Typ, damit die App vor dem Klick
     * weiß, ob sie den System-PDF-Viewer öffnen muss (ohne 1 MB zu übertragen).
     */
    private function AiGetMedia(int $mediaId, bool $metaOnly = false): array
    {
        $m = $this->AiReadMedia($mediaId, !$metaOnly);
        if (($m['ok'] ?? false) !== true) {
            return $m;
        }
        if ($metaOnly) {
            return ['ok' => true, 'isPdf' => $m['isPdf']];
        }
        return [
            'ok'      => true,
            'isPdf'   => $m['isPdf'],
            'dataUrl' => 'data:' . ($m['isPdf'] ? 'application/pdf' : 'image/jpeg') . ';base64,' . $m['base64'],
        ];
    }

    /**
     * GET /v1/ai/media/{id} — Rezeptdatei als Rohdatei mit korrektem Content-Type.
     * Nötig für mehrseitige PDFs: WebKit rendert ein PDF in einem <iframe> nur als
     * erste, nicht scrollbare Seite. Über diese URL übernimmt der System-Viewer
     * (Safari/Quick Look) und blättert alle Seiten inkl. Zoom, Teilen und Drucken.
     */
    private function HandleAiMediaFile(int $mediaId): void
    {
        $m = $this->AiReadMedia($mediaId);
        if (($m['ok'] ?? false) !== true) {
            $err = is_array($m['error'] ?? null) ? $m['error'] : ['code' => 'not_found', 'message' => 'Media not found'];
            $this->SendApiError((string)$err['code'], (string)$err['message'], $err['code'] === 'forbidden' ? 403 : 404);
            return;
        }
        $raw = base64_decode((string)$m['base64'], true);
        if (!is_string($raw) || $raw === '') {
            $this->SendApiError('empty', 'Media not readable', 404);
            return;
        }
        $etag = '"' . md5($mediaId . '|' . $m['updated'] . '|' . strlen($raw)) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: private, no-cache');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Type: ' . ($m['isPdf'] ? 'application/pdf' : 'image/jpeg'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; ' . $this->AiFileNameParams((string)@IPS_GetName($mediaId), (bool)$m['isPdf']));
        echo $raw;
    }

    /**
     * Dateiname-Parameter für Content-Disposition. Der Objektname ist frei wählbar,
     * darf also niemals ungefiltert in einen Header — die Whitelist entfernt
     * insbesondere CR/LF und Anführungszeichen (Header-Injection).
     */
    private function AiFileNameParams(string $name, bool $isPdf): string
    {
        $ext   = $isPdf ? '.pdf' : '.jpg';
        $clean = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[^\p{L}\p{N} ._-]+/u', ' ', $name)));
        if ($clean === '') {
            $clean = 'Rezept';
        }
        $clean = mb_substr($clean, 0, 80);
        $ascii = trim((string)preg_replace('/_+/', '_', (string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $clean)), '_');
        if ($ascii === '') {
            $ascii = 'recipe';
        }
        return 'filename="' . $ascii . $ext . '"; filename*=UTF-8\'\'' . rawurlencode($clean . $ext);
    }

    /** @return array ok:true+title+servings+items | ok:false+code+message+status */
    private function AiExtractIngredientsFromImage(string $imageBase64, array $erlaubteKategorien = []): array
    {
        $r = $this->AiRunCompletion(
            $this->AiIngredientsSystemPrompt($erlaubteKategorien),
            'Extrahiere die Artikel bzw. Zutaten aus diesem Bild.',
            $imageBase64
        );
        if (($r['ok'] ?? false) !== true) {
            return $r;
        }
        return ['ok' => true] + $this->AiParseRecipe((string)$r['text']);
    }

    /** @return array ok:true+title+servings+items | ok:false+code+message+status */
    private function AiExtractIngredientsFromUrl(string $url, array $erlaubteKategorien = []): array
    {
        $page = $this->AiFetchPublicPage($url);
        if (($page['ok'] ?? false) !== true) {
            return $page;
        }
        $text = $this->AiRecipeText((string)$page['body']);
        if ($text === '') {
            return ['ok' => false, 'code' => 'ai_url_empty', 'message' => $this->Translate('Could not load the page.'), 'status' => 502];
        }
        $r = $this->AiRunCompletion(
            $this->AiRecipeSystemPrompt($erlaubteKategorien),
            "Extrahiere Titel, Portionen und die Zutatenliste aus diesem Rezept:\n\n" . $text,
            null
        );
        if (($r['ok'] ?? false) !== true) {
            return $r;
        }
        return ['ok' => true] + $this->AiParseRecipe((string)$r['text']);
    }

    /** PDF-Datei → Zutaten (Anthropic nativ, OpenAI via PDF-Modell). @return array */
    private function AiExtractIngredientsFromPdf(string $pdfBase64, array $erlaubteKategorien = []): array
    {
        $r = $this->AiRunCompletion(
            $this->AiIngredientsSystemPrompt($erlaubteKategorien),
            'Extrahiere die Artikel bzw. Zutaten aus dieser Datei.',
            null,
            $pdfBase64
        );
        if (($r['ok'] ?? false) !== true) {
            return $r;
        }
        return ['ok' => true] + $this->AiParseRecipe((string)$r['text']);
    }

    // ────────────────────────────── Gemeinsame Provider-Logik ──────────────────────────────

    /**
     * Ein Chat-/Vision-Aufruf an den konfigurierten Anbieter. Ohne $imageBase64
     * wird eine reine Text-Anfrage gestellt (Rezept-Text), sonst ein Vision-Block
     * vorangestellt (Foto).
     * @return array ok:true+text | ok:false+code+message+status
     */
    private function AiRunCompletion(string $system, string $userText, ?string $imageBase64, ?string $pdfBase64 = null): array
    {
        // Nur EIN Anbieter-Aufruf gleichzeitig. Ein Aufruf belegt bis zu
        // AI_TIMEOUT Sekunden einen Webhook-Worker bzw. einen Kernel-Thread;
        // parallele Aufrufe würden den gesamten Symcon-Webserver blockieren.
        $lock = 'TGW_Ai_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            return ['ok' => false, 'code' => 'ai_busy', 'message' => $this->Translate('Another AI request is already running.'), 'status' => 429];
        }
        try {
            return $this->AiRunProviderCall($system, $userText, $imageBase64, $pdfBase64);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /** @return array ok:true+text | ok:false+code+message+status */
    private function AiRunProviderCall(string $system, string $userText, ?string $imageBase64, ?string $pdfBase64 = null): array
    {
        $provider = $this->ReadPropertyString('AiProvider');
        // Ein lokales Reasoning-Modell verbraucht sein Budget zuerst im Denken
        // (gemessen: 794 von 990 Tokens gingen in den Denktext) — mit dem kleinen
        // Cap kaeme regelmaessig eine leere Antwort mit finish_reason „length".
        $maxTokens = ($pdfBase64 !== null || $provider === 'local') ? self::AI_MAX_TOKENS_PDF : self::AI_MAX_TOKENS;

        if ($provider === 'anthropic') {
            $key = trim($this->ReadPropertyString('AiAnthropicKey'));
            if ($key === '') {
                return ['ok' => false, 'code' => 'ai_not_configured', 'message' => $this->Translate('No Anthropic API key configured.'), 'status' => 400];
            }
            if ($pdfBase64 !== null) {
                $content = [
                    ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdfBase64]],
                    ['type' => 'text', 'text' => $userText],
                ];
            } elseif ($imageBase64 !== null) {
                $content = [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $this->AiImageMime($imageBase64), 'data' => $imageBase64]],
                    ['type' => 'text', 'text' => $userText],
                ];
            } else {
                $content = $userText;
            }
            $bodyArr = [
                'model'      => self::AI_ANTHROPIC_MODEL,
                'max_tokens' => $maxTokens,
                'system'     => $system,
                'messages'   => [['role' => 'user', 'content' => $content]],
            ];
            $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
            // JSON_INVALID_UTF8_SUBSTITUTE: ein einzelnes kaputtes Byte im Text
            // (byteweise gekuerzte oder falsch deklarierte Mail) darf den Aufruf
            // nicht in `false` und damit einen TypeError kippen.
            $resp = $this->AiHttpPost('https://api.anthropic.com/v1/messages', $headers, json_encode($bodyArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            return $this->AiFinishText($resp, static function (array $data): string {
                $text = '';
                foreach (($data['content'] ?? []) as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $text .= (string)($block['text'] ?? '');
                    }
                }
                return $text;
            });
        }

        if ($provider === 'openai' || $provider === 'local') {
            // Ein lokaler Server nimmt keine PDF-Datei an, also wird sie hier
            // aufbereitet: erst der Textweg (schnell, genau, traegt jedes digital
            // erzeugte PDF), und nur wenn zu wenig Text herauskommt — also bei
            // einem Scan — gehen die ersten Seiten als Bilder mit. Dafuer braucht
            // es ein Modell mit Bildverstaendnis; hat es keines, kommt eine leere
            // oder fantasierte Antwort zurueck, weshalb der Textweg Vorrang hat.
            $seitenBilder = [];
            if ($provider === 'local' && $pdfBase64 !== null) {
                $pdfText = $this->AiPdfToText($pdfBase64);
                if (strlen($pdfText) >= self::AI_PDF_MIN_TEXT) {
                    $this->SendDebug('AI', sprintf('PDF als Text uebergeben (%d Zeichen)', strlen($pdfText)), 0);
                    $userText .= "\n\n--- Inhalt der beigefuegten PDF-Datei ---\n" . $pdfText;
                } else {
                    $seitenBilder = $this->AiPdfToImages($pdfBase64);
                    if ($seitenBilder === []) {
                        return ['ok' => false, 'code' => 'ai_pdf_unsupported', 'message' => $this->Translate('PDF is not supported by this AI provider.'), 'status' => 400];
                    }
                    $this->SendDebug('AI', sprintf('PDF als %d Seitenbild(er) uebergeben (kein Text im PDF)', count($seitenBilder)), 0);
                }
                $pdfBase64 = null;   // ab hier ist es Text bzw. sind es Bilder
            }
            if ($provider === 'openai') {
                $key   = trim($this->ReadPropertyString('AiOpenAIKey'));
                $url   = 'https://api.openai.com/v1/chat/completions';
                // PDF braucht ein Modell mit Datei-Input; gpt-4o kann kein PDF.
                $model = ($pdfBase64 !== null) ? self::AI_OPENAI_PDF_MODEL : self::AI_OPENAI_MODEL;
                if ($key === '') {
                    return ['ok' => false, 'code' => 'ai_not_configured', 'message' => $this->Translate('No OpenAI API key configured.'), 'status' => 400];
                }
            } else {
                $key     = trim($this->ReadPropertyString('AiLocalKey'));
                $baseUrl = rtrim(trim($this->ReadPropertyString('AiLocalBaseUrl')), '/');
                $model   = trim($this->ReadPropertyString('AiLocalModel'));
                if ($baseUrl === '' || $model === '') {
                    return ['ok' => false, 'code' => 'ai_not_configured', 'message' => $this->Translate('Local server URL and model must be configured.'), 'status' => 400];
                }
                // „http://127.0.0.1:1234" und „…:1234/v1" muessen beide gehen: die
                // Server (LM Studio, Ollama, llama.cpp, vLLM) bedienen alle den
                // Pfad /v1, aber das Formular fragt nach der BASIS. Ohne diese
                // Ergaenzung antwortet LM Studio mit „Unexpected endpoint or
                // method. (POST /chat/completions)" — und die leere Antwort sah
                // aus wie ein Modellfehler (am 19.08.2026 genau so gemessen).
                $url = (preg_match('#/v\d+$#', $baseUrl) === 1 ? $baseUrl : $baseUrl . '/v1') . '/chat/completions';
            }
            if ($seitenBilder !== []) {
                // Jede Seite ein eigener Bildblock, danach die Aufgabe — dieselbe
                // Reihenfolge wie beim Einzelbild.
                $userContent = [];
                foreach ($seitenBilder as $seite) {
                    $userContent[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $seite]];
                }
                $userContent[] = ['type' => 'text', 'text' => $userText];
            } elseif ($pdfBase64 !== null) {
                $userContent = [
                    ['type' => 'file', 'file' => ['filename' => 'dokument.pdf', 'file_data' => 'data:application/pdf;base64,' . $pdfBase64]],
                    ['type' => 'text', 'text' => $userText],
                ];
            } elseif ($imageBase64 !== null) {
                $userContent = [
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $this->AiImageMime($imageBase64) . ';base64,' . $imageBase64]],
                    ['type' => 'text', 'text' => $userText],
                ];
            } else {
                $userContent = $userText;
            }
            // Neuere OpenAI-Modelle (PDF) erwarten max_completion_tokens statt max_tokens.
            $tokenKey = ($provider === 'openai' && $pdfBase64 !== null) ? 'max_completion_tokens' : 'max_tokens';
            $bodyArr = [
                'model'    => $model,
                $tokenKey  => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $userContent],
                ],
            ];
            $headers = ['Content-Type: application/json'];
            if ($key !== '') {
                $headers[] = 'Authorization: Bearer ' . $key;
            }
            // Siehe Anthropic-Zweig: kaputte UTF-8-Bytes ersetzen statt scheitern.
            // Der eigene Rechner bekommt mehr Zeit als eine Cloud (AI_LOCAL_TIMEOUT).
            $resp = $this->AiHttpPost(
                $url,
                $headers,
                json_encode($bodyArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                $provider === 'local' ? self::AI_LOCAL_TIMEOUT : self::AI_TIMEOUT
            );
            return $this->AiFinishText($resp, static function (array $data): string {
                return (string)($data['choices'][0]['message']['content'] ?? '');
            });
        }

        return ['ok' => false, 'code' => 'ai_not_configured', 'message' => $this->Translate('No AI provider configured.'), 'status' => 400];
    }

    /** Wertet die HTTP-Antwort aus: Fehler mappen, sonst den Antworttext liefern. */
    private function AiFinishText(array $resp, callable $extractText): array
    {
        if (($resp['err'] ?? '') !== '') {
            return ['ok' => false, 'code' => 'ai_unreachable', 'message' => $this->Translate('Could not reach the AI service.') . ' ' . $resp['err'], 'status' => 502];
        }
        $status = (int)($resp['status'] ?? 0);
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'code' => 'ai_unauthorized', 'message' => $this->Translate('AI rejected the API key.'), 'status' => 502];
        }
        if ($status === 429) {
            return ['ok' => false, 'code' => 'ai_rate_limited', 'message' => $this->Translate('AI rate limit reached — try again later.'), 'status' => 502];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'code' => 'ai_upstream', 'message' => $this->Translate('AI request failed.') . ' (HTTP ' . $status . ')', 'status' => 502];
        }
        $data = json_decode((string)($resp['body'] ?? ''), true);
        if (!is_array($data)) {
            return ['ok' => false, 'code' => 'ai_bad_response', 'message' => $this->Translate('Unexpected AI response.'), 'status' => 502];
        }
        // Abgeschnittene Antwort erkennen: sonst degradiert eine am Token-Limit
        // gekappte Ausgabe still zu einer leeren/halben Liste („nichts erkannt"),
        // obwohl Tokens abgerechnet wurden.
        $stop = (string)($data['stop_reason'] ?? ($data['choices'][0]['finish_reason'] ?? ''));
        if ($stop === 'max_tokens' || $stop === 'length') {
            return ['ok' => false, 'code' => 'ai_truncated', 'message' => $this->Translate('The AI answer was cut off — try a smaller document.'), 'status' => 502];
        }
        $text = $extractText($data);
        if (trim($text) === '') {
            // Die Rohantwort mitschreiben: ein Server, der den Pfad nicht kennt,
            // oder ein Modell ohne Ausgabe sehen von aussen gleich aus — ohne diese
            // Zeile sucht man am falschen Ende (siehe Kommentar zum /v1-Pfad).
            $this->SendDebug('AI', 'Leere Antwort, Rohdaten: ' . mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE) ?: '', 0, 400), 0);
            return ['ok' => false, 'code' => 'ai_empty', 'message' => $this->Translate('The AI returned an empty answer.'), 'status' => 502];
        }
        return ['ok' => true, 'text' => $text];
    }

    // ────────────────────────────── Parser ──────────────────────────────

    /** Toleranter Parser: erstes „[" … letztes „]", dann feldweise validieren. */
    private function AiParseTodos(string $text): array
    {
        $rows = $this->AiDecodeJsonArray($text);
        if ($rows === []) {
            // „[]" (das Modell sieht nichts) und unlesbares Geschwafel sehen von
            // aussen gleich aus — der Rohtext im Debug unterscheidet beides.
            $this->SendDebug('AI', 'Antwort ohne Eintraege, Rohtext: ' . mb_substr($text, 0, 300), 0);
        }
        $out  = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $info = $row['info'] ?? null;
            $info = ($info === null || $info === '') ? null : (string)$info;

            $due = trim((string)($row['due'] ?? ''));
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                // gültiges Kalenderdatum
            } else {
                $due = null;
            }

            $priority = strtolower(trim((string)($row['priority'] ?? 'normal')));
            if (!in_array($priority, ['high', 'normal', 'low'], true)) {
                $priority = 'normal';
            }

            // Uhrzeit nur, wenn auch ein Datum steht — eine Zeit ohne Tag ist
            // wertlos. Liefert das Modell keine (Foto-Scan), bleibt alles wie bisher
            // und die Aufgabe wird ganztaegig.
            $time = trim((string)($row['time'] ?? ''));
            if ($due === null || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) !== 1) {
                $time = null;
            }

            // Aufgabe oder Termin? Eine Aufgabe muss man TUN, ein Termin FINDET STATT.
            // Fehlt das Feld (Foto-Scan, aeltere Antworten), bleibt es eine Aufgabe —
            // damit aendert sich fuer die vorhandenen Wege nichts.
            $kind = strtolower(trim((string)($row['kind'] ?? 'task')));
            if (!in_array($kind, ['task', 'event'], true)) {
                $kind = 'task';
            }

            // Ende nur bei Terminen und nur mit Beginn. Es darf nicht vor dem Beginn
            // liegen; ein einzelner Tag braucht kein Ende.
            $end = trim((string)($row['end'] ?? ''));
            if ($kind !== 'event' || $due === null
                || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $end, $me) !== 1
                || !checkdate((int)$me[2], (int)$me[3], (int)$me[1])
                || $end < $due) {
                $end = null;
            }

            // Ganztaegig: ausdrueckliche Angabe, sonst abgeleitet — ohne Uhrzeit ist
            // ein Termin ganztaegig, mit Uhrzeit nicht.
            if (array_key_exists('allDay', $row)) {
                $allDay = ($row['allDay'] === true || $row['allDay'] === 'true' || $row['allDay'] === 1);
            } else {
                $allDay = ($kind === 'event' && $time === null);
            }
            if ($time !== null) {
                $allDay = false;
            }

            // Reihe (Kurs) statt Dauertermin. Beides zugleich ergibt keinen Sinn: die
            // Wiederholung gewinnt, sonst entstuende aus einem Kurs zusaetzlich ein
            // wochenlanger Klotz (siehe AiSeriesRule).
            $recurrence = $this->AiParseRecurrence($row['recurrence'] ?? null, $kind, $due);
            if ($recurrence !== null) {
                $end = null;
            }

            $out[] = ['title' => $title, 'info' => $info, 'due' => $due, 'time' => $time,
                      'priority' => $priority, 'kind' => $kind, 'end' => $end, 'allDay' => $allDay,
                      'recurrence' => $recurrence];
            if (count($out) >= 50) {
                break;
            }
        }
        return $out;
    }

    /**
     * Wiederholung eines Vorschlags pruefen.
     *
     * Streng, weil daraus spaeter echte Kalendereintraege entstehen: nur die drei
     * bekannten Takte, und entweder eine Anzahl ODER ein Enddatum. Alles andere
     * faellt weg und der Vorschlag bleibt ein einzelner Termin — lieber ein Termin
     * zu wenig als eine erfundene Reihe.
     *
     * @return array{freq: string, count?: int, until?: string}|null
     */
    private function AiParseRecurrence(mixed $roh, string $kind, ?string $due): ?array
    {
        if ($kind !== 'event' || $due === null || !is_array($roh)) {
            return null;
        }
        $freq = strtolower(trim((string)($roh['freq'] ?? '')));
        if (!in_array($freq, ['weekly', 'biweekly', 'monthly'], true)) {
            return null;
        }
        $count = (int)($roh['count'] ?? 0);
        if ($count > 1) {
            // Deckel wie bei den Vorschlaegen selbst: eine Reihe, die laenger laeuft,
            // ist im Kalender des Anbieters besser als Serie von Hand gepflegt.
            return ['freq' => $freq, 'count' => min($count, self::CAL_SERIES_MAX)];
        }
        $until = trim((string)($roh['until'] ?? ''));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $until, $m) === 1
            && checkdate((int)$m[2], (int)$m[3], (int)$m[1])
            && $until > $due) {
            return ['freq' => $freq, 'until' => $until];
        }
        return null;
    }

    /**
     * Toleranter Rezept-Parser: erwartet ein Objekt {title, servings, items:[…]},
     * verkraftet aber auch eine blanke Liste (dann title/servings = null).
     * @return array{title: ?string, servings: ?int, items: array}
     */
    private function AiParseRecipe(string $text): array
    {
        $data = $this->AiDecodeJsonObject($text);
        if ($data !== null && array_key_exists('items', $data)) {
            $rows     = is_array($data['items']) ? $data['items'] : [];
            $title    = trim((string)($data['title'] ?? ''));
            $title    = ($title === '' || strcasecmp($title, 'null') === 0) ? null : $title;
            $servings = $this->AiParseServings($data['servings'] ?? null);
        } else {
            // Fallback: blanke Liste (altes Format / Modell ignorierte das Objekt)
            $rows     = $this->AiDecodeJsonArray($text);
            $title    = null;
            $servings = null;
        }
        return ['title' => $title, 'servings' => $servings, 'items' => $this->AiValidateItems($rows)];
    }

    /** Validiert eine Zutaten-/Artikelliste: {name, amount, category}. */
    private function AiValidateItems(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $amount = $row['amount'] ?? '';
            if (is_int($amount) || is_float($amount)) {
                $amount = (string)$amount;
            }
            $amount   = is_string($amount) ? trim($amount) : '';
            $category = trim((string)($row['category'] ?? ''));
            $out[] = ['name' => $name, 'amount' => $amount, 'category' => $category];
            if (count($out) >= self::AI_MAX_INGREDIENTS) {
                break;
            }
        }
        return $out;
    }

    /** Portionen tolerant lesen: int/float direkt, String wie „4 Portionen" → 4. Sonst null. */
    private function AiParseServings($value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $n = (int)$value;
        } elseif (is_string($value) && preg_match('/\d+/', $value, $m)) {
            $n = (int)$m[0];
        } else {
            return null;
        }
        return ($n >= 1 && $n <= 999) ? $n : null;
    }

    /** Schneidet das erste JSON-Objekt aus dem Text und dekodiert es. */
    private function AiDecodeJsonObject(string $text): ?array
    {
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }

    /** Schneidet das erste JSON-Array aus dem Text und dekodiert es. */
    private function AiDecodeJsonArray(string $text): array
    {
        $start = strpos($text, '[');
        $end   = strrpos($text, ']');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $rows = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Bildtyp aus den Daten selbst bestimmen.
     *
     * Bewusst an den Magic Bytes und nicht am Dateinamen oder an einer
     * durchgereichten Angabe: Das Bild kommt aus drei Richtungen (Kamera der App,
     * Mailanhang, Kachel-Relay), und nur die Bytes wissen sicher, was es ist. Eine
     * falsche Etikettierung weisen die Anbieter zurueck.
     *
     * @return string image/jpeg, image/png, image/gif oder image/webp — im Zweifel
     *                JPEG, denn das war bis dahin die einzige Annahme im Modul.
     */
    private function AiImageMime(string $base64): string
    {
        // 24 base64-Zeichen (Vielfaches von 4) ergeben 18 Bytes — genug für jede Signatur.
        $kopf = (string)base64_decode(substr($base64, 0, 24), false);
        if (str_starts_with($kopf, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($kopf, 'GIF87a') || str_starts_with($kopf, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($kopf, 'RIFF') && substr($kopf, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return 'image/jpeg';
    }

    // ────────────────────────────── PDF für lokale Server ──────────────────────────────

    /**
     * Werkzeug im Dateisystem suchen. Symcon erbt keinen brauchbaren PATH, deshalb
     * absolute Pfade — Homebrew (Apple Silicon und Intel) und die Systemablage.
     */
    private function AiToolPath(string $name): ?string
    {
        foreach (['/opt/homebrew/bin/', '/usr/local/bin/', '/usr/bin/'] as $ordner) {
            $pfad = $ordner . $name;
            if (is_executable($pfad)) {
                return $pfad;
            }
        }
        return null;
    }

    /**
     * Textebene eines PDFs lesen. Leer bei einem Scan — dann ist der Bildweg dran.
     *
     * Bewusst ueber Dateien und exec statt ueber Pipes: proc_open bleibt in Symcons
     * Umgebung haengen (gemessen — das Skript kam nie zurueck), exec dagegen laeuft
     * zuverlaessig. Beide Dateien verschwinden in jedem Fall wieder.
     */
    private function AiPdfToText(string $pdfBase64): string
    {
        $werkzeug = $this->AiToolPath('pdftotext');
        if ($werkzeug === null) {
            $this->SendDebug('AI', 'pdftotext nicht gefunden — PDF-Text entfaellt', 0);
            return '';
        }
        $roh = base64_decode($pdfBase64, true);
        if (!is_string($roh) || $roh === '') {
            return '';
        }
        $stamm  = sys_get_temp_dir() . '/symdo_pdftxt_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $quelle = $stamm . '.pdf';
        $ziel   = $stamm . '.txt';
        $text   = '';
        try {
            if (@file_put_contents($quelle, $roh) === false) {
                return '';
            }
            unset($roh);
            $rc = 0;
            $ausgabe = [];
            @exec(escapeshellarg($werkzeug) . ' -layout -enc UTF-8 '
                . escapeshellarg($quelle) . ' ' . escapeshellarg($ziel) . ' 2>/dev/null', $ausgabe, $rc);
            if ($rc !== 0) {
                $this->SendDebug('AI', 'pdftotext meldet Fehler ' . $rc, 0);
            }
            $text = (string)@file_get_contents($ziel);
        } catch (Throwable $e) {
            $this->SendDebug('AI', 'PDF-Textauszug fehlgeschlagen: ' . $e->getMessage(), 0);
        } finally {
            @unlink($quelle);
            @unlink($ziel);
        }

        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? '');
        if (strlen($text) > self::AI_RECIPE_TEXT_MAX) {
            // mb_strcut, damit kein Multibyte-Zeichen zerschnitten wird (siehe MailPrepareText).
            $text = mb_strcut($text, 0, self::AI_RECIPE_TEXT_MAX, 'UTF-8');
        }
        return $text;
    }

    /**
     * Die ersten Seiten eines PDFs als JPEG — für Scans ohne Textebene.
     *
     * pdftoppm rendert direkt in Zielbreite; GD bleibt bewusst außen vor, denn das
     * Laden einer A4-Seite kostet dort 16 MB von 32 MB memory_limit (gemessen).
     *
     * @return list<string> base64-kodierte JPEGs, leer wenn nichts ging
     */
    private function AiPdfToImages(string $pdfBase64, int $maxSeiten = self::AI_PDF_PAGES_MAX): array
    {
        $werkzeug = $this->AiToolPath('pdftoppm');
        if ($werkzeug === null) {
            $this->SendDebug('AI', 'pdftoppm nicht gefunden — PDF-Bildweg entfaellt', 0);
            return [];
        }
        $roh = base64_decode($pdfBase64, true);
        if (!is_string($roh) || $roh === '') {
            return [];
        }
        $stamm = sys_get_temp_dir() . '/symdo_pdf_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $quelle = $stamm . '.pdf';
        $bilder = [];
        try {
            if (@file_put_contents($quelle, $roh) === false) {
                return [];
            }
            unset($roh);
            $cmd = escapeshellarg($werkzeug) . ' -jpeg -jpegopt quality=82'
                 . ' -f 1 -l ' . max(1, $maxSeiten)
                 . ' -scale-to-x ' . self::AI_PDF_IMAGE_WIDTH . ' -scale-to-y -1 '
                 . escapeshellarg($quelle) . ' ' . escapeshellarg($stamm) . ' 2>/dev/null';
            $rc = 0;
            $ausgabe = [];
            @exec($cmd, $ausgabe, $rc);
            if ($rc !== 0) {
                $this->SendDebug('AI', 'pdftoppm meldet Fehler ' . $rc, 0);
            }
            $seiten = glob($stamm . '-*.jpg') ?: [];
            sort($seiten, SORT_NATURAL);
            foreach (array_slice($seiten, 0, $maxSeiten) as $datei) {
                $inhalt = (string)@file_get_contents($datei);
                if ($inhalt !== '') {
                    $bilder[] = base64_encode($inhalt);
                }
            }
        } catch (Throwable $e) {
            $this->SendDebug('AI', 'PDF-Bildwandlung fehlgeschlagen: ' . $e->getMessage(), 0);
        } finally {
            // Nichts auf der Platte zurücklassen — auch nicht bei einem Abbruch.
            @unlink($quelle);
            foreach (glob($stamm . '-*.jpg') ?: [] as $datei) {
                @unlink($datei);
            }
        }
        return $bilder;
    }

    // ────────────────────────────── HTTP ──────────────────────────────

    private function AiHttpPost(string $url, array $headers, string $bodyJson, int $timeout = self::AI_TIMEOUT): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::AI_CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        $err  = ($body === false) ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => is_string($body) ? $body : '', 'err' => $err];
    }

    /**
     * Holt eine öffentliche Webseite server-seitig — SSRF-sicher: jede Weiterleitung
     * wird einzeln gegen private/reservierte Adressbereiche geprüft (kein blindes
     * FOLLOWLOCATION). Nur http/https, Zeit-/Größen-Limits.
     * @return array ok:true+body | ok:false+code+message+status
     */
    private function AiFetchPublicPage(string $url): array
    {
        $urlErr = ['ok' => false, 'code' => 'invalid_url', 'message' => $this->Translate('Invalid or non-public URL.'), 'status' => 422];
        for ($hop = 0; $hop < 4; $hop++) {
            $validIps = [];
            if (!$this->AiIsPublicUrl($url, $validIps)) {
                return $urlErr;
            }
            // Die geprüfte IP wird an cURL gebunden: sonst löst cURL den Namen ein
            // zweites Mal auf und ein 0-TTL-Rebinding könnte auf 127.0.0.1 zeigen.
            $resp   = $this->AiHttpGet($url, $validIps);
            $status = (int)$resp['status'];
            if (($resp['err'] ?? '') !== '') {
                return ['ok' => false, 'code' => 'ai_url_fetch', 'message' => $this->Translate('Could not load the page.'), 'status' => 502];
            }
            if ($status >= 300 && $status < 400 && (string)$resp['location'] !== '' && $hop < 3) {
                $url = $this->AiResolveRedirect($url, (string)$resp['location']);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                return ['ok' => false, 'code' => 'ai_url_fetch', 'message' => $this->Translate('Could not load the page.') . ' (HTTP ' . $status . ')', 'status' => 502];
            }
            return ['ok' => true, 'body' => (string)$resp['body']];
        }
        return $urlErr;
    }

    /** Schema + öffentlicher (nicht privater/reservierter) Host? */
    private function AiIsPublicUrl(string $url, ?array &$resolvedIps = null): bool
    {
        $resolvedIps = [];
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts  = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host   = (string)($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }
        // Credentials in der URL (http://user:pass@host) ablehnen und nur die
        // Standard-Ports zulassen — sonst wird das Gateway zum Port-Scanner.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $port = (int)($parts['port'] ?? 0);
        if ($port !== 0 && $port !== 80 && $port !== 443) {
            return false;
        }
        $host = trim($host, '[]'); // IPv6-Literale
        $ips  = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            $a = @gethostbynamel($host);
            if (is_array($a)) {
                $ips = $a;
            }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = (string)$rec['ipv6'];
                    }
                }
            }
        }
        if ($ips === []) {
            return false; // nicht auflösbar → ablehnen
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false; // privat / loopback / link-local / reserviert
            }
            // PHPs Filter lässt diese durch, sie sind aber nicht „öffentlich":
            // 100.64.0.0/10 (CGNAT), 198.18.0.0/15 (Benchmark), 64:ff9b::/96 (NAT64,
            // mappt u.a. 127.0.0.1) und IPv4-mapped IPv6.
            if ($this->AiIsBlockedIp($ip)) {
                return false;
            }
        }
        $resolvedIps = array_values(array_unique($ips));
        return true;
    }

    /** Zusätzliche Deny-Liste für Bereiche, die FILTER_FLAG_NO_PRIV_RANGE nicht erfasst. */
    private function AiIsBlockedIp(string $ip): bool
    {
        $blocked = ['100.64.0.0/10', '198.18.0.0/15', '64:ff9b::/96', '::ffff:0:0/96'];
        foreach ($blocked as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $ipBin  = @inet_pton($ip);
            $netBin = @inet_pton($net);
            if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
                continue;
            }
            $bytes = intdiv((int)$bits, 8);
            $rest  = (int)$bits % 8;
            if ($bytes > 0 && strncmp($ipBin, $netBin, $bytes) !== 0) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = chr((0xFF << (8 - $rest)) & 0xFF);
            if ((($ipBin[$bytes] ?? "\0") & $mask) === (($netBin[$bytes] ?? "\0") & $mask)) {
                return true;
            }
        }
        return false;
    }

    private function AiResolveRedirect(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p      = parse_url($base);
        $scheme = (string)($p['scheme'] ?? 'https');
        $host   = (string)($p['host'] ?? '');
        $port   = isset($p['port']) ? ':' . $p['port'] : '';
        if ($host === '') {
            return $location;
        }
        if ($location !== '' && $location[0] === '/') {
            return $scheme . '://' . $host . $port . $location;
        }
        $path = (string)($p['path'] ?? '/');
        $slash = strrpos($path, '/');
        $dir   = $slash === false ? '/' : substr($path, 0, $slash + 1);
        return $scheme . '://' . $host . $port . $dir . $location;
    }

    private function AiHttpGet(string $url, array $pinnedIps = []): array
    {
        $body     = '';
        $location = '';
        $max      = 2 * 1024 * 1024;
        $ch = curl_init($url);
        if ($pinnedIps !== []) {
            $parts = parse_url($url);
            $host  = (string)($parts['host'] ?? '');
            $port  = (int)($parts['port'] ?? (strtolower((string)($parts['scheme'] ?? '')) === 'https' ? 443 : 80));
            if ($host !== '') {
                curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . implode(',', $pinnedIps)]);
            }
        }
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT        => self::AI_HTTP_GET_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SymDoGateway/1.0)',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, $max): int {
                $body .= $chunk;
                return (strlen($body) > $max) ? 0 : strlen($chunk);
            },
        ]);
        $ok    = curl_exec($ch);
        $errno = curl_errno($ch);
        // Abbruch durch die Größenbegrenzung (CURLE_WRITE_ERROR) ist kein Fehler:
        // der bis dahin geladene Body reicht.
        $err    = ($ok === false && $errno !== CURLE_WRITE_ERROR) ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'err' => $err, 'location' => $location];
    }

    // ────────────────────────────── Rezept-Text ──────────────────────────────

    /** Macht aus Roh-HTML den für die KI relevanten Text (JSON-LD-Zutaten bevorzugt). */
    private function AiRecipeText(string $html): string
    {
        $prefix      = '';
        $ingredients = $this->AiExtractJsonLdIngredients($html);
        if ($ingredients !== []) {
            $prefix = "Zutaten (aus strukturierten Daten der Seite):\n- " . implode("\n- ", $ingredients) . "\n\n";
        }
        $combined = $prefix . $this->AiHtmlToText($html);
        if (strlen($combined) > self::AI_RECIPE_TEXT_MAX) {
            $combined = substr($combined, 0, self::AI_RECIPE_TEXT_MAX);
        }
        return trim($combined);
    }

    /** schema.org/Recipe „recipeIngredient" aus JSON-LD-Blöcken (rekursiv). */
    private function AiExtractJsonLdIngredients(string $html): array
    {
        if (!preg_match_all('#<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $block) {
            $data = json_decode(trim($block), true);
            if (is_array($data)) {
                $this->AiCollectRecipeIngredients($data, $out);
            }
        }
        $clean = [];
        foreach ($out as $line) {
            $line = trim((string)preg_replace('/\s+/', ' ', (string)$line));
            if ($line !== '') {
                $clean[$line] = true;
            }
        }
        return array_slice(array_keys($clean), 0, self::AI_MAX_INGREDIENTS);
    }

    private function AiCollectRecipeIngredients(array $node, array &$out): void
    {
        foreach ($node as $key => $val) {
            if ($key === 'recipeIngredient' && is_array($val)) {
                foreach ($val as $ing) {
                    if (is_string($ing)) {
                        $out[] = $ing;
                    }
                }
            } elseif (is_array($val)) {
                $this->AiCollectRecipeIngredients($val, $out);
            }
        }
    }

    private function AiHtmlToText(string $html): string
    {
        // Skripte/Styles/versteckte Blöcke zuerst raus, sonst landet JS/CSS im Text.
        $t = preg_replace('#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $t = preg_replace('#<!--.*?-->#s', ' ', $t) ?? $t;
        $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t) ?? $t;
        $t = preg_replace('/<\/\s*(p|div|li|tr|h[1-6])\s*>/i', "\n", $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+\n/", "\n", $t) ?? $t;
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    // ────────────────────────────── Helfer ──────────────────────────────

    /** Master-Schalter: bei deaktivierter KI wird der Endpunkt abgelehnt (403). */
    /**
     * Rate-Limit-Gate für die KI-/Medien-Endpunkte. false = 429 wurde gesendet.
     * Schützt den KI-Key (Kosten) und die Platte (Medienobjekte) vor einer
     * Schleife mit einem gültigen Token.
     */
    private function AiRateLimitOk(array $device): bool
    {
        if ($this->AiRateLimitAllows((string)($device['id'] ?? ''), self::AI_RATE_MAX, self::AI_RATE_WINDOW)) {
            return true;
        }
        $this->SendApiError('ai_quota', $this->Translate('Too many AI requests — please wait a moment.'), 429);
        return false;
    }

    private function AiIsEnabled(): bool
    {
        if ($this->ReadPropertyBoolean('AiEnabled')) {
            return true;
        }
        $this->SendApiError('ai_disabled', $this->Translate('AI analysis is disabled.'), 403);
        return false;
    }

    /** Liest das Bild aus dem Body (data:-Prefix strippen, Größen-Guard). null = Fehler gesendet. */
    private function AiReadImage(array $body): ?string
    {
        $image = $this->BodyStr($body, 'image');
        $comma = strpos($image, 'base64,');
        if ($comma !== false) {
            $image = substr($image, $comma + 7);
        }
        $image = trim($image);
        if ($image === '') {
            $this->SendApiError('invalid_payload', $this->Translate('No image provided.'), 422);
            return null;
        }
        // grobe Größenbegrenzung (base64 ~ 4/3 der Bytes); die Web-App skaliert vorher runter
        if (strlen($image) > self::AI_MAX_IMAGE_B64) {
            $this->SendApiError('invalid_payload', $this->Translate('Image too large.'), 413);
            return null;
        }
        return $image;
    }

    private function SendAiErrorResult(array $result): void
    {
        $this->SendApiError(
            (string)($result['code'] ?? 'ai_error'),
            (string)($result['message'] ?? 'AI error'),
            (int)($result['status'] ?? 502)
        );
    }

    // ────────────────────────────── Prompts ──────────────────────────────

    private function AiSystemPrompt(string $today): string
    {
        return 'Du extrahierst Aufgaben (ToDos) aus Dokumenten: Briefe, Behörden- und Bankschreiben, '
            . 'Rechnungen, Notizen, Listen, E-Mails oder Fotos davon. Wichtig: Aufgaben stehen oft NICHT '
            . 'als Liste im Dokument, sondern stecken implizit in Handlungsaufforderungen — z.B. „bitte '
            . 'bestätigen Sie…“, „senden Sie das Formular zurück“, „überweisen Sie bis…“, „vereinbaren Sie '
            . 'einen Termin“. Leite daraus die Aufgabe ab, die der Empfänger erledigen muss, aus dessen '
            . 'Sicht formuliert (kurzer, prägnanter deutscher Titel, z.B. „Daten bei der Commerzbank '
            . 'bestätigen“). Drohende Konsequenzen (Sperrung, Mahnung, Frist) → priority "high". Nutze '
            . '"info" für den wichtigsten Kontext (Absender, Referenz, Konsequenz, geforderter Weg). '
            . 'WICHTIG für "due": Enthält das Dokument eine Frist, ein Fälligkeits- oder Zahlungsdatum, '
            . 'einen Termin oder ein Datum, bis zu dem der Empfänger etwas erledigen muss, trage es IMMER '
            . 'in "due" ein (Format YYYY-MM-DD). Rechne relative Angaben ausgehend von heute (' . $today . ') '
            . 'in ein konkretes Datum um — z.B. „innerhalb von 14 Tagen“, „bis Freitag“, „bis Monatsende“, '
            . '„nächste Woche“, „zum 15.03.“; bei einem Zeitraum bzw. einer Frist nimm den letztmöglichen Tag. '
            . 'Nur wenn wirklich kein Datum und keine Frist erkennbar ist, setze "due" auf null. Antworte '
            . 'AUSSCHLIESSLICH mit einem JSON-Array, ohne Erklärungen und ohne Markdown. Jedes Element hat '
            . 'exakt diese Felder: {"title": string, "info": string oder null, "due": "YYYY-MM-DD" oder '
            . 'null, "priority": "high" oder "normal" oder "low"}. '
            // Ohne diesen Satz liefert ein kleines Modell bei einer freundlichen
            // Einladung eine leere Liste: Es sucht die Aufforderung („bitte
            // zurücksenden") und findet keine (gemessen an „Angebot Segelboot bauen
            // am 27.06." — 0 Einträge, obwohl Datum, Kosten und Anmeldung dastanden).
            . 'EINLADUNGEN UND ANGEBOTE ZÄHLEN MIT: Ein Kurs, Workshop, Ausflug, Fest oder '
            . 'Mitmachangebot mit konkretem Datum gehört IMMER in die Liste — auch wenn der '
            . 'Text nur einlädt, erinnert oder „aufmerksam macht" und niemanden ausdrücklich '
            . 'auffordert; das Datum kommt dann in "due". Ist eine Anmeldung nötig oder ein '
            . 'Beitrag zu zahlen, gib das als eigenen Eintrag zurück. Antworte nur dann mit [], '
            . 'wenn WEDER ein Datum NOCH eine Handlung vorkommt — reine Werbung, Newsletter, '
            . 'Danksagungen, Rückblicke.';
    }

    /**
     * Die Kategorien, aus denen das Modell waehlen darf.
     *
     * Ohne diese Schranke erfindet es welche: gemessen an einer echten Liste standen
     * 16 von 78 Artikeln in Kategorien, die es in der Instanz nicht gibt ("Molkerei",
     * "Gewuerze", "Saucen & Dressings"). Solche Artikel landen in der Anzeige hinten
     * in eigenen Abschnitten und ohne passendes Icon. Zwei der vier Beispiele, die
     * frueher im Prompt standen, waren selbst nicht in der Standardtabelle — der
     * Prompt hat den Fehler also aktiv erzeugt.
     *
     * Vorrang hat, was der Client mitschickt: nur er weiss, in welche Liste die
     * Artikel danach wandern. Ohne Angabe wird die Vereinigung ueber die
     * Einkaufslisten gebildet, die dieses Gateway bedient.
     *
     * @return string[]
     */
    /**
     * Systemprompt fuer weitergeleitete E-Mails.
     *
     * Baut auf AiSystemPrompt auf und ergaenzt drei Dinge, die nur bei Mail auftreten:
     * eine Uhrzeit zum Termin, der Hinweis auf den Weiterleitungs-Rahmen (Absender
     * der Mail ist der Weiterleitende, die Quelle steht im Zitat) und die Regel,
     * einen fehlenden Anhang nicht zu erfinden — das Kernmodul liefert ihn nicht mit.
     */
    /**
     * Aufgabe oder Termin — die Unterscheidung steht an EINER Stelle, damit beide
     * Prompt-Fassungen (mit und ohne Anhang) sie wortgleich tragen.
     *
     * Die Trennlinie ist bewusst die Handlung, nicht das Datum: eine Aufgabe muss
     * der Empfaenger TUN, ein Termin FINDET STATT. „Formular bis Freitag
     * zurueckschicken" ist eine Aufgabe mit Frist, „Elternabend am 12.03. um 19:30"
     * ein Termin. Verlangt ein Termin zusaetzlich eine Vorbereitung, sind das ZWEI
     * Eintraege — sonst verschwindet die Handlung hinter dem Datum.
     */
    private function AiKindRule(): string
    {
        return ' UNTERSCHEIDE AUFGABE UND TERMIN. Setze in jedem Eintrag das Feld "kind": '
            . '"task" fuer etwas, das der Empfaenger TUN muss (Formular ausfuellen, '
            . 'ueberweisen, anmelden, zurueckschicken, Betreuung organisieren) — das '
            . 'Datum in "due" ist dann die FRIST. "event" fuer etwas, das STATTFINDET '
            . 'und im Kalender stehen wuerde (Elternabend, Sprechstunde, Ausflug, '
            . 'Schliesstag, Ferienzeitraum, Feiertag) — "due" ist dann der TAG des '
            . 'Termins. Bei "event" mit Uhrzeit gib "time" als "HH:MM" an und setze '
            . '"allDay" auf false; ohne Uhrzeit lass "time" weg und setze "allDay" auf '
            . 'true. Dauert ein Termin mehrere Tage (Ferien, Schliesszeit), gib in "end" '
            . 'den LETZTEN Tag im Format YYYY-MM-DD an, sonst lass "end" weg. Fordert '
            . 'ein Termin zusaetzlich eine Handlung (anmelden, Betreuung organisieren, '
            . 'etwas mitbringen), gib BEIDES zurueck: den Termin als "event" und die '
            . 'Handlung als "task" mit ihrer eigenen Frist. '
            // Ohne diesen Absatz liefert ein kleines Modell bei einer freundlichen
            // Einladung eine leere Liste: Es sucht die Aufforderung („bitte
            // zurueckschicken“) und findet keine (gemessen an „Angebot Segelboot
            // bauen am 27.06.“ — 0 Eintraege, obwohl Datum, Kosten und Anmeldung
            // im Text stehen).
            . 'EINLADUNGEN UND ANGEBOTE ZAEHLEN: Ein Kurs, Workshop, Ausflug, Fest '
            . 'oder Mitmachangebot mit konkretem Datum ist IMMER ein "event" — auch '
            . 'wenn der Text nur einlaedt, erinnert oder „aufmerksam macht“ und '
            . 'niemanden ausdruecklich auffordert. Der Empfaenger will es im '
            . 'Kalender sehen. Ist dafuer eine Anmeldung noetig oder ein Beitrag zu '
            . 'zahlen, gib zusaetzlich eine "task" dafuer zurueck. Eine leere Liste '
            . 'ist nur richtig, wenn WEDER ein Datum NOCH eine Handlung vorkommt — '
            . 'reine Werbung, Newsletter, Danksagungen, Rueckblicke.'
            . $this->AiSeriesRule();
    }

    /**
     * Reihen (Kurse) sind KEINE mehrtaegigen Termine.
     *
     * Ohne diese Regel presste das Modell einen 15-mal dienstags stattfindenden Kurs
     * in „due 01.09., end 15.12., 18:45" — im Kalender ein durchgehender Klotz von
     * dreieinhalb Monaten statt 15 Abenden. Den Takt hatte es dabei sogar erkannt und
     * in "info" geschrieben; es fehlte nur das Feld dafuer.
     *
     * Ausgerechnet werden die Einzeltermine NICHT vom Modell, sondern in CalExpandSeries
     * aus dem ersten Termin — Sprachmodelle zaehlen Kalenderwochen unzuverlaessig, und
     * eine falsche Reihe faellt im Kalender erst Wochen spaeter auf.
     */
    private function AiSeriesRule(): string
    {
        return ' REIHEN (Kurse, wiederkehrende Gruppen): Findet ein Termin MEHRFACH in '
            . 'gleichem Abstand statt („dienstags", „jeden zweiten Mittwoch", „15 Termine"), '
            . 'dann gib in "due" und "time" den ERSTEN Termin an, LASS "end" WEG und '
            . 'beschreibe die Wiederholung im Feld "recurrence": '
            . '{"freq": "weekly" | "biweekly" | "monthly", "count": Anzahl der Termine} — '
            . 'ist die Anzahl nicht genannt, aber das Datum des letzten Termins, gib statt '
            . '"count" das Feld "until": "YYYY-MM-DD". Rechne die einzelnen Termine NICHT '
            . 'selbst aus und gib sie nicht als mehrere Eintraege zurueck. "end" bleibt '
            . 'ausschliesslich fuer einen Termin, der ohne Unterbrechung mehrere Tage '
            . 'DAUERT (Ferien, Schliesszeit) — ein Kurs dauert nicht, er wiederholt sich.';
    }

    private function AiMailSystemPrompt(string $today, bool $mitAnhang = false): string
    {
        if ($mitAnhang) {
            return $this->AiSystemPrompt($today)
                . ' ZUSATZ FUER E-MAILS: Der Text ist eine E-Mail, oft weitergeleitet — der '
                . 'eigentliche Absender steht dann im zitierten Kopf innerhalb des Textes, '
                . 'nicht in der Betreffzeile. Nenne diese Quelle in "info". Die beigefuegte '
                . 'Datei liegt dir VOR und ist Teil derselben Nachricht: lies Mailtext und '
                . 'Anhang zusammen. Meist steht die Aufforderung in der Mail und die Einzelheiten '
                . '(Termine, Fristen, Betraege, Formularfelder) im Anhang — uebernimm sie von '
                . 'dort. Stehen im Anhang mehrere eigenstaendige Termine oder Aufgaben, gib sie '
                . 'als eigene Eintraege zurueck.'
                . $this->AiKindRule();
        }
        return $this->AiSystemPrompt($today)
            . ' ZUSATZ FUER E-MAILS: Der Text ist eine E-Mail, oft weitergeleitet — der '
            . 'eigentliche Absender steht dann im zitierten Kopf innerhalb des Textes, '
            . 'nicht in der Betreffzeile. Nenne diese Quelle in "info". Nennt die Mail '
            . 'einen konkreten Termin mit Uhrzeit (Elternabend, Sprechstunde, Abgabe um '
            . 'eine bestimmte Zeit), gib zusaetzlich das Feld "time" im Format "HH:MM" '
            . 'an.' . $this->AiKindRule() . ' WICHTIG zum Anhang: '
            . 'Anhaenge liegen dir NICHT vor. Ein Verweis darauf („siehe Anhang“, „im '
            . 'beigefuegten Formular“) hebt die Aufgabe aber NICHT auf — er ist selbst die '
            . 'Handlungsaufforderung. Nennt die Mail ein Formular, eine Abfrage, eine Liste '
            . 'oder ein Dokument, das ausgefuellt, beachtet, unterschrieben oder '
            . 'zurueckgeschickt werden soll, erzeuge dafuer eine Aufgabe — z.B. '
            . '„Ferienabfrage Herbstferien ausfuellen und zurueckschicken“ — und vermerke in '
            . '"info", dass die Details im Anhang stehen. Erfinde lediglich keine Angaben, '
            . 'die nur im Anhang stehen koennen: keine Fristen, Betraege, Uhrzeiten oder '
            . 'Namen, die im Mailtext nicht vorkommen.';
    }

    private function AiAllowedCategories(array $body): array
    {
        $namen = [];
        $vom_client = $body['categories'] ?? null;
        if (is_array($vom_client)) {
            foreach ($vom_client as $eintrag) {
                $name = trim((string)$eintrag);
                if ($name !== '' && mb_strlen($name) <= 60) {
                    $namen[$name] = true;
                }
            }
        }
        if ($namen === []) {
            foreach ($this->GetListInstances() as $inst) {
                if (($inst['kind'] ?? '') !== 'shopping' || !function_exists('SL_GetAppState')) {
                    continue;
                }
                $state = json_decode((string)SL_GetAppState((int)$inst['id']), true);
                foreach ((array)($state['state']['categoryOrder'] ?? []) as $name) {
                    $name = trim((string)$name);
                    if ($name !== '') {
                        $namen[$name] = true;
                    }
                }
            }
        }
        // Deckel gegen einen ueberlangen Prompt (und gegen viele Listen auf einmal).
        return array_slice(array_keys($namen), 0, 40);
    }

    /** Der Satz im Prompt, der die Kategorie beschreibt — mit oder ohne Schranke. */
    private function AiCategoryRule(array $erlaubt): string
    {
        if ($erlaubt === []) {
            // Kein Modul erreichbar: dann lieber gar keine Beispiele nennen, als
            // welche zu erfinden.
            return '"category" = grobe Lebensmittel-Kategorie oder leer. ';
        }
        return '"category" = GENAU EINE dieser Kategorien, unverändert abgeschrieben: '
            . implode(', ', array_map(static function (string $c): string {
                return '„' . $c . '“';
            }, $erlaubt))
            . '. Passt keine davon, gib "" zurück — erfinde KEINE eigenen Kategorien. ';
    }

    private function AiIngredientsSystemPrompt(array $erlaubteKategorien = []): string
    {
        return 'Du extrahierst Einkaufs-Artikel bzw. Zutaten aus einem Bild: Rezept- oder Kochbuchseiten, '
            . 'handschriftliche Einkaufslisten, Aushänge, Notizzettel oder Produktverpackungen. "items" ist '
            . 'die Liste der benötigten Artikel/Zutaten. "name" = der Artikel bzw. die Zutat, kurz und ohne '
            . 'Menge (z.B. „Mehl“, „Tomaten“, „Milch“). "amount" = die Menge samt Einheit als kurzer Text '
            . '(z.B. „500 g“, „2“, „1 Bund“), leer wenn keine Menge angegeben ist. '
            . $this->AiCategoryRule($erlaubteKategorien)
            . 'Fasse Dubletten zusammen. "title" = der Rezepttitel, WENN es sich um ein Rezept handelt, sonst '
            . 'null. "servings" = die Anzahl der Portionen, die das Rezept ergibt, als ganze Zahl, wenn '
            . 'angegeben, sonst null (bei reinen Einkaufslisten immer null). Antworte AUSSCHLIESSLICH mit '
            . 'einem JSON-Objekt, ohne Erklärungen und ohne Markdown, mit exakt diesen Feldern: '
            . '{"title": string oder null, "servings": Zahl oder null, "items": [{"name": string, '
            . '"amount": string, "category": string}]}. Wenn keinerlei Artikel erkennbar sind, gib '
            . '{"title": null, "servings": null, "items": []} zurück.';
    }

    private function AiRecipeSystemPrompt(array $erlaubteKategorien = []): string
    {
        return 'Du erhältst den Textinhalt einer Rezept-Webseite. Extrahiere daraus Titel, Portionsangabe '
            . 'und die vollständige Zutatenliste des Rezepts. Ignoriere Navigation, Werbung, Kommentare, '
            . 'Nährwerte und Zubereitungsschritte. "title" = der Titel/Name des Rezepts. "servings" = die '
            . 'Anzahl der Portionen, die das Rezept ergibt, als ganze Zahl (z.B. bei „für 4 Personen“ → 4; '
            . 'bei „12 Muffins“ → 12); null, wenn nicht angegeben. "items" ist die Zutatenliste: "name" = '
            . 'die Zutat, kurz und ohne Menge (z.B. „Mehl“, „Zwiebeln“). "amount" = die Menge samt Einheit '
            . 'als kurzer Text (z.B. „500 g“, „2“, „1 EL“), leer wenn keine Menge angegeben ist. '
            . $this->AiCategoryRule($erlaubteKategorien)
            . 'Rechne Mengen NICHT um (Originalmengen für die angegebenen Portionen). Antworte '
            . 'AUSSCHLIESSLICH mit einem JSON-Objekt, ohne Erklärungen und ohne Markdown, mit exakt diesen '
            . 'Feldern: {"title": string oder null, "servings": Zahl oder null, "items": [{"name": string, '
            . '"amount": string, "category": string}]}. Wenn keine Zutaten erkennbar sind, gib '
            . '{"title": null, "servings": null, "items": []} zurück.';
    }
}
