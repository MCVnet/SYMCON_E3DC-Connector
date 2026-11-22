<?php

declare(strict_types=1);

class E3DCOne extends IPSModule
{
    private const POWER = [
        'PV' => 'PV-Leistung (W)',
        'Battery' => 'Batterieleistung (W)',
        'House' => 'Hausverbrauch (W)',
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
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('PollTimer', 0);
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
                $wallboxes = 'Abfrage deaktiviert';
                if ($this->ReadPropertyBoolean('ReadWallbox')) {
                    $wallboxes = $this->readWallboxStatus($socket, $unit);
                }
            } finally {
                fclose($socket);
            }

            $index = 0;
            foreach (self::POWER as $ident => $label) {
                $low = $registers[$index++];
                $high = $registers[$index++];
                $this->SetValue($ident, ($high >= 32768 ? $high - 65536 : $high) * 65536 + $low);
            }
            $this->SetValue('SOC', $registers[15]);
            $this->SetValue('EMS', $registers[17]);
            $this->SetValue('Wallboxes', $wallboxes);
            $this->SetValue('LastSuccess', time());
            $this->SetValue('DataStatus', 'Messwerte aktualisiert');
            $this->SetStatus(102);
        } catch (RuntimeException $error) {
            $this->SetValue('DataStatus', $error->getMessage());
            $this->SetStatus(201);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function readWallboxStatus($socket, int $unit): string
    {
        try {
            $states = $this->readRegisters($socket, $unit, 3, 40088, 8);
        } catch (RuntimeException $error) {
            return 'Wallbox-Abfrage fehlgeschlagen. ' . $error->getMessage();
        }
        $summary = [];
        foreach ($states as $slot => $state) {
            if (($state & 1) === 0) {
                continue;
            }
            $mode = ($state & 2) !== 0 ? 'Solarbetrieb' : 'Mischbetrieb';
            $charging = ($state & 8) !== 0 ? 'lädt' : 'lädt nicht';
            $summary[] = "Slot $slot: $mode, $charging";
        }
        return $summary === [] ? 'Kein Wallbox-Slot verfügbar' : implode(' | ', $summary);
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
