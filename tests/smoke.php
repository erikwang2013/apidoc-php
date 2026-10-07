<?php
declare(strict_types=1);

/**
 * 冒烟测试（无框架、无依赖）: php tests/smoke.php
 * 覆盖: treeToList 深层 parent、缓存键清洗/目录对齐、原子写回环、分享列表解析、Auth 基础校验
 */

use erikwang2013\apidoc\Auth;
use erikwang2013\apidoc\generator\ParseTemplate;
use erikwang2013\apidoc\utils\ApiShare;
use erikwang2013\apidoc\utils\Cache;
use erikwang2013\apidoc\utils\ConfigProvider;
use erikwang2013\apidoc\utils\DirAndFile;
use erikwang2013\apidoc\utils\Helper;

error_reporting(E_ALL);
$root = dirname(__DIR__);
define('APIDOC_ROOT_PATH', $root . '/');
// 与框架约定一致: 存储路径以 / 结尾(如 ThinkPHP runtimePath)
define('APIDOC_STORAGE_PATH', sys_get_temp_dir() . '/apidoc-smoke-' . getmypid() . '/');
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

// ---------- 1. treeToList: 每层 parent = 直接父级 ----------
$tree = [['id' => 1, 'children' => [['id' => 2, 'children' => [['id' => 3]]]]]];
$map = [];
foreach (Helper::treeToList($tree) as $n) {
    if (isset($n['id'])) {
        $map[$n['id']] = $n['parent'] ?? null;
    }
}
check('treeToList: 3层树 id=2 的 parent=1', ($map[2] ?? null) === 1);
check('treeToList: 3层树 id=3 的 parent=2(修复前会错成 1)', ($map[3] ?? null) === 2);

$tree2 = [['key' => 'a', 'children' => [['key' => 'b', 'children' => [['key' => 'c']]]]]];
$map2 = [];
foreach (Helper::treeToList($tree2, 'children', 'key') as $n) {
    if (isset($n['key'])) {
        $map2[$n['key']] = $n['parent'] ?? null;
    }
}
check('treeToList(自定义key): b.parent=a', ($map2['b'] ?? null) === 'a');
check('treeToList(自定义key): c.parent=b(修复前递归丢参会错)', ($map2['c'] ?? null) === 'b');

// ---------- 2. 缓存键: 保留子目录 + 防穿越 ----------
$cache = new Cache();
$k1 = $cache->getCacheKey('share/apiShare_abc');
check('缓存键保留子目录 share/', strpos($k1, '/share/apiShare_abc_') !== false);
check('缓存键默认目录为 apidoc/', strpos($k1, APIDOC_STORAGE_PATH . '/apidoc/') === 0);
$k2 = $cache->getCacheKey('../../etc/passwd');
check('缓存键防路径穿越(无 .. 段)', strpos($k2, '..') === false);

// ---------- 3. 缓存读写回环 + 原子写 + 落盘位置 ----------
$cache->set('share/apiShare_smoke', ['name' => 't', 'type' => 'api']);
$read = $cache->get('share/apiShare_smoke');
check('缓存 set/get 回环', ($read['name'] ?? null) === 't');
check('分享缓存落在 share/ 子目录', count((array)glob(APIDOC_STORAGE_PATH . '/apidoc/share/apiShare_smoke_*.php')) === 1);
check('写入无 .tmp 残留', count((array)glob(APIDOC_STORAGE_PATH . '/apidoc/share/*.tmp')) === 0);
$cache->delete('share/apiShare_smoke');
check('缓存 delete 后不可见', $cache->get('share/apiShare_smoke') === null || $cache->get('share/apiShare_smoke') === false);

// ---------- 4. 缓存目录与 cache.folder 对齐 ----------
ConfigProvider::set(['cache' => ['folder' => 'smokefolder']]);
$cache2 = new Cache();
check('缓存目录跟随 cache.folder(修复前写死 apidoc)', strpos($cache2->getCacheKey('x'), '/smokefolder/') !== false);
ConfigProvider::set(['cache' => ['folder' => 'apidoc']]);

// ---------- 5. 分享列表解析(修复: 键拍平导致列表恒空) ----------
$apiShare = new ApiShare();
$apiShare->addApiShare(['name' => '冒烟分享', 'type' => 'api', 'apiKeys' => ['k1']]);
$list = $apiShare->getSharePageList(['cache' => ['folder' => 'apidoc']], 1, 5);
check('分享列表可见(修复前恒为空)', ($list['total'] ?? 0) >= 1);

// ---------- 6. Auth: 配置校验 + token 回环 ----------
$throwOk = false;
try {
    new Auth(['auth' => ['enable' => true, 'secret_key' => 's']]);
} catch (\Throwable $e) {
    $throwOk = true;
}
check('auth.enable=true 且无 password 时抛异常(修复前 md5(null) TypeError)', $throwOk);

$auth = new Auth(['auth' => ['enable' => true, 'password' => 'pw', 'secret_key' => 's', 'expire' => 3600]]);
$token = $auth->createToken('pw', 3600);
check('token 回环校验', $auth->checkToken($token, 'pw') === true);

// ---------- 7. 路径校验(防穿越) ----------
$realRoot = realpath(APIDOC_ROOT_PATH);
check('resolvePath: 合法相对路径放行', DirAndFile::resolvePathWithinRoot('src/Controller.php', APIDOC_ROOT_PATH) === $realRoot . '/src/Controller.php');
check('resolvePath: 开头斜杠的相对路径放行', DirAndFile::resolvePathWithinRoot('/src/Controller.php', APIDOC_ROOT_PATH) === $realRoot . '/src/Controller.php');
check('resolvePath: ../../ 穿越被拒', DirAndFile::resolvePathWithinRoot('../../etc/passwd', APIDOC_ROOT_PATH) === false);
check('resolvePath: NUL 字节被拒', DirAndFile::resolvePathWithinRoot("a\0b", APIDOC_ROOT_PATH) === false);
check('resolvePath: 非字符串被拒', DirAndFile::resolvePathWithinRoot(['x'], APIDOC_ROOT_PATH) === false);

// ---------- 8. 模板条件求值器(无 eval) 端到端 ----------
$pwn = sys_get_temp_dir() . '/apidoc-smoke-pwned';
@unlink($pwn);
$tplFile = APIDOC_STORAGE_PATH . 'cond.tpl';
file_put_contents($tplFile, implode("\n", [
    'T:{if 1 == 1}YES{/if}',
    'F:{if 1 == 2}NO{/if}',
    'A:{if 1 == 1 && 2 >= 2}AND{/if}',
    'O:{if 1 == 2 || 3 == 3}OR{/if}',
    'X:{if system(\'touch ' . $pwn . '\')}PWNED{/if}',
    'R:{if a =~ b}REGEX{/if}',
]));
$out = (new ParseTemplate())->compile($tplFile, []);
check('if 条件: 真值成立', strpos($out, 'T:YES') !== false);
check('if 条件: 假值不输出', strpos($out, 'F:NO') === false);
check('if 条件: && 复合成立', strpos($out, 'A:AND') !== false);
check('if 条件: || 复合成立', strpos($out, 'O:OR') !== false);
check('if 条件: 恶意载荷不执行', strpos($out, 'PWNED') === false && !file_exists($pwn));
check('if 条件: 无法解析表达式按假', strpos($out, 'REGEX') === false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
