<?php
declare(strict_types=1);

/**
 * 调试中继测试（无框架、无依赖）: php tests/debug.php
 * 覆盖: raw(http/tcp/udp/hex) 驱动、http-relay 驱动、mqtt/stomp 驱动、中继开关、目标不可被客户端覆盖、限流、控制器鉴权
 */

use erikwang2013\apidoc\Controller;
use erikwang2013\apidoc\exception\ErrorException;
use erikwang2013\apidoc\parses\ParseProtocol;
use erikwang2013\apidoc\protocol\debug\DebugProxy;
use erikwang2013\apidoc\protocol\debug\DebugRegistry;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Driver\AmqpDriver;
use erikwang2013\apidoc\protocol\debug\Driver\BacnetDriver;
use erikwang2013\apidoc\protocol\debug\Driver\CoapDriver;
use erikwang2013\apidoc\protocol\debug\Driver\Dnp3Driver;
use erikwang2013\apidoc\protocol\debug\Driver\Iec104Driver;
use erikwang2013\apidoc\protocol\debug\Driver\Lwm2mDriver;
use erikwang2013\apidoc\protocol\debug\Driver\OpcuaDriver;
use erikwang2013\apidoc\protocol\debug\Driver\RtcpDriver;
use erikwang2013\apidoc\protocol\debug\Driver\RtmpDriver;
use erikwang2013\apidoc\protocol\debug\Driver\RtpDriver;
use erikwang2013\apidoc\protocol\debug\Driver\DubboDriver;
use erikwang2013\apidoc\protocol\debug\Driver\GrpcDriver;
use erikwang2013\apidoc\protocol\debug\Driver\HessianDriver;
use erikwang2013\apidoc\protocol\debug\Driver\HlsDriver;
use erikwang2013\apidoc\protocol\debug\Driver\Http2Driver;
use erikwang2013\apidoc\protocol\debug\Driver\Http3Driver;
use erikwang2013\apidoc\protocol\debug\Driver\Iso8583Driver;
use erikwang2013\apidoc\protocol\debug\Driver\KafkaDriver;
use erikwang2013\apidoc\protocol\debug\Driver\ModbusTcpDriver;
use erikwang2013\apidoc\protocol\debug\Driver\MqttDriver;
use erikwang2013\apidoc\protocol\debug\Driver\RawDriver;
use erikwang2013\apidoc\protocol\debug\Driver\RtspDriver;
use erikwang2013\apidoc\protocol\debug\Driver\SipDriver;
use erikwang2013\apidoc\protocol\debug\Driver\StompDriver;
use erikwang2013\apidoc\protocol\debug\Driver\ThriftDriver;
use erikwang2013\apidoc\protocol\debug\Driver\XmppDriver;
use erikwang2013\apidoc\protocol\debug\Support\HttpClient;
use erikwang2013\apidoc\protocol\debug\Support\Protobuf;
use erikwang2013\apidoc\protocol\debug\Support\TargetGuard;
use erikwang2013\apidoc\providers\BaseService;
use erikwang2013\apidoc\utils\ApiShare;
use erikwang2013\apidoc\utils\Cache;
use erikwang2013\apidoc\utils\ConfigProvider;
use Tests\Fixtures\Protocol\AmqpDebugFixture;
use Tests\Fixtures\Protocol\CoapDebugFixture;
use Tests\Fixtures\Protocol\Iso8583ProxyFixture;
use Tests\Fixtures\Protocol\KafkaDebugFixture;
use Tests\Fixtures\Protocol\KafkaMetadataHostFixture;
use Tests\Fixtures\Protocol\ModbusDebugFixture;
use Tests\Fixtures\Protocol\MqttPublishFixture;
use Tests\Fixtures\Protocol\MqttSubscribeFixture;
use Tests\Fixtures\Protocol\StompSubscribeFixture;
use Tests\Fixtures\Protocol\ThriftFixture;
use Tests\Fixtures\Protocol\XmppDebugFixture;
use Tests\Fixtures\Protocol\XmppNoStarttlsFixture;
use Tests\Fixtures\Protocol\Dnp3DebugFixture;
use Tests\Fixtures\Protocol\Iec104DebugFixture;
use Tests\Fixtures\Protocol\BacnetDebugFixture;
use Tests\Fixtures\Protocol\OpcuaDebugFixture;
use Tests\Fixtures\Protocol\RtpDebugFixture;
use Tests\Fixtures\Protocol\RtcpDebugFixture;
use Tests\Fixtures\Protocol\RtmpDebugFixture;
use Tests\Fixtures\Protocol\Lwm2mDebugFixture;

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

/**
 * 生成自签证书(CN/SAN localhost + 127.0.0.1)供 TLS 降级用例使用
 * @return array [cert, key]
 */
function ensureDebugTlsCert(): array
{
    $dir = APIDOC_STORAGE_PATH;
    $certFile = $dir . 'tls-cert.pem';
    $keyFile = $dir . 'tls-key.pem';
    if (is_file($certFile) && is_file($keyFile)) {
        return [$certFile, $keyFile];
    }
    $cnf = $dir . 'openssl.cnf';
    file_put_contents($cnf, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=localhost\n"
        . "[v3_req]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=CA:FALSE\n");
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf]);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256', 'config' => $cnf, 'req_extensions' => 'v3_req']);
    $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256', 'config' => $cnf, 'x509_extensions' => 'v3_req']);
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);
    file_put_contents($certFile, $certPem);
    file_put_contents($keyFile, $keyPem);
    return [$certFile, $keyFile];
}

$logDir = sys_get_temp_dir();
// 本机 19001 已被 minio 占用,TCP 回显使用 19011
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/tcp_echo_server.php', '127.0.0.1', '19011'], $logDir . '/apidoc-debug-tcp.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/udp_echo_server.php', '127.0.0.1', '19002'], $logDir . '/apidoc-debug-udp.log');
startFixture([PHP_BINARY, '-S', '127.0.0.1:19005', '-t', $root . '/tests/fixtures/debug/http'], $logDir . '/apidoc-debug-http.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/mqtt_broker.php', '127.0.0.1', '19003'], $logDir . '/apidoc-debug-mqtt.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/stomp_server.php', '127.0.0.1', '19004'], $logDir . '/apidoc-debug-stomp.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/kafka_mock.php', '127.0.0.1', '19021'], $logDir . '/apidoc-debug-kafka.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/kafka_mock.php', '127.0.0.1', '19025', 'metadata-ssrf'], $logDir . '/apidoc-debug-kafka-ssrf.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/kafka_mock.php', '127.0.0.1', '19026', 'metadata-host'], $logDir . '/apidoc-debug-kafka-host.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/amqp_mock.php', '127.0.0.1', '19022'], $logDir . '/apidoc-debug-amqp.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/coap_server.php', '127.0.0.1', '19006'], $logDir . '/apidoc-debug-coap.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/modbus_server.php', '127.0.0.1', '19007'], $logDir . '/apidoc-debug-modbus.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/iso8583_server.php', '127.0.0.1', '19008'], $logDir . '/apidoc-debug-iso8583.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/rtsp_server.php', '127.0.0.1', '19009'], $logDir . '/apidoc-debug-rtsp.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/sip_server.php', '127.0.0.1', '19010'], $logDir . '/apidoc-debug-sip.log');
startFixture([PHP_BINARY, '-S', '127.0.0.1:19012', '-t', $root . '/tests/fixtures/debug/http'], $logDir . '/apidoc-debug-hls.log');
startFixture([PHP_BINARY, '-S', '127.0.0.1:19013', $root . '/tests/fixtures/debug/hessian_server.php'], $logDir . '/apidoc-debug-hessian.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/thrift_server.php', '127.0.0.1', '19014'], $logDir . '/apidoc-debug-thrift.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/dubbo_server.php', '127.0.0.1', '19015'], $logDir . '/apidoc-debug-dubbo.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/h2_server.php', '127.0.0.1', '19016'], $logDir . '/apidoc-debug-h2.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/thrift_server.php', '127.0.0.1', '19017', 'silent'], $logDir . '/apidoc-debug-silent.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/thrift_server.php', '127.0.0.1', '19018', 'large'], $logDir . '/apidoc-debug-thrift-large.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/dubbo_server.php', '127.0.0.1', '19019', 'large'], $logDir . '/apidoc-debug-dubbo-large.log');
[$tlsCert, $tlsKey] = ensureDebugTlsCert();
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/tls_http1_server.php', '127.0.0.1', '19020', $tlsCert, $tlsKey], $logDir . '/apidoc-debug-tls.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/xmpp_mock.php', '127.0.0.1', '19023', $tlsCert, $tlsKey], $logDir . '/apidoc-debug-xmpp.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/xmpp_mock.php', '127.0.0.1', '19024', $tlsCert, $tlsKey, 'no-starttls'], $logDir . '/apidoc-debug-xmpp-nostarttls.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_tcp_mock.php', 'dnp3', '19030'], $logDir . '/apidoc-debug-dnp3.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_tcp_mock.php', 'iec104', '19031'], $logDir . '/apidoc-debug-iec104.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_tcp_mock.php', 'opcua', '19033'], $logDir . '/apidoc-debug-opcua.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_tcp_mock.php', 'rtmp', '19036'], $logDir . '/apidoc-debug-rtmp.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_tcp_mock.php', 'opcua-auth-fail', '19038'], $logDir . '/apidoc-debug-opcua-auth.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_tcp_mock.php', 'opcua-bad-policy', '19039'], $logDir . '/apidoc-debug-opcua-policy.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_udp_mock.php', 'bacnet', '19032'], $logDir . '/apidoc-debug-bacnet.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_udp_mock.php', 'rtp', '19034'], $logDir . '/apidoc-debug-rtp.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_udp_mock.php', 'rtcp', '19035'], $logDir . '/apidoc-debug-rtcp.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_udp_mock.php', 'lwm2m', '19037'], $logDir . '/apidoc-debug-lwm2m.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_udp_mock.php', 'bacnet-silent', '19040'], $logDir . '/apidoc-debug-bacnet-silent.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_udp_mock.php', 'lwm2m-silent', '19041'], $logDir . '/apidoc-debug-lwm2m-silent.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_udp_mock.php', 'lwm2m-error', '19042'], $logDir . '/apidoc-debug-lwm2m-error.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_tcp_mock.php', 'silent', '19043'], $logDir . '/apidoc-debug-industrial-silent.log');
startFixture([PHP_BINARY, $root . '/tests/fixtures/debug/industrial_udp_mock.php', 'echo-large', '19044'], $logDir . '/apidoc-debug-echo-large.log');
usleep(100000);
if (!waitPort('127.0.0.1', 19011) || !waitPort('127.0.0.1', 19005) || !waitPort('127.0.0.1', 19003) || !waitPort('127.0.0.1', 19004)
    || !waitPort('127.0.0.1', 19007) || !waitPort('127.0.0.1', 19008) || !waitPort('127.0.0.1', 19009)
    || !waitPort('127.0.0.1', 19012) || !waitPort('127.0.0.1', 19013)
    || !waitPort('127.0.0.1', 19014) || !waitPort('127.0.0.1', 19015) || !waitPort('127.0.0.1', 19016)
    || !waitPort('127.0.0.1', 19017) || !waitPort('127.0.0.1', 19018) || !waitPort('127.0.0.1', 19019)
    || !waitPort('127.0.0.1', 19020) || !waitPort('127.0.0.1', 19021) || !waitPort('127.0.0.1', 19022)
    || !waitPort('127.0.0.1', 19023) || !waitPort('127.0.0.1', 19024) || !waitPort('127.0.0.1', 19025)
    || !waitPort('127.0.0.1', 19026) || !waitPort('127.0.0.1', 19030) || !waitPort('127.0.0.1', 19031)
    || !waitPort('127.0.0.1', 19033) || !waitPort('127.0.0.1', 19036) || !waitPort('127.0.0.1', 19038)
    || !waitPort('127.0.0.1', 19039)) {
    echo "FAIL  fixture 服务未就绪\n";
    exit(1);
}
usleep(100000);

// ---------- 配置 ----------
$config = require $root . '/src/config.php';
$config['apps'] = [['key' => 'api', 'title' => 'API', 'path' => 'tests/fixtures/protocol']];
$config['cache'] = ['enable' => false, 'folder' => 'apidoc'];
// 测试固定使用回环 fixture;默认拒绝私网的策略由下方 SSRF 用例单独验证
$config['protocols']['debug'] = [
    'relay_enable' => true,
    'allow_private_networks' => true,
    // XMPP STARTTLS 只信任服务端配置的 CA;测试用自签证书作为信任锚
    'tls_ca_file' => $tlsCert,
];
ConfigProvider::set($config);

$rawApi = 'Tests\Fixtures\Protocol\RawFixture@echo';
$httpApi = 'Tests\Fixtures\Protocol\HttpRelayFixture@echo';

// ---------- SSRF 守卫 ----------
/**
 * @return bool 目标是否被守卫拒绝
 */
