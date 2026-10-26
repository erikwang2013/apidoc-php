<?php
declare(strict_types=1);

/**
 * DNP3 / IEC104 / RTMP / OPC UA 串行 TCP mock
 * 用法: php industrial_tcp_mock.php <dnp3|dnp3-truncated|iec104|iec104-nodata|rtmp|opcua|opcua-auth-fail|opcua-bad-policy|opcua-null|silent> <port>
 */

$protocol = $argv[1] ?? '';
$port = (int)($argv[2] ?? 0);
if ($protocol === '' || $port <= 0) {
    fwrite(STDERR, "usage: industrial_tcp_mock.php <protocol> <port>\n");
    exit(2);
}

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if (!is_resource($server)) {
    fwrite(STDERR, "bind failed: $errstr ($errno)\n");
    exit(1);
}

function readExact($socket, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($socket, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            return '';
        }
        $data .= $chunk;
    }
    return $data;
}

function dnp3Crc(string $data): int
{
    $crc = 0;
    for ($i = 0, $length = strlen($data); $i < $length; $i++) {
        $crc ^= ord($data[$i]);
        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & 1) !== 0 ? ($crc >> 1) ^ 0xA6BC : $crc >> 1;
        }
    }
    return ($crc ^ 0xFFFF) & 0xFFFF;
}

function dnp3Frame(int $ctrl, int $dst, int $src, string $userData): string
{
    $header = "\x05\x64" . chr(strlen($userData) + 5) . chr($ctrl) . pack('v', $dst) . pack('v', $src);
    $out = $header . pack('v', dnp3Crc(substr($header, 2)));
    foreach (str_split($userData, 16) as $chunk) {
        $out .= $chunk . pack('v', dnp3Crc($chunk));
    }
    return $out;
}

function amf(string $type, $value): string
{
    if ($type === 'number') {
        return "\x00" . pack('E', (float)$value);
    }
    if ($type === 'string') {
        return "\x02" . pack('n', strlen($value)) . $value;
    }
    if ($type === 'null') {
        return "\x05";
    }
    if ($type === 'object') {
        $out = "\x03";
        ksort($value, SORT_STRING);
        foreach ($value as $name => $field) {
            $out .= pack('n', strlen($name)) . $name . amf($field[0], $field[1]);
        }
        return $out . "\x00\x00\x09";
    }
    return "\x05";
}

function amfDecodeAll(string $bytes): array
{
    $pos = 0;
    $values = [];
    $total = strlen($bytes);
    while ($pos < $total) {
        $values[] = amfDecode($bytes, $pos);
    }
    return $values;
}

function amfDecode(string $bytes, int &$pos): array
{
    if ($pos >= strlen($bytes)) {
        return ['type' => 'null'];
    }
    $marker = ord($bytes[$pos++]);
    if ($marker === 0x00) {
        $value = unpack('E', substr($bytes, $pos, 8))[1];
        $pos += 8;
        return ['type' => 'number', 'value' => $value];
    }
    if ($marker === 0x01) {
        return ['type' => 'boolean', 'value' => ord($bytes[$pos++]) !== 0];
    }
    if ($marker === 0x02) {
        $length = unpack('n', substr($bytes, $pos, 2))[1];
        $pos += 2;
        $value = substr($bytes, $pos, $length);
        $pos += $length;
        return ['type' => 'string', 'value' => $value];
    }
    if ($marker === 0x03) {
        $fields = [];
        while (true) {
            $length = unpack('n', substr($bytes, $pos, 2))[1];
            $pos += 2;
            if ($length === 0) {
                $pos++;
                return ['type' => 'object', 'value' => $fields];
            }
            $name = substr($bytes, $pos, $length);
            $pos += $length;
            $fields[$name] = amfDecode($bytes, $pos);
        }
    }
    if ($marker === 0x05 || $marker === 0x06) {
        return ['type' => 'null'];
    }
    return ['type' => 'null'];
}

