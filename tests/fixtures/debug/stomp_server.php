<?php
/**
 * STOMP 1.2 测试服务: CONNECT -> CONNECTED, SEND -> RECEIPT, DISCONNECT -> close
 * 用法: php stomp_server.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19004);
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}

function readFrame($conn): ?array
{
    $data = '';
    while (strpos($data, "\0") === false) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $data .= $chunk;
    }
    $data = substr($data, 0, strpos($data, "\0"));
    $parts = explode("\n\n", $data, 2);
    $lines = explode("\n", $parts[0]);
    $command = trim((string)array_shift($lines));
    $headers = [];
    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $headers[substr($line, 0, $colon)] = substr($line, $colon + 1);
        }
    }
    return ['command' => $command, 'headers' => $headers, 'body' => $parts[1] ?? ''];
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
    while (($frame = readFrame($conn)) !== null) {
        if ($frame['command'] === 'CONNECT' || $frame['command'] === 'STOMP') {
            writeAll($conn, "CONNECTED\nversion:1.2\n\n\0");
        } elseif ($frame['command'] === 'SEND') {
            $receiptId = $frame['headers']['receipt'] ?? 'receipt-1';
            writeAll($conn, "RECEIPT\nreceipt-id:" . $receiptId . "\n\n\0");
        } elseif ($frame['command'] === 'SUBSCRIBE') {
            // SUBSCRIBE -> 一条 hello MESSAGE
            $destination = (string)($frame['headers']['destination'] ?? '');
            $headers = $destination !== '' ? 'destination:' . $destination . "\n" : '';
            writeAll($conn, "MESSAGE\n" . $headers . "subscription:0\ncontent-length:5\n\nhello\0");
        } elseif ($frame['command'] === 'DISCONNECT') {
            break;
        }
    }
    fclose($conn);
}
