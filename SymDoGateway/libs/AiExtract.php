<?php

declare(strict_types=1);

// Der HTTP-Griff liegt geteilt in List/libs — der Scanner braucht ihn ohne diese Datei.
require_once __DIR__ . '/../../libs/AiHttp.php';
require_once __DIR__ . '/../../libs/AiProvider.php';
require_once __DIR__ . '/../../libs/AiRecipePage.php';

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
    // Missbrauchsschutz: pro Gerät N KI-Aufrufe je Zeitfenster; zusätzlich darf
    // immer nur EIN Anbieter-Aufruf gleichzeitig laufen (sonst blockieren
    // parallele Requests den Symcon-Webserver).
    private const AI_RATE_MAX        = 60;
    private const AI_RATE_WINDOW     = 3600;
    // Eingefuegter Text („Text analysieren"): grosszuegig fuer lange Mails und
    // Chat-Verlaeufe, aber gedeckelt — der Prompt reist sonst ungebremst zum
    // Anbieter. 20.000 Zeichen sind ~5 Druckseiten.
    private const AI_TEXT_MAX = 20000;

    // PDF-Upload: base64-Größenlimit (Datei ~3/4 davon).
    private const AI_MAX_PDF_B64      = 20 * 1024 * 1024;
    // Diktat: Audio-Obergrenze (Base64) und Transkriptionsmodell. 14 MB Base64
    // sind rund 10 MB Ton — bei Opus ueber 20 Minuten Sprache, mehr ist kein
    // Diktat mehr. Laengere Aufnahmen brauchen auch mehr Geduld als AI_TIMEOUT.
    private const AI_MAX_AUDIO_B64     = 14 * 1024 * 1024;
    private const AI_MAX_IMAGE_B64    = 12 * 1024 * 1024;
    // Obergrenze für gespeicherte Rezeptfotos/-dateien unter „Rezeptfotos".
    private const AI_MEDIA_MAX        = 200;
    /**
     * Groessengrenzen kommen aus OutputLimit()/RelayLimitB64() in AppCore — dort steht
     * auch, warum: die Kernoption ScriptOutputBufferLimit begrenzt die Ausgabe, und
     * was darueber liegt, waere abgelegt aber dauerhaft unabrufbar.
     */
    /** Laengste Kante eines abgelegten Bildes. Mehr kostet Platz ohne mehr zu zeigen. */
    private const AI_MEDIA_EDGE       = 1600;
    private const AI_MEDIA_QUALITY    = 82;

    // Gerichtsbilder (Essensplan): Bilderzeugung gibt es nur bei OpenAI, der
    // Key wird darum direkt gelesen — unabhaengig vom gewaehlten Chat-Anbieter.
    // 'medium' kostet ~4 Cent je Bild und reicht fuer Kachel-Miniaturen locker.
    // Eine Bilderzeugung braucht laenger als ein Chat-Aufruf (gemessen bis ~1 min).
    private const AI_IMAGE_MODEL   = 'gpt-image-1';
    private const AI_IMAGE_TIMEOUT = 120;

    // ────────────────────────────── ToDo (Foto → Aufgaben) ──────────────────────────────

    private function HandleAiExtract(array $device): void
    {
        if (!$this->AiIsEnabled() || !$this->AiRateLimitOk($device) || !$this->AiDayBudgetOk()) {
            return;
        }
        $body = $this->ReadJsonBody();
        $pdf   = $this->AiStripImage($this->BodyStr($body, 'pdf'));
        $text  = trim($this->BodyStr($body, 'text'));
        $image = '';
        if ($text !== '') {
            // Eingefuegter Text (z. B. eine WhatsApp-Nachricht) — derselbe Weg
            // wie Foto und PDF, nur reist der Inhalt direkt im Nutzer-Teil.
            if (mb_strlen($text) > self::AI_TEXT_MAX) {
                $this->SendApiError('invalid_payload', $this->Translate('Text too long.'), 413);
                return;
            }
            /* Der Text schlaegt alles andere — so war es immer. Ein daneben
               mitgeschicktes PDF wird NICHT gesendet, und weil es nicht
               gesendet wird, ist es auch nie auf seine Groesse geprueft
               worden. Es hier stehen zu lassen hiesse, genau diese ungeprueifte
               Datei an den Anbieter zu schicken. */
            $pdf = '';
        } elseif ($pdf !== '') {
            if (strlen($pdf) > self::AI_MAX_PDF_B64) {
                $this->SendApiError('invalid_payload', $this->Translate('File too large.'), 413);
                return;
            }
        } else {
            $gelesen = $this->AiReadImage($body);
            if ($gelesen === null) {
                return; // Fehler wurde bereits gesendet
            }
            $image = $gelesen;
        }

        /* Die Weiche. ALLE Riegel oben bleiben synchron — sie kosten nichts und
           eine Absage soll sofort kommen, nicht erst nach dem Warten. Erst
           danach entscheidet sich, wer den Anbieter ruft. */
        if ($this->AiJobWeg($body)) {
            $auftrag = $this->AiExtractAuftrag($image, $pdf, $text);
            $this->AiJobStarten('extract',
                ['system' => $auftrag['system'], 'user' => $auftrag['user'],
                 'payloadKind' => $image !== '' ? 'image' : ($pdf !== '' ? 'pdf' : ''),
                 'mime' => '', 'url' => ''],
                ['type' => 'todos', 'arten' => $auftrag['arten']],
                $device, $image !== '' ? $image : $pdf);
            return;
        }

        $result = $this->AiExtractTodos($image, $pdf, $text);
        if (($result['ok'] ?? false) !== true) {
            $this->SendAiErrorResult($result);
            return;
        }
        $this->MailCountDay();
        $this->SendJson(['ok' => true, 'todos' => $result['todos']]);
    }

    /**
     * Was der Anbieter zu hoeren bekommt — und welche Arten von Eintrag
     * ueberhaupt entstehen duerfen.
     *
     * Eigener Griff, weil ihn ZWEI Wege brauchen: der synchrone hier und der
     * Auftrag, den eine andere Instanz abarbeitet. Gebaut wird er in beiden
     * Faellen HIER, denn er braucht den Bestand — die Namen des Haushalts und
     * die Frage, ob es Kinder gibt. Der Laeufer kennt beides nicht.
     *
     * @return array{system:string,user:string,arten:list<string>}
     */
    private function AiExtractAuftrag(string $imageBase64 = '', string $pdfBase64 = '', string $text = ''): array
    {
        $auftrag = $pdfBase64 !== '' ? 'Extrahiere die Aufgaben aus dieser Datei.' : 'Extrahiere die Aufgaben aus diesem Dokument.';
        if ($text !== '') {
            // Kein Bild, keine Datei: der Text selbst ist das Dokument.
            $auftrag = "Extrahiere die Aufgaben aus dieser Nachricht.\n\n--- Nachricht ---\n" . $text;
        }
        /* Hausaufgaben nur, wenn es ueberhaupt ein Kind gibt: ohne Kinder kann
           die vierte Art nur schaden (aus einer Aufgabe wuerde eine Hausaufgabe,
           die niemandem gehoert). */
        $arten = ['task', 'event', 'shopping'];
        if ($this->HomeworkKinder() !== []) {
            $arten[] = 'homework';
        }
        return ['system' => $this->AiSystemPrompt(date('Y-m-d')), 'user' => $auftrag, 'arten' => $arten];
    }

    /** @return array ok:true+todos | ok:false+code+message+status */
    private function AiExtractTodos(string $imageBase64 = '', string $pdfBase64 = '', string $text = ''): array
    {
        $auftrag = $this->AiExtractAuftrag($imageBase64, $pdfBase64, $text);
        $r = $this->AiRunCompletion(
            $auftrag['system'],
            $auftrag['user'],
            $imageBase64 !== '' ? $imageBase64 : null,
            $pdfBase64 !== '' ? $pdfBase64 : null
        );
        if (($r['ok'] ?? false) !== true) {
            return $r;
        }
        return ['ok' => true, 'todos' => $this->AiParseTodos((string)$r['text'], $auftrag['arten'])];
    }

    // ─────────────────────── Einkaufsliste (Foto/URL → Zutaten) ───────────────────────

    private function HandleAiIngredients(array $device): void
    {
        if (!$this->AiIsEnabled() || !$this->AiRateLimitOk($device) || !$this->AiDayBudgetOk()) {
            return;
        }
        $body = $this->ReadJsonBody();
        $url  = trim($this->BodyStr($body, 'url'));
        $pdf  = $this->AiStripImage($this->BodyStr($body, 'pdf'));

        // Kategorien, aus denen das Modell waehlen darf — siehe AiAllowedCategories().
        $kategorien = $this->AiAllowedCategories($body);

        $image = '';
        $weg   = '';
        if ($pdf !== '') {
            if (strlen($pdf) > self::AI_MAX_PDF_B64) {
                $this->SendApiError('invalid_payload', $this->Translate('File too large.'), 413);
                return;
            }
            $weg = 'pdf';
        } elseif (($body['image'] ?? '') !== '') {
            $gelesen = $this->AiReadImage($body);
            if ($gelesen === null) {
                return;
            }
            $image = $gelesen;
            $weg   = 'image';
        } elseif ($url !== '') {
            $weg = 'url';
        } else {
            $this->SendApiError('invalid_payload', $this->Translate('No image or URL provided.'), 422);
            return;
        }

        if ($this->AiJobWeg($body)) {
            /* Beim Weg ueber eine Adresse holt der LAEUFER die Seite: das
               dauert bis zu fuenfzehn Sekunden und hat im Hook nichts verloren.
               Er haengt ihren Text an den Nutzer-Teil an. */
            $auftrag = $this->AiIngredientsAuftrag($weg, $kategorien);
            $this->AiJobStarten('ingredients',
                ['system' => $auftrag['system'], 'user' => $auftrag['user'],
                 'payloadKind' => $weg === 'url' ? '' : $weg, 'mime' => '',
                 'url' => $weg === 'url' ? $url : ''],
                ['type' => 'recipe', 'arten' => []],
                $device, $weg === 'image' ? $image : ($weg === 'pdf' ? $pdf : ''));
            return;
        }

        $result = match ($weg) {
            'pdf'   => $this->AiExtractIngredientsFromPdf($pdf, $kategorien),
            'image' => $this->AiExtractIngredientsFromImage($image, $kategorien),
            default => $this->AiExtractIngredientsFromUrl($url, $kategorien),
        };

        if (($result['ok'] ?? false) !== true) {
            $this->SendAiErrorResult($result);
            return;
        }
        $this->MailCountDay();
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
    private function AiRelayBody(string $path, string $payloadJson, int $sdwa = 0, string $txn = ''): string
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
        // Das Tagesbriefing: kein KI-Aufruf, nur der abgelegte Text — deshalb VOR
        // dem AiEnabled-Riegel. Ohne diesen Zweig blieb die Karte in der
        // Kachel-Ansicht der Web-App leer, obwohl das Gateway dort erreichbar ist.
        // Der Vorlese-Knopf erscheint dort weiterhin nicht: Die Tondatei braucht
        // einen Token, und den hat die Kachel nicht.
        // Tonschnipsel fuer die Kachel: als data:-URL, weil sie den Hook ohne
        // Token nicht abrufen kann. Steht bei den Medien, nicht bei der KI — der
        // Schnipsel ist zu diesem Zeitpunkt langst erzeugt.
        if (str_ends_with($path, 'ttsclip')) {
            return json_encode($this->TtsClipRelay($this->BodyStr($body, 'hash')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (str_ends_with($path, 'briefing')) {
            return json_encode($this->BriefingPublic(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (str_ends_with($path, 'calendar')) {
            return json_encode($this->CalHandleAction($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        /* Der Stundenplan der Kinder. Rein lesend und ohne KI — er stand
           trotzdem nicht hier, und deshalb zeigte JEDE Kachel keine
           Stundenplan-Karte: die Web-App fragt ihn ueber diesen einen Weg, und
           ohne Zweig lief die Anfrage in den KI-Riegel und endete mit
           „ai_disabled" bzw. unbekannter Route. Ueber REST ging es immer, weil
           dort der Router den Fall kennt (ApiRouter, case 'timetable'). */
        if (str_ends_with($path, 'timetable')) {
            return json_encode($this->TimetablePublic(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        /* Derselbe Zweig für den Nahverkehr — und aus demselben Grund: eine
           Kachel hat keinen Token und kennt nur diesen einen Weg nach draußen.
           Ohne die Zeile liefe ihre Anfrage in den KI-Riegel, und die Karte
           bliebe leer, obwohl sie über REST tadellos ankommt. */
        if (str_ends_with($path, 'transit')) {
            return json_encode($this->TransitPublic(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        // Notizen: lesen und bearbeiten hat mit der KI nichts zu tun, muss also auch
        // bei abgeschalteter Analyse gehen. Der Riegel steckt in der Aktion selbst —
        // 'analyse' prueft NotesAiAllowed(), alles andere nicht.
        //
        // NUR dieser eine Pfad. Ein Relay-Zweig fuer '/notes/media' waere unerreichbar,
        // weil str_ends_with($path, 'media') weiter oben zuerst greift und in
        // AiGetMedia (nur Rezeptfotos) umleitet. Die Rohdatei geht ohnehin nur ueber
        // REST — dort braucht der System-Viewer sie, und dort hat er einen Token.
        if (str_ends_with($path, 'notes')) {
            return json_encode($this->NotesHandleAction($body, null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        /* Klassenseiten: derselbe Grund wie bei Notizen und Stundenplan. Ohne
           diesen Zweig wäre der Bereich in JEDER Visu-Kachel leer und liefe
           stattdessen in den KI-Riegel weiter unten — die Antwort hieße dann
           „ai_disabled", und man sucht den Fehler bei der KI. Der Pfad endet auf
           „edumaps" und kollidiert mit keinem Zweig weiter oben (insbesondere
           nicht mit „media"). */
        if (str_ends_with($path, 'edumaps')) {
            return json_encode($this->EduHandleAction($body, null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        /* Hausaufgaben: dieselbe Begruendung wie beim Stundenplan. Ohne diesen
           Zweig ist der Bereich in JEDER Visu-Kachel leer und nur ueber REST
           erreichbar — genau der Fehler, der oben beschrieben steht. Vor dem
           KI-Riegel, weil Lesen und Abhaken mit der KI nichts zu tun haben. */
        if (str_ends_with($path, 'homework')) {
            return json_encode($this->HomeworkHandleAction($body, null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        /* Sprachdialog: Sitzung, Herzschlag, Werkzeuge. VOR dem KI-Riegel, damit
           close/tool auch bei mitten im Gespräch abgeschalteter KI noch
           durchkommen — die open-Riegel sitzen im Handler selbst. */
        if (str_ends_with($path, 'voice')) {
            return json_encode($this->VoiceHandleAction($body, null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        /* Die Nachfrage nach einem eingereihten Auftrag. Sie steht VOR dem
           KI-Riegel, und das mit Absicht: wer ein Ergebnis abholt, hat es
           vorher bestellt. Schaltet der Nutzer die KI dazwischen ab, soll er
           trotzdem erfahren, was aus seinem Foto geworden ist. */
        if (str_ends_with($path, 'jobs')) {
            return (string)json_encode($this->AiJobKachelStand($this->BodyStr($body, 'id'), $sdwa),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (!(bool) $this->AiProp('AiEnabled')) {
            return $this->AiRelayError('ai_disabled', $this->Translate('AI analysis is disabled.'));
        }

        // Ab hier kostet jeder Aufruf Geld. Der Weg ueber die Kachel bringt KEIN
        // Geraet mit, das gleitende Stundenfenster je Geraet greift also nicht — bis
        // hierher war er voellig ungebremst. Der Tagesdeckel gilt jetzt auch hier;
        // gezaehlt wird erst nach dem Aufruf (siehe unten), damit ein Fehlschlag
        // beim Anbieter kein Budget verbraucht.
        if ($this->MailDayLimitReached()) {
            return $this->AiRelayError('ai_quota', $this->Translate('Daily limit for AI calls reached.'));
        }

        // Gerichtsbild fuer den Essensplan: {name, items[]} → PNG (base64) im
        // festen Stil. Der Aufrufer (MealPlan-Modul, serverseitig) verkleinert
        // und legt selbst ab — hier entsteht nur das Bild.
        if (str_ends_with($path, 'dishimage')) {
            $items = [];
            foreach ((array)($body['items'] ?? []) as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $items[] = trim($item);
                }
            }
            $r = $this->AiGenerateDishImage($this->BodyStr($body, 'name'), $items);
            if (($r['ok'] ?? false) !== true) {
                return $this->AiRelayError((string)($r['code'] ?? 'ai_error'), (string)($r['message'] ?? 'AI error'));
            }
            $this->MailCountDay();
            return json_encode(['ok' => true, 'image' => $r['image']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (str_ends_with($path, 'ingredients')) {
            $url        = trim($this->BodyStr($body, 'url'));
            $kategorien = $this->AiAllowedCategories($body);
            $weg = $pdf !== '' ? 'pdf' : ($image !== '' ? 'image' : ($url !== '' ? 'url' : ''));
            if ($weg === '') {
                return $this->AiRelayError('invalid_payload', $this->Translate('No image or URL provided.'));
            }
            /* Ab hier ist alles geprueift. Kann ein Laeufer den Aufruf
               uebernehmen, geht er als Auftrag hinaus und die Kachel bekommt
               sofort Bescheid — statt das Gateway fuenfundvierzig Sekunden zu
               belegen. */
            if ($this->AiKachelAuftrag($sdwa, $txn)) {
                $auftrag = $this->AiIngredientsAuftrag($weg, $kategorien);
                return $this->AiRelayAuftrag('ingredients', $sdwa, $txn,
                    ['system' => $auftrag['system'], 'user' => $auftrag['user'],
                     'payloadKind' => $weg === 'url' ? '' : $weg, 'mime' => '',
                     'url' => $weg === 'url' ? $url : ''],
                    ['type' => 'recipe', 'arten' => []],
                    $weg === 'image' ? $image : ($weg === 'pdf' ? $pdf : ''));
            }
            $r = match ($weg) {
                'pdf'   => $this->AiExtractIngredientsFromPdf($pdf, $kategorien),
                'image' => $this->AiExtractIngredientsFromImage($image, $kategorien),
                default => $this->AiExtractIngredientsFromUrl($url, $kategorien),
            };
            if (($r['ok'] ?? false) !== true) {
                return $this->AiRelayError((string)($r['code'] ?? 'ai_error'), (string)($r['message'] ?? 'AI error'));
            }
            $this->MailCountDay();
            return json_encode(['ok' => true, 'title' => $r['title'] ?? null, 'servings' => $r['servings'] ?? null, 'items' => $r['items']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (str_ends_with($path, 'extract')) {
            /* Text zuerst, wie im HTTP-Weg: Nach einem Diktat schickt die
               Oberflaeche das Transkript hier durch, und der Text-Scan
               („Nachricht einfuegen") nimmt denselben Weg. Fehlte der Zweig,
               antwortete das Relay mit „No image provided" — die Aufnahme lief
               dann durch, aber es wurde nichts erkannt. */
            $text = trim($this->BodyStr($body, 'text'));
            if ($text !== '') {
                if (mb_strlen($text) > self::AI_TEXT_MAX) {
                    return $this->AiRelayError('invalid_payload', $this->Translate('Text too long.'));
                }
                // Der Text schlaegt alles andere — und was nicht mitgeht, wird
                // auch nicht geprueft, darf also nicht stehen bleiben.
                $pdf = '';
                $image = '';
            } elseif ($pdf !== '') {
                $image = '';
            } elseif ($image === '') {
                return $this->AiRelayError('invalid_payload', $this->Translate('No image provided.'));
            }
            if ($this->AiKachelAuftrag($sdwa, $txn)) {
                $auftrag = $this->AiExtractAuftrag($image, $pdf, $text);
                return $this->AiRelayAuftrag('extract', $sdwa, $txn,
                    ['system' => $auftrag['system'], 'user' => $auftrag['user'],
                     'payloadKind' => $image !== '' ? 'image' : ($pdf !== '' ? 'pdf' : ''),
                     'mime' => '', 'url' => ''],
                    ['type' => 'todos', 'arten' => $auftrag['arten']],
                    $image !== '' ? $image : $pdf);
            }
            $r = $this->AiExtractTodos($image, $pdf, $text);
            if (($r['ok'] ?? false) !== true) {
                return $this->AiRelayError((string)($r['code'] ?? 'ai_error'), (string)($r['message'] ?? 'AI error'));
            }
            $this->MailCountDay();
            return json_encode(['ok' => true, 'todos' => $r['todos']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        /* Diktat aus der Visu-Kachel. Den HTTP-Weg (/v1/ai/transcribe) erreichen
           nur App und Web-App mit Token; die Kachel hat keinen und kommt
           ausschliesslich hier vorbei — ohne diesen Zweig antwortete das Relay
           mit „Unknown AI route", und das Diktat ging in der Kachel gar nicht. */
        if (str_ends_with($path, 'transcribe')) {
            $audio = $this->AiStripImage($this->BodyStr($body, 'audio'));
            if ($audio === '') {
                return $this->AiRelayError('invalid_payload', $this->Translate('No audio data.'));
            }
            if (strlen($audio) > self::AI_MAX_AUDIO_B64) {
                return $this->AiRelayError('invalid_payload', $this->Translate('Recording too long.'));
            }
            $bytes = base64_decode($audio, true);
            if (!is_string($bytes) || $bytes === '') {
                return $this->AiRelayError('invalid_payload', $this->Translate('No audio data.'));
            }
            $mime = trim($this->BodyStr($body, 'mime'));
            if ($this->AiKachelAuftrag($sdwa, $txn)) {
                return $this->AiRelayAuftrag('transcribe', $sdwa, $txn,
                    ['system' => '', 'user' => '', 'payloadKind' => 'audio',
                     'mime' => $mime !== '' ? $mime : 'audio/webm', 'url' => ''],
                    ['type' => 'text', 'arten' => []],
                    $bytes);
            }
            $r = $this->AiTranscribe($bytes, $mime !== '' ? $mime : 'audio/webm');
            if (($r['ok'] ?? false) !== true) {
                return $this->AiRelayError((string)($r['code'] ?? 'ai_failed'), (string)($r['message'] ?? 'AI error'));
            }
            $this->MailCountDay();
            return json_encode(['ok' => true, 'text' => (string)$r['text']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $this->AiRelayError('unknown_route', 'Unknown AI route');
    }

    private function AiRelayError(string $code, string $message): string
    {
        return json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /v1/ai/transcribe — ein Diktat in Text wandeln.
     *
     * Bewusst NUR Transkription: das Zerlegen in Artikel, Termine und Aufgaben
     * macht danach der vorhandene Extract-Weg mit seinem Review-Overlay. So
     * kann die Oberflaeche das Transkript vor der Analyse noch korrigieren.
     */
    private function HandleAiTranscribe(array $device): void
    {
        if (!$this->AiIsEnabled() || !$this->AiRateLimitOk($device) || !$this->AiDayBudgetOk()) {
            return;
        }
        $body  = $this->ReadJsonBody();
        $audio = $this->AiStripImage($this->BodyStr($body, 'audio'));   // schneidet nur den data:-Kopf ab
        $mime  = trim($this->BodyStr($body, 'mime'));
        if ($audio === '') {
            $this->SendApiError('invalid_payload', $this->Translate('No audio data.'), 422);
            return;
        }
        if (strlen($audio) > self::AI_MAX_AUDIO_B64) {
            $this->SendApiError('invalid_payload', $this->Translate('Recording too long.'), 413);
            return;
        }
        $bytes = base64_decode($audio, true);
        if (!is_string($bytes) || $bytes === '') {
            $this->SendApiError('invalid_payload', $this->Translate('No audio data.'), 422);
            return;
        }
        if ($this->AiJobWeg($body)) {
            /* Die Tonaufnahme reist als ROHE Bytes in die Nutzlast, nicht als
               Base64: sie ist schon dekodiert, und ein zweites Mal zu kodieren
               kostete ein Drittel mehr Platz auf der Platte. */
            $this->AiJobStarten('transcribe',
                ['system' => '', 'user' => '', 'payloadKind' => 'audio',
                 'mime' => $mime !== '' ? $mime : 'audio/webm', 'url' => ''],
                ['type' => 'text', 'arten' => []],
                $device, $bytes);
            return;
        }

        $result = $this->AiTranscribe($bytes, $mime !== '' ? $mime : 'audio/webm');
        if (!($result['ok'] ?? false)) {
            $this->SendApiError((string)($result['code'] ?? 'ai_failed'), (string)($result['message'] ?? ''), (int)($result['status'] ?? 502));
            return;
        }
        $this->SendJson(['ok' => true, 'text' => (string)$result['text']]);
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

    /**
     * Kategorie „Rezeptfotos" unterhalb der EIGENEN Instanz (einmal anlegen, dann gemerkt).
     *
     * Sie lag bis hierher unter der SymDoWebApp-Instanz. Das war die falsche
     * Stelle: angelegt werden die Fotos hier, geloescht wuerde die Kategorie mit
     * einer fremden Instanz — und wer das Gateway entfernt, liesse sie samt
     * Fotos verwaist stehen. Symcon raeumt Kinder mit der Instanz weg, also
     * gehoeren sie unter die Instanz, die sie erzeugt.
     *
     * Bestand wandert mit: zeigt das Attribut auf eine Kategorie mit fremdem
     * Elternteil, wird sie umgehaengt. Die Medienobjekte gehen dabei mit, es
     * geht nichts verloren.
     */
    private function AiRecipePhotoCategory(): int
    {
        $catId = $this->AiRecipePhotoMigrate();
        if ($catId > 0) {
            return $catId;
        }
        $catId = IPS_CreateCategory();
        IPS_SetParent($catId, $this->InstanceID);
        IPS_SetName($catId, 'Rezeptfotos');
        $this->WriteAttributeString('RecipePhotoCategory', (string)$catId);
        return $catId;
    }

    /**
     * Die vorhandene Kategorie finden und, falls noetig, unter die eigene
     * Instanz holen. Legt NIE eine an — deshalb kann sie auch aus ApplyChanges
     * gerufen werden, ohne auf Anlagen ohne Rezeptanalyse eine leere Kategorie
     * zu hinterlassen.
     *
     * @return int 0, wenn es keine gibt
     */
    private function AiRecipePhotoMigrate(): int
    {
        $catId = (int)$this->ReadAttributeString('RecipePhotoCategory');
        if ($catId > 0 && IPS_CategoryExists($catId)) {
            if ((int)IPS_GetObject($catId)['ParentID'] !== $this->InstanceID) {
                @IPS_SetParent($catId, $this->InstanceID);
            }
            return $catId;
        }
        /* Gesucht wird an drei Stellen, in dieser Reihenfolge:
             1. unter der eigenen Instanz,
             2. unter einem ANDEREN Gateway — die App-Seite bedient immer die
                Instanz mit der niedrigsten ID (OwnsAppApi), und Symcon vergibt
                IDs zufaellig. Ein spaeter angelegtes Gateway kann die Rolle also
                uebernehmen; dann muss die Kategorie mitwandern, sonst legte der
                neue Eigentuemer eine zweite an und die alte haetten wir beim
                Loeschen der alten Instanz samt Fotos verloren,
             3. unter der Web-App — dort lag sie bis Version 3.0. */
        $orte = [$this->InstanceID];
        foreach (IPS_GetInstanceListByModuleID(IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID']) as $gw) {
            if ((int)$gw !== $this->InstanceID) {
                $orte[] = (int)$gw;
            }
        }
        foreach (IPS_GetInstanceListByModuleID(self::SDWA_MODULE_GUID) as $sdwa) {
            $orte[] = (int)$sdwa;
        }
        foreach ($orte as $ort) {
            foreach (IPS_GetChildrenIDs($ort) as $child) {
                if (!IPS_CategoryExists($child) || IPS_GetName($child) !== 'Rezeptfotos') {
                    continue;
                }
                if ($ort !== $this->InstanceID) {
                    @IPS_SetParent($child, $this->InstanceID);
                }
                $this->WriteAttributeString('RecipePhotoCategory', (string)$child);
                return $child;
            }
        }
        return 0;
    }

    /**
     * Seitenverhaeltnis erhalten, laengste Kante begrenzen, als JPEG ausgeben.
     *
     * ScaleAvatar taugt hier NICHT — das schneidet quadratisch mittig zu; bei einem
     * abfotografierten Rezept oder Dokument waeren Kopf und Fuss weg. Ohne GD gibt
     * es null; der Aufrufer muss dann selbst entscheiden.
     */
    private function AiScaleImage(string $binaer, int $maxKante = self::AI_MEDIA_EDGE): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        // Kantenmasse ZUERST, ohne das Bild zu laden. GD braucht je Pixel vier Byte,
        // Symcons php.ini erlaubt 32 MB: ein 12-MP-Foto (4032x3024) will rund 48 MB
        // und ist damit ein „Allowed memory size exhausted" — ein FATAL, den das
        // try/catch unten NICHT faengt. Der Weg ist erreichbar: AiReadMedia ruft
        // hier den Altbestand auf, also die Dateien, die vor der Groessenbegrenzung
        // unskaliert abgelegt wurden.
        if (function_exists('getimagesizefromstring')) {
            $mass = @getimagesizefromstring($binaer);
            if (is_array($mass) && (int)($mass[0] ?? 0) > 0 && (int)($mass[1] ?? 0) > 0) {
                if ((int)$mass[0] * (int)$mass[1] > self::AI_MAX_PIXEL) {
                    return null;
                }
            }
        }
        // Und trotzdem Luft geben: auch ein erlaubtes Bild kostet in GD ein
        // Vielfaches seiner Dateigroesse. Zurueckgesetzt wird in jedem Fall.
        $speicherVorher = (string)@ini_get('memory_limit');
        @ini_set('memory_limit', '192M');
        try {
            return $this->AiScaleImageGd($binaer, $maxKante);
        } finally {
            @ini_set('memory_limit', $speicherVorher);
        }
    }

    /** Der eigentliche GD-Teil. Nur aus AiScaleImage heraus aufrufen (Masspruefung dort). */
    private function AiScaleImageGd(string $binaer, int $maxKante): ?string
    {
        $bild = @imagecreatefromstring($binaer);
        if ($bild === false) {
            return null;
        }
        try {
            $b = imagesx($bild);
            $h = imagesy($bild);
            $lang = max($b, $h);
            if ($lang > $maxKante) {
                $f = $maxKante / $lang;
                $neu = imagescale($bild, max(1, (int)round($b * $f)), max(1, (int)round($h * $f)));
                if ($neu !== false) {
                    $bild = $neu;
                }
            }
            ob_start();
            imagejpeg($bild, null, self::AI_MEDIA_QUALITY);
            $aus = (string)ob_get_clean();
            return $aus !== '' ? $aus : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Ein Bild so weit verkleinern, dass sein Base64 durch eine JSON-Antwort passt.
     *
     * Noetig, weil die abgelegte Datei bewusst hoeher aufgeloest bleibt (die
     * Datei-Route braucht kein Base64), die data:-URL aber um ein Drittel aufblaeht.
     * Ein Foto soll auf JEDEM Weg ankommen — deshalb absteigende Kanten statt einer
     * Absage. Die Reihe ist kurz und endet; bleibt es danach zu gross, ist es kein
     * Bild, mit dem sich etwas anfangen laesst.
     *
     * @return string|null Rohdaten, die passen — oder null
     */
    private function AiFitImageForRelay(string $roh, int $maxB64): ?string
    {
        if (strlen(base64_encode($roh)) <= $maxB64) {
            return $roh;
        }
        foreach ([1200, 900, 700, 500] as $kante) {
            $klein = $this->AiScaleImage($roh, $kante);
            if ($klein !== null && strlen(base64_encode($klein)) <= $maxB64) {
                return $klein;
            }
        }
        return null;
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
        // Nur ablegen, was sich auch wieder abrufen laesst. Vorher landete ein 3-MB-PDF
        // im Objektbaum und „Rezept oeffnen" brach danach fuer immer ab.
        if ($isPdf) {
            if (strlen($raw) > $this->OutputLimit()) {
                return ['ok' => false, 'error' => ['code' => 'file_too_large',
                    'message' => $this->Translate('This PDF is too large to be stored and reopened here.')]];
            }
        } else {
            // ZWEI Schritte, und die Reihenfolge ist der Punkt:
            //  1. Auf AI_MEDIA_EDGE normalisieren — das ist die Auflösung, in der
            //     abgelegt werden SOLL. (Nur AiFitImageForRelay zu nehmen waere
            //     falsch: es fragt bloss „passt es?" und liefert entweder das
            //     unskalierte Original oder eine unnoetig kleine Fassung.)
            //  2. Danach pruefen, ob das Ergebnis auch abrufbar ist. AiScaleImage
            //     allein verkleinert nur bei Kante > 1600 — ein 1600er Scan wurde
            //     also bloss neu kodiert und konnte weiter ueber der Ausgabegrenze
            //     liegen. Dann waere die Datei abgelegt und nie wieder abrufbar,
            //     genau was dieser Riegel verhindern soll.
            $klein = $this->AiScaleImage($raw);
            if ($klein !== null) {
                $raw = $klein;
            }
            if (strlen($raw) > $this->OutputLimit()) {
                $passt = $this->AiFitImageForRelay($raw, $this->OutputLimit());
                if ($passt === null) {
                    return ['ok' => false, 'error' => ['code' => 'file_too_large',
                        'message' => $this->Translate('This photo is too large to be stored and reopened here.')]];
                }
                $raw = $passt;
            }
            $base64 = base64_encode($raw);
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
        // Auf 80 Zeichen wie NotesSaveAttachment — ein Client kann sonst
        // Objekte mit beliebig langem Namen im Objektbaum anlegen.
        $name = mb_substr(trim($name), 0, 80);
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
        $isPdf   = is_array($media) && ((int)($media['MediaType'] ?? MEDIATYPE_IMAGE) === MEDIATYPE_DOCUMENT);
        $content = '';
        if ($withContent) {
            $content = @IPS_GetMediaContent($mediaId);
            if (!is_string($content) || $content === '') {
                return ['ok' => false, 'error' => ['code' => 'empty', 'message' => 'Empty media']];
            }
            // Bestand heilen: Vor der Groessenbegrenzung in AiSaveMedia konnten hier
            // beliebig grosse Dateien liegen, und die Hook-Ausgabe bricht bei 1 MB ab.
            // Ein Bild wird deshalb beim Abruf verkleinert; ein PDF laesst sich nicht
            // verkleinern und bekommt eine ehrliche Absage statt einer abgeschnittenen
            // Antwort, die als kaputte Datei ankommt.
            $roh = base64_decode($content, true);
            if (is_string($roh) && strlen($roh) > $this->OutputLimit()) {
                // Wieder AiFitImageForRelay: nach einem AiScaleImage allein war NICHT
                // geprueft, ob das Ergebnis nun unter der Ausgabegrenze liegt — und
                // Symcon ERSETZT die Antwort, sobald sie darueber geht. Der Client
                // bekaeme eine kaputte Datei bei HTTP 200.
                $klein = $isPdf ? null : $this->AiFitImageForRelay($roh, $this->OutputLimit());
                if ($klein !== null) {
                    $content = base64_encode($klein);
                } else {
                    return ['ok' => false, 'error' => ['code' => 'file_too_large',
                        'message' => $this->Translate($isPdf
                            ? 'This PDF is too large to be reopened here. Open it from its original source.'
                            : 'This photo is too large to be reopened here.')]];
                }
            }
        }
        return [
            'ok'      => true,
            'base64'  => $content,
            'isPdf'   => $isPdf,
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
        // Die data:-URL reist als JSON — entweder durch den Hook (1-MB-Grenze) oder
        // ueber den Kachel-Relay. Base64 blaeht um ein Drittel auf, die Grenze ist
        // hier also eine andere als bei der Rohdatei. Ein Bild wird dafuer weiter
        // verkleinert (es soll auf jedem Weg ankommen), ein PDF laesst sich nicht
        // verkleinern und bekommt eine Absage — die Web-App holt es dann ueber die
        // Datei-Route, die ohne Base64 auskommt.
        if (strlen((string)$m['base64']) > $this->RelayLimitB64()) {
            $roh   = base64_decode((string)$m['base64'], true);
            $passt = ($m['isPdf'] || !is_string($roh))
                ? null
                : $this->AiFitImageForRelay($roh, $this->RelayLimitB64());
            if ($passt === null) {
                return ['ok' => false, 'error' => ['code' => 'too_large_for_tile',
                    'message' => $this->Translate('Too large to show here — open it in the web app.')]];
            }
            $m['base64'] = base64_encode($passt);
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
            // „Zu gross" ist kein „nicht gefunden": die Datei ist da, sie passt nur
            // nicht durch die Ausgabe. 413 sagt das, 404 schickt auf die falsche Suche.
            $status = match ((string)$err['code']) {
                'forbidden'      => 403,
                'file_too_large' => 413,
                default          => 404,
            };
            $this->SendApiError((string)$err['code'], (string)$err['message'], $status);
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
    private function AiFileNameParams(string $name, bool $isPdf, string $rueckfall = 'Rezept'): string
    {
        $ext   = $isPdf ? '.pdf' : '.jpg';
        $clean = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[^\p{L}\p{N} ._-]+/u', ' ', $name)));
        if ($clean === '') {
            $clean = $rueckfall;
        }
        $clean = mb_substr($clean, 0, 80);
        $ascii = trim((string)preg_replace('/_+/', '_', (string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $clean)), '_');
        if ($ascii === '') {
            $ascii = 'datei';
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

    /**
     * Der Prompt fuer die Zutatenwege — wieder fuer beide Wege derselbe.
     *
     * Die Adresse ist der Sonderfall: ihr Text steht beim Bauen noch gar nicht
     * fest, denn die Seite wird erst geholt. Der Nutzer-Teil endet deshalb
     * offen, und wer die Seite holt, haengt ihren Text an.
     *
     * @param list<string> $kategorien
     * @return array{system:string,user:string}
     */
    private function AiIngredientsAuftrag(string $weg, array $kategorien): array
    {
        if ($weg === 'url') {
            return ['system' => $this->AiRecipeSystemPrompt($kategorien),
                    'user'   => "Extrahiere Titel, Portionen und die Zutatenliste aus diesem Rezept:\n\n"];
        }
        return ['system' => $this->AiIngredientsSystemPrompt($kategorien),
                'user'   => $weg === 'pdf'
                    ? 'Extrahiere die Artikel bzw. Zutaten aus dieser Datei.'
                    : 'Extrahiere die Artikel bzw. Zutaten aus diesem Bild.'];
    }

    // ──────────────── Der zweite Weg: einreihen statt warten ────────────────

    /**
     * Soll dieser Aufruf eingereiht werden?
     *
     * Zwei Bedingungen, und beide muessen stimmen. Der Aufrufer muss es
     * WOLLEN — die schon installierte App wuerde eine 202-Antwort als „darin
     * war nichts zu finden" deuten. Und es muss jemanden geben, der die
     * Schlange abarbeitet; sonst laege der Auftrag nur herum, und synchron
     * antworten ist immer noch besser als gar nicht.
     *
     * @param array<string,mixed> $body
     */
    private function AiJobWeg(array $body): bool
    {
        return ($body['async'] ?? false) === true && $this->AiJobMoeglich();
    }

    /**
     * Einreihen und mit 202 antworten.
     *
     * Eine fehlende Einrichtung wird VORHER abgefangen: einen Auftrag
     * einzureihen, der nie gelingen kann, hiesse den Nutzer zwei Minuten auf
     * eine Absage warten zu lassen, die sofort feststand.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $parse
     * @param array<string,mixed> $device
     */
    private function AiJobStarten(string $kind, array $job, array $parse, array $device, string $nutzlast): void
    {
        if ($kind !== 'transcribe') {
            // Das Diktat hat seinen eigenen Anbieter-Weg (OpenAI oder lokal) und
            // prueft selbst; der Chat-Anbieter sagt darueber nichts.
            $grund = $this->AiAnbieter()->konfigurationsfehler();
            if ($grund !== null) {
                $meldung = $this->AiErrorMessage('ai_not_configured', $grund);
                $this->SendApiError((string)$meldung['code'], (string)$meldung['message'], (int)$meldung['status']);
                return;
            }
        }
        $r = $this->AiJobEnqueue($kind, $job, $parse, ['type' => 'rest'],
            $nutzlast, (string)($device['id'] ?? ''));
        if (($r['ok'] ?? false) !== true) {
            $this->SendApiError((string)($r['code'] ?? 'internal'),
                (string)($r['message'] ?? ''), (int)($r['status'] ?? 500));
            return;
        }
        $this->AiJobAccept((string)$r['id']);
    }

    /** @return array ok:true+title+servings+items | ok:false+code+message+status */
    private function AiExtractIngredientsFromUrl(string $url, array $erlaubteKategorien = []): array
    {
        // Rueckfallweg ohne `async`: der Hook holt selbst und darf deshalb nur
        // kurz warten. Mit `async` holt der Laeufer — der nimmt sich die vollen 15.
        $page = $this->AiFetchPublicPage($url, AiRecipePage::GET_TIMEOUT_HOOK);
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

    // ────────────────────────────── Gerichtsbild (Essensplan) ──────────────────────────────

    /**
     * Erzeugt ein Gerichtsbild im festen Essensplan-Stil: angerichteter Teller
     * streng von oben, transparenter Hintergrund. Nur OpenAI kann Bilder, der
     * Key wird deshalb direkt gelesen — der Chat-Anbieter darf ein anderer sein.
     * @return array ok:true+image(base64-PNG) | ok:false+code+message+status
     */
    private function AiGenerateDishImage(string $name, array $zutaten): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'code' => 'invalid_payload', 'message' => $this->Translate('No dish name provided.'), 'status' => 400];
        }
        $key = trim((string) $this->AiProp('AiOpenAIKey'));
        if ($key === '') {
            return ['ok' => false, 'code' => 'ai_not_configured', 'message' => $this->Translate('No OpenAI API key configured.'), 'status' => 503];
        }

        // Dieselbe Sperre wie die Chat-Aufrufe: nur EIN Anbieter-Aufruf zugleich,
        // sonst blockieren parallele Requests den Symcon-Webserver.
        $lock = 'TGW_Ai_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            return ['ok' => false, 'code' => 'ai_busy', 'message' => $this->Translate('Another AI request is already running.'), 'status' => 429];
        }
        try {
            $resp = $this->AiHttpPost(
                'https://api.openai.com/v1/images/generations',
                ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
                (string)json_encode([
                    'model'         => self::AI_IMAGE_MODEL,
                    'prompt'        => $this->AiDishImagePrompt($name, $zutaten),
                    'size'          => '1024x1024',
                    'quality'       => 'medium',
                    'background'    => 'transparent',
                    'output_format' => 'png',
                ], JSON_UNESCAPED_UNICODE),
                self::AI_IMAGE_TIMEOUT
            );
        } finally {
            IPS_SemaphoreLeave($lock);
        }
        return $this->AiParseImageResponse($resp);
    }

    /**
     * Der feste Stil-Prompt — dieselbe Schule wie die Produktbilder der
     * Einkaufsliste (tools/generate-images.mjs), aber als fertiges Gericht:
     * ein passender Teller streng von oben, freigestellt. Die Zutaten geben
     * dem Modell nur Kontext, damit das Gericht plausibel aussieht.
     */
    private function AiDishImagePrompt(string $name, array $zutaten): string
    {
        $prompt = 'Erstelle folgendes Bild: ' . mb_substr($name, 0, 120);
        $liste  = implode(', ', array_slice($zutaten, 0, 30));
        if ($liste !== '') {
            $prompt .= ', prepared from ' . mb_substr($liste, 0, 600);
        }
        // Bewusst KEINE Vorgabe zu Gefaess und Kamerawinkel: eine Suppe gehoert
        // in eine Schale, ein Auflauf in die Form, eine Pizza aufs Brett — und
        // manches Gericht sieht schraeg von der Seite schlicht besser aus.
        // Einheitlich bleiben Anmutung, Licht und der freigestellte Grund.
        return $prompt . ', premium 3D food render, served as a complete finished dish'
            . ' in whichever vessel suits it best (plate, bowl, baking dish, pan,'
            . ' board or glass), shown from a flattering angle for this dish,'
            . ' slightly idealized but believable, isolated, transparent background,'
            . ' centered, soft refined studio lighting, fresh appetizing appearance,'
            . ' realistic proportions, high detail, single serving only, no cutlery,'
            . ' no table, no napkin, no hands, no text, no logo, no watermark,'
            . ' not cartoonish';
    }

    /**
     * Antwort der Bild-API auswerten — eigene Methode, damit der Pruefstand die
     * Fehler-Zuordnung ohne echten Anbieter messen kann.
     * @param array $resp ['status','body','err'] aus AiHttpPost
     * @return array ok:true+image | ok:false+code+message+status
     */
    private function AiParseImageResponse(array $resp): array
    {
        if (($resp['err'] ?? '') !== '') {
            return ['ok' => false, 'code' => 'ai_unreachable', 'message' => $this->Translate('Could not reach the AI service.'), 'status' => 502];
        }
        $status = (int)($resp['status'] ?? 0);
        $daten  = json_decode((string)($resp['body'] ?? ''), true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($daten) ? trim((string)($daten['error']['message'] ?? '')) : '';
            /* 429 heisst beim Anbieter ZWEIERLEI: „zu schnell" (wartet kurz)
               oder „kein Guthaben" (wartet ewig). Beides „Rate-Limit" zu
               nennen schickt auf die falsche Faehrte — genau das ist am
               04.09.2026 passiert. Der Anbieter nennt den Unterschied im
               Feld `code`. */
            $code   = match (true) {
                $status === 401 || $status === 403 => 'ai_unauthorized',
                AiProvider::keinGuthaben((string)($resp['body'] ?? '')) => 'ai_no_credit',
                $status === 429                    => 'ai_rate_limited',
                default                            => 'ai_upstream',
            };
            $this->SendDebug('AiDishImage', sprintf('HTTP %d: %s', $status, mb_substr($detail, 0, 300)), 0);
            return ['ok' => false, 'code' => $code,
                'message' => $detail !== '' ? mb_substr($detail, 0, 200) : $this->Translate('AI provider returned an error.'),
                'status'  => $status];
        }
        $b64 = is_array($daten) ? (string)($daten['data'][0]['b64_json'] ?? '') : '';
        $roh = base64_decode($b64, true);
        if (!is_string($roh) || !str_starts_with($roh, "\x89PNG")) {
            return ['ok' => false, 'code' => 'ai_bad_response', 'message' => $this->Translate('AI provider returned no image.'), 'status' => 502];
        }
        return ['ok' => true, 'image' => $b64];
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

    // ──────────────── Durchreichen auf die symcon-freien Klassen ────────────────
    //
    // Der WIE-Teil (Rumpf, Kopfzeilen, Modelle, Fristen, das Holen fremder
    // Seiten) steht seit September 2026 in `List/libs/AiProvider.php` und
    // `List/libs/AiRecipePage.php`. Zwei Gruende: ein Laufwerk ausserhalb des
    // Gateways soll ihn benutzen koennen, ohne diese 2000 Zeilen mitzunehmen —
    // und der Rumpf einer Anfrage soll sich pruefen lassen, ohne dass ein
    // Kernel laeuft.
    //
    // Die Griffe hier behalten ihre Signatur, damit keiner der Aufrufer in
    // Briefing, MailScan, NotesAi, EduMaps und Moodle sich aendert. Sie tun nur
    // dreierlei: Konfiguration hineinreichen, Debugzeilen ins Protokoll heben
    // und aus einem Codewort einen Satz machen.

    /** Den Anbieter mit der Konfiguration des Gateways bestuecken. */
    private function AiAnbieter(): AiProvider
    {
        $konfig = [];
        foreach (AiProvider::KONFIG_FELDER as $feld) {
            $konfig[$feld] = (string) $this->AiProp($feld);
        }
        return AiProvider::ausKonfiguration($konfig);
    }

    /** @return array ok:true+text | ok:false+code+message+status */
    private function AiRunProviderCall(string $system, string $userText, ?string $imageBase64, ?string $pdfBase64 = null): array
    {
        $anbieter = $this->AiAnbieter();
        $antwort  = $anbieter->complete($system, $userText, $imageBase64, $pdfBase64);
        $this->AiDebugUebernehmen($anbieter);
        return $this->AiAntwortDeuten($antwort);
    }

    /** @return array ok:true+text | ok:false+code+message+status */
    private function AiTranscribe(string $bytes, string $mime): array
    {
        $anbieter = $this->AiAnbieter();
        $antwort  = $anbieter->transcribe($bytes, $mime);
        $this->AiDebugUebernehmen($anbieter);
        return $this->AiAntwortDeuten($antwort);
    }

    /** Was dem Anbieter unterwegs aufgefallen ist, gehoert ins Protokoll. */
    private function AiDebugUebernehmen(AiProvider $anbieter): void
    {
        foreach ($anbieter->debugZeilen() as $zeile) {
            $this->SendDebug('AI', $zeile, 0);
        }
    }

    /**
     * Aus `{ok:false, code, grund, detail}` die Antwort, die die Aufrufer
     * kennen: mit Satz und HTTP-Status.
     *
     * @param array<string,mixed> $antwort
     * @return array<string,mixed>
     */
    private function AiAntwortDeuten(array $antwort): array
    {
        if (($antwort['ok'] ?? false) === true) {
            return $antwort;
        }
        return $this->AiErrorMessage(
            (string)($antwort['code'] ?? 'ai_failed'),
            (string)($antwort['grund'] ?? ''),
            (string)($antwort['detail'] ?? ''));
    }

    /**
     * Aus einem Codewort ein Satz — die EINE Stelle, an der das geschieht.
     *
     * `grund` unterscheidet mehrere Saetze unter demselben Code (es gibt vier
     * verschiedene „nicht eingerichtet"), `detail` traegt das Bewegliche.
     * Alle Texte stehen woertlich so in der locale.json wie vorher; wer hier
     * einen aendert, aendert ihn fuer die App, die Web-App UND die Kachel.
     *
     * @return array{ok:false,code:string,message:string,status:int}
     */
    private function AiErrorMessage(string $code, string $grund = '', string $detail = ''): array
    {
        $status = 502;
        switch ($code) {
            case 'ai_not_configured':
                $status = 400;
                $text = match ($grund) {
                    'anthropic' => $this->Translate('No Anthropic API key configured.'),
                    'openai'    => $this->Translate('No OpenAI API key configured.'),
                    'local'     => $this->Translate('Local server URL and model must be configured.'),
                    'diktat'    => $this->Translate('Dictation needs an OpenAI API key or a local AI server with transcription.'),
                    default     => $this->Translate('No AI provider configured.'),
                };
                break;
            case 'ai_pdf_unsupported':
                $status = 400;
                $text   = $this->Translate('PDF is not supported by this AI provider.');
                break;
            case 'ai_unreachable':
                $text = $this->Translate('Could not reach the AI service.') . ' ' . $detail;
                break;
            case 'ai_unauthorized':
                $text = $this->Translate('AI rejected the API key.');
                break;
            case 'ai_no_credit':
                $text = $this->Translate('No credit left at the AI provider — top up the account.');
                break;
            case 'ai_rate_limited':
                $text = $this->Translate('AI rate limit reached — try again later.');
                break;
            case 'ai_upstream':
                $text = $this->Translate('AI request failed.') . ' (HTTP ' . $detail . ')';
                break;
            case 'ai_bad_response':
                $text = $this->Translate('Unexpected AI response.');
                break;
            case 'ai_truncated':
                $text = $this->Translate('The AI answer was cut off — try a smaller document.');
                break;
            case 'ai_empty':
                $text = $this->Translate('The AI returned an empty answer.');
                break;
            case 'ai_failed':
                $text = $grund === 'leer'
                    ? $this->Translate('Transcription came back empty.')
                    : $this->Translate('Transcription failed.') . ' ' . $detail;
                break;
            case 'invalid_url':
                $status = 422;
                $text   = $this->Translate('Invalid or non-public URL.');
                break;
            case 'ai_url_fetch':
                $text = $this->Translate('Could not load the page.')
                      . ($detail !== '' ? ' (HTTP ' . $detail . ')' : '');
                break;
            default:
                $text = $this->Translate('AI request failed.');
        }
        return ['ok' => false, 'code' => $code, 'message' => $text, 'status' => $status];
    }

    /**
     * Eine oeffentliche Seite holen — SSRF-sicher, siehe `AiRecipePage`.
     *
     * Die Frist ist ein GESAMTBUDGET ueber alle Weiterleitungen. Wer im Hook
     * ruft, gibt weniger mit: dort wartet ein Mensch, und die Gateway-Spur
     * bedient waehrenddessen keinen anderen Abruf. Die Scan-Wege
     * (Klassenseiten, Anhaenge) bleiben bei den vollen fuenfzehn Sekunden.
     *
     * @return array ok:true+body | ok:false+code+message+status
     */
    private function AiFetchPublicPage(string $url, int $frist = AiRecipePage::GET_TIMEOUT): array
    {
        $antwort = AiRecipePage::holen($url, $frist);
        return ($antwort['ok'] ?? false) === true ? $antwort : $this->AiAntwortDeuten($antwort);
    }

    /** Schema + oeffentlicher (nicht privater/reservierter) Host? */
    private function AiIsPublicUrl(string $url, ?array &$resolvedIps = null): bool
    {
        return AiRecipePage::istOeffentlich($url, $resolvedIps);
    }

    /** Macht aus Roh-HTML den fuer die KI relevanten Text. */
    private function AiRecipeText(string $html): string
    {
        return AiRecipePage::text($html);
    }

    /** Grob entschlacktes HTML als Fliesstext. */
    private function AiHtmlToText(string $html): string
    {
        return AiRecipePage::htmlZuText($html);
    }

    // ────────────────────────────── Parser ──────────────────────────────

    /** Toleranter Parser: erstes „[" … letztes „]", dann feldweise validieren. */
    private function AiParseTodos(string $text, array $arten = ['task', 'event']): array
    {
        $rows = $this->AiDecodeJsonArray($text);
        if ($rows === []) {
            // „[]" (das Modell sieht nichts) und unlesbares Geschwafel sehen von
            // aussen gleich aus — der Rohtext im Debug unterscheidet beides.
            $this->SendDebug('AI', 'Antwort ohne Eintraege, Rohtext: ' . mb_substr($text, 0, 300), 0);
        }
        return $this->AiValidateTodoRows($rows, $arten);
    }

    /**
     * Feldweise Validierung einer schon dekodierten Liste.
     *
     * Aus AiParseTodos herausgeloest, weil die Notiz-Analyse ihre Eintraege in einem
     * UNTERFELD liefert (dort gibt es keinen Text zum Dekodieren) und die
     * Mailanalyse eine dritte Art zulaesst. Die Vorgabewerte erhalten das bisherige
     * Verhalten Byte fuer Byte.
     *
     * @param string[] $arten erlaubte Werte fuer "kind"
     */
    /** Grosszuegig, aber nicht unbegrenzt: der Vorschlagsbestand ist ein Attribut. */
    private const AI_INFO_MAX = 6000;

    /**
     * Obergrenze fuer GD, in Pixeln. 24 MP deckt jedes Handyfoto ab und kostet in
     * GD rund 96 MB — deshalb steht die Anhebung des memory_limit daneben. Darueber
     * gibt es eine Absage statt eines Abbruchs.
     */
    private const AI_MAX_PIXEL = 24000000;

    /**
     * Ein Feld aus der Modellantwort als Zeichenkette — NIE mit (string) auf einen
     * unbekannten Wert. Liefert das Modell dort ein Array oder Objekt, waere das
     * eine PHP-Warnung in der HTTP-Antwort (siehe BodyStr in ApiRouter).
     */
    private function AiRowStr(array $row, string $key, string $vorgabe = ''): string
    {
        $wert = $row[$key] ?? null;
        if ($wert === null) {
            return $vorgabe;
        }
        return is_scalar($wert) ? (string)$wert : $vorgabe;
    }

    /**
     * Freitextfeld, das auch als LISTE kommen darf: verschachtelte Werte werden zu
     * je einer Zeile. Das ist der Fall, den der Aufzaehlungs-Prompt geradezu
     * einlaedt.
     */
    private function AiRowText(mixed $wert, int $max): string
    {
        if ($wert === null) {
            return '';
        }
        if (is_scalar($wert)) {
            return mb_substr(trim((string)$wert), 0, $max);
        }
        if (!is_array($wert)) {
            return '';
        }
        $zeilen = [];
        array_walk_recursive($wert, static function (mixed $v) use (&$zeilen): void {
            if (is_scalar($v) && trim((string)$v) !== '') {
                $zeilen[] = trim((string)$v);
            }
        });
        return mb_substr(implode("\n", $zeilen), 0, $max);
    }

    private function AiValidateTodoRows(array $rows, array $arten = ['task', 'event']): array
    {
        $out  = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            /* Die ZUSAMMENFASSUNG ist kein Eintrag (22.09.2026). Sie reist seit
               dem 21.09. als zusaetzliches Element {"kind":"summary"} in derselben
               Antwort mit; MailAnalyseCalc::Zusammenfassung liest sie vorher
               heraus. Hier muss sie RAUS — die Weiche unten kennt „summary" nicht
               und machte daraus eine Aufgabe, deren Titel der ganze
               Zusammenfassungssatz war (auf 120 Zeichen abgeschnitten, ohne Info).
               Genau das stand seither in jedem Vorschlag. */
            if (strtolower(trim($this->AiRowStr($row, 'kind'))) === 'summary') {
                continue;
            }
            // Auf NOTE_TITLE_MAX gekappt: ungedeckelt baute die KI aus einem
            // Elternbrief Titel von weit ueber 120 Zeichen, der Editor fuellte sie
            // vor, und jedes Speichern lief in „invalid_payload" — eine Sackgasse,
            // aus der der Nutzer nur durch Kuerzen von Hand herauskam.
            $title = mb_substr(trim($this->AiRowStr($row, 'title')), 0, self::NOTE_TITLE_MAX);
            if ($title === '') {
                continue;
            }
            // „info" darf eine LISTE sein: der Prompt verlangt ausdruecklich, dass
            // Aufzaehlungen vollstaendig hierher kommen — ein Modell antwortet darauf
            // durchaus mit einem JSON-Array. Ein (string)-Cast darauf erzeugte eine
            // PHP-Warnung, und die landet im Hook VOR dem JSON und zerlegt die
            // Antwort (derselbe Grund, aus dem es BodyStr gibt). Deckel dazu: der
            // Vorschlagsbestand ist ein Attribut ohne Byte-Grenze.
            $info = $this->AiRowText($row['info'] ?? null, self::AI_INFO_MAX);
            $info = ($info === '') ? null : $info;

            $due = trim($this->AiRowStr($row, 'due'));
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                // gültiges Kalenderdatum
            } else {
                $due = null;
            }

            $priority = strtolower(trim($this->AiRowStr($row, 'priority', 'normal')));
            if (!in_array($priority, ['high', 'normal', 'low'], true)) {
                $priority = 'normal';
            }

            // Uhrzeit nur, wenn auch ein Datum steht — eine Zeit ohne Tag ist
            // wertlos. Liefert das Modell keine (Foto-Scan), bleibt alles wie bisher
            // und die Aufgabe wird ganztaegig.
            $time = trim($this->AiRowStr($row, 'time'));
            if ($due === null || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) !== 1) {
                $time = null;
            }

            // Aufgabe oder Termin? Eine Aufgabe muss man TUN, ein Termin FINDET STATT.
            // Fehlt das Feld (Foto-Scan, aeltere Antworten), bleibt es eine Aufgabe —
            // damit aendert sich fuer die vorhandenen Wege nichts.
            $kind = strtolower(trim($this->AiRowStr($row, 'kind', 'task')));
            if (!in_array($kind, $arten, true)) {
                $kind = 'task';
            }

            // Ende nur bei Terminen und nur mit Beginn. Es darf nicht vor dem Beginn
            // liegen; ein einzelner Tag braucht kein Ende.
            $end = trim($this->AiRowStr($row, 'end'));
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

            // Erkannte Person → Benutzerkennung. Die Oberflaeche zeigt sie als
            // Avatar am Fund und belegt damit den Editor vor; findet sich kein
            // eindeutiger Benutzer, bleibt es wie bisher beim Anmelder.
            $zugeordnet = $this->AiPersonZuBenutzer($this->AiRowStr($row, 'person'));

            $eintrag = ['title' => $title, 'info' => $info, 'due' => $due, 'time' => $time,
                        'priority' => $priority, 'kind' => $kind, 'end' => $end, 'allDay' => $allDay,
                        'recurrence' => $recurrence, 'assignedTo' => $zugeordnet];
            if ($kind === 'shopping') {
                // Ein Artikel hat eine Menge, aber weder Frist noch Takt.
                $amount = mb_substr(trim($this->AiRowStr($row, 'amount')), 0, 40);
                $eintrag['amount'] = $amount === '' ? null : $amount;
                $eintrag['due'] = null;
                $eintrag['time'] = null;
                $eintrag['end'] = null;
                $eintrag['allDay'] = false;
                $eintrag['recurrence'] = null;
                $eintrag['priority'] = 'normal';
            }
            if ($kind === 'homework') {
                /* Eine Hausaufgabe hat eine Faelligkeit, aber keinen Takt und keine
                   Uhrzeit. Das FACH darf seit dem 22.09.2026 fehlen: ein
                   Lernzeitplan der ersten Klasse nennt Hefte statt Faecher
                   („Buchstabenheft S. 16"), und die Herabstufung zur Aufgabe machte
                   daraus reihenweise Elternaufgaben. Ohne Fach bleibt es eine
                   Hausaufgabe mit leerem Fach — der Dialog fragt es beim Uebernehmen
                   ab (er speichert nicht ohne), und die Oberflaeche kann die Art
                   ohnehin umstellen. */
                $eintrag['subject'] = HomeworkCalc::FachAufloesen(
                    $this->AiRowStr($row, 'subject'), $this->HomeworkFaecher());
                /* Die Aufgabe steht im TITEL, info ist Herkunft oder Erlaeuterung —
                   beides in die Notiz (23.09.2026). Bis dahin stand hier nur info,
                   und das Blatt zeigte „Siehe Rueckseite des Lernzeitplans." statt
                   „Mathetrainer fuer jeden Tag bearbeiten". */
                $eintrag['note'] = HomeworkCalc::NotizAusFund($title, (string)($info ?? ''));
                /* Nur KINDER: ein „Papa" im Text darf keine Hausaufgabe erben.
                   assignedTo bleibt leer, damit der ToDo-Weg sie nicht
                   versehentlich als Aufgabe anlegt. */
                $eintrag['childId'] = $this->HomeworkKindZuBenutzer($this->AiRowStr($row, 'person'));
                $eintrag['assignedTo'] = [];
                $eintrag['time'] = null;
                $eintrag['end'] = null;
                $eintrag['allDay'] = false;
                $eintrag['recurrence'] = null;
                $eintrag['priority'] = 'normal';
            }
            if ($kind === 'note') {
                // Eine Notiz hat keine Frist und keinen Takt — sie hat einen Text.
                // Rueckfall auf "info", weil kleine Modelle die Zusammenfassung
                // dorthin schreiben, wo der Prompt sie bisher haben wollte.
                // NOTE_TEXT_MAX statt einer eigenen Zahl: die 2000 hier blieben beim
                // Anheben der Grenzen stehen, waehrend der Prompt 2700 verlangt und der
                // Bestand 3000 traegt — 700 Zeichen wurden mitten im Satz still
                // verworfen, genau die Aufzaehlungen, um die es ging.
                $eintrag['text'] = mb_substr(
                    trim($this->AiRowText($row['text'] ?? ($row['info'] ?? null), self::NOTE_TEXT_MAX)),
                    0,
                    self::NOTE_TEXT_MAX
                );
                $eintrag['due'] = null;
                $eintrag['time'] = null;
                $eintrag['end'] = null;
                $eintrag['allDay'] = false;
                $eintrag['recurrence'] = null;
                $eintrag['priority'] = 'normal';
            }
            $out[] = $eintrag;
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
            if (count($out) >= AiRecipePage::MAX_INGREDIENTS) {
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
        $roh  = substr($text, $start, $end - $start + 1);
        $rows = json_decode($roh, true);
        if (is_array($rows)) {
            return $rows;
        }
        // Zweiter Versuch mit ausgebesserten Zeilenumbruechen. Modelle setzen in einen
        // laengeren Wert regelmaessig ECHTE Umbrueche, und die sind in einem
        // JSON-String unzulaessig — json_decode gibt dann null, und die ganze Antwort
        // ist verloren, obwohl nur ein Feld unsauber ist. Vorher stand im Protokoll
        // „0 Aufgabe(n)", waehrend beim Anbieter das Geld schon weg war.
        $rows = json_decode($this->AiRepairJsonStrings($roh), true);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Rohe Steuerzeichen INNERHALB von JSON-Strings maskieren.
     *
     * Laeuft zeichenweise mit einem Zustand „stehe ich in einem String" — ausserhalb
     * bleibt alles unberuehrt, damit die Struktur nicht angetastet wird. Der
     * Rueckstrich-Zaehler ist noetig, weil ein maskierter Rueckstrich (\\) vor einem
     * Anfuehrungszeichen dieses NICHT maskiert.
     */
    private function AiRepairJsonStrings(string $json): string
    {
        $aus = '';
        $inStr = false;
        $len = strlen($json);
        for ($i = 0; $i < $len; $i++) {
            $c = $json[$i];
            if (!$inStr) {
                $aus .= $c;
                if ($c === '"') {
                    $inStr = true;
                }
                continue;
            }
            if ($c === '"') {
                $rueck = 0;
                for ($j = $i - 1; $j >= 0 && $json[$j] === '\\'; $j--) {
                    $rueck++;
                }
                if ($rueck % 2 === 0) {
                    $inStr = false;
                }
                $aus .= $c;
                continue;
            }
            if ($c === "\n") {
                $aus .= '\\n';
            } elseif ($c === "\r") {
                $aus .= '\\r';
            } elseif ($c === "\t") {
                $aus .= '\\t';
            } else {
                $aus .= $c;
            }
        }
        return $aus;
    }

    // ────────────────────────────── HTTP ──────────────────────────────

    /**
     * Durchreiche auf `AiHttp::post`. Der Rumpf ist nach `List/libs/AiHttp.php`
     * gezogen, damit der Handbuch-Bau ihn in der Scanner-Instanz benutzen kann,
     * ohne diese ganze Datei mitzunehmen. Die Signatur bleibt, damit keiner der
     * dreizehn Aufrufer sich aendert.
     */
    private function AiHttpPost(string $url, array $headers, string $bodyJson, int $timeout = AiProvider::TIMEOUT): array
    {
        return AiHttp::post($url, $headers, $bodyJson, $timeout, AiProvider::CONNECT_TIMEOUT);
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

    /**
     * Der Tagesdeckel — NUR fuer Wege, die beim Anbieter wirklich Geld kosten.
     *
     * Bewusst getrennt von AiRateLimitOk: das gleitende Stundenfenster schuetzt auch
     * Endpunkte, die bloss ein Medienobjekt ablegen (HandleAiSavePhoto). Deren
     * Sperre am KI-Budget waere falsch — ein Foto zu speichern kostet nichts.
     *
     * false = die Absage ist schon gesendet.
     */
    private function AiDayBudgetOk(): bool
    {
        if (!$this->MailDayLimitReached()) {
            return true;
        }
        $this->SendApiError('ai_quota', $this->Translate('Daily limit for AI calls reached.'), 429);
        return false;
    }

    private function AiIsEnabled(): bool
    {
        if ((bool) $this->AiProp('AiEnabled')) {
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

    /**
     * Nennt dem Modell die Personen des Haushalts, damit es „Friseurtermin <Name>"
     * der richtigen zuordnen kann. Ohne bekannte Namen entfaellt der Absatz —
     * dann gibt es nichts zuzuordnen, und das Feld bleibt leer.
     *
     * Bewusst nur die NAMEN, keine Kennungen: Kennungen im Prompt laedt das
     * Modell zum Erfinden ein. Die Zuordnung Name → Benutzer macht der Server
     * danach selbst, und nur bei eindeutigem Treffer.
     */
    private function AiPersonenRegel(): string
    {
        $namen = [];
        foreach ($this->AiHaushaltsNamen() as $name) {
            $namen[] = $name;
        }
        if ($namen === []) {
            return '';
        }
        return 'Zum Haushalt gehoeren: ' . implode(', ', $namen) . '. Nennt ein Eintrag '
            . 'eindeutig eine dieser Personen (z.B. „Friseurtermin <Name>", „<Name> zum Zahnarzt", '
            . '„Turnbeutel fuer <Name>"), setze "person" auf genau diesen Namen. Sonst null. '
            . 'Die Namen in den Beispielen (<Name>) sind Platzhalter — gib sie nicht aus '
            . 'und verwende ausschließlich die oben genannten Haushaltsnamen. '
            . 'Rate nicht und erfinde keine Namen. ';
    }

    /** @return list<string> Namen der Haushaltsmitglieder, leer wenn keine gepflegt sind. */
    private function AiHaushaltsNamen(): array
    {
        $namen = [];
        try {
            foreach ($this->LoadUsers() as $u) {
                $name = trim((string)($u['name'] ?? ''));
                if ($name !== '') {
                    $namen[] = $name;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $namen;
    }

    /**
     * Name aus der Modellantwort → Benutzerkennung. Nur bei GENAU EINEM Treffer;
     * zwei „<Name>" im Haushalt bleiben unzugeordnet, statt die falsche zu waehlen.
     *
     * @return list<string>
     */
    private function AiPersonZuBenutzer(string $name): array
    {
        $suche = mb_strtolower(trim($name));
        if ($suche === '') {
            return [];
        }
        $treffer = [];
        try {
            foreach ($this->LoadUsers() as $u) {
                if (mb_strtolower(trim((string)($u['name'] ?? ''))) === $suche) {
                    $treffer[] = (string)($u['id'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        $treffer = array_values(array_filter($treffer, static fn(string $id): bool => $id !== ''));
        return count($treffer) === 1 ? $treffer : [];
    }

    private function AiSystemPrompt(string $today): string
    {
        // Die vierte Art nur anbieten, wenn es Kinder gibt — sonst entstehen
        // Hausaufgaben, die niemandem gehoeren.
        $mitHausaufgaben = $this->HomeworkKinder() !== [];
        return 'Du extrahierst Aufgaben (ToDos) aus Dokumenten: Briefe, Behörden- und Bankschreiben, '
            . 'Rechnungen, Notizen, Listen, E-Mails oder Fotos davon. Wichtig: Aufgaben stehen oft NICHT '
            . 'als Liste im Dokument, sondern stecken implizit in Handlungsaufforderungen — z.B. „bitte '
            . 'bestätigen Sie…“, „senden Sie das Formular zurück“, „überweisen Sie bis…“, „vereinbaren Sie '
            . 'einen Termin“. Leite daraus die Aufgabe ab, die der Empfänger erledigen muss, aus dessen '
            . 'Sicht formuliert (kurzer, prägnanter deutscher Titel, z.B. „Daten beim Absender '
            . 'bestätigen“). Drohende Konsequenzen (Sperrung, Mahnung, Frist) → priority "high". Nutze '
            . '"info" für den wichtigsten Kontext (Absender, Referenz, Konsequenz, geforderter Weg). '
            // Gemeldet am 21.08.2026: bei „Mitbringsel fuer den Opti-Kurs vorbereiten"
            // fehlten die meisten Gegenstaende. Kein Wunder — „wichtigster Kontext"
            // liest sich als Auftrag zum Zusammenfassen, und eine Materialliste ist
            // genau das Gegenteil: Wer sie zusammenfasst, macht sie unbrauchbar.
            // Serverseitig wird "info" nirgends gekuerzt, die Luecke entstand also
            // ausschliesslich hier im Prompt.
            . 'AUFZAEHLUNGEN GEHOEREN VOLLSTAENDIG IN "info": Nennt das Dokument, was '
            . 'MITZUBRINGEN, einzureichen, auszufuellen, vorzubereiten oder zu besorgen '
            . 'ist (Mitbringsel, Materialliste, Ausruestung, Unterlagen, Checkliste, '
            . 'Zutaten), dann uebernimm JEDEN EINZELNEN PUNKT wortgetreu — kein '
            . 'Auslassen, kein Zusammenfassen, kein „usw.", „u. a.", „etc." oder '
            . '„unter anderem". Eine gekuerzte Liste ist schlimmer als keine: der '
            . 'Empfaenger merkt am Kurstag, dass die Haelfte fehlt. Schreibe die Punkte '
            . 'als eigene Zeilen, getrennt durch \\n (niemals ein echter Umbruch im '
            . 'JSON-String), mit Mengen- und Groessenangaben, wenn sie dastehen '
            . '(„2 Passfotos", „Schwimmweste Groesse M"). Der wichtigste Kontext steht '
            . 'davor, die Liste darunter. Bei „info" gibt es keine Laengengrenze — '
            . 'Vollstaendigkeit geht hier vor Kuerze. '
            . 'WICHTIG für "due": Enthält das Dokument eine Frist, ein Fälligkeits- oder Zahlungsdatum, '
            . 'einen Termin oder ein Datum, bis zu dem der Empfänger etwas erledigen muss, trage es IMMER '
            . 'in "due" ein (Format YYYY-MM-DD). Rechne relative Angaben ausgehend von heute (' . $today . ') '
            . 'in ein konkretes Datum um — z.B. „innerhalb von 14 Tagen“, „bis Freitag“, „bis Monatsende“, '
            . '„nächste Woche“, „zum 15.03.“; bei einem Zeitraum bzw. einer Frist nimm den letztmöglichen Tag. '
            . 'Nur wenn wirklich kein Datum und keine Frist erkennbar ist, setze "due" auf null. Antworte '
            . 'AUSSCHLIESSLICH mit einem JSON-Array, ohne Erklärungen und ohne Markdown. Jedes Element hat '
            . 'exakt diese Felder: {"title": string, "info": string oder null, "due": "YYYY-MM-DD" oder '
            . 'null, "time": "HH:MM" oder null, "priority": "high" oder "normal" oder "low", '
            . '"kind": "task" oder "event" oder "shopping"' . ($mitHausaufgaben ? ' oder "homework"' : '') . ', '
            . '"end": "YYYY-MM-DD" oder null, '
            . '"allDay": true oder false, "amount": string oder null, "person": string oder null'
            . ($mitHausaufgaben ? ', "subject": string oder null' : '') . '}. '
            . $this->AiPersonenRegel()
            // Ohne diesen Satz liefert ein kleines Modell bei einer freundlichen
            // Einladung eine leere Liste: Es sucht die Aufforderung („bitte
            // zurücksenden") und findet keine (gemessen an „Angebot Segelboot bauen
            // am 27.06." — 0 Einträge, obwohl Datum, Kosten und Anmeldung dastanden).
            . $this->AiKindRule()
            . ($mitHausaufgaben ? $this->AiHomeworkKindRule() : '')
            . ' EINKAEUFE SIND EINE EIGENE ART: Nennt die Nachricht Dinge, die zu KAUFEN oder zu '
            . 'besorgen sind (Lebensmittel, Drogerie, „auf die Einkaufsliste", „wir brauchen noch"), '
            . 'gib JEDEN Artikel als eigenen Eintrag mit "kind": "shopping" zurueck. "title" ist dabei '
            . 'NUR der Artikelname („Milch", nicht „Milch kaufen"), eine Mengenangabe gehoert nach '
            . '"amount" („2", „500 g"), sonst ist "amount" null; "due", "time", "end" und "recurrence" '
            . 'bleiben bei Einkaeufen null. Eine REINE ARTIKELLISTE ohne Handlung und ohne Datum '
            . '(„Milch, Butter, Klopapier") ist deshalb KEINE leere Liste, sondern lauter '
            . '"shopping"-Eintraege.'
            /* Gemessen am 03.09.2026 an der Materialliste der Klasse 5a: aus einem
               PDF wurden 35 Einzelaufgaben („Geodreieck", „Radiergummi",
               „Schnellhefter DIN A4" achtmal). Das Modell hat die Mitbringsel-Regel
               und die Einkaufs-Regel vermischt. Ohne diese Abgrenzung muesste der
               Nutzer 35 Zeilen einzeln ablehnen. */
            . ' ABGRENZUNG: Gehoert die Aufzaehlung zu EINER Gelegenheit — Materialliste '
            . 'fuer das Schuljahr, Mitbringsel fuer einen Kurs, Ausruestung fuer eine '
            . 'Fahrt, Unterlagen fuer einen Termin —, dann ist das EINE Aufgabe mit der '
            . 'vollstaendigen Liste in "info" und NICHT ein Eintrag je Gegenstand. '
            . '"shopping" gilt nur fuer den laufenden Haushaltseinkauf ohne Anlass. Im '
            . 'Zweifel: EINE Aufgabe.'
            /* Ebenfalls gemessen: aus dem Stundenplan-PDF wurden vier datumslose
               „Termine" (Sportunterricht Montag, AG Bas Freitag). Ein Wochenplan ist
               kein Kalender. */
            . ' SCHULSTUNDEN SIND KEINE TERMINE: Ein Stundenplan, eine Kursuebersicht '
            . 'oder eine Liste wiederkehrender Stunden ergibt NIEMALS ein "event" — '
            . 'weder je Fach noch je Wochentag noch als Ganzes. Der Stundenplan steht '
            . 'im Stundenplan, nicht im Kalender. Wenn ueberhaupt, gib EINE Notiz '
            . 'zurueck ("kind": "note"), und auch die nur, wenn im Dokument etwas '
            . 'Merkenswuerdiges steht, das ueber den Plan selbst hinausgeht.';
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
    private function AiKindRule(bool $mitNotiz = false): string
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
            . ($mitNotiz ? $this->AiNoteKindRule() : '')
            . $this->AiSeriesRule();
    }

    /**
     * Aufbau des Notiztextes — an EINER Stelle, damit beide Wege (Datei-Analyse und
     * Mailanalyse) dieselbe Form liefern.
     *
     * Bewusst KEIN Markdown: Der Text wird als reiner Text gespeichert, in einem
     * Textfeld bearbeitet und in der Vorschau auf eine Zeile zusammengezogen. Ein
     * `**fett**` waere dort sichtbarer Muell. Struktur entsteht deshalb durch Zeilen
     * und Beschriftungen, und die tragen ueberall.
     */
    private function AiNoteTextRule(): string
    {
        return ' AUFBAU DES NOTIZTEXTES. Erst EIN Satz, der die Sache zusammenfasst. '
            . 'Danach je Angabe eine eigene Zeile in der Form „Bezeichnung: Wert" — '
            . 'zum Beispiel „Termin: 12.09.2026, 19:30", „Ort: Raum 214", '
            . '„Kosten: 12 EUR", „Frist: 10.09.2026", „Kontakt: <Name>, '
            . '<Telefonnummer>", „Aktenzeichen: 4711/26". Nur Angaben, die wirklich im '
            . 'Dokument stehen; erfinde keine Zeile und lass keine wichtige weg. '
            . 'Die Beispiele oben zeigen NUR die Form; uebernimm daraus keine Werte '
            . '(Namen, Nummern, Orte, Daten, Aktenzeichen) — nur, was im Dokument steht. '
            // Beobachtet am 21.08.2026: aus der Anschrift im Dokument wurde eine
            // ANDERE, frei erfundene — richtige Stadt, richtige Postleitzahl,
            // falsche Strasse und Hausnummer, mitten in einer sonst korrekten
            // Notiz. (Die echte steht hier nicht: oeffentliches Repo.) Bei Anschriften,
            // Nummern und Aktenzeichen genuegt „erfinde nichts" offenbar nicht;
            // es braucht die ausdrueckliche Anweisung, ZU KOPIEREN.
            . 'ANSCHRIFTEN, TELEFONNUMMERN, IBANs, AKTENZEICHEN, ADRESSEN UND DATEN '
            . 'schreibst du ZEICHEN FUER ZEICHEN so ab, wie sie im Dokument stehen. '
            . 'Ergaenze nichts aus eigenem Wissen und vervollstaendige nichts. Bist '
            . 'du bei einer solchen Angabe unsicher, lass die Zeile weg — eine '
            . 'falsche Hausnummer ist schlimmer als eine fehlende. '
            . 'Bezeichnungen auf Deutsch, kurz und ohne Doppelpunkt im Wert. '
            . 'Steht etwas ohne Bezeichnung da, was man behalten will, schreibe es als '
            . 'eigene Zeile ohne Bezeichnung. KEIN Markdown, keine Sternchen, keine '
            . 'Bindestrich-Listen, keine Ueberschriften.';
    }

    /**
     * Die dritte Art: „note" fuer etwas, das man BEHALTEN will.
     *
     * Nur fuer die Mailanalyse (AiKindRule(true)) — der Foto-Scan soll weiterhin
     * ausschliesslich Aufgaben und Termine liefern, und der Notiz-Prompt hat sein
     * eigenes Format.
     *
     * Die Laengengrenze und der Hinweis auf \n sind KRITISCH und nicht kosmetisch:
     * Ein echter Zeilenumbruch in einem JSON-String reisst das ganze Array — mit ihm
     * verschwinden ALLE Aufgaben und Termine derselben Mail. AiDecodeJsonArray
     * bessert das inzwischen nach, aber die Regel ist die erste Verteidigungslinie.
     */
    /**
     * Die vierte Moeglichkeit: eine Hausaufgabe.
     *
     * Steht bewusst NEBEN der Regel „Schulstunden sind keine Termine" und ist
     * deren Gegenstueck: der Plan gehoert in den Stundenplan, die Aufgabe daran
     * in die Hausaufgaben. Ohne Fach wird daraus wieder eine Aufgabe — eine
     * Hausaufgabe ohne Fach laesst sich im Stundenplan nicht anzeigen.
     */
    private function AiHomeworkKindRule(): string
    {
        return ' VIERTE MOEGLICHKEIT — HAUSAUFGABE. Steht im Dokument, was ein KIND '
            . 'fuer den Unterricht zu erledigen hat (Hausaufgabenheft, Wochenplan, '
            . '„HA", „Aufgabe bis Freitag", Buchseiten, Arbeitsblatt, Vokabeln, Lesen '
            . 'ueben), dann gib je Aufgabe EINEN Eintrag mit "kind":"homework" zurueck. '
            . 'Setze "subject" auf das Schulfach so, wie es dasteht („Mathematik", '
            . '„Mathe", „Ma"), "due" auf den Tag, an dem die Aufgabe FERTIG sein muss '
            . '(Abgabetag oder naechster Unterrichtstag in diesem Fach), und "person" '
            . 'auf den Namen des Kindes, wenn er genannt ist. "title" ist die Aufgabe '
            . 'in wenigen Worten („S. 42 Nr. 3-5"), alles Weitere gehoert nach "info". '
            . 'EINE Zeile im Heft ist EINE Hausaufgabe; fasse mehrere Faecher niemals '
            . 'zusammen. ABGRENZUNG: Eine Hausaufgabe ist etwas, das das KIND fuer den '
            . 'Unterricht tut. Elternbriefe, Zettel zum Unterschreiben, Beitraege, '
            . 'Elternabende, Ausfluege und Materiallisten bleiben "task" bzw. "event" — '
            . 'auch dann, wenn sie von der Schule kommen. '
            . 'DAS FACH AUS DEM MATERIAL SCHLIESSEN: In den ersten Klassen nennt ein '
            . 'Lernzeitplan oft nur das Heft. Buchstabenheft, Leseheft, Lesetagebuch, '
            . 'Schreibheft, Leseteppich, Diktat, Anlauttabelle heissen Deutsch; '
            . 'Zahlenheft, Rechenheft, Mathetrainer, Knobelheft heissen Mathematik; '
            . 'Forscherheft, Sachheft heissen Sachunterricht; Workbook, Vokabeln '
            . 'heissen Englisch. Ist das Fach danach noch unklar, gib die Hausaufgabe '
            . 'TROTZDEM mit "kind":"homework" und leerem "subject" zurueck — das Fach '
            . 'traegt der Nutzer nach. '
            . 'EIN LERNZEITPLAN ENTHAELT BEIDES: Was das Kind UEBT oder BEARBEITET '
            . '(Seiten, Hefte, Lesen, Rechnen, Vokabeln, eine Lernplattform) ist eine '
            . 'Hausaufgabe. Was besorgt, unterschrieben, bezahlt, angemeldet oder '
            . 'mitgebracht werden muss, und jeder Termin darin (Fototermin, Ausflug, '
            . 'Elternabend, Schliesstag) bleibt "task" bzw. "event" — auch mitten in '
            . 'einem Lernzeitplan.';
    }

    /**
     * Wie AiPersonZuBenutzer, aber ausschliesslich Kinder — und als EINZELNE
     * Kennung statt einer Liste: eine Hausaufgabe gehoert genau einem Kind.
     */
    private function HomeworkKindZuBenutzer(string $name): string
    {
        $treffer = $this->AiPersonZuBenutzer($name);
        if (!is_array($treffer) || count($treffer) !== 1) {
            return '';
        }
        $kinder = $this->HomeworkKinder();
        $id = (string)$treffer[0];
        return in_array($id, $kinder, true) ? $id : '';
    }

    private function AiNoteKindRule(): string
    {
        return ' DRITTE MOEGLICHKEIT — NOTIZ. Enthaelt die Mail Angaben, die man '
            . 'AUFBEWAHREN will, ohne dass daraus eine Handlung oder ein Termin wird '
            . '(Zugangsdaten, Zaehlerstaende, Bestell- und Aktenzeichen, Anschriften, '
            . 'Telefonnummern, Oeffnungszeiten, Beitragshoehen, Ergebnisse, '
            . 'Zusammenfassungen eines langen Schreibens), dann gib zusaetzlich EINEN '
            . 'Eintrag mit "kind":"note" zurueck. Sein Feld "title" benennt die Sache '
            . 'kurz, sein Feld "text" fasst die Mail mit ALLEN nachschlagbaren Angaben '
            // 800 war zu knapp: ein sechsseitiger Elternbrief mit zwoelf Abschnitten
            // laesst sich darin nicht unterbringen, und gemessen kamen Notizen mit 438
            // und 480 Zeichen heraus, in denen halbe Abschnitte fehlten. Erst 1800,
            // dann auf Wunsch um die Haelfte mehr: 2700. Bleibt sicher unter
            // NOTE_TEXT_MAX (3000) — darueber weist das Uebernehmen den ganzen
            // Vorschlag als invalid_payload ab, die Notiz waere also verloren.
            . 'zusammen: hoechstens 2700 Zeichen. Lieber lang und vollstaendig als '
            . 'kurz und lueckenhaft — wenn die Mail viele Abschnitte hat, nenne sie '
            . 'alle. Zeilenumbrueche darin ausschliesslich '
            . 'als \\n — niemals ein echter Umbruch innerhalb der Anfuehrungszeichen.'
            . $this->AiNoteTextRule()
            . ' Eine Notiz ersetzt Aufgaben und Termine NICHT: '
            . 'enthaelt die Mail beides, gib beides zurueck. Reine Werbung und '
            . 'Newsletter ergeben KEINE Notiz.';
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

    private function AiMailSystemPrompt(string $today, bool $mitAnhang = false, string $quelle = 'IMAP'): string
    {
        /* Die Klassenseite ist die eigentliche Quelle von Hausaufgaben — dort
           stehen Wochenplaene. Die Regel haengt trotzdem an BEIDEN Eingaengen:
           ein Elternbrief kann ebenso eine Hausaufgabe nennen. */
        $mitHausaufgaben = $this->HomeworkKinder() !== [];
        /* Nicht jede Quelle ist eine Mail. Eine Karte der Klassenseite lief bisher
           durch denselben Text — und das Modell schrieb es in „info": „E-Mail
           „5b Musterklasse — Willkommen"". Das steht dann so in der App. */
        if ($quelle === 'Edumaps') {
            return $this->AiSystemPrompt($today)
                . ' ZUSATZ: Der Text stammt von der KLASSENSEITE der Schule (eine Karte '
                . 'mit Titel und Text), NICHT aus einer E-Mail — nenne sie in "info" '
                . 'entsprechend („Klassenseite", „Elternbrief"), niemals „E-Mail".'
                . ($mitAnhang
                    ? ' Die beigefuegte Datei liegt dir VOR und gehoert zu derselben Karte: '
                        . 'lies Kartentext und Datei zusammen. Meist steht die Aufforderung auf '
                        . 'der Karte und die Einzelheiten (Termine, Fristen, Betraege, Listen) '
                        . 'in der Datei — uebernimm sie von dort.'
                    : ' Ein Verweis auf eine Datei hebt die Aufgabe NICHT auf — er ist selbst '
                        . 'die Handlungsaufforderung. Erfinde aber keine Angaben, die nur in der '
                        . 'Datei stehen koennen.')
                . $this->AiKindRule(true)
                . ($mitHausaufgaben ? $this->AiHomeworkKindRule() : '')
                . $this->AiSummaryRule();
        }
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
                . $this->AiKindRule(true)
                . ($mitHausaufgaben ? $this->AiHomeworkKindRule() : '')
                . $this->AiSummaryRule();
        }
        return $this->AiSystemPrompt($today)
            . ' ZUSATZ FUER E-MAILS: Der Text ist eine E-Mail, oft weitergeleitet — der '
            . 'eigentliche Absender steht dann im zitierten Kopf innerhalb des Textes, '
            . 'nicht in der Betreffzeile. Nenne diese Quelle in "info". Nennt die Mail '
            . 'einen konkreten Termin mit Uhrzeit (Elternabend, Sprechstunde, Abgabe um '
            . 'eine bestimmte Zeit), gib zusaetzlich das Feld "time" im Format "HH:MM" '
            . 'an.' . $this->AiKindRule(true)
            . ($mitHausaufgaben ? $this->AiHomeworkKindRule() : '') . ' WICHTIG zum Anhang: '
            . 'Anhaenge liegen dir NICHT vor. Ein Verweis darauf („siehe Anhang“, „im '
            . 'beigefuegten Formular“) hebt die Aufgabe aber NICHT auf — er ist selbst die '
            . 'Handlungsaufforderung. Nennt die Mail ein Formular, eine Abfrage, eine Liste '
            . 'oder ein Dokument, das ausgefuellt, beachtet, unterschrieben oder '
            . 'zurueckgeschickt werden soll, erzeuge dafuer eine Aufgabe — z.B. '
            . '„Ferienabfrage Herbstferien ausfuellen und zurueckschicken“ — und vermerke in '
            . '"info", dass die Details im Anhang stehen. Erfinde lediglich keine Angaben, '
            . 'die nur im Anhang stehen koennen: keine Fristen, Betraege, Uhrzeiten oder '
            . 'Namen, die im Mailtext nicht vorkommen.' . $this->AiSummaryRule();
    }

    /**
     * Die Zusammenfassung ueber den Eintraegen (19.09.2026): ein zusaetzliches
     * Element im selben Array, damit das Antwortformat gleich bleibt — kleine
     * Modelle halten EIN Format durch, zwei nicht. MailAnalyseCalc::Zusammenfassung
     * liest es heraus, die Eintrags-Pruefung verwirft es still.
     */
    private function AiSummaryRule(): string
    {
        return ' ZUSAMMENFASSUNG: Gib als ERSTES Element des Arrays zusaetzlich '
            . '{"kind": "summary", "title": string} zurueck — vier bis sechs Saetze, WER '
            . 'schreibt (Schule, Klasse, Verein, Arzt …) und WORUM es geht, ohne Anrede '
            . 'und ohne Wiederholung der Eintraege. Kein anderer Eintrag hat "kind": "summary".';
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
            . 'null. "servings" = die Anzahl der Portionen, die das Rezept ergibt, als ganze Zahl. Steht '
            . 'keine Portionsangabe im Rezept, schätze sie anhand der Zutatenmengen (z.B. 500 g Nudeln → 4) '
            . 'und gib die Schätzung an; nur bei reinen Einkaufslisten ist "servings" null. Antworte AUSSCHLIESSLICH mit '
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
            . 'bei „12 Muffins“ → 12). Fehlt die Angabe, schätze sie anhand der Zutatenmengen und gib die '
            . 'Schätzung an. "items" ist die Zutatenliste: "name" = '
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
