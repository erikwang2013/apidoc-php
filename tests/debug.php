<?php
declare(strict_types=1);

/**
 * 调试中继测试（无框架、无依赖）: php tests/debug.php
 * 覆盖: raw(http/tcp/udp/hex) 驱动、http-relay 驱动、mqtt/stomp 驱动、中继开关、目标不可被客户端覆盖、限流、控制器鉴权
 */

use erikwang2013\apidoc\Controller;
use erikwang2013\apidoc\annotation\{Channel, Debug, Protocol, Title};
use erikwang2013\apidoc\parses\ParseProtocol;
use erikwang2013\apidoc\protocol\debug\DebugProxy;
use erikwang2013\apidoc\protocol\debug\DebugRegistry;
use erikwang2013\apidoc\protocol\debug\Driver\CoapDriver;
use erikwang2013\apidoc\protocol\debug\Driver\GrpcDriver;
use erikwang2013\apidoc\protocol\debug\Driver\HessianDriver;
use erikwang2013\apidoc\protocol\debug\Driver\HlsDriver;
use erikwang2013\apidoc\protocol\debug\Driver\Iso8583Driver;
use erikwang2013\apidoc\protocol\debug\Driver\ModbusTcpDriver;
use erikwang2013\apidoc\protocol\debug\Driver\MqttDriver;
use erikwang2013\apidoc\protocol\debug\Driver\RtspDriver;
use erikwang2013\apidoc\protocol\debug\Driver\SipDriver;
use erikwang2013\apidoc\protocol\debug\Driver\StompDriver;
use erikwang2013\apidoc\protocol\debug\Support\HttpClient;
use erikwang2013\apidoc\protocol\debug\Support\Protobuf;
use erikwang2013\apidoc\providers\BaseService;
use erikwang2013\apidoc\utils\Cache;
use erikwang2013\apidoc\utils\ConfigProvider;

error_reporting(E_ALL);
$root = dirname(__DIR__);
define('APIDOC_ROOT_PATH', $root . '/');
define('APIDOC_STORAGE_PATH', sys_get_temp_dir() . '/apidoc-debug-' . getmypid() . '/');
@mkdir(APIDOC_STORAGE_PATH, 0755, true);

spl_autoload_register(function ($class) use ($root) {
    $prefix = 'erikwang2013\\apidoc\\';
    if (strpos($class, $prefix) === 0) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
require __DIR__ . '/fixtures/protocol/AnnotationFixtures.php';

#[Protocol('mqtt')]
class MqttPublishFixture
{
    #[Channel('/debug/topic', action: 'publish')]
    #[Debug(driver: 'mqtt', target: 'tcp://127.0.0.1:19003')]
    public function publish() {}
}

#[Protocol('mqtt')]
class MqttSubscribeFixture
{
    #[Channel('/debug/topic', action: 'subscribe')]
    #[Debug(driver: 'mqtt', target: 'tcp://127.0.0.1:19003')]
    public function subscribe() {}
}

#[Protocol('stomp')]
class StompSubscribeFixture
{
    #[Channel('/debug/queue', action: 'receive')]
    #[Debug(driver: 'stomp', target: 'tcp://127.0.0.1:19004')]
    public function receive() {}
}

#[Protocol('coap')]
class CoapDebugFixture
{
    #[Channel('/debug/coap', action: 'send')]
    #[Debug(driver: 'coap', target: 'udp://127.0.0.1:19006')]
    public function echo() {}
}

#[Protocol('modbus-tcp')]
class ModbusDebugFixture
{
    #[Channel('/debug/modbus', action: 'send')]
    #[Debug(driver: 'modbus-tcp', target: 'tcp://127.0.0.1:19007')]
    public function read() {}
}

#[Protocol('iso8583')]
class Iso8583ProxyFixture
{
    #[Channel('iso')]
    #[Title('ISO')]
    #[Debug(driver: 'iso8583', target: 'tcp://127.0.0.1:19008')]
    public function send() {}
}

$pass = 0;
$fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

function waitPort(string $host, int $port, float $timeout = 5.0): bool
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }
        usleep(50000);
    }
    return false;
}

// ---------- 启动 fixture 服务 ----------
$procs = [];
function startFixture(array $command, string $log)
{
    global $procs;
    $proc = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        fwrite(STDERR, "无法启动 fixture: " . implode(' ', $command) . "\n");
        exit(1);
    }
    fclose($pipes[0]);
    $procs[] = $proc;
    return $proc;
}

register_shutdown_function(function () use (&$procs) {
    foreach ($procs as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
});

$logDir = sys_get_temp_dir();
// 本机 19001 已被 minio 占用,TCP 回显使用 19011
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/tcp_echo_server.php', '127.0.0.1', '19011'], $logDir . '/apidoc-debug-tcp.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/udp_echo_server.php', '127.0.0.1', '19002'], $logDir . '/apidoc-debug-udp.log');
startFixture([PHP_BINARY, '-S', '127.0.0.1:19005', '-t', $root . '/tests/fixtures/debug/http'], $logDir . '/apidoc-debug-http.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/mqtt_broker.php', '127.0.0.1', '19003'], $logDir . '/apidoc-debug-mqtt.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/stomp_server.php', '127.0.0.1', '19004'], $logDir . '/apidoc-debug-stomp.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/coap_server.php', '127.0.0.1', '19006'], $logDir . '/apidoc-debug-coap.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/modbus_server.php', '127.0.0.1', '19007'], $logDir . '/apidoc-debug-modbus.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/iso8583_server.php', '127.0.0.1', '19008'], $logDir . '/apidoc-debug-iso8583.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/rtsp_server.php', '127.0.0.1', '19009'], $logDir . '/apidoc-debug-rtsp.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/sip_server.php', '127.0.0.1', '19010'], $logDir . '/apidoc-debug-sip.log');
startFixture([PHP_BINARY, '-S', '127.0.0.1:19012', '-t', $root . '/tests/fixtures/debug/http'], $logDir . '/apidoc-debug-hls.log');
startFixture([PHP_BINARY, '-S', '127.0.0.1:19013', $root . '/tests/fixtures/debug/hessian_server.php'], $logDir . '/apidoc-debug-hessian.log');
usleep(100000);
if (!waitPort('127.0.0.1', 19011) || !waitPort('127.0.0.1', 19005) || !waitPort('127.0.0.1', 19003) || !waitPort('127.0.0.1', 19004)
    || !waitPort('127.0.0.1', 19007) || !waitPort('127.0.0.1', 19008) || !waitPort('127.0.0.1', 19009)
    || !waitPort('127.0.0.1', 19012) || !waitPort('127.0.0.1', 19013)) {
    echo "FAIL  fixture 服务未就绪\n";
    exit(1);
}
usleep(100000);

// ---------- 配置 ----------
$config = require $root . '/src/config.php';
$config['apps'] = [['key' => 'api', 'title' => 'API', 'path' => '']];
$config['cache'] = ['enable' => false, 'folder' => 'apidoc'];
$config['protocols']['debug'] = ['relay_enable' => true];
ConfigProvider::set($config);

