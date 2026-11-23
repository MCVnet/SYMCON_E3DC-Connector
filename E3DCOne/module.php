<?php

declare(strict_types=1);

class E3DCOne extends IPSModule
{
    private const EXTRA_GROUPS = [
        'DCStrings' => 'PV-Strings',
        'PowerMeters' => 'Leistungsmesser',
        'Inverters' => 'Wechselrichter je Index',
        'BatteryDetails' => 'Batteriegrenzen und Speicherenergie',
        'RemoteStatus' => 'Status der Fernsteuerung',
        'WallboxDetails' => 'Wallbox-Slots (Details)',
        'ReadNotes' => 'Nicht verfügbare Daten'
    ];

    private const DAILY = [
        'DayPV' => 'Solarproduktion heute (kWh)',
        'DayBatteryCharge' => 'Batterie geladen heute (kWh)',
        'DayBatteryDischarge' => 'Batterie entladen heute (kWh)',
        'DayGridImport' => 'Netzbezug heute (berechnet, kWh)',
        'DayGridExport' => 'Netzeinspeisung heute (berechnet, kWh)',
        'DayHouse' => 'Hausverbrauch heute (kWh)',
        'DayHeatPump' => 'Wärmepumpe heute (kWh)',
        'DayWallbox' => 'Wallbox geladen heute (kWh)',
        'DayConsumption' => 'Gesamtverbrauch heute (kWh)'
    ];

    private const POWER = [
        'PV' => 'PV-Leistung (W)',
        'Battery' => 'Batterieleistung (W)',
        'House' => 'Hausverbrauch laut one (W)',
        'Grid' => 'Netzleistung (W)',
        'External' => 'Weitere Einspeiser (W)',
        'WallboxPower' => 'Wallbox-Leistung (W)',
        'WallboxSolar' => 'Wallbox-Solarleistung (W)'
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('Unit', 1);
        $this->RegisterPropertyInteger('Interval', 15);
        $this->RegisterPropertyBoolean('AutoFarmDevices', true);
        $this->RegisterPropertyBoolean('ReadWallbox', false);
        $this->RegisterPropertyInteger('WallboxSlot', -1);
        $this->RegisterPropertyInteger('FarmConsumptionMeter', -1);
        $this->RegisterPropertyInteger('HeatPumpMeter', -1);
        $this->RegisterPropertyInteger('SecondOne', 0);
        $this->RegisterPropertyString('SecondHost', '');
        $this->RegisterPropertyString('LanNetwork', '');
        $this->RegisterPropertyInteger('SecondCapacityFallbackWh', 0);
        $this->RegisterPropertyInteger('ForecastSource', 0);
        $this->RegisterPropertyInteger('CapacityFallbackWh', 0);
        $this->RegisterAttributeString('DailyTotals', '{}');
        $this->RegisterTimer('PollTimer', 0, 'E3DCF_Poll($_IPS["TARGET"]);');

        $this->maintainGroupedVariables();
    }

