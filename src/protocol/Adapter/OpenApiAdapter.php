<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\Adapter;

use erikwang2013\apidoc\protocol\AdapterInterface;
use erikwang2013\apidoc\protocol\ProtocolContext;
use erikwang2013\apidoc\protocol\ProtocolParseException;
use erikwang2013\apidoc\protocol\Support\JsonSchemaValidator;
use erikwang2013\apidoc\protocol\Support\RefResolver;
use erikwang2013\apidoc\protocol\Support\SchemaMapper;
use erikwang2013\apidoc\protocol\Support\Yaml;

/**
 * OpenAPI 3.x / Swagger 2.x 适配器(JSON/YAML)
 */
class OpenApiAdapter implements AdapterInterface
{
    const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

    public function format(): string
    {
        return 'openapi';
    }

    public function parse(ProtocolContext $ctx, string $content): array
    {
        $document = $this->decode($content);
        $version = isset($document['openapi']) ? $this->versionString($document['openapi']) : '';
        $swagger = isset($document['swagger']) ? $this->versionString($document['swagger']) : '';
        if ($version === '' && $swagger === '') {
            $ctx->error('OpenAPI version is missing');
        } elseif ($version !== '' && strpos($version, '3.') !== 0) {
            $ctx->unsupported('unsupported OpenAPI version: ' . $version);
        } elseif ($swagger !== '' && strpos($swagger, '2.') !== 0) {
            $ctx->unsupported('unsupported Swagger version: ' . $swagger);
        }
        $paths = isset($document['paths']) && is_array($document['paths']) ? $document['paths'] : [];
        if ($paths === []) {
            $ctx->error('OpenAPI paths are missing');
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $load = $this->loader();
        $resolveRef = function (string $ref, ?array &$resolvedRoot = null, ?string &$resolvedFile = null) use ($document, $ctx, $load): ?array {
            return RefResolver::resolve($ref, $document, $ctx, $load, $resolvedRoot, $resolvedFile);
        };
        $operations = $this->operations($paths, $document, $ctx, $load);
        if ($operations === []) {
            $ctx->error('OpenAPI operations are missing');
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $selected = $this->select($operations, (string)$ctx->option('operation', ''), $ctx);
        $operation = $selected['operation'];
        $method = $selected['method'];
        $path = $selected['path'];
        // pathItem 可能来自外部文档, 其内部的本地 $ref 必须按该文档解析
        $operationRoot = isset($selected['root']) && is_array($selected['root']) ? $selected['root'] : $document;
        $operationFile = isset($selected['file']) && is_string($selected['file']) ? $selected['file'] : $ctx->file();

        $requestFields = [];
        $parameters = [];
        if (isset($selected['pathItem']['parameters']) && is_array($selected['pathItem']['parameters'])) {
            $parameters = array_merge($parameters, $selected['pathItem']['parameters']);
        }
        if (isset($operation['parameters']) && is_array($operation['parameters'])) {
            $parameters = array_merge($parameters, $operation['parameters']);
        }
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            $parameterRoot = $operationRoot;
            $parameterFile = $operationFile;
            if (isset($parameter['$ref']) && is_string($parameter['$ref'])) {
                $resolved = $resolveRef($parameter['$ref'], $parameterRoot, $parameterFile);
                if (!is_array($resolved)) {
                    continue;
                }
                $parameter = $resolved;
            }
            $fields = $this->parameterFields($parameter, $ctx, $parameterRoot, $parameterFile, $load, $resolveRef);
            $requestFields = array_merge($requestFields, $fields);
        }
        if (isset($operation['requestBody'])) {
            $requestBody = $operation['requestBody'];
            $bodyRoot = $operationRoot;
            $bodyFile = $operationFile;
            if (is_array($requestBody) && isset($requestBody['$ref']) && is_string($requestBody['$ref'])) {
                $requestBody = $resolveRef($requestBody['$ref'], $bodyRoot, $bodyFile);
            }
            if (is_array($requestBody)) {
                $requestFields = array_merge($requestFields, $this->bodyFields($requestBody, $ctx, $bodyRoot, $bodyFile, $load, $resolveRef));
            }
        }

        $responses = isset($operation['responses']) && is_array($operation['responses']) ? $operation['responses'] : [];
        $status = '';
        $responseSchema = [];
        foreach (array_keys($responses) as $code) {
            $code = (string)$code;
            if (strpos($code, '2') !== 0) {
                continue;
            }
            $response = $responses[$code];
            $responseRoot = $operationRoot;
            $responseFile = $operationFile;
            if (is_array($response) && isset($response['$ref']) && is_string($response['$ref'])) {
                $response = $resolveRef($response['$ref'], $responseRoot, $responseFile);
            }
            if (is_array($response)) {
                $status = $code;
                $responseSchema = $this->responseSchema($response);
                break;
            }
        }
        if ($responseSchema !== []) {
            $responseSchema = RefResolver::expand($responseSchema, $responseRoot ?? $operationRoot, $ctx, $load, $responseFile ?? $operationFile);
        }
        $responseFields = SchemaMapper::fields($responseSchema, $ctx, $resolveRef);
        $messages = [
            ['name' => 'Request', 'direction' => 'request', 'fields' => $requestFields],
            ['name' => 'Response' . ($status !== '' ? ' ' . $status : ''), 'direction' => 'response', 'fields' => $responseFields],
        ];

        $sample = (string)$ctx->option('sample', '');
        if ($sample !== '') {
            $target = $ctx->option('direction', 'response') === 'request' ? 0 : 1;
            $schema = $target === 1 ? $responseSchema : [];
            $messages[$target]['sampleValidation'] = $this->validateSample($sample, $schema, $messages[$target]['fields'], $ctx, $resolveRef);
        }

        return [
            'operation' => $selected['operationId'] !== '' ? $selected['operationId'] : strtoupper($method) . ' ' . $path,
            'messages' => $messages,
            'meta' => [],
        ];
    }

    /**
     * 版本值转字符串: YAML/JSON 的 2.0 会变成 float, (string) 会丢掉 ".0"
     * @param mixed $value
     * @return string
     */
    protected function versionString($value): string
    {
        return is_float($value) ? var_export($value, true) : (string)$value;
    }

    protected function decode(string $content): array
    {
        $trimmed = ltrim($content);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $document = json_decode($content, true);
            if (!is_array($document)) {
                throw new ProtocolParseException('OpenAPI JSON parse error: ' . json_last_error_msg(), 0, 0);
            }
            return $document;
        }
        return Yaml::parse($content);
    }

