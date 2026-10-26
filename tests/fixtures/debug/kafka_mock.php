<?php
/**
 * Kafka 协议测试 broker:Metadata v4 / ListOffsets v1 / Fetch v4 / SASL PLAIN。
 * topic=debug 返回 offset 0(hello)与 1(0x00FF);topic=large 返回 200 字节消息。
 * 凭据固定 debug/secret;错误响应会带出提交的密码,用于验证驱动脱敏。
 * 用法: php kafka_mock.php <host> <port>
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19021);
// metadata-ssrf 模式广播云元数据地址;metadata-host 模式广播 localhost,
// 用于验证驱动对 metadata broker 的二次 SSRF 校验使用 DebugProxy 注入的配置
$mode = $argv[3] ?? '';
$advertiseHost = $mode === 'metadata-ssrf' ? '169.254.169.254' : ($mode === 'metadata-host' ? 'localhost' : $host);
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
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

function readExact($conn, int $length): ?string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($conn, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $data .= $chunk;
    }
    return $data;
}

function u16(string $data, int &$pos): int
{
    $value = unpack('n', substr($data, $pos, 2))[1];
    $pos += 2;
    return $value;
}

function u32(string $data, int &$pos): int
{
    $value = unpack('N', substr($data, $pos, 4))[1];
    $pos += 4;
    return $value;
}

function u64(string $data, int &$pos): int
{
    $value = unpack('J', substr($data, $pos, 8))[1];
    $pos += 8;
    return $value;
}

function kstring(string $data, int &$pos): string
{
    $length = u16($data, $pos);
    $value = substr($data, $pos, $length);
    $pos += $length;
    return $value;
}

function varint(int $value): string
{
    $raw = ($value << 1) ^ ($value >> 63);
    $out = '';
    do {
        $byte = $raw & 0x7F;
        $raw >>= 7;
        if ($raw > 0) {
            $byte |= 0x80;
        }
        $out .= chr($byte);
    } while ($raw > 0);
    return $out;
}

/**
 * RecordBatch v2;base offset 与 value 列表
 */
function recordBatch(int $baseOffset, array $values): string
{
    $records = '';
    foreach ($values as $index => $value) {
        $body = chr(0) . varint(0) . varint($index) . varint(-1) . varint(strlen($value)) . $value . varint(0);
        $records .= varint(strlen($body)) . $body;
    }
    $afterLength = pack('N', 0) . chr(2) . pack('N', 0) . pack('n', 0)
        . pack('N', count($values) - 1) . pack('J', 0) . pack('J', 0)
        . pack('J', -1) . pack('n', 0xFFFF) . pack('N', 0xFFFFFFFF)
        . pack('N', count($values)) . $records;
    return pack('J', $baseOffset) . pack('N', strlen($afterLength)) . $afterLength;
}

function response($conn, int $correlation, string $body): void
{
    $payload = pack('N', $correlation) . $body;
    writeAll($conn, pack('N', strlen($payload)) . $payload);
}

function brokersBody(string $host, int $port): string
{
    return pack('N', 0) . pack('N', 1) . pack('N', 0)
        . pack('n', strlen($host)) . $host . pack('N', $port) . pack('n', 0xFFFF);
}

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 5);
    while (true) {
        $lengthBytes = readExact($conn, 4);
        if ($lengthBytes === null) {
            break;
        }
        $length = unpack('N', $lengthBytes)[1];
        if ($length < 10 || $length > 1048576) {
            break;
        }
        $request = readExact($conn, $length);
        if ($request === null) {
            break;
        }
        $pos = 0;
        $apiKey = u16($request, $pos);
        $version = u16($request, $pos);
        $correlation = u32($request, $pos);
        kstring($request, $pos); // client id
        $body = substr($request, $pos);

        if ($apiKey === 17) {
            response($conn, $correlation, pack('n', 0) . pack('N', 1) . pack('n', 5) . 'PLAIN');
            continue;
        }
        if ($apiKey === 36) {
            $pos = 0;
            $authLength = u32($body, $pos);
            $auth = substr($body, $pos, $authLength);
            $parts = explode("\0", $auth);
            $password = $parts[2] ?? '';
            if (($parts[1] ?? '') === 'debug' && $password === 'secret') {
                response($conn, $correlation, pack('n', 0) . pack('n', 0xFFFF) . pack('N', 0));
            } else {
                $message = 'authentication failed for debug with password ' . $password;
                response($conn, $correlation, pack('n', 58) . pack('n', strlen($message)) . $message . pack('N', 0));
            }
            continue;
        }
        if ($apiKey === 3) {
            $pos = 0;
            $count = u32($body, $pos);
            $topic = $count > 0 ? kstring($body, $pos) : 'debug';
            $topicPart = pack('n', 0) . pack('n', strlen($topic)) . $topic . chr(0)
                . pack('N', 1) . pack('n', 0) . pack('N', 0) . pack('N', 0)
                . pack('N', 1) . pack('N', 0) . pack('N', 1) . pack('N', 0);
            response(
                $conn,
                $correlation,
                brokersBody($advertiseHost, $port) . pack('n', 0xFFFF) . pack('N', 0)
                . pack('N', 1) . $topicPart
            );
            continue;
        }
        if ($apiKey === 2) {
            $pos = 0;
            u32($body, $pos); // replica id
            $count = u32($body, $pos);
            $topic = $count > 0 ? kstring($body, $pos) : 'debug';
            u32($body, $pos); // partition count
            $partition = u32($body, $pos);
            response(
                $conn,
                $correlation,
                pack('N', 1) . pack('n', strlen($topic)) . $topic
                . pack('N', 1) . pack('N', $partition) . pack('n', 0)
                . pack('J', 0) . pack('J', 0)
            );
            continue;
        }
        if ($apiKey === 1) {
            $pos = 0;
            u32($body, $pos); // replica id
            u32($body, $pos); // max wait
            u32($body, $pos); // min bytes
            u32($body, $pos); // max bytes
            $pos++; // isolation level
            $count = u32($body, $pos);
            $topic = $count > 0 ? kstring($body, $pos) : 'debug';
            u32($body, $pos); // partition count
            u32($body, $pos); // partition
            $fetchOffset = u64($body, $pos);
            if ($topic === 'large') {
                $records = $fetchOffset === 0 ? recordBatch(0, [str_repeat('x', 200)]) : '';
            } elseif ($fetchOffset === 0) {
                $records = recordBatch(0, ['hello', "\x00\xFF"]);
            } elseif ($fetchOffset === 1) {
                $records = recordBatch(1, ["\x00\xFF"]);
            } else {
                $records = '';
            }
            response(
                $conn,
                $correlation,
                pack('N', 0) . pack('N', 1) . pack('n', strlen($topic)) . $topic
                . pack('N', 1) . pack('N', 0) . pack('n', 0)
                . pack('J', 2) . pack('J', 2) . pack('N', 0xFFFFFFFF)
                . pack('N', strlen($records)) . $records
            );
            continue;
        }
        // 未实现版本:返回长度 4 的错误体让驱动报协议错误
        response($conn, $correlation, '');
    }
    fclose($conn);
}
