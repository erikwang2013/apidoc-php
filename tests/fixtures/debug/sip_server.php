<?php
/**
 * SIP UDP 测试服务: 读取一个请求,回 200 OK + 请求的 Call-ID/CSeq
 * 用法: php sip_server.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19010);
$server = @stream_socket_server("udp://{$host}:{$port}", $errno, $errstr, STREAM_SERVER_BIND);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}
while (true) {
    $data = @stream_socket_recvfrom($server, 65535, 0, $peer);
    if ($data === false || $data === '' || $peer === null) {
        continue;
    }
    $cseq = preg_match('/^CSeq:\s*(.+)$/mi', $data, $matches) === 1 ? trim($matches[1]) : '1 OPTIONS';
    $callId = preg_match('/^Call-ID:\s*(.+)$/mi', $data, $matches) === 1 ? trim($matches[1]) : 'unknown';
    $via = preg_match('/^Via:\s*(.+)$/mi', $data, $matches) === 1 ? trim($matches[1]) : '';
    $response = "SIP/2.0 200 OK\r\n";
    if ($via !== '') {
        $response .= "Via: {$via}\r\n";
    }
    $response .= "Call-ID: {$callId}\r\nCSeq: {$cseq}\r\nContent-Length: 0\r\n\r\n";
    @stream_socket_sendto($server, $response, 0, $peer);
}
