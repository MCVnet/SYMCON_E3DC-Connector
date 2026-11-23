<?php

declare(strict_types=1);

namespace E3DCConnector;

use E3DCConnector\Crypto\Rijndael;
use RuntimeException;

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

    public function readDevice(string $host, int $port, string $user, string $password): array
    {
        $socket = $this->openSession($host, $port, $user, $password);
        try {
            $liveTags = [1 => 'PV', 2 => 'Battery', 3 => 'House', 4 => 'Grid', 5 => 'External',
                8 => 'SOC', 31 => 'WallboxPower', 32 => 'WallboxSolar'];
            $request = '';
            foreach ($liveTags as $offset => $name) {
                $request .= self::element(0x01000000 + $offset, 0, '');
            }
            try {
                $answer = $this->exchange($socket, $request);
            } catch (RuntimeException $error) {
                // The device may reject bundled live requests but still support BAT_REQ_SPECIFICATION.
                fclose($socket);
                $socket = null;
                $session = new self($this->key);
                $specificationSocket = $session->openSession($host, $port, $user, $password);
                try {
                    $battery = $session->readSpecification($specificationSocket);
                    return ['live' => [], 'liveError' => $error->getMessage()] + $battery;
                } finally {
                    fclose($specificationSocket);
                }
            }
            $live = [];
            foreach ($liveTags as $offset => $name) {
                $element = $answer[0x01800000 + $offset] ?? null;
                $value = $element === null ? null : self::decodeEnergy($element);
                if ($value === null || $value < -2147483648 || $value > 2147483647
                    || ($name === 'SOC' && ($value < 0 || $value > 100))) {
                    continue;
                }
                $live[$name] = (int) round($value);
            }

            return ['live' => $live] + $this->readSpecification($socket);
        } finally {
            if ($socket !== null) {
                fclose($socket);
            }
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
            if ($response === null || $response[0] === self::ERROR || $response[1] === ''
                || ord($response[1][0]) === 0) {
                throw new RuntimeException('RSCP-Anmeldung wurde abgewiesen.');
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
            $values[$tag] = [$type, $type === self::CONTAINER ? self::parseElements($value, $depth + 1) : $value];
        }
        return $values;
    }
}
