<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\parses;

use erikwang2013\apidoc\exception\ErrorException;
use erikwang2013\apidoc\generator\ParseTemplate;
use erikwang2013\apidoc\utils\DirAndFile;

class ParseCodeTemplate
{

    protected $config = [];


    public function __construct($config)
    {

        $this->config = $config;
    }

    /**
     * @param array $params {appKey,template,selected,form}
     * @param bool $filterOutOfScope UIC-1 路径:selected 中不属于 app 的 id 直接跳过;
     *                               legacy 保持原行为(渲染时抛错)
     */
    public function renderCode($params, bool $filterOutOfScope = false)
    {
        $appKey = (string)($params['appKey'] ?? '');

        // 只允许配置里的 code_template 项:客户端不能借 template 指定任意路径读取文件
        $codeTemplate = $this->resolveCodeTemplate($params['template'] ?? []);

        //解析接口数据
        $tplData = [];
        if (($codeTemplate['select_mode'] ?? '') == 'controller'){
            $parseApiMenusService = new ParseApiMenus($this->config);
            $scopeService = $filterOutOfScope ? new ParseApiDetail($this->config) : null;
            $controllers = $params['selected'] ?? [];
            if (!empty($controllers) && count($controllers) > 0) {
                $controllerList = [];
                foreach ($controllers as $class) {
                    if (!is_string($class)) {
                        continue;
                    }
                    if ($scopeService !== null && !$scopeService->isClassInAppScope($appKey, $class)) {
                        continue;
                    }
                    $classData = $parseApiMenusService->parseController($class, false, $appKey);
                    if ($classData !== false) {
                        $controllerList[] = $classData;
                    }
                }
                if (empty($codeTemplate['multiple'])){
                    $tplData = $controllerList[0] ?? [];
                }else{
                    $tplData = $controllerList;
                }
            }
        }else{
            // api
            $apis = $params['selected'] ?? [];
            if (!empty($apis) && count($apis) > 0) {
                $parseApiDetailService = new ParseApiDetail($this->config);
                $apiList = [];
                foreach ($apis as $key) {
                    $apiKey = urldecode((string)$key);
                    if ($filterOutOfScope) {
                        $class = strstr($apiKey, '@', true);
                        if ($class === false || !$parseApiDetailService->isClassInAppScope($appKey, $class)) {
                            continue;
                        }
                    }
                    $apiDetail = $parseApiDetailService->renderApiDetail($appKey,$apiKey);
                    if ($apiDetail !== false) {
                        $apiList[] = $apiDetail;
                    }
                }
                if (empty($codeTemplate['multiple'])){
                    $tplData = $apiList[0] ?? [];
                }else{
                    $tplData = $apiList;
                }
            }
        }


        // 读取模板
        $templatePath = $this->resolvePath($codeTemplate['template']);
        if ($templatePath === false || is_readable($templatePath) == false) {
            throw new ErrorException("template not found",  [
                'template' => $codeTemplate['template']
            ]);
        }
        $tplParams = [
            'form'=>  $params['form'] ?? [],
            'data'=>$tplData
        ];
        $html = (new ParseTemplate())->compile($templatePath,$tplParams);



        return $html;
    }

    /**
     * 把客户端提交的 template 解析为配置中的 code_template 项。
     * 支持按 template 路径匹配,或 template 为空时按 title 匹配(与 Go 一致);
     * 未知/外部路径一律拒绝。
     * @param mixed $requested
     * @throws ErrorException
     */
    protected function resolveCodeTemplate($requested): array
    {
        if (is_string($requested)) {
            $requested = ['template' => $requested];
        }
        if (!is_array($requested)) {
            $requested = [];
        }
        $path = (string)($requested['template'] ?? '');
        $title = (string)($requested['title'] ?? '');
        foreach ((array)($this->config['code_template'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($path !== '' && (string)($item['template'] ?? '') === $path) {
                return $item;
            }
            if ($path === '' && $title !== '' && (string)($item['title'] ?? '') === $title) {
                return $item;
            }
        }
        throw new ErrorException("template not found", [
            'template' => $path !== '' ? $path : $title
        ]);
    }

    /**
     * 解析模板路径(防路径穿越)，统一走 DirAndFile::resolvePathWithinRoot
     * @param string $path 相对于 APIDOC_ROOT_PATH 的路径(允许以 / 开头)
     * @return string|false 非法或越界时返回 false
     */
    protected function resolvePath($path)
    {
        return DirAndFile::resolvePathWithinRoot($path, APIDOC_ROOT_PATH);
    }


}