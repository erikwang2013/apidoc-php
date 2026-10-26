<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Support;

use InvalidArgumentException;

/**
 * OPC UA 二进制响应解码:小端原语、NodeId、DataValue、Variant
 * @package erikwang2013\apidoc\protocol\debug\Support
 */
class OpcuaReader
{
    public string $data;
    public int $pos = 0;

    public function __construct(string $data)
    {
        $this->data = $data;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function take(int $length): string
    {
        if ($length < 0 || $this->pos + $length > strlen($this->data)) {
            throw new InvalidArgumentException('truncated opcua message');
        }
        $bytes = substr($this->data, $this->pos, $length);
        $this->pos += $length;
        return $bytes;
    }

    public function rest(): string
    {
        return substr($this->data, $this->pos);
    }

    public function u8(): int
    {
        return ord($this->take(1));
    }

    public function u16(): int
    {
        return unpack('v', $this->take(2))[1];
    }

    public function u32(): int
    {
        return unpack('V', $this->take(4))[1];
    }

    public function i32(): int
    {
        $value = $this->u32();
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function i64(): int
    {
        $value = unpack('P', $this->take(8))[1];
        return $value;
    }

    public function u64(): int
    {
        return unpack('P', $this->take(8))[1];
    }

    public function f32(): float
    {
        return unpack('g', $this->take(4))[1];
    }

    public function f64(): float
    {
        return unpack('e', $this->take(8))[1];
    }

    public function byteString(): ?string
    {
        $length = $this->i32();
        return $length < 0 ? null : $this->take($length);
    }

    public function string(): ?string
    {
        $bytes = $this->byteString();
        return $bytes === null ? null : static::lossy($bytes);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function checkResponseHeader(): void
    {
        $this->i64(); // timestamp
        $this->u32(); // requestHandle
        $status = $this->u32();
        $this->skipDiagnosticInfo(0);
        $strings = $this->i32();
        for ($i = 0; $i < max(0, $strings); $i++) {
            $this->string();
        }
        $this->skipExtensionObject();
        if ($status !== 0) {
            throw new InvalidArgumentException('opcua service status ' . static::statusLabel($status));
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function decodeDataValue(): mixed
    {
        $mask = $this->u8();
        $value = ($mask & 0x01) !== 0 ? $this->decodeVariant() : null;
        $status = ($mask & 0x02) !== 0 ? $this->u32() : 0;
        if (($mask & 0x04) !== 0) {
            $this->i64();
        }
        if (($mask & 0x08) !== 0) {
            $this->u32();
        }
        if (($mask & 0x10) !== 0) {
            $this->i64();
        }
        if (($mask & 0x20) !== 0) {
            $this->u32();
        }
        if ($value !== null && $status === 0) {
            return $value;
        }
        throw new InvalidArgumentException('opcua read status ' . static::statusLabel($status));
    }

    /**
     * @throws InvalidArgumentException
     */
    public function decodeVariant(): mixed
    {
        $mask = $this->u8();
        $isArray = ($mask & 0x80) !== 0;
        $hasDimensions = ($mask & 0x40) !== 0;
        $typeId = $mask & 0x3F;
        if ($isArray) {
            $count = $this->i32();
            $items = [];
            if ($count >= 0) {
                for ($i = 0; $i < min($count, 10000); $i++) {
                    $items[] = $this->decodeScalar($typeId);
                }
            }
            $value = $items;
        } else {
            $value = $this->decodeScalar($typeId);
        }
        if ($hasDimensions) {
            $count = $this->i32();
            // 元素上限:尺寸数组只用于形状,超过上限仍按上限消费,避免恶意长度拖垮解析
            for ($i = 0; $i < min(max(0, $count), 10000); $i++) {
                $this->u32();
            }
        }
        return $value;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function decodeScalar(int $typeId): mixed
    {
        switch ($typeId) {
            case 0:
                return null;
            case 1:
                return $this->u8() !== 0;
            case 2:
                $value = $this->u8();
                return $value >= 0x80 ? $value - 0x100 : $value;
            case 3:
                return $this->u8();
            case 4:
                $value = $this->u16();
                return $value >= 0x8000 ? $value - 0x10000 : $value;
            case 5:
                return $this->u16();
            case 6:
                return $this->i32();
            case 7:
                return $this->u32();
            case 8:
                return $this->i64();
            case 9:
                return $this->u64();
            case 10:
                return $this->finiteFloat($this->f32());
            case 11:
                return $this->finiteFloat($this->f64());
            case 12:
                return $this->string();
            case 13:
                return $this->i64();
            case 15:
                $bytes = $this->byteString();
                return $bytes === null ? null : ['encoding' => 'base64', 'data' => base64_encode($bytes)];
            case 17:
                return $this->decodeNodeId();
            case 19:
                return static::statusLabel($this->u32());
            case 20:
                return ['namespace_index' => $this->u16(), 'name' => $this->string() ?? ''];
            case 21:
                return $this->decodeLocalizedText();
            default:
                throw new InvalidArgumentException('unsupported opcua variant type ' . $typeId);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function decodeNodeId(): string
    {
        $encoding = $this->u8();
        switch ($encoding & 0x0F) {
            case 0:
                $id = 'i=' . $this->u8();
                break;
            case 1:
                $namespace = $this->u8();
                $value = $this->u16();
                $id = $namespace === 0 ? 'i=' . $value : 'ns=' . $namespace . ';i=' . $value;
                break;
            case 2:
                $namespace = $this->u16();
                $value = $this->u32();
                $id = $namespace === 0 ? 'i=' . $value : 'ns=' . $namespace . ';i=' . $value;
                break;
            case 3:
                $namespace = $this->u16();
                $id = 'ns=' . $namespace . ';s=' . ($this->string() ?? '');
                break;
            case 4:
                $id = 'g=' . bin2hex($this->take(16));
                break;
            case 5:
                $id = 'b=' . base64_encode($this->byteString() ?? '');
                break;
            default:
                throw new InvalidArgumentException('unsupported opcua node id encoding ' . ($encoding & 0x0F));
        }
        if (($encoding & 0x40) !== 0) {
            $this->string();
        }
        if (($encoding & 0x80) !== 0) {
            $this->u32();
        }
        return $id;
    }

    public function skipNodeId(): void
    {
        $this->decodeNodeId();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function decodeLocalizedText(): string
    {
        $mask = $this->u8();
        if (($mask & 0x01) !== 0) {
            $this->string();
        }
        return ($mask & 0x02) !== 0 ? ($this->string() ?? '') : '';
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function skipExtensionObject(): void
    {
        $this->skipNodeId();
        $encoding = $this->u8();
        if ($encoding === 0) {
            return;
        }
        if ($encoding !== 1 && $encoding !== 2) {
            throw new InvalidArgumentException('unsupported opcua extension object encoding ' . $encoding);
        }
        $length = $this->i32();
        $this->take(max(0, $length));
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function skipDiagnosticInfo(int $depth): void
    {
        if ($depth > 64) {
            throw new InvalidArgumentException('opcua diagnostic info nesting exceeds 64 levels');
        }
        $mask = $this->u8();
        if (($mask & 0x01) !== 0) {
            $this->string();
        }
        if (($mask & 0x02) !== 0) {
            $this->decodeLocalizedText();
        }
        if (($mask & 0x04) !== 0) {
            $this->string();
        }
        if (($mask & 0x08) !== 0) {
            $this->string();
        }
        if (($mask & 0x10) !== 0) {
            $this->u32();
        }
        if (($mask & 0x20) !== 0) {
            $this->skipDiagnosticInfo($depth + 1);
        }
    }

    public static function statusLabel(int $status): string
    {
        switch ($status) {
            case 0:
                return 'Good';
            case 0x80000000:
                return 'BadUnexpectedError';
            case 0x801F0000:
                return 'BadUserAccessDenied';
            case 0x80200000:
                return 'BadIdentityTokenInvalid';
            case 0x80210000:
                return 'BadIdentityTokenRejected';
            case 0x80350000:
                return 'BadNotConnected';
            default:
                return sprintf('Bad (0x%08X)', $status);
        }
    }

    public static function lossy(string $bytes): string
    {
        if (preg_match('//u', $bytes) === 1) {
            return $bytes;
        }
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $bytes);
        return $converted === false ? '' : $converted;
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
