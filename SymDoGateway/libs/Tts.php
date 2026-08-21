<?php

declare(strict_types=1);

/**
 * Sprachausgabe für die Einkaufs-Ansage.
 *
 * Die Web-App lässt sich die offene Einkaufsliste vorlesen und bedient sie über die
 * Kopfhörer-Tasten. Dafür braucht sie echte Tondateien: nur ein <audio>-Element bindet
 * die Fernbedienung (Media Session), die Sprachausgabe des Browsers tut das nicht.
 *
 * Zwei Routen:
 *   POST /v1/tts            { texts: [...] }  → je Text eine Kennung, fehlende werden erzeugt
 *   GET  /v1/tts/{hash}     → die Tondatei (audio/mpeg)
 *
 * Erzeugt wird bei OpenAI, über denselben Schlüssel und dieselbe HTTP-Hilfe wie die
 * KI-Analyse (AiHttpPost liefert den Rohkörper, trägt also auch Audio). Jeder Schnipsel
 * landet als Medienobjekt im Zwischenspeicher — „2 Kilo Äpfel" wird einmal bezahlt und
 * danach nie wieder. Abteilungs- und Artikel-Ansagen sind bewusst getrennte Schnipsel:
 * beide sind für sich wiederverwendbar, zusammengefasst hinge der Schlüssel an der
 * Position im Einkauf und träfe fast nie.
 */
trait Tts
{
    /** Obergrenzen je Anfrage — eine Einkaufsliste bleibt weit darunter. */
    private const TTS_MAX_TEXTS = 80;
    private const TTS_MAX_CHARS = 200;

    /** So viele Schnipsel bleiben liegen; darüber fliegt der älteste raus. */
    private const TTS_CACHE_MAX = 400;

    /**
     * Obergrenze je Aufnahme — DIE zentrale Erklaerung zur Ausgabegrenze.
     *
     * Die Grenze ist die Symcon-Kernoption `ScriptOutputBufferLimit`, Vorgabe
     * 1048576 Bytes (1 MiB), auslesbar mit `IPS_GetOption('ScriptOutputBufferLimit')`
     * und dort auch aenderbar. Sie zaehlt die SUMME der Ausgabe einer Anfrage, nicht
     * den einzelnen Schreibvorgang: `readfile()` schreibt in 8-KB-Haeppchen und laeuft
     * trotzdem dagegen.
     *
     * Wird sie ueberschritten, wird die Antwort nicht abgeschnitten, sondern ERSETZT —
     * durch 62 Bytes Text „Output-Buffer exceeds Limit (1048576 bytes). Operation
     * halted.", bei HTTP 200 und mit dem laengst gesendeten Content-Type. Der Client
     * sieht also eine kaputte Datei und keinen Fehler; deshalb muss jeder Riegel VOR
     * der Ausgabe greifen. Am 21.08.2026 bei 1048576 und 1048577 Bytes exakt
     * eingegrenzt.
     *
     * Hier wird der Wert ABGELESEN und nicht festgeschrieben: Wer die Option
     * hochsetzt, soll davon auch etwas haben — vorher stand hier eine feste Million,
     * und die Aufnahmen blieben klein, obwohl Platz war. Etwas Luft fuer die
     * Kopfzeilen bleibt abgezogen.
     */
    private function TtsOutputLimit(): int
    {
        $grenze = 1048576;
        try {
            $o = (int)@IPS_GetOption('ScriptOutputBufferLimit');
            if ($o > 0) {
                $grenze = $o;
            }
        } catch (Throwable $e) {
            // Aeltere Symcon-Fassung ohne die Option: bei der Vorgabe bleiben.
        }
        return max(200000, $grenze - 100000);
    }

    private const TTS_CATEGORY_NAME = 'Sprachausgabe';

    /** Vorgabe-Anweisung an das Sprachmodell. */
    private const TTS_INSTRUCTIONS =
        'Sprich Deutsch. Lies wie jemand, der beim Einkaufen hilft: ruhig, deutlich, '
      . 'freundlich und ohne Eile. Sprich Zahlen und Mengen aus, als würdest du sie '
      . 'jemandem im Laden zurufen. Keine künstliche Betonung, kein Vorlesen von Satzzeichen.';