$rawApi = 'Tests\Fixtures\Protocol\RawFixture@echo';
$httpApi = 'Tests\Fixtures\Protocol\HttpRelayFixture@echo';

// ---------- raw 驱动 ----------
$proxy = new DebugProxy($config);
$res = $proxy->send('api', $rawApi, ['payload' => 'hello', 'encoding' => 'text', 'timeout' => 2]);
check('raw tcp echo', ($res['body'] ?? '') === 'hello');
check('raw tcp ok', ($res['ok'] ?? false) === true);
$resHex = $proxy->send('api', $rawApi, ['payload' => '68656c6c6f', 'encoding' => 'hex', 'timeout' => 2]);
check('raw tcp hex echo', strtolower($resHex['body'] ?? '') === '68656c6c6f');
$resUdp = $proxy->send('api', 'Tests\Fixtures\Protocol\UdpRawFixture@echo', ['payload' => 'udp-hello', 'encoding' => 'text', 'timeout' => 2]);
check('raw udp echo', ($resUdp['body'] ?? '') === 'udp-hello');
$badHex = $proxy->send('api', $rawApi, ['payload' => 'zz', 'encoding' => 'hex']);
check('raw invalid hex rejected', ($badHex['ok'] ?? true) === false);

// ---------- 中继安全 ----------
$disabledConfig = $config;
$disabledConfig['protocols']['debug']['relay_enable'] = false;
$disabled = (new DebugProxy($disabledConfig))->send('api', $rawApi, ['payload' => 'x']);
check('relay disabled returns error', ($disabled['ok'] ?? true) === false);
$evil = $proxy->send('api', $rawApi, ['target' => 'tcp://127.0.0.1:19999', 'payload' => 'x', 'timeout' => 2]);
check('client target ignored', ($evil['ok'] ?? true) === true && ($evil['body'] ?? '') === 'x');

$limitedConfig = $config;
$limitedConfig['protocols']['debug']['rate_limit_per_minute'] = 1;
$limited = new DebugProxy($limitedConfig);
$first = $limited->send('api', $rawApi, ['payload' => 'a', 'timeout' => 2], 'token-a');
$second = $limited->send('api', $rawApi, ['payload' => 'b', 'timeout' => 2], 'token-a');
check('rate limit first call passes', ($first['ok'] ?? false) === true);
check('rate limit second call rejected', ($second['ok'] ?? true) === false && strpos((string)($second['error'] ?? ''), 'rate limit') !== false);
$shareFirst = $limited->send('api', $rawApi, ['payload' => 'c', 'timeout' => 2], 'share-style-key');
$shareSecond = $limited->send('api', $rawApi, ['payload' => 'd', 'timeout' => 2], 'share-style-key');
check('share-style rate key limited', ($shareFirst['ok'] ?? false) === true && ($shareSecond['ok'] ?? true) === false);
// 清掉前面无 key 调用累计的 anonymous 桶,再验证空 key 不会绕过限流
(new Cache(['expire' => 120]))->delete('debug/rate/' . md5('anonymous') . '/' . (int)floor(time() / 60));
$anonFirst = $limited->send('api', $rawApi, ['payload' => 'e', 'timeout' => 2]);
$anonSecond = $limited->send('api', $rawApi, ['payload' => 'f', 'timeout' => 2]);
check('anonymous rate key limited', ($anonFirst['ok'] ?? false) === true && ($anonSecond['ok'] ?? true) === false);

// ---------- 请求体大小上限 ----------
$smallConfig = $config;
$smallConfig['protocols']['debug']['max_bytes'] = 4;
$oversize = (new DebugProxy($smallConfig))->send('api', $rawApi, ['payload' => 'hello', 'encoding' => 'text']);
check('oversize request rejected', ($oversize['ok'] ?? true) === false && strpos((string)($oversize['error'] ?? ''), 'max_bytes') !== false);

// ---------- http-relay 驱动 ----------
$http = $proxy->send('api', $httpApi, ['method' => 'POST', 'payload' => 'hello', 'encoding' => 'text']);
check('http relay ok', ($http['ok'] ?? false) === true);
check('http relay body', strpos((string)($http['body'] ?? ''), 'hello') !== false);

// ---------- mqtt / stomp 驱动 ----------
$mqtt = new MqttDriver();
$connectHex = bin2hex($mqtt->encodeConnect(['clientId' => 'apidoc', 'keepalive' => 60]));
check('mqtt connect packet starts with 0x10', substr($connectHex, 0, 2) === '10');
check('mqtt connect client id encoded', strpos($mqtt->encodeConnect(['clientId' => 'apidoc']), 'apidoc') !== false);
check('mqtt connect cleanSession=false clears flag', (ord($mqtt->encodeConnect(['clientId' => 'x', 'cleanSession' => false])[9]) & 0x02) === 0);
check('mqtt connect cleanSession=true sets flag', (ord($mqtt->encodeConnect(['clientId' => 'x', 'cleanSession' => true])[9]) & 0x02) === 0x02);
$mqttPublish = $mqtt->encodePublish('a/b', 0, 'hi');
check('mqtt publish topic encoded', strpos($mqttPublish, 'a/b') !== false);
check('mqtt publish qos0 fixed header', ord($mqttPublish[0]) === 0x30);
check('mqtt publish qos1 carries packet id', strpos($mqtt->encodePublish('a/b', 1, 'hi', 7), "a/b\x00\x07hi") !== false);
check('mqtt subscribe wildcard encoded', strpos($mqtt->encodeSubscribe('sensors/+/temp', 1, 3), 'sensors/+/temp') !== false);
check('mqtt disconnect packet', $mqtt->encodeDisconnect() === "\xE0\x00");
$mqttPackets = MqttDriver::decodePackets("\x20\x02\x00\x00\x40\x02\x00\x07");
check('mqtt decode connack', ($mqttPackets[0]['type'] ?? 0) === 2 && ($mqttPackets[0]['body'] ?? '') === "\x00\x00");
check('mqtt decode puback', ($mqttPackets[1]['type'] ?? 0) === 4 && ($mqttPackets[1]['body'] ?? '') === "\x00\x07");
check('mqtt decode ignores partial packet', MqttDriver::decodePackets("\x20\x02\x00") === []);
check('mqtt streaming start ok', ($mqtt->start(['topic' => 'a/b'], [])['ok'] ?? false) === true);
check('mqtt streaming start requires topic', ($mqtt->start([], [])['ok'] ?? true) === false);
check('mqtt rejects non-string topic', ($mqtt->send(['topic' => ['a/b']], ['target' => 'tcp://127.0.0.1:19003'])['ok'] ?? true) === false);
$mqttDirect = $mqtt->send(['action' => 'publish', 'topic' => 'debug/topic', 'qos' => 1, 'payload' => 'hello'], ['target' => 'tcp://127.0.0.1:19003', 'timeout' => 2]);
check('mqtt direct publish ok', ($mqttDirect['ok'] ?? false) === true);
check('mqtt direct publish returns PUBACK', ($mqttDirect['body'] ?? '') === 'PUBACK');

