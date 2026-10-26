<?php
declare(strict_types=1);

/**
 * UIC-1 contract fixture test (no framework, no dependencies):
 * php tests/ui_contract.php
 */

use erikwang2013\apidoc\Auth;
use erikwang2013\apidoc\Controller;
use erikwang2013\apidoc\exception\HttpException;
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
        return;
    }
    $prefix = 'Tests\\Fixtures\\Other\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__ . '/fixtures/other/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
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

/** Exact key match (hasPassword must not count as password) */
function array_has_key_recursive(array $data, string $needle): bool
{
    foreach ($data as $key => $value) {
        if ((string)$key === $needle) {
            return true;
        }
        if (is_array($value) && array_has_key_recursive($value, $needle)) {
            return true;
        }
    }
    return false;
}

$fixtureAppPath = 'tests/fixtures/default';
$protectedApp = ['key' => 'default', 'title' => 'Default', 'path' => $fixtureAppPath, 'password' => 'APPSECRET-XYZ'];

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
check('UIC capabilities relay drivers', count($caps['features']['debug']['relay'] ?? []) === 16);
check('UIC capabilities definitions', ($caps['features']['protocolDefinitions'] ?? false) === true);
$driverNames = DebugRegistry::names();
check(
    'DebugRegistry exposes driver names',
    count($driverNames) === 16 && in_array('mqtt', $driverNames, true)
    && !array_diff(['thrift', 'dubbo', 'http2', 'http3'], $driverNames)
);
check(
    'UIC capabilities generator is off without declarative config',
    ($caps['features']['generator']['sideEffects'] ?? null) === false
    && ($caps['features']['generator']['forms'] ?? null) === false
    && ($caps['features']['generator']['templates'] ?? null) === false
);
check(
    'UIC capabilities management features',
    ($caps['features']['share']['records'] ?? null) === true
    && ($caps['features']['share']['actions'] ?? null) === false
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

$actionApiData = null;
$shareActionConfig = [
    'share' => [
        'actions' => [
            ['click' => function (array $shareData, array $apiData) use (&$actionApiData): array {
                $actionApiData = $apiData;
                return ['action' => 'copy', 'params' => ['name' => $shareData['name']]];
            }],
        ],
    ],
    'apps' => [$protectedApp],
];
ui_contract_config([
    'token' => $adminToken,
    'key' => 'share-test-1',
    'name' => 'Team',
    'type' => 'app',
    'appKeys' => ['default'],
    'password' => 'share-pw',
], $fixtureAppPath, $shareActionConfig);
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
check(
    'UIC share list contract fields',
    preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($listRow['create_time'] ?? '')) === 1
    && ($listRow['appKeys'] ?? []) === ['default']
);

ui_contract_config(['token' => $adminToken, 'key' => 'share-test-1']);
$shareDetail = (new Controller())->uiGetApiShareDetail();
check(
    'UIC share detail contract shape',
    ($shareDetail['data']['key'] ?? '') === 'share-test-1'
    && ($shareDetail['data']['name'] ?? '') === 'Team'
    && ($shareDetail['data']['type'] ?? '') === 'app'
    && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($shareDetail['data']['create_time'] ?? '')) === 1
    && !array_key_exists('password', $shareDetail['data'])
);

// Legacy /handleApiShareAction keeps executing callables, but never with app passwords.
ui_contract_config([
    'token' => $adminToken,
    'key' => 'share-test-1',
    'index' => 0,
], $fixtureAppPath, $shareActionConfig);
$legacyShareAction = (new Controller())->handleApiShareAction();
check(
    'legacy share action still executes callable',
    ($legacyShareAction['data']['action'] ?? '') === 'copy'
    && ($legacyShareAction['data']['params']['name'] ?? '') === 'Team'
);
check(
    'legacy share action payload has no app password',
    is_array($actionApiData)
    && strpos((string)json_encode($actionApiData), 'APPSECRET-XYZ') === false
    && !array_has_key_recursive($actionApiData, 'password')
);

