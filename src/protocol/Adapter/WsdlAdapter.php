<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\Adapter;

use DOMElement;
use DOMNode;
use erikwang2013\apidoc\protocol\AdapterInterface;
use erikwang2013\apidoc\protocol\ProtocolContext;
use erikwang2013\apidoc\protocol\ProtocolParseException;
use erikwang2013\apidoc\protocol\Support\SafeXml;

/**
 * WSDL 1.1 + XSD 子集适配器
 */
class WsdlAdapter implements AdapterInterface
{
    const XSD_SCALARS = [
        'string' => 'string', 'normalizedString' => 'string', 'token' => 'string',
        'int' => 'integer', 'integer' => 'integer', 'long' => 'integer', 'short' => 'integer', 'byte' => 'integer',
        'unsignedInt' => 'integer', 'unsignedLong' => 'integer', 'unsignedShort' => 'integer',
        'decimal' => 'number', 'float' => 'number', 'double' => 'number',
        'boolean' => 'boolean', 'dateTime' => 'string', 'date' => 'string', 'time' => 'string',
        'base64Binary' => 'string', 'anyType' => 'string',
    ];

    public function format(): string
    {
        return 'wsdl';
    }

    public function parse(ProtocolContext $ctx, string $content): array
    {
        if (!class_exists('DOMDocument')) {
            $ctx->error('ext-dom is required for WSDL');
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        try {
            $document = SafeXml::load($content);
        } catch (ProtocolParseException $e) {
            throw $e;
        }
        $definitions = $document->documentElement;
        if (!$definitions instanceof DOMElement || $definitions->localName !== 'definitions') {
            $ctx->error('WSDL definitions element is missing');
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $elements = [];
        $complexTypes = [];
        $simpleTypes = [];
        $messages = [];
        $portTypes = [];
        $bindings = [];
        $services = [];

        $types = SafeXml::first($definitions, 'types');
        if ($types instanceof DOMElement) {
            foreach (SafeXml::all($types, 'schema') as $schema) {
                $this->parseSchema($schema, $ctx, $elements, $complexTypes, $simpleTypes);
            }
        }
        foreach (SafeXml::all($definitions, 'message') as $message) {
            $name = SafeXml::attr($message, 'name');
            $parts = [];
            foreach (SafeXml::all($message, 'part') as $part) {
                $parts[] = [
                    'name' => SafeXml::attr($part, 'name'),
                    'element' => $this->localName(SafeXml::attr($part, 'element')),
                    'type' => $this->localName(SafeXml::attr($part, 'type')),
                ];
            }
            $messages[$name] = $parts;
        }
        foreach (SafeXml::all($definitions, 'portType') as $portType) {
            $name = SafeXml::attr($portType, 'name');
            $operations = [];
            foreach (SafeXml::all($portType, 'operation') as $operation) {
                $input = SafeXml::first($operation, 'input');
                $output = SafeXml::first($operation, 'output');
                $operations[] = [
                    'name' => SafeXml::attr($operation, 'name'),
                    'input' => $input instanceof DOMElement ? $this->localName(SafeXml::attr($input, 'message')) : '',
                    'output' => $output instanceof DOMElement ? $this->localName(SafeXml::attr($output, 'message')) : '',
                ];
            }
            $portTypes[$name] = $operations;
        }
        foreach (SafeXml::all($definitions, 'binding') as $binding) {
            $bindings[SafeXml::attr($binding, 'name')] = $this->localName(SafeXml::attr($binding, 'type'));
        }
        foreach (SafeXml::all($definitions, 'service') as $service) {
            $ports = [];
            foreach (SafeXml::all($service, 'port') as $port) {
                $ports[] = [
                    'name' => SafeXml::attr($port, 'name'),
                    'binding' => $this->localName(SafeXml::attr($port, 'binding')),
                ];
            }
            $services[SafeXml::attr($service, 'name')] = $ports;
        }

        $candidates = $this->candidates($services, $bindings, $portTypes);
        $wanted = (string)$ctx->option('operation', '');
        $selected = $this->select($candidates, $wanted, $ctx);
        if ($selected === null) {
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $operation = $selected['operation'];
        $requestFields = $this->messageFields($operation['input'], $messages, $elements, $complexTypes, $simpleTypes, $ctx);
        $responseFields = $this->messageFields($operation['output'], $messages, $elements, $complexTypes, $simpleTypes, $ctx);
        $messagesOut = [
            ['name' => $operation['input'] !== '' ? $operation['input'] : 'Request', 'direction' => 'request', 'fields' => $requestFields],
            ['name' => $operation['output'] !== '' ? $operation['output'] : 'Response', 'direction' => 'response', 'fields' => $responseFields],
        ];
        $sample = (string)$ctx->option('sample', '');
        if ($sample !== '') {
            $messagesOut[0]['sampleValidation'] = $this->validateSample($sample, $operation, $ctx);
        }
        return [
            'operation' => $selected['label'],
            'messages' => $messagesOut,
            'meta' => ['service' => $selected['service'], 'port' => $selected['port']],
        ];
    }

    protected function parseSchema(DOMElement $schema, ProtocolContext $ctx, array &$elements, array &$complexTypes, array &$simpleTypes): void
    {
        foreach (['include', 'import'] as $tag) {
            foreach (SafeXml::all($schema, $tag) as $include) {
                $location = SafeXml::attr($include, 'schemaLocation');
                if ($location === '') {
                    continue;
                }
                $realpath = $ctx->resolve($location);
                if ($realpath === null) {
                    $ctx->warning('unable to resolve XSD ' . $tag . ': ' . $location);
                    continue;
                }
                if (!$ctx->push($realpath)) {
                    continue;
                }
                $content = $ctx->read();
                if ($content !== null) {
                    $included = SafeXml::load($content)->documentElement;
                    if ($included instanceof DOMElement) {
                        $this->parseSchema($included, $ctx, $elements, $complexTypes, $simpleTypes);
                    }
                }
                $ctx->pop();
            }
        }
        foreach (SafeXml::all($schema, 'element') as $element) {
            $name = SafeXml::attr($element, 'name');
            if ($name === '') {
                continue;
            }
            $type = $this->localName(SafeXml::attr($element, 'type'));
            $inline = SafeXml::first($element, 'complexType');
            $simple = SafeXml::first($element, 'simpleType');
            $elements[$name] = [
                'name' => $name,
                'type' => $type,
                'fields' => $inline instanceof DOMElement ? $this->parseComplexType($inline) : [],
                'enum' => $simple instanceof DOMElement ? $this->parseSimpleType($simple) : [],
            ];
        }
        foreach (SafeXml::all($schema, 'complexType') as $complexType) {
            $name = SafeXml::attr($complexType, 'name');
            if ($name !== '') {
                $complexTypes[$name] = $this->parseComplexType($complexType);
            }
        }
        foreach (SafeXml::all($schema, 'simpleType') as $simpleType) {
            $name = SafeXml::attr($simpleType, 'name');
            if ($name !== '') {
                $simpleTypes[$name] = $this->parseSimpleType($simpleType);
            }
        }
    }

    protected function parseComplexType(DOMElement $complexType): array
    {
        $fields = [];
        $container = SafeXml::first($complexType, 'sequence');
        if (!$container instanceof DOMElement) {
            $container = SafeXml::first($complexType, 'all');
        }
        if ($container instanceof DOMElement) {
            foreach (SafeXml::all($container, 'element') as $element) {
                $fields[] = $this->parseXsdElement($element);
            }
        }
        foreach (SafeXml::all($complexType, 'attribute') as $attribute) {
            $name = SafeXml::attr($attribute, 'name');
            if ($name !== '') {
                $fields[] = [
                    'name' => $name,
                    'type' => $this->xsdType($this->localName(SafeXml::attr($attribute, 'type'))),
                    'require' => SafeXml::attr($attribute, 'use') === 'required',
                ];
            }
        }
        return $fields;
    }

    protected function parseXsdElement(DOMElement $element): array
    {
        $name = SafeXml::attr($element, 'name');
        $type = $this->localName(SafeXml::attr($element, 'type'));
        $inline = SafeXml::first($element, 'complexType');
        $simple = SafeXml::first($element, 'simpleType');
        $min = SafeXml::attr($element, 'minOccurs');
        $max = SafeXml::attr($element, 'maxOccurs');
        $field = [
            'name' => $name,
            'type' => $inline instanceof DOMElement ? 'object' : $this->xsdType($type),
            'require' => $min !== '0',
        ];
        if ($inline instanceof DOMElement) {
            $field['children'] = $this->parseComplexType($inline);
        } elseif ($type !== '') {
            $field['typeName'] = $type;
        }
        if ($simple instanceof DOMElement) {
            $field['enum'] = $this->parseSimpleType($simple);
            $field['type'] = 'string';
        }
        if ($max === 'unbounded' || (ctype_digit($max) && (int)$max > 1)) {
            $field['repeated'] = true;
            $field['childrenType'] = $field['type'];
            $field['type'] = 'array';
        }
        return $field;
    }

    protected function parseSimpleType(DOMElement $simpleType): array
    {
        $values = [];
        foreach (SafeXml::all($simpleType, 'enumeration') as $enumeration) {
            $values[] = SafeXml::attr($enumeration, 'value');
        }
        return $values;
    }

    protected function messageFields(string $messageName, array $messages, array $elements, array $complexTypes, array $simpleTypes, ProtocolContext $ctx): array
    {
        if ($messageName === '' || !isset($messages[$messageName])) {
            return [];
        }
        $fields = [];
        foreach ($messages[$messageName] as $part) {
            if ($part['element'] !== '' && isset($elements[$part['element']])) {
                $element = $elements[$part['element']];
                $fields = array_merge($fields, $this->resolveFields($element['fields'], $elements, $complexTypes, $simpleTypes, $ctx, []));
            } elseif ($part['type'] !== '') {
                $fields[] = ['name' => $part['name'] !== '' ? $part['name'] : 'value', 'type' => $this->xsdType($part['type'])];
            }
        }
        return $fields;
    }

    protected function resolveFields(array $fields, array $elements, array $complexTypes, array $simpleTypes, ProtocolContext $ctx, array $visited): array
    {
        $result = [];
        foreach ($fields as $field) {
            $typeName = (string)($field['typeName'] ?? '');
            if ($typeName !== '' && isset($complexTypes[$typeName]) && !in_array($typeName, $visited, true)) {
                $visited[] = $typeName;
                $field['type'] = 'object';
                $field['children'] = $this->resolveFields($complexTypes[$typeName], $elements, $complexTypes, $simpleTypes, $ctx, $visited);
            } elseif ($typeName !== '' && isset($elements[$typeName])) {
                $field['type'] = 'object';
                $field['children'] = $this->resolveFields($elements[$typeName]['fields'], $elements, $complexTypes, $simpleTypes, $ctx, $visited);
            }
            if (!empty($field['children'])) {
                $field['children'] = $this->resolveFields($field['children'], $elements, $complexTypes, $simpleTypes, $ctx, $visited);
            }
            $result[] = $field;
        }
        return $result;
    }

    protected function candidates(array $services, array $bindings, array $portTypes): array
    {
        $candidates = [];
        foreach ($services as $serviceName => $ports) {
            foreach ($ports as $port) {
                $portType = $bindings[$port['binding']] ?? '';
                foreach ($portTypes[$portType] ?? [] as $operation) {
                    $candidates[] = [
                        'label' => $serviceName . '/' . $port['name'] . '/' . $operation['name'],
                        'service' => $serviceName,
                        'port' => $port['name'],
                        'portType' => $portType,
                        'operation' => $operation,
                    ];
                }
            }
        }
        if ($candidates === []) {
            foreach ($portTypes as $portTypeName => $operations) {
                foreach ($operations as $operation) {
                    $candidates[] = [
                        'label' => $portTypeName . '/' . $operation['name'],
                        'service' => '',
                        'port' => '',
                        'portType' => $portTypeName,
                        'operation' => $operation,
                    ];
                }
            }
        }
        return $candidates;
    }

    protected function select(array $candidates, string $wanted, ProtocolContext $ctx): ?array
    {
        if ($wanted !== '') {
            foreach ($candidates as $candidate) {
                $operationName = $candidate['operation']['name'];
                if ($candidate['label'] === $wanted
                    || $candidate['portType'] . '/' . $operationName === $wanted
                    || $operationName === $wanted
                ) {
                    return $candidate;
                }
            }
            $ctx->error('operation not found: ' . $wanted . '; candidates: ' . $this->candidatesLabel($candidates));
            return null;
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        $ctx->error('operation required; candidates: ' . $this->candidatesLabel($candidates));
        return null;
    }

    protected function candidatesLabel(array $candidates): string
    {
        return implode(', ', array_map(static function (array $candidate): string {
            return $candidate['label'];
        }, $candidates));
    }

    protected function xsdType(string $type): string
    {
        if ($type === '') {
            return 'string';
        }
        return self::XSD_SCALARS[$type] ?? 'object';
    }

    protected function localName(string $qualified): string
    {
        $pos = strrpos($qualified, ':');
        return $pos === false ? $qualified : substr($qualified, $pos + 1);
    }

    protected function validateSample(string $sample, array $operation, ProtocolContext $ctx): array
    {
        try {
            $document = SafeXml::load($sample);
        } catch (ProtocolParseException $e) {
            return ['ok' => false, 'errors' => [['path' => '$', 'severity' => 'error', 'message' => $e->getMessage()]], 'warnings' => []];
        }
        $errors = [];
        $root = $document->documentElement;
        if ($root instanceof DOMElement && $root->localName === 'Envelope') {
            $body = SafeXml::first($root, 'Body');
            $operationElement = $body instanceof DOMElement ? $body->firstElementChild : null;
            if ($operationElement instanceof DOMElement) {
                $expected = $operation['input'] !== '' ? $operation['input'] : '';
                if ($expected !== '' && $operationElement->localName !== $expected) {
                    $errors[] = ['path' => '$', 'severity' => 'error', 'message' => 'SOAP Body operation mismatch'];
                }
            } else {
                $errors[] = ['path' => '$', 'severity' => 'error', 'message' => 'SOAP Body is empty'];
            }
        }
        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => []];
    }
}
