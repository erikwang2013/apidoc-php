<?php
declare(strict_types=1);

/**
 * 协议元数据测试（无框架、无依赖）: php tests/protocol.php
 * 覆盖: ProtocolCatalog 回退、ParseProtocol::metadata 归一化输出
 */

use erikwang2013\apidoc\annotation\{Channel, Debug, Param, Protocol};
use erikwang2013\apidoc\exception\ErrorException;
use erikwang2013\apidoc\parses\ParseApiDetail;
use erikwang2013\apidoc\parses\ParseProtocol;
use erikwang2013\apidoc\protocol\ProtocolCatalog;
use erikwang2013\apidoc\protocol\debug\DebugRegistry;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Driver\GrpcDriver;
use erikwang2013\apidoc\protocol\debug\Driver\Http2Driver;
use erikwang2013\apidoc\protocol\debug\Driver\Http3Driver;
use erikwang2013\apidoc\utils\ConfigProvider;

error_reporting(E_ALL);
$root = dirname(__DIR__);
define('APIDOC_ROOT_PATH', $root . '/');
define('APIDOC_STORAGE_PATH', sys_get_temp_dir() . '/apidoc-protocol-' . getmypid() . '/');
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

#[Protocol('tcp')]
class DebugFieldsFixture
{
    #[Channel('tcp://127.0.0.1:9000', action: 'send')]
    #[Debug(driver: 'raw', fields: [['name' => 'device', 'in' => 'meta', 'type' => 'string']])]
    #[Param(name: 'payload', type: 'number', require: true)]
    public function send() {}
}

#[Protocol('mqtt')]
#[Debug(
    driver: 'mqtt',
    target: 'tcp://class:1883',
    encoding: 'json',
    timeout: 9,
    meta: ['responseEncoding' => 'json', 'hints' => 'class hint'],
    fields: [['name' => 'topic', 'in' => 'meta', 'type' => 'string']]
)]
class MergedDebugFixture
{
    #[Channel('/merged')]
    #[Debug(target: 'tcp://method:1883', timeout: 3)]
    public function run() {}
}

#[Protocol('rest')]
class BrowserDebugFixture
{
    #[Channel('https://api.example.com/ping')]
    public function ping() {}
}

#[Protocol('mqtt', version: '3.1')]
class MergedProtocolFixture
{
    #[Protocol(mode: 'relay-stream')]
    #[Debug(target: 'tcp://broker:1883')]
    public function run() {}
}

#[Protocol('mqtt')]
#[Channel('tcp://class-host:1')]
class MergedChannelFixture
{
    #[Channel(action: 'sub')]
    public function run() {}
}

#[Protocol('mqtt')]
#[Channel(address: 'tcp://class-host:1', action: 'publish')]
class PositionalChannelOverrideFixture
{
    #[Channel('/method-address')]
    public function run() {}
}

#[Protocol('mqtt')]
class UnregisteredDriverFixture
{
    #[Debug(driver: 'def', target: 'tcp://broker:1883')]
    public function run() {}
}

#[Protocol('mqtt')]
class MqttSubscribeModeFixture
{
    #[Channel('/sensors/+/temp', action: 'subscribe')]
    #[Debug(target: 'tcp://broker:1883')]
    public function subscribe() {}
}

#[Protocol('stomp')]
class StompSendModeFixture
{
    #[Channel('/queue/a', action: 'send')]
    #[Debug(target: 'tcp://broker:61613')]
    public function send() {}
}

#[Protocol('stomp')]
class StompReceiveModeFixture
{
    #[Channel('/queue/a', action: 'receive')]
    #[Debug(target: 'tcp://broker:61613')]
    public function receive() {}
}

#[Protocol('modbus-tcp')]
#[Debug(driver: 'modbus-tcp', target: 'tcp://127.0.0.1:19007', meta: ['unitId' => 7, 'hints' => 'meta hint'])]
class MetaDefaultsFixture
{
    #[Channel('/meta')]
    public function read() {}
}

#[Protocol('modbus-tcp')]
#[Debug(
    driver: 'modbus-tcp',
    target: 'tcp://127.0.0.1:19007',
    meta: ['unitId' => 7],
    fields: [['name' => 'unitId', 'in' => 'meta', 'type' => 'number', 'default' => 9]]
)]
class MetaOverrideFixture
{
    #[Channel('/meta')]
    public function read() {}
}

$config = [
    'cache' => ['folder' => 'apidoc'],
    'protocols' => ['debug' => ['relay_enable' => true]],
    'apps' => [['key' => 'api', 'title' => 'API', 'path' => 'tests/fixtures/protocol']],
];
ConfigProvider::set($config);