$stomp = new StompDriver();
$stompFrame = $stomp->encodeFrame('SEND', ['destination' => '/queue/a', 'content-length' => 2], 'hi');
check('stomp frame ends with NUL', substr($stompFrame, -1) === "\0");
check('stomp content-length', strpos($stompFrame, 'content-length:2') !== false);
$stompDecoded = StompDriver::decodeFrames($stomp->encodeFrame('CONNECTED', ['version' => '1.2']) . $stomp->encodeFrame('RECEIPT', ['receipt-id' => 'r1']));
check('stomp decode frames', count($stompDecoded) === 2 && ($stompDecoded[0]['command'] ?? '') === 'CONNECTED');
check('stomp decode receipt header', ($stompDecoded[1]['headers']['receipt-id'] ?? '') === 'r1');
$stompCrlf = StompDriver::decodeFrames("CONNECTED\r\nversion:1.2\r\n\r\n\0");
check(
    'stomp CRLF frame decoded',
    count($stompCrlf) === 1
    && ($stompCrlf[0]['command'] ?? '') === 'CONNECTED'
    && ($stompCrlf[0]['headers']['version'] ?? '') === '1.2'
);
$stompBinaryBody = "a\0b\n\r";
$stompBinary = StompDriver::decodeFrames("MESSAGE\r\ncontent-length:" . strlen($stompBinaryBody) . "\r\n\r\n" . $stompBinaryBody . "\0");
check('stomp CRLF content-length binary body', ($stompBinary[0]['body'] ?? '') === $stompBinaryBody);
check('stomp partial CRLF frame incomplete', StompDriver::decodeFrames("MESSAGE\r\ncontent-length:5\r\n\r\nabc") === []);
check('stomp streaming start ok', ($stomp->start(['destination' => '/queue/a'], [])['ok'] ?? false) === true);
check('stomp streaming start requires destination', ($stomp->start([], [])['ok'] ?? true) === false);
check('stomp rejects non-string destination', ($stomp->send(['destination' => ['/queue/a']], ['target' => 'tcp://127.0.0.1:19004'])['ok'] ?? true) === false);
$stompDirect = $stomp->send(['action' => 'send', 'destination' => '/queue/a', 'payload' => 'hi', 'receipt' => 'r1'], ['target' => 'tcp://127.0.0.1:19004', 'timeout' => 2]);
check('stomp direct send ok', ($stompDirect['ok'] ?? false) === true);
check('stomp direct send returns receipt', ($stompDirect['body'] ?? '') === 'RECEIPT' && ($stompDirect['meta']['receiptId'] ?? '') === 'r1');

$publishRef = new ReflectionClass(MqttPublishFixture::class);
$publishMeta = (new ParseProtocol($config))->metadata($publishRef, $publishRef->getMethod('publish'));
check('mqtt publish fixture mode', ($publishMeta['debug']['mode'] ?? '') === 'relay-unary');
$publishProxy = $proxy->send('api', MqttPublishFixture::class . '@publish', ['fields' => ['topic' => 'debug/topic', 'qos' => 1, 'payload' => 'hello']]);
check('mqtt publish via proxy ok', ($publishProxy['ok'] ?? false) === true && ($publishProxy['body'] ?? '') === 'PUBACK');
$subscribeRef = new ReflectionClass(MqttSubscribeFixture::class);
$subscribeMeta = (new ParseProtocol($config))->metadata($subscribeRef, $subscribeRef->getMethod('subscribe'));
check('mqtt subscribe fixture mode', ($subscribeMeta['debug']['mode'] ?? '') === 'relay-stream');
$subscribeProxy = $proxy->send('api', MqttSubscribeFixture::class . '@subscribe', ['fields' => ['topic' => 'debug/topic']]);
check('mqtt subscribe rejected by unary proxy', ($subscribeProxy['ok'] ?? true) === false && strpos((string)($subscribeProxy['error'] ?? ''), 'not unary') !== false);

// ---------- 流式会话(MQTT/STOMP) ----------
$start = $proxy->start('api', MqttSubscribeFixture::class . '@subscribe', ['fields' => ['topic' => 'debug/topic']]);
check('session start returns id', !empty($start['sessionId']));
$poll = $proxy->poll((string)($start['sessionId'] ?? ''));
check('session poll returns messages array', is_array($poll['messages'] ?? null));
check('session poll receives broker message', strpos((string)json_encode($poll['messages'] ?? []), 'hello') !== false);
$stop = $proxy->stop((string)($start['sessionId'] ?? ''));
check('session stop', ($stop['ok'] ?? false) === true);
$expired = $proxy->poll('nonexistent');
check('unknown session rejected', ($expired['ok'] ?? true) === false);
$wrongMode = $proxy->start('api', 'Tests\Fixtures\Protocol\RawFixture@echo', ['fields' => ['topic' => 'a/b']]);
check('relay-unary start rejected', ($wrongMode['ok'] ?? true) === false);

$credStart = $proxy->start('api', MqttSubscribeFixture::class . '@subscribe', ['fields' => ['topic' => 'debug/topic']], 'stream-key');
$wrongCredPoll = $proxy->poll((string)($credStart['sessionId'] ?? ''), 'other-key');
check('poll with different rate key rejected', ($wrongCredPoll['ok'] ?? true) === false);
$proxy->stop((string)($credStart['sessionId'] ?? ''), 'stream-key');

$sessions = [];
for ($i = 0; $i < 4; $i++) {
    $s = $proxy->start('api', MqttSubscribeFixture::class . '@subscribe', [], 'session-limit-key');
    $sessions[] = (string)($s['sessionId'] ?? '');
}
check('max sessions per key allows four', count(array_filter($sessions)) === 4);
$limited = $proxy->start('api', MqttSubscribeFixture::class . '@subscribe', [], 'session-limit-key');
check('max sessions per key enforced', ($limited['ok'] ?? true) === false);
foreach ($sessions as $id) {
    $proxy->stop($id, 'session-limit-key');
}
$afterStop = $proxy->start('api', MqttSubscribeFixture::class . '@subscribe', [], 'session-limit-key');
check('stop frees session slot', !empty($afterStop['sessionId']));
$proxy->stop((string)($afterStop['sessionId'] ?? ''), 'session-limit-key');

$stompStart = $proxy->start('api', StompSubscribeFixture::class . '@receive', ['fields' => ['destination' => '/queue/a']]);
check('stomp session start returns id', !empty($stompStart['sessionId']));
$stompPoll = $proxy->poll((string)($stompStart['sessionId'] ?? ''));
check('stomp session poll receives broker message', strpos((string)json_encode($stompPoll['messages'] ?? []), 'hello') !== false);
$stompStop = $proxy->stop((string)($stompStart['sessionId'] ?? ''));
check('stomp session stop', ($stompStop['ok'] ?? false) === true);

