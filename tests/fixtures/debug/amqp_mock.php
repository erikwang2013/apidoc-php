<?php
/**
 * AMQP 0-9-1 测试 broker:START/Tune/Open、Channel.Open、Confirm.Select、
 * Basic.Publish(确认 Ack)、Basic.Consume(不 ack 投递)、Queue.Declare。
 * 凭据固定 debug/secret;失败响应会带出提交的密码,用于验证驱动脱敏。
 * queue=debug/queue 投递 hello 与 0x00FF;queue=debug/single 投递一条;
 * queue=debug/partial 只发 deliver/header 不发 body;其余队列返回最近一次 publish 到同名 routing_key 的消息。
 * 用法: php amqp_mock.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19022);
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}

$published = [];

function writeAll($conn, string $data): void
{
    $written = 0;
    while ($written < strlen($data)) {
        $bytes = fwrite($conn, substr($data, $written));
        if ($bytes === false || $bytes === 0) {
            return;
        }
        $written += $bytes;
    }
}

function readExact($conn, int $length): ?string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($conn, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $data .= $chunk;
    }
    return $data;
}

function readFrame($conn): ?array
{
    $head = readExact($conn, 7);
    if ($head === null) {
        return null;
    }
    $type = ord($head[0]);
    $channel = unpack('n', substr($head, 1, 2))[1];
    $size = unpack('N', substr($head, 3, 4))[1];
    $payload = $size > 0 ? readExact($conn, $size) : '';
    if ($payload === null) {
        return null;
    }
    $end = readExact($conn, 1);
    if ($end === null || ord($end) !== 0xCE) {
        return null;
    }
    return ['type' => $type, 'channel' => $channel, 'payload' => $payload];
}

function sendFrame($conn, int $type, int $channel, string $payload): void
{
    writeAll($conn, chr($type) . pack('n', $channel) . pack('N', strlen($payload)) . $payload . "\xCE");
}

function sendMethod($conn, int $channel, int $class, int $method, string $args): void
{
    sendFrame($conn, 1, $channel, pack('n', $class) . pack('n', $method) . $args);
}

function shortstr(string $value): string
{
    return chr(strlen($value)) . $value;
}

function u16(string $data, int &$pos): int
{
    $value = unpack('n', substr($data, $pos, 2))[1];
    $pos += 2;
    return $value;
}

function u32(string $data, int &$pos): int
{
    $value = unpack('N', substr($data, $pos, 4))[1];
    $pos += 4;
    return $value;
}

function u64(string $data, int &$pos): int
{
    $value = unpack('J', substr($data, $pos, 8))[1];
    $pos += 8;
    return $value;
}

function rshortstr(string $data, int &$pos): string
{
    $length = ord($data[$pos] ?? "\0");
    $pos++;
    $value = substr($data, $pos, $length);
    $pos += $length;
    return $value;
}

function rlongstr(string $data, int &$pos): string
{
    $length = u32($data, $pos);
    $value = substr($data, $pos, $length);
    $pos += $length;
    return $value;
}

function skipTable(string $data, int &$pos): void
{
    $length = u32($data, $pos);
    $pos += $length;
}

/**
 * AMQPLAIN response: table 里取 LOGIN/PASSWORD
 * @return array{0:string,1:string}
 */
function parseAmqplain(string $table): array
{
    $pos = 0;
    $length = u32($table, $pos);
    $end = $pos + $length;
    $values = [];
    while ($pos < $end) {
        $key = rshortstr($table, $pos);
        $type = $table[$pos] ?? '';
        $pos++;
        if ($type === 'S') {
            $values[$key] = rlongstr($table, $pos);
        } else {
            return ['', ''];
        }
    }
    return [$values['LOGIN'] ?? '', $values['PASSWORD'] ?? ''];
}

function sendDelivery($conn, string $tag, int $deliveryTag, string $routingKey, string $body): void
{
    $args = shortstr($tag) . pack('J', $deliveryTag) . "\x00" . shortstr('') . shortstr($routingKey);
    sendMethod($conn, 1, 60, 60, $args);
    sendFrame($conn, 2, 1, pack('n', 60) . pack('n', 0) . pack('J', strlen($body)) . pack('n', 0));
    if ($body !== '') {
        sendFrame($conn, 3, 1, $body);
    }
}

/**
 * 只发送 deliver 与 content header,不发送 body:驱动必须报错而不是返回空结果
 */
function sendPartialDelivery($conn, string $tag, int $deliveryTag, string $routingKey): void
{
    $args = shortstr($tag) . pack('J', $deliveryTag) . "\x00" . shortstr('') . shortstr($routingKey);
    sendMethod($conn, 1, 60, 60, $args);
    sendFrame($conn, 2, 1, pack('n', 60) . pack('n', 0) . pack('J', 10) . pack('n', 0));
}

