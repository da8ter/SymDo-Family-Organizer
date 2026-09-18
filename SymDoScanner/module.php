<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Konfig.php';
require_once __DIR__ . '/../libs/Belegung.php';
require_once __DIR__ . '/../libs/ScanKanal.php';
/* Die Fachteile kommen aus dem Gateway-Ordner — dieselben Dateien, nicht
   Kopien. Eingebunden wird immer nur der BAU, nie der Leser: die Fragen der
   App kommen im Gateway an, und dort bleiben sie auch. */
require_once __DIR__ . '/../SymDoGateway/libs/EduLesen.php';
require_once __DIR__ . '/../SymDoGateway/libs/MoodleCalc.php';
require_once __DIR__ . '/../SymDoGateway/libs/MoodleLesen.php';
require_once __DIR__ . '/../SymDoGateway/libs/UntisLesen.php';
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
 * Was er heute bedient:
 *   „probe"   — der Selbsttest des Kanals, wahlweise mit Verweildauer. Er
 *               beweist den Weg, bevor ein echter Scan darauf faehrt.
 *   „auftrag" — die Warteschlange der KI-Aufrufe. Ueber sie laufen inzwischen
 *               auch Klassenseiten, LOGINEO, Postfach und Briefing.
 *   „doku"    — das Handbuch-Verzeichnis. Es kroch frueher im Gateway: drei
 *               Sekunden alle acht, eine Woche lang, also 37 % Dauerlast in
 *               genau der Spur, die auch die App bedient.
 *   „edu"     — die Klassenseiten holen und zerlegen.
 *   „moodle"  — LOGINEO: Kurse, Karten, Aufgaben, Abstimmungen, Termine.
 *
 * Draussen bleibt WebUntis (die Anmeldung ist die einzige unumkehrbare
 * Handlung des ganzen Umbaus — drei Fehlversuche sperren das Schulkonto) und
 * der Mail-Webhook (sein Zustand IST die Spool-Datei).
 */
class SymDoScanner extends IPSModuleStrict
{
    use Konfig;
    use Belegung;
    use ScanKanal;
    use EduLesen;
    use MoodleLesen;
    use UntisLesen;
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

    /**
     * Das Formular dieser Instanz: nur ein Blick auf ihren Zustand.
     *
     * Eingestellt wird hier nichts — Rolle und Quellen traegt das Gateway ein,
     * wenn es seine Scanner anlegt. Was fehlte, war der Blick: eine Instanz,
     * die stumm dasteht, sah bisher genauso aus wie eine, die arbeitet.
     *
     * Bewusst nur Billiges: zwei Eigenschaften, ein Kernel-Blick auf den
     * Elternknoten, ein Verzeichnis-Blick in den Kanal. Symcon baut das
     * Formular in der Spur DIESER Instanz — laeuft gerade ein Scan, erscheint
     * es erst danach. Etwas, das hier selbst arbeitete, liesse die Konsole
     * jedes Mal warten.
     */
    public function GetConfigurationForm(): string
    {
        $stand = $this->StandZeilen();

        $form = [
            'elements' => [
                ['type' => 'Label', 'caption' =>
                    $this->Translate('This instance does the long-running work for the gateway: fetching, reading and AI jobs. ')
                    . $this->Translate('The app stays responsive while it runs.')],
                ['type' => 'Label', 'caption' =>
                    $this->Translate('There is nothing to set up here — the gateway assigns role and sources.')],

                ['type' => 'Label', 'name' => 'StandRolle',   'caption' => $stand['rolle']],
                ['type' => 'Label', 'name' => 'StandQuellen', 'caption' => $stand['quellen']],
                ['type' => 'Label', 'name' => 'StandGateway', 'caption' => $stand['gateway']],
                ['type' => 'Label', 'name' => 'StandWartend', 'caption' => $stand['wartend']],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => $this->Translate('Refresh'),
                 'onClick' => 'IPS_RequestAction($id, "StandZeigen", 0);'],
            ],
            'status' => [],
        ];