// UIC-1 alias must not execute configured PHP callables.
$actionApiData = null;
ui_contract_config([
    'token' => $adminToken,
    'key' => 'share-test-1',
    'index' => 0,
], $fixtureAppPath, $shareActionConfig);
$shareAction = (new Controller())->uiHandleApiShareAction();
check(
    'UIC share action does not execute callables',
    ($shareAction['data'] ?? null) === false && $actionApiData === null
);

ui_contract_config(['password' => 'share-pw', 'shareKey' => 'share-test-1']);
$shareLogin = (new Controller())->uiVerifyAuth();
$shareToken = (string)($shareLogin['data']['token'] ?? '');
check(
    'UIC share login mints token from stored digest',
    $shareToken !== '' && $auth->checkToken($shareToken, md5('share-pw')) === true
);

// Share-scoped config/menus/detail through the UIC-1 Bearer header path
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

// ---------- Security regressions (review C1/C2/I2/M4/M9) ----------

// C1: a public type:"all" share must never expose app passwords
(new ApiShare())->addApiShare(['key' => 'share-all-public', 'name' => 'Public all', 'type' => 'all']);
ui_contract_config(['shareKey' => 'share-all-public'], $fixtureAppPath, [
    'apps' => [$protectedApp],
    'cache' => ['enable' => false],
]);
$publicShareExport = (new Controller())->uiExportAllData();
$publicShareLeaves = export_leaves($publicShareExport['data']['apiData'] ?? []);
check(
    'C1 public share export strips app password',
    count($publicShareLeaves) === 1
    && ($publicShareExport['data']['apiData'][0]['hasPassword'] ?? null) === true
    && strpos((string)json_encode($publicShareExport), 'APPSECRET-XYZ') === false
    && !array_has_key_recursive($publicShareExport['data']['apiData'] ?? [], 'password')
);

// C1 (legacy path): getAllApiMenus uses the same export tree
ui_contract_config(['token' => $adminToken], $fixtureAppPath, [
    'apps' => [$protectedApp],
    'cache' => ['enable' => false],
]);
$legacyAllMenus = (new Controller())->getAllApiMenus();
check(
    'C1 legacy allApiMenus strips app password',
    count(export_leaves($legacyAllMenus['data'] ?? [])) === 1
    && strpos((string)json_encode($legacyAllMenus), 'APPSECRET-XYZ') === false
    && !array_has_key_recursive($legacyAllMenus['data'] ?? [], 'password')
);

// C2: app-password tokens must not pass UIC-1 management endpoints
ui_contract_config([], $fixtureAppPath, ['apps' => [$protectedApp]]);
$appToken = (new Auth(ConfigProvider::get()))->verifyPlain('APPSECRET-XYZ', 'default');
$appTokenCases = [
    'uiAddApiShare' => ['name' => 'evil', 'type' => 'all'],
    'uiGetApiShareList' => [],
    'uiGetApiShareDetail' => ['key' => 'share-test-1'],
    'uiDeleteApiShare' => ['key' => 'share-test-1'],
    'uiHandleApiShareAction' => ['key' => 'share-test-1', 'index' => 0],
    'uiCacheClear' => [],
    'uiCacheWarm' => [],
    'uiGenerator' => [],
    // uiRenderCodeTemplate is intentionally app-scoped (N1), not admin-only
    'uiExportAllData' => ['key' => 'share-test-1'],
];
$appTokenStatuses = [];
foreach ($appTokenCases as $method => $params) {
    ui_contract_config(array_merge(['token' => $appToken, 'appKey' => 'default'], $params), $fixtureAppPath, [
        'apps' => [$protectedApp],
    ]);
    try {
        (new Controller())->{$method}();
        $appTokenStatuses[$method] = 0;
    } catch (HttpException $e) {
        $appTokenStatuses[$method] = $e->getStatusCode();
    } catch (\Throwable $e) {
        $appTokenStatuses[$method] = -1;
    }
}
check(
    'C2 app token rejected with 401 on every management endpoint',
    count($appTokenStatuses) === count($appTokenCases)
    && array_unique(array_values($appTokenStatuses)) === [401]
);

// Global admin token still works even when the request names a protected appKey.
ui_contract_config(['token' => $adminToken, 'appKey' => 'default', 'pageIndex' => 1], $fixtureAppPath, [
    'apps' => [$protectedApp],
]);
$adminShareList = (new Controller())->uiGetApiShareList();
check('C2 global token still manages shares', ($adminShareList['code'] ?? null) === 0);

