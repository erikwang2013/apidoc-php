<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use RuntimeException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Input;
use erikwang2013\apidoc\protocol\debug\Support\Socket;

/**
 * IEC 60870-5-104 只读最小集:STARTDT + 总召唤 / 单点读
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class Iec104Driver implements DebugDriverInterface
{
    protected const MAX_FRAMES = 1000;
    protected const MAX_APDU = 253;

    public function name(): string
    {
        return 'iec104';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'operation', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'interrogation', 'desc' => 'interrogation or read'],
            ['name' => 'common_address', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1],
            ['name' => 'ioa', 'in' => 'meta', 'type' => 'number', 'require' => false, 'desc' => 'Required for read'],
            ['name' => 'duration_ms', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 3000],
        ];
    }

    public function send(array $input, array $context): array
    {
        $started = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
            if (!in_array($target['scheme'], ['iec104', 'iec-60870-5-104', 'tcp'], true)) {
                return ['ok' => false, 'error' => 'iec104 驱动仅支持 iec104/tcp 目标'];
            }
            $commonAddress = Input::int($input, 'common_address', 1, 0, 0xFFFF);
            $timeout = max(0.05, (float)($context['timeout'] ?? 5));
            $duration = min(
                max(1, Input::int($input, 'duration_ms', 3000, 1, PHP_INT_MAX)),
                max(1, (int)round($timeout * 1000))
            );
            $operation = Input::string($input, 'operation', 'interrogation');
            $asdu = $this->readRequest($operation, $commonAddress, $input);
            $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
            $socket = Socket::connect($target, $context, $timeout);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => 'iec104 connect failed: ' . $e->getMessage()];
        }
        try {
            $deadline = microtime(true) + $timeout;
            $this->writeApdu($socket, "\x07\x00\x00\x00", '');
            $startedDt = false;
            while (!$startedDt) {
                $body = $this->readApdu($socket, $deadline);
                if ($body === null) {
                    return ['ok' => false, 'error' => 'iec104 STARTDT was not confirmed'];
                }
                if (strlen($body) === 4 && ord($body[0]) === 0x0B) {
                    $startedDt = true;
                }
            }
            $this->writeApdu($socket, "\x00\x00\x00\x00", $asdu);
            $collectDeadline = microtime(true) + $duration / 1000;
            [$rows, $receivedSeq] = $this->collect($socket, $collectDeadline, $maxBytes);
            $this->writeApdu(
                $socket,
                "\x01\x00" . chr(($receivedSeq << 1) & 0xFF) . chr(($receivedSeq >> 7) & 0xFF),
                ''
            );
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            fclose($socket);
        }
        if ($rows === []) {
            return ['ok' => false, 'error' => 'iec104 timed out after ' . $duration . 'ms without an ASDU'];
        }
        $body = json_encode(['asdu' => $rows], JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false, 'error' => 'encode response failed'];
        }
        return [
            'ok' => true,
            'body' => $body,
            'encoding' => 'text',
            'meta' => [
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'bytes' => strlen($body),
                'rows' => count($rows),
            ],
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function readRequest(string $operation, int $commonAddress, array $input): string
    {
        switch ($operation) {
            case 'interrogation':
                return "\x64\x01\x06\x00" . pack('v', $commonAddress) . "\x00\x00\x00\x14";
            case 'read':
                $raw = $input['ioa'] ?? null;
                if (
                    !is_int($raw)
                    && !(is_string($raw) && preg_match('/^\d+$/', $raw) === 1)
                ) {
                    throw new InvalidArgumentException('iec104 `ioa` is required for read');
                }
                $ioa = (int)$raw;
                if ($ioa > 0xFFFFFF) {
                    throw new InvalidArgumentException('iec104 ioa must fit in 24 bits');
                }
                return "\x66\x01\x05\x00" . pack('v', $commonAddress) . substr(pack('V', $ioa), 0, 3);
            default:
                throw new InvalidArgumentException(
                    'unsupported iec104 operation `' . $operation . '`; use interrogation or read'
                );
        }
    }

    /**
     * @throws RuntimeException
     */
    protected function writeApdu($socket, string $control, string $asdu): void
    {
        $length = 4 + strlen($asdu);
        if ($length > self::MAX_APDU) {
            throw new RuntimeException('iec104 ASDU exceeds ' . self::MAX_APDU . ' bytes');
        }
        if (!Socket::writeAll($socket, "\x68" . chr($length) . $control . $asdu)) {
            throw new RuntimeException('iec104 write failed');
        }
    }

    /**
     * 返回 ctrl(4)+ASDU;超时/EOF 返回 null
     * @throws RuntimeException
     */
    protected function readApdu($socket, float $deadline): ?string
    {
        try {
            $header = Socket::readExactOrNull($socket, 2, $deadline, 'iec104 apdu header');
            if ($header === null) {
                return null;
            }
            if ($header[0] !== "\x68") {
                throw new RuntimeException('invalid iec104 start byte');
            }
            $length = ord($header[1]);
            if ($length < 4 || $length > self::MAX_APDU) {
                throw new RuntimeException('invalid iec104 APDU length ' . $length);
            }
            return Socket::readExactOrNull($socket, $length, $deadline, 'iec104 apdu');
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'connection closed') !== false) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @return array{0:array,1:int} [rows,receivedSeq]
     * @throws RuntimeException
     */
    protected function collect($socket, float $deadline, int $maxBytes): array
    {
        $rows = [];
        $total = 0;
        $receivedSeq = 0;
        for ($frames = 0; $frames < self::MAX_FRAMES; $frames++) {
            $body = $this->readApdu($socket, $deadline);
            if ($body === null) {
                break;
            }
            $total += strlen($body) + 2;
            if ($total > $maxBytes) {
                throw new RuntimeException('iec104 response exceeds max_bytes (' . $maxBytes . ')');
            }
            if ((ord($body[0]) & 0x01) !== 0) {
                continue;
            }
            $receivedSeq = (unpack('v', substr($body, 2, 2))[1]) >> 1;
            foreach ($this->parseAsdus(substr($body, 4)) as $row) {
                $rows[] = $row;
            }
        }
        return [$rows, $receivedSeq];
    }

    /**
     * @throws InvalidArgumentException
     */
    public function parseAsdus(string $data): array
    {
        $rows = [];
        $pos = 0;
        $total = strlen($data);
        while ($pos < $total) {
            if ($total - $pos < 6) {
                throw new InvalidArgumentException('truncated iec104 ASDU header');
            }
            $typeId = ord($data[$pos]);
            $vsq = ord($data[$pos + 1]);
            $sequence = ($vsq & 0x80) !== 0;
            $count = $vsq & 0x7F;
            $cause = ord($data[$pos + 2]) & 0x3F;
            $pos += 6;
            $baseIoa = 0;
            for ($index = 0; $index < $count; $index++) {
                if ($sequence && $index > 0) {
                    $ioa = $baseIoa + $index;
                } else {
                    if ($pos + 3 > $total) {
                        throw new InvalidArgumentException('truncated iec104 IOA');
                    }
                    $baseIoa = ord($data[$pos]) | (ord($data[$pos + 1]) << 8) | (ord($data[$pos + 2]) << 16);
                    $ioa = $baseIoa;
                    $pos += 3;
                }
                [$value, $used] = $this->valueOf($typeId, $data, $pos);
                if ($pos + $used > $total) {
                    throw new InvalidArgumentException('truncated iec104 value');
                }
                $pos += $used;
                $rows[] = [
                    'type_id' => $typeId,
                    'cause' => $cause,
                    'ioa' => $ioa,
                    'value' => $value,
                ];
            }
        }
        return $rows;
    }

    /**
     * @return array{0:mixed,1:int} [value,usedBytes]
     * @throws InvalidArgumentException
     */
    protected function valueOf(int $typeId, string $data, int $pos): array
    {
        $byte = function (int $offset) use ($data, $pos): int {
            if ($pos + $offset >= strlen($data)) {
                throw new InvalidArgumentException('truncated iec104 value');
            }
            return ord($data[$pos + $offset]);
        };
        $i16At = function () use ($data, $pos): int {
            if ($pos + 2 > strlen($data)) {
                throw new InvalidArgumentException('truncated iec104 value');
            }
            $value = unpack('v', substr($data, $pos, 2))[1];
            return $value >= 0x8000 ? $value - 0x10000 : $value;
        };
        $i32At = function () use ($data, $pos): int {
            if ($pos + 4 > strlen($data)) {
                throw new InvalidArgumentException('truncated iec104 value');
            }
            $value = unpack('V', substr($data, $pos, 4))[1];
            return $value >= 0x80000000 ? $value - 0x100000000 : $value;
        };
        $f32At = function () use ($data, $pos): float|string {
            if ($pos + 4 > strlen($data)) {
                throw new InvalidArgumentException('truncated iec104 value');
            }
            $value = unpack('g', substr($data, $pos, 4))[1];
            if (is_finite($value)) {
                return $value;
            }
            if (is_nan($value)) {
                return 'NaN';
            }
            return $value > 0 ? 'inf' : '-inf';
        };
        switch ($typeId) {
            case 1:
                return [($byte(0) & 0x01) !== 0, 1];
            case 3:
                return [$byte(0) & 0x03, 1];
            case 5:
                return [$byte(0), 2];
            case 9:
            case 11:
                return [$i16At(), 3];
            case 13:
                return [$f32At(), 5];
            case 15:
                return [$i32At(), 5];
            case 37:
                return [$i32At(), 12];
            case 30:
                return [($byte(0) & 0x01) !== 0, 8];
            case 31:
                return [$byte(0) & 0x03, 8];
            case 34:
            case 35:
                return [$i16At(), 10];
            case 36:
                return [$f32At(), 12];
            case 45:
            case 46:
            case 47:
                return [($byte(0) & 0x01) !== 0, 1];
            case 48:
            case 49:
                return [$i16At(), 3];
            case 50:
                return [$f32At(), 5];
            case 58:
            case 59:
            case 60:
                return [($byte(0) & 0x01) !== 0, 8];
            case 61:
                return [$i16At(), 10];
            case 62:
                return [$f32At(), 12];
            case 63:
                return [null, 8];
            case 70:
            case 100:
            case 101:
                return [$byte(0), 1];
            case 102:
                return [null, 0];
            case 103:
                return [null, 7];
            default:
                throw new InvalidArgumentException(
                    'unsupported iec104 ASDU type ' . $typeId . '; read-only monitoring types are supported'
                );
        }
    }
}