$parser = new ParseProtocol($config);
$mqttRef = new ReflectionClass(\Tests\Fixtures\Protocol\MqttFixture::class);
$mqtt = $parser->metadata($mqttRef, $mqttRef->getMethod('publishTemperature'));
check('mqtt protocol label', ($mqtt['protocol']['label'] ?? '') === 'MQTT');
check('mqtt channel action', ($mqtt['channel']['action'] ?? '') === 'publish');
check('mqtt publish debug mode', ($mqtt['debug']['mode'] ?? '') === 'relay-unary');
check('mqtt debug driver', ($mqtt['debug']['driver'] ?? '') === 'mqtt');
check('mqtt debug target', ($mqtt['debug']['defaults']['target'] ?? '') === 'tcp://broker:1883');
check('mqtt debug field value present', in_array('value', array_column($mqtt['debug']['fields'], 'name'), true));
$mqttSubRef = new ReflectionClass(MqttSubscribeModeFixture::class);
$mqttSub = $parser->metadata($mqttSubRef, $mqttSubRef->getMethod('subscribe'));
check('mqtt subscribe debug mode', ($mqttSub['debug']['mode'] ?? '') === 'relay-stream');
$stompSendRef = new ReflectionClass(StompSendModeFixture::class);
$stompSend = $parser->metadata($stompSendRef, $stompSendRef->getMethod('send'));
check('stomp send debug mode', ($stompSend['debug']['mode'] ?? '') === 'relay-unary');
$stompReceiveRef = new ReflectionClass(StompReceiveModeFixture::class);
$stompReceive = $parser->metadata($stompReceiveRef, $stompReceiveRef->getMethod('receive'));
check('stomp receive debug mode', ($stompReceive['debug']['mode'] ?? '') === 'relay-stream');
$grpcRef = new ReflectionClass(\Tests\Fixtures\Protocol\GrpcFixture::class);
$grpc = $parser->metadata($grpcRef, $grpcRef->getMethod('sayHello'));
check('grpc debug mode', ($grpc['debug']['mode'] ?? '') === 'relay-unary');
check('grpc debug driver', ($grpc['debug']['driver'] ?? '') === 'grpc');
$grpcAvailable = GrpcDriver::available();
check('grpc debuggable follows curl http2 capability', ($grpc['debug']['debuggable'] ?? !$grpcAvailable) === $grpcAvailable);
check('grpc reason matches curl http2 capability', ($grpc['debug']['debugReason'] ?? null) === ($grpcAvailable ? '' : 'curl HTTP/2 is required for gRPC'));
$thriftRef = new ReflectionClass(\Tests\Fixtures\Protocol\ThriftFixture::class);
$thrift = $parser->metadata($thriftRef, $thriftRef->getMethod('ping'));
check('thrift debug mode', ($thrift['debug']['mode'] ?? '') === 'relay-unary');
check('thrift debug driver', ($thrift['debug']['driver'] ?? '') === 'thrift');
check('thrift debug fields', !array_diff(['method', 'transport', 'schema', 'args', 'payload'], array_column($thrift['debug']['fields'] ?? [], 'name')));
$dubboRef = new ReflectionClass(\Tests\Fixtures\Protocol\DubboFixture::class);
$dubbo = $parser->metadata($dubboRef, $dubboRef->getMethod('echo'));
check('dubbo debug mode', ($dubbo['debug']['mode'] ?? '') === 'relay-unary');
check('dubbo debug driver', ($dubbo['debug']['driver'] ?? '') === 'dubbo');
check('dubbo debug fields', !array_diff(['service', 'method', 'version', 'param_types', 'args', 'payload'], array_column($dubbo['debug']['fields'] ?? [], 'name')));
$http2Ref = new ReflectionClass(\Tests\Fixtures\Protocol\Http2Fixture::class);
$http2 = $parser->metadata($http2Ref, $http2Ref->getMethod('echo'));
check('http2 debug mode', ($http2['debug']['mode'] ?? '') === 'relay-unary');
check('http2 debug driver', ($http2['debug']['driver'] ?? '') === 'http2');
$http2Available = Http2Driver::available();
check('http2 debuggable follows curl http2 capability', ($http2['debug']['debuggable'] ?? !$http2Available) === $http2Available);
check('http2 reason matches curl http2 capability', ($http2['debug']['debugReason'] ?? null) === ($http2Available ? '' : 'curl HTTP/2 is required for HTTP/2'));
$http3Ref = new ReflectionClass(\Tests\Fixtures\Protocol\Http3Fixture::class);
$http3 = $parser->metadata($http3Ref, $http3Ref->getMethod('echo'));
check('http3 debug mode', ($http3['debug']['mode'] ?? '') === 'relay-unary');
check('http3 debug driver', ($http3['debug']['driver'] ?? '') === 'http3');
$http3Available = Http3Driver::available();
check('http3 debuggable follows curl http3 capability', ($http3['debug']['debuggable'] ?? !$http3Available) === $http3Available);
check('http3 reason matches curl http3 capability', ($http3['debug']['debugReason'] ?? null) === ($http3Available ? '' : 'curl HTTP/3 is required for HTTP/3'));
$posRef = new ReflectionClass(\Tests\Fixtures\Protocol\PositionalDebugFixture::class);
$pos = $parser->metadata($posRef, $posRef->getMethod('send'));
check('positional Debug lands on driver', ($pos['debug']['driver'] ?? '') === 'raw');
check('debug target falls back to channel address', ($pos['debug']['defaults']['target'] ?? '') === 'tcp://127.0.0.1:9000');
$defRef = new ReflectionClass(\Tests\Fixtures\Protocol\DefaultDriverFixture::class);
$def = $parser->metadata($defRef, $defRef->getMethod('publish'));
check('empty Debug.driver falls back to catalog driver', ($def['debug']['driver'] ?? '') === 'mqtt');
check('mqtt without action keeps catalog mode', ($def['debug']['mode'] ?? '') === 'relay-stream');
check('registered driver is debuggable with relay enabled', ($pos['debug']['debuggable'] ?? false) === true);
$relayOffConfig = $config;
$relayOffConfig['protocols']['debug']['relay_enable'] = false;
$defOff = (new ParseProtocol($relayOffConfig))->metadata($defRef, $defRef->getMethod('publish'));
check('catalog driver fallback not debuggable with relay disabled', ($defOff['debug']['debuggable'] ?? true) === false);
check('unknown protocol falls back', ProtocolCatalog::get('nope')['label'] === 'NOPE');

