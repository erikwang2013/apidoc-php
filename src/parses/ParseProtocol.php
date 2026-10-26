<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\parses;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\ProtocolCatalog;
use erikwang2013\apidoc\protocol\ProtocolContext;
use erikwang2013\apidoc\protocol\ProtocolParseException;
use erikwang2013\apidoc\protocol\ProtocolPaths;
use erikwang2013\apidoc\protocol\ProtocolRegistry;
use erikwang2013\apidoc\protocol\debug\DebugRegistry;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Driver\GrpcDriver;
use erikwang2013\apidoc\protocol\debug\Driver\Http2Driver;
use erikwang2013\apidoc\protocol\debug\Driver\Http3Driver;
use erikwang2013\apidoc\protocol\debug\Driver\XmppDriver;
use erikwang2013\apidoc\utils\Cache;
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
        } elseif (str_starts_with($catalogMode, 'browser-')
            && $driver === (string)($protocol['defaultDriver'] ?? '')) {
            // 默认浏览器协议(HLS 等)走浏览器面板;显式 relay 驱动仍按中继处理。
            $mode = $catalogMode;
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
        // Debug.meta 作为驱动字段的默认值;注解/Debug.fields 显式定义的字段合并时整体覆盖
        $driverFields = DebugRegistry::driverFields($driver);
        foreach ($driverFields as &$driverField) {
            $fieldName = is_array($driverField) ? (string)($driverField['name'] ?? '') : '';
            if ($fieldName !== '' && array_key_exists($fieldName, $meta)) {
                $driverField['default'] = $meta[$fieldName];
            }
        }
        unset($driverField);
        $fields = $this->mergeFields(array_merge(
            $driverFields,
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
        } elseif (in_array($driver, ['http2', 'http3'], true) && !$this->schemeMatches($driver, $target, $reason)) {
            $debuggable = false;
            $debugReason = $reason;
        } elseif ($driver === 'grpc' && !GrpcDriver::available()) {
            $debuggable = false;
            $debugReason = 'curl HTTP/2 is required for gRPC';
        } elseif ($driver === 'http2' && !Http2Driver::available()) {
            $debuggable = false;
            $debugReason = 'curl HTTP/2 is required for HTTP/2';
        } elseif ($driver === 'http3' && !Http3Driver::available()) {
            $debuggable = false;
            $debugReason = 'curl HTTP/3 is required for HTTP/3';
        } elseif ($driver === 'xmpp' && !XmppDriver::available()) {
            $debuggable = false;
            $debugReason = XmppDriver::unavailableReason();
        } else {
            $debuggable = true;
            $debugReason = '';
        }

        $hints = isset($meta['hints']) ? (string)$meta['hints'] : '';
        if ($hints === '' && $driver === 'stomp' && $mode === 'relay-stream') {
            // STOMP 无持久会话,每次长轮询重连的间隙可能丢消息
            $hints = 'STOMP 长轮询每次重连的间隙可能丢失消息';
        }
        $debug = [
            'mode' => $mode,
            'driver' => $driver,
            'relayEnabled' => $relayEnabled,
            'debuggable' => $debuggable,
            'debugReason' => $debugReason,
            'fields' => $fields,
            'defaults' => $defaults,
            'meta' => $meta,
            'responseEncoding' => isset($meta['responseEncoding']) ? $meta['responseEncoding'] : 'text',
            'hints' => $hints,
        ];

        $result = ['channel' => $channel, 'debug' => $debug];
        if ($name !== '') {
            // 仅声明 Channel/Debug 时无协议名,不输出空协议对象
            $result = ['protocol' => $protocol] + $result;
        }
        return $result;
    }

    /**
     * 解析协议定义(文件/内联)并校验样例
     *
     * 双契约:
     * - 4 参调用: 使用传入的 $protocolMeta(ParseApiDetail 内部路径);
     * - 3 参调用: $protocolMeta 为空时从 $methodAnnotations 中提取
     *   protocol/channel/debug(直接传入 parseApiMethod 结果的场景)。
     * 两者都取不到协议元数据时返回 []。
     *
     * @param ReflectionClass $refClass
     * @param ReflectionMethod $refMethod
     * @param array $methodAnnotations 已处理的注解
     * @param array $protocolMeta metadata() 结果
     * @return array 无协议元数据时返回 []
     */
    public function render(ReflectionClass $refClass, ReflectionMethod $refMethod, array $methodAnnotations, array $protocolMeta = []): array
    {
        if (empty($protocolMeta)) {
            // 直接传入 parseApiMethod 结果时,协议元数据已合并进注解数组
            $protocolMeta = array_intersect_key($methodAnnotations, array_flip(['protocol', 'channel', 'debug']));
        }
        if (empty($protocolMeta)) {
            return [];
        }
        $protocolConfig = isset($this->config['protocols']) && is_array($this->config['protocols']) ? $this->config['protocols'] : [];
        $catalog = ProtocolCatalog::get((string)($protocolMeta['protocol']['name'] ?? ''));
        $classAnnotations = $this->parseAnnotation->getClassAnnotation($refClass);

        // 类级定义在前,方法级同 file|operation|message 覆盖
        $entries = [];
        foreach ([$classAnnotations, $methodAnnotations] as $annotations) {
            foreach ($this->normalizeItems($annotations['definition'] ?? null) as $item) {
                $file = isset($item['file']) ? (string)$item['file'] : '';
                if ($file === '' && isset($item['name'])) {
                    $file = (string)$item['name'];
                }
                $item['file'] = $file;
                $entries[$file . '|' . (string)($item['operation'] ?? '') . '|' . (string)($item['message'] ?? '')] = $item;
            }
        }

        $definitions = [];
        $errors = [];
        $warnings = [];
        $fingerprints = [];
        if (empty($entries)) {
            $definitions[] = $this->inlineDefinition($methodAnnotations);
        } else {
            foreach (array_values($entries) as $def) {
                $definition = $this->renderDefinition($def, $methodAnnotations, $catalog, $protocolConfig, $fingerprints);
                $definitions[] = $definition;
                $errors = array_merge($errors, $definition['validation']['errors']);
                $warnings = array_merge($warnings, $definition['validation']['warnings']);
            }
        }
        return array_merge($protocolMeta, [
            'definitions' => $definitions,
            'validation' => ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings],
            '_definitionFingerprints' => $fingerprints,
        ]);
    }

    /**
     * 详情响应缓存是否仍然有效:文件定义必须带指纹且全部 mtime/size 未变
     * @param array $detail
     * @return bool
     */
    public static function cacheFresh(array $detail): bool
    {
        $definitions = isset($detail['definitions']) && is_array($detail['definitions']) ? $detail['definitions'] : [];
        if ($definitions === []) {
            return true;
        }
        $fileBacked = false;
        foreach ($definitions as $definition) {
            $format = (string)($definition['format'] ?? '');
            if ($format !== 'inline' && $format !== 'pending') {
                $fileBacked = true;
                break;
            }
        }
        if (!$fileBacked) {
            return true;
        }
        $fingerprints = isset($detail['_definitionFingerprints']) && is_array($detail['_definitionFingerprints'])
            ? $detail['_definitionFingerprints']
            : [];
        return self::fingerprintsFresh($fingerprints);
    }

    /**
     * @param array $fingerprints realpath => ['mtime'=>int,'size'=>int]
     * @return bool
     */
    protected static function fingerprintsFresh(array $fingerprints): bool
    {
        if ($fingerprints === []) {
            return false;
        }
        foreach ($fingerprints as $file => $stat) {
            if (!is_string($file) || !is_array($stat) || !is_file($file)) {
                return false;
            }
            if (filemtime($file) !== (int)($stat['mtime'] ?? -1) || filesize($file) !== (int)($stat['size'] ?? -1)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 解析单个 Definition 注解项
     * @param array $def
     * @param array $methodAnnotations
     * @param array $catalog
     * @param array $protocolConfig
     * @param array $fingerprints realpath => 指纹,按引用累积
     * @return array
     */
    protected function renderDefinition(array $def, array $methodAnnotations, array $catalog, array $protocolConfig, array &$fingerprints): array
    {
        $file = (string)($def['file'] ?? '');
        $format = !empty($def['format']) ? (string)$def['format'] : 'auto';
        if ($file === '') {
            if ($format === 'inline' || $catalog['defaultFormat'] === '') {
                return $this->inlineDefinition($methodAnnotations);
            }
            return [
                'format' => 'pending',
                'source' => '',
                'operation' => '',
                'meta' => [],
                'messages' => [],
                'validation' => [
                    'ok' => true,
                    'errors' => [],
                    'warnings' => [$this->diagnostic('warning', 'definition file required for ' . $catalog['name'])],
                ],
            ];
        }

        $paths = new ProtocolPaths($protocolConfig);
        $resolved = $paths->resolve($file);
        if ($resolved === null) {
            return [
                'format' => $format === 'auto' ? ($catalog['defaultFormat'] !== '' ? $catalog['defaultFormat'] : 'auto') : $format,
                'source' => $file,
                'operation' => '',
                'meta' => [],
                'messages' => [],
                'validation' => [
                    'ok' => false,
                    'errors' => [$this->diagnostic('error', 'definition file not found or outside allowed roots')],
                    'warnings' => [],
                ],
            ];
        }

        $options = [
            'operation' => (string)($def['operation'] ?? ''),
            'message' => (string)($def['message'] ?? ''),
            'direction' => (string)($def['direction'] ?? ''),
            'sample' => (string)($def['sample'] ?? ''),
            'sampleEncoding' => (string)($def['sampleEncoding'] ?? ''),
            'strict' => array_key_exists('strict', $def) ? (bool)$def['strict'] : true,
        ];
        $limits = $protocolConfig;
        $roots = isset($protocolConfig['allowed_roots']) && is_array($protocolConfig['allowed_roots']) ? $protocolConfig['allowed_roots'] : [];
        $sampleErrors = [];
        $fileFingerprints = [];
        if (!empty($def['sampleFile'])) {
            $sampleResolved = $paths->resolve((string)$def['sampleFile']);
            if ($sampleResolved === null) {
                $sampleErrors[] = $this->diagnostic('error', 'sample file not found or outside allowed roots');
            } else {
                // 样例文件变更同样需要让定义缓存失效
                $fileFingerprints[$sampleResolved['realpath']] = ['mtime' => filemtime($sampleResolved['realpath']), 'size' => filesize($sampleResolved['realpath'])];
                // 样例文件与定义文件走同一套路径/大小约束
                $sampleCtx = new ProtocolContext($sampleResolved['realpath'], [], $limits, $roots);
                $sampleContent = $sampleCtx->read();
                if ($sampleContent === null) {
                    $sampleErrors = array_merge($sampleErrors, $sampleCtx->errors());
                } else {
                    $options['sample'] = $sampleContent;
                }
            }
        }

        $ctx = new ProtocolContext($resolved['realpath'], $options, $limits, $roots);
        foreach ($sampleErrors as $entry) {
            $ctx->error($entry['message'], (int)$entry['line'], (int)$entry['column']);
        }
        $content = $ctx->read();
        if ($format === 'auto') {
            $format = ProtocolRegistry::detect($file, $content === null ? '' : $content);
            if ($format === '') {
                $format = $catalog['defaultFormat'];
            }
        }

        $cache = new Cache();
        $cacheKey = 'protocol/' . $format . '/' . md5($file) . '_' . md5($resolved['realpath']) . '_' . md5((string)json_encode($options));
        $useCache = $format !== '';
        if ($useCache) {
            $cached = $cache->get($cacheKey);
            if (is_array($cached)
                && isset($cached['result'], $cached['fingerprints'])
                && is_array($cached['result'])
                && is_array($cached['fingerprints'])
                && self::fingerprintsFresh($cached['fingerprints'])
            ) {
                foreach ($cached['fingerprints'] as $cachedFile => $cachedStat) {
                    $fingerprints[$cachedFile] = $cachedStat;
                }
                return $cached['result'];
            }
        }

        $operation = '';
        $meta = [];
        $messages = [];
        if ($content !== null) {
            $adapter = ProtocolRegistry::adapter($format);
            if ($adapter === null) {
                $ctx->unsupported('unsupported definition format: ' . $format);
            } else {
                try {
                    $parsed = $adapter->parse($ctx, $content);
                    $operation = isset($parsed['operation']) ? (string)$parsed['operation'] : '';
                    $meta = isset($parsed['meta']) && is_array($parsed['meta']) ? $parsed['meta'] : [];
                    $messages = isset($parsed['messages']) && is_array($parsed['messages']) ? $parsed['messages'] : [];
                } catch (ProtocolParseException $e) {
                    $ctx->error($e->getMessage(), $e->line(), $e->column());
                } catch (\Throwable $e) {
                    $ctx->error($e->getMessage());
                }
            }
        }
        // 根文件与全部导入文件参与指纹
        foreach (array_merge([$resolved['realpath']], $ctx->imports()) as $filePath) {
            if (is_file($filePath)) {
                $fileFingerprints[$filePath] = ['mtime' => filemtime($filePath), 'size' => filesize($filePath)];
            }
        }
        $result = [
            'format' => $format,
            'source' => $file,
            'operation' => $options['operation'] !== '' ? $options['operation'] : $operation,
            'meta' => $meta,
            'messages' => $messages,
            'validation' => [
                'ok' => $ctx->errors() === [],
                'errors' => $ctx->errors(),
                'warnings' => $ctx->warnings(),
            ],
        ];
        if ($useCache) {
            $cache->set($cacheKey, ['result' => $result, 'fingerprints' => $fileFingerprints]);
        }
        foreach ($fileFingerprints as $filePath => $stat) {
            $fingerprints[$filePath] = $stat;
        }
        return $result;
    }

    /**
     * 无定义文件时由注解生成内联定义
     * @param array $methodAnnotations
     * @return array
     */
    protected function inlineDefinition(array $methodAnnotations): array
    {
        $request = $this->normalizeItems($methodAnnotations['param'] ?? null);
        $response = array_merge(
            $this->normalizeItems($methodAnnotations['responseSuccess'] ?? null),
            $this->normalizeItems($methodAnnotations['responseError'] ?? null)
        );
        $messages = [];
        if (!empty($request)) {
            $messages[] = ['name' => 'Request', 'direction' => 'request', 'fields' => $request];
        }
        if (!empty($response)) {
            $messages[] = ['name' => 'Response', 'direction' => 'response', 'fields' => $response];
        }
        return [
            'format' => 'inline',
            'source' => 'inline',
            'operation' => '',
            'meta' => [],
            'messages' => $messages,
            'validation' => ['ok' => true, 'errors' => [], 'warnings' => []],
        ];
    }

    protected function diagnostic(string $severity, string $message, int $line = 0, int $column = 0): array
    {
        return ['path' => '', 'line' => $line, 'column' => $column, 'severity' => $severity, 'message' => $message];
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
    /**
     * HTTP/2 支持 http(s),HTTP/3 仅 https;scheme 不符时给出明确原因
     * @param string $driver
     * @param string $target
     * @param string|null $reason
     * @return bool
     */
    protected function schemeMatches(string $driver, string $target, ?string &$reason): bool
    {
        $reason = '';
        try {
            $parsed = DebugTarget::parse($target);
        } catch (InvalidArgumentException $e) {
            $reason = '调试目标无法解析: ' . $e->getMessage();
            return false;
        }
        if ($driver === 'http2' && !in_array($parsed['scheme'], ['http', 'https'], true)) {
            $reason = 'HTTP/2 需要 http:// 或 https:// 目标,当前为 ' . $parsed['scheme'] . '://';
            return false;
        }
        if ($driver === 'http3' && $parsed['scheme'] !== 'https') {
            $reason = 'HTTP/3 需要 https:// 目标,当前为 ' . $parsed['scheme'] . '://';
            return false;
        }
        return true;
    }

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
