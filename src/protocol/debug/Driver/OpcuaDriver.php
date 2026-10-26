<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use RuntimeException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Input;
use erikwang2013\apidoc\protocol\debug\Support\OpcuaReader;
use erikwang2013\apidoc\protocol\debug\Support\Socket;
use erikwang2013\apidoc\protocol\debug\Support\TargetGuard;

/**
 * OPC UA Binary 最小客户端:SecurityPolicy None 的浏览/读/写
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class OpcuaDriver implements DebugDriverInterface
{
    protected const NONE_POLICY = 'http://opcfoundation.org/UA/SecurityPolicy#None';
    protected const ANONYMOUS_TOKEN = 321;
    protected const USERNAME_TOKEN = 324;
    protected const OPEN_CHANNEL = 446;
    protected const CREATE_SESSION = 461;
    protected const ACTIVATE_SESSION = 467;
    protected const BROWSE = 527;
    protected const READ = 631;
    protected const WRITE = 673;

    public function name(): string
    {
        return 'opcua';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'operation', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'read', 'desc' => 'read, browse or write'],
            ['name' => 'node_id', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'i=85'],
            ['name' => 'attribute', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 13],
            ['name' => 'username', 'in' => 'meta', 'type' => 'string', 'require' => false],
            ['name' => 'password', 'in' => 'meta', 'type' => 'string', 'require' => false],
            ['name' => 'user_token_policy', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'username'],
            ['name' => 'value', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Required for write'],
        ];
    }

    public function send(array $input, array $context): array
    {
        $started = microtime(true);
        $socket = null;
        $password = null;
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
            if (!$this->targetMatches($target)) {
                return ['ok' => false, 'error' => 'unsupported opcua target scheme `' . $target['scheme'] . '`'];
            }
            $operation = Input::string($input, 'operation', 'read');
            if (!in_array($operation, ['read', 'browse', 'write'], true)) {
                return ['ok' => false, 'error' => 'unsupported opcua operation `' . $operation . '`; use browse, read or write'];
            }
            if ($operation === 'write' && (!array_key_exists('value', $input) || $input['value'] === null)) {
                return ['ok' => false, 'error' => 'opcua `value` is required for write'];
            }
            $nodeId = Input::string($input, 'node_id', 'i=85');
            $attribute = Input::int($input, 'attribute', 13, 0, 0xFFFFFFFF);
            $username = array_key_exists('username', $input) && $input['username'] !== null
                ? Input::required($input, 'username')
                : null;
            $password = $username === null ? '' : Input::string($input, 'password', '');
            $tokenPolicy = Input::string($input, 'user_token_policy', 'username');
            $timeout = max(0.05, (float)($context['timeout'] ?? 5));
            $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
            $endpoint = 'opc.tcp://' . $target['host'] . ':' . $target['port'];
            $socket = Socket::connect($target, $context, $timeout);
            $deadline = microtime(true) + $timeout;
            $state = [
                'socket' => $socket,
                'deadline' => $deadline,
                'maxBytes' => $maxBytes,
                'channel' => 0,
                'token' => 0,
                'seq' => 1,
                'request' => 1,
                'auth' => "\x00\x00",
                'endpoint' => $endpoint,
            ];
            $this->sendBytes($state, $this->encodeHel($endpoint, max($maxBytes, 8192)));
            $ack = $this->readMessage($state);
            if ($ack['kind'] !== 'ACK') {
                return ['ok' => false, 'error' => 'opcua expected ACK, got ' . $ack['kind']];
            }
            $state = $this->openSecureChannel($state);
            $state = $this->createSession($state, $username, $password, $tokenPolicy);

            switch ($operation) {
                case 'read':
                    $value = $this->readValue($state, $nodeId, $attribute);
                    $body = ['value' => $value, 'status' => 'Good'];
                    break;
                case 'browse':
                    $body = ['references' => $this->browseNode($state, $nodeId), 'status' => 'Good'];
                    break;
                default:
                    $value = Input::required($input, 'value');
                    $this->writeValue($state, $nodeId, $attribute, $value);
                    $body = ['status' => 'Good'];
                    break;
            }
        } catch (InvalidArgumentException $e) {
            // 服务端可能在 ERR reason 里回显口令,不能让凭据进入响应
            $message = $password !== null && $password !== '' ? str_replace($password, '***', $e->getMessage()) : $e->getMessage();
            return ['ok' => false, 'error' => $message];
        } catch (RuntimeException $e) {
            $message = $password !== null && $password !== '' ? str_replace($password, '***', $e->getMessage()) : $e->getMessage();
            return ['ok' => false, 'error' => 'opcua connect failed: ' . $message];
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return ['ok' => false, 'error' => 'encode response failed'];
        }
        return [
            'ok' => true,
            'body' => $encoded,
            'encoding' => 'text',
            'meta' => [
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'bytes' => strlen($encoded),
                'opcuaStatus' => 'Good',
            ],
        ];
    }

    /**
     * 裸 host:port 视为 opc.tcp;显式 tcp:// 与 Rust 一样拒绝
     */
    protected function targetMatches(array $target): bool
    {
        if ($target['scheme'] === 'opc.tcp') {
            return true;
        }
        return $target['scheme'] === 'tcp' && strpos((string)($target['original'] ?? ''), '://') === false;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function openSecureChannel(array $state): array
    {
        $body = $this->putNodeId(self::OPEN_CHANNEL);
        $body .= $this->putRequestHeader("\x00\x00", 1);
        $body .= pack('V', 0) . pack('V', 0) . pack('V', 1);
        $body .= $this->putBytes(null);
        $body .= pack('V', 3600000);
        $seq = $state['seq']++;
        $request = $state['request']++;
        $this->sendBytes($state, $this->encodeOpn($state['channel'], self::NONE_POLICY, $seq, $request, $body));
        $response = $this->readMessage($state);
        if ($response['kind'] !== 'OPN') {
            throw new InvalidArgumentException('opcua expected OPN, got ' . $response['kind']);
        }
        if ($response['policy'] !== null && $response['policy'] !== self::NONE_POLICY) {
            throw new InvalidArgumentException(
                'opcua server selected security policy `' . $response['policy'] . '`; only None is supported'
            );
        }
        $state['channel'] = $response['channel'];
        $reader = new OpcuaReader($response['body']);
        $reader->skipNodeId();
        $reader->checkResponseHeader();
        $reader->u32(); // serverVersion
        $reader->u32(); // securityToken.channelId
        $state['token'] = $reader->u32();
        return $state;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function createSession(array $state, ?string $username, string $password, string $tokenPolicy): array
    {
        $body = $this->putNodeId(self::CREATE_SESSION);
        $body .= $this->putRequestHeader($state['auth'], 2);
        $body .= $this->putString('urn:apidoc:debug');
        $body .= $this->putString('urn:apidoc');
        $body .= $this->putLocalizedText('apidoc-debug');
        $body .= pack('V', 0); // ApplicationType = Client
        $body .= $this->putBytes(null); // gatewayServerUri
        $body .= $this->putBytes(null); // discoveryProfileUri
        $body .= pack('V', 0xFFFFFFFF); // discoveryUrls -1
        $body .= $this->putBytes(null); // serverUri
        $body .= $this->putString($state['endpoint']);
        $body .= $this->putString('apidoc-debug');
        $body .= $this->putBytes(null); // clientNonce
        $body .= $this->putBytes(null); // clientCertificate
        $body .= pack('e', 600000.0);
        $body .= pack('V', 0); // maxResponseMessageSize
        $response = $this->roundtrip($state, 'MSG', $body);
        $reader = new OpcuaReader($response);
        $reader->skipNodeId();
        $reader->checkResponseHeader();
        $reader->skipNodeId(); // sessionId
        $start = $reader->pos;
        $reader->skipNodeId(); // authenticationToken
        $state['auth'] = substr($reader->data, $start, $reader->pos - $start);
        return $this->activateSession($state, $username, $password, $tokenPolicy);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function activateSession(array $state, ?string $username, string $password, string $tokenPolicy): array
    {
        $body = $this->putNodeId(self::ACTIVATE_SESSION);
        $body .= $this->putRequestHeader($state['auth'], 3);
        $body .= $this->putBytes(null); // clientSignature.algorithm
        $body .= $this->putBytes(null); // clientSignature.signature
        $body .= pack('V', 0xFFFFFFFF); // clientSoftwareCertificates -1
        $body .= pack('V', 0xFFFFFFFF); // localeIds -1
        if ($username !== null) {
            $tokenBody = $this->putString($tokenPolicy)
                . $this->putString($username)
                . $this->putBytes($password)
                . $this->putBytes(null);
            $body .= $this->putExtensionObject(self::USERNAME_TOKEN, $tokenBody);
        } else {
            $body .= $this->putExtensionObject(self::ANONYMOUS_TOKEN, $this->putString('anonymous'));
        }
        $body .= $this->putBytes(null); // userTokenSignature.algorithm
        $body .= $this->putBytes(null); // userTokenSignature.signature
        $response = $this->roundtrip($state, 'MSG', $body);
        $reader = new OpcuaReader($response);
        $reader->skipNodeId();
        $reader->checkResponseHeader();
        return $state;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function readValue(array $state, string $nodeId, int $attribute): mixed
    {
        $body = $this->putNodeId(self::READ);
        $body .= $this->putRequestHeader($state['auth'], 4);
        $body .= pack('e', 0.0); // maxAge
        $body .= pack('V', 0); // timestampsToReturn = Source
        $body .= pack('V', 1); // nodesToRead count
        $body .= $this->putNodeIdString($nodeId);
        $body .= pack('V', $attribute);
        $body .= $this->putBytes(null); // indexRange
        $body .= pack('v', 0);
        $body .= $this->putBytes(null); // dataEncoding.name
        $response = $this->roundtrip($state, 'MSG', $body);
        $reader = new OpcuaReader($response);
        $reader->skipNodeId();
        $reader->checkResponseHeader();
        $count = $reader->i32();
        if ($count !== 1) {
            throw new InvalidArgumentException('opcua read returned ' . $count . ' results (expected 1)');
        }
        return $reader->decodeDataValue();
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function browseNode(array $state, string $nodeId): array
    {
        $body = $this->putNodeId(self::BROWSE);
        $body .= $this->putRequestHeader($state['auth'], 5);
        $body .= $this->putNodeId(0); // viewId null
        $body .= pack('P', 0); // timestamp
        $body .= pack('V', 0); // viewVersion
        $body .= pack('V', 0); // requestedMaxReferencesPerNode
        $body .= pack('V', 1); // nodesToBrowse count
        $body .= $this->putNodeIdString($nodeId);
        $body .= pack('V', 0); // browseDirection = Forward
        $body .= $this->putNodeId(0); // referenceTypeId = null
        $body .= "\x01"; // includeSubtypes
        $body .= pack('V', 0); // nodeClassMask
        $body .= pack('V', 0x3F); // resultMask
        $response = $this->roundtrip($state, 'MSG', $body);
        $reader = new OpcuaReader($response);
        $reader->skipNodeId();
        $reader->checkResponseHeader();
        $count = $reader->i32();
        if ($count !== 1) {
            throw new InvalidArgumentException('opcua browse returned ' . $count . ' results (expected 1)');
        }
        $status = $reader->u32();
        if ($status !== 0) {
            throw new InvalidArgumentException('opcua browse status ' . OpcuaReader::statusLabel($status));
        }
        $reader->byteString(); // continuationPoint
        $references = $reader->i32();
        if ($references < 0) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < min($references, 10000); $i++) {
            $reader->skipNodeId(); // referenceTypeId
            $reader->u8(); // isForward
            $target = $reader->decodeNodeId();
            $namespace = $reader->u16();
            $name = $reader->string() ?? '';
            $display = $reader->decodeLocalizedText();
            $nodeClass = $reader->u32();
            $reader->skipNodeId(); // typeDefinition
            $out[] = [
                'node_id' => $target,
                'browse_name' => 'ns=' . $namespace . ';' . $name,
                'display_name' => $display,
                'node_class' => $nodeClass,
            ];
        }
        return $out;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function writeValue(array $state, string $nodeId, int $attribute, string $value): void
    {
        $body = $this->putNodeId(self::WRITE);
        $body .= $this->putRequestHeader($state['auth'], 6);
        $body .= pack('V', 1); // nodesToWrite count
        $body .= $this->putNodeIdString($nodeId);
        $body .= pack('V', $attribute);
        $body .= $this->putBytes(null); // indexRange
        $body .= "\x01"; // DataValue mask: value only
        $body .= $this->encodeVariant($value);
        $response = $this->roundtrip($state, 'MSG', $body);
        $reader = new OpcuaReader($response);
        $reader->skipNodeId();
        $reader->checkResponseHeader();
        $count = $reader->i32();
        if ($count !== 1) {
            throw new InvalidArgumentException('opcua write returned ' . $count . ' results (expected 1)');
        }
        $status = $reader->u32();
        if ($status !== 0) {
            throw new InvalidArgumentException('opcua write status ' . OpcuaReader::statusLabel($status));
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function roundtrip(array $state, string $kind, string $body): string
    {
        $message = $this->encodeMsg(
            $kind,
            $state['channel'],
            $state['token'],
            $state['seq']++,
            $state['request']++,
            $body
        );
        $this->sendBytes($state, $message);
        $response = $this->readMessage($state);
        if ($response['kind'] !== $kind) {
            throw new InvalidArgumentException('opcua expected ' . $kind . ', got ' . $response['kind']);
        }
        return $response['body'];
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function sendBytes(array $state, string $bytes): void
    {
        if (!Socket::writeAll($state['socket'], $bytes)) {
            throw new InvalidArgumentException('opcua write failed');
        }
    }

    /**
     * @return array{kind:string,channel:int,policy:?string,body:string}
     * @throws InvalidArgumentException
     */
    protected function readMessage(array $state): array
    {
        try {
            $header = Socket::readExact($state['socket'], 8, $state['deadline'], 'opcua message');
            $kind = substr($header, 0, 3);
            if ($header[3] === 'C') {
                throw new InvalidArgumentException('opcua multi-chunk responses are not supported');
            }
            if ($header[3] !== 'F') {
                throw new InvalidArgumentException('opcua message was aborted by the server');
            }
            $size = unpack('V', substr($header, 4, 4))[1];
            if ($size < 8 || $size > $state['maxBytes']) {
                throw new InvalidArgumentException(
                    'opcua message size ' . $size . ' exceeds max_bytes (' . $state['maxBytes'] . ')'
                );
            }
            $rest = Socket::readExact($state['socket'], $size - 8, $state['deadline'], 'opcua message');
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'timed out') !== false) {
                throw new InvalidArgumentException('opcua read timed out');
            }
            throw new InvalidArgumentException('opcua read failed: ' . $e->getMessage());
        }
        if ($kind === 'ERR') {
            $reader = new OpcuaReader($rest);
            $status = 0;
            $reason = '';
            try {
                $status = $reader->u32();
                $reason = $reader->string() ?? '';
            } catch (InvalidArgumentException $e) {
                // 错误报文本身截断时仍报状态,保留服务端已给出的部分
            }
            throw new InvalidArgumentException('opcua error ' . OpcuaReader::statusLabel($status) . ' ' . $reason);
        }
        if ($kind === 'ACK') {
            return ['kind' => $kind, 'channel' => 0, 'policy' => null, 'body' => $rest];
        }
        $reader = new OpcuaReader($rest);
        $channel = $reader->u32();
        $policy = null;
        if ($kind === 'OPN') {
            $policy = $reader->string() ?? '';
            $reader->byteString(); // senderCertificate
            $reader->byteString(); // receiverCertificateThumbprint
        } else {
            $reader->u32(); // tokenId
        }
        $reader->u32(); // sequenceNumber
        $reader->u32(); // requestId
        return ['kind' => $kind, 'channel' => $channel, 'policy' => $policy, 'body' => $reader->rest()];
    }

    public function encodeHel(string $endpoint, int $maxMessage): string
    {
        $bodyLength = 5 * 4 + 4 + strlen($endpoint);
        return 'HELF'
            . pack('V', 8 + $bodyLength)
            . pack('V', 0)
            . pack('V', 65535)
            . pack('V', 65535)
            . pack('V', max($maxMessage, 8192))
            . pack('V', 1)
            . $this->putString($endpoint);
    }

    public function encodeOpn(int $channel, string $policy, int $seq, int $request, string $body): string
    {
        return 'OPNF'
            . pack('V', 32 + strlen($policy) + strlen($body))
            . pack('V', $channel)
            . $this->putString($policy)
            . $this->putBytes(null)
            . $this->putBytes(null)
            . pack('V', $seq)
            . pack('V', $request)
            . $body;
    }

    public function encodeMsg(string $kind, int $channel, int $token, int $seq, int $request, string $body): string
    {
        return $kind . 'F'
            . pack('V', 24 + strlen($body))
            . pack('V', $channel)
            . pack('V', $token)
            . pack('V', $seq)
            . pack('V', $request)
            . $body;
    }

    protected function putRequestHeader(string $auth, int $handle): string
    {
        return $auth
            . pack('P', 0) // timestamp
            . pack('V', $handle)
            . pack('V', 0) // returnDiagnostics
            . $this->putBytes(null) // auditEntryId
            . pack('V', 10000) // timeoutHint
            . $this->putExtensionObject(0, '');
    }

    protected function encodeVariant(string $value): string
    {
        if ($value === 'true' || $value === 'false') {
            return "\x01" . chr($value === 'true' ? 1 : 0);
        }
        // Rust 先 parse::<i64> 再 parse::<f64>;超出 i64 的整数按浮点发送
        if (preg_match('/^[+-]?\d+$/', $value) === 1 && is_int($value + 0)) {
            $integer = (int)$value;
            if ($integer >= -2147483648 && $integer <= 2147483647) {
                return "\x06" . pack('V', $integer & 0xFFFFFFFF);
            }
            return "\x08" . pack('P', $integer);
        }
        if (is_numeric($value) || in_array(strtolower($value), ['inf', '+inf', '-inf', 'infinity', '+infinity', '-infinity', 'nan'], true)) {
            return "\x0B" . pack('e', (float)$value);
        }
        return "\x0C" . $this->putString($value);
    }

    public function putNodeId(int $id): string
    {
        if ($id <= 0xFF) {
            return "\x00" . chr($id);
        }
        if ($id <= 0xFFFF) {
            return "\x01\x00" . pack('v', $id);
        }
        return "\x02" . pack('v', 0) . pack('V', $id);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function putNodeIdString(string $value): string
    {
        $namespace = 0;
        $rest = $value;
        if (strpos($value, 'ns=') === 0) {
            $split = strpos($value, ';');
            if ($split === false) {
                throw new InvalidArgumentException('node_id `ns=` must be followed by `;`');
            }
            $namespaceText = substr($value, 3, $split - 3);
            if (preg_match('/^\d+$/', $namespaceText) !== 1 || (int)$namespaceText > 0xFFFF) {
                throw new InvalidArgumentException('invalid node_id namespace');
            }
            $namespace = (int)$namespaceText;
            $rest = substr($value, $split + 1);
        }
        if (strpos($rest, 'i=') === 0) {
            $idText = substr($rest, 2);
            if (preg_match('/^\d+$/', $idText) !== 1 || (int)$idText > 0xFFFFFFFF) {
                throw new InvalidArgumentException('invalid numeric node_id');
            }
            $id = (int)$idText;
            if ($namespace === 0 && $id <= 0xFF) {
                return "\x00" . chr($id);
            }
            if ($namespace <= 0xFF && $id <= 0xFFFF) {
                return "\x01" . chr($namespace) . pack('v', $id);
            }
            return "\x02" . pack('v', $namespace) . pack('V', $id);
        }
        if (strpos($rest, 's=') === 0) {
            return "\x03" . pack('v', $namespace) . $this->putString(substr($rest, 2));
        }
        throw new InvalidArgumentException('node_id must look like `i=85`, `ns=2;i=5` or `s=name`');
    }

    protected function putLocalizedText(string $text): string
    {
        return "\x02" . $this->putString($text);
    }

    protected function putExtensionObject(int $typeId, string $body): string
    {
        $out = $this->putNodeId($typeId);
        if ($typeId === 0) {
            return $out . "\x00";
        }
        return $out . "\x01" . pack('V', strlen($body)) . $body;
    }

    protected function putString(string $value): string
    {
        return pack('V', strlen($value)) . $value;
    }

    protected function putBytes(?string $value): string
    {
        return $value === null ? pack('V', 0xFFFFFFFF) : pack('V', strlen($value)) . $value;
    }
}
