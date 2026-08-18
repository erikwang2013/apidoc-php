<?php

namespace erikwang2013\apidoc\middleware;

use Yii;
use yii\base\ActionFilter;
use erikwang2013\apidoc\providers\Yii2Service;
use erikwang2013\apidoc\utils\ConfigProvider;

/**
 * Yii2 中间件(以 ActionFilter/behavior 形式挂在 Yii2ApidocController 上)
 * 负责:初始化 apidoc 配置、收集请求参数、跨域处理
 * 无需手动注册,由 Yii2Service::register() 注册的控制器自动挂载
 */
class Yii2Middleware extends ActionFilter
{
    public function beforeAction($action)
    {
        (new Yii2Service())->initConfig();
        $config = ConfigProvider::get();
        $config['request_params'] = array_merge(Yii::$app->request->get(), Yii::$app->request->getBodyParams());
        ConfigProvider::set($config);

        $request = Yii::$app->request;
        if (!empty($config['allowCrossDomain'])) {
            $headers = Yii::$app->response->headers;
            $headers->set('Access-Control-Allow-Credentials', 'true');
            $headers->set('Access-Control-Allow-Origin', $request->headers->get('Origin', '*'));
            $headers->set('Access-Control-Allow-Methods', $request->headers->get('Access-Control-Request-Method', '*'));
            $headers->set('Access-Control-Allow-Headers', $request->headers->get('Access-Control-Request-Headers', '*'));
        }
        if ($request->isOptions) {
            return false; // 预检请求无条件短路,不执行业务逻辑(与 WebmanMiddleware 一致)
        }
        return true;
    }
}
