<?php

declare(strict_types=1);

namespace E3DCConnector;

use E3DCConnector\Crypto\Rijndael;
use RuntimeException;

require_once __DIR__ . '/RscpDetailCatalog.php';
require_once __DIR__ . '/Rijndael/Base.php';
require_once __DIR__ . '/Rijndael/Rijndael.php';

final class FarmRscp
{
    private const BLOCK_SIZE = 32;
    private const HEADER_SIZE = 18;
    private const AUTH_REQUEST = 0x00000001;
    private const AUTH_RESPONSE = 0x00800001;
    private const DAY_REQUEST = 0x06000100;
    private const DAY_RESPONSE = 0x06800100;
    private const DAY_SUM = 0x06800010;
    private const DAY_ENERGY_TAGS = [
        0x06800002 => 'DB_BAT_POWER_IN',
        0x06800003 => 'DB_BAT_POWER_OUT',
        0x06800004 => 'DB_DC_POWER',
        0x06800005 => 'DB_GRID_POWER_IN',
        0x06800006 => 'DB_GRID_POWER_OUT',
        0x06800007 => 'DB_CONSUMPTION',
        0x06800008 => 'DB_PM_0_POWER',
        0x06800009 => 'DB_PM_1_POWER',
        0x0680000C => 'DB_CONSUMED_PRODUCTION',
        0x0680000E => 'DB_PRODUCTION_POWER',
    ];
    private const CONTAINER = 0x0E;
    private const ERROR = 0xFF;

    private Rijndael $cipher;
    private string $sendIV;
    private string $receiveIV;

    public function __construct(private readonly string $key)
    {
        if ($key === '' || strlen($key) > self::BLOCK_SIZE) {
            throw new RuntimeException('RSCP-Schlüssel muss 1 bis 32 Bytes lang sein.');
        }
        $this->cipher = new Rijndael(Rijndael::MODE_CBC);
        $this->cipher->setPreferredEngine(Rijndael::ENGINE_INTERNAL);
        $this->cipher->setBlockLength(256);
        $this->cipher->setKeyLength(256);
        $this->cipher->setKey(str_pad($key, self::BLOCK_SIZE, "\xFF"));
        $this->cipher->disablePadding();
        $this->sendIV = $this->receiveIV = str_repeat("\xFF", self::BLOCK_SIZE);
    }

    public function readDayEnergyWh(string $host, int $port, string $user, string $password, int $day): array
    {
        $socket = $this->openSession($host, $port, $user, $password);
        try {
            $period = self::element(0x06000101, 0x09, pack('P', $day))
                . self::element(0x06000102, 0x09, pack('P', 86400))
                . self::element(0x06000103, 0x09, pack('P', 86400));
            $answer = $this->exchange($socket, self::element(self::DAY_REQUEST, self::CONTAINER, $period));
            $sum = $answer[self::DAY_RESPONSE][1][self::DAY_SUM] ?? null;
            if (($sum[0] ?? null) !== self::CONTAINER) {
                throw new RuntimeException('RSCP lieferte keine Tagessummen.');
            }
            $energy = [];
            foreach (self::DAY_ENERGY_TAGS as $tag => $name) {
                $element = $sum[1][$tag] ?? null;
                if ($element === null) {
                    continue;
                }
                $value = self::decodeEnergy($element);
                if ($value !== null) {
                    $energy[$name] = $value;
                }
            }
            return $energy;
        } finally {
            fclose($socket);
        }
    }

    private const LIVE_TAGS = [1 => 'PV', 2 => 'Battery', 3 => 'House', 4 => 'Grid', 5 => 'External',
        8 => 'SOC', 31 => 'WallboxPower', 32 => 'WallboxSolar'];

