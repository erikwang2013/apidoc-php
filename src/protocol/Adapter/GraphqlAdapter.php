<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\Adapter;

use erikwang2013\apidoc\protocol\AdapterInterface;
use erikwang2013\apidoc\protocol\ProtocolContext;
use erikwang2013\apidoc\protocol\Support\TokenStream;

/**
 * GraphQL SDL 适配器(子集)
 */
class GraphqlAdapter implements AdapterInterface
{
    const DEFINITIONS = ['schema', 'type', 'input', 'interface', 'enum', 'scalar', 'union', 'extend', 'directive'];

    public function format(): string
    {
        return 'graphql';
    }

    public function parse(ProtocolContext $ctx, string $content): array
    {
        $types = $this->parseDocument($ctx, $content);
        $queryType = $types['__schema']['query'] ?? 'Query';
        $queryFields = isset($types[$queryType]['fields']) && is_array($types[$queryType]['fields'])
            ? $types[$queryType]['fields']
            : [];
        $wanted = (string)$ctx->option('operation', '');
        $selected = $this->select($types, $queryType, $queryFields, $wanted, $ctx);
        if ($selected === null) {
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $typeName = $selected['type'];
        $fieldName = $selected['field'];
        $field = $selected['definition'];
        $request = [];
        foreach ($field['args'] ?? [] as $arg) {
            $descriptor = $this->describeType($arg['type'], $types, []);
            $descriptor['name'] = $arg['name'];
            $descriptor['require'] = !empty($arg['type']['require']);
            $request[] = $descriptor;
        }
        $response = $this->responseFields($field['type'], $types);
        $messages = [
            ['name' => 'Request', 'direction' => 'request', 'fields' => $request],
            ['name' => 'Response', 'direction' => 'response', 'fields' => $response],
        ];
        $sample = (string)$ctx->option('sample', '');
        if ($sample !== '') {
            $target = $ctx->option('direction', '') === 'request' ? 0 : 1;
            $messages[$target]['sampleValidation'] = $this->validateSample($sample, $messages[$target]['fields']);
        }
        return [
            'operation' => $typeName . '.' . $fieldName,
            'messages' => $messages,
            'meta' => [],
        ];
    }

    protected function parseDocument(ProtocolContext $ctx, string $content): array
    {
        $types = [];
        if (preg_match_all('/^\s*#import\s+["\']([^"\']+)["\']/m', $content, $matches) === 1 || !empty($matches[1])) {
            foreach ($matches[1] as $import) {
                $realpath = $ctx->resolve((string)$import);
                if ($realpath === null) {
                    $ctx->warning('unable to resolve GraphQL import: ' . $import);
                    continue;
                }
                if (!$ctx->push($realpath)) {
                    continue;
                }
                $imported = $ctx->read();
                if ($imported !== null) {
                    $types = array_replace($types, $this->parseDocument($ctx, $imported));
                }
                $ctx->pop();
            }
        }
        $ts = new TokenStream($content);
        while (!$ts->eof()) {
            $this->skipDescription($ts);
            if ($ts->eof()) {
                break;
            }
            $keyword = $ts->identifier();
            switch ($keyword) {
                case 'schema':
                    $this->parseSchema($ts, $types);
                    break;
                case 'type':
                case 'interface':
                case 'input':
                    $this->parseTypeDefinition($ts, $types, $keyword);
                    break;
                case 'enum':
                    $this->parseEnum($ts, $types);
                    break;
                case 'scalar':
                    $name = $ts->identifier();
                    $types[$name] = ['kind' => 'scalar'];
                    break;
                case 'union':
                    $this->parseUnion($ts, $types);
                    break;
                case 'extend':
                    $kind = $ts->identifier();
                    if ($kind === 'type' || $kind === 'interface' || $kind === 'input') {
                        $this->parseTypeDefinition($ts, $types, $kind, true);
                    } else {
                        $this->skipDefinition($ts);
                    }
                    break;
                case 'directive':
                    $this->skipDirective($ts);
                    break;
                default:
                    $ctx->warning('unsupported GraphQL definition: ' . $keyword);
                    $this->skipDefinition($ts);
            }
        }
        return $types;
    }

    protected function parseSchema(TokenStream $ts, array &$types): void
    {
        $ts->expect('{');
        while (!$ts->eof() && $ts->peek() !== '}') {
            $this->skipDescription($ts);
            if ($ts->peek() === '}') {
                break;
            }
            $kind = $ts->identifier();
            $ts->expect(':');
            $types['__schema'][$kind] = $ts->identifier();
            $ts->consume(',');
        }
        $ts->expect('}');
    }

    protected function parseTypeDefinition(TokenStream $ts, array &$types, string $kind, bool $extend = false): void
    {
        $name = $ts->identifier();
        while (!$ts->eof() && $ts->peek() !== '{') {
            if ($ts->peek() === '}') {
                break;
            }
            $ts->next();
        }
        $fields = $this->parseFields($ts);
        if ($extend && isset($types[$name]['fields'])) {
            $types[$name]['fields'] = array_merge($types[$name]['fields'], $fields);
        } else {
            $types[$name] = ['kind' => $kind, 'fields' => $fields];
        }
    }

    protected function parseFields(TokenStream $ts): array
    {
        $fields = [];
        $ts->expect('{');
        while (!$ts->eof() && $ts->peek() !== '}') {
            $this->skipDescription($ts);
            if ($ts->peek() === '}') {
                break;
            }
            $name = $ts->identifier();
            $args = [];
            if ($ts->consume('(') !== null) {
                $args = $this->parseArguments($ts);
            }
            $ts->expect(':');
            $type = $this->parseTypeRef($ts);
            $this->skipDirectives($ts);
            $fields[$name] = ['name' => $name, 'type' => $type, 'args' => $args];
        }
        $ts->expect('}');
        return $fields;
    }

    protected function parseArguments(TokenStream $ts): array
    {
        $args = [];
        while (!$ts->eof() && $ts->peek() !== ')') {
            $this->skipDescription($ts);
            if ($ts->peek() === ')') {
                break;
            }
            $name = $ts->identifier();
            $ts->expect(':');
            $type = $this->parseTypeRef($ts);
            if ($ts->consume('=') !== null) {
                $this->skipDefaultValue($ts);
            }
            $args[] = ['name' => $name, 'type' => $type];
            $ts->consume(',');
        }
        $ts->expect(')');
        return $args;
    }

    protected function parseTypeRef(TokenStream $ts): array
    {
        if ($ts->consume('[') !== null) {
            $inner = $this->parseTypeRef($ts);
            $ts->expect(']');
            $type = ['kind' => 'array', 'inner' => $inner];
        } else {
            $type = ['kind' => 'named', 'name' => $ts->identifier()];
        }
        $type['require'] = $ts->consume('!') !== null;
        return $type;
    }

    protected function parseEnum(TokenStream $ts, array &$types): void
    {
        $name = $ts->identifier();
        $values = [];
        $ts->expect('{');
        while (!$ts->eof() && $ts->peek() !== '}') {
            $this->skipDescription($ts);
            if ($ts->peek() === '}') {
                break;
            }
            $values[] = $ts->identifier();
            $ts->consume(',');
        }
        $ts->expect('}');
        $types[$name] = ['kind' => 'enum', 'values' => $values];
    }

    protected function parseUnion(TokenStream $ts, array &$types): void
    {
        $name = $ts->identifier();
        $members = [];
        if ($ts->consume('=') !== null) {
            while (!$ts->eof()) {
                $members[] = $ts->identifier();
                if ($ts->consume('|') === null) {
                    break;
                }
            }
        }
        $types[$name] = ['kind' => 'union', 'members' => $members];
    }

    protected function select(array $types, string $queryType, array $queryFields, string $wanted, ProtocolContext $ctx): ?array
    {
        if ($wanted !== '') {
            $pos = strrpos($wanted, '.');
            $typeName = $pos === false ? $queryType : substr($wanted, 0, $pos);
            $fieldName = $pos === false ? $wanted : substr($wanted, $pos + 1);
            if (isset($types[$typeName]['fields'][$fieldName])) {
                return ['type' => $typeName, 'field' => $fieldName, 'definition' => $types[$typeName]['fields'][$fieldName]];
            }
            $ctx->error('operation not found: ' . $wanted . '; candidates: ' . $this->candidates($queryType, $queryFields));
            return null;
        }
        if (count($queryFields) === 1) {
            $fieldName = (string)array_key_first($queryFields);
            return ['type' => $queryType, 'field' => $fieldName, 'definition' => $queryFields[$fieldName]];
        }
        $ctx->error('operation required; candidates: ' . $this->candidates($queryType, $queryFields));
        return null;
    }

    protected function candidates(string $typeName, array $fields): string
    {
        $names = [];
        foreach (array_keys($fields) as $field) {
            $names[] = $typeName . '.' . $field;
        }
        return implode(', ', $names);
    }

    protected function responseFields(array $typeRef, array $types): array
    {
        if ($typeRef['kind'] === 'array') {
            $inner = $this->describeType($typeRef['inner'], $types, []);
            return [['name' => 'items', 'type' => 'array', 'require' => !empty($typeRef['require']), 'children' => $inner['children'] ?? []]];
        }
        if ($typeRef['kind'] === 'named') {
            $descriptor = $this->describeType($typeRef, $types, []);
            if (($descriptor['type'] ?? '') === 'object') {
                return $descriptor['children'] ?? [];
            }
            return [['name' => 'result', 'type' => $descriptor['type'] ?? 'string', 'require' => !empty($typeRef['require'])]];
        }
        return [];
    }

    protected function describeType(array $typeRef, array $types, array $visited): array
    {
        if ($typeRef['kind'] === 'array') {
            $inner = $this->describeType($typeRef['inner'], $types, $visited);
            $result = ['type' => 'array', 'require' => !empty($typeRef['require'])];
            if (($inner['type'] ?? '') === 'object') {
                $result['children'] = $inner['children'] ?? [];
                $result['childrenType'] = 'object';
            } else {
                $result['childrenType'] = $inner['type'] ?? 'string';
            }
            return $result;
        }
        $name = (string)($typeRef['name'] ?? '');
        $require = !empty($typeRef['require']);
        $scalars = ['String' => 'string', 'ID' => 'string', 'Int' => 'integer', 'Float' => 'number', 'Boolean' => 'boolean'];
        if (isset($scalars[$name])) {
            return ['type' => $scalars[$name], 'require' => $require];
        }
        if (isset($types[$name]['kind']) && $types[$name]['kind'] === 'enum') {
            return ['type' => 'string', 'require' => $require, 'enum' => $types[$name]['values'] ?? []];
        }
        if (isset($types[$name]['fields']) && !in_array($name, $visited, true)) {
            $visited[] = $name;
            return ['type' => 'object', 'require' => $require, 'ref' => $name, 'children' => $this->mapFields($types[$name]['fields'], $types, $visited)];
        }
        return ['type' => 'object', 'require' => $require, 'ref' => $name, 'children' => []];
    }

    protected function mapFields(array $fields, array $types, array $visited): array
    {
        $result = [];
        foreach ($fields as $field) {
            $descriptor = $this->describeType($field['type'], $types, $visited);
            $descriptor['name'] = $field['name'];
            $result[] = $descriptor;
        }
        return $result;
    }

    protected function validateSample(string $sample, array $fields): array
    {
        $data = json_decode($sample, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'errors' => [['path' => '$', 'severity' => 'error', 'message' => 'JSON sample parse error: ' . json_last_error_msg()]], 'warnings' => []];
        }
        $errors = [];
        $this->validateFields($fields, $data, '$', $errors);
        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => []];
    }

    protected function validateFields(array $fields, $data, string $path, array &$errors): void
    {
        if (!is_array($data)) {
            $errors[] = ['path' => $path, 'severity' => 'error', 'message' => 'expected object'];
            return;
        }
        foreach ($fields as $field) {
            $name = (string)$field['name'];
            $exists = array_key_exists($name, $data);
            if (!$exists) {
                if (!empty($field['require'])) {
                    $errors[] = ['path' => $path . '.' . $name, 'severity' => 'error', 'message' => 'required field missing'];
                }
                continue;
            }
            $value = $data[$name];
            $type = $field['type'] ?? '';
            if ($type === 'object' && !empty($field['children'])) {
                $this->validateFields($field['children'], $value, $path . '.' . $name, $errors);
            } elseif ($type === 'array' && !empty($field['children'])) {
                if (!is_array($value)) {
                    $errors[] = ['path' => $path . '.' . $name, 'severity' => 'error', 'message' => 'expected array'];
                    continue;
                }
                foreach ($value as $index => $item) {
                    $this->validateFields($field['children'], $item, $path . '.' . $name . '[' . $index . ']', $errors);
                }
            } elseif ($type === 'integer' && !is_int($value)) {
                $errors[] = ['path' => $path . '.' . $name, 'severity' => 'error', 'message' => 'expected integer'];
            } elseif ($type === 'number' && !is_int($value) && !is_float($value)) {
                $errors[] = ['path' => $path . '.' . $name, 'severity' => 'error', 'message' => 'expected number'];
            } elseif ($type === 'boolean' && !is_bool($value)) {
                $errors[] = ['path' => $path . '.' . $name, 'severity' => 'error', 'message' => 'expected boolean'];
            } elseif ($type === 'string' && !is_string($value)) {
                $errors[] = ['path' => $path . '.' . $name, 'severity' => 'error', 'message' => 'expected string'];
            }
        }
    }

    protected function skipDescription(TokenStream $ts): void
    {
        if ($ts->peek() !== '"') {
            return;
        }
        if ($ts->peek(1) === '"' && $ts->peek(2) === '"') {
            $ts->next();
            $ts->next();
            $ts->next();
            while (!$ts->eof()) {
                if ($ts->peek() === '"' && $ts->peek(1) === '"' && $ts->peek(2) === '"') {
                    $ts->next();
                    $ts->next();
                    $ts->next();
                    return;
                }
                $ts->next();
            }
            return;
        }
        $ts->string();
    }

    protected function skipDirectives(TokenStream $ts): void
    {
        while ($ts->consume('@') !== null) {
            $ts->identifier();
            if ($ts->consume('(') !== null) {
                $this->skipBalanced($ts, '(', ')');
            }
        }
    }

    protected function skipDefaultValue(TokenStream $ts): void
    {
        $ch = $ts->peek();
        if ($ch === '[') {
            $ts->next();
            $this->skipBalanced($ts, '[', ']');
            return;
        }
        if ($ch === '{') {
            $ts->next();
            $this->skipBalanced($ts, '{', '}');
            return;
        }
        if ($ch === '"' || $ch === "'") {
            $ts->string();
            return;
        }
        while (!$ts->eof()) {
            $ch = $ts->peek();
            if ($ch === ',' || $ch === ')' || $ch === '}') {
                return;
            }
            $ts->next();
        }
    }

    protected function skipBalanced(TokenStream $ts, string $open, string $close): void
    {
        $depth = 1;
        while (!$ts->eof() && $depth > 0) {
            $ch = $ts->next();
            if ($ch === $open) {
                $depth++;
            } elseif ($ch === $close) {
                $depth--;
            }
        }
    }

    protected function skipDirective(TokenStream $ts): void
    {
        $ts->consume('@');
        if (!$ts->eof()) {
            $ts->identifier();
        }
        if ($ts->consume('(') !== null) {
            $this->skipBalanced($ts, '(', ')');
        }
        if (!$ts->eof()) {
            $ts->identifier(); // on
        }
        while (!$ts->eof()) {
            $ch = $ts->peek();
            if ($ch === '|' || ctype_alpha($ch) || $ch === '_') {
                $ts->next();
                continue;
            }
            break;
        }
    }

    protected function skipDefinition(TokenStream $ts): void
    {
        while (!$ts->eof()) {
            $ch = $ts->peek();
            if ($ch === '}') {
                $ts->next();
                return;
            }
            if (ctype_alpha($ch) || $ch === '_') {
                $word = $ts->identifier();
                if (in_array($word, self::DEFINITIONS, true)) {
                    // Cannot rewind; let the main loop report the unsupported definition next.
                    return;
                }
                continue;
            }
            $ts->next();
        }
    }
}