// I2: with global auth off, anonymous all-apps export serves unprotected apps only
$mixedApps = [
    ['key' => 'default', 'title' => 'Default', 'path' => $fixtureAppPath],
    ['key' => 'secure', 'title' => 'Secure', 'path' => $fixtureAppPath, 'password' => 'APPSECRET-XYZ'],
];
$mixedExtra = [
    'apps' => $mixedApps,
    'auth' => ['enable' => false, 'secret_key' => 's', 'expire' => 3600],
    'cache' => ['enable' => false],
];
ui_contract_config([], $fixtureAppPath, $mixedExtra);
$mixedAnonExport = (new Controller())->uiExportAllData();
check(
    'I2 anonymous export omits protected apps',
    array_column($mixedAnonExport['data']['apiData'] ?? [], 'appKey') === ['default']
    && strpos((string)json_encode($mixedAnonExport), 'APPSECRET-XYZ') === false
);

// A protected app stays exportable with its own app token
ui_contract_config([], $fixtureAppPath, $mixedExtra);
$secureToken = (new Auth(ConfigProvider::get()))->verifyPlain('APPSECRET-XYZ', 'secure');
ui_contract_config(['token' => $secureToken, 'appKey' => 'secure'], $fixtureAppPath, $mixedExtra);
$mixedSecureExport = (new Controller())->uiExportAllData();
check(
    'I2 protected app export requires and accepts its app token',
    array_column($mixedSecureExport['data']['apiData'] ?? [], 'appKey') === ['secure']
    && count(export_leaves($mixedSecureExport['data']['apiData'] ?? [])) === 1
);

$missingAppTokenStatus = 0;
ui_contract_config(['appKey' => 'secure'], $fixtureAppPath, $mixedExtra);
try {
    (new Controller())->uiExportAllData();
} catch (HttpException $e) {
    $missingAppTokenStatus = $e->getStatusCode();
}
check('I2 protected app export without token is 401', $missingAppTokenStatus === 401);

// I2 (owner key path): a password-protected share requires its share token when global auth is off
(new ApiShare())->addApiShare([
    'key' => 'share-protected-all',
    'name' => 'Protected all',
    'type' => 'all',
    'password' => 'share-pw',
]);
ui_contract_config(['key' => 'share-protected-all'], $fixtureAppPath, $mixedExtra);
$keyExportStatus = 0;
try {
    (new Controller())->uiExportAllData();
} catch (HttpException $e) {
    $keyExportStatus = $e->getStatusCode();
}
check('I2 owner key export of password share without token is 401', $keyExportStatus === 401);
$protectedShareToken = (new Auth(ConfigProvider::get()))->createToken(md5('share-pw'));
ui_contract_config(['key' => 'share-protected-all', 'token' => $protectedShareToken], $fixtureAppPath, $mixedExtra);
$keyShareExport = (new Controller())->uiExportAllData();
check(
    'I2 owner key export accepts the share token',
    count(export_leaves($keyShareExport['data']['apiData'] ?? [])) === 2
    && strpos((string)json_encode($keyShareExport), 'APPSECRET-XYZ') === false
);

// M4: unknown types and missing required scope keys fail closed
$m4Rejected = 0;
$m4Cases = [
    ['name' => 'bad', 'type' => 'nope'],
    ['name' => 'bad', 'type' => 'app'],
    ['name' => 'bad', 'type' => 'api'],
];
foreach ($m4Cases as $m4Params) {
    ui_contract_config(array_merge(['token' => $adminToken], $m4Params), $fixtureAppPath, ['apps' => [$protectedApp]]);
    try {
        (new Controller())->uiAddApiShare();
    } catch (HttpException $e) {
        $m4Rejected++;
    }
}
check('M4 invalid share type/scope rejected', $m4Rejected === count($m4Cases));