// 排队消息与 CONNACK 同一次写出:第一次 poll 建立持久订阅,第二次重连由 broker 随 CONNACK 下发
$queuedStart = $proxy->start('api', MqttSubscribeFixture::class . '@subscribe', ['fields' => ['topic' => 'queued/hello']]);
$proxy->poll((string)($queuedStart['sessionId'] ?? ''));
$queuedPoll = $proxy->poll((string)($queuedStart['sessionId'] ?? ''));
$queuedMessages = is_array($queuedPoll['messages'] ?? null) ? $queuedPoll['messages'] : [];
check('mqtt poll keeps PUBLISH read with CONNACK', strpos((string)json_encode($queuedMessages), 'hello') !== false);
check('mqtt poll deduplicates resent packet id', count($queuedMessages) === 1);
$proxy->stop((string)($queuedStart['sessionId'] ?? ''));

// PUBLISH 跨两次写出(第一次与 CONNACK 同批),剩余字节不能丢
$splitStart = $proxy->start('api', MqttSubscribeFixture::class . '@subscribe', ['fields' => ['topic' => 'split/hello']]);
$proxy->poll((string)($splitStart['sessionId'] ?? ''));
$splitPoll = $proxy->poll((string)($splitStart['sessionId'] ?? ''));
check('mqtt poll decodes PUBLISH split across reads', strpos((string)json_encode($splitPoll['messages'] ?? []), 'hello') !== false);
$proxy->stop((string)($splitStart['sessionId'] ?? ''));

// ---------- coap / modbus-tcp / iso8583 驱动 ----------
check('debug registry has protocol drivers', DebugRegistry::driver('coap') instanceof CoapDriver
    && DebugRegistry::driver('modbus-tcp') instanceof ModbusTcpDriver
    && DebugRegistry::driver('iso8583') instanceof Iso8583Driver);
check('coap fields registered', !array_diff(['method', 'path', 'payload'], array_column(DebugRegistry::driverFields('coap'), 'name')));
check('modbus fields registered', !array_diff(['unitId', 'function', 'address', 'quantity', 'values'], array_column(DebugRegistry::driverFields('modbus-tcp'), 'name')));
check('iso8583 fields registered', !array_diff(['lengthPrefix', 'mti', 'bitmap', 'fields'], array_column(DebugRegistry::driverFields('iso8583'), 'name')));

$coap = new CoapDriver();
check('coap header version 1', (ord($coap->encodeRequest(1, 1, 'coap://host/x', 'hi')[0]) >> 6) === 1);
$coapReq = $coap->encodeRequest(0x1234, 2, 'coap://host/a/b', 'hi');
check('coap post code and message id', bin2hex(substr($coapReq, 0, 4)) === '40021234');
check('coap uri-path options', substr($coapReq, 4) === "\xB1a\x01b\xFFhi");
$coapDecoded = $coap->decodeResponse("\x60\x45\x12\x34\xFFok");
check('coap ack decoded', ($coapDecoded['ok'] ?? false) === true
    && ($coapDecoded['type'] ?? -1) === 2
    && ($coapDecoded['codeText'] ?? '') === '2.05'
    && ($coapDecoded['messageId'] ?? 0) === 0x1234
    && ($coapDecoded['payload'] ?? '') === 'ok');
$coapRes = $coap->send(['method' => 'GET', 'path' => '/debug/coap', 'payload' => 'hi'], ['target' => 'udp://127.0.0.1:19006', 'timeout' => 2]);
check('coap send ok', ($coapRes['ok'] ?? false) === true);
check('coap send returns payload', ($coapRes['body'] ?? '') === 'coap-ok');
check('coap send ack metadata', ($coapRes['meta']['code'] ?? '') === '2.05' && ($coapRes['meta']['type'] ?? -1) === 2);
check('coap rejects tcp target', ($coap->send(['path' => '/x'], ['target' => 'tcp://127.0.0.1:19006'])['ok'] ?? true) === false);
$coapProxyRes = $proxy->send('api', CoapDebugFixture::class . '@echo', ['fields' => ['method' => 'POST', 'path' => '/debug/coap', 'payload' => 'hi']]);
check('coap via proxy ok', ($coapProxyRes['ok'] ?? false) === true && ($coapProxyRes['body'] ?? '') === 'coap-ok');

$modbus = new ModbusTcpDriver();
$req = $modbus->encodeRead(1, 3, 0, 2);
check('modbus mbap length', bin2hex(substr($req, 4, 2)) === '0006');
check('modbus function 3', ord($req[7]) === 3);
check('modbus transaction, unit and pdu', bin2hex(substr($req, 0, 2)) === '0001' && ord($req[6]) === 1 && bin2hex(substr($req, 8)) === '00000002');
$writeReq = $modbus->encodeWriteSingle(2, 6, 10, 500);
check('modbus write register pdu', bin2hex(substr($writeReq, 7)) === '06000a01f4');
$modbusException = ModbusTcpDriver::decodeResponse(pack('n', 1) . pack('n', 0) . pack('n', 3) . chr(1) . chr(0x83) . chr(2));
check('modbus exception decoded', ($modbusException['ok'] ?? true) === false
    && ($modbusException['meta']['exceptionCode'] ?? 0) === 2
    && strpos((string)($modbusException['error'] ?? ''), 'exception 2') !== false);
$modbusRes = $modbus->send(['function' => 3, 'address' => 0, 'quantity' => 2], ['target' => 'tcp://127.0.0.1:19007', 'timeout' => 2]);
check('modbus send ok', ($modbusRes['ok'] ?? false) === true);
check('modbus register values', ($modbusRes['meta']['values'] ?? []) === [100, 200] && strpos((string)($modbusRes['body'] ?? ''), '100, 200') !== false);
check('modbus response hex returned', substr((string)($modbusRes['meta']['hex'] ?? ''), 0, 4) !== '');
check('modbus rejects unsupported function', ($modbus->send(['function' => 99], ['target' => 'tcp://127.0.0.1:19007'])['ok'] ?? true) === false);
$modbusProxyRes = $proxy->send('api', ModbusDebugFixture::class . '@read', ['fields' => ['function' => 3, 'address' => 0, 'quantity' => 2]]);
check('modbus via proxy ok', ($modbusProxyRes['ok'] ?? false) === true && ($modbusProxyRes['meta']['values'] ?? []) === [100, 200]);

