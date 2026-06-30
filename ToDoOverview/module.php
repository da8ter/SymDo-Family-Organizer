<?php

declare(strict_types=1);

class ToDoOverview extends IPSModule
{
    // GUID des Quell-Moduls ToDoList (Filter im SelectInstance des Formulars)
    private const TODOLIST_MODULE_GUID = '{E0E38D9B-31BC-4F5E-A6CA-91A2A60C7C46}';

    // Idents der drei Statistik-Variablen in der ToDoList-Instanz
    private const SRC_IDENTS = ['OpenTasks', 'OverdueTasks', 'DueTodayTasks'];

    public function Create(): void
    {
        parent::Create();

        // Pflicht, damit Symcon die HTML-Kachel aus GetVisualizationTile() rendert
        $this->SetVisualizationType(1);

        $this->RegisterPropertyInteger('ToDoListInstanceID', 0);
        $this->RegisterPropertyInteger('OpenObjectID', 0);
        $this->RegisterPropertyBoolean('OverdueRedBackground', false);
        $this->RegisterPropertyInteger('OverdueBackgroundColor', 0xFF5A5A);

        // Welche Werte in der Uebersicht angezeigt werden (Standard: alle an)
        $this->RegisterPropertyBoolean('ShowOpen', true);
        $this->RegisterPropertyBoolean('ShowOverdue', true);
        $this->RegisterPropertyBoolean('ShowToday', true);

        // Schriftgroessen-Skalierung in Prozent (Standard 100)
        $this->RegisterPropertyInteger('LabelFontScale', 100);
        $this->RegisterPropertyInteger('ValueFontScale', 100);

        // Merkt sich die aktuell abonnierten Variablen-IDs, um Abos sauber zu lösen
        $this->RegisterAttributeString('SubscribedVarIDs', '[]');

        // Keine eigenen Variablen: dieses Modul liest ausschließlich Fremdvariablen.
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Kernel-Check: Kein Heavy Work vor KR_READY
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // 1. Alte Abos/Referenzen sauber lösen (kein Leak bei Instanzwechsel)
        $previous = json_decode($this->ReadAttributeString('SubscribedVarIDs'), true);
        if (is_array($previous)) {
            foreach ($previous as $oldID) {
                $oldID = (int) $oldID;
                if ($oldID > 0) {
                    $this->UnregisterMessage($oldID, VM_UPDATE);
                }
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        // 2. Quell-Variablen auflösen und neu abonnieren
        $instanceID = $this->ReadPropertyInteger('ToDoListInstanceID');
        $subscribed = [];

        if ($instanceID > 0 && IPS_InstanceExists($instanceID)) {
            foreach (self::SRC_IDENTS as $ident) {
                $varID = @IPS_GetObjectIDByIdent($ident, $instanceID);
                if ($varID > 0 && IPS_VariableExists($varID)) {
                    $this->RegisterReference($varID);
                    $this->RegisterMessage($varID, VM_UPDATE);
                    $subscribed[] = $varID;
                }
            }
        }

        // Klick-Ziel referenzieren, damit es nicht unbemerkt gelöscht wird
        $openID = $this->ReadPropertyInteger('OpenObjectID');
        if ($openID > 0 && @IPS_ObjectExists($openID)) {
            $this->RegisterReference($openID);
        }

        $this->WriteAttributeString('SubscribedVarIDs', json_encode($subscribed));

        // 3. Initialwerte an die Kachel senden
        $this->PushState();
    }

    // Signatur ohne Typen: IPSModule::MessageSink ist in der Basisklasse untypisiert,
    // daher muss der Override kompatibel (ebenfalls untypisiert) sein.
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                return;
            case VM_UPDATE:
                $this->PushState();
                return;
        }
    }

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            IPS_LogMessage('ToDoOverview', 'GetVisualizationTile: module.html could not be loaded. path=' . $path);
            return '';
        }

        // Initial-Payload inline mitgeben, damit die Kachel sofort korrekte Werte zeigt
        $payload = json_encode($this->BuildPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $html .= '<script>handleMessage(' . $payload . ');</script>';

        return $html;
    }

    // Kein RequestAction-Override: Die Kachel ruft kein requestAction auf
    // (Klick nutzt direkt openObject, Initialwerte kommen inline aus GetVisualizationTile).

    private function PushState(): void
    {
        $this->UpdateVisualizationValue(
            json_encode($this->BuildPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function BuildPayload(): array
    {
        return [
            'type'                 => 'state',
            'open'                 => $this->ReadCounter('OpenTasks'),
            'overdue'              => $this->ReadCounter('OverdueTasks'),
            'today'                => $this->ReadCounter('DueTodayTasks'),
            'openObjectId'         => $this->ReadPropertyInteger('OpenObjectID'),
            'overdueRedBackground' => $this->ReadPropertyBoolean('OverdueRedBackground'),
            'overdueBackgroundColor' => $this->ReadPropertyInteger('OverdueBackgroundColor'),
            'showOpen'             => $this->ReadPropertyBoolean('ShowOpen'),
            'showOverdue'          => $this->ReadPropertyBoolean('ShowOverdue'),
            'showToday'            => $this->ReadPropertyBoolean('ShowToday'),
            'labelFontScale'       => $this->ReadPropertyInteger('LabelFontScale'),
            'valueFontScale'       => $this->ReadPropertyInteger('ValueFontScale'),
        ];
    }

    private function ReadCounter(string $ident): int
    {
        $instanceID = $this->ReadPropertyInteger('ToDoListInstanceID');
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return 0;
        }
        $varID = @IPS_GetObjectIDByIdent($ident, $instanceID);
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return 0;
        }
        return (int) GetValue($varID);
    }
}
