<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Konfig.php';
require_once __DIR__ . '/../libs/Belegung.php';
require_once __DIR__ . '/../libs/ScanKanal.php';
/* Die Fachteile kommen aus dem Gateway-Ordner — dieselben Dateien, nicht
   Kopien. Eingebunden wird immer nur der BAU, nie der Leser: die Fragen der
   App kommen im Gateway an, und dort bleiben sie auch. */
require_once __DIR__ . '/../SymDoGateway/libs/DokuGemein.php';
require_once __DIR__ . '/../SymDoGateway/libs/DokuBau.php';
require_once __DIR__ . '/../libs/AiJobRunner.php';

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
 * Zwei Quellen kennt er heute:
 *   „probe" — der Selbsttest des Kanals, wahlweise mit Verweildauer. Er
 *             beweist den Weg, bevor ein echter Scan darauf faehrt.
 *   „doku"  — das Handbuch-Verzeichnis. Es kroch frueher im Gateway: drei
 *             Sekunden alle acht, eine Woche lang, also 37 % Dauerlast in
 *             genau der Spur, die auch die App bedient.
 * Die uebrigen (edu, moodle, mail, briefing) ziehen einzeln nach.
 */
class SymDoScanner extends IPSModuleStrict
{
    use Konfig;
    use Belegung;
    use ScanKanal;
    use DokuGemein;
    use DokuBau;

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
        /* Wofuer diese Instanz da ist — „jobs", „schule", „briefing". Nicht
           Zierde: daran erkennt das Gateway seine eigenen Scanner wieder und
           traegt spaeter umgezogene Quellen nach, statt eine zweite Instanz
           danebenzustellen. */
        $this->RegisterPropertyString('Rolle', '');

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
        /* Auf WELCHE Variable wir uns in diesem Kernel-Lauf angemeldet haben.
           0 heisst „noch keine" — dann versucht es jeder Takt erneut. Das
           deckt den einen Fall ab, in dem das Gateway seine Signalvariable
           erst nach unserem Uebernehmen anlegt. */
        $this->RegisterAttributeInteger('SignalAbo', 0);

        $this->RegisterTimer('Takt', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Takt\', 0);');
        /* Ein zweiter Zeitgeber fuer Arbeit, die in Etappen laeuft: der
           Handbuch-Bau kriecht tausend Seiten ab, jede Etappe vier Sekunden.
           Er ist bewusst NICHT der Takt — der ist das Sicherheitsnetz und
           soll traege bleiben. */
        $this->RegisterTimer('Weiter', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Weiter\', 0);');
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

        /* KEIN Rueckruf ins Gateway aus ApplyChanges. IPS_ConnectInstance
           loest drueben ein ApplyChanges aus — und wenn das GATEWAY uns gerade
           erst angelegt hat, liefe das mitten in seinem eigenen Lauf. Der
           Einmal-Zeitgeber schiebt es in unsere Spur, wo es niemanden stoert. */
        if (!(bool)@$this->ReadAttributeBoolean('ParentMigrated')) {
            @$this->RegisterOnceTimer('Verbinden', 'IPS_RequestAction($_IPS[\'TARGET\'], \'Verbinden\', 0);');
        }
        @$this->WriteAttributeInteger('SignalAbo', 0);
        $this->SignalAbonnieren();

        /* Nach einem Kernelstart ist der Timer aus, der gemerkte Wert aber noch
           da — sonst setzte ihn niemand wieder. */
        @$this->WriteAttributeInteger('TimerMs', -1);
        // Liegengebliebenes aus einem abgebrochenen Lauf.
        @$this->WriteAttributeString('Laeuft', '');

        if ($this->GatewayID() <= 0) {
            /* Ohne Gateway gibt es keinen Kanal: sein Verzeichnis haengt an
               dessen Kennung. Lieber sichtbar stumm als in einen Topf „0"
               schreiben, den nie jemand leert.

               Der Takt bleibt aber AN. Er ist der einzige Rueckweg: die
               Instanzliste des Gateways kommt waehrend eines Modul-Neuladens
               kurz leer zurueck (das Gateway selbst faengt genau diese Falle
               in AppApiOwnerID ab). Faellt unser Uebernehmen in dieses
               Fenster und schalteten wir den Takt ab, waere die Instanz bis
               zum naechsten Kernelstart tot: kein Zeitgeber, kein Abo, und
               hereinrufen darf uns wegen der Richtungsregel niemand. */
            $this->TaktSetzen(self::TAKT_MS);
            $this->SetStatus(104);
            return;
        }

