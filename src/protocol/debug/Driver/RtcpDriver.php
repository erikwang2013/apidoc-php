<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;
use erikwang2013\apidoc\protocol\debug\Support\Input;

/**
 * RTCP (RFC 3550) 包级收发:构造/解析 SR、RR、SDES、BYE 头部
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class RtcpDriver implements DebugDriverInterface
{
    public function name(): string
    {
        return 'rtcp';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'packet_type', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'rr', 'desc' => 'sr, rr, sdes, bye or numeric'],
            ['name' => 'ssrc', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 0],
            ['name' => 'count', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1, 'desc' => 'Datagrams to send, max 1000'],
            ['name' => 'recv', 'in' => 'meta', 'type' => 'boolean', 'require' => false, 'default' => false],
            ['name' => 'duration_ms', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 500, 'desc' => 'Receive window'],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'SDES CNAME or BYE reason text'],
        ];
    }

    public function send(array $input, array $context): array
    {
        $started = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
            if (!$this->targetMatches($target)) {
                return ['ok' => false, 'error' => 'rtcp 驱动仅支持 rtcp/udp/tcp 目标'];
            }
            $packetType = $this->packetType(Input::string($input, 'packet_type', 'rr') ?? 'rr');
            $ssrc = Input::int($input, 'ssrc', 0, 0) & 0xFFFFFFFF;
            $payload = Hex::decode(
                (string)($input['payload'] ?? ''),
                (string)($input['encoding'] ?? 'text')
            );
            $outcome = RtpDriver::exchange($input, $context, $target, function () use (
                $packetType,
                $ssrc,
                $payload
            ): string {
                return $this->encodePacket($packetType, $ssrc, $payload);
            });
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (($outcome['ok'] ?? false) !== true) {
            return $outcome;
        }
        $packets = [];
        foreach ($outcome['packets'] as $bytes) {
            $packet = $this->decodePacket($bytes);
            if ($packet === null) {
                continue;
            }
            $packets[] = [
                'version' => $packet['version'],
                'padding' => $packet['padding'],
                'count' => $packet['count'],
                'packet_type' => $packet['packet_type'],
                'length' => $packet['length'],
                'ssrc' => $packet['ssrc'],
                'text' => $packet['text'],
            ];
        }
        $body = json_encode(
            ['packets' => $packets],
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
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
                'sent' => $outcome['sent'],
                // 与 Rust x-received 一致:统计收到的原始数据报,含解码失败的
                'received' => count($outcome['packets']),
            ],
        ];
    }

    protected function targetMatches(array $target): bool
    {
        if (in_array($target['scheme'], ['rtcp', 'udp'], true)) {
            return true;
        }
        return $target['scheme'] === 'tcp' && strpos((string)($target['original'] ?? ''), '://') === false;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function packetType(string $value): int
    {
        switch ($value) {
            case 'rr':
                return 201;
            case 'sr':
                return 200;
            case 'sdes':
                return 202;
            case 'bye':
                return 203;
        }
        if (preg_match('/^\d+$/', $value) === 1 && (int)$value <= 0xFF) {
            return (int)$value;
        }
        throw new InvalidArgumentException(
            'unknown rtcp packet_type `' . $value . '`; use sr/rr/sdes/bye'
        );
    }

    /**
     * SDES/BYE 的 count 固定为 1;SR/RR 载荷原样发送
     */
    public function encodePacket(int $packetType, int $ssrc, string $payload): string
    {
        $count = ($packetType === 202 || $packetType === 203) ? 1 : 0;
        if ($packetType === 202) {
            $text = substr($payload, 0, 255);
            $body = chr(1) . chr(strlen($text)) . $text;
        } elseif ($packetType === 203 && $payload !== '') {
            $text = substr($payload, 0, 255);
            $body = chr(strlen($text)) . $text;
        } else {
            $body = $payload;
        }
        $padded = (strlen($body) + 3) & ~3;
        $length = 1 + intdiv($padded, 4);
        return chr(0x80 | $count)
            . chr($packetType)
            . pack('n', $length)
            . pack('N', $ssrc & 0xFFFFFFFF)
            . str_pad($body, $padded, "\x00");
    }

    /**
     * @return array|null null 表示非法包
     */
    public function decodePacket(string $bytes): ?array
    {
        $total = strlen($bytes);
        if ($total < 4) {
            return null;
        }
        $length = (unpack('n', substr($bytes, 2, 2))[1] + 1) * 4;
        if ($length > $total) {
            return null;
        }
        $first = ord($bytes[0]);
        $count = $first & 0x1F;
        $packetType = ord($bytes[1]);
        $ssrc = $length >= 8 ? unpack('N', substr($bytes, 4, 4))[1] : null;
        $text = null;
        if ($packetType === 202 && $length >= 10) {
            $textLength = ord($bytes[9]);
            if (10 + $textLength <= $length) {
                $text = static::lossy(substr($bytes, 10, $textLength));
            }
        } elseif ($packetType === 203 && $ssrc !== null) {
            $at = 4 + $count * 4;
            $textLength = $at < $total ? ord($bytes[$at]) : 0;
            if ($at + 1 + $textLength <= $length) {
                $text = static::lossy(substr($bytes, $at + 1, $textLength));
            }
        }
        return [
            'version' => $first >> 6,
            'padding' => ($first & 0x20) !== 0,
            'count' => $count,
            'packet_type' => $packetType,
            'length' => $length,
            'ssrc' => $ssrc,
            'text' => $text,
        ];
    }

    protected static function lossy(string $bytes): string
    {
        if (preg_match('//u', $bytes) === 1) {
            return $bytes;
        }
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $bytes);
        return $converted === false ? '' : $converted;
    }
}