$iso = new Iso8583Driver();
$wire = $iso->encodeFrame('0800', str_repeat('00', 8), 2);
check('iso length prefix', strlen($wire) === 2 + 4 + 8);
check('iso length prefix value', bin2hex(substr($wire, 0, 2)) === '000c' && substr($wire, 2, 4) === '0800');
check('iso ascii length prefix', substr($iso->encodeFrame('0800', str_repeat('00', 8), '2ascii'), 0, 2) === '12');
check('iso no length prefix', strlen($iso->encodeFrame('0800', str_repeat('00', 8), 'none')) === 12);
check('iso fields blob appended', strlen($iso->encodeFrame('0800', str_repeat('00', 8), 2, 'abcd')) === 2 + 4 + 8 + 2);
$isoRes = $iso->send(['mti' => '0800', 'bitmap' => str_repeat('00', 16), 'fields' => '30303030'], ['target' => 'tcp://127.0.0.1:19008', 'timeout' => 2]);
check('iso send ok', ($isoRes['ok'] ?? false) === true);
check('iso response hex', strpos((string)($isoRes['body'] ?? ''), bin2hex('0810')) === 2 * 2 && strpos((string)($isoRes['body'] ?? ''), bin2hex('APPROVED')) !== false);
check('iso response ascii preview', strpos((string)($isoRes['meta']['ascii'] ?? ''), 'APPROVED') !== false);
check('iso rejects bad mti', ($iso->send(['mti' => '08'], ['target' => 'tcp://127.0.0.1:19008'])['ok'] ?? true) === false);
check('iso rejects bad bitmap', ($iso->send(['bitmap' => 'zz'], ['target' => 'tcp://127.0.0.1:19008'])['ok'] ?? true) === false);
$isoProxyRes = $proxy->send('api', Iso8583ProxyFixture::class . '@send', ['fields' => ['mti' => '0800', 'bitmap' => str_repeat('00', 16)]]);
check('iso8583 via proxy ok', ($isoProxyRes['ok'] ?? false) === true && strpos((string)($isoProxyRes['body'] ?? ''), bin2hex('APPROVED')) !== false);
$isoFieldBlob = '30303030';
$isoFieldsRes = $proxy->send('api', Iso8583ProxyFixture::class . '@send', ['fields' => [
    'lengthPrefix' => '2binary',
    'mti' => '0800',
    'bitmap' => str_repeat('00', 16),
    'fields' => $isoFieldBlob,
]]);
check('iso8583 fields bag via proxy ok', ($isoFieldsRes['ok'] ?? false) === true
    && strpos((string)($isoFieldsRes['body'] ?? ''), bin2hex('0810')) !== false
    && strpos((string)($isoFieldsRes['body'] ?? ''), $isoFieldBlob) !== false);

// ---------- rtsp / sip / hls 驱动 ----------
check('debug registry has stream drivers', DebugRegistry::driver('rtsp') instanceof RtspDriver
    && DebugRegistry::driver('sip') instanceof SipDriver
    && DebugRegistry::driver('hls') instanceof HlsDriver);
check('rtsp fields registered', !array_diff(['method', 'url', 'cseq', 'headers'], array_column(DebugRegistry::driverFields('rtsp'), 'name')));
check('sip fields registered', !array_diff(['method', 'uri', 'from', 'to', 'callId', 'cseq', 'headers', 'body'], array_column(DebugRegistry::driverFields('sip'), 'name')));
check('hls fields registered', !array_diff(['fetchSegment'], array_column(DebugRegistry::driverFields('hls'), 'name')));
check('hls mode is relay unary', (new HlsDriver())->mode() === 'relay-unary');

$rtsp = new RtspDriver();
$req = $rtsp->encodeRequest('DESCRIBE', 'rtsp://host/stream', 1);
check('rtsp start line', strpos($req, "DESCRIBE rtsp://host/stream RTSP/1.0\r\n") === 0);
check('rtsp cseq', strpos($req, "CSeq: 1\r\n") !== false);
check('rtsp user agent', strpos($req, "User-Agent: apidoc-debug\r\n") !== false);
$rtspHeaders = $rtsp->encodeRequest('OPTIONS', '*', 2, ['X-Test' => '1']);
check('rtsp extra headers', strpos($rtspHeaders, "X-Test: 1\r\n") !== false);
$rtspRes = $rtsp->send(['method' => 'DESCRIBE', 'url' => 'rtsp://127.0.0.1:19009/stream', 'cseq' => 7], ['target' => 'tcp://127.0.0.1:19009', 'timeout' => 2]);
check('rtsp send ok', ($rtspRes['ok'] ?? false) === true);
check('rtsp send raw response', strpos((string)($rtspRes['body'] ?? ''), "RTSP/1.0 200 OK\r\n") === 0);
check('rtsp send cseq echoed', strpos((string)($rtspRes['body'] ?? ''), "CSeq: 7\r\n") !== false);
check('rtsp send public header', strpos((string)($rtspRes['body'] ?? ''), 'Public:') !== false);
check('rtsp send status meta', ($rtspRes['meta']['status'] ?? 0) === 200);
check('rtsp rejects bad method', ($rtsp->send(['method' => 'GET', 'url' => 'rtsp://host/x'], ['target' => 'tcp://127.0.0.1:19009'])['ok'] ?? true) === false);
check('rtsp rejects non-tcp target', ($rtsp->send([], ['target' => 'udp://127.0.0.1:19009'])['ok'] ?? true) === false);
check('rtsp rejects invalid headers json', ($rtsp->send(['headers' => 'nope'], ['target' => 'tcp://127.0.0.1:19009'])['ok'] ?? true) === false);
check('rtsp rejects header injection', ($rtsp->send(['headers' => '{"X-Test":"a\r\nInjected: 1"}'], ['target' => 'tcp://127.0.0.1:19009'])['ok'] ?? true) === false);

$sip = new SipDriver();
$msg = $sip->encodeRequest('OPTIONS', 'sip:host', 'from@test', 'to@test', 1);
check('sip start line', strpos($msg, "OPTIONS sip:host SIP/2.0\r\n") === 0);
check('sip via header', strpos($msg, 'Via: SIP/2.0/UDP apidoc-debug;branch=z9hG4bK') !== false);
check('sip max forwards', strpos($msg, "Max-Forwards: 70\r\n") !== false);
check('sip from header', strpos($msg, 'From: <sip:from@test>') !== false);
check('sip to header', strpos($msg, 'To: <sip:to@test>') !== false);
check('sip call id', preg_match('/^Call-ID: \S+\r$/m', $msg) === 1);
check('sip cseq header', strpos($msg, "CSeq: 1 OPTIONS\r\n") !== false);
check('sip content length', strpos($msg, "Content-Length: 0\r\n\r\n") !== false);
check('sip tcp via transport', strpos($sip->encodeRequest('OPTIONS', 'sip:host', 'a@b', 'c@d', 2, 'call-1', [], '', 'TCP'), 'Via: SIP/2.0/TCP ') !== false);
check('sip body content length', strpos($sip->encodeRequest('MESSAGE', 'sip:host', 'a@b', 'c@d', 2, 'call-1', [], 'hello'), "Content-Length: 5\r\n\r\nhello") !== false);
$sipRes = $sip->send(['method' => 'OPTIONS', 'callId' => 'test-call', 'cseq' => 5], ['target' => 'udp://127.0.0.1:19010', 'timeout' => 2]);
check('sip send ok', ($sipRes['ok'] ?? false) === true);
check('sip send response line', strpos((string)($sipRes['body'] ?? ''), "SIP/2.0 200 OK\r\n") === 0);
check('sip send call id echoed', strpos((string)($sipRes['body'] ?? ''), "Call-ID: test-call\r\n") !== false);
check('sip send cseq echoed', strpos((string)($sipRes['body'] ?? ''), "CSeq: 5 OPTIONS\r\n") !== false);
check('sip send content length', strpos((string)($sipRes['body'] ?? ''), 'Content-Length: 0') !== false);
check('sip send status meta', ($sipRes['meta']['status'] ?? 0) === 200);
check('sip rejects bad method', ($sip->send(['method' => 'INVITE'], ['target' => 'udp://127.0.0.1:19010'])['ok'] ?? true) === false);
check('sip rejects http target', ($sip->send([], ['target' => 'http://127.0.0.1:19010'])['ok'] ?? true) === false);
$sipMessage = $sip->encodeRequest('MESSAGE', 'sip:host', 'a@b', 'c@d', 2, 'call-1', [], 'hello');
check('sip message default content type', strpos($sipMessage, "Content-Type: application/sdp\r\n") !== false);
check('sip caller content type wins', substr_count(strtolower($sip->encodeRequest('MESSAGE', 'sip:host', 'a@b', 'c@d', 2, 'call-1', ['Content-Type' => 'application/json'], '{}')), "content-type:") === 1
    && strpos($sip->encodeRequest('MESSAGE', 'sip:host', 'a@b', 'c@d', 2, 'call-1', ['Content-Type' => 'application/json'], '{}'), "Content-Type: application/json\r\n") !== false);
