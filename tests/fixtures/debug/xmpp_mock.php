<?php
/**
 * XMPP 测试服务器:stream 头 -> STARTTLS(可关闭)-> SASL PLAIN -> bind/session ->
 * 可选发送 hello,并对客户端 message 回显 echo:<body>。
 * 密码固定 secret;失败时 failure 文本会带出提交的密码,用于验证驱动脱敏。
 * 用法: php xmpp_mock.php <host> <port> <cert> <key> [no-starttls]
 */
$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 19023);
$cert = $argv[3] ?? '';
$key = $argv[4] ?? '';
$noStartTls = ($argv[5] ?? '') === 'no-starttls';
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "{$errstr} ({$errno})\n");
    exit(1);
}

const STREAM_NS = 'http://etherx.jabber.org/streams';
const TLS_NS = 'urn:ietf:params:xml:ns:xmpp-tls';
const SASL_NS = 'urn:ietf:params:xml:ns:xmpp-sasl';
const BIND_NS = 'urn:ietf:params:xml:ns:xmpp-bind';
const SESSION_NS = 'urn:ietf:params:xml:ns:xmpp-session';
const STANZAS_NS = 'urn:ietf:params:xml:ns:xmpp-stanzas';

function writeAll($conn, string $data): void
{
    $written = 0;
    while ($written < strlen($data)) {
        $bytes = fwrite($conn, substr($data, $written));
        if ($bytes === false || $bytes === 0) {
            return;
        }
        $written += $bytes;
    }
}

function xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function streamHeader(): string
{
    return '<?xml version="1.0"?>'
        . '<stream:stream xmlns="jabber:client" xmlns:stream="' . STREAM_NS . '"'
        . ' id="mock" from="localhost" version="1.0">';
}

/**
 * 读取一个完整元素(客户端发送的 XML 都很小):开标签 + 对应闭标签
 */
function readElement($conn): ?string
{
    $open = '';
    while (strpos($open, '>') === false) {
        $char = fread($conn, 1);
        if ($char === false || $char === '') {
            return null;
        }
        $open .= $char;
    }
    $open = trim($open);
    if (strpos($open, '</') === 0 || substr($open, -2) === '/>') {
        return $open;
    }
    if (preg_match('#^<([A-Za-z0-9:_-]+)#', $open, $matches) !== 1) {
        return $open;
    }
    $name = $matches[1];
    $data = $open;
    while (strpos($data, '</' . $name . '>') === false) {
        $char = fread($conn, 1);
        if ($char === false || $char === '') {
            return null;
        }
        $data .= $char;
    }
    return $data;
}

function readStreamHeader($conn): bool
{
    $data = '';
    while (($start = strpos($data, '<stream:stream')) === false || strpos($data, '>', $start) === false) {
        $char = fread($conn, 1);
        if ($char === false || $char === '') {
            return false;
        }
        $data .= $char;
    }
    return true;
}

function attribute(string $xml, string $name): string
{
    return preg_match('#\b' . preg_quote($name, '#') . '="([^"]*)"#', $xml, $matches) === 1
        ? html_entity_decode($matches[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')
        : '';
}

function enableTls($conn, string $cert, string $key): bool
{
    stream_context_set_option($conn, 'ssl', 'local_cert', $cert);
    stream_context_set_option($conn, 'ssl', 'local_pk', $key);
    stream_context_set_option($conn, 'ssl', 'verify_peer', false);
    stream_context_set_option($conn, 'ssl', 'allow_self_signed', true);
    return @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
}

function handleConnection($conn, string $cert, string $key, bool $noStartTls): void
{
    if (!readStreamHeader($conn)) {
        return;
    }
    writeAll($conn, streamHeader());
    writeAll(
        $conn,
        '<stream:features>'
        . ($noStartTls ? '' : '<starttls xmlns="' . TLS_NS . '"><required/></starttls>')
        . '</stream:features>'
    );
    if ($noStartTls) {
        readElement($conn);
        return;
    }
    $startTls = readElement($conn);
    if ($startTls === null || strpos($startTls, 'starttls') === false) {
        return;
    }
    writeAll($conn, '<proceed xmlns="' . TLS_NS . '"/>');
    if (!enableTls($conn, $cert, $key)) {
        return;
    }
    if (!readStreamHeader($conn)) {
        return;
    }
    writeAll($conn, streamHeader());
    writeAll(
        $conn,
        '<stream:features><mechanisms xmlns="' . SASL_NS . '">'
        . '<mechanism>PLAIN</mechanism></mechanisms></stream:features>'
    );
    $auth = readElement($conn);
    if ($auth === null || preg_match('#<auth\b[^>]*>(.*?)</auth>#s', $auth, $matches) !== 1) {
        return;
    }
    $decoded = base64_decode($matches[1], true);
    if ($decoded === false) {
        return;
    }
    $parts = explode("\0", $decoded);
    $local = $parts[1] ?? '';
    $password = $parts[2] ?? '';
    if ($local === '' || $password !== 'secret') {
        writeAll(
            $conn,
            '<failure xmlns="' . SASL_NS . '"><not-authorized/>'
            . '<text xmlns="' . STANZAS_NS . '">bad password ' . xml($password) . '</text></failure>'
        );
        return;
    }
    writeAll($conn, '<success xmlns="' . SASL_NS . '"/>');

    if (!readStreamHeader($conn)) {
        return;
    }
    writeAll($conn, streamHeader());
    writeAll(
        $conn,
        '<stream:features><bind xmlns="' . BIND_NS . '"/>'
        . '<session xmlns="' . SESSION_NS . '"/></stream:features>'
    );
    $bind = readElement($conn);
    if ($bind === null) {
        return;
    }
    $bindId = attribute($bind, 'id');
    writeAll(
        $conn,
        '<iq type="result" id="' . xml($bindId) . '"><bind xmlns="' . BIND_NS . '">'
        . '<jid>' . xml($local) . '@localhost/php</jid></bind></iq>'
    );
    $session = readElement($conn);
    if ($session !== null && strpos($session, 'session') !== false) {
        $sessionId = attribute($session, 'id');
        writeAll($conn, '<iq type="result" id="' . xml($sessionId) . '"/>');
    }

    if ($local !== 'silent') {
        writeAll($conn, '<message from="bot@localhost/1" type="chat" id="hello"><body>hello</body></message>');
    }
    while (true) {
        $element = readElement($conn);
        if ($element === null) {
            return;
        }
        if (strpos($element, '</stream:stream') === 0) {
            writeAll($conn, '</stream:stream>');
            return;
        }
        if (strpos($element, '<message') !== 0) {
            continue;
        }
        $body = preg_match('#<body>(.*?)</body>#s', $element, $matches) === 1
            ? html_entity_decode($matches[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')
            : '';
        writeAll(
            $conn,
            '<message from="echo@localhost/1" type="chat" id="echo">'
            . '<body>' . xml('echo:' . $body) . '</body></message>'
        );
    }
}

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 5);
    handleConnection($conn, $cert, $key, $noStartTls);
    fclose($conn);
}
