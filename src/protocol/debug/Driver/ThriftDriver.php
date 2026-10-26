<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;
use erikwang2013\apidoc\protocol\debug\Support\HttpClient;

/**
 * Thrift TBinaryProtocol 一元驱动:schema 来自 Debug.meta.schema(params/returned),
 * 参数按 name 从 JSON body 取值;支持 tcp(默认)与 http 两种传输。
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class ThriftDriver implements DebugDriverInterface
{
    private const T_STOP = 0;
    private const T_BOOL = 2;
    private const T_BYTE = 3;
    private const T_DOUBLE = 4;
    private const T_I16 = 6;
    private const T_I32 = 8;
    private const T_I64 = 10;
    private const T_STRING = 11;
    private const T_STRUCT = 12;
    private const T_MAP = 13;
    private const T_SET = 14;
    private const T_LIST = 15;
    private const CALL = 1;
    private const EXCEPTION = 3;

    public function name(): string
    {
        return 'thrift';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'method', 'in' => 'meta', 'type' => 'string', 'require' => true, 'desc' => 'Thrift method name'],
            ['name' => 'transport', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'tcp', 'desc' => 'tcp or http'],
            ['name' => 'schema', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'JSON {"params":[...],"returned":[...]}, each {name,field,type,required}'],
            ['name' => 'args', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'JSON object keyed by schema param name'],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'Raw Thrift call frame as hex (fallback)'],
        ];
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        $method = trim((string)($input['method'] ?? ''));
        $transport = strtolower(trim((string)($input['transport'] ?? 'tcp')));
        if (!in_array($transport, ['tcp', 'http'], true)) {
            return ['ok' => false, 'error' => 'unsupported thrift transport `' . $transport . '`'];
        }
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
            $schema = $this->schema($input['schema'] ?? null);
            $payload = $input['payload'] ?? '';
            if (!is_string($payload)) {
                return ['ok' => false, 'error' => 'payload 必须是 hex 字符串'];
            }
            if (trim($payload) !== '') {
                $frame = Hex::decode($payload, 'hex');
            } else {
                if ($method === '') {
                    return ['ok' => false, 'error' => 'thrift method 必填'];
                }
                $args = $input['args'] ?? [];
                if (is_string($args)) {
                    $args = trim($args) === '' ? [] : json_decode($args, true);
                }
                if (!is_array($args)) {
                    return ['ok' => false, 'error' => 'thrift args 必须是 JSON 对象'];
                }
                $frame = $this->buildFrame($method, $schema['params'], $args);
            }
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($frame) > $maxBytes) {
            return ['ok' => false, 'error' => 'request exceeds max_bytes'];
        }
        $timeout = (float)($context['timeout'] ?? 5);

        if ($transport === 'http') {
            if (!in_array($target['scheme'], ['http', 'https'], true)) {
                return ['ok' => false, 'error' => 'thrift http 传输需要 http/https 目标'];
            }
            $result = HttpClient::request([
                'url' => (string)$context['target'],
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-thrift', 'Accept: application/x-thrift'],
                'body' => $frame,
                'timeout' => $timeout,
                'maxBytes' => $maxBytes,
            ]);
            if (isset($result['error'])) {
                return ['ok' => false, 'error' => $result['error']];
            }
            $status = (int)($result['status'] ?? 0);
            if ($status < 200 || $status >= 300) {
                return ['ok' => false, 'error' => 'thrift http status ' . $status, 'meta' => ['status' => $status]];
            }
            $response = (string)($result['body'] ?? '');
            try {
                $value = $this->parseResponse($response, $schema['returned']);
            } catch (InvalidArgumentException $e) {
                return ['ok' => false, 'error' => 'thrift response parse failed: ' . $e->getMessage()];
            }
            $meta = ['durationMs' => (int)round((microtime(true) - $start) * 1000), 'bytes' => strlen($response), 'status' => $status];
        } else {
            if ($target['scheme'] !== 'tcp') {
                return ['ok' => false, 'error' => 'thrift tcp 传输需要 tcp://host:port 目标'];
            }
            $exchange = $this->tcpExchange($target['host'] . ':' . $target['port'], $frame, $timeout, $maxBytes, $schema['returned']);
            if (($exchange['ok'] ?? false) !== true) {
                return $exchange;
            }
            $value = $exchange['value'];
            $meta = [
                'durationMs' => (int)round((microtime(true) - $start) * 1000),
                'bytes' => $exchange['bytes'],
                'status' => 0,
                'x-thrift-method' => $method,
            ];
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return ['ok' => false, 'error' => 'thrift 响应无法编码为 JSON'];
        }
        return ['ok' => true, 'body' => $json, 'meta' => $meta];
    }

    /**
     * 构造 TBinaryProtocol CALL 帧
     * @param string $method
     * @param array $params schema.params,每项 {name,field,type,required,children}
     * @param array $args name=>value
     * @return string
     * @throws InvalidArgumentException
     */
    public function buildFrame(string $method, array $params, array $args): string
    {
        if (strlen($method) > 0x7fffffff) {
            throw new InvalidArgumentException('thrift 方法名过长');
        }
        $out = '';
        $out .= pack('N', 0x80010000 | self::CALL);
        $out .= pack('N', strlen($method)) . $method;
        $out .= pack('N', 0);
        foreach ($params as $param) {
            if (!is_array($param)) {
                throw new InvalidArgumentException('thrift schema param 必须是对象');
            }
            $name = (string)($param['name'] ?? '');
            $id = $this->fieldId($param);
            $spec = $this->parseSpec((string)($param['type'] ?? ''), (array)($param['children'] ?? []));
            if (array_key_exists($name, $args)) {
                $out .= $this->writeField($spec['wire'], $id);
                $out .= $this->writeValue($spec, $args[$name]);
            } elseif (!empty($param['required'])) {
                throw new InvalidArgumentException('thrift missing required param `' . $name . '`');
            }
        }
        return $out . chr(self::T_STOP);
    }

    /**
     * 解析 REPLY/EXCEPTION 帧;数据不完整时抛 "incomplete"
     * @param string $frame
     * @param array $returned schema.returned
     * @return array
     * @throws InvalidArgumentException
     */
    public function parseResponse(string $frame, array $returned = []): array
    {
        $offset = 0;
        $message = $this->readMessageBegin($frame, $offset);
        if ($message['type'] === self::EXCEPTION) {
            $exception = $this->readValue($frame, $offset, self::T_STRUCT, null);
            $message = $exception['field_1'] ?? 'thrift exception';
            if (is_array($message)) {
                $message = json_encode($message, JSON_UNESCAPED_SLASHES) ?: 'thrift exception';
            }
            throw new InvalidArgumentException('thrift exception: ' . (string)$message);
        }
        $fields = [];
        foreach ($returned as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('thrift schema returned 必须是对象');
            }
            $fields[] = [
                'name' => (string)($item['name'] ?? ''),
                'id' => $this->fieldId($item),
                'spec' => $this->parseSpec((string)($item['type'] ?? ''), (array)($item['children'] ?? [])),
            ];
        }
        return $this->readValue($frame, $offset, self::T_STRUCT, ['type' => 'struct', 'wire' => self::T_STRUCT, 'fields' => $fields]);
    }

    /**
     * @param mixed $raw
     * @return array ['params'=>array,'returned'=>array]
     * @throws InvalidArgumentException
     */
    protected function schema($raw): array
    {
        if ($raw === null || $raw === '') {
            return ['params' => [], 'returned' => []];
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            throw new InvalidArgumentException('thrift schema 必须是 JSON 对象');
        }
        return [
            'params' => isset($raw['params']) && is_array($raw['params']) ? $raw['params'] : [],
            'returned' => isset($raw['returned']) && is_array($raw['returned']) ? $raw['returned'] : [],
        ];
    }

    /**
     * @param string $ty
     * @param array $children
     * @return array 内部 spec
     * @throws InvalidArgumentException
     */
    protected function parseSpec(string $ty, array $children): array
    {
        $ty = trim($ty);
        if (preg_match('/^list\s*<(.+)>$/i', $ty, $matches) === 1) {
            return $this->spec('list', self::T_LIST, ['item' => $this->parseSpec($matches[1], [])]);
        }
        if (preg_match('/^map\s*<(.+)>$/i', $ty, $matches) === 1) {
            $pos = strpos($matches[1], ',');
            if ($pos === false) {
                throw new InvalidArgumentException('invalid thrift map type `' . $ty . '`');
            }
            return $this->spec('map', self::T_MAP, [
                'key' => $this->parseSpec(substr($matches[1], 0, $pos), []),
                'value' => $this->parseSpec(substr($matches[1], $pos + 1), []),
            ]);
        }
        $simple = [
            'bool' => self::T_BOOL,
            'byte' => self::T_BYTE,
            'i8' => self::T_BYTE,
            'i16' => self::T_I16,
            'i32' => self::T_I32,
            'int' => self::T_I32,
            'i64' => self::T_I64,
            'long' => self::T_I64,
            'double' => self::T_DOUBLE,
            'float' => self::T_DOUBLE,
            'string' => self::T_STRING,
            'binary' => self::T_STRING,
            'bytes' => self::T_STRING,
            'enum' => self::T_I32,
        ];
        $lower = strtolower($ty);
        if (isset($simple[$lower])) {
            return $this->spec($lower, $simple[$lower]);
        }
        if ($lower === 'struct' || $lower === 'object') {
            $fields = [];
            foreach ($children as $child) {
                if (!is_array($child)) {
                    throw new InvalidArgumentException('thrift struct field 必须是对象');
                }
                $fields[] = [
                    'name' => (string)($child['name'] ?? ''),
                    'id' => $this->fieldId($child),
                    'spec' => $this->parseSpec((string)($child['type'] ?? ''), (array)($child['children'] ?? [])),
                ];
            }
            return $this->spec($lower, self::T_STRUCT, ['fields' => $fields]);
        }
        throw new InvalidArgumentException('unsupported thrift type `' . $ty . '`');
    }

    protected function spec(string $type, int $wire, array $extra = []): array
    {
        return ['type' => $type, 'wire' => $wire] + $extra;
    }

    /**
     * @param array $item schema 项
     * @return int
     * @throws InvalidArgumentException
     */
    protected function fieldId(array $item): int
    {
        $name = (string)($item['name'] ?? '');
        $raw = $item['field'] ?? null;
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1)) {
            throw new InvalidArgumentException('thrift field `' . $name . '` requires a numeric field id');
        }
        $id = (int)$raw;
        if ($id < -32768 || $id > 32767) {
            throw new InvalidArgumentException('thrift field id out of range for `' . $name . '`');
        }
        return $id;
    }

    protected function writeField(int $wire, int $id): string
    {
        return chr($wire) . pack('n', $id & 0xffff);
    }

    /**
     * @param array $spec
     * @param mixed $value
     * @return string
     * @throws InvalidArgumentException
     */
    protected function writeValue(array $spec, $value): string
    {
        switch ($spec['wire']) {
            case self::T_BOOL:
                if (!is_bool($value)) {
                    throw new InvalidArgumentException('expected bool');
                }
                return chr($value ? 1 : 0);
            case self::T_BYTE:
                return chr(((int)$this->intValue($value, 'i8')) & 0xff);
            case self::T_I16:
                return pack('n', ((int)$this->intValue($value, 'i16')) & 0xffff);
            case self::T_I32:
                return pack('N', ((int)$this->intValue($value, 'i32')) & 0xffffffff);
            case self::T_I64:
                return pack('J', $this->intValue($value, 'i64'));
            case self::T_DOUBLE:
                if (!is_int($value) && !is_float($value)) {
                    throw new InvalidArgumentException('expected double');
                }
                return pack('E', (float)$value);
            case self::T_STRING:
                if (!is_string($value)) {
                    throw new InvalidArgumentException('expected string');
                }
                if ($spec['type'] === 'binary' || $spec['type'] === 'bytes') {
                    $bytes = base64_decode($value, true);
                    if ($bytes === false) {
                        throw new InvalidArgumentException('invalid base64 binary param');
                    }
                    $value = $bytes;
                }
                return pack('N', strlen($value)) . $value;
            case self::T_LIST:
            case self::T_SET:
                if (!is_array($value)) {
                    throw new InvalidArgumentException('expected array');
                }
                $out = chr($spec['item']['wire']) . pack('N', count($value));
                foreach ($value as $item) {
                    $out .= $this->writeValue($spec['item'], $item);
                }
                return $out;
            case self::T_MAP:
                if (!is_array($value)) {
                    throw new InvalidArgumentException('expected object');
                }
                $out = chr($spec['key']['wire']) . chr($spec['value']['wire']) . pack('N', count($value));
                foreach ($value as $key => $item) {
                    $out .= $this->writeMapKey($spec['key'], (string)$key);
                    $out .= $this->writeValue($spec['value'], $item);
                }
                return $out;
            case self::T_STRUCT:
                if (!is_array($value)) {
                    throw new InvalidArgumentException('expected object');
                }
                $out = '';
                foreach ($spec['fields'] as $field) {
                    if (array_key_exists($field['name'], $value)) {
                        $out .= $this->writeField($field['spec']['wire'], $field['id']);
                        $out .= $this->writeValue($field['spec'], $value[$field['name']]);
                    }
                }
                return $out . chr(self::T_STOP);
        }
        throw new InvalidArgumentException('unsupported thrift wire type ' . $spec['wire']);
    }

    /**
     * @param array $spec
     * @param string $key
     * @return string
     * @throws InvalidArgumentException
     */
    protected function writeMapKey(array $spec, string $key): string
    {
        switch ($spec['wire']) {
            case self::T_STRING:
                return pack('N', strlen($key)) . $key;
            case self::T_BOOL:
                return chr($key === 'true' ? 1 : 0);
            case self::T_BYTE:
            case self::T_I16:
            case self::T_I32:
            case self::T_I64:
                if (preg_match('/^-?\d+$/', $key) !== 1) {
                    throw new InvalidArgumentException('invalid numeric map key `' . $key . '`');
                }
                return $this->writeValue($spec, (int)$key);
        }
        throw new InvalidArgumentException('unsupported thrift map key type');
    }

    /**
     * @param mixed $value
     * @param string $expected
     * @return int
     * @throws InvalidArgumentException
     */
    protected function intValue($value, string $expected): int
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException('expected ' . $expected);
        }
        return $value;
    }

    /**
     * @param string $data
     * @param int $offset
     * @param int $wire
     * @param array|null $spec
     * @return mixed
     * @throws InvalidArgumentException
     */
    protected function readValue(string $data, int &$offset, int $wire, ?array $spec)
    {
        switch ($wire) {
            case self::T_BOOL:
                return $this->readByte($data, $offset) !== 0;
            case self::T_BYTE:
                $value = $this->readByte($data, $offset);
                return $value > 127 ? $value - 256 : $value;
            case self::T_I16:
                return $this->readI16($data, $offset);
            case self::T_I32:
                $value = unpack('N', $this->readBytes($data, $offset, 4))[1];
                return $value > 0x7fffffff ? $value - 0x100000000 : $value;
            case self::T_I64:
                return unpack('J', $this->readBytes($data, $offset, 8))[1];
            case self::T_DOUBLE:
                return unpack('E', $this->readBytes($data, $offset, 8))[1];
            case self::T_STRING:
                $length = $this->readLength($data, $offset);
                $bytes = $this->readBytes($data, $offset, $length);
                if ($spec !== null && ($spec['type'] === 'binary' || $spec['type'] === 'bytes')) {
                    return base64_encode($bytes);
                }
                return $bytes;
            case self::T_STRUCT:
                return $this->readStruct($data, $offset, $spec);
            case self::T_LIST:
            case self::T_SET:
                $elementWire = $this->readByte($data, $offset);
                $length = $this->readLength($data, $offset);
                $elementSpec = $spec !== null && isset($spec['item']) ? $spec['item'] : null;
                $items = [];
                for ($i = 0; $i < $length; $i++) {
                    $items[] = $this->readValue($data, $offset, $elementWire, $elementSpec);
                }
                return $items;
            case self::T_MAP:
                $keyWire = $this->readByte($data, $offset);
                $valueWire = $this->readByte($data, $offset);
                $length = $this->readLength($data, $offset);
                $keySpec = $spec['key'] ?? null;
                $valueSpec = $spec['value'] ?? null;
                $map = [];
                for ($i = 0; $i < $length; $i++) {
                    $key = $this->readValue($data, $offset, $keyWire, $keySpec);
                    if (is_bool($key)) {
                        $key = $key ? 'true' : 'false';
                    } elseif ($key !== null) {
                        $key = (string)$key;
                    }
                    $map[(string)$key] = $this->readValue($data, $offset, $valueWire, $valueSpec);
                }
                return $map;
        }
        throw new InvalidArgumentException('unsupported thrift wire type ' . $wire);
    }

    /**
     * @param string $data
     * @param int $offset
     * @param array|null $spec struct spec
     * @return array
     * @throws InvalidArgumentException
     */
    protected function readStruct(string $data, int &$offset, ?array $spec): array
    {
        $known = [];
        if ($spec !== null && isset($spec['fields']) && is_array($spec['fields'])) {
            foreach ($spec['fields'] as $field) {
                $known[$field['id']] = $field;
            }
        }
        $value = [];
        while (true) {
            $wire = $this->readByte($data, $offset);
            if ($wire === self::T_STOP) {
                return $value;
            }
            $id = $this->readI16($data, $offset);
            $field = $known[$id] ?? null;
            $name = $field !== null ? $field['name'] : 'field_' . $id;
            $value[$name] = $this->readValue($data, $offset, $wire, $field !== null ? $field['spec'] : null);
        }
    }

    /**
     * @return array ['name','type','seq']
     * @throws InvalidArgumentException
     */
    protected function readMessageBegin(string $data, int &$offset): array
    {
        $first = $this->readI32($data, $offset);
        if ($first < 0) {
            if ((($first & 0xffffffff) & 0xffff0000) !== 0x80010000) {
                throw new InvalidArgumentException(sprintf('unsupported thrift version 0x%08x', $first & 0xffffffff));
            }
            $name = $this->readString($data, $offset, $this->readLength($data, $offset));
            $type = $first & 0xff;
        } else {
            $name = $this->readString($data, $offset, $first);
            $type = $this->readByte($data, $offset);
        }
        return ['name' => $name, 'type' => $type, 'seq' => $this->readI32($data, $offset)];
    }

    /**
     * @return string
     * @throws InvalidArgumentException
     */
    protected function readString(string $data, int &$offset, int $length): string
    {
        return $this->readBytes($data, $offset, $length);
    }

    protected function readI16(string $data, int &$offset): int
    {
        $value = unpack('n', $this->readBytes($data, $offset, 2))[1];
        return $value > 32767 ? $value - 65536 : $value;
    }

    protected function readI32(string $data, int &$offset): int
    {
        $value = unpack('N', $this->readBytes($data, $offset, 4))[1];
        return $value > 0x7fffffff ? $value - 0x100000000 : $value;
    }

    /**
     * @return int 非负长度
     * @throws InvalidArgumentException
     */
    protected function readLength(string $data, int &$offset): int
    {
        $length = $this->readI32($data, $offset);
        if ($length < 0) {
            throw new InvalidArgumentException('invalid thrift length ' . $length);
        }
        return $length;
    }

    protected function readByte(string $data, int &$offset): int
    {
        if ($offset >= strlen($data)) {
            throw new InvalidArgumentException('incomplete');
        }
        return ord($data[$offset++]);
    }

    protected function readBytes(string $data, int &$offset, int $length): string
    {
        if ($offset + $length > strlen($data)) {
            throw new InvalidArgumentException('incomplete');
        }
        $raw = substr($data, $offset, $length);
        $offset += $length;
        return $raw;
    }

    /**
     * @param string $address host:port
     * @return array ['ok'=>true,'value'=>mixed,'bytes'=>int] 或 ['ok'=>false,...]
     */
    protected function tcpExchange(string $address, string $frame, float $timeout, int $maxBytes, array $returned): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('tcp://' . $address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            $message = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'connect failed (' . $errno . ')';
            return ['ok' => false, 'error' => 'thrift tcp ' . $message];
        }
        stream_set_blocking($socket, true);
        stream_set_timeout($socket, (int)max(1, (int)ceil($timeout)));
        $written = 0;
        while ($written < strlen($frame)) {
            $bytes = @fwrite($socket, substr($frame, $written));
            if ($bytes === false || $bytes === 0) {
                fclose($socket);
                return ['ok' => false, 'error' => 'thrift tcp write failed'];
            }
            $written += $bytes;
        }
        $response = '';
        $deadline = microtime(true) + $timeout;
        $error = '';
        while (true) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                break;
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int)$left, (int)(($left - floor($left)) * 1000000));
            if ($ready !== 1) {
                break;
            }
            // 多读 1 字节用于判定响应超限,普通响应仍按 maxBytes 截断
            $chunk = @fread($socket, min(8192, $maxBytes + 1 - strlen($response)));
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    break;
                }
                continue;
            }
            $response .= $chunk;
            if (strlen($response) > $maxBytes) {
                $error = 'thrift response exceeds max_bytes';
                break;
            }
            try {
                $value = $this->parseResponse($response, $returned);
                fclose($socket);
                return ['ok' => true, 'value' => $value, 'bytes' => strlen($response)];
            } catch (InvalidArgumentException $e) {
                if ($e->getMessage() !== 'incomplete') {
                    $error = 'thrift response parse failed: ' . $e->getMessage();
                    break;
                }
            }
        }
        fclose($socket);
        if ($error !== '') {
            return ['ok' => false, 'error' => $error];
        }
        if ($response === '') {
            return ['ok' => false, 'error' => 'thrift read timed out'];
        }
        return ['ok' => false, 'error' => 'thrift incomplete response'];
    }
}
