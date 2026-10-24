<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Support;

use InvalidArgumentException;

/**
 * protobuf wire 格式最小编解码:wire type 0(varint)/1(64 位)/2(长度前缀)/5(32 位)
 * @package erikwang2013\apidoc\protocol\debug\Support
 */
class Protobuf
{
    /**
     * @param array $fields fieldNumber => ['type'=>'string|bytes|bool|int|double|message|repeated','value'=>...]
     * @return string
     * @throws InvalidArgumentException
     */
    public static function encode(array $fields): string
    {
        $out = '';
        foreach ($fields as $number => $field) {
            $number = (int)$number;
            if ($number < 1 || $number > 0x1fffffff) {
                throw new InvalidArgumentException('protobuf 字段编号非法: ' . $number);
            }
            if (!is_array($field) || !isset($field['type'])) {
                throw new InvalidArgumentException('protobuf 字段定义缺少 type');
            }
            if ($field['type'] === 'repeated') {
                $items = isset($field['value']) && is_array($field['value']) ? $field['value'] : [];
                foreach ($items as $item) {
                    if (!is_array($item) || !isset($item['type'])) {
                        $item = ['type' => $field['itemType'] ?? 'string', 'value' => $item];
                    }
                    $out .= static::encodeValue($number, $item);
                }
                continue;
            }
            $out .= static::encodeValue($number, $field);
        }
        return $out;
    }

    /**
     * @param int $number 字段编号
     * @param array $field ['type','value']
     * @return string
     * @throws InvalidArgumentException
     */
    protected static function encodeValue(int $number, array $field): string
    {
        $type = (string)$field['type'];
        $value = $field['value'] ?? null;
        switch ($type) {
            case 'string':
            case 'bytes':
                $raw = (string)$value;
                return static::varint(($number << 3) | 2) . static::varint(strlen($raw)) . $raw;
            case 'message':
                $raw = static::encode(is_array($value) ? $value : []);
                return static::varint(($number << 3) | 2) . static::varint(strlen($raw)) . $raw;
            case 'bool':
                return static::varint(($number << 3) | 0) . static::varint($value ? 1 : 0);
            case 'int':
                return static::varint(($number << 3) | 0) . static::varint((int)$value);
            case 'double':
                return static::varint(($number << 3) | 1) . pack('e', (float)$value);
            default:
                throw new InvalidArgumentException('不支持的 protobuf 类型: ' . $type);
        }
    }

    /**
     * @param string $bytes
     * @param array $schema fieldNumber => ['name','type'](+'fields' 嵌套 / 'itemType' 重复)
     * @return array 字段名 => 值;未知字段为 field_<n> => 原始字节 hex
     * @throws InvalidArgumentException
     */
    public static function decode(string $bytes, array $schema): array
    {
        $result = [];
        $offset = 0;
        $total = strlen($bytes);
        while ($offset < $total) {
            $start = $offset;
            $tag = static::readVarint($bytes, $offset);
            $number = $tag >> 3;
            $wire = $tag & 0x07;
            if ($number < 1) {
                throw new InvalidArgumentException('protobuf 字段编号非法');
            }
            $entry = isset($schema[$number]) && is_array($schema[$number]) ? $schema[$number] : null;
            if ($entry === null) {
                static::skipValue($bytes, $offset, $wire);
                $result['field_' . $number] = bin2hex(substr($bytes, $start, $offset - $start));
                continue;
            }
            $name = isset($entry['name']) ? (string)$entry['name'] : 'field_' . $number;
            $type = isset($entry['type']) ? (string)$entry['type'] : '';
            if ($type === 'repeated' || strpos($type, 'repeated:') === 0) {
                $itemType = strpos($type, 'repeated:') === 0 ? substr($type, 9) : (string)($entry['itemType'] ?? 'string');
                // proto3 标量重复字段常以 packed 形式出现
                if ($wire === 2 && in_array($itemType, ['int', 'bool', 'double'], true)) {
                    $length = static::readVarint($bytes, $offset);
                    $packed = static::readBytes($bytes, $offset, $length);
                    $inner = 0;
                    while ($inner < strlen($packed)) {
                        if ($itemType === 'double') {
                            $result[$name][] = unpack('e', static::readBytes($packed, $inner, 8))[1];
                            continue;
                        }
                        $item = static::readVarint($packed, $inner);
                        $result[$name][] = $itemType === 'bool' ? $item !== 0 : $item;
                    }
                    continue;
                }
                $result[$name][] = static::decodeValue($bytes, $offset, $wire, $itemType, $entry);
                continue;
            }
            $result[$name] = static::decodeValue($bytes, $offset, $wire, $type, $entry);
        }
        return $result;
    }