    public function readDevice(string $host, int $port, string $user, string $password, bool $includeSpecification = true): array
    {
        $socket = $this->openSession($host, $port, $user, $password);
        $session = $this;
        $live = [];
        $liveError = '';
        try {
            $request = '';
            foreach (self::LIVE_TAGS as $offset => $name) {
                $request .= self::element(0x01000000 + $offset, 0, '');
            }
            try {
                $live = self::decodeLiveValues($this->exchange($socket, $request));
            } catch (RuntimeException $error) {
                $liveError = $error->getMessage();
            }

            if (!isset($live['PV'], $live['Battery'], $live['House'], $live['Grid'])) {
                // Some endpoints close the connection or send separate frames for a bundled request.
                // Reconnect first so unread bundled responses cannot be mistaken for single responses.
                fclose($socket);
                $socket = null;
                $session = new self($this->key);
                try {
                    $socket = $session->openSession($host, $port, $user, $password);
                    foreach ([1, 2, 3, 4, 8] as $offset) {
                        if (isset($live[self::LIVE_TAGS[$offset]])) {
                            continue;
                        }
                        $reply = $session->exchange($socket, self::element(0x01000000 + $offset, 0, ''));
                        $live += self::decodeLiveValues($reply);
                    }
                } catch (RuntimeException $error) {
                    $liveError = $error->getMessage();
                    if ($socket !== null) {
                        fclose($socket);
                        $socket = null;
                    }
                }
            }

            if (!$includeSpecification) {
                if ($live === [] && $liveError !== '') {
                    throw new RuntimeException($liveError);
                }
                return $live;
            }
            if ($socket === null) {
                $session = new self($this->key);
                try {
                    $socket = $session->openSession($host, $port, $user, $password);
                } catch (RuntimeException $error) {
                    return ['live' => $live, 'liveError' => $liveError, 'specification' => [], 'batteryError' => $error->getMessage()];
                }
            }
            return ['live' => $live, 'liveError' => $liveError] + $session->readSpecification($socket);
        } finally {
            if ($socket !== null) {
                fclose($socket);
            }
        }
    }

    private static function decodeLiveValues(array $answer): array
    {
        $live = [];
        foreach (self::LIVE_TAGS as $offset => $name) {
            $element = $answer[0x01800000 + $offset] ?? null;
            $value = $element === null ? null : self::decodeEnergy($element);
            if ($value !== null && $value >= -2147483648 && $value <= 2147483647
                && ($name !== 'SOC' || ($value >= 0 && $value <= 100))) {
                $live[$name] = (int) round($value);
            }
        }
        return $live;
    }

    public function readLiveValues(string $host, int $port, string $user, string $password): array
    {
        return $this->readDevice($host, $port, $user, $password, false);
    }

    public function readDetails(string $host, int $port, string $user, string $password, string $group, int $index): array
    {
        if (!isset(RscpDetailCatalog::REQUESTS[$group]) || !in_array($group, ['INFO', 'EMS', 'DCDC', 'BAT', 'PVI', 'PM', 'WB'], true)
            || $index < 0 || $index > 255) {
            throw new RuntimeException('Ungültige RSCP-Datengruppe oder Index.');
        }
        $socket = $this->openSession($host, $port, $user, $password);
        try {
            $payload = '';
            foreach (RscpDetailCatalog::REQUESTS[$group] as $tag) {
                $payload .= self::element($tag, 0, '');
            }
            if ($group === 'EMS' || $group === 'INFO') {
                return self::describe($this->exchange($socket, $payload));
            }
            [$request, $indexType] = ['BAT' => [0x03040000, 5], 'DCDC' => [0x04040000, 5], 'PVI' => [0x02040000, 5],
                'PM' => [0x05040000, 5], 'WB' => [0x0E040000, 3]][$group];
            $prefix = self::element($request + 1, $indexType, pack($indexType === 3 ? 'C' : 'v', $index));
            $reply = $this->exchange($socket, self::element($request, self::CONTAINER, $prefix . $payload));
            $data = $reply[$request + 0x00800000] ?? null;
            if (($data[0] ?? null) !== self::CONTAINER) {
                return self::describe($reply);
            }
            $more = '';
            if ($group === 'BAT') {
                $more = self::indexedRequests($data[1], 0x0380000D, 64, 5, RscpDetailCatalog::REQUESTS['DCB']);
            } elseif ($group === 'PVI') {
                $more = self::indexedRequests($data[1], 0x028AC000, 3, 3, RscpDetailCatalog::REQUESTS['PVI_AC'])
                    . self::indexedRequests($data[1], 0x028DC000, 16, 3, RscpDetailCatalog::REQUESTS['PVI_DC'])
                    . self::indexedRequests($data[1], 0x02800101, 64, 3, [0x02000100]);
            }
            $described = self::describe($reply);
            if ($more !== '') {
                try {
                    $extra = $this->exchange($socket, self::element($request, self::CONTAINER, $prefix . $more));
                    $described['Details je Modul, Phase oder String'] = self::describe($extra);
                } catch (RuntimeException $error) {
                    $described['Status Detailabfrage'] = $error->getMessage();
                }
            }
            return $described;
        } finally {
            fclose($socket);
        }
    }

