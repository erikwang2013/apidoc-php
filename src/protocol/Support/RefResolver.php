<?php

namespace erikwang2013\apidoc\protocol\Support;

use erikwang2013\apidoc\protocol\ProtocolContext;

/**
 * $ref 解析: 支持 #/pointer 与 file#/pointer, 外部文件经上下文校验并计入 imports
 * @package erikwang2013\apidoc\protocol\Support
 */
class RefResolver
{
    /** 单次展开允许解析的 $ref 数 */
    const MAX_REFS = 64;
    /** 单次展开允许的 $ref 嵌套深度 */
    const MAX_DEPTH = 32;

    /**
     * @var \WeakMap|null 每个上下文中已加载的外部文档(realpath => 原始文档)
     */
    protected static $documents;
    /**
     * @var \WeakMap|null 每个上下文中已展开的外部文档(realpath => 展开后文档)
     */
    protected static $expanded;

    /**
     * 单次解析 $ref
     * @param string $ref
     * @param array $root 当前文档根
     * @param ProtocolContext $ctx
     * @param callable $load string $content => array|null
     * @param array|null $resolvedRoot 输出: 引用目标所在的文档根
     * @param string|null $resolvedFile 输出: 引用目标所在文件(realpath)
     * @return array|null
     */
    public static function resolve(string $ref, array $root, ProtocolContext $ctx, callable $load, ?array &$resolvedRoot = null, ?string &$resolvedFile = null): ?array
    {
        list($file, $fragment) = self::split($ref, $ctx);
        if ($file === null) {
            return null;
        }
        $resolvedFile = $ctx->file();
        if ($file !== '') {
            $realpath = $ctx->resolve($file);
            if ($realpath === null) {
                $ctx->error('unable to resolve $ref: ' . $ref);
                return null;
            }
            $resolvedFile = $realpath;
            // 同一上下文重复引用同一文件不算环, 首次加载后复用文档
            $documents = self::documents($ctx);
            if (array_key_exists($realpath, $documents)) {
                $root = $documents[$realpath];
            } else {
                if (!$ctx->push($realpath)) {
                    return null;
                }
                $content = $ctx->read();
                $document = $content === null ? null : $load($content);
                $ctx->pop();
                if (!is_array($document)) {
                    $ctx->error('unable to parse $ref: ' . $ref);
                    return null;
                }
                self::remember($ctx, $realpath, $document);
                $root = $document;
            }
        }
        $resolvedRoot = $root;
        return self::pointer($root, $fragment, $ctx, $ref);
    }

    /**
     * 递归展开 schema 中的 $ref(含链式/外部文件), 环与深度超限时返回空节点并告警
     * @param array $schema
     * @param array $root 当前文档根
     * @param ProtocolContext $ctx
     * @param callable $load string $content => array|null
     * @param string|null $baseFile 外部节点所在文件, 用于解析其中的相对文件引用
     * @return array
     */
    public static function expand(array $schema, array $root, ProtocolContext $ctx, callable $load, ?string $baseFile = null): array
    {
        $entered = $baseFile !== null && $baseFile !== '' && $baseFile !== $ctx->file();
        if ($entered) {
            $ctx->enterFile($baseFile);
        }
        try {
            $state = ['refs' => 0, 'stack' => []];
            return self::expandNode($schema, $root, $ctx, $load, $state, 0);
        } finally {
            if ($entered) {
                $ctx->leaveFile();
            }
        }
    }

