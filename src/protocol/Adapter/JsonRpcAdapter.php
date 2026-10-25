<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\Adapter;

use erikwang2013\apidoc\protocol\AdapterInterface;
use erikwang2013\apidoc\protocol\ProtocolContext;
use erikwang2013\apidoc\protocol\ProtocolParseException;
use erikwang2013\apidoc\protocol\Support\JsonSchemaValidator;
use erikwang2013\apidoc\protocol\Support\RefResolver;
use erikwang2013\apidoc\protocol\Support\SchemaMapper;

/**
 * JSON-RPC 2.0 方法定义适配器
 */
class JsonRpcAdapter implements AdapterInterface
{
    public function format(): string
    {
        return 'json-rpc';
    }

    public function parse(ProtocolContext $ctx, string $content): array
    {
        $document = json_decode($content, true);
        if (!is_array($document)) {
            throw new ProtocolParseException('JSON-RPC JSON parse error: ' . json_last_error_msg(), 0, 0);
        }
        $methods = isset($document['methods']) && is_array($document['methods']) ? $document['methods'] : [];
        if ($methods === []) {
            $ctx->error('JSON-RPC methods are missing');
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $wanted = (string)$ctx->option('operation', '');
        if ($wanted === '') {
            if (count($methods) === 1) {
                $wanted = (string)array_key_first($methods);
            } else {
                $ctx->error('operation required; candidates: ' . implode(', ', array_keys($methods)));
                return ['operation' => '', 'messages' => [], 'meta' => []];
            }
        }
        if (!isset($methods[$wanted]) || !is_array($methods[$wanted])) {
            $ctx->error('operation not found: ' . $wanted . '; candidates: ' . implode(', ', array_keys($methods)));
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $method = $methods[$wanted];
        $load = function (string $content): ?array {
            $decoded = json_decode($content, true);
            return is_array($decoded) ? $decoded : null;
        };
        $resolveRef = function (string $ref) use ($document, $ctx, $load): ?array {
            return RefResolver::resolve($ref, $document, $ctx, $load);
        };
        $paramsSchema = isset($method['params']) && is_array($method['params']) ? $method['params'] : [];
        $resultSchema = isset($method['result']) && is_array($method['result']) ? $method['result'] : [];
        $paramsSchema = RefResolver::expand($paramsSchema, $document, $ctx, $load);
        $resultSchema = RefResolver::expand($resultSchema, $document, $ctx, $load);
        $messages = [
            ['name' => 'Params', 'direction' => 'request', 'fields' => SchemaMapper::fields($paramsSchema, $ctx, $resolveRef)],
            ['name' => 'Result', 'direction' => 'response', 'fields' => SchemaMapper::fields($resultSchema, $ctx, $resolveRef)],
        ];
        $sample = (string)$ctx->option('sample', '');
        if ($sample !== '') {
            $messages = $this->validateSample($sample, $wanted, $paramsSchema, $resultSchema, $messages, $ctx, $resolveRef);
        }
        return ['operation' => $wanted, 'messages' => $messages, 'meta' => []];
    }

    protected function validateSample(string $sample, string $method, array $paramsSchema, array $resultSchema, array $messages, ProtocolContext $ctx, callable $resolveRef): array
    {
        $data = json_decode($sample, true);
        if (!is_array($data)) {
            $messages[0]['sampleValidation'] = ['ok' => false, 'errors' => [['path' => '$', 'severity' => 'error', 'message' => 'JSON sample parse error: ' . json_last_error_msg()]], 'warnings' => []];
            return $messages;
        }
        $errors = [];
        if (($data['jsonrpc'] ?? '') !== '2.0') {
            $errors[] = ['path' => '$.jsonrpc', 'severity' => 'error', 'message' => 'jsonrpc must be 2.0'];
        }
        if (isset($data['method']) && $data['method'] !== $method) {
            $errors[] = ['path' => '$.method', 'severity' => 'error', 'message' => 'method mismatch'];
        }
        if (array_key_exists('params', $data)) {
            foreach (JsonSchemaValidator::validate($paramsSchema, $data['params'], $resolveRef) as $error) {
                $errors[] = [
                    'path' => '$' . (isset($error['path']) ? substr((string)$error['path'], 1) : ''),
                    'severity' => 'error',
                    'message' => isset($error['message']) ? (string)$error['message'] : 'invalid params',
                ];
            }
            $messages[0]['sampleValidation'] = ['ok' => $errors === [], 'errors' => $errors, 'warnings' => []];
        }
        if (array_key_exists('result', $data)) {
            $resultErrors = [];
            foreach (JsonSchemaValidator::validate($resultSchema, $data['result'], $resolveRef) as $error) {
                $resultErrors[] = [
                    'path' => '$' . (isset($error['path']) ? substr((string)$error['path'], 1) : ''),
                    'severity' => 'error',
                    'message' => isset($error['message']) ? (string)$error['message'] : 'invalid result',
                ];
            }
            $messages[1]['sampleValidation'] = ['ok' => $resultErrors === [], 'errors' => $resultErrors, 'warnings' => []];
        }
        return $messages;
    }
}