    /**
     * @param string $bytes
     * @param int $offset
     * @param int $wire
     * @param string $type
     * @param array $entry
     * @return mixed
     * @throws InvalidArgumentException
     */
    protected static function decodeValue(string $bytes, int &$offset, int $wire, string $type, array $entry)
    {
        switch ($wire) {
            case 0:
                $value = static::readVarint($bytes, $offset);
                return $type === 'bool' ? $value !== 0 : $value;
            case 1:
                $raw = static::readBytes($bytes, $offset, 8);
                return $type === 'double' ? unpack('e', $raw)[1] : bin2hex($raw);
            case 2:
                $length = static::readVarint($bytes, $offset);
                $raw = static::readBytes($bytes, $offset, $length);
                if ($type === 'message') {
                    $fields = isset($entry['fields']) && is_array($entry['fields'])
                        ? $entry['fields']
                        : (isset($entry['schema']) && is_array($entry['schema']) ? $entry['schema'] : []);
                    return static::decode($raw, $fields);
                }
                return $raw;
            case 5:
                $raw = static::readBytes($bytes, $offset, 4);
                return $type === 'float' ? unpack('g', $raw)[1] : unpack('V', $raw)[1];
            default:
                throw new InvalidArgumentException('不支持的 protobuf wire type: ' . $wire);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected static function skipValue(string $bytes, int &$offset, int $wire): void
    {
        switch ($wire) {
            case 0:
                static::readVarint($bytes, $offset);
                return;
            case 1:
                static::readBytes($bytes, $offset, 8);
                return;
            case 2:
                $length = static::readVarint($bytes, $offset);
                static::readBytes($bytes, $offset, $length);
                return;
            case 5:
                static::readBytes($bytes, $offset, 4);
                return;
            default:
                throw new InvalidArgumentException('不支持的 protobuf wire type: ' . $wire);
        }
    }

    /**
     * @param int $value
     * @return string
     */
    protected static function varint(int $value): string
    {
        $out = '';
        if ($value < 0) {
            // 负数按 64 位补码固定 10 字节
            for ($i = 0; $i < 9; $i++) {
                $out .= chr(($value & 0x7f) | 0x80);
                $value >>= 7;
            }
            return $out . chr($value & 0x01);
        }
        while ($value > 0x7f) {
            $out .= chr(($value & 0x7f) | 0x80);
            $value >>= 7;
        }
        return $out . chr($value);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected static function readVarint(string $bytes, int &$offset): int
    {
        $value = 0;
        $total = strlen($bytes);
        for ($i = 0; $i < 10; $i++) {
            if ($offset >= $total) {
                throw new InvalidArgumentException('protobuf varint 截断');
            }
            $byte = ord($bytes[$offset++]);
            if ($i === 9 && $byte > 1) {
                throw new InvalidArgumentException('protobuf varint 溢出');
            }
            $value |= ($byte & 0x7f) << ($i * 7);
            if (($byte & 0x80) === 0) {
                return $value;
            }
        }
        throw new InvalidArgumentException('protobuf varint 过长');
    }

    /**
     * @throws InvalidArgumentException
     */
    protected static function readBytes(string $bytes, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($bytes)) {
            throw new InvalidArgumentException('protobuf 长度越界');
        }
        $raw = substr($bytes, $offset, $length);
        $offset += $length;
        return $raw;
    }
}