function guardRejects(string $target, array $config): bool
{
    try {
        TargetGuard::check(DebugTarget::parse($target), $config);
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
}

$strictGuard = ['allow_private_networks' => false, 'allow_hosts' => [], 'allow_unix_sockets' => false];
$privateGuard = ['allow_private_networks' => true, 'allow_hosts' => [], 'allow_unix_sockets' => false];
$blockedTargets = [
    'loopback v4' => 'http://127.0.0.1:80/',
    'private 10/8' => 'http://10.0.0.1:80/',
    'private 172.16/12' => 'http://172.16.0.1:80/',
    'private 192.168/16' => 'http://192.168.1.1:80/',
    'link local' => 'http://169.254.1.1:80/',
    'cgnat' => 'http://100.64.0.1:80/',
    'unspecified' => 'http://0.0.0.0:80/',
    'multicast' => 'http://224.0.0.1:80/',
    'loopback v6' => 'http://[::1]:80/',
    'link local v6' => 'http://[fe80::1]:80/',
    'ula v6' => 'http://[fc00::1]:80/',
    'mapped v4' => 'http://[::ffff:127.0.0.1]:80/',
    'compatible v4' => 'http://[::127.0.0.1]:80/',
    'nat64 v4' => 'http://[64:ff9b::7f00:1]:80/',
    '6to4 v4' => 'http://[2002:7f00:1::]:80/',
    'translated v4' => 'http://[::ffff:0:127.0.0.1]:80/',
];
foreach ($blockedTargets as $label => $blockedTarget) {
    check('ssrf blocks ' . $label, guardRejects($blockedTarget, $strictGuard));
}
$metadataTargets = [
    'aws v4' => 'http://169.254.169.254/latest/meta-data/',
    'aws v6' => 'http://[fd00:ec2::254]/',
    'mapped metadata' => 'http://[::ffff:169.254.169.254]/',
    'alibaba' => 'http://100.100.100.200/',
];
foreach ($metadataTargets as $label => $metadataTarget) {
    check('ssrf blocks metadata ' . $label . ' even with private networks', guardRejects($metadataTarget, $privateGuard));
}
check('ssrf allows loopback when explicitly enabled', !guardRejects('http://127.0.0.1:19005/', $privateGuard));
check('ssrf allows public v4', !guardRejects('http://8.8.8.8:80/', $strictGuard));
check('ssrf allows public v6', !guardRejects('http://[2606:4700::1111]:80/', $strictGuard));
check('ssrf blocks host resolving to loopback', guardRejects('http://localhost:19005/', $strictGuard));
check('ssrf allow_hosts exact', TargetGuard::hostAllowed(['allow_hosts' => ['api.example.com']], 'api.example.com')
    && !TargetGuard::hostAllowed(['allow_hosts' => ['api.example.com']], 'evil.example.com'));
check('ssrf allow_hosts wildcard', TargetGuard::hostAllowed(['allow_hosts' => ['*.internal.example.com']], 'a.internal.example.com')
    && !TargetGuard::hostAllowed(['allow_hosts' => ['*.internal.example.com']], 'notinternal.example.com')
    && !TargetGuard::hostAllowed(['allow_hosts' => ['*.internal.example.com']], 'internal.example.com.evil'));
check('ssrf allow_hosts checked before DNS', (function () use ($strictGuard) {
    $config = $strictGuard;
    $config['allow_hosts'] = ['api.example.com'];
    try {
        TargetGuard::check(DebugTarget::parse('http://not-in-list.invalid:80/'), $config);
        return false;
    } catch (InvalidArgumentException $e) {
        return strpos($e->getMessage(), 'allow_hosts') !== false;
    }
})());
check('ssrf unix sockets disabled by default', guardRejects('unix:///tmp/apidoc.sock', $strictGuard));
check('ssrf unix sockets gated by config', !guardRejects('unix:///tmp/apidoc.sock', [
    'allow_private_networks' => false, 'allow_hosts' => [], 'allow_unix_sockets' => true,
]));
$strictProxyConfig = $config;
$strictProxyConfig['protocols']['debug'] = ['relay_enable' => true, 'allow_private_networks' => false];
$ssrfProxy = (new DebugProxy($strictProxyConfig))->send('api', $rawApi, ['payload' => 'x', 'timeout' => 1]);
check('debug relay rejects private target by default', ($ssrfProxy['ok'] ?? true) === false
    && strpos((string)($ssrfProxy['error'] ?? ''), 'private/loopback/link-local') !== false);
$pinnedRaw = (new RawDriver())->send(['payload' => 'pin', 'encoding' => 'text', 'timeout' => 2], [
    'target' => 'tcp://pinned.invalid:19011',
    'guard' => ['host' => 'pinned.invalid', 'port' => 19011, 'ips' => ['127.0.0.1'], 'unix' => false],
    'timeout' => 2,
]);
check('raw driver connects only to the pinned IP', ($pinnedRaw['body'] ?? '') === 'pin');
$pinnedTarget = DebugTarget::parse('http://localhost:19005/headers.php');
$pinnedInfo = TargetGuard::check($pinnedTarget, $privateGuard);
$pinnedHttp = HttpClient::request(array_merge([
    'url' => 'http://localhost:19005/headers.php',
    'timeout' => 3,
    'maxBytes' => 1024,
], TargetGuard::httpOptions($pinnedTarget, ['guard' => $pinnedInfo])));
check('http relay pins hostname but preserves Host header', ($pinnedHttp['body'] ?? '') === 'localhost:19005');

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
// 客户端传入的 target/host/port 不能进入驱动输入,目标只取注解
$buildRequest = new ReflectionMethod(DebugProxy::class, 'buildRequest');
if (PHP_VERSION_ID < 80100) {
    $buildRequest->setAccessible(true);
}
$built = $buildRequest->invoke($proxy, $config['protocols']['debug'], [
    'defaults' => ['target' => 'tcp://127.0.0.1:19001', 'encoding' => 'text', 'timeout' => 2],
], [
    'target' => 'tcp://127.0.0.1:19999',
    'host' => '127.0.0.1',
    'port' => 19999,
    'payload' => 'hi',
    'fields' => [
        'target' => 'tcp://127.0.0.1:19998',
        'host' => '127.0.0.1',
        'port' => 19998,
        'qos' => 1,
    ],
]);
check('buildRequest drops client target/host/port', ($built['ok'] ?? false) === true
    && $built['target'] === 'tcp://127.0.0.1:19001'
    && !array_key_exists('target', $built['input'] ?? [])
    && !array_key_exists('host', $built['input'] ?? [])
    && !array_key_exists('port', $built['input'] ?? [])
    && ($built['input']['payload'] ?? '') === 'hi'
    && ($built['input']['qos'] ?? 0) === 1);

// ---------- 编码优先级 / 未知编码 ----------
$encodingDefaults = ['defaults' => ['target' => 'tcp://127.0.0.1:19011', 'encoding' => 'text', 'timeout' => 2]];
$topEncoding = $buildRequest->invoke($proxy, $config['protocols']['debug'], $encodingDefaults, [
    'encoding' => 'hex',
    'fields' => ['encoding' => 'base64', 'payload' => 'hi'],
]);
check('top-level encoding wins over fields encoding', ($topEncoding['ok'] ?? false) === true
    && ($topEncoding['input']['encoding'] ?? '') === 'hex');
$fieldEncoding = $buildRequest->invoke($proxy, $config['protocols']['debug'], $encodingDefaults, [
    'fields' => ['encoding' => 'base64', 'payload' => 'aGk='],
]);
check('fields encoding used when top-level absent', ($fieldEncoding['ok'] ?? false) === true
    && ($fieldEncoding['input']['encoding'] ?? '') === 'base64');
$badEncoding = $buildRequest->invoke($proxy, $config['protocols']['debug'], $encodingDefaults, ['encoding' => 'utf-7']);
check('unknown encoding rejected', ($badEncoding['ok'] ?? true) === false
    && ($badEncoding['error'] ?? '') === 'unsupported encoding');
$encodingEcho = $proxy->send('api', $rawApi, [
    'payload' => '6869',
    'encoding' => 'hex',
    'fields' => ['encoding' => 'text'],
    'timeout' => 2,
]);
check('top-level encoding reaches driver', ($encodingEcho['ok'] ?? false) === true
    && ($encodingEcho['body'] ?? '') === '6869');

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
$sameRedirect = $proxy->send('api', 'Tests\Fixtures\Protocol\HttpSameOriginRedirectFixture@echo', ['timeout' => 2]);
check('same-origin redirect followed', ($sameRedirect['ok'] ?? false) === true && ($sameRedirect['status'] ?? 0) === 200);
$crossRedirect = $proxy->send('api', 'Tests\Fixtures\Protocol\HttpRedirectFixture@echo', ['timeout' => 2]);
check('cross-host redirect blocked', ($crossRedirect['ok'] ?? true) === false
    && ($crossRedirect['error'] ?? '') === 'cross-host redirect blocked');
$hostHeader = $proxy->send('api', 'Tests\Fixtures\Protocol\HttpHeadersFixture@echo', [
    'headers' => ['Host' => 'evil.example', 'X-Test' => '1'],
    'timeout' => 2,
]);
check('client Host header dropped', ($hostHeader['ok'] ?? false) === true
    && ($hostHeader['body'] ?? '') === '127.0.0.1:19005');

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

// ---------- kafka / amqp / xmpp 驱动 ----------
check('batch 3b drivers registered', DebugRegistry::driver('kafka') instanceof KafkaDriver
    && DebugRegistry::driver('amqp') instanceof AmqpDriver
    && DebugRegistry::driver('xmpp') instanceof XmppDriver
    && count(DebugRegistry::names()) === 27);
check('batch 3c drivers registered', DebugRegistry::driver('dnp3') instanceof Dnp3Driver
    && DebugRegistry::driver('iec104') instanceof Iec104Driver
    && DebugRegistry::driver('bacnet') instanceof BacnetDriver
    && DebugRegistry::driver('opcua') instanceof OpcuaDriver
    && DebugRegistry::driver('rtp') instanceof RtpDriver
    && DebugRegistry::driver('rtcp') instanceof RtcpDriver
    && DebugRegistry::driver('rtmp') instanceof RtmpDriver
    && DebugRegistry::driver('lwm2m') instanceof Lwm2mDriver);
check('capability list hides unavailable drivers', in_array('http3', DebugRegistry::names(), true)
    && (Http3Driver::available() || !in_array('http3', DebugRegistry::availableNames(), true)));
check('xmpp driver available', XmppDriver::available());

$loopGuard = ['host' => '127.0.0.1', 'port' => 19021, 'ips' => ['127.0.0.1'], 'unix' => false];
$kafkaCtx = [
    'target' => 'kafka://127.0.0.1:19021',
    'guard' => $loopGuard,
    'timeout' => 2,
    'maxBytes' => 1048576,
    'debugConfig' => $config['protocols']['debug'],
];
$kafka = new KafkaDriver();
check('kafka requires topic', ($kafka->send([], $kafkaCtx)['ok'] ?? true) === false);
check('kafka rejects produce', (function () use ($kafka, $kafkaCtx) {
    $res = $kafka->send(['action' => 'produce', 'topic' => 'debug'], $kafkaCtx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'read-only') !== false;
})());
check('kafka rejects invalid offset', (function () use ($kafka, $kafkaCtx) {
    $res = $kafka->send(['topic' => 'debug', 'offset' => 'middle'], $kafkaCtx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'invalid kafka offset') !== false;
})());
check('kafka rejects invalid partition', (function () use ($kafka, $kafkaCtx) {
    $res = $kafka->send(['topic' => 'debug', 'partition' => 'x'], $kafkaCtx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'partition') !== false;
})());
$kafkaFetch = $kafka->send(['topic' => 'debug', 'offset' => 'earliest', 'messages' => 2], $kafkaCtx);
$kafkaMessages = json_decode((string)($kafkaFetch['body'] ?? ''), true)['messages'] ?? [];
check('kafka fetch ok', ($kafkaFetch['ok'] ?? false) === true && ($kafkaFetch['encoding'] ?? '') === 'text');
check('kafka record batch decoded', count($kafkaMessages) === 2
    && ($kafkaMessages[0]['offset'] ?? -1) === 0
    && ($kafkaMessages[0]['payload'] ?? []) === ['encoding' => 'utf8', 'data' => 'hello']
    && ($kafkaMessages[1]['payload'] ?? []) === ['encoding' => 'base64', 'data' => 'AP8=']);
$kafkaSasl = $kafka->send(['topic' => 'debug', 'username' => 'debug', 'password' => 'secret', 'messages' => 1], $kafkaCtx);
check('kafka SASL PLAIN fetch', ($kafkaSasl['ok'] ?? false) === true);
check('kafka SASL failure redacts password', (function () use ($kafka, $kafkaCtx) {
    $res = $kafka->send(['topic' => 'debug', 'username' => 'debug', 'password' => 'secret-wrong'], $kafkaCtx);
    $error = (string)($res['error'] ?? '');
    return ($res['ok'] ?? true) === false && strpos($error, 'SASL') !== false
        && strpos($error, 'secret-wrong') === false && strpos($error, '***') !== false;
})());
$kafkaEmpty = $kafka->send(['topic' => 'debug', 'offset' => '9', 'messages' => 1, 'duration_ms' => 150], $kafkaCtx);
check('kafka empty fetch honors duration_ms', ($kafkaEmpty['ok'] ?? false) === true
    && json_decode((string)($kafkaEmpty['body'] ?? ''), true) === ['messages' => []]);
check('kafka TLS target rejected', (function () use ($kafka, $kafkaCtx) {
    $ctx = $kafkaCtx;
    $ctx['target'] = 'kafkas://127.0.0.1:19021';
    $res = $kafka->send(['topic' => 'debug'], $ctx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'kafkas://') !== false;
})());
check('kafka rejects tcp alias like Rust', (function () use ($kafka, $kafkaCtx) {
    $ctx = $kafkaCtx;
    $ctx['target'] = 'tcp://127.0.0.1:19021';
    $res = $kafka->send(['topic' => 'debug'], $ctx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'kafka://') !== false;
})());
check('kafka rejects comma-separated bootstrap', (function () use ($kafka, $kafkaCtx) {
    $ctx = $kafkaCtx;
    $ctx['target'] = 'kafka://127.0.0.1:19021,127.0.0.1:19026';
    $res = $kafka->send(['topic' => 'debug'], $ctx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'multiple bootstrap') !== false;
})());
$kafkaSsrfCtx = $kafkaCtx;
$kafkaSsrfCtx['target'] = 'kafka://127.0.0.1:19025';
$kafkaSsrf = $kafka->send(['topic' => 'debug'], $kafkaSsrfCtx);
check('kafka metadata broker revalidated by SSRF guard', ($kafkaSsrf['ok'] ?? true) === false
    && strpos((string)($kafkaSsrf['error'] ?? ''), 'metadata broker') !== false
    && strpos((string)($kafkaSsrf['error'] ?? ''), 'blocked') !== false);

$amqpGuard = $loopGuard;
$amqpGuard['port'] = 19022;
$amqpCtx = ['target' => 'amqp://127.0.0.1:19022', 'guard' => $amqpGuard, 'timeout' => 2, 'maxBytes' => 1048576];
$amqp = new AmqpDriver();
check('amqp publish requires routing key', (function () use ($amqp, $amqpCtx) {
    $res = $amqp->send(['payload' => 'x'], $amqpCtx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'routing_key') !== false;
})());
check('amqp consume requires queue', (function () use ($amqp, $amqpCtx) {
    $res = $amqp->send(['consume' => 'true'], $amqpCtx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'queue') !== false;
})());
$amqpPublish = $amqp->send([
    'routing_key' => 'debug/echo',
    'payload' => 'amqp-hello',
    'encoding' => 'text',
    'username' => 'debug',
    'password' => 'secret',
], $amqpCtx);
check('amqp publish confirmed', ($amqpPublish['ok'] ?? false) === true
    && json_decode((string)($amqpPublish['body'] ?? ''), true) === ['published' => true]);
$amqpConsume = $amqp->send([
    'consume' => 'true',
    'queue' => 'debug/echo',
    'username' => 'debug',
    'password' => 'secret',
    'messages' => 1,
    'duration_ms' => 500,
], $amqpCtx);
$amqpDelivery = json_decode((string)($amqpConsume['body'] ?? ''), true)['delivery'] ?? [];
check('amqp consume round-trips published body', ($amqpConsume['ok'] ?? false) === true
    && ($amqpConsume['encoding'] ?? '') === 'text'
    && ($amqpDelivery['routing_key'] ?? '') === 'debug/echo'
    && ($amqpDelivery['payload'] ?? []) === ['encoding' => 'utf8', 'data' => 'amqp-hello']);
$amqpMany = $amqp->send([
    'consume' => 'true',
    'queue' => 'debug/queue',
    'declare' => true,
    'username' => 'debug',
    'password' => 'secret',
    'messages' => 2,
    'duration_ms' => 500,
], $amqpCtx);
$amqpDeliveries = json_decode((string)($amqpMany['body'] ?? ''), true)['deliveries'] ?? [];
check('amqp consume returns deliveries array', ($amqpMany['ok'] ?? false) === true && count($amqpDeliveries) === 2
    && ($amqpDeliveries[0]['payload']['data'] ?? '') === 'hello'
    && ($amqpDeliveries[1]['payload'] ?? []) === ['encoding' => 'base64', 'data' => 'AP8=']);
$amqpEmpty = $amqp->send([
    'consume' => 'true',
    'queue' => 'debug/empty',
    'username' => 'debug',
    'password' => 'secret',
    'messages' => 1,
    'duration_ms' => 150,
], $amqpCtx);
check('amqp empty consume honors duration_ms', ($amqpEmpty['ok'] ?? false) === true
    && json_decode((string)($amqpEmpty['body'] ?? ''), true) === ['deliveries' => []]);
check('amqp SASL failure redacts password', (function () use ($amqp, $amqpCtx) {
    $res = $amqp->send(['routing_key' => 'x', 'username' => 'debug', 'password' => 'amqp-wrong'], $amqpCtx);
    $error = (string)($res['error'] ?? '');
    return ($res['ok'] ?? true) === false && strpos($error, 'amqp-wrong') === false && strpos($error, '***') !== false;
})());
check('amqp TLS target rejected', (function () use ($amqp, $amqpCtx) {
    $ctx = $amqpCtx;
    $ctx['target'] = 'amqps://127.0.0.1:19022';
    $res = $amqp->send(['routing_key' => 'x'], $ctx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'TLS') !== false;
})());
check('amqp rejects tcp alias like Rust', (function () use ($amqp, $amqpCtx) {
    $ctx = $amqpCtx;
    $ctx['target'] = 'tcp://127.0.0.1:19022';
    $res = $amqp->send(['routing_key' => 'x'], $ctx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'amqp://') !== false;
})());
$amqpPartial = $amqp->send([
    'consume' => 'true',
    'queue' => 'debug/partial',
    'username' => 'debug',
    'password' => 'secret',
    'messages' => 1,
    'duration_ms' => 150,
], $amqpCtx);
check('amqp mid-delivery timeout is an error', ($amqpPartial['ok'] ?? true) === false
    && strpos((string)($amqpPartial['error'] ?? ''), 'announced') !== false);

$xmppGuard = $loopGuard;
$xmppGuard['port'] = 19023;
$xmppCtx = [
    'target' => 'xmpp://127.0.0.1:19023',
    'guard' => $xmppGuard,
    'timeout' => 3,
    'maxBytes' => 1048576,
    'debugConfig' => $config['protocols']['debug'],
];
$xmpp = new XmppDriver();
check('xmpp requires jid', ($xmpp->send([], $xmppCtx)['ok'] ?? true) === false);
check('xmpp rejects invalid jid', (function () use ($xmpp, $xmppCtx) {
    $res = $xmpp->send(['jid' => 'not-a-jid', 'password' => 'secret'], $xmppCtx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'invalid xmpp jid') !== false;
})());
check('xmpp requires password', (function () use ($xmpp, $xmppCtx) {
    $res = $xmpp->send(['jid' => 'debug@localhost/php'], $xmppCtx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'password') !== false;
})());
check('xmpp rejects invalid type', (function () use ($xmpp, $xmppCtx) {
    $res = $xmpp->send(['jid' => 'debug@localhost/php', 'password' => 'secret', 'type' => 'headline'], $xmppCtx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'message type') !== false;
})());
check('xmpp send requires to', (function () use ($xmpp, $xmppCtx) {
    $res = $xmpp->send(['jid' => 'debug@localhost/php', 'password' => 'secret', 'payload' => 'hi'], $xmppCtx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'requires opts.to') !== false;
})());
$xmppSend = $xmpp->send([
    'jid' => 'debug@localhost/php',
    'password' => 'secret',
    'to' => 'echo@localhost',
    'payload' => 'hi',
    'messages' => 2,
    'duration_ms' => 1000,
], $xmppCtx);
$xmppMessages = json_decode((string)($xmppSend['body'] ?? ''), true)['messages'] ?? [];
check('xmpp STARTTLS SASL send and receive', ($xmppSend['ok'] ?? false) === true
    && ($xmppSend['encoding'] ?? '') === 'text' && count($xmppMessages) === 2
    && ($xmppMessages[0] ?? []) === ['from' => 'bot@localhost/1', 'body' => 'hello']
    && ($xmppMessages[1] ?? []) === ['from' => 'echo@localhost/1', 'body' => 'echo:hi']);
check('xmpp outbound body declares xml:lang en', ($xmppMessages[1]['body'] ?? '') === 'echo:hi');
$xmppReceive = $xmpp->send([
    'jid' => 'debug@localhost/php',
    'password' => 'secret',
    'messages' => 1,
    'duration_ms' => 1000,
], $xmppCtx);
$xmppReceiveMessages = json_decode((string)($xmppReceive['body'] ?? ''), true)['messages'] ?? [];
check('xmpp receive-only collects message', ($xmppReceive['ok'] ?? false) === true
    && ($xmppReceiveMessages[0] ?? []) === ['from' => 'bot@localhost/1', 'body' => 'hello']
    && strpos((string)($xmppReceive['body'] ?? ''), 'secret') === false);
$xmppPlain = $xmpp->send([
    'jid' => 'plain@localhost/php',
    'password' => 'secret',
    'messages' => 1,
    'duration_ms' => 1000,
], $xmppCtx);
$xmppPlainMessages = json_decode((string)($xmppPlain['body'] ?? ''), true)['messages'] ?? [];
check('xmpp prefers no-lang body when english is missing', ($xmppPlain['ok'] ?? false) === true
    && ($xmppPlainMessages[0] ?? []) === ['from' => 'bot@localhost/1', 'body' => 'neutral']);
$xmppSilent = $xmpp->send([
    'jid' => 'silent@localhost/php',
    'password' => 'secret',
    'messages' => 1,
    'duration_ms' => 150,
], $xmppCtx);
check('xmpp empty receive honors duration_ms', ($xmppSilent['ok'] ?? false) === true
    && json_decode((string)($xmppSilent['body'] ?? ''), true) === ['messages' => []]);
check('xmpp SASL failure redacts password', (function () use ($xmpp, $xmppCtx) {
    $res = $xmpp->send(['jid' => 'debug@localhost/php', 'password' => 'xmpp-wrong'], $xmppCtx);
    $error = (string)($res['error'] ?? '');
    return ($res['ok'] ?? true) === false && strpos($error, 'SASL') !== false
        && strpos($error, 'xmpp-wrong') === false && strpos($error, '***') !== false;
})());
check('xmpp verifies TLS certificate for JID domain', (function () use ($xmpp, $xmppCtx) {
    $res = $xmpp->send(['jid' => 'debug@wrong.example/php', 'password' => 'secret'], $xmppCtx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'STARTTLS') !== false;
})());
$xmppNoStarttlsCtx = $xmppCtx;
$xmppNoStarttlsCtx['target'] = 'xmpp://127.0.0.1:19024';
$xmppNoStarttls = $xmpp->send(['jid' => 'debug@localhost/php', 'password' => 'secret'], $xmppNoStarttlsCtx);
check('xmpp fails closed without STARTTLS', ($xmppNoStarttls['ok'] ?? true) === false
    && strpos((string)($xmppNoStarttls['error'] ?? ''), 'STARTTLS') !== false);
check('xmpp direct TLS target rejected', (function () use ($xmpp, $xmppCtx) {
    $ctx = $xmppCtx;
    $ctx['target'] = 'xmpps://127.0.0.1:19023';
    $res = $xmpp->send(['jid' => 'debug@localhost/php', 'password' => 'secret'], $ctx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'xmpps') !== false;
})());
check('xmpp rejects tcp alias like Rust', (function () use ($xmpp, $xmppCtx) {
    $ctx = $xmppCtx;
    $ctx['target'] = 'tcp://127.0.0.1:19023';
    $res = $xmpp->send(['jid' => 'debug@localhost/php', 'password' => 'secret'], $ctx);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'xmpp://') !== false;
})());
$tlsMethod = new ReflectionMethod(XmppDriver::class, 'tlsCryptoMethod');
$tlsMethod->setAccessible(true);
$tlsBits = (int)$tlsMethod->invoke(null);
check('xmpp TLS floor is exactly TLS 1.2+', $tlsBits === (STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT));
$xmppPrefix = new XmppDriver();
$nextStanza = new ReflectionMethod(XmppDriver::class, 'nextStanza');
$nextStanza->setAccessible(true);
$parseXml = new ReflectionMethod(XmppDriver::class, 'parseXml');
$parseXml->setAccessible(true);
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
fwrite($pair[0], '<?xml version="1.0"?><s:stream xmlns="jabber:client" xmlns:s="' . XmppDriver::STREAM_NS . '">'
    . '<s:features><starttls xmlns="' . XmppDriver::TLS_NS . '"/></s:features></s:stream>');
