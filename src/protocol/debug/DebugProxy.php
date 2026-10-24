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
     * @param string $rateKey 限流标识;为空时归入 anonymous 桶
     * @return array
     */
    public function send(string $appKey, string $apiKey, array $input, string $rateKey = ''): array
    {
        $debugConfig = $this->debugConfig();
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
        $request = $this->buildRequest($debugConfig, $debug, $input);
        if (($request['ok'] ?? false) !== true) {
            return $request;
        }
        try {
            $result = $driver->send($request['input'], $this->driverContext($request));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return is_array($result) ? $result : ['ok' => false, 'error' => 'driver returned invalid result'];
    }

    /**
     * 建立流式会话
     * @param string $appKey 应用 key
     * @param string $apiKey 类名@方法名
     * @param array $input 客户端输入
     * @param string $rateKey 限流标识;同时绑定会话
     * @return array
     */
    public function start(string $appKey, string $apiKey, array $input, string $rateKey = ''): array
    {
        $debugConfig = $this->debugConfig();
        if (($debugConfig['relay_enable'] ?? false) !== true) {
            return ['ok' => false, 'error' => 'relay disabled'];
        }
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
        if (($debug['mode'] ?? '') !== 'relay-stream') {
            return ['ok' => false, 'error' => 'driver is not streaming'];
        }
        $driverName = (string)($debug['driver'] ?? '');
        $driver = DebugRegistry::driver($driverName);
        if ($driver === null) {
            return ['ok' => false, 'error' => 'driver is not available: ' . $driverName];
        }
        if (!($driver instanceof StreamingDriverInterface)) {
            return ['ok' => false, 'error' => 'driver does not support streaming: ' . $driverName];
        }
        $request = $this->buildRequest($debugConfig, $debug, $input);
        if (($request['ok'] ?? false) !== true) {
            return $request;
        }
        $rateKeyHash = hash('sha256', $rateKey);
        $sessionIds = $this->liveSessionIds($rateKeyHash);
        $maxSessions = (int)($debugConfig['max_sessions'] ?? 4);
        if ($maxSessions > 0 && count($sessionIds) >= $maxSessions) {
            return ['ok' => false, 'error' => 'max sessions exceeded'];
        }
        $channel = is_array($detail['channel'] ?? null) ? $detail['channel'] : [];
        $sessionId = DebugSession::create([
            'appKey' => $appKey,
            'apiKey' => $apiKey,
            'rateKeyHash' => $rateKeyHash,
            'driver' => $driverName,
            'target' => $request['target'],
            'fields' => $request['input'],
            'channel' => $channel,
        ]);
        $context = $this->driverContext($request);
        $context['sessionId'] = $sessionId;
        $context['channel'] = $channel;
        try {
            $result = $driver->start($request['input'], $context);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            DebugSession::delete($sessionId);
            return is_array($result) ? $result : ['ok' => false, 'error' => 'driver returned invalid result'];
        }
        $sessionIds[] = $sessionId;
        (new Cache())->set($this->sessionIndexKey($rateKeyHash), $sessionIds);
        return ['ok' => true, 'sessionId' => $sessionId];
    }

    /**
     * 拉取流式消息
     * @param string $sessionId
     * @param string $rateKey 必须与建立会话时的凭证一致
     * @return array
     */
    public function poll(string $sessionId, string $rateKey = ''): array
    {
        $debugConfig = $this->debugConfig();
        if (($debugConfig['relay_enable'] ?? false) !== true) {
            return ['ok' => false, 'error' => 'relay disabled'];
        }
        if (!$this->consumeRate($debugConfig, $rateKey !== '' ? $rateKey : 'anonymous')) {
            return ['ok' => false, 'error' => 'rate limit exceeded'];
        }
        $session = DebugSession::get($sessionId);
        if ($session === null) {
            return ['ok' => false, 'error' => 'session not found or expired'];
        }
        if (!hash_equals((string)($session['rateKeyHash'] ?? ''), hash('sha256', $rateKey))) {
            return ['ok' => false, 'error' => 'session credential mismatch'];
        }
        $driver = DebugRegistry::driver((string)($session['driver'] ?? ''));
        if (!($driver instanceof StreamingDriverInterface)) {
            return ['ok' => false, 'error' => 'driver is not available: ' . (string)($session['driver'] ?? '')];
        }
        DebugSession::touch($sessionId);
        $maxTimeout = (float)($debugConfig['max_timeout'] ?? 30);
        $timeout = $maxTimeout > 0 ? min(25.0, $maxTimeout) : 25.0;
        $context = [
            'target' => (string)($session['target'] ?? ''),
            'timeout' => $timeout,
            'maxBytes' => max(1, (int)($debugConfig['max_bytes'] ?? 1048576)),
            'maxMessages' => 100,
            'fields' => is_array($session['fields'] ?? null) ? $session['fields'] : [],
            'channel' => is_array($session['channel'] ?? null) ? $session['channel'] : [],
        ];
        try {
            $result = $driver->poll($sessionId, $context);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            return is_array($result) ? $result : ['ok' => false, 'error' => 'driver returned invalid result'];
        }
        if (!isset($result['messages']) || !is_array($result['messages'])) {
            $result['messages'] = [];
        }
        return $result;
    }

    /**
     * 结束流式会话
     * @param string $sessionId
     * @param string $rateKey 必须与建立会话时的凭证一致
     * @return array
     */
    public function stop(string $sessionId, string $rateKey = ''): array
    {
        $debugConfig = $this->debugConfig();
        if (($debugConfig['relay_enable'] ?? false) !== true) {
            return ['ok' => false, 'error' => 'relay disabled'];
        }
        if (!$this->consumeRate($debugConfig, $rateKey !== '' ? $rateKey : 'anonymous')) {
            return ['ok' => false, 'error' => 'rate limit exceeded'];
        }
        $session = DebugSession::get($sessionId);
        if ($session === null) {
            return ['ok' => false, 'error' => 'session not found or expired'];
        }
        if (!hash_equals((string)($session['rateKeyHash'] ?? ''), hash('sha256', $rateKey))) {
            return ['ok' => false, 'error' => 'session credential mismatch'];
        }
        $driver = DebugRegistry::driver((string)($session['driver'] ?? ''));
        if ($driver instanceof StreamingDriverInterface) {
            try {
                $driver->stop($sessionId, [
                    'target' => (string)($session['target'] ?? ''),
                    'fields' => is_array($session['fields'] ?? null) ? $session['fields'] : [],
                    'channel' => is_array($session['channel'] ?? null) ? $session['channel'] : [],
                ]);
            } catch (Throwable $e) {
                // 清理会话优先,驱动停止失败不影响结果
            }
        }
        DebugSession::delete($sessionId);
        $this->releaseSession((string)$session['rateKeyHash'], $sessionId);
        return ['ok' => true];
    }

    protected function debugConfig(): array
    {
        return isset($this->config['protocols']['debug']) && is_array($this->config['protocols']['debug'])
            ? $this->config['protocols']['debug']
            : [];
    }

    /**
     * 解析目标并归一化驱动输入(单次与流式共用)
     * @param array $debugConfig
     * @param array $debug 调试元数据
     * @param array $input 客户端输入
     * @return array ['ok'=>true,'target','meta','timeout','maxBytes','input'] 或 ['ok'=>false,'error']
     */
    protected function buildRequest(array $debugConfig, array $debug, array $input): array
    {
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

        $driverFields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        unset($input['fields'], $input['target']);
        // 驱动字段优先于顶层同名字段;`fields` 本身可以是驱动字段(如 iso8583 的字段数据)
        $driverInput = array_merge($input, $driverFields);
        unset($driverInput['target']);
        $encoding = $driverInput['encoding'] ?? $defaults['encoding'] ?? 'text';
        if (!is_string($encoding)) {
            return ['ok' => false, 'error' => 'encoding must be a string'];
        }
        $driverInput['encoding'] = $encoding;
        if (isset($driverInput['payload']) && !is_string($driverInput['payload'])) {
            return ['ok' => false, 'error' => 'payload must be a string'];
        }
        $maxBytes = max(1, (int)($debugConfig['max_bytes'] ?? 1048576));
        $encodedInput = json_encode($driverInput, JSON_INVALID_UTF8_SUBSTITUTE);
        $inputSize = $encodedInput === false ? strlen(serialize($driverInput)) : strlen($encodedInput);
        if (strlen((string)($driverInput['payload'] ?? '')) > $maxBytes || $inputSize > $maxBytes) {
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
        return [
            'ok' => true,
            'target' => $target,
            'meta' => $debug,
            'timeout' => $timeout,
            'maxBytes' => $maxBytes,
            'input' => $driverInput,
        ];
    }

    protected function driverContext(array $request): array
    {
        return [
            'target' => $request['target'],
            'meta' => $request['meta'],
            'timeout' => $request['timeout'],
            'maxBytes' => $request['maxBytes'],
        ];
    }

    /**
     * 会话索引:每个限流标识一个桶,过期会话顺手剪掉
     */
    protected function liveSessionIds(string $rateKeyHash): array
    {
        $key = $this->sessionIndexKey($rateKeyHash);
        $cache = new Cache();
        $ids = $cache->get($key, []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $live = [];
        foreach ($ids as $id) {
            if (is_string($id) && DebugSession::get($id) !== null) {
                $live[] = $id;
            }
        }
        if (count($live) !== count($ids)) {
            $cache->set($key, $live);
        }
        return $live;
    }

    protected function releaseSession(string $rateKeyHash, string $sessionId): void
    {
        $key = $this->sessionIndexKey($rateKeyHash);
        $cache = new Cache();
        $ids = $cache->get($key, []);
        if (!is_array($ids)) {
            return;
        }
        $remaining = [];
        foreach ($ids as $id) {
            if ($id !== $sessionId) {
                $remaining[] = $id;
            }
        }
        if ($remaining === []) {
            $cache->delete($key);
        } else {
            $cache->set($key, $remaining);
        }
    }

    protected function sessionIndexKey(string $rateKeyHash): string
    {
        return 'debug/sessions/' . $rateKeyHash;
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
