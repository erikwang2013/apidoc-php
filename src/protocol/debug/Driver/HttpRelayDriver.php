<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;
use erikwang2013\apidoc\protocol\debug\Support\HttpClient;
use erikwang2013\apidoc\protocol\debug\Support\TargetGuard;

/**
 * HTTP 中继驱动:由服务端发起单次 HTTP 请求
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class HttpRelayDriver implements DebugDriverInterface
{
    /**
     * @var array
     */
    protected static $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    public function name(): string
    {
        return 'http-relay';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'target', 'in' => 'target', 'type' => 'string', 'require' => true, 'desc' => 'http:// or https:// URL'],
            ['name' => 'method', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'GET'],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'Text/hex/base64 payload'],
            ['name' => 'encoding', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'text'],
            ['name' => 'headers', 'in' => 'header', 'type' => 'array', 'require' => false],
            ['name' => 'query', 'in' => 'query', 'type' => 'array', 'require' => false],
            ['name' => 'timeout', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 5],
        ];
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!in_array($target['scheme'], ['http', 'https'], true)) {
            return ['ok' => false, 'error' => 'http-relay 驱动仅支持 http/https 目标'];
        }
        $unavailable = $this->unavailableError($target['scheme']);
        if ($unavailable !== '') {
            return ['ok' => false, 'error' => $unavailable];
        }
        $method = $input['method'] ?? 'GET';
        if (!is_string($method)) {
            return ['ok' => false, 'error' => 'method 必须是字符串'];
        }
        $method = strtoupper($method);
        if (!in_array($method, static::$methods, true)) {
            return ['ok' => false, 'error' => '不支持的 HTTP 方法: ' . $method];
        }
        $encoding = (string)($input['encoding'] ?? 'text');
        try {
            $body = Hex::decode((string)($input['payload'] ?? ''), $encoding);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($body) > $maxBytes) {
            return ['ok' => false, 'error' => 'request exceeds max_bytes'];
        }
        $url = (string)$context['target'];
        $query = isset($input['query']) && is_array($input['query']) ? $input['query'] : [];
        if ($query !== []) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $result = HttpClient::request(array_merge([
            'url' => $url,
            'method' => $method,
            'headers' => $input['headers'] ?? [],
            'body' => $body,
            'timeout' => (float)($context['timeout'] ?? 5),
            'maxBytes' => $maxBytes,
        ], $this->httpOptions($target['scheme']), TargetGuard::httpOptions($target, $context)));
        if (isset($result['error'])) {
            return ['ok' => false, 'error' => $result['error']];
        }
        return [
            'ok' => true,
            'status' => $result['status'],
            'headers' => $result['headers'],
            'body' => $result['body'],
            'trailers' => $result['trailers'],
            'meta' => ['durationMs' => (int)round((microtime(true) - $start) * 1000), 'bytes' => strlen($result['body'])],
        ];
    }

    /**
     * @return array 追加到 HttpClient 的选项(HTTP 版本等)
     */
    protected function httpOptions(string $scheme): array
    {
        return [];
    }

    /**
     * @return string 非空表示当前环境不支持该驱动
     */
    protected function unavailableError(string $scheme): string
    {
        return '';
    }
}
