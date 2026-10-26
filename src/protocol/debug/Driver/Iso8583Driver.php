<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\TargetGuard;

/**
 * ISO 8583 驱动:长度前缀 + MTI + bitmap + 字段十六进制,不做字段解码(P1)
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class Iso8583Driver implements DebugDriverInterface
{
    /**
     * @var string[]
     */
    protected static $prefixes = ['2ascii', '2binary', '4ascii', '4binary', 'none'];

    public function name(): string
    {
        return 'iso8583';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'lengthPrefix', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => '2binary', 'desc' => '2ascii|2binary|4ascii|4binary|none'],
            ['name' => 'mti', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => '0800'],
            ['name' => 'bitmap', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => str_repeat('0', 32), 'desc' => 'Bitmap hex, 16 bytes by default'],
            ['name' => 'fields', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'Field data hex appended after the bitmap'],
        ];
    }

    /**
     * 编码完整帧:长度前缀 + MTI + bitmap + fields
     * @param string $mti 4 位 MTI
     * @param string $bitmap bitmap 十六进制
     * @param int|string $lengthPrefix 2/4 或 2ascii|2binary|4ascii|4binary|none
     * @param string $fields 字段数据十六进制
     * @return string
     */
    public function encodeFrame(string $mti, string $bitmap, $lengthPrefix = '2binary', string $fields = ''): string
    {
        $prefix = is_int($lengthPrefix) ? $lengthPrefix . 'binary' : (string)$lengthPrefix;
        if (!in_array($prefix, self::$prefixes, true)) {
            $prefix = '2binary';
        }
        $body = $mti . $this->hexBytes($bitmap) . $this->hexBytes($fields);
        return $this->encodeLength(strlen($body), $prefix) . $body;
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($target['scheme'] !== 'tcp') {
            return ['ok' => false, 'error' => 'iso8583 驱动仅支持 tcp 目标'];
        }
        foreach (['lengthPrefix', 'mti', 'bitmap', 'fields'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                return ['ok' => false, 'error' => $key . ' must be a string'];
            }
        }
        $prefix = (string)($input['lengthPrefix'] ?? '2binary');
        if (!in_array($prefix, self::$prefixes, true)) {
            return ['ok' => false, 'error' => 'lengthPrefix 仅支持 2ascii/2binary/4ascii/4binary/none'];
        }
        $mti = (string)($input['mti'] ?? '0800');
        if (strlen($mti) !== 4 || !ctype_digit($mti)) {
            return ['ok' => false, 'error' => 'mti 必须是 4 位数字'];
        }
        $bitmap = $this->normalizeHex((string)($input['bitmap'] ?? str_repeat('0', 32)));
        if ($bitmap === null || !in_array(strlen($bitmap), [16, 32], true)) {
            return ['ok' => false, 'error' => 'bitmap 必须是 8 或 16 字节的十六进制'];
        }
        $fields = $this->normalizeHex((string)($input['fields'] ?? ''));
        if ($fields === null) {
            return ['ok' => false, 'error' => 'fields 必须是合法的十六进制'];
        }
        $frame = $this->encodeFrame($mti, $bitmap, $prefix, $fields);
        $bodyLength = strlen($frame) - $this->prefixLength($prefix);
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($frame) > $maxBytes) {
            return ['ok' => false, 'error' => 'request exceeds max_bytes'];
        }
        if (($prefix === '2binary' && $bodyLength > 65535)
            || ($prefix === '2ascii' && $bodyLength > 99)
            || ($prefix === '4ascii' && $bodyLength > 9999)) {
            return ['ok' => false, 'error' => 'frame 超出长度前缀容量'];
        }
        $timeout = (float)($context['timeout'] ?? 5);
        if ($timeout <= 0) {
            $timeout = 5.0;
        }
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . TargetGuard::connectHost($target, $context) . ':' . $target['port'],
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!$socket) {
            $message = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'connect failed (' . $errno . ')';
            return ['ok' => false, 'error' => $message];
        }
        stream_set_blocking($socket, true);
        $deadline = microtime(true) + $timeout;
        try {
            if (!$this->writeAll($socket, $frame)) {
                return ['ok' => false, 'error' => 'write failed'];
            }
            $response = $this->readResponse($socket, $prefix, $deadline, $maxBytes);
        } finally {
            fclose($socket);
        }
        if (($response['ok'] ?? false) !== true) {
            return $response;
        }
        $bytes = $response['bytes'];
        $payload = substr($bytes, $this->prefixLength($prefix));
        return [
            'ok' => true,
            'body' => bin2hex($bytes),
            'meta' => [
                'durationMs' => (int)round((microtime(true) - $start) * 1000),
                'bytes' => strlen($bytes),
                'length' => strlen($payload),
                'ascii' => (string)preg_replace('/[^\x20-\x7E]/', '.', substr($payload, 0, 1024)),
            ],
        ];
    }

    /**
     * @return array{ok: bool, bytes?: string, error?: string}
     */
    protected function readResponse($socket, string $prefix, float $deadline, int $maxBytes): array
    {
        $prefixLength = $this->prefixLength($prefix);
        if ($prefixLength > 0) {
            $prefixBytes = $this->readExact($socket, $prefixLength, $deadline);
            if ($prefixBytes === null) {
                return ['ok' => false, 'error' => 'read timed out'];
            }
            $length = $this->decodeLength($prefixBytes, $prefix);
            if ($length === null) {
                return ['ok' => false, 'error' => 'invalid iso8583 length prefix'];
            }
            if ($length > $maxBytes) {
                return ['ok' => false, 'error' => 'response exceeds max_bytes'];
            }
            $payload = $length === 0 ? '' : $this->readExact($socket, $length, $deadline);
            if ($payload === null) {
                return ['ok' => false, 'error' => 'read timed out'];
            }
            return ['ok' => true, 'bytes' => $prefixBytes . $payload];
        }
        $bytes = '';
        while (strlen($bytes) < $maxBytes) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                break;
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $seconds = (int)$left;
            $micros = (int)(($left - $seconds) * 1000000);
            if (@stream_select($read, $write, $except, $seconds, $micros) !== 1) {
                break;
            }
            $chunk = @fread($socket, min(8192, $maxBytes - strlen($bytes)));
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    break;
                }
                continue;
            }
            $bytes .= $chunk;
        }
        if ($bytes === '') {
            return ['ok' => false, 'error' => 'read timed out'];
        }
        return ['ok' => true, 'bytes' => $bytes];
    }

    protected function encodeLength(int $length, string $prefix): string
    {
        switch ($prefix) {
            case '2ascii':
                return sprintf('%02d', $length);
            case '4ascii':
                return sprintf('%04d', $length);
            case '4binary':
                return pack('N', $length);
            case 'none':
                return '';
            default:
                return pack('n', $length & 0xFFFF);
        }
    }

    protected function decodeLength(string $prefixBytes, string $prefix): ?int
    {
        switch ($prefix) {
            case '2ascii':
            case '4ascii':
                return ctype_digit($prefixBytes) ? (int)$prefixBytes : null;
            case '4binary':
                return (int)unpack('N', $prefixBytes)[1];
            default:
                return (int)unpack('n', $prefixBytes)[1];
        }
    }

    protected function prefixLength(string $prefix): int
    {
        switch ($prefix) {
            case '2ascii':
            case '2binary':
                return 2;
            case '4ascii':
            case '4binary':
                return 4;
            default:
                return 0;
        }
    }

    protected function hexBytes(string $hex): string
    {
        $normalized = $this->normalizeHex($hex);
        return $normalized === null ? '' : (string)hex2bin($normalized);
    }

    protected function normalizeHex(string $hex): ?string
    {
        $hex = (string)preg_replace('/\s+/', '', $hex);
        if ($hex === '') {
            return '';
        }
        if (strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
            return null;
        }
        return strtolower($hex);
    }

    protected function writeAll($socket, string $data): bool
    {
        $written = 0;
        $total = strlen($data);
        while ($written < $total) {
            $bytes = @fwrite($socket, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                return false;
            }
            $written += $bytes;
        }
        return true;
    }

    protected function readExact($socket, int $length, float $deadline): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return null;
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $seconds = (int)$left;
            $micros = (int)(($left - $seconds) * 1000000);
            if (@stream_select($read, $write, $except, $seconds, $micros) !== 1) {
                return null;
            }
            $chunk = @fread($socket, $length - strlen($buffer));
            if ($chunk === false) {
                return null;
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    return null;
                }
                continue;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
