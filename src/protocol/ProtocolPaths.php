<?php

namespace erikwang2013\apidoc\protocol;

use erikwang2013\apidoc\utils\DirAndFile;

/**
 * 协议定义文件路径解析: 只允许相对路径且必须位于 allowed_roots 内
 * @package erikwang2013\apidoc\protocol
 */
class ProtocolPaths
{
    /**
     * @var array 允许的根目录, 空时回退到 APIDOC_ROOT_PATH
     */
    protected $roots;

    /**
     * @param array $protocolConfig protocols 配置块
     */
    public function __construct(array $protocolConfig)
    {
        $roots = $protocolConfig['allowed_roots'] ?? [];
        if (!$roots && defined('APIDOC_ROOT_PATH')) {
            $roots = [APIDOC_ROOT_PATH];
        }
        $this->roots = is_array($roots) ? $roots : [];
    }

    /**
     * 解析定义文件相对路径
     * @param string $relativePath 声明路径(相对)
     * @return array|null ['realpath'=>string,'declared'=>string], 非法/不存在时 null
     */
    public function resolve(string $relativePath): ?array
    {
        if ($relativePath === '' || strpos($relativePath, "\0") !== false) {
            return null;
        }
        if (strpos($relativePath, '/') === 0 || preg_match('#^[A-Za-z]:[\\\\/]#', $relativePath)) {
            return null;
        }
        foreach (explode('/', str_replace('\\', '/', $relativePath)) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }
        foreach ($this->roots as $root) {
            $file = DirAndFile::resolvePathWithinRoot($relativePath, $root);
            if ($file !== false && is_file($file)) {
                return [
                    'realpath' => realpath($file),
                    'declared' => ltrim(str_replace('\\', '/', $relativePath), '/'),
                ];
            }
        }
        return null;
    }
}
