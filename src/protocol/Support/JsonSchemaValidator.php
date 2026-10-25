<?php

namespace erikwang2013\apidoc\protocol\Support;

/**
 * JSON Schema 样例校验(仅覆盖常用关键字)
 * @package erikwang2013\apidoc\protocol\Support
 */
class JsonSchemaValidator
{
    /**
     * @param array $schema
     * @param mixed $data 已 json_decode 的样例
     * @param callable $resolveRef string $ref => array|null
     * @param string $path
     * @param int $depth 递归深度上限, 防止未展开的递归 schema 无限展开
     * @return array [['path'=>'$.x','message'=>'...'], ...]
     */
    public static function validate(array $schema, $data, callable $resolveRef, string $path = '$', int $depth = 0): array
    {
        if ($depth > 32) {
            return [];
        }
        $errors = [];
        // 链式 $ref(A->B->C)必须逐层解析; 环时停止解析, 避免吞掉后置约束
        $seen = [];
        $refDepth = 0;
        while (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $ref = $schema['$ref'];
            if (isset($seen[$ref]) || $refDepth >= 32) {
                break;
            }
            $seen[$ref] = true;
            $resolved = $resolveRef($ref);
            if (!is_array($resolved)) {
                return $errors;
            }
            $siblings = $schema;
            unset($siblings['$ref']);
            $schema = array_merge($siblings, $resolved);
            $refDepth++;
        }
        if (!empty($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (is_array($branch)) {
                    $errors = array_merge($errors, self::validate($branch, $data, $resolveRef, $path, $depth + 1));
                }
            }
        }
        if (!empty($schema['anyOf']) && is_array($schema['anyOf'])) {
            $matched = false;
            foreach ($schema['anyOf'] as $branch) {
                if (is_array($branch) && self::validate($branch, $data, $resolveRef, $path, $depth + 1) === []) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $errors[] = self::error($path, 'does not match any of the allowed schemas');
            }
        }
        if (!empty($schema['oneOf']) && is_array($schema['oneOf'])) {
            $matches = 0;
            foreach ($schema['oneOf'] as $branch) {
                if (is_array($branch) && self::validate($branch, $data, $resolveRef, $path, $depth + 1) === []) {
                    $matches++;
                }
            }
            if ($matches !== 1) {
                $errors[] = self::error($path, 'must match exactly one schema');
            }
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            $errors[] = self::error($path, 'value is not one of the allowed values');
        }
        if (array_key_exists('const', $schema) && $data != $schema['const']) {
            $errors[] = self::error($path, 'value must equal the const value');
        }
        $type = isset($schema['type']) ? $schema['type'] : null;
        if ($type !== null && !self::matchesType($type, $data)) {
            $errors[] = self::error($path, 'expected type ' . (is_array($type) ? implode('|', $type) : (string)$type));
            return $errors;
        }
        if (!is_array($data)) {
            return $errors;
        }
        $isObject = $data === [] || !self::isList($data);
        if ($isObject && isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $name) {
                if (!array_key_exists($name, $data)) {
                    $errors[] = self::error(self::childPath($path, (string)$name), 'is required');
                }
            }
        }
        if ($isObject && !empty($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $prop) {
                if (is_array($prop) && array_key_exists($name, $data)) {
                    $errors = array_merge($errors, self::validate($prop, $data[$name], $resolveRef, self::childPath($path, (string)$name), $depth + 1));
                }
            }
        }
        if (isset($schema['items']) && is_array($schema['items']) && self::isList($data)) {
            foreach (array_values($data) as $index => $item) {
                $errors = array_merge($errors, self::validate($schema['items'], $item, $resolveRef, $path . '[' . $index . ']', $depth + 1));
            }
        }
        return $errors;
    }

    /**
     * @param mixed $type string|array
     * @param mixed $data
     * @return bool
     */
    protected static function matchesType($type, $data): bool
    {
        foreach ((array)$type as $item) {
            if (self::matchesOne((string)$item, $data)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $type
     * @param mixed $data
     * @return bool
     */
    protected static function matchesOne(string $type, $data): bool
    {
        switch ($type) {
            case 'object':
                return is_array($data) && ($data === [] || !self::isList($data));
            case 'array':
                return is_array($data) && self::isList($data);
            case 'string':
                return is_string($data);
            case 'integer':
                return is_int($data);
            case 'number':
                return is_int($data) || is_float($data);
            case 'boolean':
                return is_bool($data);
            case 'null':
                return $data === null;
        }
        // 未知类型按规范忽略
        return true;
    }

    protected static function isList(array $data): bool
    {
        return $data === [] || array_keys($data) === range(0, count($data) - 1);
    }

    protected static function childPath(string $path, string $name): string
    {
        return $path === '$' ? '$.' . $name : $path . '.' . $name;
    }

    protected static function error(string $path, string $message): array
    {
        return ['path' => $path, 'message' => $message];
    }
}
