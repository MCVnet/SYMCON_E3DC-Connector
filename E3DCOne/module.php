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
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('Unit', 1);
        $this->RegisterPropertyInteger('Interval', 15);
        $this->RegisterPropertyBoolean('ReadWallbox', false);
        $this->RegisterPropertyInteger('FarmConsumptionMeter', -1);
        $this->RegisterPropertyInteger('HeatPumpMeter', -1);
        $this->RegisterPropertyInteger('SecondOne', 0);
        $this->RegisterTimer('PollTimer', 0, 'E3DCF_Poll($_IPS["TARGET"]);');

        $position = 0;
        foreach (self::POWER as $ident => $label) {
            $this->RegisterVariableInteger($ident, $label, '', ++$position);
        }
        $this->RegisterVariableInteger('SOC', 'Batterieladezustand (%)', '', 10);
        $this->RegisterVariableInteger('EMS', 'EMS-Status (Rohwert)', '', 11);
        $this->RegisterVariableString('Wallboxes', 'Wallbox-Erkennung', '', 12);
        $this->RegisterVariableInteger('LastSuccess', 'Letzte erfolgreiche Abfrage', '~UnixTimestamp', 13);
        $this->RegisterVariableString('DataStatus', 'Datenstatus', '', 14);
        $this->RegisterVariableString('Manufacturer', 'Hersteller', '', 15);
        $this->RegisterVariableString('Model', 'Modell', '', 16);
        $this->RegisterVariableString('Serial', 'Seriennummer', '', 17);
        $this->RegisterVariableString('Firmware', 'Firmware-Release', '', 18);
        $this->RegisterVariableString('ModbusVersion', 'Modbus-Firmware-Version', '', 19);
        $this->RegisterVariableInteger('RegisterCount', 'Gemeldete Registeranzahl', '', 20);
        $this->RegisterVariableInteger('Emergency', 'Notstromstatus (Rohwert)', '', 21);
        $this->RegisterVariableString('EfficiencyRaw', 'Autarkie und Eigenverbrauch (Register 40082)', '', 22);
        $this->RegisterVariableString('EMSFlags', 'EMS-Status (Details)', '', 23);
        $this->RegisterVariableInteger('FarmConsumption', 'Farm-Gesamtverbrauch (W)', '', 31);
        $this->RegisterVariableInteger('HeatPumpConsumption', 'Wärmepumpe (W)', '', 32);
        $this->RegisterVariableInteger('FarmHouseConsumption', 'Hausverbrauch ohne Wärmepumpe (W)', '', 33);
        $this->RegisterVariableBoolean('FarmConsumptionValid', 'Farm-Verbrauchswerte aktuell', '', 34);
        $this->RegisterVariableFloat('FarmBatterySOC', 'Farm-Batterieladezustand (%)', '', 35);
        $this->RegisterVariableInteger('FarmBatteryPower', 'Farm-Batterieleistung (W)', '', 36);
        $this->RegisterVariableBoolean('FarmBatteryValid', 'Farm-Batteriewerte aktuell', '', 37);
        $position = 24;
        foreach (self::EXTRA_GROUPS as $ident => $label) {
            $this->RegisterVariableString($ident, $label, '', $position++);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('PollTimer', 0);
        $this->SetValue('FarmBatteryValid', false);
        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetStatus(104);
            return;
        }
        $this->SetTimerInterval('PollTimer', max(5, min(3600, $this->ReadPropertyInteger('Interval'))) * 1000);
    }

    public function Poll(): void
    {
        $lock = 'E3DCOne.Poll.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            return;
        }

        try {
            $this->SetValue('FarmConsumptionValid', false);
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
                $this->SetValue('LastSuccess', time());
                $this->readDetails($socket, $unit);
            } finally {
                fclose($socket);
            }

            $this->SetValue('DataStatus', 'Messwerte aktualisiert');
            $this->SetStatus(102);
        } catch (RuntimeException $error) {
            $this->SetValue('FarmBatteryValid', false);
            $this->SetValue('DataStatus', $error->getMessage());
            $this->SetStatus(201);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function publishCore(array $registers): void
    {
        $index = 0;
        foreach (self::POWER as $ident => $label) {
            $this->SetValue($ident, $this->signed32($registers, $index));
            $index += 2;
        }
        $this->SetValue('SOC', $registers[15]);
        $this->SetValue('Emergency', $registers[16]);
        $this->SetValue('EfficiencyRaw', sprintf('0x%04X', $registers[14]));
        $ems = $registers[17];
        $this->SetValue('EMS', $ems);
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

        $wallboxes = [];
        $summary = [];
        $wallboxStatus = 'Abfrage deaktiviert';
        if ($this->ReadPropertyBoolean('ReadWallbox')) {
            $values = $read('Wallbox-Slots', 40088, 8);
            $wallboxStatus = $values === null ? 'Wallbox-Abfrage fehlgeschlagen' : 'Kein Wallbox-Slot verfügbar';
            foreach ($values ?? [] as $slot => $state) {
                $available = ($state & 1) !== 0;
                $details = ['Verfügbar' => $available, 'Rohwert' => $state];
                if ($available) {
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
        $this->SetValue('Wallboxes', $summary === [] ? $wallboxStatus : implode(' | ', $summary));
        $this->publishJson('WallboxDetails', $wallboxes);

        $identity = $read('Geräteinformationen', 40002, 66);
        if ($identity !== null) {
            foreach (['Manufacturer', 'Model', 'Serial', 'Firmware'] as $index => $ident) {
                $fields = array_slice($identity, 2 + 16 * $index, 16);
                $this->SetValue($ident, trim(explode("\0", pack('n*', ...$fields), 2)[0]));
            }
            $this->SetValue('ModbusVersion', sprintf('%d.%d', $identity[0] >> 8, $identity[0] & 255));
            $this->SetValue('RegisterCount', $identity[1]);
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
        if ($meters !== null) {
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
        $peer = $this->ReadPropertyInteger('SecondOne');
        if ($peer === 0) {
            return;
        }
        $moduleId = '{AAEF96D2-6F24-4FF9-9C9C-C7FECE2C1559}';
        if ($peer === $this->InstanceID || !IPS_InstanceExists($peer)
            || IPS_GetInstance($peer)['ModuleInfo']['ModuleID'] !== $moduleId) {
            $this->SetValue('FarmBatteryValid', false);
            $notes['Farm-Batterie'] = 'Die Instanz des zweiten one auswählen.';
            return;
        }
        $detailsId = @IPS_GetObjectIDByIdent('BatteryDetails', $peer);
        $powerId = @IPS_GetObjectIDByIdent('Battery', $peer);
        $statusId = @IPS_GetObjectIDByIdent('LastSuccess', $peer);
        $remote = [];
        if ($detailsId > 0 && $powerId > 0 && $statusId > 0) {
            $peerInterval = max(5, min(3600, IPS_GetProperty($peer, 'Interval')));
            $maxAge = 2 * $peerInterval + 10;
            if (time() - GetValue($statusId) <= $maxAge
                && time() - IPS_GetVariable($detailsId)['VariableUpdated'] <= $maxAge) {
                $remote = json_decode(GetValue($detailsId), true) ?? [];
            }
        }

        foreach ([$local, $remote] as $storage) {
            if (($storage['Max. speicherbare Energie (Wh)'] ?? 0) <= 0
                || !isset($storage['Verbleibende Energie (Wh)'])
                || $storage['Verbleibende Energie (Wh)'] < 0
                || $storage['Verbleibende Energie (Wh)'] > $storage['Max. speicherbare Energie (Wh)']) {
                $this->SetValue('FarmBatteryValid', false);
                $notes['Farm-Batterie'] = 'Aktuelle, plausible Speicherenergie beider one erforderlich.';
                return;
            }
        }

        $energy = $local['Verbleibende Energie (Wh)'] + $remote['Verbleibende Energie (Wh)'];
        $capacity = $local['Max. speicherbare Energie (Wh)'] + $remote['Max. speicherbare Energie (Wh)'];
        $this->SetValue('FarmBatterySOC', round(100 * $energy / $capacity, 1));
        $this->SetValue('FarmBatteryPower', $this->GetValue('Battery') + GetValue($powerId));
        $this->SetValue('FarmBatteryValid', true);
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
        $this->SetValue('FarmConsumption', $total);
        $this->SetValue('HeatPumpConsumption', $heat);
        $this->SetValue('FarmHouseConsumption', $total - $heat);
        $this->SetValue('FarmConsumptionValid', true);
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
        $this->SetValue($ident, json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function readRegisters($socket, int $unit, int $transaction, int $register, int $count): array
    {
        $request = pack('nnnCCnn', $transaction, 0, 6, $unit, 3, $register - 1, $count);
        if (fwrite($socket, $request) !== strlen($request)) {
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
            $part = fread($socket, $length - strlen($data));
            if ($part === false || $part === '') {
                throw new RuntimeException('Modbus-Antwort unvollständig oder Zeitlimit erreicht.');
            }
            $data .= $part;
        }
        return $data;
    }
}
