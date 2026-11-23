<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FarmRscp.php';

final class E3DCInstanceUnavailable extends ErrorException
{
}

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

    private const RSCP_ENERGY = [
        'DB_BAT_POWER_IN' => 'Batterie geladen',
        'DB_BAT_POWER_OUT' => 'Batterie entladen',
        'DB_GRID_POWER_IN' => 'Netzbezug',
        'DB_GRID_POWER_OUT' => 'Netzeinspeisung',
        'DB_CONSUMPTION' => 'Verbrauch',
        'DB_PRODUCTION_POWER' => 'Produktion laut DB_PRODUCTION_POWER',
        'DB_PM_0_POWER' => 'Zähler 0',
        'DB_PM_1_POWER' => 'Zähler 1'
    ];

    private const DAILY = [
        'DayPV' => 'Solarproduktion heute (kWh)',
        'DayBatteryCharge' => 'Batterie geladen heute (kWh)',
        'DayBatteryDischarge' => 'Batterie entladen heute (kWh)',
        'DayGridImport' => 'Netzbezug heute (berechnet, kWh)',
        'DayGridExport' => 'Netzeinspeisung heute (berechnet, kWh)',
        'DayHouse' => 'Hausverbrauch heute (kWh)',
        'DayHeatPump' => 'Zusatzverbrauch heute (kWh)',
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
        $this->RegisterPropertyString('OperatingMode', 'auto');
        $this->RegisterPropertyString('ExternalSystems', '[]');
        $this->RegisterPropertyBoolean('ReadRscpSolar', false);
        $this->RegisterPropertyInteger('FarmRscpPort', 5034);
        $this->RegisterPropertyString('FarmRscpUser', 'local.user');
        $this->RegisterPropertyString('FarmRscpPassword', '');
        $this->RegisterPropertyString('FarmRscpKey', '');
        $this->RegisterAttributeInteger('RscpSolarReadAt', 0);
        $this->RegisterTimer('RscpSolarTimer', 0, 'if (IPS_InstanceExists($_IPS["TARGET"])) { E3DCF_PollRscpSolar($_IPS["TARGET"]); }');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('Unit', 1);
        $this->RegisterPropertyInteger('Interval', 15);
        $this->RegisterPropertyBoolean('AutoFarmDevices', true);
        $this->RegisterPropertyBoolean('ReadWallbox', true);
        $this->RegisterPropertyString('AccessoryNames', '{}');
        $this->RegisterPropertyString('AccessoryOptions', '[]');
        $this->RegisterPropertyInteger('BackupReservePercent', 0);
        $this->RegisterPropertyBoolean('AllowWallboxControl', false);
        $this->RegisterPropertyBoolean('AllowBatteryControl', false);
        $this->RegisterPropertyInteger('WallboxSlot', -1);
        $this->RegisterPropertyInteger('FarmConsumptionMeter', -1);
        $this->RegisterPropertyBoolean('FarmMeterIsConsumption', false);
        $this->RegisterPropertyInteger('HeatPumpMeter', -1);
        $this->RegisterPropertyString('AdditionalConsumerName', '');
        $this->RegisterPropertyInteger('SecondOne', 0);
        $this->RegisterPropertyString('SecondHost', '');
        $this->RegisterPropertyString('LanNetwork', '');
        $this->RegisterPropertyInteger('SecondCapacityFallbackWh', 0);
        $this->RegisterPropertyInteger('ForecastSource', 0);
        $this->RegisterPropertyInteger('CapacityFallbackWh', 0);
        $this->RegisterAttributeString('DailyTotals', '{}');
        $this->RegisterTimer('PollTimer', 0, 'if (IPS_InstanceExists($_IPS["TARGET"])) { E3DCF_Poll($_IPS["TARGET"]); }');
        $this->RegisterTimer('InitialPollTimer', 0, 'if (IPS_InstanceExists($_IPS["TARGET"])) { E3DCF_RunInitialPoll($_IPS["TARGET"]); }');

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
        $this->maintainGroupedVariable('OperatingModeStatus', 'Betriebsart', '', VARIABLETYPE_STRING);
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
        $this->maintainGroupedVariable('AdditionalConsumerValid', 'Zusatzverbrauch aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('HeatPumpConsumption', 'Zusatzverbrauch (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmHouseConsumption', 'Hausverbrauch ohne Zusatzverbrauch (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmMeterPower', 'Farmzähler – vorzeichenbehaftete Leistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('RscpDayEnergy', 'RSCP-Tagessummen (Wh)', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('DayPVEstimate', 'Solarproduktion heute – Modbus-Summe (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('DayPVSource', 'Datenquelle Solarproduktion', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('RscpSolarStatus', 'RSCP-Tagesertrag – Status', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('DayBalanceStart', 'Verbrauchs- und Netzbilanz erfasst seit', '~UnixTimestamp', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmConsumptionValid', 'Farm-Verbrauchswerte aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('FarmBatterySOC', 'Farm-Batterieladezustand (%)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('FarmBatteryPower', 'Farm-Batterieleistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmBatteryValid', 'Farm-Batteriewerte aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('BatteryCapacity', 'Speicherkapazität des one (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('BatteryEnergy', 'Verbleibende Speicherenergie des one (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('BatteryEnergyAboveReserve', 'Speicherenergie oberhalb der Reserve (kWh)', '', VARIABLETYPE_FLOAT);
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
        $this->updateOperatingLayout();
        $this->setGroupedValue('AdditionalConsumerValid', false);
        $this->updateAdditionalConsumer(false);
        $this->publishExternalSystems();
        $this->SetTimerInterval('InitialPollTimer', 0);
        $this->SetTimerInterval('PollTimer', 0);
        $this->SetTimerInterval('RscpSolarTimer', 0);
        $this->WriteAttributeInteger('RscpSolarReadAt', 0);
        $this->setGroupedValue('RscpSolarStatus', 'RSCP-Tagesertrag deaktiviert');
        $this->publishSolarFallback();
        $this->setGroupedValue('FarmBatteryValid', false);
        $this->setGroupedValue('BatteryEnergyValid', false);
        $this->setGroupedValue('OneEfficiencyValid', false);
        $this->setGroupedValue('FarmPowerValid', false);
        $this->setGroupedValue('SecondOneValid', false);
        $this->setGroupedValue('FarmConsumptionValid', false);
        $this->setGroupedValue('SGReadyValid', false);
        $this->setGroupedValue('WallboxAvailable', false);
        $this->publishAccessories([]);
        $this->publishRscpEnergy([]);
        if (!$this->ReadPropertyBoolean('Active')) {
            $daily = $this->decodeJsonArray(@$this->ReadAttributeString('DailyTotals')) ?? [];
            $daily['powers'] = [];
            $this->WriteAttributeString('DailyTotals', json_encode($daily));
            $this->setGroupedValue('DayForecastAvailable', false);
            $this->setGroupedValue('DataStatus', 'Instanz deaktiviert');
            $this->SetStatus(104);
            return;
        }
        if (!filter_var($this->ReadPropertyString('Host'), FILTER_VALIDATE_IP)) {
            $this->setGroupedValue('DataStatus', 'IP-Adresse des Hauptgeräts fehlt');
            $this->SetStatus(104);
            return;
        }
        $this->SetTimerInterval('PollTimer', max(5, min(3600, $this->ReadPropertyInteger('Interval'))) * 1000);
        $this->SetTimerInterval('InitialPollTimer', 250);
        if ($this->ReadPropertyBoolean('ReadRscpSolar')) {
            $this->setGroupedValue('RscpSolarStatus', 'RSCP-Abfrage ausstehend');
            $this->SetTimerInterval('RscpSolarTimer', 60000);
        }
    }

    private const GROUPS = [
            'Farm' => ['Farm – Gesamtanlage', [
                'FarmPV', 'FarmGrid', 'FarmGridImport', 'FarmGridExport', 'FarmConsumption',
                'FarmHouseConsumption', 'HeatPumpConsumption', 'AdditionalConsumerValid', 'FarmAutarky',
                'FarmWallboxPower', 'FarmPowerValid', 'FarmConsumptionValid', 'FarmMeterPower',
                'FarmBatterySOC', 'FarmBatteryPower', 'FarmBatteryChargePower', 'FarmBatteryDischargePower', 'FarmBatteryCapacity', 'FarmBatteryEnergy', 'FarmBatteryValid'
            ]],
            'Storage' => ['Batterie und Speicher', [
                'SOC', 'Battery',
                'BatteryChargePower', 'BatteryDischargePower', 'BatteryCapacity', 'BatteryEnergy',
                'BatteryEnergyValid', 'BatteryEnergyAboveReserve', 'BatteryDetails'
            ]],
            'Wallbox' => ['Wallbox – Übersicht', [
                'WallboxAvailable', 'WallboxPower', 'WallboxSolar', 'WallboxCharging',
                'WallboxChargeAllowed', 'WallboxSolarMode', 'WallboxPhaseMode',
                'WallboxVehicleConnected', 'Wallboxes', 'WallboxDetails'
            ]],
            'Daily' => ['Tageswerte und Prognose', [
                'DayForecast', 'DayForecastAvailable', 'DayStart', 'DayBalanceStart', 'DayPVEstimate', 'DayPVSource', 'RscpSolarStatus', 'RscpDayEnergy'
            ]],
            'Master' => ['Farmmaster', [
                'PV', 'House', 'Grid', 'GridImport', 'GridExport', 'External',
                'OneAutarky', 'OneSelfConsumption', 'OneEfficiencyValid',
                'Manufacturer', 'Model', 'Serial', 'Firmware', 'ModbusVersion'
            ]],
            'Second' => ['Weiteres Farmgerät', [
                'SecondOneValid', 'SecondPV', 'SecondBattery', 'SecondSOC', 'SecondCapacity'
            ]],
            'Diagnostics' => ['Diagnose und Status', [
                'OperatingModeStatus', 'DataStatus', 'LastSuccess', 'LanSetupStatus', 'ReadNotes', 'DetectedFarmDevices', 'RegisterCount',
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

    private function shouldCreateVariable(string $ident, string $groupIdent): bool
    {
        if (in_array($ident, ['LanSetupStatus', 'DataStatus', 'OperatingModeStatus'], true)) {
            return true;
        }
        if (!filter_var($this->ReadPropertyString('Host'), FILTER_VALIDATE_IP)) {
            return false;
        }
        return $this->isFarmMode() || (!in_array($groupIdent, ['E3DCGroupFarm', 'E3DCGroupSecond'], true) && $ident !== 'DayHeatPump');
    }

    private function maintainGroupedVariable(string $ident, string $name, string $profile, int $type): void
    {
        [$groupIdent, $groupName, $position] = $this->variableLocation($ident);
        if (!$this->shouldCreateVariable($ident, $groupIdent)) {
            return;
        }
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
        if ($original === false && $groupIdent === 'E3DCGroupFarm') {
            $storage = @IPS_GetObjectIDByIdent('E3DCGroupStorage', $this->InstanceID);
            if ($storage !== false && IPS_CategoryExists($storage)) {
                $previous = @IPS_GetObjectIDByIdent($ident, $storage);
                if ($previous !== false && IPS_VariableExists($previous)) {
                    $original = $previous;
                }
            }
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
        $variable = $this->findGroupedVariable($ident, $this->InstanceID);
        if ($variable === false && !$this->shouldCreateVariable($ident, $this->variableLocation($ident)[0])) {
            return;
        }
        SetValue($variable !== false ? $variable : $this->groupedVariableID($ident), $value);
    }

    private function getGroupedValue(string $ident): mixed
    {
        return GetValue($this->groupedVariableID($ident));
    }

    private function hasFarmPeer(): bool
    {
        return trim($this->ReadPropertyString('SecondHost')) !== '' || $this->ReadPropertyInteger('SecondOne') !== 0;
    }

    private function isFarmMode(): bool
    {
        return match ($this->ReadPropertyString('OperatingMode')) {
            'farm' => true,
            'standalone' => false,
            default => $this->hasFarmPeer()
        };
    }

    private function updateOperatingLayout(): void
    {
        $farm = $this->isFarmMode();
        $configured = filter_var($this->ReadPropertyString('Host'), FILTER_VALIDATE_IP) !== false;
        $status = $configured ? ($farm ? 'Farm' : 'Einzelgerät') : 'Einrichtung ausstehend';
        if ($configured && $farm && !$this->hasFarmPeer()) {
            $status .= ' – weiteres Farmgerät fehlt';
        }
        $this->setGroupedValue('OperatingModeStatus', $status);
        foreach ([
            'Farm' => ['Farm – Gesamtanlage', !$farm, 10],
            'Master' => [$farm ? 'Farmmaster – Gerätedaten' : 'Einzelgerät – Live und Gerätedaten', false, 20],
            'Storage' => ['Batterie und Speicher des Hauptgeräts', false, 30],
            'Second' => ['Weiteres Farmgerät', !$farm, 40],
            'Wallbox' => ['Wallbox – Übersicht', false, 50],
            'Daily' => ['Tageswerte und Prognose', false, 60],
            'Diagnostics' => ['Diagnose und Status', false, 90]
        ] as $group => [$name, $hidden, $position]) {
            $category = @IPS_GetObjectIDByIdent('E3DCGroup' . $group, $this->InstanceID);
            if ($category !== false && IPS_CategoryExists($category)) {
                IPS_SetName($category, $name);
                IPS_SetHidden($category, $hidden);
                IPS_SetPosition($category, $position);
            }
        }
    }

    private function publishExternalSystems(): void
    {
        $configuration = @$this->ReadPropertyString('ExternalSystems');
        if (!is_string($configuration)) {
            return;
        }
        $rows = $this->decodeJsonArray($configuration);
        $root = @IPS_GetObjectIDByIdent('CategoryExternalSystems', $this->InstanceID);
        if (!is_array($rows) || $rows === []) {
            if ($root !== false) {
                IPS_SetHidden($root, true);
            }
            return;
        }
        $root = $this->accessoryCategory($this->InstanceID, 'CategoryExternalSystems', 'Externe Systeme');
        IPS_SetPosition($root, 80);
        IPS_SetHidden($root, false);
        $current = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $source = (int) ($row['Source'] ?? 0);
            if ($source <= 0 || isset($current[$source])) {
                continue;
            }
            $exists = IPS_VariableExists($source);
            $parent = $exists ? IPS_GetParent($source) : 0;
            while ($parent > 0 && $parent !== $this->InstanceID) {
                $parent = IPS_GetParent($parent);
            }
            $value = $exists && $parent !== $this->InstanceID ? GetValue($source) : null;
            $name = trim((string) ($row['Name'] ?? ''));
            $category = $this->accessoryCategory($root, 'Quelle' . $source, $name ?: ($exists ? IPS_GetName($source) : 'Quelle ' . $source));
            $current[$source] = $category;
            IPS_SetHidden($category, false);
            $this->publishAccessoryValue($category, 'Rolle', 'Rolle', match ($row['Role'] ?? '') {
                'generation' => 'Erzeuger',
                'consumption' => 'Verbraucher',
                'meter' => 'Zähler',
                default => 'Weiteres System',
            });
            $factor = ($row['Unit'] ?? 'W') === 'kW' ? 1000 : 1;
            $watts = (is_int($value) || is_float($value)) ? (float) $value * $factor : NAN;
            $valid = $this->ReadPropertyBoolean('Active') && is_finite($watts);
            $this->publishAccessoryValue($category, 'Verfuegbar', 'Messwert verfügbar', $valid);
            if ($valid) {
                $this->publishAccessoryValue($category, 'Leistung', 'Leistung (W)', $watts);
            }
        }
        foreach (IPS_GetChildrenIDs($root) as $category) {
            if (!in_array($category, $current, true) && IPS_CategoryExists($category)) {
                IPS_SetHidden($category, true);
            }
        }
    }

    public function DiscoverLAN(string $network, string $master, int $port, int $unit, string $mode = ''): string
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return 'Instanz ist deaktiviert. Für die LAN-Suche zuerst aktivieren.';
        }
        $mode = in_array($mode, ['auto', 'standalone', 'farm'], true) ? $mode : $this->ReadPropertyString('OperatingMode');
        $result = $this->findDevices($network, $master, $port, $unit, $mode);
        if (!isset($result['master'])) {
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
    'OperatingMode' => $_IPS['OperatingMode'],
    'Port' => $_IPS['OnePort'],
    'Unit' => $_IPS['OneUnit']
];
if ($_IPS['OneSecond'] !== '') {
    $settings['SecondHost'] = $_IPS['OneSecond'];
}
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
SetValue($statusId, 'IP-Adressen übernommen: ' . implode(' und ', array_filter([$settings['Host'], $settings['SecondHost'] ?? ''])));
PHP;
        $started = IPS_RunScriptTextEx($script, [
            'OneInstance' => $this->InstanceID,
            'OneSetupStatus' => $statusId,
            'OneMaster' => $result['master'],
            'OperatingMode' => $result['mode'],
            'OneSecond' => $result['second'],
            'OnePort' => $result['port'],
            'OneUnit' => $result['unit']
        ]);
        if (!$started) {
            $this->setGroupedValue('LanSetupStatus', 'Automatische Übernahme konnte nicht gestartet werden.');
            return $this->getGroupedValue('LanSetupStatus');
        }

        $this->UpdateFormField('OperatingMode', 'value', $result['mode']);
        $this->UpdateFormField('Host', 'value', $result['master']);
        if ($result['second'] !== '') {
            $this->UpdateFormField('SecondHost', 'value', $result['second']);
        }
        return $result['message'] . "\n\nAutomatische Übernahme gestartet. Das Ergebnis steht unter LAN-Einrichtung.";
    }

    public function DiscoverLANSetup(string $network, string $master, int $port, int $unit): string
    {
        return json_encode(['message' => $this->DiscoverLAN($network, $master, $port, $unit)],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private function findDevices(string $network, string $master, int $port, int $unit, string $mode): array
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
            $matches += $this->scanDeviceSubnet($prefix, $port, $unit, $mode !== 'standalone');
        }
        if ($matches === []) {
            return ['message' => sprintf('Kein E3/DC-Gerät im Modbus Simple Mode gefunden. Geprüft: %s (Port %d, Unit-ID %d).',
                implode(', ', $searched), $port, $unit)];
        }

        $lines = [];
        foreach ($matches as $host => $device) {
            $lines[] = $host . ' — E3/DC ' . ($device['model'] ?: '(Modell unbekannt)') . ' — ' . $device['serial'];
        }
        $summary = implode("\n", $lines);
        return $this->classifyDiscoveredDevices($matches, $masterIp, $mode, $port, $unit, $summary);
    }

    private function classifyDiscoveredDevices(array $matches, string $masterIp, string $mode, int $port, int $unit, string $summary): array
    {
        $farmMasters = array_keys(array_filter($matches, static fn (array $device): bool => $device['farmMeter']));
        if ($mode === 'standalone' || count($matches) === 1) {
            $host = $masterIp !== '' ? $masterIp : array_key_first($matches);
            if (!isset($matches[$host]) || (count($matches) > 1 && $masterIp === '')) {
                return ['message' => $summary . "\n\nFür ein Einzelgerät bitte die gewünschte IP-Adresse wählen."];
            }
            if ($mode !== 'standalone' && $this->hasFarmPeer()) {
                return ['message' => $summary . "\n\nDie vorhandene Farmkonfiguration bleibt erhalten."];
            }
            $detectedMode = $mode !== 'standalone' && ($farmMasters !== [] || $mode === 'farm') ? 'farm' : 'standalone';
            $caption = $detectedMode === 'farm' ? 'Farmmaster erkannt. Weiteres Farmgerät fehlt.' : 'Einzelgerät erkannt.';
            return ['message' => $summary . "\n" . $caption, 'master' => $host, 'second' => '',
                'mode' => $detectedMode, 'port' => $port, 'unit' => $unit];
        }
        if (count($matches) !== 2 || count(array_filter($matches,
            static fn (array $device): bool => strcasecmp($device['model'], 'one') === 0)) !== 2) {
            return ['message' => $summary . "\n\nBitte Hauptgerät und gegebenenfalls das zweite Farmgerät anhand der Treffer eintragen."];
        }
        $serials = array_column($matches, 'serial');
        if (in_array('', $serials, true) || count(array_unique($serials)) !== 2) {
            return ['message' => $summary . "\n\nDie beiden Geräte melden keine eindeutigen Seriennummern."];
        }
        if (count($farmMasters) === 1) {
            $masterIp = $farmMasters[0];
        } elseif ($mode !== 'farm' && (!$this->hasFarmPeer() || !isset($matches[trim($this->ReadPropertyString('SecondHost'))]))) {
            return ['message' => $summary . "\n\nZwei Geräte gefunden, aber kein eindeutiger Farmmaster. Bitte die Betriebsart manuell wählen."];
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
            'mode' => 'farm',
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

    private function scanDeviceSubnet(string $prefix, int $port, int $unit, bool $identifyMaster): array
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
                            $matches[$host] = ['model' => '', 'serial' => '', 'farmMeter' => false];
                            usleep(110000);
                            $identity = $this->readRegisters($socket, $unit, 2, 40020, 32);
                            $matches[$host]['model'] = $this->decodeText(array_slice($identity, 0, 16));
                            $matches[$host]['serial'] = $this->decodeText(array_slice($identity, 16, 16));
                            if ($identifyMaster) {
                                usleep(110000);
                                $meters = $this->readRegisters($socket, $unit, 3, 40105, 32);
                                $matches[$host]['farmMeter'] = in_array(5, array_filter($meters,
                                    static fn (int $index): bool => $index % 4 === 0, ARRAY_FILTER_USE_KEY), true);
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

    public function RunInitialPoll(): void
    {
        $this->runTimerPoll(function (): void {
            $this->SetTimerInterval('InitialPollTimer', 0);
            $this->pollMeasurements();
            $this->refreshAccessoryTable();
        });
    }

    public function Poll(): void
    {
        $this->runTimerPoll($this->pollMeasurements(...));
    }

    private function runTimerPoll(callable $poll): void
    {
        if (!IPS_InstanceExists($this->InstanceID)) {
            return;
        }
        $instanceId = $this->InstanceID;
        $previous = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$previous, $instanceId): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            if (in_array($message, [
                'InstanceInterface is not available',
                'Instanz #' . $instanceId . ' existiert nicht',
                'Instance #' . $instanceId . ' does not exist'
            ], true)) {
                throw new E3DCInstanceUnavailable($message, 0, $severity, $file, $line);
            }
            return $previous !== null ? (bool) $previous($severity, $message, $file, $line) : false;
        });
        try {
            $poll();
        } catch (E3DCInstanceUnavailable) {
            // The next scheduled poll can run once Symcon exposes the instance again.
        } finally {
            restore_error_handler();
        }
    }

    private function pollMeasurements(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        if (!filter_var($this->ReadPropertyString('Host'), FILTER_VALIDATE_IP)) {
            $this->setGroupedValue('DataStatus', 'IP-Adresse des Hauptgeräts fehlt oder ist ungültig.');
            return;
        }
        $lock = 'E3DCOne.Poll.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            return;
        }

        try {
            $this->publishExternalSystems();
            $this->setGroupedValue('FarmConsumptionValid', false);
            $this->setGroupedValue('AdditionalConsumerValid', false);
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
                $transaction = 2;
                $notes = [];
                $registers = $this->readCore($socket, $unit, $transaction, $notes);
                $this->publishCore($registers);
                $this->setGroupedValue('LastSuccess', time());
                $this->readDetails($socket, $unit, $transaction, $notes, $registers[15] ?? -1);
            } finally {
                fclose($socket);
            }

            $this->publishDaily($this->isFarmMode() && $this->getGroupedValue('FarmPowerValid'), $registers);
            $this->setGroupedValue('DataStatus', 'Messwerte aktualisiert');
            $this->SetStatus(102);
        } catch (RuntimeException $error) {
            $this->publishAccessories([]);
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

    private function readCore($socket, int $unit, int &$transaction, array &$notes): array
    {
        usleep(110000);
        try {
            return $this->readRegisters($socket, $unit, $transaction++, 40068, 18);
        } catch (RuntimeException $error) {
            if (!str_starts_with($error->getMessage(), 'Modbus-Ausnahme ')) {
                throw $error;
            }
        }
        usleep(110000);
        $core = $this->readRegisters($socket, $unit, $transaction++, 40068, 8);
        foreach ([8 => 2, 10 => 2, 12 => 2, 14 => 1, 15 => 1, 16 => 1, 17 => 1] as $offset => $count) {
            usleep(110000);
            try {
                $values = $this->readRegisters($socket, $unit, $transaction++, 40068 + $offset, $count);
                foreach ($values as $index => $value) {
                    $core[$offset + $index] = $value;
                }
            } catch (RuntimeException $error) {
                if (!str_starts_with($error->getMessage(), 'Modbus-Ausnahme ')) {
                    throw $error;
                }
                $notes['Register ' . (40068 + $offset)] = $error->getMessage();
            }
        }
        return $core;
    }

    private function publishCore(array $registers): void
    {
        $index = 0;
        foreach (self::POWER as $ident => $label) {
            if (isset($registers[$index])) {
                $this->setGroupedValue($ident, $this->signed32($registers, $index));
            }
            $index += 2;
        }
        $battery = $this->getGroupedValue('Battery');
        $grid = $this->getGroupedValue('Grid');
        $this->setGroupedValue('BatteryChargePower', max(0, $battery));
        $this->setGroupedValue('BatteryDischargePower', max(0, -$battery));
        $this->setGroupedValue('GridImport', max(0, $grid));
        $this->setGroupedValue('GridExport', max(0, -$grid));
        if (isset($registers[15])) {
            $this->setGroupedValue('SOC', $registers[15]);
        }
        $this->setGroupedValue('Emergency', $registers[16] ?? 0);
        $this->setGroupedValue('EmergencyState', match ($registers[16] ?? -1) {
            -1 => 'Nicht verfügbar',
            0 => 'Nicht unterstützt',
            1 => 'Aktiv',
            2 => 'Nicht aktiv',
            3 => 'Nicht verfügbar',
            4 => 'Motorschalter außerhalb Normalstellung',
            default => 'Unbekannter Status (' . $registers[16] . ')'
        });
        $efficiency = $registers[14] ?? 0xFFFF;
        $this->setGroupedValue('EfficiencyRaw', sprintf('0x%04X', $efficiency));
        $autarky = $efficiency >> 8;
        $selfConsumption = $efficiency & 0xFF;
        $valid = $autarky <= 100 && $selfConsumption <= 100;
        if ($valid) {
            $this->setGroupedValue('OneAutarky', $autarky);
            $this->setGroupedValue('OneSelfConsumption', $selfConsumption);
        }
        $this->setGroupedValue('OneEfficiencyValid', $valid);
        $ems = $registers[17] ?? 0;
        $this->setGroupedValue('EMS', $ems);
        $flags = [];
        foreach (['Laden gesperrt', 'Entladen gesperrt', 'Notstrom möglich',
            'Wetterbasiertes Laden', 'Einspeisung abgeregelt', 'Ladesperrzeit',
            'Entladesperrzeit'] as $bit => $label) {
            $flags[$label] = ($ems & (1 << $bit)) !== 0;
        }
        $this->publishJson('EMSFlags', $flags);
    }

    private function readDetails($socket, int $unit, int $transaction, array $notes, int $soc): void
    {
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
        $values = $read('Wallbox-Slots', 40088, 8);
        if ($values === null && $connectionUsable) {
            for ($index = 0; $index < 8 && $connectionUsable; $index++) {
                $state = $read('Wallbox-Slot ' . $index, 40088 + $index, 1);
                if ($state !== null) {
                    $values[$index] = $state[0];
                }
            }
        }
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
        $this->setGroupedValue('Wallboxes', $summary === [] ? $wallboxStatus : implode(' | ', $summary));
        $this->publishJson('WallboxDetails', $wallboxes);
        $slot = $availableSlots[0] ?? -1;
        if (count($availableSlots) > 1) {
            $notes['Wallbox-Zuordnung'] = 'Übersicht verwendet Slot ' . $slot . '. Alle erkannten Wallboxen stehen unter Zubehör.';
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
            for ($i = 0; $i < 3; $i++) {
                $stringData['String ' . ($i + 1)] = [
                    'Spannung (V)' => $strings[$i],
                    'Strom (A)' => $strings[3 + $i] / 100,
                    'Leistung (W)' => $strings[6 + $i]
                ];
            }
        }
        $this->publishJson('DCStrings', $stringData);

        $meters = $read('Leistungsmesser und SG Ready', 40105, 33);
        $sgReady = $meters !== null ? $meters[32] : null;
        if ($meters === null && $connectionUsable) {
            $meters = $read('Leistungsmesser', 40105, 32);
            $sgReady = $read('SG Ready', 40137, 1)[0] ?? null;
        }
        $meter32 = $read('32-Bit-Phasenleistung', 40138, 48);
        $meterData = [];
        $this->setGroupedValue('SGReadyValid', $sgReady !== null);
        if ($sgReady !== null) {
            $this->setGroupedValue('SGReadyState', $sgReady);
            $meterData['SG Ready (Rohwert)'] = $sgReady;
        }
        if ($meters !== null) {
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
        $heatPump = -1;
        if ($auto && $meters !== null) {
            $farm = $this->resolveMeter($meters, $farm, 5, 'Farmzähler', $notes);
        }
        if ($this->isFarmMode() && $meters !== null) {
            $heatPump = $this->resolveMeter($meters, -1, 4, 'Zusatzverbrauchszähler', $notes);
        }
        $this->publishJson('DetectedFarmDevices', [
            'Farmzähler' => $farm >= 0 ? $farm : null,
            'Zusatzverbrauchszähler' => $heatPump >= 0 ? $heatPump : null,
            'Wallbox-Slot' => $wallboxAvailable ? $slot : null
        ]);
        $farmBalance = $this->isFarmMode() ? $this->readFarmMeterPowers($meterData, $farm, $heatPump, $notes) : null;
        $additionalMeter = $this->isFarmMode() && $heatPump >= 0 && $heatPump !== $farm;
        $this->updateAdditionalConsumer($additionalMeter, $heatPump);

        $battery = $read('Batteriegrenzen und Speicherenergie', 40202, 8);
        $batteryData = [];
        if ($battery !== null) {
            foreach (['Max. Ladeleistung (W)', 'Max. Entladeleistung (W)',
                'Verbleibende Energie (Wh)', 'Max. speicherbare Energie (Wh)'] as $index => $label) {
                $batteryData[$label] = $this->signed32($battery, 2 * $index);
            }
        }
        $this->publishJson('BatteryDetails', $batteryData);
        $batteryData = $this->batteryWithFallback($batteryData, $this->ReadPropertyInteger('CapacityFallbackWh'), $soc);
        $hasBatteryEnergy = $this->hasBatteryEnergy($batteryData);
        if (($batteryData['Max. speicherbare Energie (Wh)'] ?? 0) > 0) {
            $this->setGroupedValue('BatteryCapacity', $batteryData['Max. speicherbare Energie (Wh)'] / 1000);
        }
        if ($hasBatteryEnergy) {
            $this->setGroupedValue('BatteryEnergy', $batteryData['Verbleibende Energie (Wh)'] / 1000);
            $reserve = max(0, min(100, $this->ReadPropertyInteger('BackupReservePercent')));
            $available = max(0, $batteryData['Verbleibende Energie (Wh)']
                - $batteryData['Max. speicherbare Energie (Wh)'] * $reserve / 100);
            $this->setGroupedValue('BatteryEnergyAboveReserve', round($available / 1000, 3));
        } elseif ($battery !== null) {
            $notes['Speicherenergie'] = 'Der one liefert keine plausible Speicherkapazität oder Restenergie.';
        }
        if ($batteryData['Geschätzt'] ?? false) {
            $notes['Speicherenergie (Quelle)'] = 'Restenergie aus eingetragener Kapazität und Geräte-SOC geschätzt.';
        }
        $this->setGroupedValue('BatteryEnergyValid', $hasBatteryEnergy);
        $peerInverters = [];
        if ($this->isFarmMode()) {
            $this->publishFarmBattery($batteryData, $farmBalance, $notes, $peerInverters);
        }

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

        $inverters = $this->readInverters($read);
        $this->publishJson('Inverters', $inverters);

        $accessories = [
            'Wallbox' => array_filter($wallboxes, static fn (array $entry): bool => $entry['Verfügbar']),
            'Zaehler' => array_filter($meterData, static fn ($entry): bool => is_array($entry) && $entry['Typ'] !== 0),
            'String' => array_filter($stringData, static fn (array $entry): bool => array_sum($entry) !== 0),
            'Wechselrichter' => $this->detectedInverters($inverters),
            'WechselrichterZweit' => $this->detectedInverters($peerInverters)
        ];
        $this->publishAccessories($accessories, $farm, $heatPump);
        $this->publishJson('ReadNotes', $notes);
    }

    private function readInverters(callable $read): array
    {
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
        return $inverters;
    }

    private function detectedInverters(array $entries): array
    {
        return array_filter($entries, static fn ($entry): bool => is_array($entry) && (
            ($entry['Frequenz L1 (Hz)'] ?? 0) > 0 || ($entry['L1']['AC-Spannung (V)'] ?? 0) > 0
            || ($entry['L1']['DC-Spannung (V)'] ?? 0) > 0
        ));
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($form['elements'] as &$section) {
            foreach ($section['items'] ?? [] as $index => $item) {
                if (($item['name'] ?? '') === 'AccessoryOptions') {
                    $section['items'][$index]['values'] = $this->accessoryTableRows();
                }
            }
        }
        unset($section);
        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function accessoryTableRows(): array
    {
        $root = @IPS_GetObjectIDByIdent('CategoryAccessories', $this->InstanceID);
        $rows = $this->accessorySettings() ?? [];
        $roles = [];
        $assignmentId = $this->findGroupedVariable('DetectedFarmDevices', $this->InstanceID);
        $assignments = $assignmentId !== false ? $this->decodeJsonArray(GetValue($assignmentId)) : null;
        foreach ([
            'Farmzähler' => ['Zaehler', 'Farmzähler'],
            'Zusatzverbrauchszähler' => ['Zaehler', 'Zusatzverbrauch'],
            'Wallbox-Slot' => ['Wallbox', 'Wallbox-Übersicht']
        ] as $name => [$prefix, $role]) {
            $index = $assignments[$name] ?? null;
            if (is_int($index) && $index >= 0) {
                $roles[$prefix . $index] = $role;
            }
        }
        foreach ($root !== false && IPS_CategoryExists($root) ? IPS_GetChildrenIDs($root) : [] as $category) {
            if (!IPS_CategoryExists($category)) {
                continue;
            }
            $key = IPS_GetObject($category)['ObjectIdent'];
            $current = @IPS_GetObjectIDByIdent('Aktuell', $category);
            $setting = $rows[$key] ?? [];
            $rows[$key] = [
                'Key' => $key,
                'Name' => ($setting['Name'] ?? '') !== '' ? $setting['Name'] : IPS_GetName($category),
                'Visible' => $setting['Visible'] ?? true,
                'Role' => $roles[$key] ?? '',
                'Current' => $current !== false && IPS_VariableExists($current) && GetValue($current)
                    ? 'Erkannt' : 'Zuletzt erkannt'
            ];
        }
        return array_values($rows);
    }

    private function refreshAccessoryTable(): void
    {
        $this->UpdateFormField('AccessoryOptions', 'values',
            json_encode($this->accessoryTableRows(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function decodeJsonArray(mixed $value): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    private function accessorySettings(): ?array
    {
        $configuration = @$this->ReadPropertyString('AccessoryOptions');
        if (!is_string($configuration)) {
            return null;
        }
        $settings = [];
        foreach ($this->decodeJsonArray($configuration) ?? [] as $row) {
            if (!is_array($row) || !is_string($row['Key'] ?? null) || trim($row['Key']) === '') {
                continue;
            }
            $key = trim($row['Key']);
            $settings[$key] = [
                'Key' => $key,
                'Name' => (string) ($row['Name'] ?? ''),
                'Visible' => (bool) ($row['Visible'] ?? true),
                'Role' => '',
                'Current' => 'Noch nicht erkannt'
            ];
        }
        return $settings;
    }

    private function accessoryName(string $key, array $settings, array $legacyNames, string $default): string
    {
        $name = trim((string) ($settings[$key]['Name'] ?? ($legacyNames[$key] ?? '')));
        return $name !== '' ? $name : $default;
    }

    private function accessoryLegacyNames(): ?array
    {
        $configuration = @$this->ReadPropertyString('AccessoryNames');
        return is_string($configuration) ? ($this->decodeJsonArray($configuration) ?? []) : null;
    }

    private function additionalConsumerName(int $index, array $settings, array $legacyNames): string
    {
        $legacy = trim((string) @$this->ReadPropertyString('AdditionalConsumerName'));
        return $this->accessoryName('Zaehler' . $index, $settings, $legacyNames, $legacy !== '' ? $legacy : 'Zusatzverbrauch');
    }

    private function publishAccessories(array $groups, int $farm = -1, int $heatPump = -1): void
    {
        $ident = 'CategoryAccessories';
        $root = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        $names = $this->accessoryLegacyNames();
        $settings = $this->accessorySettings();
        if ($settings === null || $names === null || ($root === false && array_filter($groups) === [])) {
            return;
        }
        $firstDetection = $root === false;
        $root = $this->accessoryCategory($this->InstanceID, $ident, 'Zubehör und Messpunkte');
        IPS_SetPosition($root, 70);
        $current = [];
        foreach ($groups as $kind => $entries) {
            foreach ($entries as $label => $fields) {
                $index = (int) substr($label, strrpos($label, ' ') + 1);
                $key = $kind . $index;
                $visible = (bool) ($settings[$key]['Visible'] ?? true);
                $defaultName = match (true) {
                    $kind === 'Zaehler' && $index === $farm => 'Farmzähler',
                    $kind === 'Zaehler' && $index === $heatPump => $this->additionalConsumerName($index, $settings, $names),
                    $kind === 'Zaehler' => 'Zähler ' . $index,
                    $kind === 'String' => 'MPP-Tracker ' . $index,
                    $kind === 'WechselrichterZweit' => 'Wechselrichter ' . $index . ' – zweites Farmgerät',
                    default => $kind . ' ' . $index
                };
                $category = $this->accessoryCategory($root, $key, $this->accessoryName($key, $settings, $names, $defaultName));
                $current[] = $category;
                IPS_SetHidden($category, !$visible);
                $this->publishAccessoryValue($category, 'Aktuell', 'Daten aktuell', true);
                foreach ($fields as $field => $value) {
                    if (is_array($value)) {
                        foreach ($value as $metric => $measurement) {
                            $this->publishAccessoryValue($category, 'Value' . sha1($field . ' ' . $metric), $field . ' ' . $metric, $measurement);
                        }
                        continue;
                    }
                    $this->publishAccessoryValue($category, 'Value' . sha1($field), $field, $value);
                }
            }
        }
        foreach (IPS_GetChildrenIDs($root) as $category) {
            if (!in_array($category, $current, true) && IPS_CategoryExists($category)) {
                $status = @IPS_GetObjectIDByIdent('Aktuell', $category);
                if ($status !== false && IPS_VariableExists($status)) {
                    SetValue($status, false);
                }
            }
        }
        if ($firstDetection && $current !== []) {
            $this->refreshAccessoryTable();
        }
    }

    private function accessoryCategory(int $parent, string $ident, string $name): int
    {
        $category = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($category === false) {
            $category = IPS_CreateCategory();
            IPS_SetParent($category, $parent);
            IPS_SetIdent($category, $ident);
        }
        if (IPS_GetName($category) !== $name) {
            IPS_SetName($category, $name);
        }
        return $category;
    }

    private function publishAccessoryValue(int $parent, string $ident, string $name, mixed $value): void
    {
        $variable = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($variable === false) {
            $type = match (gettype($value)) {
                'boolean' => VARIABLETYPE_BOOLEAN,
                'integer' => VARIABLETYPE_INTEGER,
                'double' => VARIABLETYPE_FLOAT,
                default => VARIABLETYPE_STRING
            };
            $variable = IPS_CreateVariable($type);
            IPS_SetParent($variable, $parent);
            IPS_SetIdent($variable, $ident);
            IPS_SetName($variable, $name);
        }
        SetValue($variable, $value);
    }

    public function SetWallboxMode(int $slot, bool $solar): void
    {
        $this->setWallboxBit($slot, 2, $solar);
    }

    public function SetWallboxChargingAllowed(int $slot, bool $allowed): void
    {
        $this->setWallboxBit($slot, 4, !$allowed);
    }

    private function setWallboxBit(int $slot, int $mask, bool $enabled): void
    {
        if ($slot < 0 || $slot > 7) {
            throw new RuntimeException('Wallbox-Slot muss zwischen 0 und 7 liegen.');
        }
        $this->controlDevice(false, 'AllowWallboxControl', function ($socket, int $unit) use ($slot, $mask, $enabled): void {
            $register = 40088 + $slot;
            $state = $this->readRegisters($socket, $unit, 2, $register, 1)[0];
            if (($state & 1) === 0) {
                throw new RuntimeException('Wallbox-Slot meldet keine verfügbare Wallbox.');
            }
            $target = $enabled ? $state | $mask : $state & ~$mask;
            if ($target === $state) {
                return;
            }
            $payload = pack('nn', $register - 1, $target);
            $this->writeRegisters($socket, $unit, 3, 6, $payload, $payload);
            usleep(110000);
            $actual = $this->readRegisters($socket, $unit, 4, $register, 1)[0];
            if (($actual & (1 | $mask)) !== ($target & (1 | $mask))) {
                throw new RuntimeException('Die Wallbox hat die Änderung beim Rücklesen nicht bestätigt.');
            }
        });
    }

    public function SetBatteryPower(bool $secondDevice, int $watts): void
    {
        $this->controlDevice($secondDevice, 'AllowBatteryControl', function ($socket, int $unit) use ($watts): void {
            $limits = $this->readRegisters($socket, $unit, 2, 40202, 4);
            $charge = $this->signed32($limits, 0);
            $discharge = $this->signed32($limits, 2);
            if ($charge < 0 || $discharge > 0 || ($charge === 0 && $discharge === 0)
                || $watts < $discharge || $watts > $charge) {
                throw new RuntimeException('Batterieleistung liegt außerhalb der aktuell gemeldeten Grenzen.');
            }
            $address = pack('nn', 40197 - 1, 2);
            $this->writeRegisters($socket, $unit, 3, 16, $address . pack('Cnn', 4, $watts & 0xFFFF, ($watts >> 16) & 0xFFFF), $address);
        });
    }

    public function StopBatteryControl(bool $secondDevice): void
    {
        $this->controlDevice($secondDevice, 'AllowBatteryControl', function ($socket, int $unit): void {
            $this->readRegisters($socket, $unit, 2, 40199, 1);
            $payload = pack('nn', 40199 - 1, 1);
            $this->writeRegisters($socket, $unit, 3, 6, $payload, $payload);
        });
    }

    private function controlDevice(bool $secondDevice, string $permission, callable $command): void
    {
        if ($secondDevice && !$this->isFarmMode()) {
            throw new RuntimeException('Das zweite Gerät ist nur im Farmbetrieb verfügbar.');
        }
        if (!$this->ReadPropertyBoolean('Active') || !$this->ReadPropertyBoolean($permission)) {
            throw new RuntimeException('Diese Steuerung ist in der Instanz deaktiviert.');
        }
        $host = trim($this->ReadPropertyString($secondDevice ? 'SecondHost' : 'Host'));
        $port = $this->ReadPropertyInteger('Port');
        $unit = $this->ReadPropertyInteger('Unit');
        if (!filter_var($host, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535 || $unit < 0 || $unit > 255
            || ($secondDevice && $host === trim($this->ReadPropertyString('Host')))) {
            throw new RuntimeException('Eine gültige und eindeutige Zieladresse ist erforderlich.');
        }
        $lock = 'E3DCOne.Poll.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 2000)) {
            throw new RuntimeException('Eine Abfrage oder Steuerung läuft bereits.');
        }
        try {
            $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
            $socket = @stream_socket_client('tcp://' . $address . ':' . $port, $code, $message, 2.0);
            if ($socket === false) {
                throw new RuntimeException('E3/DC-Zielgerät nicht erreichbar.');
            }
            try {
                stream_set_timeout($socket, 2);
                if ($this->readRegisters($socket, $unit, 1, 40001, 1)[0] !== 0xE3DC) {
                    throw new RuntimeException('E3/DC Simple Mode wurde am Zielgerät nicht erkannt.');
                }
                usleep(110000);
                $command($socket, $unit);
            } finally {
                fclose($socket);
            }
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function publishFarmBattery(array $local, ?array $farmBalance, array &$notes, array &$peerInverters): void
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
        [$pv, $power, $soc, $remote, $peerInverters] = $snapshot;
        $this->setGroupedValue('SecondPV', $pv);
        $this->setGroupedValue('SecondBattery', $power);
        $this->setGroupedValue('SecondSOC', $soc);
        $this->setGroupedValue('SecondCapacity', ($remote['Max. speicherbare Energie (Wh)'] ?? 0) / 1000);
        $this->publishFarmPower($pv, $power, $farmBalance, $notes);

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
        $instance = $peer !== $this->InstanceID && IPS_InstanceExists($peer) ? IPS_GetInstance($peer) : null;
        if (($instance['ModuleInfo']['ModuleID'] ?? null) !== $moduleId) {
            $this->setGroupedValue('FarmBatteryValid', false);
            $notes['Farm-Batterie'] = 'Die Instanz des zweiten one auswählen.';
            return null;
        }
        $pvId = $this->findGroupedVariable('PV', $peer);
        $powerId = $this->findGroupedVariable('Battery', $peer);
        $statusId = $this->findGroupedVariable('LastSuccess', $peer);
        $detailsId = $this->findGroupedVariable('BatteryDetails', $peer);
        if ($pvId <= 0 || $powerId <= 0 || $statusId <= 0 || $instance['InstanceStatus'] !== 102) {
            $notes['Farm-Leistung'] = 'Aktuelle Leistungsdaten des zweiten one erforderlich.';
            return null;
        }
        $configuration = @IPS_GetConfiguration($peer);
        $settings = is_string($configuration) ? json_decode($configuration, true) : null;
        $peerInterval = filter_var($settings['Interval'] ?? null, FILTER_VALIDATE_INT);
        $maxAge = 2 * max(5, min(3600, $peerInterval === false ? 15 : $peerInterval)) + 10;
        if (time() - GetValue($statusId) > $maxAge) {
            $this->setGroupedValue('FarmBatteryValid', false);
            $notes['Farm-Leistung'] = 'Aktuelle Leistungsdaten des zweiten one erforderlich.';
            return null;
        }


        $remote = [];
        if ($detailsId > 0 && time() - IPS_GetVariable($detailsId)['VariableUpdated'] <= $maxAge) {
            $details = GetValue($detailsId);
            $decoded = is_string($details) ? json_decode($details, true) : null;
            $remote = is_array($decoded) ? $decoded : [];
        }

        $socId = $this->findGroupedVariable('SOC', $peer);
        $soc = $socId > 0 ? GetValue($socId) : null;
        if (is_int($soc)) {
            $capacity = filter_var($settings['CapacityFallbackWh'] ?? null, FILTER_VALIDATE_INT);
            if ($capacity === false || $capacity <= 0) {
                $capacity = $this->ReadPropertyInteger('SecondCapacityFallbackWh');
            }
            $remote = $this->batteryWithFallback($remote, $capacity, $soc);
        }
        $inverters = [];
        $inverterId = $this->findGroupedVariable('Inverters', $peer);
        if ($inverterId !== false && time() - IPS_GetVariable($inverterId)['VariableUpdated'] <= $maxAge) {
            $data = GetValue($inverterId);
            $decoded = is_string($data) ? json_decode($data, true) : null;
            $inverters = is_array($decoded) ? $decoded : [];
        }
        return [GetValue($pvId), GetValue($powerId), is_int($soc) ? $soc : -1, $remote, $inverters];
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
            $transaction = 2;
            $core = $this->readCore($socket, $unit, $transaction, $notes);
            $energy = [];
            try {
                usleep(110000);
                $registers = $this->readRegisters($socket, $unit, $transaction++, 40206, 4);
                $energy = [
                    'Verbleibende Energie (Wh)' => $this->signed32($registers, 0),
                    'Max. speicherbare Energie (Wh)' => $this->signed32($registers, 2)
                ];
            } catch (RuntimeException $error) {
                $notes['Speicherenergie des zweiten one'] = $error->getMessage();
            }
            $soc = $core[15] ?? -1;
            $energy = $this->batteryWithFallback($energy, $this->ReadPropertyInteger('SecondCapacityFallbackWh'), $soc);
            $connectionUsable = true;
            $inverters = $this->readInverters(function (string $label, int $address, int $count) use ($socket, $unit, &$transaction, &$notes, &$connectionUsable): ?array {
                if (!$connectionUsable) {
                    return null;
                }
                usleep(110000);
                try {
                    return $this->readRegisters($socket, $unit, $transaction++, $address, $count);
                } catch (RuntimeException $error) {
                    $notes[$label . ' – zweites Farmgerät'] = $error->getMessage();
                    $connectionUsable = str_starts_with($error->getMessage(), 'Modbus-Ausnahme ');
                    return null;
                }
            });
            return [$this->signed32($core, 0), $this->signed32($core, 2), $soc, $energy, $inverters];
        } finally {
            fclose($socket);
        }
    }

    private function publishFarmPower(int $peerPV, int $peerBattery, ?array $farmBalance, array &$notes): void
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
        if ($farmBalance === null) {
            return;
        }
        [$meterPower, $heat] = $farmBalance;
        $external = $this->getGroupedValue('External');
        if (!$this->ReadPropertyBoolean('FarmMeterIsConsumption')) {
            $grid = $meterPower;
            $consumption = $grid + $pv + $external - $battery - $wallbox;
        } else {
            $consumption = $meterPower;
            $grid = $consumption + $wallbox - $pv - $external + $battery;
        }
        if ($consumption < 0) {
            $notes['Farm-Bilanz'] = 'Der berechnete Farm-Gesamtverbrauch ist negativ. Messpunkt und Vorzeichen prüfen.';
            return;
        }
        if ($heat !== null && ($heat < 0 || $consumption < $heat)) {
            $notes['Zusatzverbrauch'] = sprintf('Zusatzverbrauch %d W passt nicht zum Farm-Gesamtverbrauch %d W.', $heat, $consumption);
            $heat = null;
        }
        $this->setGroupedValue('FarmConsumption', $consumption);
        if ($heat !== null) {
            $this->setGroupedValue('HeatPumpConsumption', $heat);
            $this->setGroupedValue('FarmHouseConsumption', $consumption - $heat);
            $this->setGroupedValue('AdditionalConsumerValid', true);
        }
        $this->setGroupedValue('FarmConsumptionValid', true);
        $demand = $consumption + $wallbox;
        $import = max(0, $grid);
        $this->setGroupedValue('FarmGrid', $grid);
        $this->setGroupedValue('FarmGridImport', $import);
        $this->setGroupedValue('FarmGridExport', max(0, -$grid));
        $this->setGroupedValue('FarmAutarky', $demand > 0 ? round(100 * max(0, $demand - $import) / $demand, 1) : 0.0);
        $this->setGroupedValue('FarmPowerValid', true);
    }

    public function PollRscpSolar(): void
    {
        $this->runTimerPoll($this->pollRscpDayData(...));
    }

    private function pollRscpDayData(): void
    {
        if (!$this->ReadPropertyBoolean('Active') || !$this->ReadPropertyBoolean('ReadRscpSolar')) {
            return;
        }
        if (!filter_var($this->ReadPropertyString('Host'), FILTER_VALIDATE_IP)) {
            return;
        }
        $lock = 'E3DCOne.RscpSolar.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            return;
        }
        try {
            $host = trim($this->ReadPropertyString('Host'));
            $port = $this->ReadPropertyInteger('FarmRscpPort');
            $user = trim($this->ReadPropertyString('FarmRscpUser'));
            $password = $this->ReadPropertyString('FarmRscpPassword');
            $key = $this->ReadPropertyString('FarmRscpKey');
            if (!filter_var($host, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535
                || $user === '' || $password === '' || $key === '') {
                throw new RuntimeException('Farmmaster-IP, RSCP-Port und beide RSCP-Passwörter prüfen.');
            }
            $today = date('Y-m-d');
            // RSCP expects local calendar midnight encoded as UTC, as in the verified probe.
            $day = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->getTimestamp();
            $energy = (new \E3DCConnector\FarmRscp($key))->readDayEnergyWh($host, $port, $user, $password, $day);
            if ($today !== date('Y-m-d')) {
                throw new RuntimeException('Tageswechsel während der RSCP-Abfrage. Neuer Versuch im nächsten Intervall.');
            }
            $this->setGroupedValue('RscpDayEnergy', json_encode($energy, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT));
            $this->publishRscpEnergy($energy);
            $solarWh = $energy['DB_DC_POWER'] ?? null;
            if ($solarWh === null || $solarWh < 0) {
                $this->setGroupedValue('RscpSolarStatus', 'RSCP-Tagessummen gelesen. Solarertrag fehlt.');
                if (!$this->hasCurrentRscpSolar(time(), strtotime('today'))) {
                    $this->publishSolarFallback();
                }
                return;
            }
            $this->WriteAttributeInteger('RscpSolarReadAt', time());
            $this->setGroupedValue('DayPV', $solarWh / 1000);
            $this->setGroupedValue('DayPVSource', 'E3/DC Farmcontroller (RSCP: DB_DC_POWER)');
            $this->setGroupedValue('RscpSolarStatus', 'RSCP-Tagesdaten zuletzt gelesen um ' . date('H:i:s'));
        } catch (RuntimeException $error) {
            $this->setGroupedValue('RscpSolarStatus', $error->getMessage());
            if (!$this->hasCurrentRscpSolar(time(), strtotime('today'))) {
                $this->publishRscpEnergy([]);
                $this->publishSolarFallback();
            }
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function publishRscpEnergy(array $energy): void
    {
        $daily = @IPS_GetObjectIDByIdent('E3DCGroupDaily', $this->InstanceID);
        if ($daily === false || !IPS_CategoryExists($daily)) {
            return;
        }
        if ($energy === [] && @IPS_GetObjectIDByIdent('CategoryRscpEnergy', $daily) === false) {
            return;
        }
        $root = $this->accessoryCategory($daily, 'CategoryRscpEnergy', 'Direkte RSCP-Tagessummen');
        foreach (self::RSCP_ENERGY as $tag => $label) {
            $existing = @IPS_GetObjectIDByIdent($tag, $root);
            $value = $energy[$tag] ?? null;
            if ($value === null && $existing === false) {
                continue;
            }
            $this->publishAccessoryValue($root, $tag . 'Aktuell', $label . ' aktuell', $value !== null);
            if ($value !== null) {
                $this->publishAccessoryValue($root, $tag, $label . ' (kWh)', $value / 1000.0);
            }
        }
        $this->publishAccessoryValue($root, 'LastRead', 'Letzte erfolgreiche RSCP-Abfrage', $energy === [] ? 0 : time());
    }

    private function hasCurrentRscpSolar(int $now, int $midnight): bool
    {
        $last = $this->ReadAttributeInteger('RscpSolarReadAt');
        return $this->ReadPropertyBoolean('ReadRscpSolar') && $last >= $midnight && $now - $last <= 180;
    }

    private function publishSolarFallback(): void
    {
        $configuration = @$this->ReadAttributeString('DailyTotals');
        if (!is_string($configuration)) {
            return;
        }
        $state = $this->decodeJsonArray($configuration) ?? [];
        $estimate = ($state['midnight'] ?? 0) === strtotime('today')
            ? round($state['totals']['DayPV'] ?? 0.0, 3) : 0.0;
        $this->setGroupedValue('DayPVEstimate', $estimate);
        $this->setGroupedValue('DayPV', $estimate);
        $this->setGroupedValue('DayPVSource', 'Modbus-Summe');
    }

    private function publishDaily(bool $valid, ?array $core = null): void
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

        $single = !$this->isFarmMode();
        $valid = $single ? $core !== null : $valid;

        $now = time();
        $midnight = strtotime('today', $now);
        $configuration = @$this->ReadAttributeString('DailyTotals');
        if (!is_string($configuration)) {
            return;
        }
        $state = $this->decodeJsonArray($configuration) ?? [];
        $topology = $single ? 'single' : 'farm';
        if (($state['topology'] ?? 'farm') !== $topology) {
            $state = [];
        }
        $previousTime = $state['time'] ?? 0;
        $previousPowers = $state['powers'] ?? [];
        $maxGap = 2 * max(5, min(3600, $this->ReadPropertyInteger('Interval'))) + 10;
        $canIntegrate = $previousPowers !== [] && $now > $previousTime && $now - $previousTime <= $maxGap;
        if (($state['midnight'] ?? 0) !== $midnight) {
            $state = ['midnight' => $midnight, 'start' => $canIntegrate ? $midnight : 0, 'totals' => []];
        }
        $balanceKey = !$this->ReadPropertyBoolean('FarmMeterIsConsumption') ? 'grid-v3' : 'consumption-v3';
        $balanceChanged = ($state['balanceKey'] ?? null) !== $balanceKey
            && ($state['totals'] ?? []) !== [];
        $balanceIdents = ['DayGridImport', 'DayGridExport', 'DayHouse', 'DayConsumption'];
        if ($balanceChanged) {
            foreach ($balanceIdents as $ident) {
                unset($state['totals'][$ident], $previousPowers[$ident]);
            }
            $state['balanceStart'] = 0;
        }
        $state['balanceKey'] = $balanceKey;
        $powers = [];
        // PV, battery and wallbox power are published before the farm balance is checked.
        if (!$single && $this->getGroupedValue('SecondOneValid')) {
            $powers = [
                'DayPV' => $this->getGroupedValue('FarmPV'),
                'DayBatteryCharge' => $this->getGroupedValue('FarmBatteryChargePower'),
                'DayBatteryDischarge' => $this->getGroupedValue('FarmBatteryDischargePower'),
                'DayWallbox' => $this->getGroupedValue('FarmWallboxPower')
            ];
            if ($state['start'] === 0) {
                $state['start'] = $now;
            }
        }
        if ($valid) {
            $powers += [
                'DayGridImport' => $this->getGroupedValue('FarmGridImport'),
                'DayGridExport' => $this->getGroupedValue('FarmGridExport'),
                'DayConsumption' => $this->getGroupedValue('FarmConsumption')
            ];
            if ($this->getGroupedValue('AdditionalConsumerValid')) {
                $powers['DayHouse'] = $this->getGroupedValue('FarmHouseConsumption');
                $powers['DayHeatPump'] = $this->getGroupedValue('HeatPumpConsumption');
            }
        }

        if ($single && $core !== null) {
            $powers = [
                'DayPV' => $this->getGroupedValue('PV'),
                'DayBatteryCharge' => $this->getGroupedValue('BatteryChargePower'),
                'DayBatteryDischarge' => $this->getGroupedValue('BatteryDischargePower'),
                'DayGridImport' => $this->getGroupedValue('GridImport'),
                'DayGridExport' => $this->getGroupedValue('GridExport'),
                'DayHouse' => $this->getGroupedValue('House'),
                'DayConsumption' => $this->getGroupedValue('House')
            ];
            if (isset($core[10])) {
                $powers['DayWallbox'] = $this->getGroupedValue('WallboxPower');
            }
            if ($state['start'] === 0) {
                $state['start'] = $now;
            }
        }

        if ($valid && ($state['balanceStart'] ?? 0) === 0) {
            $state['balanceStart'] = $canIntegrate && isset($previousPowers['DayConsumption']) ? max($previousTime, $midnight) : $now;
        }
        foreach (self::DAILY as $ident => $label) {
            $total = $state['totals'][$ident] ?? 0.0;
            if ($canIntegrate && isset($previousPowers[$ident], $powers[$ident])) {
                $seconds = $now - max($previousTime, $midnight);
                $startPower = $previousPowers[$ident];
                if ($previousTime < $midnight) {
                    $startPower += ($powers[$ident] - $startPower) * ($midnight - $previousTime) / ($now - $previousTime);
                }
                $total += (max(0, $startPower) + max(0, $powers[$ident])) * $seconds / 7200000;
            }
            $state['totals'][$ident] = $total;
            if ($ident === 'DayPV') {
                $this->setGroupedValue('DayPVEstimate', round($total, 3));
                if ($this->hasCurrentRscpSolar($now, $midnight)) {
                    continue;
                }
                $this->setGroupedValue('DayPVSource', 'Modbus-Summe');
            }
            $this->setGroupedValue($ident, round($total, 3));
        }
        $state['topology'] = $topology;
        $state['time'] = $now;
        $state['powers'] = $powers;
        $this->WriteAttributeString('DailyTotals', json_encode($state));
        $this->setGroupedValue('DayStart', $state['start']);
        $this->setGroupedValue('DayBalanceStart', $state['balanceStart'] ?? 0);
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
            $notes[$name] = $type === 4 ? 'Mehrere Typ-4-Zähler erkannt. Kein eindeutiger Zusatzverbrauchszähler.'
                : 'Mehrere Zähler dieses Typs gefunden. Bitte den gewünschten Index wählen.';
        }
        return -1;
    }

    private function updateAdditionalConsumer(bool $visible, int $index = -1): void
    {
        $settings = $this->accessorySettings();
        $names = $this->accessoryLegacyNames();
        if ($settings === null || $names === null) {
            return;
        }
        $consumer = $this->additionalConsumerName($index, $settings, $names);
        foreach ([
            'HeatPumpConsumption' => $consumer . ' (W)',
            'FarmHouseConsumption' => 'Hausverbrauch ohne ' . $consumer . ' (W)',
            'DayHeatPump' => $consumer . ' heute (kWh)',
            'DayHouse' => $this->isFarmMode() ? 'Hausverbrauch ohne ' . $consumer . ' heute (kWh)' : self::DAILY['DayHouse'],
            'AdditionalConsumerValid' => 'Zusatzverbrauch aktuell',
        ] as $ident => $caption) {
            $id = $this->findGroupedVariable($ident, $this->InstanceID);
            if ($id === false) {
                continue;
            }
            if (IPS_GetName($id) !== $caption) {
                IPS_SetName($id, $caption);
            }
            IPS_SetHidden($id, !$visible && ($ident !== 'DayHouse' || $this->isFarmMode()));
        }
    }

    private function readFarmMeterPowers(array $meters, int $farm, int $additional, array &$notes): ?array
    {
        $farmMeter = $meters['Index ' . $farm] ?? [];
        if (($farmMeter['Typ'] ?? null) !== 5) {
            $notes['Farm-Verbrauch'] = 'Farmzähler (Typ 5) zuordnen.';
            return null;
        }
        $total = $farmMeter['L1 (W)'] + $farmMeter['L2 (W)'] + $farmMeter['L3 (W)'];
        $this->setGroupedValue('FarmMeterPower', $total);

        $extra = $meters['Index ' . $additional] ?? [];
        $consumption = null;
        if ($additional >= 0) {
            if ($additional !== $farm && ($extra['Typ'] ?? 0) !== 0) {
                $consumption = $extra['L1 (W)'] + $extra['L2 (W)'] + $extra['L3 (W)'];
            } else {
                $notes['Zusatzverbrauch'] = 'Der ausgewählte Zusatzverbrauchszähler ist nicht verfügbar oder entspricht dem Farmzähler.';
            }
        }
        return [$total, $consumption];
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
        $body = $this->exchangeModbus($socket, $unit, $transaction, 3, pack('nn', $register - 1, $count), $register);
        if (ord($body[0]) !== 3 || ord($body[1]) !== $count * 2 || strlen($body) !== 2 + $count * 2) {
            throw new RuntimeException('Unerwartete Modbus-Registerantwort.');
        }
        return array_values(unpack('n*', substr($body, 2)));
    }

    private function writeRegisters($socket, int $unit, int $transaction, int $function, string $payload, string $expected): void
    {
        usleep(110000);
        $register = unpack('n', $payload)[1] + 1;
        $response = $this->exchangeModbus($socket, $unit, $transaction, $function, $payload, $register);
        if ($response !== chr($function) . $expected) {
            throw new RuntimeException('Unerwartete Modbus-Schreibbestätigung.');
        }
    }

    private function exchangeModbus($socket, int $unit, int $transaction, int $function, string $payload, int $register): string
    {
        $request = pack('nnnCC', $transaction, 0, strlen($payload) + 2, $unit, $function) . $payload;
        if (@fwrite($socket, $request) !== strlen($request)) {
            throw new RuntimeException('Modbus-Anfrage konnte nicht gesendet werden.');
        }
        $header = unpack('ntransaction/nprotocol/nlength/Cunit', $this->readBytes($socket, 7));
        if ($header['transaction'] !== $transaction || $header['protocol'] !== 0
            || $header['unit'] !== $unit || $header['length'] < 3 || $header['length'] > 254) {
            throw new RuntimeException('Ungültiger Modbus-Antwortkopf.');
        }
        $body = $this->readBytes($socket, $header['length'] - 1);
        if (ord($body[0]) === ($function | 0x80)) {
            throw new RuntimeException('Modbus-Ausnahme ' . ord($body[1]) . ' bei Register ' . $register . '.');
        }
        return $body;
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