check('sip no content type for empty body', strpos($sip->encodeRequest('OPTIONS', 'sip:host', 'a@b', 'c@d', 1), 'Content-Type:') === false);
check('sip content length counts bytes', strpos($sip->encodeRequest('MESSAGE', 'sip:host', 'a@b', 'c@d', 2, 'call-1', [], 'héllo'), "Content-Length: 6\r\n\r\nh\xc3\xa9llo") !== false);
$sipMessageRes = $sip->send(['method' => 'MESSAGE', 'body' => '{}', 'headers' => '{"Content-Type":"application/json"}', 'callId' => 'msg-call', 'cseq' => 6], ['target' => 'udp://127.0.0.1:19010', 'timeout' => 2]);
check('sip message send ok', ($sipMessageRes['ok'] ?? false) === true && ($sipMessageRes['meta']['status'] ?? 0) === 200);

$hls = new HlsDriver();
$parsed = $hls->parsePlaylist("#EXTM3U\n#EXTINF:10,\nseg1.ts\n#EXT-X-ENDLIST\n");
check('hls segment parsed', ($parsed['segments'][0] ?? '') === 'seg1.ts');
check('hls is media playlist', ($parsed['master'] ?? true) === false);
check('hls endlist parsed', ($parsed['endlist'] ?? false) === true);
check('hls segment duration', ($parsed['durations'][0] ?? null) === 10.0);
$masterParsed = $hls->parsePlaylist("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1280000,RESOLUTION=640x360\nlow.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=2560000,RESOLUTION=1280x720,CODECS=\"avc1,mp4a\"\nhigh.m3u8\n");
check('hls master detected', ($masterParsed['master'] ?? false) === true);
check('hls variants parsed', count($masterParsed['variants']) === 2
    && ($masterParsed['variants'][0]['bandwidth'] ?? 0) === 1280000
    && ($masterParsed['variants'][1]['resolution'] ?? '') === '1280x720'
    && ($masterParsed['variants'][1]['uri'] ?? '') === 'high.m3u8');
$hlsRes = $hls->send([], ['target' => 'http://127.0.0.1:19012/playlist.m3u8', 'timeout' => 2]);
check('hls send ok', ($hlsRes['ok'] ?? false) === true);
$hlsJson = json_decode((string)($hlsRes['body'] ?? ''), true);
check('hls send returns parsed playlist', is_array($hlsJson) && ($hlsJson['segments'][0] ?? '') === 'seg1.ts' && ($hlsJson['endlist'] ?? false) === true);
$hlsFetch = $hls->send(['fetchSegment' => true], ['target' => 'http://127.0.0.1:19012/playlist.m3u8', 'timeout' => 2]);
check('hls fetch segment returns size and hash', ($hlsFetch['ok'] ?? false) === true
    && ($hlsFetch['meta']['segment']['bytes'] ?? 0) === strlen('HLS-SEGMENT-1')
    && strlen((string)($hlsFetch['meta']['segment']['sha256'] ?? '')) === 64);
check('hls fetch segment omits media bytes', strpos((string)($hlsFetch['body'] ?? ''), 'HLS-SEGMENT-1') === false);
check('hls rejects non-http target', ($hls->send([], ['target' => 'tcp://127.0.0.1:19012'])['ok'] ?? true) === false);

// ---------- grpc / hessian 驱动 ----------
check('debug registry has rpc drivers', DebugRegistry::driver('grpc') instanceof GrpcDriver
    && DebugRegistry::driver('hessian') instanceof HessianDriver);
check('grpc fields registered', !array_diff(['service', 'method', 'metadata', 'payload', 'schema', 'tls'], array_column(DebugRegistry::driverFields('grpc'), 'name')));
check('hessian fields registered', !array_diff(['method', 'args', 'payload'], array_column(DebugRegistry::driverFields('hessian'), 'name')));
check('grpc mode is relay unary', (new GrpcDriver())->mode() === 'relay-unary');
check('hessian mode is relay unary', (new HessianDriver())->mode() === 'relay-unary');

$pb = Protobuf::encode([1 => ['type' => 'string', 'value' => 'hi']]);
check('protobuf string field', bin2hex($pb) === '0a026869');
$decoded = Protobuf::decode(hex2bin('0a026869'), [1 => ['name' => 'name', 'type' => 'string']]);
check('protobuf decode', ($decoded['name'] ?? '') === 'hi');
check('protobuf varint encode', bin2hex(Protobuf::encode([1 => ['type' => 'int', 'value' => 150]])) === '089601');
check('protobuf varint decode', (Protobuf::decode(hex2bin('089601'), [1 => ['name' => 'n', 'type' => 'int']])['n'] ?? 0) === 150);
check('protobuf bool field', bin2hex(Protobuf::encode([2 => ['type' => 'bool', 'value' => true]])) === '1001');
check('protobuf double decode', (Protobuf::decode(Protobuf::encode([3 => ['type' => 'double', 'value' => 1.5]]), [3 => ['name' => 'd', 'type' => 'double']])['d'] ?? 0.0) === 1.5);
$nested = Protobuf::encode([2 => ['type' => 'message', 'value' => [1 => ['type' => 'string', 'value' => 'hi']]]]);
check('protobuf nested message bytes', bin2hex($nested) === '12040a026869');
check('protobuf nested message decode', (Protobuf::decode($nested, [2 => ['name' => 'inner', 'type' => 'message', 'fields' => [1 => ['name' => 'name', 'type' => 'string']]]])['inner']['name'] ?? '') === 'hi');
$repeated = Protobuf::encode([1 => ['type' => 'repeated', 'itemType' => 'int', 'value' => [1, 2]]]);
check('protobuf repeated bytes', bin2hex($repeated) === '08010802');
check('protobuf repeated decode', (Protobuf::decode($repeated, [1 => ['name' => 'nums', 'type' => 'repeated', 'itemType' => 'int']])['nums'] ?? []) === [1, 2]);
check('protobuf packed repeated decode', (Protobuf::decode(hex2bin('0a03010203'), [1 => ['name' => 'nums', 'type' => 'repeated', 'itemType' => 'int']])['nums'] ?? []) === [1, 2, 3]);
check('protobuf unknown field hex', (Protobuf::decode(hex2bin('182a'), [])['field_3'] ?? '') === '182a');
$pbThrew = false;
try {
    Protobuf::decode(hex2bin('0880'), []);
} catch (InvalidArgumentException $e) {
    $pbThrew = true;
}
check('protobuf malformed varint throws', $pbThrew);
$pbThrew = false;
try {
    Protobuf::decode(hex2bin('0a056869'), []);
} catch (InvalidArgumentException $e) {
    $pbThrew = true;
}
check('protobuf truncated length throws', $pbThrew);

