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
$detail = renderFixture(\Tests\Fixtures\Protocol\OpenApiFixture::class, 'createUser');
$body = $detail['definitions'][0]['messages'][0] ?? [];
check('openapi external body local ref type', ($body['fields'][0]['type'] ?? '') === 'integer');
check('openapi external body valid', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);
$detail = renderFixture(\Tests\Fixtures\Protocol\OpenApiFixture::class, 'ping');
check('swagger 2.0 numeric version accepted', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);
check('swagger 2.0 response field', ($detail['definitions'][0]['messages'][1]['fields'][0]['name'] ?? '') === 'ok');

// ---------- AsyncAPI ----------
$detail = renderFixture(\Tests\Fixtures\Protocol\AsyncApiFixture::class, 'onTemp');
check('asyncapi operation', ($detail['definitions'][0]['operation'] ?? '') === 'sensors/+/temp:publish');
check('asyncapi message name', ($detail['definitions'][0]['messages'][0]['name'] ?? '') === 'Temperature');
check('asyncapi direction event', ($detail['definitions'][0]['messages'][0]['direction'] ?? '') === 'event');
check('asyncapi field value', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'value');
check('asyncapi external payload local ref type', ($detail['definitions'][0]['messages'][0]['fields'][0]['type'] ?? '') === 'number');
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
$search = renderFixture(\Tests\Fixtures\Protocol\ProtoFixture::class, 'search');
check('proto lowercase request type parsed', ($search['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'q');
check('proto lowercase type valid', ($search['definitions'][0]['validation']['ok'] ?? false) === true);
$bad = renderFixture(\Tests\Fixtures\Protocol\ProtoBadFixture::class, 'bad');
check('proto unsupported construct reported', ($bad['definitions'][0]['validation']['ok'] ?? true) === false);
check('proto error has line', ($bad['definitions'][0]['validation']['errors'][0]['line'] ?? 0) > 0);

// ---------- WSDL ----------
$detail = renderFixture(\Tests\Fixtures\Protocol\WsdlFixture::class, 'add');
check('wsdl operation', ($detail['definitions'][0]['operation'] ?? '') === 'CalculatorService/CalculatorPort/Add');
check('wsdl request a', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'a');
check('wsdl request b', ($detail['definitions'][0]['messages'][0]['fields'][1]['name'] ?? '') === 'b');
check('wsdl response result', ($detail['definitions'][0]['messages'][1]['fields'][0]['name'] ?? '') === 'result');
check('wsdl xsd import fingerprinted', count($detail['_definitionFingerprints'] ?? []) === 2);
check('wsdl valid', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);

// ---------- EDMX ----------
$detail = renderFixture(\Tests\Fixtures\Protocol\EdmxFixture::class, 'products');
check('edmx operation', ($detail['definitions'][0]['operation'] ?? '') === 'Products');
check('edmx id required', ($detail['definitions'][0]['messages'][0]['fields'][0]['require'] ?? false) === true);
check('edmx price type', ($detail['definitions'][0]['messages'][0]['fields'][2]['type'] ?? '') === 'number');
check('edmx valid', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);

// ---------- JSON-RPC ----------
$detail = renderFixture(\Tests\Fixtures\Protocol\JsonRpcFixture::class, 'getUser');
check('json-rpc operation', ($detail['definitions'][0]['operation'] ?? '') === 'user.get');
check('json-rpc params id', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'id');
check('json-rpc result name', ($detail['definitions'][0]['messages'][1]['fields'][1]['name'] ?? '') === 'name');
check('json-rpc valid', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);
check('json-rpc sample valid', ($detail['definitions'][0]['messages'][0]['sampleValidation']['ok'] ?? false) === true);
$bad = renderFixture(\Tests\Fixtures\Protocol\JsonRpcFixture::class, 'getUserBad');
check('json-rpc bad sample rejected', ($bad['definitions'][0]['messages'][0]['sampleValidation']['ok'] ?? true) === false);

// ---------- XML-RPC ----------
$detail = renderFixture(\Tests\Fixtures\Protocol\XmlRpcFixture::class, 'add');
check('xml-rpc operation', ($detail['definitions'][0]['operation'] ?? '') === 'add');
check('xml-rpc param a', ($detail['definitions'][0]['messages'][0]['fields'][0]['name'] ?? '') === 'a');
check('xml-rpc result type', ($detail['definitions'][0]['messages'][1]['fields'][0]['type'] ?? '') === 'integer');
check('xml-rpc valid', ($detail['definitions'][0]['validation']['ok'] ?? false) === true);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
