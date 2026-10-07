<?php

namespace erikwang2013\apidoc\middleware;

use erikwang2013\apidoc\providers\BaseService;
use erikwang2013\apidoc\utils\ApiCrossDomain;
use erikwang2013\apidoc\utils\ConfigProvider;
use support\Db;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class WebmanMiddleware implements MiddlewareInterface
{
    use BaseService;

    public function process(Request $request, callable $handler) : Response
    {
        $this->initConfig();
        $params = $request->all();
        $config =  ConfigProvider::get();
        $config['request_params'] = $params;
        ConfigProvider::set($config);

        $response = $request->method() == 'OPTIONS' ? response('') : $handler($request);
        // 给响应添加跨域相关的http头(Origin 未命中 cors_origins 白名单时返回空数组,不下发任何 CORS 头)
        $corsHeaders = ApiCrossDomain::corsHeaders(
            $config,
            (string)$request->header('origin', ''),
            (string)$request->header('access-control-request-method', ''),
            (string)$request->header('access-control-request-headers', '')
        );
        if (!empty($corsHeaders)) {
            $response->withHeaders($corsHeaders);
        }

        return $response;
    }

    static function getApidocConfig()
    {
        $config = config('plugin.erikwang2013.apidoc.app.apidoc');
        $exportConfig = config('plugin.erikwang2013.apidoc-export.app');
        if (!(!empty($config['auto_url']) && !empty($config['auto_url']['filter_keys']))){
            $config['auto_url']['filter_keys'] = ['app','controller'];
        }
        $config['app_frame'] = "webman";
        if (!empty($exportConfig)){
            $config['export_config'] = $exportConfig;
        }
        return $config;
    }

    static function databaseQuery($sql)
    {
        return Db::select($sql);
    }

    static function getRootPath()
    {
        return BASE_PATH."/";
    }

    static function getRuntimePath()
    {
        return BASE_PATH."/runtime/";
    }

    static function setLang($locale)
    {
        locale($locale);
    }

    static function getLang($lang): string
    {
        return $lang;
    }

    static function handleResponseJson($res)
    {
        return json($res);
    }

    static function getTablePrefix(){
        $driver = config('database.default');
        $table_prefix=config('database.connections.'.$driver.'.prefix');
        return $table_prefix;
    }
}