    private function maintainGroupedVariables(): void
    {
        foreach (self::POWER as $ident => $label) {
            $this->maintainGroupedVariable($ident, $label, '', VARIABLETYPE_INTEGER);
        }
        $this->maintainGroupedVariable('SOC', 'Batterieladezustand (%)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('EMS', 'EMS-Status (Rohwert)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('Wallboxes', 'Wallbox-Erkennung', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('LastSuccess', 'Letzte erfolgreiche Abfrage', '~UnixTimestamp', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('DataStatus', 'Datenstatus', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('Manufacturer', 'Hersteller', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('Model', 'Modell', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('Serial', 'Seriennummer', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('Firmware', 'Firmware-Release', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('ModbusVersion', 'Modbus-Firmware-Version', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('RegisterCount', 'Gemeldete Registeranzahl', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('Emergency', 'Notstromstatus (Rohwert)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('EfficiencyRaw', 'Autarkie und Eigenverbrauch (Register 40082)', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('EMSFlags', 'EMS-Status (Details)', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('FarmConsumption', 'Farm-Gesamtverbrauch (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('HeatPumpConsumption', 'Wärmepumpe (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmHouseConsumption', 'Hausverbrauch ohne Wärmepumpe (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmConsumptionValid', 'Farm-Verbrauchswerte aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('FarmBatterySOC', 'Farm-Batterieladezustand (%)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('FarmBatteryPower', 'Farm-Batterieleistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmBatteryValid', 'Farm-Batteriewerte aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('BatteryCapacity', 'Speicherkapazität des one (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('BatteryEnergy', 'Verbleibende Speicherenergie des one (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('BatteryEnergyValid', 'Speicherenergie des one aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('FarmBatteryCapacity', 'Farm-Speicherkapazität (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('FarmBatteryEnergy', 'Verbleibende Farm-Speicherenergie (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('FarmPV', 'Farm-PV-Leistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmGrid', 'Farm-Netzleistung (berechnet, W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmGridImport', 'Farm-Netzbezug (berechnet, W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmGridExport', 'Farm-Einspeisung (berechnet, W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmAutarky', 'Farm-Momentanautarkie (berechnet, %)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('FarmWallboxPower', 'Farm-Wallbox-Leistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmPowerValid', 'Farm-Leistungswerte aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('DayForecast', 'Solarprognose heute (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('DayForecastAvailable', 'Solarprognose verfügbar', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('DayStart', 'Tageserfassung seit', '~UnixTimestamp', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('SecondPV', 'Zweiter one PV-Leistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('SecondBattery', 'Zweiter one Batterieleistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('SecondSOC', 'Zweiter one Batterieladezustand (%)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('SecondCapacity', 'Zweiter one Speicherkapazität (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('SecondOneValid', 'Zweiter one erreichbar', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('LanSetupStatus', 'LAN-Einrichtung', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('BatteryChargePower', 'Ladeleistung des one (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('BatteryDischargePower', 'Entladeleistung des one (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('GridImport', 'Netzbezug laut one (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('GridExport', 'Netzeinspeisung laut one (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmBatteryChargePower', 'Farm-Batterieladeleistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmBatteryDischargePower', 'Farm-Batterieentladeleistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('DetectedFarmDevices', 'Zähler- und Wallbox-Zuordnung', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('SGReadyState', 'SG Ready (Statuscode)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('SGReadyValid', 'SG-Ready-Status aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('WallboxAvailable', 'Wallbox verfügbar', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('WallboxSolarMode', 'Wallbox im Solarbetrieb', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('WallboxChargeAllowed', 'Wallbox-Laden freigegeben', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('WallboxCharging', 'Wallbox lädt', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('WallboxVehicleConnected', 'Fahrzeug angeschlossen', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('WallboxPhaseMode', 'Wallbox-Phasenmodus', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('EmergencyState', 'Notstromstatus', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('OneAutarky', 'Autarkie laut one (%)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('OneSelfConsumption', 'Eigenverbrauch laut one (%)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('OneEfficiencyValid', 'Autarkie und Eigenverbrauch aktuell', '', VARIABLETYPE_BOOLEAN);
        foreach (self::DAILY as $ident => $label) {
            $this->maintainGroupedVariable($ident, $label, '', VARIABLETYPE_FLOAT);
        }
        foreach (self::EXTRA_GROUPS as $ident => $label) {
            $this->maintainGroupedVariable($ident, $label, '', VARIABLETYPE_STRING);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->maintainGroupedVariables();
        $this->SetTimerInterval('PollTimer', 0);
        $this->setGroupedValue('FarmBatteryValid', false);
        $this->setGroupedValue('BatteryEnergyValid', false);
        $this->setGroupedValue('OneEfficiencyValid', false);
        $this->setGroupedValue('FarmPowerValid', false);
        $this->setGroupedValue('SecondOneValid', false);
        $this->setGroupedValue('FarmConsumptionValid', false);
        $this->setGroupedValue('SGReadyValid', false);
        $this->setGroupedValue('WallboxAvailable', false);
        if (!$this->ReadPropertyBoolean('Active')) {
            $daily = json_decode($this->ReadAttributeString('DailyTotals'), true) ?? [];
            $daily['powers'] = [];
            $this->WriteAttributeString('DailyTotals', json_encode($daily));
            $this->setGroupedValue('DayForecastAvailable', false);
            $this->setGroupedValue('DataStatus', 'Instanz deaktiviert');
            $this->SetStatus(104);
            return;
        }
        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->setGroupedValue('DataStatus', 'Farmmaster-IP fehlt');
            $this->SetStatus(104);
            return;
        }
        $this->SetTimerInterval('PollTimer', max(5, min(3600, $this->ReadPropertyInteger('Interval'))) * 1000);
    }

    private const GROUPS = [
            'Farm' => ['Farm live', [
                'FarmPV', 'FarmGrid', 'FarmGridImport', 'FarmGridExport', 'FarmConsumption',
                'FarmHouseConsumption', 'HeatPumpConsumption', 'FarmAutarky',
                'FarmWallboxPower', 'FarmPowerValid', 'FarmConsumptionValid'
            ]],
            'Storage' => ['Batterie und Speicher', [
                'FarmBatterySOC', 'FarmBatteryPower', 'FarmBatteryChargePower', 'FarmBatteryDischargePower',
                'FarmBatteryCapacity', 'FarmBatteryEnergy', 'FarmBatteryValid', 'SOC', 'Battery',
                'BatteryChargePower', 'BatteryDischargePower', 'BatteryCapacity', 'BatteryEnergy',
                'BatteryEnergyValid', 'BatteryDetails'
            ]],
            'Wallbox' => ['Wallbox', [
                'WallboxAvailable', 'WallboxPower', 'WallboxSolar', 'WallboxCharging',
                'WallboxChargeAllowed', 'WallboxSolarMode', 'WallboxPhaseMode',
                'WallboxVehicleConnected', 'Wallboxes', 'WallboxDetails'
            ]],
            'Daily' => ['Tageswerte und Prognose', [
                'DayForecast', 'DayForecastAvailable', 'DayStart'
            ]],
            'Master' => ['Farmmaster', [
                'PV', 'House', 'Grid', 'GridImport', 'GridExport', 'External',
                'OneAutarky', 'OneSelfConsumption', 'OneEfficiencyValid',
                'Manufacturer', 'Model', 'Serial', 'Firmware', 'ModbusVersion'
            ]],
            'Second' => ['Zweiter one', [
                'SecondOneValid', 'SecondPV', 'SecondBattery', 'SecondSOC', 'SecondCapacity'
            ]],
            'Diagnostics' => ['Diagnose und Status', [
                'DataStatus', 'LastSuccess', 'LanSetupStatus', 'ReadNotes', 'DetectedFarmDevices', 'RegisterCount',
                'PowerMeters', 'DCStrings', 'Inverters', 'SGReadyState', 'SGReadyValid',
                'EmergencyState', 'Emergency', 'EMS', 'EMSFlags', 'EfficiencyRaw', 'RemoteStatus'
            ]]
        ];

    private function variableLocation(string $ident): array
    {
        foreach (self::GROUPS as $index => [$label, $idents]) {
            if ($index === 'Daily') {
                $idents = array_merge($idents, array_keys(self::DAILY));
            }
            $position = array_search($ident, $idents, true);
            if ($position !== false) {
                return ['E3DCGroup' . $index, $label, $position * 10];
            }
        }
        throw new LogicException('Unbekannte Modulvariable: ' . $ident);
    }

    private function maintainGroupedVariable(string $ident, string $name, string $profile, int $type): void
    {
        [$groupIdent, $groupName, $position] = $this->variableLocation($ident);
        $category = @IPS_GetObjectIDByIdent($groupIdent, $this->InstanceID);
        if ($category === false) {
            $category = IPS_CreateCategory();
            IPS_SetParent($category, $this->InstanceID);
            IPS_SetIdent($category, $groupIdent);
            IPS_SetName($category, $groupName);
            IPS_SetPosition($category, array_search(substr($groupIdent, 9), array_keys(self::GROUPS), true) * 10);
        }
        if (!IPS_CategoryExists($category)) {
            return;
        }

        $existing = @IPS_GetObjectIDByIdent($ident, $category);
        if ($existing !== false && IPS_VariableExists($existing)) {
            return;
        }
        $original = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($original !== false && !IPS_VariableExists($original)) {
            return;
        }
        if ($existing !== false) {
            // Only remove links created by the previous layout for this original variable.
            if ($original === false || !IPS_LinkExists($existing)
                || IPS_GetLink($existing)['TargetID'] !== $original || !IPS_SetIdent($existing, '')) {
                return;
            }
        }
        if ($original !== false) {
            if (IPS_SetParent($original, $category)) {
                IPS_SetPosition($original, $position);
                if ($existing !== false) {
                    IPS_DeleteLink($existing);
                }
            } elseif ($existing !== false) {
                IPS_SetIdent($existing, $ident);
            }
            return;
        }

        $variable = IPS_CreateVariable($type);
        IPS_SetParent($variable, $category);
        IPS_SetIdent($variable, $ident);
        IPS_SetName($variable, $name);
        IPS_SetPosition($variable, $position);
        if ($profile !== '') {
            IPS_SetVariableCustomProfile($variable, $profile);
        }
    }

    private function findGroupedVariable(string $ident, int $instance): int|false
    {
        [$groupIdent] = $this->variableLocation($ident);
        $category = @IPS_GetObjectIDByIdent($groupIdent, $instance);
        if ($category !== false && IPS_CategoryExists($category)) {
            $variable = @IPS_GetObjectIDByIdent($ident, $category);
            if ($variable !== false && IPS_VariableExists($variable)) {
                return $variable;
            }
        }
        $variable = @IPS_GetObjectIDByIdent($ident, $instance);
        return $variable !== false && IPS_VariableExists($variable) ? $variable : false;
    }

    private function groupedVariableID(string $ident): int
    {
        $variable = $this->findGroupedVariable($ident, $this->InstanceID);
        if ($variable === false) {
            throw new RuntimeException('Modulvariable fehlt: ' . $ident);
        }
        return $variable;
    }

    private function setGroupedValue(string $ident, mixed $value): void
    {
        SetValue($this->groupedVariableID($ident), $value);
    }

    private function getGroupedValue(string $ident): mixed
    {
        return GetValue($this->groupedVariableID($ident));
    }

    public function DiscoverLAN(string $network, string $master, int $port, int $unit): string
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return 'Instanz ist deaktiviert. Für die LAN-Suche zuerst aktivieren.';
        }
        $result = $this->findOneDevices($network, $master, $port, $unit);
        if (!isset($result['second'])) {
            return $result['message'];
        }
        $statusId = $this->groupedVariableID('LanSetupStatus');
        $this->setGroupedValue('LanSetupStatus', 'IP-Adressen gefunden. Übernahme läuft.');
        $script = <<<'PHP'
$id = $_IPS['OneInstance'];
$statusId = $_IPS['OneSetupStatus'];
if (!IPS_InstanceExists($id) || !IPS_VariableExists($statusId)) {
    return;
}
$settings = [
    'Host' => $_IPS['OneMaster'],
    'SecondHost' => $_IPS['OneSecond'],
    'Port' => $_IPS['OnePort'],
    'Unit' => $_IPS['OneUnit']
];
foreach ($settings as $property => $value) {
    if (!IPS_SetProperty($id, $property, $value)) {
        SetValue($statusId, 'IP-Adressen konnten nicht gespeichert werden.');
        return;
    }
}
if (!IPS_ApplyChanges($id)) {
    SetValue($statusId, 'IP-Adressen konnten nicht übernommen werden.');
    return;
}
SetValue($statusId, 'IP-Adressen übernommen: ' . $settings['Host'] . ' und ' . $settings['SecondHost']);
E3DCF_Poll($id);
PHP;
        $started = IPS_RunScriptTextEx($script, [
            'OneInstance' => $this->InstanceID,
            'OneSetupStatus' => $statusId,
            'OneMaster' => $result['master'],
            'OneSecond' => $result['second'],
            'OnePort' => $result['port'],
            'OneUnit' => $result['unit']
        ]);
        if (!$started) {
            $this->setGroupedValue('LanSetupStatus', 'Automatische Übernahme konnte nicht gestartet werden.');
            return $this->getGroupedValue('LanSetupStatus');
        }

        return $result['message'] . "\n\nAutomatische Übernahme gestartet. Das Ergebnis steht unter LAN-Einrichtung.";
    }

    public function DiscoverLANSetup(string $network, string $master, int $port, int $unit): string
    {
        return json_encode(['message' => $this->DiscoverLAN($network, $master, $port, $unit)],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private function findOneDevices(string $network, string $master, int $port, int $unit): array
    {
        $savedMaster = trim($this->ReadPropertyString('Host'));
        $network = trim($network);
        if ($network === '') {
            $network = trim($master);
        }
        if ($network === '') {
            $network = $savedMaster;
        }
        $localPrefixes = $this->localIPv4Prefixes();
        $prefixes = $localPrefixes;
        if ($network !== '') {
            $address = preg_replace('~\s*/\s*24$~', '', $network);
            if (preg_match('/^([0-9]{1,3}\.){2}[0-9]{1,3}\.?$/', $address)) {
                $address = rtrim($address, '.') . '.0';
            }
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return ['message' => 'Das LAN-Suchnetz muss eine IPv4-Adresse oder ein IPv4-/24-Netz sein. Eingegeben: ' . $network];
            }
            $prefixes = [substr($address, 0, strrpos($address, '.') + 1)];
        }
        if ($prefixes === []) {
            return ['message' => 'Symcon hat keine nutzbare lokale IPv4-Adresse gefunden. Bei Docker oder VLAN das LAN-Suchnetz angeben.'];
        }
        if ($port === 0) {
            $port = $this->ReadPropertyInteger('Port');
            $unit = $this->ReadPropertyInteger('Unit');
        }
        if ($port < 1 || $port > 65535) {
            return ['message' => 'Modbus-TCP-Port zwischen 1 und 65535 eingeben.'];
        }
        if ($unit < 0 || $unit > 255) {
            return ['message' => 'Modbus Unit-ID zwischen 0 und 255 eingeben.'];
        }
        $masterIp = $savedMaster !== '' ? $savedMaster : trim($master);
        $matches = [];
        $searched = [];
        foreach ($prefixes as $prefix) {
            if (!in_array($prefix, $localPrefixes, true)
                && !preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $prefix)
                && (!filter_var($savedMaster, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    || !str_starts_with($savedMaster, $prefix)
                    || !$this->verifyMasterForDiscovery($savedMaster, $port, $unit))) {
                return ['message' => 'Dieses Suchnetz muss am Symcon-Server anliegen oder einen gespeicherten, erreichbaren Farmmaster enthalten.'];
            }
            $searched[] = $prefix . '0/24';
            $matches += $this->scanOneSubnet($prefix, $port, $unit, $masterIp === '');
        }
        if ($matches === []) {
            return ['message' => sprintf('Kein E3/DC one gefunden. Geprüft: %s (Port %d, Unit-ID %d).',
                implode(', ', $searched), $port, $unit)];
        }

        $lines = [];
        foreach ($matches as $host => $device) {
            $lines[] = $host . ' — E3/DC one — ' . $device['serial'];
        }
        $summary = implode("\n", $lines);
        if (count($matches) !== 2) {
            return ['message' => $summary . "\n\nFür die automatische Zuordnung müssen genau zwei one gefunden werden."];
        }
        $serials = array_column($matches, 'serial');
        if (in_array('', $serials, true) || count(array_unique($serials)) !== 2) {
            return ['message' => $summary . "\n\nDie beiden Geräte melden keine eindeutigen Seriennummern."];
        }
        if ($masterIp === '') {
            $farmMasters = array_keys(array_filter($matches, static fn (array $device): bool => $device['farmMeter']));
            if (count($farmMasters) === 1) {
                $masterIp = $farmMasters[0];
            }
        }
        if (!isset($matches[$masterIp])) {
            return ['message' => $summary . "\n\nDer Farmmaster konnte nicht eindeutig zugeordnet werden. Bitte die Farmmaster-IP hinterlegen."];
        }

        $secondIp = array_key_first(array_diff_key($matches, [$masterIp => true]));
        if (substr($masterIp, 0, strrpos($masterIp, '.')) !== substr($secondIp, 0, strrpos($secondIp, '.'))) {
            return ['message' => $summary . "\n\nDie Geräte liegen in unterschiedlichen Suchnetzen. Bitte das Farmnetz angeben."];
        }
        return [
            'message' => "Farmmaster: $masterIp – " . $matches[$masterIp]['serial']
                . "\nZweiter one: $secondIp – " . $matches[$secondIp]['serial'],
            'master' => $masterIp,
            'second' => $secondIp,
            'port' => $port,
            'unit' => $unit
        ];
    }

    private function localIPv4Prefixes(): array
    {
        $prefixes = [];
        foreach (Sys_GetNetworkInfo() as $adapter) {
            $address = $adapter['IP'] ?? '';
            if (!is_string($address) || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || preg_match('/^(0|127|169\.254)\./', $address)
                || (int) explode('.', $address, 2)[0] >= 224) {
                continue;
            }
            $prefixes[substr($address, 0, strrpos($address, '.') + 1)] = true;
        }
        return array_keys($prefixes);
    }

    private function scanOneSubnet(string $prefix, int $port, int $unit, bool $identifyMaster): array
    {
        $matches = [];
        foreach (array_chunk(range(1, 254), 24) as $batch) {
            $pending = [];
            foreach ($batch as $lastOctet) {
                $host = $prefix . $lastOctet;
                $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errorCode, $errorText, 0.25,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
                if ($socket !== false) {
                    $pending[$host] = $socket;
                }
            }
            try {
                $deadline = microtime(true) + 0.3;
                while ($pending !== [] && microtime(true) < $deadline) {
                    $writable = $pending;
                    $readable = null;
                    $exceptions = null;
                    $remaining = max(0, (int) (($deadline - microtime(true)) * 1000000));
                    if (@stream_select($readable, $writable, $exceptions, 0, $remaining) < 1) {
                        break;
                    }
                    foreach ($writable as $host => $socket) {
                        unset($pending[$host]);
                        try {
                            if (@stream_socket_get_name($socket, true) === false) {
                                continue;
                            }
                            stream_set_blocking($socket, true);
                            stream_set_timeout($socket, 0, 300000);
                            if ($this->readRegisters($socket, $unit, 1, 40001, 1)[0] !== 0xE3DC) {
                                continue;
                            }
                            usleep(110000);
                            $identity = $this->readRegisters($socket, $unit, 2, 40020, 32);
                            $model = $this->decodeText(array_slice($identity, 0, 16));
                            if (strcasecmp($model, 'one') === 0) {
                                $serial = $this->decodeText(array_slice($identity, 16, 16));
                                $matches[$host] = ['serial' => $serial, 'farmMeter' => false];
                                if ($identifyMaster) {
                                    usleep(110000);
                                    $meters = $this->readRegisters($socket, $unit, 3, 40105, 32);
                                    $matches[$host]['farmMeter'] = in_array(5, array_filter($meters,
                                        static fn (int $index): bool => $index % 4 === 0, ARRAY_FILTER_USE_KEY), true);
                                }
                            }
                        } catch (RuntimeException $error) {
                            // The port can be open on devices that do not speak E3/DC Modbus.
                        } finally {
                            fclose($socket);
                        }
                    }
                }
            } finally {
                foreach ($pending as $socket) {
                    fclose($socket);
                }
            }
        }

        return $matches;
    }

    private function verifyMasterForDiscovery(string $host, int $port, int $unit): bool
    {
        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errorCode, $errorText, 1.0);
        if ($socket === false) {
            return false;
        }
        try {
            stream_set_timeout($socket, 1);
            return $this->readRegisters($socket, $unit, 1, 40001, 1)[0] === 0xE3DC;
        } catch (RuntimeException $error) {
            return false;
        } finally {
            fclose($socket);
        }
    }

    public function Poll(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $lock = 'E3DCOne.Poll.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            return;
        }

        try {
            $this->setGroupedValue('FarmConsumptionValid', false);
            $host = trim($this->ReadPropertyString('Host'));
            $port = $this->ReadPropertyInteger('Port');
            $unit = $this->ReadPropertyInteger('Unit');
            if (!filter_var($host, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535 || $unit < 0 || $unit > 255) {
                throw new RuntimeException('IP-Adresse, Port oder Unit-ID ungültig.');
            }

            $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
            $socket = @stream_socket_client('tcp://' . $address . ':' . $port, $errorCode, $errorText, 2.0);
            if ($socket === false) {
                throw new RuntimeException('TCP-Verbindung fehlgeschlagen (Fehler ' . $errorCode . ').');
            }

            try {
                stream_set_timeout($socket, 2);
                if ($this->readRegisters($socket, $unit, 1, 40001, 1)[0] !== 0xE3DC) {
                    throw new RuntimeException('E3/DC Simple Mode wurde nicht erkannt.');
                }
                $registers = $this->readRegisters($socket, $unit, 2, 40068, 18);
                $this->publishCore($registers);
                $this->setGroupedValue('LastSuccess', time());
                $this->readDetails($socket, $unit);
            } finally {
                fclose($socket);
            }

            $this->publishDaily($this->getGroupedValue('FarmPowerValid'));
            $this->setGroupedValue('DataStatus', 'Messwerte aktualisiert');
            $this->SetStatus(102);
        } catch (RuntimeException $error) {
            $this->setGroupedValue('FarmBatteryValid', false);
            $this->setGroupedValue('BatteryEnergyValid', false);
            $this->setGroupedValue('FarmPowerValid', false);
            $this->setGroupedValue('SecondOneValid', false);
            $this->setGroupedValue('SGReadyValid', false);
            $this->setGroupedValue('OneEfficiencyValid', false);
            $this->setGroupedValue('WallboxAvailable', false);
            $this->publishDaily(false);
            $this->setGroupedValue('DataStatus', $error->getMessage());
            $this->SetStatus(201);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function publishCore(array $registers): void
    {
        $index = 0;
        foreach (self::POWER as $ident => $label) {
            $this->setGroupedValue($ident, $this->signed32($registers, $index));
            $index += 2;
        }
        $battery = $this->getGroupedValue('Battery');
        $grid = $this->getGroupedValue('Grid');
        $this->setGroupedValue('BatteryChargePower', max(0, $battery));
        $this->setGroupedValue('BatteryDischargePower', max(0, -$battery));
        $this->setGroupedValue('GridImport', max(0, $grid));
        $this->setGroupedValue('GridExport', max(0, -$grid));
        $this->setGroupedValue('SOC', $registers[15]);
        $this->setGroupedValue('Emergency', $registers[16]);
        $this->setGroupedValue('EmergencyState', match ($registers[16]) {
            0 => 'Nicht unterstützt',
            1 => 'Aktiv',
            2 => 'Nicht aktiv',
            3 => 'Nicht verfügbar',
            4 => 'Motorschalter außerhalb Normalstellung',
            default => 'Unbekannter Status (' . $registers[16] . ')'
        });
        $efficiency = $registers[14];
        $this->setGroupedValue('EfficiencyRaw', sprintf('0x%04X', $efficiency));
        $autarky = $efficiency >> 8;
        $selfConsumption = $efficiency & 0xFF;
        $valid = $autarky <= 100 && $selfConsumption <= 100;
        if ($valid) {
            $this->setGroupedValue('OneAutarky', $autarky);
            $this->setGroupedValue('OneSelfConsumption', $selfConsumption);
        }
        $this->setGroupedValue('OneEfficiencyValid', $valid);
        $ems = $registers[17];
        $this->setGroupedValue('EMS', $ems);
        $flags = [];
        foreach (['Laden gesperrt', 'Entladen gesperrt', 'Notstrom möglich',
            'Wetterbasiertes Laden', 'Einspeisung abgeregelt', 'Ladesperrzeit',
            'Entladesperrzeit'] as $bit => $label) {
            $flags[$label] = ($ems & (1 << $bit)) !== 0;
        }
        $this->publishJson('EMSFlags', $flags);
    }

    private function readDetails($socket, int $unit): void
    {
        $transaction = 3;
        $notes = [];
        $connectionUsable = true;
        $read = function (string $label, int $start, int $count) use ($socket, $unit, &$transaction, &$notes, &$connectionUsable): ?array {
            if (!$connectionUsable) {
                $notes[$label] = 'Nach Verbindungsfehler übersprungen.';
                return null;
            }
            // The manufacturer recommends at most ten requests per second.
            usleep(110000);
            try {
                return $this->readRegisters($socket, $unit, $transaction++, $start, $count);
            } catch (RuntimeException $error) {
                $notes[$label] = $error->getMessage();
                $connectionUsable = str_starts_with($error->getMessage(), 'Modbus-Ausnahme ');
                return null;
            }
        };

        $auto = $this->ReadPropertyBoolean('AutoFarmDevices');
        $wallboxes = [];
        $availableSlots = [];
        $summary = [];
        $wallboxStatus = 'Abfrage deaktiviert';
        if ($auto || $this->ReadPropertyBoolean('ReadWallbox')) {
            $values = $read('Wallbox-Slots', 40088, 8);
            $wallboxStatus = $values === null ? 'Wallbox-Abfrage fehlgeschlagen' : 'Kein Wallbox-Slot verfügbar';
            foreach ($values ?? [] as $slot => $state) {
                $available = ($state & 1) !== 0;
                $details = ['Verfügbar' => $available, 'Rohwert' => $state];
                if ($available) {
                    $availableSlots[] = $slot;
                    $details += [
                        'Betriebsart' => ($state & 2) !== 0 ? 'Solarbetrieb' : 'Mischbetrieb',
                        'Laden freigegeben' => ($state & 4) === 0,
                        'Auto lädt' => ($state & 8) !== 0,
                        'Fahrzeug angeschlossen' => ($state & 32) !== 0,
                        'Aktive Phasen laut efy-Status' => ($state & 4096) !== 0 ? 1 : 3
                    ];
                    $charging = $details['Auto lädt'] ? 'lädt' : 'lädt nicht';
                    $summary[] = "Slot $slot: " . $details['Betriebsart'] . ", $charging";
                }
                $wallboxes['Slot ' . $slot] = $details;
            }
        }
        $this->setGroupedValue('Wallboxes', $summary === [] ? $wallboxStatus : implode(' | ', $summary));
        $this->publishJson('WallboxDetails', $wallboxes);
        $slot = $this->ReadPropertyInteger('WallboxSlot');
        if ($slot === -1 && count($availableSlots) === 1) {
            $slot = $availableSlots[0];
        } elseif ($slot === -1 && count($availableSlots) > 1) {
            $notes['Wallbox-Zuordnung'] = 'Mehrere Wallboxen gefunden. Bitte den gewünschten Slot wählen.';
        }
        $selectedWallbox = $wallboxes['Slot ' . $slot] ?? [];
        $wallboxAvailable = $selectedWallbox['Verfügbar'] ?? false;
        if ($wallboxAvailable) {
            $this->setGroupedValue('WallboxSolarMode', $selectedWallbox['Betriebsart'] === 'Solarbetrieb');
            $this->setGroupedValue('WallboxChargeAllowed', $selectedWallbox['Laden freigegeben']);
            $this->setGroupedValue('WallboxCharging', $selectedWallbox['Auto lädt']);
            $this->setGroupedValue('WallboxVehicleConnected', $selectedWallbox['Fahrzeug angeschlossen']);
            $this->setGroupedValue('WallboxPhaseMode', $selectedWallbox['Aktive Phasen laut efy-Status']);
        }
        $this->setGroupedValue('WallboxAvailable', $wallboxAvailable);

        $identity = $read('Geräteinformationen', 40002, 66);
        if ($identity !== null) {
            foreach (['Manufacturer', 'Model', 'Serial', 'Firmware'] as $index => $ident) {
                $fields = array_slice($identity, 2 + 16 * $index, 16);
                $this->setGroupedValue($ident, $this->decodeText($fields));
            }
            $this->setGroupedValue('ModbusVersion', sprintf('%d.%d', $identity[0] >> 8, $identity[0] & 255));
            $this->setGroupedValue('RegisterCount', $identity[1]);
        }

        $strings = $read('PV-Strings', 40096, 9);
        $stringData = [];
        if ($strings !== null) {
            for ($i = 0; $i < 2; $i++) {
                $stringData['String ' . ($i + 1)] = [
                    'Spannung (V)' => $strings[$i],
                    'Strom (A)' => $strings[3 + $i] / 100,
                    'Leistung (W)' => $strings[6 + $i]
                ];
            }
        }
        $this->publishJson('DCStrings', $stringData);

        $meters = $read('Leistungsmesser und SG Ready', 40105, 33);
        $meter32 = $read('32-Bit-Phasenleistung', 40138, 48);
        $meterData = [];
        $this->setGroupedValue('SGReadyValid', $meters !== null);
        if ($meters !== null) {
            $this->setGroupedValue('SGReadyState', $meters[32]);
            $meterData['SG Ready (Rohwert)'] = $meters[32];
            for ($i = 0; $i < 8; $i++) {
                $entry = ['Typ' => $meters[4 * $i]];
                for ($phase = 0; $phase < 3; $phase++) {
                    $entry['L' . ($phase + 1) . ' (W)'] = $meter32 === null
                        ? $this->signed16($meters[4 * $i + 1 + $phase])
                        : $this->signed32($meter32, 6 * $i + 2 * $phase);
                }
                $meterData['Index ' . $i] = $entry;
            }
        }
        $this->publishJson('PowerMeters', $meterData);
        $farm = $this->ReadPropertyInteger('FarmConsumptionMeter');
        $heatPump = $this->ReadPropertyInteger('HeatPumpMeter');
        if ($auto && $meters !== null) {
            $farm = $this->resolveMeter($meters, $farm, 5, 'Farmzähler', $notes);
            $heatPump = $this->resolveMeter($meters, $heatPump, 4, 'Wärmepumpenzähler', $notes);
        }
        $this->publishJson('DetectedFarmDevices', [
            'Farmzähler' => $farm >= 0 ? $farm : null,
            'Wärmepumpenzähler' => $heatPump >= 0 ? $heatPump : null,
            'Wallbox-Slot' => $wallboxAvailable ? $slot : null
        ]);
        if ($farm !== -1 || $heatPump !== -1) {
            $this->publishFarmConsumption($meterData, $farm, $heatPump, $notes);
        }

        $battery = $read('Batteriegrenzen und Speicherenergie', 40202, 8);
        $batteryData = [];
        if ($battery !== null) {
            foreach (['Max. Ladeleistung (W)', 'Max. Entladeleistung (W)',
                'Verbleibende Energie (Wh)', 'Max. speicherbare Energie (Wh)'] as $index => $label) {
                $batteryData[$label] = $this->signed32($battery, 2 * $index);
            }
        }
        $this->publishJson('BatteryDetails', $batteryData);
        $batteryData = $this->batteryWithFallback($batteryData, $this->ReadPropertyInteger('CapacityFallbackWh'), $this->getGroupedValue('SOC'));
        $hasBatteryEnergy = $this->hasBatteryEnergy($batteryData);
        if (($batteryData['Max. speicherbare Energie (Wh)'] ?? 0) > 0) {
            $this->setGroupedValue('BatteryCapacity', $batteryData['Max. speicherbare Energie (Wh)'] / 1000);
        }
        if ($hasBatteryEnergy) {
            $this->setGroupedValue('BatteryEnergy', $batteryData['Verbleibende Energie (Wh)'] / 1000);
        } elseif ($battery !== null) {
            $notes['Speicherenergie'] = 'Der one liefert keine plausible Speicherkapazität oder Restenergie.';
        }
        if ($batteryData['Geschätzt'] ?? false) {
            $notes['Speicherenergie (Quelle)'] = 'Restenergie aus eingetragener Kapazität und Geräte-SOC geschätzt.';
        }
        $this->setGroupedValue('BatteryEnergyValid', $hasBatteryEnergy);
        $this->publishFarmBattery($batteryData, $notes);

        $remote = [];
        foreach ([40186 => 'Zuletzt geschriebene Einspeisegrenze', 40188 => 'Leistungsabgabe bereit',
            40194 => 'Wirkleistungs-Timeout (s)', 40196 => 'Blindleistungs-Timeout (s)',
            40199 => 'Batterie-Fernsteuerung deaktivieren (Rohwert)',
            40200 => 'Wechselrichter-Modusvorgabe', 40201 => 'Wechselrichter-Betriebsmodus'] as $address => $label) {
            $values = $read($label, $address, $address === 40186 ? 2 : 1);
            if ($values !== null) {
                $remote[$label] = $address === 40186 ? $this->signed32($values, 0) : $values[0];
            }
        }
        $this->publishJson('RemoteStatus', $remote);

        $inverters = [];
        for ($index = 0; $index < 8; $index++) {
            $label = 'Wechselrichter ' . $index;
            $values = $read($label, 41000 + 34 * $index, 34);
            if ($values === null) {
                continue;
            }
            $entry = ['Frequenz L1 (Hz)' => $this->signed16($values[24]) / 100];
            for ($i = 0; $i < 3; $i++) {
                $phase = 'L' . ($i + 1);
                $entry[$phase] = [
                    'Scheinleistung (VA)' => $this->signed32($values, 2 * $i),
                    'Wirkleistung (W)' => $this->signed32($values, 6 + 2 * $i),
                    'Blindleistung (var)' => $this->signed32($values, 12 + 2 * $i),
                    'AC-Spannung (V)' => $this->signed16($values[18 + $i]) / 10,
                    'AC-Strom (A)' => $this->signed16($values[21 + $i]) / 100
                ];
                if ($i < 2) {
                    $entry[$phase]['DC-Leistung (W)'] = $this->signed16($values[25 + $i]);
                    $entry[$phase]['DC-Spannung (V)'] = $this->signed16($values[28 + $i]) / 10;
                    $entry[$phase]['DC-Strom (A)'] = $this->signed16($values[31 + $i]) / 100;
                }
            }
            $inverters[$label] = $entry;
        }
        $this->publishJson('Inverters', $inverters);

        $this->publishJson('ReadNotes', $notes);
    }

    private function publishFarmBattery(array $local, array &$notes): void
    {
        $this->setGroupedValue('FarmPowerValid', false);
        $this->setGroupedValue('FarmBatteryValid', false);
        $secondHost = trim($this->ReadPropertyString('SecondHost'));
        if ($secondHost === '') {
            $snapshot = $this->readLegacyPeer($notes);
        } else {
            try {
                $snapshot = $this->readSecondOne($secondHost, $notes);
            } catch (RuntimeException $error) {
                $notes['Zweiter one'] = $error->getMessage();
                $snapshot = null;
            }
        }
        $this->setGroupedValue('SecondOneValid', $snapshot !== null);
        if ($snapshot === null) {
            return;
        }
        [$pv, $power, $soc, $remote] = $snapshot;
        $this->setGroupedValue('SecondPV', $pv);
        $this->setGroupedValue('SecondBattery', $power);
        $this->setGroupedValue('SecondSOC', $soc);
        $this->setGroupedValue('SecondCapacity', ($remote['Max. speicherbare Energie (Wh)'] ?? 0) / 1000);
        $this->publishFarmPower($pv, $power);

        if (($local['Geschätzt'] ?? false) || ($remote['Geschätzt'] ?? false)) {
            $notes['Farm-Batterie (Quelle)'] = 'Restenergie aus eingetragener Kapazität und Geräte-SOC geschätzt.';
        }
        $localCapacity = $local['Max. speicherbare Energie (Wh)'] ?? 0;
        $remoteCapacity = $remote['Max. speicherbare Energie (Wh)'] ?? 0;
        $capacity = $localCapacity + $remoteCapacity;
        if ($localCapacity > 0 && $remoteCapacity > 0) {
            $this->setGroupedValue('FarmBatteryCapacity', $capacity / 1000);
        }
        foreach ([$local, $remote] as $storage) {
            if (!$this->hasBatteryEnergy($storage)) {
                $this->setGroupedValue('FarmBatteryValid', false);
                $notes['Farm-Batterie'] = 'Aktuelle, plausible Speicherenergie beider one erforderlich.';
                return;
            }
        }

        $energy = $local['Verbleibende Energie (Wh)'] + $remote['Verbleibende Energie (Wh)'];
        $this->setGroupedValue('FarmBatteryEnergy', $energy / 1000);
        $this->setGroupedValue('FarmBatterySOC', round(100 * $energy / $capacity, 1));
        $this->setGroupedValue('FarmBatteryValid', true);
    }

    private function readLegacyPeer(array &$notes): ?array
    {
        $peer = $this->ReadPropertyInteger('SecondOne');
        if ($peer === 0) {
            return null;
        }
        $moduleId = '{AAEF96D2-6F24-4FF9-9C9C-C7FECE2C1559}';
        if ($peer === $this->InstanceID || !IPS_InstanceExists($peer)
            || IPS_GetInstance($peer)['ModuleInfo']['ModuleID'] !== $moduleId) {
            $this->setGroupedValue('FarmBatteryValid', false);
            $notes['Farm-Batterie'] = 'Die Instanz des zweiten one auswählen.';
            return null;
        }
        $pvId = $this->findGroupedVariable('PV', $peer);
        $powerId = $this->findGroupedVariable('Battery', $peer);
        $statusId = $this->findGroupedVariable('LastSuccess', $peer);
        $detailsId = $this->findGroupedVariable('BatteryDetails', $peer);
        $peerInterval = max(5, min(3600, IPS_GetProperty($peer, 'Interval')));
        $maxAge = 2 * $peerInterval + 10;
        if ($pvId <= 0 || $powerId <= 0 || $statusId <= 0
            || IPS_GetInstance($peer)['InstanceStatus'] !== 102
            || time() - GetValue($statusId) > $maxAge) {
            $this->setGroupedValue('FarmBatteryValid', false);
            $notes['Farm-Leistung'] = 'Aktuelle Leistungsdaten des zweiten one erforderlich.';
            return null;
        }


        $remote = [];
        if ($detailsId > 0 && time() - IPS_GetVariable($detailsId)['VariableUpdated'] <= $maxAge) {
            $remote = json_decode(GetValue($detailsId), true) ?? [];
        }

        $socId = $this->findGroupedVariable('SOC', $peer);
        if ($socId > 0) {
            $remote = $this->batteryWithFallback($remote, IPS_GetProperty($peer, 'CapacityFallbackWh'), GetValue($socId));
        }
        return [GetValue($pvId), GetValue($powerId), $socId > 0 ? GetValue($socId) : 0, $remote];
    }

    private function readSecondOne(string $host, array &$notes): array
    {
        $master = trim($this->ReadPropertyString('Host'));
        if (!filter_var($host, FILTER_VALIDATE_IP) || inet_pton($host) === inet_pton($master)) {
            throw new RuntimeException('Eine andere gültige IP-Adresse für den zweiten one eingeben.');
        }
        $port = $this->ReadPropertyInteger('Port');
        $unit = $this->ReadPropertyInteger('Unit');
        $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $socket = @stream_socket_client('tcp://' . $address . ':' . $port, $errorCode, $errorText, 2.0);
        if ($socket === false) {
            throw new RuntimeException('TCP-Verbindung fehlgeschlagen (Fehler ' . $errorCode . ').');
        }

        try {
            stream_set_timeout($socket, 2);
            if ($this->readRegisters($socket, $unit, 1, 40001, 1)[0] !== 0xE3DC) {
                throw new RuntimeException('E3/DC Simple Mode wurde am zweiten one nicht erkannt.');
            }
            usleep(110000);
            $core = $this->readRegisters($socket, $unit, 2, 40068, 18);
            $energy = [];
            try {
                usleep(110000);
                $registers = $this->readRegisters($socket, $unit, 3, 40206, 4);
                $energy = [
                    'Verbleibende Energie (Wh)' => $this->signed32($registers, 0),
                    'Max. speicherbare Energie (Wh)' => $this->signed32($registers, 2)
                ];
            } catch (RuntimeException $error) {
                $notes['Speicherenergie des zweiten one'] = $error->getMessage();
            }
            $soc = $core[15];
            $energy = $this->batteryWithFallback($energy, $this->ReadPropertyInteger('SecondCapacityFallbackWh'), $soc);
            return [$this->signed32($core, 0), $this->signed32($core, 2), $soc, $energy];
        } finally {
            fclose($socket);
        }
    }

    private function publishFarmPower(int $peerPV, int $peerBattery): void
    {
        $pv = $this->getGroupedValue('PV') + $peerPV;
        $localBattery = $this->getGroupedValue('Battery');
        $battery = $localBattery + $peerBattery;
        $this->setGroupedValue('FarmBatteryChargePower', max(0, $localBattery) + max(0, $peerBattery));
        $this->setGroupedValue('FarmBatteryDischargePower', max(0, -$localBattery) + max(0, -$peerBattery));
        $wallbox = $this->getGroupedValue('WallboxPower');
        $this->setGroupedValue('FarmPV', $pv);
        $this->setGroupedValue('FarmBatteryPower', $battery);
        $this->setGroupedValue('FarmWallboxPower', $wallbox);
        if (!$this->getGroupedValue('FarmConsumptionValid')) {
            return;
        }

        // The portal displays wallbox demand separately from the other consumers.
        $demand = $this->getGroupedValue('FarmConsumption') + $wallbox;
        $grid = $demand - $pv - $this->getGroupedValue('External') + $battery;
        $import = max(0, $grid);
        $this->setGroupedValue('FarmGrid', $grid);
        $this->setGroupedValue('FarmGridImport', $import);
        $this->setGroupedValue('FarmGridExport', max(0, -$grid));
        $this->setGroupedValue('FarmAutarky', $demand > 0 ? round(100 * max(0, $demand - $import) / $demand, 1) : 0.0);
        $this->setGroupedValue('FarmPowerValid', true);
    }

    private function publishDaily(bool $valid): void
    {
        $forecastSource = $this->ReadPropertyInteger('ForecastSource');
        $ownForecast = $this->groupedVariableID('DayForecast');
        $forecast = $forecastSource > 0 && $forecastSource !== $ownForecast && IPS_VariableExists($forecastSource)
            ? GetValue($forecastSource) : null;
        $forecastAvailable = (is_int($forecast) || is_float($forecast)) && is_finite((float) $forecast) && $forecast >= 0;
        if ($forecastAvailable) {
            $this->setGroupedValue('DayForecast', (float) $forecast);
        }
        $this->setGroupedValue('DayForecastAvailable', $forecastAvailable);

        if (trim($this->ReadPropertyString('SecondHost')) === '' && $this->ReadPropertyInteger('SecondOne') === 0) {
            return;
        }

        $now = time();
        $midnight = strtotime('today', $now);
        $state = json_decode($this->ReadAttributeString('DailyTotals'), true) ?? [];
        $previousTime = $state['time'] ?? 0;
        $previousPowers = $state['powers'] ?? [];
        $maxGap = 2 * max(5, min(3600, $this->ReadPropertyInteger('Interval'))) + 10;
        $canIntegrate = $valid && $previousPowers !== [] && $now > $previousTime && $now - $previousTime <= $maxGap;
        if (($state['midnight'] ?? 0) !== $midnight) {
            $state = ['midnight' => $midnight, 'start' => $canIntegrate ? $midnight : 0, 'totals' => []];
        }
        $powers = [];
        if ($valid) {
            $battery = $this->getGroupedValue('FarmBatteryPower');
            $powers = [
                'DayPV' => $this->getGroupedValue('FarmPV'),
                'DayBatteryCharge' => max(0, $battery),
                'DayBatteryDischarge' => max(0, -$battery),
                'DayGridImport' => $this->getGroupedValue('FarmGridImport'),
                'DayGridExport' => $this->getGroupedValue('FarmGridExport'),
                'DayHouse' => $this->getGroupedValue('FarmHouseConsumption'),
                'DayHeatPump' => $this->getGroupedValue('HeatPumpConsumption'),
                'DayWallbox' => $this->getGroupedValue('FarmWallboxPower'),
                'DayConsumption' => $this->getGroupedValue('FarmConsumption')
            ];
            if ($state['start'] === 0) {
                $state['start'] = $now;
            }
        }

        foreach (self::DAILY as $ident => $label) {
            $total = $state['totals'][$ident] ?? 0.0;
            if ($canIntegrate) {
                $seconds = $now - max($previousTime, $midnight);
                $startPower = $previousPowers[$ident];
                if ($previousTime < $midnight) {
                    $startPower += ($powers[$ident] - $startPower) * ($midnight - $previousTime) / ($now - $previousTime);
                }
                $total += (max(0, $startPower) + max(0, $powers[$ident])) * $seconds / 7200000;
            }
            $state['totals'][$ident] = $total;
            $this->setGroupedValue($ident, round($total, 3));
        }
        $state['time'] = $now;
        $state['powers'] = $powers;
        $this->WriteAttributeString('DailyTotals', json_encode($state));
        $this->setGroupedValue('DayStart', $state['start']);
    }

    private function batteryWithFallback(array $values, int $configuredCapacity, int $soc): array
    {
        if (!$this->hasBatteryEnergy($values) && $configuredCapacity > 0 && $soc >= 0 && $soc <= 100) {
            $values['Max. speicherbare Energie (Wh)'] = $configuredCapacity;
            $values['Verbleibende Energie (Wh)'] = (int) round($configuredCapacity * $soc / 100);
            $values['Geschätzt'] = true;
        }
        return $values;
    }

    private function hasBatteryEnergy(array $values): bool
    {
        $capacity = $values['Max. speicherbare Energie (Wh)'] ?? 0;
        $energy = $values['Verbleibende Energie (Wh)'] ?? -1;
        return $capacity > 0 && $energy >= 0 && $energy <= $capacity;
    }

    private function resolveMeter(array $registers, int $index, int $type, string $name, array &$notes): int
    {
        if ($index !== -1) {
            return $index;
        }
        $matches = [];
        for ($candidate = 0; $candidate < 8; $candidate++) {
            if ($registers[4 * $candidate] === $type) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches !== []) {
            $notes[$name] = 'Mehrere Zähler dieses Typs gefunden. Bitte den gewünschten Index wählen.';
        }
        return -1;
    }

    private function publishFarmConsumption(array $meters, int $farm, int $heatPump, array &$notes): void
    {
        $farmMeter = $meters['Index ' . $farm] ?? [];
        $heatPumpMeter = $meters['Index ' . $heatPump] ?? [];
        if (($farmMeter['Typ'] ?? null) !== 5 || ($heatPumpMeter['Typ'] ?? null) !== 4) {
            $notes['Farm-Verbrauch'] = 'Farmzähler (Typ 5) und Wärmepumpenzähler (Typ 4) zuordnen.';
            return;
        }

        $total = $farmMeter['L1 (W)'] + $farmMeter['L2 (W)'] + $farmMeter['L3 (W)'];
        $heat = $heatPumpMeter['L1 (W)'] + $heatPumpMeter['L2 (W)'] + $heatPumpMeter['L3 (W)'];
        $this->setGroupedValue('FarmConsumption', $total);
        $this->setGroupedValue('HeatPumpConsumption', $heat);
        $this->setGroupedValue('FarmHouseConsumption', $total - $heat);
        $this->setGroupedValue('FarmConsumptionValid', true);
    }

    private function decodeText(array $registers): string
    {
        return trim(explode("\0", pack('n*', ...$registers), 2)[0]);
    }

    private function signed16(int $value): int
    {
        return $value >= 32768 ? $value - 65536 : $value;
    }

    private function signed32(array $values, int $index): int
    {
        return $this->signed16($values[$index + 1]) * 65536 + $values[$index];
    }

    private function publishJson(string $ident, array $data): void
    {
        $this->setGroupedValue($ident, json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function readRegisters($socket, int $unit, int $transaction, int $register, int $count): array
    {
        $request = pack('nnnCCnn', $transaction, 0, 6, $unit, 3, $register - 1, $count);
        if (@fwrite($socket, $request) !== strlen($request)) {
            throw new RuntimeException('Modbus-Anfrage konnte nicht gesendet werden.');
        }
        $header = unpack('ntransaction/nprotocol/nlength/Cunit', $this->readBytes($socket, 7));
        if ($header['transaction'] !== $transaction || $header['protocol'] !== 0
            || $header['unit'] !== $unit || $header['length'] < 3 || $header['length'] > 254) {
            throw new RuntimeException('Ungültiger Modbus-Antwortkopf.');
        }
        $body = $this->readBytes($socket, $header['length'] - 1);
        if (ord($body[0]) === 0x83) {
            throw new RuntimeException('Modbus-Ausnahme ' . ord($body[1]) . ' bei Register ' . $register . '.');
        }
        if (ord($body[0]) !== 3 || ord($body[1]) !== $count * 2 || strlen($body) !== 2 + $count * 2) {
            throw new RuntimeException('Unerwartete Modbus-Registerantwort.');
        }
        return array_values(unpack('n*', substr($body, 2)));
    }

    private function readBytes($socket, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $part = @fread($socket, $length - strlen($data));
            if ($part === false || $part === '') {
                throw new RuntimeException('Modbus-Antwort unvollständig oder Zeitlimit erreicht.');
            }
            $data .= $part;
        }
        return $data;
    }
}
