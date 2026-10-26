<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;
use erikwang2013\apidoc\protocol\debug\Support\TargetGuard;

/**
 * Dubbo 2.0.x 一元驱动:16 字节头 + hessian2 body,直连 tcp provider
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class DubboDriver implements DebugDriverInterface
{
    private const MAGIC = "\xda\xbb";
    // request | two-way | hessian2
    private const FLAG_REQUEST = 0xc2;
    private const SERIALIZATION_HESSIAN2 = 2;
    private const STATUS_OK = 20;

    public function name(): string
    {
        return 'dubbo';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'service', 'in' => 'meta', 'type' => 'string', 'require' => true, 'desc' => 'Dubbo service interface, e.g. com.demo.UserService'],
            ['name' => 'method', 'in' => 'meta', 'type' => 'string', 'require' => true, 'desc' => 'Dubbo method name'],
            ['name' => 'version', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => '', 'desc' => 'Service version'],
            ['name' => 'param_types', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Comma-separated Java descriptors, e.g. Ljava/lang/String;I'],
            ['name' => 'args', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'JSON array of call arguments in param_types order'],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false, 'desc' => 'Raw Dubbo body as hex (fallback)'],
        ];
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        $service = trim((string)($input['service'] ?? ''));
        $method = trim((string)($input['method'] ?? ''));
        if ($service === '' || $method === '') {
            return ['ok' => false, 'error' => 'dubbo service 和 method 必填'];
        }
        $version = (string)($input['version'] ?? '');
        $payload = $input['payload'] ?? '';
        if (!is_string($payload)) {
            return ['ok' => false, 'error' => 'payload 必须是 hex 字符串'];
        }
        $typesDesc = $this->paramTypesDesc((string)($input['param_types'] ?? ''));
        try {
            if (trim($payload) !== '') {
                $body = Hex::decode($payload, 'hex');
            } else {
                $args = $input['args'] ?? [];
                if (is_string($args)) {
                    $args = trim($args) === '' ? [] : json_decode($args, true);
                }
                if (!is_array($args) || ($args !== [] && array_keys($args) !== range(0, count($args) - 1))) {
                    return ['ok' => false, 'error' => 'dubbo args 必须是 JSON 数组'];
                }
                $declared = $this->countDescriptors($typesDesc);
                if ($declared !== count($args)) {
                    return ['ok' => false, 'error' => 'dubbo param_types declares ' . $declared . ' parameter(s) but body has ' . count($args)];
                }
                $body = $this->encodeRequest($service, $version, $method, $typesDesc, $args);
            }
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($target['scheme'] !== 'tcp') {
            return ['ok' => false, 'error' => 'dubbo 驱动仅支持 tcp://host:port 目标'];
        }
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($body) > $maxBytes) {
            return ['ok' => false, 'error' => 'request exceeds max_bytes'];
        }
        $frame = $this->frame($body);
        $exchange = $this->tcpExchange(TargetGuard::connectHost($target, $context) . ':' . $target['port'], $frame, (float)($context['timeout'] ?? 5), $maxBytes);
        if (($exchange['ok'] ?? false) !== true) {
            return $exchange;
        }
        $json = json_encode($exchange['value'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return ['ok' => false, 'error' => 'dubbo 响应无法编码为 JSON'];
        }
        return [
            'ok' => true,
            'body' => $json,
            'meta' => [
                'durationMs' => (int)round((microtime(true) - $start) * 1000),
                'bytes' => $exchange['bytes'],
                'status' => 0,
                'x-dubbo-service' => $service,
                'x-dubbo-method' => $method,
                'x-dubbo-status' => self::STATUS_OK,
            ],
        ];
    }

    /**
     * 16 字节 Dubbo 请求头 + body;request id 固定 0(与 Rust 参考实现一致)
     * @param string $body
     * @param int $requestId
     * @return string
     */
    public function frame(string $body, int $requestId = 0): string
    {
        return self::MAGIC . chr(self::FLAG_REQUEST) . chr(0) . pack('J', $requestId) . pack('N', strlen($body)) . $body;
    }

    /**
     * @param string $service
     * @param string $version
     * @param string $method
     * @param string $typesDesc
     * @param array $args
     * @return string hessian2 body
     */
    public function encodeRequest(string $service, string $version, string $method, string $typesDesc, array $args): string
    {
        $hessian = new HessianDriver();
        $out = $hessian->encodeValue('2.0.2')
            . $hessian->encodeValue($service)
            . $hessian->encodeValue($version)
            . $hessian->encodeValue($method)
            . $hessian->encodeValue($typesDesc);
        foreach ($args as $arg) {
            $out .= $hessian->encodeValue($arg);
        }
        // attachments 必须是 map,Dubbo provider 不接受空 list
        return $out . $hessian->encodeMap([]);
    }

    /**
     * @param string $body
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function parseResponse(string $body)
    {
        if ($body === '') {
            throw new InvalidArgumentException('dubbo: empty response body');
        }
        $flag = ord($body[0]);
        $hessian = new HessianDriver();
        if ($flag === 1 || $flag === 4) {
            $payload = substr($body, 1);
            $offset = 0;
            $value = $hessian->decodeAt($payload, $offset);
            if ($flag === 4) {
                // flag 4 在返回值后附带 attachments map;显式解码以校验报文完整
                $attachments = $hessian->decodeAt($payload, $offset);
                if (!is_array($attachments)) {
                    throw new InvalidArgumentException('dubbo: flag 4 attachments must be a map');
                }
            }
            return $value;
        }
        if ($flag === 2 || $flag === 5) {
            return null;
        }
        if ($flag === 0 || $flag === 3) {
            $value = $hessian->decode(substr($body, 1));
            $message = is_array($value) ? ($value['detailMessage'] ?? $value['message'] ?? null) : null;
            if (!is_scalar($message) && $message !== null) {
                $message = json_encode($message, JSON_UNESCAPED_SLASHES);
            }
            throw new InvalidArgumentException('dubbo exception: ' . ($message !== null && $message !== '' ? (string)$message : json_encode($value, JSON_UNESCAPED_SLASHES)));
        }
        throw new InvalidArgumentException('dubbo: unknown response flag ' . $flag);
    }

    /**
     * 逗号分隔的 Java 描述符拼接为 body 里的单个字符串
     */
    protected function paramTypesDesc(string $raw): string
    {
        $parts = array_filter(array_map('trim', explode(',', $raw)), static function (string $part): bool {
            return $part !== '';
        });
        return implode('', $parts);
    }

    /**
     * 统计 Java 类型描述符个数:`Ljava/lang/String;I[B` → 3
     * @throws InvalidArgumentException
     */
    public function countDescriptors(string $desc): int
    {
        $length = strlen($desc);
        $offset = 0;
        $count = 0;
        while ($offset < $length) {
            while ($offset < $length && $desc[$offset] === '[') {
                $offset++;
            }
            if ($offset >= $length) {
                throw new InvalidArgumentException('invalid Java type descriptor `' . $desc . '`');
            }
            $char = $desc[$offset];
            if ($char === 'L') {
                $end = strpos($desc, ';', $offset);
                if ($end === false) {
                    throw new InvalidArgumentException('invalid Java type descriptor `' . $desc . '`');
                }
                $offset = $end + 1;
            } elseif (strpos('ZBCSIJFD', $char) !== false) {
                $offset++;
            } else {
                throw new InvalidArgumentException('invalid Java type descriptor `' . $desc . '` at byte ' . $offset);
            }
            $count++;
        }
        return $count;
    }

    /**
     * @return array ['ok'=>true,'value'=>mixed,'bytes'=>int] 或 ['ok'=>false,...]
     */
    protected function tcpExchange(string $address, string $frame, float $timeout, int $maxBytes): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('tcp://' . $address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            $message = trim($errstr) !== '' ? $errstr . ' (' . $errno . ')' : 'connect failed (' . $errno . ')';
            return ['ok' => false, 'error' => 'dubbo tcp ' . $message];
        }
        stream_set_timeout($socket, (int)max(1, (int)ceil($timeout)));
        $written = 0;
        while ($written < strlen($frame)) {
            $bytes = @fwrite($socket, substr($frame, $written));
            if ($bytes === false || $bytes === 0) {
                fclose($socket);
                return ['ok' => false, 'error' => 'dubbo write failed'];
            }
            $written += $bytes;
        }
        $deadline = microtime(true) + $timeout;
        $header = $this->readExact($socket, 16, $deadline);
        if ($header['error'] !== '') {
            fclose($socket);
            return ['ok' => false, 'error' => $header['error']];
        }
        $head = $header['data'];
        if (substr($head, 0, 2) !== self::MAGIC) {
            fclose($socket);
            return ['ok' => false, 'error' => 'dubbo: invalid response magic'];
        }
        if ((ord($head[2]) & 0x80) !== 0) {
            fclose($socket);
            return ['ok' => false, 'error' => 'dubbo: expected a response frame'];
        }
        $serialization = ord($head[2]) & 0x1f;
        if ($serialization !== self::SERIALIZATION_HESSIAN2) {
            fclose($socket);
            return ['ok' => false, 'error' => 'dubbo: unsupported response serialization id ' . $serialization];
        }
        $length = unpack('N', substr($head, 12, 4))[1];
        if ($length > $maxBytes) {
            fclose($socket);
            return ['ok' => false, 'error' => 'dubbo response exceeds max_bytes'];
        }
        $body = $this->readExact($socket, $length, $deadline);
        fclose($socket);
        if ($body['error'] !== '') {
            return ['ok' => false, 'error' => $body['error']];
        }
        $status = ord($head[3]);
        if ($status !== self::STATUS_OK) {
            return ['ok' => false, 'error' => 'dubbo status ' . $status, 'meta' => ['status' => $status]];
        }
        try {
            $value = $this->parseResponse($body['data']);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => true, 'value' => $value, 'bytes' => $length];
    }

    /**
     * @param resource $socket
     * @return array ['data'=>string,'error'=>string]
     */
    protected function readExact($socket, int $length, float $deadline): array
    {
        $data = '';
        while (strlen($data) < $length) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return ['data' => $data, 'error' => 'dubbo read timed out'];
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int)$left, (int)(($left - floor($left)) * 1000000));
            if ($ready !== 1) {
                return ['data' => $data, 'error' => 'dubbo read timed out'];
            }
            $chunk = @fread($socket, $length - strlen($data));
            if ($chunk === false) {
                return ['data' => $data, 'error' => 'dubbo read failed'];
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    return ['data' => $data, 'error' => 'dubbo response truncated'];
                }
                continue;
            }
            $data .= $chunk;
        }
        return ['data' => $data, 'error' => ''];
    }
}
