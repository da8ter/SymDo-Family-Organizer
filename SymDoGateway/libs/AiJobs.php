<?php

declare(strict_types=1);

require_once __DIR__ . '/../../libs/AiJobStore.php';

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
           anderen warteten hinter ihm. */
        if ($laden->zaehleWartende() >= self::AI_JOB_QUEUE_MAX
            || $laden->zaehleWartende(AiJobStore::herkunftVon($kopf)) >= self::AI_JOB_PER_ORIGIN) {
            return ['ok' => false, 'code' => 'ai_busy',
                'message' => $this->Translate('Another AI request is already running.'), 'status' => 429];
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

    /** @param array<string,mixed> $kopf */
    private function AiJobWartetBody(AiJobStore $laden, string $id, array $kopf): array
    {
        $position = max(1, $laden->position($id));
        $jeAufruf = $this->AiAnbieter()->istLokal() ? 320 : 65;
        return [
            'ok'        => true,
            'queued'    => true,
            'id'        => $id,
            'state'     => (string)($kopf['state'] ?? AiJobStore::OFFEN),
            'position'  => $position,
            'pollAfter' => self::AI_JOB_POLL_S,
            'maxWait'   => min(600, $position * $jeAufruf + 15),
        ];
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
            if ((string)($kopf['kind'] ?? '') !== 'transcribe') {
                $this->MailCountDay();
            }
        }
        $kopf['finishedAt'] = time();
        // Die Rohantwort wird nicht aufbewahrt: sie ist gedeutet, und ein
        // Anbietertext kann sehr lang sein.
        unset($kopf['raw']);
        $laden->schreiben($kopf);

        $this->WsPushJob($id);
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
