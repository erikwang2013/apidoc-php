<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Support;

use RuntimeException;

/**
 * TCP 调试驱动的共享套接字助手:固定校验后的 IP + 截止时间读取
 * @package erikwang2013\apidoc\protocol\debug\Support
 */
class Socket
{
    /**
     * @param array $target DebugTarget::parse() 结果
     * @param array $context 驱动上下文(含 guard)
     * @param float $timeout 连接超时(秒)
     * @return resource
     * @throws RuntimeException
     */
    public static function connect(array $target, array $context, float $timeout)
    {
        $host = TargetGuard::connectHost($target, $context);
        $address = 'tcp://' . $host . ':' . (int)($target['port'] ?? 0);
        $errno = 0;
        $errstr = '';
        // 每个连接独立 context,避免 TLS 选项(cafile/verify)泄漏到后续请求
        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            max(0.05, $timeout),
            STREAM_CLIENT_CONNECT,
            stream_context_create()
        );
        if (!is_resource($socket)) {
            $message = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'connect failed (' . $errno . ')';
            throw new RuntimeException($message);
        }
        stream_set_blocking($socket, true);
        return $socket;
    }

    public static function writeAll($socket, string $data): bool
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

    /**
     * 精确读取 length 字节,超时/断连抛 RuntimeException
     * @throws RuntimeException
     */
    public static function readExact($socket, int $length, float $deadline, string $what): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                throw new RuntimeException($what . ' timed out');
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int)$left, (int)(($left - (int)$left) * 1000000));
            if ($ready === false) {
                throw new RuntimeException($what . ' read failed');
            }
            if ($ready === 0) {
                throw new RuntimeException($what . ' timed out');
            }
            $chunk = @fread($socket, min(8192, $length - strlen($data)));
            if ($chunk === false) {
                throw new RuntimeException($what . ' read failed');
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    throw new RuntimeException($what . ': connection closed');
                }
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }

    /**
     * 精确读取 length 字节:超时返回 null,断连抛异常(用于可超时的链路帧读取)
     * @throws RuntimeException
     */
    public static function readExactOrNull($socket, int $length, float $deadline, string $what): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return null;
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int)$left, (int)(($left - (int)$left) * 1000000));
            if ($ready === false) {
                throw new RuntimeException($what . ' read failed');
            }
            if ($ready === 0) {
                return null;
            }
            $chunk = @fread($socket, min(8192, $length - strlen($data)));
            if ($chunk === false) {
                throw new RuntimeException($what . ' read failed');
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    throw new RuntimeException($what . ': connection closed');
                }
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }

    /**
     * 读一块数据:null 表示超时,空串表示对端关闭
     */
    public static function readSome($socket, float $deadline, int $max = 8192): ?string
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            return null;
        }
        $read = [$socket];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, (int)$left, (int)(($left - (int)$left) * 1000000));
        if ($ready !== 1) {
            return null;
        }
        $chunk = @fread($socket, max(1, $max));
        if ($chunk === false) {
            return null;
        }
        if ($chunk === '') {
            return feof($socket) ? '' : null;
        }
        return $chunk;
    }
}