$prefixedStanza = $nextStanza->invoke($xmppPrefix, $pair[1], microtime(true) + 1, 4096);
$prefixedDoc = $parseXml->invoke($xmppPrefix, $prefixedStanza);
check('xmpp accepts arbitrary stream prefix', $prefixedDoc->documentElement->localName === 'features'
    && $prefixedDoc->documentElement->namespaceURI === XmppDriver::STREAM_NS);
$prefixedClosed = false;
try {
    $nextStanza->invoke($xmppPrefix, $pair[1], microtime(true) + 1, 4096);
} catch (Throwable $e) {
    $prefixedClosed = strpos($e->getMessage(), 'stream closed') !== false;
}
check('xmpp detects prefixed stream footer', $prefixedClosed);
fclose($pair[0]);
fclose($pair[1]);
$scanElement = new ReflectionMethod(XmppDriver::class, 'scanElement');
$scanElement->setAccessible(true);
$xmppDoctypeRejected = false;
try {
    $scanElement->invoke($xmpp, '<!DOCTYPE x [<!ENTITY y "z">]><message/>');
} catch (Throwable $e) {
    $xmppDoctypeRejected = strpos($e->getMessage(), 'DOCTYPE') !== false;
}
check('xmpp parser rejects DOCTYPE/entity declarations', $xmppDoctypeRejected);
$xmppDepthRejected = false;
try {
    $scanElement->invoke($xmpp, str_repeat('<a>', 40) . str_repeat('</a>', 40));
} catch (Throwable $e) {
    $xmppDepthRejected = strpos($e->getMessage(), 'deeply nested') !== false;
}
check('xmpp parser caps nesting depth', $xmppDepthRejected);

// 驱动只能使用 DebugProxy 注入的 protocols.debug;不能回退到全局 ConfigProvider
$permissiveGlobal = $config;
$permissiveGlobal['protocols']['debug']['allow_hosts'] = [];
ConfigProvider::set($permissiveGlobal);
$strictInjected = $config;
$strictInjected['protocols']['debug']['allow_hosts'] = ['127.0.0.1'];
$kafkaInjectedStrict = (new DebugProxy($strictInjected))->send(
    'api',
    KafkaMetadataHostFixture::class . '@fetch',
    ['fields' => ['topic' => 'debug']]
);
check('kafka metadata broker uses injected allow_hosts', ($kafkaInjectedStrict['ok'] ?? true) === false
    && strpos((string)($kafkaInjectedStrict['error'] ?? ''), 'metadata broker') !== false
    && strpos((string)($kafkaInjectedStrict['error'] ?? ''), 'allow_hosts') !== false);

