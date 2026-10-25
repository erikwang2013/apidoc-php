<?php

namespace erikwang2013\apidoc\protocol\Support;

use erikwang2013\apidoc\protocol\ProtocolContext;

/**
 * JSON Schema => apidoc 字段映射
 * @package erikwang2013\apidoc\protocol\Support
 */
class SchemaMapper
{
    /**
     * 解析 schema 得到 apidoc 字段列表
     * @param array $schema
     * @param ProtocolContext $ctx
     * @param callable $resolveRef string $ref => array|null
     * @return array
     */
    public static function fields(array $schema, ProtocolContext $ctx, callable $resolveRef): array
    {
        $schema = self::flatten($schema, $ctx, $resolveRef);
        if (empty($schema['properties']) || !is_array($schema['properties'])) {
            return [];
        }
        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];
        $fields = [];
        foreach ($schema['properties'] as $name => $prop) {
            if (!is_array($prop)) {
                $prop = [];
            }
            $prop = self::flatten($prop, $ctx, $resolveRef);
            $field = [
                'name' => (string)$name,
                'type' => self::typeOf($prop),
                'require' => in_array($name, $required, true),
            ];
            if (!empty($prop['description'])) {
                $field['desc'] = (string)$prop['description'];
            }
            if (isset($prop['enum']) && is_array($prop['enum'])) {
                $field['enum'] = array_values($prop['enum']);
            }
            if (!empty($prop['nullable']) || (isset($prop['type']) && is_array($prop['type']))) {
                $field['require'] = false;
                $ctx->warning('field ' . $name . ' is nullable or a union type; marked optional');
            }
            if ($field['type'] === 'array') {
                $items = isset($prop['items']) && is_array($prop['items']) ? $prop['items'] : [];
                $children = self::fields($items, $ctx, $resolveRef);
                if (!empty($children)) {
                    $field['children'] = $children;
                }
            } elseif ($field['type'] === 'object') {
                $children = self::fields($prop, $ctx, $resolveRef);
                if (!empty($children)) {
                    $field['children'] = $children;
                }
            }
            $fields[] = $field;
        }
        return $fields;
    }

    /**
     * JSON Schema type => apidoc 参数类型
     * @param array $schema
     * @return string
     */
    public static function typeOf(array $schema): string
    {
        $type = isset($schema['type']) ? $schema['type'] : null;
        if (is_array($type)) {
            foreach ($type as $item) {
                if ($item !== 'null') {
                    $type = $item;
                    break;
                }
            }
        }
        switch ($type) {
            case 'string':
            case 'integer':
            case 'number':
            case 'boolean':
            case 'array':
            case 'object':
                return $type;
            case 'int':
                return 'integer';
            case 'bool':
                return 'boolean';
        }
        return !empty($schema['properties']) ? 'object' : 'string';
    }

    /**
     * 展开 $ref/allOf, oneOf/anyOf 取首分支并告警
     * @param array $schema
     * @param ProtocolContext $ctx
     * @param callable $resolveRef
     * @return array
     */
    protected static function flatten(array $schema, ProtocolContext $ctx, callable $resolveRef): array
    {
        if (isset($schema['$ref'])) {
            $resolved = $resolveRef((string)$schema['$ref']);
            if (!is_array($resolved)) {
                return [];
            }
            $schema = array_merge($schema, $resolved);
            unset($schema['$ref']);
        }
        if (!empty($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $branch = self::flatten($branch, $ctx, $resolveRef);
                if (!empty($branch['properties']) && is_array($branch['properties'])) {
                    $schema['properties'] = array_merge($schema['properties'] ?? [], $branch['properties']);
                }
                if (!empty($branch['required']) && is_array($branch['required'])) {
                    $schema['required'] = array_merge($schema['required'] ?? [], $branch['required']);
                }
            }
            $schema['required'] = array_values(array_unique($schema['required'] ?? []));
            unset($schema['allOf']);
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (empty($schema[$keyword]) || !is_array($schema[$keyword])) {
                continue;
            }
            $ctx->warning($keyword . ' is not fully supported; using the first branch');
            $branch = isset($schema[$keyword][0]) && is_array($schema[$keyword][0]) ? $schema[$keyword][0] : [];
            unset($schema[$keyword]);
            $schema = array_merge($schema, $branch);
            if (isset($schema['$ref'])) {
                $resolved = $resolveRef((string)$schema['$ref']);
                if (!is_array($resolved)) {
                    return [];
                }
                $schema = array_merge($schema, $resolved);
                unset($schema['$ref']);
            }
        }
        return $schema;
    }
}
