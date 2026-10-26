<?php
/**
 * Thrift TBinaryProtocol fixture: 回显请求方法名,返回 i32 字段 0 = 42。
 * 用法: php thrift_server.php <host> <port> [reply|silent|large]
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19014);
$mode = $argv[3] ?? 'reply';
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}
while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 1);
    $request = fread($conn, 65536);
    if ($mode === 'silent') {
        sleep(3);
        fclose($conn);
        continue;
    }
    if ($mode === 'large') {
        fwrite($conn, str_repeat('x', 4096));
        fclose($conn);
        continue;
    }
    $method = 'ping';
    if (is_string($request) && strlen($request) >= 8) {
        $first = unpack('N', substr($request, 0, 4))[1];
        if ($first > 0x7fffffff) {
            $nameLength = unpack('N', substr($request, 4, 4))[1];
            if ($nameLength > 0 && $nameLength <= 255 && strlen($request) >= 8 + $nameLength) {
                $method = substr($request, 8, $nameLength);
            }
        }
    }
    $reply = pack('N', 0x80010000 | 2) . pack('N', strlen($method)) . $method . pack('N', 0)
        . "\x08\x00\x00" . pack('N', 42) . "\x00";
    fwrite($conn, $reply);
    fclose($conn);
}
