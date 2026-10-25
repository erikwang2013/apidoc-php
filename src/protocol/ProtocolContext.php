<?php

namespace erikwang2013\apidoc\protocol;

use erikwang2013\apidoc\utils\DirAndFile;

/**
 * 协议定义解析上下文: 当前文件、选项、资源上限、导入栈与诊断信息
 * @package erikwang2013\apidoc\protocol
 */
class ProtocolContext
{
    /**
     * @var string 当前文件(realpath)
     */
    protected $file;
    /**
     * @var array 解析选项(strict/message/direction/sample/...)
     */
    protected $options;
    /**
     * @var array 资源上限
     */
    protected $limits;
    /**
     * @var array 允许的根目录
     */
    protected $roots;
    /**
     * @var array
     */
    protected $errors = [];
    /**
     * @var array
     */
    protected $warnings = [];
    /**
     * @var array 已导入文件(realpath)
     */
    protected $imports = [];
    /**
     * @var array 已访问文件(realpath => true)
     */
    protected $visited = [];
    /**
     * @var int 当前导入深度
     */
    protected $depth = 0;
    /**
     * @var array 文件/深度恢复栈
     */
    protected $stack = [];

    public function __construct(string $file, array $options, array $limits, array $roots)
    {
        $this->file = $file;
        $this->options = $options;
        $this->limits = $limits + [
            'max_file_bytes' => 2097152,
            'max_lines' => 50000,
            'max_imports' => 64,
            'max_depth' => 32,
        ];
        if (!$roots && defined('APIDOC_ROOT_PATH')) {
            $roots = [APIDOC_ROOT_PATH];
        }
        $this->roots = $roots;
        $this->visited[$file] = true;
    }

    /**
     * @return string 当前文件
     */
    public function file(): string
    {
        return $this->file;
    }

    /**
     * @param mixed $key
     * @param mixed $default
     * @return mixed
     */
    public function option($key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * 读取当前文件(受字节数与行数上限约束)
     * @return string|null 超限或读取失败时 null, 并记录 error
     */
    public function read(): ?string
    {
        $size = @filesize($this->file);
        if ($size === false) {
            $this->error('unable to stat file');
            return null;
        }
        if ($size > $this->limits['max_file_bytes']) {
            $this->error('file exceeds max_file_bytes', 0, 0);
            return null;
        }
        $content = @file_get_contents($this->file);
        if ($content === false) {
            $this->error('unable to read file');
            return null;
        }
        if (substr_count($content, "\n") + 1 > $this->limits['max_lines']) {
            $this->error('file exceeds max_lines');
            return null;
        }
        return $content;
    }

    /**
     * 解析导入路径(相对于当前文件, 必须位于允许的根目录内)
     * @param string $importPath
     * @return string|null realpath, 非法/不存在时 null
     */
    public function resolve(string $importPath): ?string
    {
        if ($importPath === '' || strpos($importPath, "\0") !== false) {
            return null;
        }
        if (strpos($importPath, '/') === 0 || preg_match('#^[A-Za-z]:[\\\\/]#', $importPath)) {
            return null;
        }
        $candidate = str_replace('\\', '/', dirname($this->file) . '/' . $importPath);
        foreach ($this->roots as $root) {
            $rootPath = realpath($root);
            if ($rootPath === false) {
                continue;
            }
            $rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
            if (strpos($candidate, $rootPath . '/') !== 0) {
                continue;
            }
            $file = DirAndFile::resolvePathWithinRoot(substr($candidate, strlen($rootPath) + 1), $root);
            if ($file !== false && is_file($file)) {
                $real = realpath($file);
                // 软链可能指向根目录外, realpath 后再校验一次
                if ($real !== false && ProtocolPaths::withinRoots($real, $this->roots)) {
                    return $real;
                }
            }
        }
        return null;
    }

    /**
     * 进入导入文件
     * @param string $realpath 导入文件的 realpath
     * @return bool 被环/上限拒绝时 false
     */
    public function push(string $realpath): bool
    {
        if (isset($this->visited[$realpath])) {
            $this->warning('import cycle skipped');
            return false;
        }
        if (count($this->imports) >= $this->limits['max_imports']) {
            $this->error('max_imports exceeded');
            return false;
        }
        if ($this->depth >= $this->limits['max_depth']) {
            $this->error('max_depth exceeded');
            return false;
        }
        $this->visited[$realpath] = true;
        $this->imports[] = $realpath;
        $this->stack[] = ['file' => $this->file, 'depth' => $this->depth];
        $this->file = $realpath;
        $this->depth++;
        return true;
    }

    /**
     * 返回上一个文件
     */
    public function pop(): void
    {
        $previous = array_pop($this->stack);
        if ($previous !== null) {
            $this->file = $previous['file'];
            $this->depth = $previous['depth'];
        }
    }

    /**
     * 临时进入文件上下文(不参与导入环检测), 用于展开外部节点内的相对引用
     * @param string $file 已校验的 realpath
     */
    public function enterFile(string $file): void
    {
        $this->stack[] = ['file' => $this->file, 'depth' => $this->depth];
        $this->file = $file;
    }

    /**
     * 恢复 enterFile 前的文件上下文
     */
    public function leaveFile(): void
    {
        $previous = array_pop($this->stack);
        if ($previous !== null) {
            $this->file = $previous['file'];
            $this->depth = $previous['depth'];
        }
    }

    public function error(string $message, int $line = 0, int $column = 0): void
    {
        $this->errors[] = $this->entry('error', $message, $line, $column);
    }

    public function warning(string $message, int $line = 0, int $column = 0): void
    {
        $this->warnings[] = $this->entry('warning', $message, $line, $column);
    }

    /**
     * 不支持的语法: strict 模式记为 error, 否则 warning
     */
    public function unsupported(string $message, int $line = 0, int $column = 0): void
    {
        if (!empty($this->options['strict'])) {
            $this->error($message, $line, $column);
            return;
        }
        $this->warning($message, $line, $column);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array 已导入文件 realpath 列表
     */
    public function imports(): array
    {
        return $this->imports;
    }

    protected function entry(string $severity, string $message, int $line, int $column): array
    {
        return [
            'path' => '',
            'line' => $line,
            'column' => $column,
            'severity' => $severity,
            'message' => $message,
        ];
    }
}
