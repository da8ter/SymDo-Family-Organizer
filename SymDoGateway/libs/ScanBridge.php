<?php

declare(strict_types=1);

/**
 * Die Gateway-Seite des Scan-Kanals: Auftraege hinaus, Ergebnisse herein.
 *
 * `ScanKanal` (in `List/libs/`) kennt nur Dateien — beide Seiten binden ihn
 * ein. Dieser Trait ist das, was NUR das Gateway tut:
 *
 *  - Er haelt die **Signalvariable** `ScanSignal`. Sie ist der ganze Trick des
 *    Umbaus. Ein `IPS_RequestAction` in eine Scanner-Instanz waere synchron:
 *    das Gateway wartete dann auf deren Spur und haette sich das Problem nur
 *    verschoben. `SetValue` dagegen kehrt sofort zurueck, und die Zustellung
 *    laeuft in Symcons eigenem Nachrichten-Thread — gemessen weckt sie eine
 *    Instanz auch mitten in einem zehn Sekunden langen Lauf.
 *  - Er **pflegt die Ergebnisse ein**, wenn ein Scanner „ScanInbox" ruft. Das
 *    ist die erlaubte Richtung: ein Ruf ins Gateway kostet die Dauer eines
 *    Hooks (live gemessen: 0,4 ms).
 *  - Er **legt die Scanner-Instanzen selbst an** (so entschieden am
 *    12.09.2026). Wer die Bibliothek aktualisiert, soll nichts von Hand
 *    einrichten muessen. Angelegt wird nur, was auch Arbeit hat: eine Rolle
 *    ohne umgezogene Quelle bekommt keine stumme Instanz.
 *
 * Die Richtungsregel, an der alles haengt:
 *
 *   Gateway -> Scanner  NUR passiv (Auftragsdatei + Signalvariable)
 *   Scanner -> Gateway  synchron erlaubt, aber kurz
 *
 * Wer hier je ein `SDSC_…` oder ein `IPS_RequestAction(<scanner>, …)`
 * einbaut, macht den Umbau rueckgaengig, ohne dass es auffaellt — der
 * Rauchtest des Scanners sucht genau danach.
 */
trait ScanBridge
{
    /** Das Modul, das die zweite Spur stellt. */
    private const SCANNER_MODULE_GUID = '{8A405A5A-B5F5-4BEC-AB9D-82249D0ADFD3}';

    /**
     * Welche Instanz welche Quellen bedient.
     *
     * Drei Spuren, damit nichts hinter etwas anderem wartet: ein Foto-Scan
     * nicht hinter einem Moodle-Lauf, das Briefing um 05:30 nicht hinter
     * beidem. Die Listen wachsen, waehrend die Quellen einzeln umziehen; eine
     * leere Liste heisst „hier ist noch nichts umgezogen" und legt deshalb
     * auch keine Instanz an.
     *
     * @var array<string,list<string>>
     */
    private const SCAN_ROLLEN = [
        'jobs'     => ['probe', 'auftrag'],
        'schule'   => ['doku'],    // + edu, moodle, mail
        'briefing' => [],          // + briefing
    ];

    /** Wie viele Umschlaege ein Durchgang einpflegt, bevor er Luft holt. */
    private const SCAN_INBOX_STUECK = 5;

    /** Das Sicherheitsnetz, falls ein Weckruf ausfaellt. */
    private const SCAN_NETZ_MS = 60000;

    /** Der Zaehler laeuft um, bevor er gross wird — er zaehlt nichts, er weckt nur. */
    private const SCAN_SIGNAL_MAX = 1000000000;

    /**
     * Der vierte Topf des Kanals: eine Markierung je Scanner, „fuer dich ist
     * etwas hinterlegt". Sie ist noetig, weil `IPS_GetProperty` HINTERLEGTE
     * Werte nicht zeigt — am 13.09.2026 in der 9.1 nachgemessen: nach
     * `IPS_SetProperty` liefert es unveraendert den alten Stand. Ohne diese
     * Markierung erfuehre der Scanner also nie, dass er etwas zu uebernehmen
     * hat, und das Gateway muesste `IPS_ApplyChanges` auf ihn rufen — genau
     * der Griff, der es auf seine Spur warten liesse.
     */
    private const SCAN_TOPF_NACHTRAG = 'nachtrag';

    // ------------------------------------------------------------------
    // Lebenslauf
    // ------------------------------------------------------------------

