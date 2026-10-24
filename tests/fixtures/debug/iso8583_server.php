<?php
/**
 * ISO 8583 测试服务: 读 2 字节大端长度前缀,回复 MTI 0810 + bitmap + APPROVED
 * 用法: php iso8583_server.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19008);
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}

function readExact($conn, int $length): ?string
{
    $buffer = '';
    while (strlen($buffer) < $length) {
        $chunk = fread($conn, $length - strlen($buffer));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $buffer .= $chunk;
    }
    return $buffer;
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

$responseBody = '0810' . str_repeat("\x00", 16) . 'APPROVED';

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 5);
    while (($prefix = readExact($conn, 2)) !== null) {
        $length = unpack('n', $prefix)[1];
        if ($length < 1 || $length > 65535 || ($request = readExact($conn, $length)) === null) {
            break;
        }
        // 回显 bitmap 之后的请求字段,用于断言驱动字段袋透传
        $echo = strlen($request) > 20 ? substr($request, 20) : '';
        $body = $responseBody . $echo;
        writeAll($conn, pack('n', strlen($body)) . $body);
    }
    fclose($conn);
}
