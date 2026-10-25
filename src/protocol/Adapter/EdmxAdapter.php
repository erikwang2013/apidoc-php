<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\Adapter;

use DOMElement;
use DOMNode;
use erikwang2013\apidoc\protocol\AdapterInterface;
use erikwang2013\apidoc\protocol\ProtocolContext;
use erikwang2013\apidoc\protocol\ProtocolParseException;
use erikwang2013\apidoc\protocol\Support\JsonSchemaValidator;
use erikwang2013\apidoc\protocol\Support\SafeXml;

/**
 * OData $metadata(EDMX)适配器
 */
class EdmxAdapter implements AdapterInterface
{
    const TYPES = [
        'Edm.Int32' => 'integer', 'Edm.Int64' => 'integer', 'Edm.Int16' => 'integer', 'Edm.Byte' => 'integer',
        'Edm.String' => 'string', 'Edm.Boolean' => 'boolean',
        'Edm.Decimal' => 'number', 'Edm.Double' => 'number', 'Edm.Single' => 'number',
        'Edm.DateTimeOffset' => 'string', 'Edm.Guid' => 'string',
    ];

    public function format(): string
    {
        return 'edmx';
    }

    public function parse(ProtocolContext $ctx, string $content): array
    {
        if (!class_exists('DOMDocument')) {
            $ctx->error('ext-dom is required for EDMX');
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $document = SafeXml::load($content);
        $entities = [];
        $complexTypes = [];
        $entitySets = [];
        $functions = [];
        foreach ($this->descendants($document->documentElement, 'EntityType') as $entityType) {
            $name = SafeXml::attr($entityType, 'Name');
            $entities[$name] = $this->properties($entityType, $ctx);
        }
        foreach ($this->descendants($document->documentElement, 'ComplexType') as $complexType) {
            $name = SafeXml::attr($complexType, 'Name');
            $complexTypes[$name] = $this->properties($complexType, $ctx);
        }
        foreach ($this->descendants($document->documentElement, 'EntitySet') as $entitySet) {
            $entitySets[SafeXml::attr($entitySet, 'Name')] = $this->localName(SafeXml::attr($entitySet, 'EntityType'));
        }
        foreach ($this->descendants($document->documentElement, 'FunctionImport') as $function) {
            $name = SafeXml::attr($function, 'Name');
            $fields = [];
            foreach (SafeXml::all($function, 'Parameter') as $parameter) {
                $fields[] = [
                    'name' => SafeXml::attr($parameter, 'Name'),
                    'type' => $this->edmType(SafeXml::attr($parameter, 'Type')),
                    'require' => true,
                ];
            }
            $functions[$name] = $fields;
        }

        $candidates = array_merge(array_keys($entitySets), array_keys($functions));
        $wanted = (string)$ctx->option('operation', '');
        if ($wanted !== '' && !in_array($wanted, $candidates, true)) {
            $ctx->error('operation not found: ' . $wanted . '; candidates: ' . implode(', ', $candidates));
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        if ($wanted === '') {
            if (count($candidates) === 1) {
                $wanted = $candidates[0];
            } else {
                $ctx->error('operation required; candidates: ' . implode(', ', $candidates));
                return ['operation' => '', 'messages' => [], 'meta' => []];
            }
        }

        if (isset($entitySets[$wanted])) {
            $entityName = $entitySets[$wanted];
            $fields = $this->resolveFields(
                $entities[$entityName] ?? [],
                $entities,
                $complexTypes,
                $ctx,
                []
            );
            $messages = [['name' => $wanted, 'direction' => 'response', 'fields' => $fields]];
            $direction = 'response';
        } else {
            $fields = $functions[$wanted] ?? [];
            $messages = [['name' => $wanted, 'direction' => 'request', 'fields' => $fields]];
            $direction = 'request';
        }

        $sample = (string)$ctx->option('sample', '');
        if ($sample !== '' && $messages !== []) {
            $messages[0]['sampleValidation'] = $this->validateSample($sample, $messages[0]['fields'], $ctx);
        }
        return [
            'operation' => $wanted,
            'messages' => $messages,
            'meta' => ['direction' => $direction],
        ];
    }

    protected function properties(DOMElement $node, ProtocolContext $ctx): array
    {
        $fields = [];
        foreach (SafeXml::all($node, 'Property') as $property) {
            $fields[] = [
                'name' => SafeXml::attr($property, 'Name'),
                'type' => $this->edmType(SafeXml::attr($property, 'Type')),
                'require' => SafeXml::attr($property, 'Nullable') === 'false',
            ];
        }
        foreach (SafeXml::all($node, 'NavigationProperty') as $navigation) {
            $fields[] = [
                'name' => SafeXml::attr($navigation, 'Name'),
                'type' => 'object',
                'require' => false,
                'ref' => $this->localName(SafeXml::attr($navigation, 'Type')),
            ];
        }
        return $fields;
    }

    protected function resolveFields(array $fields, array $entities, array $complexTypes, ProtocolContext $ctx, array $visited): array
    {
        $result = [];
        foreach ($fields as $field) {
            $ref = (string)($field['ref'] ?? '');
            if ($ref !== '' && !in_array($ref, $visited, true)) {
                $visited[] = $ref;
                $target = $entities[$ref] ?? $complexTypes[$ref] ?? [];
                $field['children'] = $this->resolveFields($target, $entities, $complexTypes, $ctx, $visited);
            }
            $result[] = $field;
        }
        return $result;
    }

    protected function validateSample(string $sample, array $fields, ProtocolContext $ctx): array
    {
        $data = json_decode($sample, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'errors' => [['path' => '$', 'severity' => 'error', 'message' => 'JSON sample parse error: ' . json_last_error_msg()]], 'warnings' => []];
        }
        $schema = $this->schemaForFields($fields);
        $errors = JsonSchemaValidator::validate($schema, $data, static function (string $ref): ?array {
            return null;
        });
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

    protected function schemaForFields(array $fields): array
    {
        $properties = [];
        $required = [];
        foreach ($fields as $field) {
            $property = ['type' => $this->jsonType((string)($field['type'] ?? 'string'))];
            if (!empty($field['enum'])) {
                $property['enum'] = $field['enum'];
            }
            if (($field['type'] ?? '') === 'array') {
                $property['items'] = ['type' => $this->jsonType((string)($field['childrenType'] ?? 'string'))];
            }
            if (($field['type'] ?? '') === 'object') {
                $property = ['type' => 'object', 'properties' => []];
            }
            $properties[(string)$field['name']] = $property;
            if (!empty($field['require'])) {
                $required[] = (string)$field['name'];
            }
        }
        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    protected function jsonType(string $type): string
    {
        $map = ['integer' => 'integer', 'number' => 'number', 'boolean' => 'boolean', 'array' => 'array', 'object' => 'object', 'string' => 'string'];
        return $map[$type] ?? 'string';
    }

    protected function edmType(string $type): string
    {
        if (strpos($type, 'Collection(') === 0) {
            return 'array';
        }
        return self::TYPES[$type] ?? 'string';
    }

    protected function localName(string $qualified): string
    {
        $pos = strrpos($qualified, '.');
        return $pos === false ? $qualified : substr($qualified, $pos + 1);
    }

    /**
     * @return array<int,DOMElement>
     */
    protected function descendants(DOMNode $node, string $localName): array
    {
        $result = [];
        if (!$node instanceof DOMNode) {
            return $result;
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if ($child->localName === $localName) {
                    $result[] = $child;
                }
                $result = array_merge($result, $this->descendants($child, $localName));
            }
        }
        return $result;
    }
}