function rtmpWrite($socket, int $csid, int $typeId, int $streamId, string $payload, int $chunkSize): void
{
    $out = chr($csid) . "\x00\x00\x00" . substr(pack('N', strlen($payload)), 1) . chr($typeId) . pack('V', $streamId);
    foreach (str_split($payload, $chunkSize) as $index => $chunk) {
        if ($index > 0) {
            $out .= chr(0xC0 | $csid);
        }
        $out .= $chunk;
    }
    fwrite($socket, $out);
}

function rtmpCommand($socket, array $values, int $chunkSize): void
{
    $payload = '';
    foreach ($values as $value) {
        $payload .= amf($value[0], $value[1]);
    }
    rtmpWrite($socket, 3, 20, 0, $payload, $chunkSize);
}

function rtmpReadMessage($socket, int &$chunkSize): ?array
{
    $states = [];
    while (true) {
        $first = readExact($socket, 1);
        if ($first === '') {
            return null;
        }
        $format = ord($first) >> 6;
        $csid = ord($first) & 0x3F;
        $state = $states[$csid] ?? null;
        unset($states[$csid]);
        if ($format === 0) {
            $header = readExact($socket, 11);
            if ($header === '') {
                return null;
            }
            $length = (ord($header[3]) << 16) | (ord($header[4]) << 8) | ord($header[5]);
            $typeId = ord($header[6]);
            $streamId = unpack('V', substr($header, 7, 4))[1];
            $state = ['length' => $length, 'typeId' => $typeId, 'streamId' => $streamId, 'payload' => ''];
        } elseif ($format === 1) {
            $header = readExact($socket, 7);
            $length = (ord($header[3]) << 16) | (ord($header[4]) << 8) | ord($header[5]);
            $state['length'] = $length;
            $state['typeId'] = ord($header[6]);
        } elseif ($format === 2) {
            readExact($socket, 3);
        }
        if ($state === null) {
            return null;
        }
        $want = min($state['length'] - strlen($state['payload']), $chunkSize);
        $chunk = readExact($socket, $want);
        if ($chunk === '') {
            return null;
        }
        $state['payload'] .= $chunk;
        if (strlen($state['payload']) === $state['length']) {
            if ($state['typeId'] === 1 && strlen($state['payload']) >= 4) {
                $chunkSize = unpack('N', substr($state['payload'], 0, 4))[1] & 0x7FFFFFFF;
                continue;
            }
            return [$state['typeId'], $state['streamId'], $state['payload']];
        }
        $states[$csid] = $state;
    }
}

function uaString(string $value): string
{
    return pack('V', strlen($value)) . $value;
}

function uaNodeId(int $id): string
{
    if ($id <= 0xFF) {
        return "\x00" . chr($id);
    }
    return "\x01\x00" . pack('v', $id);
}

function uaHeader(int $handle = 1): string
{
    return pack('P', 0) . pack('V', $handle) . pack('V', 0) . "\x00" . pack('V', 0xFFFFFFFF) . "\x00\x00\x00";
}

function uaMessage(string $kind, int $channel, int $token, int $seq, int $request, string $body): string
{
    return $kind . 'F' . pack('V', 24 + strlen($body)) . pack('V', $channel)
        . pack('V', $token) . pack('V', $seq) . pack('V', $request) . $body;
}

function uaOpn(int $channel, string $policy, int $seq, int $request, string $body): string
{
    return 'OPNF' . pack('V', 32 + strlen($policy) + strlen($body)) . pack('V', $channel)
        . uaString($policy) . pack('V', 0xFFFFFFFF) . pack('V', 0xFFFFFFFF)
        . pack('V', $seq) . pack('V', $request) . $body;
}

function uaRead($socket): ?array
{
    $header = readExact($socket, 8);
    if ($header === '') {
        return null;
    }
    $size = unpack('V', substr($header, 4, 4))[1];
    if ($size < 8 || $size > 1048576) {
        return null;
    }
    $rest = readExact($socket, $size - 8);
    return [substr($header, 0, 3), $rest];
}