// M9: generated share keys are random hex, not uniqid()
(new ApiShare())->addApiShare(['name' => 'Generated key', 'type' => 'all']);
ui_contract_config(['token' => $adminToken, 'pageIndex' => 1], $fixtureAppPath, ['apps' => [$protectedApp]]);
$generatedList = (new Controller())->uiGetApiShareList();
$generatedKey = '';
foreach (($generatedList['data']['data'] ?? []) as $row) {
    if (($row['name'] ?? '') === 'Generated key') {
        $generatedKey = (string)($row['key'] ?? '');
    }
}
check('M9 generated share keys are random hex', preg_match('/^[0-9a-f]{32}$/', $generatedKey) === 1);

// ---------- N1: renderCodeTemplate stays app-scoped, templates are allowlisted ----------

$codeTemplateItem = [
    'title' => 'Sample',
    'template' => 'tests/fixtures/templates/sample.tpl',
    'select_mode' => 'api',
    'multiple' => true,
    'language' => 'text',
    'tips' => '',
    'limit' => 100,
    'form' => ['items' => []],
];
$n1Extra = [
    'apps' => [$protectedApp],
    'code_template' => [$codeTemplateItem],
    'share' => ['actions' => [['click' => fn () => true]]],
    'cache' => ['enable' => false],
];
ui_contract_config([], $fixtureAppPath, $n1Extra);
$n1AppToken = (new Auth(ConfigProvider::get()))->verifyPlain('APPSECRET-XYZ', 'default');
$n1Params = [
    'token' => $n1AppToken,
    'appKey' => 'default',
    'template' => 'Sample',
    'selected' => [$menuKey],
    'form' => ['name' => 'ok'],
];

ui_contract_config($n1Params, $fixtureAppPath, $n1Extra);
$n1Render = (new Controller())->uiRenderCodeTemplate();
check(
    'N1 app token renders a configured template by logical name',
    trim((string)($n1Render['data']['code'] ?? '')) === 'Sample ok'
);

// legacy UI/route still echoes the configured path from legacy /getConfig
ui_contract_config(array_merge($n1Params, ['template' => $codeTemplateItem]), $fixtureAppPath, $n1Extra);
$n1LegacyRender = (new Controller())->renderCodeTemplate();
check(
    'N1 legacy renderCodeTemplate stays app-scoped',
    trim((string)($n1LegacyRender['data']['code'] ?? '')) === 'Sample ok'
);

$n1DeniedStatuses = [];
$n1BadTemplates = [
    ['template' => 'README.md', 'select_mode' => 'api', 'multiple' => true],
    ['template' => '../composer.json', 'select_mode' => 'api', 'multiple' => true],
    // UIC-1 must not accept the configured server path, only the logical name
    ['template' => 'tests/fixtures/templates/sample.tpl', 'title' => 'Sample'],
    'README.md',
];
foreach ($n1BadTemplates as $badTemplate) {
    ui_contract_config(array_merge($n1Params, ['template' => $badTemplate]), $fixtureAppPath, $n1Extra);
    try {
        (new Controller())->uiRenderCodeTemplate();
        $n1DeniedStatuses[] = 0;
    } catch (HttpException $e) {
        $n1DeniedStatuses[] = $e->getStatusCode();
    }
}
check(
    'N1 unconfigured template paths rejected',
    count($n1DeniedStatuses) === count($n1BadTemplates)
    && array_unique($n1DeniedStatuses) === [412]
);

$publicAppExtra = [
    'apps' => [['key' => 'default', 'title' => 'Default', 'path' => $fixtureAppPath]],
    'code_template' => [$codeTemplateItem],
    'cache' => ['enable' => false],
];
ui_contract_config([
    'token' => $adminToken,
    'appKey' => 'default',
    'template' => 'Sample',
    'selected' => [$menuKey],
    'form' => ['name' => 'global'],
], $fixtureAppPath, $publicAppExtra);
$n1GlobalRender = (new Controller())->uiRenderCodeTemplate();
check(
    'N1 global token renders a configured template',
    trim((string)($n1GlobalRender['data']['code'] ?? '')) === 'Sample global'
);

// ---------- Phase 2b: declarative generator (in-memory, no side effects) ----------

