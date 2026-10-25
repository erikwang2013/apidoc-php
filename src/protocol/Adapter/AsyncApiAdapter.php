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
 * AsyncAPI 2.x/3.x 适配器
 */
class AsyncApiAdapter implements AdapterInterface
{
    public function format(): string
    {
        return 'asyncapi';
    }

    public function parse(ProtocolContext $ctx, string $content): array
    {
        $document = $this->decode($content);
        $version = isset($document['asyncapi']) ? (string)$document['asyncapi'] : '';
        if ($version === '') {
            $ctx->error('AsyncAPI version is missing');
        } elseif (strpos($version, '2.') !== 0 && strpos($version, '3.') !== 0) {
            $ctx->unsupported('unsupported AsyncAPI version: ' . $version);
        }
        $load = $this->loader();
        $resolveRef = function (string $ref, ?array &$resolvedRoot = null, ?string &$resolvedFile = null) use ($document, $ctx, $load): ?array {
            return RefResolver::resolve($ref, $document, $ctx, $load, $resolvedRoot, $resolvedFile);
        };
        $wanted = (string)$ctx->option('operation', '');
        $selection = strpos($version, '3.') === 0
            ? $this->selectV3($document, $wanted, $ctx, $resolveRef)
            : $this->selectV2($document, $wanted, $ctx, $resolveRef);
        if ($selection === null) {
            return ['operation' => '', 'messages' => [], 'meta' => []];
        }
        $direction = (string)$ctx->option('direction', '');
        if ($direction === '') {
            $direction = 'event';
        }
        $sample = (string)$ctx->option('sample', '');
        $roots = isset($selection['roots']) && is_array($selection['roots']) ? $selection['roots'] : [];
        $files = isset($selection['files']) && is_array($selection['files']) ? $selection['files'] : [];
        $messages = [];
        foreach ($selection['messages'] as $index => $message) {
            if (!is_array($message)) {
                continue;
            }
            $messageRoot = isset($roots[$index]) && is_array($roots[$index]) ? $roots[$index] : $document;
            $messageFile = isset($files[$index]) && is_string($files[$index]) ? $files[$index] : $ctx->file();
            $payload = isset($message['payload']) && is_array($message['payload']) ? $message['payload'] : [];
            $payload = RefResolver::expand($payload, $messageRoot, $ctx, $load, $messageFile);
            $fields = SchemaMapper::fields($payload, $ctx, $resolveRef);
            $name = isset($message['name']) ? (string)$message['name'] : (isset($payload['title']) ? (string)$payload['title'] : 'Message');
            $item = ['name' => $name, 'direction' => $direction, 'fields' => $fields];
            if ($sample !== '') {
                $item['sampleValidation'] = $this->validateSample($sample, $payload, $ctx, $resolveRef);
            }
            $messages[] = $item;
        }
        return [
            'operation' => $selection['operation'],
            'messages' => $messages,
            'meta' => [],
        ];
    }

