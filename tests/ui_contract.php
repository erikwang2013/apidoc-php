<?php
declare(strict_types=1);

/**
 * UIC-1 contract fixture test (no framework, no dependencies):
 * php tests/ui_contract.php
 */

use erikwang2013\apidoc\Auth;
use erikwang2013\apidoc\Controller;
use erikwang2013\apidoc\protocol\debug\DebugRegistry;
use erikwang2013\apidoc\ui\UiCapabilities;
use erikwang2013\apidoc\utils\ApiShare;
use erikwang2013\apidoc\utils\ConfigProvider;
use erikwang2013\apidoc\utils\Helper;

require __DIR__ . '/_bootstrap.php';

// Tests\Fixtures\Ui controllers live under tests/fixtures/default (E2E fixture app)
spl_autoload_register(function (string $class): void {
    $prefix = 'Tests\\Fixtures\\Ui\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__ . '/fixtures/default/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function ui_contract_config(array $params, string $appPath = 'default', array $extra = []): void
{
    ConfigProvider::set(array_merge([
        'title' => 'Demo',
        'definitions' => '',
        'apps' => [
            ['key' => 'default', 'title' => 'Default', 'path' => $appPath],
        ],
        'auth' => ['enable' => true, 'password' => 'pw', 'secret_key' => 's', 'expire' => 3600],
        'cache' => ['enable' => true],
        'request_params' => $params,
    ], $extra));
}

function export_leaves(array $nodes): array
{
    $leaves = [];
    foreach ($nodes as $node) {
        if (!empty($node['children']) && is_array($node['children'])) {
            $leaves = array_merge($leaves, export_leaves($node['children']));
        } elseif (isset($node['url']) || isset($node['method'])) {
            $leaves[] = $node;
        }
    }
    return $leaves;
}

$menus = contract_fixture('menus');
check('menus fixture loads', is_array($menus) && isset($menus[0]['response']['code']));
check('menus fixture envelope', ($menus[0]['response']['code'] ?? null) === 0);

$capsFixture = contract_fixture('capabilities');
check('capabilities fixture loads', is_array($capsFixture) && isset($capsFixture[0]['response']['data']));
check(
    'capabilities contract is 1.0',
    ($capsFixture[0]['response']['data']['contract'] ?? '') === '1.0'
);

ConfigProvider::set([
    'title' => 'Demo',
    'apps' => [
        ['key' => 'default', 'title' => 'Default', 'path' => 'default'],
    ],
    'auth' => ['enable' => true, 'password' => 'pw', 'secret_key' => 's', 'expire' => 3600],
    'cache' => ['enable' => true],
]);
$caps = UiCapabilities::fromConfig(ConfigProvider::get());
check('UIC capabilities contract', ($caps['contract'] ?? '') === '1.0');
check('UIC capabilities backend', ($caps['backend'] ?? '') === 'php');
check('UIC capabilities relay drivers', count($caps['features']['debug']['relay'] ?? []) === 12);
check('UIC capabilities definitions', ($caps['features']['protocolDefinitions'] ?? false) === true);
$driverNames = DebugRegistry::names();
check(
    'DebugRegistry exposes driver names',
    count($driverNames) === 12 && in_array('mqtt', $driverNames, true)
);
check(
    'UIC capabilities advertises legacy generator side effects',
    ($caps['features']['generator']['sideEffects'] ?? null) === true
    && ($caps['features']['generator']['forms'] ?? null) === true
    && ($caps['features']['generator']['templates'] ?? null) === true
);
check(
    'UIC capabilities management features',
    ($caps['features']['share']['records'] ?? null) === true
    && ($caps['features']['share']['actions'] ?? null) === true
    && ($caps['features']['cache']['mode'] ?? '') === 'server'
    && ($caps['features']['auth']['shareToken'] ?? null) === true
    && in_array('asyncapi', $caps['features']['exports'] ?? [], true)
);

$auth = new Auth(['auth' => ['enable' => true, 'password' => 'pw', 'secret_key' => 's', 'expire' => 3600]]);
$token = $auth->verifyPlain('pw', '');
check('UIC plaintext token verifies', $auth->checkToken($token, '') === true);
$wrongThrew = false;
try {
    $auth->verifyPlain('bad', '');
} catch (\Throwable $e) {
    $wrongThrew = true;
}
check('UIC plaintext wrong password throws', $wrongThrew);

// Share links store md5(plaintext); ApiShare::checkShareAuth passes that digest
// to checkToken. A token minted from the plaintext never verifies for a share.
$sharePlain = 'share-pw';
$shareDigest = md5($sharePlain);
$shareToken = $auth->createToken($shareDigest);
check('UIC share token from stored digest verifies', $auth->checkToken($shareToken, $shareDigest) === true);
$plaintextToken = $auth->createToken($sharePlain);
check('UIC share token from plaintext rejected', $auth->checkToken($plaintextToken, $shareDigest) === false);

check(
    'UIC controller methods exist',
    method_exists(Controller::class, 'uiCapabilities')
    && method_exists(Controller::class, 'uiVerifyAuth')
    && method_exists(Controller::class, 'uiDebugSend')
);
$routes = file_get_contents(__DIR__ . '/../src/providers/BaseService.php');
check('UIC debug route declared', strpos((string)$routes, "'ui/v1/debugSend'") !== false);
check('UIC verify route declared', strpos((string)$routes, "'ui/v1/verifyAuth'") !== false);

// ---------- Phase 2 management aliases ----------

$managementMethods = [
    'uiAddApiShare',
    'uiGetApiShareList',
    'uiGetApiShareDetail',
    'uiDeleteApiShare',
    'uiHandleApiShareAction',
    'uiCacheClear',
    'uiCacheWarm',
    'uiGenerator',
    'uiRenderCodeTemplate',
    'uiExportAllData',
];
$missingMethods = array_filter($managementMethods, fn ($m) => !method_exists(Controller::class, $m));
check('UIC management controller methods exist', $missingMethods === []);
$managementRoutes = [
    'ui/v1/addApiShare',
    'ui/v1/getApiShareList',
    'ui/v1/getApiShareDetail',
    'ui/v1/deleteApiShare',
    'ui/v1/handleApiShareAction',
    'ui/v1/cache/clear',
    'ui/v1/cache/warm',
    'ui/v1/generator',
    'ui/v1/renderCodeTemplate',
    'ui/v1/exportAllData',
];
$missingRoutes = array_filter(
    $managementRoutes,
    fn ($r) => strpos((string)$routes, "'" . $r . "'") === false
);
check('UIC management routes declared', $missingRoutes === []);

$adminToken = $auth->createToken('pw');

ui_contract_config(['token' => $adminToken]);
$clear = (new Controller())->uiCacheClear();
check(
    'UIC cache clear shape',
    ($clear['code'] ?? null) === 0
    && ($clear['data']['ok'] ?? null) === true
    && ($clear['data']['mode'] ?? '') === 'server'
);
$warm = (new Controller())->uiCacheWarm();
check(
    'UIC cache warm shape',
    ($warm['code'] ?? null) === 0
    && ($warm['data']['ok'] ?? null) === true
    && ($warm['data']['mode'] ?? '') === 'server'
    && ($warm['data']['message'] ?? '') === 'cache rebuilt'
);

$shareActionConfig = [
    'share' => [
        'actions' => [
            ['click' => function (array $shareData): array {
                return ['action' => 'copy', 'params' => ['name' => $shareData['name']]];
            }],
        ],
    ],
];
ui_contract_config([
    'token' => $adminToken,
    'key' => 'share-test-1',
    'name' => 'Team',
    'type' => 'app',
    'appKeys' => ['default'],
    'password' => 'share-pw',
], 'default', $shareActionConfig);
$shareAdd = (new Controller())->uiAddApiShare();
check('UIC share add', ($shareAdd['data'] ?? null) === true);

ui_contract_config(['token' => $adminToken, 'pageIndex' => 1]);
$shareList = (new Controller())->uiGetApiShareList();
$listRow = $shareList['data']['data'][0] ?? [];
check(
    'UIC share list hides password',
    ($shareList['data']['total'] ?? 0) >= 1
    && ($listRow['key'] ?? '') === 'share-test-1'
    && !array_key_exists('password', $listRow)
);

ui_contract_config(['token' => $adminToken, 'key' => 'share-test-1']);
$shareDetail = (new Controller())->uiGetApiShareDetail();
check(
    'UIC share detail contract shape',
    ($shareDetail['data']['key'] ?? '') === 'share-test-1'
    && ($shareDetail['data']['name'] ?? '') === 'Team'
    && ($shareDetail['data']['type'] ?? '') === 'app'
    && !array_key_exists('password', $shareDetail['data'])
);

ui_contract_config([
    'token' => $adminToken,
    'key' => 'share-test-1',
    'index' => 0,
], 'default', $shareActionConfig);
$shareAction = (new Controller())->uiHandleApiShareAction();
check(
    'UIC share action delegates',
    ($shareAction['data']['action'] ?? '') === 'copy'
    && ($shareAction['data']['params']['name'] ?? '') === 'Team'
);

ui_contract_config(['password' => 'share-pw', 'shareKey' => 'share-test-1']);
$shareLogin = (new Controller())->uiVerifyAuth();
$shareToken = (string)($shareLogin['data']['token'] ?? '');
check(
    'UIC share login mints token from stored digest',
    $shareToken !== '' && $auth->checkToken($shareToken, md5('share-pw')) === true
);

// Share-scoped config/menus/detail through the UIC-1 Bearer header path
$fixtureAppPath = 'tests/fixtures/default';
$menuKey = Helper::createApiKey('Tests\\Fixtures\\Ui\\UsersController', 'listUsers');
// cache off: the warm probe above cached an empty menu for the placeholder app path
ui_contract_config([], $fixtureAppPath, ['cache' => ['enable' => false]]);
$_GET = ['appKey' => 'default', 'shareKey' => 'share-test-1', 'path' => $menuKey];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $shareToken;
$bearerConfig = (new Controller())->uiConfig();
check(
    'UIC share config via Bearer token',
    ($bearerConfig['code'] ?? null) === 0
    && count($bearerConfig['data']['apps'] ?? []) === 1
    && ($bearerConfig['data']['capabilities']['contract'] ?? '') === '1.0'
);
$bearerMenus = (new Controller())->uiMenus();
$bearerLeaves = export_leaves($bearerMenus['data']['data'] ?? []);
check(
    'UIC share menus via Bearer token',
    ($bearerMenus['code'] ?? null) === 0
    && count($bearerLeaves) === 1
    && ($bearerLeaves[0]['url'] ?? '') === '/users'
);
$bearerDetail = (new Controller())->uiDetail();
check(
    'UIC share detail via Bearer token',
    ($bearerDetail['code'] ?? null) === 0
    && ($bearerDetail['data']['title'] ?? '') === 'List users'
    && ($bearerDetail['data']['appKey'] ?? '') === 'default'
);
unset($_SERVER['HTTP_AUTHORIZATION']);
$_GET = [];

// exportAllData: embedded details for one app, no per-leaf HTTP calls
ui_contract_config(['token' => $adminToken, 'appKey' => 'default'], $fixtureAppPath);
$export = (new Controller())->uiExportAllData();
$exportTree = $export['data']['apiData'] ?? [];
$exportLeaves = export_leaves($exportTree);
$exportLeaf = $exportLeaves[0] ?? [];
$requiredFields = [
    'name', 'title', 'menuKey', 'method', 'url', 'appKey', 'desc', 'header', 'query',
    'routeParam', 'param', 'responseSuccess', 'responseError', 'responseStatus',
    'protocol', 'channel', 'definitions', 'validation',
];
$missingFields = array_filter($requiredFields, fn ($f) => !array_key_exists($f, $exportLeaf));
check(
    'UIC exportAllData app root',
    ($exportTree[0]['appKey'] ?? '') === 'default' && ($exportTree[0]['title'] ?? '') === 'Default'
);
check(
    'UIC exportAllData embeds required detail fields',
    count($exportLeaves) === 1 && $missingFields === []
    && ($exportLeaf['name'] ?? '') === 'listUsers'
    && ($exportLeaf['method'] ?? '') === 'GET'
    && ($exportLeaf['url'] ?? '') === '/users'
    && ($exportLeaf['appKey'] ?? '') === 'default'
    && ($exportLeaf['menuKey'] ?? '') === $menuKey
);
check(
    'UIC exportAllData carries params and validation',
    in_array('limit', array_column($exportLeaf['param'] ?? [], 'name'), true)
    && ($exportLeaf['validation']['ok'] ?? null) === true
);

// Share-scoped export filters apiKeys and never returns the password digest
(new ApiShare())->addApiShare([
    'key' => 'share-api-1',
    'name' => 'Api share',
    'type' => 'api',
    'apiKeys' => [$menuKey],
]);
ui_contract_config(['token' => $adminToken, 'key' => 'share-api-1'], $fixtureAppPath);
$shareExport = (new Controller())->uiExportAllData();
$shareExportLeaves = export_leaves($shareExport['data']['apiData'] ?? []);
$shareExportJson = json_encode($shareExport);
check(
    'UIC exportAllData share scope',
    count($shareExportLeaves) === 1
    && ($shareExportLeaves[0]['menuKey'] ?? '') === $menuKey
    && strpos((string)$shareExportJson, 'password') === false
);

ui_contract_config(['token' => $adminToken, 'key' => 'share-api-1']);
$shareDelete = (new Controller())->uiDeleteApiShare();
check('UIC share delete', ($shareDelete['data'] ?? null) === true);

contract_exit();
