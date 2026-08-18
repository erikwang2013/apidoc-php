<?php

namespace erikwang2013\apidoc\providers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Db\ConnectionInterface;
use Yiisoft\Router\Route;
use Yiisoft\Router\RouteCollectorInterface;
use Yiisoft\Translator\TranslatorInterface;
use erikwang2013\apidoc\middleware\Yii3Middleware;
use erikwang2013\apidoc\utils\Helper;

/**
 * Yii3 框架支持
 *
 * 接入方式(Yii3 无 composer 自动发现,需手动调用):
 * 在应用启动前(如 config/routes.php 顶部或 bootstrap 中)调用一次:
 *
 *     \erikwang2013\apidoc\providers\Yii3Service::register($container);
 *
 * 要求(从容器解析,按需安装):
 * - yiisoft/router(RouteCollectorInterface)+ 适配器(如 yiisoft/router-fastroute)
 * - yiisoft/db(ConnectionInterface,可选的 getTablePrefix())
 * - yiisoft/aliases(可选的 @root/@runtime,缺省用 getcwd()/runtime)
 * - yiisoft/translator(可选,缺省语言 key 原样返回)
 * - apidoc 配置:register() 第二参传入数组,或使用 yiisoft/config 的
 *   ConfigInterface 读取 params['apidoc'](可选 params['apidoc-export'])
 *
 * 说明:
 * - 路由经 yiisoft/router 的 Route::methods()->action()->middleware() 注册,
 *   回调格式为 "控制器类@方法名",由容器解析控制器实例
 * - 接口响应统一转为 JSON(经 ResponseFactoryInterface 构造 PSR-7 响应)
 */
class Yii3Service
{
    use BaseService;

    /** @var ContainerInterface|null */
    private static $container = null;

    /** @var array|null register() 第二参传入的配置覆盖 */
    private static $config = null;

    /**
     * 注册 apidoc 路由与自动注册的用户接口路由
     * @param ContainerInterface $container 应用容器
     * @param array|null $config apidoc 配置,不传则尝试从 yiisoft/config 读取
     */
    public static function register(ContainerInterface $container, ?array $config = null)
    {
        if (static::$container !== null) {
            return; // 幂等:已注册过则跳过,避免静态 $container 被后续应用覆盖造成串扰
        }
        static::$container = $container;
        static::$config = $config;

        !defined('APIDOC_ROOT_PATH') && define('APIDOC_ROOT_PATH', static::getRootPath());
        !defined('APIDOC_STORAGE_PATH') && define('APIDOC_STORAGE_PATH', static::getRuntimePath());

        $collector = static::getCollector();
        if ($collector === null) {
            return; // 未安装/未注册 yiisoft/router,跳过路由注册
        }

        // 注册 apidoc 自身路由
        static::registerApidocRoutes(function ($item) use ($collector) {
            $collector->addRoute(
                Route::methods(static::allMethods(), static::normalizeUri($item['uri']))
                    ->action(static::makeAction($item['callback']))
                    ->middleware(Yii3Middleware::class)
            );
        });

        // 自动注册用户接口路由(用户自己的 middleware 原样挂到路由上)
        static::autoRegisterRoutes(function ($routeData) use ($collector) {
            foreach ($routeData as $controller) {
                if (count($controller['methods'])) {
                    foreach ($controller['methods'] as $method) {
                        $apiMethods = Helper::handleApiMethod($method['method']);
                        $route = Route::methods(array_merge($apiMethods, ['OPTIONS']), static::normalizeUri($method['url']))
                            ->action(static::makeAction($method['controller'] . '@' . $method['name']));
                        foreach ($method['middleware'] ?? [] as $middleware) {
                            $route = $route->middleware($middleware);
                        }
                        $collector->addRoute($route);
                    }
                }
            }
        }, static::getApidocConfig());
    }

    private static function getCollector()
    {
        if (static::$container !== null && static::$container->has(RouteCollectorInterface::class)) {
            return static::$container->get(RouteCollectorInterface::class);
        }
        return null;
    }

    /**
     * "控制器类@方法名" => 路由 action 回调,返回 PSR-7 响应
     */
    private static function makeAction(string $callback): callable
    {
        return function (ServerRequestInterface $request, RequestHandlerInterface $next) use ($callback) {
            list($class, $method) = explode('@', $callback, 2);
            try {
                $controller = static::$container->get($class);
            } catch (\Throwable $e) {
                $controller = new $class();
            }
            return static::toJson($controller->{$method}());
        };
    }

