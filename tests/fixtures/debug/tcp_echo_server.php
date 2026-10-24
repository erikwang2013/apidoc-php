<?php
/**
 * TCP 回显服务: 读取一次数据后回写并关闭
 * 用法: php tcp_echo_server.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19001);
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
    $data = fread($conn, 65536);
    if (is_string($data) && $data !== '') {
        fwrite($conn, $data);
    }
    fclose($conn);
}
