<?php

namespace erikwang2013\apidoc\middleware;

use erikwang2013\apidoc\utils\ConfigProvider;

class ThinkPHPMiddleware
{
    public function handle($request, \Closure $next)
    {
        $params = $request->param();
        $config =  ConfigProvider::get();
        $config['request_params'] = $params;
        ConfigProvider::set($config);
        return $next($request);
    }
}