    private function ScanCreate(): void
    {
        /* Zwei getrennte Zeitgeber, weil sie Verschiedenes tun: `ScanNetz`
           schaut regelmaessig nach (falls ein Weckruf ausfiel), `ScanWeiter`
           holt den Rest eines grossen Stapels nach, ohne die Spur am Stueck
           zu belegen. */
        $this->RegisterTimer('ScanNetz', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'ScanNetz\', 0);');
        $this->RegisterTimer('ScanWeiter', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'ScanWeiter\', 0);');
        $this->RegisterAttributeInteger('ScanNetzMs', -1);
    }

    /**
     * Nur die Instanz, die auch die App bedient, hat Scanner — die Sync-Seite
     * darf mehrfach existieren, der Kanal haengt aber an EINER Kennung.
     */
    private function ScanApplyChanges(): void
    {
        /* Die Signalvariable IMMER behalten (letztes Argument `true`). Haengte
           sie an einer Bedingung, loeschte `MaintainVariable` sie beim ersten
           Uebernehmen, an dem die Bedingung gerade nicht gilt — und jede
           Anmeldung der Scanner zeigte danach auf ein Objekt, das es nicht
           mehr gibt. Ein Zaehler, den niemand liest, kostet nichts. */
        $this->MaintainVariable(self::SCAN_SIGNAL_IDENT, $this->Translate('Scan signal'),
            VARIABLETYPE_INTEGER, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Clock',
            ], 95, true);
        $signal = (int)@$this->GetIDForIdent(self::SCAN_SIGNAL_IDENT);
        if ($signal > 0 && !(bool)@IPS_GetObject($signal)['ObjectIsHidden']) {
            // Sie gehoert in keine Visualisierung: sie ist Klingeldraht, kein Wert.
            @IPS_SetHidden($signal, true);
        }

        // Nach einem Kernelstart steht der Zeitgeber auf 0, der Merker aber noch
        // auf dem alten Wert — sonst setzte ihn niemand wieder.
        @$this->WriteAttributeInteger('ScanNetzMs', -1);
        $this->ScanNetzSetzen(self::SCAN_NETZ_MS);

        /* Anlegen NICHT hier: `IPS_CreateInstance` laesst die neue Instanz
           sofort ihr ApplyChanges laufen, und das gehoert nicht mitten in
           unseres. Der Einmal-Zeitgeber schiebt es hinter diesen Aufruf. */
        if ($this->ScannerFehlt()) {
            @$this->RegisterOnceTimer('ScannerAnlegen',
                'IPS_RequestAction($_IPS[\'TARGET\'], \'ScannerAnlegen\', 0);');
        }

