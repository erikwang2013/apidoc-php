<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\parses;

use erikwang2013\apidoc\protocol\ProtocolCatalog;
use ReflectionClass;
use ReflectionMethod;

class ParseProtocol
{
    protected $config;

    protected $parseAnnotation;

    public function __construct($config)
    {
        $this->config = $config;
        $this->parseAnnotation = new ParseAnnotation($config);
    }

    /**
     * 协议/通道/调试元数据
     * @param ReflectionClass $refClass
     * @param ReflectionMethod $refMethod
     * @return array 无协议注解时返回 []
     */
    public function metadata(ReflectionClass $refClass, ReflectionMethod $refMethod): array
    {
        $classAnnotations = $this->parseAnnotation->getClassAnnotation($refClass);
        $methodAnnotations = $this->parseAnnotation->getMethodAnnotation($refMethod);

        $protocolRaw = $this->pick($classAnnotations, $methodAnnotations, 'protocol');
        $channelRaw = $this->pick($classAnnotations, $methodAnnotations, 'channel');
        $debugRaw = $this->pick($classAnnotations, $methodAnnotations, 'debug');
        if ($protocolRaw === null && $channelRaw === null && $debugRaw === null) {
            return [];
        }
        $protocolAnn = $protocolRaw ?: [];
        $channelAnn = $channelRaw ?: [];
        $debugAnn = $debugRaw ?: [];

        $name = isset($protocolAnn['name']) ? trim((string)$protocolAnn['name']) : '';
        $protocol = ProtocolCatalog::get($name);
        $protocol['version'] = isset($protocolAnn['version']) ? $protocolAnn['version'] : '';
        if (!empty($protocolAnn['transport'])) {
            $protocol['transport'] = $protocolAnn['transport'];
        }
        $protocol['mode'] = !empty($protocolAnn['mode']) ? $protocolAnn['mode'] : $protocol['defaultMode'];

        $address = isset($channelAnn['address']) ? (string)$channelAnn['address'] : '';
        if ($address === '') {
            $address = isset($channelAnn['name']) ? (string)$channelAnn['name'] : '';
        }
        $channel = [
            'address' => $address,
            'action' => isset($channelAnn['action']) ? $channelAnn['action'] : '',
            'meta' => !empty($channelAnn['meta']) && is_array($channelAnn['meta']) ? $channelAnn['meta'] : [],
        ];

        $driver = isset($debugAnn['driver']) ? (string)$debugAnn['driver'] : '';
        if ($driver === '') {
            $driver = isset($debugAnn['name']) ? (string)$debugAnn['name'] : '';
        }
        if ($driver === 'auto') {
            $driver = $protocol['defaultDriver'];
        }
        $meta = !empty($debugAnn['meta']) && is_array($debugAnn['meta']) ? $debugAnn['meta'] : [];
        $relayEnabled = ($this->config['protocols']['debug']['relay_enable'] ?? false) === true;

        $catalogMode = (string)$protocol['defaultMode'];
        if (in_array($driver, ['http', 'websocket', 'sse'], true)) {
            $mode = $catalogMode !== '' ? $catalogMode : 'browser-' . $driver;
        } else {
            $mode = $catalogMode === 'relay-stream' ? 'relay-stream' : 'relay-unary';
        }

        $target = isset($debugAnn['target']) ? (string)$debugAnn['target'] : '';
        if ($target === '') {
            $target = $channel['address'];
        }
        if ($target === '') {
            $target = $this->methodUrl($refClass, $refMethod, $methodAnnotations);
        }
        $defaults = [
            'target' => $target,
            'encoding' => !empty($debugAnn['encoding']) ? $debugAnn['encoding'] : 'text',
            'timeout' => !empty($debugAnn['timeout']) ? $debugAnn['timeout'] : 5,
        ];

        $fields = [];
        foreach (['param' => 'body', 'header' => 'header', 'query' => 'query'] as $key => $in) {
            foreach ($this->normalizeItems($methodAnnotations[$key] ?? null) as $item) {
                $item['in'] = $in;
                $fields[] = $item;
            }
        }
        $fields[] = ['name' => 'target', 'in' => 'target', 'type' => 'string', 'require' => true, 'default' => $target];

        $driverExists = $driver !== '' && $protocol['debuggable'];
        if (in_array($mode, ['browser-http', 'browser-websocket', 'browser-sse'], true)) {
            $debuggable = $target !== '';
            $debugReason = $debuggable ? '' : '未配置调试目标';
        } elseif ($target === '') {
            $debuggable = false;
            $debugReason = '未配置调试目标';
        } elseif (!$relayEnabled) {
            $debuggable = false;
            $debugReason = '调试中继未开启（protocols.debug.relay_enable=false）';
        } elseif (!$driverExists) {
            $debuggable = false;
            $debugReason = $protocol['debugReason'] !== '' ? $protocol['debugReason'] : '未支持的协议驱动（P2）';
        } else {
            $debuggable = true;
            $debugReason = '';
        }

        $debug = [
            'mode' => $mode,
            'driver' => $driver,
            'relayEnabled' => $relayEnabled,
            'debuggable' => $debuggable,
            'debugReason' => $debugReason,
            'fields' => $fields,
            'defaults' => $defaults,
            'responseEncoding' => isset($meta['responseEncoding']) ? $meta['responseEncoding'] : 'text',
            'hints' => isset($meta['hints']) ? $meta['hints'] : '',
        ];

        return ['protocol' => $protocol, 'channel' => $channel, 'debug' => $debug];
    }

    /**
     * 方法注解优先于类注解;重复注解取第一个
     * @return array|null null 表示未声明
     */
    protected function pick(array $classAnnotations, array $methodAnnotations, string $key)
    {
        if (array_key_exists($key, $methodAnnotations)) {
            $raw = $methodAnnotations[$key];
        } elseif (array_key_exists($key, $classAnnotations)) {
            $raw = $classAnnotations[$key];
        } else {
            return null;
        }
        $items = $this->normalizeItems($raw);
        return $items[0] ?? [];
    }

    /**
     * 归一字符串/字符串列表/关联数组为注解项列表
     * @param mixed $raw
     * @return array
     */
    protected function normalizeItems($raw): array
    {
        if (empty($raw)) {
            return [];
        }
        if (is_string($raw)) {
            return [['name' => $raw]];
        }
        if (!is_array($raw)) {
            return [];
        }
        if (!is_int(array_key_first($raw))) {
            return [$raw];
        }
        $items = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $items[] = ['name' => $item];
            } elseif (is_array($item)) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * 方法 url:显式 Url 注解优先,否则沿用自动生成规则
     * @return string
     */
    protected function methodUrl(ReflectionClass $refClass, ReflectionMethod $refMethod, array $methodAnnotations): string
    {
        $url = isset($methodAnnotations['url']) ? $methodAnnotations['url'] : '';
        if (is_array($url)) {
            $url = reset($url);
        }
        if (is_string($url) && $url !== '') {
            return $url;
        }
        return ParseApiDetail::autoCreateUrl($refClass->name, $refMethod->name, $this->config);
    }
}
