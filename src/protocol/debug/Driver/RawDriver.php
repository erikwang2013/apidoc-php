<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;

/**
 * 原始套接字驱动(TCP/UDP/UDS);send 由 Task 4 实现
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class RawDriver implements DebugDriverInterface
{
    public function name(): string
    {
        return 'raw';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'target', 'in' => 'target', 'type' => 'string', 'require' => true, 'desc' => 'tcp://host:port, udp://host:port, or unix:///path'],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'Text/hex/base64 payload'],
            ['name' => 'encoding', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'text'],
            ['name' => 'timeout', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 5],
        ];
    }

    public function send(array $input, array $context): array
    {
        return ['ok' => false, 'error' => 'raw driver not implemented yet'];
    }
}
