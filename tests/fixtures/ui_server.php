<?php
declare(strict_types=1);

/**
 * Router for `php -S 127.0.0.1:18081 tests/fixtures/ui_server.php`.
 * Serves the UIC-1 read/auth routes against the fixture app in ./default.
 */

use erikwang2013\apidoc\Controller;
use erikwang2013\apidoc\exception\HttpException;
use erikwang2013\apidoc\utils\ConfigProvider;

$root = dirname(__DIR__, 2);
// App paths resolve against this: app path "default" => tests/fixtures/default.
define('APIDOC_ROOT_PATH', __DIR__ . '/');
// Cache is disabled below; only defined because ConfigProvider expects the constant.
define('APIDOC_STORAGE_PATH', sys_get_temp_dir() . '/apidoc-ui-e2e-' . getmypid() . '/');

spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'erikwang2013\\apidoc\\';
    if (strpos($class, $prefix) === 0) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
spl_autoload_register(function (string $class): void {
    $prefix = 'Tests\\Fixtures\\Ui\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__ . '/default/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

ConfigProvider::set([
    'title' => 'UIC-1 E2E',
    'definitions' => '',
    'apps' => [
        ['key' => 'default', 'title' => 'Default', 'path' => 'default'],
    ],
    'auth' => ['enable' => true, 'password' => 'pw', 'secret_key' => 'uic1-e2e-secret', 'expire' => 3600],
    'cache' => ['enable' => false],
]);

$routes = [
    '/apidoc/ui/v1/capabilities' => ['GET', 'uiCapabilities'],
    '/apidoc/ui/v1/config' => ['GET', 'uiConfig'],
    '/apidoc/ui/v1/verifyAuth' => ['POST', 'uiVerifyAuth'],
    '/apidoc/ui/v1/menus' => ['POST', 'uiMenus'],
    '/apidoc/ui/v1/detail' => ['POST', 'uiDetail'],
    '/apidoc/ui/v1/docMenus' => ['POST', 'uiDocMenus'],
    '/apidoc/ui/v1/docDetail' => ['POST', 'uiDocDetail'],
    '/apidoc/ui/v1/addApiShare' => ['POST', 'uiAddApiShare'],
    '/apidoc/ui/v1/getApiShareList' => ['POST', 'uiGetApiShareList'],
    '/apidoc/ui/v1/getApiShareDetail' => ['POST', 'uiGetApiShareDetail'],
    '/apidoc/ui/v1/deleteApiShare' => ['POST', 'uiDeleteApiShare'],
    '/apidoc/ui/v1/handleApiShareAction' => ['POST', 'uiHandleApiShareAction'],
    '/apidoc/ui/v1/cache/clear' => ['POST', 'uiCacheClear'],
    '/apidoc/ui/v1/cache/warm' => ['POST', 'uiCacheWarm'],
    '/apidoc/ui/v1/generator' => ['POST', 'uiGenerator'],
    '/apidoc/ui/v1/renderCodeTemplate' => ['POST', 'uiRenderCodeTemplate'],
    '/apidoc/ui/v1/exportAllData' => ['POST', 'uiExportAllData'],
];

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

header('Content-Type: application/json');
if (!isset($routes[$path])) {
    http_response_code(404);
    echo json_encode(['code' => 404, 'message' => 'not found', 'data' => '']);
    return;
}
[$allowedMethod, $action] = $routes[$path];
if ($method !== $allowedMethod) {
    http_response_code(405);
    echo json_encode(['code' => 405, 'message' => 'method not allowed', 'data' => '']);
    return;
}

try {
    $result = (new Controller())->{$action}();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (HttpException $e) {
    http_response_code($e->getStatusCode());
    echo json_encode(
        ['code' => $e->getCode(), 'message' => $e->getMessage(), 'data' => ''],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(
        ['code' => 5000, 'message' => $e->getMessage(), 'data' => ''],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}
