<?php

namespace erikwang2013\apidoc\protocol\Adapter;

use erikwang2013\apidoc\protocol\AdapterInterface;
use erikwang2013\apidoc\protocol\ProtocolContext;
use erikwang2013\apidoc\protocol\ProtocolParseException;
use erikwang2013\apidoc\protocol\Support\JsonSchemaValidator;
use erikwang2013\apidoc\protocol\Support\RefResolver;
use erikwang2013\apidoc\protocol\Support\SchemaMapper;

/**
 * JSON Schema 适配器
 * @package erikwang2013\apidoc\protocol\Adapter
 */
class JsonSchemaAdapter implements AdapterInterface
{
    public function format(): string
    {
        return 'json-schema';
    }

    public function parse(ProtocolContext $ctx, string $content): array
    {
        $root = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ProtocolParseException('JSON parse error: ' . json_last_error_msg(), 0, 0);
        }
        if (!is_array($root)) {
            throw new ProtocolParseException('JSON parse error: root must be an object', 0, 0);
        }
        $message = (string)$ctx->option('message', '');
        $schema = $root;
        if ($message !== '') {
            if (isset($root['$defs'][$message]) && is_array($root['$defs'][$message])) {
                $schema = $root['$defs'][$message];
            } elseif (isset($root['definitions'][$message]) && is_array($root['definitions'][$message])) {
                $schema = $root['definitions'][$message];
            } else {
                $ctx->error('message not found: ' . $message);
            }
        }
        $load = function (string $loaded): ?array {
            $data = json_decode($loaded, true);
            return is_array($data) ? $data : null;
        };
        $resolveRef = function (string $ref) use ($root, $ctx, $load): ?array {
            return RefResolver::resolve($ref, $root, $ctx, $load);
        };
        // 先展开 $ref(含外部文件/链式引用), 字段映射与样例校验都基于展开结果
        $expanded = RefResolver::expand($schema, $root, $ctx, $load);
        $name = $message !== '' ? $message : (isset($schema['title']) ? (string)$schema['title'] : 'Body');
        $direction = (string)$ctx->option('direction', '');
        if ($direction === '') {
            $direction = 'request';
        }
        $sample = (string)$ctx->option('sample', '');
        return [
            'operation' => '',
            'messages' => [[
                'name' => $name,
                'direction' => $direction,
                'fields' => SchemaMapper::fields($expanded, $ctx, $resolveRef),
                'sample' => $sample,
                'sampleValidation' => $this->validateSample($sample, $expanded, $resolveRef),
            ]],
            'meta' => isset($schema['x-webhook-signature']) && is_array($schema['x-webhook-signature']) ? $schema['x-webhook-signature'] : [],
        ];
    }

    /**
     * @param string $sample
     * @param array $schema
     * @param callable $resolveRef
     * @return array ['ok'=>bool,'errors'=>array]
     */
    protected function validateSample(string $sample, array $schema, callable $resolveRef): array
    {
        if ($sample === '') {
            return ['ok' => true, 'errors' => []];
        }
        $data = json_decode($sample, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'errors' => [['path' => '$', 'message' => 'JSON parse error: ' . json_last_error_msg()]]];
        }
        $errors = JsonSchemaValidator::validate($schema, $data, $resolveRef);
        return ['ok' => $errors === [], 'errors' => $errors];
    }
}
