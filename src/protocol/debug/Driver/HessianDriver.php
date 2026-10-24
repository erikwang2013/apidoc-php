<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;
use erikwang2013\apidoc\protocol\debug\Support\HttpClient;

/**
 * Hessian 2.0 驱动:序列化调用参数并发起 HTTP POST,解码应答值
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class HessianDriver implements DebugDriverInterface
{
    public function name(): string
    {
        return 'hessian';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'method', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Remote method name'],
            ['name' => 'args', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'JSON array of call arguments'],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'Raw Hessian body as hex (fallback)'],
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
        if (!in_array($target['scheme'], ['http', 'https'], true)) {
            return ['ok' => false, 'error' => 'hessian 驱动仅支持 http/https 目标'];
        }
        $payload = $input['payload'] ?? '';
        if (!is_string($payload)) {
            return ['ok' => false, 'error' => 'payload 必须是 hex 字符串'];
        }
        if (trim($payload) !== '') {
            try {
                $body = Hex::decode($payload, 'hex');
            } catch (InvalidArgumentException $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        } else {
            $method = $input['method'] ?? '';
            if (!is_string($method) || trim($method) === '') {
                return ['ok' => false, 'error' => 'hessian method 必填'];
            }
            $args = $input['args'] ?? [];
            if (is_string($args)) {
                $args = trim($args) === '' ? [] : json_decode($args, true);
            }
            if (!is_array($args)) {
                return ['ok' => false, 'error' => 'args 必须是 JSON 数组'];
            }
            try {
                $body = $this->encodeCall(trim($method), array_values($args));
            } catch (InvalidArgumentException $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($body) > $maxBytes) {
            return ['ok' => false, 'error' => 'request exceeds max_bytes'];
        }
        $result = HttpClient::request([
            'url' => (string)$context['target'],
            'method' => 'POST',
            'headers' => ['Content-Type: x-application/hessian', 'Accept: x-application/hessian'],
            'body' => $body,
            'timeout' => (float)($context['timeout'] ?? 5),
            'maxBytes' => $maxBytes,
        ]);
        if (isset($result['error'])) {
            return ['ok' => false, 'error' => $result['error']];
        }
        $status = (int)($result['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'hessian 请求失败: HTTP ' . $status, 'meta' => ['status' => $status]];
        }
        $response = (string)($result['body'] ?? '');
        try {
            $decoded = $this->decodeResponse($response);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => 'hessian 响应解码失败: ' . $e->getMessage()];
        }
        if ($decoded['fault'] !== null) {
            $fault = $decoded['fault'];
            $code = is_scalar($fault['code'] ?? null) ? (string)$fault['code'] : '';
            $message = is_scalar($fault['message'] ?? null) ? (string)$fault['message'] : '';
            return [
                'ok' => false,
                'error' => 'hessian fault' . ($code !== '' ? ' ' . $code : '') . ($message !== '' ? ': ' . $message : ''),
                'meta' => ['fault' => $fault, 'status' => $status],
            ];
        }
        $value = $decoded['value'];
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return ['ok' => false, 'error' => 'hessian 响应无法编码为 JSON'];
        }
        return [
            'ok' => true,
            'body' => $json,
            'meta' => [
                'durationMs' => (int)round((microtime(true) - $start) * 1000),
                'bytes' => strlen($response),
                'status' => $status,
            ],
        ];
    }

    /**
     * Hessian 2.0 call: c x02 x00 m <len16> <method> args* z
     * @param string $method
     * @param array $args
     * @return string
     */
    public function encodeCall(string $method, array $args): string
    {
        $length = $this->charLength($method);
        if ($length > 65535) {
            throw new InvalidArgumentException('hessian 方法名超过 65535 字符');
        }
        $out = "c\x02\x00m" . pack('n', $length) . $method;
        foreach ($args as $arg) {
            $out .= $this->encodeValue($arg);
        }
        return $out . 'z';
    }

    /**
     * 应答:真实服务为 r x02 x00 (object|f ...) z;fixture 直接返回裸值
     * @param string $data
     * @return array ['value'=>mixed,'fault'=>array|null]
     * @throws InvalidArgumentException
     */
    protected function decodeResponse(string $data): array
    {
        if (strlen($data) >= 3 && $data[0] === 'r') {
            // shortcut: 未解析应答 header*,标准未定义编码,遇到再扩展
            $offset = 3;
            if ($offset < strlen($data) && ord($data[$offset]) === 0x66) {
                $offset++;
                $fault = [];
                while (true) {
                    if ($offset >= strlen($data)) {
                        throw new InvalidArgumentException('hessian fault 缺少结束标记');
                    }
                    if (ord($data[$offset]) === 0x7a) {
                        break;
                    }
                    $key = $this->readValue($data, $offset);
                    if (!is_scalar($key) && $key !== null) {
                        throw new InvalidArgumentException('hessian fault 键类型非法');
                    }
                    $fault[(string)$key] = $this->readValue($data, $offset);
                }
                return ['value' => null, 'fault' => $fault];
            }
            return ['value' => $this->readValue($data, $offset), 'fault' => null];
        }
        return ['value' => $this->decode($data), 'fault' => null];
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function encodeValue($value): string
    {
        if ($value === null) {
            return $this->encodeNull();
        }
        if (is_bool($value)) {
            return $this->encodeBool($value);
        }
        if (is_int($value)) {
            return $value >= -2147483648 && $value <= 2147483647
                ? $this->encodeInt($value)
                : $this->encodeLong($value);
        }
        if (is_float($value)) {
            return $this->encodeDouble($value);
        }
        if (is_string($value)) {
            return $this->encodeString($value);
        }
        if (is_array($value)) {
            $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
            return $isList ? $this->encodeList($value) : $this->encodeMap($value);
        }
        return $this->encodeString((string)$value);
    }

    public function encodeString(string $value): string
    {
        // shortcut: 字符串长度按 UTF-8 字符数;超过 65535 不切 R chunk
        $length = $this->charLength($value);
        if ($length <= 31) {
            return chr($length) . $value;
        }
        if ($length <= 1023) {
            return chr(0x30 + ($length >> 8)) . chr($length & 0xff) . $value;
        }
        if ($length <= 65535) {
            return 'S' . pack('n', $length) . $value;
        }
        throw new InvalidArgumentException('hessian 字符串超过 65535 字符');
    }

    public function encodeInt(int $value): string
    {
        if ($value >= -16 && $value <= 47) {
            return chr(0x90 + $value);
        }
        if ($value >= -2048 && $value <= 2047) {
            return chr(0xc8 + ($value >> 8)) . chr($value & 0xff);
        }
        if ($value >= -262144 && $value <= 262143) {
            return chr(0xd4 + ($value >> 16)) . chr(($value >> 8) & 0xff) . chr($value & 0xff);
        }
        if ($value < -2147483648 || $value > 2147483647) {
            throw new InvalidArgumentException('hessian int 超出 32 位范围');
        }
        return 'I' . pack('N', $value);
    }

    public function encodeLong(int $value): string
    {
        if ($value >= -8 && $value <= 15) {
            return chr(0xe0 + $value);
        }
        if ($value >= -2048 && $value <= 2047) {
            return chr(0xf8 + ($value >> 8)) . chr($value & 0xff);
        }
        if ($value >= -262144 && $value <= 262143) {
            return chr(0x3c + ($value >> 16)) . chr(($value >> 8) & 0xff) . chr($value & 0xff);
        }
        return 'L' . pack('J', $value);
    }

    public function encodeDouble(float $value): string
    {
        return 'D' . pack('E', $value);
    }

    public function encodeBool(bool $value): string
    {
        return $value ? 'T' : 'F';
    }

    public function encodeNull(): string
    {
        return 'N';
    }

    public function encodeBinary(string $value): string
    {
        if (strlen($value) > 65535) {
            throw new InvalidArgumentException('hessian binary 超过 65535 字节');
        }
        return 'B' . pack('n', strlen($value)) . $value;
    }

    /**
     * @param array $items
     * @return string 定长无类型列表 X
     */
    public function encodeList(array $items): string
    {
        $out = 'X' . $this->encodeInt(count($items));
        foreach ($items as $item) {
            $out .= $this->encodeValue($item);
        }
        return $out;
    }

    /**
     * @param array $map
     * @return string 无类型 map H
     */
    public function encodeMap(array $map): string
    {
        $out = 'H';
        foreach ($map as $key => $value) {
            $out .= $this->encodeValue(is_int($key) ? $key : (string)$key) . $this->encodeValue($value);
        }
        return $out . 'Z';
    }

    /**
     * @param string $data
     * @return mixed 首个 hessian 值
     * @throws InvalidArgumentException
     */
    public function decode(string $data)
    {
        $offset = 0;
        return $this->readValue($data, $offset);
    }

    /**
     * @param string $data
     * @param int $offset
     * @return mixed
     * @throws InvalidArgumentException
     */
    protected function readValue(string $data, int &$offset)
    {
        if ($offset >= strlen($data)) {
            throw new InvalidArgumentException('hessian 数据截断');
        }
        $code = ord($data[$offset]);
        if ($code === 0x4e) {
            $offset++;
            return null;
        }
        if ($code === 0x54) {
            $offset++;
            return true;
        }
        if ($code === 0x46) {
            $offset++;
            return false;
        }
        if ($code <= 0x1f || ($code >= 0x30 && $code <= 0x33) || $code === 0x53 || $code === 0x52) {
            return $this->readString($data, $offset);
        }
        if (($code >= 0x20 && $code <= 0x2f) || $code === 0x42 || $code === 0x62) {
            return $this->readBinary($data, $offset);
        }
        if (($code >= 0x5b && $code <= 0x5f) || $code === 0x44) {
            return $this->readDouble($data, $offset);
        }
        if (($code >= 0x80 && $code <= 0xd7) || $code === 0x49) {
            return $this->readInt($data, $offset);
        }
        if (($code >= 0xd8 && $code <= 0xff) || ($code >= 0x38 && $code <= 0x3f) || $code === 0x4c || $code === 0x59) {
            return $this->readLong($data, $offset);
        }
        if (($code >= 0x55 && $code <= 0x58) || ($code >= 0x70 && $code <= 0x7f)) {
            return $this->readList($data, $offset, $code);
        }
        if ($code === 0x48 || $code === 0x4d) {
            return $this->readMap($data, $offset, $code);
        }
        throw new InvalidArgumentException(sprintf('不支持的 hessian 值类型: 0x%02x', $code));
    }

    protected function readString(string $data, int &$offset): string
    {
        $value = '';
        while (true) {
            $code = $this->readByte($data, $offset);
            if ($code <= 0x1f) {
                $length = $code;
            } elseif ($code >= 0x30 && $code <= 0x33) {
                $length = (($code - 0x30) << 8) + $this->readByte($data, $offset);
            } elseif ($code === 0x53 || $code === 0x52) {
                $length = unpack('n', $this->readBytes($data, $offset, 2))[1];
            } else {
                throw new InvalidArgumentException('hessian 字符串编码非法');
            }
            $value .= $this->readChars($data, $offset, $length);
            if ($code !== 0x52) {
                return $value;
            }
        }
    }

    protected function readBinary(string $data, int &$offset): string
    {
        $value = '';
        while (true) {
            $code = $this->readByte($data, $offset);
            if ($code >= 0x20 && $code <= 0x2f) {
                return $value . $this->readBytes($data, $offset, $code - 0x20);
            }
            if ($code !== 0x42 && $code !== 0x62) {
                throw new InvalidArgumentException('hessian binary 编码非法');
            }
            $length = unpack('n', $this->readBytes($data, $offset, 2))[1];
            $value .= $this->readBytes($data, $offset, $length);
            if ($code === 0x42) {
                return $value;
            }
        }
    }

    protected function readInt(string $data, int &$offset): int
    {
        $code = $this->readByte($data, $offset);
        if ($code >= 0x80 && $code <= 0xbf) {
            return $code - 0x90;
        }
        if ($code >= 0xc0 && $code <= 0xcf) {
            return (($code - 0xc8) << 8) + $this->readByte($data, $offset);
        }
        if ($code >= 0xd0 && $code <= 0xd7) {
            $b1 = $this->readByte($data, $offset);
            $b2 = $this->readByte($data, $offset);
            return (($code - 0xd4) << 16) + ($b1 << 8) + $b2;
        }
        if ($code === 0x49) {
            $value = unpack('N', $this->readBytes($data, $offset, 4))[1];
            return $value > 0x7fffffff ? $value - 0x100000000 : $value;
        }
        throw new InvalidArgumentException('hessian int 编码非法');
    }

    protected function readLong(string $data, int &$offset): int
    {
        $code = $this->readByte($data, $offset);
        if ($code >= 0xd8 && $code <= 0xef) {
            return $code - 0xe0;
        }
        if ($code >= 0xf0) {
            return (($code - 0xf8) << 8) + $this->readByte($data, $offset);
        }
        if ($code >= 0x38 && $code <= 0x3f) {
            $b1 = $this->readByte($data, $offset);
            $b2 = $this->readByte($data, $offset);
            return (($code - 0x3c) << 16) + ($b1 << 8) + $b2;
        }
        if ($code === 0x59) {
            $value = unpack('N', $this->readBytes($data, $offset, 4))[1];
            return $value > 0x7fffffff ? $value - 0x100000000 : $value;
        }
        if ($code === 0x4c) {
            return unpack('J', $this->readBytes($data, $offset, 8))[1];
        }
        throw new InvalidArgumentException('hessian long 编码非法');
    }

    protected function readDouble(string $data, int &$offset): float
    {
        $code = $this->readByte($data, $offset);
        if ($code === 0x5b) {
            return 0.0;
        }
        if ($code === 0x5c) {
            return 1.0;
        }
        if ($code === 0x5d) {
            return (float)$this->signed($this->readByte($data, $offset), 8);
        }
        if ($code === 0x5e) {
            return (float)$this->signed(unpack('n', $this->readBytes($data, $offset, 2))[1], 16);
        }
        if ($code === 0x5f) {
            return (float)unpack('G', $this->readBytes($data, $offset, 4))[1];
        }
        if ($code === 0x44) {
            return unpack('E', $this->readBytes($data, $offset, 8))[1];
        }
        throw new InvalidArgumentException('hessian double 编码非法');
    }

    protected function readList(string $data, int &$offset, int $code): array
    {
        $this->readByte($data, $offset); // 消费列表标记
        if ($code === 0x55 || $code === 0x56) {
            $this->readValue($data, $offset); // 类型串
        }
        if ($code === 0x55 || $code === 0x57) {
            $items = [];
            while (true) {
                if ($offset >= strlen($data)) {
                    throw new InvalidArgumentException('hessian list 缺少结束标记');
                }
                if (ord($data[$offset]) === 0x5a) {
                    $offset++;
                    return $items;
                }
                $items[] = $this->readValue($data, $offset);
            }
        }
        if ($code >= 0x70 && $code <= 0x77) {
            $this->readValue($data, $offset); // 类型引用
            $length = $code - 0x70;
        } elseif ($code >= 0x78 && $code <= 0x7f) {
            $length = $code - 0x78;
        } else {
            $length = $this->readValue($data, $offset);
            if (!is_int($length) || $length < 0) {
                throw new InvalidArgumentException('hessian list 长度非法');
            }
        }
        $items = [];
        for ($i = 0; $i < $length; $i++) {
            $items[] = $this->readValue($data, $offset);
        }
        return $items;
    }

    protected function readMap(string $data, int &$offset, int $code): array
    {
        $this->readByte($data, $offset); // 消费 map 标记
        if ($code === 0x4d) {
            $this->readValue($data, $offset); // 'M' 带类型
        }
        $map = [];
        while (true) {
            if ($offset >= strlen($data)) {
                throw new InvalidArgumentException('hessian map 缺少结束标记');
            }
            if (ord($data[$offset]) === 0x5a) {
                $offset++;
                return $map;
            }
            $key = $this->readValue($data, $offset);
            if (!is_scalar($key) && $key !== null) {
                throw new InvalidArgumentException('hessian map 键类型非法');
            }
            $map[(string)$key] = $this->readValue($data, $offset);
        }
    }

    protected function readChars(string $data, int &$offset, int $length): string
    {
        if (function_exists('mb_substr')) {
            $value = mb_substr(substr($data, $offset), 0, $length, 'UTF-8');
            if (mb_strlen($value, 'UTF-8') < $length) {
                throw new InvalidArgumentException('hessian 字符串截断');
            }
        } else {
            $value = $this->readBytes($data, $offset, $length);
            return $value;
        }
        $offset += strlen($value);
        return $value;
    }

    protected function charLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    protected function signed(int $value, int $bits): int
    {
        $limit = 1 << ($bits - 1);
        return $value >= $limit ? $value - ($limit << 1) : $value;
    }

    protected function readByte(string $data, int &$offset): int
    {
        if ($offset >= strlen($data)) {
            throw new InvalidArgumentException('hessian 数据截断');
        }
        return ord($data[$offset++]);
    }

    protected function readBytes(string $data, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($data)) {
            throw new InvalidArgumentException('hessian 数据截断');
        }
        $raw = substr($data, $offset, $length);
        $offset += $length;
        return $raw;
    }
}
