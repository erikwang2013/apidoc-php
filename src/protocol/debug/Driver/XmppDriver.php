<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\Hex;
use erikwang2013\apidoc\protocol\debug\Support\Socket;

/**
 * XMPP 一元执行器:连接强制 STARTTLS(证书校验到 JID 域名)→ SASL PLAIN → bind →
 * 可选发送一条 message → 收集响应。凭据只从字段读取,错误文本中的密码会被替换。
 * 目标只接受 xmpp://(与 Rust 一致);不把 tcp:// 当作别名。
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class XmppDriver implements DebugDriverInterface
{
    const MAX_FRAMES = 1000;
    const MAX_DEPTH = 32;
    const STREAM_NS = 'http://etherx.jabber.org/streams';
    const TLS_NS = 'urn:ietf:params:xml:ns:xmpp-tls';
    const SASL_NS = 'urn:ietf:params:xml:ns:xmpp-sasl';
    const BIND_NS = 'urn:ietf:params:xml:ns:xmpp-bind';
    const SESSION_NS = 'urn:ietf:params:xml:ns:xmpp-session';
    const CLIENT_NS = 'jabber:client';
    const STANZAS_NS = 'urn:ietf:params:xml:ns:xmpp-stanzas';

    /**
     * @var string 增量 XML 缓冲区
     */
    protected $buffer = '';

    /**
     * @var bool 服务端是否已关闭 stream
     */
    protected $streamClosed = false;

    /**
     * @var array DebugProxy 注入的 protocols.debug
     */
    protected $debugConfig = [];

    /**
     * @var string 服务端流 XML 前缀
     */
    protected $streamPrefix = 'stream';

    public static function available(): bool
    {
        return extension_loaded('openssl')
            && function_exists('stream_socket_enable_crypto')
            && class_exists(DOMDocument::class);
    }

    public static function unavailableReason(): string
    {
        return 'xmpp driver requires the openssl and dom extensions';
    }

    public function name(): string
    {
        return 'xmpp';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'jid', 'in' => 'meta', 'type' => 'string', 'require' => true, 'desc' => 'user@domain/resource'],
            ['name' => 'password', 'in' => 'meta', 'type' => 'string', 'require' => true],
            ['name' => 'to', 'in' => 'meta', 'type' => 'string', 'require' => false, 'desc' => 'Recipient JID; required when sending'],
            ['name' => 'type', 'in' => 'meta', 'type' => 'string', 'require' => false, 'default' => 'chat', 'desc' => 'chat or groupchat'],
            ['name' => 'messages', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 1],
            ['name' => 'duration_ms', 'in' => 'meta', 'type' => 'number', 'require' => false, 'default' => 2000],
            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'require' => false],
        ];
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        $this->buffer = '';
        $this->streamClosed = false;
        $this->streamPrefix = 'stream';
        $this->debugConfig = is_array($context['debugConfig'] ?? null) ? $context['debugConfig'] : [];
        if (!self::available()) {
            return ['ok' => false, 'error' => self::unavailableReason()];
        }
        $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
        $redact = function (string $message) use ($password): string {
            return $password !== '' ? str_replace($password, '***', $message) : $message;
        };
        foreach (['jid', 'password', 'to', 'type'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                return ['ok' => false, 'error' => $key . ' must be a string'];
            }
        }
        $jidRaw = trim((string)($input['jid'] ?? ''));
        if ($jidRaw === '') {
            return ['ok' => false, 'error' => 'xmpp requires opts.jid'];
        }
        if (strlen($jidRaw) > 1024) {
            return ['ok' => false, 'error' => 'xmpp jid is too long'];
        }
        $jid = $this->parseJid($jidRaw);
        if ($jid === null) {
            return ['ok' => false, 'error' => 'invalid xmpp jid'];
        }
        if ($password === '') {
            return ['ok' => false, 'error' => 'xmpp requires opts.password'];
        }
        if (strlen($password) > 1024) {
            return ['ok' => false, 'error' => 'xmpp password is too long'];
        }
        $type = strtolower(trim((string)($input['type'] ?? 'chat')));
        if ($type === '') {
            $type = 'chat';
        }
        if (!in_array($type, ['chat', 'groupchat'], true)) {
            return ['ok' => false, 'error' => 'invalid xmpp message type `' . $type . '`; use chat or groupchat'];
        }
        $to = trim((string)($input['to'] ?? ''));
        if ($to !== '' && (strlen($to) > 1024 || $this->parseJid($to) === null)) {
            return ['ok' => false, 'error' => 'invalid xmpp recipient jid'];
        }
        $hasBody = isset($input['payload']) && (string)$input['payload'] !== '';
        try {
            $body = $hasBody ? Hex::decode((string)$input['payload'], (string)($input['encoding'] ?? 'text')) : '';
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        if (strlen($body) > $maxBytes) {
            return ['ok' => false, 'error' => 'request body exceeds max_bytes (' . $maxBytes . ')'];
        }
        if ($hasBody && preg_match('//u', $body) !== 1) {
            return ['ok' => false, 'error' => 'xmpp body must be valid utf8'];
        }
        if ($hasBody && $to === '') {
            return ['ok' => false, 'error' => 'xmpp send requires opts.to'];
        }
        $messagesRaw = $input['messages'] ?? 1;
        $want = is_numeric($messagesRaw) ? (int)$messagesRaw : 1;
        $want = max(1, min(self::MAX_FRAMES, $want));
        $timeout = (float)($context['timeout'] ?? 5);
        if ($timeout <= 0) {
            $timeout = 5.0;
        }
        $durationRaw = $input['duration_ms'] ?? 2000;
        $durationMs = is_numeric($durationRaw) ? (int)$durationRaw : 2000;
        $durationMs = max(1, min((int)round($timeout * 1000), $durationMs));

        $targetRaw = trim((string)($context['target'] ?? ''));
        if ($targetRaw === '') {
            $target = ['scheme' => 'xmpp', 'host' => $jid['domain'], 'port' => 5222, 'path' => '', 'original' => ''];
        } else {
            try {
                $target = DebugTarget::parse($targetRaw);
            } catch (InvalidArgumentException $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
            $scheme = (string)$target['scheme'];
            if ($scheme === 'xmpps') {
                return ['ok' => false, 'error' => 'xmpps (direct TLS) is not supported; use xmpp:// which upgrades via STARTTLS'];
            }
            if ($scheme !== 'xmpp') {
                return ['ok' => false, 'error' => 'xmpp driver requires an xmpp:// target'];
            }
            if (($target['path'] ?? '') !== '' && $target['path'] !== '/') {
                return ['ok' => false, 'error' => 'invalid xmpp target; use xmpp://host[:port]'];
            }
            if (strpos((string)$target['original'], '?') !== false) {
                return ['ok' => false, 'error' => 'invalid xmpp target; use xmpp://host[:port]'];
            }
        }

        $deadline = microtime(true) + $timeout;
        try {
            $socket = Socket::connect($target, $context, $timeout);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $redact('xmpp connect failed: ' . $e->getMessage())];
        }
        try {
            $this->connectOnline($socket, $jid, $password, $deadline, $maxBytes);
            if ($hasBody) {
                $id = 'apidoc-' . bin2hex(random_bytes(6));
                $this->write(
                    $socket,
                    '<message to="' . $this->xml($to) . '" type="' . $this->xml($type) . '" id="' . $id . '">'
                    . '<body xml:lang="en">' . $this->xml($body) . '</body></message>'
                );
            }
            $messages = $this->collectMessages($socket, $want, $durationMs, $deadline, $maxBytes);
            try {
                $this->write($socket, '</stream:stream>');
            } catch (RuntimeException $e) {
                // 关闭是尽力而为
            }
            $json = json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                return ['ok' => false, 'error' => 'xmpp response could not be encoded as JSON'];
            }
            return [
                'ok' => true,
                'status' => 0,
                'headers' => ['x-messages' => (string)count($messages)],
                'body' => $json,
                'encoding' => 'text',
                'meta' => [
                    'durationMs' => (int)round((microtime(true) - $start) * 1000),
                    'messages' => count($messages),
                ],
            ];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $redact($e->getMessage())];
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * @param array{local:string,domain:string,resource:string} $jid
     */
    protected function connectOnline($socket, array $jid, string $password, float $deadline, int $maxBytes): void
    {
        $this->writeStreamHeader($socket, $jid['domain']);
        $features = $this->waitForFeatures($socket, $deadline, $maxBytes);
        if ($this->findElement($features, self::TLS_NS, 'starttls') === null) {
            throw new RuntimeException('xmpp server does not offer STARTTLS');
        }
        $this->write($socket, '<starttls xmlns="' . self::TLS_NS . '"/>');
        $reply = $this->waitForStanza($socket, [self::TLS_NS], ['proceed', 'failure'], $deadline, $maxBytes);
        if ($reply->documentElement->localName !== 'proceed') {
            throw new RuntimeException('xmpp STARTTLS was refused by the server');
        }
        $this->enableTls($socket, $jid['domain'], $deadline);

        $this->writeStreamHeader($socket, $jid['domain']);
        $features = $this->waitForFeatures($socket, $deadline, $maxBytes);
        $offered = false;
        foreach ($features->getElementsByTagNameNS(self::SASL_NS, 'mechanism') as $mechanism) {
            if (trim($mechanism->textContent) === 'PLAIN') {
                $offered = true;
            }
        }
        if (!$offered) {
            throw new RuntimeException('xmpp server does not offer SASL PLAIN');
        }
        $auth = base64_encode("\0" . $jid['local'] . "\0" . $password);
        $this->write($socket, '<auth xmlns="' . self::SASL_NS . '" mechanism="PLAIN">' . $auth . '</auth>');
        $reply = $this->waitForStanza($socket, [self::SASL_NS], ['success', 'failure'], $deadline, $maxBytes);
        if ($reply->documentElement->localName !== 'success') {
            $text = $this->elementText($reply, self::STANZAS_NS, 'text');
            throw new RuntimeException('xmpp SASL authentication failed' . ($text !== '' ? ': ' . $text : ''));
        }

        $this->writeStreamHeader($socket, $jid['domain']);
        $features = $this->waitForFeatures($socket, $deadline, $maxBytes);
        $this->write(
            $socket,
            '<iq type="set" id="bind-1"><bind xmlns="' . self::BIND_NS . '"/></iq>'
        );
        $result = $this->waitForIqResult($socket, 'bind-1', $deadline, $maxBytes);
        $jidElement = $result->getElementsByTagNameNS(self::BIND_NS, 'jid')->item(0);
        if (!$jidElement instanceof DOMElement || trim($jidElement->textContent) === '') {
            throw new RuntimeException('xmpp bind did not return a jid');
        }
        if ($this->findElement($features, self::SESSION_NS, 'session') !== null) {
            $this->write(
                $socket,
                '<iq type="set" id="session-1"><session xmlns="' . self::SESSION_NS . '"/></iq>'
            );
            $this->waitForIqResult($socket, 'session-1', $deadline, $maxBytes);
        }
    }

    protected function writeStreamHeader($socket, string $domain): void
    {
        $this->write(
            $socket,
            '<?xml version="1.0"?>'
            . '<stream:stream to="' . $this->xml($domain) . '" xmlns="' . self::CLIENT_NS . '"'
            . ' xmlns:stream="' . self::STREAM_NS . '" version="1.0">'
        );
    }

    protected function enableTls($socket, string $domain, float $deadline): void
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new RuntimeException('xmpp STARTTLS handshake timed out');
        }
        stream_context_set_option($socket, 'ssl', 'verify_peer', true);
        stream_context_set_option($socket, 'ssl', 'verify_peer_name', true);
        stream_context_set_option($socket, 'ssl', 'peer_name', $domain);
        stream_context_set_option($socket, 'ssl', 'SNI_enabled', true);
        $caFile = $this->tlsCaFile();
        if ($caFile !== '') {
            stream_context_set_option($socket, 'ssl', 'cafile', $caFile);
        }
        stream_set_timeout($socket, max(1, (int)ceil($left)));
        $result = @stream_socket_enable_crypto($socket, true, static::tlsCryptoMethod());
        if ($result !== true) {
            throw new RuntimeException('xmpp STARTTLS handshake failed');
        }
        $this->buffer = '';
        $this->streamClosed = false;
    }

    /**
     * TLS 1.2 是下限,与 Rust/rustls 参考实现一致
     */
    protected static function tlsCryptoMethod(): int
    {
        return STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }

    /**
     * 仅允许服务端配置的 CA 文件,客户端不能指定
     */
    protected function tlsCaFile(): string
    {
        $path = $this->debugConfig['tls_ca_file'] ?? '';
        if (!is_string($path) || $path === '') {
            return '';
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('xmpp tls_ca_file is not readable');
        }
        return $path;
    }

    /**
     * @return array<int,array{from:string,body:string}>
     */
    protected function collectMessages($socket, int $want, int $durationMs, float $deadline, int $maxBytes): array
    {
        $until = min($deadline, microtime(true) + $durationMs / 1000);
        $messages = [];
        $total = 0;
        while (count($messages) < $want && microtime(true) < $until) {
            try {
                $raw = $this->nextStanza($socket, $until, $maxBytes);
            } catch (RuntimeException $e) {
                if (strpos($e->getMessage(), 'timed out') !== false) {
                    break;
                }
                throw $e;
            }
            $doc = $this->parseXml($raw);
            $root = $doc->documentElement;
            if ($root->localName === 'error' && $root->namespaceURI === self::STREAM_NS) {
                throw new RuntimeException('xmpp stream error: ' . trim($root->textContent));
            }
            if ($root->localName !== 'message') {
                continue;
            }
            $bodyElement = $this->selectBody($doc);
            if ($bodyElement === null) {
                continue;
            }
            $text = $bodyElement->textContent;
            $total += strlen($text);
            if ($total > $maxBytes) {
                throw new RuntimeException('xmpp messages exceed max_bytes (' . $maxBytes . ')');
            }
            $messages[] = [
                'from' => $root->getAttribute('from'),
                'body' => $text,
            ];
        }
        return $messages;
    }

    /**
     * 与 Rust get_best_body(["en"]) 对齐:优先英文,其次无语言标记;其他语言跳过
     */
    protected function selectBody(DOMDocument $doc): ?DOMElement
    {
        $fallback = null;
        foreach ($doc->getElementsByTagNameNS(self::CLIENT_NS, 'body') as $body) {
            if (!$body instanceof DOMElement) {
                continue;
            }
            $lang = strtolower($body->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang'));
            if ($lang === 'en' || strpos($lang, 'en-') === 0) {
                return $body;
            }
            if ($lang === '' && $fallback === null) {
                $fallback = $body;
            }
        }
        return $fallback;
    }

    protected function waitForFeatures($socket, float $deadline, int $maxBytes): DOMDocument
    {
        while (true) {
            $raw = $this->nextStanza($socket, $deadline, $maxBytes);
            $doc = $this->parseXml($raw);
            $root = $doc->documentElement;
            if ($root->localName === 'features' && $root->namespaceURI === self::STREAM_NS) {
                return $doc;
            }
            if ($root->localName === 'error' && $root->namespaceURI === self::STREAM_NS) {
                throw new RuntimeException('xmpp stream error: ' . trim($root->textContent));
            }
            if ($root->localName === 'failure') {
                throw new RuntimeException('xmpp connection refused by the server');
            }
        }
    }

    /**
     * @param array<int,string> $namespaces
     * @param array<int,string> $names
     */
    protected function waitForStanza($socket, array $namespaces, array $names, float $deadline, int $maxBytes): DOMDocument
    {
        while (true) {
            $doc = $this->parseXml($this->nextStanza($socket, $deadline, $maxBytes));
            $root = $doc->documentElement;
            if (in_array($root->namespaceURI, $namespaces, true) && in_array($root->localName, $names, true)) {
                return $doc;
            }
            if ($root->localName === 'error' && $root->namespaceURI === self::STREAM_NS) {
                throw new RuntimeException('xmpp stream error: ' . trim($root->textContent));
            }
        }
    }

    protected function waitForIqResult($socket, string $id, float $deadline, int $maxBytes): DOMDocument
    {
        while (true) {
            $doc = $this->parseXml($this->nextStanza($socket, $deadline, $maxBytes));
            $root = $doc->documentElement;
            if ($root->localName === 'error' && $root->namespaceURI === self::STREAM_NS) {
                throw new RuntimeException('xmpp stream error: ' . trim($root->textContent));
            }
            if ($root->localName !== 'iq' || $root->getAttribute('id') !== $id) {
                continue;
            }
            $type = $root->getAttribute('type');
            if ($type === 'result') {
                return $doc;
            }
            if ($type === 'error') {
                $text = $this->elementText($doc, self::STANZAS_NS, 'text');
                throw new RuntimeException('xmpp iq failed' . ($text !== '' ? ': ' . $text : ''));
            }
        }
    }

    protected function findElement(DOMDocument $doc, string $namespace, string $name): ?DOMElement
    {
        $element = $doc->getElementsByTagNameNS($namespace, $name)->item(0);
        return $element instanceof DOMElement ? $element : null;
    }

    protected function elementText(DOMDocument $doc, string $namespace, string $name): string
    {
        $element = $doc->getElementsByTagNameNS($namespace, $name)->item(0);
        return $element instanceof DOMElement ? trim($element->textContent) : '';
    }

    /**
     * 读取缓冲中的下一个完整 stanza;stream 头/XML 声明会被跳过
     */
    protected function nextStanza($socket, float $deadline, int $maxBytes): string
    {
        while (true) {
            if ($this->streamClosed) {
                throw new RuntimeException('xmpp stream closed by server');
            }
            $this->buffer = ltrim($this->buffer);
            if ($this->buffer === '') {
                $this->readMore($socket, $deadline, $maxBytes);
                continue;
            }
            if (strncmp($this->buffer, '<?xml', 5) === 0) {
                $end = strpos($this->buffer, '?>');
                if ($end === false) {
                    $this->readMore($socket, $deadline, $maxBytes);
                    continue;
                }
                $this->buffer = substr($this->buffer, $end + 2);
                continue;
            }
            // 流头/流尾的 XML 前缀任意,按命名空间声明识别
            if (preg_match('#^<(?<prefix>[A-Za-z_][\w.-]*):stream\b#i', $this->buffer, $matches) === 1) {
                $end = $this->tagEnd($this->buffer, 0);
                if ($end === null) {
                    $this->readMore($socket, $deadline, $maxBytes);
                    continue;
                }
                $prefix = (string)$matches['prefix'];
                $declaration = '#\bxmlns:' . preg_quote($prefix, '#') . '\s*=\s*(["\'])'
                    . preg_quote(self::STREAM_NS, '#') . '\1#';
                if (preg_match($declaration, substr($this->buffer, 0, $end)) === 1) {
                    $this->streamPrefix = $prefix;
                    $this->buffer = substr($this->buffer, $end);
                    continue;
                }
            }
            if (preg_match('#^</(?:[A-Za-z_][\w.-]*:)?stream\s*>#i', $this->buffer, $matches) === 1) {
                $this->buffer = substr($this->buffer, strlen($matches[0]));
                $this->streamClosed = true;
                continue;
            }
            if ($this->buffer[0] !== '<') {
                $next = strpos($this->buffer, '<');
                $this->buffer = $next === false ? '' : substr($this->buffer, $next);
                continue;
            }
            $end = $this->scanElement($this->buffer);
            if ($end === null) {
                $this->readMore($socket, $deadline, $maxBytes);
                continue;
            }
            $stanza = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end);
            return $stanza;
        }
    }

    protected function readMore($socket, float $deadline, int $maxBytes): void
    {
        $room = $maxBytes - strlen($this->buffer);
        if ($room <= 0) {
            throw new RuntimeException('xmpp stanza exceeds max_bytes (' . $maxBytes . ')');
        }
        $chunk = Socket::readSome($socket, $deadline, min(8192, $room));
        if ($chunk === null) {
            throw new RuntimeException('xmpp read timed out');
        }
        if ($chunk === '') {
            throw new RuntimeException('xmpp connection closed');
        }
        $this->buffer .= $chunk;
    }

    /**
     * 返回首个完整顶层元素的字节长度;不完整返回 null
     */
    protected function scanElement(string $data): ?int
    {
        $length = strlen($data);
        $depth = 0;
        $i = 0;
        while ($i < $length) {
            if ($data[$i] !== '<') {
                $i++;
                continue;
            }
            if (substr($data, $i, 4) === '<!--') {
                $end = strpos($data, '-->', $i + 4);
                if ($end === false) {
                    return null;
                }
                $i = $end + 3;
                continue;
            }
            if (substr($data, $i, 9) === '<![CDATA[') {
                $end = strpos($data, ']]>', $i + 9);
                if ($end === false) {
                    return null;
                }
                $i = $end + 3;
                continue;
            }
            if (substr($data, $i, 2) === '<?') {
                $end = strpos($data, '?>', $i + 2);
                if ($end === false) {
                    return null;
                }
                $i = $end + 2;
                continue;
            }
            if (substr($data, $i, 2) === '<!') {
                throw new RuntimeException('xmpp DOCTYPE/entity declarations are not allowed');
            }
            $end = $this->tagEnd($data, $i);
            if ($end === null) {
                return null;
            }
            $closing = $data[$i + 1] === '/';
            $selfClosing = $data[$end - 2] === '/';
            if ($closing) {
                $depth--;
                if ($depth < 0) {
                    throw new RuntimeException('xmpp server sent malformed xml');
                }
            } elseif (!$selfClosing) {
                $depth++;
                if ($depth > self::MAX_DEPTH) {
                    throw new RuntimeException('xmpp stanza is too deeply nested');
                }
            }
            if ($depth === 0) {
                return $end;
            }
            $i = $end;
        }
        return null;
    }

    /**
     * 找到标签结束的 '>' 之后的位置,跳过引号内的内容
     */
    protected function tagEnd(string $data, int $offset): ?int
    {
        $length = strlen($data);
        $quote = null;
        for ($i = $offset + 1; $i < $length; $i++) {
            $char = $data[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return $i + 1;
            }
        }
        return null;
    }

    protected function parseXml(string $stanza): DOMDocument
    {
        // stream 前缀的命名空间在流头上声明,补一个包裹根以完整解析
        $wrapped = '<root xmlns="' . self::CLIENT_NS . '" xmlns:stream="' . self::STREAM_NS . '"';
        if ($this->streamPrefix !== 'stream') {
            $wrapped .= ' xmlns:' . $this->streamPrefix . '="' . self::STREAM_NS . '"';
        }
        $wrapped .= '>' . $stanza . '</root>';
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($wrapped, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new RuntimeException('xmpp server sent invalid xml');
        }
        $root = $doc->documentElement;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $wrapper = new DOMDocument();
                $imported = $wrapper->importNode($child, true);
                $wrapper->appendChild($imported);
                return $wrapper;
            }
        }
        throw new RuntimeException('xmpp server sent an empty stanza');
    }

    /**
     * @return array{local:string,domain:string,resource:string}|null
     */
    protected function parseJid(string $jid): ?array
    {
        if (preg_match('/[\s\x00-\x1f]/', $jid) === 1) {
            return null;
        }
        $at = strpos($jid, '@');
        if ($at === false) {
            return null;
        }
        $local = substr($jid, 0, $at);
        $rest = substr($jid, $at + 1);
        $slash = strpos($rest, '/');
        $domain = $slash === false ? $rest : substr($rest, 0, $slash);
        $resource = $slash === false ? '' : substr($rest, $slash + 1);
        if ($local === '' || $domain === '' || strpos($domain, '@') !== false) {
            return null;
        }
        return ['local' => $local, 'domain' => $domain, 'resource' => $resource];
    }

    protected function xml(string $value): string
    {
        return (string)htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    protected function write($socket, string $data): void
    {
        if (!Socket::writeAll($socket, $data)) {
            throw new RuntimeException('xmpp write failed');
        }
    }
}
