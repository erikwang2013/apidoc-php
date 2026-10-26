<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Amf0;
use erikwang2013\apidoc\protocol\debug\Support\Input;
use erikwang2013\apidoc\protocol\debug\Support\Socket;

/**
 * RTMP 信令调试:简单握手 + AMF0 + chunk 基础(connect/createStream/publish/play)
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class RtmpDriver implements DebugDriverInterface
{
    protected const RTMP_VERSION = 3;
    protected const HANDSHAKE_SIZE = 1536;
    protected const DEFAULT_CHUNK_SIZE = 128;

    public function name(): string
    {
        return 'rtmp';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'operation', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'connect', 'desc' => 'connect, publish or play'],
            ['name' => 'app', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'live'],
            ['name' => 'stream', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Required for publish/play'],
            ['name' => 'duration_ms', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1000, 'desc' => 'onStatus wait'],
        ];
    }

    public function send(array $input, array $context): array
    {
        $started = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
            if (!$this->targetMatches($target)) {
                return ['ok' => false, 'error' => 'rtmp 驱动仅支持 rtmp/tcp 目标'];
            }
            $operation = strtolower(Input::string($input, 'operation', 'connect') ?? 'connect');
            if (!in_array($operation, ['connect', 'publish', 'play'], true)) {
                return [
                    'ok' => false,
                    'error' => 'unsupported rtmp operation `' . $operation . '`; use connect/publish/play',
                ];
            }
            $timeout = max(0.05, (float)($context['timeout'] ?? 5));
            // 整个会话共用一个截止时间,慢连接不能额外获得一份超时
            $deadline = microtime(true) + $timeout;
            $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
            $socket = Socket::connect($target, $context, max(0.001, $deadline - microtime(true)));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => 'rtmp connect failed: ' . $e->getMessage()];
        }
        try {
            $this->handshake($socket, $deadline);
            $reader = ['chunkSize' => self::DEFAULT_CHUNK_SIZE, 'states' => []];
            $app = Input::string($input, 'app', 'live');
            $properties = [
                'app' => Amf0::string($app),
                'flashVer' => Amf0::string('FMLE/3.0 (compatible; apidoc)'),
                'tcUrl' => Amf0::string('rtmp://' . $target['host'] . '/' . $app),
                'type' => Amf0::string('nonprivate'),
            ];
            $this->sendCommand($socket, 3, 0, [
                Amf0::string('connect'),
                Amf0::number(1.0),
                Amf0::object($properties),
            ]);
            $this->expectResult($socket, $deadline, $maxBytes, $reader, 1.0);
            $streamName = null;
            $status = null;
            if ($operation !== 'connect') {
                if (!isset($input['stream']) || !is_scalar($input['stream'])) {
                    throw new InvalidArgumentException('rtmp publish/play requires opts.stream');
                }
                $streamName = (string)$input['stream'];
                $this->sendCommand($socket, 3, 0, [
                    Amf0::string('createStream'),
                    Amf0::number(2.0),
                    Amf0::null(),
                ]);
                $result = $this->expectResult($socket, $deadline, $maxBytes, $reader, 2.0);
                $streamId = isset($result[3]) && ($result[3]['type'] ?? '') === 'number'
                    ? (int)$result[3]['value']
                    : 1;
                $command = [
                    Amf0::string($operation),
                    Amf0::number(0.0),
                    Amf0::null(),
                    Amf0::string($streamName),
                ];
                if ($operation === 'publish') {
                    $command[] = Amf0::string('live');
                }
                $this->sendCommand($socket, 8, $streamId, $command);
                $wait = min(
                    max(0, Input::int($input, 'duration_ms', 1000, 0)),
                    max(1, (int)round($timeout * 1000))
                );
                $waitDeadline = microtime(true) + $wait / 1000;
                // 等待 onStatus 不能越过会话截止时间,否则会拖长整个请求
                $statusDeadline = min($waitDeadline, $deadline);
                while (microtime(true) < $statusDeadline) {
                    try {
                        $values = $this->readCommand($socket, $statusDeadline, $maxBytes, $reader);
                    } catch (RuntimeException $e) {
                        if (strpos($e->getMessage(), 'timed out') !== false) {
                            break;
                        }
                        throw $e;
                    }
                    if ($this->commandName($values) !== 'onStatus') {
                        continue;
                    }
                    $code = $this->objectField($values, 'code');
                    $level = $this->objectField($values, 'level');
                    if ($level === 'error') {
                        throw new InvalidArgumentException($this->amfError($values));
                    }
                    $status = $code;
                    break;
                }
                if ($status === null && $waitDeadline >= $deadline) {
                    // 原等待窗口本会超出会话截止时间,按 Rust 外层 timeout 处理
                    throw new RuntimeException('rtmp session timed out');
                }
            }
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (RuntimeException $e) {
            return [
                'ok' => false,
                'error' => strpos($e->getMessage(), 'timed out') !== false
                    ? 'rtmp session timed out'
                    : $e->getMessage(),
            ];
        } finally {
            fclose($socket);
        }
        $body = json_encode(
            [
                'connected' => true,
                'operation' => $operation,
                'stream' => $streamName,
                'status' => $status,
            ],
            JSON_UNESCAPED_SLASHES
        );
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

    protected function targetMatches(array $target): bool
    {
        if ($target['scheme'] === 'rtmp') {
            return true;
        }
        return $target['scheme'] === 'tcp' && strpos((string)($target['original'] ?? ''), '://') === false;
    }

    /**
     * 简单握手:C0+C1 -> S0+S1+S2 -> C2=S1
     * @throws RuntimeException
     */
    protected function handshake($socket, float $deadline): void
    {
        try {
            $random = random_bytes(self::HANDSHAKE_SIZE - 8);
        } catch (Throwable $e) {
            $random = '';
            for ($i = 0; $i < self::HANDSHAKE_SIZE - 8; $i++) {
                $random .= chr($i & 0xFF);
            }
        }
        $c1 = str_repeat("\x00", 8) . $random;
        if (!Socket::writeAll($socket, chr(self::RTMP_VERSION) . $c1)) {
            throw new RuntimeException('rtmp handshake write failed');
        }
        $s0 = $this->readHandshake($socket, 1, $deadline);
        if (ord($s0) !== self::RTMP_VERSION) {
            throw new InvalidArgumentException(
                'unsupported RTMP version ' . ord($s0) . ' (expected ' . self::RTMP_VERSION . ')'
            );
        }
        $s1 = $this->readHandshake($socket, self::HANDSHAKE_SIZE, $deadline);
        $this->readHandshake($socket, self::HANDSHAKE_SIZE, $deadline);
        if (!Socket::writeAll($socket, $s1)) {
            throw new RuntimeException('rtmp handshake write failed');
        }
    }

    /**
     * @throws RuntimeException
     */
    protected function readHandshake($socket, int $length, float $deadline): string
    {
        try {
            return Socket::readExact($socket, $length, $deadline, 'rtmp read');
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'timed out') !== false) {
                throw $e;
            }
            throw new RuntimeException('rtmp handshake read failed: ' . $this->errorDetail($e));
        }
    }

    /**
     * @throws RuntimeException
     */
    protected function readChunkByte($socket, int $length, float $deadline): string
    {
        try {
            return Socket::readExact($socket, $length, $deadline, 'rtmp read');
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'timed out') !== false) {
                throw $e;
            }
            throw new RuntimeException('rtmp chunk read failed: ' . $this->errorDetail($e));
        }
    }

    protected function errorDetail(RuntimeException $e): string
    {
        return (string)preg_replace('/^rtmp read: /', '', $e->getMessage());
    }

    /**
     * 写一个 fmt 0 消息,按 chunk_size 分块,续块用 fmt 3
     * @throws InvalidArgumentException
     */
    public function writeMessage(int $csid, int $typeId, int $streamId, string $payload, int $chunkSize): string
    {
        if ($csid < 2 || $csid > 63) {
            throw new InvalidArgumentException('rtmp chunk stream id must be 2-63');
        }
        $out = chr($csid)
            . "\x00\x00\x00"
            . substr(pack('N', strlen($payload)), 1)
            . chr($typeId)
            . pack('V', $streamId);
        $chunks = str_split($payload, max(1, $chunkSize));
        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                $out .= chr(0xC0 | $csid);
            }
            $out .= $chunk;
        }
        return $out;
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function sendCommand($socket, int $csid, int $streamId, array $values): void
    {
        $payload = '';
        foreach ($values as $value) {
            $payload .= Amf0::encode($value);
        }
        $bytes = $this->writeMessage($csid, 20, $streamId, $payload, self::DEFAULT_CHUNK_SIZE);
        if (!Socket::writeAll($socket, $bytes)) {
            throw new RuntimeException('rtmp write failed');
        }
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function readCommand($socket, float $deadline, int $maxBytes, array &$reader): array
    {
        while (true) {
            [$typeId, $streamId, $payload] = $this->readMessage($socket, $deadline, $maxBytes, $reader);
            if ($typeId === 20) {
                return Amf0::decodeAll($payload);
            }
        }
    }

    /**
     * 读取一条完整消息,协议控制消息(chunk size/ack/bandwidth)内部消费
     * @return array{0:int,1:int,2:string}
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function readMessage($socket, float $deadline, int $maxBytes, array &$reader): array
    {
        while (true) {
            [$typeId, $streamId, $payload] = $this->readChunkMessage($socket, $deadline, $maxBytes, $reader);
            if ($typeId === 1 && strlen($payload) >= 4) {
                $size = unpack('N', substr($payload, 0, 4))[1] & 0x7FFFFFFF;
                $reader['chunkSize'] = max(1, $size);
                continue;
            }
            if ($typeId >= 4 && $typeId <= 6) {
                continue;
            }
            return [$typeId, $streamId, $payload];
        }
    }

    /**
     * @return array{0:int,1:int,2:string}
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function readChunkMessage($socket, float $deadline, int $maxBytes, array &$reader): array
    {
        while (true) {
            $first = ord($this->readChunkByte($socket, 1, $deadline));
            $format = $first >> 6;
            $csid = $first & 0x3F;
            if ($csid === 0) {
                $csid = 64 + ord($this->readChunkByte($socket, 1, $deadline));
            } elseif ($csid === 1) {
                $low = ord($this->readChunkByte($socket, 1, $deadline));
                $high = ord($this->readChunkByte($socket, 1, $deadline));
                $csid = 64 + $low + ($high << 8);
            }
            $current = $reader['states'][$csid] ?? null;
            unset($reader['states'][$csid]);
            switch ($format) {
                case 0:
                    $this->readChunkByte($socket, 3, $deadline);
                    $length = $this->readU24($socket, $deadline);
                    $typeId = ord($this->readChunkByte($socket, 1, $deadline));
                    $streamId = unpack('V', $this->readChunkByte($socket, 4, $deadline))[1];
                    if ($current !== null && $current['payload'] !== '') {
                        throw new InvalidArgumentException('rtmp new chunk header while message incomplete');
                    }
                    $current = [
                        'length' => $length,
                        'typeId' => $typeId,
                        'streamId' => $streamId,
                        'payload' => '',
                    ];
                    break;
                case 1:
                    $this->readChunkByte($socket, 3, $deadline);
                    $length = $this->readU24($socket, $deadline);
                    $typeId = ord($this->readChunkByte($socket, 1, $deadline));
                    if ($current === null) {
                        throw new InvalidArgumentException('rtmp fmt 1 chunk without previous state');
                    }
                    if ($current['payload'] !== '') {
                        throw new InvalidArgumentException('rtmp new chunk header while message incomplete');
                    }
                    $current['length'] = $length;
                    $current['typeId'] = $typeId;
                    break;
                case 2:
                    $this->readChunkByte($socket, 3, $deadline);
                    if ($current === null) {
                        throw new InvalidArgumentException('rtmp fmt 2 chunk without previous state');
                    }
                    if ($current['payload'] !== '') {
                        throw new InvalidArgumentException('rtmp new chunk header while message incomplete');
                    }
                    break;
                default:
                    if ($current === null) {
                        throw new InvalidArgumentException('rtmp fmt 3 chunk without previous state');
                    }
                    break;
            }
            if ($current === null) {
                throw new InvalidArgumentException('rtmp chunk without state');
            }
            if ($current['length'] > $maxBytes) {
                throw new InvalidArgumentException('rtmp message exceeds max_bytes (' . $maxBytes . ')');
            }
            $want = min(
                $current['length'] - strlen($current['payload']),
                $reader['chunkSize']
            );
            $buffered = strlen($current['payload']);
            foreach ($reader['states'] as $state) {
                $buffered += strlen($state['payload']);
            }
            if ($buffered + $want > $maxBytes) {
                throw new InvalidArgumentException(
                    'rtmp cross-csid buffered data exceeds max_bytes (' . $maxBytes . ')'
                );
            }
            $current['payload'] .= $this->readChunkByte($socket, $want, $deadline);
            if (strlen($current['payload']) === $current['length']) {
                return [$current['typeId'], $current['streamId'], $current['payload']];
            }
            if (count($reader['states']) >= 64) {
                throw new InvalidArgumentException('too many concurrent rtmp chunk streams');
            }
            $reader['states'][$csid] = $current;
        }
    }

    /**
     * @throws RuntimeException
     */
    protected function readU24($socket, float $deadline): int
    {
        $bytes = $this->readChunkByte($socket, 3, $deadline);
        return (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function expectResult($socket, float $deadline, int $maxBytes, array &$reader, float $transaction): array
    {
        while (true) {
            $values = $this->readCommand($socket, $deadline, $maxBytes, $reader);
            $name = $this->commandName($values);
            if ($name === '_error') {
                throw new InvalidArgumentException($this->amfError($values));
            }
            if (
                $name === '_result'
                && isset($values[1])
                && ($values[1]['type'] ?? '') === 'number'
                && (float)$values[1]['value'] == $transaction
            ) {
                return $values;
            }
        }
    }

    protected function commandName(array $values): ?string
    {
        return isset($values[0]) && ($values[0]['type'] ?? '') === 'string'
            ? (string)$values[0]['value']
            : null;
    }

    protected function objectField(array $values, string $field): ?string
    {
        foreach ($values as $value) {
            if (($value['type'] ?? '') !== 'object') {
                continue;
            }
            $found = $value['value'][$field] ?? null;
            if (is_array($found) && ($found['type'] ?? '') === 'string') {
                return (string)$found['value'];
            }
        }
        return null;
    }

    protected function amfError(array $values): string
    {
        $code = 'unknown';
        $description = '';
        foreach ($values as $value) {
            if (($value['type'] ?? '') !== 'object') {
                continue;
            }
            $entry = $value['value']['code'] ?? null;
            if (is_array($entry) && ($entry['type'] ?? '') === 'string') {
                $code = (string)$entry['value'];
            }
            $entry = $value['value']['description'] ?? null;
            if (is_array($entry) && ($entry['type'] ?? '') === 'string') {
                $description = (string)$entry['value'];
            }
        }
        return 'rtmp server error ' . $code . ': ' . $description;
    }
}
