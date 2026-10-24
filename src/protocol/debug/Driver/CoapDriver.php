<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;

/**
 * CoAP (RFC 7252) 驱动:UDP 单次请求/响应
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class CoapDriver implements DebugDriverInterface
{
    public function name(): string
    {
        return 'coap';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'method', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'GET', 'desc' => 'GET or POST'],
            ['name' => 'path', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => '/', 'desc' => 'Uri-Path, defaults to target path'],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false],
        ];
    }

    /**
     * 编码 CoAP 请求:version 1, CON, 无 token
     * @param int $messageId 16 位消息 ID
     * @param int $method 1=GET, 2=POST
     * @param string $path 目标 URI 或路径
     * @param string $payload 原始载荷
     * @return string
     */
    public function encodeRequest(int $messageId, int $method, string $path, string $payload = ''): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            $parsed = parse_url($path);
            $path = is_array($parsed) && isset($parsed['path']) ? (string)$parsed['path'] : '/';
        }
        $bytes = "\x40" . chr($method === 2 ? 0x02 : 0x01) . pack('n', $messageId & 0xFFFF);
        $previous = 0;
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            $bytes .= $this->encodeOption(11 - $previous, rawurldecode($segment));
            $previous = 11;
        }
        return $bytes . ($payload === '' ? '' : "\xFF" . $payload);
    }

    /**
     * 解析 CoAP 响应
     * @param string $bytes 数据报
     * @return array
     */
    public function decodeResponse(string $bytes): array
    {
        $total = strlen($bytes);
        if ($total < 4) {
            return ['ok' => false, 'error' => 'coap response too short'];
        }
        $first = ord($bytes[0]);
        $version = $first >> 6;
        if ($version !== 1) {
            return ['ok' => false, 'error' => 'unsupported coap version: ' . $version];
        }
        $type = ($first >> 4) & 0x03;
        $tokenLength = $first & 0x0F;
        if ($tokenLength > 8 || $total < 4 + $tokenLength) {
            return ['ok' => false, 'error' => 'invalid coap token length'];
        }
        $code = ord($bytes[1]);
        $messageId = (int)unpack('n', substr($bytes, 2, 2))[1];
        $token = substr($bytes, 4, $tokenLength);
        $offset = 4 + $tokenLength;
        $options = [];
        $optionNumber = 0;
        $payload = '';
        while ($offset < $total) {
            $byte = ord($bytes[$offset]);
            if ($byte === 0xFF) {
                $payload = substr($bytes, $offset + 1);
                break;
            }
            $offset++;
            $delta = $byte >> 4;
            $length = $byte & 0x0F;
            if ($delta === 13) {
                if ($offset >= $total) {
                    return ['ok' => false, 'error' => 'truncated coap option'];
                }
                $delta = 13 + ord($bytes[$offset++]);
            } elseif ($delta === 14) {
                if ($offset + 1 >= $total) {
                    return ['ok' => false, 'error' => 'truncated coap option'];
                }
                $delta = 269 + (int)unpack('n', substr($bytes, $offset, 2))[1];
                $offset += 2;
            } elseif ($delta === 15) {
                return ['ok' => false, 'error' => 'invalid coap option'];
            }
            if ($length === 13) {
                if ($offset >= $total) {
                    return ['ok' => false, 'error' => 'truncated coap option'];
                }
                $length = 13 + ord($bytes[$offset++]);
            } elseif ($length === 14) {
                if ($offset + 1 >= $total) {
                    return ['ok' => false, 'error' => 'truncated coap option'];
                }
                $length = 269 + (int)unpack('n', substr($bytes, $offset, 2))[1];
                $offset += 2;
            } elseif ($length === 15) {
                return ['ok' => false, 'error' => 'invalid coap option'];
            }
            $optionNumber += $delta;
            if ($offset + $length > $total) {
                return ['ok' => false, 'error' => 'truncated coap option'];
            }
            $options[] = ['number' => $optionNumber, 'value' => substr($bytes, $offset, $length)];
            $offset += $length;
        }
        return [
            'ok' => true,
            'version' => $version,
            'type' => $type,
            'typeText' => ['CON', 'NON', 'ACK', 'RST'][$type],
            'code' => $code,
            'codeText' => sprintf('%d.%02d', $code >> 5, $code & 0x1F),
            'messageId' => $messageId,
            'token' => bin2hex($token),
            'options' => $options,
            'payload' => $payload,
        ];
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($target['scheme'] !== 'udp') {
            return ['ok' => false, 'error' => 'coap 驱动仅支持 udp 目标'];
        }
        if (isset($input['path']) && !is_string($input['path'])) {
            return ['ok' => false, 'error' => 'path must be a string'];
        }
        $method = $input['method'] ?? 'GET';
        if (is_int($method)) {
            $method = $method === 2 ? 'POST' : 'GET';
        }
        $method = strtoupper(trim((string)$method));
        if (!in_array($method, ['GET', 'POST'], true)) {
            return ['ok' => false, 'error' => 'coap method 仅支持 GET 或 POST'];
        }
        $path = (string)($input['path'] ?? $target['path']);
        if ($path === '') {
            $path = '/';
        }
        $encoding = (string)($input['encoding'] ?? 'text');
        try {
            $payload = Hex::decode((string)($input['payload'] ?? ''), $encoding);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($payload) > $maxBytes) {
            return ['ok' => false, 'error' => 'request exceeds max_bytes'];
        }
        $timeout = (float)($context['timeout'] ?? 5);
        if ($timeout <= 0) {
            $timeout = 5.0;
        }
        $messageId = random_int(0, 0xFFFF);
        $request = $this->encodeRequest($messageId, $method === 'POST' ? 2 : 1, $path, $payload);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'udp://' . $target['host'] . ':' . $target['port'],
            $errno,
            $errstr,
            $timeout
        );
        if (!$socket) {
            $message = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'connect failed (' . $errno . ')';
            return ['ok' => false, 'error' => $message];
        }
        try {
            if (@fwrite($socket, $request) !== strlen($request)) {
                return ['ok' => false, 'error' => 'write failed'];
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $seconds = (int)$timeout;
            $micros = (int)(($timeout - $seconds) * 1000000);
            if (@stream_select($read, $write, $except, $seconds, $micros) !== 1) {
                return ['ok' => false, 'error' => 'read timed out', 'meta' => ['durationMs' => (int)round((microtime(true) - $start) * 1000), 'bytes' => 0]];
            }
            $response = @fread($socket, min(65535, $maxBytes));
        } finally {
            fclose($socket);
        }
        if ($response === false || $response === '') {
            return ['ok' => false, 'error' => 'read timed out', 'meta' => ['durationMs' => (int)round((microtime(true) - $start) * 1000), 'bytes' => 0]];
        }
        $decoded = $this->decodeResponse($response);
        if (($decoded['ok'] ?? false) !== true) {
            return $decoded;
        }
        $meta = [
            'durationMs' => (int)round((microtime(true) - $start) * 1000),
            'bytes' => strlen($response),
            'code' => $decoded['codeText'],
            'type' => $decoded['type'],
            'typeText' => $decoded['typeText'],
            'messageId' => $decoded['messageId'],
            'requestMessageId' => $messageId,
            'token' => $decoded['token'],
            'options' => $decoded['options'],
        ];
        if (($decoded['code'] >> 5) >= 4) {
            return ['ok' => false, 'error' => 'coap ' . $decoded['codeText'] . ' (' . $decoded['typeText'] . ')', 'meta' => $meta];
        }
        return [
            'ok' => true,
            'body' => Hex::encode($decoded['payload'], $encoding),
            'encoding' => $encoding,
            'meta' => $meta,
        ];
    }

    /**
     * Option:delta/length 低 4 位,13/14 走扩展字节
     */
    protected function encodeOption(int $delta, string $value): string
    {
        $length = strlen($value);
        if ($delta < 0 || $delta > 65804 || $length > 65804) {
            return '';
        }
        $deltaNibble = $delta < 13 ? $delta : ($delta < 269 ? 13 : 14);
        $lengthNibble = $length < 13 ? $length : ($length < 269 ? 13 : 14);
        $bytes = chr(($deltaNibble << 4) | $lengthNibble);
        if ($deltaNibble === 13) {
            $bytes .= chr($delta - 13);
        } elseif ($deltaNibble === 14) {
            $bytes .= pack('n', $delta - 269);
        }
        if ($lengthNibble === 13) {
            $bytes .= chr($length - 13);
        } elseif ($lengthNibble === 14) {
            $bytes .= pack('n', $length - 269);
        }
        return $bytes . $value;
    }
}