    private static function indexedRequests(array $reply, int $countTag, int $maximum, int $type, array $tags): string
    {
        $count = isset($reply[$countTag]) ? (int) self::decodeEnergy($reply[$countTag]) : 0;
        $payload = '';
        for ($index = 0; $index < min($count, $maximum); $index++) {
            foreach ($tags as $tag) {
                $payload .= self::element($tag, $type, pack($type === 3 ? 'C' : 'v', $index));
            }
        }
        return $payload;
    }

    private static function describe(array $elements): array
    {
        $result = [];
        foreach ($elements as $key => [$type, $data]) {
            $tag = $key & 0xFFFFFFFF;
            $position = $key >> 32;
            $label = RscpDetailCatalog::FIELDS[$tag] ?? sprintf('0x%08X', $tag);
            if ($position > 0) {
                $label .= ' #' . ($position + 1);
            }
            if ($type === self::ERROR) {
                $label .= ' – Fehler';
            }
            $value = match ($type) {
                self::CONTAINER => self::describe($data),
                self::ERROR => ['RSCP-Fehler' => strlen($data) === 4 ? unpack('V', $data)[1] : -1],
                1 => strlen($data) === 1 ? ord($data) !== 0 : null,
                0x0D => rtrim($data, "\0"),
                0x0C, 0x10 => bin2hex($data),
                0x0F => strlen($data) === 12 ? unpack('P', $data)[1] : null,
                default => self::decodeEnergy([$type, $data])
            };
            if ($value !== null && $value !== []) {
                $result[$label] = $value;
            }
        }
        return $result;
    }

    public function testAuthentication(string $host, int $port, string $user, string $password): void
    {
        fclose($this->openSession($host, $port, $user, $password));
    }

    public function readIdentity(string $host, int $port, string $user, string $password): array
    {
        $socket = $this->openSession($host, $port, $user, $password);
        try {
            $reply = $this->exchange($socket, self::element(0x0A000001, 0, '') . self::element(0x0A000019, 0, ''));
            $serial = $reply[0x0A800001] ?? null;
            $firmware = $reply[0x0A800019] ?? null;
            return [
                'serial' => ($serial[0] ?? null) === 0x0D ? rtrim($serial[1], "\0") : '',
                'firmware' => ($firmware[0] ?? null) === 0x0D ? rtrim($firmware[1], "\0") : ''
            ];
        } finally {
            fclose($socket);
        }
    }

    public function writeSetting(string $host, int $port, string $user, string $password, string $setting, int $value): void
    {
        $specification = [
            'Leistungslimits' => [0x01000100, 1, 1, 0x01800100],
            'Ladelimit' => [0x01000101, 7, 100000, 0x01800101],
            'Entladelimit' => [0x01000102, 7, 100000, 0x01800102],
            'Entladeschwelle' => [0x01000103, 7, 100000, 0x01800103],
            'Stromsparen' => [0x01000104, 3, 1, 0x01800104],
            'Wetterbasiertes Laden' => [0x01000105, 3, 1, 0x01800105],
            'Wallbox priorisieren' => [0x01000078, 3, 1, 0],
            'Batterie zur Wallbox entladen' => [0x01000076, 3, 1, 0],
            'Manuelle Ladung (Wh)' => [0x0100008F, 7, 100000, 0],
            'Notstromtest starten' => [0x01000090, 1, 1, 0]
        ][$setting] ?? null;
        if ($specification === null || $value < 0 || $value > $specification[2]
            || (in_array($setting, ['Manuelle Ladung (Wh)', 'Notstromtest starten'], true) && $value === 0)) {
            throw new RuntimeException('Ungültige Einstellung oder Wert außerhalb des erlaubten Bereichs.');
        }
        [$tag, $type, , $resultTag] = $specification;
        $data = $setting === 'Wallbox priorisieren' ? 1 - $value : $value;
        $command = self::element($tag, $type, pack($type === 7 ? 'V' : 'C', $data));
        $socket = $this->openSession($host, $port, $user, $password);
        try {
            if ($resultTag !== 0) {
                $response = $this->exchange($socket, self::element(0x0100008C, self::CONTAINER, $command))[0x0180008C] ?? null;
                $ack = ($response[0] ?? null) === self::CONTAINER ? ($response[1][$resultTag] ?? null) : null;
                $code = $ack !== null ? self::decodeEnergy($ack) : null;
                if ($code !== 0 && (!in_array($setting, ['Ladelimit', 'Entladelimit'], true) || $code !== 1)) {
                    throw new RuntimeException('Das E3/DC-Gerät hat die Einstellung nicht bestätigt.');
                }
            } else {
                $response = $this->exchange($socket, $command)[$tag + 0x00800000] ?? null;
                if ($response === null || $response[0] === self::ERROR
                    || ($tag === 0x0100008F && ($response[1] === '' || ord($response[1][0]) !== 1))) {
                    throw new RuntimeException('Das E3/DC-Gerät hat den Befehl nicht bestätigt.');
                }
            }
        } finally {
            fclose($socket);
        }
    }