// ---------- P2 回退:目录驱动与元数据 ----------
$p2RawRef = new ReflectionClass(\Tests\Fixtures\Protocol\P2RawFixture::class);
$p2Raw = $parser->metadata($p2RawRef, $p2RawRef->getMethod('publish'));
check('p2 amqp raw fallback driver', ($p2Raw['debug']['driver'] ?? '') === 'raw');
check('p2 amqp raw fallback mode', ($p2Raw['debug']['mode'] ?? '') === 'relay-unary');
check('p2 amqp raw fallback fields include payload', in_array('payload', array_column($p2Raw['debug']['fields'] ?? [], 'name'), true));
$p2NoRef = new ReflectionClass(\Tests\Fixtures\Protocol\P2NoDriverFixture::class);
$p2No = $parser->metadata($p2NoRef, $p2NoRef->getMethod('offer'));
check('webrtc p2 metadata not debuggable', ($p2No['debug']['debuggable'] ?? true) === false);
check('webrtc p2 metadata reason', strpos((string)($p2No['debug']['debugReason'] ?? ''), 'SDP') !== false && strpos((string)($p2No['debug']['debugReason'] ?? ''), 'P2') !== false);

// ---------- 驱动注册表 / 调试目标 ----------
check('target parses tcp', DebugTarget::parse('tcp://broker:1883')['port'] === 1883);
check('target parses unix', DebugTarget::parse('unix:///tmp/app.sock')['scheme'] === 'unix');
$bad = false;
try { DebugTarget::parse('file:///etc/passwd'); } catch (Throwable $e) { $bad = true; }
check('target rejects file scheme', $bad);
check('registry raw driver', DebugRegistry::driver('raw')?->name() === 'raw');
check('registry unknown driver', DebugRegistry::driver('nope') === null);

check('target bare host:port is tcp', DebugTarget::parse('broker:1883')['scheme'] === 'tcp');
check('target parses path', DebugTarget::parse('https://example.com/api')['path'] === '/api');
check('target applies https default port', DebugTarget::parse('https://example.com/api')['port'] === 443);
check('target applies ws default port', DebugTarget::parse('ws://example.com')['port'] === 80);
$badPort = false;
try { DebugTarget::parse('tcp://host:70000'); } catch (Throwable $e) { $badPort = true; }
check('target rejects out of range port', $badPort);
$badCreds = false;
try { DebugTarget::parse('http://user:pass@host'); } catch (Throwable $e) { $badCreds = true; }
check('target rejects credentials', $badCreds);
$missingPort = false;
try { DebugTarget::parse('tcp://broker'); } catch (Throwable $e) { $missingPort = true; }
check('target requires explicit tcp port', $missingPort);

$rawFields = DebugRegistry::driverFields('raw');
$rawNames = array_column($rawFields, 'name');
check('registry raw fields count', count($rawFields) === 4);
check('registry raw fields names', !array_diff(['target', 'payload', 'encoding', 'timeout'], $rawNames));
check('registry unknown fields', DebugRegistry::driverFields('nope') === []);
check('registry mqtt driver', DebugRegistry::driver('mqtt')?->name() === 'mqtt');
check('registry stomp driver', DebugRegistry::driver('stomp')?->name() === 'stomp');
$mqttFields = DebugRegistry::driverFields('mqtt');
check('registry mqtt fields', !array_diff(['topic', 'qos', 'clientId', 'username', 'password', 'payload'], array_column($mqttFields, 'name')));
check('registry mqtt qos default', ($mqttFields[1]['default'] ?? null) === 1);
$stompFields = DebugRegistry::driverFields('stomp');
check('registry stomp fields', !array_diff(['destination', 'command', 'contentType', 'payload'], array_column($stompFields, 'name')));
check('registry stomp contentType default', ($stompFields[2]['default'] ?? '') === 'text/plain');
check('raw driver send is stubbed', (DebugRegistry::driver('raw')->send([], [])['ok'] ?? true) === false);

// ---------- debug.fields 注入 / 去重 / Debug 注解合并 ----------
$fieldsRef = new ReflectionClass(DebugFieldsFixture::class);
$fieldsMeta = $parser->metadata($fieldsRef, $fieldsRef->getMethod('send'));
$debugFields = $fieldsMeta['debug']['fields'] ?? [];
$byKey = [];
foreach ($debugFields as $item) {
    $byKey[($item['in'] ?? '') . '|' . ($item['name'] ?? '')] = $item;
}
check('driver field payload injected', isset($byKey['body|payload']));
check('driver field encoding injected', isset($byKey['meta|encoding']));
check('driver field timeout injected', isset($byKey['meta|timeout']));
check('Debug.fields item injected', ($byKey['meta|device']['type'] ?? '') === 'string');
check('annotation param overrides driver field', ($byKey['body|payload']['type'] ?? '') === 'number');
check('driver target field deduped', count(array_filter($debugFields, function ($item) {
    return ($item['in'] ?? '') === 'target' && ($item['name'] ?? '') === 'target';
})) === 1);
check('deduped target keeps default', ($byKey['target|target']['default'] ?? '') === 'tcp://127.0.0.1:9000');

