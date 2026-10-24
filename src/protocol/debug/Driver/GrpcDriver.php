<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\Support\HttpClient;
use erikwang2013\apidoc\protocol\debug\Support\Protobuf;

/**
 * gRPC unary 驱动:protobuf 编解码 + gRPC 帧 + HTTP/2 POST
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class GrpcDriver implements DebugDriverInterface
{
    public function name(): string
    {
        return 'grpc';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    /**
     * gRPC 需要 HTTP/2;curl 版本决定能力
     */
    public static function available(): bool
    {
        if (!function_exists('curl_init') || !function_exists('curl_version')) {
            return false;
        }
        $version = curl_version();
        return is_array($version) && (($version['features'] ?? 0) & CURL_VERSION_HTTP2) !== 0;
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'service', 'in' => 'meta', 'type' => 'string', 'require' => true, 'desc' => 'gRPC service, e.g. demo.Greeter'],
            ['name' => 'method', 'in' => 'meta', 'type' => 'string', 'require' => true, 'desc' => 'gRPC method, e.g. SayHello'],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'JSON protobuf fields keyed by number: {"1":{"type":"string","value":"hi"}}'],
            ['name' => 'schema', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'JSON schema {"request":{...},"response":{...}} or a response field map'],
            ['name' => 'metadata', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'JSON object of gRPC metadata headers'],
            ['name' => 'tls', 'in' => 'meta', 'type' => 'boolean', 'require' => false, 'default' => false],
        ];
    }

    /**
     * gRPC 帧:1 字节压缩标志 0 + 4 字节大端长度 + protobuf
     */
    public function frame(string $message): string
    {
        return "\x00" . pack('N', strlen($message)) . $message;
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        if (!static::available()) {
            return ['ok' => false, 'error' => 'curl HTTP/2 is required for gRPC'];
        }
        $tls = $this->boolField($input['tls'] ?? false);
        if ($tls === null) {
            return ['ok' => false, 'error' => 'tls 必须是布尔值'];
        }
        $base = $this->baseUrl((string)($context['target'] ?? ''), $tls);
        if ($base === null) {
            return ['ok' => false, 'error' => 'grpc 驱动仅支持 host:port 或 http(s)://host:port 目标'];
        }
        $service = trim((string)($input['service'] ?? ''));
        $method = trim((string)($input['method'] ?? ''));
        if ($service === '' || $method === '') {
            return ['ok' => false, 'error' => 'grpc service 和 method 必填'];
        }
        if (preg_match('/^[A-Za-z0-9_.]+$/', $service) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $method) !== 1) {
            return ['ok' => false, 'error' => 'grpc service/method 含非法字符'];
        }
        $metadata = $input['metadata'] ?? '';
        if (!is_string($metadata)) {
            return ['ok' => false, 'error' => 'metadata 必须是 JSON 字符串'];
        }
        $headers = ['content-type: application/grpc', 'te: trailers'];
        if (trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'error' => 'metadata 不是合法的 JSON 对象'];
            }
            foreach ($decoded as $name => $value) {
                $name = strtolower((string)$name);
                if (preg_match('/^[a-z0-9_.-]+$/', $name) !== 1 || !is_scalar($value)) {
                    return ['ok' => false, 'error' => 'metadata 名称或值非法'];
                }
                if (in_array($name, ['content-type', 'te'], true)) {
                    return ['ok' => false, 'error' => 'metadata 不能覆盖 content-type/te'];
                }
                if (preg_match('/[\r\n\0]/', (string)$value) === 1) {
                    return ['ok' => false, 'error' => 'metadata 值不能包含换行'];
                }
                $headers[] = $name . ': ' . $value;
            }
        }
        $schema = null;
        $schemaRaw = $input['schema'] ?? '';
        if (is_array($schemaRaw)) {
            $schema = $schemaRaw;
        } elseif (is_string($schemaRaw) && trim($schemaRaw) !== '') {
            $schema = json_decode($schemaRaw, true);
            if (!is_array($schema)) {
                return ['ok' => false, 'error' => 'schema 不是合法的 JSON 对象'];
            }
        } elseif (!is_string($schemaRaw)) {
            return ['ok' => false, 'error' => 'schema 必须是 JSON 字符串'];
        }
        $responseSchema = [];
        if ($schema !== null) {
            if (isset($schema['response']) && is_array($schema['response'])) {
                $responseSchema = $schema['response'];
            } elseif (!isset($schema['request'])) {
                $responseSchema = $schema;
            }
        }
        $payload = $input['payload'] ?? '';
        if (is_array($payload)) {
            $fields = $payload;
        } elseif (is_string($payload)) {
            $fields = [];
            if (trim($payload) !== '') {
                $fields = json_decode($payload, true);
                if (!is_array($fields)) {
                    return ['ok' => false, 'error' => 'payload 不是合法的 JSON 对象'];
                }
            }
        } else {
            return ['ok' => false, 'error' => 'payload 必须是 JSON 字符串'];
        }
        try {
            $message = Protobuf::encode($fields);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $frame = $this->frame($message);
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($frame) > $maxBytes) {
            return ['ok' => false, 'error' => 'request exceeds max_bytes'];
        }
        $result = HttpClient::request([
            'url' => $base . '/' . $service . '/' . $method,
            'method' => 'POST',
            'headers' => $headers,
            'body' => $frame,
            'timeout' => (float)($context['timeout'] ?? 5),
            'maxBytes' => $maxBytes,
            'httpVersion' => 2,
        ]);
        if (isset($result['error'])) {
            return ['ok' => false, 'error' => $result['error']];
        }
        $status = (int)($result['status'] ?? 0);
        // trailers 中的状态优先于响应头
        $statusHeaders = array_merge((array)($result['headers'] ?? []), (array)($result['trailers'] ?? []));
        if (!isset($statusHeaders['grpc-status'])) {
            return ['ok' => false, 'error' => 'gRPC 响应缺少 grpc-status(目标可能不是 gRPC 服务, HTTP ' . $status . ')'];
        }
        $grpcStatus = (int)$statusHeaders['grpc-status'];
        $grpcMessage = isset($statusHeaders['grpc-message']) ? rawurldecode((string)$statusHeaders['grpc-message']) : '';
        if ($grpcStatus !== 0) {
            return [
                'ok' => false,
                'error' => 'gRPC status ' . $grpcStatus . ($grpcMessage !== '' ? ': ' . $grpcMessage : ''),
                'meta' => ['grpcStatus' => $grpcStatus, 'grpcMessage' => $grpcMessage, 'status' => $status],
            ];
        }
        $body = (string)($result['body'] ?? '');
        if (strlen($body) < 5) {
            return ['ok' => false, 'error' => 'gRPC 响应帧缺失或过短 (HTTP ' . $status . ')'];
        }
        if (ord($body[0]) !== 0) {
            return ['ok' => false, 'error' => 'gRPC 压缩响应帧暂不支持'];
        }
        $length = unpack('N', substr($body, 1, 4))[1];
        if (5 + $length > strlen($body)) {
            return ['ok' => false, 'error' => 'gRPC 响应帧截断'];
        }
        $message = substr($body, 5, $length);
        if ($responseSchema !== []) {
            try {
                $decoded = Protobuf::decode($message, $responseSchema);
            } catch (InvalidArgumentException $e) {
                return ['ok' => false, 'error' => 'gRPC protobuf 解码失败: ' . $e->getMessage()];
            }
            $bodyOut = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($bodyOut === false) {
                return ['ok' => false, 'error' => 'gRPC 响应无法编码为 JSON'];
            }
        } else {
            $bodyOut = bin2hex($message);
        }
        return [
            'ok' => true,
            'body' => $bodyOut,
            'meta' => [
                'grpcStatus' => $grpcStatus,
                'grpcMessage' => $grpcMessage,
                'durationMs' => (int)round((microtime(true) - $start) * 1000),
                'bytes' => strlen($message),
                'status' => $status,
            ],
        ];
    }

    /**
     * @return string|null 归一为 scheme://host:port;非法返回 null
     */
    protected function baseUrl(string $target, bool $tls): ?string
    {
        $target = trim($target);
        if (preg_match('#^(https?)://#i', $target, $matches) === 1) {
            $parts = parse_url($target);
            if (!is_array($parts) || empty($parts['host']) || empty($parts['port'])) {
                return null;
            }
            $port = (int)$parts['port'];
            if ($port < 1 || $port > 65535) {
                return null;
            }
            return strtolower($matches[1]) . '://' . $parts['host'] . ':' . $port;
        }
        if (preg_match('/^([A-Za-z0-9.-]+):(\d{1,5})$/', $target, $matches) !== 1) {
            return null;
        }
        $port = (int)$matches[2];
        if ($port < 1 || $port > 65535) {
            return null;
        }
        return ($tls ? 'https' : 'http') . '://' . $matches[1] . ':' . $port;
    }

    protected function boolField($raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if ($raw === 0 || $raw === '0' || $raw === 'false') {
            return false;
        }
        if ($raw === 1 || $raw === '1' || $raw === 'true') {
            return true;
        }
        return null;
    }
}
