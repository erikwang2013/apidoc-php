<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Support;

use InvalidArgumentException;

/**
 * AMF0 子集:number/boolean/string/object/null/undefined/ECMA array/date
 * 值表示为 ['type'=>..., 'value'=>...],未知标记直接报错
 * @package erikwang2013\apidoc\protocol\debug\Support
 */
class Amf0
{
    protected const MAX_DEPTH = 64;

    public static function number(float $value): array
    {
        return ['type' => 'number', 'value' => $value];
    }

    public static function boolean(bool $value): array
    {
        return ['type' => 'boolean', 'value' => $value];
    }

    public static function string(string $value): array
    {
        return ['type' => 'string', 'value' => $value];
    }

    public static function null(): array
    {
        return ['type' => 'null'];
    }

    public static function object(array $fields): array
    {
        return ['type' => 'object', 'value' => $fields];
    }

    public static function ecmaArray(array $fields): array
    {
        return ['type' => 'ecma', 'value' => $fields];
    }

    public static function encode(array $value): string
    {
        $type = (string)($value['type'] ?? '');
        switch ($type) {
            case 'number':
                return "\x00" . pack('E', (float)$value['value']);
            case 'boolean':
                return "\x01" . chr(!empty($value['value']) ? 1 : 0);
            case 'string':
                return static::encodeString((string)$value['value']);
            case 'null':
                return "\x05";
            case 'object':
                return "\x03" . static::encodeProperties((array)($value['value'] ?? []));
            case 'ecma':
                $fields = (array)($value['value'] ?? []);
                return "\x08" . pack('N', count($fields)) . static::encodeProperties($fields);
            default:
                throw new InvalidArgumentException('unsupported AMF0 value type `' . $type . '`');
        }
    }

    public static function encodeString(string $value): string
    {
        return "\x02" . pack('n', strlen($value) & 0xFFFF) . $value;
    }

    public static function encodeNumber(float $value): string
    {
        return "\x00" . pack('E', $value);
    }

    /**
     * @param array<array{0:string,1:array}> $fields
     */
    public static function encodeObject(array $fields): string
    {
        $map = [];
        foreach ($fields as $field) {
            $map[$field[0]] = $field[1];
        }
        return static::encode(static::object($map));
    }

    public static function encodeProperties(array $fields): string
    {
        ksort($fields, SORT_STRING);
        $out = '';
        foreach ($fields as $name => $field) {
            $name = (string)$name;
            $out .= pack('n', strlen($name) & 0xFFFF) . $name . static::encode($field);
        }
        return $out . "\x00\x00\x09";
    }

    /**
     * @return array
     * @throws InvalidArgumentException
     */
    public static function decodeAll(string $bytes): array
    {
        $position = 0;
        $values = [];
        $total = strlen($bytes);
        while ($position < $total) {
            $values[] = static::decodeValue($bytes, $position, 0);
        }
        return $values;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function decodeValue(string $bytes, int &$position, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new InvalidArgumentException('AMF0 nesting exceeds ' . self::MAX_DEPTH . ' levels');
        }
        $marker = ord(static::take($bytes, $position, 1));
        switch ($marker) {
            case 0x00:
                return static::number(unpack('E', static::take($bytes, $position, 8))[1]);
            case 0x01:
                return static::boolean(ord(static::take($bytes, $position, 1)) !== 0);
            case 0x02:
                $length = unpack('n', static::take($bytes, $position, 2))[1];
                return static::string(static::readText($bytes, $position, $length));
            case 0x03:
                return static::object(static::readProperties($bytes, $position, $depth + 1));
            case 0x05:
            case 0x06:
                return static::null();
            case 0x08:
                static::take($bytes, $position, 4);
                return static::ecmaArray(static::readProperties($bytes, $position, $depth + 1));
            case 0x0B:
                $milliseconds = unpack('E', static::take($bytes, $position, 8))[1];
                static::take($bytes, $position, 2);
                return static::number($milliseconds);
            case 0x0C:
                $length = unpack('N', static::take($bytes, $position, 4))[1];
                return static::string(static::readText($bytes, $position, $length));
            default:
                throw new InvalidArgumentException(sprintf('unsupported AMF0 type 0x%02x', $marker));
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected static function readProperties(string $bytes, int &$position, int $depth): array
    {
        $fields = [];
        while (true) {
            $length = unpack('n', static::take($bytes, $position, 2))[1];
            if ($length === 0) {
                $end = ord(static::take($bytes, $position, 1));
                if ($end !== 0x09) {
                    throw new InvalidArgumentException(sprintf('invalid AMF0 object end 0x%02x', $end));
                }
                return $fields;
            }
            $name = static::readText($bytes, $position, $length);
            $fields[$name] = static::decodeValue($bytes, $position, $depth);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected static function take(string $bytes, int &$position, int $length): string
    {
        if ($position + $length > strlen($bytes)) {
            throw new InvalidArgumentException('truncated AMF0 value');
        }
        $slice = substr($bytes, $position, $length);
        $position += $length;
        return $slice;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected static function readText(string $bytes, int &$position, int $length): string
    {
        $slice = static::take($bytes, $position, $length);
        if (preg_match('//u', $slice) !== 1) {
            throw new InvalidArgumentException('AMF0 string is not UTF-8');
        }
        return $slice;
    }
}
