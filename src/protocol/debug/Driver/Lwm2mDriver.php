<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use RuntimeException;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;
use erikwang2013\apidoc\protocol\debug\Support\Input;
use erikwang2013\apidoc\protocol\debug\Support\UdpSocket;

/**
 * LwM2M 1.1 最小客户端:Register/Update/Deregister + 单资源 TLV Read/Write
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class Lwm2mDriver extends CoapDriver
{
    protected const CONTENT_FORMAT_TLV = 11542;
    protected const MAX_MESSAGES = 1000;

    public function name(): string
    {
        return 'lwm2m';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'operation', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'register', 'desc' => 'register, update, deregister, read or write'],
            ['name' => 'endpoint', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Required for register'],
            ['name' => 'lifetime', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 300],
            ['name' => 'location', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Required for update/deregister'],
            ['name' => 'object_id', 'in' => 'meta', 'type' => 'number', 'require' => false, 'desc' => 'Required for read/write'],
            ['name' => 'instance_id', 'in' => 'meta', 'type' => 'number', 'require' => false, 'desc' => 'Required for read/write'],
            ['name' => 'resource_id', 'in' => 'meta', 'type' => 'number', 'require' => false, 'desc' => 'Required for read/write'],
            ['name' => 'value', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Required for write'],
            ['name' => 'duration_ms', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 3000],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false],
        ];
    }

    public function send(array $input, array $context): array
    {
        $started = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
            if (!$this->targetMatches($target)) {
                return ['ok' => false, 'error' => 'lwm2m 驱动仅支持 coap/udp/tcp 目标'];
            }
            $operation = Input::string($input, 'operation', 'register');
            $token = "\xA5\x5A";
            $encoding = (string)($input['encoding'] ?? 'text');
            $payload = Hex::decode((string)($input['payload'] ?? ''), $encoding);
            $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
            $timeout = max(0.05, (float)($context['timeout'] ?? 5));
            $duration = min(
                max(1, Input::int($input, 'duration_ms', 3000, 0)),
                max(1, (int)round($timeout * 1000))
            );
            switch ($operation) {
                case 'register':
                    if (strlen($payload) > $maxBytes) {
                        return [
                            'ok' => false,
                            'error' => 'register payload exceeds max_bytes (' . $maxBytes . ')',
                        ];
                    }
                    $endpoint = $this->requiredOption($input, 'endpoint');
                    $lifetimeRaw = Input::string($input, 'lifetime', '300');
                    $lifetime = $this->parseLifetime($lifetimeRaw);
                    $request = $this->buildRequest(
                        'POST',
                        '/rd',
                        [['ep', $endpoint], ['lt', $lifetime], ['lwm2m', '1.1'], ['b', 'U']],
                        $payload,
                        self::CONTENT_FORMAT_TLV,
                        1,
                        $token
                    );
                    $expect = '2.01';
                    break;
                case 'update':
                    $location = $this->requiredOption($input, 'location');
                    $request = $this->buildRequest(
                        'POST',
                        $location,
                        [],
                        $payload,
                        self::CONTENT_FORMAT_TLV,
                        2,
                        $token
                    );
                    $expect = '2.04';
                    break;
                case 'deregister':
                    $location = $this->requiredOption($input, 'location');
                    $request = $this->buildRequest('DELETE', $location, [], '', 0, 3, $token);
                    $expect = '2.02';
                    break;
                case 'read':
                    $request = $this->buildRequest(
                        'GET',
                        $this->resourcePath($input),
                        [],
                        '',
                        0,
                        4,
                        $token
                    );
                    $expect = '2.05';
                    break;
                case 'write':
                    $resourceId = $this->requiredResourceId($input);
                    $value = $this->requiredOption($input, 'value');
                    $encoded = $this->tlvEncodeResource($resourceId, $value);
                    $request = $this->buildRequest(
                        'PUT',
                        $this->resourcePath($input),
                        [],
                        $encoded,
                        self::CONTENT_FORMAT_TLV,
                        5,
                        $token
                    );
                    $expect = '2.04';
                    break;
                default:
                    throw new InvalidArgumentException(
                        'unsupported lwm2m operation `' . $operation . '`; use register, update, deregister, read or write'
                    );
            }
            $response = $this->roundtrip($target, $context, $request, $token, $duration, $maxBytes);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $code = $this->codeString($response['code']);
        if ($code !== $expect) {
            $suffix = $response['payload'] === '' ? '' : ': ' . static::lossy($response['payload']);
            return [
                'ok' => false,
                'error' => 'lwm2m ' . $operation . ' failed: expected ' . $expect . ', got ' . $code . $suffix,
            ];
        }
        switch ($operation) {
            case 'register':
                $location = '';
                foreach ($this->optionStrings($response, 8) as $segment) {
                    $location .= '/' . $segment;
                }
                $body = ['code' => $code, 'location' => $location];
                break;
            case 'read':
                $format = $this->optionUint($response, 12) ?? 0;
                $body = ['code' => $code, 'value' => $this->decodeValue($response['payload'], $format)];
                break;
            default:
                $body = ['code' => $code];
                break;
        }
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return ['ok' => false, 'error' => 'encode response failed'];
        }
        return [
            'ok' => true,
            'body' => $encoded,
            'encoding' => 'text',
            'meta' => [
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'bytes' => strlen($encoded),
            ],
        ];
    }

    protected function targetMatches(array $target): bool
    {
        if (in_array($target['scheme'], ['coap', 'lwm2m', 'udp'], true)) {
            return true;
        }
        return $target['scheme'] === 'tcp' && strpos((string)($target['original'] ?? ''), '://') === false;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function parseLifetime(?string $value): string
    {
        $value = (string)$value;
        if (preg_match('/^\d+$/', $value) !== 1 || (int)$value > 0xFFFFFFFF) {
            throw new InvalidArgumentException('invalid lifetime `' . $value . '`');
        }
        return (string)(int)$value;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function requiredOption(array $input, string $key): string
    {
        if (!isset($input[$key]) || !is_scalar($input[$key])) {
            throw new InvalidArgumentException('lwm2m ' . $key . ' is required');
        }
        return (string)$input[$key];
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function requiredResourceId(array $input): int
    {
        $value = $this->requiredOption($input, 'resource_id');
        if (preg_match('/^\d+$/', $value) !== 1 || (int)$value > 0xFFFF) {
            throw new InvalidArgumentException('invalid resource_id');
        }
        return (int)$value;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function resourcePath(array $input): string
    {
        $parts = [];
        foreach (['object_id', 'instance_id', 'resource_id'] as $key) {
            $parts[] = $this->requiredOption($input, $key);
        }
        return '/' . implode('/', $parts);
    }

    /**
     * CoAP 请求:CON + 固定 token;选项按编号升序(CoAP 要求)
     * @param array<array{0:string,1:string}> $query
     * @throws InvalidArgumentException
     */
    protected function buildRequest(
        string $method,
        string $path,
        array $query,
        string $payload,
        int $contentFormat,
        int $messageId,
        string $token
    ): string {
        $codes = ['GET' => 1, 'POST' => 2, 'PUT' => 3, 'DELETE' => 4];
        if (!isset($codes[$method])) {
            throw new InvalidArgumentException('unsupported coap method `' . $method . '`');
        }
        $options = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment !== '') {
                $options[] = [11, $segment];
            }
        }
        $options[] = [12, $this->encodeUint($contentFormat)];
        foreach ($query as [$key, $value]) {
            $options[] = [15, $key . '=' . $this->percentEncode($value)];
        }
        usort($options, function (array $left, array $right): int {
            return $left[0] <=> $right[0];
        });
        $bytes = "\x42" . chr($codes[$method]) . pack('n', $messageId & 0xFFFF) . $token;
        $previous = 0;
        foreach ($options as [$number, $value]) {
            $bytes .= $this->encodeOption($number - $previous, $value);
            $previous = $number;
        }
        return $bytes . ($payload === '' ? '' : "\xFF" . $payload);
    }

    protected function encodeUint(int $value): string
    {
        if ($value === 0) {
            return '';
        }
        $bytes = pack('N', $value & 0xFFFFFFFF);
        return ltrim($bytes, "\x00");
    }

    protected function percentEncode(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * 发送并收响应:按 token 匹配,跳过非 CoAP 数据报
     * @return array decodeResponse() 结果
     * @throws InvalidArgumentException
     */
    protected function roundtrip(
        array $target,
        array $context,
        string $request,
        string $token,
        int $duration,
        int $maxBytes
    ): array {
        if (strlen($request) > $maxBytes) {
            throw new InvalidArgumentException('coap request exceeds max_bytes (' . $maxBytes . ')');
        }
        try {
            $socket = UdpSocket::connect($target, $context, max(0.05, (float)($context['timeout'] ?? 5)));
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('coap connect failed: ' . $e->getMessage());
        }
        try {
            try {
                UdpSocket::write($socket, $request);
            } catch (RuntimeException $e) {
                throw new InvalidArgumentException('coap send failed: ' . $e->getMessage());
            }
            $deadline = microtime(true) + $duration / 1000;
            $total = 0;
            for ($i = 0; $i < self::MAX_MESSAGES; $i++) {
                try {
                    $datagram = UdpSocket::receive($socket, $deadline);
                } catch (RuntimeException $e) {
                    throw new InvalidArgumentException('coap recv failed: ' . $e->getMessage());
                }
                if ($datagram === null) {
                    break;
                }
                $total += strlen($datagram['data']);
                if ($total > $maxBytes) {
                    throw new InvalidArgumentException('coap response exceeds max_bytes (' . $maxBytes . ')');
                }
                $decoded = $this->decodeResponse($datagram['data']);
                if (($decoded['ok'] ?? false) !== true || $decoded['token'] !== bin2hex($token)) {
                    continue;
                }
                if ($decoded['type'] === 3) {
                    throw new InvalidArgumentException('coap peer rejected the request (RST)');
                }
                return $decoded;
            }
        } finally {
            fclose($socket);
        }
        throw new InvalidArgumentException('coap request timed out after ' . $duration . 'ms (no response)');
    }

    /**
     * @return string[]
     */
    protected function optionStrings(array $packet, int $number): array
    {
        $out = [];
        foreach ($packet['options'] as $option) {
            if ($option['number'] === $number) {
                $out[] = static::lossy($option['value']);
            }
        }
        return $out;
    }

    protected function optionUint(array $packet, int $number): ?int
    {
        foreach ($packet['options'] as $option) {
            if ($option['number'] === $number) {
                $value = 0;
                for ($i = 0, $len = strlen($option['value']); $i < $len; $i++) {
                    $value = ($value << 8) | ord($option['value'][$i]);
                }
                return $value;
            }
        }
        return null;
    }

    protected function codeString(int $code): string
    {
        $class = $code >> 5;
        if (in_array($class, [2, 4, 5], true)) {
            return $class . '.' . sprintf('%02d', $code & 0x1F);
        }
        return '0.' . sprintf('%02d', $code);
    }

    /**
     * 单资源 TLV:无类型信息,按长度猜整数,否则 UTF-8/base64
     */
    protected function decodeValue(string $payload, int $contentFormat): mixed
    {
        if ($contentFormat !== self::CONTENT_FORMAT_TLV) {
            return $this->textOrBase64($payload);
        }
        try {
            $first = $this->tlvDecodeFirst($payload);
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage(), 'encoding' => 'base64', 'data' => base64_encode($payload)];
        }
        if ($first === null) {
            return null;
        }
        $value = $first[1];
        $length = strlen($value);
        if ($length === 0) {
            return 0;
        }
        if (in_array($length, [1, 2, 4, 8], true)) {
            return $this->signedBe($value);
        }
        return $this->textOrBase64($value);
    }

    protected function textOrBase64(string $bytes): mixed
    {
        if (preg_match('//u', $bytes) === 1) {
            return $bytes;
        }
        return ['encoding' => 'base64', 'data' => base64_encode($bytes)];
    }

    protected function signedBe(string $bytes): int
    {
        $length = strlen($bytes);
        $value = 0;
        for ($i = 0; $i < min($length, 8); $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }
        if ($length < 8 && (ord($bytes[0]) & 0x80) !== 0) {
            $value -= 1 << (8 * $length);
        }
        return $value;
    }

    public function tlvEncodeResource(int $id, string $value): string
    {
        $parsed = $this->parseTlvValue($value);
        $bytes = $parsed['bytes'];
        $id16 = $id > 0xFF;
        $length = strlen($bytes);
        if ($length <= 7) {
            $lengthType = 0;
            $inline = $length;
        } elseif ($length <= 0xFF) {
            $lengthType = 1;
            $inline = 0;
        } elseif ($length <= 0xFFFF) {
            $lengthType = 2;
            $inline = 0;
        } else {
            $lengthType = 3;
            $inline = 0;
        }
        $out = chr(0xC0 | ($id16 ? 0x20 : 0) | ($lengthType << 3) | $inline);
        $out .= $id16 ? pack('n', $id) : chr($id);
        if ($lengthType === 1) {
            $out .= chr($length);
        } elseif ($lengthType === 2) {
            $out .= pack('n', $length);
        } elseif ($lengthType === 3) {
            $out .= substr(pack('N', $length), 1);
        }
        return $out . $bytes;
    }

    /**
     * @return array{bytes:string}
     */
    protected function parseTlvValue(string $value): array
    {
        if (preg_match('/^[+-]?\d+$/', $value) === 1 && is_int($value + 0)) {
            $number = (int)$value;
            if ($number === 0) {
                return ['bytes' => ''];
            }
            if ($number >= -128 && $number <= 127) {
                return ['bytes' => chr($number & 0xFF)];
            }
            if ($number >= -32768 && $number <= 32767) {
                return ['bytes' => pack('n', $number & 0xFFFF)];
            }
            if ($number >= -2147483648 && $number <= 2147483647) {
                return ['bytes' => pack('N', $number & 0xFFFFFFFF)];
            }
            return ['bytes' => pack('J', $number)];
        }
        if (strcasecmp($value, 'true') === 0) {
            return ['bytes' => "\x01"];
        }
        if (strcasecmp($value, 'false') === 0) {
            return ['bytes' => "\x00"];
        }
        return ['bytes' => $value];
    }

    /**
     * @return array{0:int,1:string}|null
     * @throws InvalidArgumentException
     */
    public function tlvDecodeFirst(string $data): ?array
    {
        $total = strlen($data);
        if ($total === 0) {
            return null;
        }
        $header = ord($data[0]);
        $kind = $header >> 6;
        if ($kind !== 3) {
            throw new InvalidArgumentException('unsupported tlv entry type 0b' . decbin($kind));
        }
        $id16 = ($header & 0x20) !== 0;
        $lengthType = ($header >> 3) & 0x03;
        $inline = $header & 0x07;
        $pos = 1;
        if ($id16) {
            if ($pos + 2 > $total) {
                throw new InvalidArgumentException('truncated tlv id');
            }
            $id = unpack('n', substr($data, $pos, 2))[1];
            $pos += 2;
        } else {
            if ($pos >= $total) {
                throw new InvalidArgumentException('truncated tlv id');
            }
            $id = ord($data[$pos]);
            $pos++;
        }
        switch ($lengthType) {
            case 0:
                $length = $inline;
                break;
            case 1:
                if ($pos >= $total) {
                    throw new InvalidArgumentException('truncated tlv length');
                }
                $length = ord($data[$pos]);
                $pos++;
                break;
            case 2:
                if ($pos + 2 > $total) {
                    throw new InvalidArgumentException('truncated tlv length');
                }
                $length = unpack('n', substr($data, $pos, 2))[1];
                $pos += 2;
                break;
            default:
                if ($pos + 3 > $total) {
                    throw new InvalidArgumentException('truncated tlv length');
                }
                $length = unpack('N', "\x00" . substr($data, $pos, 3))[1];
                $pos += 3;
                break;
        }
        if ($pos + $length > $total) {
            throw new InvalidArgumentException('truncated tlv value');
        }
        return [$id, substr($data, $pos, $length)];
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
