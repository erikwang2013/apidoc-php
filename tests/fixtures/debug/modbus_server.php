<?php
/**
 * Modbus TCP 测试服务: function 3 返回寄存器 100,200...;其他 function 返回异常 1
 * 用法: php modbus_server.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19007);
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}

function readExact($conn, int $length): ?string
{
    $buffer = '';
    while (strlen($buffer) < $length) {
        $chunk = fread($conn, $length - strlen($buffer));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $buffer .= $chunk;
    }
    return $buffer;
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
    while (($header = readExact($conn, 6)) !== null) {
        $transaction = substr($header, 0, 2);
        $length = unpack('n', substr($header, 4, 2))[1];
        if ($length < 2 || $length > 254 || ($rest = readExact($conn, $length)) === null) {
            break;
        }
        $unit = ord($rest[0]);
        $function = ord($rest[1]);
        if ($function === 3) {
            $address = unpack('n', substr($rest, 2, 2))[1];
            $quantity = max(1, min(unpack('n', substr($rest, 4, 2))[1], 125));
            $pdu = chr(3) . chr($quantity * 2);
            for ($i = 0; $i < $quantity; $i++) {
                $pdu .= pack('n', 100 * ($i + 1) + $address);
            }
        } else {
            $pdu = chr($function | 0x80) . chr(1);
        }
        $body = chr($unit) . $pdu;
        writeAll($conn, $transaction . pack('n', 0) . pack('n', strlen($body)) . $body);
    }
    fclose($conn);
}
