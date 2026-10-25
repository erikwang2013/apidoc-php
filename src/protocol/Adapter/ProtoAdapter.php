<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\Adapter;

use erikwang2013\apidoc\protocol\AdapterInterface;
use erikwang2013\apidoc\protocol\ProtocolContext;
use erikwang2013\apidoc\protocol\Support\TokenStream;

/**
 * Protocol Buffers IDL 适配器(proto2/proto3 子集)
 */
class ProtoAdapter implements AdapterInterface
{
    const SCALARS = [
        'double' => 'number', 'float' => 'number',
        'int32' => 'integer', 'int64' => 'integer',
        'uint32' => 'integer', 'uint64' => 'integer',
        'sint32' => 'integer', 'sint64' => 'integer',
        'fixed32' => 'integer', 'fixed64' => 'integer',
        'sfixed32' => 'integer', 'sfixed64' => 'integer',
        'bool' => 'boolean', 'string' => 'string', 'bytes' => 'string',
    ];

    public function format(): string
    {
        return 'proto';
    }

    public function parse(ProtocolContext $ctx, string $content): array
    {
        $symbols = $this->parseDocument($ctx, $content);
        $package = (string)($symbols['package'] ?? '');
        $rpcs = [];
        foreach ($symbols['services'] ?? [] as $serviceName => $service) {
            foreach ($service['rpcs'] as $rpc) {
                $rpcs[] = [
                    'service' => (string)$serviceName,
                    'package' => $service['package'],
                    'rpc' => $rpc,
                    'operation' => ($service['package'] !== '' ? $service['package'] . '.' : '') . $serviceName . '/' . $rpc['name'],
                ];
            }
        }
        $wanted = (string)$ctx->option('operation', '');
        $selected = $this->select($rpcs, $wanted, $ctx);
        if ($selected === null) {
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $rpc = $selected['rpc'];
        $request = $this->messageForType($rpc['request'], $symbols, $ctx, []);
        $response = $this->messageForType($rpc['response'], $symbols, $ctx, []);
        $messages = [
            ['name' => $rpc['request'], 'direction' => 'request', 'fields' => $request, 'clientStream' => $rpc['client_stream']],
            ['name' => $rpc['response'], 'direction' => 'response', 'fields' => $response, 'serverStream' => $rpc['server_stream']],
        ];
        if ((string)$ctx->option('sample', '') !== '') {
            $ctx->warning('protobuf sample validation is planned for P3');
        }
        return [
            'operation' => $selected['operation'],
            'messages' => $messages,
            'meta' => ['package' => $package],
        ];
    }

    protected function parseDocument(ProtocolContext $ctx, string $content): array
    {
        $symbols = ['messages' => [], 'enums' => [], 'services' => [], 'package' => ''];
        $ts = new TokenStream($content);
        while (!$ts->eof()) {
            $line = $ts->line();
            $column = $ts->column();
            $keyword = $ts->identifier();
            switch ($keyword) {
                case 'syntax':
                    $ts->expect('=');
                    $ts->string();
                    $ts->expect(';');
                    break;
                case 'package':
                    $symbols['package'] = $ts->identifier();
                    $ts->expect(';');
                    break;
                case 'import':
                    $importLine = $ts->line();
                    $next = $ts->peek();
                    if ($next === 'p' || $next === 'w') {
                        $ts->identifier();
                    }
                    $file = $ts->string();
                    $ts->expect(';');
                    $this->import($ctx, $symbols, $file, $importLine);
                    break;
                case 'option':
                    $this->skipToSemicolon($ts);
                    break;
                case 'message':
                    $this->parseMessage($ts, $symbols, $ctx);
                    break;
                case 'enum':
                    $this->parseEnum($ts, $symbols);
                    break;
                case 'service':
                    $this->parseService($ts, $symbols, $ctx, (string)($symbols['package'] ?? ''));
                    break;
                case 'extend':
                    $ctx->unsupported('unsupported construct: extend', $line, $column);
                    $this->skipDefinition($ts);
                    break;
                case 'group':
                    $ctx->unsupported('unsupported construct: group', $line, $column);
                    $this->skipDefinition($ts);
                    break;
                case 'edition':
                    $ctx->unsupported('unsupported construct: edition', $line, $column);
                    $this->skipDefinition($ts);
                    break;
                case 'reserved':
                    $this->skipToSemicolon($ts);
                    break;
                default:
                    $ctx->warning('unsupported proto definition: ' . $keyword);
                    $this->skipDefinition($ts);
                    break;
            }
        }
        $this->aliasSymbols($symbols);
        return $symbols;
    }

    protected function import(ProtocolContext $ctx, array &$symbols, string $file, int $line): void
    {
        $realpath = $ctx->resolve($file);
        if ($realpath === null) {
            $ctx->error('unable to resolve import: ' . $file, $line, 0);
            return;
        }
        if (!$ctx->push($realpath)) {
            return;
        }
        $content = $ctx->read();
        if ($content !== null) {
            $imported = $this->parseDocument($ctx, $content);
            $symbols['messages'] = array_replace($symbols['messages'], $imported['messages']);
            $symbols['enums'] = array_replace($symbols['enums'], $imported['enums']);
            $symbols['services'] = array_replace($symbols['services'], $imported['services']);
        }
        $ctx->pop();
    }

    protected function aliasSymbols(array &$symbols): void
    {
        $package = (string)($symbols['package'] ?? '');
        if ($package === '') {
            return;
        }
        foreach (['messages', 'enums', 'services'] as $kind) {
            foreach ($symbols[$kind] as $name => $value) {
                $symbols[$kind][$package . '.' . $name] = $value;
            }
        }
    }

    protected function parseMessage(TokenStream $ts, array &$symbols, ProtocolContext $ctx): void
    {
        $name = $ts->identifier();
        $ts->expect('{');
        $fields = [];
        while (!$ts->eof() && $ts->peek() !== '}') {
            $line = $ts->line();
            $column = $ts->column();
            $word = $ts->peek();
            if (ctype_alpha($word) || $word === '_') {
                $save = null;
                // Peek a full identifier without consuming irreversibly is not available;
                // consume it and branch. Type names are only lost for unknown constructs.
                $token = $ts->identifier();
                if ($token === 'message') {
                    $this->parseMessage($ts, $symbols, $ctx);
                    continue;
                }
                if ($token === 'enum') {
                    $this->parseEnum($ts, $symbols);
                    continue;
                }
                if ($token === 'oneof') {
                    $oneofName = $ts->identifier();
                    $ts->expect('{');
                    while (!$ts->eof() && $ts->peek() !== '}') {
                        $field = $this->parseField($ts, $ctx, true, $oneofName);
                        if ($field !== null) {
                            $fields[] = $field;
                        }
                    }
                    $ts->expect('}');
                    continue;
                }
                if (in_array($token, ['option', 'reserved', 'extensions'], true)) {
                    $this->skipToSemicolon($ts);
                    continue;
                }
                if (in_array($token, ['repeated', 'optional', 'required'], true)) {
                    $field = $this->parseFieldAfterLabel($ts, $ctx, '', false, '', $token);
                } elseif ($token === 'map') {
                    $field = $this->parseFieldAfterLabel($ts, $ctx, 'map', false, '');
                } else {
                    $field = $this->parseFieldAfterLabel($ts, $ctx, $token, false, '');
                }
                if ($field !== null) {
                    $fields[] = $field;
                }
                continue;
            }
            $ts->next();
        }
        $ts->expect('}');
        $symbols['messages'][$name] = ['name' => $name, 'fields' => $fields];
    }

    protected function parseField(TokenStream $ts, ProtocolContext $ctx, bool $inOneof = false, string $oneof = ''): ?array
    {
        $label = '';
        $peek = $ts->peek();
        if (ctype_alpha($peek) || $peek === '_') {
            $word = $ts->identifier();
            if (in_array($word, ['repeated', 'optional', 'required'], true)) {
                $label = $word;
            } else {
                return $this->parseFieldAfterLabel($ts, $ctx, $word, $inOneof, $oneof);
            }
        }
        return $this->parseFieldAfterLabel($ts, $ctx, '', $inOneof, $oneof, $label);
    }

    protected function parseFieldAfterLabel(TokenStream $ts, ProtocolContext $ctx, string $firstType, bool $inOneof, string $oneof, string $label = ''): ?array
    {
        $map = false;
        $mapKey = '';
        $mapValue = '';
        if ($firstType === 'map') {
            $map = true;
            $ts->expect('<');
            $mapKey = $ts->identifier();
            $ts->expect(',');
            $mapValue = $ts->identifier();
            $ts->expect('>');
            $type = 'map';
        } elseif ($firstType !== '') {
            $type = $firstType;
        } else {
            $type = $ts->identifier();
        }
        $name = $ts->identifier();
        $fieldNumber = 0;
        if ($ts->consume('=') !== null) {
            $fieldNumber = (int)$ts->number();
        }
        if ($ts->consume('[') !== null) {
            $this->skipBalanced($ts, '[', ']');
        }
        $ts->expect(';');
        $field = [
            'name' => $name,
            'type' => $this->typeFor($type),
            'require' => $label === 'required',
            'fieldNumber' => $fieldNumber,
        ];
        if (!isset(self::SCALARS[$type]) && $type !== 'map') {
            $field['typeName'] = $type;
        }
        if ($type === 'bytes') {
            $field['format'] = 'bytes';
        }
        if ($label === 'repeated') {
            $field['repeated'] = true;
            $field['childrenType'] = $this->typeFor($type);
            $field['type'] = 'array';
        }
        if ($map) {
            $field['type'] = 'object';
            $field['map'] = true;
            $field['children'] = [
                ['name' => 'key', 'type' => $this->typeFor($mapKey), 'typeName' => $mapKey],
                ['name' => 'value', 'type' => $this->typeFor($mapValue), 'typeName' => $mapValue],
            ];
        }
        if ($inOneof) {
            $field['oneof'] = $oneof;
        }
        return $field;
    }

    protected function typeFor(string $type): string
    {
        return self::SCALARS[$type] ?? 'object';
    }

    protected function parseEnum(TokenStream $ts, array &$symbols): void
    {
        $name = $ts->identifier();
        $values = [];
        $ts->expect('{');
        while (!$ts->eof() && $ts->peek() !== '}') {
            if ($ts->consume(';') !== null) {
                continue;
            }
            $value = $ts->identifier();
            if ($ts->consume('=') !== null) {
                $ts->number();
                if ($ts->consume('[') !== null) {
                    $this->skipBalanced($ts, '[', ']');
                }
            }
            $ts->consume(';');
            $values[] = $value;
        }
        $ts->expect('}');
        $symbols['enums'][$name] = $values;
    }

    protected function parseService(TokenStream $ts, array &$symbols, ProtocolContext $ctx, string $package = ''): void
    {
        $name = $ts->identifier();
        $rpcs = [];
        $ts->expect('{');
        while (!$ts->eof() && $ts->peek() !== '}') {
            $line = $ts->line();
            $column = $ts->column();
            $keyword = $ts->identifier();
            if ($keyword === 'option') {
                $this->skipToSemicolon($ts);
                continue;
            }
            if ($keyword !== 'rpc') {
                $ctx->warning('unsupported service member: ' . $keyword, $line, $column);
                $this->skipDefinition($ts);
                continue;
            }
            $rpcName = $ts->identifier();
            $ts->expect('(');
            $clientStream = false;
            if ($ts->peekIdentifier() === 'stream') {
                $ts->identifier();
                $clientStream = true;
            }
            $request = $ts->identifier();
            $ts->expect(')');
            $returns = $ts->identifier();
            if ($returns !== 'returns') {
                $ctx->error('expected returns', $line, $column);
                $this->skipDefinition($ts);
                continue;
            }
            $ts->expect('(');
            $serverStream = false;
            if ($ts->peekIdentifier() === 'stream') {
                $ts->identifier();
                $serverStream = true;
            }
            $response = $ts->identifier();
            $ts->expect(')');
            if ($ts->consume('{') !== null) {
                $this->skipBalanced($ts, '{', '}');
            }
            $ts->consume(';');
            $rpcs[] = [
                'name' => $rpcName,
                'request' => $request,
                'response' => $response,
                'client_stream' => $clientStream,
                'server_stream' => $serverStream,
            ];
        }
        $ts->expect('}');
        $symbols['services'][$name] = ['name' => $name, 'package' => $package, 'rpcs' => $rpcs];
    }

    protected function select(array $rpcs, string $wanted, ProtocolContext $ctx): ?array
    {
        // Fill service package from the symbol table is not available here; use empty package for simple names.
        if ($wanted !== '') {
            foreach ($rpcs as $item) {
                if ($item['operation'] === $wanted || $item['service'] . '/' . $item['rpc']['name'] === $wanted) {
                    return $item;
                }
            }
            $ctx->error('operation not found: ' . $wanted . '; candidates: ' . $this->candidates($rpcs));
            return null;
        }
        if (count($rpcs) === 1) {
            return $rpcs[0];
        }
        $ctx->error('operation required; candidates: ' . $this->candidates($rpcs));
        return null;
    }

    protected function candidates(array $rpcs): string
    {
        $names = [];
        foreach ($rpcs as $item) {
            $names[] = $item['operation'];
        }
        return implode(', ', $names);
    }

    protected function messageForType(string $type, array $symbols, ProtocolContext $ctx, array $visited): array
    {
        $message = $this->findMessage($type, $symbols);
        if ($message === null) {
            $enum = $this->findEnum($type, $symbols);
            if ($enum !== null) {
                return [['name' => 'value', 'type' => 'string', 'enum' => $enum]];
            }
            $ctx->warning('unresolved proto type: ' . $type);
            return [];
        }
        if (in_array($type, $visited, true)) {
            $ctx->warning('proto message cycle detected: ' . $type);
            return [];
        }
        $visited[] = $type;
        $fields = [];
        foreach ($message['fields'] as $field) {
            $fields[] = $this->mapField($field, $symbols, $ctx, $visited);
        }
        return $fields;
    }

    protected function mapField(array $field, array $symbols, ProtocolContext $ctx, array $visited): array
    {
        $result = [
            'name' => $field['name'],
            'type' => $field['type'],
            'require' => !empty($field['require']),
            'fieldNumber' => (int)$field['fieldNumber'],
        ];
        if (!empty($field['oneof'])) {
            $result['oneof'] = $field['oneof'];
        }
        if (!empty($field['repeated'])) {
            $result['repeated'] = true;
            $result['type'] = 'array';
        }
        if (!empty($field['map'])) {
            $result['map'] = true;
            $result['type'] = 'object';
            $result['children'] = $field['children'];
            return $result;
        }
        $type = (string)$field['type'];
        $typeName = (string)($field['typeName'] ?? '');
        $repeated = !empty($field['repeated']);
        if (!empty($field['map'])) {
            $children = [];
            foreach ($field['children'] as $child) {
                $child['type'] = $this->typeFor((string)($child['typeName'] ?? ''));
                $childName = (string)($child['typeName'] ?? '');
                $message = $this->findMessage($childName, $symbols);
                $enum = $this->findEnum($childName, $symbols);
                if ($message !== null) {
                    $child['children'] = $this->messageForType($childName, $symbols, $ctx, $visited);
                } elseif ($enum !== null) {
                    $child['type'] = 'string';
                    $child['enum'] = $enum;
                }
                $children[] = $child;
            }
            $result['children'] = $children;
            return $result;
        }
        if ($typeName !== '') {
            $message = $this->findMessage($typeName, $symbols);
            if ($message !== null) {
                $children = $this->messageForType($typeName, $symbols, $ctx, $visited);
                if ($repeated) {
                    $result['children'] = $children;
                    $result['childrenType'] = 'object';
                } else {
                    $result['type'] = 'object';
                    $result['ref'] = $typeName;
                    $result['children'] = $children;
                }
                return $result;
            }
            $enum = $this->findEnum($typeName, $symbols);
            if ($enum !== null) {
                $result['type'] = 'string';
                $result['enum'] = $enum;
                return $result;
            }
            $ctx->warning('unresolved proto type: ' . $typeName);
            $result['type'] = 'object';
            $result['ref'] = $typeName;
            return $result;
        }
        if ($repeated) {
            $result['childrenType'] = $field['childrenType'] ?? 'string';
        }
        return $result;
    }

    protected function findMessage(string $type, array $symbols): ?array
    {
        $candidates = [$type, $this->lastSegment($type)];
        foreach ($candidates as $candidate) {
            if (isset($symbols['messages'][$candidate])) {
                return $symbols['messages'][$candidate];
            }
        }
        return null;
    }

    protected function findEnum(string $type, array $symbols): ?array
    {
        foreach ([$type, $this->lastSegment($type)] as $candidate) {
            if (isset($symbols['enums'][$candidate])) {
                return $symbols['enums'][$candidate];
            }
        }
        return null;
    }

    protected function lastSegment(string $type): string
    {
        $pos = strrpos($type, '.');
        return $pos === false ? $type : substr($type, $pos + 1);
    }

    protected function skipToSemicolon(TokenStream $ts): void
    {
        while (!$ts->eof()) {
            $ch = $ts->peek();
            if ($ch === ';') {
                $ts->next();
                return;
            }
            if ($ch === '{') {
                $ts->next();
                $this->skipBalanced($ts, '{', '}');
                continue;
            }
            if ($ch === '[') {
                $ts->next();
                $this->skipBalanced($ts, '[', ']');
                continue;
            }
            $ts->next();
        }
    }

    protected function skipDefinition(TokenStream $ts): void
    {
        while (!$ts->eof()) {
            $ch = $ts->peek();
            if ($ch === '{') {
                $ts->next();
                $this->skipBalanced($ts, '{', '}');
                return;
            }
            if ($ch === ';') {
                $ts->next();
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
}
