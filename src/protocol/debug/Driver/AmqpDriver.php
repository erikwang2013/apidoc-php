<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use RuntimeException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;
use erikwang2013\apidoc\protocol\debug\Support\Socket;

/**
 * AMQP 0-9-1 执行器:publish 走 Confirm.Select,consume 不 ack(断开后 broker 重新投递)。
 * 凭据只从字段读取;错误文本中的密码会被替换。TLS(amqps://) 显式拒绝。
 * 目标只接受 amqp://(与 Rust 一致);不把 tcp:// 当作别名。
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class AmqpDriver implements DebugDriverInterface
{
    const MAX_FRAMES = 1000;
    const FRAME_METHOD = 1;
    const FRAME_HEADER = 2;
    const FRAME_BODY = 3;
    const FRAME_HEARTBEAT = 8;
    const FRAME_END = 0xCE;

    /**
     * @var int 协商后的 frame-max 载荷上限
     */
    protected $frameMax = 131072;

    public function name(): string
    {
        return 'amqp';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'consume', 'in' => 'meta', 'type' => 'boolean', 'require' => false, 'default' => false],
            ['name' => 'queue', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Queue to consume'],
            ['name' => 'declare', 'in' => 'meta', 'type' => 'boolean', 'require' => false, 'default' => false, 'desc' => 'Declare the queue before consuming'],
            ['name' => 'routing_key', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Required when publishing'],
            ['name' => 'exchange', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => ''],
            ['name' => 'vhost', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => '/'],
            ['name' => 'username', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'guest'],
            ['name' => 'password', 'in' => 'meta', 'type' => 'string', 'require' => false],
            ['name' => 'messages', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1],
            ['name' => 'duration_ms', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 2000],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false],
        ];
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : 'guest';
        $redact = function (string $message) use ($password): string {
            return $password !== '' ? str_replace($password, '***', $message) : $message;
        };
        foreach (['queue', 'routing_key', 'exchange', 'vhost', 'username', 'password'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                return ['ok' => false, 'error' => $key . ' must be a string'];
            }
        }
        $consume = $this->boolOption($input['consume'] ?? false);
        if ($consume === null) {
            return ['ok' => false, 'error' => 'invalid amqp consume `' . (is_scalar($input['consume']) ? (string)$input['consume'] : gettype($input['consume'])) . '`; use "true" or "false"'];
        }
        $declare = $this->boolOption($input['declare'] ?? false);
        if ($declare === null) {
            return ['ok' => false, 'error' => 'invalid amqp declare; use "true" or "false"'];
        }
        $queue = trim((string)($input['queue'] ?? ''));
        $routingKey = trim((string)($input['routing_key'] ?? ''));
        if ($consume && $queue === '') {
            return ['ok' => false, 'error' => 'amqp consume requires opts.queue'];
        }
        if (!$consume && $routingKey === '') {
            return ['ok' => false, 'error' => 'amqp publish requires opts.routing_key'];
        }
        $exchange = (string)($input['exchange'] ?? '');
        $vhost = (string)($input['vhost'] ?? '/');
        $username = (string)($input['username'] ?? 'guest');
        foreach (['queue' => $queue, 'routing_key' => $routingKey, 'exchange' => $exchange, 'vhost' => $vhost] as $name => $value) {
            if (strlen($value) > 255) {
                return ['ok' => false, 'error' => 'amqp ' . $name . ' is too long'];
            }
        }
        if (strlen($username) > 512 || strlen($password) > 512) {
            return ['ok' => false, 'error' => 'amqp credentials are too long'];
        }
        try {
            $body = Hex::decode((string)($input['payload'] ?? ''), (string)($input['encoding'] ?? 'text'));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($body) > $maxBytes) {
            return ['ok' => false, 'error' => 'request body exceeds max_bytes (' . $maxBytes . ')'];
        }
        $messagesRaw = $input['messages'] ?? 1;
        $want = is_numeric($messagesRaw) ? (int)$messagesRaw : 1;
        $want = max(1, min(self::MAX_FRAMES, $want));
        $timeout = (float)($context['timeout'] ?? 5);
        if ($timeout <= 0) {
            $timeout = 5.0;
        }
        $durationRaw = $input['duration_ms'] ?? 2000;
        $durationMs = is_numeric($durationRaw) ? (int)$durationRaw : 2000;
        $durationMs = max(1, min((int)round($timeout * 1000), $durationMs));

        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $scheme = (string)$target['scheme'];
        if ($scheme === 'amqps') {
            return ['ok' => false, 'error' => 'amqp over TLS requires a pinned transport; use amqp:// (plaintext) in this build'];
        }
        if ($scheme !== 'amqp') {
            return ['ok' => false, 'error' => 'amqp driver requires an amqp:// target'];
        }
        if (($target['path'] ?? '') !== '' && $target['path'] !== '/') {
            return ['ok' => false, 'error' => 'amqp vhost must be provided via opts.vhost, not the target URL'];
        }
        if (strpos((string)$target['original'], '?') !== false) {
            return ['ok' => false, 'error' => 'amqp connection parameters must be provided via opts, not the target URL'];
        }

        $deadline = microtime(true) + $timeout;
        try {
            $socket = Socket::connect($target, $context, $timeout);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $redact('amqp connect failed: ' . $e->getMessage())];
        }
        try {
            $this->handshake($socket, $username, $password, $vhost, $deadline, $maxBytes);
            $this->openChannel($socket, $deadline, $maxBytes);
            if ($consume) {
                if ($declare) {
                    $this->sendMethod($socket, 1, 50, 10, pack('n', 0) . $this->shortstr($queue) . "\x00" . pack('N', 0));
                    $this->expectMethod($socket, 50, 11, $deadline, $maxBytes);
                }
                $tag = 'apidoc-' . bin2hex(random_bytes(6));
                // 不 ack:取消消费者并断开后 broker 会重新投递,调试读取不吞业务消息
                $this->sendMethod($socket, 1, 60, 20, pack('n', 0) . $this->shortstr($queue) . $this->shortstr($tag) . "\x00" . pack('N', 0));
                $this->expectMethod($socket, 60, 21, $deadline, $maxBytes);
                $deliveries = $this->consumeDeliveries($socket, $want, $durationMs, $deadline, $maxBytes);
                $this->sendMethod($socket, 1, 60, 30, $this->shortstr($tag) . "\x01");
                $count = count($deliveries);
                $payload = $count === 1
                    ? ['delivery' => $deliveries[0]]
                    : ['deliveries' => $deliveries];
            } else {
                $this->publish($socket, $exchange, $routingKey, $body, $deadline, $maxBytes);
                $count = 0;
                $payload = ['published' => true];
            }
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                return ['ok' => false, 'error' => 'amqp response could not be encoded as JSON'];
            }
            $this->close($socket);
            return [
                'ok' => true,
                'status' => 0,
                'headers' => ['x-messages' => (string)$count],
                'body' => $json,
                'encoding' => 'text',
                'meta' => [
                    'durationMs' => (int)round((microtime(true) - $start) * 1000),
                    'messages' => $count,
                ],
            ];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $redact($e->getMessage())];
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    protected function handshake($socket, string $username, string $password, string $vhost, float $deadline, int $maxBytes): void
    {
        if (!Socket::writeAll($socket, "AMQP\x00\x00\x09\x01")) {
            throw new RuntimeException('amqp write failed');
        }
        [$class, $method, $args] = $this->readMethodFrame($socket, $deadline, $maxBytes);
        if ($class !== 10 || $method !== 10) {
            throw new RuntimeException('amqp handshake did not start with connection.start');
        }
        $pos = 0;
        $this->readInt8($args, $pos); // version major
        $this->readInt8($args, $pos); // version minor
        $this->skipTable($args, $pos);
        $mechanisms = explode(' ', $this->readLongstr($args, $pos));
        $this->readLongstr($args, $pos); // locales
        if (in_array('PLAIN', $mechanisms, true)) {
            $mechanism = 'PLAIN';
            $response = "\0" . $username . "\0" . $password;
        } elseif (in_array('AMQPLAIN', $mechanisms, true)) {
            $mechanism = 'AMQPLAIN';
            $response = $this->table(['LOGIN' => $username, 'PASSWORD' => $password]);
        } else {
            throw new RuntimeException('amqp broker does not offer PLAIN or AMQPLAIN SASL');
        }
        $clientProperties = $this->table([
            'product' => 'apidoc-debug',
            'version' => '0.1.0',
            'platform' => 'PHP',
        ]);
        $this->sendMethod(
            $socket,
            0,
            10,
            11,
            $clientProperties . $this->shortstr($mechanism) . $this->longstr($response) . $this->shortstr('en_US')
        );
        [$class, $method, $args] = $this->readMethodFrame($socket, $deadline, $maxBytes);
        if ($class !== 10 || $method !== 30) {
            throw new RuntimeException('amqp handshake did not receive connection.tune');
        }
        $pos = 0;
        $channelMax = $this->readInt16($args, $pos);
        $serverFrameMax = $this->readInt32($args, $pos);
        $this->readInt16($args, $pos); // heartbeat offered by broker
        $this->frameMax = $serverFrameMax > 0 ? min($serverFrameMax, 131072) : 131072;
        if ($this->frameMax < 4096) {
            throw new RuntimeException('amqp broker frame-max is too small');
        }
        // heartbeat=0: 一元调试连接很短,避免心跳定时器
        $this->sendMethod(
            $socket,
            0,
            10,
            31,
            pack('n', min($channelMax, 2047)) . pack('N', $this->frameMax) . pack('n', 0)
        );
        $this->sendMethod($socket, 0, 10, 40, $this->shortstr($vhost) . $this->shortstr('') . "\x00");
        $this->expectMethod($socket, 10, 41, $deadline, $maxBytes);
    }

    protected function openChannel($socket, float $deadline, int $maxBytes): void
    {
        $this->sendMethod($socket, 1, 20, 10, $this->shortstr(''));
        $this->expectMethod($socket, 20, 11, $deadline, $maxBytes);
    }

    protected function publish($socket, string $exchange, string $routingKey, string $body, float $deadline, int $maxBytes): void
    {
        $this->sendMethod($socket, 1, 85, 10, "\x00");
        $this->expectMethod($socket, 85, 11, $deadline, $maxBytes);
        $this->sendMethod($socket, 1, 60, 40, pack('n', 0) . $this->shortstr($exchange) . $this->shortstr($routingKey) . "\x00");
        $this->sendFrame($socket, self::FRAME_HEADER, 1, pack('n', 60) . pack('n', 0) . pack('J', strlen($body)) . pack('n', 0));
        $chunkSize = max(1, $this->frameMax - 8);
        $offset = 0;
        while ($offset < strlen($body)) {
            $chunk = substr($body, $offset, $chunkSize);
            $this->sendFrame($socket, self::FRAME_BODY, 1, $chunk);
            $offset += strlen($chunk);
        }
        while (true) {
            [$class, $method, $args] = $this->readMethodFrame($socket, $deadline, $maxBytes);
            if ($class === 60 && $method === 80) {
                return;
            }
            if ($class === 60 && $method === 120) {
                throw new RuntimeException('amqp publish was rejected by the broker (nack)');
            }
            if ($class === 60 && $method === 50) {
                $pos = 0;
                $this->readInt16($args, $pos);
                $text = $this->readShortstr($args, $pos);
                throw new RuntimeException('amqp publish was returned by the broker: ' . $text);
            }
            throw new RuntimeException('amqp unexpected method ' . $class . '.' . $method . ' while awaiting publish confirmation');
        }
    }

    /**
     * @return array<int,array{exchange:string,routing_key:string,payload:array}>
     */
    protected function consumeDeliveries($socket, int $want, int $durationMs, float $deadline, int $maxBytes): array
    {
        $until = min($deadline, microtime(true) + $durationMs / 1000);
        $deliveries = [];
        $total = 0;
        while (count($deliveries) < $want && microtime(true) < $until) {
            try {
                $frame = $this->readFrame($socket, $until, $maxBytes);
            } catch (RuntimeException $e) {
                // 到 duration_ms 仍无消息时按成功返回空列表
                if (strpos($e->getMessage(), 'timed out') !== false) {
                    break;
                }
                throw $e;
            }
            if ($frame['type'] === self::FRAME_HEARTBEAT) {
                continue;
            }
            if ($frame['type'] !== self::FRAME_METHOD) {
                throw new RuntimeException('amqp unexpected frame while consuming');
            }
            $payload = $frame['payload'];
            $pos = 0;
            $class = $this->readInt16($payload, $pos);
            $method = $this->readInt16($payload, $pos);
            $this->throwIfClose($class, $method, $payload, $pos);
            if ($class === 60 && $method === 30) {
                throw new RuntimeException('amqp consumer was cancelled by the broker');
            }
            if ($class !== 60 || $method !== 60) {
                throw new RuntimeException('amqp unexpected method ' . $class . '.' . $method . ' while consuming');
            }
            $this->readShortstr($payload, $pos); // consumer tag
            $this->readInt64($payload, $pos); // delivery tag
            $this->readInt8($payload, $pos); // redelivered bit
            $exchange = $this->readShortstr($payload, $pos);
            $routingKey = $this->readShortstr($payload, $pos);
            try {
                $content = $this->readContent($socket, $until, $maxBytes);
            } catch (RuntimeException $e) {
                // deliver 已读出后超时:消息已被 broker 投递,不能报告成空结果
                if (strpos($e->getMessage(), 'timed out') !== false) {
                    throw new RuntimeException('amqp delivery timed out after the broker announced it');
                }
                throw $e;
            }
            $total += strlen($content);
            if ($total > $maxBytes) {
                throw new RuntimeException('amqp messages exceed max_bytes (' . $maxBytes . ')');
            }
            $deliveries[] = [
                'exchange' => $exchange,
                'routing_key' => $routingKey,
                'payload' => Hex::payload($content),
            ];
        }
        return $deliveries;
    }

    protected function readContent($socket, float $deadline, int $maxBytes): string
    {
        while (true) {
            $frame = $this->readFrame($socket, $deadline, $maxBytes);
            if ($frame['type'] === self::FRAME_HEARTBEAT) {
                continue;
            }
            break;
        }
        if ($frame['type'] !== self::FRAME_HEADER) {
            throw new RuntimeException('amqp delivery is missing the content header');
        }
        $pos = 0;
        $classId = $this->readInt16($frame['payload'], $pos);
        $this->readInt16($frame['payload'], $pos); // weight
        $bodySize = $this->readInt64($frame['payload'], $pos);
        $this->readInt16($frame['payload'], $pos); // property flags
        if ($classId !== 60) {
            throw new RuntimeException('amqp content header class mismatch');
        }
        if ($bodySize < 0 || $bodySize > $maxBytes) {
            throw new RuntimeException('amqp message exceeds max_bytes (' . $maxBytes . ')');
        }
        $body = '';
        while (strlen($body) < $bodySize) {
            $frame = $this->readFrame($socket, $deadline, $maxBytes);
            if ($frame['type'] === self::FRAME_HEARTBEAT) {
                continue;
            }
            if ($frame['type'] !== self::FRAME_BODY) {
                throw new RuntimeException('amqp delivery is missing content body');
            }
            $body .= $frame['payload'];
            if (strlen($body) > $bodySize) {
                throw new RuntimeException('amqp content body exceeds the declared size');
            }
        }
        return $body;
    }

    /**
     * 读取下一个方法帧,跳过心跳;broker 关闭连接/通道时抛出带原因的异常
     * @return array{0:int,1:int,2:string}
     */
    protected function readMethodFrame($socket, float $deadline, int $maxBytes): array
    {
        while (true) {
            $frame = $this->readFrame($socket, $deadline, $maxBytes);
            if ($frame['type'] === self::FRAME_HEARTBEAT) {
                continue;
            }
            if ($frame['type'] !== self::FRAME_METHOD) {
                throw new RuntimeException('amqp expected a method frame, got type ' . $frame['type']);
            }
            $pos = 0;
            $class = $this->readInt16($frame['payload'], $pos);
            $method = $this->readInt16($frame['payload'], $pos);
            $this->throwIfClose($class, $method, $frame['payload'], $pos);
            return [$class, $method, substr($frame['payload'], 4)];
        }
    }

    /**
     * @return string 方法参数
     */
    protected function expectMethod($socket, int $expectClass, int $expectMethod, float $deadline, int $maxBytes): string
    {
        [$class, $method, $args] = $this->readMethodFrame($socket, $deadline, $maxBytes);
        if ($class !== $expectClass || $method !== $expectMethod) {
            throw new RuntimeException('amqp unexpected method ' . $class . '.' . $method . '; expected ' . $expectClass . '.' . $expectMethod);
        }
        return $args;
    }

    protected function throwIfClose(int $class, int $method, string $payload, int $pos): void
    {
        if (($class === 10 && $method === 50) || ($class === 20 && $method === 40)) {
            try {
                $code = $this->readInt16($payload, $pos);
                $text = $this->readShortstr($payload, $pos);
                throw new RuntimeException('amqp broker closed ' . ($class === 10 ? 'connection' : 'channel') . ': ' . $code . ' ' . $text);
            } catch (RuntimeException $e) {
                if (strpos($e->getMessage(), 'amqp broker closed') === 0) {
                    throw $e;
                }
                throw new RuntimeException('amqp broker closed ' . ($class === 10 ? 'connection' : 'channel'));
            }
        }
    }

    /**
     * @return array{type:int,channel:int,payload:string}
     */
    protected function readFrame($socket, float $deadline, int $maxBytes): array
    {
        $head = Socket::readExact($socket, 7, $deadline, 'amqp frame');
        $type = ord($head[0]);
        $channel = unpack('n', substr($head, 1, 2))[1];
        $size = unpack('N', substr($head, 3, 4))[1];
        if ($size > $maxBytes) {
            throw new RuntimeException('amqp frame exceeds max_bytes (' . $maxBytes . ')');
        }
        $payload = $size > 0 ? Socket::readExact($socket, $size, $deadline, 'amqp frame') : '';
        $end = Socket::readExact($socket, 1, $deadline, 'amqp frame');
        if (ord($end) !== self::FRAME_END) {
            throw new RuntimeException('amqp frame end marker is invalid');
        }
        return ['type' => $type, 'channel' => $channel, 'payload' => $payload];
    }

    protected function sendMethod($socket, int $channel, int $class, int $method, string $args): void
    {
        $this->sendFrame($socket, self::FRAME_METHOD, $channel, pack('n', $class) . pack('n', $method) . $args);
    }

    protected function sendFrame($socket, int $type, int $channel, string $payload): void
    {
        if (strlen($payload) > $this->frameMax - 8) {
            throw new RuntimeException('amqp frame exceeds the negotiated frame size');
        }
        $frame = chr($type) . pack('n', $channel) . pack('N', strlen($payload)) . $payload . chr(self::FRAME_END);
        if (!Socket::writeAll($socket, $frame)) {
            throw new RuntimeException('amqp write failed');
        }
    }

    protected function close($socket): void
    {
        try {
            $deadline = microtime(true) + 0.5;
            $this->sendMethod($socket, 0, 10, 50, pack('n', 200) . $this->shortstr('apidoc done') . pack('n', 0) . pack('n', 0));
            while (true) {
                $frame = $this->readFrame($socket, $deadline, 65536);
                if ($frame['type'] !== self::FRAME_METHOD) {
                    continue;
                }
                $pos = 0;
                $class = $this->readInt16($frame['payload'], $pos);
                $method = $this->readInt16($frame['payload'], $pos);
                if ($class === 10 && $method === 51) {
                    return;
                }
                break;
            }
        } catch (\Throwable $e) {
            // 关闭是最佳努力:连接已被 broker 关闭时不再报错
        }
    }

    protected function shortstr(string $value): string
    {
        if (strlen($value) > 255) {
            throw new RuntimeException('amqp short string is too long');
        }
        return chr(strlen($value)) . $value;
    }

    protected function longstr(string $value): string
    {
        return pack('N', strlen($value)) . $value;
    }

    /**
     * 编码 field table,值统一按长字符串(S)处理
     * @param array<string,string> $entries
     */
    protected function table(array $entries): string
    {
        $payload = '';
        foreach ($entries as $key => $value) {
            $payload .= $this->shortstr((string)$key) . 'S' . $this->longstr($value);
        }
        return pack('N', strlen($payload)) . $payload;
    }

    protected function skipTable(string $data, int &$pos): void
    {
        $length = $this->readInt32($data, $pos);
        if ($length < 0 || $pos + $length > strlen($data)) {
            throw new RuntimeException('invalid amqp field table');
        }
        $pos += $length;
    }

    protected function ensure(string $data, int $pos, int $need = 0): void
    {
        if ($need < 0 || $pos < 0 || $pos + $need > strlen($data)) {
            throw new RuntimeException('truncated amqp frame');
        }
    }

    protected function readInt8(string $data, int &$pos): int
    {
        $this->ensure($data, $pos, 1);
        return ord($data[$pos++]);
    }

    protected function readInt16(string $data, int &$pos): int
    {
        $this->ensure($data, $pos, 2);
        $value = unpack('n', substr($data, $pos, 2))[1];
        $pos += 2;
        return $value;
    }

    protected function readInt32(string $data, int &$pos): int
    {
        $this->ensure($data, $pos, 4);
        $value = unpack('N', substr($data, $pos, 4))[1];
        $pos += 4;
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    protected function readInt64(string $data, int &$pos): int
    {
        $this->ensure($data, $pos, 8);
        $value = unpack('J', substr($data, $pos, 8))[1];
        $pos += 8;
        return $value;
    }

    protected function readShortstr(string $data, int &$pos): string
    {
        $length = $this->readInt8($data, $pos);
        $this->ensure($data, $pos, $length);
        $value = substr($data, $pos, $length);
        $pos += $length;
        return $value;
    }

    protected function readLongstr(string $data, int &$pos): string
    {
        $length = $this->readInt32($data, $pos);
        if ($length < 0) {
            throw new RuntimeException('invalid amqp long string');
        }
        $this->ensure($data, $pos, $length);
        $value = substr($data, $pos, $length);
        $pos += $length;
        return $value;
    }

    protected function boolOption($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'true' || $normalized === '1') {
                return true;
            }
            if ($normalized === 'false' || $normalized === '0' || $normalized === '') {
                return false;
            }
        }
        return null;
    }
}
