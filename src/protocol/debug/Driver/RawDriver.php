<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;

/**
 * 原始套接字驱动(TCP/UDP/UDS)
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class RawDriver implements DebugDriverInterface
{
    public function name(): string
    {
        return 'raw';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'target', 'in' => 'target', 'type' => 'string', 'require' => true, 'desc' => 'tcp://host:port, udp://host:port, or unix:///path'],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'Text/hex/base64 payload'],
            ['name' => 'encoding', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'text'],
            ['name' => 'timeout', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 5],
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
        if (!in_array($target['scheme'], ['tcp', 'udp', 'unix'], true)) {
            return ['ok' => false, 'error' => 'raw 驱动不支持的目标协议: ' . $target['scheme']];
        }
        $encoding = (string)($input['encoding'] ?? 'text');
        try {
            $payload = Hex::decode((string)($input['payload'] ?? ''), $encoding);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $timeout = (float)($context['timeout'] ?? 5);
        if ($timeout <= 0) {
            $timeout = 5.0;
        }
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 65536));
        $address = $target['scheme'] === 'unix'
            ? 'unix://' . $target['path']
            : $target['scheme'] . '://' . $target['host'] . ':' . $target['port'];

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            $message = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'connect failed (' . $errno . ')';
            return ['ok' => false, 'error' => $message];
        }
        stream_set_blocking($socket, true);
        $written = 0;
        $total = strlen($payload);
        while ($written < $total) {
            $bytes = @fwrite($socket, substr($payload, $written));
            if ($bytes === false || $bytes === 0) {
                fclose($socket);
                return ['ok' => false, 'error' => 'write failed'];
            }
            $written += $bytes;
        }

        // 用 stream_select 等可读:PHP 8.5 下阻塞 fread 可能先返回空串
        $response = '';
        $timedOut = false;
        $deadline = $start + $timeout;
        while (strlen($response) < $maxBytes) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                $timedOut = true;
                break;
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int)$left, (int)(($left - floor($left)) * 1000000));
            if ($ready !== 1) {
                $timedOut = true;
                break;
            }
            $chunk = @fread($socket, min(8192, $maxBytes - strlen($response)));
            if ($chunk === false) {
                $timedOut = true;
                break;
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    break;
                }
                continue;
            }
            $response .= $chunk;
            if ($target['scheme'] === 'udp') {
                // UDP 单次请求只取一个数据报
                break;
            }
        }
        fclose($socket);

        $durationMs = (int)round((microtime(true) - $start) * 1000);
        if ($response === '' && $timedOut) {
            return ['ok' => false, 'error' => 'read timed out', 'meta' => ['durationMs' => $durationMs, 'bytes' => 0]];
        }
        return [
            'ok' => true,
            'body' => Hex::encode($response, $encoding),
            'encoding' => $encoding,
            'meta' => ['durationMs' => $durationMs, 'bytes' => strlen($response)],
        ];
    }
}
