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
use erikwang2013\apidoc\utils\ConfigProvider;

require __DIR__ . '/_bootstrap.php';

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

check(
    'UIC controller methods exist',
    method_exists(Controller::class, 'uiCapabilities')
    && method_exists(Controller::class, 'uiVerifyAuth')
    && method_exists(Controller::class, 'uiDebugSend')
);
$routes = file_get_contents(__DIR__ . '/../src/providers/BaseService.php');
check('UIC debug route declared', strpos((string)$routes, "'ui/v1/debugSend'") !== false);
check('UIC verify route declared', strpos((string)$routes, "'ui/v1/verifyAuth'") !== false);

contract_exit();