    protected function loader(): callable
    {
        return function (string $content): ?array {
            $trimmed = ltrim($content);
            if ($trimmed === '') {
                return null;
            }
            if ($trimmed[0] === '{' || $trimmed[0] === '[') {
                $document = json_decode($content, true);
                return is_array($document) ? $document : null;
            }
            try {
                return Yaml::parse($content);
            } catch (\Throwable $e) {
                return null;
            }
        };
    }

    /**
     * @return array<int,array{path:string,method:string,operationId:string,operation:array,pathItem:array}>
     */
    protected function operations(array $paths, array $document, ProtocolContext $ctx, callable $load): array
    {
        $result = [];
        $resolveRef = function (string $ref, ?array &$resolvedRoot = null, ?string &$resolvedFile = null) use ($document, $ctx, $load): ?array {
            return RefResolver::resolve($ref, $document, $ctx, $load, $resolvedRoot, $resolvedFile);
        };
        foreach ($paths as $path => $pathItem) {
            if (!is_array($pathItem)) {
                continue;
            }
            $pathItemRoot = $document;
            $pathItemFile = $ctx->file();
            if (isset($pathItem['$ref']) && is_string($pathItem['$ref'])) {
                $resolved = $resolveRef($pathItem['$ref'], $pathItemRoot, $pathItemFile);
                if (is_array($resolved)) {
                    $pathItem = $resolved;
                }
            }
            foreach (self::METHODS as $method) {
                if (!isset($pathItem[$method]) || !is_array($pathItem[$method])) {
                    continue;
                }
                $operation = $pathItem[$method];
                $result[] = [
                    'path' => (string)$path,
                    'method' => $method,
                    'operationId' => isset($operation['operationId']) ? (string)$operation['operationId'] : '',
                    'operation' => $operation,
                    'pathItem' => $pathItem,
                    'root' => $pathItemRoot,
                    'file' => $pathItemFile,
                ];
            }
        }
        return $result;
    }

