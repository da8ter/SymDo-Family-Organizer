<?php

declare(strict_types=1);

require_once __DIR__ . '/../../libs/AiJobStore.php';
require_once __DIR__ . '/../../libs/AiJobRunner.php';

/**
 * KI-Auftraege: einreihen, abholen, fertigmelden.
 *
 * Ein KI-Aufruf dauert bis zu 45 Sekunden, bei einem lokalen Server bis zu
 * 300. Bisher lief er IM Webhook — und Symcon fuehrt je Instanz genau eine
 * Sache zur Zeit aus. Wer waehrend eines Foto-Scans die App oeffnete, sah sie
 * haengen; gemessen brauchten 30 gleichzeitige Abrufe 874 ms statt 31.
 *
 * Jetzt gibt es einen zweiten Weg: der Hook legt den Auftrag ab und antwortet
 * mit 202 und einer Kennung, der Scanner ruft den Anbieter in SEINER Spur, und
 * die App holt das Ergebnis unter der Kennung ab.
 *
 * Der Weg ist ein ANGEBOT, kein Zwang. Nur wer `"async": true` mitschickt,
 * bekommt ihn; alle anderen bekommen die Antwort wie immer synchron. Das ist
 * kein Zoegern, sondern Notwendigkeit: die App auf dem Telefon des Nutzers ist
 * bereits installiert und wuerde eine 202-Antwort als „darin war nichts zu
 * finden" deuten. Erst wenn beide Seiten neu sind, wird der Weg zur Vorgabe.
 *
 * Wer was tut:
 *
 *   Hook     prueft alles Schnelle (Einwilligung, Fenster, Groessen, Budget),
 *            BAUT DEN PROMPT — dafuer braucht es den Bestand, den der Laeufer
 *            nicht kennt — und reiht ein.
 *   Laeufer  ruft den Anbieter. Mehr nicht.
 *   Hier     deutet die Antwort beim Abholen. Das kostet Millisekunden.
 */
trait AiJobs
{
    /** So viele Auftraege duerfen insgesamt warten. */
    private const AI_JOB_QUEUE_MAX = 8;

    /**
     * So viele HINTERGRUND-Auswertungen warten hoechstens.
     *
     * Ein eigener Topf neben `AI_JOB_QUEUE_MAX`, und etwas groesser als der
     * Deckel je Klassenseiten-Lauf (EDU_JE_LAUF_MAX = 5): sonst faende ein Lauf
     * seine eigene Schlange voll und meldete weniger ausgewertete Karten, als
     * er haette auswerten duerfen.
     */
    private const AI_JOB_HINTERGRUND_MAX = 8;

    /** Und so viele je Absender — ein Geraet soll die Schlange nicht fuellen. */
    private const AI_JOB_PER_ORIGIN = 2;

    /** So lange bleibt ein fertiges Ergebnis abholbereit. */
    private const AI_JOB_KEEP_S = 600;

    /** Wartet ein Auftrag laenger, laeuft offenbar niemand mehr. */
    private const AI_JOB_QUEUE_MAX_AGE_S = 900;

    /** Und so lange darf EIN Aufruf dauern, bevor er als verloren gilt. */
    private const AI_JOB_RUN_MAX_S = 400;

    /** So oft soll die App nachfragen. */
    private const AI_JOB_POLL_S = 2;

    /** Der Zeitgeber, der liegengebliebene Auftraege einsammelt. */
    private const AI_JOB_SWEEP_MS = 35000;

    // ------------------------------------------------------------------
    // Lebenslauf
    // ------------------------------------------------------------------

    private function AiJobCreate(): void
    {
        $this->RegisterTimer('AiJobSweep', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'AiJobSweep\', 0);');
    }

    private function AiJobApplyChanges(): void
    {
        /* Was ein Neustart mitten im Lauf hinterlassen hat, kommt hier wieder
           in Ordnung: ein „laeuft", das niemand mehr faehrt, geht zurueck in
           die Schlange statt fuer immer zu warten. */
        $this->AiJobAufraeumen();
        /* Nach einem Kernelstart ist der Zeitgeber aus, der gemerkte Wert aber
           noch da — sonst setzte ihn niemand wieder, und der Merker verhinderte
           es fuer die ganze Kernel-Laufzeit. Dieselbe Falle wie im VRR-Modul
           und im Scanner, und dies war die einzige Kopie ohne diesen Schritt. */
        @$this->WriteAttributeInteger('AiJobSweepMs', -1);
        $this->AiJobSweepSetzen($this->AiJobLaden(false)->koepfe() === [] ? 0 : self::AI_JOB_SWEEP_MS);
    }

