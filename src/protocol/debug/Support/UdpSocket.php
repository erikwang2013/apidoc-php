<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Support;

use RuntimeException;

/**
 * UDP 调试驱动的共享套接字助手:绑定通配端口,发送到 guard 固定的 IP
 * @package erikwang2013\apidoc\protocol\debug\Support
 */
class UdpSocket
{
    /**
     * 绑定本机通配端口;地址族跟随目标固定 IP
     * @param array $target DebugTarget::parse() 结果
     * @param array $context 驱动上下文(含 guard)
     * @return resource
     * @throws RuntimeException
     */
    public static function bind(array $target, array $context)
    {
        $host = static::host($target, $context);
        $local = strpos($host, ':') !== false ? '[::]:0' : '0.0.0.0:0';
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_server('udp://' . $local, $errno, $errstr, STREAM_SERVER_BIND);
        if (!is_resource($socket)) {
            $message = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'bind failed (' . $errno . ')';
            throw new RuntimeException($message);
        }
        return $socket;
    }

    /**
     * 绑定并连接已校验目标,只收该对端的数据报
     * @param array $target DebugTarget::parse() 结果
     * @param array $context 驱动上下文(含 guard)
     * @return resource
     * @throws RuntimeException
     */
    public static function connect(array $target, array $context, float $timeout)
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'udp://' . static::address($target, $context),
            $errno,
            $errstr,
            max(0.05, $timeout)
        );
        if (!is_resource($socket)) {
            $message = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'connect failed (' . $errno . ')';
            throw new RuntimeException($message);
        }
        return $socket;
    }

    /**
     * 向已连接套接字发送一个数据报
     * @param resource $socket
     * @throws RuntimeException
     */
    public static function write($socket, string $data): void
    {
        if (@fwrite($socket, $data) !== strlen($data)) {
            throw new RuntimeException('send failed');
        }
    }

    /**
     * 发送到已校验目标;必须整包写出
     * @param resource $socket
     * @throws RuntimeException
     */
    public static function send($socket, string $data, array $target, array $context): void
    {
        $address = static::address($target, $context);
        if (@stream_socket_sendto($socket, $data, 0, $address) !== strlen($data)) {
            throw new RuntimeException('send failed');
        }
    }

    /**
     * 接收一个数据报;null 表示截止时间前无数据
     * @param resource $socket
     * @return array{data:string,peer:string}|null
     * @throws RuntimeException
     */
    public static function receive($socket, float $deadline): ?array
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            return null;
        }
        $read = [$socket];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, (int)$left, (int)(($left - (int)$left) * 1000000));
        if ($ready === false) {
            throw new RuntimeException('recv failed');
        }
        if ($ready === 0) {
            return null;
        }
        $peer = '';
        $data = @stream_socket_recvfrom($socket, 65535, 0, $peer);
        if ($data === false) {
            throw new RuntimeException('recv failed');
        }
        return ['data' => $data, 'peer' => (string)$peer];
    }

    /**
     * 广播发送需要 SO_BROADCAST;扩展缺失时明确失败
     * @param resource $socket
     */
    public static function enableBroadcast($socket): bool
    {
        if (!function_exists('socket_import_stream') || !defined('SOL_SOCKET') || !defined('SO_BROADCAST')) {
            return false;
        }
        $raw = @socket_import_stream($socket);
        return $raw !== false && @socket_set_option($raw, SOL_SOCKET, SO_BROADCAST, 1) === true;
    }

    /**
     * guard 固定的第一个 IP;无 guard 时退回目标字面地址
     */
    public static function host(array $target, array $context): string
    {
        $ips = TargetGuard::ips($context);
        if ($ips !== []) {
            return $ips[0];
        }
        return trim((string)($target['host'] ?? ''), '[]');
    }

    /**
     * sendto 地址(IPv6 需要方括号)
     */
    public static function address(array $target, array $context): string
    {
        $host = static::host($target, $context);
        if (strpos($host, ':') !== false) {
            $host = '[' . trim($host, '[]') . ']';
        }
        return $host . ':' . (int)($target['port'] ?? 0);
    }
}