    protected function decode(string $content): array
    {
        $trimmed = ltrim($content);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $document = json_decode($content, true);
            if (!is_array($document)) {
                throw new ProtocolParseException('AsyncAPI JSON parse error: ' . json_last_error_msg(), 0, 0);
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

    protected function selectV2(array $document, string $wanted, ProtocolContext $ctx, callable $resolveRef): ?array
    {
        $channels = isset($document['channels']) && is_array($document['channels']) ? $document['channels'] : [];
        $candidates = [];
        foreach ($channels as $channelName => $channel) {
            if (!is_array($channel)) {
                continue;
            }
            $channelRoot = $document;
            $channelFile = $ctx->file();
            if (isset($channel['$ref']) && is_string($channel['$ref'])) {
                $resolved = $resolveRef($channel['$ref'], $channelRoot, $channelFile);
                if (is_array($resolved)) {
                    $channel = $resolved;
                }
            }
            foreach (['publish', 'subscribe'] as $action) {
                if (!isset($channel[$action]) || !is_array($channel[$action])) {
                    continue;
                }
                $candidates[] = ['channel' => (string)$channelName, 'action' => $action, 'operation' => $channel[$action], 'root' => $channelRoot, 'file' => $channelFile];
            }
        }
        if ($wanted !== '') {
            $pos = strrpos($wanted, ':');
            $channelWanted = $pos === false ? $wanted : substr($wanted, 0, $pos);
            $actionWanted = $pos === false ? '' : substr($wanted, $pos + 1);
            foreach ($candidates as $candidate) {
                if ($candidate['channel'] === $channelWanted && ($actionWanted === '' || $candidate['action'] === $actionWanted)) {
                    return $this->v2Messages($candidate, $document, $ctx, $resolveRef);
                }
            }
            $ctx->error('operation not found: ' . $wanted . '; candidates: ' . $this->candidates($candidates));
            return null;
        }
        if (count($candidates) === 1) {
            return $this->v2Messages($candidates[0], $document, $ctx, $resolveRef);
        }
        $ctx->error('operation required; candidates: ' . $this->candidates($candidates));
        return null;
    }

    protected function v2Messages(array $candidate, array $document, ProtocolContext $ctx, callable $resolveRef): array
    {
        $operation = $candidate['channel'] . ':' . $candidate['action'];
        $message = $candidate['operation']['message'] ?? [];
        $messageRoot = isset($candidate['root']) && is_array($candidate['root']) ? $candidate['root'] : $document;
        $messageFile = isset($candidate['file']) && is_string($candidate['file']) ? $candidate['file'] : $ctx->file();
        if (is_array($message) && isset($message['$ref']) && is_string($message['$ref'])) {
            $message = $resolveRef($message['$ref'], $messageRoot, $messageFile);
        }
        return [
            'operation' => $operation,
            'messages' => is_array($message) ? [$message] : [],
            'roots' => [$messageRoot],
            'files' => [$messageFile],
        ];
    }

    protected function selectV3(array $document, string $wanted, ProtocolContext $ctx, callable $resolveRef): ?array
    {
        $operations = isset($document['operations']) && is_array($document['operations']) ? $document['operations'] : [];
        $candidates = [];
        foreach ($operations as $operationId => $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $candidates[] = ['id' => (string)$operationId, 'operation' => $operation];
        }
        if ($wanted !== '') {
            foreach ($candidates as $candidate) {
                if ($candidate['id'] === $wanted) {
                    return $this->v3Messages($candidate, $document, $ctx, $resolveRef);
                }
            }
            $ctx->error('operation not found: ' . $wanted . '; candidates: ' . $this->candidatesV3($candidates));
            return null;
        }
        if (count($candidates) === 1) {
            return $this->v3Messages($candidates[0], $document, $ctx, $resolveRef);
        }
        $ctx->error('operation required; candidates: ' . $this->candidatesV3($candidates));
        return null;
    }

    protected function v3Messages(array $candidate, array $document, ProtocolContext $ctx, callable $resolveRef): array
    {
        $operation = $candidate['operation'];
        $channelRef = isset($operation['channel']) && is_array($operation['channel']) ? (string)($operation['channel']['$ref'] ?? '') : '';
        $channelAddress = '';
        if ($channelRef !== '') {
            $resolved = $resolveRef($channelRef);
            if (is_array($resolved)) {
                $channelAddress = isset($resolved['address']) ? (string)$resolved['address'] : '';
            }
            if ($channelAddress === '') {
                $channelAddress = basename(str_replace('~1', '/', $channelRef));
            }
        }
        $messages = [];
        $roots = [];
        $files = [];
        $messageRefs = isset($operation['messages']) && is_array($operation['messages']) ? $operation['messages'] : [];
        foreach ($messageRefs as $messageRef) {
            $messageRoot = $document;
            $messageFile = $ctx->file();
            if (is_array($messageRef) && isset($messageRef['$ref']) && is_string($messageRef['$ref'])) {
                $messageRef = $resolveRef($messageRef['$ref'], $messageRoot, $messageFile);
            }
            if (is_array($messageRef)) {
                $messages[] = $messageRef;
                $roots[] = $messageRoot;
                $files[] = $messageFile;
            }
        }
        return ['operation' => $candidate['id'], 'messages' => $messages, 'roots' => $roots, 'files' => $files, 'channel' => $channelAddress];
    }

    protected function candidates(array $candidates): string
    {
        $names = [];
        foreach ($candidates as $candidate) {
            $names[] = $candidate['channel'] . ':' . $candidate['action'];
        }
        return implode(', ', $names);
    }

    protected function candidatesV3(array $candidates): string
    {
        return implode(', ', array_map(static function (array $candidate): string {
            return $candidate['id'];
        }, $candidates));
    }

    protected function validateSample(string $sample, array $schema, ProtocolContext $ctx, callable $resolveRef): array
    {
        $data = json_decode($sample, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'ok' => false,
                'errors' => [['path' => '$', 'severity' => 'error', 'message' => 'JSON sample parse error: ' . json_last_error_msg()]],
                'warnings' => [],
            ];
        }
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
}