$generatorTemplate = 'tests/fixtures/templates/generator.tpl';
$generatorItem = [
    'name' => 'service',
    'title' => 'Service',
    'files' => [[
        'name' => 'gen',
        'path' => 'tests/fixtures/generated',
        'namespace' => 'App\\Gen',
        'template' => $generatorTemplate,
    ]],
    'form' => ['items' => [['name' => 'name', 'title' => 'Name', 'type' => 'input']]],
];
$legacyGeneratorItem = [
    'name' => 'legacy',
    'title' => 'Legacy',
    'files' => [[
        'name' => 'gen',
        'path' => 'tests/fixtures/generated',
        'template' => $generatorTemplate,
    ]],
    'middleware' => [],
];
$generatorExtra = [
    'apps' => [$protectedApp],
    'ui_generator' => [$generatorItem],
    'generator' => [$legacyGeneratorItem],
    'code_template' => [$codeTemplateItem],
    'database_query_function' => fn () => false,
    'cache' => ['enable' => false],
];

ui_contract_config(['token' => $adminToken], $fixtureAppPath, $generatorExtra);
$genConfig = (new Controller())->uiConfig();
$genDescriptor = $genConfig['data']['generator'][0] ?? [];
check(
    'P2b uiConfig exposes only declarative generator descriptors',
    count($genConfig['data']['generator'] ?? []) === 1
    && ($genDescriptor['name'] ?? '') === 'service'
    && ($genDescriptor['title'] ?? '') === 'Service'
    && count($genDescriptor['files'] ?? []) === 1
    && !array_key_exists('template', $genDescriptor['files'][0] ?? [])
    && ($genDescriptor['form']['items'][0]['name'] ?? '') === 'name'
);
check(
    'P2b capabilities advertise configured declarative generator',
    ($genConfig['data']['capabilities']['features']['generator']['forms'] ?? null) === true
    && ($genConfig['data']['capabilities']['features']['generator']['templates'] ?? null) === true
    && ($genConfig['data']['capabilities']['features']['generator']['sideEffects'] ?? null) === false
);
$genCodeTemplate = $genConfig['data']['code_template'][0] ?? [];
check(
    'P2b uiConfig exposes logical code-template id, not server path',
    ($genCodeTemplate['template'] ?? '') === 'Sample'
    && ($genCodeTemplate['title'] ?? '') === 'Sample'
    && strpos((string)json_encode($genConfig['data']['code_template']), 'tests/fixtures/templates') === false
);

$genParams = [
    'token' => $adminToken,
    'index' => 0,
    'form' => ['appKey' => 'default', 'name' => 'demo'],
    'tables' => [],
    // 请求里的 template 不受支持;value/path 按配置文件的 name 覆盖
    'files' => [[
        'name' => 'gen',
        'value' => 'Demo',
        'path' => 'tests/fixtures/generated/out',
        'template' => '../composer.json',
    ]],
];
ui_contract_config($genParams, $fixtureAppPath, $generatorExtra);
$genRender = (new Controller())->uiGenerator();
$expectedGen = "class Demo\n{\n    // demo\n    const PATH = 'tests/fixtures/generated/out';\n    const NS = 'App\\Gen';\n}\n";
check(
    'P2b generator renders configured template in memory',
    ($genRender['code'] ?? null) === 0
    && count($genRender['data']['files'] ?? []) === 1
    && ($genRender['data']['files'][0]['name'] ?? '') === 'gen'
    && ($genRender['data']['files'][0]['path'] ?? '') === 'tests/fixtures/generated/out'
    && ($genRender['data']['files'][0]['content'] ?? '') === $expectedGen
);
check(
    'P2b generator writes nothing and ignores request template paths',
    !file_exists(__DIR__ . '/fixtures/generated/out.php')
    && !file_exists(__DIR__ . '/fixtures/generated/Demo.php')
);

$badGeneratorCases = [
    ['index' => 0, 'files' => [['name' => 'nope', 'value' => 'x']]],
    ['index' => 0, 'files' => [['name' => 'gen', 'value' => 'x', 'path' => '../../tmp/evil']]],
    ['index' => 9, 'files' => []],
];
$badGeneratorStatuses = [];
foreach ($badGeneratorCases as $badGeneratorCase) {
    ui_contract_config(array_merge($genParams, $badGeneratorCase), $fixtureAppPath, $generatorExtra);
    try {
        (new Controller())->uiGenerator();
        $badGeneratorStatuses[] = 0;
    } catch (HttpException $e) {
        $badGeneratorStatuses[] = $e->getStatusCode();
    }
}
check(
    'P2b generator rejects unknown files, escaping paths and unknown index',
    count($badGeneratorStatuses) === count($badGeneratorCases)
    && array_unique($badGeneratorStatuses) === [412]
);

