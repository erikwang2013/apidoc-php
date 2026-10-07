<?php

namespace erikwang2013\apidoc\providers;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Server\Event\MainCoroutineServerStart;

/**
 * Hyperf 路由注册监听器:主协程服务启动时注册 apidoc 路由
 *
 * 由 ConfigProvider 的 listeners 配置注册,类文件仅在 Hyperf 进程内被加载,非 Hyperf 环境无影响
 * 注:未在真实 Hyperf 环境验证,如未生效请在应用启动处手动调用 HyperfService::register()
 */
class HyperfRoutesListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            MainCoroutineServerStart::class,
        ];
    }

    public function process(object $event): void
    {
        HyperfService::register();
    }
}
