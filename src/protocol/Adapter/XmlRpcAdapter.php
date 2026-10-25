<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\Adapter;

use DOMElement;
use erikwang2013\apidoc\protocol\AdapterInterface;
use erikwang2013\apidoc\protocol\ProtocolContext;
use erikwang2013\apidoc\protocol\ProtocolParseException;
use erikwang2013\apidoc\protocol\Support\SafeXml;

/**
 * XML-RPC 方法定义适配器
 */
class XmlRpcAdapter implements AdapterInterface
{
    const TYPES = [
        'int' => 'integer', 'i4' => 'integer', 'i8' => 'integer',
        'double' => 'number', 'boolean' => 'boolean', 'string' => 'string',
        'dateTime.iso8601' => 'string', 'base64' => 'string',
        'struct' => 'object', 'array' => 'array',
    ];

    public function format(): string
    {
        return 'xml-rpc';
    }

    public function parse(ProtocolContext $ctx, string $content): array
    {
        if (!class_exists('DOMDocument')) {
            $ctx->error('ext-dom is required for XML-RPC');
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $document = SafeXml::load($content);
        $methods = [];
        foreach ($this->descendants($document->documentElement, 'method') as $method) {
            $name = SafeXml::attr($method, 'name');
            $params = [];
            foreach ($this->descendants($method, 'param') as $param) {
                $type = SafeXml::attr($param, 'type');
                $field = [
                    'name' => SafeXml::attr($param, 'name') !== '' ? SafeXml::attr($param, 'name') : 'param' . (count($params) + 1),
                    'type' => $this->typeOf($type),
                    'require' => true,
                ];
                if ($type === 'struct') {
                    $children = [];
                    foreach (SafeXml::all($param, 'member') as $member) {
                        $children[] = [
                            'name' => SafeXml::attr($member, 'name'),
                            'type' => $this->typeOf(SafeXml::attr($member, 'type')),
                        ];
                    }
                    $field['children'] = $children;
                }
                $params[] = $field;
            }
            $result = SafeXml::first($method, 'result');
            $resultType = $result instanceof DOMElement ? $this->typeOf(SafeXml::attr($result, 'type')) : 'string';
            $methods[$name] = ['params' => $params, 'result' => $resultType];
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
        if (!isset($methods[$wanted])) {
            $ctx->error('operation not found: ' . $wanted . '; candidates: ' . implode(', ', array_keys($methods)));
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $messages = [
            ['name' => 'Params', 'direction' => 'request', 'fields' => $methods[$wanted]['params']],
            ['name' => 'Result', 'direction' => 'response', 'fields' => [['name' => 'result', 'type' => $methods[$wanted]['result'], 'require' => true]]],
        ];
        $sample = (string)$ctx->option('sample', '');
        if ($sample !== '') {
            $messages[0]['sampleValidation'] = $this->validateSample($sample, $wanted, $methods[$wanted]['params']);
        }
        return ['operation' => $wanted, 'messages' => $messages, 'meta' => []];
    }

    protected function validateSample(string $sample, string $method, array $params): array
    {
        try {
            $document = SafeXml::load($sample);
        } catch (ProtocolParseException $e) {
            return ['ok' => false, 'errors' => [['path' => '$', 'severity' => 'error', 'message' => $e->getMessage()]], 'warnings' => []];
        }
        $root = $document->documentElement;
        if (!$root instanceof DOMElement || !in_array($root->localName, ['methodCall', 'methodResponse'], true)) {
            return ['ok' => false, 'errors' => [['path' => '$', 'severity' => 'error', 'message' => 'root must be methodCall or methodResponse']], 'warnings' => []];
        }
        if ($root->localName === 'methodResponse') {
            $paramsNode = SafeXml::first($root, 'params');
            $faultNode = SafeXml::first($root, 'fault');
            if (!$paramsNode instanceof DOMElement && !$faultNode instanceof DOMElement) {
                return ['ok' => false, 'errors' => [['path' => '$', 'severity' => 'error', 'message' => 'methodResponse requires params or fault']], 'warnings' => []];
            }
            return ['ok' => true, 'errors' => [], 'warnings' => []];
        }
        $nameNode = SafeXml::first($root, 'methodName');
        if (!$nameNode instanceof DOMElement || trim($nameNode->textContent) !== $method) {
            return ['ok' => false, 'errors' => [['path' => '$', 'severity' => 'error', 'message' => 'methodName mismatch']], 'warnings' => []];
        }
        $paramsNode = SafeXml::first($root, 'params');
        $actual = $paramsNode instanceof DOMElement ? count(SafeXml::all($paramsNode, 'param')) : 0;
        if ($actual !== count($params)) {
            return ['ok' => false, 'errors' => [['path' => '$', 'severity' => 'error', 'message' => 'param count mismatch']], 'warnings' => []];
        }
        return ['ok' => true, 'errors' => [], 'warnings' => []];
    }

    protected function typeOf(string $type): string
    {
        return self::TYPES[$type] ?? 'string';
    }

    /**
     * @return array<int,DOMElement>
     */
    protected function descendants(\DOMNode $node, string $localName): array
    {
        $result = [];
        if (!$node instanceof \DOMNode) {
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
