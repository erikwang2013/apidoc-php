<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;

/**
 * SIP (RFC 3261) 驱动:UDP/TCP 单次请求/响应,不做事务重传
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class SipDriver implements DebugDriverInterface
{
    /**
     * @var string[]
     */
    protected static $methods = ['OPTIONS', 'REGISTER', 'MESSAGE'];

    /**
     * @var string[] 由驱动生成的头,额外头不能覆盖;Content-Type 允许调用方提供
     */
    protected static $reserved = ['via', 'max-forwards', 'from', 'to', 'call-id', 'cseq', 'content-length'];

    public function name(): string
    {
        return 'sip';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'method', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'OPTIONS', 'desc' => 'OPTIONS/REGISTER/MESSAGE'],
            ['name' => 'uri', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'sip: request URI, defaults to sip:<target host>'],
            ['name' => 'from', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'sip:apidoc@localhost'],
            ['name' => 'to', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Defaults to the request URI'],
            ['name' => 'callId', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Generated when empty'],
            ['name' => 'cseq', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1],
            ['name' => 'headers', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Extra headers as a JSON object; Content-Type defaults to application/sdp for MESSAGE bodies'],
            ['name' => 'body', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'SDP or text body'],
        ];
    }

    /**
     * @param array $headers 头名 => 值
     * @param string $transport UDP|TCP,与目标传输一致
     */
    public function encodeRequest(
        string $method,
        string $uri,
        string $from,
        string $to,
        int $cseq,
        string $callId = '',
        array $headers = [],
        string $body = '',
        string $transport = 'UDP'
    ): string {
        if ($callId === '') {
            $callId = bin2hex(random_bytes(8)) . '@apidoc-debug';
        }
        $lines = [
            $method . ' ' . $uri . ' SIP/2.0',
            'Via: SIP/2.0/' . $transport . ' apidoc-debug;branch=z9hG4bK' . bin2hex(random_bytes(8)) . ';rport',
            'Max-Forwards: 70',
            'From: ' . $this->nameAddr($from, 'tag=' . bin2hex(random_bytes(4))),
            'To: ' . $this->nameAddr($to),
            'Call-ID: ' . $callId,
            'CSeq: ' . $cseq . ' ' . $method,
        ];
        $hasContentType = false;
        foreach ($headers as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'Content-Type') === 0) {
                $hasContentType = true;
            }
        }
        if ($body !== '' && !$hasContentType) {
            $lines[] = 'Content-Type: ' . ($method === 'MESSAGE' ? 'application/sdp' : 'text/plain');
        }
        foreach ($headers as $name => $value) {
            $name = (string)$name;
            if (!is_scalar($value)
                || in_array(strtolower($name), static::$reserved, true)
                || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1
                || preg_match('/[\r\n\0]/', $name . (string)$value) === 1) {
                continue;
            }
            $lines[] = $name . ': ' . (string)$value;
        }
        $lines[] = 'Content-Length: ' . strlen($body);
        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!in_array($target['scheme'], ['udp', 'tcp'], true)) {
            return ['ok' => false, 'error' => 'sip 驱动仅支持 udp/tcp 目标'];
        }
        $method = strtoupper(trim((string)($input['method'] ?? 'OPTIONS')));
        if (!in_array($method, static::$methods, true)) {
            return ['ok' => false, 'error' => 'sip method 仅支持 ' . implode('/', static::$methods)];
        }
        foreach (['uri', 'from', 'to', 'callId', 'body'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                return ['ok' => false, 'error' => $key . ' must be a string'];
            }
        }
        $uri = isset($input['uri']) && $input['uri'] !== '' ? $input['uri'] : 'sip:' . $target['host'];
        $from = isset($input['from']) && $input['from'] !== '' ? $input['from'] : 'sip:apidoc@localhost';
        $to = isset($input['to']) && $input['to'] !== '' ? $input['to'] : $uri;
        $callId = (string)($input['callId'] ?? '');
        if ($callId === '') {
            $callId = bin2hex(random_bytes(8)) . '@apidoc-debug';
        }
        foreach (['uri' => $uri, 'from' => $from, 'to' => $to, 'callId' => $callId] as $key => $value) {
            if (preg_match('/[\r\n\s]/', $value) === 1) {
                return ['ok' => false, 'error' => $key . ' 不能包含空白或换行'];
            }
        }
        $cseq = $this->intField($input, 'cseq', 1);
        if ($cseq === null || $cseq < 1 || $cseq > 0x7FFFFFFF) {
            return ['ok' => false, 'error' => 'cseq 必须是正整数'];
        }
        $headers = $this->parseHeaders($input['headers'] ?? []);
        if ($headers === null) {
            return ['ok' => false, 'error' => 'headers 必须是 JSON 对象'];
        }
        $body = (string)($input['body'] ?? '');
        $transport = $target['scheme'] === 'tcp' ? 'TCP' : 'UDP';
        $request = $this->encodeRequest($method, $uri, $from, $to, $cseq, $callId, $headers, $body, $transport);
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
            $target['scheme'] . '://' . $target['host'] . ':' . $target['port'],
            $errno,
            $errstr,
            $timeout
        );
        if (!$socket) {
            $message = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'connect failed (' . $errno . ')';
            return ['ok' => false, 'error' => $message];
        }
        $deadline = microtime(true) + $timeout;
        $meta = [
            'durationMs' => 0,
            'bytes' => 0,
            'transport' => $transport,
            'callId' => $callId,
            'cseq' => $cseq,
        ];
        try {
            if (!$this->writeAll($socket, $request)) {
                return ['ok' => false, 'error' => 'write failed'];
            }
            if ($target['scheme'] === 'udp') {
                $response = $this->readDatagram($socket, $deadline, $maxBytes);
            } else {
                $read = $this->readResponse($socket, $deadline, $maxBytes);
                $response = ($read['ok'] ?? false) === true ? $read['bytes'] : null;
                if ($response === null && isset($read['error'])) {
                    return $read;
                }
            }
        } finally {
            fclose($socket);
        }
        if ($response === null || $response === '') {
            return ['ok' => false, 'error' => 'read timed out'];
        }
        $status = 0;
        $reason = '';
        if (preg_match('#^SIP/\d\.\d\s+(\d{3})\s*(.*)#', $response, $matches) === 1) {
            $status = (int)$matches[1];
            $reason = trim($matches[2]);
        }
        $meta['durationMs'] = (int)round((microtime(true) - $start) * 1000);
        $meta['bytes'] = strlen($response);
        $meta['status'] = $status;
        $meta['reason'] = $reason;
        if ($status === 0) {
            return ['ok' => false, 'error' => 'invalid sip response', 'body' => $response, 'meta' => $meta];
        }
        if ($status >= 400) {
            return ['ok' => false, 'error' => 'sip ' . $status . ($reason !== '' ? ' ' . $reason : ''), 'body' => $response, 'meta' => $meta];
        }
        return ['ok' => true, 'body' => $response, 'meta' => $meta];
    }

    protected function nameAddr(string $value, string $suffix = ''): string
    {
        if (strpos($value, '<') !== 0) {
            if (preg_match('#^(sip|sips|tel):#i', $value) !== 1) {
                $value = 'sip:' . $value;
            }
            $value = '<' . $value . '>';
        }
        return $suffix === '' ? $value : $value . ';' . $suffix;
    }

    protected function readDatagram($socket, float $deadline, int $maxBytes): ?string
    {
        $read = [$socket];
        $write = null;
        $except = null;
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            return null;
        }
        $seconds = (int)$left;
        $micros = (int)(($left - $seconds) * 1000000);
        if (@stream_select($read, $write, $except, $seconds, $micros) !== 1) {
            return null;
        }
        $response = @fread($socket, min(65535, $maxBytes));
        return $response === false || $response === '' ? null : $response;
    }

    /**
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
                return ['ok' => false, 'error' => $buffer === '' ? 'read timed out' : 'invalid sip response'];
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