    /** @return bool true = der Ident gehoerte uns */
    private function AiJobRequestAction(string $Ident, mixed $Value): bool
    {
        switch ($Ident) {
            case 'AiJobDone':
                // Der kurze Ruf des Laeufers. Erlaubte Richtung, Millisekunden.
                $this->AiJobFinish((string)$Value);
                return true;
            case 'AiJobSweep':
                $this->AiJobSweep();
                return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Der Laden
    // ------------------------------------------------------------------

    /**
     * Wo die Auftraege liegen — unter der Kennung des Gateways, wie der
     * Scan-Kanal auch. Zwei Gateways im selben Kernel teilen sich sonst eine
     * Warteschlange, und der falsche Laeufer holte fremde Auftraege ab.
     */
    private function AiJobDir(): string
    {
        return rtrim((string) @IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR
            . 'symdo_aijobs' . DIRECTORY_SEPARATOR . $this->KonfigID() . DIRECTORY_SEPARATOR;
    }

    private function AiJobLaden(bool $anlegen = true): AiJobStore
    {
        return AiJobStore::in($this->AiJobDir(), $anlegen);
    }

    /**
     * Kann ein Auftrag ueberhaupt abgearbeitet werden?
     *
     * Ohne Scanner fuer die Quelle „auftrag" laege er nur herum. Dann bleibt
     * alles beim Alten: der Hook antwortet synchron, wie er es immer tat.
     */
    private function AiJobMoeglich(): bool
    {
        return $this->ScanQuelleUebernommen('auftrag');
    }

    // ------------------------------------------------------------------
    // Einreihen
    // ------------------------------------------------------------------

    /**
     * Einen Auftrag einreihen und den Laeufer wecken.
     *
     * @param array<string,mixed> $job    system, user, payloadKind, mime, url
     * @param array<string,mixed> $parse  type (todos|recipe|text) und arten
     * @param array<string,mixed> $origin type (rest|tile), sdwa, txn
     * @return array{ok:bool,id?:string,code?:string,message?:string,status?:int}
     */
    private function AiJobEnqueue(string $kind, array $job, array $parse, array $origin,
        string $nutzlast = '', string $geraet = ''): array
    {
        $laden = $this->AiJobLaden();
        if (!$laden->nutzbar()) {
            return ['ok' => false, 'code' => 'internal',
                'message' => $this->Translate('AI request failed.'), 'status' => 500];
        }

        $kopf = [
            'id'         => AiJobStore::neueKennung(),
            'kind'       => $kind,
            'state'      => AiJobStore::OFFEN,
            'createdAt'  => time(),
            'startedAt'  => 0,
            'finishedAt' => 0,
            'notBefore'  => 0,
            'attempts'   => 0,
            'device'     => $geraet,
            'origin'     => $origin,
            'job'        => $job,
            'parse'      => $parse,
        ];

        /* Zwei Deckel, und beide sind noetig. Der erste schuetzt den Anbieter
           und das Tagesbudget, der zweite die anderen Bewohner: ohne ihn
           fuellte ein Telefon mit einem Stapel Fotos die Schlange, und alle
           anderen warteten hinter ihm.

           HINTERGRUNDARBEIT rechnet in einem EIGENEN Topf. Sonst haette sie
           zwei Wirkungen, die beide falsch waeren: fuenf Klassenseiten-Karten
           je Lauf kaemen am Deckel je Herkunft (zwei) nicht vorbei, und sie
           fuellten die Schlange so weit, dass das Foto, das gerade jemand
           hochlaedt, mit „belegt" abgewiesen wuerde. Was niemand angestossen
           hat, darf niemandem im Weg stehen. */
        $hintergrund = (string)($origin['type'] ?? '') === AiJobStore::HERKUNFT_HINTERGRUND;
        $wartend = $hintergrund
            ? $laden->zaehleWartende(AiJobStore::HERKUNFT_HINTERGRUND)
            : $laden->zaehleWartende() - $laden->zaehleWartende(AiJobStore::HERKUNFT_HINTERGRUND);
        $deckel = $hintergrund ? self::AI_JOB_HINTERGRUND_MAX : self::AI_JOB_QUEUE_MAX;
        if ($wartend >= $deckel
            || (!$hintergrund
                && $laden->zaehleWartende(AiJobStore::herkunftVon($kopf)) >= self::AI_JOB_PER_ORIGIN)) {
            return ['ok' => false, 'code' => 'ai_busy',
                'message' => $this->Translate('Another AI request is already running.'), 'status' => 429];
        }

        /* HINTERGRUNDARBEIT bucht das Tagesbudget SOFORT, nicht erst beim
           Deuten. Der synchrone Weg prueft und bucht in derselben Runde — Karte
           zwei sah also schon den Stand nach Karte eins. Ueber Auftraege laeuft
           das Buchen aber erst, wenn der Ruf zurueckkommt, und der kommt
           fruehestens nach dem Lauf: alle fuenf Karten pruefen sonst gegen
           denselben veralteten Zaehler. Bei Deckel 20 und Stand 19 ginge
           synchron genau EINER durch — hier fuenf, und der Tag endete bei 24.
           Der Nutzer hat 20 eingestellt.

           Gebucht wird bei der Annahme und beim Deuten NICHT noch einmal. Ein
           Auftrag, der spaeter scheitert, hat seinen Platz damit verbraucht —
           das ist die vorsichtige Richtung: lieber einer zu wenig als einer zu
           viel. */
        if ($hintergrund) {
            $this->MailCountDay();
        }

        $id = $laden->anlegen($kopf, $nutzlast);
        if ($id === null) {
            return ['ok' => false, 'code' => 'internal',
                'message' => $this->Translate('AI request failed.'), 'status' => 500];
        }

        // Auftragsdatei UND Klingel — eines allein liefe ins Leere.
        $this->ScanAuftragGeben('auftrag', ['anlass' => 'hook']);
        $this->AiJobSweepSetzen(self::AI_JOB_SWEEP_MS);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Die 202-Antwort: „angenommen, hol es dort ab".
     *
     * `maxWait` ist die Schaetzung, wie lange es dauern kann — die App macht
     * daraus ihre eigene Frist, statt zu raten. Ein lokaler Server rechnet
     * anders als eine Cloud: er darf fuenf Minuten je Aufruf brauchen.
     */
    private function AiJobAccept(string $id): void
    {
        $laden = $this->AiJobLaden(false);
        $kopf  = $laden->lesen($id);
        $this->SendJson($this->AiJobWartetBody($laden, $id, $kopf ?? []), 202);
    }

    /**
     * @param array<string,mixed> $kopf
     */
    private function AiJobWartetBody(AiJobStore $laden, string $id, array $kopf): array
    {
        $position = max(1, $laden->position($id));
        return [
            'ok'        => true,
            'queued'    => true,
            'id'        => $id,
            'state'     => (string)($kopf['state'] ?? AiJobStore::OFFEN),
            'position'  => $position,
            'pollAfter' => self::AI_JOB_POLL_S,
            'maxWait'   => $this->AiJobDauerSchaetzung((string)($kopf['kind'] ?? ''), $position),
        ];
    }

    /**
     * Wie lange es hoechstens dauern kann — die Zahl, aus der die App ihre
     * eigene Frist macht.
     *
     * Sie muss EHRLICH sein. Eine zu knappe Schaetzung ist teurer als eine zu
     * grosszuegige: der Aufruf laeuft weiter, wird bezahlt und aufs Tagesbudget
     * gebucht, aber niemand holt ihn ab — der Nutzer sieht „nichts gefunden".
     * Deshalb stehen hier die echten Obergrenzen und nicht Daumenwerte:
     *
     *   der Anbieteraufruf selbst   45 s, lokal 300 s, ein Diktat 120 s
     *   eine Vertagung bei „belegt" 30 s
     *   bis der Sammler noch einmal klingelt   35 s
     *
     * Mal der Position in der Schlange, plus etwas Luft — und gedeckelt, denn
     * eine Frist von einer halben Stunde hilft niemandem mehr.
     */
    private function AiJobDauerSchaetzung(string $kind, int $position): int
    {
        $anbieter = $this->AiAnbieter();
        if ($kind === 'transcribe') {
            $jeAufruf = $anbieter->istLokal() ? AiProvider::LOCAL_TIMEOUT : AiProvider::TRANSCRIBE_TIMEOUT;
        } else {
            $jeAufruf = $anbieter->istLokal() ? AiProvider::LOCAL_TIMEOUT : AiProvider::TIMEOUT;
        }
        $jeAufruf += AiJobRunner::BREMSE_S + (int)(self::AI_JOB_SWEEP_MS / 1000);
        return min(600, max(1, $position) * $jeAufruf + 15);
    }

    // ------------------------------------------------------------------
    // Der Weg der Visu-Kachel
    // ------------------------------------------------------------------

    /**
     * Darf dieser Kachel-Aufruf als Auftrag hinausgehen?
     *
     * Eine Kachel hat keinen Token und kennt nur den Weg ueber `requestAction`.
     * Bisher hing sie waehrend des ganzen Anbieteraufrufs an der Gateway-Spur —
     * und mit ihr die App, die Web-App und jede andere Kachel.
     *
     * Anders als beim HTTP-Weg gibt es hier kein Opt-in: die Kachel wird mit
     * dem Gateway ausgeliefert, beide Seiten wechseln zusammen. Bedingung ist
     * nur, dass ein Laeufer bereitsteht.
     */
    private function AiKachelAuftrag(int $sdwa, string $txn): bool
    {
        return $sdwa > 0 && $txn !== '' && $this->AiJobMoeglich();
    }

    /**
     * Den Auftrag einreihen und der Kachel sofort Bescheid geben.
     *
     * Der Rueckgabewert ist der Rumpf, den das Relay zurueckgibt — er wird der
     * Kachel als ERSTES `AiResult` zugestellt. Das ZWEITE, mit dem Ergebnis,
     * kommt spaeter aus `AiJobTileAntwort()`.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $parse
     */
    private function AiRelayAuftrag(string $kind, int $sdwa, string $txn,
        array $job, array $parse, string $nutzlast): string
    {
        $r = $this->AiJobEnqueue($kind, $job, $parse,
            ['type' => 'tile', 'sdwa' => $sdwa, 'txn' => $txn], $nutzlast, '');
        if (($r['ok'] ?? false) !== true) {
            return $this->AiRelayError((string)($r['code'] ?? 'internal'), (string)($r['message'] ?? ''));
        }
        $laden = $this->AiJobLaden(false);
        $id    = (string)$r['id'];
        return (string)json_encode($this->AiJobWartetBody($laden, $id, $laden->lesen($id) ?? []),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Die Nachfrage einer Kachel nach ihrem Auftrag.
     *
     * Gebunden an die KACHEL, die ihn gestellt hat — nicht an ein Geraet, denn
     * eines hat sie nicht. Eine fremde Kachel bekommt ihn so wenig zu sehen
     * wie ein fremdes Telefon.
     *
     * @return array<string,mixed>
     */
    private function AiJobKachelStand(string $id, int $sdwa): array
    {
        $laden = $this->AiJobLaden(false);
        $kopf  = $laden->lesen($id);
        if ($kopf === null || (int)(($kopf['origin']['sdwa'] ?? 0)) !== $sdwa) {
            return ['ok' => false, 'error' => ['code' => 'job_not_found',
                'message' => $this->Translate('This AI job is unknown or has expired.')]];
        }
        if ((string)$kopf['state'] === AiJobStore::ROH) {
            $this->AiJobFinish($id);
            $kopf = $laden->lesen($id) ?? $kopf;
        }
        $zustand = (string)($kopf['state'] ?? '');
        if ($zustand === AiJobStore::OFFEN || $zustand === AiJobStore::LAEUFT) {
            return $this->AiJobWartetBody($laden, $id, $kopf);
        }
        $rumpf = (array)((($kopf['result'] ?? [])['body']) ?? []);
        if ($zustand === AiJobStore::GESCHEITERT) {
            return ['ok' => false, 'error' => ['code' => (string)($rumpf['code'] ?? 'ai_failed'),
                'message' => (string)($rumpf['message'] ?? $this->Translate('AI request failed.'))]];
        }
        return $rumpf;
    }

    /**
     * Das ZWEITE `AiResult`: das Ergebnis, unter derselben Kennung des
     * Vorgangs. Die Kachel hat das erste (die Annahme) bekommen und wartet
     * seitdem auf dieses hier.
     *
     * Der Ruf geht in die Spur der Kachel. Das ist dieselbe Richtung, die das
     * Relay immer schon genommen hat — nur dauert er jetzt Millisekunden statt
     * dreiviertel Minuten.
     *
     * @param array<string,mixed> $kopf
     */
    private function AiJobTileAntwort(array $kopf): void
    {
        $sdwa = (int)(($kopf['origin']['sdwa'] ?? 0));
        $txn  = (string)(($kopf['origin']['txn'] ?? ''));
        /* Die weisse Liste ein zweites Mal: zwischen Einreihen und Antwort
           koennen Minuten liegen, und in dieser Zeit kann aus der Kennung eine
           ganz andere Instanz geworden sein. */
        if ($sdwa <= 0 || $txn === '' || !$this->IsSymDoWebAppInstance($sdwa)) {
            return;
        }
        $ergebnis = is_array($kopf['result'] ?? null) ? $kopf['result'] : [];
        $rumpf    = is_array($ergebnis['body'] ?? null) ? $ergebnis['body'] : [];
        if ((string)($kopf['state'] ?? '') === AiJobStore::GESCHEITERT) {
            $rumpf = ['ok' => false, 'error' => ['code' => (string)($rumpf['code'] ?? 'ai_failed'),
                'message' => (string)($rumpf['message'] ?? $this->Translate('AI request failed.'))]];
        }
        @IPS_RequestAction($sdwa, 'AiResult', (string)json_encode([
            'txn'    => $txn,
            'status' => (($rumpf['ok'] ?? false) === true) ? 200 : 502,
            'json'   => $rumpf,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------------
    // Abholen
    // ------------------------------------------------------------------

    /**
     * `GET /v1/ai/jobs/{id}` — der Abholschein.
     *
     * Gerätegebunden: die Kennung ist zwar nicht zu raten, aber sie reist
     * durch Protokolle und Adresszeilen. Wer sie hat, soll trotzdem nicht das
     * Ergebnis eines fremden Geraets sehen.
     *
     * @param array<string,mixed> $device
     */
    private function HandleAiJobStatus(array $device, string $id): void
    {
        $laden = $this->AiJobLaden(false);
        $kopf  = $laden->lesen($id);
        $eigen = (string)($device['id'] ?? '');
        if ($kopf === null
            || ((string)($kopf['device'] ?? '') !== '' && (string)$kopf['device'] !== $eigen)) {
            $this->SendApiError('job_not_found', $this->Translate('This AI job is unknown or has expired.'), 404);
            return;
        }

        // Roh heisst: der Anbieter hat geantwortet, aber niemand hat es
        // gedeutet. Das holen wir jetzt nach — es kostet Millisekunden.
        if ((string)$kopf['state'] === AiJobStore::ROH) {
            $this->AiJobFinish($id);
            $kopf = $laden->lesen($id) ?? $kopf;
        }

        $zustand = (string)($kopf['state'] ?? '');
        if ($zustand === AiJobStore::OFFEN || $zustand === AiJobStore::LAEUFT) {
            $this->SendJson($this->AiJobWartetBody($laden, $id, $kopf), 202);
            return;
        }

        $ergebnis = is_array($kopf['result'] ?? null) ? $kopf['result'] : [];
        $rumpf    = is_array($ergebnis['body'] ?? null) ? $ergebnis['body'] : [];
        if ($zustand === AiJobStore::GESCHEITERT) {
            $this->SendApiError(
                (string)($rumpf['code'] ?? 'ai_failed'),
                (string)($rumpf['message'] ?? $this->Translate('AI request failed.')),
                (int)($ergebnis['status'] ?? 502));
            return;
        }

        /* Die Antwort ist dieselbe wie auf dem synchronen Weg — nur mit der
           Kennung dabei. Sonst muesste die App zwei Formen kennen. */
        $this->SendJson($rumpf + ['id' => $id]);
    }

    // ------------------------------------------------------------------
    // Fertigmelden und Deuten
    // ------------------------------------------------------------------

    /**
     * Aus der rohen Anbieter-Antwort das machen, was die App erwartet.
     *
     * Laeuft in der Gateway-Spur, aber nur Millisekunden: ein JSON zerlegen
     * und Felder pruefen. Das Langsame ist zu diesem Zeitpunkt laengst vorbei.
     */
    private function AiJobFinish(string $id): void
    {
        $laden = $this->AiJobLaden(false);
        $kopf  = $laden->lesen($id);
        if ($kopf === null || (string)($kopf['state'] ?? '') !== AiJobStore::ROH) {
            return;   // schon gedeutet, oder gar nicht da
        }
        $roh = is_array($kopf['raw'] ?? null) ? $kopf['raw'] : [];

        // Was dem Anbieter aufgefallen ist, gehoert ins Protokoll dieser Instanz.
        foreach ((array)($roh['debug'] ?? []) as $zeile) {
            $this->SendDebug('AI', (string)$zeile, 0);
        }

        if (($roh['ok'] ?? false) !== true) {
            $meldung = $this->AiErrorMessage(
                (string)($roh['code'] ?? 'ai_failed'),
                (string)($roh['grund'] ?? ''),
                (string)($roh['detail'] ?? ''));
            $kopf['state']  = AiJobStore::GESCHEITERT;
            $kopf['result'] = ['status' => (int)$meldung['status'], 'body' => $meldung];
        } else {
            $text = (string)($roh['text'] ?? '');
            $art  = (string)(($kopf['parse']['type'] ?? '') ?: 'text');
            if ($art === 'todos') {
                $arten = (array)($kopf['parse']['arten'] ?? ['task', 'event']);
                $rumpf = ['ok' => true, 'todos' => $this->AiParseTodos($text, $arten)];
            } elseif ($art === 'recipe') {
                $rezept = $this->AiParseRecipe($text);
                $rumpf = ['ok' => true, 'title' => $rezept['title'] ?? null,
                          'servings' => $rezept['servings'] ?? null, 'items' => $rezept['items'] ?? []];
            } else {
                $rumpf = ['ok' => true, 'text' => trim($text)];
            }
            $kopf['state']  = AiJobStore::FERTIG;
            $kopf['result'] = ['status' => 200, 'body' => $rumpf];
            /* Gezaehlt wird nur, was auch geklappt hat — und nur, was der
               synchrone Weg auch zaehlt. Das Diktat zaehlt dort NICHT: es ist
               die halbe Miete, das Zerlegen kommt danach als eigener Aufruf
               und wird dann gezaehlt. Wer hier mitzaehlte, buchte dem Nutzer
               jedes Diktat doppelt aufs Tagesbudget. */
            /* Hintergrundarbeit hat schon bei der Annahme gebucht — sonst
               zaehlte sie doppelt. */
            if ((string)($kopf['kind'] ?? '') !== 'transcribe'
                && (string)((($kopf['origin'] ?? [])['type']) ?? '') !== AiJobStore::HERKUNFT_HINTERGRUND) {
                $this->MailCountDay();
            }
        }
        $kopf['finishedAt'] = time();
        // Die Rohantwort wird nicht aufbewahrt: sie ist gedeutet, und ein
        // Anbietertext kann sehr lang sein.
        unset($kopf['raw']);
        $laden->schreiben($kopf);

        /* Wer den Auftrag gestellt hat, erfaehrt es auf seinem Weg: die App und
           die Web-App ueber die Klingel, die Visu-Kachel ueber ein zweites
           `AiResult` mit derselben Vorgangskennung. */
        $herkunft = (string)((($kopf['origin'] ?? [])['type']) ?? '');
        if ($herkunft === 'tile') {
            $this->AiJobTileAntwort($kopf);
        } elseif ($herkunft === AiJobStore::HERKUNFT_HINTERGRUND) {
            /* Hintergrundarbeit: niemand wartet auf eine Antwort. Der Vorschlag
               geht in den Bestand, und die App erfaehrt es ueber dessen eigenes
               Signal — nicht ueber die Auftrags-Klingel. */
            $this->MailAuftragEinpflegen($kopf);
        } else {
            $this->WsPushJob($id);
        }
        $this->AiJobSweepSetzen(self::AI_JOB_SWEEP_MS);
    }

    /**
     * Der App sagen, dass es etwas abzuholen gibt — damit sie nicht im Takt
     * nachfragen muss. Die Kennung allein verraet nichts: abgeholt wird nur
     * mit Token und vom richtigen Geraet.
     */
    private function WsPushJob(string $id): void
    {
        /* Dieselbe Leitung wie `WsPushDirty`, nur mit einer anderen Nachricht.
           Der Pfad MUSS mit '/hook/' beginnen — andere Schreibweisen liefern
           true und senden nichts. In den Pruefstands-Attrappen gibt es
           WC_PushMessage nicht, deshalb die Wache. */
        if (!function_exists('WC_PushMessage')) {
            return;
        }
        $controls = (array)@IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL_GUID);
        if ($controls === []) {
            return;
        }
        @WC_PushMessage((int)$controls[0], '/hook/' . self::WS_HOOK_PATH,
            (string)json_encode(['t' => 'job', 'id' => $id]));
    }

    // ------------------------------------------------------------------
    // Aufraeumen
    // ------------------------------------------------------------------

    private function AiJobAufraeumen(): int
    {
        return $this->AiJobLaden(false)->aufraeumen(time(), self::AI_JOB_KEEP_S,
            self::AI_JOB_QUEUE_MAX_AGE_S, self::AI_JOB_RUN_MAX_S);
    }

    /**
     * Das Sicherheitsnetz: raeumt auf und klingelt noch einmal, falls etwas
     * wartet und niemand es genommen hat (der Scanner war beim Klingeln noch
     * nicht bereit, die Signalvariable fehlte, der Kernel startete gerade).
     * Ist die Schlange leer, stellt sich der Zeitgeber selbst ab.
     */
    private function AiJobSweep(): void
    {
        $this->AiJobAufraeumen();
        $laden = $this->AiJobLaden(false);

        /* Liegengebliebene ROH-Auftraege deuten.
           Der Laeufer schreibt die Antwort und meldet sie mit einem Ruf ins
           Gateway. Faellt dieser Ruf aus — Kernel-Neustart dazwischen, Instanz
           kurz weg —, bleibt der Auftrag ROH liegen. Ein Geraet holt ihn per
           Nachfrage nach, die Kachel ebenso; bei HINTERGRUNDARBEIT fragt aber
           niemand nach. Ohne diesen Kehrgang waere die bezahlte Antwort fuer
           immer verloren, und die Karte staende schon als gesehen im Merker. */
        foreach ($laden->koepfe() as $kopf) {
            if ((string)($kopf['state'] ?? '') === AiJobStore::ROH) {
                $this->AiJobFinish((string)$kopf['id']);
            }
        }
        /* Abgestellt wird erst, wenn GAR NICHTS mehr da ist — nicht schon,
           wenn nichts mehr wartet. Ein fertiger Kopf muss noch verfallen
           (AI_JOB_KEEP_S); schaltete sich der Zeitgeber vorher ab, laege er
           fuer immer herum und niemand raeumte ihn weg. */
        if ($laden->koepfe() === []) {
            $this->AiJobSweepSetzen(0);
            return;
        }
        if ($laden->zaehleWartende() > 0) {
            $this->ScanAuftragGeben('auftrag', ['anlass' => 'timer']);
        }
    }

    /** Nur bei Aenderung setzen — sonst zaehlt der Zeitgeber jedes Mal von vorn. */
    private function AiJobSweepSetzen(int $ms): void
    {
        if ((int)@$this->ReadAttributeInteger('AiJobSweepMs') === $ms) {
            return;
        }
        @$this->SetTimerInterval('AiJobSweep', $ms);
        @$this->WriteAttributeInteger('AiJobSweepMs', $ms);
    }
}
