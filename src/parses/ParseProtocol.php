<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\parses;

use erikwang2013\apidoc\protocol\ProtocolCatalog;
use erikwang2013\apidoc\protocol\debug\DebugRegistry;
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

        $protocolRaw = $this->mergeAnnotation($classAnnotations, $methodAnnotations, 'protocol');
        $channelRaw = $this->mergeAnnotation($classAnnotations, $methodAnnotations, 'channel', ['name' => 'address']);
        $debugRaw = $this->mergeAnnotation($classAnnotations, $methodAnnotations, 'debug', ['name' => 'driver']);
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
        if ($driver === '' || $driver === 'auto') {
            $driver = $protocol['defaultDriver'];
        }
        $meta = !empty($debugAnn['meta']) && is_array($debugAnn['meta']) ? $debugAnn['meta'] : [];
        $relayEnabled = ($this->config['protocols']['debug']['relay_enable'] ?? false) === true;

        $catalogMode = (string)$protocol['defaultMode'];
        $actionFollowingMode = '';
        if (in_array($driver, ['mqtt', 'stomp'], true)) {
            // 发布/发送走单次中继,订阅/接收走流式会话
            if (in_array(strtolower($channel['action']), ['publish', 'send'], true)) {
                $actionFollowingMode = 'relay-unary';
            } elseif (in_array(strtolower($channel['action']), ['subscribe', 'receive'], true)) {
                $actionFollowingMode = 'relay-stream';
            }
        }
        if ($actionFollowingMode !== '') {
            $mode = $actionFollowingMode;
        } elseif (in_array($driver, ['http', 'websocket', 'sse'], true)) {
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

        $annotationFields = [];
        foreach (['param' => 'body', 'header' => 'header', 'query' => 'query'] as $key => $in) {
            foreach ($this->normalizeItems($methodAnnotations[$key] ?? null) as $item) {
                $item['in'] = $in;
                $annotationFields[] = $item;
            }
        }
        $fields = $this->mergeFields(array_merge(
            DebugRegistry::driverFields($driver),
            $this->normalizeItems($debugAnn['fields'] ?? null),
            $annotationFields,
            [['name' => 'target', 'in' => 'target', 'type' => 'string', 'require' => true, 'default' => $target]]
        ));

        $driverExists = DebugRegistry::driver($driver) !== null;
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

        $result = ['channel' => $channel, 'debug' => $debug];
        if ($name !== '') {
            // 仅声明 Channel/Debug 时无协议名,不输出空协议对象
            $result = ['protocol' => $protocol] + $result;
        }
        return $result;
    }

    /**
     * 注解按字段合并:类级为基,方法级非空字段覆盖,重复注解取第一个
     * @param array $aliases 位置参数别名映射,如 ['name' => 'address']
     * @return array|null null 表示未声明
     */
    protected function mergeAnnotation(array $classAnnotations, array $methodAnnotations, string $key, array $aliases = [])
    {
        $hasClass = array_key_exists($key, $classAnnotations);
        $hasMethod = array_key_exists($key, $methodAnnotations);
        if (!$hasClass && !$hasMethod) {
            return null;
        }
        $classItems = $this->normalizeItems($hasClass ? $classAnnotations[$key] : null);
        $methodItems = $this->normalizeItems($hasMethod ? $methodAnnotations[$key] : null);
        $merged = $classItems[0] ?? [];
        $method = $methodItems[0] ?? [];
        // 位置参数(x)解析为 name,方法级声明应覆盖类级同义字段
        foreach ($aliases as $alias => $field) {
            if (!empty($method[$alias]) && empty($method[$field])) {
                $method[$field] = $method[$alias];
            }
        }
        foreach ($method as $field => $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                $merged[$field] = $value;
            }
        }
        return $merged;
    }

    /**
     * 按 in|name 去重,后出现的项覆盖先出现的项
     * @param array $items
     * @return array
     */
    protected function mergeFields(array $items): array
    {
        $merged = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['name'])) {
                continue;
            }
            $in = isset($item['in']) ? (string)$item['in'] : '';
            $merged[$in . '|' . $item['name']] = $item;
        }
        return array_values($merged);
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