$grpc = new GrpcDriver();
check('grpc frame prefix', bin2hex($grpc->frame("\x0a\x02hi")) === '00000000040a026869');
check('grpc frame big endian length', substr($grpc->frame(str_repeat('a', 300)), 1, 4) === "\x00\x00\x01\x2c");
check('http2 option: plaintext prior knowledge', HttpClient::versionOption(false, true) === CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE);
check('http2 option: tls alpn', HttpClient::versionOption(true, true) === CURL_HTTP_VERSION_2_0);
check('http2 option: prior knowledge unavailable fallback', HttpClient::versionOption(false, true, false) === CURL_HTTP_VERSION_2_0);
$grpcRes = $grpc->send(
    ['service' => 'demo.Greeter', 'method' => 'SayHello', 'payload' => '{"1":{"type":"string","value":"hi"}}'],
    ['target' => '127.0.0.1:19005', 'timeout' => 2]
);
check('grpc non-grpc endpoint fails cleanly', ($grpcRes['ok'] ?? true) === false
    && (string)($grpcRes['error'] ?? '') !== '');
check('grpc rejects tcp scheme target', ($grpc->send(['service' => 'a', 'method' => 'b'], ['target' => 'tcp://127.0.0.1:19005'])['ok'] ?? true) === false);
check('grpc rejects missing service', ($grpc->send(['method' => 'SayHello'], ['target' => '127.0.0.1:19005'])['ok'] ?? true) === false);
check('grpc rejects invalid payload json', ($grpc->send(['service' => 'a', 'method' => 'b', 'payload' => 'nope'], ['target' => '127.0.0.1:19005'])['ok'] ?? true) === false);
check('grpc rejects invalid metadata json', ($grpc->send(['service' => 'a', 'method' => 'b', 'metadata' => 'nope'], ['target' => '127.0.0.1:19005'])['ok'] ?? true) === false);

$hessian = new HessianDriver();
check('hessian string encode', bin2hex($hessian->encodeString('hi')) === '026869');
check('hessian int encode', bin2hex($hessian->encodeInt(1)) === '91');
check('hessian int two byte encode', bin2hex($hessian->encodeInt(1000)) === 'cbe8');
check('hessian int negative two byte encode', bin2hex($hessian->encodeInt(-17)) === 'c7ef');
check('hessian long encode', bin2hex($hessian->encodeLong(1)) === 'e1');
check('hessian call envelope bytes', bin2hex($hessian->encodeCall('say', [])) === '6302006d00037361797a');
check('hessian bool encode', $hessian->encodeBool(true) === 'T' && $hessian->encodeBool(false) === 'F');
check('hessian null encode', $hessian->encodeNull() === 'N');
check('hessian list roundtrip', $hessian->decode($hessian->encodeList([1, 'x', true])) === [1, 'x', true]);
check('hessian map roundtrip', $hessian->decode($hessian->encodeMap(['a' => 1])) === ['a' => 1]);
check('hessian binary roundtrip', $hessian->decode($hessian->encodeBinary("\x00\x01")) === "\x00\x01");
check('hessian double roundtrip', $hessian->decode($hessian->encodeDouble(1.5)) === 1.5);
check('hessian long roundtrip', $hessian->decode($hessian->encodeLong(1234567890123)) === 1234567890123);
check('hessian map uses untyped marker', $hessian->encodeMap(['a' => 1])[0] === 'H');
check('hessian compact double one', $hessian->decode("\x5c") === 1.0);
check('hessian compact double byte', $hessian->decode("\x5d\x80") === -128.0);
check('hessian compact double short', $hessian->decode("\x5e\x80\x00") === -32768.0);
check('hessian compact double float', $hessian->decode("\x5f\x3f\x80\x00\x00") === 1.0);
check('hessian compact binary', $hessian->decode("\x23abc") === 'abc');
check('hessian compact binary extension vector', $hessian->decode("\x34\x10" . str_repeat('A', 16)) === str_repeat('A', 16));
check('hessian binary compact marker 15', $hessian->encodeBinary(str_repeat('A', 15))[0] === "\x2f");
check('hessian binary compact marker 16', substr($hessian->encodeBinary(str_repeat('A', 16)), 0, 2) === "\x34\x10");
check('hessian binary compact marker 1023', substr($hessian->encodeBinary(str_repeat('A', 1023)), 0, 2) === "\x37\xff");
check('hessian binary 1024 uses B', $hessian->encodeBinary(str_repeat('A', 1024))[0] === 'B');
foreach ([0, 1, 15, 16, 255, 1023] as $binaryLength) {
    $binaryRaw = str_repeat('A', $binaryLength);
    check('hessian binary roundtrip ' . $binaryLength, $hessian->decode($hessian->encodeBinary($binaryRaw)) === $binaryRaw);
}
check('hessian string utf16 emoji length', bin2hex($hessian->encodeString("\xf0\x9f\x98\x80")) === '02f09f9880');
check('hessian string utf16 cjk length', bin2hex($hessian->encodeString("\xe4\xbd\xa0\xe5\xa5\xbd")) === '02e4bda0e5a5bd');
check('hessian string utf16 mixed length', bin2hex($hessian->encodeString("a\xf0\x9f\x98\x80b")) === '0461f09f988062');
check('hessian string utf16 mixed roundtrip', $hessian->decode($hessian->encodeString("a\xf0\x9f\x98\x80b")) === "a\xf0\x9f\x98\x80b");
check('hessian string utf16 16-unit marker', bin2hex($hessian->encodeString(str_repeat("\xf0\x9f\x98\x80", 8))) === '10' . str_repeat('f09f9880', 8));
check('hessian string utf16 32-unit marker', bin2hex($hessian->encodeString(str_repeat("\xf0\x9f\x98\x80", 16))) === '3020' . str_repeat('f09f9880', 16));
check('hessian string chunk', $hessian->decode("\x52\x00\x05hello\x05world") === 'helloworld');
check('hessian compact untyped list', $hessian->decode("\x7a\x91\x92") === [1, 2]);
check('hessian compact typed list', $hessian->decode("\x72\x04[int\x90\x91") === [0, 1]);
check('hessian typed map decode', $hessian->decode("\x4d\x03Car\x01a\x91\x5a") === ['a' => 1]);
check('hessian 32-bit long decode', $hessian->decode("\x59\x00\x00\x00\x2a") === 42);
$hessianThrew = false;
try {
    $hessian->decode('');
} catch (InvalidArgumentException $e) {
    $hessianThrew = true;
}
check('hessian empty body throws', $hessianThrew);
$hessianRes = $hessian->send(['method' => 'sayHello', 'args' => '["hi",1]'], ['target' => 'http://127.0.0.1:19013/hessian_server.php', 'timeout' => 2]);
check('hessian send ok', ($hessianRes['ok'] ?? false) === true);
check('hessian send decodes response', json_decode((string)($hessianRes['body'] ?? ''), true) === 'hello');
check('hessian send status meta', ($hessianRes['meta']['status'] ?? 0) === 200);
$hessianRaw = $hessian->send(['payload' => '6302006d00037361797a'], ['target' => 'http://127.0.0.1:19013/hessian_server.php', 'timeout' => 2]);
check('hessian raw payload fallback', ($hessianRaw['ok'] ?? false) === true);
$hessianEnvelope = $hessian->send(['method' => 'sayHello'], ['target' => 'http://127.0.0.1:19013/envelope', 'timeout' => 2]);
check('hessian reply envelope decoded', ($hessianEnvelope['ok'] ?? false) === true
    && json_decode((string)($hessianEnvelope['body'] ?? ''), true) === 'hello');