        return (string)json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Die vier Zeilen des Formulars — eine Quelle fuer den Aufbau und fuer den
     * Knopf. Zwei getrennte Stellen zeigten frueher oder spaeter Verschiedenes.
     *
     * @return array{rolle:string,quellen:string,gateway:string,wartend:string}
     */
    private function StandZeilen(): array
    {
        $rolle   = (string)@$this->ReadPropertyString('Rolle');
        $quellen = $this->Quellen();
        $gateway = $this->GatewayID();

        return [
            'rolle' => sprintf($this->Translate('Role: %s'),
                $rolle === '' ? $this->Translate('not assigned yet') : $this->RollenName($rolle)),
            'quellen' => sprintf($this->Translate('Sources: %s'),
                implode(', ', array_map(fn(string $q): string => $this->QuellenName($q), $quellen))),
            'gateway' => ($gateway > 0 && @IPS_InstanceExists($gateway))
                ? sprintf($this->Translate('Gateway: %1$s (#%2$d)'), (string)@IPS_GetName($gateway), $gateway)
                : $this->Translate('Gateway: none found — this instance stays idle'),
            'wartend' => sprintf($this->Translate('Waiting jobs: %d'),
                $this->ScanAuftraegeOffen($quellen)),
        ];
    }

    /** Den Formularkopf auffrischen, ohne das Formular neu zu bauen. */
    private function StandAnzeigen(): void
    {
        $stand = $this->StandZeilen();
        $this->UpdateFormField('StandRolle',   'caption', $stand['rolle']);
        $this->UpdateFormField('StandQuellen', 'caption', $stand['quellen']);
        $this->UpdateFormField('StandGateway', 'caption', $stand['gateway']);
        $this->UpdateFormField('StandWartend', 'caption', $stand['wartend']);
    }

    /** Die Rolle in Worten. Unbekannte Rollen zeigen ihren Schluessel. */
    private function RollenName(string $rolle): string
    {
        $namen = [
            'jobs'     => $this->Translate('Jobs'),
            'schule'   => $this->Translate('School'),
            'briefing' => $this->Translate('Briefing'),
        ];
        return $namen[$rolle] ?? $rolle;
    }

