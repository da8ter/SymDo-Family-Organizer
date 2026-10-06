<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/EspStatusCalc.php';

/**
 * SymDoESPVoice — ein SymDo-Sprachgerät (ESP32) in Symcon.
 *
 * Kind eines Symcon-MQTT-Servers. Das Gerät meldet seinen Zustand auf
 * symdo/esp/<geraet>/status und nimmt Befehle auf symdo/esp/<geraet>/cmd.
 * Mitglied, Raum, Weckwort und Schalterlaubnis legt die Instanz als
 * Sprachprofil im SymDo-Gateway ab (TGW_SetDeviceVoiceProfile) — zusammen mit
 * dem MQTT-Zugang des übergeordneten Servers, den das Gerät dort abholt.
 *
 *   SDEV_StartConversation(<id>)   Gespräch auslösen (z. B. von der Klingel)
 *   SDEV_Reboot(<id>)              Gerät neu starten
 */
class SymDoESPVoice extends IPSModuleStrict
{
    private const GATEWAY_MODULE_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';
    private const MQTT_SERVER_GUID    = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const MQTT_TX             = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::MQTT_SERVER_GUID]]);
    }

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('UserId', '');
        $this->RegisterPropertyString('Room', '');
        $this->RegisterPropertyString('WakeWord', 'hiesp');
        $this->RegisterPropertyString('WakeCustom', '');
        $this->RegisterPropertyBoolean('AllowDevices', true);
        $this->RegisterPropertyBoolean('ShareTranscript', false);
        // Gerätegenauer Schlüssel für signierte MQTT-Befehle (siehe EspStatusCalc::Befehl)
        $this->RegisterAttributeString('CmdKey', '');

        $this->RegisterVariableBoolean('ONLINE', $this->Translate('Online'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                ['Value' => false, 'Caption' => $this->Translate('Offline'), 'IconActive' => false, 'IconValue' => '',
                 'ColorActive' => true, 'ColorValue' => 0xE57373, 'ContentColorActive' => false, 'ContentColorValue' => -1],
                ['Value' => true, 'Caption' => $this->Translate('Online'), 'IconActive' => false, 'IconValue' => '',
                 'ColorActive' => true, 'ColorValue' => 0x81C784, 'ContentColorActive' => false, 'ContentColorValue' => -1],
            ], JSON_UNESCAPED_UNICODE),
        ], 10);
        $optionen = [];
        foreach (EspStatusCalc::ZUSTAENDE as $wert => $text) {
            $optionen[] = ['Value' => $wert, 'Caption' => $this->Translate($text), 'IconActive' => false, 'IconValue' => '',
                           'Color' => -1];
        }
        $this->RegisterVariableInteger('STATE', $this->Translate('State'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'Microphone',
            'OPTIONS'      => json_encode($optionen, JSON_UNESCAPED_UNICODE),
        ], 20);
        $this->RegisterVariableInteger('BATTERY', $this->Translate('Battery'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Battery',
            'SUFFIX'       => ' %',
        ], 30);
        $this->RegisterVariableBoolean('CHARGING', $this->Translate('Charging'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Electricity',
        ], 40);
        $this->RegisterVariableInteger('VOLUME', $this->Translate('Volume'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'Speaker',
            'SUFFIX'       => ' %',
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP_SIZE'    => 5,
        ], 50);
        $this->EnableAction('VOLUME');
        $this->RegisterVariableInteger('BRIGHTNESS', $this->Translate('Brightness'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'Sun',
            'SUFFIX'       => ' %',
            'MIN'          => 5,
            'MAX'          => 100,
            'STEP_SIZE'    => 5,
        ], 60);
        $this->EnableAction('BRIGHTNESS');
        $this->RegisterVariableString('QUESTION', $this->Translate('Last question'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Speech',
        ], 70);
        $this->RegisterVariableString('ANSWER', $this->Translate('Last answer'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Speech',
        ], 80);
        $this->RegisterVariableString('FIRMWARE', $this->Translate('Firmware'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information',
        ], 90);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        if (strlen($this->ReadAttributeString('CmdKey')) !== 64) {
            $this->WriteAttributeString('CmdKey', EspStatusCalc::NeuerSchluessel());
        }
        $geraet = trim($this->ReadPropertyString('DeviceId'));
        if (!EspStatusCalc::GeraetGueltig($geraet)) {
            // Nichts empfangen, solange kein Gerät gewählt ist.
            $this->SetReceiveDataFilter('^$');
            $this->SetStatus(104);
            return;
        }
        $this->SetReceiveDataFilter('.*' . preg_quote(EspStatusCalc::ThemaStatus($geraet), '/') . '.*');
        $this->SetSummary($geraet);
        $server = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($server !== 0 && !$this->ServerNurFuerSprachgeraete($server)) {
            // Profil trotzdem schreiben (ohne MQTT-Zugang), aber laut sagen, warum.
            $this->ProfilSchreiben($geraet);
            $this->SetStatus(202);
            return;
        }
        $this->SetStatus($this->ProfilSchreiben($geraet) ? 102 : 201);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d)) {
            return '';
        }
        $geraet = trim($this->ReadPropertyString('DeviceId'));
        if ((string)($d['Topic'] ?? '') !== EspStatusCalc::ThemaStatus($geraet)) {
            return '';
        }
        // Module Strict: der MQTT-Server reicht die Nutzlast hex-kodiert durch.
        $roh = (string)($d['Payload'] ?? '');
        $nutzlast = ctype_xdigit($roh) && strlen($roh) % 2 === 0 ? (string)hex2bin($roh) : $roh;
        $this->SendDebug('Status', $nutzlast, 0);
        $s = EspStatusCalc::Status($nutzlast);
        $ziel = ['online' => 'ONLINE', 'zustand' => 'STATE', 'akku' => 'BATTERY', 'laedt' => 'CHARGING',
                 'lautstaerke' => 'VOLUME', 'helligkeit' => 'BRIGHTNESS', 'frage' => 'QUESTION',
                 'antwort' => 'ANSWER', 'version' => 'FIRMWARE'];
        foreach ($ziel as $feld => $ident) {
            if (array_key_exists($feld, $s) && $this->GetValue($ident) !== $s[$feld]) {
                $this->SetValue($ident, $s[$feld]);
            }
        }
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'VOLUME':
                $this->Senden('volume', max(0, min(100, (int)$Value)));
                $this->SetValue('VOLUME', max(0, min(100, (int)$Value)));
                return;
            case 'BRIGHTNESS':
                $this->Senden('brightness', max(5, min(100, (int)$Value)));
                $this->SetValue('BRIGHTNESS', max(5, min(100, (int)$Value)));
                return;
        }
        throw new Exception('Invalid Ident');
    }

    public function StartConversation(): bool
    {
        return $this->Senden('start');
    }

    public function Reboot(): bool
    {
        return $this->Senden('reboot');
    }

    /** Für den Knopf im Formular: neuer Kopplungscode des Gateways. */
    public function PairingCode(): string
    {
        $gw = $this->GatewayID();
        if ($gw === 0) {
            return $this->Translate('No SymDo Gateway found.');
        }
        $r = json_decode((string)@TGW_CreatePairing($gw), true);
        $code = is_array($r) ? (string)($r['code'] ?? '') : '';
        if ($code === '') {
            return $this->Translate('The gateway did not create a pairing code.');
        }
        return sprintf($this->Translate("Pairing code: %s\n\nHold the button on the device for 3 seconds, join its Wi-Fi and enter the Symcon address and this code at http://192.168.4.1."), $code);
    }

    public function GetConfigurationForm(): string
    {
        $gw = $this->GatewayID();
        $geraete = [['caption' => $this->Translate('— please select —'), 'value' => '']];
        $mitglieder = [['caption' => $this->Translate('— household —'), 'value' => '']];
        if ($gw !== 0) {
            foreach (json_decode((string)@TGW_GetPairedDevices($gw), true) ?: [] as $d) {
                if (($d['platform'] ?? '') === 'esp32') {
                    $geraete[] = ['caption' => $d['deviceName'] . ' (' . $d['deviceId'] . ', ' . $d['createdAt'] . ', ' . $d['status'] . ')',
                                  'value' => $d['deviceId']];
                }
            }
            foreach (json_decode((string)@TGW_GetUsersForTile($gw), true) ?: [] as $u) {
                $mitglieder[] = ['caption' => (string)($u['name'] ?? ''), 'value' => (string)($u['id'] ?? '')];
            }
        }
        $woerter = [
            ['caption' => 'Hi ESP', 'value' => 'hiesp'], ['caption' => 'Alexa', 'value' => 'alexa'],
            ['caption' => 'Jarvis', 'value' => 'jarvis'], ['caption' => 'Computer', 'value' => 'computer'],
            ['caption' => $this->Translate('Custom English phrase'), 'value' => 'eigen'],
            ['caption' => $this->Translate('Off (button only)'), 'value' => 'aus'],
        ];
        return (string)json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => $gw === 0 ? $this->Translate('No SymDo Gateway found.') : ''],
                ['type' => 'Select', 'name' => 'DeviceId', 'caption' => $this->Translate('Voice device'), 'options' => $geraete],
                ['type' => 'Button', 'caption' => $this->Translate('Pair a new device'), 'onClick' => 'echo SDEV_PairingCode($id);'],
                ['type' => 'Select', 'name' => 'UserId', 'caption' => $this->Translate('Speaks as member'), 'options' => $mitglieder],
                ['type' => 'ValidationTextBox', 'name' => 'Room', 'caption' => $this->Translate('Room (default for "turn on the light")')],
                ['type' => 'CheckBox', 'name' => 'AllowDevices', 'caption' => $this->Translate('May control devices')],
                ['type' => 'CheckBox', 'name' => 'ShareTranscript', 'caption' => $this->Translate('Show last question and answer in Symcon (all voice devices on this MQTT server can read along)')],
                ['type' => 'Select', 'name' => 'WakeWord', 'caption' => $this->Translate('Wake word'), 'options' => $woerter],
                ['type' => 'ValidationTextBox', 'name' => 'WakeCustom', 'caption' => $this->Translate('Custom phrase (English, 2–5 words)')],
                ['type' => 'Label', 'caption' => $this->Translate('The wake word only listens if hands-free is enabled in the SymDo Gateway.')],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => $this->Translate('Start conversation'), 'onClick' => 'SDEV_StartConversation($id);'],
                ['type' => 'Button', 'caption' => $this->Translate('Restart device'), 'onClick' => 'SDEV_Reboot($id);'],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => $this->Translate('Please select a voice device.')],
                ['code' => 201, 'icon' => 'error', 'caption' => $this->Translate('The profile could not be stored in the SymDo Gateway.')],
                ['code' => 202, 'icon' => 'error', 'caption' => $this->Translate('Please use a separate MQTT server for voice devices only — the device would otherwise receive the access of a shared server.')],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------------

    private function ProfilSchreiben(string $geraet): bool
    {
        $gw = $this->GatewayID();
        if ($gw === 0) {
            return false;
        }
        $profil = EspStatusCalc::Profil(
            trim($this->ReadPropertyString('UserId')),
            trim($this->ReadPropertyString('Room')),
            $this->ReadPropertyBoolean('AllowDevices'),
            EspStatusCalc::Weckwort($this->ReadPropertyString('WakeWord'), $this->ReadPropertyString('WakeCustom')),
            $this->MqttZugang(),
            $this->ReadAttributeString('CmdKey'),
            $this->ReadPropertyBoolean('ShareTranscript')
        );
        return (bool)@TGW_SetDeviceVoiceProfile($gw, $geraet, (string)json_encode($profil, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Port, Benutzer und Passwort des übergeordneten MQTT-Servers — das Gerät
     * holt sie mit seinem Profil im Gateway ab.
     * @return array{port:int, user:string, pass:string}|null
     */
    private function MqttZugang(): ?array
    {
        $server = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($server === 0 || !$this->ServerNurFuerSprachgeraete($server)) {
            return null;
        }
        $io = (int)(IPS_GetInstance($server)['ConnectionID'] ?? 0);
        $sc = json_decode((string)@IPS_GetConfiguration($server), true) ?: [];
        $ic = $io !== 0 ? (json_decode((string)@IPS_GetConfiguration($io), true) ?: []) : [];
        $port = (int)($ic['Port'] ?? 0);
        if ($port === 0) {
            return null;
        }
        return ['port' => $port, 'user' => (string)($sc['UserName'] ?? ''), 'pass' => (string)($sc['Password'] ?? '')];
    }

    /**
     * Den Zugang eines MQTT-Servers bekommt das Geraet nur, wenn an diesem Server
     * ausschliesslich Sprachgeraete haengen. Symcons MQTT-Server kennt keine
     * Themenrechte: mit dem Zugang eines gemeinsam genutzten Servers (etwa fuer
     * Home Assistant) koennte das Geraet ueberall mitlesen und schreiben.
     */
    private function ServerNurFuerSprachgeraete(int $server): bool
    {
        foreach (IPS_GetInstanceList() as $id) {
            if ((int)(IPS_GetInstance($id)['ConnectionID'] ?? 0) !== $server) {
                continue;
            }
            if ((string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '') !== '{DDF91F65-36AE-4539-BBFC-6F1F1D943A9E}') {
                return false;
            }
        }
        return true;
    }

    private function Senden(string $cmd, ?int $wert = null): bool
    {
        $geraet = trim($this->ReadPropertyString('DeviceId'));
        $schluessel = $this->ReadAttributeString('CmdKey');
        if (!EspStatusCalc::GeraetGueltig($geraet) || strlen($schluessel) !== 64 || !$this->HasActiveParent()) {
            return false;
        }
        $nutzlast = EspStatusCalc::Befehl($cmd, $wert, $schluessel, time());
        $this->SendDebug('Befehl', $nutzlast, 0);
        $this->SendDataToParent((string)json_encode([
            'DataID'           => self::MQTT_TX,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => EspStatusCalc::ThemaBefehl($geraet),
            // Module Strict: Nutzlast hex-kodiert, wie sie auch ankommt.
            'Payload'          => bin2hex($nutzlast),
        ]));
        return true;
    }

    private function GatewayID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::GATEWAY_MODULE_GUID);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }
}