$mergedRef = new ReflectionClass(MergedDebugFixture::class);
$merged = $parser->metadata($mergedRef, $mergedRef->getMethod('run'));
check('class Debug.driver kept when method omits it', ($merged['debug']['driver'] ?? '') === 'mqtt');
check('method Debug.target overrides class', ($merged['debug']['defaults']['target'] ?? '') === 'tcp://method:1883');
check('class Debug.encoding kept when method omits it', ($merged['debug']['defaults']['encoding'] ?? '') === 'json');
check('method Debug.timeout overrides class', ($merged['debug']['defaults']['timeout'] ?? 0) === 3);
check('class Debug.meta kept when method omits it', ($merged['debug']['responseEncoding'] ?? '') === 'json');
check('class Debug.fields kept when method omits it', in_array('topic', array_column($merged['debug']['fields'] ?? [], 'name'), true));
check('class Debug.meta hints exposed', ($merged['debug']['hints'] ?? '') === 'class hint');
check('Debug.meta exposed in debug object', ($merged['debug']['meta']['responseEncoding'] ?? '') === 'json');

// ---------- Debug.meta 作为字段默认值 ----------
$metaDefaultsRef = new ReflectionClass(MetaDefaultsFixture::class);
$metaDefaults = $parser->metadata($metaDefaultsRef, $metaDefaultsRef->getMethod('read'));
$metaFieldByName = array_column($metaDefaults['debug']['fields'] ?? [], null, 'name');
check('Debug.meta applied as driver field default', ($metaFieldByName['unitId']['default'] ?? null) === 7);
check('Debug.meta unitId exposed', ($metaDefaults['debug']['meta']['unitId'] ?? null) === 7);
check('Debug.meta hint exposed', ($metaDefaults['debug']['hints'] ?? '') === 'meta hint');
$metaOverrideRef = new ReflectionClass(MetaOverrideFixture::class);
$metaOverride = $parser->metadata($metaOverrideRef, $metaOverrideRef->getMethod('read'));
$metaOverrideFields = array_column($metaOverride['debug']['fields'] ?? [], null, 'name');
check('explicit field default overrides Debug.meta', ($metaOverrideFields['unitId']['default'] ?? null) === 9);
check('stomp stream hint warns about poll gap', strpos((string)($stompReceive['debug']['hints'] ?? ''), 'STOMP') === 0);

$browserRef = new ReflectionClass(BrowserDebugFixture::class);
$browser = $parser->metadata($browserRef, $browserRef->getMethod('ping'));
check('browser mode debuggable with target despite no registered driver', ($browser['debug']['debuggable'] ?? false) === true);

// ---------- Protocol / Channel 类级与方法级字段合并 ----------
$mergedProtocolRef = new ReflectionClass(MergedProtocolFixture::class);
$mergedProtocol = $parser->metadata($mergedProtocolRef, $mergedProtocolRef->getMethod('run'));
check('class Protocol.name inherited when method omits it', ($mergedProtocol['protocol']['name'] ?? '') === 'mqtt');
check('class Protocol.version inherited when method omits it', ($mergedProtocol['protocol']['version'] ?? '') === '3.1');
check('method Protocol.mode overrides class', ($mergedProtocol['protocol']['mode'] ?? '') === 'relay-stream');

$mergedChannelRef = new ReflectionClass(MergedChannelFixture::class);
$mergedChannel = $parser->metadata($mergedChannelRef, $mergedChannelRef->getMethod('run'));
check('class Channel.address inherited when method omits it', ($mergedChannel['channel']['address'] ?? '') === 'tcp://class-host:1');
check('method Channel.action overrides class', ($mergedChannel['channel']['action'] ?? '') === 'sub');

$positionalRef = new ReflectionClass(PositionalChannelOverrideFixture::class);
$positional = $parser->metadata($positionalRef, $positionalRef->getMethod('run'));
check('method positional Channel overrides class address', ($positional['channel']['address'] ?? '') === '/method-address');
check('class Channel.action kept when method omits it', ($positional['channel']['action'] ?? '') === 'publish');

$unregisteredRef = new ReflectionClass(UnregisteredDriverFixture::class);
$unregistered = $parser->metadata($unregisteredRef, $unregisteredRef->getMethod('run'));
check('unregistered driver not debuggable with relay enabled and target', ($unregistered['debug']['debuggable'] ?? true) === false);

