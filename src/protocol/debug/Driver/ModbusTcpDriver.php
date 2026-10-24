<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;

/**
 * Modbus TCP 驱动:MBAP + PDU,支持 function 1/2/3/4/5/6/16
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class ModbusTcpDriver implements DebugDriverInterface
{
    /**
     * @var array<int, string> 异常码
     */
    protected static $exceptionCodes = [
        1 => 'illegal function',
        2 => 'illegal data address',
        3 => 'illegal data value',
        4 => 'server device failure',
        5 => 'acknowledge',
        6 => 'server device busy',
        8 => 'memory parity error',
        10 => 'gateway path unavailable',
        11 => 'gateway target device failed to respond',
    ];

    public function name(): string
    {
        return 'modbus-tcp';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'unitId', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1, 'desc' => 'Modbus unit id'],
            ['name' => 'function', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 3, 'desc' => '1/2 coils, 3/4 registers, 5/6 single write, 16 multiple write'],
            ['name' => 'address', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 0],
            ['name' => 'quantity', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1],
            ['name' => 'values', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Comma-separated values for write functions'],
        ];
    }

    public function encodeRead(int $transactionId, int $function, int $address, int $quantity, int $unitId = 1): string
    {
        $pdu = chr($function & 0xFF) . pack('n', $address & 0xFFFF) . pack('n', $quantity & 0xFFFF);
        return $this->encodeFrame($transactionId, $unitId, $pdu);
    }

    public function encodeWriteSingle(int $transactionId, int $function, int $address, int $value, int $unitId = 1): string
    {
        $pdu = chr($function & 0xFF) . pack('n', $address & 0xFFFF) . pack('n', $value & 0xFFFF);
        return $this->encodeFrame($transactionId, $unitId, $pdu);
    }

    /**
     * @param int[] $values 16 位寄存器值
     */
    public function encodeWriteMultiple(int $transactionId, int $address, array $values, int $unitId = 1): string
    {
        $pdu = chr(16) . pack('n', $address & 0xFFFF) . pack('n', count($values) & 0xFFFF);
        $pdu .= chr(count($values) * 2);
        foreach ($values as $value) {
            $pdu .= pack('n', $value & 0xFFFF);
        }
        return $this->encodeFrame($transactionId, $unitId, $pdu);
    }

    /**
     * 解析完整响应帧(MBAP + PDU)
     * @param string $bytes
     * @return array
     */
    public static function decodeResponse(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length < 9) {
            return ['ok' => false, 'error' => 'modbus response too short'];
        }
        $transactionId = (int)unpack('n', substr($bytes, 0, 2))[1];
        $protocolId = (int)unpack('n', substr($bytes, 2, 2))[1];
        if ($protocolId !== 0) {
            return ['ok' => false, 'error' => 'invalid modbus protocol id: ' . $protocolId];
        }
        $mbapLength = (int)unpack('n', substr($bytes, 4, 2))[1];
        if ($mbapLength < 2 || $mbapLength + 6 > $length) {
            return ['ok' => false, 'error' => 'invalid modbus mbap length: ' . $mbapLength];
        }
        $unitId = ord($bytes[6]);
        $function = ord($bytes[7]);
        $meta = [
            'transactionId' => $transactionId,
            'unitId' => $unitId,
            'hex' => bin2hex($bytes),
        ];
        if (($function & 0x80) !== 0) {
            $code = ord($bytes[8]);
            $name = isset(static::$exceptionCodes[$code]) ? static::$exceptionCodes[$code] : 'unknown exception';
            $meta['exceptionCode'] = $code;
            return ['ok' => false, 'error' => 'modbus exception ' . $code . ': ' . $name, 'function' => $function & 0x7F, 'meta' => $meta];
        }
        switch ($function) {
            case 1:
            case 2:
                $byteCount = ord($bytes[8]);
                if ($length < 9 + $byteCount) {
                    return ['ok' => false, 'error' => 'truncated modbus response'];
                }
                $bits = [];
                for ($i = 0; $i < $byteCount; $i++) {
                    $byte = ord($bytes[9 + $i]);
                    for ($bit = 0; $bit < 8; $bit++) {
                        $bits[] = ($byte >> $bit) & 1;
                    }
                }
                $meta['function'] = $function;
                $meta['values'] = $bits;
                return ['ok' => true, 'body' => 'function ' . $function . ' bits: ' . implode(', ', $bits), 'meta' => $meta];
            case 3:
            case 4:
                $byteCount = ord($bytes[8]);
                if ($byteCount % 2 !== 0 || $length < 9 + $byteCount) {
                    return ['ok' => false, 'error' => 'invalid modbus byte count: ' . $byteCount];
                }
                $values = $byteCount === 0 ? [] : array_values(unpack('n*', substr($bytes, 9, $byteCount)) ?: []);
                $meta['function'] = $function;
                $meta['values'] = $values;
                return ['ok' => true, 'body' => 'function ' . $function . ' registers: ' . implode(', ', $values), 'meta' => $meta];
            case 5:
            case 6:
                if ($length < 12) {
                    return ['ok' => false, 'error' => 'truncated modbus response'];
                }
                $address = (int)unpack('n', substr($bytes, 8, 2))[1];
                $value = (int)unpack('n', substr($bytes, 10, 2))[1];
                $meta['function'] = $function;
                $meta['address'] = $address;
                $meta['value'] = $value;
                return ['ok' => true, 'body' => 'function ' . $function . ' address ' . $address . ' value ' . $value, 'meta' => $meta];
            case 16:
                if ($length < 12) {
                    return ['ok' => false, 'error' => 'truncated modbus response'];
                }
                $address = (int)unpack('n', substr($bytes, 8, 2))[1];
                $quantity = (int)unpack('n', substr($bytes, 10, 2))[1];
                $meta['function'] = $function;
                $meta['address'] = $address;
                $meta['quantity'] = $quantity;
                return ['ok' => true, 'body' => 'function 16 address ' . $address . ' quantity ' . $quantity, 'meta' => $meta];
            default:
                return ['ok' => false, 'error' => 'unsupported modbus response function: ' . $function, 'meta' => $meta];
        }
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($target['scheme'] !== 'tcp') {
            return ['ok' => false, 'error' => 'modbus-tcp 驱动仅支持 tcp 目标'];
        }
        if (isset($input['values']) && !is_string($input['values'])) {
            return ['ok' => false, 'error' => 'values must be a string'];
        }
        $unitId = $this->intField($input, 'unitId', 1);
        $function = $this->intField($input, 'function', 3);
        $address = $this->intField($input, 'address', 0);
        $quantity = $this->intField($input, 'quantity', 1);
        if ($unitId === null || $unitId < 1 || $unitId > 255) {
            return ['ok' => false, 'error' => 'unitId 必须是 1-255 的整数'];
        }
        if ($function === null || !in_array($function, [1, 2, 3, 4, 5, 6, 16], true)) {
            return ['ok' => false, 'error' => 'modbus function 仅支持 1/2/3/4/5/6/16'];
        }
        if ($address === null || $address < 0 || $address > 65535) {
            return ['ok' => false, 'error' => 'address 必须是 0-65535 的整数'];
        }
        $transactionId = random_int(0, 0xFFFF);
        if (in_array($function, [5, 6, 16], true)) {
            $values = $this->parseValues((string)($input['values'] ?? ''));
            if ($values === null || $values === []) {
                return ['ok' => false, 'error' => 'values 必须是逗号分隔的整数'];
            }
            if ($function !== 16 && count($values) !== 1) {
                return ['ok' => false, 'error' => 'function ' . $function . ' 只接受一个 value'];
            }
            if ($function === 5) {
                if (!in_array($values[0], [0, 1], true)) {
                    return ['ok' => false, 'error' => 'function 5 value 仅支持 0 或 1'];
                }
                $request = $this->encodeWriteSingle($transactionId, 5, $address, $values[0] === 1 ? 0xFF00 : 0x0000, $unitId);
            } elseif ($function === 6) {
                if ($values[0] < 0 || $values[0] > 65535) {
                    return ['ok' => false, 'error' => 'function 6 value 必须是 0-65535 的整数'];
                }
                $request = $this->encodeWriteSingle($transactionId, 6, $address, $values[0], $unitId);
            } else {
                if (count($values) > 123) {
                    return ['ok' => false, 'error' => 'function 16 最多写入 123 个寄存器'];
                }
                foreach ($values as $value) {
                    if ($value < 0 || $value > 65535) {
                        return ['ok' => false, 'error' => '寄存器值必须是 0-65535 的整数'];
                    }
                }
                $request = $this->encodeWriteMultiple($transactionId, $address, $values, $unitId);
            }
        } else {
            if ($quantity === null || $quantity < 1 || $quantity > ($function <= 2 ? 2000 : 125)) {
                return ['ok' => false, 'error' => 'quantity 超出范围'];
            }
            $request = $this->encodeRead($transactionId, $function, $address, $quantity, $unitId);
        }
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($request) > $maxBytes) {
            return ['ok' => false, 'error' => 'request exceeds max_bytes'];
        }
        $timeout = (float)($context['timeout'] ?? 5);
        if ($timeout <= 0) {
            $timeout = 5.0;
        }
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $target['host'] . ':' . $target['port'],
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!$socket) {
            $message = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'connect failed (' . $errno . ')';
            return ['ok' => false, 'error' => $message];
        }
        stream_set_blocking($socket, true);
        $deadline = microtime(true) + $timeout;
        try {
            if (!$this->writeAll($socket, $request)) {
                return ['ok' => false, 'error' => 'write failed'];
            }
            $header = $this->readExact($socket, 6, $deadline);
            if ($header === null) {
                return ['ok' => false, 'error' => 'read timed out'];
            }
            $mbapLength = (int)unpack('n', substr($header, 4, 2))[1];
            if ($mbapLength < 2 || $mbapLength > 254) {
                return ['ok' => false, 'error' => 'invalid modbus mbap length: ' . $mbapLength];
            }
            $rest = $this->readExact($socket, $mbapLength, $deadline);
            if ($rest === null) {
                return ['ok' => false, 'error' => 'read timed out'];
            }
        } finally {
            fclose($socket);
        }
        $response = $header . $rest;
        if (strlen($response) > $maxBytes) {
            return ['ok' => false, 'error' => 'response exceeds max_bytes'];
        }
        $decoded = static::decodeResponse($response);
        if (isset($decoded['meta']['transactionId']) && $decoded['meta']['transactionId'] !== $transactionId) {
            return ['ok' => false, 'error' => 'modbus transaction id mismatch'];
        }
        if (!isset($decoded['meta'])) {
            $decoded['meta'] = [];
        }
        $decoded['meta']['durationMs'] = (int)round((microtime(true) - $start) * 1000);
        $decoded['meta']['bytes'] = strlen($response);
        return $decoded;
    }

    protected function encodeFrame(int $transactionId, int $unitId, string $pdu): string
    {
        $mbap = pack('n', $transactionId & 0xFFFF) . pack('n', 0) . pack('n', (strlen($pdu) + 1) & 0xFFFF) . chr($unitId & 0xFF);
        return $mbap . $pdu;
    }

    protected function intField(array $input, string $key, int $default): ?int
    {
        $raw = $input[$key] ?? $default;
        if (!is_numeric($raw) || floor((float)$raw) !== (float)$raw) {
            return null;
        }
        return (int)$raw;
    }

    /**
     * @return int[]|null
     */
    protected function parseValues(string $values): ?array
    {
        if (trim($values) === '') {
            return [];
        }
        $parsed = [];
        foreach (explode(',', $values) as $value) {
            $value = trim($value);
            if (!is_numeric($value) || floor((float)$value) !== (float)$value) {
                return null;
            }
            $parsed[] = (int)$value;
        }
        return $parsed;
    }

    protected function writeAll($socket, string $data): bool
    {
        $written = 0;
        $total = strlen($data);
        while ($written < $total) {
            $bytes = @fwrite($socket, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                return false;
            }
            $written += $bytes;
        }
        return true;
    }

    protected function readExact($socket, int $length, float $deadline): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return null;
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $seconds = (int)$left;
            $micros = (int)(($left - $seconds) * 1000000);
            if (@stream_select($read, $write, $except, $seconds, $micros) !== 1) {
                return null;
            }
            $chunk = @fread($socket, $length - strlen($buffer));
            if ($chunk === false) {
                return null;
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    return null;
                }
                continue;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
