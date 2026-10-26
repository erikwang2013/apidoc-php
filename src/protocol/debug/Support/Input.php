<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Support;

use InvalidArgumentException;

/**
 * 驱动选项归一:UI 传来的数字可能是字符串,按 Rust opts_* 语义处理
 * @package erikwang2013\apidoc\protocol\debug\Support
 */
class Input
{
    /**
     * 可选整数;缺失/非法/越界时返回默认值(对齐 Rust `parse().ok()`)
     */
    public static function int(array $input, string $key, int $default, ?int $min = null, ?int $max = null): int
    {
        $value = $input[$key] ?? null;
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $number = (int)$value;
        } else {
            return $default;
        }
        if ($min !== null && $number < $min) {
            return $default;
        }
        if ($max !== null && $number > $max) {
            return $default;
        }
        return $number;
    }

    /**
     * 必填整数;缺失或非法抛 InvalidArgumentException
     * @throws InvalidArgumentException
     */
    public static function requiredInt(array $input, string $key, ?int $min = null, ?int $max = null): int
    {
        if (!isset($input[$key])) {
            throw new InvalidArgumentException($key . ' is required');
        }
        $value = $input[$key];
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw new InvalidArgumentException('invalid ' . $key);
        }
        $number = (int)$value;
        if (($min !== null && $number < $min) || ($max !== null && $number > $max)) {
            throw new InvalidArgumentException('invalid ' . $key);
        }
        return $number;
    }

    /**
     * 必填字符串;缺失或非标量抛 InvalidArgumentException
     * @throws InvalidArgumentException
     */
    public static function required(array $input, string $key): string
    {
        if (!isset($input[$key]) || !is_scalar($input[$key])) {
            throw new InvalidArgumentException($key . ' is required');
        }
        return (string)$input[$key];
    }

    /**
     * 可选字符串;缺失返回默认值,非标量抛异常
     * @throws InvalidArgumentException
     */
    public static function string(array $input, string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return $default;
        }
        if (!is_scalar($input[$key])) {
            throw new InvalidArgumentException($key . ' must be a string');
        }
        return (string)$input[$key];
    }

    /**
     * Rust opts_bool 语义:仅 "true"/"1" 为真;PHP 布尔原样接受
     */
    public static function bool(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return strcasecmp($value, 'true') === 0 || $value === '1';
        }
        return $value === 1;
    }
}
