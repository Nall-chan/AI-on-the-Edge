<?php

declare(strict_types=1);

/**
 * @package       AIontheEdgeDevice
 * @file          module.php
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2024 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       1.20
 *
 */

namespace {

    eval('declare(strict_types=1);namespace AIontheEdgeDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');
    eval('declare(strict_types=1);namespace AIontheEdgeDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
    eval('declare(strict_types=1);namespace AIontheEdgeDevice {?>' . file_get_contents(__DIR__ . '/../libs/helper/VariableHelper.php') . '}');

    /**
     * AIontheEdgeDevice
     *
     * @property string $Host Adresse des Device
     * @property string $ApiKey
     * @method bool RegisterHook(string $WebHook)
     * @method bool SendDebug(string $Message, mixed $Data, int $Format)*
     * @method void SetValueBoolean(string $Ident, bool $value)
     * @method void SetValueFloat(string $Ident, float $value)
     * @method void SetValueInteger(string $Ident, int $value)
     * @method void SetValueString(string $Ident, string $value)
     * @method int FindIDForIdent(string $Ident)
     */
    class AIontheEdgeDevice extends IPSModuleStrict
    {
        use \AIontheEdgeDevice\DebugHelper;
        use \AIontheEdgeDevice\BufferHelper;
        use \AIontheEdgeDevice\VariableHelper;

        /**
         * Create
         *
         * @return void
         */
        public function Create(): void
        {
            //Never delete this line!
            parent::Create();
            $this->RegisterAttributeString(\AIontheEdgeDevice\Attribute::Host, '');
            $this->RegisterPropertyString(\AIontheEdgeDevice\Property::ApiKey, '');
            $this->RegisterPropertyString(\AIontheEdgeDevice\Property::ValueIcon, 'Drops');
            $this->RegisterPropertyBoolean(\AIontheEdgeDevice\Property::EnablePreValue, true);
            $this->RegisterPropertyBoolean(\AIontheEdgeDevice\Property::EnableRawValue, true);
            $this->RegisterPropertyBoolean(\AIontheEdgeDevice\Property::EnableSnapshotImage, true);
            $this->RegisterPropertyBoolean(\AIontheEdgeDevice\Property::EnableTimeoutVariable, true);
            $this->RegisterPropertyBoolean(\AIontheEdgeDevice\Property::EnableTimeoutInstanceStatus, true);
            $this->RegisterPropertyInteger(\AIontheEdgeDevice\Property::Timeout, 300);
            $this->RegisterPropertyInteger(\AIontheEdgeDevice\Property::DigitizeIntervall, 0);
            $this->RegisterTimer(\AIontheEdgeDevice\Timer::Timeout, 0, 'IPS_RequestAction(' . $this->InstanceID . ',"Timeout",true);');
            $this->RegisterTimer(\AIontheEdgeDevice\Timer::RunFlow, 0, 'IPS_RequestAction(' . $this->InstanceID . ',"RunFlow",true);');

            if (IPS_GetKernelRunlevel() != KR_READY) {
                $this->RegisterMessage(0, IPS_KERNELSTARTED);
            }
            $this->Host = '';
            $this->ApiKey = '';
        }

        public function Migrate(string $JSONData): string
        {
            // Prüfe Version diese Modul-Instanz
            $j = json_decode($JSONData);
            if (isset($j->property->{AIontheEdgeDevice\Property::ValueProfile})) {
                $j->property->{AIontheEdgeDevice\Property::ValueIcon} = $j->property->{AIontheEdgeDevice\Property::ValueProfile} == 'Water.m3' ? 'Drops' : 'Flame';
            }
            $offlineVar = $this->FindIDForIdent('Offline');
            if (IPS_VariableExists($offlineVar)) {
                IPS_SetIdent($offlineVar, \AIontheEdgeDevice\Variable::Connection);
            }

            return json_encode($j);
        }

        /**
         * ApplyChanges
         *
         * @return void
         */
        public function ApplyChanges(): void
        {
            $this->SetTimerInterval(AIontheEdgeDevice\Timer::Timeout, 0);
            $this->SetTimerInterval(AIontheEdgeDevice\Timer::RunFlow, 0);
            $this->SetStatus(AIontheEdgeDevice\ModuleState::Inactive);
            //Never delete this line!
            parent::ApplyChanges();
            $this->Host = '';

            if (IPS_GetKernelRunlevel() != KR_READY) {
                return;
            }
            $this->RegisterHook(AIontheEdgeDevice\Hook::Uri . $this->InstanceID);
            $Host = $this->ReadAttributeString(AIontheEdgeDevice\Attribute::Host);
            if ($Host) {
                $this->Host = gethostbyname($Host);
            }
            $this->ApiKey = $this->ReadPropertyString(AIontheEdgeDevice\Property::ApiKey);
            $this->RegisterVariableInteger(
                \AIontheEdgeDevice\Variable::Timestamp,
                $this->Translate('Last flow'),
                [
                    'PRESENTATION'  => VARIABLE_PRESENTATION_DATE_TIME,
                    'TEMPLATE'      => VARIABLE_TEMPLATE_DATE_TIME
                ]
            );
            $this->RegisterVariableFloat(
                \AIontheEdgeDevice\Variable::Value,
                $this->Translate('Value'),
                [
                    'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                    'SUFFIX'      => ' m³',
                    'ICON'        => $this->ReadPropertyString(AIontheEdgeDevice\Property::ValueIcon)
                ]
            );
            $this->RegisterVariableString(
                \AIontheEdgeDevice\Variable::Error,
                $this->Translate('Error')
            );
            $this->MaintainVariable(
                \AIontheEdgeDevice\Variable::PreValue,
                $this->Translate('Previous value'),
                VARIABLETYPE_FLOAT,
                [
                    'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                    'SUFFIX'      => ' m³',
                    'ICON'        => $this->ReadPropertyString(AIontheEdgeDevice\Property::ValueIcon)
                ],
                0,
                $this->ReadPropertyBoolean(AIontheEdgeDevice\Property::EnablePreValue)
            );
            $this->MaintainVariable(
                \AIontheEdgeDevice\Variable::RawValue,
                $this->Translate('Raw value'),
                VARIABLETYPE_STRING,
                [],
                0,
                $this->ReadPropertyBoolean(AIontheEdgeDevice\Property::EnableRawValue)
            );
            $this->MaintainVariable(
                \AIontheEdgeDevice\Variable::Connection,
                $this->Translate('Connection'),
                VARIABLETYPE_BOOLEAN,
                [
                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                    'OPTIONS'      => '[
                        {"ColorDisplay":-1,"Value":false,"Caption":"online","IconActive":true,"IconValue":"Ok","ColorActive":true,"ColorValue":-1,"Color":-1},
                        {"ColorDisplay":16711680,"Value":true,"Caption":"offline","IconActive":true,"IconValue":"Cross","ColorActive":true,"ColorValue":16711680,"Color":-1}
                    ]'

                ],
                0,
                $this->ReadPropertyBoolean(AIontheEdgeDevice\Property::EnableTimeoutVariable)
            );

            if ($this->Host) {
                $DigitizeIntervall = $this->ReadPropertyInteger(AIontheEdgeDevice\Property::DigitizeIntervall);
                $this->SetTimerInterval(AIontheEdgeDevice\Timer::RunFlow, $DigitizeIntervall * 60000);
                $Timeout = $this->ReadPropertyInteger(AIontheEdgeDevice\Property::Timeout);
                if ($Timeout) {
                    $this->ResetTimeoutIntervall();
                } else {
                    $this->SetStatus(AIontheEdgeDevice\ModuleState::Active);
                }
                if ($DigitizeIntervall) {
                    @$this->StartFlow();
                }
            }
        }
        /**
         * Interne Funktion des SDK.
         */
        public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
        {
            switch ($Message) {
                case IPS_KERNELSTARTED:
                    $this->UnregisterMessage(0, IPS_KERNELSTARTED);
                    $this->ApplyChanges();
                    break;
            }
        }
        /**
         * RequestAction
         * Actionhandler der Statusvariablen. Interne SDK-Funktion.
         *
         * @param string                $Ident Der Ident der Statusvariable.
         * @param bool|float|int|string $Value Der angeforderte neue Wert.
         */
        public function RequestAction(string $Ident, mixed $Value): void
        {
            switch ($Ident) {
                case \AIontheEdgeDevice\Timer::RunFlow:
                    $this->StartFlow();
                    break;
                case \AIontheEdgeDevice\Timer::Timeout:
                    $this->TriggerTimeout();
                    break;
                case \AIontheEdgeDevice\Action::SaveAddress:
                    $this->UpdateFormField(AIontheEdgeDevice\Attribute::Host, 'enabled', false);
                    $this->UpdateFormField(AIontheEdgeDevice\Action::ResetAddress, 'caption', $this->Translate('Overwrite Address'));
                    $this->UpdateFormField(AIontheEdgeDevice\Action::ResetAddress, 'onClick', 'IPS_RequestAction($id, \'ResetAddress\', true);');
                    $this->WriteAttributeString(AIontheEdgeDevice\Attribute::Host, $Value);
                    $this->Host = $Value;
                    if ($Value == '') {
                        $this->SetStatus(AIontheEdgeDevice\ModuleState::Inactive);
                    } else {
                        $this->UpdateFormField(AIontheEdgeDevice\Hook::Form, 'caption', $this->GetConsumerAddress());
                    }
                    break;
                case \AIontheEdgeDevice\Action::ResetAddress:
                    $this->UpdateFormField(AIontheEdgeDevice\Attribute::Host, 'enabled', true);
                    $this->UpdateFormField(AIontheEdgeDevice\Action::ResetAddress, 'caption', $this->Translate('Save'));
                    $this->UpdateFormField(AIontheEdgeDevice\Action::ResetAddress, 'onClick', 'IPS_RequestAction($id, \'SaveAddress\', $Host);');
                    break;
                case \AIontheEdgeDevice\Action::EnableLogging:
                    $AId = IPS_GetInstanceListByModuleID(AIontheEdgeDevice\GUID::ArchiveControl)[0];
                    AC_SetLoggingStatus($AId, $this->FindIDForIdent((string) $Value), true);
                    if ($Value != 'RawValue') {
                        AC_SetAggregationType($AId, $this->FindIDForIdent((string) $Value), 1);
                    }
                    $this->UpdateFormField(AIontheEdgeDevice\Action::EnableLogging . (string) $Value, 'enabled', false);
                    break;
            }
        }

        /**
         * GetConfigurationForm
         *
         * @return string
         */
        public function GetConfigurationForm(): string
        {
            $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
            if ($this->GetStatus() == IS_CREATING) {
                return json_encode($Form);
            }
            $Form['elements'][0]['items'][0]['value'] = $this->ReadAttributeString(AIontheEdgeDevice\Attribute::Host);
            $AId = IPS_GetInstanceListByModuleID(AIontheEdgeDevice\GUID::ArchiveControl)[0];
            if (AC_GetLoggingStatus($AId, $this->FindIDForIdent(AIontheEdgeDevice\Variable::Value))) {
                $Form['elements'][2]['items'][1]['enabled'] = false;
            }
            if (AC_GetLoggingStatus($AId, $this->FindIDForIdent(AIontheEdgeDevice\Variable::PreValue))) {
                $Form['elements'][3]['items'][1]['enabled'] = false;
            }
            if (AC_GetLoggingStatus($AId, $this->FindIDForIdent(AIontheEdgeDevice\Variable::RawValue))) {
                $Form['elements'][4]['items'][1]['enabled'] = false;
            }
            $Form['actions'][0]['items'][1]['caption'] = $this->GetConsumerAddress();
            $this->SendDebug('FORM', json_encode($Form), 0);
            $this->SendDebug('FORM', json_last_error_msg(), 0);
            return json_encode($Form);
        }

        /**
         * ReadValues
         *
         * @return bool
         */
        public function ReadValues(): bool
        {
            if ($this->Host == '') {
                trigger_error($this->Translate('Device offline'), E_USER_NOTICE);
                return false;
            }
            $Values = @Sys_GetURLContent('http://' . $this->Host . '/json');
            $this->SendDebug('ReadValues JSON:' . $this->Host, $Values, 0);
            if ($Values) {
                $DataValues = json_decode($Values, true)['main'];
                $Timestamp = new DateTime($DataValues['timestamp']);
                $this->SetDataValues(
                    $Timestamp->getTimestamp(),
                    (float) $DataValues['value'],
                    (float) $DataValues['pre'],
                    $DataValues['raw'],
                    $DataValues['error']
                );
                return true;
            }
            $this->TriggerTimeout();
            return false;
        }

        /**
         * LoadImage
         *
         * @return void
         */
        public function LoadImage(): bool
        {
            if ($this->Host == '') {
                trigger_error($this->Translate('Device offline'), E_USER_NOTICE);
                return false;
            }
            if (!$this->ReadPropertyBoolean(AIontheEdgeDevice\Property::EnableSnapshotImage)) {
                return false;
            }
            $imageData = @Sys_GetURLContent('http://' . $this->Host . '/img_tmp/alg_roi.jpg');
            $this->SendDebug('Receive Image Bytes', strlen($imageData), 0);
            $MediaId = $this->GetMediaId();
            IPS_SetMediaContent($MediaId, base64_encode($imageData));
            return true;
        }

        /**
         * StartFlow
         *
         * @return bool
         */
        public function StartFlow(): bool
        {
            if ($this->Host == '') {
                trigger_error($this->Translate('Device offline'), E_USER_NOTICE);
                return false;
            }
            $Value = @Sys_GetURLContent('http://' . $this->Host . '/flow_start');
            $this->SendDebug(__FUNCTION__, $Value, 0);
            return (bool) $Value;
        }

        /**
         * ProcessHookData
         *
         * @return void
         */
        protected function ProcessHookData(): void
        {
            $DeviceAddr = $_SERVER['REMOTE_ADDR'];
            if ($_SERVER['HTTP_USER_AGENT'] != 'ESP32 Meter reader') {
                http_response_code(403); // 403 Forbidden
                echo json_encode(['status' => 'error', 'message' => 'Invalid device, not an ESP32 Meter reader']);
                $this->SendDebug($DeviceAddr, 'Invalid device, not an ESP32 Meter reader', 0);
                return;
            }

            $ReceivedApiKey = isset($_SERVER['HTTP_APIKEY']) ? $_SERVER['HTTP_APIKEY'] : '';
            if ($ReceivedApiKey !== $this->ApiKey) {
                http_response_code(403); // 403 Forbidden
                echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
                $this->SendDebug($DeviceAddr, 'Invalid ApiKey received', 0);
                return;
            }
            if ($this->ReadAttributeString(AIontheEdgeDevice\Attribute::Host) == '') {
                $this->Host = $DeviceAddr;
                $Host = gethostbyaddr($DeviceAddr);
                $this->WriteAttributeString(AIontheEdgeDevice\Attribute::Host, $Host);
                $this->UpdateFormField(AIontheEdgeDevice\Attribute::Host, 'value', $Host);
                $this->UpdateFormField(AIontheEdgeDevice\Hook::Form, 'caption', $this->GetConsumerAddress());
            }
            if ($this->Host != $DeviceAddr) {
                http_response_code(403); // 403 Forbidden
                echo json_encode(['status' => 'error', 'message' => 'Invalid device']);
                $this->SendDebug($DeviceAddr, 'Invalid device', 0);
                return;
            }
            switch ($_SERVER['REQUEST_METHOD']) {
                case 'POST': // Receive Data
                    $jsonData = file_get_contents('php://input');
                    $this->SendDebug('Receive JSON:' . $DeviceAddr, $jsonData, 0);
                    $dataArray = json_decode($jsonData, true);
                    if (!$jsonData || !is_array($dataArray)) {
                        http_response_code(400); // 400 Bad Request
                        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data']);
                        $this->SendDebug($DeviceAddr, 'Invalid JSON data', 0);
                        return;
                    }
                    $DataValues = array_shift($dataArray);
                    if ($DataValues['name'] = !'main') {
                        http_response_code(400); // 400 Bad Request
                        echo json_encode(['status' => 'error', 'message' => 'Invalid name given']);
                        $this->SendDebug($DeviceAddr, 'Invalid name given', 0);
                        return;
                    }
                    $this->SetDataValues(
                        (int) $DataValues['timestampLong'],
                        (float) $DataValues['value'],
                        (float) $DataValues['preValue'],
                        $DataValues['rawValue'],
                        $DataValues['error']
                    );
                    http_response_code(200); // 200 OK
                    echo json_encode(['status' => 'success', 'message' => 'Data written to Symcon']);
                    return;
                case 'PUT': //Save Image
                    $Timestamp = $_GET['timestamp'];
                    if (!ctype_digit($Timestamp) || $Timestamp < 0 || $Timestamp > PHP_INT_MAX) {
                        http_response_code(400); // 400 Bad Request
                        echo json_encode(['status' => 'error', 'message' => 'Invalid timestamp']);
                        $this->SendDebug($DeviceAddr, 'Invalid timestamp', 0);
                        return;
                    }
                    $imageData = file_get_contents('php://input');
                    if (!$imageData) {
                        http_response_code(400); // 400 Bad Request
                        echo json_encode(['status' => 'error', 'message' => 'No image data received']);
                        $this->SendDebug($DeviceAddr, 'No image data received', 0);
                        return;
                    }
                    if ($this->ReadPropertyBoolean(AIontheEdgeDevice\Property::EnableSnapshotImage)) {
                        $this->SendDebug('Receive Image', strlen($imageData), 0);
                        $MediaId = $this->GetMediaId();
                        IPS_SetMediaContent($MediaId, base64_encode($imageData));
                    }
                    http_response_code(200); // 200 OK
                    echo json_encode(['status' => 'success', 'message' => 'Image uploaded successfully']);
                    return;
            }
            // Handle unsupported HTTP methods
            http_response_code(405); // 405 Method Not Allowed
            echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
            $this->SendDebug($DeviceAddr, 'Method not allowed', 0);
        }

        /**
         * SetDataValues
         *
         * @param  int $Timestamp
         * @param  float $Value
         * @param  float $PreValue
         * @param  string $RawValue
         * @param  string $Error
         * @return void
         */
        private function SetDataValues(int $Timestamp, float $Value, float $PreValue, string $RawValue, string $Error): void
        {
            $this->SendDebug('SetDataValues', func_get_args(), 0);
            $this->SetValueInteger(AIontheEdgeDevice\Variable::Timestamp, $Timestamp);
            if ($Value > 0) {
                $this->SetValueFloat(AIontheEdgeDevice\Variable::Value, $Value);
            }
            $this->SetValueString(AIontheEdgeDevice\Variable::Error, $Error);
            if ($this->ReadPropertyBoolean(AIontheEdgeDevice\Property::EnablePreValue)) {
                $this->SetValueFloat(AIontheEdgeDevice\Variable::PreValue, $PreValue);
            }
            if ($this->ReadPropertyBoolean(AIontheEdgeDevice\Property::EnableRawValue)) {
                $this->SetValueString(AIontheEdgeDevice\Variable::RawValue, $RawValue);
            }
            if ($this->ReadPropertyBoolean(AIontheEdgeDevice\Property::EnableTimeoutVariable)) {
                $this->SetValueBoolean(AIontheEdgeDevice\Variable::Connection, false);
            }
            if ($this->ReadPropertyBoolean(AIontheEdgeDevice\Property::EnableTimeoutInstanceStatus) || ($this->GetStatus() == \AIontheEdgeDevice\ModuleState::Inactive)) {
                $this->SetStatus(AIontheEdgeDevice\ModuleState::Active);
            }
            $this->ResetTimeoutIntervall();
        }

        /**
         * GetMediaId
         *
         * @return int
         */
        private function GetMediaId(): int
        {
            $MediaId = $this->FindIDForIdent('IMAGE');
            if (!$MediaId) {
                $MediaId = IPS_CreateMedia(MEDIATYPE_IMAGE);
                IPS_SetParent($MediaId, $this->InstanceID);
                IPS_SetName($MediaId, $this->Translate('Image'));
                IPS_SetIdent($MediaId, 'IMAGE');
                $filename = 'media' . DIRECTORY_SEPARATOR . 'AiOnTheEdge' . $this->InstanceID . '.jpg';
                IPS_SetMediaFile($MediaId, $filename, false);
            }
            return $MediaId;
        }

        /**
         * ResetTimeoutIntervall
         *
         * @return void
         */
        private function ResetTimeoutIntervall(): void
        {
            $this->SetTimerInterval(AIontheEdgeDevice\Timer::Timeout, 0);
            $Timeout = $this->ReadPropertyInteger(AIontheEdgeDevice\Property::Timeout);
            $this->SetTimerInterval(AIontheEdgeDevice\Timer::Timeout, $Timeout * 1000);
        }

        /**
         * TriggerTimeout
         *
         * @return void
         */
        private function TriggerTimeout(): void
        {
            if ($this->ReadPropertyBoolean(AIontheEdgeDevice\Property::EnableTimeoutVariable)) {
                $this->SetValueBoolean(AIontheEdgeDevice\Variable::Connection, true);
            }
            if ($this->ReadPropertyBoolean(AIontheEdgeDevice\Property::EnableTimeoutInstanceStatus)) {
                $this->SetStatus(AIontheEdgeDevice\ModuleState::Offline);
            }
            $this->SetTimerInterval(AIontheEdgeDevice\Timer::Timeout, 0);
        }

        /**
         * GetConsumerAddress
         *
         * @return string
         */
        private function GetConsumerAddress(): string
        {
            $Url = $this->Translate('Invalid');
            if (IPS_GetOption('NATSupport')) {
                $ip = IPS_GetOption('NATPublicIP');
                if ($ip == '') {
                    $this->SendDebug('NAT enabled ConsumerAddress', 'Invalid', 0);
                    return $this->Translate('NATPublicIP is missing in special switches!');
                }
                $Url = 'http://' . $ip . ':3777' . \AIontheEdgeDevice\Hook::Uri . $this->InstanceID;
            } else {
                if ($this->Host) {
                    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                    socket_bind($sock, '0.0.0.0', 0);
                    @socket_connect($sock, $this->Host, 80);
                    $ip = '';
                    socket_getsockname($sock, $ip);
                    @socket_close($sock);
                    if ($ip == '0.0.0.0') {
                        $this->SendDebug('ConsumerAddress', 'Invalid', 0);
                        return $this->Translate('Invalid');
                    }
                    $Url = 'http://' . $ip . ':3777' . \AIontheEdgeDevice\Hook::Uri . $this->InstanceID;
                } else {
                    $IPs = $this->getIPAdresses();
                    $this->SendDebug('MyIPs', $IPs, 0);
                    foreach ($IPs as &$ip) {
                        $ip = 'http://' . $ip . ':3777' . \AIontheEdgeDevice\Hook::Uri . $this->InstanceID;
                    }
                    $Url = implode("\r\n", $IPs);
                }
            }
            return $Url;
        }

        /**
         * getIPAdresses
         *
         * @return array
         */
        private function getIPAdresses(): array
        {
            $Networks = net_get_interfaces();
            $Addresses = [];
            foreach ($Networks as $InterfaceDescription => $Interface) {
                $Interface['up'] ??= false;
                if (!$Interface['up']) {
                    continue;
                }
                foreach ($Interface['unicast'] as $Address) {
                    $Address['family'] ??= -1;
                    switch ($Address['family']) {
                        case AF_INET:
                            if ($Address['address'] == '127.0.0.1') {
                                continue 2;
                            }
                            break;
                        default:
                            continue 2;
                    }
                    $Addresses[] = $Address['address'];
                }
            }
            return $Addresses;
        }
    }

}

