<?php
declare(strict_types=1);

/**
 * 调试中继测试（无框架、无依赖）: php tests/debug.php
 * 覆盖: raw(http/tcp/udp/hex) 驱动、http-relay 驱动、中继开关、目标不可被客户端覆盖、限流、控制器鉴权
 */

use erikwang2013\apidoc\Controller;
use erikwang2013\apidoc\protocol\debug\DebugProxy;
use erikwang2013\apidoc\providers\BaseService;
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
usleep(100000);
if (!waitPort('127.0.0.1', 19011) || !waitPort('127.0.0.1', 19005)) {
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
$limitedConfig['protocols']['debug']['rate_limit'] = 1;
$limited = new DebugProxy($limitedConfig);
$first = $limited->send('api', $rawApi, ['payload' => 'a', 'timeout' => 2], 'token-a');
$second = $limited->send('api', $rawApi, ['payload' => 'b', 'timeout' => 2], 'token-a');
check('rate limit first call passes', ($first['ok'] ?? false) === true);
check('rate limit second call rejected', ($second['ok'] ?? true) === false && strpos((string)($second['error'] ?? ''), 'rate limit') !== false);
check('rate limit skipped without key', ((new DebugProxy($limitedConfig))->send('api', $rawApi, ['payload' => 'c', 'timeout' => 2])['ok'] ?? false) === true);

// ---------- http-relay 驱动 ----------
$http = $proxy->send('api', $httpApi, ['method' => 'POST', 'payload' => 'hello', 'encoding' => 'text']);
check('http relay ok', ($http['ok'] ?? false) === true);
check('http relay body', strpos((string)($http['body'] ?? ''), 'hello') !== false);

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

$routes = (new ReflectionClass(BaseService::class))->getStaticProperties()['routes'] ?? [];
$rules = array_column($routes, 'rule');
check('debug routes registered', !array_diff(['debugSend', 'debugStart', 'debugPoll', 'debugStop'], $rules));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
