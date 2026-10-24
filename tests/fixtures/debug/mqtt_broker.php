<?php
/**
 * MQTT 3.1.1 测试 broker: CONNECT -> CONNACK, PUBLISH(QoS1) -> PUBACK, DISCONNECT -> close
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

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 5);
    while (($packet = readPacket($conn)) !== null) {
        if ($packet['type'] === 1) {
            // CONNACK: session present=0, return code=0
            writeAll($conn, "\x20\x02\x00\x00");
        } elseif ($packet['type'] === 3) {
            $qos = ($packet['flags'] >> 1) & 0x03;
            if ($qos === 1 && strlen($packet['body']) >= 4) {
                $topicLength = unpack('n', substr($packet['body'], 0, 2))[1];
                $packetId = substr($packet['body'], 2 + $topicLength, 2);
                // PUBACK
                writeAll($conn, "\x40\x02" . $packetId);
            }
        } elseif ($packet['type'] === 14) {
            break;
        }
    }
    fclose($conn);
}
