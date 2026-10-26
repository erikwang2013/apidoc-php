<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Support;

use InvalidArgumentException;

/**
 * 调试载荷编解码
 * @package erikwang2013\apidoc\protocol\debug\Support
 */
class Hex
{
    /**
     * @param string $value 载荷
     * @param string $encoding text|hex|base64
     * @return string 原始字节
     * @throws InvalidArgumentException 编码非法
     */
    public static function decode(string $value, string $encoding): string
    {
        switch ($encoding) {
            case 'hex':
                $value = (string)preg_replace('/\s+/', '', $value);
                if ($value !== '' && (strlen($value) % 2 !== 0 || !ctype_xdigit($value))) {
                    throw new InvalidArgumentException('payload 不是合法的十六进制');
                }
                return $value === '' ? '' : (string)hex2bin($value);
            case 'base64':
                $bytes = base64_decode($value, true);
                if ($bytes === false) {
                    throw new InvalidArgumentException('payload 不是合法的 base64');
                }
                return $bytes;
            default:
                return $value;
        }
    }

    /**
     * 二进制载荷描述(与 Rust Payload::from_bytes 对齐):合法 UTF-8 用 utf8,否则 base64
     * @param string $bytes
     * @return array{encoding:string,data:string}
     */
    public static function payload(string $bytes): array
    {
        if (preg_match('//u', $bytes) === 1) {
            return ['encoding' => 'utf8', 'data' => $bytes];
        }
        return ['encoding' => 'base64', 'data' => base64_encode($bytes)];
    }

    /**
     * @param string $bytes 原始字节
     * @param string $encoding text|hex|base64
     * @return string 编码后的载荷
     */
    public static function encode(string $bytes, string $encoding): string
    {
        switch ($encoding) {
            case 'hex':
                return bin2hex($bytes);
            case 'base64':
                return base64_encode($bytes);
            default:
                return $bytes;
        }
    }
}
