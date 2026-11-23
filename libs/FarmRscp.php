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
    private const SOLAR_ENERGY = 0x06800004;
    private const CONTAINER = 0x0E;
    private const ERROR = 0xFF;

    private Rijndael $cipher;
    private string $sendIV;
    private string $receiveIV;

    public function __construct(string $key)
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

    public function readDaySolarKwh(string $host, int $port, string $user, string $password, int $day): float
    {
        $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $socket = @stream_socket_client('tcp://' . $address . ':' . $port, $code, $message, 2.0);
        if ($socket === false) {
            throw new RuntimeException('RSCP-Farmcontroller ist nicht erreichbar.');
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

            $period = self::element(0x06000101, 0x09, pack('P', $day))
                . self::element(0x06000102, 0x09, pack('P', 86400))
                . self::element(0x06000103, 0x09, pack('P', 86400));
            $answer = $this->exchange($socket, self::element(self::DAY_REQUEST, self::CONTAINER, $period));
            $sum = $answer[self::DAY_RESPONSE][1][self::DAY_SUM] ?? null;
            $solar = $sum[1][self::SOLAR_ENERGY] ?? null;
            if ($solar === null || !in_array($solar[0], [0x06, 0x07, 0x08, 0x09, 0x0A, 0x0B], true)) {
                throw new RuntimeException('RSCP lieferte keinen Solar-Tagesertrag.');
            }
            $format = [0x06 => 'V', 0x07 => 'V', 0x08 => 'P', 0x09 => 'P', 0x0A => 'g', 0x0B => 'e'][$solar[0]];
            $bytes = in_array($format, ['V', 'g'], true) ? 4 : 8;
            if (strlen($solar[1]) !== $bytes) {
                throw new RuntimeException('RSCP lieferte einen ungültigen Solar-Tagesertrag.');
            }
            $wh = unpack($format, $solar[1])[1];
            if (!is_finite((float) $wh) || $wh < 0 || ($solar[0] === 0x06 && $wh >= 0x80000000)) {
                throw new RuntimeException('RSCP lieferte einen ungültigen Solar-Tagesertrag.');
            }
            return $wh / 1000;
        } finally {
            fclose($socket);
        }
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