    /** Aus AppCreate() aufgerufen. */
    private function TtsCreate(): void
    {
        $this->RegisterPropertyString('TtsVoice', 'alloy');
        // Steuert Sprache und Tonfall. Ohne sie liest das Modell deutsche Texte
        // gern mit englischem Einschlag.
        $this->RegisterPropertyString('TtsInstructions', self::TTS_INSTRUCTIONS);
        $this->RegisterPropertyString('TtsModel', 'gpt-4o-mini-tts');
        // Hash → ['id' => Medien-ID, 'at' => Zeitstempel]
        $this->RegisterAttributeString('TtsCache', '{}');
        $this->RegisterAttributeString('TtsCategory', '');
    }

    /**
     * Die Ansage hängt an OpenAI: Anthropic hat keine Sprachausgabe. Ohne Schlüssel
     * bleibt die Funktion aus, statt im Laden stumm zu scheitern.
     */
    private function TtsEnabled(): bool
    {
        return $this->ReadPropertyString('AiProvider') === 'openai'
            && trim($this->ReadPropertyString('AiOpenAIKey')) !== ''
            && $this->TtsStorageReady();
    }

    /**
     * Stimme und Modell über IPS_GetConfiguration statt ReadPropertyString: die
     * Eigenschaften entstehen in Create() und existieren erst nach dem nächsten
     * Kernel-Start. Bis dahin lieferte das Lesen eine PHP-Warnung, die kein
     * try/catch fängt — dieselbe Falle wie bei den Sichtbarkeits-Schaltern.
     */
    private function TtsSetting(string $name, string $default): string
    {
        $cfg  = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $wert = (is_array($cfg) && array_key_exists($name, $cfg)) ? trim((string)$cfg[$name]) : '';
        return $wert !== '' ? $wert : $default;
    }

