<?php

namespace erikwang2013\apidoc\middleware;

use erikwang2013\apidoc\utils\ConfigProvider;

class LaravelMiddleware
{
    public function handle($request, \Closure $next)
    {
        $params = $request->all();
        $config =  ConfigProvider::get();
        $config['request_params'] = $params;
        ConfigProvider::set($config);
        return $next($request);
    }
}