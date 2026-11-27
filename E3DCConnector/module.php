<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FarmRscp.php';

final class E3DCInstanceUnavailable extends ErrorException
{
}

class E3DCConnector extends IPSModule
{
    private const EXTRA_GROUPS = [
        'DCStrings' => 'PV-Strings',
        'PowerMeters' => 'Leistungsmesser',
        'Inverters' => 'Wechselrichter je Index',
        'BatteryDetails' => 'Batteriegrenzen und Speicherenergie',
        'RemoteStatus' => 'Status der Fernsteuerung',
        'WallboxDetails' => 'Wallbox-Slots (Details)',
        'ReadNotes' => 'Hinweise zu den Datenquellen'
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
        'PV' => 'PV-Erzeugung (W)',
        'Battery' => 'Batterieleistung (W)',
        'House' => 'Hausverbrauch (W)',
        'Grid' => 'Netzleistung (W)',
        'External' => 'Externe Erzeugung (W)',
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
        $this->RegisterPropertyInteger('DeviceRscpPort', 5033);
        $this->RegisterPropertyInteger('RscpDetailInterval', 300);
        $this->RegisterPropertyString('FarmRscpUser', 'local.user');
        $this->RegisterPropertyString('FarmRscpPassword', '');
        $this->RegisterPropertyString('FarmRscpKey', '');
        $this->RegisterAttributeInteger('RscpSolarReadAt', 0);
        $this->RegisterTimer('RscpSolarTimer', 0, 'if (IPS_InstanceExists($_IPS["TARGET"])) { E3DCF_PollRscpSolar($_IPS["TARGET"]); }');
        $this->RegisterPropertyInteger('Interval', 15);
        $this->RegisterPropertyString('AccessoryNames', '{}');
        $this->RegisterPropertyString('AccessoryOptions', '[]');
        $this->RegisterPropertyBoolean('AllowBatteryControl', false);
        $this->RegisterPropertyString('AdditionalConsumerName', '');
        $this->RegisterPropertyInteger('SecondOne', 0);
        $this->RegisterPropertyString('SecondHost', '');
        $this->RegisterPropertyString('LanNetwork', '');
        $this->RegisterPropertyInteger('ForecastSource', 0);
        $this->RegisterAttributeString('DailyTotals', '{}');
        $this->RegisterTimer('PollTimer', 0, 'if (IPS_InstanceExists($_IPS["TARGET"])) { E3DCF_Poll($_IPS["TARGET"]); }');
        $this->RegisterTimer('RscpDetailsTimer', 0, 'if (IPS_InstanceExists($_IPS["TARGET"])) { E3DCF_PollRscpDetails($_IPS["TARGET"]); }');
        $this->RegisterTimer('InitialPollTimer', 0, 'if (IPS_InstanceExists($_IPS["TARGET"])) { E3DCF_RunInitialPoll($_IPS["TARGET"]); }');

        $this->maintainGroupedVariables();
    }

    private function findModuleCategory(int $instance, string $ident): int|false
    {
        $archive = @IPS_GetObjectIDByIdent('E3DCInactive' . $instance, 0);
        $parents = [$instance];
        if ($archive !== false && IPS_CategoryExists($archive)) {
            $parents[] = $archive;
        }
        foreach ($parents as $parent) {
            $id = $this->findLayoutCategory($parent, $ident);
            if ($id !== false) {
                return $id;
            }
        }
        return false;
    }

