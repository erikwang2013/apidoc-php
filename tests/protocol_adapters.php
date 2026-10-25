<?php
declare(strict_types=1);

/**
 * 协议定义适配器冒烟测试: php tests/protocol_adapters.php
 */

use erikwang2013\apidoc\parses\ParseApiDetail;
use erikwang2013\apidoc\utils\ConfigProvider;

error_reporting(E_ALL);
$root = dirname(__DIR__);
define('APIDOC_ROOT_PATH', $root . '/');
define('APIDOC_STORAGE_PATH', sys_get_temp_dir() . '/apidoc-adapters-' . getmypid() . '/');
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
require __DIR__ . '/fixtures/protocol/AdapterFixtures.php';

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

$config = require __DIR__ . '/../src/config.php';
$config['cache'] = ['folder' => 'apidoc', 'enable' => false];
$config['protocols'] = ['allowed_roots' => [$root]];
$config['apps'] = [['key' => 'api', 'title' => 'API', 'path' => 'tests/fixtures/protocol']];
ConfigProvider::set($config);

function renderFixture(string $class, string $method): array
{
    global $config;
    return (new ParseApiDetail($config))->renderApiDetail('api', $class . '@' . $method);
}

// ---------- OpenAPI ----------
$detail = renderFixture(\Tests\Fixtures\Protocol\OpenApiFixture::class, 'getUser');
check('openapi definition parses', ($detail['definitions'][0]['format'] ?? '') === 'openapi');
check('openapi operation', ($detail['definitions'][0]['operation'] ?? '') === 'getUser');
$request = $detail['definitions'][0]['messages'][0] ?? [];
$response = $detail['definitions'][0]['messages'][1] ?? [];
check('openapi request id', ($request['fields'][0]['name'] ?? '') === 'id');
check('openapi request required', ($request['fields'][0]['require'] ?? false) === true);
check('openapi response id', ($response['fields'][0]['name'] ?? '') === 'id');
check('openapi response name', ($response['fields'][1]['name'] ?? '') === 'name');
check('openapi external import fingerprinted', count($detail['_definitionFingerprints'] ?? []) === 2);
check('openapi valid', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);

// ---------- AsyncAPI ----------
$detail = renderFixture(\Tests\Fixtures\Protocol\AsyncApiFixture::class, 'onTemp');
check('asyncapi operation', ($detail['definitions'][0]['operation'] ?? '') === 'sensors/+/temp:publish');
check('asyncapi message name', ($detail['definitions'][0]['messages'][0]['name'] ?? '') === 'Temperature');
check('asyncapi direction event', ($detail['definitions'][0]['messages'][0]['direction'] ?? '') === 'event');
check('asyncapi field value', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'value');
check('asyncapi external import', count($detail['_definitionFingerprints'] ?? []) === 2);

// ---------- GraphQL ----------
$detail = renderFixture(\Tests\Fixtures\Protocol\GraphqlFixture::class, 'user');
check('graphql operation', ($detail['definitions'][0]['operation'] ?? '') === 'Query.user');
check('graphql request id required', ($detail['definitions'][0]['messages'][0]['fields'][0]['require'] ?? false) === true);
check('graphql response id', ($detail['definitions'][0]['messages'][1]['fields'][0]['name'] ?? '') === 'id');
check('graphql list type', ($detail['definitions'][0]['messages'][1]['fields'][2]['type'] ?? '') === 'array');
check('graphql import fingerprinted', count($detail['_definitionFingerprints'] ?? []) === 2);

// ---------- Proto ----------
$detail = renderFixture(\Tests\Fixtures\Protocol\ProtoFixture::class, 'sayHello');
check('proto operation', ($detail['definitions'][0]['operation'] ?? '') === 'helloworld.Greeter/SayHello');
$req = $detail['definitions'][0]['messages'][0];
$res = $detail['definitions'][0]['messages'][1];
check('proto request name field', ($req['fields'][0]['name'] ?? '') === 'name');
check('proto request field number', ($req['fields'][0]['fieldNumber'] ?? 0) === 1);
check('proto repeated is array', ($req['fields'][2]['type'] ?? '') === 'array');
check('proto imported message resolves', ($req['fields'][1]['children'][0]['name'] ?? '') === 'trace_id');
check('proto import fingerprinted', count($detail['_definitionFingerprints'] ?? []) === 2);
check('proto response message field', ($res['fields'][0]['name'] ?? '') === 'message');
$bad = renderFixture(\Tests\Fixtures\Protocol\ProtoBadFixture::class, 'bad');
check('proto unsupported construct reported', ($bad['definitions'][0]['validation']['ok'] ?? true) === false);
check('proto error has line', ($bad['definitions'][0]['validation']['errors'][0]['line'] ?? 0) > 0);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
