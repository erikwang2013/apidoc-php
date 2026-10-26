<?php
declare(strict_types=1);

namespace erikwang2013\apidoc\ui;

use erikwang2013\apidoc\generator\Declarative;
use erikwang2013\apidoc\parses\ParseCodeTemplate;
use erikwang2013\apidoc\protocol\debug\DebugRegistry;
use erikwang2013\apidoc\utils\ConfigProvider;

/**
 * UIC-1 capability advertisement. The UI uses these flags to gate features;
 * strict parity still advertises every implemented feature.
 */
class UiCapabilities
{
    public static function fromConfig(array $config): array
    {
        $fe = ConfigProvider::getFeConfig();
        return [
            'contract' => '1.0',
            'backend' => 'php',
            'version' => self::version(),
            'features' => [
                'menus' => true,
                'detail' => true,
                'docs' => true,
                'protocolDefinitions' => true,
                'debug' => [
                    'browser' => ['http', 'websocket', 'sse'],
                    // hls 在 UIC 详情里固定走浏览器模式,不能声明为中继可用
                    'relay' => array_values(array_diff(DebugRegistry::availableNames(), ['hls'])),
                    'modes' => [
                        'browser-http',
                        'browser-websocket',
                        'browser-sse',
                        'relay-unary',
                        'relay-stream',
                    ],
                ],
                'share' => [
                    'link' => true,
                    'records' => true,
                    // UIC-1 does not execute legacy PHP share-action callables
                    'actions' => false,
                ],
                'cache' => [
                    'manage' => true,
                    'mode' => 'server',
                ],
                'generator' => [
                    // UIC-1 renders only configured ui_generator/code_template entries in memory
                    'forms' => (new Declarative($config))->descriptors() !== [],
                    'templates' => ParseCodeTemplate::descriptors($config) !== [],
                    'sideEffects' => false,
                ],
                'exports' => ['md', 'pdf', 'openapi', 'postman', 'asyncapi', 'typescript'],
                'auth' => [
                    'global' => !empty($config['auth']['enable']),
                    'app' => self::hasAppPassword($fe['apps'] ?? []),
                    'shareToken' => true,
                ],
            ],
        ];
    }

    private static function hasAppPassword(array $apps): bool
    {
        foreach ($apps as $app) {
            if (!empty($app['hasPassword'])) {
                return true;
            }
            if (!empty($app['items']) && self::hasAppPassword($app['items'])) {
                return true;
            }
        }
        return false;
    }

    private static function version(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            $version = \Composer\InstalledVersions::getPrettyVersion('erikwang2013/apidoc-php');
            if (is_string($version) && $version !== '') {
                return $version;
            }
        }
        return 'dev';
    }
}
