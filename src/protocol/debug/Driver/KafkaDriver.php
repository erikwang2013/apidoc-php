<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use RuntimeException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;
use erikwang2013\apidoc\protocol\debug\Support\Socket;
use erikwang2013\apidoc\protocol\debug\Support\TargetGuard;
use erikwang2013\apidoc\utils\ConfigProvider;

/**
 * Kafka 2.0+ 只读执行器:Metadata v4 -> ListOffsets v1 -> Fetch v4(record batch v2)。
 * 与 Rust 参考实现一致只读不写;支持 SASL PLAIN 与 gzip 批次。
 * shortcut: 不校验批次 CRC32C,升级到需要损坏检测时补;TLS(kafkas://) 显式拒绝。
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class KafkaDriver implements DebugDriverInterface
{
    const MAX_MESSAGES = 1000;
    const CLIENT_ID = 'apidoc-debug';

    /**
     * @var int
     */
    protected $correlation = 0;

    /**
     * @var array<int,string>
     */
    protected static $errorNames = [
        1 => 'offset out of range',
        2 => 'corrupt message',
        3 => 'unknown topic or partition',
        5 => 'leader not available',
        6 => 'not leader for partition',
        7 => 'request timed out',
        29 => 'topic authorization failed',
        35 => 'unsupported version',
        63 => 'unsupported compression type',
    ];

    public function name(): string
    {
        return 'kafka';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'topic', 'in' => 'meta', 'type' => 'string', 'require' => true, 'desc' => 'Topic to fetch'],
            ['name' => 'partition', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 0],
            ['name' => 'offset', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'latest', 'desc' => 'earliest, latest or a numeric offset'],
            ['name' => 'messages', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1],
            ['name' => 'duration_ms', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 2000],
            ['name' => 'username', 'in' => 'meta', 'type' => 'string', 'require' => false],
            ['name' => 'password', 'in' => 'meta', 'type' => 'string', 'require' => false],
        ];
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
        $redact = function (string $message) use ($password): string {
            return $password !== '' ? str_replace($password, '***', $message) : $message;
        };
        foreach (['topic', 'offset', 'username', 'password'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key]) && !($key === 'offset' && is_int($input[$key]))) {
                return ['ok' => false, 'error' => $key . ' must be a string'];
            }
        }
        $action = strtolower(trim((string)($input['action'] ?? '')));
        if (in_array($action, ['publish', 'produce', 'send'], true)) {
            return ['ok' => false, 'error' => 'kafka driver is read-only; produce is not supported'];
        }
        if ($action !== '' && !in_array($action, ['fetch', 'consume', 'subscribe', 'receive'], true)) {
            return ['ok' => false, 'error' => 'kafka driver does not support action: ' . $action];
        }
        $topic = trim((string)($input['topic'] ?? ''));
        if ($topic === '') {
            return ['ok' => false, 'error' => 'kafka requires topic'];
        }
        if (strlen($topic) > 249) {
            return ['ok' => false, 'error' => 'kafka topic is too long'];
        }
        $partitionRaw = $input['partition'] ?? 0;
        if (!is_int($partitionRaw) && !(is_string($partitionRaw) && preg_match('/^-?\d+$/', $partitionRaw) === 1)) {
            return ['ok' => false, 'error' => 'invalid kafka partition `' . (string)$partitionRaw . '`; use a non-negative integer'];
        }
        $partition = (int)$partitionRaw;
        if ($partition < 0) {
            return ['ok' => false, 'error' => 'kafka partition must be non-negative'];
        }
        $offsetRaw = $input['offset'] ?? 'latest';
        $offsetAt = null;
        if (is_int($offsetRaw) || (is_string($offsetRaw) && preg_match('/^\d+$/', $offsetRaw) === 1)) {
            $offsetAt = (int)$offsetRaw;
        } elseif (!in_array((string)$offsetRaw, ['earliest', 'latest'], true)) {
            return ['ok' => false, 'error' => 'invalid kafka offset `' . (string)$offsetRaw . '`; use earliest, latest or a number'];
        }
        $username = (string)($input['username'] ?? '');
        if ($password !== '' && $username === '') {
            return ['ok' => false, 'error' => 'kafka password requires username'];
        }
        if (strlen($username) > 512 || strlen($password) > 512) {
            return ['ok' => false, 'error' => 'kafka credentials are too long'];
        }
        $messagesRaw = $input['messages'] ?? 1;
        $want = is_numeric($messagesRaw) ? (int)$messagesRaw : 1;
        $want = max(1, min(self::MAX_MESSAGES, $want));
        $timeout = (float)($context['timeout'] ?? 5);
        if ($timeout <= 0) {
            $timeout = 5.0;
        }
        $durationRaw = $input['duration_ms'] ?? 2000;
        $durationMs = is_numeric($durationRaw) ? (int)$durationRaw : 2000;
        $durationMs = max(1, min((int)round($timeout * 1000), $durationMs));
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));

        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $scheme = (string)$target['scheme'];
        if ($scheme === 'kafkas') {
            return ['ok' => false, 'error' => 'kafka TLS (kafkas://) is not supported by this build; use kafka:// (plaintext)'];
        }
        if (!in_array($scheme, ['kafka', 'tcp'], true)) {
            return ['ok' => false, 'error' => 'kafka driver requires a kafka:// or tcp:// target'];
        }
        if (strpos((string)$target['original'], '?') !== false) {
            return ['ok' => false, 'error' => 'kafka target must not contain query parameters'];
        }
        if (($target['path'] ?? '') !== '' && $target['path'] !== '/') {
            return ['ok' => false, 'error' => 'kafka target must not contain a path'];
        }

        $deadline = microtime(true) + $timeout;
        $fetchDeadline = min($deadline, microtime(true) + $durationMs / 1000);
        $sockets = [];
        try {
            $bootstrap = $this->open($target, $context, $timeout, $username, $password, $deadline, $maxBytes);
            $sockets[] = $bootstrap;
            $metadata = $this->metadata($bootstrap, $topic, $deadline, $maxBytes);
            if ($metadata['topicError'] !== 0) {
                return $this->failure($this->errorText($metadata['topicError'], 'kafka topic `' . $topic . '` unavailable'), $redact);
            }
            if (!isset($metadata['partitions'][$partition])) {
                return ['ok' => false, 'error' => 'kafka topic `' . $topic . '` has no partition ' . $partition];
            }
            if ($metadata['partitions'][$partition]['error'] !== 0) {
                return $this->failure($this->errorText($metadata['partitions'][$partition]['error'], 'kafka partition ' . $partition . ' unavailable'), $redact);
            }
            $brokers = $this->validateBrokers($metadata['brokers'], $redact);
            // broker 顺序处理连接:先释放 bootstrap,否则 leader 连接只能等到超时
            fclose($bootstrap);
            $sockets = [];
            $leaderId = (int)$metadata['partitions'][$partition]['leader'];
            if (!isset($brokers[$leaderId])) {
                return ['ok' => false, 'error' => 'kafka partition leader ' . $leaderId . ' is not listed in metadata'];
            }
            $leader = $brokers[$leaderId];
            $leaderTarget = ['scheme' => 'tcp', 'host' => $leader['host'], 'port' => $leader['port'], 'path' => '', 'original' => ''];
            $leaderSocket = $this->open($leaderTarget, ['guard' => ['ips' => $leader['ips']]], $timeout, $username, $password, $deadline, $maxBytes);
            $sockets[] = $leaderSocket;
            $offset = $offsetAt;
            if ($offset === null) {
                $offset = $this->resolveOffset($leaderSocket, $topic, $partition, (string)$offsetRaw, $deadline, $maxBytes);
            }
            $messages = [];
            $total = 0;
            while (count($messages) < $want && microtime(true) < $fetchDeadline) {
                $remaining = min(0.5, max(0.001, $fetchDeadline - microtime(true)));
                $records = $this->fetch($leaderSocket, $topic, $partition, $offset, (int)ceil($remaining * 1000), $maxBytes, $deadline);
                foreach ($records as $record) {
                    $offset = $record['offset'] + 1;
                    $total += strlen($record['value']);
                    if ($total > $maxBytes) {
                        return ['ok' => false, 'error' => 'kafka messages exceed max_bytes (' . $maxBytes . ')'];
                    }
                    $messages[] = [
                        'partition' => $partition,
                        'offset' => $record['offset'],
                        'payload' => Hex::payload($record['value']),
                    ];
                    if (count($messages) >= $want) {
                        break;
                    }
                }
            }
            $body = json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return [
                'ok' => true,
                'status' => 0,
                'headers' => ['x-topic' => $topic, 'x-messages' => (string)count($messages)],
                'body' => $body === false ? '{"messages":[]}' : $body,
                'encoding' => 'json',
                'meta' => [
                    'durationMs' => (int)round((microtime(true) - $start) * 1000),
                    'topic' => $topic,
                    'partition' => $partition,
                    'messages' => count($messages),
                ],
            ];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $redact($e->getMessage())];
        } finally {
            foreach ($sockets as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
        }
    }

    /**
     * @param array $metadataBrokers
     * @param callable $redact
     * @return array<int,array{host:string,port:int,ips:array}>
     */
    protected function validateBrokers(array $metadataBrokers, callable $redact): array
    {
        $config = $this->debugConfig();
        $brokers = [];
        foreach ($metadataBrokers as $nodeId => $broker) {
            $host = (string)$broker['host'];
            $port = (int)$broker['port'];
            if (!TargetGuard::hostAllowed($config, $host)) {
                throw new RuntimeException('kafka metadata broker `' . $host . ':' . $port . '` rejected: host is not in allow_hosts');
            }
            $ips = TargetGuard::resolveHost($host);
            if ($ips === []) {
                throw new RuntimeException('kafka metadata broker `' . $host . ':' . $port . '` resolved to no addresses');
            }
            foreach ($ips as $ip) {
                try {
                    TargetGuard::checkIp($ip, $config);
                } catch (InvalidArgumentException $e) {
                    throw new RuntimeException('kafka metadata broker `' . $host . ':' . $port . '` rejected: ' . $e->getMessage());
                }
            }
            $brokers[(int)$nodeId] = ['host' => $host, 'port' => $port, 'ips' => $ips];
        }
        return $brokers;
    }

    /**
     * @return resource
     */
    protected function open(array $target, array $context, float $timeout, string $username, string $password, float $deadline, int $maxBytes)
    {
        try {
            $socket = Socket::connect($target, $context, $timeout);
        } catch (RuntimeException $e) {
            throw new RuntimeException('kafka connect failed: ' . $e->getMessage());
        }
        if ($username !== '') {
            $this->saslPlain($socket, $username, $password, $deadline, $maxBytes);
        }
        return $socket;
    }

    protected function saslPlain($socket, string $username, string $password, float $deadline, int $maxBytes): void
    {
        $response = $this->request($socket, 17, 1, $this->kafkaString('PLAIN'), $deadline, $maxBytes);
        $pos = 0;
        $error = $this->readInt16($response, $pos);
        $count = $this->readInt32($response, $pos);
        if ($error !== 0) {
            throw new RuntimeException($this->errorText($error, 'kafka SASL handshake failed'));
        }
        if ($count < 0 || $count > 64) {
            throw new RuntimeException('invalid kafka SASL mechanism count');
        }
        $offered = [];
        for ($i = 0; $i < $count; $i++) {
            $offered[] = $this->readString($response, $pos);
        }
        if (!in_array('PLAIN', $offered, true)) {
            throw new RuntimeException('kafka broker does not offer SASL PLAIN');
        }
        $auth = "\0" . $username . "\0" . $password;
        $body = pack('N', strlen($auth)) . $auth;
        $response = $this->request($socket, 36, 0, $body, $deadline, $maxBytes);
        $pos = 0;
        $error = $this->readInt16($response, $pos);
        $message = $this->readNullableString($response, $pos);
        if ($error !== 0) {
            throw new RuntimeException('kafka SASL authentication failed: ' . ($message !== '' ? $message : $this->errorText($error, 'error ' . $error)));
        }
    }

    /**
     * @return array{brokers:array,partitions:array,topicError:int}
     */
    protected function metadata($socket, string $topic, float $deadline, int $maxBytes): array
    {
        // v4: topics + allow_auto_topic_creation=false
        $body = pack('N', 1) . $this->kafkaString($topic) . "\x00";
        $response = $this->request($socket, 3, 4, $body, $deadline, $maxBytes);
        $pos = 0;
        $this->readInt32($response, $pos); // throttle_time_ms
        $brokerCount = $this->readInt32($response, $pos);
        if ($brokerCount < 0 || $brokerCount > 1000) {
            throw new RuntimeException('invalid kafka broker count ' . $brokerCount);
        }
        $brokers = [];
        for ($i = 0; $i < $brokerCount; $i++) {
            $nodeId = $this->readInt32($response, $pos);
            $host = $this->readString($response, $pos);
            $port = $this->readInt32($response, $pos);
            $this->readNullableString($response, $pos); // rack
            if ($port < 1 || $port > 65535) {
                throw new RuntimeException('invalid kafka broker port ' . $port);
            }
            $brokers[$nodeId] = ['host' => $host, 'port' => $port];
        }
        $this->readNullableString($response, $pos); // cluster_id
        $this->readInt32($response, $pos); // controller_id
        $topicCount = $this->readInt32($response, $pos);
        if ($topicCount < 0 || $topicCount > 10000) {
            throw new RuntimeException('invalid kafka topic count ' . $topicCount);
        }
        $partitions = [];
        $topicError = 0;
        for ($i = 0; $i < $topicCount; $i++) {
            $error = $this->readInt16($response, $pos);
            $name = $this->readString($response, $pos);
            $this->readInt8($response, $pos); // is_internal
            $partitionCount = $this->readInt32($response, $pos);
            if ($partitionCount < 0 || $partitionCount > 100000) {
                throw new RuntimeException('invalid kafka partition count ' . $partitionCount);
            }
            if ($name === $topic) {
                $topicError = $error;
                for ($p = 0; $p < $partitionCount; $p++) {
                    $partitionError = $this->readInt16($response, $pos);
                    $index = $this->readInt32($response, $pos);
                    $leader = $this->readInt32($response, $pos);
                    $replicas = $this->readInt32($response, $pos);
                    if ($replicas < 0 || $replicas > 1000) {
                        throw new RuntimeException('invalid kafka replica count');
                    }
                    $pos += 4 * $replicas;
                    $this->ensure($response, $pos);
                    $isr = $this->readInt32($response, $pos);
                    if ($isr < 0 || $isr > 1000) {
                        throw new RuntimeException('invalid kafka ISR count');
                    }
                    $pos += 4 * $isr;
                    $this->ensure($response, $pos);
                    $partitions[$index] = ['error' => $partitionError, 'leader' => $leader];
                }
            } else {
                for ($p = 0; $p < $partitionCount; $p++) {
                    $this->readInt16($response, $pos);
                    $this->readInt32($response, $pos);
                    $this->readInt32($response, $pos);
                    $replicas = $this->readInt32($response, $pos);
                    if ($replicas < 0 || $replicas > 1000) {
                        throw new RuntimeException('invalid kafka replica count');
                    }
                    $pos += 4 * $replicas;
                    $this->ensure($response, $pos);
                    $isr = $this->readInt32($response, $pos);
                    if ($isr < 0 || $isr > 1000) {
                        throw new RuntimeException('invalid kafka ISR count');
                    }
                    $pos += 4 * $isr;
                    $this->ensure($response, $pos);
                }
            }
        }
        return ['brokers' => $brokers, 'partitions' => $partitions, 'topicError' => $topicError];
    }

    protected function resolveOffset($socket, string $topic, int $partition, string $offset, float $deadline, int $maxBytes): int
    {
        $timestamp = $offset === 'earliest' ? -2 : -1;
        $body = pack('N', 0xFFFFFFFF)
            . pack('N', 1) . $this->kafkaString($topic)
            . pack('N', 1) . pack('N', $partition) . $this->int64($timestamp);
        $response = $this->request($socket, 2, 1, $body, $deadline, $maxBytes);
        $pos = 0;
        $topicCount = $this->readInt32($response, $pos);
        if ($topicCount < 0 || $topicCount > 10000) {
            throw new RuntimeException('invalid kafka offset response');
        }
        for ($i = 0; $i < $topicCount; $i++) {
            $name = $this->readString($response, $pos);
            $partitionCount = $this->readInt32($response, $pos);
            if ($partitionCount < 0 || $partitionCount > 100000) {
                throw new RuntimeException('invalid kafka offset response');
            }
            for ($p = 0; $p < $partitionCount; $p++) {
                $index = $this->readInt32($response, $pos);
                $error = $this->readInt16($response, $pos);
                $this->readInt64($response, $pos); // timestamp
                $offsetValue = $this->readInt64($response, $pos);
                if ($name === $topic && $index === $partition) {
                    if ($error !== 0) {
                        throw new RuntimeException($this->errorText($error, 'kafka offset lookup failed'));
                    }
                    if ($offsetValue < 0) {
                        throw new RuntimeException('kafka offset lookup returned a negative offset');
                    }
                    return $offsetValue;
                }
            }
        }
        throw new RuntimeException('kafka offset lookup returned no partition');
    }

    /**
     * @return array<int,array{offset:int,value:string}>
     */
    protected function fetch($socket, string $topic, int $partition, int $offset, int $maxWaitMs, int $maxBytes, float $deadline): array
    {
        $body = pack('N', 0xFFFFFFFF)
            . pack('N', max(1, min(500, $maxWaitMs)))
            . pack('N', 1)
            . pack('N', $maxBytes)
            . "\x00"
            . pack('N', 1) . $this->kafkaString($topic)
            . pack('N', 1) . pack('N', $partition) . $this->int64($offset) . pack('N', $maxBytes);
        $response = $this->request($socket, 1, 4, $body, $deadline, $maxBytes);
        $pos = 0;
        $this->readInt32($response, $pos); // throttle_time_ms
        $topicCount = $this->readInt32($response, $pos);
        if ($topicCount < 0 || $topicCount > 10000) {
            throw new RuntimeException('invalid kafka fetch response');
        }
        for ($i = 0; $i < $topicCount; $i++) {
            $name = $this->readString($response, $pos);
            $partitionCount = $this->readInt32($response, $pos);
            if ($partitionCount < 0 || $partitionCount > 100000) {
                throw new RuntimeException('invalid kafka fetch response');
            }
            for ($p = 0; $p < $partitionCount; $p++) {
                $index = $this->readInt32($response, $pos);
                $error = $this->readInt16($response, $pos);
                $this->readInt64($response, $pos); // high watermark
                $this->readInt64($response, $pos); // last stable offset
                $aborted = $this->readInt32($response, $pos);
                if ($aborted > 0) {
                    $pos += 16 * $aborted;
                    $this->ensure($response, $pos);
                }
                $recordsLength = $this->readInt32($response, $pos);
                if ($recordsLength < 0) {
                    $recordsLength = 0;
                }
                $this->ensure($response, $pos, $recordsLength);
                $records = substr($response, $pos, $recordsLength);
                $pos += $recordsLength;
                if ($name === $topic && $index === $partition) {
                    if ($error !== 0) {
                        throw new RuntimeException($this->errorText($error, 'kafka fetch failed'));
                    }
                    return $this->parseRecordSet($records, $maxBytes);
                }
            }
        }
        return [];
    }

    /**
     * @return array<int,array{offset:int,value:string}>
     */
    protected function parseRecordSet(string $records, int $maxBytes): array
    {
        $messages = [];
        $pos = 0;
        $total = strlen($records);
        while ($pos + 12 <= $total) {
            $baseOffset = $this->readInt64($records, $pos);
            $batchLength = $this->readInt32($records, $pos);
            if ($batchLength < 49 || $pos + $batchLength > $total) {
                throw new RuntimeException('truncated kafka record batch');
            }
            $end = $pos + $batchLength;
            $this->readInt32($records, $pos); // partition leader epoch
            $magic = $this->readInt8($records, $pos);
            if ($magic !== 2) {
                throw new RuntimeException('kafka legacy message format is not supported; broker must support record batch v2');
            }
            $this->readInt32($records, $pos); // crc (not verified)
            $attributes = $this->readInt16($records, $pos);
            $lastOffsetDelta = $this->readInt32($records, $pos);
            $this->readInt64($records, $pos); // base timestamp
            $this->readInt64($records, $pos); // max timestamp
            $this->readInt64($records, $pos); // producer id
            $this->readInt16($records, $pos); // producer epoch
            $this->readInt32($records, $pos); // base sequence
            $recordCount = $this->readInt32($records, $pos);
            if ($recordCount < 0 || $recordCount > 1000000) {
                throw new RuntimeException('invalid kafka record count ' . $recordCount);
            }
            $payload = substr($records, $pos, $end - $pos);
            $pos = $end;
            $codec = $attributes & 0x07;
            if ($codec !== 0) {
                $payload = $this->decompress($payload, $codec, $maxBytes);
            }
            $recordPos = 0;
            for ($i = 0; $i < $recordCount; $i++) {
                $length = $this->readVarint($payload, $recordPos);
                if ($length < 0 || $recordPos + $length > strlen($payload)) {
                    throw new RuntimeException('truncated kafka record');
                }
                $recordEnd = $recordPos + $length;
                $this->readInt8($payload, $recordPos); // attributes
                $this->readVarint($payload, $recordPos); // timestamp delta
                $offsetDelta = $this->readVarint($payload, $recordPos);
                $keyLength = $this->readVarint($payload, $recordPos);
                if ($keyLength > 0) {
                    $recordPos += $keyLength;
                    $this->ensure($payload, $recordPos);
                }
                $valueLength = $this->readVarint($payload, $recordPos);
                $value = '';
                if ($valueLength > 0) {
                    $this->ensure($payload, $recordPos, $valueLength);
                    $value = substr($payload, $recordPos, $valueLength);
                    $recordPos += $valueLength;
                }
                $headerCount = $this->readVarint($payload, $recordPos);
                if ($headerCount < 0 || $headerCount > 10000) {
                    throw new RuntimeException('invalid kafka record header count');
                }
                for ($h = 0; $h < $headerCount; $h++) {
                    $keySize = $this->readVarint($payload, $recordPos);
                    if ($keySize > 0) {
                        $recordPos += $keySize;
                        $this->ensure($payload, $recordPos);
                    }
                    $valueSize = $this->readVarint($payload, $recordPos);
                    if ($valueSize > 0) {
                        $recordPos += $valueSize;
                        $this->ensure($payload, $recordPos);
                    }
                }
                if ($recordPos > $recordEnd) {
                    throw new RuntimeException('kafka record length mismatch');
                }
                $recordPos = $recordEnd;
                $offset = $baseOffset + $offsetDelta;
                if ($offset < 0 || $offsetDelta < 0 || ($lastOffsetDelta >= 0 && $offsetDelta > $lastOffsetDelta)) {
                    throw new RuntimeException('invalid kafka record offset delta');
                }
                $messages[] = ['offset' => $offset, 'value' => $value];
            }
        }
        return $messages;
    }

    protected function decompress(string $payload, int $codec, int $maxBytes): string
    {
        if ($codec === 1) {
            if (!function_exists('gzdecode')) {
                throw new RuntimeException('kafka gzip record batch requires the zlib extension');
            }
            $decoded = @gzdecode($payload, $maxBytes + 1);
            if ($decoded === false) {
                throw new RuntimeException('kafka gzip record batch is invalid');
            }
            if (strlen($decoded) > $maxBytes) {
                throw new RuntimeException('kafka record batch exceeds max_bytes (' . $maxBytes . ')');
            }
            return $decoded;
        }
        throw new RuntimeException('kafka compression codec ' . $codec . ' is not supported');
    }

    /**
     * @return string 去掉 correlation id 的响应体
     */
    protected function request($socket, int $apiKey, int $version, string $body, float $deadline, int $maxBytes): string
    {
        $this->correlation++;
        $payload = pack('n', $apiKey) . pack('n', $version) . pack('N', $this->correlation)
            . $this->kafkaString(self::CLIENT_ID) . $body;
        if (!Socket::writeAll($socket, pack('N', strlen($payload)) . $payload)) {
            throw new RuntimeException('kafka write failed');
        }
        $header = Socket::readExact($socket, 4, $deadline, 'kafka response');
        $length = unpack('N', $header)[1];
        if ($length < 4) {
            throw new RuntimeException('invalid kafka response length');
        }
        if ($length > $maxBytes) {
            throw new RuntimeException('kafka response exceeds max_bytes (' . $maxBytes . ')');
        }
        $response = Socket::readExact($socket, $length, $deadline, 'kafka response');
        $correlation = unpack('N', substr($response, 0, 4))[1];
        if ($correlation !== $this->correlation) {
            throw new RuntimeException('kafka response correlation mismatch');
        }
        return substr($response, 4);
    }

    protected function kafkaString(string $value): string
    {
        return pack('n', strlen($value) & 0xFFFF) . $value;
    }

    protected function int64(int $value): string
    {
        return pack('J', $value);
    }

    protected function ensure(string $data, int $pos, int $need = 0): void
    {
        if ($need < 0 || $pos < 0 || $pos + $need > strlen($data)) {
            throw new RuntimeException('truncated kafka response');
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
        return $value >= 0x8000 ? $value - 0x10000 : $value;
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

    protected function readString(string $data, int &$pos): string
    {
        $length = $this->readInt16($data, $pos);
        if ($length < 0) {
            throw new RuntimeException('invalid kafka string length');
        }
        $this->ensure($data, $pos, $length);
        $value = substr($data, $pos, $length);
        $pos += $length;
        return $value;
    }

    protected function readNullableString(string $data, int &$pos): string
    {
        $length = $this->readInt16($data, $pos);
        if ($length < 0) {
            return '';
        }
        $this->ensure($data, $pos, $length);
        $value = substr($data, $pos, $length);
        $pos += $length;
        return $value;
    }

    protected function readVarint(string $data, int &$pos): int
    {
        $raw = 0;
        $shift = 0;
        for ($i = 0; $i < 10; $i++) {
            $this->ensure($data, $pos, 1);
            $byte = ord($data[$pos++]);
            $raw |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return ($raw >> 1) ^ -($raw & 1);
            }
            $shift += 7;
        }
        throw new RuntimeException('invalid kafka varint');
    }

    protected function errorText(int $code, string $prefix): string
    {
        $name = isset(static::$errorNames[$code]) ? static::$errorNames[$code] : 'error ' . $code;
        return $prefix . ': ' . $name;
    }

    /**
     * @return array protocols.debug 配置;未初始化时返回空
     */
    protected function debugConfig(): array
    {
        try {
            $config = ConfigProvider::get('protocols.debug');
            return is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param callable $redact
     * @return array
     */
    protected function failure(string $message, callable $redact): array
    {
        return ['ok' => false, 'error' => $redact($message)];
    }
}