    public function writeBatteryPower(string $host, int $port, string $user, string $password, int $watts, bool $automatic = false): void
    {
        if ($watts < -100000 || $watts > 100000) {
            throw new RuntimeException('Batterie-Sollleistung außerhalb des erlaubten Bereichs.');
        }
        $socket = $this->openSession($host, $port, $user, $password);
        try {
            $mode = $automatic ? 0 : ($watts < 0 ? 2 : ($watts > 0 ? 3 : 1));
            $request = self::element(0x01000031, 3, chr($mode)) . self::element(0x01000032, 6, pack('V', abs($watts)));
            $response = $this->exchange($socket, self::element(0x01000030, self::CONTAINER, $request))[0x01800030] ?? null;
            if ($response === null || self::decodeEnergy($response) !== abs($watts)) {
                throw new RuntimeException('Das E3/DC-Gerät hat die Batterie-Sollleistung nicht bestätigt.');
            }
        } finally {
            fclose($socket);
        }
    }

    public function writeIdlePeriod(string $host, int $port, string $user, string $password, int $type, int $day, bool $active, string $start, string $end): void
    {
        if ($type < 0 || $type > 1 || $day < 0 || $day > 6
            || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $start)
            || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $end)) {
            throw new RuntimeException('Sperrzeit ungültig. Uhrzeiten im Format HH:MM eingeben.');
        }
        $clock = static function (string $time): string {
            [$hour, $minute] = explode(':', $time);
            return self::element(0x01000087, 3, chr((int) $hour)) . self::element(0x01000088, 3, chr((int) $minute));
        };
        $period = self::element(0x01000083, 3, chr($type)) . self::element(0x01000084, 3, chr($day))
            . self::element(0x01000089, 1, chr((int) $active))
            . self::element(0x01000085, self::CONTAINER, $clock($start))
            . self::element(0x01000086, self::CONTAINER, $clock($end));
        $request = self::element(0x01000081, self::CONTAINER, self::element(0x01000082, self::CONTAINER, $period));
        $socket = $this->openSession($host, $port, $user, $password);
        try {
            $response = $this->exchange($socket, $request)[0x01800081] ?? null;
            if ($response === null || $response[0] === self::ERROR) {
                throw new RuntimeException('Das E3/DC-Gerät hat die Sperrzeit nicht bestätigt.');
            }
            $readback = $this->exchange($socket, self::element(0x01000080, 0, ''))[0x01800080] ?? null;
            if (($readback[0] ?? null) !== self::CONTAINER) {
                throw new RuntimeException('Die Sperrzeit konnte nicht zurückgelesen werden.');
            }
            foreach ($readback[1] as $tag => [$format, $fields]) {
                if (($tag & 0xFFFFFFFF) !== 0x01000082 || $format !== self::CONTAINER
                    || ($fields[0x01000083][1] ?? '') !== chr($type) || ($fields[0x01000084][1] ?? '') !== chr($day)) {
                    continue;
                }
                $actualClock = static function (array $fields): string {
                    return sprintf('%02d:%02d', ord($fields[0x01000087][1] ?? "\xFF"), ord($fields[0x01000088][1] ?? "\xFF"));
                };
                if (($fields[0x01000089][1] ?? '') === chr((int) $active)
                    && $actualClock($fields[0x01000085][1] ?? []) === $start
                    && $actualClock($fields[0x01000086][1] ?? []) === $end) {
                    return;
                }
            }
            throw new RuntimeException('Die zurückgelesene Sperrzeit entspricht nicht der Vorgabe.');
        } finally {
            fclose($socket);
        }
    }

    private function readSpecification($socket): array
    {
        $specification = [];
        $batteryError = '';
        try {
            $request = self::element(0x03040001, 0x05, pack('v', 0))
                . self::element(0x03000043, 0, '');
            $answer = $this->exchange($socket, self::element(0x03040000, self::CONTAINER, $request));
            $battery = $answer[0x03840000] ?? null;
            $container = ($battery[0] ?? null) === self::CONTAINER ? ($battery[1][0x03800043] ?? null) : null;
            $capacity = ($container[0] ?? null) === self::CONTAINER ? ($container[1][0x03800125] ?? null) : null;
            foreach ([$battery, $container, $capacity] as $element) {
                if (($element[0] ?? null) === self::ERROR && strlen($element[1]) === 4) {
                    $batteryError = 'RSCP-Batterieabfrage: ' . match (unpack('V', $element[1])[1]) {
                        1 => 'Abfrage wird nicht unterstützt.',
                        2 => 'Zugriff verweigert.',
                        6 => 'Wert nicht verfügbar.',
                        default => 'Fehlercode ' . unpack('V', $element[1])[1] . '.'
                    };
                    break;
                }
            }
            if (($container[0] ?? null) === self::CONTAINER) {
                foreach ([0x03800125 => 'Kapazität (Wh)', 0x03800126 => 'Spezifizierte Entladeleistung (W)',
                    0x03800127 => 'Spezifizierte Ladeleistung (W)'] as $tag => $name) {
                    if (isset($container[1][$tag])) {
                        $value = self::decodeEnergy($container[1][$tag]);
                        if ($value !== null) {
                            $specification[$name] = $value;
                        }
                    }
                }
            }
        } catch (RuntimeException $error) {
            $batteryError = $error->getMessage();
        }
        return ['specification' => $specification, 'batteryError' => $batteryError];
    }

    private function openSession(string $host, int $port, string $user, string $password)
    {
        $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $socket = @stream_socket_client('tcp://' . $address . ':' . $port, $code, $message, 2.0);
        if ($socket === false) {
            throw new RuntimeException('RSCP-Gerät ist nicht erreichbar.');
        }
        try {
            stream_set_timeout($socket, 2);
            $credentials = self::element(2, 0x0D, $user) . self::element(3, 0x0D, $password);
            $auth = $this->exchange($socket, self::element(self::AUTH_REQUEST, self::CONTAINER, $credentials));
            $response = $auth[self::AUTH_RESPONSE] ?? null;
            if ($response === null) {
                throw new RuntimeException('Der RSCP-Endpunkt lieferte keine Anmeldeantwort.');
            }
            if ($response[0] === self::ERROR) {
                $errorCode = strlen($response[1]) === 4 ? unpack('V', $response[1])[1] : null;
                throw new RuntimeException($errorCode === 2 ? 'RSCP-Anmeldung abgewiesen (Zugriff verweigert).'
                    : 'RSCP-Anmeldung fehlgeschlagen' . ($errorCode === null ? '.' : ' (Fehlercode ' . $errorCode . ').'));
            }
            $accessLevel = self::decodeEnergy($response);
            if ($accessLevel === 0) {
                throw new RuntimeException('RSCP-Anmeldung abgewiesen (Benutzerebene 0).');
            }
            if ($accessLevel === null || $accessLevel < 0) {
                throw new RuntimeException('RSCP-Endpunkt lieferte eine ungültige Benutzerebene.');
            }

            return $socket;
        } catch (RuntimeException $error) {
            fclose($socket);
            throw $error;
        }
    }

    private static function decodeEnergy(array $element): int|float|null
    {
        [$type, $data] = $element;
        $format = [0x02 => 'c', 0x03 => 'C', 0x04 => 'v', 0x05 => 'v', 0x06 => 'V', 0x07 => 'V', 0x08 => 'P', 0x09 => 'P', 0x0A => 'g', 0x0B => 'e'][$type] ?? null;
        $length = [0x02 => 1, 0x03 => 1, 0x04 => 2, 0x05 => 2, 0x06 => 4, 0x07 => 4, 0x08 => 8, 0x09 => 8, 0x0A => 4, 0x0B => 8][$type] ?? null;
        if ($format === null || strlen($data) !== $length) {
            return null;
        }
        $value = unpack($format, $data)[1];
        if ($type === 0x04 && $value >= 0x8000) {
            $value -= 0x10000;
        }
        if ($type === 0x06 && $value >= 0x80000000) {
            $value -= 0x100000000;
        }
        return is_finite((float) $value) ? $value : null;
    }

    private static function element(int $tag, int $type, string $value): string
    {
        return pack('VCv', $tag, $type, strlen($value)) . $value;
    }

    private function exchange($socket, string $request): array
    {
        $frame = "\xE3\xDC\x00\x11" . pack('PVv', time(), 0, strlen($request)) . $request;
        $frame .= pack('V', crc32($frame));
        $frame = str_pad($frame, (int) (ceil(strlen($frame) / self::BLOCK_SIZE) * self::BLOCK_SIZE), "\0");
        $this->cipher->setIV($this->sendIV);
        $encrypted = $this->cipher->encrypt($frame);
        $this->sendIV = substr($encrypted, -self::BLOCK_SIZE);
        for ($sent = 0, $length = strlen($encrypted); $sent < $length; $sent += $written) {
            $written = @fwrite($socket, substr($encrypted, $sent));
            if (!$written) {
                throw new RuntimeException('RSCP-Anfrage konnte nicht gesendet werden.');
            }
        }

        $encrypted = $this->readBytes($socket, self::BLOCK_SIZE);
        $this->cipher->setIV($this->receiveIV);
        $header = $this->cipher->decrypt($encrypted);
        if (substr($header, 0, 2) !== "\xE3\xDC") {
            throw new RuntimeException('RSCP-Antwort konnte nicht entschlüsselt werden.');
        }
        $payloadLength = unpack('v', substr($header, 16, 2))[1];
        $crcPresent = (ord($header[3]) & 0x10) !== 0;
        $dataLength = self::HEADER_SIZE + $payloadLength;
        $length = (int) (ceil(($dataLength + ($crcPresent ? 4 : 0)) / self::BLOCK_SIZE) * self::BLOCK_SIZE);
        if ($length > self::BLOCK_SIZE) {
            $encrypted .= $this->readBytes($socket, $length - self::BLOCK_SIZE);
        }
        $this->cipher->setIV($this->receiveIV);
        $frame = $this->cipher->decrypt($encrypted);
        $this->receiveIV = substr($encrypted, -self::BLOCK_SIZE);

        if ($crcPresent && pack('V', crc32(substr($frame, 0, $dataLength))) !== substr($frame, $dataLength, 4)) {
            throw new RuntimeException('Prüfsumme der RSCP-Antwort ist ungültig.');
        }
        return self::parseElements(substr($frame, self::HEADER_SIZE, $payloadLength));
    }

    private function readBytes($socket, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = @fread($socket, $length - strlen($bytes));
            if ($part === false || $part === '') {
                throw new RuntimeException('RSCP-Verbindung geschlossen oder Zeitlimit erreicht.');
            }
            $bytes .= $part;
        }
        return $bytes;
    }

    private static function parseElements(string $data, int $depth = 0): array
    {
        if ($depth > 8) {
            throw new RuntimeException('RSCP-Antwort ist zu tief verschachtelt.');
        }
        $values = [];
        $size = strlen($data);
        for ($offset = 0; $offset < $size; $offset += 7 + $length) {
            if ($size - $offset < 7) {
                throw new RuntimeException('RSCP-Datenelement ist unvollständig.');
            }
            ['tag' => $tag, 'type' => $type, 'length' => $length] = unpack('Vtag/Ctype/vlength', substr($data, $offset, 7));
            if ($length > $size - $offset - 7) {
                throw new RuntimeException('RSCP-Datenelement ist unvollständig.');
            }
            $value = substr($data, $offset + 7, $length);
            while (isset($values[$tag])) {
                $tag += 0x100000000;
            }
            $values[$tag] = [$type, $type === self::CONTAINER ? self::parseElements($value, $depth + 1) : $value];
        }
        return $values;
    }
}
