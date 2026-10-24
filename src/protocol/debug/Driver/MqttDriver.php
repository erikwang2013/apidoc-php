<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\StreamingDriverInterface;
use erikwang2013\apidoc\protocol\debug\Support\Hex;

/**
 * MQTT 3.1.1 驱动:单次发布 + 流式订阅(Task 9)
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class MqttDriver implements StreamingDriverInterface
{
    /**
     * @var array<int, string> CONNACK 返回码
     */
    protected static $connackCodes = [
        1 => 'unacceptable protocol version',
        2 => 'identifier rejected',
        3 => 'server unavailable',
        4 => 'bad user name or password',
        5 => 'not authorized',
    ];

    public function name(): string
    {
        return 'mqtt';
    }

    public function mode(): string
    {
        return 'relay-stream';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'topic', 'in' => 'meta', 'type' => 'string', 'require' => true, 'desc' => 'Publish topic'],
            ['name' => 'qos', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1],
            ['name' => 'clientId', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'apidoc-debug'],
            ['name' => 'username', 'in' => 'meta', 'type' => 'string', 'require' => false],
            ['name' => 'password', 'in' => 'meta', 'type' => 'string', 'require' => false],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false],
        ];
    }

    public function start(array $input, array $context): array
    {
        return ['ok' => false, 'error' => 'streaming driver not implemented yet'];
    }

    public function poll(string $sessionId, array $context): array
    {
        return ['ok' => false, 'error' => 'streaming driver not implemented yet'];
    }

    public function stop(string $sessionId, array $context): void
    {
    }

    public function encodeConnect(array $options = []): string
    {
        $clientId = (string)($options['clientId'] ?? 'apidoc-debug');
        $keepalive = max(0, min(65535, (int)($options['keepalive'] ?? 60)));
        $username = isset($options['username']) ? (string)$options['username'] : '';
        $password = isset($options['password']) ? (string)$options['password'] : '';
        $cleanSession = !array_key_exists('cleanSession', $options) || $options['cleanSession'] !== false;
        $flags = $cleanSession ? 0x02 : 0x00;
        if ($username !== '') {
            $flags |= 0x80;
        }
        if ($password !== '') {
            $flags |= 0x40;
        }
        $body = "\x00\x04MQTT\x04" . chr($flags) . pack('n', $keepalive);
        $body .= $this->encodeString($clientId);
        if ($username !== '') {
            $body .= $this->encodeString($username);
        }
        if ($password !== '') {
            $body .= $this->encodeString($password);
        }
        return "\x10" . $this->encodeRemainingLength(strlen($body)) . $body;
    }

    public function encodePublish(string $topic, int $qos, string $payload, int $packetId = 1): string
    {
        $qos = $qos === 1 ? 1 : 0;
        $body = $this->encodeString($topic);
        if ($qos === 1) {
            $body .= pack('n', $packetId & 0xFFFF);
        }
        $body .= $payload;
        return chr(0x30 | ($qos << 1)) . $this->encodeRemainingLength(strlen($body)) . $body;
    }

    public function encodeSubscribe(string $topic, int $qos = 0, int $packetId = 1): string
    {
        $body = pack('n', $packetId & 0xFFFF) . $this->encodeString($topic) . chr($qos === 1 ? 1 : 0);
        return "\x82" . $this->encodeRemainingLength(strlen($body)) . $body;
    }

    public function encodeDisconnect(): string
    {
        return "\xE0\x00";
    }

    /**
     * @param string $bytes 已收到的字节流
     * @return array<int, array{type:int, flags:int, body:string}> 只返回完整报文
     */
    public static function decodePackets(string $bytes): array
    {
        $packets = [];
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $first = ord($bytes[$offset]);
            $position = $offset + 1;
            $remaining = 0;
            $multiplier = 1;
            do {
                if ($position >= $length) {
                    return $packets;
                }
                $digit = ord($bytes[$position++]);
                $remaining += ($digit & 0x7F) * $multiplier;
                $multiplier *= 128;
            } while (($digit & 0x80) !== 0);
            if ($position + $remaining > $length) {
                break;
            }
            $packets[] = [
                'type' => ($first >> 4) & 0x0F,
                'flags' => $first & 0x0F,
                'body' => substr($bytes, $position, $remaining),
            ];
            $offset = $position + $remaining;
        }
        return $packets;
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
            return ['ok' => false, 'error' => 'mqtt 驱动仅支持 tcp 目标'];
        }
        $action = strtolower(trim((string)($input['action'] ?? 'publish')));
        if (!in_array($action, ['publish', 'send'], true)) {
            return ['ok' => false, 'error' => 'mqtt 驱动不支持的操作: ' . $action];
        }
        foreach (['topic', 'clientId', 'username', 'password'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                return ['ok' => false, 'error' => $key . ' must be a string'];
            }
        }
        $topic = trim((string)($input['topic'] ?? ''));
        if ($topic === '') {
            return ['ok' => false, 'error' => 'topic 不能为空'];
        }
        if (strlen($topic) > 65535) {
            return ['ok' => false, 'error' => 'topic 过长'];
        }
        $qosRaw = $input['qos'] ?? 1;
        if (!is_numeric($qosRaw) || !in_array((int)$qosRaw, [0, 1], true)) {
            return ['ok' => false, 'error' => 'qos 仅支持 0 或 1'];
        }
        $qos = (int)$qosRaw;
        try {
            $payload = Hex::decode((string)($input['payload'] ?? ''), (string)($input['encoding'] ?? 'text'));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($payload) > $maxBytes) {
            return ['ok' => false, 'error' => 'request exceeds max_bytes'];
        }
        $timeout = (float)($context['timeout'] ?? 5);
        if ($timeout <= 0) {
            $timeout = 5.0;
        }
        $connectOptions = [
            'clientId' => (string)($input['clientId'] ?? 'apidoc-debug'),
            'keepalive' => 60,
        ];
        if ((string)($input['username'] ?? '') !== '') {
            $connectOptions['username'] = (string)$input['username'];
        }
        if ((string)($input['password'] ?? '') !== '') {
            $connectOptions['password'] = (string)$input['password'];
        }
        foreach (['clientId', 'username', 'password'] as $key) {
            if (isset($connectOptions[$key]) && strlen((string)$connectOptions[$key]) > 65535) {
                return ['ok' => false, 'error' => $key . ' 过长'];
            }
        }
        $packetId = 1;

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
        try {
            if (!$this->writeAll($socket, $this->encodeConnect($connectOptions))) {
                return ['ok' => false, 'error' => 'write failed'];
            }
            $packets = $this->readPackets($socket, $timeout, $maxBytes, function (array $packets): bool {
                return $this->findPacket($packets, 2) !== null;
            });
            $connack = $this->findPacket($packets, 2);
            if ($connack === null) {
                return ['ok' => false, 'error' => 'CONNACK timed out'];
            }
            if (strlen($connack['body']) < 2) {
                return ['ok' => false, 'error' => 'invalid CONNACK'];
            }
            $code = ord($connack['body'][1]);
            if ($code !== 0) {
                $name = isset(static::$connackCodes[$code]) ? static::$connackCodes[$code] : 'unknown return code';
                return ['ok' => false, 'error' => 'CONNACK ' . $code . ': ' . $name];
            }
            if (!$this->writeAll($socket, $this->encodePublish($topic, $qos, $payload, $packetId))) {
                return ['ok' => false, 'error' => 'write failed'];
            }
            if ($qos === 1) {
                $packets = $this->readPackets($socket, $timeout, $maxBytes, function (array $packets): bool {
                    return $this->findPacket($packets, 4) !== null;
                });
                if ($this->findPacket($packets, 4) === null) {
                    return ['ok' => false, 'error' => 'PUBACK timed out'];
                }
            }
            $this->writeAll($socket, $this->encodeDisconnect());
            return [
                'ok' => true,
                'body' => $qos === 1 ? 'PUBACK' : 'PUBLISHED',
                'meta' => [
                    'durationMs' => (int)round((microtime(true) - $start) * 1000),
                    'bytes' => strlen($payload),
                    'packetId' => $packetId,
                ],
            ];
        } finally {
            fclose($socket);
        }
    }

    protected function encodeString(string $value): string
    {
        return pack('n', strlen($value) & 0xFFFF) . $value;
    }

    protected function encodeRemainingLength(int $length): string
    {
        $encoded = '';
        do {
            $digit = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $digit |= 0x80;
            }
            $encoded .= chr($digit);
        } while ($length > 0);
        return $encoded;
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

    protected function readPackets($socket, float $timeout, int $maxBytes, callable $done): array
    {
        $deadline = microtime(true) + $timeout;
        $buffer = '';
        $packets = [];
        while (true) {
            $packets = static::decodePackets($buffer);
            if ($done($packets)) {
                break;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                break;
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int)$left, (int)(($left - (int)$left) * 1000000));
            if ($ready !== 1) {
                break;
            }
            $chunk = @fread($socket, min(8192, max(1, $maxBytes - strlen($buffer))));
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    break;
                }
                continue;
            }
            $buffer .= $chunk;
            if (strlen($buffer) >= $maxBytes) {
                break;
            }
        }
        return $packets;
    }

    protected function findPacket(array $packets, int $type): ?array
    {
        foreach ($packets as $packet) {
            if ($packet['type'] === $type) {
                return $packet;
            }
        }
        return null;
    }
}