    /** Eine Quelle in Worten. */
    private function QuellenName(string $quelle): string
    {
        $namen = [
            'probe'    => $this->Translate('Self-test'),
            'doku'     => $this->Translate('Handbook'),
            'edu'      => $this->Translate('Class pages'),
            'moodle'   => $this->Translate('LOGINEO'),
            'mail'     => $this->Translate('Mailbox'),
            'briefing' => $this->Translate('Briefing'),
            'untis'    => $this->Translate('WebUntis'),
            'auftrag'  => $this->Translate('AI jobs'),
        ];
        return $namen[$quelle] ?? $quelle;
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
                case 'StandZeigen':
                    // Der Knopf im Formular. Er laeuft im Skript-Thread der
                    // Konsole, nicht in der Gateway-Spur.
                    $this->StandAnzeigen();
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
        /* Was der Umschlag ausser der Auskunft mitbringt. Die meisten Quellen
           bringen nichts — sie legen ihr Ergebnis dort ab, wo der Leser es
           ohnehin sucht (der Handbuch-Bau etwa als Datei unter der BestandID). */
        $nutzlast = [];
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

            case 'edu':
                /* Die Klassenseiten HOLEN und ZERLEGEN. Mehr nicht: Medien,
                   Sperre und Sperrliste haengen alle an der Gateway-Instanz,
                   und der Bestand wird dort gepflegt (siehe EduEinpflegen).
                   Hier laeuft nur das Langsame — vier Seiten a einer halben bis
                   fuenfzehn Sekunden.

                   Die Seiten kommen MIT dem Auftrag. Sie stehen als Eigenschaft
                   am Gateway, und `IPS_GetProperty` auf eine fremde Instanz
                   zeigt einen nur eingetippten Wert nicht — der Scanner koennte
                   sie also weder lesen noch merken, ob sie aktuell sind. */
                $erg = $this->EduSeitenLesen((array)($auftrag['seiten'] ?? []));
                $nutzlast['seiten'] = $erg['seiten'];
                /* „Alles auswerten" reist zurueck: auswerten tut das Gateway,
                   und nur dieses Feld sagt ihm, dass auch schon Gemerktes
                   drankommen soll. */
                $nutzlast['alles'] = ($auftrag['alles'] ?? false) === true;
                $ok = $erg['fehler'] === [];
                $text = $erg['text'];
                if ($erg['seiten'] === [] && $erg['fehler'] === []) {
                    // Nichts eingerichtet: kein Umschlag, keine Meldung.
                    $melden = false;
                }
                break;

            case 'moodle':
                /* LOGINEO. Dasselbe Bild wie bei den Klassenseiten: hier wird
                   nur GEHOLT und zerlegt, eingepflegt wird im Gateway. Der
                   Unterschied ist die Menge — ein Konto kostet einen Abruf fuer
                   den Standort, einen fuer die Kurse, einen je Kurs fuer die
                   Inhalte, und je Aufgabe noch einen fuer den Abgabestand. Ein
                   Abruf darf 25 Sekunden brauchen.

                   Die ZUGAENGE reisen mit dem Auftrag, samt Token: er liegt in
                   einem Attribut des Gateways, und ein Attribut ist von hier
                   aus nicht zu lesen. Dasselbe gilt fuer die Sperrliste. */
                $erg = $this->MoodleErnten((array)($auftrag['konten'] ?? []),
                    (array)($auftrag['gesperrt'] ?? []));
                foreach (['seiten', 'hausaufgaben', 'vorschlaege'] as $feld) {
                    if ($erg[$feld] !== []) {
                        $nutzlast[$feld] = $erg[$feld];
                    }
                }
                $ok = $erg['ok'];
                $text = $erg['text'];
                if ($erg['konten'] === 0) {
                    // Nichts eingerichtet: kein Umschlag, keine Meldung.
                    $melden = false;
                }
                break;

            case 'untis':
                /* WebUntis. Hier wird angemeldet — die einzige unumkehrbare
                   Handlung dieses Moduls: drei Fehlanmeldungen sperren das
                   Konto der Familie. Deshalb GENAU EINE Anmeldung je Lauf
                   (`UntisKontoErnten` klammert alle Kinder), und ob ueberhaupt
                   angemeldet werden darf, hat das Gateway vorher entschieden
                   (`UntisGesperrt`) — der Fehlerzaehler ist ein Attribut und
                   von hier aus nicht zu lesen.

                   Zugangsdaten reisen NICHT mit dem Auftrag: Server, Schule,
                   Benutzer und Kennwort sind Eigenschaften des Gateways, und
                   die liest `UntisProp` ueber `KonfigID()` selbst. */
                $kinder = (array)($auftrag['kinder'] ?? []);
                if ($kinder === []) {
                    $melden = false;
                    break;
                }
                $erg = $this->UntisKontoErnten($kinder, ($auftrag['alles'] ?? false) !== true);
                $ok = ($erg['ok'] ?? false) === true;
                if (!$ok) {
                    /* Der Grund reist mit: nur das Gateway darf den
                       Fehlerzaehler hochsetzen, und es braucht dafuer den
                       Code, den WebUntis geliefert hat. */
                    $nutzlast['untis'] = ['ok' => false, 'code' => (int)($erg['code'] ?? 0),
                                          'kinder' => []];
                    $text = (string)($erg['meldung'] ?? '');
                    break;
                }
                $nutzlast['untis'] = ['ok' => true, 'code' => 0,
                                      'kinder' => (array)($erg['kinder'] ?? [])];
                $text = sprintf($this->Translate('%d student(s) read'), count($erg['kinder']));
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
        foreach ($nutzlast as $feld => $wert) {
            $umschlag[$feld] = $wert;
        }
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
     * Die Klassenseiten holen und zerlegen.
     *
     * Das Teure und das einzige, was hier laeuft: je Seite ein Abruf von einer
     * halben bis fuenfzehn Sekunden, dann der Zerleger aus `EduLesen`.
     * Eingepflegt wird im Gateway — die Karten reisen als Umschlag hinueber.
     *
     * Eine Seite, die NICHT gelesen werden konnte, kommt gar nicht erst in den
     * Umschlag. Das ist kein Detail: das Gateway gleicht je Seite ab, welche
     * Karten verschwunden sind, und eine leere Seite hiesse dort „alle Karten
     * dieser Klassenseite sind weg". Der Fehler wird gemeldet, die Seite bleibt
     * beim vorigen Stand, und der naechste Lauf versucht es erneut.
     *
     * @param list<array{name:string,url:string,userId:string}> $seiten
     * @return array{seiten:list<array<string,mixed>>,fehler:list<string>,text:string}
     */
    private function EduSeitenLesen(array $seiten): array
    {
        $raus = [];
        $fehler = [];
        $karten = 0;
        foreach ($seiten as $s) {
            $url  = trim((string)($s['url'] ?? ''));
            $name = trim((string)($s['name'] ?? ''));
            if ($url === '') {
                continue;
            }
            $antwort = AiRecipePage::holen($url);
            if (($antwort['ok'] ?? false) !== true) {
                /* Die ADRESSE nicht ins Protokoll: sie ist der Zugang zur
                   Klassenseite, und das Protokoll ist weltlesbar. */
                $fehler[] = $name . ': ' . (string)($antwort['code'] ?? 'Abruf fehlgeschlagen');
                continue;
            }
            $rumpf = (string)($antwort['body'] ?? '');
            $kartenDerSeite = $this->EduKarten($rumpf);
            if ($kartenDerSeite === []) {
                /* Kein stilles Schweigen: bricht das Markup der Schule, saehe es
                   sonst aus wie „nichts Neues" — und das Gateway haette die
                   ganze Seite archiviert. */
                $fehler[] = $name . ': 0 Karten — hat sich die Seite geaendert?';
                continue;
            }
            $raus[] = [
                'seite'  => ['name' => $name, 'url' => $url,
                             'userId' => trim((string)($s['userId'] ?? ''))],
                'quelle' => 'edu',
                'karten' => array_values($kartenDerSeite),
                /* Verweise auf ANDERE Anlagen. Sie muessen HIER heraus, denn nur
                   hier liegt der rohe Rumpf: das Gateway bekommt die Karten,
                   und ein Verweis kann auch neben ihnen stehen.
                   Aufgenommen wird drueben — die Fundliste ist ein Attribut des
                   Gateways, und dort haengt auch der Schalter „Verlinkten
                   Seiten folgen" samt Deckel und Erreichbarkeitsprobe. */
                'funde'  => $this->EduKartenLinks($rumpf, $url),
            ];
            $karten += count($kartenDerSeite);
        }

        $text = sprintf($this->Translate('%1$d card(s) on %2$d page(s) read'), $karten, count($raus));
        if ($fehler !== []) {
            $text .= ' — ' . implode(' | ', $fehler);
        }
        return ['seiten' => $raus, 'fehler' => $fehler, 'text' => $text];
    }

    /**
     * Alle LOGINEO-Konten ernten.
     *
     * Ein Konto, das nicht lesbar ist, haelt die anderen NICHT auf — genau
     * dafuer zaehlt das Gateway die Fehlschlaege je Zugang. Und ein Konto ohne
     * Token kommt hier gar nicht erst an: die weisse Liste des Auftrags laesst
     * es nicht durch.
     *
     * @param list<array<string,mixed>> $konten
     * @param list<string>              $gesperrt
     * @return array{ok:bool,konten:int,text:string,seiten:list<array<string,mixed>>,
     *               hausaufgaben:list<array<string,mixed>>,vorschlaege:list<array<string,mixed>>}
     */
    private function MoodleErnten(array $konten, array $gesperrt): array
    {
        $seiten = [];
        $hausaufgaben = [];
        $vorschlaege = [];
        $teile = [];
        $ok = true;
        foreach ($konten as $zugang) {
            if (!is_array($zugang)) {
                continue;
            }
            $name = trim((string)($zugang['name'] ?? '')) !== ''
                ? (string)$zugang['name'] : ('#' . (string)($zugang['userId'] ?? '?'));
            $ernte = $this->MoodleKontoErnten($zugang, $gesperrt);
            if (($ernte['ok'] ?? false) !== true) {
                $ok = false;
                $teile[] = $name . ': ' . $this->Translate('nothing readable');
                continue;
            }
            foreach ($ernte['seiten'] as $e) {
                $seiten[] = $e;
            }
            foreach ($ernte['hausaufgaben'] as $e) {
                $hausaufgaben[] = $e;
            }
            foreach ($ernte['vorschlaege'] as $e) {
                $vorschlaege[] = $e;
            }
            $teile[] = $name . ': ' . sprintf(
                $this->Translate('%1$d course(s), %2$d card(s) read'),
                (int)$ernte['kurse'], (int)$ernte['karten'])
                /* Der Leser laesst die Hausaufgaben weg, wenn die Schule den
                   Abruf schuldig blieb — das Gateway erfaehrt es nur ueber
                   diesen Text, der Umschlag traegt dafuer kein Feld. */
                . ((int)($ernte['aufgabenFehler'] ?? 0) > 0
                    ? ', ' . $this->Translate('homework not readable this time — kept as it was')
                    : '');
        }
        return ['ok' => $ok, 'konten' => count($konten),
                'text' => implode(' | ', $teile),
                'seiten' => $seiten, 'hausaufgaben' => $hausaufgaben,
                'vorschlaege' => $vorschlaege];
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