    private function findLayoutCategory(int $parent, string $ident): int|false
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id !== false && IPS_CategoryExists($id)) {
            return $id;
        }
        foreach (['CategoryFarm', 'CategoryRscpDetails', 'CategorySystem', 'Device1', 'Device2'] as $branch) {
            $child = @IPS_GetObjectIDByIdent($branch, $parent);
            if ($child !== false && IPS_CategoryExists($child)) {
                $id = $this->findLayoutCategory($child, $ident);
                if ($id !== false) {
                    return $id;
                }
            }
        }
        return false;
    }

    private function inactiveCategory(): int
    {
        $archive = $this->accessoryCategory(0, 'E3DCInactive' . $this->InstanceID,
            'E3/DC Connector – inaktive Messwerte (' . $this->InstanceID . ')');
        IPS_SetHidden($archive, true);
        return $archive;
    }

    private function placeLayoutCategory(int $category, int|false $parent): void
    {
        $destination = $parent !== false ? $parent : $this->inactiveCategory();
        if (IPS_GetParent($category) !== $destination) {
            IPS_SetParent($category, $destination);
        }
        IPS_SetHidden($category, $parent === false);
    }

    private function structureCategory(string $ident, string $name, int $parent): int
    {
        $id = $this->findModuleCategory($this->InstanceID, $ident);
        if ($id === false) {
            return $this->accessoryCategory($parent, $ident, $name);
        }
        if (IPS_GetParent($id) !== $parent) {
            IPS_SetParent($id, $parent);
        }
        if (IPS_GetName($id) !== $name) {
            IPS_SetName($id, $name);
        }
        return $id;
    }

    private function maintainGroupedVariables(): void
    {
        foreach (self::POWER as $ident => $label) {
            $this->maintainGroupedVariable($ident, $label, '', VARIABLETYPE_INTEGER);
        }
        $this->maintainGroupedVariable('SOC', 'Batterieladezustand (%)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('EMS', 'EMS-Status (Rohwert)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('Wallboxes', 'Erkannte Wallboxen', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('LastSuccess', 'Letzte erfolgreiche Abfrage', '~UnixTimestamp', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('DataStatus', 'Status der Messwertabfrage', '', VARIABLETYPE_STRING);
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
        $this->maintainGroupedVariable('FarmConsumption', 'Gesamtverbrauch (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('AdditionalConsumerValid', 'Zusatzverbrauch aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('HeatPumpConsumption', 'Zusatzverbrauch (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmHouseConsumption', 'Hausverbrauch ohne Zusatzverbrauch (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmMeterPower', 'Leistung am Farmzähler (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('RscpDayEnergy', 'RSCP-Tagessummen (Wh)', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('DayPVEstimate', 'Solarproduktion aus Live-Werten (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('DayPVSource', 'Datenquelle Solarproduktion', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('RscpSolarStatus', 'Status der RSCP-Tagesabfrage', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('DayBalanceStart', 'Verbrauchs- und Netzbilanz erfasst seit', '~UnixTimestamp', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmConsumptionValid', 'Verbrauchsbilanz aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('FarmBatterySOC', 'Ladezustand gesamt (%)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('FarmBatteryPower', 'Batterieleistung gesamt (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmBatteryValid', 'Speicherwerte aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('RscpCapacity', 'Spezifizierte Kapazität (RSCP, kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('RscpCapacityStatus', 'Status der Kapazitätsabfrage', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('RscpCapacityValid', 'Spezifizierte Kapazität aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('SecondRscpCapacity', 'Spezifizierte Kapazität (RSCP, kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('SecondRscpCapacityStatus', 'Status der Kapazitätsabfrage', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('SecondRscpCapacityValid', 'Spezifizierte Kapazität aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('BatteryCapacity', 'Nutzbare Kapazität (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('BatteryEnergy', 'Verbleibende Energie (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('BatteryEnergyAboveReserve', 'Speicherenergie oberhalb der Reserve (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('BatteryEnergyValid', 'Speicherenergie aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('FarmBatteryCapacity', 'Nutzbare Kapazität gesamt (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('FarmBatteryEnergy', 'Verbleibende Energie gesamt (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('FarmPV', 'PV-Erzeugung gesamt (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmGrid', 'Netzleistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmGridImport', 'Netzbezug (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmGridExport', 'Netzeinspeisung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmAutarky', 'Autarkie aktuell (%)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('FarmWallboxPower', 'Wallbox-Leistung gesamt (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmPowerValid', 'Leistungsbilanz aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('DayForecast', 'Solarprognose heute (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('DayForecastAvailable', 'Solarprognose verfügbar', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('DayStart', 'Tageserfassung seit', '~UnixTimestamp', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('SecondPV', 'PV-Erzeugung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('SecondBattery', 'Batterieleistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('SecondSOC', 'Batterieladezustand (%)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('SecondCapacity', 'Nutzbare Kapazität (kWh)', '', VARIABLETYPE_FLOAT);
        $this->maintainGroupedVariable('SecondOneValid', 'Messwerte des zweiten Geräts aktuell', '', VARIABLETYPE_BOOLEAN);
        $this->maintainGroupedVariable('LanSetupStatus', 'LAN-Einrichtung', '', VARIABLETYPE_STRING);
        $this->maintainGroupedVariable('BatteryChargePower', 'Ladeleistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('BatteryDischargePower', 'Entladeleistung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('GridImport', 'Netzbezug (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('GridExport', 'Netzeinspeisung (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmBatteryChargePower', 'Ladeleistung gesamt (W)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('FarmBatteryDischargePower', 'Entladeleistung gesamt (W)', '', VARIABLETYPE_INTEGER);
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
        $this->maintainGroupedVariable('OneAutarky', 'Autarkie (%)', '', VARIABLETYPE_INTEGER);
        $this->maintainGroupedVariable('OneSelfConsumption', 'Eigenverbrauch (%)', '', VARIABLETYPE_INTEGER);
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
        if (in_array(IPS_GetName($this->InstanceID), [
            'E3/DC Hauskraftwerk', 'E3/DC Farm', 'E3/DC one (Farm)', 'E3DC Farm', 'E3DCOne'
        ], true)) {
            IPS_SetName($this->InstanceID, 'E3/DC Connector');
        }
        $this->maintainGroupedVariables();
        foreach (self::OBSOLETE_MODBUS as $ident) {
            $variable = $this->findGroupedVariable($ident, $this->InstanceID);
            if ($variable !== false) {
                IPS_SetHidden($variable, true);
            }
        }
        $this->updateOperatingLayout();
        $this->setGroupedValue('AdditionalConsumerValid', false);
        $this->updateAdditionalConsumer(false);
        $this->publishExternalSystems();
        $this->SetTimerInterval('InitialPollTimer', 0);
        $this->SetTimerInterval('PollTimer', 0);
        $this->SetTimerInterval('RscpSolarTimer', 0);
        $this->SetTimerInterval('RscpDetailsTimer', 0);
        $this->WriteAttributeInteger('RscpSolarReadAt', 0);
        $this->setGroupedValue('RscpSolarStatus', 'RSCP-Tagesertrag deaktiviert');
        $this->publishSolarFallback();
        $this->setGroupedValue('FarmBatteryValid', false);
        $this->setGroupedValue('BatteryEnergyValid', false);
        $this->setGroupedValue('RscpCapacityValid', false);
        $this->setGroupedValue('SecondRscpCapacityValid', false);
        $this->setGroupedValue('OneEfficiencyValid', false);
        $this->setGroupedValue('FarmPowerValid', false);
        $this->setGroupedValue('SecondOneValid', false);
        $this->setGroupedValue('FarmConsumptionValid', false);
        $this->setGroupedValue('SGReadyValid', false);
        $this->setGroupedValue('WallboxAvailable', false);
        $this->markAccessoryDataStale();
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
            if ($this->ReadPropertyInteger('RscpDetailInterval') > 0) {
                $this->SetTimerInterval('RscpDetailsTimer', 1000);
            }
        }
    }

    private const GROUPS = [
        'Farm' => ['Farm – Leistung und Verbrauch', [
            'FarmPV', 'FarmConsumption', 'FarmHouseConsumption', 'HeatPumpConsumption', 'AdditionalConsumerValid',
            'FarmGrid', 'FarmGridImport', 'FarmGridExport', 'FarmAutarky', 'FarmWallboxPower',
            'FarmPowerValid', 'FarmConsumptionValid', 'FarmMeterPower'
        ]],
        'FarmStorage' => ['Farm – Batteriespeicher', [
            'FarmBatterySOC', 'FarmBatteryPower', 'FarmBatteryChargePower', 'FarmBatteryDischargePower',
            'FarmBatteryCapacity', 'FarmBatteryEnergy', 'FarmBatteryValid'
        ]],
        'Master' => ['Hauptgerät – Leistung und Verbrauch', [
            'PV', 'House', 'Grid', 'GridImport', 'GridExport', 'External',
            'OneAutarky', 'OneSelfConsumption', 'OneEfficiencyValid'
        ]],
        'Storage' => ['Hauptgerät – Batteriespeicher', [
            'SOC', 'Battery', 'BatteryChargePower', 'BatteryDischargePower', 'BatteryCapacity',
            'BatteryEnergy', 'BatteryEnergyValid', 'BatteryEnergyAboveReserve', 'RscpCapacity',
            'RscpCapacityValid', 'RscpCapacityStatus', 'BatteryDetails'
        ]],
        'Second' => ['Zweites Farmgerät – Messwerte', [
            'SecondPV', 'SecondOneValid'
        ]],
        'SecondStorage' => ['Zweites Farmgerät – Batteriespeicher', [
            'SecondSOC', 'SecondBattery', 'SecondCapacity', 'SecondRscpCapacity',
            'SecondRscpCapacityValid', 'SecondRscpCapacityStatus'
        ]],
        'Wallbox' => ['Wallbox – Betrieb und Leistung', [
            'WallboxAvailable', 'WallboxSolarMode', 'WallboxChargeAllowed', 'WallboxCharging',
            'WallboxVehicleConnected', 'WallboxPhaseMode', 'WallboxPower', 'WallboxSolar',
            'Wallboxes', 'WallboxDetails'
        ]],
        'Daily' => ['Energie – Tageswerte und Prognose', [
            'DayForecast', 'DayForecastAvailable', 'DayStart', 'DayBalanceStart',
            'DayPVEstimate', 'DayPVSource', 'RscpSolarStatus', 'RscpDayEnergy'
        ]],
        'Identity' => ['Hauptgerät – Geräteinformationen', [
            'Manufacturer', 'Model', 'Serial', 'Firmware', 'ModbusVersion', 'RegisterCount'
        ]],
        'Diagnostics' => ['Verbindung und Diagnose', [
            'OperatingModeStatus', 'DataStatus', 'LastSuccess', 'LanSetupStatus', 'ReadNotes', 'DetectedFarmDevices',
            'PowerMeters', 'DCStrings', 'Inverters', 'SGReadyState', 'SGReadyValid',
            'EmergencyState', 'Emergency', 'EMS', 'EMSFlags', 'EfficiencyRaw', 'RemoteStatus'
        ]]
    ];

    private const FARM_GROUPS = ['Farm', 'FarmStorage', 'Second', 'SecondStorage'];
    private const STANDALONE_GROUPS = ['Master', 'Storage', 'Identity', 'Wallbox'];
    private const OBSOLETE_MODBUS = ['ModbusVersion', 'RegisterCount', 'EfficiencyRaw', 'EMSFlags', 'EMS',
        'SGReadyState', 'SGReadyValid', 'Emergency', 'EmergencyState', 'PowerMeters', 'DCStrings',
        'Inverters', 'RemoteStatus', 'WallboxPhaseMode', 'WallboxDetails', 'DetectedFarmDevices',
        'FarmMeterPower', 'HeatPumpConsumption', 'AdditionalConsumerValid', 'FarmHouseConsumption', 'DayHeatPump'];


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
        if (in_array($ident, self::OBSOLETE_MODBUS, true)) {
            return false;
        }
        if (in_array($ident, ['LanSetupStatus', 'DataStatus', 'OperatingModeStatus'], true)) {
            return true;
        }
        if (!filter_var($this->ReadPropertyString('Host'), FILTER_VALIDATE_IP)) {
            return false;
        }
        $group = substr($groupIdent, 9);
        if ($this->isFarmMode()) {
            return !in_array($group, self::STANDALONE_GROUPS, true)
                && (!in_array($group, ['Second', 'SecondStorage'], true) || $this->hasFarmPeer());
        }
        return !in_array($group, self::FARM_GROUPS, true);
    }

    private function maintainGroupedVariable(string $ident, string $name, string $profile, int $type): void
    {
        [$groupIdent, $groupName, $position] = $this->variableLocation($ident);
        if (!$this->shouldCreateVariable($ident, $groupIdent)) {
            return;
        }
        $category = $this->findModuleCategory($this->InstanceID, $groupIdent);
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
            $this->updateVariablePresentation($existing, $ident, $name, $position);
            return;
        }
        $original = $this->findGroupedVariable($ident, $this->InstanceID);
        if ($existing !== false) {
            // Only remove links created by the previous layout for this original variable.
            if ($original === false || !IPS_LinkExists($existing)
                || IPS_GetLink($existing)['TargetID'] !== $original || !IPS_SetIdent($existing, '')) {
                return;
            }
        }
        if ($original !== false) {
            if (IPS_SetParent($original, $category)) {
                $this->updateVariablePresentation($original, $ident, $name, $position);
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

    private function updateVariablePresentation(int $id, string $ident, string $name, int $position): void
    {
        // The configured name of the optional consumer is managed after meter detection.
        if (!in_array($ident, ['HeatPumpConsumption', 'FarmHouseConsumption', 'DayHeatPump', 'DayHouse'], true)
            && IPS_GetName($id) !== $name) {
            IPS_SetName($id, $name);
        }
        IPS_SetPosition($id, $position);
    }

    private function findGroupedVariable(string $ident, int $instance): int|false
    {
        [$groupIdent] = $this->variableLocation($ident);
        $category = $this->findModuleCategory($instance, $groupIdent);
        if ($category !== false && IPS_CategoryExists($category)) {
            $variable = @IPS_GetObjectIDByIdent($ident, $category);
            if ($variable !== false && IPS_VariableExists($variable)) {
                return $variable;
            }
        }
        $variable = @IPS_GetObjectIDByIdent($ident, $instance);
        if ($variable !== false && IPS_VariableExists($variable)) {
            return $variable;
        }
        // Earlier releases may have placed the variable in another module category.
        foreach (array_keys(self::GROUPS) as $group) {
            $previous = $this->findModuleCategory($instance, 'E3DCGroup' . $group);
            if ($previous === false || $previous === $category || !IPS_CategoryExists($previous)) {
                continue;
            }
            $variable = @IPS_GetObjectIDByIdent($ident, $previous);
            if ($variable !== false && IPS_VariableExists($variable)) {
                return $variable;
            }
        }
        return false;
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
        if ($this->shouldCreateVariable($ident, $this->variableLocation($ident)[0])) {
            SetValue($this->groupedVariableID($ident), $value);
        }
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
        $farmRoot = $configured && $farm
            ? $this->structureCategory('CategoryFarm', 'Farmbetrieb', $this->InstanceID) : false;
        if ($farmRoot !== false) {
            IPS_SetHidden($farmRoot, false);
            IPS_SetPosition($farmRoot, 10);
        }
        $devices = $configured ? $this->deviceLayoutCategory() : false;
        $main = $devices !== false && !$farm
            ? $this->structureCategory('Device1', 'Hauptgerät', $devices) : false;
        $peer = $devices !== false && $farm && $this->hasFarmPeer()
            ? $this->structureCategory('Device2', 'Zweites Farmgerät', $devices) : false;
        $system = $this->accessoryCategory($this->InstanceID, 'CategorySystem', 'System und Diagnose');
        IPS_SetPosition($system, 30);
        $positions = ['Farm' => 10, 'FarmStorage' => 15, 'Master' => 20, 'Storage' => 25,
            'Second' => 30, 'SecondStorage' => 35, 'Wallbox' => 40, 'Daily' => 50,
            'Identity' => 60, 'Diagnostics' => 90];
        foreach (self::GROUPS as $group => [$name, $idents]) {
            $ident = 'E3DCGroup' . $group;
            $parent = match ($group) {
                'Farm', 'FarmStorage' => $farmRoot,
                'Daily' => $farm ? $farmRoot : $main,
                'Diagnostics' => $system,
                'Master', 'Storage', 'Identity', 'Wallbox' => $main,
                'Second', 'SecondStorage' => $peer
            };
            $category = $this->findModuleCategory($this->InstanceID, $ident);
            if ($parent === false) {
                if ($group === 'Daily') {
                    $idents = array_merge($idents, array_keys(self::DAILY));
                }
                foreach ($idents as $variableIdent) {
                    $variable = @IPS_GetObjectIDByIdent($variableIdent, $this->InstanceID);
                    if ($variable !== false && IPS_VariableExists($variable)) {
                        $category = $category !== false ? $category
                            : $this->accessoryCategory($this->inactiveCategory(), $ident, $name);
                        if (@IPS_GetObjectIDByIdent($variableIdent, $category) === false) {
                            IPS_SetParent($variable, $category);
                        }
                    }
                }
            }
            if ($category !== false) {
                $this->placeLayoutCategory($category, $parent);
                IPS_SetName($category, !$farm && $group === 'Master' ? 'Einzelgerät – Leistung und Verbrauch' : $name);
                IPS_SetPosition($category, $positions[$group]);
            }
        }
        foreach ([
            'Device1' => $main !== false ? $devices : false,
            'Device2' => $peer !== false ? $devices : false,
            'FarmController' => $farmRoot,
            'CategoryRscpDetails' => $devices !== false ? IPS_GetParent($devices) : false,
            'CategoryFarm' => $farmRoot !== false ? $this->InstanceID : false,
            'CategoryAccessories' => $devices,
            'CategoryExternalSystems' => $system
        ] as $ident => $parent) {
            $category = $this->findModuleCategory($this->InstanceID, $ident);
            if ($category !== false) {
                $this->placeLayoutCategory($category, $parent);
            }
        }
        $this->updateAccessoryLayout($configured, $farm);
    }

    private function deviceLayoutCategory(): int
    {
        $farm = $this->isFarmMode();
        $parent = $farm ? $this->structureCategory('CategoryFarm', 'Farmbetrieb', $this->InstanceID) : $this->InstanceID;
        $devices = $this->structureCategory('CategoryRscpDetails', $farm ? 'Farmgeräte' : 'Einzelgeräte', $parent);
        IPS_SetPosition($devices, $farm ? 70 : 20);
        return $devices;
    }

    private function updateAccessoryLayout(bool $configured, bool $farm): void
    {
        $root = $this->findModuleCategory($this->InstanceID, 'CategoryAccessories');
        if ($root === false || !$configured) {
            return;
        }
        $archive = @IPS_GetObjectIDByIdent('E3DCInactive' . $this->InstanceID, 0);
        $stored = $archive !== false ? @IPS_GetObjectIDByIdent('InactiveAccessories', $archive) : false;
        foreach (IPS_GetChildrenIDs($root) as $id) {
            $key = IPS_GetObject($id)['ObjectIdent'];
            if (IPS_CategoryExists($id) && !$this->accessoryMatchesMode($key, $farm)) {
                $stored = $stored !== false ? $stored
                    : $this->accessoryCategory($this->inactiveCategory(), 'InactiveAccessories', 'Zubehör und Messpunkte');
                IPS_SetParent($id, $stored);
            }
        }
        if ($stored !== false) {
            foreach (IPS_GetChildrenIDs($stored) as $id) {
                if (IPS_CategoryExists($id) && $this->accessoryMatchesMode(IPS_GetObject($id)['ObjectIdent'], $farm)) {
                    IPS_SetParent($id, $root);
                }
            }
        }
    }

    private function accessoryMatchesMode(string $key, bool $farm): bool
    {
        if (!preg_match('/^(?:Zaehler|Wechselrichter|Wallbox)(Farm|Zweit)?[0-9]+$/D', $key, $parts)) {
            return true;
        }
        return match ($parts[1] ?? '') {
            'Farm' => $farm,
            'Zweit' => $farm && $this->hasFarmPeer(),
            default => !$farm
        };
    }

    private function publishExternalSystems(): void
    {
        $configuration = @$this->ReadPropertyString('ExternalSystems');
        if (!is_string($configuration)) {
            return;
        }
        $rows = $this->decodeJsonArray($configuration);
        $root = $this->findModuleCategory($this->InstanceID, 'CategoryExternalSystems');
        if (!is_array($rows) || $rows === []) {
            if ($root !== false) {
                IPS_SetHidden($root, true);
            }
            return;
        }
        $root = $this->structureCategory('CategoryExternalSystems', 'Externe Systeme',
            $this->accessoryCategory($this->InstanceID, 'CategorySystem', 'System und Diagnose'));
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
        $port = $this->ReadPropertyInteger('DeviceRscpPort');
        if ($this->rscpCredentials() === null) {
            return 'Zuerst lokales RSCP aktivieren und die Zugangsdaten übernehmen.';
        }
        $mode = in_array($mode, ['auto', 'standalone', 'farm'], true) ? $mode : $this->ReadPropertyString('OperatingMode');
        $result = $this->findDevices($network, $master, $port, $unit, $mode);
        if (!isset($result['master'])) {
            $this->setGroupedValue('LanSetupStatus', $result['message']);
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
    'DeviceRscpPort' => $_IPS['OnePort']
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
            'OnePort' => $result['port']
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
            $port = $this->ReadPropertyInteger('DeviceRscpPort');
        }
        if ($port < 1 || $port > 65535) {
            return ['message' => 'RSCP-Port zwischen 1 und 65535 eingeben.'];
        }
        $masterIp = $savedMaster !== '' ? $savedMaster : trim($master);
        $farmPort = $this->ReadPropertyInteger('FarmRscpPort');
        $searchFarm = $mode !== 'standalone' && $farmPort > 0 && $farmPort <= 65535 && $farmPort !== $port;
        $matches = [];
        $searched = [];
        foreach ($prefixes as $prefix) {
            if (!in_array($prefix, $localPrefixes, true)
                && !preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $prefix)
                && (!filter_var($savedMaster, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    || !str_starts_with($savedMaster, $prefix)
                    || ($this->discoverIdentity($savedMaster, $port) === null
                        && (!$searchFarm || !$this->discoverFarmController($savedMaster, $farmPort))))) {
                return ['message' => 'Dieses Suchnetz muss am Symcon-Server anliegen oder ein gespeichertes, erreichbares E3/DC-Gerät enthalten.'];
            }
            $searched[] = $prefix . '0/24';
            $devices = $this->scanDeviceSubnet($prefix, $port, false);
            if ($searchFarm) {
                foreach ($this->scanDeviceSubnet($prefix, $farmPort, true) as $address => $controller) {
                    $devices[$address] = array_replace($controller, $devices[$address] ?? [], ['farmMeter' => true]);
                }
            }
            $matches += $devices;
        }
        if ($matches === []) {
            $ports = $searchFarm ? $port . ' und ' . $farmPort : (string) $port;
            return ['message' => 'Kein E3/DC-Gerät mit erfolgreicher RSCP-Abfrage gefunden. Suchnetze: '
                . implode(', ', $searched) . '. RSCP-Ports: ' . $ports . '.'];
        }

        $lines = [];
        foreach ($matches as $host => $device) {
            $lines[] = $host . ($device['farmMeter'] ? ' – E3/DC Farmcontroller' : ' – E3/DC Gerät')
                . ($device['serial'] !== '' ? ' – ' . $device['serial'] : '');
        }
        $summary = implode("\n", $lines);
        return $this->classifyDiscoveredDevices($matches, $masterIp, $mode, $port, $unit, $summary);
    }

    private function classifyDiscoveredDevices(array $matches, string $masterIp, string $mode, int $port, int $unit, string $summary): array
    {
        $farmMasters = array_keys(array_filter($matches, static fn (array $device): bool => $device['farmMeter']));
        if ($mode === 'farm' && count($matches) === 1) {
            return ['message' => $summary . "\n\nNur ein RSCP-Endpunkt bestätigt. Beide Farmgeräte müssen erreichbar sein, bevor die Zuordnung gespeichert wird."];
        }
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
        if (count($matches) !== 2) {
            return ['message' => $summary . "\n\nBitte Hauptgerät und gegebenenfalls das zweite Farmgerät anhand der Treffer eintragen."];
        }
        $serials = array_column($matches, 'serial');
        if ($serials[0] !== '' && $serials[0] === $serials[1]) {
            return ['message' => $summary . "\n\nBeide Adressen melden dieselbe Seriennummer."];
        }
        if (count($farmMasters) === 1) {
            $masterIp = $farmMasters[0];
        } elseif (!$this->hasFarmPeer() || !isset($matches[trim($this->ReadPropertyString('SecondHost'))])
            || $masterIp === trim($this->ReadPropertyString('SecondHost'))) {
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

    private function scanDeviceSubnet(string $prefix, int $port, bool $farmController): array
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
                            if ($farmController) {
                                if ($this->discoverFarmController($host, $port)) {
                                    $matches[$host] = ['serial' => '', 'farmMeter' => true];
                                }
                            } else {
                                $identity = $this->discoverIdentity($host, $port);
                                if ($identity !== null) {
                                    $matches[$host] = $identity + ['farmMeter' => false];
                                }
                            }
                        } catch (RuntimeException $error) {
                            // The port can be open on devices that do not answer as an E3/DC RSCP endpoint.
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

    private function discoverFarmController(string $host, int $port): bool
    {
        $credentials = $this->rscpCredentials();
        if ($credentials === null) {
            return false;
        }
        [$user, $password, $key] = $credentials;
        try {
            $day = (new DateTimeImmutable(date('Y-m-d'), new DateTimeZone('UTC')))->getTimestamp();
            $energy = (new \E3DCConnector\FarmRscp($key))->readDayEnergyWh($host, $port, $user, $password, $day);
            return isset($energy['DB_DC_POWER']);
        } catch (RuntimeException) {
            return false;
        }
    }

    private function discoverIdentity(string $host, int $port): ?array
    {
        $credentials = $this->rscpCredentials();
        if ($credentials === null) {
            return null;
        }
        [$user, $password, $key] = $credentials;
        try {
            return (new \E3DCConnector\FarmRscp($key))->readIdentity($host, $port, $user, $password);
        } catch (RuntimeException) {
            return null;
        }
    }

    public function RunInitialPoll(): void
    {
        $this->runTimerPoll(function (): void {
            $this->SetTimerInterval('InitialPollTimer', 0);
            $this->pollMeasurements();
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
        $host = trim($this->ReadPropertyString('Host'));
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $this->setGroupedValue('DataStatus', 'IP-Adresse des Hauptgeräts fehlt oder ist ungültig.');
            return;
        }
        $lock = 'E3DCConnector.Poll.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            return;
        }

        try {
            $this->publishExternalSystems();
            $notes = [];
            foreach (['FarmConsumptionValid', 'AdditionalConsumerValid', 'FarmPowerValid', 'FarmBatteryValid',
                'BatteryEnergyValid', 'SecondOneValid', 'RscpCapacityValid', 'SecondRscpCapacityValid',
                'SGReadyValid', 'WallboxAvailable', 'OneEfficiencyValid'] as $ident) {
                $this->setGroupedValue($ident, false);
            }
            $farmMode = $this->isFarmMode();
            $readMainDevice = !$farmMode;
            $live = $readMainDevice ? $this->readLocalRscpDevice($host, false, $notes) : [];
            if ($live !== []) {
                $this->publishRscpLive($live);
            }
            $farmValid = false;
            $farm = [];
            $second = [];
            if ($farmMode) {
                $peer = trim($this->ReadPropertyString('SecondHost'));
                if ($peer === '') {
                    $peer = $this->legacyPeerHost();
                }
                if (filter_var($peer, FILTER_VALIDATE_IP) && inet_pton($peer) !== inet_pton($host)) {
                    $second = $this->readLocalRscpDevice($peer, true, $notes);
                    foreach (['PV' => 'SecondPV', 'Battery' => 'SecondBattery', 'SOC' => 'SecondSOC'] as $field => $ident) {
                        if (isset($second[$field])) {
                            $this->setGroupedValue($ident, $second[$field]);
                        }
                    }
                    $this->setGroupedValue('SecondOneValid', isset($second['PV'], $second['Battery']));
                }
                $farm = $this->readRscpFarmLive($host, $notes);
                $farmValid = $this->publishRscpFarmLive($farm);
            }
            $this->publishJson('ReadNotes', $notes);
            $valid = isset($live['PV'], $live['Battery'], $live['House'], $live['Grid']);
            $this->publishDaily($farmValid, $valid ? $this->mergeRscpCore([], $live) : null, isset($farm['WallboxPower']));
            if ($valid || $farmValid || $live !== [] || $farm !== [] || $second !== []) {
                $this->setGroupedValue('LastSuccess', time());
            }
            if ($valid || $farmValid) {
                $message = $farmValid ? 'Messwerte des Farmcontrollers per RSCP aktualisiert.'
                    : 'Messwerte des Hauptgeräts per RSCP aktualisiert.';
                if ($readMainDevice && !$valid) {
                    $message .= ' Hauptgerät: ' . ($notes['RSCP Hauptgerät'] ?? 'Live-Werte unvollständig.');
                }
                $this->setGroupedValue('DataStatus', $message);
                $this->SetStatus(102);
            } else {
                $source = $farmMode ? 'Farmcontroller' : 'Hauptgerät';
                $message = $source . ': ' . ($notes['RSCP ' . $source] ?? 'Keine vollständige RSCP-Live-Antwort.');
                if ($second !== []) {
                    $message .= ' Werte des zweiten Geräts wurden aktualisiert.';
                }
                $this->setGroupedValue('DataStatus', $message);
                $this->SetStatus(201);
            }
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function rscpCredentials(): ?array
    {
        if (!$this->ReadPropertyBoolean('ReadRscpSolar')) {
            return null;
        }
        $user = trim($this->ReadPropertyString('FarmRscpUser'));
        $password = $this->ReadPropertyString('FarmRscpPassword');
        $key = $this->ReadPropertyString('FarmRscpKey');
        return $user !== '' && $password !== '' && $key !== '' ? [$user, $password, $key] : null;
    }

    private function legacyPeerHost(): string
    {
        $peer = $this->ReadPropertyInteger('SecondOne');
        if ($peer <= 0 || $peer === $this->InstanceID || !IPS_InstanceExists($peer)
            || (IPS_GetInstance($peer)['ModuleInfo']['ModuleID'] ?? null) !== '{AAEF96D2-6F24-4FF9-9C9C-C7FECE2C1559}') {
            return '';
        }
        $settings = $this->decodeJsonArray(@IPS_GetConfiguration($peer));
        return is_string($settings['Host'] ?? null) ? trim($settings['Host']) : '';
    }

    private function publishRscpLive(array $live): void
    {
        foreach (array_keys(self::POWER + ['SOC' => '']) as $ident) {
            if (isset($live[$ident])) {
                $this->setGroupedValue($ident, $live[$ident]);
            }
        }
        if (isset($live['Battery'])) {
            $this->setGroupedValue('BatteryChargePower', max(0, $live['Battery']));
            $this->setGroupedValue('BatteryDischargePower', max(0, -$live['Battery']));
        }
        if (isset($live['Grid'])) {
            $this->setGroupedValue('GridImport', max(0, $live['Grid']));
            $this->setGroupedValue('GridExport', max(0, -$live['Grid']));
        }
    }

    private function readRscpFarmLive(string $host, array &$notes): array
    {
        $credentials = $this->rscpCredentials();
        if ($credentials === null) {
            return [];
        }
        [$user, $password, $key] = $credentials;
        try {
            return (new \E3DCConnector\FarmRscp($key))->readLiveValues($host, $this->ReadPropertyInteger('FarmRscpPort'), $user, $password);
        } catch (RuntimeException $error) {
            $notes['RSCP Farmcontroller'] = $host . ':' . $this->ReadPropertyInteger('FarmRscpPort') . ' – ' . $error->getMessage();
            return [];
        }
    }

    private function publishRscpFarmLive(array $live): bool
    {
        foreach (['PV' => 'FarmPV', 'Battery' => 'FarmBatteryPower', 'House' => 'FarmConsumption',
            'Grid' => 'FarmGrid', 'WallboxPower' => 'FarmWallboxPower'] as $field => $ident) {
            if (isset($live[$field])) {
                $this->setGroupedValue($ident, $live[$field]);
            }
        }
        if (isset($live['Battery'])) {
            $this->setGroupedValue('FarmBatteryChargePower', max(0, $live['Battery']));
            $this->setGroupedValue('FarmBatteryDischargePower', max(0, -$live['Battery']));
        }
        if (isset($live['Grid'])) {
            $this->setGroupedValue('FarmGridImport', max(0, $live['Grid']));
            $this->setGroupedValue('FarmGridExport', max(0, -$live['Grid']));
        }
        $valid = isset($live['PV'], $live['Battery'], $live['House'], $live['Grid']);
        $this->setGroupedValue('FarmPowerValid', $valid);
        $this->setGroupedValue('FarmConsumptionValid', isset($live['House']));
        if ($valid && $live['House'] > 0) {
            $this->setGroupedValue('FarmAutarky', round(100 * max(0, $live['House'] - max(0, $live['Grid'])) / $live['House'], 1));
        }
        return $valid;
    }

    private function readLocalRscpDevice(string $host, bool $second, array &$notes): array
    {
        $status = $second ? 'SecondRscpCapacityStatus' : 'RscpCapacityStatus';
        $label = $second ? 'RSCP zweites Farmgerät' : 'RSCP Hauptgerät';
        if (!$this->ReadPropertyBoolean('ReadRscpSolar')) {
            $notes[$label] = 'Lokales RSCP deaktiviert.';
            $this->setGroupedValue($status, $notes[$label]);
            return [];
        }
        $port = $this->ReadPropertyInteger('DeviceRscpPort');
        $credentials = $this->rscpCredentials();
        if ($port < 1 || $port > 65535 || $credentials === null) {
            $notes[$label] = 'RSCP-Port und Zugangsdaten prüfen.';
            $this->setGroupedValue($status, $notes[$label]);
            return [];
        }
        [$user, $password, $key] = $credentials;
        try {
            $data = (new \E3DCConnector\FarmRscp($key))->readDevice($host, $port, $user, $password);
        } catch (RuntimeException $error) {
            $notes[$label] = $host . ':' . $port . ' – ' . $error->getMessage();
            $this->setGroupedValue($status, $notes[$label]);
            return [];
        }
        $live = $data['live'];
        $missing = array_diff(['PV', 'Battery', 'House', 'Grid'], array_keys($live));
        $message = $live === [] ? 'Keine Live-Messwerte.' : 'Live-Werte: ' . implode(', ', array_keys($live)) . '.';
        if ($missing !== []) {
            $message .= ' Fehlend: ' . implode(', ', $missing) . '.';
            if (($data['liveError'] ?? '') !== '') {
                $message .= ' ' . $data['liveError'];
            }
        }
        $notes[$label] = $host . ':' . $port . ' – ' . $message;
        $capacity = $data['specification']['Kapazität (Wh)'] ?? null;
        $valid = $capacity !== null && $capacity > 0;
        $this->setGroupedValue($second ? 'SecondRscpCapacityValid' : 'RscpCapacityValid', $valid);
        if ($valid) {
            $this->setGroupedValue($status, 'Spezifizierte Kapazität für Batterieindex 0 gelesen.');
            $this->setGroupedValue($second ? 'SecondRscpCapacity' : 'RscpCapacity', $capacity / 1000);
        } else {
            $message = $data['batteryError'] ?: ($capacity === null
                ? 'Keine spezifizierte Kapazität für Batterieindex 0 geliefert.'
                : 'Batterieindex 0 meldet keine positive Kapazität.');
            $notes[$label . ' Batterie'] = $message;
            $this->setGroupedValue($status, $message);
        }
        return $live;
    }

    private function mergeRscpCore(array $registers, array $live): array
    {
        foreach (array_keys(self::POWER) as $index => $ident) {
            if (isset($live[$ident])) {
                $registers[2 * $index] = $live[$ident] & 0xFFFF;
                $registers[2 * $index + 1] = ($live[$ident] >> 16) & 0xFFFF;
            }
        }
        if (isset($live['SOC'])) {
            $registers[15] = $live['SOC'];
        }
        return $registers;
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
        $root = $this->findModuleCategory($this->InstanceID, 'CategoryAccessories');
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
            $roleId = @IPS_GetObjectIDByIdent('Role', $category);
            $rows[$key] = [
                'Key' => $key,
                'Name' => ($setting['Name'] ?? '') !== '' ? $setting['Name'] : IPS_GetName($category),
                'Visible' => $setting['Visible'] ?? true,
                'Role' => $roleId !== false && IPS_VariableExists($roleId) ? GetValue($roleId) : ($roles[$key] ?? ''),
                'Current' => $current !== false && IPS_VariableExists($current) && GetValue($current)
                    ? 'Erkannt' : 'Zuletzt erkannt'
            ];
        }
        return array_values($rows);
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

    private function markAccessoryDataStale(): void
    {
        $root = $this->findModuleCategory($this->InstanceID, 'CategoryAccessories');
        if ($root === false || !IPS_CategoryExists($root)) {
            return;
        }
        foreach (IPS_GetChildrenIDs($root) as $category) {
            if (!IPS_CategoryExists($category)) {
                continue;
            }
            $status = @IPS_GetObjectIDByIdent('Aktuell', $category);
            if ($status !== false && IPS_VariableExists($status)) {
                SetValue($status, false);
            }
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

    private function publishAccessoryValue(int $parent, string $ident, string $name, mixed $value, string $profile = ''): void
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
        if ($profile !== '' && IPS_GetVariable($variable)['VariableCustomProfile'] !== $profile) {
            IPS_SetVariableCustomProfile($variable, $profile);
        }
        SetValue($variable, $value);
    }

    public function PollRscpDetails(): void
    {
        $this->runRscpDetails(false);
    }

    public function RefreshRscpDetails(): void
    {
        $this->runRscpDetails(true);
    }

    private function runRscpDetails(bool $manual): void
    {
        $this->runTimerPoll(function () use ($manual): void {
            $interval = $this->ReadPropertyInteger('RscpDetailInterval');
            $enabled = $this->ReadPropertyBoolean('Active') && $this->rscpCredentials() !== null;
            if (!$manual) {
                $this->SetTimerInterval('RscpDetailsTimer', $enabled && $interval > 0 ? max(60, min(3600, $interval)) * 1000 : 0);
            }
            if (!$enabled || (!$manual && $interval <= 0)) {
                return;
            }
            $lock = 'E3DCConnector.Details.' . $this->InstanceID;
            if (!IPS_SemaphoreEnter($lock, 0)) {
                return;
            }
            try {
                $farm = $this->isFarmMode();
                $this->ReadRscpDeviceOverview(false, $farm);
                if ($farm && $this->hasFarmPeer()) {
                    $this->ReadRscpDeviceOverview(true);
                }
                $this->collectRscpAccessories(true);
            } finally {
                IPS_SemaphoreLeave($lock);
            }
        });
    }

    public function ScanRscpAccessories(): string
    {
        $message = $this->collectRscpAccessories();
        $this->ReloadForm();
        return $message;
    }

    private function collectRscpAccessories(bool $skipIndexZero = false): string
    {
        $farm = $this->isFarmMode();
        $targets = $farm ? [[false, true]] : [[false, false]];
        if ($farm && $this->hasFarmPeer()) {
            $targets[] = [true, false];
        }
        $messages = [];
        foreach ($targets as [$second, $controller]) {
            $label = $controller ? 'Farmcontroller' : ($second ? 'Zweites Farmgerät' : 'Hauptgerät');
            try {
                [$client, $host, $port, $user, $password] = $this->rscpTarget($second, $controller);
                $result = $client->readAccessoryInventory($host, $port, $user, $password);
                foreach ($result['entries'] as [$group, $index, $type]) {
                    [$prefix, $name, $role] = match ($group) {
                        'PM' => ['Zaehler', 'Leistungsmesser', match ($type) {
                            4 => 'Zusatzverbrauchszähler', 5 => 'Farmzähler', default => 'Leistungsmesser'
                        }],
                        'PVI' => ['Wechselrichter', 'Wechselrichter', 'Wechselrichter'],
                        'WB' => ['Wallbox', 'Wallbox', 'Wallbox']
                    };
                    $key = $prefix . ($controller ? 'Farm' : ($second ? 'Zweit' : '')) . $index;
                    $this->publishRscpAccessory($key, $name . ' ' . $index, [], $second, $role);
                    if (!$skipIndexZero || $index !== 0) {
                        try {
                            $this->ReadRscpDetails($second, $group, $index, $controller);
                        } catch (RuntimeException $error) {
                            $messages[] = $label . ' ' . $name . ' ' . $index . ': ' . $error->getMessage();
                        }
                    }
                }
                $messages[] = $label . ': ' . count($result['entries']) . ' Zubehörgeräte erkannt.'
                    . ($result['warning'] !== '' ? ' ' . $result['warning'] : '');
            } catch (RuntimeException $error) {
                $messages[] = $label . ': ' . $error->getMessage();
            }
        }
        return implode("\n", $messages);
    }

    public function ReadRscpDetails(bool $secondDevice, string $group, int $index, bool $farmController = false): string
    {
        if ($this->isFarmMode() !== ($secondDevice || $farmController)
            || ($secondDevice && !$this->hasFarmPeer())) {
            throw new RuntimeException('Das gewählte RSCP-Ziel ist in dieser Betriebsart nicht eingerichtet.');
        }
        [$client, $host, $port, $user, $password] = $this->rscpTarget($secondDevice, $farmController);
        $names = ['INFO' => 'Geräteinformationen', 'DCDC' => 'DC/DC-Wandler', 'EMS' => 'Energiemanagement', 'BAT' => 'Batterie und Batteriemodule',
            'PVI' => 'Wechselrichter', 'PM' => 'Leistungsmesser', 'WB' => 'Wallbox'];
        if (!isset($names[$group])) {
            throw new RuntimeException('Unbekannte RSCP-Datengruppe.');
        }
        $root = $farmController ? $this->structureCategory('CategoryFarm', 'Farmbetrieb', $this->InstanceID)
            : $this->deviceLayoutCategory();
        $device = $this->structureCategory($farmController ? 'FarmController' : ($secondDevice ? 'Device2' : 'Device1'),
            $farmController ? 'Farmcontroller' : ($secondDevice ? 'Zweites Farmgerät' : 'Hauptgerät'), $root);
        IPS_SetPosition($device, 60);
        IPS_SetHidden($device, false);
        $category = $this->accessoryCategory($device, $group . $index, $names[$group] . (in_array($group, ['INFO', 'EMS'], true) ? '' : ' ' . $index));
        $kind = ['PVI' => 'Wechselrichter', 'PM' => 'Zaehler', 'WB' => 'Wallbox'][$group] ?? null;
        $key = $kind !== null ? $kind . ($farmController ? 'Farm' : ($secondDevice ? 'Zweit' : '')) . $index : null;
        try {
            $data = $client->readDetails($host, $port, $user, $password, $group, $index);
        } catch (RuntimeException $error) {
            $message = $host . ':' . $port . ' – ' . $error->getMessage();
            $this->publishAccessoryValue($category, 'ReadStatus', 'Status', $message);
            if ($key !== null) {
                $this->markAccessoryPowerStale($key);
            }
            throw new RuntimeException($message, 0, $error);
        }
        $this->publishRscpTree($category, $data);
        $hasMeasurements = !$this->containsOnlyRscpErrors($data);
        $errors = $this->describeRscpErrors($data);
        $status = $hasMeasurements ? 'RSCP-Messwerte gelesen.' : 'Keine Messwerte für diese Datengruppe.';
        if ($errors !== []) {
            $status = implode(' ', $errors) . ' ' . $status;
        }
        $this->publishAccessoryValue($category, 'ReadStatus', 'Status', $status);
        $this->publishAccessoryValue($category, 'LastRead', 'Letzte RSCP-Abfrage', time(), '~UnixTimestamp');
        if ($group === 'INFO' && !$secondDevice && !$farmController) {
            foreach (['INFO_SERIAL_NUMBER' => 'Serial', 'INFO_SW_RELEASE' => 'Firmware'] as $tag => $ident) {
                if (is_string($data[$tag] ?? null)) {
                    $this->setGroupedValue($ident, $data[$tag]);
                }
            }
        }
        if ($key !== null) {
            if ($hasMeasurements) {
                $this->publishRscpAccessory($key, $names[$group] . ' ' . $index, $data, $secondDevice);
            } else {
                $this->markAccessoryPowerStale($key);
            }
        }
        $endpoint = $host . ':' . $port . ' – ';
        return $endpoint . $status;
    }

    public function ReadRscpDeviceOverview(bool $secondDevice, bool $farmController = false): string
    {
        $results = [];
        foreach (['INFO', 'EMS', 'DCDC', 'BAT', 'PVI', 'PM', 'WB'] as $group) {
            try {
                $results[] = $group . ' – ' . $this->ReadRscpDetails($secondDevice, $group, 0, $farmController);
            } catch (RuntimeException $error) {
                $results[] = $group . ' – ' . $error->getMessage();
            }
        }
        return implode("\n", $results);
    }

    private function describeRscpErrors(array $data): array
    {
        $errors = [];
        foreach ($data as $field => $value) {
            if ($field === 'RSCP-Fehler' && is_int($value)) {
                $errors[$value] = match ($value) {
                    1, 7 => 'Abfrage wird nicht unterstützt (RSCP ' . $value . ').',
                    2 => 'Zugriff verweigert (RSCP 2).',
                    3 => 'Anfrageformat abgelehnt (RSCP 3).',
                    4 => 'Gerät bittet um erneuten Versuch (RSCP 4).',
                    5 => 'Index oder Wert außerhalb des Gerätebereichs (RSCP 5).',
                    6 => 'Datengruppe oder Wert nicht verfügbar (RSCP 6).',
                    8 => 'Gerät meldet Datengruppe bereits in Verwendung (RSCP 8).',
                    default => 'RSCP-Fehler ' . $value . '.'
                };
            } elseif (is_array($value)) {
                $errors += $this->describeRscpErrors($value);
            }
        }
        return $errors;
    }

    private function containsOnlyRscpErrors(array $data): bool
    {
        foreach ($data as $field => $value) {
            if ($field === 'RSCP-Fehler' || str_contains($field, '_INDEX')) {
                continue;
            }
            if (!is_array($value) || !$this->containsOnlyRscpErrors($value)) {
                return false;
            }
        }
        return true;
    }

    private function publishRscpAccessory(string $key, string $label, array $data, bool $secondDevice, string $role = ''): void
    {
        $root = $this->structureCategory('CategoryAccessories', 'Zubehör und Messpunkte',
            $this->deviceLayoutCategory());
        IPS_SetPosition($root, 70);
        $settings = $this->accessorySettings() ?? [];
        $default = $label . ($secondDevice ? ' – zweites Farmgerät' : '');
        $category = $this->accessoryCategory($root, $key,
            $this->accessoryName($key, $settings, $this->accessoryLegacyNames() ?? [], $default));
        IPS_SetHidden($category, !($settings[$key]['Visible'] ?? true));
        $this->publishAccessoryValue($category, 'Aktuell', 'Daten gelesen', true);
        if ($role !== '') {
            $this->publishAccessoryValue($category, 'Role', 'Funktion', $role);
        }
        if ($data !== []) {
            $this->publishJsonValue($category, 'RscpSnapshot', 'RSCP-Daten', $data);
            $this->publishAccessoryPower($category, $data);
        }
    }

    private function markAccessoryPowerStale(string $key): void
    {
        $root = $this->findModuleCategory($this->InstanceID, 'CategoryAccessories');
        $category = $root !== false ? @IPS_GetObjectIDByIdent($key, $root) : false;
        if ($category !== false && IPS_CategoryExists($category)) {
            $this->publishAccessoryValue($category, 'PowerCurrent', 'Leistung aktuell', false);
        }
    }

    private function publishAccessoryPower(int $category, array $data): void
    {
        $phases = $this->accessoryPhasePowers($data);
        foreach ($phases as $index => $power) {
            $phase = $index + 1;
            $this->publishAccessoryValue($category, 'PowerL' . $phase, 'Leistung L' . $phase . ' (W)', $power);
        }
        $this->publishAccessoryValue($category, 'PowerCurrent', 'Leistung aktuell', $phases !== []);
        if ($phases !== []) {
            $this->publishAccessoryValue($category, 'PowerTotal', 'Leistung gesamt (W)', (float) array_sum($phases));
        }
    }

    private function accessoryPhasePowers(array $data): array
    {
        $phases = [];
        foreach ($data as $field => $value) {
            if (is_array($value)) {
                if (preg_match('/^PVI_AC_POWER(?: \(W\))?(?: #\d+)?$/D', $field)
                    && is_numeric($value['PVI_INDEX'] ?? null) && is_numeric($value['PVI_VALUE'] ?? null)) {
                    $index = (int) $value['PVI_INDEX'];
                    if ($index >= 0 && $index < 3) {
                        $phases[$index] = (float) $value['PVI_VALUE'];
                    }
                } else {
                    $phases += $this->accessoryPhasePowers($value);
                }
            } elseif (is_numeric($value) && preg_match('/^(?:WB_)?PM_POWER_L([123])(?: \(W\))?$/D', $field, $match)) {
                $phases[(int) $match[1] - 1] = (float) $value;
            }
        }
        ksort($phases);
        return $phases;
    }

    private function publishJsonValue(int $parent, string $ident, string $name, array $data): void
    {
        $this->publishAccessoryValue($parent, $ident, $name,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function publishRscpTree(int $parent, array $data): void
    {
        foreach ($data as $field => $value) {
            $ident = (is_array($value) ? 'Container' : 'Tag') . sha1($field);
            if (is_array($value)) {
                if (str_ends_with($field, ' – Fehler')) {
                    $previous = @IPS_GetObjectIDByIdent($ident, $parent);
                    if ($previous !== false && IPS_CategoryExists($previous)) {
                        IPS_SetHidden($previous, true);
                    }
                    continue;
                }
                $label = match ($field) {
                    'BAT_DATA' => 'Batteriemesswerte',
                    'BAT_SPECIFICATION' => 'Batteriespezifikation',
                    'Details je Modul, Phase oder String' => 'Module, Phasen und Strings',
                    default => $field
                };
                $this->publishRscpTree($this->accessoryCategory($parent, $ident, $label), $value);
            } else {
                $this->publishAccessoryValue($parent, $ident, $field, is_int($value) || is_float($value) ? (float) $value : $value);
            }
        }
    }

    public function SetRscpSetting(bool $secondDevice, string $setting, int $value): string
    {
        [$client, $host, $port, $user, $password] = $this->rscpControlTarget($secondDevice);
        $client->writeSetting($host, $port, $user, $password, $setting, $value);
        return 'RSCP-Befehl vom Gerät bestätigt.';
    }

    public function SetRscpIdlePeriod(bool $secondDevice, int $type, int $day, bool $active, string $start, string $end): string
    {
        [$client, $host, $port, $user, $password] = $this->rscpControlTarget($secondDevice);
        $client->writeIdlePeriod($host, $port, $user, $password, $type, $day, $active, $start, $end);
        return 'RSCP-Sperrzeit vom Gerät bestätigt.';
    }

    public function TestRscpConnections(bool $includeMainDevice = false): string
    {
        $farmMode = $this->isFarmMode();
        $targets = [];
        if (!$farmMode || $includeMainDevice) {
            $targets['Hauptgerät'] = [false, false];
        }
        if ($farmMode) {
            $targets['Farmcontroller'] = [false, true];
            if ($this->hasFarmPeer()) {
                $targets['Zweites Farmgerät'] = [true, false];
            }
        }
        $results = [];
        foreach ($targets as $label => [$second, $farm]) {
            $connection = '';
            try {
                [$client, $host, $port, $user, $password] = $this->rscpTarget($second, $farm);
                $connection = ' ' . $host . ':' . $port;
                $client->testAuthentication($host, $port, $user, $password);
                $state = 'Anmeldung erfolgreich.';
            } catch (RuntimeException $error) {
                $state = $error->getMessage();
            }
            $results[] = $label . $connection . ' – ' . $state;
        }
        return implode("\n", $results);
    }

    private function rscpTarget(bool $secondDevice, bool $farmController = false): array
    {
        if ($farmController && ($secondDevice || !$this->isFarmMode())) {
            throw new RuntimeException('Den Farmcontroller nur im Farmbetrieb als eigenes Ziel wählen.');
        }
        $credentials = $this->rscpCredentials();
        if (!$this->ReadPropertyBoolean('Active') || $credentials === null) {
            throw new RuntimeException('Instanz und lokales RSCP aktivieren und Zugangsdaten hinterlegen.');
        }
        $host = trim($this->ReadPropertyString($secondDevice ? 'SecondHost' : 'Host'));
        if ($secondDevice && $host === '') {
            $host = $this->legacyPeerHost();
        }
        $port = $this->ReadPropertyInteger($farmController ? 'FarmRscpPort' : 'DeviceRscpPort');
        if (!filter_var($host, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535 || ($secondDevice && !$this->isFarmMode())) {
            throw new RuntimeException('RSCP-Zielgerät und Port prüfen.');
        }
        [$user, $password, $key] = $credentials;
        return [new \E3DCConnector\FarmRscp($key), $host, $port, $user, $password];
    }

    private function rscpControlTarget(bool $secondDevice): array
    {
        if (!$this->ReadPropertyBoolean('AllowBatteryControl')) {
            throw new RuntimeException('RSCP-Steuerung in der Instanz freigeben.');
        }
        return $this->rscpTarget($secondDevice);
    }

    public function SetWallboxMode(int $slot, bool $solar): void
    {
        throw new RuntimeException('Der bisherige Wallbox-Befehl war eine Modbus-Funktion. RSCP-Wallboxmodus ist noch nicht freigegeben.');
    }

    public function SetWallboxChargingAllowed(int $slot, bool $allowed): void
    {
        throw new RuntimeException('Der bisherige Wallbox-Befehl war eine Modbus-Funktion. RSCP-Wallbox-Ladefreigabe ist noch nicht freigegeben.');
    }

    public function SetBatteryPower(bool $secondDevice, int $watts): void
    {
        [$client, $host, $port, $user, $password] = $this->rscpControlTarget($secondDevice);
        $client->writeBatteryPower($host, $port, $user, $password, $watts);
    }

    public function StopBatteryControl(bool $secondDevice): void
    {
        [$client, $host, $port, $user, $password] = $this->rscpControlTarget($secondDevice);
        $client->writeBatteryPower($host, $port, $user, $password, 0, true);
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
        $lock = 'E3DCConnector.RscpSolar.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            return;
        }
        try {
            $host = trim($this->ReadPropertyString('Host'));
            $port = $this->ReadPropertyInteger($this->isFarmMode() ? 'FarmRscpPort' : 'DeviceRscpPort');
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
        $daily = $this->findModuleCategory($this->InstanceID, 'E3DCGroupDaily');
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
        $this->setGroupedValue('DayPVSource', 'Aus RSCP-Live-Werten integriert');
    }

    private function publishDaily(bool $valid, ?array $core = null, bool $farmWallboxCurrent = false): void
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
        $topology = $single ? 'single-rscp' : 'farm-rscp';
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
        $balanceKey = 'rscp-v1';
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
        if (!$single && $valid) {
            $powers = [
                'DayPV' => $this->getGroupedValue('FarmPV'),
                'DayBatteryCharge' => $this->getGroupedValue('FarmBatteryChargePower'),
                'DayBatteryDischarge' => $this->getGroupedValue('FarmBatteryDischargePower')
            ];
            if ($farmWallboxCurrent) {
                $powers['DayWallbox'] = $this->getGroupedValue('FarmWallboxPower');
            }
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
                $this->setGroupedValue('DayPVSource', 'Aus RSCP-Live-Werten integriert');
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

    private function publishJson(string $ident, array $data): void
    {
        $this->setGroupedValue($ident, json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

}
