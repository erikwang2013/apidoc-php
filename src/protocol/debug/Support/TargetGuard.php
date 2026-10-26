<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Support;

use InvalidArgumentException;

/**
 * 中继目标 SSRF 守卫,对齐 Rust debug::guard:
 * 主机白名单、回环/私网/链路本地/CGNAT/云元数据拒绝、DNS 结果逐个校验并固定连接地址。
 */
class TargetGuard
{
    /**
     * 校验 DebugTarget::parse() 的结果。
     * @param array $target
     * @param array $config protocols.debug
     * @return array ['host','port','ips','unix']
     * @throws InvalidArgumentException
     */
    public static function check(array $target, array $config): array
    {
        $scheme = (string)($target['scheme'] ?? '');
        if ($scheme === 'unix') {
            if (($config['allow_unix_sockets'] ?? false) !== true) {
                throw new InvalidArgumentException('unix targets are disabled; set protocols.debug.allow_unix_sockets = true to allow them');
            }
            return ['host' => '', 'port' => null, 'ips' => [], 'unix' => true];
        }
        $host = (string)($target['host'] ?? '');
        $port = (int)($target['port'] ?? 0);
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('target requires a host and port');
        }
        if (!static::hostAllowed($config, $host)) {
            throw new InvalidArgumentException('target host `' . $host . '` is not in allow_hosts');
        }
        $ips = static::resolveHost($host);
        if ($ips === []) {
            throw new InvalidArgumentException('target host `' . $host . '` resolved to no addresses');
        }
        foreach ($ips as $ip) {
            static::checkIp($ip, $config);
        }
        return ['host' => $host, 'port' => $port, 'ips' => $ips, 'unix' => false];
    }

    /**
     * 连接地址:用已校验的 IP 替换主机名,防 DNS rebinding。
     * @param array $target
     * @param array $context
     * @return string
     */
    public static function connectHost(array $target, array $context): string
    {
        $ips = static::guardIps($context);
        $host = $ips[0] ?? (string)($target['host'] ?? '');
        return strpos($host, ':') !== false ? '[' . trim($host, '[]') . ']' : $host;
    }

    /**
     * HttpClient 选项:curl 固定解析结果并禁用代理,stream 回退使用 pin 信息。
     * @param array $target
     * @param array $context
     * @return array
     */
    public static function httpOptions(array $target, array $context): array
    {
        $ips = static::guardIps($context);
        if ($ips === []) {
            return [];
        }
        $host = trim((string)($target['host'] ?? ''), '[]');
        $port = (int)($target['port'] ?? 0);
        $ip = $ips[0];
        $resolveIp = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        return [
            'resolve' => [$host . ':' . $port . ':' . $resolveIp],
            'noProxy' => true,
            'pin' => ['host' => $host, 'port' => $port, 'ip' => $ip],
        ];
    }

    /**
     * @param array $config
     * @param string $host
     * @return bool
     */
    public static function hostAllowed(array $config, string $host): bool
    {
        $allow = $config['allow_hosts'] ?? [];
        if (!is_array($allow) || $allow === []) {
            return true;
        }
        $host = static::normalizeHost($host);
        foreach ($allow as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $entry = static::normalizeHost($entry);
            if (strncmp($entry, '*.', 2) === 0) {
                $suffix = substr($entry, 2);
                if ($host === $suffix || substr($host, -strlen($suffix) - 1) === '.' . $suffix) {
                    return true;
                }
                continue;
            }
            if ($host === $entry) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $ip
     * @param array $config
     * @return void
     * @throws InvalidArgumentException
     */
    public static function checkIp(string $ip, array $config): void
    {
        if (static::isAlwaysBlocked($ip)) {
            throw new InvalidArgumentException('target address ' . $ip . ' is blocked (cloud metadata)');
        }
        if (($config['allow_private_networks'] ?? false) === true) {
            return;
        }
        if (static::isPrivate($ip)) {
            throw new InvalidArgumentException('target address ' . $ip . ' is private/loopback/link-local');
        }
    }

    /**
     * @param string $host
     * @return array<string>
     */
    public static function resolveHost(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = [];
        // gethostbynamel 会走 hosts 文件,IPv4 首选
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $ips[] = $ip;
                }
            }
        }
        if (function_exists('dns_get_record')) {
            // shortcut: 系统解析器不带超时,升级到支持异步 DNS 时再加截止时间
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $ip = (string)($record['ipv6'] ?? '');
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                        $ips[] = $ip;
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * 已校验的固定 IP 列表;空数组表示上下文未携带 guard
     * @param array $context
     * @return array<string>
     */
    public static function ips(array $context): array
    {
        return static::guardIps($context);
    }

    /**
     * @param array $context
     * @return array<string>
     */
    protected static function guardIps(array $context): array
    {
        $guard = $context['guard'] ?? null;
        if (!is_array($guard) || !isset($guard['ips']) || !is_array($guard['ips'])) {
            return [];
        }
        $ips = [];
        foreach ($guard['ips'] as $ip) {
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $ip;
            }
        }
        return $ips;
    }

    protected static function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }

    protected static function isAlwaysBlocked(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        if (strlen($bin) === 4) {
            return static::isMetadataV4($bin);
        }
        if ($bin === inet_pton('fd00:ec2::254')) {
            return true;
        }
        $embedded = static::embeddedV4($bin);
        return $embedded !== null && static::isMetadataV4(inet_pton($embedded));
    }

    protected static function isMetadataV4(string $bin): bool
    {
        $o = array_values(unpack('C4', $bin));
        return ($o[0] === 169 && $o[1] === 254 && $o[2] === 169 && $o[3] === 254)
            || ($o[0] === 169 && $o[1] === 254 && $o[2] === 170 && $o[3] === 2)
            || ($o[0] === 100 && $o[1] === 100 && $o[2] === 100 && $o[3] === 200);
    }

    protected static function isPrivate(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        if (strlen($bin) === 4) {
            return static::isPrivateV4($bin);
        }
        // IPv4-mapped/compatible、NAT64、6to4 里嵌入的地址必须按 IPv4 规则判断
        $embedded = static::embeddedV4($bin);
        if ($embedded !== null) {
            return static::isPrivate($embedded);
        }
        $b = array_values(unpack('C16', $bin));
        $last = $b[15];
        $zeroPrefix = $b[0] === 0 && $b[1] === 0 && $b[2] === 0 && $b[3] === 0
            && $b[4] === 0 && $b[5] === 0 && $b[6] === 0 && $b[7] === 0
            && $b[8] === 0 && $b[9] === 0 && $b[10] === 0 && $b[11] === 0
            && $b[12] === 0 && $b[13] === 0 && $b[14] === 0;
        if ($zeroPrefix && ($last === 0 || $last === 1)) {
            return true;
        }
        // ff00::/8 multicast
        if ($b[0] === 0xff) {
            return true;
        }
        // fc00::/7 unique local
        if (($b[0] & 0xfe) === 0xfc) {
            return true;
        }
        // fe80::/10 link-local
        return $b[0] === 0xfe && ($b[1] & 0xc0) === 0x80;
    }

    protected static function isPrivateV4(string $bin): bool
    {
        $o = array_values(unpack('C4', $bin));
        return $o[0] === 10
            || ($o[0] === 172 && $o[1] >= 16 && $o[1] <= 31)
            || ($o[0] === 192 && $o[1] === 168)
            || $o[0] === 127
            || ($o[0] === 169 && $o[1] === 254)
            // 100.64.0.0/10 CGNAT
            || ($o[0] === 100 && $o[1] >= 64 && $o[1] <= 127)
            || ($o[0] === 192 && $o[1] === 0 && $o[2] === 0)
            || ($o[0] === 198 && ($o[1] === 18 || $o[1] === 19))
            || $o[0] >= 224
            || $o[0] === 0;
    }

    /**
     * 提取 IPv6 中嵌入的 IPv4(mapped/compatible/NAT64/6to4/translated)。
     * @param string $bin 16 字节
     * @return string|null
     */
    protected static function embeddedV4(string $bin): ?string
    {
        $b = array_values(unpack('C16', $bin));
        $zero = static function (int $from, int $to) use ($b): bool {
            for ($i = $from; $i < $to; $i++) {
                if ($b[$i] !== 0) {
                    return false;
                }
            }
            return true;
        };
        if ($zero(0, 10) && $b[10] === 0xff && $b[11] === 0xff) {
            return (string)inet_ntop(substr($bin, 12, 4));
        }
        if ($zero(0, 12)) {
            return (string)inet_ntop(substr($bin, 12, 4));
        }
        // 64:ff9b::/96 NAT64
        if ($b[0] === 0x00 && $b[1] === 0x64 && $b[2] === 0xff && $b[3] === 0x9b && $zero(4, 12)) {
            return (string)inet_ntop(substr($bin, 12, 4));
        }
        // 2002::/16 6to4
        if ($b[0] === 0x20 && $b[1] === 0x02) {
            return (string)inet_ntop(substr($bin, 2, 4));
        }
        // ::ffff:0:a.b.c.d IPv4-translated
        if ($zero(0, 8) && $b[8] === 0xff && $b[9] === 0xff && $b[10] === 0 && $b[11] === 0) {
            return (string)inet_ntop(substr($bin, 12, 4));
        }
        return null;
    }
}
