<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\StreamingDriverInterface;
use erikwang2013\apidoc\protocol\debug\Support\Hex;

/**
 * STOMP 1.2 驱动:单次 SEND + 流式订阅(Task 9)
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class StompDriver implements StreamingDriverInterface
{
    public function name(): string
    {
        return 'stomp';
    }

    public function mode(): string
    {
        return 'relay-stream';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'destination', 'in' => 'meta', 'type' => 'string', 'require' => true, 'desc' => 'Queue or topic destination'],
            ['name' => 'command', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'SEND'],
            ['name' => 'contentType', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'text/plain'],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false],
        ];
    }

    public function start(array $input, array $context): array
    {
        return ['ok' => false, 'error' => 'streaming driver not implemented yet'];
    }

    public function poll(string $sessionId, array $context): array
    {
        return ['ok' => false, 'error' => 'streaming driver not implemented yet'];
    }

    public function stop(string $sessionId, array $context): void
    {
    }

    public function encodeFrame(string $command, array $headers = [], string $body = ''): string
    {
        $frame = strtoupper(trim($command)) . "\n";
        if ($body !== '' && !array_key_exists('content-length', $headers)) {
            $headers['content-length'] = strlen($body);
        }
        foreach ($headers as $name => $value) {
            $frame .= $name . ':' . $value . "\n";
        }
        return $frame . "\n" . $body . "\0";
    }

    /**
     * @param string $bytes 已收到的字节流
     * @return array<int, array{command:string, headers:array, body:string}> 只返回完整帧
     */
    public static function decodeFrames(string $bytes): array
    {
        $frames = [];
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            while ($offset < $length && ($bytes[$offset] === "\n" || $bytes[$offset] === "\r")) {
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }
            $headerEnd = strpos($bytes, "\n\n", $offset);
            if ($headerEnd === false) {
                break;
            }
            $lines = explode("\n", substr($bytes, $offset, $headerEnd - $offset));
            $command = trim((string)array_shift($lines));
            if ($command === '') {
                $offset = $headerEnd + 2;
                continue;
            }
            $headers = [];
            foreach ($lines as $line) {
                $line = rtrim($line, "\r");
                $colon = strpos($line, ':');
                if ($colon === false) {
                    continue;
                }
                $name = trim(substr($line, 0, $colon));
                $value = substr($line, $colon + 1);
                if ($value !== '' && $value[0] === ' ') {
                    $value = substr($value, 1);
                }
                if ($name !== '') {
                    $headers[$name] = $value;
                }
            }
            $bodyStart = $headerEnd + 2;
            if (isset($headers['content-length']) && ctype_digit((string)$headers['content-length'])) {
                $bodyLength = (int)$headers['content-length'];
                if ($bodyStart + $bodyLength > $length) {
                    break;
                }
                $body = substr($bytes, $bodyStart, $bodyLength);
                $offset = $bodyStart + $bodyLength;
                if ($offset < $length && $bytes[$offset] === "\0") {
                    $offset++;
                }
            } else {
                $nul = strpos($bytes, "\0", $bodyStart);
                if ($nul === false) {
                    break;
                }
                $body = substr($bytes, $bodyStart, $nul - $bodyStart);
                $offset = $nul + 1;
            }
            $frames[] = ['command' => $command, 'headers' => $headers, 'body' => $body];
        }
        return $frames;
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
            return ['ok' => false, 'error' => 'stomp 驱动仅支持 tcp 目标'];
        }
        $action = strtolower(trim((string)($input['action'] ?? 'send')));
        if ($action !== 'send') {
            return ['ok' => false, 'error' => 'stomp 驱动不支持的操作: ' . $action];
        }
        foreach (['destination', 'command', 'contentType', 'receipt'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                return ['ok' => false, 'error' => $key . ' must be a string'];
            }
        }
        $destination = trim((string)($input['destination'] ?? ''));
        if ($destination === '') {
            return ['ok' => false, 'error' => 'destination 不能为空'];
        }
        $command = strtoupper(trim((string)($input['command'] ?? 'SEND')));
        if (preg_match('/^[A-Z]+$/', $command) !== 1) {
            return ['ok' => false, 'error' => 'command 非法: ' . $command];
        }
        $contentType = (string)($input['contentType'] ?? 'text/plain');
        $receipt = trim((string)($input['receipt'] ?? ''));
        foreach (['destination' => $destination, 'content-type' => $contentType, 'receipt' => $receipt] as $name => $value) {
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                return ['ok' => false, 'error' => $name . ' 不能包含控制字符'];
            }
        }
        try {
            $payload = Hex::decode((string)($input['payload'] ?? ''), (string)($input['encoding'] ?? 'text'));
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
        try {
            $connect = $this->encodeFrame('CONNECT', ['accept-version' => '1.2', 'host' => $target['host']]);
            if (!$this->writeAll($socket, $connect)) {
                return ['ok' => false, 'error' => 'write failed'];
            }
            $frames = $this->readFrames($socket, $timeout, $maxBytes, function (array $frames): bool {
                return $this->findFrame($frames, 'CONNECTED') !== null || $this->findFrame($frames, 'ERROR') !== null;
            });
            $error = $this->findFrame($frames, 'ERROR');
            if ($error !== null) {
                return ['ok' => false, 'error' => $this->frameError($error)];
            }
            if ($this->findFrame($frames, 'CONNECTED') === null) {
                return ['ok' => false, 'error' => 'CONNECTED timed out'];
            }
            $headers = ['destination' => $destination, 'content-type' => $contentType];
            if ($receipt !== '') {
                $headers['receipt'] = $receipt;
            }
            if (!$this->writeAll($socket, $this->encodeFrame($command, $headers, $payload))) {
                return ['ok' => false, 'error' => 'write failed'];
            }
            $receiptId = '';
            if ($receipt !== '') {
                $frames = $this->readFrames($socket, $timeout, $maxBytes, function (array $frames): bool {
                    return $this->findFrame($frames, 'RECEIPT') !== null || $this->findFrame($frames, 'ERROR') !== null;
                });
                $error = $this->findFrame($frames, 'ERROR');
                if ($error !== null) {
                    return ['ok' => false, 'error' => $this->frameError($error)];
                }
                $frame = $this->findFrame($frames, 'RECEIPT');
                if ($frame === null) {
                    return ['ok' => false, 'error' => 'RECEIPT timed out'];
                }
                $receiptId = (string)($frame['headers']['receipt-id'] ?? '');
            }
            $this->writeAll($socket, $this->encodeFrame('DISCONNECT'));
            $meta = [
                'durationMs' => (int)round((microtime(true) - $start) * 1000),
                'bytes' => strlen($payload),
            ];
            if ($receiptId !== '') {
                $meta['receiptId'] = $receiptId;
            }
            return ['ok' => true, 'body' => $receipt !== '' ? 'RECEIPT' : 'SENT', 'meta' => $meta];
        } finally {
            fclose($socket);
        }
    }

    protected function frameError(array $frame): string
    {
        $message = (string)($frame['headers']['message'] ?? $frame['body']);
        return $message !== '' ? 'STOMP ERROR: ' . $message : 'STOMP ERROR';
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

    protected function readFrames($socket, float $timeout, int $maxBytes, callable $done): array
    {
        $deadline = microtime(true) + $timeout;
        $buffer = '';
        $frames = [];
        while (true) {
            $frames = static::decodeFrames($buffer);
            if ($done($frames)) {
                break;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                break;
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int)$left, (int)(($left - (int)$left) * 1000000));
            if ($ready !== 1) {
                break;
            }
            $chunk = @fread($socket, min(8192, max(1, $maxBytes - strlen($buffer))));
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    break;
                }
                continue;
            }
            $buffer .= $chunk;
            if (strlen($buffer) >= $maxBytes) {
                break;
            }
        }
        return $frames;
    }

    protected function findFrame(array $frames, string $command): ?array
    {
        foreach ($frames as $frame) {
            if ($frame['command'] === $command) {
                return $frame;
            }
        }
        return null;
    }
}
