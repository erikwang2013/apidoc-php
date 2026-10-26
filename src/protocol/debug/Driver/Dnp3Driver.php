<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use RuntimeException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Input;
use erikwang2013\apidoc\protocol\debug\Support\Socket;

/**
 * DNP3 (IEEE 1815) 只读最小集:CRC 链路帧 + Class 0..3 / 范围读
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class Dnp3Driver implements DebugDriverInterface
{
    protected const MAX_FRAMES = 1000;
    protected const MAX_LINK_DATA = 250;

    public function name(): string
    {
        return 'dnp3';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'operation', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'integrity', 'desc' => 'integrity/class0..3 or read'],
            ['name' => 'link_address', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1],
            ['name' => 'source_address', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 0],
            ['name' => 'group', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 30],
            ['name' => 'variation', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1],
            ['name' => 'start', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 0],
            ['name' => 'stop', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 0],
            ['name' => 'duration_ms', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 3000],
        ];
    }

    public function send(array $input, array $context): array
    {
        $started = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
            if (!in_array($target['scheme'], ['dnp3', 'tcp'], true)) {
                return ['ok' => false, 'error' => 'dnp3 驱动仅支持 dnp3/tcp 目标'];
            }
            $linkAddress = Input::int($input, 'link_address', 1, 0, 0xFFFF);
            $masterAddress = Input::int($input, 'source_address', 0, 0, 0xFFFF);
            if ($linkAddress === $masterAddress) {
                return ['ok' => false, 'error' => 'dnp3 link_address and source_address must differ'];
            }
            $timeout = max(0.05, (float)($context['timeout'] ?? 5));
            // 显式 0 按 1ms 处理(对齐 Rust clamp),只有负数/非法值才回退默认
            $duration = min(
                max(1, Input::int($input, 'duration_ms', 3000, 0, PHP_INT_MAX)),
                max(1, (int)round($timeout * 1000))
            );
            $operation = Input::string($input, 'operation', 'integrity');
            $payload = $this->readRequest($operation, $input);
            $app = "\xC0\x01" . $payload;
            $request = $this->encodeLinkFrame(0xC4, $linkAddress, $masterAddress, "\xC0" . $app);
            $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
            $socket = Socket::connect($target, $context, $timeout);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => 'dnp3 connect failed: ' . $e->getMessage()];
        }
        try {
            if (!Socket::writeAll($socket, $request)) {
                return ['ok' => false, 'error' => 'dnp3 write failed'];
            }
            $deadline = microtime(true) + $duration / 1000;
            [$points, $responses] = $this->collectPoints($socket, $deadline, $linkAddress, $masterAddress, $maxBytes);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            fclose($socket);
        }
        if ($points === []) {
            return ['ok' => false, 'error' => 'dnp3 timed out after ' . $duration . 'ms without a data response'];
        }
        $body = json_encode(['points' => $points], JSON_UNESCAPED_SLASHES);
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
                'frames' => $responses,
            ],
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function readRequest(string $operation, array $input): string
    {
        switch ($operation) {
            case 'integrity':
            case 'class0':
                return "\x3C\x01\x06";
            case 'class1':
                return "\x3C\x02\x06";
            case 'class2':
                return "\x3C\x03\x06";
            case 'class3':
                return "\x3C\x04\x06";
            case 'read':
                $group = Input::int($input, 'group', 30, 0, 0xFF);
                $variation = Input::int($input, 'variation', 1, 0, 0xFF);
                $start = Input::int($input, 'start', 0, 0, 0xFFFFFFFF);
                $stop = Input::int($input, 'stop', $start, 0, 0xFFFFFFFF);
                if ($start > $stop) {
                    throw new InvalidArgumentException('dnp3 start must be <= stop');
                }
                if ($stop > 0xFFFF) {
                    throw new InvalidArgumentException('dnp3 stop must be <= 65535');
                }
                $out = chr($group) . chr($variation);
                if ($stop <= 0xFF) {
                    return $out . "\x00" . chr($start) . chr($stop);
                }
                return $out . "\x01" . pack('v', $start) . pack('v', $stop);
            default:
                throw new InvalidArgumentException(
                    'unsupported dnp3 operation `' . $operation . '`; use integrity, class0, class1, class2, class3 or read'
                );
        }
    }

    /**
     * @return array{0:array,1:int} [points, completed read responses]
     */
    protected function collectPoints($socket, float $deadline, int $linkAddress, int $masterAddress, int $maxBytes): array
    {
        $points = [];
        $responses = 0;
        $app = '';
        $totalBytes = 0;
        for ($frames = 0; $frames < self::MAX_FRAMES; $frames++) {
            $frame = $this->readLinkFrame($socket, $deadline);
            if ($frame === null) {
                break;
            }
            $totalBytes += strlen($frame['data']) + 10;
            if ($totalBytes > $maxBytes) {
                throw new RuntimeException('dnp3 response exceeds max_bytes (' . $maxBytes . ')');
            }
            if (($frame['ctrl'] & 0xC0) !== 0x40 || $frame['dst'] !== $masterAddress || $frame['src'] !== $linkAddress) {
                continue;
            }
            if ($frame['data'] === '') {
                continue;
            }
            $transport = ord($frame['data'][0]);
            if (($transport & 0x80) !== 0) {
                $app = '';
            }
            $app .= substr($frame['data'], 1);
            if (($transport & 0x40) === 0) {
                continue;
            }
            // 应用头: control + function + 2 字节 IIN,对象从 IIN 之后开始
            if (strlen($app) < 4) {
                $app = '';
                continue;
            }
            if (ord($app[1]) !== 0x81) {
                $app = '';
                continue;
            }
            foreach ($this->parseObjects(substr($app, 4)) as $point) {
                $points[] = $point;
            }
            $app = '';
            $responses++;
        }
        return [$points, $responses];
    }

    /**
     * @return array{ctrl:int,dst:int,src:int,data:string}|null
     * @throws RuntimeException
     */
    protected function readLinkFrame($socket, float $deadline): ?array
    {
        try {
            $header = Socket::readExactOrNull($socket, 10, $deadline, 'dnp3 link header');
        } catch (RuntimeException $e) {
            // 帧间 EOF 表示对端正常收尾;超时由 readExactOrNull 返回 null
            if (strpos($e->getMessage(), 'connection closed') !== false) {
                return null;
            }
            throw $e;
        }
        if ($header === null) {
            return null;
        }
        if (substr($header, 0, 2) !== "\x05\x64") {
            throw new RuntimeException('invalid dnp3 start bytes');
        }
        $length = ord($header[2]);
        if ($length < 5 || $length > self::MAX_LINK_DATA) {
            throw new RuntimeException('invalid dnp3 link length ' . $length);
        }
        if ($this->crc16(substr($header, 2, 6)) !== unpack('v', substr($header, 8, 2))[1]) {
            throw new RuntimeException('dnp3 header CRC mismatch');
        }
        $dataLength = $length - 5;
        $blocks = (int)ceil($dataLength / 16);
        try {
            $body = Socket::readExactOrNull($socket, $dataLength + $blocks * 2, $deadline, 'dnp3 link frame');
        } catch (RuntimeException $e) {
            // 已读到帧头后断连属于截断帧,不能当成正常结束
            if (strpos($e->getMessage(), 'connection closed') !== false) {
                throw new RuntimeException('truncated dnp3 frame');
            }
            throw $e;
        }
        if ($body === null) {
            throw new RuntimeException('truncated dnp3 frame');
        }
        $data = '';
        $offset = 0;
        for ($index = 0; $index < $blocks; $index++) {
            $blockLength = $index + 1 === $blocks ? $dataLength - $index * 16 : 16;
            $block = substr($body, $offset, $blockLength);
            $expected = unpack('v', substr($body, $offset + $blockLength, 2))[1];
            if ($this->crc16($block) !== $expected) {
                throw new RuntimeException('dnp3 data CRC mismatch in block ' . $index);
            }
            $data .= $block;
            $offset += $blockLength + 2;
        }
        return [
            'ctrl' => ord($header[3]),
            'dst' => unpack('v', substr($header, 4, 2))[1],
            'src' => unpack('v', substr($header, 6, 2))[1],
            'data' => $data,
        ];
    }

    /**
     * 链路头 + 每 16 字节用户数据块 + CRC
     * @throws InvalidArgumentException
     */
    public function encodeLinkFrame(int $ctrl, int $dst, int $src, string $userData): string
    {
        if (strlen($userData) + 5 > self::MAX_LINK_DATA) {
            throw new InvalidArgumentException('dnp3 user data exceeds 250 bytes');
        }
        $header = "\x05\x64" . chr(strlen($userData) + 5) . chr($ctrl) . pack('v', $dst) . pack('v', $src);
        $out = $header . pack('v', $this->crc16(substr($header, 2)));
        foreach (str_split($userData, 16) as $chunk) {
            $out .= $chunk . pack('v', $this->crc16($chunk));
        }
        return $out;
    }

    /**
     * CRC-16/DNP:poly 0x3D65(反射 0xA6BC),init 0,xorout 0xFFFF
     */
    public function crc16(string $data): int
    {
        $crc = 0;
        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $crc ^= ord($data[$i]);
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 1) !== 0 ? ($crc >> 1) ^ 0xA6BC : $crc >> 1;
            }
        }
        return ($crc ^ 0xFFFF) & 0xFFFF;
    }

    /**
     * @return array<int,array{group:int,variation:int,index:int,value:mixed,flags:int|null}>
     * @throws InvalidArgumentException
     */
    public function parseObjects(string $data): array
    {
        $points = [];
        $pos = 0;
        $total = strlen($data);
        while ($pos < $total) {
            if ($total - $pos < 3) {
                throw new InvalidArgumentException('truncated dnp3 object header');
            }
            $group = ord($data[$pos]);
            $variation = ord($data[$pos + 1]);
            $qualifier = ord($data[$pos + 2]);
            $pos += 3;
            if (($qualifier >> 4) === 6) {
                throw new InvalidArgumentException('dnp3 all-objects qualifier in a response is not supported');
            }
            [$start, $count, $pos] = $this->readRange($data, $pos, $qualifier);
            $prefix = $qualifier & 0x0F;
            if ($prefix > 3) {
                throw new InvalidArgumentException('dnp3 size-prefix qualifier ' . $prefix . ' is not supported');
            }
            if (($group === 1 && $variation === 1) || ($group === 3 && $variation === 1)) {
                if ($prefix !== 0) {
                    throw new InvalidArgumentException('dnp3 index-prefixed packed bit fields are not supported');
                }
                $bits = $group === 1 ? 1 : 2;
                $bytes = (int)ceil($count * $bits / 8);
                if ($pos + $bytes > $total) {
                    throw new InvalidArgumentException('truncated dnp3 packed bits');
                }
                for ($i = 0; $i < $count; $i++) {
                    $value = $bits === 1
                        ? ((ord($data[$pos + intdiv($i, 8)]) >> ($i % 8)) & 1)
                        : ((ord($data[$pos + intdiv($i, 4)]) >> (($i % 4) * 2)) & 0x03);
                    $points[] = [
                        'group' => $group,
                        'variation' => $variation,
                        'index' => $start + $i,
                        'value' => $value,
                        'flags' => null,
                    ];
                }
                $pos += $bytes;
                continue;
            }
            // 每个受支持的点至少消费 1 字节,count 超过剩余字节必然截断
            if ($count > $total - $pos) {
                throw new InvalidArgumentException('truncated dnp3 object value');
            }
            for ($offset = 0; $offset < $count; $offset++) {
                $index = $start + $offset;
                if ($prefix === 1) {
                    if ($pos + 1 > $total) {
                        throw new InvalidArgumentException('truncated dnp3 index');
                    }
                    $index = ord($data[$pos]);
                    $pos++;
                } elseif ($prefix === 2) {
                    $index = $this->readU16($data, $pos);
                } elseif ($prefix === 3) {
                    $index = $this->readU32($data, $pos);
                }
                [$value, $flags] = $this->readPoint($group, $variation, $data, $pos, $offset);
                $points[] = [
                    'group' => $group,
                    'variation' => $variation,
                    'index' => $index,
                    'value' => $value,
                    'flags' => $flags,
                ];
            }
        }
        return $points;
    }

    /**
     * @return array{0:int,1:int,2:int} [start,count,newPos]
     * @throws InvalidArgumentException
     */
    protected function readRange(string $data, int $pos, int $qualifier): array
    {
        $total = strlen($data);
        switch ($qualifier >> 4) {
            case 0:
                if ($pos + 2 > $total) {
                    throw new InvalidArgumentException('truncated dnp3 range');
                }
                $start = ord($data[$pos]);
                $stop = ord($data[$pos + 1]);
                if ($stop < $start) {
                    throw new InvalidArgumentException('dnp3 range stop < start');
                }
                return [$start, $stop - $start + 1, $pos + 2];
            case 1:
                if ($pos + 4 > $total) {
                    throw new InvalidArgumentException('truncated dnp3 range');
                }
                $start = unpack('v', substr($data, $pos, 2))[1];
                $stop = unpack('v', substr($data, $pos + 2, 2))[1];
                if ($stop < $start) {
                    throw new InvalidArgumentException('dnp3 range stop < start');
                }
                return [$start, $stop - $start + 1, $pos + 4];
            case 2:
                if ($pos + 8 > $total) {
                    throw new InvalidArgumentException('truncated dnp3 range');
                }
                $start = unpack('V', substr($data, $pos, 4))[1];
                $stop = unpack('V', substr($data, $pos + 4, 4))[1];
                if ($stop < $start) {
                    throw new InvalidArgumentException('dnp3 range stop < start');
                }
                return [$start, $stop - $start + 1, $pos + 8];
            case 7:
                if ($pos + 1 > $total) {
                    throw new InvalidArgumentException('truncated dnp3 range');
                }
                return [0, ord($data[$pos]), $pos + 1];
            case 8:
                if ($pos + 2 > $total) {
                    throw new InvalidArgumentException('truncated dnp3 range');
                }
                return [0, unpack('v', substr($data, $pos, 2))[1], $pos + 2];
            case 9:
                if ($pos + 4 > $total) {
                    throw new InvalidArgumentException('truncated dnp3 range');
                }
                return [0, unpack('V', substr($data, $pos, 4))[1], $pos + 4];
            default:
                throw new InvalidArgumentException('unsupported dnp3 range qualifier ' . ($qualifier >> 4));
        }
    }

    /**
     * @return array{0:mixed,1:int|null} [value,flags]
     * @throws InvalidArgumentException
     */
    protected function readPoint(int $group, int $variation, string $data, int &$pos, int $offset): array
    {
        $flags = null;
        switch (true) {
            case ($group === 1 || $group === 10) && $variation === 2:
                $flags = $this->readU8($data, $pos);
                return [($flags & 0x80) !== 0, $flags];
            case $group === 3 && $variation === 2:
                $flags = $this->readU8($data, $pos);
                return [($flags >> 6) & 0x03, $flags];
            case ($group === 20 || $group === 21) && $variation === 1:
                $flags = $this->readU8($data, $pos);
                return [$this->readU32($data, $pos), $flags];
            case ($group === 20 || $group === 21) && $variation === 2:
                $flags = $this->readU8($data, $pos);
                return [$this->readU16($data, $pos), $flags];
            case ($group === 20 || $group === 21) && $variation === 5:
                return [$this->readU32($data, $pos), null];
            case ($group === 20 || $group === 21) && $variation === 6:
                return [$this->readU16($data, $pos), null];
            case ($group === 30 || $group === 40) && $variation === 1:
                $flags = $this->readU8($data, $pos);
                return [$this->readI32($data, $pos), $flags];
            case ($group === 30 || $group === 40) && $variation === 2:
                $flags = $this->readU8($data, $pos);
                return [$this->readI16($data, $pos), $flags];
            case ($group === 30 || $group === 40) && $variation === 3:
                return [$this->readI32($data, $pos), null];
            case ($group === 30 || $group === 40) && $variation === 4:
                return [$this->readI16($data, $pos), null];
            case $group === 30 && $variation === 5:
                $flags = $this->readU8($data, $pos);
                return [$this->readFloat32($data, $pos), $flags];
            case $group === 30 && $variation === 6:
                $flags = $this->readU8($data, $pos);
                return [$this->readFloat64($data, $pos), $flags];
            case $group === 30 && $variation === 7:
                return [$this->readFloat32($data, $pos), null];
            case $group === 30 && $variation === 8:
                return [$this->readFloat64($data, $pos), null];
            case $group === 50 && $variation >= 1 && $variation <= 3:
                return [['encoding' => 'hex', 'data' => $this->readHex($data, $pos, 6)], null];
            case $group === 50 && $variation === 4:
                return [['encoding' => 'hex', 'data' => $this->readHex($data, $pos, 10)], null];
            default:
                throw new InvalidArgumentException(
                    'unsupported dnp3 object group ' . $group . ' variation ' . $variation . ' at point offset ' . $offset
                );
        }
    }

    protected function readU8(string $data, int &$pos): int
    {
        if ($pos + 1 > strlen($data)) {
            throw new InvalidArgumentException('truncated dnp3 object value');
        }
        return ord($data[$pos++]);
    }

    protected function readU16(string $data, int &$pos): int
    {
        if ($pos + 2 > strlen($data)) {
            throw new InvalidArgumentException('truncated dnp3 object value');
        }
        $value = unpack('v', substr($data, $pos, 2))[1];
        $pos += 2;
        return $value;
    }

    protected function readU32(string $data, int &$pos): int
    {
        if ($pos + 4 > strlen($data)) {
            throw new InvalidArgumentException('truncated dnp3 object value');
        }
        $value = unpack('V', substr($data, $pos, 4))[1];
        $pos += 4;
        return $value;
    }

    protected function readI16(string $data, int &$pos): int
    {
        $value = $this->readU16($data, $pos);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    protected function readI32(string $data, int &$pos): int
    {
        $value = $this->readU32($data, $pos);
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    protected function readFloat32(string $data, int &$pos): float|string
    {
        if ($pos + 4 > strlen($data)) {
            throw new InvalidArgumentException('truncated dnp3 object value');
        }
        $value = unpack('g', substr($data, $pos, 4))[1];
        $pos += 4;
        return $this->finiteFloat($value);
    }

    protected function readFloat64(string $data, int &$pos): float|string
    {
        if ($pos + 8 > strlen($data)) {
            throw new InvalidArgumentException('truncated dnp3 object value');
        }
        $value = unpack('e', substr($data, $pos, 8))[1];
        $pos += 8;
        return $this->finiteFloat($value);
    }

    protected function readHex(string $data, int &$pos, int $length): string
    {
        if ($pos + $length > strlen($data)) {
            throw new InvalidArgumentException('truncated dnp3 object value');
        }
        $value = bin2hex(substr($data, $pos, $length));
        $pos += $length;
        return $value;
    }

    protected function finiteFloat(float $value): float|string
    {
        if (is_finite($value)) {
            return $value;
        }
        if (is_nan($value)) {
            return 'NaN';
        }
        return $value > 0 ? 'inf' : '-inf';
    }
}
