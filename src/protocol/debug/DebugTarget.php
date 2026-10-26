<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug;

use InvalidArgumentException;

/**
 * 调试目标解析:scheme://host:port/path,裸 host:port 视为 tcp
 * @package erikwang2013\apidoc\protocol\debug
 */
class DebugTarget
{
    /**
     * @var array
     */
    protected static $schemes = [
        'tcp', 'udp', 'unix', 'http', 'https', 'ws', 'wss',
        'kafka', 'kafkas', 'amqp', 'amqps', 'xmpp', 'xmpps',
    ];

    /**
     * @var array
     */
    protected static $defaultPorts = [
        'http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443,
        'kafka' => 9092, 'kafkas' => 9093, 'amqp' => 5672, 'amqps' => 5671,
        'xmpp' => 5222, 'xmpps' => 5223,
    ];

    /**
     * @param string $target
     * @return array ['scheme','host','port','path','original']
     * @throws InvalidArgumentException
     */
    public static function parse(string $target): array
    {
        $original = $target;
        $input = trim($target);
        if ($input === '') {
            throw new InvalidArgumentException('调试目标为空');
        }
        // parse_url 不接受 unix:///path,单独处理
        if (preg_match('#^unix://(.*)$#i', $input, $matches) === 1) {
            if ($matches[1] === '') {
                throw new InvalidArgumentException('unix 调试目标缺少路径');
            }
            return [
                'scheme' => 'unix',
                'host' => '',
                'port' => null,
                'path' => $matches[1],
                'original' => $original,
            ];
        }
        if (strpos($input, '://') === false) {
            $input = 'tcp://' . $input;
        }
        $parts = parse_url($input);
        if ($parts === false || empty($parts['scheme'])) {
            throw new InvalidArgumentException('无法解析调试目标: ' . $original);
        }
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, self::$schemes, true)) {
            throw new InvalidArgumentException('不支持的调试目标协议: ' . $scheme);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('调试目标不能包含账号密码');
        }
        $host = isset($parts['host']) ? (string)$parts['host'] : '';
        if ($host === '') {
            throw new InvalidArgumentException('调试目标缺少主机');
        }
        if (isset($parts['port'])) {
            $port = (int)$parts['port'];
        } elseif (isset(self::$defaultPorts[$scheme])) {
            $port = self::$defaultPorts[$scheme];
        } else {
            throw new InvalidArgumentException($scheme . ' 调试目标必须显式指定端口');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('调试目标端口超出范围: ' . $port);
        }
        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'path' => isset($parts['path']) ? (string)$parts['path'] : '',
            'original' => $original,
        ];
    }
}
