<?php
/**
 * Dubbo 2.0 fixture: 校验 16 字节请求头并回显 request id,body 固定 hessian2 字符串 "ok"。
 * 用法: php dubbo_server.php <host> <port> [reply|silent|large]
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19015);
$mode = $argv[3] ?? 'reply';
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}
function readExact($conn, int $length): string
{
    $data = '';
    while (strlen($data) < $length && !feof($conn)) {
        $chunk = fread($conn, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    return $data;
}
while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 1);
    $head = readExact($conn, 16);
    if (strlen($head) < 16) {
        fclose($conn);
        continue;
    }
    $length = unpack('N', substr($head, 12, 4))[1];
    readExact($conn, $length);
    if ($mode === 'silent') {
        sleep(3);
        fclose($conn);
        continue;
    }
    $body = $mode === 'large' ? str_repeat('x', 4096) : "\x01\x02ok";
    // 0x02 = response+hessian2, 20 = OK
    fwrite($conn, "\xda\xbb\x02\x14" . substr($head, 4, 8) . pack('N', strlen($body)) . $body);
    fclose($conn);
}