$hessianFault = $hessian->send(['method' => 'sayHello'], ['target' => 'http://127.0.0.1:19013/fault', 'timeout' => 2]);
check('hessian fault returns error', ($hessianFault['ok'] ?? true) === false
    && strpos((string)($hessianFault['error'] ?? ''), 'File Not Found') !== false
    && ($hessianFault['meta']['fault']['code'] ?? '') === 'ServiceException');
check('hessian rejects bad args json', ($hessian->send(['method' => 'x', 'args' => 'nope'], ['target' => 'http://127.0.0.1:19013/hessian_server.php'])['ok'] ?? true) === false);
check('hessian rejects non-http target', ($hessian->send(['method' => 'x'], ['target' => 'tcp://127.0.0.1:19013'])['ok'] ?? true) === false);

// ---------- 控制器端点 ----------
$controllerConfig = $config;
$controllerConfig['request_params'] = [
    'appKey' => 'api',
    'path' => $rawApi,
    'input' => ['payload' => 'hello', 'encoding' => 'text', 'timeout' => 2],
    'token' => 'controller-token',
];
ConfigProvider::set($controllerConfig);
$response = (new Controller())->debugSend();
check('controller debugSend relays', ($response['data']['ok'] ?? false) === true && ($response['data']['body'] ?? '') === 'hello');

$deniedConfig = $controllerConfig;
$deniedConfig['request_params'] = ['appKey' => 'api', 'path' => $rawApi, 'input' => ['payload' => 'x']];
unset($deniedConfig['protocols']['debug']['relay_require_auth']);
ConfigProvider::set($deniedConfig);
$denied = (new Controller())->debugSend();
check('controller debugSend requires auth by default', ($denied['data']['ok'] ?? true) === false && ($denied['data']['error'] ?? '') === 'authentication required');

// auth 关闭时,任意 token 都归入同一个 anonymous 桶
$anonConfig = $config;
$anonConfig['protocols']['debug']['rate_limit_per_minute'] = 1;
(new Cache(['expire' => 120]))->delete('debug/rate/' . md5('anonymous') . '/' . (int)floor(time() / 60));
$anonResults = [];
foreach (['arbitrary-1', 'arbitrary-2'] as $i => $token) {
    $anonRequestConfig = $anonConfig;
    $anonRequestConfig['request_params'] = [
        'appKey' => 'api',
        'path' => $rawApi,
        'input' => ['payload' => 't' . $i, 'timeout' => 2],
        'token' => $token,
    ];
    ConfigProvider::set($anonRequestConfig);
    $anonResults[] = (new Controller())->debugSend();
}
check(
    'auth-disabled arbitrary tokens share anonymous bucket',
    ($anonResults[0]['data']['ok'] ?? false) === true
    && ($anonResults[1]['data']['ok'] ?? true) === false
    && strpos((string)($anonResults[1]['data']['error'] ?? ''), 'rate limit') !== false
);

$rateKeyMethod = new ReflectionMethod(Controller::class, 'debugRateKey');
$configProp = new ReflectionProperty(Controller::class, 'config');
if (PHP_VERSION_ID < 80100) {
    $rateKeyMethod->setAccessible(true);
    $configProp->setAccessible(true);
}
$controllerForKeys = new Controller();
ConfigProvider::set($config);
$configProp->setValue($controllerForKeys, $config);
check('rate key: shareKey wins over token', $rateKeyMethod->invoke($controllerForKeys, ['token' => 't', 'shareKey' => 's']) === 'share:s');
check('rate key: auth-disabled client token maps to anonymous', $rateKeyMethod->invoke($controllerForKeys, ['token' => 'arbitrary', 'appKey' => 'api']) === 'anonymous');
check('rate key: no credential maps to anonymous', $rateKeyMethod->invoke($controllerForKeys, []) === 'anonymous');
$authOnConfig = $config;
$authOnConfig['auth']['enable'] = true;
$configProp->setValue($controllerForKeys, $authOnConfig);
check('rate key: validated token maps to its own bucket', $rateKeyMethod->invoke($controllerForKeys, ['token' => 'tk', 'appKey' => 'api']) === 'token:tk');

$routes = (new ReflectionClass(BaseService::class))->getStaticProperties()['routes'] ?? [];
$rules = array_column($routes, 'rule');
check('debug routes registered', !array_diff(['debugSend', 'debugStart', 'debugPoll', 'debugStop'], $rules));

// ---------- stream 回退总超时(放在最后,slow 端点会占用内置服务器) ----------
$viaStream = new ReflectionMethod(HttpClient::class, 'viaStream');
if (PHP_VERSION_ID < 80100) {
    $viaStream->setAccessible(true);
}
$slowStart = microtime(true);
$slow = $viaStream->invoke(null, [
    'url' => 'http://127.0.0.1:19005/slow.php',
    'method' => 'GET',
    'timeout' => 1,
    'maxBytes' => 1024,
]);
$slowElapsed = microtime(true) - $slowStart;
check('stream fallback bounds total timeout', ($slow['error'] ?? '') === 'request timed out' && $slowElapsed < 2.0);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
