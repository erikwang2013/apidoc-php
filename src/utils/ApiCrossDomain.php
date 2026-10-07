<?php
declare(strict_types = 1);
namespace erikwang2013\apidoc\utils;
use \Illuminate\Http\Request;
class ApiCrossDomain
{

    /**
     * 校验跨域 Origin 是否允许(各框架 middleware 共用)
     * - 未配置 cors_origins(空数组/未设置):保持原行为,回显请求 Origin
     * - 配置了 cors_origins 白名单:仅命中(严格比较)才允许,未命中不下发 CORS 头
     * @param array $config apidoc配置
     * @param string $origin 请求头 Origin
     * @return bool
     */
    public static function isOriginAllowed($config, $origin){
        $origins = !empty($config['cors_origins']) && is_array($config['cors_origins']) ? $config['cors_origins'] : [];
        if (empty($origins)) {
            return true; // 未配置白名单,维持原行为
        }
        return $origin !== '' && in_array($origin, $origins, true);
    }

    /**
     * 计算跨域响应头(webman/yii2/yii3 中间件共用)
     * @param array $config apidoc配置
     * @param string $origin 请求头 Origin
     * @param string $requestMethod 请求头 Access-Control-Request-Method
     * @param string $requestHeaders 请求头 Access-Control-Request-Headers
     * @return array 空数组表示不下发任何 CORS 头(未开启跨域 / Origin 未命中白名单)
     */
    public static function corsHeaders($config, $origin = '', $requestMethod = '', $requestHeaders = ''){
        if (empty($config['allowCrossDomain']) || !static::isOriginAllowed($config, $origin)) {
            return [];
        }
        return [
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Origin' => $origin !== '' ? $origin : '*',
            'Access-Control-Allow-Methods' => $requestMethod !== '' ? $requestMethod : '*',
            'Access-Control-Allow-Headers' => $requestHeaders !== '' ? $requestHeaders : '*',
        ];
    }

    public function handle (Request $request, \Closure $next){

        $response = $next($request);
        $origin = $request->server('HTTP_ORIGIN') ? $request->server('HTTP_ORIGIN') : '';
        $config = [];
        try {
            $config = ConfigProvider::get();
        } catch (\Throwable $e) {
            // 配置未初始化(如手动挂载该中间件)时维持原行为
        }
        if (!static::isOriginAllowed($config, (string)$origin)) {
            return $response; // Origin 未命中白名单:不下发任何 CORS 头
        }
        $response->header('Access-Control-Allow-Origin', $origin);
        $response->header('Access-Control-Allow-Headers', 'Origin, Content-Type, Cookie, X-CSRF-TOKEN, Accept, Authorization, X-XSRF-TOKEN');
        $response->header('Access-Control-Expose-Headers', 'Authorization, authenticated');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, OPTIONS');
        $response->header('Access-Control-Allow-Credentials', 'true');
        return $response;

    }


}
