<?php

namespace erikwang2013\apidoc\protocol;

/**
 * 协议定义格式识别与适配器注册表
 * @package erikwang2013\apidoc\protocol
 */
class ProtocolRegistry
{
    /**
     * @var array 扩展名 => 格式
     */
    protected static $extensions = [
        'proto' => 'proto',
        'thrift' => 'thrift',
        'graphql' => 'graphql',
        'gql' => 'graphql',
        'wsdl' => 'wsdl',
    ];

    /**
     * @var array 格式 => 适配器类(类可能由后续任务实现)
     */
    protected static $adapters = [
        'proto' => Adapter\ProtoAdapter::class,
        'openapi' => Adapter\OpenApiAdapter::class,
        'asyncapi' => Adapter\AsyncApiAdapter::class,
        'graphql' => Adapter\GraphqlAdapter::class,
        'wsdl' => Adapter\WsdlAdapter::class,
        'edmx' => Adapter\EdmxAdapter::class,
        'json-schema' => Adapter\JsonSchemaAdapter::class,
        'json-rpc' => Adapter\JsonRpcAdapter::class,
        'xml-rpc' => Adapter\XmlRpcAdapter::class,
    ];

    /**
     * 识别定义格式: 已知扩展名直接映射, json/yaml/xml 依据内容判断
     * @param string $relativePath 声明路径
     * @param string $content 文件内容(用于结构化格式)
     * @return string 格式标识, 未知时 ''
     */
    public static function detect(string $relativePath, string $content = ''): string
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (isset(self::$extensions[$extension])) {
            return self::$extensions[$extension];
        }
        if (in_array($extension, ['json', 'yaml', 'yml'], true)) {
            return self::detectStructured($content);
        }
        if ($extension === 'xml') {
            return self::detectXml($content);
        }
        return '';
    }

    /**
     * @return AdapterInterface|null 适配器实例; 未知或未实现的格式为 null
     */
    public static function adapter(string $format): ?AdapterInterface
    {
        $class = self::$adapters[$format] ?? null;
        if ($class === null || !class_exists($class)) {
            return null;
        }
        return new $class();
    }

    /**
     * @return array 已注册的格式标识
     */
    public static function formats(): array
    {
        return array_keys(self::$adapters);
    }

    protected static function detectStructured(string $content): string
    {
        if (preg_match('/(["\']?openapi["\']?|["\']?swagger["\']?)\s*:/i', $content)) {
            return 'openapi';
        }
        if (preg_match('/["\']?asyncapi["\']?\s*:/i', $content)) {
            return 'asyncapi';
        }
        if (preg_match('/["\']?methods["\']?\s*:/i', $content)) {
            return 'json-rpc';
        }
        if (preg_match('/(\$schema|["\']?type["\']?|["\']?properties["\']?)\s*:/i', $content)) {
            return 'json-schema';
        }
        return '';
    }

    protected static function detectXml(string $content): string
    {
        if (stripos($content, '<edmx:') !== false) {
            return 'edmx';
        }
        if (stripos($content, '<definitions') !== false && stripos($content, 'wsdl') !== false) {
            return 'wsdl';
        }
        if (stripos($content, '<methodCall') !== false || stripos($content, '<methodResponse') !== false) {
            return 'xml-rpc';
        }
        if (stripos($content, '<Envelope') !== false || stripos($content, '<definitions') !== false) {
            return 'wsdl';
        }
        return '';
    }
}
