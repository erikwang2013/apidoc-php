<?php
declare(strict_types=1);

/**
 * 协议元数据测试（无框架、无依赖）: php tests/protocol.php
 * 覆盖: ProtocolCatalog 回退、ParseProtocol::metadata 归一化输出
 */

use erikwang2013\apidoc\annotation\{Channel, Debug, Param, Protocol};
use erikwang2013\apidoc\parses\ParseProtocol;
use erikwang2013\apidoc\protocol\ProtocolCatalog;
use erikwang2013\apidoc\protocol\debug\DebugRegistry;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
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

$config = [
    'cache' => ['folder' => 'apidoc'],
    'protocols' => ['debug' => ['relay_enable' => true]],
    'apps' => [['key' => 'api', 'title' => 'API', 'path' => '']],
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
check('p2 protocol not debuggable', ProtocolCatalog::get('kafka')['debuggable'] === false);

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

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