// 目录条目必须携带 defaultFormat，供定义解析计划消费
check('rest default format', ProtocolCatalog::get('rest')['defaultFormat'] === 'openapi');
check('grpc default format', ProtocolCatalog::get('grpc')['defaultFormat'] === 'proto');
check('amqp p2 fallback driver', ProtocolCatalog::get('amqp')['defaultDriver'] === 'raw');
check('amqp p2 fallback mode', ProtocolCatalog::get('amqp')['defaultMode'] === 'relay-unary');
check('binary-body uses http relay', ProtocolCatalog::get('binary-body')['defaultDriver'] === 'http-relay' && ProtocolCatalog::get('binary-body')['defaultMode'] === 'relay-unary');
check('websocket-binary uses browser websocket', ProtocolCatalog::get('websocket-binary')['defaultDriver'] === 'websocket' && ProtocolCatalog::get('websocket-binary')['defaultMode'] === 'browser-websocket');
check('socket.io uses browser websocket fallback', ProtocolCatalog::get('socket.io')['defaultMode'] === 'browser-websocket');
check('stomp-binary uses stomp driver', ProtocolCatalog::get('stomp-binary')['defaultDriver'] === 'stomp' && ProtocolCatalog::get('stomp-binary')['defaultMode'] === 'relay-stream');
check('http2 uses specialized relay', ProtocolCatalog::get('http2')['defaultDriver'] === 'http2' && ProtocolCatalog::get('http2')['defaultMode'] === 'relay-unary');
check('http3 uses specialized relay', ProtocolCatalog::get('http3')['defaultDriver'] === 'http3' && ProtocolCatalog::get('http3')['defaultMode'] === 'relay-unary');
check('thrift uses specialized relay', ProtocolCatalog::get('thrift')['defaultDriver'] === 'thrift' && ProtocolCatalog::get('thrift')['defaultMode'] === 'relay-unary');
check('dubbo uses specialized relay', ProtocolCatalog::get('dubbo')['defaultDriver'] === 'dubbo' && ProtocolCatalog::get('dubbo')['defaultMode'] === 'relay-unary');
check('grpc catalog stays specialized', ProtocolCatalog::get('grpc')['defaultDriver'] === 'grpc' && ProtocolCatalog::get('grpc')['defaultMode'] === 'relay-unary');
check('hessian catalog stays specialized', ProtocolCatalog::get('hessian')['defaultDriver'] === 'hessian' && ProtocolCatalog::get('hessian')['defaultMode'] === 'relay-unary');
check('iso8583 catalog stays specialized', ProtocolCatalog::get('iso8583')['defaultDriver'] === 'iso8583' && ProtocolCatalog::get('iso8583')['defaultMode'] === 'relay-unary');
check('webrtc keeps no driver', ProtocolCatalog::get('webrtc')['defaultDriver'] === '' && ProtocolCatalog::get('webrtc')['defaultMode'] === '');
check('webrtc p2 reason', ProtocolCatalog::get('webrtc')['debugReason'] === 'WebRTC 需要手动 SDP/信令（P2）');
$noDriverIds = ['webrtc', 'webrtc-datachannel'];
$missingDefaults = [];
foreach (ProtocolCatalog::ids() as $id) {
    $entry = ProtocolCatalog::get($id);
    $noDriver = in_array($id, $noDriverIds, true);
    if ($entry['label'] === '' || (!$noDriver && ($entry['defaultDriver'] === '' || $entry['defaultMode'] === ''))) {
        $missingDefaults[] = $id;
    }
}
check('every catalog id has debug defaults', $missingDefaults === []);
check('catalog ids returns all protocol ids', array_diff(['http', 'mqtt', 'amqp', 'webrtc'], ProtocolCatalog::ids()) === []);

// ---------- 明细/菜单集成 + 既有 HTTP 兼容 ----------
$plainRef = new ReflectionClass(\Tests\Fixtures\Protocol\PlainHttpFixture::class);
$plainMeta = (new erikwang2013\apidoc\parses\ParseProtocol($config))->metadata($plainRef, $plainRef->getMethod('plain'));
check('plain http has no protocol metadata', $plainMeta === []);
$detail = (new erikwang2013\apidoc\parses\ParseApiDetail($config))->renderApiDetail('api', 'Tests\\Fixtures\\Protocol\\MqttFixture@publishTemperature');
check('mqtt detail has protocol', ($detail['protocol']['name'] ?? '') === 'mqtt');
check('mqtt detail has debug', ($detail['debug']['driver'] ?? '') === 'mqtt');
check('mqtt detail url is channel', ($detail['url'] ?? '') === '/sensors/+/temp');
$menus = (new erikwang2013\apidoc\parses\ParseApiMenus($config));
$controller = $menus->parseController(\Tests\Fixtures\Protocol\MqttFixture::class, false, 'api');
check('menu has protocol key', ($controller['children'][0]['protocolKey'] ?? '') === 'mqtt');
check('menu has no internal fields', !array_key_exists('_definitionFingerprints', $controller['children'][0]));

