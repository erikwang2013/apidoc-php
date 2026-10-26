<?php
declare(strict_types=1);

/**
 * BACnet / RTP / RTCP / LwM2M UDP mock
 * 用法: php industrial_udp_mock.php <bacnet|bacnet-silent|rtp|rtcp|echo-large|lwm2m|lwm2m-silent|lwm2m-error> <port>
 */

$protocol = $argv[1] ?? '';
$port = (int)($argv[2] ?? 0);
if ($protocol === '' || $port <= 0) {
    fwrite(STDERR, "usage: industrial_udp_mock.php <protocol> <port>\n");
    exit(2);
}

$server = @stream_socket_server('udp://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND);
if (!is_resource($server)) {
    fwrite(STDERR, "bind failed: $errstr ($errno)\n");
    exit(1);
}

function coapOption(int $delta, string $value): string
{
    $length = strlen($value);
    $nibble = function (int $number): array {
        if ($number < 13) {
            return [$number, ''];
        }
        if ($number < 269) {
            return [13, chr($number - 13)];
        }
        return [14, pack('n', $number - 269)];
    };
    [$deltaNibble, $deltaExtra] = $nibble($delta);
    [$lengthNibble, $lengthExtra] = $nibble($length);
    return chr(($deltaNibble << 4) | $lengthNibble) . $deltaExtra . $lengthExtra . $value;
}

function coapParse(string $data): ?array
{
    $total = strlen($data);
    if ($total < 4) {
        return null;
    }
    $first = ord($data[0]);
    $tokenLength = $first & 0x0F;
    if (4 + $tokenLength > $total) {
        return null;
    }
    $result = [
        'code' => ord($data[1]),
        'message_id' => unpack('n', substr($data, 2, 2))[1],
        'token' => substr($data, 4, $tokenLength),
        'path' => [],
        'payload' => '',
    ];
    $pos = 4 + $tokenLength;
    $number = 0;
    while ($pos < $total) {
        $byte = ord($data[$pos++]);
        if ($byte === 0xFF) {
            $result['payload'] = substr($data, $pos);
            break;
        }
        $delta = $byte >> 4;
        $length = $byte & 0x0F;
        if ($delta === 13) {
            $delta = 13 + ord($data[$pos++]);
        } elseif ($delta === 14) {
            $delta = 269 + unpack('n', substr($data, $pos, 2))[1];
            $pos += 2;
        }
        if ($length === 13) {
            $length = 13 + ord($data[$pos++]);
        } elseif ($length === 14) {
            $length = 269 + unpack('n', substr($data, $pos, 2))[1];
            $pos += 2;
        }
        $number += $delta;
        $value = substr($data, $pos, $length);
        $pos += $length;
        if ($number === 11) {
            $result['path'][] = $value;
        }
    }
    return $result;
}

function coapResponse(array $request, int $code, array $options, string $payload): string
{
    $tokenLength = strlen($request['token']);
    $out = chr(0x40 | $tokenLength) . chr($code)
        . pack('n', $request['message_id']) . $request['token'];
    $previous = 0;
    foreach ($options as $option) {
        $out .= coapOption($option[0] - $previous, $option[1]);
        $previous = $option[0];
    }
    if ($payload !== '') {
        $out .= "\xFF" . $payload;
    }
    return $out;
}

function bacnetBvlc(string $apdu): string
{
    $npdu = "\x01\x00" . $apdu;
    return "\x81\x0A" . pack('n', 4 + strlen($npdu)) . $npdu;
}

while (true) {
    $peer = '';
    $data = @stream_socket_recvfrom($server, 65535, 0, $peer);
    if ($data === false) {
        break;
    }
    if ($data === '') {
        continue;
    }
    if ($protocol === 'rtp' || $protocol === 'rtcp') {
        stream_socket_sendto($server, $data, 0, $peer);
        continue;
    }
    if ($protocol === 'echo-large') {
        stream_socket_sendto($server, str_repeat('x', 64), 0, $peer);
        continue;
    }
    if ($protocol === 'bacnet-silent') {
        continue;
    }
    if ($protocol === 'bacnet') {
        $apdu = substr($data, 6);
        if (substr($apdu, 0, 2) === "\x10\x08") {
            $objectId = (8 << 22) | 1234;
            stream_socket_sendto($server, bacnetBvlc("\x10\x00\xC4" . pack('N', $objectId)), 0, $peer);
            continue;
        }
        if (substr($apdu, 0, 5) === "\x00\x00\x00\x0C\xC4" && strlen($apdu) >= 11) {
            $objectId = unpack('N', substr($apdu, 5, 4))[1];
            $property = ord($apdu[10]);
            $ack = "\x30\x00\x0C\xC4" . pack('N', $objectId) . "\x19" . chr($property) . "\x3E"
                . "\x44" . pack('G', 21.5);
            stream_socket_sendto($server, bacnetBvlc($ack), 0, $peer);
        }
        continue;
    }
    if (strpos($protocol, 'lwm2m') === 0) {
        if ($protocol === 'lwm2m-silent') {
            continue;
        }
        $request = coapParse($data);
        if ($request === null) {
            continue;
        }
        if ($protocol === 'lwm2m-error') {
            stream_socket_sendto($server, coapResponse($request, 0x80, [], 'nope'), 0, $peer);
            continue;
        }
        switch ($request['code']) {
            case 1:
                $response = coapResponse($request, 0x45, [[12, "\x2D\x16"]], "\xC2\x09\x01\x2C");
                break;
            case 2:
                if (count($request['path']) === 1 && $request['path'][0] === 'rd') {
                    $response = coapResponse($request, 0x41, [[8, 'rd'], [8, 'abc']], '');
                } else {
                    $response = coapResponse($request, 0x44, [], '');
                }
                break;
            case 3:
                $response = coapResponse($request, 0x44, [], '');
                break;
            case 4:
                $response = coapResponse($request, 0x42, [], '');
                break;
            default:
                $response = coapResponse($request, 0x80, [], '');
                break;
        }
        stream_socket_sendto($server, $response, 0, $peer);
    }
}
fclose($server);
