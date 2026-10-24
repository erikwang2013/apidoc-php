<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug;

use InvalidArgumentException;
use Throwable;
use erikwang2013\apidoc\parses\ParseApiDetail;
use erikwang2013\apidoc\utils\Cache;

/**
 * 调试中继:目标只取注解元数据,客户端传入的 target 一律忽略
 * @package erikwang2013\apidoc\protocol\debug
 */
class DebugProxy
{
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 单次调试请求
     * @param string $appKey 应用 key
     * @param string $apiKey 类名@方法名
     * @param array $input 客户端输入
     * @param string $rateKey 限流标识;为空时跳过限流(测试/只读调用)
     * @return array
     */
    public function send(string $appKey, string $apiKey, array $input, string $rateKey = ''): array
    {
        $debugConfig = isset($this->config['protocols']['debug']) && is_array($this->config['protocols']['debug'])
            ? $this->config['protocols']['debug']
            : [];
        if (($debugConfig['relay_enable'] ?? false) !== true) {
            return ['ok' => false, 'error' => 'relay disabled'];
        }
        // 匿名调用也有独立桶,避免空 key 绕过限流
        if (!$this->consumeRate($debugConfig, $rateKey !== '' ? $rateKey : 'anonymous')) {
            return ['ok' => false, 'error' => 'rate limit exceeded'];
        }
        try {
            $detail = (new ParseApiDetail($this->config))->renderApiDetail($appKey, $apiKey);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $debug = is_array($detail) ? ($detail['debug'] ?? []) : [];
        if (empty($debug)) {
            return ['ok' => false, 'error' => 'debug metadata not found'];
        }
        if (($debug['mode'] ?? '') !== 'relay-unary') {
            return ['ok' => false, 'error' => 'driver is not unary'];
        }
        $driverName = (string)($debug['driver'] ?? '');
        $driver = DebugRegistry::driver($driverName);
        if ($driver === null) {
            return ['ok' => false, 'error' => 'driver is not available: ' . $driverName];
        }
        $defaults = isset($debug['defaults']) && is_array($debug['defaults']) ? $debug['defaults'] : [];
        $target = (string)($defaults['target'] ?? '');
        if ($target === '') {
            return ['ok' => false, 'error' => 'debug target not configured'];
        }
        try {
            DebugTarget::parse($target);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
        $driverInput = array_merge($fields, $input);
        unset($driverInput['fields'], $driverInput['target']);
        $encoding = $driverInput['encoding'] ?? $defaults['encoding'] ?? 'text';
        if (!is_string($encoding)) {
            return ['ok' => false, 'error' => 'encoding must be a string'];
        }
        $driverInput['encoding'] = $encoding;
        if (isset($driverInput['payload']) && !is_string($driverInput['payload'])) {
            return ['ok' => false, 'error' => 'payload must be a string'];
        }
        $maxBytes = max(1, (int)($debugConfig['max_bytes'] ?? 1048576));
        if (
            strlen((string)($driverInput['payload'] ?? '')) > $maxBytes ||
            strlen((string)json_encode($driverInput)) > $maxBytes
        ) {
            return ['ok' => false, 'error' => 'request exceeds max_bytes'];
        }

        $timeout = (float)($input['timeout'] ?? $defaults['timeout'] ?? 5);
        $maxTimeout = (float)($debugConfig['max_timeout'] ?? 30);
        if ($maxTimeout > 0) {
            $timeout = min($timeout, $maxTimeout);
        }
        if ($timeout <= 0) {
            $timeout = 5.0;
        }
        $context = [
            'target' => $target,
            'meta' => $debug,
            'timeout' => $timeout,
            'maxBytes' => $maxBytes,
        ];
        try {
            $result = $driver->send($driverInput, $context);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return is_array($result) ? $result : ['ok' => false, 'error' => 'driver returned invalid result'];
    }

    public function start(string $appKey, string $apiKey, array $input, string $rateKey = ''): array
    {
        return ['ok' => false, 'error' => 'streaming driver not implemented yet'];
    }

    public function poll(string $sessionId, string $appKey = '', string $apiKey = '', string $rateKey = ''): array
    {
        return ['ok' => false, 'error' => 'streaming driver not implemented yet'];
    }

    public function stop(string $sessionId, string $appKey = '', string $apiKey = '', string $rateKey = ''): array
    {
        return ['ok' => false, 'error' => 'streaming driver not implemented yet'];
    }

    /**
     * 每分钟计数器限流,默认 60
     */
    protected function consumeRate(array $debugConfig, string $rateKey): bool
    {
        $limit = (int)($debugConfig['rate_limit_per_minute'] ?? 60);
        if ($limit <= 0) {
            return true;
        }
        $bucket = 'debug/rate/' . md5($rateKey) . '/' . (int)floor(time() / 60);
        $cache = new Cache(['expire' => 120]);
        $count = (int)$cache->get($bucket, 0);
        if ($count >= $limit) {
            return false;
        }
        $cache->set($bucket, $count + 1);
        return true;
    }
}