$debugOnlyRef = new ReflectionClass(\Tests\Fixtures\Protocol\DebugOnlyFixture::class);
$debugOnlyMeta = $parser->metadata($debugOnlyRef, $debugOnlyRef->getMethod('ping'));
check('debug-only metadata has no protocol key', !array_key_exists('protocol', $debugOnlyMeta));
check('debug-only metadata has debug', ($debugOnlyMeta['debug']['driver'] ?? '') === 'http');
$debugOnlyDetail = (new erikwang2013\apidoc\parses\ParseApiDetail($config))->renderApiDetail('api', 'Tests\\Fixtures\\Protocol\\DebugOnlyFixture@ping');
check('debug-only detail has no protocolKey', !array_key_exists('protocolKey', $debugOnlyDetail));
check('debug-only detail has no protocolLabel', !array_key_exists('protocolLabel', $debugOnlyDetail));
$debugOnlyController = $menus->parseController(\Tests\Fixtures\Protocol\DebugOnlyFixture::class, false, 'api');
$debugOnlyChild = $debugOnlyController['children'][0];
check('debug-only menu lacks protocol keys', !array_key_exists('protocolKey', $debugOnlyChild) && !array_key_exists('protocolLabel', $debugOnlyChild) && !array_key_exists('channelAddress', $debugOnlyChild));

$plainController = $menus->parseController(\Tests\Fixtures\Protocol\PlainHttpFixture::class, false, 'api');
$plainKeys = array_keys($plainController['children'][0]);
sort($plainKeys);
check('plain http menu keys unchanged', $plainKeys === ['appKey', 'author', 'menuKey', 'method', 'name', 'tag', 'title', 'url']);
$grpcDetail = (new erikwang2013\apidoc\parses\ParseApiDetail($config))->renderApiDetail('api', 'Tests\\Fixtures\\Protocol\\GrpcFixture@sayHello');
check('grpc detail url is channel address', ($grpcDetail['url'] ?? '') === 'helloworld.Greeter/SayHello');

// ---------- app path 范围 ----------
require __DIR__ . '/fixtures/OutsideFixture.php';
$outsideThrew = false;
try {
    (new ParseApiDetail($config))->renderApiDetail('api', 'Tests\Fixtures\OutsideFixture@ping');
} catch (ErrorException $e) {
    $outsideThrew = $e->getMessage() === 'api not in app scope';
}
check('api outside app path rejected', $outsideThrew);

$emptyScopeConfig = $config;
$emptyScopeConfig['apps'][0]['path'] = '';
ConfigProvider::set($emptyScopeConfig);
$emptyScopeThrew = false;
try {
    (new ParseApiDetail($emptyScopeConfig))->renderApiDetail('api', 'Tests\Fixtures\Protocol\PlainHttpFixture@plain');
} catch (ErrorException $e) {
    $emptyScopeThrew = $e->getMessage() === 'api not in app scope';
}
check('empty app path rejects api', $emptyScopeThrew);
ConfigProvider::set($config);

// ---------- 定义文件路径/上下文/注册表 ----------
$root = realpath(dirname(__DIR__));
$paths = new erikwang2013\apidoc\protocol\ProtocolPaths([
    'allowed_roots' => [$root],
    'max_file_bytes' => 16,
    'max_imports' => 2,
    'max_depth' => 2,
]);
check('relative path resolves', ($paths->resolve('tests/fixtures/protocol/AnnotationFixtures.php')['realpath'] ?? '') === $root . '/tests/fixtures/protocol/AnnotationFixtures.php');
check('traversal rejected', $paths->resolve('../../etc/passwd') === null);
check('absolute path rejected', $paths->resolve('/etc/passwd') === null);
check('missing file rejected', $paths->resolve('tests/fixtures/protocol/missing.proto') === null);
$secret = sys_get_temp_dir() . '/apidoc-proto-secret-' . getmypid() . '.yaml';
$escapeLink = $root . '/tests/fixtures/protocol/escape-' . getmypid() . '.yaml';
file_put_contents($secret, "secret: true\n");
@symlink($secret, $escapeLink);
check('symlink outside root rejected', $paths->resolve('tests/fixtures/protocol/' . basename($escapeLink)) === null);
$escapeCtx = new erikwang2013\apidoc\protocol\ProtocolContext($root . '/tests/fixtures/protocol/AnnotationFixtures.php', [], [], [$root]);
check('import symlink outside root rejected', $escapeCtx->resolve(basename($escapeLink)) === null);
@unlink($escapeLink);
@unlink($secret);