$strictGlobal = $config;
$strictGlobal['protocols']['debug']['allow_hosts'] = ['127.0.0.1'];
ConfigProvider::set($strictGlobal);
$permissiveInjected = $config;
$permissiveInjected['protocols']['debug']['allow_hosts'] = [];
$kafkaInjectedPermissive = (new DebugProxy($permissiveInjected))->send(
    'api',
    KafkaMetadataHostFixture::class . '@fetch',
    ['fields' => ['topic' => 'debug', 'messages' => 2]]
);
check('kafka metadata broker ignores stricter global config', ($kafkaInjectedPermissive['ok'] ?? false) === true
    && count(json_decode((string)($kafkaInjectedPermissive['body'] ?? ''), true)['messages'] ?? []) === 2);

$globalNoCa = $config;
unset($globalNoCa['protocols']['debug']['tls_ca_file']);
ConfigProvider::set($globalNoCa);
$xmppInjectedCa = (new DebugProxy($config))->send('api', XmppDebugFixture::class . '@send', ['fields' => [
    'jid' => 'debug@localhost/php', 'password' => 'secret', 'messages' => 1,
]]);
check('xmpp uses injected tls_ca_file without global CA', ($xmppInjectedCa['ok'] ?? false) === true
    && (json_decode((string)($xmppInjectedCa['body'] ?? ''), true)['messages'][0]['body'] ?? '') === 'hello');

ConfigProvider::set($config);
$injectedNoCa = $config;
unset($injectedNoCa['protocols']['debug']['tls_ca_file']);
$xmppInjectedNoCa = (new DebugProxy($injectedNoCa))->send('api', XmppDebugFixture::class . '@send', ['fields' => [
    'jid' => 'debug@localhost/php', 'password' => 'secret', 'messages' => 1,
]]);
check('xmpp ignores global CA when injection omits it', ($xmppInjectedNoCa['ok'] ?? true) === false
    && strpos((string)($xmppInjectedNoCa['error'] ?? ''), 'STARTTLS') !== false);
ConfigProvider::set($config);

$kafkaUi = $proxy->send('api', KafkaDebugFixture::class . '@fetch', ['fields' => ['topic' => 'debug', 'messages' => 2]]);
check('kafka driver through relay', ($kafkaUi['ok'] ?? false) === true
    && count(json_decode((string)($kafkaUi['body'] ?? ''), true)['messages'] ?? []) === 2);
$amqpUi = $proxy->send('api', AmqpDebugFixture::class . '@receive', ['fields' => [
    'consume' => 'true', 'queue' => 'debug/single', 'username' => 'debug', 'password' => 'secret', 'messages' => 1,
]]);
check('amqp driver through relay', ($amqpUi['ok'] ?? false) === true
    && (json_decode((string)($amqpUi['body'] ?? ''), true)['delivery']['payload']['data'] ?? '') === 'hello');
$xmppUi = $proxy->send('api', XmppDebugFixture::class . '@send', ['fields' => [
    'jid' => 'debug@localhost/php', 'password' => 'secret', 'messages' => 1,
]]);
check('xmpp driver through relay', ($xmppUi['ok'] ?? false) === true
    && (json_decode((string)($xmppUi['body'] ?? ''), true)['messages'][0]['body'] ?? '') === 'hello');
$xmppNoStarttlsUi = $proxy->send('api', XmppNoStarttlsFixture::class . '@send', ['fields' => [
    'jid' => 'debug@localhost/php', 'password' => 'secret',
]]);
check('xmpp fails closed through relay without STARTTLS', ($xmppNoStarttlsUi['ok'] ?? true) === false
    && strpos((string)($xmppNoStarttlsUi['error'] ?? ''), 'STARTTLS') !== false);
check('batch 3b drivers stay unary, not fake stream', (function () use ($proxy) {
    $res = $proxy->start('api', KafkaDebugFixture::class . '@fetch', ['fields' => ['topic' => 'debug']]);
    return ($res['ok'] ?? true) === false && strpos((string)($res['error'] ?? ''), 'not streaming') !== false;
})());

$strictNewConfig = $config;
$strictNewConfig['protocols']['debug'] = ['relay_enable' => true, 'allow_private_networks' => false, 'tls_ca_file' => $tlsCert];
$strictNew = new DebugProxy($strictNewConfig);
$batch3bDrivers = [
    'kafka' => [KafkaDebugFixture::class . '@fetch', ['topic' => 'debug']],
    'amqp' => [AmqpDebugFixture::class . '@receive', ['consume' => 'true', 'queue' => 'debug/queue']],
    'xmpp' => [XmppDebugFixture::class . '@send', ['jid' => 'debug@localhost/php', 'password' => 'secret']],
];
foreach ($batch3bDrivers as $name => [$api, $fields]) {
    $res = $strictNew->send('api', $api, ['fields' => $fields]);
    check('ssrf blocks batch 3b ' . $name, ($res['ok'] ?? true) === false
        && strpos((string)($res['error'] ?? ''), 'private/loopback/link-local') !== false);
}
$disabledNewConfig = $config;
$disabledNewConfig['protocols']['debug'] = ['relay_enable' => false];
$disabledNew = new DebugProxy($disabledNewConfig);
foreach ($batch3bDrivers as $name => [$api, $fields]) {
    $res = $disabledNew->send('api', $api, ['fields' => $fields]);
    check('disabled relay blocks batch 3b ' . $name, ($res['ok'] ?? true) === false
        && strpos((string)($res['error'] ?? ''), 'relay disabled') !== false);
}

$kafkaSmallConfig = $config;
$kafkaSmallConfig['protocols']['debug']['max_bytes'] = 200;
$kafkaSmall = new DebugProxy($kafkaSmallConfig);
$kafkaCapped = $kafkaSmall->send('api', KafkaDebugFixture::class . '@fetch', ['fields' => ['topic' => 'large', 'messages' => 1]]);
check('kafka response size cap', ($kafkaCapped['ok'] ?? true) === false
    && strpos((string)($kafkaCapped['error'] ?? ''), 'max_bytes') !== false);
$amqpSmallConfig = $config;
$amqpSmallConfig['protocols']['debug']['max_bytes'] = 200;
$amqpSmall = new DebugProxy($amqpSmallConfig);
$amqpCapped = $amqpSmall->send('api', AmqpDebugFixture::class . '@receive', ['fields' => [
    'consume' => 'true', 'queue' => 'debug/large', 'username' => 'debug', 'password' => 'secret', 'messages' => 1,
]]);
check('amqp frame size cap', ($amqpCapped['ok'] ?? true) === false
    && strpos((string)($amqpCapped['error'] ?? ''), 'max_bytes') !== false);
$xmppSmallConfig = $config;
$xmppSmallConfig['protocols']['debug']['max_bytes'] = 128;
$xmppSmall = new DebugProxy($xmppSmallConfig);
$xmppCapped = $xmppSmall->send('api', XmppDebugFixture::class . '@send', ['fields' => [
    'jid' => 'debug@localhost/php', 'password' => 'secret', 'messages' => 1,
]]);
check('xmpp stanza size cap', ($xmppCapped['ok'] ?? true) === false
    && strpos((string)($xmppCapped['error'] ?? ''), 'max_bytes') !== false);

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
$hlsCrossHost = $hls->send(['fetchSegment' => true], ['target' => 'http://127.0.0.1:19012/cross-host.m3u8', 'timeout' => 2]);
check('hls cross-host segment blocked', ($hlsCrossHost['ok'] ?? true) === false
    && ($hlsCrossHost['error'] ?? '') === 'cross-host segment blocked');
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
$hessianBounded = false;
try {
    // 类型串 + 32 位长度 0x7fffffff,但没有任何元素字节
    $hessian->decode("\x56\x03int\x49\x7f\xff\xff\xff");
} catch (InvalidArgumentException $e) {
    $hessianBounded = strpos($e->getMessage(), 'exceeds bounds') !== false;
}
check('hessian list length bounded by remaining bytes', $hessianBounded);
$hessianDeep = 0;
for ($i = 0; $i < 40; $i++) {
    $hessianDeep = [$hessianDeep];
}
$hessianDepthThrew = false;
try {
    $hessian->decode($hessian->encodeValue($hessianDeep));
} catch (InvalidArgumentException $e) {
    $hessianDepthThrew = strpos($e->getMessage(), 'nesting exceeds') !== false;
}
check('hessian nesting depth capped', $hessianDepthThrew);
$hessianKeyThrew = false;
try {
    $hessian->decode("\x48\x7a\x91\x92\x54\x5a");
} catch (InvalidArgumentException $e) {
    $hessianKeyThrew = strpos($e->getMessage(), '键类型非法') !== false;
}
check('hessian map rejects non-scalar key', $hessianKeyThrew);

// ---------- thrift / dubbo / http2 / http3 驱动 ----------
check('debug registry has batch 3a drivers', DebugRegistry::driver('thrift') instanceof ThriftDriver
    && DebugRegistry::driver('dubbo') instanceof DubboDriver
    && DebugRegistry::driver('http2') instanceof Http2Driver
    && DebugRegistry::driver('http3') instanceof Http3Driver);
check('thrift fields registered', !array_diff(['method', 'transport', 'schema', 'args', 'payload'], array_column(DebugRegistry::driverFields('thrift'), 'name')));
check('dubbo fields registered', !array_diff(['service', 'method', 'version', 'param_types', 'args', 'payload'], array_column(DebugRegistry::driverFields('dubbo'), 'name')));
check('http2 fields registered', !array_diff(['target', 'method', 'payload', 'headers'], array_column(DebugRegistry::driverFields('http2'), 'name')));
check('http3 fields registered', in_array('target', array_column(DebugRegistry::driverFields('http3'), 'name'), true));
check('thrift mode is relay unary', (new ThriftDriver())->mode() === 'relay-unary');
check('dubbo mode is relay unary', (new DubboDriver())->mode() === 'relay-unary');
check('http2 mode is relay unary', (new Http2Driver())->mode() === 'relay-unary');
check('http3 mode is relay unary', (new Http3Driver())->mode() === 'relay-unary');

$thrift = new ThriftDriver();
$thriftParams = [['name' => 'n', 'field' => 1, 'type' => 'i32']];
check('thrift call frame golden', bin2hex($thrift->buildFrame('ping', $thriftParams, ['n' => 7]))
    === '800100010000000470696e67000000000800010000000700');
check('thrift list frame golden', bin2hex($thrift->buildFrame('put', [['name' => 'items', 'field' => 1, 'type' => 'list<i32>']], ['items' => [1, 2]]))
    === '8001000100000003707574000000000f00010800000002000000010000000200');
$thriftReply = hex2bin('800100020000000470696e67000000000800000000002a00');
check('thrift reply parse', $thrift->parseResponse($thriftReply, [['name' => 'result', 'field' => 0, 'type' => 'i32']]) === ['result' => 42]);
$thriftNested = hex2bin('800100020000000470696e67000000000c00010b00010000000268690000');
check('thrift nested struct decode', $thrift->parseResponse($thriftNested, [['name' => 'user', 'field' => 1, 'type' => 'struct', 'children' => [['name' => 'name', 'field' => 1, 'type' => 'string']]]])
    === ['user' => ['name' => 'hi']]);