    protected function select(array $operations, string $wanted, ProtocolContext $ctx): array
    {
        if ($wanted !== '') {
            foreach ($operations as $item) {
                if ($item['operationId'] !== '' && $item['operationId'] === $wanted) {
                    return $item;
                }
                if (strcasecmp(strtoupper($item['method']) . ' ' . $item['path'], $wanted) === 0) {
                    return $item;
                }
            }
            $ctx->error('operation not found: ' . $wanted . '; candidates: ' . $this->candidates($operations));
            return $operations[0];
        }
        if (count($operations) === 1) {
            return $operations[0];
        }
        $ctx->error('operation required; candidates: ' . $this->candidates($operations));
        return $operations[0];
    }

    protected function candidates(array $operations): string
    {
        $names = [];
        foreach ($operations as $item) {
            $names[] = $item['operationId'] !== '' ? $item['operationId'] : strtoupper($item['method']) . ' ' . $item['path'];
        }
        return implode(', ', $names);
    }

    protected function parameterFields(array $parameter, ProtocolContext $ctx, array $root, ?string $file, callable $load, callable $resolveRef): array
    {
        $name = isset($parameter['name']) ? (string)$parameter['name'] : '';
        $in = isset($parameter['in']) ? (string)$parameter['in'] : 'query';
        if ($name === '') {
            return [];
        }
        $schema = isset($parameter['schema']) && is_array($parameter['schema']) ? $parameter['schema'] : $parameter;
        $schema = RefResolver::expand($schema, $root, $ctx, $load, $file);
        $field = [
            'name' => $name,
            'type' => SchemaMapper::typeOf($schema),
            'require' => !empty($parameter['required']),
            'desc' => isset($parameter['description']) ? (string)$parameter['description'] : '',
            'in' => $in,
        ];
        if ($in === 'body') {
            $children = SchemaMapper::fields($schema, $ctx, $resolveRef);
            return $children !== [] ? $children : [$field];
        }
        $children = SchemaMapper::fields($schema, $ctx, $resolveRef);
        if ($children !== []) {
            $field['children'] = $children;
        }
        return [$field];
    }

    protected function bodyFields(array $requestBody, ProtocolContext $ctx, array $root, ?string $file, callable $load, callable $resolveRef): array
    {
        $content = isset($requestBody['content']) && is_array($requestBody['content']) ? $requestBody['content'] : [];
        $schema = [];
        foreach (['application/json', '*/*', 'application/*'] as $type) {
            if (isset($content[$type]['schema']) && is_array($content[$type]['schema'])) {
                $schema = $content[$type]['schema'];
                break;
            }
        }
        if ($schema === []) {
            return [];
        }
        $schema = RefResolver::expand($schema, $root, $ctx, $load, $file);
        $fields = SchemaMapper::fields($schema, $ctx, $resolveRef);
        foreach ($fields as &$field) {
            if (!isset($field['in'])) {
                $field['in'] = 'body';
            }
        }
        unset($field);
        return $fields;
    }

    protected function responseSchema(array $response): array
    {
        if (isset($response['content']) && is_array($response['content'])) {
            foreach (['application/json', '*/*', 'application/*'] as $type) {
                if (isset($response['content'][$type]['schema']) && is_array($response['content'][$type]['schema'])) {
                    return $response['content'][$type]['schema'];
                }
            }
        }
        return isset($response['schema']) && is_array($response['schema']) ? $response['schema'] : [];
    }

    protected function validateSample(string $sample, array $schema, array $fields, ProtocolContext $ctx, callable $resolveRef): array
    {
        $data = json_decode($sample, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'ok' => false,
                'errors' => [['path' => '$', 'severity' => 'error', 'message' => 'JSON sample parse error: ' . json_last_error_msg()]],
                'warnings' => [],
            ];
        }
        if ($schema !== []) {
            $errors = JsonSchemaValidator::validate($schema, $data, $resolveRef);
            $result = [];
            foreach ($errors as $error) {
                $result[] = [
                    'path' => isset($error['path']) ? (string)$error['path'] : '$',
                    'severity' => 'error',
                    'message' => isset($error['message']) ? (string)$error['message'] : 'invalid value',
                ];
            }
            return ['ok' => $result === [], 'errors' => $result, 'warnings' => []];
        }
        $errors = [];
        foreach ($fields as $field) {
            if (!empty($field['require']) && is_array($data) && !array_key_exists((string)$field['name'], $data)) {
                $errors[] = ['path' => '$.' . $field['name'], 'severity' => 'error', 'message' => 'required field missing'];
            }
        }
        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => []];
    }
}