namespace AIontheEdgeDevice {

    /**
     * ModuleState
     */
    class ModuleState
    {
        public const Active = IS_ACTIVE;
        public const Inactive = IS_INACTIVE;
        public const Offline = IS_EBASE + 2;
    }

    /**
     * Property
     */
    class Property
    {
        public const ApiKey = 'ApiKey';
        public const ValueProfile = 'ValueProfile';
        public const ValueIcon = 'ValueIcon';
        public const EnablePreValue = 'EnablePreValue';
        public const EnableRawValue = 'EnableRawValue';
        public const EnableSnapshotImage = 'EnableSnapshotImage';
        public const EnableTimeoutVariable = 'EnableTimeoutVariable';
        public const EnableTimeoutInstanceStatus = 'EnableTimeoutInstanceStatus';
        public const Timeout = 'Timeout';
        public const DigitizeIntervall = 'DigitizeIntervall';
    }

    /**
     * Attribute
     */
    class Attribute
    {
        public const Host = 'Host';
    }
    /**
     * Timer
     */
    class Timer
    {
        public const Timeout = 'Timeout';
        public const RunFlow = 'RunFlow';
    }

    /**
     * Hook
     */
    class Hook
    {
        public const Uri = '/hook/AIontheEdgeDevice/';
        public const Form = 'EventHook';
    }

    /**
     * Variable
     */
    class Variable
    {
        public const Timestamp = 'Timestamp';
        public const Value = 'Value';
        public const Error = 'Error';
        public const PreValue = 'PreValue';
        public const RawValue = 'RawValue';
        public const Connection = 'Connection';
    }

    /**
     * Action
     */
    class Action
    {
        public const EnableLogging = 'EnableLogging';
        public const SaveAddress = 'SaveAddress';
        public const ResetAddress = 'ResetAddress';
    }

    /**
     * GUID
     */
    class GUID
    {
        public const ArchiveControl = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    }
}