    /**
     * @param array $node
     * @param array $root 当前文档根
     * @param ProtocolContext $ctx
     * @param callable $load
     * @param array $state ['refs'=>int,'stack'=>string[]]
     * @param int $depth
     * @return array
     */
    protected static function expandNode(array $node, array $root, ProtocolContext $ctx, callable $load, array &$state, int $depth): array
    {
        if ($depth > self::MAX_DEPTH || $state['refs'] > self::MAX_REFS) {
            return self::stub($ctx, '$ref depth limit exceeded');
        }
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $siblings = $node;
            unset($siblings['$ref']);
            $siblings = self::expandNode($siblings, $root, $ctx, $load, $state, $depth);
            return self::expandRef($node['$ref'], $siblings, $root, $ctx, $load, $state, $depth);
        }
        foreach (['properties', '$defs', 'definitions'] as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                continue;
            }
            foreach ($node[$key] as $name => $child) {
                if (is_array($child)) {
                    $node[$key][$name] = self::expandNode($child, $root, $ctx, $load, $state, $depth);
                }
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                continue;
            }
            foreach ($node[$key] as $index => $child) {
                if (is_array($child)) {
                    $node[$key][$index] = self::expandNode($child, $root, $ctx, $load, $state, $depth);
                }
            }
        }
        if (isset($node['items']) && is_array($node['items'])) {
            if (self::isList($node['items'])) {
                foreach ($node['items'] as $index => $child) {
                    if (is_array($child)) {
                        $node['items'][$index] = self::expandNode($child, $root, $ctx, $load, $state, $depth);
                    }
                }
            } else {
                $node['items'] = self::expandNode($node['items'], $root, $ctx, $load, $state, $depth);
            }
        }
        return $node;
    }

    /**
     * 展开单个 $ref: 本地 #/... 对当前文档根, file#/... 相对当前文件解析并按文件展开
     * @param string $ref
     * @param array $siblings $ref 同级的其他键
     * @param array $root
     * @param ProtocolContext $ctx
     * @param callable $load
     * @param array $state
     * @param int $depth
     * @return array
     */
    protected static function expandRef(string $ref, array $siblings, array $root, ProtocolContext $ctx, callable $load, array &$state, int $depth): array
    {
        list($file, $fragment) = self::split($ref, $ctx);
        if ($file === null) {
            return array_merge($siblings, self::emptyStub());
        }
        $realpath = '';
        if ($file !== '') {
            $realpath = $ctx->resolve($file);
            if ($realpath === null) {
                $ctx->error('unable to resolve $ref: ' . $ref);
                return array_merge($siblings, self::emptyStub());
            }
            $key = $realpath . '#' . $fragment;
        } else {
            $key = $ctx->file() . '#' . $fragment;
        }
        if (in_array($key, $state['stack'], true)) {
            return array_merge($siblings, self::stub($ctx, '$ref cycle detected: ' . $ref));
        }
        if ($state['refs'] >= self::MAX_REFS || $depth >= self::MAX_DEPTH) {
            return array_merge($siblings, self::stub($ctx, '$ref depth limit exceeded: ' . $ref));
        }
        $state['refs']++;
        $state['stack'][] = $key;
        if ($realpath !== '') {
            $documents = self::expanded($ctx);
            if (array_key_exists($realpath, $documents)) {
                $documentRoot = $documents[$realpath];
            } else {
                if (!$ctx->push($realpath)) {
                    array_pop($state['stack']);
                    return array_merge($siblings, self::emptyStub());
                }
                $content = $ctx->read();
                $document = $content === null ? null : $load($content);
                $documentRoot = [];
                if (is_array($document)) {
                    // 整份外部文档在“当前文件”位置上展开, 其内部相对引用才能按自身目录解析
                    $documentRoot = self::expandNode($document, $document, $ctx, $load, $state, $depth + 1);
                }
                $ctx->pop();
                if (!is_array($document)) {
                    array_pop($state['stack']);
                    $ctx->error('unable to parse $ref: ' . $ref);
                    return array_merge($siblings, self::emptyStub());
                }
                self::rememberExpanded($ctx, $realpath, $documentRoot);
            }
        } else {
            $documentRoot = $root;
        }
        $target = self::pointer($documentRoot, $fragment, $ctx, $ref);
        if ($target === null) {
            array_pop($state['stack']);
            return array_merge($siblings, self::emptyStub());
        }
        $expanded = self::expandNode($target, $documentRoot, $ctx, $load, $state, $depth + 1);
        array_pop($state['stack']);
        return array_merge($siblings, $expanded);
    }

    /**
     * 拆分 ref 为 [文件, 片段]; 空 ref 记录错误并返回 [null, '']
     * @return array [string|null, string]
     */
    protected static function split(string $ref, ProtocolContext $ctx): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            $ctx->error('empty $ref');
            return [null, ''];
        }
        if ($ref[0] === '#') {
            return ['', substr($ref, 1)];
        }
        $hash = strpos($ref, '#');
        if ($hash === false) {
            return [$ref, ''];
        }
        return [substr($ref, 0, $hash), substr($ref, $hash + 1)];
    }

    /**
     * 按 JSON Pointer 片段取节点, 缺失/非对象时记录错误
     * @param array $root
     * @param string $fragment
     * @param ProtocolContext $ctx
     * @param string $ref
     * @return array|null
     */
    protected static function pointer(array $root, string $fragment, ProtocolContext $ctx, string $ref): ?array
    {
        if ($fragment === '' || $fragment === '/') {
            return $root;
        }
        $node = $root;
        foreach (explode('/', ltrim($fragment, '/')) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                $ctx->error('$ref not found: ' . $ref);
                return null;
            }
            $node = $node[$segment];
        }
        if (!is_array($node)) {
            $ctx->error('$ref target is not an object: ' . $ref);
            return null;
        }
        return $node;
    }

    protected static function stub(ProtocolContext $ctx, string $message): array
    {
        $ctx->warning($message);
        return self::emptyStub();
    }

    protected static function emptyStub(): array
    {
        return ['type' => 'object', 'properties' => [], 'children' => []];
    }

    protected static function isList(array $data): bool
    {
        return $data === [] || array_keys($data) === range(0, count($data) - 1);
    }

    /**
     * @return array 当前上下文已加载的原始外部文档
     */
    protected static function documents(ProtocolContext $ctx): array
    {
        if (self::$documents === null) {
            self::$documents = new \WeakMap();
        }
        return isset(self::$documents[$ctx]) ? self::$documents[$ctx] : [];
    }

    protected static function remember(ProtocolContext $ctx, string $realpath, array $document): void
    {
        if (self::$documents === null) {
            self::$documents = new \WeakMap();
        }
        $documents = self::documents($ctx);
        $documents[$realpath] = $document;
        self::$documents[$ctx] = $documents;
    }

    /**
     * @return array 当前上下文已展开的外部文档
     */
    protected static function expanded(ProtocolContext $ctx): array
    {
        if (self::$expanded === null) {
            self::$expanded = new \WeakMap();
        }
        return isset(self::$expanded[$ctx]) ? self::$expanded[$ctx] : [];
    }

    protected static function rememberExpanded(ProtocolContext $ctx, string $realpath, array $document): void
    {
        if (self::$expanded === null) {
            self::$expanded = new \WeakMap();
        }
        $expanded = self::expanded($ctx);
        $expanded[$realpath] = $document;
        self::$expanded[$ctx] = $expanded;
    }
}