        // Was waehrend des Neustarts liegen blieb.
        if ($this->ScanErgebnisse() !== []) {
            @$this->RegisterOnceTimer('ScanWeiter',
                'IPS_RequestAction($_IPS[\'TARGET\'], \'ScanWeiter\', 0);');
        }
    }

    /** @return bool true = der Ident gehoerte uns */
    private function ScanRequestAction(string $Ident, mixed $Value): bool
    {
        switch ($Ident) {
            case 'ScanInbox':      // der Weckruf eines Scanners
            case 'ScanWeiter':     // der Rest eines Stapels
                $this->ScanEinpflegen();
                return true;

            case 'ScanNetz':
                // Das Netz: normalerweise ist nichts da, dann wird aufgeraeumt.
                if ($this->ScanEinpflegen() === 0) {
                    $this->ScanAufraeumen();
                }
                return true;

            case 'ScannerAnlegen':
                $this->ScannerAnlegen();
                return true;

            case 'ScanProbe':
                /* Der Selbsttest des ganzen Weges, von Hand ausloesbar:
                   `IPS_RequestAction(<gateway>, 'ScanProbe', 10)` laesst einen
                   Scanner zehn Sekunden verweilen. Waehrenddessen muss die
                   App unveraendert schnell antworten — genau das ist der
                   Beweis, um den es bei diesem Umbau geht. */
                $this->ScanAuftragGeben('probe', [
                    'anlass'    => 'hand',
                    'verweilen' => max(0, (int)$Value),
                ]);
                return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Hinaus: Auftrag und Weckruf
    // ------------------------------------------------------------------

    /**
     * Einen Auftrag ablegen und den zustaendigen Scanner wecken.
     *
     * Beides zusammen, nie einzeln: eine Auftragsdatei ohne Weckruf laege bis
     * zum naechsten Takt des Scanners (fuenf Minuten), ein Weckruf ohne Datei
     * liefe ins Leere.
     *
     * @param array<string,mixed> $auftrag
     * @return bool false = die Quelle ist unbekannt oder das Verzeichnis nicht nutzbar
     */
    private function ScanAuftragGeben(string $quelle, array $auftrag = []): bool
    {
        if (!$this->ScanAuftragAblegen($quelle, $auftrag)) {
            return false;
        }
        $this->ScanSignalGeben();
        return true;
    }

    /**
     * Klingeln. Der Zaehler bedeutet nichts — nur seine AENDERUNG zaehlt, denn
     * daran haengt das `VM_UPDATE`, das die Scanner abonniert haben. Deshalb
     * laeuft er um, statt immer weiter zu wachsen.
     */
    private function ScanSignalGeben(): void
    {
        $id = (int)@$this->GetIDForIdent(self::SCAN_SIGNAL_IDENT);
        if ($id <= 0) {
            return;   // noch nicht angelegt — der Takt der Scanner faengt es auf
        }
        $wert = (int)@GetValue($id);
        @$this->SetValue(self::SCAN_SIGNAL_IDENT, ($wert + 1) % self::SCAN_SIGNAL_MAX);
    }

    // ------------------------------------------------------------------
    // Herein: Ergebnisse einpflegen
    // ------------------------------------------------------------------

    /**
     * Die Warteschlange abarbeiten — in kleinen Stapeln.
     *
     * Der Deckel ist kein Geiz, sondern der Sinn der Sache: dieser Griff
     * laeuft in der Gateway-Spur, und was hier lange dauert, laesst die App
     * warten. Bleibt etwas liegen, holt ein Einmal-Zeitgeber den Rest, und
     * dazwischen kommen die Hooks dran.
     *
     * @return int wie viele Umschlaege eingepflegt wurden
     */
    private function ScanEinpflegen(): int
    {
        $alle = $this->ScanErgebnisse();
        if ($alle === []) {
            return 0;
        }
        $n = 0;
        foreach (array_slice($alle, 0, self::SCAN_INBOX_STUECK) as $pfad) {
            $umschlag = $this->ScanErgebnisLesen($pfad);
            if ($umschlag === null) {
                // Unlesbar oder fuer ein fremdes Gateway: nicht wegwerfen,
                // sondern beiseitelegen — dort ist es nachschaubar.
                $this->ScanErgebnisInFehler($pfad, 'Umschlag nicht lesbar');
                continue;
            }
            /* Ein Wurf beim Einpflegen darf den Kanal NICHT verkeilen. Ohne
               diese Klammer bliebe die Datei als aeltestes Ergebnis liegen,
               jeder Netz-Takt und jeder Weckruf wuerfe an derselben Stelle
               erneut, und weil das Aufraeumen nur laeuft, wenn ein Durchgang
               NICHTS eingepflegt hat, verfiele sie auch nach sieben Tagen nie.
               Nach fuenfzig Dateien verwirft der Kanal dann jedes neue Ergebnis
               JEDER Quelle — ein einziger kaputter Umschlag haette den ganzen
               Umbau stillgelegt. */
            try {
                $genommen = $this->ScanUmschlagVerarbeiten($umschlag);
            } catch (\Throwable $e) {
                $this->ScanErgebnisInFehler($pfad, 'Quelle ' . (string)$umschlag['quelle']
                    . ' warf: ' . mb_substr($e->getMessage(), 0, 200));
                $this->ScanMelden('Umschlag der Quelle ' . (string)$umschlag['quelle']
                    . ' liess sich nicht einpflegen: ' . $e->getMessage(), KL_WARNING);
                continue;
            }
            if (!$genommen) {
                $this->ScanErgebnisInFehler($pfad, 'Quelle ' . (string)$umschlag['quelle'] . ' nicht eingepflegt');
                continue;
            }
            $this->ScanErgebnisWeg($pfad);
            $n++;
        }
        if (count($alle) > self::SCAN_INBOX_STUECK) {
            @$this->RegisterOnceTimer('ScanWeiter',
                'IPS_RequestAction($_IPS[\'TARGET\'], \'ScanWeiter\', 0);');
        }
        return $n;
    }

    /**
     * Einen Umschlag in den Bestand uebernehmen.
     *
     * Heute kennt das Gateway nur „probe": den Selbsttest, der nichts
     * mitbringt ausser der Auskunft, dass der Weg getragen hat. Die echten
     * Quellen kommen einzeln dazu, jede mit ihrem eigenen Einpflegen; bis
     * dahin landet ein Umschlag, den niemand kennt, sichtbar im Fehlerordner
     * statt still im Nichts.
     *
     * @param array<string,mixed> $umschlag der bereits geprueifte Umschlag
     * @return bool false = niemand zustaendig
     */
    private function ScanUmschlagVerarbeiten(array $umschlag): bool
    {
        switch ((string)$umschlag['quelle']) {
            case 'probe':
                $this->SendDebug('Scan-Kanal', sprintf('Probe von %d: %s (%d ms)',
                    (int)$umschlag['scanner'], (string)$umschlag['status']['text'],
                    (int)$umschlag['status']['dauerMs']), 0);
                return true;

            case 'auftrag':
                /* Der Laeufer meldet jeden fertigen Auftrag einzeln und sofort
                   (IPS_RequestAction 'AiJobDone') — die App wartet ja. Dieser
                   Umschlag ist nur die Schlussmeldung einer Runde. */
                $this->SendDebug('Scan-Kanal', sprintf('KI-Auftraege von %d: %s',
                    (int)$umschlag['scanner'], (string)$umschlag['status']['text']), 0);
                return true;

            case 'doku':
                /* Der Handbuch-Bau bringt nichts mit: Verzeichnis und
                   Bauzustand liegen als Dateien unter der BestandID, und der
                   Leser findet sie dort von selbst. Der Umschlag ist nur die
                   Auskunft — durch, oder warum nicht. Eine Stoerung gehoert
                   ins Protokoll: sonst faellt es niemandem auf, dass die
                   Handbuchfragen seit Tagen aus einem alten Verzeichnis
                   beantwortet werden. */
                if (((bool)($umschlag['status']['ok'] ?? false)) === true) {
                    $this->SendDebug('Scan-Kanal', sprintf('Handbuch von %d: %s',
                        (int)$umschlag['scanner'], (string)$umschlag['status']['text']), 0);
                } else {
                    $this->ScanMelden('Handbuch-Verzeichnis: '
                        . (string)$umschlag['status']['text'], KL_WARNING);
                }
                return true;

            case 'edu':
                /* Klassenseiten. Der Scanner holt und zerlegt, das Gateway
                   pflegt ein — Medien, Sperre und Sperrliste haengen alle an
                   DIESER Instanz, siehe EduEinpflegen.

                   Die Statuszeile kommt mit: das Konfigurationsformular liest
                   sie aus dem Gateway-Attribut. Schriebe der Scanner sie bei
                   sich, stuende dort dauerhaft „Noch nicht nachgesehen". */
                $gespiegelt = $this->EduUmschlagEinpflegen($umschlag);
                $text = (string)($umschlag['status']['text'] ?? '');
                $this->SendDebug('Scan-Kanal', sprintf('Klassenseiten von %d: %s (%d gespiegelt)',
                    (int)$umschlag['scanner'], $text, $gespiegelt), 0);
                if (((bool)($umschlag['status']['ok'] ?? false)) !== true) {
                    $this->ScanMelden('Klassenseiten: ' . $text, KL_WARNING);
                }
                return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Die Scanner-Instanzen
    // ------------------------------------------------------------------

    /**
     * Alle Scanner, die zu DIESEM Gateway gehoeren.
     *
     * `IPS_GetConfiguration` ist ein Kernel-Lesezugriff: er belegt die Spur
     * der fremden Instanz nicht. Genau deshalb steht hier kein `SDSC_Stand`,
     * obwohl das bequemer waere.
     *
     * @return array<int,array{rolle:string,quellen:list<string>}>
     */
    private function ScannerInstanzen(): array
    {
        $raus = [];
        foreach ((array)@IPS_GetInstanceListByModuleID(self::SCANNER_MODULE_GUID) as $roh) {
            $id = (int)$roh;
            /* Eine Instanz ohne Elternknoten faellt auf das niedrigste Gateway
               zurueck — also auf uns, denn diese Liste holt nur der Besitzer
               der App-Seite. */
            $verbindung = (int)(@IPS_GetInstance($id)['ConnectionID'] ?? 0);
            if ($verbindung !== 0 && $verbindung !== $this->InstanceID) {
                continue;
            }
            $cfg = json_decode((string)@IPS_GetConfiguration($id), true);
            if (!is_array($cfg)) {
                continue;
            }
            $jobs = json_decode((string)($cfg['Jobs'] ?? '[]'), true);
            $quellen = [];
            foreach (is_array($jobs) ? $jobs : [] as $z) {
                if (!is_array($z) || ($z['active'] ?? true) !== true) {
                    continue;
                }
                $q = (string)($z['role'] ?? '');
                if ($q !== '' && !in_array($q, $quellen, true)) {
                    $quellen[] = $q;
                }
            }
            $raus[$id] = ['rolle' => (string)($cfg['Rolle'] ?? ''), 'quellen' => $quellen];
        }
        return $raus;
    }

    /** @var array<string,bool>|null Welche Quelle ist umgezogen — einmal je PHP-Aufruf. */
    private ?array $scanUebernommen = null;

    /**
     * Bedient ein Scanner diese Quelle bereits?
     *
     * Daran haengt jede Weiche des Umzugs: solange die Antwort nein ist,
     * arbeitet das Gateway weiter wie immer. Es gibt bewusst KEINEN Schalter
     * dafuer — die Wahrheit steht in den Instanzen selbst, und ein Schalter,
     * der danebenliegt, waere schlimmer als die Frage.
     */
    private function ScanQuelleUebernommen(string $quelle): bool
    {
        if ($this->scanUebernommen === null) {
            $alle = [];
            foreach ($this->ScannerInstanzen() as $s) {
                foreach ($s['quellen'] as $q) {
                    $alle[$q] = true;
                }
            }
            $this->scanUebernommen = $alle;
        }
        return ($this->scanUebernommen[$quelle] ?? false) === true;
    }

    /** Fehlt einer Rolle noch ihre Instanz oder eine ihrer Quellen? */
    private function ScannerFehlt(): bool
    {
        $da = $this->ScannerInstanzen();
        foreach (self::SCAN_ROLLEN as $rolle => $quellen) {
            if ($quellen === []) {
                continue;
            }
            $haben = [];
            foreach ($da as $s) {
                if ($s['rolle'] === $rolle) {
                    $haben = $s['quellen'];
                    break;
                }
            }
            if (array_diff($quellen, $haben) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fehlende Scanner anlegen, vorhandene nachziehen.
     *
     * Laeuft in einem Einmal-Zeitgeber, nicht in `ApplyChanges`: beide
     * `IPS_…`-Griffe loesen drueben ein ApplyChanges aus, und das gehoert
     * nicht in unseres hinein. Die neue Instanz haengt sich selbst ans
     * Gateway — auch das erst in IHREM Zeitgeber, damit kein Rueckruf in
     * unsere gerade laufende Spur faellt.
     */
    private function ScannerAnlegen(): void
    {
        $this->scanUebernommen = null;   // gleich aendert sich, was hier steht

        if (!@IPS_ModuleExists(self::SCANNER_MODULE_GUID)) {
            /* Kein Scanner-Modul (aeltere Installation, Ordner fehlt): alles
               laeuft weiter wie bisher im Gateway. Nur sagen muss man es. */
            $this->ScanMelden('Scanner-Modul nicht gefunden — die Scans bleiben im Gateway.', KL_WARNING);
            return;
        }
        $da = $this->ScannerInstanzen();
        foreach (self::SCAN_ROLLEN as $rolle => $quellen) {
            if ($quellen === []) {
                continue;   // noch nichts umgezogen: keine stumme Instanz anlegen
            }
            $id = 0;
            $haben = [];
            $rolleSitzt = false;
            foreach ($da as $sid => $s) {
                if ($s['rolle'] === (string)$rolle) {
                    $id = (int)$sid;
                    $haben = $s['quellen'];
                    $rolleSitzt = true;
                    break;
                }
            }
            if ($id === 0) {
                /* Uebernehmen statt danebenstellen. Eine Instanz aus der Zeit
                   vor der Eigenschaft „Rolle" traegt sie leer — und wer von
                   Hand einen Scanner angelegt hat, ebenso. Ohne diesen Griff
                   staende nach dem Neustart ein zweiter am selben Kanal, der
                   um dieselben Auftragsdateien takelt und vom richtigen nicht
                   zu unterscheiden ist. */
                foreach ($da as $sid => $s) {
                    if ($s['rolle'] === '') {
                        $id = (int)$sid;
                        $haben = $s['quellen'];
                        unset($da[$sid]);   // nicht zweimal uebernehmen
                        @IPS_SetName($id, $this->ScannerName((string)$rolle));
                        $this->ScanMelden(sprintf('Scanner #%d als „%s" uebernommen.',
                            $id, $this->ScannerName((string)$rolle)), KL_NOTIFY);
                        break;
                    }
                }
            }
            $fehlt = array_values(array_diff($quellen, $haben));
            /* `$rolleSitzt` muss mit hinein: eine uebernommene Instanz hat
               zwar schon alle Quellen, aber noch nicht ihre Rolle — ohne den
               Merker ginge der Durchgang hier vorbei und die Rolle bliebe
               fuer immer leer. */
            if ($id > 0 && $fehlt === [] && $rolleSitzt) {
                continue;
            }

            try {
                $neu = ($id === 0);
                if ($neu) {
                    $id = (int)IPS_CreateInstance(self::SCANNER_MODULE_GUID);
                    IPS_SetName($id, $this->ScannerName((string)$rolle));
                    /* NEBEN das Gateway, nicht darunter: Symcon raeumt Kinder
                       mit ihrer Instanz weg, und ein geloeschtes Gateway naehme
                       sonst die Scanner mit. Dieselbe Ueberlegung wie im
                       Einrichtungs-Assistenten. */
                    $eltern = (int)@IPS_GetParent($this->InstanceID);
                    if ($eltern > 0) {
                        @IPS_SetParent($id, $eltern);
                    }
                    $this->ScanMelden(sprintf('Scanner „%s" angelegt (#%d).',
                        $this->ScannerName((string)$rolle), $id), KL_NOTIFY);
                }
                @IPS_SetProperty($id, 'Rolle', (string)$rolle);
                @IPS_SetProperty($id, 'Jobs', (string)json_encode(
                    array_map(static fn(string $q): array => ['role' => $q, 'active' => true],
                        array_values(array_unique(array_merge($haben, $quellen))))));

                if ($neu) {
                    /* Nur die eben angelegte Instanz duerfen wir uebernehmen
                       lassen: sie kann noch nichts tun, also wartet hier
                       niemand. */
                    @IPS_ApplyChanges($id);
                } else {
                    /* Eine LAUFENDE Instanz nie von hier aus uebernehmen
                       lassen. `IPS_ApplyChanges` wartet auf ihre Spur — und
                       wenn die gerade einen mehrminuetigen Scan faehrt,
                       stuende solange die ganze App. Steckt dieser Scan
                       ausserdem in seinem Weckruf zu uns, warten beide Seiten
                       aufeinander. Also nur Bescheid geben: der Scanner
                       uebernimmt das Hinterlegte selbst, in seiner Spur. */
                    $this->ScannerNachtragMelden($id);
                }
            } catch (\Throwable $e) {
                $this->ScanMelden(sprintf('Scanner „%s" konnte nicht angelegt werden: %s',
                    (string)$rolle, $e->getMessage()), KL_ERROR);
            }
        }
    }

    /**
     * Einem Scanner sagen, dass fuer ihn etwas hinterlegt ist.
     *
     * Die Markierung traegt keinen Inhalt — was zu uebernehmen ist, steht
     * bereits als hinterlegte Eigenschaft an SEINER Instanz. Sie sagt nur:
     * „ruf einmal dein ApplyChanges". Weggeraeumt wird sie drueben, und zwar
     * NACH dem Uebernehmen; bleibt sie doch einmal liegen, kostet das eine
     * ueberfluessige Uebernahme und sonst nichts.
     */
    private function ScannerNachtragMelden(int $id): void
    {
        $dir = $this->ScanDir(self::SCAN_TOPF_NACHTRAG, true);
        if ($dir === '') {
            return;
        }
        $this->ScanSchreibenAtomar($dir . $id . '.json', (string)json_encode(['at' => time()]));
        $this->ScanSignalGeben();
    }

    /** Der Name, unter dem die Instanz in der Konsole steht. */
    private function ScannerName(string $rolle): string
    {
        $namen = [
            'jobs'     => $this->Translate('SymDo - Scanner (jobs)'),
            'schule'   => $this->Translate('SymDo - Scanner (school)'),
            'briefing' => $this->Translate('SymDo - Scanner (briefing)'),
        ];
        return $namen[$rolle] ?? ('SymDo - Scanner (' . $rolle . ')');
    }

    /** Den Zeitgeber nur bei Aenderung setzen — sonst zaehlt er von vorn. */
    private function ScanNetzSetzen(int $ms): void
    {
        if ((int)@$this->ReadAttributeInteger('ScanNetzMs') === $ms) {
            return;
        }
        @$this->SetTimerInterval('ScanNetz', $ms);
        @$this->WriteAttributeInteger('ScanNetzMs', $ms);
    }
}
