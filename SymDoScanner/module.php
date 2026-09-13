<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Konfig.php';
require_once __DIR__ . '/../libs/Belegung.php';
require_once __DIR__ . '/../libs/ScanKanal.php';

/**
 * SymDo Scanner — die Arbeitsspur neben dem Gateway.
 *
 * Symcon fuehrt je Instanz genau EINE Sache zur Zeit aus. Das Gateway bedient
 * die Webhooks der App und liess bisher in derselben Spur die langen Laeufe
 * mitlaufen: Klassenseiten holen, Moodle abfragen, Handbuch indizieren,
 * Briefing erzeugen, Post auswerten. Gemessen: 30 gleichzeitige Hook-Abrufe
 * brauchten 874 ms statt 31, waehrend ein Timer lief. Der Nutzer sieht das als
 * „die App haengt".
 *
 * Diese Instanz ist die zweite Spur. Sie bekommt ihre Auftraege NICHT per
 * Funktionsaufruf — der wuerde das Gateway auf sie warten lassen und damit
 * genau das Problem verschieben —, sondern ueber den Datei-Kanal (ScanKanal)
 * und eine Signalvariable am Gateway:
 *
 *   Gateway legt Auftragsdatei ab  ->  erhoeht ScanSignal
 *   -> MessageSink hier (eigener Nachrichten-Thread, weckt auch eine
 *      beschaeftigte Instanz; gemessen ~30 ms mitten in einem 10-Sekunden-Lauf)
 *   -> RegisterOnceTimer, damit die Arbeit in DIESER Spur laeuft
 *   -> Ergebnisdatei  ->  kurzer Ruf ins Gateway ('ScanInbox')
 *
 * Die Richtungsregel, an der alles haengt: **die Gateway-Spur ruft nie
 * synchron hier herein.** Umgekehrt ist es erlaubt — ein Ruf ins Gateway
 * kostet die Dauer eines Hooks, also Millisekunden.
 *
 * Heute kennt der Scanner nur die Quelle „probe": den Selbsttest des Kanals.
 * Er beweist den Weg, bevor ein echter Scan darauf faehrt. Die echten Quellen
 * (doku, edu, moodle, mail, briefing) ziehen danach einzeln um.
 */
class SymDoScanner extends IPSModuleStrict
{
    use Konfig;
    use Belegung;
    use ScanKanal;

    private const GATEWAY_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    /**
     * Das Sicherheitsnetz. Im Regelfall weckt das Signal; dieser Takt faengt
     * nur die Faelle, in denen es ausgefallen ist (Gateway noch nicht bereit,
     * Variable eben erst angelegt, Kernel gerade gestartet). Bewusst traege:
     * ein Auftrag, der einmal fuenf Minuten liegt, ist kein Schaden — ein
     * Sekundentakt in einer Dauerinstanz schon.
     */
    private const TAKT_MS = 300000;

    /** Wie lange der Selbsttest hoechstens verweilen darf (Beweislauf). */
    private const PROBE_VERWEIL_MAX = 30;

    public function Create(): void
    {
        parent::Create();

        /* Welche Quellen DIESE Instanz bedient. Objektform, damit sich eine
           Quelle abschalten laesst, ohne sie zu verlieren. Das Gateway legt
           die Instanzen mit der passenden Liste an. */
        $this->RegisterPropertyString('Jobs', '[{"role":"probe","active":true}]');

        /* Absturzwaechter: steht hier ein Lauf, der nie geendet hat, faengt
           der naechste Takt ihn ab, statt ewig zu schweigen. */
        $this->RegisterAttributeString('Laeuft', '');
        /* Der zuletzt GESETZTE Takt. Ohne ihn verhungert der Timer:
           SetTimerInterval startet die Uhr in Symcon 9.1 auch beim gleichen
           Wert neu — im VRR-Modul gemessen und dort dieselbe Loesung. */
        $this->RegisterAttributeInteger('TimerMs', -1);
        /* Einmalige Schritte, die schon erledigt sind (spaeter: die Mitgift
           der Merkerstaende aus dem Gateway). */
        $this->RegisterAttributeString('EinmalErledigt', '[]');
        $this->RegisterAttributeBoolean('ParentMigrated', false);

        $this->RegisterTimer('Takt', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Takt\', 0);');
    }