$thriftException = false;
try {
    $thrift->parseResponse(hex2bin('8001000300000003657272000000000b000100000004626f6f6d00'));
} catch (InvalidArgumentException $e) {
    $thriftException = strpos($e->getMessage(), 'boom') !== false;
}
check('thrift exception frame decoded', $thriftException);
$thriftThrew = false;
try {
    $thrift->buildFrame('x', [['name' => 'a', 'field' => 1, 'type' => 'nope']], ['a' => 1]);
} catch (InvalidArgumentException $e) {
    $thriftThrew = true;
}
check('thrift unknown type throws', $thriftThrew);
$thriftGoldens = [
    'bool' => [['name' => 'a', 'field' => 1, 'type' => 'bool'], ['a' => true], '80010001000000016d000000000200010100'],
    'byte' => [['name' => 'a', 'field' => 1, 'type' => 'i8'], ['a' => -1], '80010001000000016d00000000030001ff00'],
    'i16' => [['name' => 'a', 'field' => 1, 'type' => 'i16'], ['a' => -2], '80010001000000016d00000000060001fffe00'],
    'i64' => [['name' => 'a', 'field' => 1, 'type' => 'i64'], ['a' => 1], '80010001000000016d000000000a0001000000000000000100'],
    'double' => [['name' => 'a', 'field' => 1, 'type' => 'double'], ['a' => 1.5], '80010001000000016d000000000400013ff800000000000000'],
    'binary' => [['name' => 'a', 'field' => 1, 'type' => 'binary'], ['a' => base64_encode("\x00\x01")], '80010001000000016d000000000b000100000002000100'],
    'map' => [['name' => 'a', 'field' => 1, 'type' => 'map<string,i32>'], ['a' => ['a' => 1]], '80010001000000016d000000000d00010b080000000100000001610000000100'],
    'struct' => [['name' => 'a', 'field' => 1, 'type' => 'struct', 'children' => [['name' => 'f', 'field' => 1, 'type' => 'string']]], ['a' => ['f' => 'hi']], '80010001000000016d000000000c00010b00010000000268690000'],
];
foreach ($thriftGoldens as $label => $golden) {
    check('thrift frame golden ' . $label, bin2hex($thrift->buildFrame('m', [$golden[0]], $golden[1])) === $golden[2]);
}
$thriftBounded = false;
try {
    // 字段1是 list<i32>,声明长度 0x7fffffff 但无元素
    $thrift->parseResponse(hex2bin('80010002000000016d000000000f0001087fffffff00'));
} catch (InvalidArgumentException $e) {
    $thriftBounded = strpos($e->getMessage(), 'exceeds bounds') !== false;
}
check('thrift list length bounded by remaining bytes', $thriftBounded);
$thriftDeepType = str_repeat('list<', 40) . 'i32' . str_repeat('>', 40);
$thriftDepthThrew = false;
try {
    $thrift->buildFrame('m', [['name' => 'a', 'field' => 1, 'type' => $thriftDeepType]], ['a' => []]);
} catch (InvalidArgumentException $e) {
    $thriftDepthThrew = strpos($e->getMessage(), 'nesting exceeds') !== false;
}
check('thrift schema nesting depth capped', $thriftDepthThrew);
$thriftShapeThrew = false;
try {
    $thrift->buildFrame('m', [['name' => 'a', 'field' => 1, 'type' => 'list<i32>']], ['a' => ['x' => 1]]);
} catch (InvalidArgumentException $e) {
    $thriftShapeThrew = true;
}
check('thrift list rejects associative array', $thriftShapeThrew);
$thriftMapShapeThrew = false;
try {
    $thrift->buildFrame('m', [['name' => 'a', 'field' => 1, 'type' => 'map<string,i32>']], ['a' => [1, 2]]);
} catch (InvalidArgumentException $e) {
    $thriftMapShapeThrew = true;
}
check('thrift string-key map rejects list', $thriftMapShapeThrew);
check('thrift missing required param rejected', ($thrift->send(['method' => 'x', 'schema' => ['params' => [['name' => 'a', 'field' => 1, 'type' => 'i32', 'required' => true]]]], ['target' => 'tcp://127.0.0.1:19014'])['ok'] ?? true) === false);
check('thrift missing method rejected', ($thrift->send([], ['target' => 'tcp://127.0.0.1:19014'])['ok'] ?? true) === false);
check('thrift rejects udp target', ($thrift->send(['method' => 'x', 'payload' => '00'], ['target' => 'udp://127.0.0.1:19014'])['ok'] ?? true) === false);
check('thrift rejects unknown transport', ($thrift->send(['method' => 'x', 'payload' => '00', 'transport' => 'ws'], ['target' => 'tcp://127.0.0.1:19014'])['ok'] ?? true) === false);
$thriftSchema = ['returned' => [['name' => 'result', 'field' => 0, 'type' => 'i32']]];
$thriftRes = $thrift->send(['method' => 'ping', 'schema' => $thriftSchema, 'args' => []], ['target' => 'tcp://127.0.0.1:19014', 'timeout' => 2]);
check('thrift tcp send ok', ($thriftRes['ok'] ?? false) === true && json_decode((string)($thriftRes['body'] ?? ''), true) === ['result' => 42]);
$thriftRaw = $thrift->send(['payload' => bin2hex($thrift->buildFrame('ping', [], [])), 'schema' => $thriftSchema], ['target' => 'tcp://127.0.0.1:19014', 'timeout' => 2]);
check('thrift raw payload fallback', ($thriftRaw['ok'] ?? false) === true);
$thriftHttp = $thrift->send(['method' => 'ping', 'transport' => 'http', 'schema' => $thriftSchema], ['target' => 'http://127.0.0.1:19005/thrift_server.php', 'timeout' => 2]);
check('thrift http transport ok', ($thriftHttp['ok'] ?? false) === true && ($thriftHttp['meta']['status'] ?? 0) === 200);
check('thrift request max bytes', ($thrift->send(['method' => 'ping', 'schema' => $thriftSchema], ['target' => 'tcp://127.0.0.1:19014', 'maxBytes' => 10])['ok'] ?? true) === false);
$thriftTimeout = $thrift->send(['method' => 'ping', 'schema' => $thriftSchema], ['target' => 'tcp://127.0.0.1:19017', 'timeout' => 0.5]);
check('thrift read timeout', ($thriftTimeout['ok'] ?? true) === false && ($thriftTimeout['error'] ?? '') === 'thrift read timed out');
$thriftLarge = $thrift->send(['method' => 'ping', 'schema' => $thriftSchema], ['target' => 'tcp://127.0.0.1:19018', 'timeout' => 2, 'maxBytes' => 64]);
check('thrift response max bytes', ($thriftLarge['ok'] ?? true) === false && ($thriftLarge['error'] ?? '') === 'thrift response exceeds max_bytes');

$dubbo = new DubboDriver();
check('dubbo frame golden', bin2hex($dubbo->frame(hex2bin('01026f6b'), 5))
    === 'dabbc20000000000000000050000000401026f6b');
check('dubbo request body golden', bin2hex($dubbo->encodeRequest('a', '', 'm', 'I', [7])) === '05322e302e32016100016d014997485a');
check('dubbo descriptor count', $dubbo->countDescriptors('Ljava/lang/String;I[B') === 3
    && $dubbo->countDescriptors('[[J') === 1
    && $dubbo->countDescriptors('') === 0);
