<?php
declare(strict_types=1);

/**
 * UIC-1 contract fixture test (no framework, no dependencies):
 * php tests/ui_contract.php
 */

require __DIR__ . '/_bootstrap.php';

$menus = contract_fixture('menus');
check('menus fixture loads', is_array($menus) && isset($menus[0]['response']['code']));
check('menus fixture envelope', ($menus[0]['response']['code'] ?? null) === 0);

$caps = contract_fixture('capabilities');
check('capabilities fixture loads', is_array($caps) && isset($caps[0]['response']['data']));
check(
    'capabilities contract is 1.0',
    ($caps[0]['response']['data']['contract'] ?? '') === '1.0'
);

contract_exit();