// I-2 regression: form values must not select the template file (composer.json probe)
$probeGeneratorExtra = $generatorExtra;
$probeGeneratorExtra['ui_generator'] = [[
    'name' => 'probe',
    'title' => 'Probe',
    'files' => [['name' => 'gen', 'path' => 'tests/fixtures/generated', 'template' => '${form.read}']],
]];
ui_contract_config([
    'token' => $adminToken,
    'index' => 0,
    'form' => ['appKey' => 'default', 'read' => 'composer.json'],
], $fixtureAppPath, $probeGeneratorExtra);
$probeStatus = 0;
$probeLeak = false;
try {
    $probe = (new Controller())->uiGenerator();
    $probeLeak = strpos((string)json_encode($probe), 'erikwang2013/apidoc-php') !== false;
} catch (HttpException $e) {
    $probeStatus = $e->getStatusCode();
}
check(
    'I-2 form values cannot select an arbitrary template file',
    $probeStatus === 412 && !$probeLeak
);

$arrayFormStatus = 0;
try {
    ui_contract_config(array_merge($genParams, ['form' => ['appKey' => 'default', 'name' => ['x']]]), $fixtureAppPath, $generatorExtra);
    (new Controller())->uiGenerator();
} catch (HttpException $e) {
    $arrayFormStatus = $e->getStatusCode();
} catch (\Throwable $e) {
    $arrayFormStatus = -1;
}
check('M-4 array form values fail with 412, not TypeError', $arrayFormStatus === 412);

$duplicateFileStatus = 0;
$duplicateFileExtra = $generatorExtra;
$duplicateFileExtra['ui_generator'] = [array_merge($generatorItem, ['files' => [
    ['name' => 'gen', 'path' => 'tests/fixtures/generated', 'template' => $generatorTemplate],
    ['name' => 'gen', 'path' => 'tests/fixtures/generated', 'template' => $generatorTemplate],
]])];
try {
    ui_contract_config(['token' => $adminToken], $fixtureAppPath, $duplicateFileExtra);
    (new Controller())->uiConfig();
} catch (HttpException $e) {
    $duplicateFileStatus = $e->getCode();
}
check('M-4 duplicate configured file names rejected', $duplicateFileStatus === 4014);

$duplicateTemplateStatus = 0;
$duplicateTemplateExtra = $generatorExtra;
$duplicateTemplateExtra['code_template'] = [$codeTemplateItem, $codeTemplateItem];
try {
    ui_contract_config(['token' => $adminToken], $fixtureAppPath, $duplicateTemplateExtra);
    (new Controller())->uiConfig();
} catch (HttpException $e) {
    $duplicateTemplateStatus = $e->getCode();
}
check('M-4 duplicate configured template names rejected', $duplicateTemplateStatus === 4016);

$disabledGeneratorExtra = $generatorExtra;
$disabledGeneratorExtra['ui_generator'] = [array_merge($generatorItem, ['enable' => false])];
ui_contract_config(['token' => $adminToken], $fixtureAppPath, $disabledGeneratorExtra);
$disabledGeneratorConfig = (new Controller())->uiConfig();
check(
    'M-3 capabilities omit forms when every generator is disabled',
    ($disabledGeneratorConfig['data']['capabilities']['features']['generator']['forms'] ?? null) === false
    && ($disabledGeneratorConfig['data']['generator'] ?? null) === []
);

