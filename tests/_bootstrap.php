<?php
declare(strict_types=1);

/**
 * Shared bootstrap for dependency-free tests.
 * Mirrors tests/smoke.php so UIC-1 contract tests can load src/ without a framework.
 */

error_reporting(E_ALL);
$root = dirname(__DIR__);
define('APIDOC_ROOT_PATH', $root . '/');
define('APIDOC_STORAGE_PATH', sys_get_temp_dir() . '/apidoc-contract-' . getmypid() . '/');
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

$GLOBALS['uic_pass'] = 0;
$GLOBALS['uic_fail'] = 0;

function check(string $name, bool $cond): void
{
    if ($cond) {
        $GLOBALS['uic_pass']++;
        echo "PASS  {$name}\n";
    } else {
        $GLOBALS['uic_fail']++;
        echo "FAIL  {$name}\n";
    }
}

function contract_fixture(string $name): array
{
    $path = __DIR__ . '/../contract/uic-1/fixtures/' . $name . '.json';
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function contract_exit(): void
{
    if ($GLOBALS['uic_fail'] > 0) {
        exit(1);
    }
    echo "OK\n";
}
