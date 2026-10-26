<?php
/**
 * 最小 HTTP/2 cleartext(h2c, prior knowledge)fixture: 等客户端 HEADERS 后返回 200 + "h2-ok"。
 * 只覆盖测试所需的单请求/单流场景,不做完整 HTTP/2 实现。
 * 用法: php h2_server.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19016);
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}
function h2Frame(int $type, int $flags, int $streamId, string $payload): string
{
    return substr(pack('N', strlen($payload)), 1, 3) . chr($type) . chr($flags) . pack('N', $streamId) . $payload;
}
while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 2);
    $preface = '';
    while (strlen($preface) < 24 && !feof($conn)) {
        $chunk = fread($conn, 24 - strlen($preface));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $preface .= $chunk;
    }
    if ($preface !== "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n") {
        fclose($conn);
        continue;
    }
    fwrite($conn, h2Frame(4, 0, 0, ''));
    $gotRequest = false;
    $deadline = microtime(true) + 2;
    while (!$gotRequest && microtime(true) < $deadline) {
        $head = '';
        while (strlen($head) < 9 && !feof($conn)) {
            $chunk = fread($conn, 9 - strlen($head));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $head .= $chunk;
        }
        if (strlen($head) < 9) {
            break;
        }
        $length = unpack('N', "\x00" . substr($head, 0, 3))[1];
        $type = ord($head[3]);
        $flags = ord($head[4]);
        $payload = '';
        while (strlen($payload) < $length && !feof($conn)) {
            $chunk = fread($conn, $length - strlen($payload));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $payload .= $chunk;
        }
        if ($type === 4 && ($flags & 0x1) === 0) {
            fwrite($conn, h2Frame(4, 0x1, 0, ''));
        } elseif ($type === 6 && ($flags & 0x1) === 0) {
            fwrite($conn, h2Frame(6, 0x1, 0, $payload));
        } elseif ($type === 1) {
            $gotRequest = true;
        }
    }
    if ($gotRequest) {
        // HPACK 静态表 index 8 = :status 200
        fwrite($conn, h2Frame(1, 0x4, 1, "\x88"));
        fwrite($conn, h2Frame(0, 0x1, 1, 'h2-ok'));
        usleep(100000);
    }
    fclose($conn);
}