$generatedLegacyFile = __DIR__ . '/fixtures/generated/Legacy.php';
@unlink($generatedLegacyFile);
ui_contract_config([
    'token' => $adminToken,
    'index' => 0,
    'form' => ['appKey' => 'default', 'name' => 'legacy'],
    'tables' => [],
    'files' => [['name' => 'gen', 'value' => 'Legacy', 'path' => 'tests/fixtures/generated']],
], $fixtureAppPath, $generatorExtra);
$legacyGenerated = (new Controller())->createGenerator();
$legacyGeneratedContent = is_file($generatedLegacyFile) ? (string)file_get_contents($generatedLegacyFile) : '';
check(
    'P2b legacy generator still writes files',
    ($legacyGenerated['data']['form']['name'] ?? '') === 'legacy'
    && strpos($legacyGeneratedContent, 'class Legacy') !== false
    && strpos($legacyGeneratedContent, '// legacy') !== false
);
@unlink($generatedLegacyFile);
@rmdir(__DIR__ . '/fixtures/generated');

// UIC-1 code templates skip selected APIs outside the authenticated app; legacy still rejects them.
$countTemplateItem = array_merge($codeTemplateItem, ['template' => 'tests/fixtures/templates/count.tpl']);
$outOfScopeKey = Helper::createApiKey('Tests\\Fixtures\\Other\\OtherController', 'listOther');
$scopeTemplateExtra = [
    'apps' => [$protectedApp],
    'code_template' => [$countTemplateItem],
    'cache' => ['enable' => false],
];
$scopeTemplateParams = [
    'token' => $n1AppToken,
    'appKey' => 'default',
    'template' => 'Sample',
    'selected' => [$menuKey, $outOfScopeKey],
    'form' => ['name' => 'scope'],
];
ui_contract_config($scopeTemplateParams, $fixtureAppPath, $scopeTemplateExtra);
$scopeRender = (new Controller())->uiRenderCodeTemplate();
check(
    'P2b renderCodeTemplate filters selected ids outside the app',
    trim((string)($scopeRender['data']['code'] ?? '')) === 'Count 1 scope'
);
$legacyScopeStatus = 0;
ui_contract_config(array_merge($scopeTemplateParams, ['template' => $countTemplateItem]), $fixtureAppPath, $scopeTemplateExtra);
try {
    (new Controller())->renderCodeTemplate();
} catch (HttpException $e) {
    $legacyScopeStatus = $e->getStatusCode();
    $legacyScopeMessage = $e->getMessage();
}
check(
    'P2b legacy renderCodeTemplate still rejects out-of-scope selected ids',
    $legacyScopeStatus === 501 && $legacyScopeMessage === 'api not in app scope'
);

$missingAppKeyStatus = 0;
ui_contract_config([
    'token' => $adminToken,
    'template' => 'Sample',
    'selected' => [$menuKey],
], $fixtureAppPath, $scopeTemplateExtra);
try {
    (new Controller())->uiRenderCodeTemplate();
} catch (HttpException $e) {
    $missingAppKeyStatus = $e->getStatusCode();
}
check('P2b renderCodeTemplate requires appKey', $missingAppKeyStatus === 412);

// ---------- R2: legacy management routes require the global token ----------

$r2Cases = [
    'addApiShare' => ['name' => 'evil', 'type' => 'all'],
    'getApiShareList' => ['pageIndex' => 1],
    'getApiShareDetail' => ['key' => 'share-test-1'],
    'deleteApiShare' => ['key' => 'share-test-1'],
    'handleApiShareAction' => ['key' => 'share-test-1', 'index' => 0],
    'cancelAllCache' => [],
    'createAllCache' => [],
    'createGenerator' => [],
];
$r2Statuses = [];
foreach ($r2Cases as $method => $params) {
    ui_contract_config(
        array_merge(['token' => $n1AppToken, 'appKey' => 'default'], $params),
        $fixtureAppPath,
        $n1Extra
    );
    try {
        (new Controller())->{$method}();
        $r2Statuses[$method] = 0;
    } catch (HttpException $e) {
        $r2Statuses[$method] = $e->getStatusCode();
    } catch (\Throwable $e) {
        $r2Statuses[$method] = -1;
    }
}
check(
    'R2 legacy management routes reject app tokens with 401',
    count($r2Statuses) === count($r2Cases)
    && array_unique(array_values($r2Statuses)) === [401]
);

ui_contract_config(['token' => $adminToken, 'pageIndex' => 1], $fixtureAppPath, $n1Extra);
$r2LegacyList = (new Controller())->getApiShareList();
check('R2 legacy management routes accept the global token', ($r2LegacyList['code'] ?? null) === 0);

contract_exit();
