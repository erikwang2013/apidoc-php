<?php

namespace erikwang2013\apidoc\middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use erikwang2013\apidoc\providers\Yii3Service;
use erikwang2013\apidoc\utils\ApiCrossDomain;
use erikwang2013\apidoc\utils\ConfigProvider;

/**
 * Yii3 中间件(PSR-15),挂在 apidoc 自身路由上
 * 负责:初始化 apidoc 配置、收集请求参数、跨域处理
 * 无需手动注册,由 Yii3Service::register() 注册路由时自动挂载
 */
class Yii3Middleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        (new Yii3Service())->initConfig();
        $config = ConfigProvider::get();
        $parsedBody = $request->getParsedBody();
        $config['request_params'] = array_merge(
            $request->getQueryParams(),
            is_array($parsedBody) ? $parsedBody : []
        );
        ConfigProvider::set($config);

        $response = strtoupper($request->getMethod()) === 'OPTIONS'
            ? Yii3Service::emptyResponse() // 预检请求无条件短路,不执行业务逻辑(与 WebmanMiddleware 一致)
            : $handler->handle($request);
        // Origin 未命中 cors_origins 白名单时返回空数组,不下发任何 CORS 头
        $corsHeaders = ApiCrossDomain::corsHeaders(
            $config,
            (string)$request->getHeaderLine('Origin'),
            (string)$request->getHeaderLine('Access-Control-Request-Method'),
            (string)$request->getHeaderLine('Access-Control-Request-Headers')
        );
        foreach ($corsHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
