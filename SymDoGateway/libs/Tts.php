<?php

declare(strict_types=1);

/* Die Unterschrift fuer Amazon Polly steht in einer eigenen, reinen Klasse — und
   eine Klasse laedt PHP nicht von selbst. Sie fehlte hier: der Griff darauf
   endete mit „Class AwsSigV4 not found", sowohl beim Abrufen der Stimmenliste als
   auch beim Sprechen selbst. Deshalb steht das require HIER, beim einzigen
   Benutzer, und nicht in AppCore neben den Traits. */
require_once __DIR__ . '/AwsSigV4.php';

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

    /** Vorgabe, wenn keine Persona-Stimme mitkommt. Neutral und gut verstaendlich. */
    private const TTS_AZURE_VOICE = 'de-DE-KatjaNeural';

    /** Amazon Polly: deutsche Neural-Stimme und eine Region, die es fuehrt. */
    private const TTS_POLLY_VOICE  = 'Vicki';
    private const TTS_POLLY_REGION = 'eu-central-1';

    /**
     * Vorgabestimme bei ElevenLabs: „Rachel", eine der oeffentlichen Stimmen, die
     * jedes Konto kennt. Sie ist englisch trainiert und spricht Deutsch ueber das
     * mehrsprachige Modell — mit leichtem Akzent. Wer es akzentfrei will, traegt die
     * Kennung einer deutschen Stimme aus seinem Konto ein (Knopf „Stimmen abrufen").
     */
    private const TTS_ELEVEN_VOICE = '21m00Tcm4TlvDq8ikWAM';

    /**
     * Stilverstaerkung fuer ALLE Personas. 0 ist die Einstellung der Vorschau und
     * die qualitativ sicherste; hoehere Werte betonen den Charakter, kosten aber
     * Stabilitaet (siehe TtsElevenSettings). 0,2 entspricht 20 von 100 in der
     * ElevenLabs-Oberflaeche.
     */
    private const TTS_ELEVEN_STYLE = 0.02;

    /**
     * Tonqualitaet bei ElevenLabs, und was ein Zeichen dann kostet.
     *
     * Warum einstellbar und nicht fest: die zwei Anforderungen widersprechen sich.
     *   - Klang. mp3_22050_32 (der frueheste Wert hier) ist fuer Sprache hoerbar
     *     dumpf; die Vorschau bei ElevenLabs spielt mp3_44100_128. Genau das war
     *     der Grund, warum das Briefing schlechter klang als die Vorschau.
     *   - Die Hook-Ausgabegrenze. Bei der Symcon-Vorgabe von 1 MiB passen in EIN
     *     Stueck nur rund 580 Zeichen im 128er Format — ein Briefing zerfiele in
     *     mehrere Aufnahmen, und jede Naht ist hoerbar (die Sprechmelodie setzt neu
     *     an).
     * Wer die Kernoption hochgestellt hat, kann also 128 nehmen; bei der Vorgabe ist
     * 64 die bessere Wahl — bei Sprache kaum vom 128er zu unterscheiden, aber
     * gut doppelt so sparsam.
     *
     * Die Bytes je Zeichen sind GEMESSEN (22.08.2026, deutscher Text):
     *   32 kbit/22 kHz  ->  279  (222 999 fuer 798 Zeichen)
     *  128 kbit/44 kHz  -> 1257  (1 361 755 fuer 1083 Zeichen)
     * Der 64er Wert ist daraus verhaeltnisgleich abgeleitet, mit Luft nach oben.
     */
    private const TTS_ELEVEN_QUALITIES = [
        '32'  => ['format' => 'mp3_22050_32',  'bytes' => 300],
        '64'  => ['format' => 'mp3_44100_64',  'bytes' => 660],
        '128' => ['format' => 'mp3_44100_128', 'bytes' => 1300],
    ];

    /**
     * Die beste Qualitaet, die fuer DIESEN Text noch in EINE Aufnahme passt.
     *
     * Dasselbe Vorgehen wie bei Bildern und PDFs: nicht raten und nicht einstellen
     * lassen, sondern an der Kernoption ausrichten. OutputLimit() liest
     * ScriptOutputBufferLimit zur Laufzeit — wer sie hochstellt, bekommt hier ohne
     * weiteres Zutun besseren Klang, wer sie senkt, bekommt eine Aufnahme, die
     * ueberhaupt ankommt.
     *
     * Warum „in eine Aufnahme": jede Naht zwischen zwei Aufnahmen ist hoerbar, die
     * Sprechmelodie setzt neu an. Ein Stueck in 64 kbit klingt besser als zwei in
     * 128. Deshalb faellt die Stufe, wenn der Text lang wird — und steigt wieder,
     * wenn er kurz ist.
     *
     * 80 Prozent der Grenze, nicht 100: die Schaetzung je Zeichen schwankt mit der
     * Sprechweise (Pausen kosten Zeit ohne Text), und eine zu grosse Aufnahme waere
     * gar keine — der Anbieter liefert sie, aber die Hook-Ausgabe ERSETZT die
     * Antwort.
     *
     * Passt selbst die sparsamste Stufe nicht, gilt trotzdem sie: dann teilt
     * BriefingSpeechParts, und das ist besser als nichts.
     *
     * @return array{format:string,bytes:int}
     */
    private function TtsElevenQualityFor(int $zeichen): array
    {
        // Ausdrueckliche Wahl schlaegt die Rechnung — fuer den Fall, dass jemand
        // bewusst kleine Dateien will (langsame Verbindung).
        $wahl = $this->TtsSetting('TtsElevenQuality', 'auto');
        if (isset(self::TTS_ELEVEN_QUALITIES[$wahl])) {
            return self::TTS_ELEVEN_QUALITIES[$wahl];
        }
        $platz = (int)($this->OutputLimit() * 0.8);
        $zeichen = max(1, $zeichen);
        // Absteigend: die erste Stufe, die passt, ist die beste, die passt.
        foreach (['128', '64', '32'] as $stufe) {
            if ($zeichen * self::TTS_ELEVEN_QUALITIES[$stufe]['bytes'] <= $platz) {
                return self::TTS_ELEVEN_QUALITIES[$stufe];
            }
        }
        return self::TTS_ELEVEN_QUALITIES['32'];
    }

    /**
     * Das Format fuer die Adresse. Im Rumpf wird output_format ignoriert.
     *
     * `$vorgabe` ist das Format, das der Aufrufer schon bestimmt hat (das Briefing
     * waehlt es EINMAL fuer den ganzen Text, damit alle Stuecke gleich klingen).
     * Ohne Vorgabe entscheidet die Textlaenge.
     */
    private function TtsElevenFormat(string $text, string $vorgabe = ''): string
    {
        if (str_starts_with($vorgabe, 'mp3_')) {
            return $vorgabe;
        }
        return $this->TtsElevenQualityFor(mb_strlen($text))['format'];
    }

    /**
     * Stimmen von gpt-4o-mini-tts. Feste Liste, weil sie zum MODELL gehoert und nicht
     * zum Konto — anders als bei ElevenLabs.
     *
     * Zwoelf, nicht dreizehn: das sind die, die hier nachweislich benutzt oder
     * abgehoert wurden (acht in den Personas, dazu ballad, coral, marin und echo aus
     * den Hoerproben). Das Modell hat noch eine weitere, die ich nicht benennen kann
     * — eine geratene Kennung waere eine Auswahl, die beim Anbieter abgelehnt wird.
     */
    private const TTS_OPENAI_VOICES = [
        'alloy', 'ash', 'ballad', 'coral', 'echo', 'fable',
        'marin', 'nova', 'onyx', 'sage', 'shimmer', 'verse',
    ];

    /**
     * Die deutschen neuronalen Stimmen von Azure. Ebenfalls fest: sie gehoeren zum
     * Dienst. „Multilingual" spricht mehrere Sprachen mit derselben Stimme.
     */
    private const TTS_AZURE_GERMAN_VOICES = [
        'de-DE-KatjaNeural', 'de-DE-ConradNeural', 'de-DE-ChristophNeural',
        'de-DE-KillianNeural', 'de-DE-RalfNeural', 'de-DE-KasperNeural',
        'de-DE-BerndNeural', 'de-DE-AmalaNeural', 'de-DE-ElkeNeural',
        'de-DE-GiselaNeural', 'de-DE-KlarissaNeural', 'de-DE-LouisaNeural',
        'de-DE-MajaNeural', 'de-DE-TanjaNeural', 'de-DE-FlorianMultilingualNeural',
        'de-DE-SeraphinaMultilingualNeural',
    ];


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
        // Zweiter Anbieter. Grund fuer die Wahl: bei OpenAI wird `speed` ignoriert
        // (gemessen), Azure steuert Tempo und Pausen ueber SSML verbindlich, und es
        // hat 17 deutsche Stimmen statt 13 gemischtsprachiger. ACHTUNG bei den
        // Stilen: `mstts:express-as` kennen die DEUTSCHEN Stimmen fast nicht — nur
        // ConradNeural, und dort nur „cheerful" und „sad". Der Ausdruck kommt hier
        // also aus Stimmwahl plus prosody, nicht aus Stilnamen.
        $this->RegisterPropertyString('TtsProvider', 'openai');   // openai | azure | elevenlabs
        $this->RegisterPropertyString('TtsAzureKey', '');
        $this->RegisterPropertyString('TtsAzureRegion', 'westeurope');
        // Dritter Anbieter. Braucht einen KOSTENPFLICHTIGEN Zugang: die freie Stufe
        // hat keine kommerzielle Lizenz und verlangt Namensnennung im Ergebnis.
        // Eine Stimme statt einer Liste: welche Stimmen ein Konto hat, weiss nur das
        // Konto — die Kennungen sind nicht allgemeingueltig. Deshalb eintragbar, mit
        // einer der oeffentlichen Vorgabestimmen als Startwert.
        $this->RegisterPropertyString('TtsElevenKey', '');
        $this->RegisterPropertyString('TtsElevenVoice', self::TTS_ELEVEN_VOICE);
        $this->RegisterPropertyString('TtsElevenModel', 'eleven_multilingual_v2');
        // Welche Stimmen der Abruf anbietet. Einstellbar, weil sich am echten Konto
        // gezeigt hat, dass die API die Rubriken der Weboberflaeche NICHT nachbilden
        // kann: sie unterscheidet nur „selbst erzeugt" von „aus der Bibliothek
        // kopiert", und alle Kopien sind in jedem Feld identisch. Wer eine andere
        // Menge sehen will, waehlt sie hier, statt dass ich sie errate.
        $this->RegisterPropertyString('TtsElevenScope', 'bookmarked');
        // „auto": die Stufe richtet sich an ScriptOutputBufferLimit und an der
        // Textlaenge aus — wie bei Bildern und PDFs (siehe TtsElevenQualityFor).
        // 32/64/128 sind ausdrueckliche Vorgaben fuer den Fall, dass jemand bewusst
        // kleine Dateien will.
        $this->RegisterPropertyString('TtsElevenQuality', 'auto');
        /* Vierter Anbieter: Amazon Polly. Braucht ZWEI Geheimnisse statt einem —
           Zugriffsschluessel und geheimer Schluessel — und unterschreibt jede
           Anfrage (Signature Version 4, siehe AwsSigV4). Grund fuer die Aufnahme:
           Polly rechnet nach Zeichen ohne Monatsmindestbetrag, hat mehrere
           deutsche Neural-Stimmen und laeuft in Frankfurt.
           Die Engine steht auf `neural`: `standard` klingt deutlich blecherner,
           kostet aber nur wenig weniger. */
        $this->RegisterPropertyString('TtsPollyKey', '');
        $this->RegisterPropertyString('TtsPollySecret', '');
        $this->RegisterPropertyString('TtsPollyRegion', self::TTS_POLLY_REGION);
        $this->RegisterPropertyString('TtsPollyVoice', self::TTS_POLLY_VOICE);
        $this->RegisterPropertyString('TtsPollyEngine', 'neural');
        // Die abgerufenen Polly-Stimmen — wie bei ElevenLabs, damit die Auswahl
        // ohne HTTP-Abruf bei jedem Formularaufbau auskommt.
        $this->RegisterAttributeString('TtsPollyVoiceCache', '[]');
        // Hash → ['id' => Medien-ID, 'at' => Zeitstempel]
        $this->RegisterAttributeString('TtsCache', '{}');
        $this->RegisterAttributeString('TtsCategory', '');
        // Die abgerufenen ElevenLabs-Stimmen. Abgelegt, damit die Personas-Liste sie
        // als Auswahl anbieten kann — ein HTTP-Abruf bei jedem Formularaufbau waere
        // eine Wartezeit bei jedem Oeffnen, auch wenn niemand Stimmen sucht.
        $this->RegisterAttributeString('TtsElevenVoiceCache', '[]');
    }

    /**
     * Die Ansage hängt an OpenAI: Anthropic hat keine Sprachausgabe. Ohne Schlüssel
     * bleibt die Funktion aus, statt im Laden stumm zu scheitern.
     */
    private function TtsEnabled(): bool
    {
        if (!$this->TtsStorageReady()) {
            return false;
        }
        if ($this->TtsProvider() === 'elevenlabs') {
            // Wie bei Azure: eigener Dienst, eigener Schluessel — unabhaengig davon,
            // welcher Anbieter die TEXTE schreibt.
            return $this->TtsSetting('TtsElevenKey', '') !== '';
        }
        if ($this->TtsProvider() === 'polly') {
            // Zwei Geheimnisse UND die Region: ohne Region gibt es keinen Wirt,
            // an den die Anfrage ginge, und ohne beide Schluessel keine
            // Unterschrift.
            return $this->TtsSetting('TtsPollyKey', '') !== ''
                && $this->TtsSetting('TtsPollySecret', '') !== ''
                && $this->TtsSetting('TtsPollyRegion', '') !== '';
        }
        if ($this->TtsProvider() === 'azure') {
            // Azure haengt NICHT am KI-Anbieter: die Sprachausgabe ist ein eigener
            // Dienst mit eigenem Schluessel. Man kann also mit Anthropic texten und
            // mit Azure sprechen — bei OpenAI ging das nie, dort ist es derselbe
            // Schluessel.
            return $this->TtsSetting('TtsAzureKey', '') !== ''
                && $this->TtsSetting('TtsAzureRegion', '') !== '';
        }
        return $this->ReadPropertyString('AiProvider') === 'openai'
            && trim($this->ReadPropertyString('AiOpenAIKey')) !== ''
            && true;
    }

    /** openai | azure | elevenlabs | polly — ueber TtsSetting, weil die
     *  Eigenschaften erst nach dem naechsten Kernel-Start existieren. */
    private function TtsProvider(): string
    {
        $wahl = $this->TtsSetting('TtsProvider', 'openai');
        return in_array($wahl, ['azure', 'elevenlabs', 'polly'], true) ? $wahl : 'openai';
    }

    /**
     * Das Tonformat haengt am Anbieter, nicht am Geschmack: AAC gibt es bei Azure
     * nicht. Wer den Anbieter wechselt, bekommt also MP3 — und weil das im
     * Schluessel steckt (TtsHash), entsteht die Aufnahme sauber neu statt aus dem
     * Zwischenspeicher der anderen Seite zu kommen.
     */
    private function TtsFormat(string $wunsch): string
    {
        if ($this->TtsProvider() === 'elevenlabs') {
            // Dort gibt es weder AAC noch FLAC. MP3 in allen Faellen.
            return 'mp3';
        }
        if ($this->TtsProvider() === 'polly') {
            // Polly kennt mp3, ogg_vorbis und pcm — kein AAC, kein FLAC.
            return 'mp3';
        }
        if ($this->TtsProvider() !== 'azure') {
            return $wunsch;
        }
        return $wunsch === 'flac' ? 'flac' : 'mp3';
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
                'Speech output was just installed — restart the Symcon kernel once', 503);
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
    /**
     * Uhrzeiten ausschreiben — NUR auf dem Weg zur Stimme.
     *
     * ElevenLabs liest „14:15" uneinheitlich: mal als Uhrzeit, mal als zwei
     * getrennte Zahlen, gelegentlich englisch. Ausgeschrieben ist die Aussprache
     * festgelegt. Der angezeigte Text behaelt die Ziffern, denn gelesen ist
     * „14:15" die bessere Form — die beiden Fassungen durften auseinandergehen.
     *
     * Drei Formen, in dieser Reihenfolge: „14:15" (ein angehaengtes „Uhr" wird
     * mitgenommen, sonst stuende danach „vierzehn Uhr fuenfzehn Uhr"), dann das
     * halb ausgeschriebene „14 Uhr 15", zuletzt das blosse „18 Uhr". Zahlen ohne
     * „Uhr" bleiben unberuehrt: „14 Artikel" ist keine Uhrzeit, und „um 8" ist
     * von einer Anzahl nicht zu unterscheiden.
     */
    private function TtsTimesAsWords(string $text): string
    {
        $uhr = fn (array $t): string => $this->TtsClockWords((int)$t[1], (int)($t[2] ?? 0));
        $regeln = [
            '/\b([01]?\d|2[0-3]):([0-5]\d)(?:\s*(?:Uhr|h)\b)?/u',
            '/\b([01]?\d|2[0-3])\s*Uhr\s*([0-5]?\d)\b(?!\s*\d)/u',
            '/\b([01]?\d|2[0-3])\s*Uhr\b/u',
        ];
        foreach ($regeln as $regel) {
            $text = (string)preg_replace_callback($regel, $uhr, $text);
        }
        return $text;
    }

    /** „vierzehn Uhr fuenfzehn". Volle Stunde ohne Minutenangabe. */
    private function TtsClockWords(int $stunde, int $minute): string
    {
        // „ein Uhr", nicht „eins Uhr": vor „Uhr" steht die Stunde attributiv.
        // Bei einundzwanzig Uhr gilt das nicht — dort ist die Eins schon gebeugt.
        $wort = $stunde === 1 ? 'ein' : $this->TtsNumberWord($stunde);
        return $minute === 0 ? $wort . ' Uhr' : $wort . ' Uhr ' . $this->TtsNumberWord($minute);
    }

    /** Zahlwort fuer 0 bis 99 — Uhrzeit braucht 59, die Jahreszahl mehr. */
    private function TtsNumberWord(int $zahl): string
    {
        $klein = ['null', 'eins', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun',
                  'zehn', 'elf', 'zwölf', 'dreizehn', 'vierzehn', 'fünfzehn', 'sechzehn', 'siebzehn',
                  'achtzehn', 'neunzehn'];
        if ($zahl < 0 || $zahl > 99) {
            return (string)$zahl;
        }
        if ($zahl < 20) {
            return $klein[$zahl];
        }
        // sechzig und siebzig, nicht sechszig und siebenzig — wie bei 16 und 17.
        $zehner = [2 => 'zwanzig', 3 => 'dreißig', 4 => 'vierzig', 5 => 'fünfzig',
                   6 => 'sechzig', 7 => 'siebzig', 8 => 'achtzig', 9 => 'neunzig'];
        $z = intdiv($zahl, 10);
        $e = $zahl % 10;
        if ($e === 0) {
            return $zehner[$z];
        }
        // „einundzwanzig", nicht „einsundzwanzig". Sechs und sieben bleiben voll:
        // „sechsundzwanzig", „siebenundzwanzig" — anders als 16 und 17.
        $vorne = $e === 1 ? 'ein' : $klein[$e];
        return $vorne . 'und' . $zehner[$z];
    }

    /**
     * Datumsangaben ausschreiben — wie bei den Uhrzeiten nur fuer die Stimme.
     *
     * „am 24.08." liest die KI als zwei Zahlen mit Punkten. Drei Formen kommen im
     * Briefing vor: die Kurzform aus BriefingDayWord, die volle Form mit Jahr aus
     * der Kopfzeile des Auftrags, und „24. August", das das Modell selbst schreibt.
     *
     * Der Fall ist am Wort DAVOR entschieden: nach „am", „bis", „seit", „vom",
     * „ab" steht der Dativ („am vierundzwanzigsten August"), nach „der", „die",
     * „das", „ist" der Nominativ („der vierundzwanzigste August"). Alles andere
     * bekommt den Dativ, weil im Briefing praktisch immer eine Praeposition
     * davorsteht; die Verwechslung waere ein Schoenheitsfehler, kein Vorlesefehler.
     */
    private function TtsDatesAsWords(string $text): string
    {
        $monate = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
                   'August', 'September', 'Oktober', 'November', 'Dezember'];
        // Das Wort davor wird mitgelesen und unveraendert wieder ausgegeben; es
        // entscheidet nur den Fall.
        $vor = '(?:(?<vor>\p{L}+)(?<lz>\s+))?';

        // Alle Gruppen sind BENANNT. Benannte Gruppen bekommen in PHP zusaetzlich
        // eine Nummer, und die zaehlt das Wort davor mit — mit $t[1] fuer den Tag
        // erkannte die Umschrift kein einziges Datum.
        $bauen = function (array $t, int $tag, int $monat, string $jahr = '') use ($monate): ?string {
            if ($tag < 1 || $tag > 31 || $monat < 1 || $monat > 12) {
                return null;   // kein Datum: unangetastet lassen
            }
            $wort  = (string)($t['vor'] ?? '');
            $davor = mb_strtolower($wort);
            // Drei Endungen, drei Faelle: nach Artikel die schwache („der dritte
            // Oktober"), als Satzaussage ohne Artikel die starke („Abgabe ist
            // dritter Oktober"), sonst der Dativ („am dritten Oktober").
            if (in_array($davor, ['der', 'die', 'das', 'dieser', 'jeder'], true)) {
                $form = 'schwach';
            } elseif (in_array($davor, ['ist', 'war', 'wird', 'bleibt'], true)) {
                $form = 'stark';
            } else {
                $form = 'dativ';
            }
            $satz = $this->TtsOrdinalWord($tag, $form) . ' ' . $monate[$monat];
            if ($jahr !== '') {
                $satz .= ' ' . $this->TtsYearWord((int)$jahr);
            }
            return $wort === '' ? $satz : $wort . ($t['lz'] ?? ' ') . $satz;
        };

        // 1) 24.08.2026 — mit Jahr, kein abschliessender Punkt im Weg.
        $text = (string)preg_replace_callback(
            '/' . $vor . '\b(?<tag>\d{1,2})\.(?<monat>\d{1,2})\.(?<jahr>\d{4})(?!\d)/u',
            fn (array $t): string => $bauen($t, (int)$t['tag'], (int)$t['monat'], $t['jahr']) ?? $t[0],
            $text);

        // 2) 24.08. am Satzende. Der Punkt der Kurzform IST hier zugleich der
        //    Schlusspunkt des Satzes — ohne ihn liefen zwei Saetze ineinander.
        $text = (string)preg_replace_callback(
            '/' . $vor . '\b(?<tag>\d{1,2})\.(?<monat>\d{1,2})\.(?!\d)(?=\s+\p{Lu}|\s*$)/u',
            fn (array $t): string => ($g = $bauen($t, (int)$t['tag'], (int)$t['monat'])) === null ? $t[0] : $g . '.',
            $text);

        // 3) 24.08. mitten im Satz — hier gehoert kein Punkt hin.
        $text = (string)preg_replace_callback(
            '/' . $vor . '\b(?<tag>\d{1,2})\.(?<monat>\d{1,2})\.(?!\d)/u',
            fn (array $t): string => $bauen($t, (int)$t['tag'], (int)$t['monat']) ?? $t[0],
            $text);

        // 4) „24. August" — Monat schon ausgeschrieben, nur der Tag fehlt.
        $text = (string)preg_replace_callback(
            '/' . $vor . '\b(?<tag>\d{1,2})\.\s*(?<monat>' . implode('|', $monate) . ')\b(?:\s+(?<jahr>\d{4})\b)?/u',
            function (array $t) use ($monate, $bauen): string {
                // Die Jahreszahl mitnehmen, sonst stuende der Monat ausgeschrieben
                // und daneben „2026" wieder als Ziffernfolge.
                $monat = (int)array_search($t['monat'], $monate, true);
                return $bauen($t, (int)$t['tag'], $monat, (string)($t['jahr'] ?? '')) ?? $t[0];
            },
            $text);

        return $text;
    }

    /**
     * Ordnungszahl 1 bis 31. Bis 19 auf „-te", ab 20 auf „-ste"; vier Formen
     * folgen der Regel nicht: erste, dritte, siebte, achte.
     *
     * $form waehlt die Endung: 'dativ' fuer „am dritten Oktober", 'schwach' fuer
     * „der dritte Oktober", 'stark' fuer „Abgabe ist dritter Oktober".
     */
    private function TtsOrdinalWord(int $tag, string $form = 'dativ'): string
    {
        $sonder = [1 => 'erste', 3 => 'dritte', 7 => 'siebte', 8 => 'achte'];
        if (isset($sonder[$tag])) {
            $wort = $sonder[$tag];
        } elseif ($tag < 20) {
            $wort = $this->TtsNumberWord($tag) . 'te';
        } else {
            $wort = $this->TtsNumberWord($tag) . 'ste';
        }
        return match ($form) {
            'stark'   => $wort . 'r',
            'schwach' => $wort,
            default   => $wort . 'n',
        };
    }

    /** Jahreszahl: zweitausendsechsundzwanzig, neunzehnhundertneunundneunzig. */
    private function TtsYearWord(int $jahr): string
    {
        if ($jahr >= 2000 && $jahr <= 2099) {
            $rest = $jahr - 2000;
            return $rest === 0 ? 'zweitausend' : 'zweitausend' . $this->TtsNumberWord($rest);
        }
        if ($jahr >= 1900 && $jahr <= 1999) {
            $rest = $jahr - 1900;
            return $rest === 0 ? 'neunzehnhundert' : 'neunzehnhundert' . $this->TtsNumberWord($rest);
        }
        // Alles ausserhalb bleibt, wie es ist — geraten wird hier nicht.
        return (string)$jahr;
    }

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
    private function TtsHash(string $text, string $stimme = '', string $anweisung = '', string $format = 'mp3'): string
    {
        // Modell und Vorgabestimme je Anbieter: bei ElevenLabs stehen sie in eigenen
        // Feldern. Ohne diese Unterscheidung aendert ein Stimmwechsel dort den
        // Schluessel NICHT — die alte Aufnahme spielte weiter, obwohl eine andere
        // Stimme eingestellt ist.
        if ($this->TtsProvider() === 'elevenlabs') {
            // Das WIRKLICHE Tonformat gehoert in den Schluessel, nicht nur unser
            // Kurzname „mp3". Sonst liefert der Zwischenspeicher nach einer
            // Formataenderung weiter die alte Aufnahme — genau das waere nach dem
            // Umzug von output_format in die Adresse passiert: dieselbe Ansage, aber
            // noch in 128 kbit/s und damit viermal so gross.
            // Das WIRKLICHE Format in den Schluessel — es haengt an der Textlaenge
            // und an der Kernoption, kann sich also aendern. Genau derselbe Aufruf
            // wie beim Versand, sonst zeigte der Schluessel auf eine Aufnahme in
            // einem anderen Format.
            $modell  = $this->TtsSetting('TtsElevenModel', 'eleven_multilingual_v2')
                . '|' . $this->TtsElevenFormat($text, $format);
            $vorgabe = $this->TtsSetting('TtsElevenVoice', self::TTS_ELEVEN_VOICE);
        } else {
            $modell  = $this->TtsSetting('TtsModel', 'gpt-4o-mini-tts');
            $vorgabe = $this->TtsSetting('TtsVoice', 'alloy');
        }
        return substr(hash('sha256',
            // Der Anbieter gehoert dazu, aus demselben Grund wie das Format: die
            // Aufnahme ist eine andere, der Text derselbe.
            $this->TtsProvider() . '|' .
            $modell . '|' .
            ($stimme !== '' ? $stimme : $vorgabe) . '|' .
            ($anweisung !== '' ? $anweisung : $this->TtsSetting('TtsInstructions', self::TTS_INSTRUCTIONS)) . '|' .
            // Das Format gehoert dazu: Es steckt nicht im Text, macht aber eine ANDERE
            // Aufnahme. Ohne es wuerde nach einem Formatwechsel die alte weiterspielen —
            // der Schluessel passte ja noch.
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
    private function TtsProduce(string $hash, string $text, string $stimme = '', string $anweisung = '', string $format = 'mp3'): int
    {
        $mp3 = $this->TtsRequestAudio($text, $stimme, $anweisung, $format);
        if ($mp3 === '') {
            return 0;
        }
        // Symcons Hook gibt hoechstens 1 MB aus (gemessen: „Output-Buffer exceeds
        // Limit") — und Haeppchen mit flush() helfen nicht, die Grenze ist die
        // Summe. Was darueber liegt, waere nicht abholbar; dann lieber gar nichts
        // ablegen als eine Datei, die im Player stumm bleibt.
        if (strlen($mp3) > $this->OutputLimit()) {
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
    private function TtsRequestAudio(string $text, string $stimme = '', string $anweisung = '', string $format = 'mp3'): string
    {
        if ($this->TtsProvider() === 'elevenlabs') {
            return $this->TtsRequestEleven($text, $stimme, $anweisung, $format);
        }
        if ($this->TtsProvider() === 'azure') {
            return $this->TtsRequestAzure($text, $stimme, $anweisung, $format);
        }
        if ($this->TtsProvider() === 'polly') {
            return $this->TtsRequestPolly($text, $stimme, $anweisung, $format);
        }
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
        // KEIN `speed`: Fuer gpt-4o-mini-tts ist das Feld nicht dokumentiert und wird
        // ignoriert — derselbe Satz ergab am 21.08.2026 bei 1.0 und bei 1.14 dieselbe
        // Laenge (9,69 s gegen 9,95 s, die Streuung zweier Laeufe, nicht 14 %). Das
        // Tempo steuert man bei diesem Modell ueber `instructions`, siehe
        // BriefingSpeechStyle. Wer je auf tts-1 wechselt, wo es wirkt, muss es dort
        // wieder ergaenzen.
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

    /**
     * Amazon Polly: EIN unterschriebener POST, JSON im Rumpf, Audiobytes zurueck.
     *
     * Zwei Dinge unterscheiden ihn von den anderen dreien:
     *  - Jede Anfrage wird UNTERSCHRIEBEN (Signature Version 4). Ein Schluessel im
     *    Kopf reicht nicht; Rumpf, Pfad, Kopfzeilen und Zeitstempel gehen in die
     *    Rechnung ein. Sie steht in AwsSigV4 und ist dort gegen die offiziellen
     *    Pruefwerte von AWS belegt — ohne das saehe man bei einem Fehler nur ein
     *    „403 SignatureDoesNotMatch" und muesste raten.
     *  - Die Vorleseanweisung ist wieder Markup, aber ANDERES als bei Azure:
     *    Polly nimmt SSML nur, wenn TextType auf `ssml` steht, und kennt
     *    `mstts:express-as` nicht. Kommt hier ein SSML-Rumpf an, wird er
     *    durchgereicht; sonst schlichter Text.
     */
    private function TtsRequestPolly(string $text, string $stimme, string $anweisung, string $format): string
    {
        $schluessel = $this->TtsSetting('TtsPollyKey', '');
        $geheim     = $this->TtsSetting('TtsPollySecret', '');
        $region     = $this->TtsSetting('TtsPollyRegion', self::TTS_POLLY_REGION);
        if ($schluessel === '' || $geheim === '' || $region === '') {
            return '';
        }
        $stimme = $stimme !== '' ? $stimme : $this->TtsSetting('TtsPollyVoice', self::TTS_POLLY_VOICE);
        $engine = $this->TtsSetting('TtsPollyEngine', 'neural');

        /* SSML nur, wenn wirklich welches ankommt. Die Anweisungen aus
           BriefingSpeechStyle sind fuer Azure gebaut; ein <speak>-Rumpf passt
           auch hier, ein englischer Freitext („speak slowly") waere dagegen
           Text, den Polly VORLESEN wuerde. Deshalb die Pruefung auf das Markup
           und nicht auf „ist etwas gesetzt". */
        $ssml = trim($anweisung) !== '' && str_starts_with(ltrim($anweisung), '<');
        $inhalt = $ssml ? $anweisung : $text;
        if ($ssml && !str_contains($inhalt, '<speak')) {
            $inhalt = '<speak>' . $inhalt . '</speak>';
        }

        $felder = [
            'OutputFormat' => $format === 'ogg' ? 'ogg_vorbis' : 'mp3',
            'Text'         => $inhalt,
            'TextType'     => $ssml ? 'ssml' : 'text',
            'VoiceId'      => $stimme,
            'Engine'       => in_array($engine, ['neural', 'standard', 'long-form', 'generative'], true)
                ? $engine : 'neural',
            // Ohne Sprachangabe liest eine zweisprachige Stimme deutsche Texte
            // gern englisch. Bei rein deutschen Stimmen ist es folgenlos.
            'LanguageCode' => 'de-DE',
        ];
        $rumpf = (string)json_encode($felder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $wirt  = 'polly.' . $region . '.amazonaws.com';

        $kopf = AwsSigV4::Headers('POST', $wirt, '/v1/speech', '', $rumpf,
            ['Content-Type' => 'application/json'], $region, 'polly', $schluessel, $geheim);
        $zeilen = [];
        foreach ($kopf as $name => $wert) {
            $zeilen[] = $name . ': ' . $wert;
        }

        $resp = $this->AiHttpPost('https://' . $wirt . '/v1/speech', $zeilen, $rumpf);
        $status = (int)($resp['status'] ?? 0);
        $roh    = (string)($resp['body'] ?? '');
        if (($resp['err'] ?? '') !== '') {
            $this->SendDebug('TTS', 'Polly HTTP-Fehler: ' . $resp['err'], 0);
            return '';
        }
        if ($status !== 200 || $roh === '') {
            // Polly meldet Fehler als JSON — das ist die Meldung, nicht der Ton.
            // Der Hinweis auf die Schluessellaengen gehoert hier genauso hin: eine
            // stumme Ansage laesst sonst genauso raten wie ein leeres Stimmenfeld.
            $this->SendDebug('TTS', 'Polly Antwort ' . $status . ': ' . mb_substr($roh, 0, 300)
                . $this->TtsPollyCredentialHint($schluessel, $geheim), 0);
            return '';
        }
        if (str_starts_with(ltrim($roh), '{')) {
            $this->SendDebug('TTS', 'Polly: erwartete Audiodaten, bekam JSON: ' . mb_substr($roh, 0, 300), 0);
            return '';
        }
        return $roh;
    }

    /**
     * Sehen die Polly-Zugangsdaten ueberhaupt nach Zugangsdaten aus?
     *
     * Amazon antwortet auf jeden Formfehler mit demselben 403
     * „SignatureDoesNotMatch" — dieselbe Meldung, die auch ein Fehler in der
     * Rechnung ergaebe. Man raet dann zwischen „Schluessel falsch" und „Modul
     * kaputt". Die Laengen sind bei AWS fest: die Kennung hat 20 Zeichen, das
     * Geheimnis 40. Ein 20 Zeichen langes Geheimnis ist die Kennung, zweimal
     * eingefuegt — der haeufigste Fall.
     *
     * Bewusst nur ein HINWEIS und keine Sperre: aendert AWS das Format, soll das
     * Modul nicht den Dienst verweigern.
     *
     * @return string Leer, wenn nichts auffaellt.
     */
    private function TtsPollyCredentialHint(string $schluessel, string $geheim): string
    {
        if (strlen($geheim) > 0 && strlen($geheim) < 40) {
            return ' ' . $this->Translate('The secret access key looks too short — AWS uses 40 characters (the 20-character value is the access key ID).');
        }
        if (strlen($schluessel) > 0 && strlen($schluessel) !== 20) {
            return ' ' . $this->Translate('The access key ID looks unusual — AWS uses 20 characters.');
        }
        return '';
    }

    /**
     * Die deutschen Stimmen des Kontos abrufen und ablegen.
     *
     * Wie bei ElevenLabs abgelegt statt bei jedem Formularaufbau geholt: ein
     * HTTP-Abruf beim Oeffnen waere eine Wartezeit fuer jeden, auch fuer den, der
     * gar keine Stimme sucht.
     *
     * @return string Meldung fuer die Statuszeile
     */
    private function TtsPollyVoiceList(): string
    {
        $schluessel = $this->TtsSetting('TtsPollyKey', '');
        $geheim     = $this->TtsSetting('TtsPollySecret', '');
        $region     = $this->TtsSetting('TtsPollyRegion', self::TTS_POLLY_REGION);
        if ($schluessel === '' || $geheim === '' || $region === '') {
            return $this->Translate('Enter access key, secret and region first.');
        }
        $wirt    = 'polly.' . $region . '.amazonaws.com';
        // Die Abfrage MUSS kanonisch sein (sortiert, kodiert) — sie geht in die
        // Unterschrift ein.
        $abfrage = 'LanguageCode=de-DE';
        $kopf    = AwsSigV4::Headers('GET', $wirt, '/v1/voices', $abfrage, '', [],
            $region, 'polly', $schluessel, $geheim);
        $zeilen = [];
        foreach ($kopf as $name => $wert) {
            $zeilen[] = $name . ': ' . $wert;
        }
        $resp = $this->TtsHttpGet('https://' . $wirt . '/v1/voices?' . $abfrage, $zeilen);
        $status = (int)($resp['status'] ?? 0);
        $roh    = (string)($resp['body'] ?? '');
        if ($status !== 200) {
            $this->SendDebug('TTS', 'Polly Stimmen ' . $status . ': ' . mb_substr($roh, 0, 300), 0);
            return sprintf($this->Translate('Could not fetch voices (HTTP %d).'), $status)
                . $this->TtsPollyCredentialHint($schluessel, $geheim);
        }
        $daten = json_decode($roh, true);
        $liste = [];
        foreach ((array)($daten['Voices'] ?? []) as $v) {
            if (!is_array($v) || trim((string)($v['Id'] ?? '')) === '') {
                continue;
            }
            $liste[] = [
                'id'   => (string)$v['Id'],
                'name' => trim(sprintf('%s (%s%s)', (string)$v['Id'],
                    (string)($v['Gender'] ?? ''),
                    // Welche Engines die Stimme kann, entscheidet, ob `neural`
                    // ueberhaupt geht — deshalb steht es im Namen.
                    ($v['SupportedEngines'] ?? []) ? ', ' . implode('/', (array)$v['SupportedEngines']) : '')),
            ];
        }
        usort($liste, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        @$this->WriteAttributeString('TtsPollyVoiceCache', (string)json_encode($liste, JSON_UNESCAPED_UNICODE));
        return sprintf($this->Translate('%d German voices fetched.'), count($liste));
    }

    /** @return list<array{id:string,name:string}> Die abgelegten Polly-Stimmen. */
    private function TtsPollyCachedVoices(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('TtsPollyVoiceCache'), true);
        return is_array($roh) ? array_values(array_filter($roh, 'is_array')) : [];
    }

    /**
     * Azure Speech: EIN POST, SSML im Rumpf, Audiobytes zurueck.
     *
     * Zwei Dinge sind hier anders als bei OpenAI und beide sind Fallen:
     *  - Die Vorleseanweisung ist kein Freitext, sondern Markup. Was dort ankommt,
     *    ist ein SSML-Rumpf aus BriefingSpeechStyle bzw. der Vorgabe unten; ein
     *    englischer Satz („speak slowly") hat hier keine Wirkung.
     *  - Der Text MUSS maskiert werden. Ein „&" oder ein „<" im Briefing (etwa in
     *    „Müller & Sohn") macht das SSML sonst ungueltig, und Azure antwortet mit
     *    400 statt mit Ton.
     */
    private function TtsRequestAzure(string $text, string $stimme, string $anweisung, string $format): string
    {
        $key    = $this->TtsSetting('TtsAzureKey', '');
        $region = $this->TtsSetting('TtsAzureRegion', '');
        if ($key === '' || $region === '') {
            return '';
        }
        $voice = $stimme !== '' ? $stimme : self::TTS_AZURE_VOICE;
        // Die Sprache steckt im Stimmnamen („de-DE-KatjaNeural"); xml:lang muss dazu
        // passen, sonst spricht die Stimme den Text mit fremder Lautung.
        $lang = preg_match('/^([a-z]{2}-[A-Z]{2})-/', $voice, $m) === 1 ? $m[1] : 'de-DE';
        $ssml = '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" '
            . 'xmlns:mstts="https://www.w3.org/2001/mstts" xml:lang="' . $lang . '">'
            . '<voice name="' . htmlspecialchars($voice, ENT_QUOTES | ENT_XML1, 'UTF-8') . '">'
            . ($anweisung !== '' ? $anweisung : '<prosody rate="0%">%%TEXT%%</prosody>')
            . '</voice></speak>';
        // Der Text wird EINGESETZT, nicht angehaengt: die Anweisung bringt ihre
        // eigenen Huellen mit (prosody, express-as), und der Text gehoert hinein.
        $ssml = str_replace('%%TEXT%%', htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8'), $ssml);

        $resp = $this->AiHttpPost(
            'https://' . rawurlencode($region) . '.tts.speech.microsoft.com/cognitiveservices/v1',
            [
                'Ocp-Apim-Subscription-Key: ' . $key,
                // charset ausdruecklich: der Text ist voller Umlaute, und
                // htmlspecialchars laesst die als rohes UTF-8 stehen (nur & < > " '
                // werden maskiert). XML nimmt ohne Angabe zwar UTF-8 an, aber darauf
                // soll sich hier nichts verlassen.
                'Content-Type: application/ssml+xml; charset=utf-8',
                'X-Microsoft-OutputFormat: ' . self::TtsAzureFormat($format),
                'User-Agent: SymDoGateway',
            ],
            $ssml
        );
        $status = (int)($resp['status'] ?? 0);
        $roh    = (string)($resp['body'] ?? '');
        if (($resp['err'] ?? '') !== '') {
            $this->SendDebug('TTS', 'Azure HTTP-Fehler: ' . $resp['err'], 0);
            return '';
        }
        if ($status !== 200 || $roh === '') {
            // Azure schickt im Fehlerfall eine knappe Textmeldung, kein JSON.
            $this->SendDebug('TTS', 'Azure Antwort ' . $status . ': ' . mb_substr($roh, 0, 300), 0);
            return '';
        }
        return $roh;
    }

    /**
     * Ein GET mit Kopfzeilen — eigener kleiner Helfer, KEIN AiHttpGet.
     *
     * Dessen zweiter Parameter sind gepinnte IP-Adressen, keine Kopfzeilen: der
     * Aufruf ist ein SSRF-gesicherter Abruf einer FREMDEN Webseite (Rezept-Links),
     * mit vorgetaeuschter Browser-Kennung. Ein API-Aufruf gegen einen festen
     * Endpunkt ist etwas anderes; die Formen zu vermischen hiesse, dass eine
     * Aenderung an einem der beiden Zwecke den anderen trifft.
     *
     * @param list<string> $headers
     * @return array{status:int,body:string,err:string}
     */
    private function TtsHttpGet(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $err  = ($body === false) ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => is_string($body) ? $body : '', 'err' => $err];
    }

    /**
     * Aktionen der Sprachausgabe. Ueber RequestAction und NICHT als neue public
     * TGW_-Methode: die gaebe es erst nach einem Kernel-Neustart (dieselbe
     * Begruendung wie beim KI-Relay in AppCore).
     */
    /**
     * Anbieter und Schluessel der Sprachausgabe — ein eigener Klapp-Bereich im
     * Briefing, damit die drei Anbieter nicht dreizehn Felder untereinander
     * legen, von denen hoechstens neun je gebraucht werden.
     *
     * Gezeigt wird NUR, was zum gewaehlten Anbieter gehoert. Die erste Anzeige
     * richtet sich nach dem gespeicherten Stand, jede Umstellung sofort ueber
     * TtsProviderPick — sonst saehe man bis zum Uebernehmen die falschen Felder.
     *
     * Der Aufrufer prueft, ob es die Eigenschaften schon gibt: sie entstehen in
     * Create() und damit erst nach einem Kernel-Neustart.
     */
    private function GetTtsProviderPanel(array $cfg): array
    {
        $ist = (string)($cfg['TtsProvider'] ?? 'openai');
        if (!in_array($ist, ['openai', 'azure', 'elevenlabs', 'polly'], true)) {
            $ist = 'openai';
        }
        // Die abgerufenen Stimmen als Auswahl; ohne Abruf bleibt das Feld ein
        // Textfeld-Ersatz mit der Vorgabestimme, damit man auch ohne Abruf
        // sprechen kann.
        $pollyStimmen = [['caption' => self::TTS_POLLY_VOICE, 'value' => self::TTS_POLLY_VOICE]];
        foreach ($this->TtsPollyCachedVoices() as $v) {
            if ((string)$v['id'] === self::TTS_POLLY_VOICE) {
                $pollyStimmen[0]['caption'] = (string)$v['name'];
                continue;
            }
            $pollyStimmen[] = ['caption' => (string)$v['name'], 'value' => (string)$v['id']];
        }
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Voice provider'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Select',
                    'name'    => 'TtsProvider',
                    'width'   => '400px',
                    'caption' => $this->Translate('Voice from'),
                    // Ohne onChange zeigte der Bereich die Felder des GESPEICHERTEN
                    // Anbieters: wer umstellte, sah bis zum Uebernehmen die
                    // falschen. Ueber IPS_RequestAction und nicht ueber eine neue
                    // public TGW_-Methode — die gaebe es erst nach einem
                    // Kernel-Neustart.
                    'onChange' => 'IPS_RequestAction($id, "TtsProviderPick", $TtsProvider);',
                    'options' => [
                        ['caption' => $this->Translate('OpenAI (same key as the AI)'), 'value' => 'openai'],
                        ['caption' => $this->Translate('Azure Speech (own key, German voices)'), 'value' => 'azure'],
                        ['caption' => $this->Translate('ElevenLabs (own key, paid account required)'), 'value' => 'elevenlabs'],
                        ['caption' => $this->Translate('Amazon Polly (own access key, billed per character)'), 'value' => 'polly'],
                    ]
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'TtsPollyKey',
                    'width'   => '400px',
                    'caption' => $this->Translate('AWS access key ID'),
                    'visible' => $ist === 'polly'
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'TtsPollySecret',
                    'width'   => '400px',
                    'caption' => $this->Translate('AWS secret access key'),
                    'visible' => $ist === 'polly'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'TtsPollyRegion',
                    'width'   => '200px',
                    'caption' => $this->Translate('AWS region (e.g. eu-central-1)'),
                    'visible' => $ist === 'polly'
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'TtsPollyVoice',
                    'width'   => '400px',
                    'caption' => $this->Translate('Polly voice'),
                    'options' => $pollyStimmen,
                    'visible' => $ist === 'polly'
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'TtsPollyEngine',
                    'width'   => '400px',
                    'caption' => $this->Translate('Polly engine'),
                    'options' => [
                        ['caption' => $this->Translate('neural (recommended)'), 'value' => 'neural'],
                        ['caption' => $this->Translate('generative (most natural, most expensive)'), 'value' => 'generative'],
                        ['caption' => $this->Translate('long-form (for long texts)'), 'value' => 'long-form'],
                        ['caption' => $this->Translate('standard (cheapest, noticeably tinnier)'), 'value' => 'standard'],
                    ],
                    'visible' => $ist === 'polly'
                ],
                [
                    'type'    => 'Button',
                    'name'    => 'TtsPollyVoicesButton',
                    'caption' => $this->Translate('Fetch German voices'),
                    'onClick' => 'IPS_RequestAction($id, "TtsPollyVoices", 0);',
                    'visible' => $ist === 'polly'
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'TtsPollyStatus',
                    'caption' => sprintf($this->Translate('%d voices stored.'), count($this->TtsPollyCachedVoices())),
                    'visible' => $ist === 'polly'
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'TtsPollyHint',
                    'visible' => $ist === 'polly',
                    'caption' => $this->Translate("Polly bills per character with no monthly minimum and runs in Frankfurt (eu-central-1). Every request is signed (Signature Version 4) — that is why it needs two secrets instead of one: access key ID and secret access key. Create them in the AWS console under IAM; the policy needs only „polly:SynthesizeSpeech“ and „polly:DescribeVoices“.\n\nThe engine decides how it sounds and what it costs: neural is the sensible default, generative is the most natural and by far the most expensive, standard is the cheapest and audibly tinnier.")
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'TtsOpenAiHint',
                    'visible' => $ist === 'openai',
                    'caption' => $this->Translate('OpenAI needs nothing further here: it speaks with the key already entered under "AI features". The voice is chosen per persona in the persona editor.')
                ],
                [
                    // PasswordTextBox wie jedes andere Geheimnis in diesem
                    // Formular (Client-Secrets, Mailgun-Schluessel, KI-Schluessel,
                    // CalDAV-Passwort). Als ValidationTextBox stand der Schluessel
                    // im Klartext auf dem Schirm — sichtbar bei jedem Blick ueber
                    // die Schulter und in jedem Bildschirmfoto der Konfiguration.
                    'type'    => 'PasswordTextBox',
                    'name'    => 'TtsAzureKey',
                    'width'   => '400px',
                    'caption' => $this->Translate('Azure Speech key'),
                    'visible' => $ist === 'azure'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'TtsAzureRegion',
                    'width'   => '200px',
                    'caption' => $this->Translate('Azure region (e.g. westeurope)'),
                    'visible' => $ist === 'azure'
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'TtsAzureHint',
                    'visible' => $ist === 'azure',
                    'caption' => $this->Translate('Azure brings 17 German voices instead of 13 mixed-language ones, and its speech markup really does control tempo and pauses — with OpenAI the speed parameter is ignored. Free tier F0: 0.5 million characters per month, which is about ten times our consumption. Note: the speaking styles (cheerful, sad, shouting) that Azure advertises exist for German on one single voice, so the character comes from the choice of voice and from tempo, not from style names.')
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'TtsElevenKey',
                    'width'   => '400px',
                    'caption' => $this->Translate('ElevenLabs API key'),
                    'visible' => $ist === 'elevenlabs'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'TtsElevenVoice',
                    'width'   => '400px',
                    'caption' => $this->Translate('ElevenLabs voice ID'),
                    'visible' => $ist === 'elevenlabs'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'TtsElevenModel',
                    'width'   => '400px',
                    'caption' => $this->Translate('ElevenLabs model (eleven_multilingual_v2 speaks German)'),
                    'visible' => $ist === 'elevenlabs'
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'TtsElevenQuality',
                    'width'   => '400px',
                    'caption' => $this->Translate('Audio quality'),
                    'visible' => $ist === 'elevenlabs',
                    'options' => [
                        ['caption' => $this->Translate('Automatic — best quality that still fits in one recording'), 'value' => 'auto'],
                        ['caption' => $this->Translate('Always high (128 kbit, like the ElevenLabs preview)'), 'value' => '128'],
                        ['caption' => $this->Translate('Always good (64 kbit)'), 'value' => '64'],
                        ['caption' => $this->Translate('Always thrifty (32 kbit at 22 kHz) — audibly dull'), 'value' => '32'],
                    ]
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'TtsElevenQualityHint',
                    'visible' => $ist === 'elevenlabs',
                    'caption' => sprintf($this->Translate('Automatic works like the guard for photos and PDFs: it reads the Symcon core option ScriptOutputBufferLimit at runtime and picks the best quality whose recording still fits into ONE piece — every seam between two recordings is audible, because the intonation starts anew. Your limit is currently %s, which is enough for about %d characters at 128 kbit. Raise the option and the sound improves by itself; lower it and you still get a recording that arrives.'),
                        $this->BriefingLimitText(), (int)($this->OutputLimit() * 0.8 / 1300))
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'TtsElevenScope',
                    'width'   => '400px',
                    'caption' => $this->Translate('Which voices to offer'),
                    'visible' => $ist === 'elevenlabs',
                    'options' => [
                        ['caption' => $this->Translate('My Voices (as on the ElevenLabs website)'), 'value' => 'bookmarked'],
                        ['caption' => $this->Translate('Own voices only (created or cloned by you)'), 'value' => 'personal'],
                        ['caption' => $this->Translate('Own plus every copy from the library'), 'value' => 'non-default'],
                        ['caption' => $this->Translate('All, including the default voices'), 'value' => 'all'],
                    ]
                ],
                [
                    'type'    => 'Button',
                    'name'    => 'TtsElevenVoicesButton',
                    'visible' => $ist === 'elevenlabs',
                    'caption' => $this->Translate('List voices of the account'),
                    'onClick' => 'IPS_RequestAction($id, \'TtsElevenVoices\', 0);'
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'TtsElevenStatus',
                    'visible' => $ist === 'elevenlabs',
                    'caption' => ' '
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'TtsElevenHint',
                    'visible' => $ist === 'elevenlabs',
                    'caption' => $this->Translate('ElevenLabs needs a PAID account: the free tier grants no commercial licence and requires every generated file to name ElevenLabs. It also has no voice per persona — which voices an account holds only that account knows, so all personas share the voice entered above and their character comes from the sliders (expressive against level). "List voices of the account" fetches the IDs available to your key.')
                ],
            ]
        ];
    }

    /**
     * Felder des gewaehlten Anbieters zeigen, die der anderen verbergen. Getrennt
     * von der Formularerzeugung, weil UpdateFormField nur aus einer laufenden
     * Aktion heraus wirkt.
     */
    private function TtsShowProviderFields(string $anbieter): void
    {
        $gruppen = [
            'openai'     => ['TtsOpenAiHint'],
            'azure'      => ['TtsAzureKey', 'TtsAzureRegion', 'TtsAzureHint'],
            'elevenlabs' => ['TtsElevenKey', 'TtsElevenVoice', 'TtsElevenModel',
                             'TtsElevenQuality', 'TtsElevenQualityHint', 'TtsElevenScope',
                             'TtsElevenVoicesButton', 'TtsElevenStatus', 'TtsElevenHint'],
            'polly'      => ['TtsPollyKey', 'TtsPollySecret', 'TtsPollyRegion', 'TtsPollyVoice',
                             'TtsPollyEngine', 'TtsPollyVoicesButton', 'TtsPollyStatus',
                             'TtsPollyHint'],
        ];
        if (!isset($gruppen[$anbieter])) {
            $anbieter = 'openai';
        }
        foreach ($gruppen as $key => $felder) {
            foreach ($felder as $feld) {
                $this->UpdateFormField($feld, 'visible', $key === $anbieter);
            }
        }
    }

    private function TtsRequestAction(string $ident, mixed $value): bool
    {
        if ($ident === 'TtsProviderPick') {
            $this->TtsShowProviderFields((string)$value);
            return true;
        }
        if ($ident === 'TtsPollyVoices') {
            $this->UpdateFormField('TtsPollyStatus', 'caption', $this->TtsPollyVoiceList());
            // Wie bei ElevenLabs: die Auswahl im Personen-Editor entstand beim Bau
            // des Formulars und wuesste sonst nichts von den neuen Stimmen.
            $this->RefreshPersonaVoicePicker();
            return true;
        }
        if ($ident !== 'TtsElevenVoices') {
            return false;
        }
        $this->UpdateFormField('TtsElevenStatus', 'caption', $this->TtsElevenVoiceList());
        // Der Abruf legt die Stimmen ab; die Auswahl im Personen-Editor entstand
        // aber schon beim Bau des Formulars und wuesste sonst nichts davon.
        // Unbedingt und nicht nur bei Erfolg: schlug der Abruf fehl, ist der
        // Zwischenspeicher unveraendert und das Nachziehen ohne Wirkung.
        $this->RefreshPersonaVoicePicker();
        return true;
    }

    /**
     * Die Stimmen abrufen, die der eingetragene Schluessel sehen darf.
     *
     * Noetig, weil die Kennungen NICHT allgemeingueltig sind: jedes Konto hat seine
     * eigenen. Ohne diese Liste muesste man sie aus der Weboberflaeche von
     * ElevenLabs heraussuchen und abtippen.
     */
    private function TtsElevenVoiceList(): string
    {
        $key = $this->TtsSetting('TtsElevenKey', '');
        if ($key === '') {
            return $this->Translate('No ElevenLabs key entered.');
        }
        // v2 statt v1, wegen `voice_type`. WELCHE Menge, entscheidet die Einstellung.
        //
        // Am echten Konto gemessen (22.08.2026, 27 Stimmen): die API unterscheidet
        //   category `generated`/`cloned`  = selbst erzeugt oder geklont   (1)
        //   category `professional`        = aus der Bibliothek kopiert    (5)
        //   category `premade`             = Vorgabestimmen jedes Kontos  (21)
        // `personal`/`non-community` liefern nur die erste Sorte, `non-default`
        // beide ersten, ohne Filter alle.
        //
        // Die Rubrik „Meine Stimmen" der Weboberflaeche ist KEINE dieser Mengen,
        // laesst sich aber nachbilden — ueber `is_bookmarked`, ein Feld, das schon
        // immer im Rumpf stand. Am 22.08.2026 nachgerechnet: von neun kopierten
        // Stimmen tragen sechs `true` und drei `false`; die drei mit `false` fehlen
        // in der Rubrik. Die Gegenprobe am Stand des Vortags (sechs Stimmen: eine
        // eigene, zwei gemerkte, drei nicht gemerkte) ergibt genau die drei, die
        // der Nutzer dort sah.
        //
        // Eigene Stimmen haben das Feld gar nicht (NULL). Deshalb wird auf
        // `=== false` geprueft und nicht auf „nicht wahr" — sonst fielen die
        // eigenen mit heraus, also gerade die, die sicher hineingehoeren.
        $umfang = $this->TtsSetting('TtsElevenScope', 'bookmarked');
        $art    = $umfang === 'bookmarked' ? 'non-default' : $umfang;
        $filter = in_array($art, ['personal', 'non-default', 'non-community'], true)
            ? '&voice_type=' . $art
            : '';
        $resp = $this->TtsHttpGet('https://api.elevenlabs.io/v2/voices?page_size=100' . $filter, [
            'xi-api-key: ' . $key,
            'Accept: application/json',
            'User-Agent: SymDoGateway',
        ]);
        $status = (int)($resp['status'] ?? 0);
        if (($resp['err'] ?? '') !== '' || $status !== 200) {
            return sprintf($this->Translate('Could not fetch the voices (HTTP %d).'), $status);
        }
        $stimmen = $this->TtsElevenParseVoices((string)($resp['body'] ?? ''), $umfang === 'bookmarked');
        if ($stimmen === []) {
            return $this->Translate('The key works, but "My Voices" is empty in this account.');
        }
        // Ablegen, damit die Personas-Liste sie als Auswahl anbieten kann. Scheitert
        // das Schreiben (Attribut vor dem Kernel-Neustart), bleibt die Anzeige
        // trotzdem — sie ist der eigentliche Zweck des Knopfes.
        @$this->WriteAttributeString('TtsElevenVoiceCache',
            (string)json_encode($stimmen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zeilen = [];
        foreach ($stimmen as $v) {
            $zeilen[] = $v['name'] . ($v['info'] !== '' ? ' (' . $v['info'] . ')' : '') . ' — ' . $v['id'];
        }
        return implode("\n", $zeilen);
    }

    /**
     * Die Antwort auf das Wesentliche eindampfen: Kennung, Name, ein paar Merkmale.
     *
     * Getrennt, weil daran zwei Dinge haengen — die Anzeige im Formular und der
     * Zwischenspeicher fuer die Personas-Liste. Beide sollen dasselbe sehen.
     *
     * @return list<array{id:string,name:string,info:string}>
     */
    private function TtsElevenParseVoices(string $rumpf, bool $nurGemerkte = false): array
    {
        $d = json_decode($rumpf, true);
        $roh = is_array($d) && is_array($d['voices'] ?? null) ? $d['voices'] : [];
        $raus = [];
        foreach (array_slice($roh, 0, 100) as $v) {
            if (!is_array($v)) {
                continue;
            }
            $id = trim((string)($v['voice_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            // Nur bei „Meine Stimmen": die nicht gemerkten Kopien aussortieren.
            if ($nurGemerkte && ($v['is_bookmarked'] ?? null) === false) {
                continue;
            }
            $merkmale = [];
            // Herkunft zuerst, denn sie erklaert die Liste: „eigene" sind selbst
            // erzeugte oder geklonte, „gespeichert" sind welche aus der Bibliothek.
            // Ohne diese Angabe sieht man in der Auswahl nicht, warum sechs Stimmen
            // dastehen, wenn man nur eine selbst gebaut hat.
            $art = (string)($v['category'] ?? '');
            if ($art === 'generated' || $art === 'cloned') {
                $merkmale[] = $this->Translate('own');
            } elseif ($art === 'professional') {
                // Gemerkt oder nur kopiert — der Unterschied ist sichtbar, sobald
                // eine weitere Menge gewaehlt ist, und erklaert dann die Zeilen,
                // die unter „Meine Stimmen" fehlen.
                $merkmale[] = ($v['is_bookmarked'] ?? null) === true
                    ? $this->Translate('saved')
                    : $this->Translate('library');
            }
            foreach (['language', 'accent', 'gender'] as $k) {
                $w = ((array)($v['labels'] ?? []))[$k] ?? null;
                if (is_scalar($w) && trim((string)$w) !== '') {
                    $merkmale[] = trim((string)$w);
                }
            }
            $raus[] = [
                'id'   => $id,
                'name' => trim((string)($v['name'] ?? '')) !== '' ? trim((string)$v['name']) : $id,
                'info' => implode(', ', $merkmale),
            ];
        }
        return $raus;
    }

    /**
     * Die abgelegten Stimmen — Quelle der Auswahlspalte in der Personas-Liste.
     *
     * @return list<array{id:string,name:string,info:string}>
     */
    private function TtsElevenCachedVoices(): array
    {
        $roh = json_decode($this->ReadAttributeStringSafe('TtsElevenVoiceCache', '[]'), true);
        if (!is_array($roh)) {
            return [];
        }
        $raus = [];
        foreach ($roh as $v) {
            if (is_array($v) && trim((string)($v['id'] ?? '')) !== '') {
                $raus[] = ['id' => (string)$v['id'], 'name' => (string)($v['name'] ?? $v['id']),
                           'info' => (string)($v['info'] ?? '')];
            }
        }
        return $raus;
    }

    /**
     * ElevenLabs. POST /v1/text-to-speech/{voice_id}, Schluessel im Kopf `xi-api-key`,
     * Antwort sind rohe Audiodaten.
     *
     * Der Ausdruck kommt hier NICHT aus einer Anweisung im Text (wie bei OpenAI) und
     * nicht aus SSML (wie bei Azure), sondern aus `voice_settings`. Deshalb reist die
     * Persona-Anweisung als Stellwerk-Angabe mit: `stability` niedrig heisst mehr
     * Ausdruck und mehr Streuung, hoch heisst gleichmaessig und flach.
     *
     * Das mehrsprachige Modell spricht Deutsch mit jeder Stimme; eine englisch
     * trainierte behaelt dabei einen leichten Akzent.
     */
    private function TtsRequestEleven(string $text, string $stimme, string $anweisung, string $format = ''): string
    {
        $key = $this->TtsSetting('TtsElevenKey', '');
        if ($key === '') {
            return '';
        }
        $voice = $stimme !== '' ? $stimme : $this->TtsSetting('TtsElevenVoice', self::TTS_ELEVEN_VOICE);
        // Nur was in einen Pfad gehoert: die Kennungen sind alphanumerisch. Ohne diese
        // Wache truege ein vertippter Wert Schraegstriche in die Adresse.
        $voice = preg_replace('/[^A-Za-z0-9_-]/', '', $voice) ?? '';
        if ($voice === '') {
            $voice = self::TTS_ELEVEN_VOICE;
        }
        $felder = [
            'text'           => $text,
            'model_id'       => $this->TtsSetting('TtsElevenModel', 'eleven_multilingual_v2'),
            'voice_settings' => $this->TtsElevenSettings($anweisung),
        ];
        // output_format gehoert in die ADRESSE, nicht in den Rumpf. Im Rumpf wird es
        // stillschweigend ignoriert: am 22.08.2026 gemessen, die Antwort kam mit der
        // Vorgabe mp3_44100_128 — 1,36 MB fuer 1083 Zeichen statt der erwarteten
        // 0,33 MB. Das ist kein Schoenheitsfehler: die Hook-Ausgabe hat einen Deckel,
        // und BriefingAudioBudget rechnet mit Bytes je Zeichen.
        $resp = $this->AiHttpPost(
            'https://api.elevenlabs.io/v1/text-to-speech/' . $voice
                . '?output_format=' . $this->TtsElevenFormat($text, $format),
            [
                'xi-api-key: ' . $key,
                'Content-Type: application/json',
                'Accept: audio/mpeg',
                'User-Agent: SymDoGateway',
            ],
            (string)json_encode($felder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $status = (int)($resp['status'] ?? 0);
        $roh    = (string)($resp['body'] ?? '');
        if (($resp['err'] ?? '') !== '') {
            $this->SendDebug('TTS', 'ElevenLabs HTTP-Fehler: ' . $resp['err'], 0);
            return '';
        }
        if ($status !== 200 || $roh === '') {
            // Im Fehlerfall kommt JSON mit `detail`; die ersten 300 Zeichen genuegen.
            $this->SendDebug('TTS', 'ElevenLabs Antwort ' . $status . ': ' . mb_substr($roh, 0, 300), 0);
            return '';
        }
        // Gegenprobe: MP3 beginnt mit „ID3" oder einem Frame-Kopf (0xFF 0xFB/0xF3/0xF2).
        // Eine JSON-Fehlermeldung mit Status 200 waere sonst als Tondatei abgelegt.
        if (!str_starts_with($roh, 'ID3') && !(strlen($roh) > 1 && $roh[0] === "\xFF")) {
            $this->SendDebug('TTS', 'ElevenLabs: erwartete Audiodaten, bekam ' . mb_substr($roh, 0, 200), 0);
            return '';
        }
        return $roh;
    }

    /**
     * Die Persona-Anweisung auf die Regler von ElevenLabs uebersetzen.
     *
     * Es gibt dort kein Feld fuer eine Anweisung in Worten — was bei OpenAI ein Satz
     * ist und bei Azure SSML, muss hier durch die Regler.
     *
     * ZWEI Regeln, beide aus der Doku und am 22.08.2026 hoerbar bestaetigt:
     *
     *  1. `style` bleibt KLEIN. Die Stilverstaerkung „fuehrt schnell zu
     *     unerwuenschter Instabilitaet und unnatuerlichem Klang" und kostet
     *     zusaetzlich Rechenzeit. Genau daran lag es, dass das Briefing schlechter
     *     klang als die Vorschau: die stand bei 0, der Drillsergeant bei 0,65.
     *     Seit dem 22.08.2026 steht hier 0,2 (auf Wunsch, zum Vergleich) — das sind
     *     20 von 100 auf der Skala der ElevenLabs-Oberflaeche, also kein
     *     Mini-Wert. Klingt es rauh oder unruhig, ist TTS_ELEVEN_STYLE der Regler:
     *     0 ist die Einstellung der Vorschau.
     *  2. `stability` bleibt in der Naehe der Vorgabe 0,5. Niedrig heisst laut Doku
     *     „ausdrucksstaerker, aber anfaellig fuer Halluzinationen" — 0,25 war zu
     *     weit unten.
     *
     * Das Tempo ist der eigentliche Hebel, und dafuer gibt es ein eigenes,
     * unbedenkliches Feld: `speed` (0,7 bis 1,2; die Doku warnt nur vor den
     * Extremen). Die Anweisungen nennen ihr Tempo woertlich („hohes Tempo",
     * „langsames Tempo", „ruhiges Tempo"), daran haengt die Zuordnung — nicht an
     * geratenen Stimmungswoertern.
     *
     * Die Vorgabewerte des Kontos (stability 0,5 / similarity 0,75 / style 0 /
     * speed 1,0) sind ueber /v1/voices/settings/default nachgelesen.
     *
     * @return array<string,float|bool>
     */
    private function TtsElevenSettings(string $anweisung): array
    {
        $t = mb_strtolower($anweisung);
        $hat = static fn(string ...$w): bool => (bool)array_filter(
            $w, static fn(string $x): bool => $t !== '' && str_contains($t, $x)
        );

        // Tempo nach dem, was die Anweisung ausdruecklich sagt.
        if ($hat('hohes tempo', 'schnell')) {
            $speed = 1.12;
        } elseif ($hat('langsames tempo', 'müde', 'unhurried', 'measured')) {
            $speed = 0.85;
        } elseif ($hat('ruhiges tempo')) {
            $speed = 0.94;
        } else {
            $speed = 1.0;
        }

        // Ausdruck nur MASSVOLL ueber die Stabilitaet: laut/bruellend etwas
        // beweglicher, sachlich/hoeflich etwas gleichmaessiger.
        if ($hat('brülle', 'sehr laut', 'energisch', 'aufdrehen')) {
            $stability = 0.45;
        } elseif ($hat('sachlich', 'ruhig', 'zurückhaltend', 'calm', 'controlled')) {
            $stability = 0.60;
        } else {
            $stability = 0.50;
        }

        return [
            'stability'         => $stability,
            'similarity_boost'  => 0.75,
            // Siehe Regel 1 im Kommentar oben — an EINER Stelle einstellbar.
            'style'             => self::TTS_ELEVEN_STYLE,
            'use_speaker_boost' => true,
            'speed'             => max(0.7, min(1.2, $speed)),
        ];
    }

    /** Unsere Formatnamen auf die von Azure. */
    private static function TtsAzureFormat(string $format): string
    {
        // 48 kbit/s statt der ueblichen 96: das Briefing ist Sprache, und die
        // Hook-Ausgabe hat einen Deckel (siehe BriefingAudioBudget). Bei 24 kHz mono
        // ist der Unterschied kaum hoerbar, die Datei aber halb so gross.
        return $format === 'flac'
            ? 'riff-24khz-16bit-mono-pcm'
            : 'audio-24khz-48kbitrate-mono-mp3';
    }

    /** @param array<string, array{id:int,at:int}> $cache */
    /**
     * Markiert einen Schnipsel als Briefing-Aufnahme. Der Unterschied zaehlt
     * beim Aufraeumen: Einkaufs-Ansagen („2 Kilo Aepfel") werden WIEDERVERWENDET
     * und sollen liegen bleiben — ein Briefing-Text kommt nie wieder vor, sein
     * Schnipsel ist nach ein paar Tagen nur noch Ballast.
     */
    private function TtsMarkBriefing(string $hash): void
    {
        $cache = $this->TtsCacheRead();
        if (!isset($cache[$hash]) || !empty($cache[$hash]['briefing'])) {
            return;
        }
        $cache[$hash]['briefing'] = true;
        $this->TtsCacheWrite($cache);
    }

    /**
     * Raeumt Briefing-Schnipsel weg, die aelter als $tage sind (Wunsch vom
     * 23.08.2026: nach 7 Tagen loeschen). NUR markierte: die Einkaufs-Ansagen
     * bezahlt man sonst jede Woche neu. Die aktuellen Briefings sind nie
     * betroffen — heutiges und Vorschau sind hoechstens zwei Tage alt, und das
     * feste Objekt „Briefing-Audio" traegt ohnehin eine eigene Kopie.
     *
     * @return int wie viele entfernt wurden
     */
    private function TtsSweepBriefing(int $tage = 7): int
    {
        $cache  = $this->TtsCacheRead();
        $grenze = time() - $tage * 86400;
        $weg    = 0;
        foreach ($cache as $hash => $eintrag) {
            if (empty($eintrag['briefing']) || (int)($eintrag['at'] ?? 0) >= $grenze) {
                continue;
            }
            $mid = (int)($eintrag['id'] ?? 0);
            if ($mid > 0 && @IPS_MediaExists($mid)) {
                @IPS_DeleteMedia($mid, true);
            }
            unset($cache[$hash]);
            $weg++;
        }
        if ($weg > 0) {
            $this->TtsCacheWrite($cache);
            $this->SendDebug('TTS', sprintf('%d alte Briefing-Aufnahme(n) entfernt', $weg), 0);
        }
        return $weg;
    }

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