    /**
     * Der Zwischenspeicher liegt in einem Attribut, und auch das gibt es vor dem
     * ersten Kernel-Start noch nicht. Ohne ihn würde JEDE Ansage neu erzeugt und
     * bezahlt — deshalb bleibt die Funktion dann lieber ganz aus.
     */
    private function TtsStorageReady(): bool
    {
        try {
            return is_string($this->ReadAttributeString('TtsCache'));
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Routen
    // ---------------------------------------------------------------------

    /**
     * POST /v1/tts — nimmt die Texte einer ganzen Liste entgegen und liefert je Text
     * eine Kennung. Was fehlt, wird jetzt erzeugt; die Web-App holt danach alle
     * Schnipsel und hält sie vor, damit im Laden kein Empfang mehr nötig ist.
     */
    private function HandleTtsPrepare(): void
    {
        if (!$this->TtsStorageReady()) {
            $this->SendApiError('tts_restart_required',
                'Speech output was just installed — restart the IP-Symcon kernel once', 503);
            return;
        }
        if (!$this->TtsEnabled()) {
            $this->SendApiError('tts_disabled',
                'Speech output requires the OpenAI provider with an API key', 409);
            return;
        }
        $body  = $this->ReadJsonBody();
        $texte = $body['texts'] ?? null;
        if (!is_array($texte) || $texte === []) {
            $this->SendApiError('invalid_payload', 'texts must be a non-empty array', 422);
            return;
        }
        if (count($texte) > self::TTS_MAX_TEXTS) {
            $this->SendApiError('too_many', 'At most ' . self::TTS_MAX_TEXTS . ' texts per request', 422);
            return;
        }

        $clips = [];
        $neu   = 0;
        $fehler = 0;
        // Gleiche Texte nur einmal erzeugen: eine Liste wiederholt Abteilungen.
        $gesehen = [];
        // JEDER Eingabetext bekommt genau einen Eintrag, und der traegt seine
        // Position. Der Aufrufer ordnet ueber 'i' zu, NICHT ueber den Text: hier
        // wird normalisiert („2 kg Äpfel" → „2 Kilo Äpfel"), der Text auf dem
        // Rueckweg ist also ein anderer als der hingeschickte.
        foreach (array_values($texte) as $i => $roh) {
            $text = $this->TtsNormalize((string)$roh);
            if ($text === '') {
                $clips[] = ['i' => $i, 'text' => '', 'hash' => '', 'ok' => false, 'cached' => false];
                continue;
            }
            if (isset($gesehen[$text])) {
                $clips[] = ['i' => $i] + $gesehen[$text];
                continue;
            }
            $hash = $this->TtsHash($text);
            $mid  = $this->TtsLookup($hash);
            $cached = $mid > 0;
            if (!$cached) {
                $mid = $this->TtsProduce($hash, $text);
                if ($mid > 0) {
                    $neu++;
                } else {
                    $fehler++;
                }
            }
            $eintrag = ['text' => $text, 'hash' => $hash, 'ok' => $mid > 0, 'cached' => $cached];
            $gesehen[$text] = $eintrag;
            $clips[] = ['i' => $i] + $eintrag;
        }

        $this->SendDebug('TTS', 'prepare: ' . count($clips) . ' Schnipsel, ' . $neu . ' neu erzeugt, ' . $fehler . ' fehlgeschlagen', 0);
        $this->SendJson([
            'ok'        => $fehler === 0,
            'voice'     => $this->TtsSetting('TtsVoice', 'alloy'),
            'clips'     => $clips,
            'generated' => $neu,
            'failed'    => $fehler,
        ]);
    }

    /** GET /v1/tts/{hash} — die fertige Tondatei. */
    private function HandleTtsAudio(string $hash): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
            $this->SendApiError('invalid_payload', 'Malformed clip id', 422);
            return;
        }
        $mid = $this->TtsLookup($hash);
        if ($mid <= 0) {
            $this->SendApiError('not_found', 'Clip not found', 404);
            return;
        }
        $roh = base64_decode((string)@IPS_GetMediaContent($mid), true);
        if (!is_string($roh) || $roh === '') {
            $this->SendApiError('empty', 'Clip not readable', 404);
            return;
        }
        // Der Inhalt ist unveränderlich — der Hash IST der ETag.
        $etag = '"' . $hash . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=604800, immutable');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        http_response_code(200);
        header('Content-Type: ' . $this->TtsMimeType($this->TtsFormatOf($hash)));
        header('Content-Length: ' . strlen($roh));
        header('X-Content-Type-Options: nosniff');
        echo $roh;
    }

