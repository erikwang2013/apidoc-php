<?php

namespace erikwang2013\apidoc\providers;

use erikwang2013\apidoc\utils\ConfigProvider;
use erikwang2013\apidoc\utils\Helper;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Router\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use erikwang2013\apidoc\middleware\HyperfMiddleware;



/**
 * Hyperf 框架支持
 *
 * 路由注册由 ConfigProvider 注册的 HyperfRoutesListener 在主协程服务启动时自动触发;
 * 也可在应用启动处手动调用 HyperfService::register()(重复调用安全)
 * 注:监听器方式未在真实 Hyperf 环境验证,如未生效请改用手动调用
 */
class HyperfService
{

    /** @var bool 防止监听器触发与手动调用重复注册路由 */
    private static $registered = false;

    static function register(){
        if (static::$registered === true) {
            return;
        }
        static::$registered = true;
        ! defined('APIDOC_ROOT_PATH') && define('APIDOC_ROOT_PATH', HyperfMiddleware::getRootPath());
        ! defined('APIDOC_STORAGE_PATH') && define('APIDOC_STORAGE_PATH', HyperfMiddleware::getRuntimePath());

        HyperfMiddleware::registerApidocRoutes(function ($item){
            Router::addRoute(['GET','POST'],$item['uri'],$item['callback'],['middleware' => [HyperfMiddleware::class]]);
        });

        // 自动注册路由
        HyperfMiddleware::autoRegisterRoutes(function ($routeData){
            foreach ($routeData as $controller) {
                if (count($controller['methods'])){
                    $methods= $controller['methods'];
                    $routeCallback = function ()use ($methods){
                        foreach ($methods as $method) {
                            $apiMethods = Helper::handleApiMethod($method['method']);
                            $options = [];
                            if (!empty($method['middleware'])){
                                $options['middleware']= $method['middleware'];
                            }
                            Router::addRoute([...$apiMethods,'OPTIONS'],$method['url'], $method['controller']."@".$method['name'],$options);
                        }
                    };
                    $groupOptions = [];
                    if (!empty($controller['middleware'])){
                        $groupOptions['middleware'] = $controller['middleware'];
                    }
                    Router::addGroup("",$routeCallback,$groupOptions);
                }
            }
        }, HyperfMiddleware::getApidocConfig());
    }
}