    /**
     * „connect" statt „require": an EINEM Gateway haengen mehrere Instanzen.
     * (ConnectParent/RequireParent gibt es fuer IPSModuleStrict nicht.)
     */
    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::GATEWAY_GUID]]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->GatewayEinmaligVerbinden();
        $this->SignalAbonnieren();

        /* Nach einem Kernelstart ist der Timer aus, der gemerkte Wert aber noch
           da — sonst setzte ihn niemand wieder. */
        @$this->WriteAttributeInteger('TimerMs', -1);
        // Liegengebliebenes aus einem abgebrochenen Lauf.
        @$this->WriteAttributeString('Laeuft', '');

        if ($this->GatewayID() <= 0) {
            /* Ohne Gateway gibt es keinen Kanal: sein Verzeichnis haengt an
               dessen Kennung. Lieber sichtbar stumm als in einen Topf „0"
               schreiben, den nie jemand leert. */
            $this->TaktSetzen(0);
            $this->SetStatus(104);
            return;
        }

        $this->TaktSetzen(self::TAKT_MS);
        $this->ScanAnspruchVerwaist();

        $this->SetStatus(102);

        /* Wer beim Uebernehmen einen Auftrag liegen hat, soll nicht bis zum
           naechsten Takt warten. */
        if ($this->ScanAuftraegeOffen($this->Quellen()) > 0) {
            $this->Wecken();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE) {
            /* HIER wird nicht gearbeitet. Der MessageSink laeuft in einem
               eigenen Nachrichten-Thread — Arbeit darin hielte die Zustellung
               fuer alle auf. Er setzt nur den Einmal-Timer; der laeuft dann in
               der Spur dieser Instanz. */
            $this->Wecken();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $start = microtime(true);
        try {
            switch ($Ident) {
                case 'Takt':
                case 'Weck':
                    $this->Lauf();
                    return;
            }
            parent::RequestAction($Ident, $Value);
        } finally {
            $this->Belegung('aktion', $Ident, $start);
        }
    }

    // ------------------------------------------------------------------
    // Oeffentlich
    // ------------------------------------------------------------------

    /**
     * Einen Auftrag von Hand ausloesen — fuer Knoepfe im Formular und fuer
     * Skripte: `SDSC_Auftrag(<id>, 'probe', '{}')`.
     *
     * Ein Knopf im Konsolen-Formular laeuft in seinem eigenen Skript-Thread,
     * nicht in der Gateway-Spur; er darf deshalb direkt hier hereinrufen. Der
     * Aufrufer wartet die Laufzeit ab und bekommt den Statustext zurueck.
     */
    public function Auftrag(string $Art, string $Json): string
    {
        if (!ScanKanalCalc::QuelleGueltig($Art)) {
            return $this->Translate('Unknown source');
        }
        if (!in_array($Art, $this->Quellen(), true)) {
            return $this->Translate('This instance does not handle that source');
        }
        if ($this->GatewayID() <= 0) {
            return $this->Translate('No gateway found');
        }
        $roh = json_decode($Json === '' ? '{}' : $Json, true);
        $auftrag = ScanKanalCalc::AuftragBlock(is_array($roh) ? $roh : []);
        $auftrag['anlass'] = 'hand';
        return $this->Ausfuehren($Art, $auftrag, is_array($roh) ? $roh : [], 0);
    }

    /** Was dieser Scanner gerade sieht — fuer Statuszeilen und Pruefstaende. */
    public function Stand(): string
    {
        return (string)json_encode([
            'quellen'  => $this->Quellen(),
            'gateway'  => $this->GatewayID(),
            'wartend'  => $this->ScanAuftraegeOffen($this->Quellen()),
            'kanal'    => $this->ScanStand(),
            'laeuft'   => (string)@$this->ReadAttributeString('Laeuft'),
        ], JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------
    // Der Lauf
    // ------------------------------------------------------------------

    /**
     * Alles abarbeiten, was wartet. Ein Auftrag je Runde und Quelle: so bleibt
     * der Speicher ueberschaubar (64 MB) und ein langer Lauf blockiert nicht
     * die anderen Quellen dieser Instanz laenger als noetig.
     */
    private function Lauf(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $quellen = $this->Quellen();
        if ($quellen === [] || $this->GatewayID() <= 0) {
            return;
        }
        // Kein zweiter Lauf in derselben Spur — die serialisiert zwar ohnehin,
        // aber der Waechter macht einen Abbruch sichtbar.
        @$this->WriteAttributeString('Laeuft', (string)json_encode(['at' => time()]));
        try {
            for ($runde = 0; $runde < 8; $runde++) {
                $auftrag = $this->ScanAuftragNehmen($quellen);
                if ($auftrag === null) {
                    break;
                }
                $this->Ausfuehren($auftrag['quelle'], $auftrag['auftrag'], [], $auftrag['at']);
            }
        } finally {
            @$this->WriteAttributeString('Laeuft', '');
            $this->TaktSetzen(self::TAKT_MS);
        }
    }

    /**
     * Einen Auftrag ausfuehren und sein Ergebnis in den Kanal legen.
     *
     * @param array<string,mixed> $auftrag  der geprueifte Auftragsblock
     * @param array<string,mixed> $roh      die Rohangaben (nur beim Weg von Hand)
     * @param int                 $gestellt wann der Auftrag abgelegt wurde, 0 = von Hand
     */
    private function Ausfuehren(string $quelle, array $auftrag, array $roh, int $gestellt): string
    {
        $start = microtime(true);
        $text = '';
        $ok = true;

        switch ($quelle) {
            case 'probe':
                /* Der Selbsttest. Er tut absichtlich nichts Fachliches — er
                   beweist nur, dass der Weg traegt. „verweilen" ist der
                   Beweislauf: waehrend diese Spur steht, muss die Gateway-Spur
                   weiter in Millisekunden antworten. */
                $verweilen = max(0, min(self::PROBE_VERWEIL_MAX, (int)($roh['verweilen'] ?? 0)));
                if ($verweilen > 0) {
                    sleep($verweilen);
                }
                $text = sprintf($this->Translate('Channel checked (%d s dwell, reason %s)'),
                    $verweilen, (string)$auftrag['anlass']);
                break;

            default:
                // Die echten Quellen ziehen einzeln um; bis dahin laeuft der
                // Scan weiter im Gateway und dieser Auftrag ist ein Irrlaeufer.
                $ok = false;
                $text = sprintf($this->Translate('Source %s not moved yet'), $quelle);
                break;
        }

        $umschlag = ScanKanalCalc::LeererUmschlag($quelle, $this->KonfigID(), $this->InstanceID, time(), $text);
        $umschlag['status']['ok'] = $ok;
        $umschlag['status']['dauerMs'] = (int)round((microtime(true) - $start) * 1000);
        $umschlag['anlass'] = (string)$auftrag['anlass'];
        if ($gestellt > 0) {
            $umschlag['auftragAt'] = $gestellt;
        }

        $this->Belegung('scan', $quelle, $start);

        if ($this->ScanErgebnisSchreiben($umschlag)) {
            $this->GatewayWecken();
        }
        return $text;
    }

    // ------------------------------------------------------------------
    // Gateway und Signal
    // ------------------------------------------------------------------

    /** Die Instanz, deren Konfiguration gilt — beim Scanner immer das Gateway. */
    protected function KonfigID(): int
    {
        return $this->GatewayID();
    }

    /** Und unter der der Bestand liegt (Medien, Kategorien) — ebenfalls das Gateway. */
    protected function BestandID(): int
    {
        return $this->GatewayID();
    }

    private function GatewayID(): int
    {
        $eltern = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($eltern > 0) {
            return $eltern;
        }
        /* Sonst die Instanz mit der NIEDRIGSTEN Kennung: sie bedient die App.
           Dieselbe Regel wie im VRR-Modul und im Stundenplan. */
        $ids = (array)@IPS_GetInstanceListByModuleID(self::GATEWAY_GUID);
        if ($ids === []) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }

    /**
     * Einmalig ans Gateway haengen. Der Merker steht VOR dem Verbinden:
     * IPS_ConnectInstance loest auf beiden Seiten ApplyChanges aus, und das
     * des Gateways ist teuer. Wer die Verbindung spaeter bewusst loest, behaelt
     * sie geloest. Dieselbe Vorgehensweise wie im VRR-Modul.
     */
    private function GatewayEinmaligVerbinden(): void
    {
        if ((bool)@$this->ReadAttributeBoolean('ParentMigrated')) {
            return;
        }
        @$this->WriteAttributeBoolean('ParentMigrated', true);
        if ((int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0) > 0) {
            return;
        }
        $gateway = $this->GatewayID();
        if ($gateway > 0 && @IPS_InstanceExists($gateway)) {
            @IPS_ConnectInstance($this->InstanceID, $gateway);
        }
    }

    /**
     * Die Signalvariable des Gateways abonnieren.
     *
     * Die Kennung wird bei jedem Uebernehmen frisch gesucht und NICHT
     * gemerkt: eine gemerkte Kennung zeigt nach einem Neuaufbau des Gateways
     * auf ein fremdes Objekt. Fehlt die Variable (Gateway aelter als dieser
     * Umbau), bleibt es beim Takt — der Kanal traegt trotzdem, nur traeger.
     */
    private function SignalAbonnieren(): void
    {
        $gateway = $this->GatewayID();
        if ($gateway <= 0) {
            return;
        }
        $var = @IPS_GetObjectIDByIdent(self::SCAN_SIGNAL_IDENT, $gateway);
        if (is_int($var) && $var > 0 && @IPS_VariableExists($var)) {
            $this->RegisterMessage($var, VM_UPDATE);
        }
    }

    /** Den Einmal-Timer setzen — die Arbeit gehoert in die eigene Spur, nicht hierher. */
    private function Wecken(): void
    {
        @$this->RegisterOnceTimer('Weck', 'IPS_RequestAction($_IPS[\'TARGET\'], \'Weck\', 0);');
    }

    /**
     * Dem Gateway sagen, dass etwas im Kanal liegt. Erlaubte Richtung: der
     * Ruf kostet die Dauer eines Hooks. Faellt er aus (Gateway beschaeftigt,
     * Modul neu geladen), holt das Gateway das Ergebnis mit seinem eigenen
     * Netz — es geht also nichts verloren, es dauert nur.
     */
    private function GatewayWecken(): void
    {
        $gateway = $this->GatewayID();
        if ($gateway > 0 && @IPS_InstanceExists($gateway)) {
            @IPS_RequestAction($gateway, 'ScanInbox', 0);
        }
    }

    private function TaktSetzen(int $ms): void
    {
        if ((int)@$this->ReadAttributeInteger('TimerMs') === $ms) {
            return;   // sonst startet SetTimerInterval die Uhr neu und der Timer verhungert
        }
        @$this->SetTimerInterval('Takt', $ms);
        @$this->WriteAttributeInteger('TimerMs', $ms);
    }

    /**
     * Die Quellen dieser Instanz.
     *
     * Mit Rueckfall: nach einem Modul-Reload ohne Kernel-Neustart gibt es die
     * Eigenschaft noch nicht, und ein Scanner ganz ohne Quelle waere stumm,
     * ohne dass man saehe warum.
     *
     * @return list<string>
     */
    private function Quellen(): array
    {
        $roh = json_decode((string)@IPS_GetProperty($this->InstanceID, 'Jobs'), true);
        if (!is_array($roh) || $roh === []) {
            return ['probe'];
        }
        $raus = [];
        foreach ($roh as $z) {
            if (!is_array($z) || ($z['active'] ?? true) !== true) {
                continue;
            }
            $rolle = (string)($z['role'] ?? '');
            if (ScanKanalCalc::QuelleGueltig($rolle) && !in_array($rolle, $raus, true)) {
                $raus[] = $rolle;
            }
        }
        return $raus === [] ? ['probe'] : $raus;
    }
}
