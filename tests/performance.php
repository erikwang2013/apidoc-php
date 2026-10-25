<?php
declare(strict_types = 1);

/**
 * 响应性能回归: 文档接口缓存命中 + 协议定义解析/渲染
 * 预算: CPU P95 < 10ms(本机处理; 不含框架/HTTP 服务器/外部网络耗时)
 * 墙钟 P50/P95/max 仅报告(宿主 IO/调度会放大墙钟尾部, 不作为门禁)
 * 运行: php tests/performance.php
 */

use erikwang2013\apidoc\Controller;
use erikwang2013\apidoc\parses\ParseApiDetail;
use erikwang2013\apidoc\utils\ConfigProvider;

error_reporting(E_ALL);
$root = dirname(__DIR__);
define('APIDOC_ROOT_PATH', $root . '/');
$storage = sys_get_temp_dir() . '/apidoc-perf-' . getmypid() . '/';
@mkdir($storage, 0777, true);
define('APIDOC_STORAGE_PATH', $storage);

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

$config = require __DIR__ . '/../src/config.php';
$config['cache'] = ['folder' => 'apidoc', 'enable' => true];
$config['protocols'] = ['allowed_roots' => [$root]];
$config['apps'] = [['key' => 'api', 'title' => 'API', 'path' => 'tests/fixtures/protocol']];
$config['docs'] = [['title' => 'Readme', 'path' => 'README.md']];

const BUDGET_MS = 10.0;
const RUNS = 100;

function configure(array $params): void
{
    global $config;
    $config['request_params'] = $params;
    ConfigProvider::set($config);
}

function cpuMs(): float
{
    $usage = getrusage();
    return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000
        + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
}

/**
 * @return array ['p50'=>float,'p95'=>float,'p99'=>float,'max'=>float]
 */
function stats(array $times): array
{
    sort($times);
    $count = count($times);
    return [
        'p50' => $times[(int)($count * 0.50)],
        'p95' => $times[(int)($count * 0.95)],
        'p99' => $times[(int)($count * 0.99)],
        'max' => $times[$count - 1],
    ];
}

/**
 * 同时记录 CPU 与墙钟; CPU 不受宿主 IO/调度抖动影响, 用于硬门禁
 * @return array ['cpu'=>array,'wall'=>array]
 */
function measure(callable $fn, int $runs = RUNS): array
{
    $fn();
    $cpu = [];
    $wall = [];
    for ($i = 0; $i < $runs; $i++) {
        $cpuStarted = cpuMs();
        $wallStarted = hrtime(true);
        $fn();
        $wall[] = (hrtime(true) - $wallStarted) / 1e6;
        $cpu[] = cpuMs() - $cpuStarted;
    }
    return ['cpu' => stats($cpu), 'wall' => stats($wall)];
}

$results = [];
$check = function (string $name, array $stats) use (&$results): void {
    $results[$name] = $stats;
    $flag = $stats['cpu']['p95'] >= BUDGET_MS ? ' FAIL' : '';
    printf(
        "%-34s cpu p95=%6.2fms | wall p50=%6.2fms p95=%6.2fms max=%7.2fms%s\n",
        $name,
        $stats['cpu']['p95'],
        $stats['wall']['p50'],
        $stats['wall']['p95'],
        $stats['wall']['max'],
        $flag
    );
};

// ---------- 缓存命中的文档接口 ----------
$detailParams = [
    'appKey' => 'api',
    'path' => 'Tests\\Fixtures\\Protocol\\OpenApiFixture@getUser',
    'lang' => '',
];
configure($detailParams);
$check('getApiDetail (cache hit)', measure(function () {
    (new Controller())->getApiDetail();
}));

configure(['appKey' => 'api', 'lang' => '']);
$check('getApiMenus (cache hit)', measure(function () {
    (new Controller())->getApiMenus();
}));

configure(['lang' => '']);
$check('getAllApiMenus (cache hit)', measure(function () {
    (new Controller())->getAllApiMenus();
}));

configure(['lang' => '']);
$check('getConfig', measure(function () {
    (new Controller())->getConfig();
}));

configure(['appKey' => 'api', 'path' => 'README.md', 'lang' => '']);
$check('getMdDetail', measure(function () {
    (new Controller())->getMdDetail();
}));

configure(['appKey' => 'api', 'pageIndex' => 1, 'lang' => '']);
$check('getApiShareList', measure(function () {
    (new Controller())->getApiShareList();
}));

// ---------- 协议定义解析/渲染(不走详情缓存) ----------
$cases = [
    'openapi' => ['Tests\\Fixtures\\Protocol\\OpenApiFixture', 'getUser'],
    'asyncapi' => ['Tests\\Fixtures\\Protocol\\AsyncApiFixture', 'onTemp'],
    'graphql' => ['Tests\\Fixtures\\Protocol\\GraphqlFixture', 'user'],
    'proto' => ['Tests\\Fixtures\\Protocol\\ProtoFixture', 'sayHello'],
    'wsdl' => ['Tests\\Fixtures\\Protocol\\WsdlFixture', 'add'],
    'edmx' => ['Tests\\Fixtures\\Protocol\\EdmxFixture', 'products'],
    'json-rpc' => ['Tests\\Fixtures\\Protocol\\JsonRpcFixture', 'getUser'],
    'xml-rpc' => ['Tests\\Fixtures\\Protocol\\XmlRpcFixture', 'add'],
    'json-schema' => ['Tests\\Fixtures\\Protocol\\JsonSchemaFixture', 'user'],
];
$config['cache']['enable'] = false;
configure($detailParams);
foreach ($cases as $name => $case) {
    $ref = new ReflectionClass($case[0]);
    $method = $ref->getMethod($case[1]);
    $check('render ' . $name, measure(function () use ($config, $case) {
        (new ParseApiDetail($config))->renderApiDetail('api', $case[0] . '@' . $case[1]);
    }, 50));
}

printf("\n%d endpoints measured, budget P95 < %.0f ms\n", count($results), BUDGET_MS);
$failed = array_filter($results, static function (array $stats): bool {
    return $stats['cpu']['p95'] >= BUDGET_MS;
});
if ($failed !== []) {
    echo "FAIL: " . implode(', ', array_keys($failed)) . " exceeded the budget\n";
    exit(1);
}
echo "PASS\n";