function uaServiceId(string $body): int
{
    $encoding = ord($body[0] ?? "\x00") & 0x0F;
    if ($encoding === 0) {
        return ord($body[1] ?? "\x00");
    }
    if ($encoding === 1) {
        return ord($body[2] ?? "\x00") | (ord($body[3] ?? "\x00") << 8);
    }
    if ($encoding === 2) {
        return unpack('V', substr($body, 3, 4))[1];
    }
    return 0;
}

for ($connection = 0; $connection < 16; $connection++) {
    $client = @stream_socket_accept($server, 30);
    if (!is_resource($client)) {
        break;
    }
    stream_set_timeout($client, 5);
    if ($protocol === 'silent') {
        usleep(500000);
        fclose($client);
        continue;
    }
    if ($protocol === 'dnp3') {
        $header = readExact($client, 10);
        if ($header !== '' && substr($header, 0, 2) === "\x05\x64") {
            $dataLength = ord($header[2]) - 5;
            readExact($client, $dataLength + (int)ceil($dataLength / 16) * 2);
            $objects = "\x1E\x01\x00\x00\x01"
                . "\x01" . pack('V', 42)
                . "\x01" . pack('V', 0xFFFFFFF9);
            // 传输头 + 应用头(control/function/IIN) + 对象
            fwrite($client, dnp3Frame(0x44, 0, 1, "\xC0\xC0\x81\x00\x00" . $objects));
        }
    } elseif ($protocol === 'dnp3-truncated') {
        $header = readExact($client, 10);
        if ($header !== '' && substr($header, 0, 2) === "\x05\x64") {
            $dataLength = ord($header[2]) - 5;
            readExact($client, $dataLength + (int)ceil($dataLength / 16) * 2);
            // 合法帧头声明 1 字节用户数据,但只发 1 字节就断开
            $out = "\x05\x64\x06\x44" . pack('v', 0) . pack('v', 1);
            fwrite($client, $out . pack('v', dnp3Crc(substr($out, 2))) . "\xC0");
        }
    } elseif ($protocol === 'iec104') {
        $apdu = readExact($client, 6);
        if ($apdu !== '' && ord($apdu[0]) === 0x68) {
            readExact($client, ord($apdu[1]) - 4);
            if (ord($apdu[2]) === 0x07) {
                fwrite($client, "\x68\x04\x0B\x00\x00\x00");
                $apdu = readExact($client, 6);
                if ($apdu !== '' && ord($apdu[0]) === 0x68) {
                    readExact($client, ord($apdu[1]) - 4);
                }
            }
            $asdu = "\x01\x01\x14\x00" . pack('v', 1) . "\x01\x00\x00\x01";
            fwrite($client, "\x68" . chr(4 + strlen($asdu)) . "\x02\x00\x00\x00" . $asdu);
        }
    } elseif ($protocol === 'iec104-nodata') {
        // 确认 STARTDT,但总召唤后不发 ASDU
        $apdu = readExact($client, 6);
        if ($apdu !== '' && ord($apdu[0]) === 0x68) {
            readExact($client, ord($apdu[1]) - 4);
            if (ord($apdu[2]) === 0x07) {
                fwrite($client, "\x68\x04\x0B\x00\x00\x00");
                $apdu = readExact($client, 6);
                if ($apdu !== '' && ord($apdu[0]) === 0x68) {
                    readExact($client, ord($apdu[1]) - 4);
                }
            }
        }
        usleep(500000);
    } elseif ($protocol === 'rtmp') {
        readExact($client, 1537);
        fwrite($client, "\x03" . str_repeat("\x00", 1536) . str_repeat("\x00", 1536));
        readExact($client, 1536);
        $chunkSize = 128;
        while (true) {
            $message = rtmpReadMessage($client, $chunkSize);
            if ($message === null) {
                break;
            }
            if ($message[0] !== 20) {
                continue;
            }
            $values = amfDecodeAll($message[2]);
            $name = ($values[0]['type'] ?? '') === 'string' ? $values[0]['value'] : '';
            if ($name === 'connect') {
                rtmpCommand($client, [
                    ['string', '_result'],
                    ['number', 1.0],
                    ['object', ['fmsVer' => ['string', 'FMS/3,0,1,123'], 'capabilities' => ['number', 31.0]]],
                    ['object', ['level' => ['string', 'status'], 'code' => ['string', 'NetConnection.Connect.Success'], 'description' => ['string', 'Connection succeeded.']]],
                ], $chunkSize);
            } elseif ($name === 'createStream') {
                rtmpCommand($client, [
                    ['string', '_result'],
                    ['number', 2.0],
                    ['null', null],
                    ['number', 1.0],
                ], $chunkSize);
            } elseif ($name === 'publish' || $name === 'play') {
                $code = $name === 'publish' ? 'NetStream.Publish.Start' : 'NetStream.Play.Start';
                rtmpCommand($client, [
                    ['string', 'onStatus'],
                    ['number', 0.0],
                    ['null', null],
                    ['object', ['level' => ['string', 'status'], 'code' => ['string', $code], 'description' => ['string', 'Start.']]],
                ], $chunkSize);
            }
        }
    } elseif (strpos($protocol, 'opcua') === 0) {
        $hel = uaRead($client);
        if ($hel === null || $hel[0] !== 'HEL') {
            fclose($client);
            continue;
        }
        fwrite($client, 'ACKF' . pack('V', 8));
        $opn = uaRead($client);
        if ($opn === null || $opn[0] !== 'OPN') {
            fclose($client);
            continue;
        }
        $policy = 'http://opcfoundation.org/UA/SecurityPolicy#None';
        if ($protocol === 'opcua-bad-policy') {
            $policy = 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256';
        }
        $openBody = "\x00\x00" . uaHeader()
            . pack('V', 0) . pack('V', 1) . pack('V', 1) . pack('P', 0) . pack('V', 3600000);
        fwrite($client, uaOpn(1, $policy, 1, 1, $openBody));
        while (true) {
            $message = uaRead($client);
            if ($message === null || $message[0] !== 'MSG') {
                break;
            }
            $body = substr($message[1], 16);
            $service = uaServiceId($body);
            if ($service === 461) {
                $response = "\x00\x00" . uaHeader() . "\x01\x00\x0D\x00" . "\x01\x00\x07\x00";
                fwrite($client, uaMessage('MSG', 1, 1, 1, 1, $response));
            } elseif ($service === 467) {
                if ($protocol === 'opcua-auth-fail') {
                    $reason = 'BadIdentityTokenRejected: secret';
                    fwrite($client, 'ERRF' . pack('V', 8 + 4 + 4 + strlen($reason))
                        . pack('V', 0x80210000) . uaString($reason));
                    break;
                }
                fwrite($client, uaMessage('MSG', 1, 1, 1, 1, "\x00\x00" . uaHeader()));
            } elseif ($service === 631) {
                $value = $protocol === 'opcua-null' ? "\x01\x00" : "\x01\x06" . pack('V', 42);
                $response = "\x00\x00" . uaHeader() . pack('V', 1) . $value;
                fwrite($client, uaMessage('MSG', 1, 1, 1, 1, $response));
            } elseif ($service === 527) {
                $response = "\x00\x00" . uaHeader() . pack('V', 1) . pack('V', 0)
                    . pack('V', 0xFFFFFFFF) . pack('V', 1)
                    . "\x00\x00" . "\x01" . "\x00\x55"
                    . pack('v', 0) . uaString('Demo') . "\x02" . uaString('Demo')
                    . pack('V', 2) . "\x00\x00";
                fwrite($client, uaMessage('MSG', 1, 1, 1, 1, $response));
            } elseif ($service === 673) {
                $response = "\x00\x00" . uaHeader() . pack('V', 1) . pack('V', 0);
                fwrite($client, uaMessage('MSG', 1, 1, 1, 1, $response));
            } else {
                fwrite($client, 'ERRF' . pack('V', 8 + 4 + strlen('unknown service'))
                    . pack('V', 0x80000000) . uaString('unknown service'));
                break;
            }
        }
    }
    fclose($client);
}
fclose($server);