$tmp = tempnam(sys_get_temp_dir(), 'apidoc-proto-');
file_put_contents($tmp, 'abcdefghijklmnopq');
$ctx = new erikwang2013\apidoc\protocol\ProtocolContext($tmp, ['strict' => true], ['max_file_bytes' => 5, 'max_imports' => 2, 'max_depth' => 2], [$root]);
check('oversized read rejected', $ctx->read() === null);
check('oversized read records error', count($ctx->errors()) === 1);
check('registry detects proto', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.proto') === 'proto');
check('registry detects openapi yaml', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.yaml', "openapi: 3.0.3\n") === 'openapi');
check('registry detects asyncapi', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.yaml', "asyncapi: 2.6.0\n") === 'asyncapi');
check('registry detects edmx', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.xml', '<edmx:Edmx xmlns:edmx="x"></edmx:Edmx>') === 'edmx');
check('unknown format returns empty', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.bin') === '');
check('unknown adapter returns null', erikwang2013\apidoc\protocol\ProtocolRegistry::adapter('nope') === null);
check('registry detects json schema $schema', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.json', '{"$schema":"http://json-schema.org/draft-07/schema#","$ref":"#/definitions/x"}') === 'json-schema');
check('registry detects json schema $ref root', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.json', '{"$ref":"#/definitions/x"}') === 'json-schema');
check('registry detects yaml json schema', erikwang2013\apidoc\protocol\ProtocolRegistry::detect('a.yaml', "\$schema: 'http://json-schema.org/draft-07/schema#'\n") === 'json-schema');
check('registry lists formats', in_array('openapi', erikwang2013\apidoc\protocol\ProtocolRegistry::formats(), true));

$ctxFile = $root . '/tests/fixtures/protocol/AnnotationFixtures.php';
$importCtx = new erikwang2013\apidoc\protocol\ProtocolContext($ctxFile, [], ['max_imports' => 1, 'max_depth' => 1], [$root]);
check('import resolves within root', $importCtx->resolve('AnnotationFixtures.php') === $ctxFile);
check('import traversal rejected', $importCtx->resolve('../../../../etc/passwd') === null);
check('import cycle skipped', $importCtx->push($ctxFile) === false && count($importCtx->warnings()) === 1);
$importFile = $root . '/src/protocol/ProtocolPaths.php';
check('push switches file', $importCtx->push($importFile) === true && $importCtx->file() === $importFile && $importCtx->imports() === [$importFile]);
$importCtx->pop();
check('pop restores file', $importCtx->file() === $ctxFile);
check('max_imports enforced', $importCtx->push($root . '/src/protocol/ProtocolContext.php') === false && count($importCtx->errors()) === 1);

// ---------- 内联定义 / JSON Schema 适配器 ----------
$renderer = new ParseProtocol($config);
$inlineRef = new ReflectionClass(\Tests\Fixtures\Protocol\InlineFixture::class);
$inline = $renderer->render($inlineRef, $inlineRef->getMethod('call'), (new ParseApiDetail($config))->parseApiMethod($inlineRef, $inlineRef->getMethod('call')));
check('inline definition created', ($inline['definitions'][0]['format'] ?? '') === 'inline');
check('inline request message', ($inline['definitions'][0]['messages'][0]['direction'] ?? '') === 'request');
check('inline request field id', ($inline['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'id');
check('inline response field ok', ($inline['definitions'][0]['messages'][1]['fields'][0]['name'] ?? '') === 'ok');
$detail = (new ParseApiDetail($config))->renderApiDetail('api', 'Tests\\Fixtures\\Protocol\\JsonSchemaFixture@user');
check('json schema definition parses', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'id');
check('json schema sample valid', ($detail['definitions'][0]['messages'][0]['sampleValidation']['ok'] ?? false) === true);
check('file definition has fingerprints', count($detail['_definitionFingerprints'] ?? []) === 1);
$brokenRef = new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaFixture::class);
$broken = $renderer->render($brokenRef, $brokenRef->getMethod('broken'), (new ParseApiDetail($config))->parseApiMethod($brokenRef, $brokenRef->getMethod('broken')));
check('malformed json is a validation error', ($broken['definitions'][0]['validation']['ok'] ?? true) === false);
check('malformed json error is visible', strpos(json_encode($broken['definitions'][0]['validation']['errors']), 'JSON') !== false);
$hook = $renderer->render($brokenRef, $brokenRef->getMethod('hook'), (new ParseApiDetail($config))->parseApiMethod($brokenRef, $brokenRef->getMethod('hook')));
check('webhook signature metadata recorded', ($hook['definitions'][0]['meta']['header'] ?? '') === 'X-Signature');
$fromFile = $renderer->render($brokenRef, $brokenRef->getMethod('userFile'), (new ParseApiDetail($config))->parseApiMethod($brokenRef, $brokenRef->getMethod('userFile')));
check('sampleFile is validated', ($fromFile['definitions'][0]['messages'][0]['sampleValidation']['ok'] ?? false) === true);
check('sampleFile is fingerprinted', isset($fromFile['_definitionFingerprints'][realpath('tests/fixtures/protocol/json-schema/user-sample.json')]));

// ---------- 递归 / 多文件 / 链式 $ref ----------
$refsRef = new ReflectionClass(\Tests\Fixtures\Protocol\JsonSchemaRefsFixture::class);
$renderRefs = function (string $method) use ($renderer, $refsRef, $config) {
    return $renderer->render($refsRef, $refsRef->getMethod($method), (new ParseApiDetail($config))->parseApiMethod($refsRef, $refsRef->getMethod($method)));
};
$recursive = $renderRefs('recursive');
check('recursive ref produces a definition', ($recursive['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'next');
check('recursive ref records a warning', count($recursive['definitions'][0]['validation']['warnings'] ?? []) > 0);
$multi = $renderRefs('multi');
check('local ref inside external doc resolves', ($multi['definitions'][0]['messages'][0]['fields'][0]['children'][0]['children'][0]['name'] ?? '') === 'label');
check('relative ref resolves against external dir', ($multi['definitions'][0]['messages'][0]['fields'][0]['children'][1]['children'][0]['name'] ?? '') === 'sku');
check('multi-file imports are fingerprinted', count($multi['_definitionFingerprints'] ?? []) === 3);
$chain = $renderRefs('chain');
check('chained ref fields resolve to the last schema', ($chain['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'name');
check('chained ref sample validates', ($chain['definitions'][0]['messages'][0]['sampleValidation']['ok'] ?? false) === true);
$chainBad = $renderRefs('chainBad');
check('chained ref rejects invalid sample', ($chainBad['definitions'][0]['messages'][0]['sampleValidation']['ok'] ?? true) === false);
check('chained ref invalid sample path', ($chainBad['definitions'][0]['messages'][0]['sampleValidation']['errors'][0]['path'] ?? '') === '$.name');
check('json schema sample error has severity', ($chainBad['definitions'][0]['messages'][0]['sampleValidation']['errors'][0]['severity'] ?? '') === 'error');
check('json schema sample has warnings', array_key_exists('warnings', $chainBad['definitions'][0]['messages'][0]['sampleValidation'] ?? []));
$classDefRef = new ReflectionClass(\Tests\Fixtures\Protocol\ClassDefinitionFixture::class);
$classDef = $renderer->render($classDefRef, $classDefRef->getMethod('inherited'), (new ParseApiDetail($config))->parseApiMethod($classDefRef, $classDefRef->getMethod('inherited')));
check('class-level definition inherited', ($classDef['definitions'][0]['source'] ?? '') === 'tests/fixtures/protocol/json-schema/user.json');
check('class-level definition parsed', ($classDef['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'id');

// ---------- 定义缓存与文件指纹 ----------
$fpFile = sys_get_temp_dir() . '/apidoc-proto-fp-' . getmypid() . '.json';
file_put_contents($fpFile, '{"type":"object","properties":{"a":{"type":"string"}}}');
$fpDetail = [
    'definitions' => [['format' => 'json-schema', 'source' => 'x', 'messages' => [], 'validation' => ['ok' => true, 'errors' => [], 'warnings' => []]]],
    '_definitionFingerprints' => [$fpFile => ['mtime' => filemtime($fpFile), 'size' => filesize($fpFile)]],
];
check('fingerprint fresh', erikwang2013\apidoc\parses\ParseProtocol::cacheFresh($fpDetail) === true);
touch($fpFile, time() + 5);
clearstatcache();
check('fingerprint detects mtime change', erikwang2013\apidoc\parses\ParseProtocol::cacheFresh($fpDetail) === false);
unlink($fpFile);
check('fingerprint detects missing file', erikwang2013\apidoc\parses\ParseProtocol::cacheFresh($fpDetail) === false);
check('inline-only detail is fresh', erikwang2013\apidoc\parses\ParseProtocol::cacheFresh(['definitions' => [['format' => 'inline']]]) === true);
check('file detail without fingerprints is stale', erikwang2013\apidoc\parses\ParseProtocol::cacheFresh(['definitions' => [['format' => 'json-schema']]]) === false);

$cacheFile = 'tests/fixtures/protocol/json-schema/cache.json';
erikwang2013\apidoc\utils\DirAndFile::deleteDir(APIDOC_STORAGE_PATH . 'apidoc/protocol');
file_put_contents($cacheFile, '{"title":"Cache","type":"object","properties":{"a":{"type":"string"}}}');
$cacheFirst = (new ParseApiDetail($config))->renderApiDetail('api', 'Tests\\Fixtures\\Protocol\\CacheFixture@user');
check('adapter cache first parse', ($cacheFirst['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'a');
file_put_contents($cacheFile, '{"title":"Cache","type":"object","properties":{"b":{"type":"string"},"extra":{"type":"string"}}}');
clearstatcache();
$cacheSecond = (new ParseApiDetail($config))->renderApiDetail('api', 'Tests\\Fixtures\\Protocol\\CacheFixture@user');
check('adapter cache invalidates on file change', ($cacheSecond['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'b');
@unlink($cacheFile);

// ---------- YAML 子集 ----------
$yaml = erikwang2013\apidoc\protocol\Support\Yaml::parse("title: T\nservers:\n  - url: a\n  - url: b\ninline: [a, b]\n");
check('yaml mapping', ($yaml['title'] ?? '') === 'T');
check('yaml sequence of maps', ($yaml['servers'][1]['url'] ?? '') === 'b');
check('yaml inline list', ($yaml['inline'] ?? []) === ['a', 'b']);
$threw = false;
try {
    erikwang2013\apidoc\protocol\Support\Yaml::parse("a: &x 1\nb: *x\n");
} catch (erikwang2013\apidoc\protocol\ProtocolParseException $e) {
    $threw = true;
    check('yaml anchor error line', $e->line() > 0);
}
check('yaml rejects anchors', $threw === true);

// ---------- SafeXml / TokenStream ----------
$doc = erikwang2013\apidoc\protocol\Support\SafeXml::load('<root><a x="1">v</a></root>');
check('xml first finds element', erikwang2013\apidoc\protocol\Support\SafeXml::attr(erikwang2013\apidoc\protocol\Support\SafeXml::first($doc->documentElement, 'a'), 'x') === '1');
$threw = false;
try {
    erikwang2013\apidoc\protocol\Support\SafeXml::load('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>');
} catch (erikwang2013\apidoc\protocol\ProtocolParseException $e) {
    $threw = true;
}
check('xml rejects doctype', $threw === true);
$ts = new erikwang2013\apidoc\protocol\Support\TokenStream("// c\nmessage A { string name = 1; }");
$ts->skipTrivia();
check('token identifier', $ts->identifier() === 'message');
check('token line tracking', $ts->line() === 2);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