        $this->TaktSetzen(self::TAKT_MS);
        $this->ScanAnspruchVerwaist();

        $this->SetStatus(102);

        // Was das Gateway hinterlegt hatte, ist mit diesem Lauf uebernommen.
        $this->NachtragWeg();

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
                case 'Weiter':
                    // Die naechste Etappe einer Arbeit, die in Stuecken laeuft.
                    $this->Etappe();
                    return;
                case 'Verbinden':
                    $this->GatewayEinmaligVerbinden();
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
            'rolle'    => (string)@$this->ReadPropertyString('Rolle'),
            'quellen'  => $this->Quellen(),
            'gateway'  => $this->GatewayID(),
            'signal'   => (int)@$this->ReadAttributeInteger('SignalAbo'),
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
        /* Hat das Gateway uns eine Quelle nachgetragen, uebernehmen wir sie
           SELBST — in unserer Spur. Das Gateway darf das nicht tun: sein
           IPS_ApplyChanges wartete auf uns, und liefe hier gerade ein langer
           Scan, stuende solange die ganze App. */
        if ($this->NachtragOffen()) {
            /* Blank IPS_ApplyChanges im Zeitgeber-Skript, nicht von hier aus:
               ein Uebernehmen aus der eigenen laufenden Aktion heraus wuerde
               die Spur von innen betreten. Dasselbe Muster wie im Gateway
               („UebernehmenNachtragen"). */
            @$this->RegisterOnceTimer('Uebernehmen', 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
            return;   // ApplyChanges weckt gleich selbst nach, falls etwas wartet
        }
        $quellen = $this->Quellen();
        if ($quellen === [] || $this->GatewayID() <= 0) {
            return;
        }
        if ($this->GetStatus() !== 102) {
            // Das Gateway ist wieder da — sonst bliebe die Instanz auf 104 stehen.
            $this->SetStatus(102);
        }
        /* Nachholen, falls das Gateway seine Signalvariable erst nach unserem
           Uebernehmen angelegt hat — sonst weckte uns bis zum naechsten
           Kernelstart nur noch der Takt. */
        $this->SignalAbonnieren();
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
        /* Nicht jede Etappe ist ein Ergebnis. Der Handbuch-Bau laeuft ueber
           Stunden in Vier-Sekunden-Stuecken; ein Umschlag je Stueck waeren
           vierhundert Umschlaege fuer EINE Auskunft. Gemeldet wird, wenn etwas
           fertig ist oder schiefging. */
        $melden = true;

        switch ($quelle) {
            case 'probe':
                /* Der Selbsttest. Er tut absichtlich nichts Fachliches — er
                   beweist nur, dass der Weg traegt. „verweilen" ist der
                   Beweislauf: waehrend diese Spur steht, muss die Gateway-Spur
                   weiter in Millisekunden antworten.
                   Zuerst aus dem AUFTRAG, dann aus den Rohangaben: ueber den
                   Kanal kommt nur der geprueifte Block an, von Hand auch das
                   Rohe. Stuende hier nur `$roh`, verweilte der Weg ueber das
                   Gateway immer null Sekunden — und der Beweis waere keiner. */
                $verweilen = max(0, min(self::PROBE_VERWEIL_MAX,
                    (int)($auftrag['verweilen'] ?? $roh['verweilen'] ?? 0)));
                if ($verweilen > 0) {
                    sleep($verweilen);
                }
                $text = sprintf($this->Translate('Channel checked (%d s dwell, reason %s)'),
                    $verweilen, (string)$auftrag['anlass']);
                break;

            case 'auftrag':
                /* Die KI-Auftraege. Das Gateway hat den Prompt gebaut und den
                   Auftrag abgelegt; hier laeuft nur das Langsame — der Anruf
                   beim Anbieter, bis zu 45 Sekunden, lokal bis zu 300. Genau
                   dafuer gibt es diese Instanz. */
                $n = $this->AuftraegeAbarbeiten();
                if ($n === 0) {
                    // Nichts zu tun, oder alles vertagt: keine Meldung noetig.
                    $melden = false;
                    break;
                }
                $text = sprintf($this->Translate('%d AI jobs answered'), $n);
                break;

            case 'doku':
                /* Das Handbuch-Verzeichnis. Frueher baute es das Gateway
                   selbst: drei Sekunden alle acht, eine Woche lang — 37 %
                   Dauerlast in genau der Spur, die auch die App bedient.
                   Hier stoert es niemanden, und der Blick auf laufende
                   Gespraeche entfaellt deshalb. */
                if (!$this->DokuBaubar()) {
                    $ok = false;
                    $text = $this->Translate('No OpenAI key — the handbook index cannot be built');
                    $this->EtappeSetzen(0);
                    break;
                }
                $stand = $this->DokuIndexPflegen();
                if ((int)($stand['fehler'] ?? 0) >= self::DOKU_FEHLER_MAX) {
                    $ok = false;
                    $text = $this->Translate('Embedding failed repeatedly — build halted');
                    $this->EtappeSetzen(0);
                    break;
                }
                if (!$stand['fertig']) {
                    // Noch nicht durch: in acht Sekunden weiter, ohne Umschlag.
                    $this->EtappeSetzen(self::DOKU_TAKT);
                    $melden = false;
                    break;
                }
                $this->EtappeSetzen(0);
                $text = sprintf($this->Translate('Handbook index ready: %d pages, %d sections'),
                    count($stand['seiten']), (int)$stand['stuecke']);
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

        if ($melden && $this->ScanErgebnisSchreiben($umschlag)) {
            $this->GatewayWecken();
        }
        return $text;
    }

    /**
     * Die Warteschlange der KI-Auftraege abarbeiten.
     *
     * Der Laeufer selbst kennt kein Symcon — er bekommt eine Uhr, die
     * Anbieter-Sperre, einen Weg den Anbieter zu bauen und einen, das Ergebnis
     * zu melden. Alles vier wird hier hereingereicht.
     *
     * Die Sperre traegt einen EIGENEN Namen, nicht den der Gateway-Sperre.
     * Das ist wichtiger, als es aussieht: die Gateway-Sperre wird auf allen
     * synchronen Wegen mit Frist NULL geholt — Sprachdialog, Briefing,
     * Postauswertung, Kachel. Teilten wir sie, bekaeme jeder dieser Wege
     * waehrend eines laufenden Auftrags sofort „belegt" (bis zu fuenf Minuten
     * bei einem lokalen Server), und die Postauswertung buchte dafuer sogar
     * Tagesbudget. Vorher war das unerreichbar, weil nur die Gateway-Spur
     * selbst die Sperre halten konnte — und die ist in sich serialisiert.
     *
     * Der Grund der Sperre bleibt gewahrt: sie verhindert, dass sich ZWEI
     * Anbieteraufrufe aus der Warteschlange ueberholen. Ein gleichzeitiger
     * Aufruf aus dem Gateway belegt keinen Webhook-Arbeiter mehr als vorher.
     */
    private function AuftraegeAbarbeiten(): int
    {
        $gateway = $this->GatewayID();
        $laden = AiJobStore::in(rtrim((string) @IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR
            . 'symdo_aijobs' . DIRECTORY_SEPARATOR . $gateway . DIRECTORY_SEPARATOR, false);
        if (!$laden->nutzbar()) {
            return 0;
        }
        $sperre = 'SDSC_AiJob_' . $gateway;

        $laeufer = new AiJobRunner(
            $laden,
            function (): AiProvider {
                $konfig = [];
                foreach (AiProvider::KONFIG_FELDER as $feld) {
                    $konfig[$feld] = (string) $this->AiProp($feld);
                }
                return AiProvider::ausKonfiguration($konfig);
            },
            static fn(int $ms): bool => (bool) @IPS_SemaphoreEnter($sperre, $ms),
            static function () use ($sperre): void { @IPS_SemaphoreLeave($sperre); },
            function (string $id) use ($gateway): void {
                /* Der kurze Ruf zurueck. Erlaubte Richtung: er kostet das
                   Gateway die Dauer eines Hooks, also Millisekunden — und die
                   App wartet auf genau diese Meldung. */
                if ($gateway > 0 && @IPS_InstanceExists($gateway)) {
                    @IPS_RequestAction($gateway, 'AiJobDone', $id);
                }
            },
            static fn(): int => time()
        );
        return $laeufer->abarbeiten();
    }

    /**
     * Die naechste Etappe einer Arbeit, die in Stuecken laeuft.
     *
     * Heute gibt es genau eine: den Handbuch-Bau. Er braucht keinen neuen
     * Auftrag je Stueck — der erste hat gereicht, ab dann traegt ihn dieser
     * Zeitgeber, bis das Verzeichnis steht.
     */
    private function Etappe(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY || $this->GatewayID() <= 0) {
            return;
        }
        if (!in_array('doku', $this->Quellen(), true)) {
            $this->EtappeSetzen(0);
            return;
        }
        $this->Ausfuehren('doku', ScanKanalCalc::AuftragBlock(['anlass' => 'timer']), [], 0);
    }

    /**
     * Den Etappen-Zeitgeber stellen.
     *
     * Hier steht ABSICHTLICH kein Merker wie bei `TaktSetzen`: dort verhindert
     * er, dass ein wiederholtes Setzen die Uhr immer wieder von vorn starten
     * laesst. Hier ist genau das gewollt — gesetzt wird am ENDE einer Etappe,
     * und die naechste soll acht Sekunden SPAETER kommen.
     */
    private function EtappeSetzen(int $ms): void
    {
        @$this->SetTimerInterval('Weiter', $ms);
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
        /* Die Kennung des Elternknotens ueberlebt dessen Loeschung — sie zeigt
           dann auf nichts. Ungeprueft uebernommen, schriebe der Kanal in ein
           Verzeichnis, das kein Gateway je liest. */
        $eltern = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($eltern > 0 && @IPS_InstanceExists($eltern)) {
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
        if ((int)@$this->ReadAttributeInteger('SignalAbo') > 0) {
            return;   // in diesem Kernel-Lauf schon geschehen
        }
        $gateway = $this->GatewayID();
        if ($gateway <= 0) {
            return;
        }
        $var = @IPS_GetObjectIDByIdent(self::SCAN_SIGNAL_IDENT, $gateway);
        if (is_int($var) && $var > 0 && @IPS_VariableExists($var)) {
            $this->RegisterMessage($var, VM_UPDATE);
            @$this->WriteAttributeInteger('SignalAbo', $var);
        }
    }

    /**
     * Hat das Gateway uns etwas hinterlegt, das wir noch nicht uebernommen
     * haben?
     *
     * Erkannt wird das an einer Markierung im Kanal, NICHT am Vergleich von
     * hinterlegtem und aktivem Stand: `IPS_GetProperty` zeigt hinterlegte
     * Werte nicht (am 13.09.2026 in der 9.1 nachgemessen — nach
     * `IPS_SetProperty` liefert es unveraendert den alten Stand). Ein
     * Vergleich haette also immer „nichts Neues" gesagt, und das Gateway
     * haette `IPS_ApplyChanges` auf uns rufen muessen: der eine Griff, der
     * es auf unsere Spur warten laesst.
     */
    private function NachtragOffen(): bool
    {
        $pfad = $this->NachtragPfad();
        return $pfad !== '' && @is_file($pfad);
    }

    /**
     * Die Markierung wegraeumen — erst NACH dem Uebernehmen, deshalb am Ende
     * von ApplyChanges. Bliebe sie liegen, kostete das eine ueberfluessige
     * Uebernahme; verschwaende sie zu frueh, ginge ein Nachtrag verloren.
     */
    private function NachtragWeg(): void
    {
        $pfad = $this->NachtragPfad();
        if ($pfad !== '') {
            @unlink($pfad);
        }
    }

    private function NachtragPfad(): string
    {
        $dir = $this->ScanDir('nachtrag');
        return $dir === '' ? '' : $dir . $this->InstanceID . '.json';
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
        $roh = json_decode((string)@$this->ReadPropertyString('Jobs'), true);
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