$dubboThrew = false;
try {
    $dubbo->countDescriptors('Ljava/lang/String');
} catch (InvalidArgumentException $e) {
    $dubboThrew = true;
}
check('dubbo invalid descriptor throws', $dubboThrew);
check('dubbo response value flag', $dubbo->parseResponse("\x01\x02ok") === 'ok');
check('dubbo response null flag', $dubbo->parseResponse("\x02") === null);
$dubboException = false;
try {
    $dubbo->parseResponse("\x00" . $hessian->encodeMap(['detailMessage' => 'boom']));
} catch (InvalidArgumentException $e) {
    $dubboException = strpos($e->getMessage(), 'boom') !== false;
}
check('dubbo exception flag decoded', $dubboException);
check('dubbo unknown flag throws', (function () use ($dubbo) {
    try {
        $dubbo->parseResponse("\x09");
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
})());
check('dubbo flag 4 decodes trailing attachments', $dubbo->parseResponse(
    "\x04" . $hessian->encodeValue('ok') . $hessian->encodeMap(['trace' => '1'])
) === 'ok');
$dubboFlag4Missing = false;
try {
    $dubbo->parseResponse("\x04" . $hessian->encodeValue('ok'));
} catch (InvalidArgumentException $e) {
    $dubboFlag4Missing = true;
}
check('dubbo flag 4 requires attachments', $dubboFlag4Missing);
$dubboFlag4Bad = false;
try {
    $dubbo->parseResponse("\x04" . $hessian->encodeValue('ok') . $hessian->encodeString('x'));
} catch (InvalidArgumentException $e) {
    $dubboFlag4Bad = strpos($e->getMessage(), 'attachments must be a map') !== false;
}
check('dubbo flag 4 rejects non-map attachments', $dubboFlag4Bad);
$dubboBounded = false;
try {
    $dubbo->parseResponse("\x01\x56\x03int\x49\x7f\xff\xff\xff");
} catch (InvalidArgumentException $e) {
    $dubboBounded = strpos($e->getMessage(), 'exceeds bounds') !== false;
}
check('dubbo response inherits hessian list bounds', $dubboBounded);
check('dubbo rejects args without param_types', ($dubbo->send(['service' => 's', 'method' => 'm', 'args' => [1]], ['target' => 'tcp://127.0.0.1:19015'])['ok'] ?? true) === false);
check('dubbo rejects descriptor mismatch', ($dubbo->send(['service' => 's', 'method' => 'm', 'param_types' => 'I', 'args' => [1, 2]], ['target' => 'tcp://127.0.0.1:19015'])['ok'] ?? true) === false);
check('dubbo rejects missing service', ($dubbo->send(['method' => 'm'], ['target' => 'tcp://127.0.0.1:19015'])['ok'] ?? true) === false);
check('dubbo rejects udp target', ($dubbo->send(['service' => 's', 'method' => 'm', 'payload' => '00'], ['target' => 'udp://127.0.0.1:19015'])['ok'] ?? true) === false);
$dubboRes = $dubbo->send(['service' => 'com.demo.Echo', 'method' => 'echo', 'param_types' => 'I', 'args' => [7]], ['target' => 'tcp://127.0.0.1:19015', 'timeout' => 2]);
check('dubbo tcp send ok', ($dubboRes['ok'] ?? false) === true && json_decode((string)($dubboRes['body'] ?? ''), true) === 'ok');
check('dubbo response meta status', ($dubboRes['meta']['x-dubbo-status'] ?? 0) === 20);
$dubboBadMagic = $dubbo->send(['service' => 's', 'method' => 'm', 'param_types' => 'I', 'args' => [7]], ['target' => 'tcp://127.0.0.1:19014', 'timeout' => 2]);
check('dubbo rejects invalid response magic', ($dubboBadMagic['ok'] ?? true) === false && ($dubboBadMagic['error'] ?? '') === 'dubbo: invalid response magic');
check('dubbo request max bytes', ($dubbo->send(['service' => 's', 'method' => 'm', 'param_types' => 'I', 'args' => [7]], ['target' => 'tcp://127.0.0.1:19015', 'maxBytes' => 10])['ok'] ?? true) === false);
$dubboTimeout = $dubbo->send(['service' => 's', 'method' => 'm', 'param_types' => 'I', 'args' => [7]], ['target' => 'tcp://127.0.0.1:19017', 'timeout' => 0.5]);
check('dubbo read timeout', ($dubboTimeout['ok'] ?? true) === false && ($dubboTimeout['error'] ?? '') === 'dubbo read timed out');
$dubboLarge = $dubbo->send(['service' => 's', 'method' => 'm', 'param_types' => 'I', 'args' => [7]], ['target' => 'tcp://127.0.0.1:19019', 'timeout' => 2, 'maxBytes' => 128]);
check('dubbo response max bytes', ($dubboLarge['ok'] ?? true) === false && ($dubboLarge['error'] ?? '') === 'dubbo response exceeds max_bytes');

$http2 = new Http2Driver();
$http3Option = null;
$http3OptionThrew = false;
try {
    $http3Option = HttpClient::versionOptionFor(3, true, false);
} catch (RuntimeException $e) {
    $http3OptionThrew = true;
}
check('http3 option is 3ONLY when supported', defined('CURL_HTTP_VERSION_3ONLY')
    ? (!$http3OptionThrew && $http3Option === CURL_HTTP_VERSION_3ONLY)
    : $http3OptionThrew);
check('http3 availability implies 3ONLY support', !Http3Driver::available()
    || (defined('CURL_HTTP_VERSION_3ONLY') && $http3Option === CURL_HTTP_VERSION_3ONLY));
check('http3 availability implies CURL_VERSION_HTTP3 feature', !Http3Driver::available()
    || ((curl_version()['features'] ?? 0) & CURL_VERSION_HTTP3) !== 0);
if (Http2Driver::available()) {
    $http2Res = $http2->send([], ['target' => 'http://127.0.0.1:19016/echo', 'timeout' => 2]);
    check('http2 h2c send ok', ($http2Res['ok'] ?? false) === true && ($http2Res['body'] ?? '') === 'h2-ok' && ($http2Res['status'] ?? 0) === 200);
} else {
    $http2Res = $http2->send([], ['target' => 'http://127.0.0.1:19016/echo', 'timeout' => 2]);
    check('http2 unavailable fails clearly', ($http2Res['ok'] ?? true) === false && strpos((string)($http2Res['error'] ?? ''), 'HTTP/2 unavailable') !== false);
}
check('http2 rejects non-http target', ($http2->send([], ['target' => 'tcp://127.0.0.1:19016'])['ok'] ?? true) === false);
check('http3 requires https', (new Http3Driver())->send([], ['target' => 'http://127.0.0.1:19016/echo'])['error'] === 'HTTP/3 requires an https:// target');
if (!Http3Driver::available()) {
    $http3Res = (new Http3Driver())->send([], ['target' => 'https://127.0.0.1:19016/echo', 'timeout' => 1]);
    check('http3 unavailable fails clearly', ($http3Res['ok'] ?? true) === false && strpos((string)($http3Res['error'] ?? ''), 'HTTP/3 unavailable') !== false);
}
// TLS 服务只支持 HTTP/1.1:固定解析 + 自签 CA 下必须能连,HTTP/2 请求必须失败而不是降级
$tlsTarget = DebugTarget::parse('https://localhost:19020/hello');
$tlsGuard = TargetGuard::check($tlsTarget, $privateGuard);
$tlsOptions = TargetGuard::httpOptions($tlsTarget, ['guard' => $tlsGuard]);
$tlsHttp1 = HttpClient::request(array_merge([
    'url' => 'https://localhost:19020/hello',
    'timeout' => 3,
    'maxBytes' => 1024,
    'httpVersion' => 1,
    'caFile' => $tlsCert,
], $tlsOptions));
check('tls fixture reachable with pinned self-signed CA', ($tlsHttp1['status'] ?? 0) === 200 && ($tlsHttp1['body'] ?? '') === 'hello');
if (HttpClient::http2Available()) {
    $tlsDowngrade = HttpClient::request(array_merge([
        'url' => 'https://localhost:19020/hello',
        'timeout' => 3,
        'maxBytes' => 1024,
        'httpVersion' => 2,
        'caFile' => $tlsCert,
    ], $tlsOptions));
    check('tls HTTP/2 downgrade fails closed', isset($tlsDowngrade['error'])
        && $tlsDowngrade['error'] === 'HTTP/2 required but server negotiated HTTP/1.1');
} else {
    echo "SKIP  tls HTTP/2 downgrade (curl without HTTP/2)\n";
}

$thriftProxyInput = [
    'method' => 'ping',
    'schema' => ['returned' => [['name' => 'result', 'field' => 0, 'type' => 'i32']]],
    'args' => [],
    'timeout' => 2,
];
$thriftProxy = (new DebugProxy($config))->send('api', ThriftFixture::class . '@ping', $thriftProxyInput);
check('new driver relay through app scope', ($thriftProxy['ok'] ?? false) === true
    && json_decode((string)($thriftProxy['body'] ?? ''), true) === ['result' => 42]);
$scopeConfig = $config;
$scopeConfig['apps'][] = ['key' => 'other', 'title' => 'Other', 'path' => 'tests/fixtures/other'];
ConfigProvider::set($scopeConfig);
$thriftProxyDenied = (new DebugProxy($scopeConfig))->send('other', ThriftFixture::class . '@ping', $thriftProxyInput);
ConfigProvider::set($config);
check('new driver relay denied outside app scope', ($thriftProxyDenied['ok'] ?? true) === false
    && strpos((string)($thriftProxyDenied['error'] ?? ''), 'scope') !== false);

// ---------- dnp3 / iec104 / bacnet / opcua / rtp / rtcp / rtmp / lwm2m 驱动 ----------
$industrialCtx = static function (string $target, int $port, array $extra = []): array {
    return array_merge([
        'target' => $target,
        'guard' => ['host' => '127.0.0.1', 'port' => $port, 'ips' => ['127.0.0.1'], 'unix' => false],
        'timeout' => 2,
        'maxBytes' => 1048576,
        'debugConfig' => ['allow_private_networks' => true],
    ], $extra);
};

$dnp3 = new Dnp3Driver();
check('dnp3 crc16 check vector', $dnp3->crc16('123456789') === 0xEA82);
$dnp3Frame = $dnp3->encodeLinkFrame(0xC4, 1, 0, "\xC0\xC0\x01\x3C\x01\x06");
check(
    'dnp3 link frame header and block crc',
    substr($dnp3Frame, 0, 8) === "\x05\x64\x0B\xC4\x01\x00\x00\x00"
    && unpack('v', substr($dnp3Frame, 8, 2))[1] === $dnp3->crc16(substr($dnp3Frame, 2, 6))
    && unpack('v', substr($dnp3Frame, 16, 2))[1] === $dnp3->crc16(substr($dnp3Frame, 10, 6))
);
$dnp3Objects = "\x1E\x01\x00\x00\x01\x01" . pack('V', 42) . "\x01" . pack('V', 0xFFFFFFF9);
$dnp3Points = $dnp3->parseObjects($dnp3Objects);
check(
    'dnp3 object decode',
    count($dnp3Points) === 2 && $dnp3Points[0]['value'] === 42 && $dnp3Points[0]['flags'] === 1
    && $dnp3Points[0]['index'] === 0 && $dnp3Points[1]['value'] === -7 && $dnp3Points[1]['index'] === 1
);
$dnp3Ok = $dnp3->send(['duration_ms' => 50], $industrialCtx('dnp3://127.0.0.1:19030', 19030));
$dnp3Body = json_decode((string)($dnp3Ok['body'] ?? ''), true);
check(
    'dnp3 integrity poll success',
    ($dnp3Ok['ok'] ?? false) === true && ($dnp3Body['points'][0]['value'] ?? null) === 42
    && ($dnp3Body['points'][1]['value'] ?? null) === -7
);
$dnp3Pinned = $dnp3->send(['duration_ms' => 50], $industrialCtx('dnp3://does-not-resolve.invalid:19030', 19030));
check('dnp3 connects pinned guard ip', ($dnp3Pinned['ok'] ?? false) === true);
$dnp3Cap = $dnp3->send(['duration_ms' => 50], $industrialCtx('dnp3://127.0.0.1:19030', 19030, ['maxBytes' => 1]));
check('dnp3 response cap', ($dnp3Cap['error'] ?? '') === 'dnp3 response exceeds max_bytes (1)');
$dnp3Timeout = $dnp3->send(['duration_ms' => 1], $industrialCtx('dnp3://127.0.0.1:19043', 19043, ['timeout' => 0.2]));
check('dnp3 silent peer times out', strpos((string)($dnp3Timeout['error'] ?? ''), 'dnp3 timed out') !== false);
check(
    'dnp3 rejects bad target and range',
    strpos((string)($dnp3->send([], ['target' => 'http://127.0.0.1:19030'])['error'] ?? ''), 'dnp3') !== false
    && ($dnp3->send(['operation' => 'read', 'start' => 5, 'stop' => 1], ['target' => 'dnp3://127.0.0.1:19030'])['error'] ?? '')
        === 'dnp3 start must be <= stop'
    && strpos((string)($dnp3->send(['operation' => 'bogus'], ['target' => 'dnp3://127.0.0.1:19030'])['error'] ?? ''), 'unsupported dnp3 operation') !== false
    && ($dnp3->send(['link_address' => 1, 'source_address' => 1], ['target' => 'dnp3://127.0.0.1:19030'])['error'] ?? '')
        === 'dnp3 link_address and source_address must differ'
);
try {
    $dnp3->parseObjects("\x1E\x01");
    $dnp3Malformed = false;
} catch (InvalidArgumentException $e) {
    $dnp3Malformed = $e->getMessage() === 'truncated dnp3 object header';
}
check('dnp3 rejects truncated object header', $dnp3Malformed);

$iec104 = new Iec104Driver();
$iec104Rows = $iec104->parseAsdus("\x01\x01\x14\x00" . pack('v', 1) . "\x01\x00\x00\x01");
check(
    'iec104 asdu decode',
    count($iec104Rows) === 1 && $iec104Rows[0]['type_id'] === 1 && $iec104Rows[0]['cause'] === 20
    && $iec104Rows[0]['ioa'] === 1 && $iec104Rows[0]['value'] === true
);
$iec104Ok = $iec104->send(['duration_ms' => 50], $industrialCtx('iec104://127.0.0.1:19031', 19031));
$iec104Body = json_decode((string)($iec104Ok['body'] ?? ''), true);
check(
    'iec104 interrogation success',
    ($iec104Ok['ok'] ?? false) === true && ($iec104Body['asdu'][0]['type_id'] ?? null) === 1
    && ($iec104Body['asdu'][0]['value'] ?? null) === true
);
$iec104Cap = $iec104->send(['duration_ms' => 50], $industrialCtx('iec104://127.0.0.1:19031', 19031, ['maxBytes' => 1]));
check('iec104 response cap', ($iec104Cap['error'] ?? '') === 'iec104 response exceeds max_bytes (1)');
$iec104Timeout = $iec104->send([], $industrialCtx('iec104://127.0.0.1:19043', 19043, ['timeout' => 0.2]));
check('iec104 silent peer fails startdt', ($iec104Timeout['error'] ?? '') === 'iec104 STARTDT was not confirmed');
check(
    'iec104 rejects bad target and ioa',
    strpos((string)($iec104->send([], ['target' => 'http://127.0.0.1:19031'])['error'] ?? ''), 'iec104') !== false
    && ($iec104->send(['operation' => 'read'], ['target' => 'iec104://127.0.0.1:19031'])['error'] ?? '')
        === 'iec104 `ioa` is required for read'
    && ($iec104->send(['operation' => 'read', 'ioa' => 0x1000000], ['target' => 'iec104://127.0.0.1:19031'])['error'] ?? '')
        === 'iec104 ioa must fit in 24 bits'
    && strpos((string)($iec104->send(['operation' => 'bogus'], ['target' => 'iec104://127.0.0.1:19031'])['error'] ?? ''), 'unsupported iec104 operation') !== false
);
try {
    $iec104->parseAsdus("\x63\x01\x14\x00" . pack('v', 1) . "\x01\x00\x00\x01");
    $iec104Malformed = false;
} catch (InvalidArgumentException $e) {
    $iec104Malformed = strpos($e->getMessage(), 'unsupported iec104 ASDU type') === 0;
}
check('iec104 rejects unsupported asdu type', $iec104Malformed);

$bacnet = new BacnetDriver();
check('bacnet bvlc golden vector', bin2hex($bacnet->encodeBvlc(false, "\x10\x08")) === '810a000801001008');
check('bacnet bvlc decode', $bacnet->decodeBvlc(hex2bin('810a000801001008')) === "\x10\x08");
$bacnetIAm = "\x10\x00\xC4" . pack('N', (8 << 22) | 1234);
check('bacnet i-am decode', $bacnet->parseIAm($bacnetIAm) === 1234);
check('bacnet i-am ignores non-device object', $bacnet->parseIAm("\x10\x00\xC4" . pack('N', (1 << 22) | 1234)) === null);
$bacnetAck = "\x30\x00\x00\xC4" . pack('N', (8 << 22) | 1234) . "\x19\x55\x3E\x44" . pack('G', 21.5);
check('bacnet complex ack decode', $bacnet->parseReadPropertyAck($bacnetAck) === 21.5);
try {
    $bacnet->parseReadPropertyAck("\x50\x00");
    $bacnetMalformed = false;
} catch (InvalidArgumentException $e) {
    $bacnetMalformed = strpos($e->getMessage(), 'bacnet error PDU') === 0;
}
check('bacnet rejects error pdu', $bacnetMalformed);
try {
    $bacnet->parseAppValue('');
    $bacnetEmpty = false;
} catch (InvalidArgumentException $e) {
    $bacnetEmpty = $e->getMessage() === 'empty bacnet value';
}
check('bacnet rejects empty value', $bacnetEmpty);
$bacnetWho = $bacnet->send(['duration_ms' => 50], $industrialCtx('bacnet://127.0.0.1:19032', 19032));
$bacnetWhoBody = json_decode((string)($bacnetWho['body'] ?? ''), true);
check(
    'bacnet who-is success',
    ($bacnetWho['ok'] ?? false) === true && ($bacnetWhoBody['devices'][0]['instance'] ?? null) === 1234
);
$bacnetRead = $bacnet->send(
    ['operation' => 'read_property', 'object_instance' => 1234, 'property' => 85, 'duration_ms' => 50],
    $industrialCtx('bacnet://127.0.0.1:19032', 19032)
);
check('bacnet read property success', ($bacnetRead['ok'] ?? false) === true
    && json_decode((string)($bacnetRead['body'] ?? ''), true) === ['value' => 21.5]);
$bacnetCap = $bacnet->send(['duration_ms' => 50], $industrialCtx('bacnet://127.0.0.1:19032', 19032, ['maxBytes' => 1]));
check('bacnet response cap', ($bacnetCap['error'] ?? '') === 'bacnet response exceeds max_bytes (1)');
$bacnetTimeout = $bacnet->send(['duration_ms' => 50], $industrialCtx('bacnet://127.0.0.1:19040', 19040));
check('bacnet silent peer times out', strpos((string)($bacnetTimeout['error'] ?? ''), 'bacnet who_is timed out') !== false);
check(
    'bacnet broadcast guard',
    strpos((string)($bacnet->send(['broadcast' => true], $industrialCtx('bacnet://127.0.0.1:19032', 19032, ['debugConfig' => []]))['error'] ?? ''), 'allow_private_networks') !== false
    && strpos((string)($bacnet->send(['broadcast' => true], $industrialCtx('bacnet://224.0.0.1:19032', 19032, ['guard' => ['ips' => ['224.0.0.1'], 'host' => '224.0.0.1', 'port' => 19032, 'unix' => false]]))['error'] ?? ''), 'multicast') !== false
    && strpos((string)($bacnet->send(['broadcast' => true], $industrialCtx('bacnet://255.255.255.255:19032', 19032, ['guard' => ['ips' => ['255.255.255.255'], 'host' => '255.255.255.255', 'port' => 19032, 'unix' => false]]))['error'] ?? ''), 'never a valid') !== false
    && strpos((string)($bacnet->send([], ['target' => 'http://127.0.0.1:19032'])['error'] ?? ''), 'bacnet') !== false
);
check(
    'bacnet requires valid object instance',
    ($bacnet->send(['operation' => 'read_property'], ['target' => 'bacnet://127.0.0.1:19032'])['error'] ?? '')
        === 'bacnet object_instance is required'
    && ($bacnet->send(['operation' => 'read_property', 'object_instance' => 0x400000], ['target' => 'bacnet://127.0.0.1:19032'])['error'] ?? '')
        === 'bacnet object_instance must fit in 22 bits'
    && strpos((string)($bacnet->send(['operation' => 'bogus'], ['target' => 'bacnet://127.0.0.1:19032'])['error'] ?? ''), 'unsupported bacnet operation') !== false
);

$opcua = new OpcuaDriver();
$opcuaRead = $opcua->send(['operation' => 'read', 'node_id' => 'i=85'], $industrialCtx('opc.tcp://127.0.0.1:19033', 19033));
check('opcua read success', ($opcuaRead['ok'] ?? false) === true
    && json_decode((string)($opcuaRead['body'] ?? ''), true) === ['value' => 42, 'status' => 'Good']);
$opcuaBrowse = $opcua->send(['operation' => 'browse', 'node_id' => 'i=85'], $industrialCtx('opc.tcp://127.0.0.1:19033', 19033));
$opcuaBrowseBody = json_decode((string)($opcuaBrowse['body'] ?? ''), true);
check(
    'opcua browse success',
    ($opcuaBrowse['ok'] ?? false) === true
    && ($opcuaBrowseBody['references'][0]['node_id'] ?? '') === 'i=85'
    && ($opcuaBrowseBody['references'][0]['display_name'] ?? '') === 'Demo'
);
$opcuaWrite = $opcua->send(
    ['operation' => 'write', 'node_id' => 'i=85', 'value' => '42'],
    $industrialCtx('opc.tcp://127.0.0.1:19033', 19033)
);
check('opcua write success', ($opcuaWrite['ok'] ?? false) === true
    && json_decode((string)($opcuaWrite['body'] ?? ''), true) === ['status' => 'Good']);
$opcuaAuth = $opcua->send(
    ['username' => 'u', 'password' => 'secret'],
    $industrialCtx('opc.tcp://127.0.0.1:19038', 19038)
);
check(
    'opcua redacts echoed password',
    ($opcuaAuth['ok'] ?? true) === false
    && strpos((string)($opcuaAuth['error'] ?? ''), 'secret') === false
    && strpos((string)($opcuaAuth['error'] ?? ''), '***') !== false
);
$opcuaPolicy = $opcua->send([], $industrialCtx('opc.tcp://127.0.0.1:19039', 19039));
check('opcua rejects non-none policy', strpos((string)($opcuaPolicy['error'] ?? ''), 'only None is supported') !== false);
$opcuaCap = $opcua->send([], $industrialCtx('opc.tcp://127.0.0.1:19033', 19033, ['maxBytes' => 1]));
check('opcua message cap', strpos((string)($opcuaCap['error'] ?? ''), 'exceeds max_bytes (1)') !== false);
$opcuaTimeout = $opcua->send([], $industrialCtx('opc.tcp://127.0.0.1:19043', 19043, ['timeout' => 0.2]));
check('opcua silent peer times out', ($opcuaTimeout['error'] ?? '') === 'opcua read timed out');
check(
    'opcua rejects bad target and node id',
    strpos((string)($opcua->send([], ['target' => 'http://127.0.0.1:19033'])['error'] ?? ''), 'unsupported opcua target scheme') !== false
    && ($opcua->send(['operation' => 'write'], ['target' => 'opc.tcp://127.0.0.1:19033'])['error'] ?? '')
        === 'opcua `value` is required for write'
    && ($opcua->send(['node_id' => 'x=1'], $industrialCtx('opc.tcp://127.0.0.1:19033', 19033))['error'] ?? '')
        === 'node_id must look like `i=85`, `ns=2;i=5` or `s=name`'
    && strpos((string)($opcua->send(['operation' => 'bogus'], ['target' => 'opc.tcp://127.0.0.1:19033'])['error'] ?? ''), 'unsupported opcua operation') !== false
);

$rtp = new RtpDriver();
check(
    'rtp packet golden vector',
    bin2hex($rtp->encodePacket(96, 1, 1000, 0x11223344, 'hi')) === '8060000100000' . '3e8112233446869'
);
$rtpExtended = "\xB3\x60" . pack('n', 7) . pack('N', 160) . pack('N', 0x01020304)
    . pack('N', 0x0A0B0C0D) . pack('N', 0x0E0F1011) . pack('N', 0x12131415)
    . pack('n', 0xBEDE) . pack('n', 1) . 'abcd' . 'abc' . "\x00\x00\x00\x04";
$rtpDecoded = $rtp->decodePacket($rtpExtended);
check(
    'rtp decode csrc extension padding',
    $rtpDecoded !== null && $rtpDecoded['csrc'] === [0x0A0B0C0D, 0x0E0F1011, 0x12131415]
    && $rtpDecoded['payload'] === 'abc' && $rtpDecoded['padding'] === true && $rtpDecoded['extension'] === true
    && $rtpDecoded['sequence'] === 7
);
check('rtp malformed packet returns null', $rtp->decodePacket("\x80") === null);
$rtpEcho = $rtp->send(
    ['payload' => 'hello', 'recv' => true, 'count' => 2, 'ssrc' => 7, 'sequence' => 1, 'duration_ms' => 50],
    $industrialCtx('rtp://127.0.0.1:19034', 19034)
);
$rtpEchoBody = json_decode((string)($rtpEcho['body'] ?? ''), true);
check(
    'rtp echo success',
    ($rtpEcho['ok'] ?? false) === true && count($rtpEchoBody['packets'] ?? []) === 2
    && ($rtpEchoBody['packets'][0]['sequence'] ?? null) === 1
    && ($rtpEchoBody['packets'][1]['sequence'] ?? null) === 2
    && ($rtpEchoBody['packets'][0]['payload']['data'] ?? null) === 'hello'
    && ($rtpEcho['meta']['sent'] ?? 0) === 2 && ($rtpEcho['meta']['received'] ?? 0) === 2
);
$rtpSendCap = $rtp->send(['payload' => 'hello'], $industrialCtx('rtp://127.0.0.1:19034', 19034, ['maxBytes' => 1]));
check('rtp send cap', ($rtpSendCap['error'] ?? '') === 'udp send exceeds max_bytes (1)');
$rtpRecvCap = $rtp->send(['recv' => true], $industrialCtx('rtp://127.0.0.1:19044', 19044, ['maxBytes' => 16]));
check('rtp receive cap', ($rtpRecvCap['error'] ?? '') === 'response exceeds max_bytes (16)');
$rtpQuiet = $rtp->send(['recv' => true, 'duration_ms' => 50], $industrialCtx('rtp://127.0.0.1:19040', 19040));
check('rtp bounded receive returns empty', ($rtpQuiet['ok'] ?? false) === true
    && ($rtpQuiet['meta']['received'] ?? -1) === 0);
check(
    'rtp rejects bad target and payload',
    strpos((string)($rtp->send([], ['target' => 'http://127.0.0.1:19034'])['error'] ?? ''), 'rtp') !== false
    && ($rtp->send(['payload_type' => 128], ['target' => 'rtp://127.0.0.1:19034'])['error'] ?? '') === 'payload_type must be 0-127'
    && ($rtp->send(['payload' => str_repeat('x', 65507)], ['target' => 'rtp://127.0.0.1:19034'])['error'] ?? '')
        === 'rtp payload exceeds 65507 bytes'
);

$rtcp = new RtcpDriver();
$rtcpSdes = $rtcp->encodePacket(202, 7, 'abc');
$rtcpDecoded = $rtcp->decodePacket($rtcpSdes);
check(
    'rtcp sdes golden vector',
    bin2hex($rtcpSdes) === '81ca0003000000070103616263000000'
    && $rtcpDecoded !== null && $rtcpDecoded['packet_type'] === 202 && $rtcpDecoded['ssrc'] === 7
    && $rtcpDecoded['text'] === 'abc' && $rtcpDecoded['length'] === 16
);
$rtcpBye = $rtcp->decodePacket($rtcp->encodePacket(203, 9, 'bye'));
check('rtcp bye reason decode', $rtcpBye !== null && $rtcpBye['text'] === 'bye' && $rtcpBye['packet_type'] === 203);
$rtcpEcho = $rtcp->send(
    ['packet_type' => 'rr', 'ssrc' => 7, 'recv' => true, 'duration_ms' => 50],
    $industrialCtx('rtcp://127.0.0.1:19035', 19035)
);
$rtcpEchoBody = json_decode((string)($rtcpEcho['body'] ?? ''), true);
check(
    'rtcp echo success',
    ($rtcpEcho['ok'] ?? false) === true && ($rtcpEchoBody['packets'][0]['packet_type'] ?? null) === 201
    && ($rtcpEchoBody['packets'][0]['ssrc'] ?? null) === 7
);
$rtcpRecvCap = $rtcp->send(['recv' => true], $industrialCtx('rtcp://127.0.0.1:19044', 19044, ['maxBytes' => 16]));
check('rtcp receive cap', ($rtcpRecvCap['error'] ?? '') === 'response exceeds max_bytes (16)');
check(
    'rtcp rejects bad target and packet type',
    strpos((string)($rtcp->send([], ['target' => 'http://127.0.0.1:19035'])['error'] ?? ''), 'rtcp') !== false
    && strpos((string)($rtcp->send(['packet_type' => 'bogus'], ['target' => 'rtcp://127.0.0.1:19035'])['error'] ?? ''), 'unknown rtcp packet_type') !== false
);

$rtmp = new RtmpDriver();
$rtmpConnect = $rtmp->send([], $industrialCtx('rtmp://127.0.0.1:19036', 19036));
check('rtmp connect success', ($rtmpConnect['ok'] ?? false) === true
    && json_decode((string)($rtmpConnect['body'] ?? ''), true) === [
        'connected' => true, 'operation' => 'connect', 'stream' => null, 'status' => null,
    ]);
$rtmpPublish = $rtmp->send(
    ['operation' => 'publish', 'stream' => 'demo', 'duration_ms' => 500],
    $industrialCtx('rtmp://127.0.0.1:19036', 19036)
);
$rtmpPublishBody = json_decode((string)($rtmpPublish['body'] ?? ''), true);
check('rtmp publish success', ($rtmpPublish['ok'] ?? false) === true
    && ($rtmpPublishBody['stream'] ?? '') === 'demo'
    && ($rtmpPublishBody['status'] ?? '') === 'NetStream.Publish.Start');
$rtmpPlay = $rtmp->send(
    ['operation' => 'play', 'stream' => 'demo', 'duration_ms' => 500],
    $industrialCtx('rtmp://127.0.0.1:19036', 19036)
);
check('rtmp play success', ($rtmpPlay['ok'] ?? false) === true
    && (json_decode((string)($rtmpPlay['body'] ?? ''), true)['status'] ?? '') === 'NetStream.Play.Start');
$rtmpCap = $rtmp->send([], $industrialCtx('rtmp://127.0.0.1:19036', 19036, ['maxBytes' => 1]));
check('rtmp message cap', strpos((string)($rtmpCap['error'] ?? ''), 'rtmp message exceeds max_bytes (1)') !== false);
$rtmpTimeout = $rtmp->send([], $industrialCtx('rtmp://127.0.0.1:19043', 19043, ['timeout' => 0.2]));
check('rtmp silent peer times out', ($rtmpTimeout['error'] ?? '') === 'rtmp session timed out');
check(
    'rtmp rejects bad target and stream',
    strpos((string)($rtmp->send([], ['target' => 'http://127.0.0.1:19036'])['error'] ?? ''), 'rtmp') !== false
    && strpos((string)($rtmp->send(['operation' => 'bogus'], ['target' => 'rtmp://127.0.0.1:19036'])['error'] ?? ''), 'unsupported rtmp operation') !== false
    && ($rtmp->send(['operation' => 'publish'], $industrialCtx('rtmp://127.0.0.1:19036', 19036))['error'] ?? '')
        === 'rtmp publish/play requires opts.stream'
);

$lwm2m = new Lwm2mDriver();
check('lwm2m tlv golden vector', bin2hex($lwm2m->tlvEncodeResource(9, '300')) === 'c209012c');
check(
    'lwm2m tlv decode',
    $lwm2m->tlvDecodeFirst("\xC2\x09\x01\x2C") === [9, "\x01\x2C"]
);
try {
    $lwm2m->tlvDecodeFirst("\x40\x01");
    $lwm2mTlvMalformed = false;
} catch (InvalidArgumentException $e) {
    $lwm2mTlvMalformed = strpos($e->getMessage(), 'unsupported tlv entry type') === 0;
}
check('lwm2m rejects unsupported tlv type', $lwm2mTlvMalformed);
$lwm2mRegister = $lwm2m->send(
    ['operation' => 'register', 'endpoint' => 'node-1', 'duration_ms' => 200],
    $industrialCtx('lwm2m://127.0.0.1:19037', 19037)
);
check('lwm2m register success', ($lwm2mRegister['ok'] ?? false) === true
    && json_decode((string)($lwm2mRegister['body'] ?? ''), true) === ['code' => '2.01', 'location' => '/rd/abc']);
$lwm2mRead = $lwm2m->send(
    ['operation' => 'read', 'object_id' => 3, 'instance_id' => 0, 'resource_id' => 9, 'duration_ms' => 200],
    $industrialCtx('lwm2m://127.0.0.1:19037', 19037)
);
check('lwm2m read success', ($lwm2mRead['ok'] ?? false) === true
    && json_decode((string)($lwm2mRead['body'] ?? ''), true) === ['code' => '2.05', 'value' => 300]);
$lwm2mWrite = $lwm2m->send(
    ['operation' => 'write', 'object_id' => 3, 'instance_id' => 0, 'resource_id' => 9, 'value' => '300', 'duration_ms' => 200],
    $industrialCtx('lwm2m://127.0.0.1:19037', 19037)
);
$lwm2mUpdate = $lwm2m->send(
    ['operation' => 'update', 'location' => '/rd/abc', 'duration_ms' => 200],
    $industrialCtx('lwm2m://127.0.0.1:19037', 19037)
);
$lwm2mDeregister = $lwm2m->send(
    ['operation' => 'deregister', 'location' => '/rd/abc', 'duration_ms' => 200],
    $industrialCtx('lwm2m://127.0.0.1:19037', 19037)
);
check(
    'lwm2m write/update/deregister success',
    ($lwm2mWrite['body'] ?? '') === '{"code":"2.04"}'
    && ($lwm2mUpdate['body'] ?? '') === '{"code":"2.04"}'
    && ($lwm2mDeregister['body'] ?? '') === '{"code":"2.02"}'
);
$lwm2mPinned = $lwm2m->send(
    ['operation' => 'register', 'endpoint' => 'node-1', 'duration_ms' => 200],
    $industrialCtx('lwm2m://does-not-resolve.invalid:19037', 19037)
);
check('lwm2m connects pinned guard ip', ($lwm2mPinned['ok'] ?? false) === true);
$lwm2mError = $lwm2m->send(
    ['operation' => 'register', 'endpoint' => 'node-1', 'duration_ms' => 200],
    $industrialCtx('lwm2m://127.0.0.1:19042', 19042)
);
check('lwm2m unexpected code fails', ($lwm2mError['error'] ?? '')
    === 'lwm2m register failed: expected 2.01, got 4.00: nope');
$lwm2mTimeout = $lwm2m->send(
    ['operation' => 'register', 'endpoint' => 'node-1', 'duration_ms' => 100],
    $industrialCtx('lwm2m://127.0.0.1:19041', 19041, ['timeout' => 0.5])
);
check('lwm2m silent peer times out', ($lwm2mTimeout['error'] ?? '')
    === 'coap request timed out after 100ms (no response)');
$lwm2mCap = $lwm2m->send(
    ['operation' => 'register', 'endpoint' => 'node-1', 'payload' => 'hello'],
    $industrialCtx('lwm2m://127.0.0.1:19037', 19037, ['maxBytes' => 3])
);
check('lwm2m register payload cap', ($lwm2mCap['error'] ?? '') === 'register payload exceeds max_bytes (3)');
check(
    'lwm2m validation errors',
    ($lwm2m->send([], ['target' => 'lwm2m://127.0.0.1:19037'])['error'] ?? '') === 'lwm2m endpoint is required'
    && ($lwm2m->send(['operation' => 'register', 'endpoint' => 'e', 'lifetime' => 'abc'], ['target' => 'lwm2m://127.0.0.1:19037'])['error'] ?? '')
        === 'invalid lifetime `abc`'
    && ($lwm2m->send(['operation' => 'read', 'object_id' => 3, 'instance_id' => 0], ['target' => 'lwm2m://127.0.0.1:19037'])['error'] ?? '')
        === 'lwm2m resource_id is required'
    && ($lwm2m->send(['operation' => 'write', 'object_id' => 3, 'instance_id' => 0, 'resource_id' => 'abc', 'value' => '1'], ['target' => 'lwm2m://127.0.0.1:19037'])['error'] ?? '')
        === 'invalid resource_id'
    && strpos((string)($lwm2m->send(['operation' => 'bogus'], ['target' => 'lwm2m://127.0.0.1:19037'])['error'] ?? ''), 'unsupported lwm2m operation') !== false
    && strpos((string)($lwm2m->send([], ['target' => 'http://127.0.0.1:19037'])['error'] ?? ''), 'lwm2m') !== false
);

$batch3cProxy = (new DebugProxy($config))->send('api', Dnp3DebugFixture::class . '@read', [
    'fields' => ['duration_ms' => 50],
    'timeout' => 2,
]);
check('batch 3c relay through app scope', ($batch3cProxy['ok'] ?? false) === true
    && (json_decode((string)($batch3cProxy['body'] ?? ''), true)['points'][0]['value'] ?? null) === 42);
$batch3cScopeConfig = $config;
$batch3cScopeConfig['apps'][] = ['key' => 'other', 'title' => 'Other', 'path' => 'tests/fixtures/other'];
ConfigProvider::set($batch3cScopeConfig);
$batch3cDenied = (new DebugProxy($batch3cScopeConfig))->send('other', Dnp3DebugFixture::class . '@read', [
    'fields' => ['duration_ms' => 50],
]);
ConfigProvider::set($config);
check('batch 3c relay denied outside app scope', ($batch3cDenied['ok'] ?? true) === false
    && strpos((string)($batch3cDenied['error'] ?? ''), 'scope') !== false);
$batch3cStrictConfig = $config;
$batch3cStrictConfig['protocols']['debug']['allow_private_networks'] = false;
$batch3cStrict = (new DebugProxy($batch3cStrictConfig))->send('api', Dnp3DebugFixture::class . '@read', [
    'fields' => ['duration_ms' => 50],
]);
check('batch 3c relay enforces ssrf guard', ($batch3cStrict['ok'] ?? true) === false
    && strpos((string)($batch3cStrict['error'] ?? ''), 'private') !== false);
$batch3cOffConfig = $config;
$batch3cOffConfig['protocols']['debug']['relay_enable'] = false;
$batch3cOff = (new DebugProxy($batch3cOffConfig))->send('api', Dnp3DebugFixture::class . '@read', []);
check('batch 3c relay disabled fails closed', ($batch3cOff['ok'] ?? true) === false
    && ($batch3cOff['error'] ?? '') === 'relay disabled');

// ---------- 控制器端点 ----------
$controllerConfig = $config;
// auth 全局关闭且 app 无密码时 token 不会被校验,这些用例刻意使用匿名/占位 token
$controllerConfig['protocols']['debug']['relay_require_auth'] = false;
$controllerConfig['request_params'] = [
    'appKey' => 'api',
    'path' => $rawApi,
    'input' => ['payload' => 'hello', 'encoding' => 'text', 'timeout' => 2],
    'token' => 'controller-token',
];
ConfigProvider::set($controllerConfig);
$response = (new Controller())->debugSend();
check('controller debugSend relays', ($response['data']['ok'] ?? false) === true && ($response['data']['body'] ?? '') === 'hello');

$encodedConfig = $controllerConfig;
$encodedConfig['request_params']['path'] = rawurlencode($rawApi);
$encodedConfig['request_params']['token'] = 'controller-encoded-token';
ConfigProvider::set($encodedConfig);
$encodedResponse = (new Controller())->debugSend();
check('controller debugSend decodes path', ($encodedResponse['data']['ok'] ?? false) === true && ($encodedResponse['data']['body'] ?? '') === 'hello');

$deniedConfig = $controllerConfig;
$deniedConfig['request_params'] = ['appKey' => 'api', 'path' => $rawApi, 'input' => ['payload' => 'x']];
unset($deniedConfig['protocols']['debug']['relay_require_auth']);
ConfigProvider::set($deniedConfig);
$denied = (new Controller())->debugSend();
check('controller debugSend requires auth by default', ($denied['data']['ok'] ?? true) === false && ($denied['data']['error'] ?? '') === 'authentication required');

// 仅凭非空 token 不够:auth/app 密码都没配置时 token 不会被校验,必须拒绝
$presenceConfig = $controllerConfig;
unset($presenceConfig['protocols']['debug']['relay_require_auth']);
ConfigProvider::set($presenceConfig);
$presence = (new Controller())->debugSend();
check('presence-only token rejected when no auth configured', ($presence['data']['ok'] ?? true) === false
    && ($presence['data']['error'] ?? '') === 'relay requires auth: enable auth or configure an app password');

// ---------- 分享 apiKeys 范围 ----------
$apiShare = ['type' => 'api', 'apiKeys' => [urlencode($rawApi)]];
check('share api key raw allowed', ApiShare::isApiKeyAllowed($apiShare, $rawApi) === true);
check('share api key encoded allowed', ApiShare::isApiKeyAllowed($apiShare, urlencode($rawApi)) === true);
check('share api key disallowed', ApiShare::isApiKeyAllowed($apiShare, 'Tests\Fixtures\Protocol\UdpRawFixture@echo') === false);
check('non-api share allows any key', ApiShare::isApiKeyAllowed(['type' => 'app'], 'Anything@x') === true);

$shareStore = new ApiShare();
$shareStore->addApiShare([
    'key' => 'allowed-api-share',
    'name' => 'Allowed API share',
    'type' => 'api',
    'apiKeys' => [urlencode($rawApi)],
]);
$shareStore->addApiShare([
    'key' => 'denied-api-share',
    'name' => 'Denied API share',
    'type' => 'api',
    'apiKeys' => [urlencode('Tests\Fixtures\Protocol\UdpRawFixture@echo')],
]);
// auth 开启时旧实现的 init(true) 会因分享无 token 直接拒绝;分享应只走 checkShareAuth
$shareConfig = $config;
$shareConfig['auth']['enable'] = true;
$shareConfig['protocols']['debug']['relay_require_auth'] = true;
$shareConfig['request_params'] = [
    'appKey' => 'api',
    'path' => $rawApi,
    'shareKey' => 'allowed-api-share',
    'input' => ['payload' => 'share', 'encoding' => 'text', 'timeout' => 2],
];
ConfigProvider::set($shareConfig);
$shareAllowed = (new Controller())->debugSend();
check('share api key allowed through init(false)', ($shareAllowed['data']['ok'] ?? false) === true
    && ($shareAllowed['data']['body'] ?? '') === 'share');
$shareDetail = (new Controller())->getApiDetail();
check('share api key allowed for getApiDetail', ($shareDetail['code'] ?? -1) === 0
    && ($shareDetail['data']['name'] ?? '') === 'echo');

$shareDeniedConfig = $shareConfig;
$shareDeniedConfig['request_params']['shareKey'] = 'denied-api-share';
ConfigProvider::set($shareDeniedConfig);
$shareDenied = false;
try {
    (new Controller())->debugSend();
} catch (ErrorException $e) {
    $shareDenied = $e->getMessage() === 'share api not allowed';
}
check('share api key disallowed for debugSend', $shareDenied);
$shareDetailDenied = false;
try {
    (new Controller())->getApiDetail();
} catch (ErrorException $e) {
    $shareDetailDenied = $e->getMessage() === 'share api not allowed';
}
check('share api key disallowed for getApiDetail', $shareDetailDenied);

// 新增驱动的分享范围:api 类型分享只能访问 apiKeys 内的接口
$shareStore->addApiShare([
    'key' => 'thrift-api-share',
    'name' => 'Thrift API share',
    'type' => 'api',
    'apiKeys' => [urlencode(ThriftFixture::class . '@ping')],
]);
$thriftShareConfig = $shareConfig;
$thriftShareConfig['request_params'] = [
    'appKey' => 'api',
    'path' => ThriftFixture::class . '@ping',
    'shareKey' => 'thrift-api-share',
    'input' => $thriftProxyInput,
];
ConfigProvider::set($thriftShareConfig);
$thriftShare = (new Controller())->debugSend();
check('share scoped new driver relay allowed', ($thriftShare['data']['ok'] ?? false) === true
    && json_decode((string)($thriftShare['data']['body'] ?? ''), true) === ['result' => 42]);
$thriftShareDeniedConfig = $thriftShareConfig;
$thriftShareDeniedConfig['request_params']['shareKey'] = 'denied-api-share';
ConfigProvider::set($thriftShareDeniedConfig);
$thriftShareDenied = false;
try {
    (new Controller())->debugSend();
} catch (ErrorException $e) {
    $thriftShareDenied = $e->getMessage() === 'share api not allowed';
}
check('share scoped new driver relay denied', $thriftShareDenied);

$shareStore->addApiShare([
    'key' => 'kafka-api-share',
    'name' => 'Kafka API share',
    'type' => 'api',
    'apiKeys' => [urlencode(KafkaDebugFixture::class . '@fetch')],
]);
$kafkaShareConfig = $shareConfig;
$kafkaShareConfig['request_params'] = [
    'appKey' => 'api',
    'path' => KafkaDebugFixture::class . '@fetch',
    'shareKey' => 'kafka-api-share',
    'input' => ['fields' => ['topic' => 'debug', 'messages' => 1], 'timeout' => 2],
];
ConfigProvider::set($kafkaShareConfig);
$kafkaShare = (new Controller())->debugSend();
check('share scoped kafka relay allowed', ($kafkaShare['data']['ok'] ?? false) === true
    && count(json_decode((string)($kafkaShare['data']['body'] ?? ''), true)['messages'] ?? []) >= 1);
$kafkaShareDeniedConfig = $kafkaShareConfig;
$kafkaShareDeniedConfig['request_params']['shareKey'] = 'denied-api-share';
ConfigProvider::set($kafkaShareDeniedConfig);
$kafkaShareDenied = false;
try {
    (new Controller())->debugSend();
} catch (ErrorException $e) {
    $kafkaShareDenied = $e->getMessage() === 'share api not allowed';
}
check('share scoped kafka relay denied', $kafkaShareDenied);

// 批次 3c:api 分享范围只覆盖 apiKeys 内的 DNP3 接口
$shareStore->addApiShare([
    'key' => 'dnp3-api-share',
    'name' => 'DNP3 API share',
    'type' => 'api',
    'apiKeys' => [urlencode(Dnp3DebugFixture::class . '@read')],
]);
$dnp3ShareConfig = $shareConfig;
$dnp3ShareConfig['request_params'] = [
    'appKey' => 'api',
    'path' => Dnp3DebugFixture::class . '@read',
    'shareKey' => 'dnp3-api-share',
    'input' => ['fields' => ['duration_ms' => 50], 'timeout' => 2],
];
ConfigProvider::set($dnp3ShareConfig);
$dnp3Share = (new Controller())->debugSend();
check('share scoped batch 3c relay allowed', ($dnp3Share['data']['ok'] ?? false) === true
    && (json_decode((string)($dnp3Share['data']['body'] ?? ''), true)['points'][0]['value'] ?? null) === 42);
$dnp3ShareDeniedConfig = $dnp3ShareConfig;
$dnp3ShareDeniedConfig['request_params']['shareKey'] = 'denied-api-share';
ConfigProvider::set($dnp3ShareDeniedConfig);
$dnp3ShareDenied = false;
try {
    (new Controller())->debugSend();
} catch (ErrorException $e) {
    $dnp3ShareDenied = $e->getMessage() === 'share api not allowed';
}
check('share scoped batch 3c relay denied', $dnp3ShareDenied);

// 流式会话:start/poll/stop 全部走分享校验,且不被 init 拦截
$shareStore->addApiShare([
    'key' => 'stream-api-share',
    'name' => 'Stream API share',
    'type' => 'api',
    'apiKeys' => [urlencode(MqttSubscribeFixture::class . '@subscribe')],
]);
$streamShareConfig = $shareConfig;
$streamShareConfig['request_params'] = [
    'appKey' => 'api',
    'path' => MqttSubscribeFixture::class . '@subscribe',
    'shareKey' => 'stream-api-share',
    'input' => ['fields' => ['topic' => 'debug/topic'], 'timeout' => 2],
];
ConfigProvider::set($streamShareConfig);
$shareStart = (new Controller())->debugStart();
$shareSessionId = (string)($shareStart['data']['sessionId'] ?? '');
check('share stream start allowed', $shareSessionId !== '');
$streamShareConfig['request_params'] = ['sessionId' => $shareSessionId, 'appKey' => 'api', 'shareKey' => 'stream-api-share'];
ConfigProvider::set($streamShareConfig);
$sharePoll = (new Controller())->debugPoll();
check('share stream poll allowed', ($sharePoll['data']['ok'] ?? false) === true);
$shareStop = (new Controller())->debugStop();
check('share stream stop allowed', ($shareStop['data']['ok'] ?? false) === true);

// auth 关闭时,任意 token 都归入同一个 anonymous 桶
$anonConfig = $config;
$anonConfig['protocols']['debug']['relay_require_auth'] = false;
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