    /**
     * Derselbe Schnipsel, aber als data:-URL fuer die Visu-Kachel.
     *
     * Die Kachel kann GET /v1/tts/{hash} nicht aufrufen: Sie hat keinen Token, weil
     * sie nie gekoppelt wurde — ihr einziger Weg nach draussen ist das Relay. Also
     * reist der Ton denselben Weg wie die Rezeptfotos (AiGetMedia), nur mit dem
     * Ton-MIME. Rund 480 KB je Briefing werden dabei zu etwa 640 KB Base64.
     *
     * @return array<string, mixed>
     */
    private function TtsClipRelay(string $hash): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
            return ['ok' => false, 'error' => ['code' => 'invalid_payload', 'message' => 'Malformed clip id']];
        }
        $mid = $this->TtsLookup($hash);
        if ($mid <= 0) {
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'Clip not found']];
        }
        $b64 = (string)@IPS_GetMediaContent($mid);
        if ($b64 === '') {
            return ['ok' => false, 'error' => ['code' => 'empty', 'message' => 'Clip not readable']];
        }
        return [
            'ok'      => true,
            'hash'    => $hash,
            'dataUrl' => 'data:' . $this->TtsMimeType($this->TtsFormatOf($hash)) . ';base64,' . $b64,
        ];
    }

    // ---------------------------------------------------------------------
    // Erzeugung und Zwischenspeicher
    // ---------------------------------------------------------------------

    /**
     * Vorlesbar machen: Einheiten ausschreiben, damit „2 kg Äpfel" nicht als
     * „zwei ka geh Äpfel" ankommt. Bewusst schlicht — nur die Fälle, die in
     * Einkaufslisten wirklich vorkommen.
     */
    private function TtsNormalize(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }
        // Kaufmanns-Und ausschreiben: die Kategorien heissen „Obst & Gemüse"
        $text = str_replace(['&amp;', '&'], ['und', 'und'], $text);
        $ersetzungen = [
            '/(\d)\s*kg\b/iu'    => '$1 Kilo',
            '/(\d)\s*g\b/u'      => '$1 Gramm',
            '/(\d)\s*l\b/iu'     => '$1 Liter',
            '/(\d)\s*ml\b/iu'    => '$1 Milliliter',
            '/(\d)\s*x\b/iu'     => '$1 mal',
            '/(\d)\s*Stk\b\.?/iu' => '$1 Stück',
            '/(\d)\s*Pck\b\.?/iu' => '$1 Packung',
            '/(\d)\s*TL\b/u'     => '$1 Teelöffel',
            '/(\d)\s*EL\b/u'     => '$1 Esslöffel',
        ];
        $text = (string)preg_replace(array_keys($ersetzungen), array_values($ersetzungen), $text);

        // Die Eins klingt gezaehlt und unnatuerlich. „1 Zwiebel" sagt niemand — man
        // sagt „Zwiebel". Vor einer Einheit wird sie zum Artikel, dessen Geschlecht
        // je Einheit feststeht; „1 Stück" und „1 mal" fallen ganz weg.
        // Reihenfolge zaehlt: Einheiten und Gebinde ZUERST, sonst schluckt die
        // letzte Regel die Eins auch vor „Glas" — und „Glas Gurken" sagt niemand.
        $eins = [
            '/^1\s+(Kilo|Gramm|Liter|Milliliter|Teelöffel|Esslöffel|Becher|Beutel|Bund|Glas|Karton|Kopf|Netz)\b/u'
                => 'ein $1',
            '/^1\s+(Packung|Dose|Flasche|Tüte|Rolle|Schale|Tafel|Stange)\b/u'
                => 'eine $1',
            '/^1\s+(?:Stück|mal)\s+/u' => '',
            '/^1\s+(?=\p{L})/u'        => '',
        ];
        $text = (string)preg_replace(array_keys($eins), array_values($eins), $text);

        $text = trim($text);
        // Ein Zeichen fuer den Schlusspunkt freihalten, sonst reisst die Grenze.
        if (mb_strlen($text) > self::TTS_MAX_CHARS - 1) {
            $text = rtrim(mb_substr($text, 0, self::TTS_MAX_CHARS - 1));
        }
        // Schlusspunkt: ohne ihn laesst das Modell den Satz in der Luft haengen.
        if ($text !== '' && !preg_match('/[.!?]$/u', $text)) {
            $text .= '.';
        }
        return $text;
    }

    /** Format einer abgelegten Aufnahme; alte Einträge ohne Angabe sind MP3. */
    private function TtsFormatOf(string $hash): string
    {
        $cache = $this->TtsCacheRead();
        $fmt = (string)($cache[$hash]['fmt'] ?? 'mp3');
        return $fmt !== '' ? $fmt : 'mp3';
    }

    private function TtsMimeType(string $format): string
    {
        switch ($format) {
            case 'aac':  return 'audio/aac';
            case 'opus': return 'audio/ogg';
            case 'wav':  return 'audio/wav';
            case 'flac': return 'audio/flac';
            default:     return 'audio/mpeg';
        }
    }

    /**
     * Stimme, Modell und Vorleseanweisung gehören in den Schlüssel: sonst bliebe
     * die alte Aufnahme liegen, obwohl jemand die Stimme gewechselt hat. Aus
     * demselben Grund tragen die Überschreibungen mit ein — dasselbe Briefing
     * klingt als Drillsergeant anders als sachlich.
     */
    private function TtsHash(string $text, string $stimme = '', string $anweisung = '', float $tempo = 1.0, string $format = 'mp3'): string
    {
        return substr(hash('sha256',
            $this->TtsSetting('TtsModel', 'gpt-4o-mini-tts') . '|' .
            ($stimme !== '' ? $stimme : $this->TtsSetting('TtsVoice', 'alloy')) . '|' .
            ($anweisung !== '' ? $anweisung : $this->TtsSetting('TtsInstructions', self::TTS_INSTRUCTIONS)) . '|' .
            // Das Tempo gehoert dazu, sonst spielte die alte, langsame Aufnahme
            // weiter, obwohl jemand es geaendert hat.
            number_format($tempo, 2, '.', '') . '|' .
            // Das Format ebenso: Es steckt nicht im Text, macht aber eine ANDERE
            // Aufnahme. Ohne es wuerde nach dem Wechsel auf ein besseres Format die
            // alte, schlechtere weiterspielen — der Schluessel passte ja noch.
            $format .
            '|' . $text), 0, 32);
    }

    /** @return int Medien-ID oder 0 */
    private function TtsLookup(string $hash): int
    {
        $cache = $this->TtsCacheRead();
        $mid   = (int)($cache[$hash]['id'] ?? 0);
        if ($mid > 0 && @IPS_MediaExists($mid)) {
            return $mid;
        }
        if ($mid > 0) {
            // Objekt von Hand gelöscht — Eintrag mitnehmen, sonst bleibt eine Leiche.
            unset($cache[$hash]);
            $this->TtsCacheWrite($cache);
        }
        return 0;
    }

    /** Erzeugt den Schnipsel und legt ihn ab. @return int Medien-ID oder 0 */
    /**
     * @param string $stimme    Leer = die Einstellung. Der Aufrufer kann eine eigene
     *                          waehlen (das Briefing tut das je Tonfall).
     * @param string $anweisung Leer = die Einstellung (Einkaufs-Vorlesestil).
     */
    private function TtsProduce(string $hash, string $text, string $stimme = '', string $anweisung = '', string $format = 'mp3', float $tempo = 1.0): int
    {
        $mp3 = $this->TtsRequestAudio($text, $stimme, $anweisung, $format, $tempo);
        if ($mp3 === '') {
            return 0;
        }
        // Symcons Hook gibt hoechstens 1 MB aus (gemessen: „Output-Buffer exceeds
        // Limit") — und Haeppchen mit flush() helfen nicht, die Grenze ist die
        // Summe. Was darueber liegt, waere nicht abholbar; dann lieber gar nichts
        // ablegen als eine Datei, die im Player stumm bleibt.
        if (strlen($mp3) > $this->TtsOutputLimit()) {
            $this->SendDebug('TTS', sprintf('Aufnahme zu gross (%d kB), verworfen', (int)round(strlen($mp3) / 1024)), 0);
            return 0;
        }
        $cat = $this->TtsCategoryID();
        if ($cat <= 0) {
            $this->SendDebug('TTS', 'Ablage-Kategorie nicht verfügbar', 0);
            return 0;
        }
        $mid = IPS_CreateMedia(MEDIATYPE_DOCUMENT);
        IPS_SetParent($mid, $cat);
        IPS_SetName($mid, mb_substr($text, 0, 60));
        // Erst die Datei, dann der Inhalt — anders nimmt Symcon den Inhalt nicht an.
        IPS_SetMediaFile($mid, 'media/tts_' . $hash . '.' . $format, false);
        IPS_SetMediaContent($mid, base64_encode($mp3));

        $cache = $this->TtsCacheRead();
        $cache[$hash] = ['id' => $mid, 'at' => time(), 'fmt' => $format];
        $this->TtsCacheWrite($this->TtsEvict($cache));
        return $mid;
    }

    /**
     * @param string $format mp3 (Vorgabe), aac, opus … Kurze Ansagen bleiben bei
     *        MP3; ein langer Text braucht ein sparsameres Format, weil die
     *        Abholung bei 1 MB endet.
     * @return string rohe Audiodaten, '' bei Fehler
     */
    private function TtsRequestAudio(string $text, string $stimme = '', string $anweisung = '', string $format = 'mp3', float $tempo = 1.0): string
    {
        $key = trim($this->ReadPropertyString('AiOpenAIKey'));
        if ($key === '') {
            return '';
        }
        $felder = [
            'model'           => $this->TtsSetting('TtsModel', 'gpt-4o-mini-tts'),
            'voice'           => $stimme !== '' ? $stimme : $this->TtsSetting('TtsVoice', 'alloy'),
            'input'           => $text,
            'instructions'    => $anweisung !== '' ? $anweisung : $this->TtsSetting('TtsInstructions', self::TTS_INSTRUCTIONS),
            'response_format' => $format,
        ];
        // `speed` ist fuer gpt-4o-mini-tts NICHT dokumentiert und wird von ihm
        // ignoriert: derselbe Satz ergab am 21.08.2026 bei 1.0 und bei 1.14 dieselbe
        // Laenge (9,69 s gegen 9,95 s — die Streuung zweier Laeufe, nicht 14 %).
        // Das Tempo steuert man bei diesem Modell ueber `instructions`. Mitgeschickt
        // wird das Feld nur noch, wenn es abweicht — fuer tts-1, wo es wirkt.
        if (abs($tempo - 1.0) > 0.001) {
            $felder['speed'] = max(0.25, min(4.0, $tempo));
        }
        $body = json_encode($felder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $resp = $this->AiHttpPost('https://api.openai.com/v1/audio/speech', [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ], (string)$body);

        $status = (int)($resp['status'] ?? 0);
        $roh    = (string)($resp['body'] ?? '');
        if (($resp['err'] ?? '') !== '') {
            $this->SendDebug('TTS', 'HTTP-Fehler: ' . $resp['err'], 0);
            return '';
        }
        if ($status !== 200 || $roh === '') {
            // Im Fehlerfall antwortet OpenAI mit JSON statt Audio — das ist die Meldung.
            $this->SendDebug('TTS', 'Antwort ' . $status . ': ' . mb_substr($roh, 0, 300), 0);
            return '';
        }
        // Gegenprobe: eine JSON-Antwort mit Status 200 wäre keine Tondatei.
        if (str_starts_with(ltrim($roh), '{')) {
            $this->SendDebug('TTS', 'Erwartete Audiodaten, bekam JSON: ' . mb_substr($roh, 0, 300), 0);
            return '';
        }
        return $roh;
    }

    /** @param array<string, array{id:int,at:int}> $cache */
    private function TtsEvict(array $cache): array
    {
        if (count($cache) <= self::TTS_CACHE_MAX) {
            return $cache;
        }
        uasort($cache, static fn(array $a, array $b): int => ($a['at'] ?? 0) <=> ($b['at'] ?? 0));
        while (count($cache) > self::TTS_CACHE_MAX) {
            $hash = (string)array_key_first($cache);
            $mid  = (int)($cache[$hash]['id'] ?? 0);
            if ($mid > 0 && @IPS_MediaExists($mid)) {
                @IPS_DeleteMedia($mid, true);
            }
            unset($cache[$hash]);
        }
        return $cache;
    }

    /** @return array<string, array{id:int,at:int}> */
    private function TtsCacheRead(): array
    {
        $data = json_decode($this->ReadAttributeString('TtsCache'), true);
        return is_array($data) ? $data : [];
    }

    private function TtsCacheWrite(array $cache): void
    {
        $this->WriteAttributeString('TtsCache', (string)json_encode($cache));
    }

    /** Ablage der Schnipsel: eine Kategorie unter dieser Instanz. */
    private function TtsCategoryID(): int
    {
        $catId = (int)$this->ReadAttributeString('TtsCategory');
        if ($catId > 0 && IPS_CategoryExists($catId)) {
            return $catId;
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            if (IPS_CategoryExists($child) && IPS_GetName($child) === self::TTS_CATEGORY_NAME) {
                $this->WriteAttributeString('TtsCategory', (string)$child);
                return $child;
            }
        }
        $catId = IPS_CreateCategory();
        IPS_SetParent($catId, $this->InstanceID);
        IPS_SetName($catId, self::TTS_CATEGORY_NAME);
        $this->WriteAttributeString('TtsCategory', (string)$catId);
        return $catId;
    }
}
