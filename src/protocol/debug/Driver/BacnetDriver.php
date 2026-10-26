<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use RuntimeException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Input;
use erikwang2013\apidoc\protocol\debug\Support\TargetGuard;
use erikwang2013\apidoc\protocol\debug\Support\UdpSocket;

/**
 * BACnet/IP 最小集:Who-Is / I-Am 与 ReadProperty
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class BacnetDriver implements DebugDriverInterface
{
    protected const MAX_FRAMES = 1000;
    protected const PRESENT_VALUE = 85;

    public function name(): string
    {
        return 'bacnet';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'operation', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'who_is', 'desc' => 'who_is or read_property'],
            ['name' => 'broadcast', 'in' => 'meta', 'type' => 'boolean', 'require' => false, 'default' => false, 'desc' => 'who_is only'],
            ['name' => 'object_type', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 8],
            ['name' => 'object_instance', 'in' => 'meta', 'type' => 'number', 'require' => false, 'desc' => 'Required for read_property'],
            ['name' => 'property', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 85],
            ['name' => 'duration_ms', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 3000],
        ];
    }

    public function send(array $input, array $context): array
    {
        $started = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
            $bareTcp = $target['scheme'] === 'tcp'
                && strpos((string)($target['original'] ?? ''), '://') === false;
            if (!in_array($target['scheme'], ['bacnet', 'bacnet-ip', 'udp'], true) && !$bareTcp) {
                return ['ok' => false, 'error' => 'bacnet 驱动仅支持 bacnet/udp 目标'];
            }
            $broadcast = Input::bool($input, 'broadcast');
            $this->validateDestination($target, $context, $broadcast);
            $operation = Input::string($input, 'operation', 'who_is');
            $apdu = $this->requestApdu($operation, $broadcast, $input);
            $packet = $this->encodeBvlc($broadcast, $apdu);
            $timeout = max(0.05, (float)($context['timeout'] ?? 5));
            $duration = min(
                max(1, Input::int($input, 'duration_ms', 3000, 1, PHP_INT_MAX)),
                max(1, (int)round($timeout * 1000))
            );
            $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
            $socket = UdpSocket::bind($target, $context);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => 'bacnet bind failed: ' . $e->getMessage()];
        }
        try {
            if ($broadcast && !UdpSocket::enableBroadcast($socket)) {
                return ['ok' => false, 'error' => 'bacnet broadcast socket failed'];
            }
            UdpSocket::send($socket, $packet, $target, $context);
            $deadline = microtime(true) + $duration / 1000;
            $devices = [];
            $propertyValue = null;
            $total = 0;
            for ($frames = 0; $frames < self::MAX_FRAMES; $frames++) {
                $datagram = UdpSocket::receive($socket, $deadline);
                if ($datagram === null) {
                    break;
                }
                $data = $datagram['data'];
                $total += strlen($data);
                if ($total > $maxBytes) {
                    return ['ok' => false, 'error' => 'bacnet response exceeds max_bytes (' . $maxBytes . ')'];
                }
                $decoded = $this->decodeBvlc($data);
                if ($decoded === null) {
                    continue;
                }
                if ($operation === 'who_is') {
                    $instance = $this->parseIAm($decoded);
                    if ($instance !== null) {
                        $devices[] = ['instance' => $instance, 'address' => $datagram['peer']];
                    }
                    continue;
                }
                if (strlen($decoded) > 0 && in_array(ord($decoded[0]), [0x30, 0x50, 0x60, 0x70], true)) {
                    $propertyValue = $this->parseReadPropertyAck($decoded);
                    break;
                }
            }
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => 'bacnet recv failed: ' . $e->getMessage()];
        } finally {
            fclose($socket);
        }
        if ($operation === 'who_is') {
            if ($devices === []) {
                return ['ok' => false, 'error' => 'bacnet who_is timed out after ' . $duration . 'ms without I-Am'];
            }
            $body = json_encode(['devices' => $devices], JSON_UNESCAPED_SLASHES);
        } else {
            if ($propertyValue === null) {
                return ['ok' => false, 'error' => 'bacnet read_property timed out after ' . $duration . 'ms'];
            }
            $body = json_encode(['value' => $propertyValue], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
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
            ],
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function requestApdu(string $operation, bool $broadcast, array $input): string
    {
        if ($operation === 'who_is') {
            return "\x10\x08";
        }
        if ($operation !== 'read_property') {
            throw new InvalidArgumentException(
                'unsupported bacnet operation `' . $operation . '`; use who_is or read_property'
            );
        }
        if ($broadcast) {
            throw new InvalidArgumentException('bacnet broadcast is only valid for who_is');
        }
        $objectType = Input::int($input, 'object_type', 8, 0);
        if ($objectType > 0xFFFF) {
            $objectType = 8;
        }
        $objectInstance = $this->requiredInstance($input);
        $property = Input::int($input, 'property', self::PRESENT_VALUE, 0);
        if ($property > 0xFFFF) {
            $property = self::PRESENT_VALUE;
        }
        if ($objectInstance > 0x3FFFFF) {
            throw new InvalidArgumentException('bacnet object_instance must fit in 22 bits');
        }
        if ($objectType > 0x3FF || $property > 0xFF) {
            throw new InvalidArgumentException(
                'bacnet object_type <= 1023 and property <= 255 are required'
            );
        }
        $objectId = ($objectType << 22) | $objectInstance;
        return "\x00\x00\x0C\xC4" . pack('N', $objectId) . "\x19" . chr($property);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function requiredInstance(array $input): int
    {
        if (!isset($input['object_instance']) || !is_scalar($input['object_instance'])) {
            throw new InvalidArgumentException('bacnet object_instance is required');
        }
        $value = (string)$input['object_instance'];
        if (preg_match('/^\d+$/', $value) !== 1 || (int)$value > 0xFFFFFFFF) {
            throw new InvalidArgumentException('invalid bacnet object_instance');
        }
        return (int)$value;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function validateDestination(array $target, array $context, bool $broadcast): void
    {
        if ($broadcast && (($context['debugConfig']['allow_private_networks'] ?? false) !== true)) {
            throw new InvalidArgumentException('bacnet broadcast requires DebugConfig.allow_private_networks = true');
        }
        $ips = TargetGuard::ips($context);
        if ($ips === []) {
            $host = trim((string)($target['host'] ?? ''), '[]');
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $ips = [$host];
            }
        }
        foreach ($ips as $ip) {
            if ($ip === '255.255.255.255') {
                throw new InvalidArgumentException('255.255.255.255 is never a valid BACnet target');
            }
            $bin = @inet_pton($ip);
            if ($bin === false) {
                continue;
            }
            if (strlen($bin) === 4 && ord($bin[0]) >= 224) {
                throw new InvalidArgumentException('BACnet/IP multicast targets are not supported');
            }
            if (strlen($bin) === 16 && ord($bin[0]) === 0xFF) {
                throw new InvalidArgumentException('BACnet/IP multicast targets are not supported');
            }
        }
    }

    /**
     * BVLC:`81 0A/0B len(BE) NPDU APDU`;length 含 4 字节 BVLC 头
     */
    public function encodeBvlc(bool $broadcast, string $apdu): string
    {
        $npdu = "\x01\x00" . $apdu;
        return "\x81" . ($broadcast ? "\x0B" : "\x0A") . pack('n', 4 + strlen($npdu)) . $npdu;
    }

    /**
     * 返回 APDU;非数据链路帧或带路由信息的 NPDU 返回 null
     * @throws InvalidArgumentException
     */
    public function decodeBvlc(string $data): ?string
    {
        $length = strlen($data);
        if ($length < 6 || ord($data[0]) !== 0x81) {
            return null;
        }
        if (!in_array(ord($data[1]), [0x04, 0x0A, 0x0B], true)) {
            return null;
        }
        $bvlcLength = unpack('n', substr($data, 2, 2))[1];
        if ($bvlcLength < 6 || $bvlcLength > $length) {
            throw new InvalidArgumentException('invalid bacnet BVLC length');
        }
        if (ord($data[4]) !== 0x01) {
            throw new InvalidArgumentException('unsupported bacnet NPDU version');
        }
        if ((ord($data[5]) & 0x30) !== 0) {
            return null;
        }
        return substr($data, 6, $bvlcLength - 6);
    }

    public function parseIAm(string $apdu): ?int
    {
        if (strlen($apdu) < 7 || ord($apdu[0]) !== 0x10 || ord($apdu[1]) !== 0x00 || ord($apdu[2]) !== 0xC4) {
            return null;
        }
        $objectId = unpack('N', substr($apdu, 3, 4))[1];
        return ($objectId >> 22) === 8 ? $objectId & 0x3FFFFF : null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function parseReadPropertyAck(string $apdu): mixed
    {
        $first = strlen($apdu) > 0 ? ord($apdu[0]) : -1;
        if ($first === 0x50) {
            throw new InvalidArgumentException('bacnet error PDU: ' . bin2hex($apdu));
        }
        if ($first === 0x60) {
            throw new InvalidArgumentException('bacnet reject PDU: ' . bin2hex($apdu));
        }
        if ($first === 0x70) {
            throw new InvalidArgumentException('bacnet abort PDU: ' . bin2hex($apdu));
        }
        if ($first !== 0x30) {
            throw new InvalidArgumentException('not a bacnet ComplexACK');
        }
        $total = strlen($apdu);
        $pos = 3;
        if ($pos >= $total || ord($apdu[$pos]) !== 0xC4) {
            throw new InvalidArgumentException('unsupported bacnet ComplexACK shape');
        }
        $pos += 5;
        if ($pos >= $total || ord($apdu[$pos]) !== 0x19) {
            throw new InvalidArgumentException('bacnet ComplexACK is missing property id');
        }
        $pos += 2;
        if ($pos < $total && ord($apdu[$pos]) === 0x29) {
            $pos += 2;
        }
        if ($pos < $total && ord($apdu[$pos]) === 0x3E) {
            $pos++;
        }
        return $this->parseAppValue(substr($apdu, $pos))[0];
    }

    /**
     * @return array{0:mixed,1:int} [value,consumedBytes]
     * @throws InvalidArgumentException
     */
    public function parseAppValue(string $data): array
    {
        if ($data === '') {
            throw new InvalidArgumentException('empty bacnet value');
        }
        $tag = ord($data[0]);
        $tagNumber = $tag >> 4;
        if ($tagNumber === 0x0F) {
            throw new InvalidArgumentException('bacnet extended tag numbers are not supported');
        }
        $header = 1;
        $length = $tag & 0x0F;
        if ($length === 5) {
            if (!isset($data[1])) {
                throw new InvalidArgumentException('truncated bacnet extended length');
            }
            $length = ord($data[1]);
            $header = 2;
        } elseif ($length > 5) {
            throw new InvalidArgumentException('invalid bacnet tag length code');
        }
        if ($tagNumber === 1) {
            return [$length === 1, $header];
        }
        if ($header + $length > strlen($data)) {
            throw new InvalidArgumentException('truncated bacnet value payload');
        }
        $bytes = substr($data, $header, $length);
        $consumed = $header + $length;
        $unsigned = function (string $value): int {
            if (strlen($value) > 4) {
                throw new InvalidArgumentException('bacnet unsigned value longer than 4 bytes');
            }
            $result = 0;
            for ($i = 0, $len = strlen($value); $i < $len; $i++) {
                $result = ($result << 8) | ord($value[$i]);
            }
            return $result;
        };
        switch ($tagNumber) {
            case 0:
                return [null, $consumed];
            case 2:
            case 9:
                return [$unsigned($bytes), $consumed];
            case 3:
                $size = strlen($bytes);
                if ($size === 0 || $size > 4) {
                    throw new InvalidArgumentException('bacnet signed value must be 1..=4 bytes');
                }
                $raw = $unsigned($bytes);
                $bits = 8 * $size;
                $signed = ($raw & (1 << ($bits - 1))) !== 0 ? $raw - (1 << $bits) : $raw;
                return [$signed, $consumed];
            case 4:
                if (strlen($bytes) !== 4) {
                    throw new InvalidArgumentException('bacnet REAL must be 4 bytes');
                }
                return [$this->finiteFloat(unpack('G', $bytes)[1]), $consumed];
            case 5:
                if (strlen($bytes) !== 8) {
                    throw new InvalidArgumentException('bacnet DOUBLE must be 8 bytes');
                }
                return [$this->finiteFloat(unpack('E', $bytes)[1]), $consumed];
            case 6:
            case 8:
                return [['encoding' => 'hex', 'data' => bin2hex($bytes)], $consumed];
            case 7:
                $charset = $bytes !== '' ? ord($bytes[0]) : 0;
                if ($charset !== 0) {
                    throw new InvalidArgumentException('unsupported bacnet string charset ' . $charset);
                }
                return [substr($bytes, 1), $consumed];
            default:
                return [['encoding' => 'hex', 'data' => bin2hex($bytes)], $consumed];
        }
    }

    protected function finiteFloat(float $value): float|string
    {
        if (is_finite($value)) {
            return $value;
        }
        if (is_nan($value)) {
            return 'NaN';
        }
        return $value > 0 ? 'inf' : '-inf';
    }
}
