<?php
/**
 * UDP 回显服务: 回写一个数据报
 * 用法: php udp_echo_server.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19002);
$server = @stream_socket_server("udp://{$host}:{$port}", $errno, $errstr, STREAM_SERVER_BIND);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}
while (true) {
    $data = @stream_socket_recvfrom($server, 65536, 0, $peer);
    if ($data === false || $peer === null) {
        continue;
    }
    stream_socket_sendto($server, $data, 0, $peer);
}
