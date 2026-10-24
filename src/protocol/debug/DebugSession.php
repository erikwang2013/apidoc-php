<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug;

use erikwang2013\apidoc\utils\Cache;

/**
 * 流式调试会话:Cache 存储,默认 60s 过期
 * @package erikwang2013\apidoc\protocol\debug
 */
class DebugSession
{
    const TTL = 60;

    /**
     * 创建会话并返回 id
     * @param array $data 会话数据(凭证只存 rateKeyHash,不存原始 token)
     * @return string
     */
    public static function create(array $data): string
    {
        $id = bin2hex(random_bytes(16));
        $now = time();
        $data['id'] = $id;
        $data['createdAt'] = $now;
        $data['expiresAt'] = $now + self::TTL;
        (new Cache(['expire' => self::TTL]))->set(self::key($id), $data);
        return $id;
    }

    /**
     * @param string $id
     * @return array|null 不存在或已过期返回 null
     */
    public static function get(string $id): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            return null;
        }
        $data = (new Cache(['expire' => self::TTL]))->get(self::key($id));
        if (!is_array($data) || (int)($data['expiresAt'] ?? 0) < time()) {
            if (is_array($data)) {
                self::delete($id);
            }
            return null;
        }
        return $data;
    }

    /**
     * 续期 60s
     * @param string $id
     * @return bool 会话存在返回 true
     */
    public static function touch(string $id): bool
    {
        $data = self::get($id);
        if ($data === null) {
            return false;
        }
        $data['expiresAt'] = time() + self::TTL;
        return (new Cache(['expire' => self::TTL]))->set(self::key($id), $data);
    }

    public static function delete(string $id): void
    {
        (new Cache(['expire' => self::TTL]))->delete(self::key($id));
    }

    protected static function key(string $id): string
    {
        return 'debug/session/' . $id;
    }
}
