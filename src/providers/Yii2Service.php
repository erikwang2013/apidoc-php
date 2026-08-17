<?php

namespace erikwang2013\apidoc\providers;

use Yii;
use yii\base\Action;
use yii\base\Controller as BaseYiiController;
use yii\web\Response;
use erikwang2013\apidoc\middleware\Yii2Middleware;
use erikwang2013\apidoc\utils\Helper;

/**
 * Yii2 框架支持
 *
 * 接入方式(Yii2 无 composer 自动发现,需手动调用):
 * 在 web/index.php(或 console 入口)创建应用之后、run() 之前调用一次:
 *
 *     \erikwang2013\apidoc\providers\Yii2Service::register();
 *
 * 要求:
 * - urlManager 需开启 'enablePrettyUrl' => true
 * - apidoc 配置放在应用配置的 'params' 中: params['apidoc'](可选 params['apidoc-export'])
 * - 依赖 Yii::$app->db(可选的 tablePrefix)
 *
 * 说明:
 * - Yii2 的 UrlRule 不支持闭包回调,这里把路由注册为 URL 规则,
 *   经 controllerMap(取 url 首段)+ 自定义 Action(取剩余段)承载回调;
 *   因此 url 至少需要 controller/action 两段
 * - 用户接口 attribute 中配置的 middleware 在 Yii2 下不生效,
 *   请改用用户控制器自身的 behaviors()/ActionFilter(ponytail: 不做行为注入)
 */
class Yii2Service
{
    use BaseService;

    /**
     * 注册 apidoc 路由与自动注册的用户接口路由
     */
    public static function register()
    {
        !defined('APIDOC_ROOT_PATH') && define('APIDOC_ROOT_PATH', static::getRootPath());
        !defined('APIDOC_STORAGE_PATH') && define('APIDOC_STORAGE_PATH', static::getRuntimePath());

        // 注册 apidoc 自身路由
        static::registerApidocRoutes(function ($item) {
            static::addRouteRule($item['uri'], $item['callback'], static::allMethods());
        });

        // 自动注册用户接口路由
        static::autoRegisterRoutes(function ($routeData) {
            foreach ($routeData as $controller) {
                if (count($controller['methods'])) {
                    foreach ($controller['methods'] as $method) {
                        $apiMethods = Helper::handleApiMethod($method['method']);
                        static::addRouteRule($method['url'], $method['controller'] . '@' . $method['name'], array_merge($apiMethods, ['OPTIONS']));
                    }
                }
            }
        }, static::getApidocConfig());
    }

    /**
     * 将一条 url 注册为 Yii2 URL 规则:controllerMap(url 首段) + Action(剩余段)
     * @param $uri
     * @param $callback "控制器类@方法名"
     * @param array $methods 允许的 HTTP 方法
     */
    private static function addRouteRule($uri, $callback, array $methods)
    {
        $uri = trim((string)$uri, '/');
        if ($uri === '' || strpos($uri, '/') === false) {
            return; // Yii2 路由至少需要 controller/action 两段
        }
        list($controllerId, $actionId) = explode('/', $uri, 2);
        if (!isset(Yii::$app->controllerMap[$controllerId])) {
            Yii::$app->controllerMap[$controllerId] = Yii2ApidocController::class;
        }
        Yii2ApidocController::$actionMap[$controllerId][$actionId] = [
            'class' => Yii2Action::class,
            'callback' => $callback,
        ];
        Yii::$app->urlManager->addRules([
            [
                'pattern' => $uri,
                'route' => $uri,
                'verb' => $methods,
            ],
        ]);
    }

    private static function allMethods(): array
    {
        return ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    }

    static function getApidocConfig()
    {
        $config = !empty(Yii::$app->params['apidoc']) ? Yii::$app->params['apidoc'] : [];
        $exportConfig = !empty(Yii::$app->params['apidoc-export']) ? Yii::$app->params['apidoc-export'] : [];
        if (!(!empty($config['auto_url']) && !empty($config['auto_url']['filter_keys']))) {
            $config['auto_url']['filter_keys'] = ['app', 'controllers'];
        }
        $config['app_frame'] = "yii2";
        if (!empty($exportConfig)) {
            $config['export_config'] = $exportConfig;
        }
        return $config;
    }

    static function registerRoute($route)
    {
        static::addRouteRule($route['uri'], $route['callback'], static::allMethods());
    }

    static function databaseQuery($sql)
    {
        return Yii::$app->db->createCommand($sql)->queryAll();
    }

    static function getRootPath()
    {
        return rtrim(Yii::getAlias('@app'), '/') . '/';
    }

    static function getRuntimePath()
    {
        return rtrim(Yii::getAlias('@runtime'), '/') . '/';
    }

    static function setLang($locale)
    {
        Yii::$app->language = $locale;
    }

    static function getLang($lang)
    {
        return Yii::t('apidoc', $lang);
    }

    static function handleResponseJson($res)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return $res;
    }

    static function getTablePrefix()
    {
        return !empty(Yii::$app->db) ? (string)Yii::$app->db->tablePrefix : '';
    }
}

/**
 * apidoc 路由承载控制器:经 controllerMap 挂到 url 首段,actions() 返回已注册回调
 */
class Yii2ApidocController extends BaseYiiController
{
    /** @var array 已注册回调: [controllerId][actionId] => action 配置 */
    public static $actionMap = [];

    public function actions()
    {
        return static::$actionMap[$this->id] ?? [];
    }

    public function behaviors()
    {
        return [
            'apidocMiddleware' => ['class' => Yii2Middleware::class],
        ];
    }
}

/**
 * 将 "控制器类@方法名" 回调包装为 Yii2 Action
 */
class Yii2Action extends Action
{
    public $callback;

    public function run()
    {
        list($class, $method) = explode('@', $this->callback, 2);
        $controller = Yii::createObject($class);
        $result = $controller->{$method}();
        if (!$result instanceof Response) {
            Yii::$app->response->format = Response::FORMAT_JSON;
        }
        return $result;
    }
}
