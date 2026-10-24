<?php
/**
 * RTSP 测试服务: 读取一个请求,回 200 OK + 请求的 CSeq + Public
 * 用法: php rtsp_server.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19009);
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
    stream_set_timeout($conn, 5);
    $request = '';
    while (strpos($request, "\r\n\r\n") === false && !feof($conn)) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }
    if (preg_match('/^CSeq:\s*(\d+)/mi', $request, $matches) !== 1) {
        fwrite($conn, "RTSP/1.0 400 Bad Request\r\nCSeq: 0\r\nContent-Length: 0\r\n\r\n");
        fclose($conn);
        continue;
    }
    $response = "RTSP/1.0 200 OK\r\n"
        . "CSeq: {$matches[1]}\r\n"
        . "Public: OPTIONS, DESCRIBE, SETUP, PLAY, TEARDOWN\r\n"
        . "Content-Length: 0\r\n\r\n";
    fwrite($conn, $response);
    fclose($conn);
}
