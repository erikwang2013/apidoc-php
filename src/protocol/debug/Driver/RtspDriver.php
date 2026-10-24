<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;

/**
 * RTSP (RFC 2326) 驱动:TCP 单次请求/响应,不做会话续接(P1)
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class RtspDriver implements DebugDriverInterface
{
    /**
     * @var string[]
     */
    protected static $methods = ['OPTIONS', 'DESCRIBE', 'SETUP', 'PLAY', 'TEARDOWN'];

    public function name(): string
    {
        return 'rtsp';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'method', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'OPTIONS', 'desc' => 'OPTIONS/DESCRIBE/SETUP/PLAY/TEARDOWN'],
            ['name' => 'url', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'rtsp:// request URL, defaults to the target'],
            ['name' => 'cseq', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1],
            ['name' => 'headers', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Extra headers as a JSON object'],
        ];
    }

    /**
     * @param array $headers 头名 => 值
     */
    public function encodeRequest(string $method, string $url, int $cseq, array $headers = []): string
    {
        $lines = [$method . ' ' . $url . ' RTSP/1.0', 'CSeq: ' . $cseq, 'User-Agent: apidoc-debug'];
        foreach ($headers as $name => $value) {
            $name = (string)$name;
            if (!is_scalar($value)
                || in_array(strtolower($name), ['cseq', 'user-agent', 'content-length'], true)
                || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1
                || preg_match('/[\r\n\0]/', $name . (string)$value) === 1) {
                continue;
            }
            $lines[] = $name . ': ' . (string)$value;
        }
        return implode("\r\n", $lines) . "\r\n\r\n";
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
            return ['ok' => false, 'error' => 'rtsp 驱动仅支持 tcp 目标'];
        }
        $method = strtoupper(trim((string)($input['method'] ?? 'OPTIONS')));
        if (!in_array($method, static::$methods, true)) {
            return ['ok' => false, 'error' => 'rtsp method 仅支持 ' . implode('/', static::$methods)];
        }
        if (isset($input['url']) && !is_string($input['url'])) {
            return ['ok' => false, 'error' => 'url must be a string'];
        }
        $url = isset($input['url']) && $input['url'] !== ''
            ? $input['url']
            : 'rtsp://' . $target['host'] . ':' . $target['port'] . ($target['path'] !== '' ? $target['path'] : '/');
        if ($url !== '*' && preg_match('#^rtsp://[^\s]+$#i', $url) !== 1) {
            return ['ok' => false, 'error' => 'url 必须是 rtsp:// URL'];
        }
        $cseq = $this->intField($input, 'cseq', 1);
        if ($cseq === null || $cseq < 1) {
            return ['ok' => false, 'error' => 'cseq 必须是正整数'];
        }
        $headers = $this->parseHeaders($input['headers'] ?? []);
        if ($headers === null) {
            return ['ok' => false, 'error' => 'headers 必须是 JSON 对象'];
        }
        $request = $this->encodeRequest($method, $url, $cseq, $headers);
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($request) > $maxBytes) {
            return ['ok' => false, 'error' => 'request exceeds max_bytes'];
        }
        $timeout = (float)($context['timeout'] ?? 5);
        if ($timeout <= 0) {
            $timeout = 5.0;
        }
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $target['host'] . ':' . $target['port'],
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
            if (!$this->writeAll($socket, $request)) {
                return ['ok' => false, 'error' => 'write failed'];
            }
            $read = $this->readResponse($socket, $deadline, $maxBytes);
        } finally {
            fclose($socket);
        }
        if (($read['ok'] ?? false) !== true) {
            return $read;
        }
        $response = $read['bytes'];
        $status = 0;
        $reason = '';
        if (preg_match('#^RTSP/\d\.\d\s+(\d{3})\s*(.*)#', $response, $matches) === 1) {
            $status = (int)$matches[1];
            $reason = trim($matches[2]);
        }
        $meta = [
            'durationMs' => (int)round((microtime(true) - $start) * 1000),
            'bytes' => strlen($response),
            'status' => $status,
            'reason' => $reason,
        ];
        if ($status === 0) {
            return ['ok' => false, 'error' => 'invalid rtsp response', 'body' => $response, 'meta' => $meta];
        }
        if ($status >= 400) {
            return ['ok' => false, 'error' => 'rtsp ' . $status . ($reason !== '' ? ' ' . $reason : ''), 'body' => $response, 'meta' => $meta];
        }
        return ['ok' => true, 'body' => $response, 'meta' => $meta];
    }

    /**
     * 读到响应头结束,再按 Content-Length 补正文
     * @return array{ok: bool, bytes?: string, error?: string}
     */
    protected function readResponse($socket, float $deadline, int $maxBytes): array
    {
        $buffer = '';
        while (($split = strpos($buffer, "\r\n\r\n")) === false) {
            if (strlen($buffer) >= $maxBytes) {
                return ['ok' => false, 'error' => 'response exceeds max_bytes'];
            }
            $chunk = $this->readChunk($socket, $deadline, min(8192, $maxBytes - strlen($buffer)));
            if ($chunk === null) {
                return ['ok' => false, 'error' => $buffer === '' ? 'read timed out' : 'invalid rtsp response'];
            }
            $buffer .= $chunk;
        }
        $contentLength = 0;
        if (preg_match('/^Content-Length:\s*(\d+)/mi', substr($buffer, 0, $split), $matches) === 1) {
            $contentLength = (int)$matches[1];
        }
        $bodyLength = strlen($buffer) - $split - 4;
        if ($bodyLength < $contentLength) {
            if ($split + 4 + $contentLength > $maxBytes) {
                return ['ok' => false, 'error' => 'response exceeds max_bytes'];
            }
            $extra = $this->readExact($socket, $contentLength - $bodyLength, $deadline);
            if ($extra === null) {
                return ['ok' => false, 'error' => 'read timed out'];
            }
            $buffer .= $extra;
        }
        return ['ok' => true, 'bytes' => $buffer];
    }

    protected function readChunk($socket, float $deadline, int $length): ?string
    {
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
        $chunk = @fread($socket, $length);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        return $chunk;
    }

    protected function readExact($socket, int $length, float $deadline): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = $this->readChunk($socket, $deadline, $length - strlen($buffer));
            if ($chunk === null) {
                return null;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    /**
     * @return array<string, string>|null
     */
    protected function parseHeaders($raw): ?array
    {
        if (is_string($raw)) {
            if (trim($raw) === '') {
                return [];
            }
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return null;
        }
        $headers = [];
        foreach ($raw as $name => $value) {
            if (!is_string($name)
                || !is_scalar($value)
                || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1
                || preg_match('/[\r\n\0]/', $name . (string)$value) === 1) {
                return null;
            }
            $headers[$name] = (string)$value;
        }
        return $headers;
    }

    protected function intField(array $input, string $key, int $default): ?int
    {
        $raw = $input[$key] ?? $default;
        if (!is_numeric($raw) || floor((float)$raw) !== (float)$raw) {
            return null;
        }
        return (int)$raw;
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
}
