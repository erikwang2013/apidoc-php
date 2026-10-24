<?php
declare(strict_types=1);

/**
 * 协议元数据测试（无框架、无依赖）: php tests/protocol.php
 * 覆盖: ProtocolCatalog 回退、ParseProtocol::metadata 归一化输出
 */

use erikwang2013\apidoc\parses\ParseProtocol;
use erikwang2013\apidoc\protocol\ProtocolCatalog;
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

$config = [
    'cache' => ['folder' => 'apidoc'],
    'protocols' => ['debug' => ['relay_enable' => true]],
];
ConfigProvider::set($config);

$parser = new ParseProtocol($config);
$mqttRef = new ReflectionClass(\Tests\Fixtures\Protocol\MqttFixture::class);
$mqtt = $parser->metadata($mqttRef, $mqttRef->getMethod('publishTemperature'));
check('mqtt protocol label', ($mqtt['protocol']['label'] ?? '') === 'MQTT');
check('mqtt channel action', ($mqtt['channel']['action'] ?? '') === 'publish');
check('mqtt debug mode', ($mqtt['debug']['mode'] ?? '') === 'relay-stream');
check('mqtt debug driver', ($mqtt['debug']['driver'] ?? '') === 'mqtt');
check('mqtt debug target', ($mqtt['debug']['defaults']['target'] ?? '') === 'tcp://broker:1883');
check('mqtt debug field value present', in_array('value', array_column($mqtt['debug']['fields'], 'name'), true));
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
check('catalog driver fallback is debuggable with relay enabled', ($def['debug']['debuggable'] ?? false) === true);
$relayOffConfig = $config;
$relayOffConfig['protocols']['debug']['relay_enable'] = false;
$defOff = (new ParseProtocol($relayOffConfig))->metadata($defRef, $defRef->getMethod('publish'));
check('catalog driver fallback not debuggable with relay disabled', ($defOff['debug']['debuggable'] ?? true) === false);
check('unknown protocol falls back', ProtocolCatalog::get('nope')['label'] === 'NOPE');

// 目录条目必须携带 defaultFormat，供定义解析计划消费
check('rest default format', ProtocolCatalog::get('rest')['defaultFormat'] === 'openapi');
check('grpc default format', ProtocolCatalog::get('grpc')['defaultFormat'] === 'proto');
check('p2 protocol not debuggable', ProtocolCatalog::get('kafka')['debuggable'] === false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
