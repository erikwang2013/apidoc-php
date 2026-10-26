<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use RuntimeException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;
use erikwang2013\apidoc\protocol\debug\Support\Input;
use erikwang2013\apidoc\protocol\debug\Support\UdpSocket;

/**
 * RTP (RFC 3550) 包级收发:只解析头部,不重建媒体流
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class RtpDriver implements DebugDriverInterface
{
    public const MAX_DATAGRAMS = 1000;
    public const MAX_DATAGRAM_BYTES = 65507;

    public function name(): string
    {
        return 'rtp';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'payload_type', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 96],
            ['name' => 'sequence', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 0],
            ['name' => 'timestamp', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 0],
            ['name' => 'ssrc', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 0],
            ['name' => 'count', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1, 'desc' => 'Datagrams to send, max 1000'],
            ['name' => 'recv', 'in' => 'meta', 'type' => 'boolean', 'require' => false, 'default' => false],
            ['name' => 'duration_ms', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 500, 'desc' => 'Receive window'],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false],
        ];
    }

    public function send(array $input, array $context): array
    {
        $started = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
            if (!$this->targetMatches($target)) {
                return ['ok' => false, 'error' => 'rtp 驱动仅支持 rtp/udp/tcp 目标'];
            }
            $payloadType = Input::int($input, 'payload_type', 96, 0);
            if ($payloadType > 127) {
                return ['ok' => false, 'error' => 'payload_type must be 0-127'];
            }
            $sequence = Input::int($input, 'sequence', 0, 0) & 0xFFFF;
            $timestamp = Input::int($input, 'timestamp', 0, 0) & 0xFFFFFFFF;
            $ssrc = Input::int($input, 'ssrc', 0, 0) & 0xFFFFFFFF;
            $payload = Hex::decode(
                (string)($input['payload'] ?? ''),
                (string)($input['encoding'] ?? 'text')
            );
            if (strlen($payload) + 12 > self::MAX_DATAGRAM_BYTES) {
                return ['ok' => false, 'error' => 'rtp payload exceeds ' . self::MAX_DATAGRAM_BYTES . ' bytes'];
            }
            $outcome = static::exchange($input, $context, $target, function (int $i) use (
                $payloadType,
                $sequence,
                $timestamp,
                $ssrc,
                $payload
            ): string {
                return $this->encodePacket(
                    $payloadType,
                    ($sequence + $i) & 0xFFFF,
                    ($timestamp + $i * 160) & 0xFFFFFFFF,
                    $ssrc,
                    $payload
                );
            });
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (($outcome['ok'] ?? false) !== true) {
            return $outcome;
        }
        $count = $outcome['sent'];
        $received = $outcome['packets'];
        $packets = [];
        foreach ($received as $bytes) {
            $packet = $this->decodePacket($bytes);
            if ($packet === null) {
                continue;
            }
            $packets[] = [
                'version' => $packet['version'],
                'padding' => $packet['padding'],
                'extension' => $packet['extension'],
                'marker' => $packet['marker'],
                'payload_type' => $packet['payload_type'],
                'sequence' => $packet['sequence'],
                'timestamp' => $packet['timestamp'],
                'ssrc' => $packet['ssrc'],
                'csrc' => $packet['csrc'],
                'payload' => Hex::payload($packet['payload']),
            ];
        }
        $body = json_encode(['packets' => $packets], JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false, 'error' => 'encode response failed'];
        }
        return [
            'ok' => true,
            'body' => $body,
            'encoding' => 'text',
            'meta' => [
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'bytes' => strlen($body),
                'sent' => $count,
                'received' => count($packets),
            ],
        ];
    }

    protected function targetMatches(array $target): bool
    {
        if (in_array($target['scheme'], ['rtp', 'udp'], true)) {
            return true;
        }
        return $target['scheme'] === 'tcp' && strpos((string)($target['original'] ?? ''), '://') === false;
    }

    /**
     * RTP/RTCP 共用的 UDP 收发:发送 count 个包,recv=true 时在窗口内最多收 count 个
     * @param callable(int):string $build 按序号构造数据报
     * @return array{ok:bool,sent?:int,packets?:array,error?:string}
     */
    public static function exchange(array $input, array $context, array $target, callable $build): array
    {
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        $timeout = max(0.05, (float)($context['timeout'] ?? 5));
        $count = max(1, min(self::MAX_DATAGRAMS, Input::int($input, 'count', 1, 0)));
        $duration = min(
            max(1, Input::int($input, 'duration_ms', 500, 0)),
            max(1, (int)round($timeout * 1000))
        );
        try {
            $socket = UdpSocket::connect($target, $context, $timeout);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => 'udp connect failed: ' . $e->getMessage()];
        }
        try {
            $sentBytes = 0;
            for ($i = 0; $i < $count; $i++) {
                $packet = $build($i);
                if (strlen($packet) > self::MAX_DATAGRAM_BYTES) {
                    return ['ok' => false, 'error' => 'udp packet exceeds ' . self::MAX_DATAGRAM_BYTES . ' bytes'];
                }
                $sentBytes += strlen($packet);
                if ($sentBytes > $maxBytes) {
                    return ['ok' => false, 'error' => 'udp send exceeds max_bytes (' . $maxBytes . ')'];
                }
                try {
                    UdpSocket::write($socket, $packet);
                } catch (RuntimeException $e) {
                    return ['ok' => false, 'error' => 'udp send failed: ' . $e->getMessage()];
                }
            }
            $received = [];
            if (($input['recv'] ?? null) === true || ($input['recv'] ?? null) === 'true') {
                $deadline = microtime(true) + $duration / 1000;
                $total = 0;
                for ($i = 0; $i < $count; $i++) {
                    try {
                        $datagram = UdpSocket::receive($socket, $deadline);
                    } catch (RuntimeException $e) {
                        return ['ok' => false, 'error' => 'udp recv failed: ' . $e->getMessage()];
                    }
                    if ($datagram === null) {
                        break;
                    }
                    $total += strlen($datagram['data']);
                    if ($total > $maxBytes) {
                        return ['ok' => false, 'error' => 'response exceeds max_bytes (' . $maxBytes . ')'];
                    }
                    $received[] = $datagram['data'];
                }
            }
        } finally {
            fclose($socket);
        }
        return ['ok' => true, 'sent' => $count, 'packets' => $received];
    }

    /**
     * 固定 12 字节头 + 载荷,无 CSRC/扩展/padding
     */
    public function encodePacket(int $payloadType, int $sequence, int $timestamp, int $ssrc, string $payload): string
    {
        return "\x80"
            . chr($payloadType & 0x7F)
            . pack('n', $sequence & 0xFFFF)
            . pack('N', $timestamp & 0xFFFFFFFF)
            . pack('N', $ssrc & 0xFFFFFFFF)
            . $payload;
    }

    /**
     * 解析 RTP 头;非法包返回 null(对齐 Rust filter_map)
     * @return array|null
     */
    public function decodePacket(string $bytes): ?array
    {
        try {
            $length = strlen($bytes);
            if ($length < 12) {
                throw new InvalidArgumentException('rtp packet too short: ' . $length . ' bytes');
            }
            $first = ord($bytes[0]);
            $cc = $first & 0x0F;
            $extension = ($first & 0x10) !== 0;
            $padding = ($first & 0x20) !== 0;
            $offset = 12 + $cc * 4;
            if ($offset > $length) {
                throw new InvalidArgumentException('rtp CSRC list exceeds packet length');
            }
            $csrc = [];
            for ($i = 0; $i < $cc; $i++) {
                $csrc[] = unpack('N', substr($bytes, 12 + $i * 4, 4))[1];
            }
            if ($extension) {
                if ($offset + 4 > $length) {
                    throw new InvalidArgumentException('rtp extension header exceeds packet length');
                }
                $words = unpack('n', substr($bytes, $offset + 2, 2))[1];
                $offset += 4 + $words * 4;
                if ($offset > $length) {
                    throw new InvalidArgumentException('rtp extension data exceeds packet length');
                }
            }
            $end = $length;
            if ($padding) {
                $pad = ord($bytes[$length - 1]);
                if ($pad === 0 || $pad > $end - $offset) {
                    throw new InvalidArgumentException('rtp padding is invalid');
                }
                $end -= $pad;
            }
            return [
                'version' => $first >> 6,
                'padding' => $padding,
                'extension' => $extension,
                'marker' => (ord($bytes[1]) & 0x80) !== 0,
                'payload_type' => ord($bytes[1]) & 0x7F,
                'sequence' => unpack('n', substr($bytes, 2, 2))[1],
                'timestamp' => unpack('N', substr($bytes, 4, 4))[1],
                'ssrc' => unpack('N', substr($bytes, 8, 4))[1],
                'csrc' => $csrc,
                'payload' => substr($bytes, $offset, $end - $offset),
            ];
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }
}
