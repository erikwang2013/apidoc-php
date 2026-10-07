<?php
declare(strict_types=1);
/**
 * Hyperf the Config Provider
 */
namespace erikwang2013\apidoc;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [],
            // 注册主协程服务启动监听:Hyperf 下自动注册 apidoc 路由(非 Hyperf 环境不会加载该监听类)
            'listeners' => class_exists(\Hyperf\Event\Contract\ListenerInterface::class)
                ? [\erikwang2013\apidoc\providers\HyperfRoutesListener::class]
                : [],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config of apidoc.',
                    'source' => __DIR__ . '/config.php',
                    'destination' => BASE_PATH . '/config/autoload/apidoc.php',
                ],
            ],
        ];
    }
}