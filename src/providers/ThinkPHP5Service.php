<?php

namespace erikwang2013\apidoc\providers;

use erikwang2013\apidoc\middleware\ThinkPHPMiddleware;
use erikwang2013\apidoc\utils\Helper;
use think\facade\App;
use think\facade\Route;
use think\facade\Request;
use think\facade\Lang;
use think\Db;
use Exception;

/**
 * ThinkPHP5 框架支持
 *
 * 接入方式(TP5 无 composer 自动发现,需手动触发):
 *   在应用初始化处调用一次:
 *
 *       (new \erikwang2013\apidoc\providers\ThinkPHP5Service())->run();
 *
 * 说明:
 * - 不在 composer.json 的 extra.think.services 中注册(TP6 应用会同时加载本类与 ThinkPHPService,风险大于收益)
 * - 路由中间件(ThinkPHPMiddleware)仅在 TP5.1+ 支持的路由中间件方法存在时挂载
 */
class ThinkPHP5Service
{
    use BaseService;

    public function run(){
        $this->initConfig();
        self::registerApidocRoutes();
        // 自动注册路由
        self::autoRegisterRoutes(function ($routeData){
            $appRoute = app('route');
            $routeGroup = $appRoute->getGroup();
            foreach ($routeData as $controller) {
                $routeGroup = $appRoute->getGroup();
                if (!empty($controller['middleware'])){
                    $routeGroup->middleware($controller['middleware']);
                }
                if (count($controller['methods'])){
                    foreach ($controller['methods'] as $method) {
                        $apiMethods = Helper::handleApiMethod($method['method']);
                        $apiMethods = implode("|",$apiMethods);
                        $route = $routeGroup->addRule($method['url'],$method['controller']."@".$method['name'],$apiMethods);
                        if (!empty($method['middleware'])){
                            $route->middleware($method['middleware']);
                        }
                    }
                }
            }
        });
    }

    static function getApidocConfig()
    {
        $config = config("apidoc.");
        $exportConfig = config("apidoc-export.");
        if (!(!empty($config['auto_url']) && !empty($config['auto_url']['filter_keys']))){
            $config['auto_url']['filter_keys'] = ['app','controller'];
        }
        $config['app_frame'] = "thinkphp5";
        if (!empty($exportConfig)){
            $config['export_config'] = $exportConfig;
        }
        return $config;
    }

    static function registerRoute($route){
        $config = self::getApidocConfig();
        $registerRoute = Route::rule($route['uri'], $route['callback'],"*");
        // 挂载中间件注入 request_params(否则非 GET 表单参数丢失),与 ThinkPHPService 思路一致;
        // TP5.0 无路由中间件方法,此时降级不挂载
        if (method_exists($registerRoute, 'middleware')) {
            $registerRoute->middleware([ThinkPHPMiddleware::class]);
        }
        if (!empty($config['allowCrossDomain'])) {
            $registerRoute->allowCrossDomain();
        }
    }

    static function databaseQuery($sql){
        return Db::query($sql);
    }

    static function getTablePrefix(){
        $driver = config('database.default');
        $table_prefix=config('database.connections.'.$driver.'.prefix');
        return $table_prefix;
    }

    static function getRootPath()
    {
        return App::getRootPath();
    }

    static function getRuntimePath()
    {
        return App::getRuntimePath();
    }

    static function setLang($locale){
        Lang::setLangCookieVar($locale);
    }

    static function getLang($lang){
        return Lang::get($lang);
    }

    static function handleResponseJson($res){
        return json($res);
    }


}
