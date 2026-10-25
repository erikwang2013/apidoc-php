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

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
