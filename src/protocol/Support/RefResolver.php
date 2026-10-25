<?php

namespace erikwang2013\apidoc\protocol\Support;

use erikwang2013\apidoc\protocol\ProtocolContext;

/**
 * $ref 解析: 支持 #/pointer 与 file#/pointer, 外部文件经上下文校验并计入 imports
 * @package erikwang2013\apidoc\protocol\Support
 */
class RefResolver
{
    /**
     * @var \WeakMap|null 每个上下文中已加载的外部文档(realpath => 文档)
     */
    protected static $documents;

    /**
     * @param string $ref
     * @param array $root 当前文档根
     * @param ProtocolContext $ctx
     * @param callable $load string $content => array|null
     * @return array|null
     */
    public static function resolve(string $ref, array $root, ProtocolContext $ctx, callable $load): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            $ctx->error('empty $ref');
            return null;
        }
        $file = '';
        $fragment = '';
        if ($ref[0] === '#') {
            $fragment = substr($ref, 1);
        } else {
            $hash = strpos($ref, '#');
            if ($hash === false) {
                $file = $ref;
            } else {
                $file = substr($ref, 0, $hash);
                $fragment = substr($ref, $hash + 1);
            }
        }
        if ($file !== '') {
            $realpath = $ctx->resolve($file);
            if ($realpath === null) {
                $ctx->error('unable to resolve $ref: ' . $ref);
                return null;
            }
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
        return is_array($node) ? $node : null;
    }

    /**
     * @return array 当前上下文已加载的外部文档
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
}