    private static function toJson($data): ResponseInterface
    {
        if ($data instanceof ResponseInterface) {
            return $data;
        }
        if (static::$container !== null && static::$container->has(ResponseFactoryInterface::class)) {
            $response = static::$container->get(ResponseFactoryInterface::class)->createResponse();
            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        }
        throw new \RuntimeException('Yii3Service: 容器中未注册 ' . ResponseFactoryInterface::class . ',接口响应无法转换');
    }

    /**
     * 返回空 PSR-7 响应(OPTIONS 预检请求短路用)
     */
    static function emptyResponse(): ResponseInterface
    {
        if (static::$container !== null && static::$container->has(ResponseFactoryInterface::class)) {
            return static::$container->get(ResponseFactoryInterface::class)->createResponse(204);
        }
        throw new \RuntimeException('Yii3Service: 容器中未注册 ' . ResponseFactoryInterface::class . ',OPTIONS 预检请求无法短路');
    }

    private static function allMethods(): array
    {
        return ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    }

    private static function normalizeUri($uri): string
    {
        $uri = trim((string)$uri, '/');
        return $uri === '' ? '/' : '/' . $uri;
    }

    static function getApidocConfig()
    {
        $params = null;
        if (static::$config === null && static::$container !== null && static::$container->has(ConfigInterface::class)) {
            try {
                $params = static::$container->get(ConfigInterface::class)->get('params');
            } catch (\Throwable $e) {
                $params = null;
            }
        }
        $config = static::$config ?? (!empty($params['apidoc']) ? $params['apidoc'] : []);
        $exportConfig = static::$config === null && !empty($params['apidoc-export']) ? $params['apidoc-export'] : null;
        if (!(!empty($config['auto_url']) && !empty($config['auto_url']['filter_keys']))) {
            $config['auto_url']['filter_keys'] = ['app', 'controllers'];
        }
        $config['app_frame'] = "yii3";
        if (!empty($exportConfig)) {
            $config['export_config'] = $exportConfig;
        }
        return $config;
    }

    static function registerRoute($route)
    {
        $collector = static::getCollector();
        if ($collector === null) {
            return;
        }
        $collector->addRoute(
            Route::methods(static::allMethods(), static::normalizeUri($route['uri']))
                ->action(static::makeAction($route['callback']))
                ->middleware(Yii3Middleware::class)
        );
    }

    static function databaseQuery($sql)
    {
        if (static::$container !== null && static::$container->has(ConnectionInterface::class)) {
            return static::$container->get(ConnectionInterface::class)->createCommand($sql)->queryAll();
        }
        return []; // 容器未注册 ConnectionInterface,返回空结果
    }

    static function getRootPath()
    {
        if (static::$container !== null && static::$container->has(Aliases::class)) {
            try {
                $root = static::$container->get(Aliases::class)->get('@root');
            } catch (\Throwable $e) {
                $root = null; // 别名未定义时走默认值
            }
            if ($root !== null) {
                return rtrim($root, '/') . '/';
            }
        }
        return getcwd() . '/';
    }

    static function getRuntimePath()
    {
        if (static::$container !== null && static::$container->has(Aliases::class)) {
            try {
                $runtime = static::$container->get(Aliases::class)->get('@runtime');
            } catch (\Throwable $e) {
                $runtime = null; // 别名未定义时走默认值
            }
            if ($runtime !== null) {
                return rtrim($runtime, '/') . '/';
            }
        }
        return static::getRootPath() . 'runtime/';
    }

    static function setLang($locale)
    {
        if (static::$container !== null && static::$container->has(TranslatorInterface::class)) {
            $translator = static::$container->get(TranslatorInterface::class);
            if (method_exists($translator, 'setLocale')) {
                $translator->setLocale($locale);
            }
        }
    }

    static function getLang($lang)
    {
        if (static::$container !== null && static::$container->has(TranslatorInterface::class)) {
            $translator = static::$container->get(TranslatorInterface::class);
            if (method_exists($translator, 'translate')) {
                return $translator->translate('apidoc', $lang) ?? $lang;
            }
        }
        return $lang;
    }

    static function handleResponseJson($res)
    {
        return static::toJson($res);
    }

    static function getTablePrefix()
    {
        if (static::$container !== null && static::$container->has(ConnectionInterface::class)) {
            $connection = static::$container->get(ConnectionInterface::class);
            if (method_exists($connection, 'getTablePrefix')) {
                return (string)$connection->getTablePrefix();
            }
        }
        return '';
    }
}
