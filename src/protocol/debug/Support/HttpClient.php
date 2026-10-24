<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Support;

/**
 * 调试中继 HTTP 客户端:优先 curl,无扩展时退回 stream 上下文
 * 仅 http/https、最多 3 次重定向、不带 cookie、限制超时与响应字节数
 * @package erikwang2013\apidoc\protocol\debug\Support
 */
class HttpClient
{
    /**
     * @param array $options url, method, headers, body, timeout, maxBytes, httpVersion, http2PriorKnowledge
     * @return array ['status','headers','body','trailers'] 或 ['error']
     */
    public static function request(array $options): array
    {
        $scheme = strtolower((string)parse_url((string)($options['url'] ?? ''), PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['error' => '仅支持 http/https 目标'];
        }
        if (function_exists('curl_init')) {
            return static::viaCurl($options);
        }
        return static::viaStream($options);
    }

    protected static function viaCurl(array $options): array
    {
        $method = strtoupper((string)($options['method'] ?? 'GET'));
        $timeout = max(0.1, (float)($options['timeout'] ?? 5));
        $maxBytes = max(1, (int)($options['maxBytes'] ?? 1048576));
        $headers = static::normalizeHeaders($options['headers'] ?? []);
        $body = (string)($options['body'] ?? '');
        $httpVersion = (int)($options['httpVersion'] ?? 1);
        $priorKnowledge = (bool)($options['http2PriorKnowledge'] ?? false);
        $tls = strtolower((string)parse_url((string)($options['url'] ?? ''), PHP_URL_SCHEME)) === 'https';

        $status = 0;
        $responseHeaders = [];
        $trailers = [];
        $bodyStarted = false;
        $truncated = false;
        $responseBody = '';

        $ch = curl_init((string)$options['url']);
        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT_MS => (int)round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int)round(min($timeout, 10) * 1000),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => $httpVersion === 2
                ? static::versionOption($tls, $priorKnowledge)
                : CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$status, &$responseHeaders, &$trailers, &$bodyStarted): int {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    return strlen($line);
                }
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $matches) === 1) {
                    $status = (int)$matches[1];
                    $responseHeaders = [];
                    $trailers = [];
                    $bodyStarted = false;
                    return strlen($line);
                }
                $pos = strpos($trimmed, ':');
                if ($pos === false) {
                    return strlen($line);
                }
                $name = strtolower(trim(substr($trimmed, 0, $pos)));
                $value = trim(substr($trimmed, $pos + 1));
                if ($bodyStarted) {
                    $trailers[$name] = $value;
                } else {
                    $responseHeaders[$name] = $value;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$responseBody, &$bodyStarted, &$truncated, $maxBytes): int {
                $bodyStarted = true;
                $room = $maxBytes - strlen($responseBody);
                if (strlen($chunk) > $room) {
                    $responseBody .= substr($chunk, 0, $room);
                    $truncated = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $curlOptions[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $curlOptions[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $curlOptions[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($body !== '' || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $curlOptions);
        $ok = curl_exec($ch);
        $error = curl_error($ch);

        if ($ok === false && !$truncated) {
            return ['error' => $error !== '' ? $error : 'request failed'];
        }
        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $responseBody,
            'trailers' => $trailers,
        ];
    }

    /**
     * HTTP/2 协商方式:明文用 h2c prior knowledge(gRPC 默认),TLS 用 ALPN
     * @param bool $tls 目标是否 https
     * @param bool $priorKnowledge 是否允许明文 prior knowledge
     * @param bool|null $priorAvailable 仅测试用;false 模拟 curl 常量不可用
     * @return int curl 的 CURLOPT_HTTP_VERSION 值
     */
    public static function versionOption(bool $tls, bool $priorKnowledge, ?bool $priorAvailable = null): int
    {
        if (!$tls && $priorKnowledge && $priorAvailable !== false && defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE')) {
            return CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE;
        }
        return CURL_HTTP_VERSION_2_0;
    }

    protected static function viaStream(array $options): array
    {
        if ((int)($options['httpVersion'] ?? 1) === 2) {
            return ['error' => 'HTTP/2 需要 curl 扩展'];
        }
        $method = strtoupper((string)($options['method'] ?? 'GET'));
        $timeout = max(0.1, (float)($options['timeout'] ?? 5));
        $maxBytes = max(1, (int)($options['maxBytes'] ?? 1048576));
        $headers = static::normalizeHeaders($options['headers'] ?? []);
        $body = (string)($options['body'] ?? '');
        $url = (string)$options['url'];
        // 总超时按绝对截止时间计,重定向各跳共享剩余时间
        $deadline = microtime(true) + $timeout;

        for ($redirects = 0; ; $redirects++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return ['error' => 'request timed out'];
            }
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                return ['error' => '仅支持 http/https 目标'];
            }
            $http = [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => $remaining,
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ];
            if ($body !== '' || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $http['content'] = $body;
            }
            $context = stream_context_create(['http' => $http]);
            $responseBody = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
            // PHP 8.4+ 用函数取响应头,避免 8.5 对 $http_response_header 的弃用告警;
            // 旧版本经 get_defined_vars() 取,避免字面量引用在 8.5 编译期报弃用
            if (function_exists('http_get_last_response_headers')) {
                $headerLines = (array)(http_get_last_response_headers() ?? []);
            } else {
                $vars = get_defined_vars();
                $headerLines = isset($vars['http_response_header']) ? (array)$vars['http_response_header'] : [];
            }
            if ($responseBody === false) {
                // 流包装器超时可能比截止时间早几毫秒返回,留容差
                $timedOut = $deadline - microtime(true) <= 0.1;
                return ['error' => $timedOut ? 'request timed out' : 'request failed'];
            }
            list($status, $responseHeaders) = static::parseHeaderLines($headerLines);
            if ($status >= 300 && $status < 400 && $redirects < 3 && !empty($responseHeaders['location'])) {
                $url = static::resolveUrl($url, $responseHeaders['location']);
                continue;
            }
            return [
                'status' => $status,
                'headers' => $responseHeaders,
                'body' => substr($responseBody, 0, $maxBytes),
                'trailers' => [],
            ];
        }
    }

    /**
     * @param array $headers ['Name'=>'value'] 或 ['Name: value']
     * @return array 头行;含 CR/LF 的项被丢弃以防头注入
     */
    protected static function normalizeHeaders($headers): array
    {
        if (!is_array($headers)) {
            return [];
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $line = (string)$value;
            } else {
                $line = $name . ': ' . $value;
            }
            if (preg_match('/[\r\n\0]/', $line) !== 1) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /**
     * @param array $lines 原始响应头行
     * @return array [status, headers]
     */
    protected static function parseHeaderLines(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int)$matches[1];
                $headers = [];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }
        return [$status, $headers];
    }

    protected static function resolveUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (strpos($location, '//') === 0) {
            return $parts['scheme'] . ':' . $location;
        }
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }
        $path = isset($parts['path']) ? $parts['path'] : '/';
        return $origin . substr($path, 0, (int)strrpos($path, '/') + 1) . $location;
    }
}
