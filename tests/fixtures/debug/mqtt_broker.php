<?php
/**
 * MQTT 3.1.1 测试 broker: CONNECT -> CONNACK, PUBLISH(QoS1) -> PUBACK, SUBSCRIBE -> SUBACK + hello, DISCONNECT -> close
 * cleanSession=false 模拟:记住 clientId 的订阅,重连时把排队消息与 CONNACK 一起下发。
 * topic 前缀 queued/ 触发同一次写出的排队消息(重复 packetId),split/ 触发跨两次写出的报文。
 * 用法: php mqtt_broker.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19003);
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}

function readByte($conn): ?string
{
    $byte = fread($conn, 1);
    return ($byte === false || $byte === '') ? null : $byte;
}

function readPacket($conn): ?array
{
    $first = readByte($conn);
    if ($first === null) {
        return null;
    }
    $length = 0;
    $multiplier = 1;
    do {
        $byte = readByte($conn);
        if ($byte === null) {
            return null;
        }
        $digit = ord($byte);
        $length += ($digit & 0x7F) * $multiplier;
        $multiplier *= 128;
    } while (($digit & 0x80) !== 0);
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($conn, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $body .= $chunk;
    }
    return ['type' => (ord($first) >> 4) & 0x0F, 'flags' => ord($first) & 0x0F, 'body' => $body];
}

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

function encodeRemainingLength(int $length): string
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

function buildPublish(string $topic, string $payload, int $qos = 0, int $packetId = 1): string
{
    $body = pack('n', strlen($topic)) . $topic;
    if ($qos === 1) {
        $body .= pack('n', $packetId & 0xFFFF);
    }
    $body .= $payload;
    return chr(0x30 | ($qos === 1 ? 0x02 : 0x00)) . encodeRemainingLength(strlen($body)) . $body;
}

function connectClientId(string $body): string
{
    // body: 2+4 协议名,1 级别,1 flags,2 keepalive,2 clientId 长度,clientId
    if (strlen($body) < 12) {
        return '';
    }
    $length = unpack('n', substr($body, 10, 2))[1];
    return (string)substr($body, 12, $length);
}

// 持久会话:clientId => 订阅 topic(模拟 cleanSession=false)
$subscriptions = [];

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 5);
    $clientId = '';
    while (($packet = readPacket($conn)) !== null) {
        if ($packet['type'] === 1) {
            $clientId = connectClientId($packet['body']);
            $connack = "\x20\x02\x00\x00";
            $topic = $subscriptions[$clientId] ?? '';
            if (strpos($topic, 'queued/') === 0) {
                // CONNACK 与排队消息同一次写出;同一 packetId 发送两次,验证去重
                writeAll($conn, $connack . buildPublish($topic, 'hello', 1, 7) . buildPublish($topic, 'hello', 1, 7));
            } elseif (strpos($topic, 'split/') === 0) {
                // CONNACK + 半个 PUBLISH,200ms 后补另一半
                $publish = buildPublish($topic, 'hello', 1, 7);
                $half = intdiv(strlen($publish), 2);
                writeAll($conn, $connack . substr($publish, 0, $half));
                usleep(200000);
                writeAll($conn, substr($publish, $half));
            } else {
                writeAll($conn, $connack);
            }
        } elseif ($packet['type'] === 3) {
            $qos = ($packet['flags'] >> 1) & 0x03;
            if ($qos === 1 && strlen($packet['body']) >= 4) {
                $topicLength = unpack('n', substr($packet['body'], 0, 2))[1];
                $packetId = substr($packet['body'], 2 + $topicLength, 2);
                // PUBACK
                writeAll($conn, "\x40\x02" . $packetId);
            }
        } elseif ($packet['type'] === 8) {
            // SUBSCRIBE: packetId + topic + qos -> SUBACK;首次订阅补一条 hello
            if (strlen($packet['body']) >= 4) {
                $packetId = substr($packet['body'], 0, 2);
                $topicLength = unpack('n', substr($packet['body'], 2, 2))[1];
                $topic = substr($packet['body'], 4, $topicLength);
                $already = $clientId !== '' && isset($subscriptions[$clientId]);
                if ($clientId !== '') {
                    $subscriptions[$clientId] = $topic;
                }
                writeAll($conn, "\x90\x03" . $packetId . "\x01");
                if (!$already) {
                    writeAll($conn, buildPublish($topic, 'hello'));
                }
            }
        } elseif ($packet['type'] === 14) {
            break;
        }
    }
    fclose($conn);
}
