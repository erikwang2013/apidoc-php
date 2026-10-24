<?php
/**
 * CoAP 测试服务: 回显 message id,回复 ACK 2.05 Content + 小载荷
 * 用法: php coap_server.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19006);
$server = @stream_socket_server("udp://{$host}:{$port}", $errno, $errstr, STREAM_SERVER_BIND);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}

while (true) {
    $data = @stream_socket_recvfrom($server, 65535, 0, $peer);
    if ($data === false || strlen($data) < 4 || $peer === null) {
        continue;
    }
    // 4 字节头: version/type/token length, code, message id
    $messageId = substr($data, 2, 2);
    $response = "\x60\x45" . $messageId . "\xFFcoap-ok";
    @stream_socket_sendto($server, $response, 0, $peer);
}