function handleConnection($conn): void
{
    global $published;
    $header = readExact($conn, 8);
    if ($header !== "AMQP\x00\x00\x09\x01") {
        return;
    }
    sendMethod($conn, 0, 10, 10, chr(0) . chr(9) . pack('N', 0)
        . pack('N', strlen('PLAIN AMQPLAIN')) . 'PLAIN AMQPLAIN' . pack('N', 5) . 'en_US');
    $tuned = false;
    while (true) {
        $frame = readFrame($conn);
        if ($frame === null) {
            return;
        }
        if ($frame['type'] !== 1) {
            continue;
        }
        $payload = $frame['payload'];
        $pos = 0;
        $class = u16($payload, $pos);
        $method = u16($payload, $pos);
        $args = substr($payload, 4);
        $pos = 0;
        if ($class === 10 && $method === 11) {
            skipTable($args, $pos); // client properties
            $mechanism = rshortstr($args, $pos);
            $response = rlongstr($args, $pos);
            if ($mechanism === 'PLAIN') {
                $parts = explode("\0", $response);
                $user = $parts[1] ?? '';
                $password = $parts[2] ?? '';
            } elseif ($mechanism === 'AMQPLAIN') {
                [$user, $password] = parseAmqplain($response);
            } else {
                $user = '';
                $password = '';
            }
            if ($user !== 'debug' || $password !== 'secret') {
                $text = 'ACCESS_REFUSED - login refused for ' . $user . ' with password ' . $password;
                sendMethod($conn, 0, 10, 50, pack('n', 403) . shortstr($text) . pack('n', 0) . pack('n', 0));
                return;
            }
            sendMethod($conn, 0, 10, 30, pack('n', 2047) . pack('N', 131072) . pack('n', 0));
            continue;
        }
        if ($class === 10 && $method === 31) {
            $tuned = true;
            continue;
        }
        if ($class === 10 && $method === 40) {
            sendMethod($conn, 0, 10, 41, shortstr(''));
            continue;
        }
        if ($class === 20 && $method === 10) {
            sendMethod($conn, 1, 20, 11, pack('N', 0));
            continue;
        }
        if ($class === 85 && $method === 10) {
            sendMethod($conn, 1, 85, 11, '');
            continue;
        }
        if ($class === 50 && $method === 10) {
            u16($args, $pos); // ticket
            $queue = rshortstr($args, $pos);
            sendMethod($conn, 1, 50, 11, shortstr($queue) . pack('N', 0) . pack('N', 0));
            continue;
        }
        if ($class === 60 && $method === 20) {
            u16($args, $pos); // ticket
            $queue = rshortstr($args, $pos);
            $tag = rshortstr($args, $pos);
            sendMethod($conn, 1, 60, 21, shortstr($tag));
            if ($queue === 'debug/queue') {
                sendDelivery($conn, $tag, 1, $queue, 'hello');
                sendDelivery($conn, $tag, 2, $queue, "\x00\xFF");
            } elseif ($queue === 'debug/single') {
                sendDelivery($conn, $tag, 1, $queue, 'hello');
            } elseif ($queue === 'debug/large') {
                sendDelivery($conn, $tag, 1, $queue, str_repeat('x', 300));
            } elseif ($queue === 'debug/partial') {
                sendPartialDelivery($conn, $tag, 1, $queue);
            } elseif (isset($published[$queue])) {
                sendDelivery($conn, $tag, 1, $queue, $published[$queue]);
            }
            continue;
        }
        if ($class === 60 && $method === 30) {
            $tag = rshortstr($args, $pos);
            sendMethod($conn, 1, 60, 31, shortstr($tag));
            continue;
        }
        if ($class === 60 && $method === 40) {
            u16($args, $pos); // ticket
            rshortstr($args, $pos); // exchange
            $routingKey = rshortstr($args, $pos);
            $header = readFrame($conn);
            if ($header === null || $header['type'] !== 2) {
                return;
            }
            $headerPos = 0;
            u16($header['payload'], $headerPos); // class id
            u16($header['payload'], $headerPos); // weight
            $bodySize = u64($header['payload'], $headerPos);
            $body = '';
            while (strlen($body) < $bodySize) {
                $bodyFrame = readFrame($conn);
                if ($bodyFrame === null || $bodyFrame['type'] !== 3) {
                    return;
                }
                $body .= $bodyFrame['payload'];
            }
            $published[$routingKey] = $body;
            sendMethod($conn, 1, 60, 80, pack('J', 1) . "\x00");
            continue;
        }
        if ($class === 20 && $method === 40) {
            sendMethod($conn, 1, 20, 41, '');
            continue;
        }
        if ($class === 10 && $method === 50) {
            sendMethod($conn, 0, 10, 51, '');
            return;
        }
        if (!$tuned) {
            return;
        }
    }
}

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 5);
    handleConnection($conn);
    fclose($conn);
}
