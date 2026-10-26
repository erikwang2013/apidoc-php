<?php
declare(strict_types=1);

/**
 * 只支持 HTTP/1.1 的 TLS 服务:用于验证 HTTP/2 不能静默降级
 * 用法: php tls_http1_server.php 127.0.0.1 19020 cert.pem key.pem
 */
if ($argc < 5) {
    fwrite(STDERR, "usage: tls_http1_server.php host port cert key\n");
    exit(1);
}
$host = $argv[1];
$port = (int)$argv[2];
$cert = $argv[3];
$key = $argv[4];
$context = stream_context_create(['ssl' => [
    'local_cert' => $cert,
    'local_pk' => $key,
    'verify_peer' => false,
    'allow_self_signed' => true,
]]);
$server = @stream_socket_server('tls://' . $host . ':' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!is_resource($server)) {
    fwrite(STDERR, 'listen failed: ' . $errstr . ' (' . $errno . ")\n");
    exit(1);
}
while (true) {
    $conn = @stream_socket_accept($server, 30);
    if (!is_resource($conn)) {
        continue;
    }
    stream_set_timeout($conn, 5);
    $request = '';
    while (strpos($request, "\r\n\r\n") === false && !feof($conn)) {
        $chunk = fread($conn, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }
    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello");
    fclose($conn);
}
