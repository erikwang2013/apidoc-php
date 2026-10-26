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
        // UIC-1 只接受逻辑模板名;legacy 路由继续接受配置里的模板路径以保持原行为
        $codeTemplate = $this->resolveCodeTemplate($params['template'] ?? [], !$filterOutOfScope);

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
     * UIC-1 /ui/v1/config 的 code_template 描述:服务端模板路径不下发,
     * `template` 字段改为逻辑模板名,再由 renderCode 服务端解析回配置项。
     * @param array $config
     * @return array
     * @throws ErrorException
     */
    public static function descriptors(array $config): array
    {
        $list = [];
        $ids = [];
        foreach ((array)($config['code_template'] ?? []) as $item) {
            if (!is_array($item)) {
                throw new ErrorException('invalid params', ['field' => 'code_template']);
            }
            foreach (['name', 'title', 'template'] as $field) {
                if (isset($item[$field]) && !is_string($item[$field])) {
                    throw new ErrorException('invalid params', ['field' => $field]);
                }
            }
            $id = static::templateId($item);
            if ($id === '') {
                throw new ErrorException('invalid params', ['field' => 'template']);
            }
            if (isset($ids[$id])) {
                throw new ErrorException('duplicate template name', ['template' => $id]);
            }
            $ids[$id] = true;
            unset($item['template']);
            $item['template'] = $id;
            if (!isset($item['title']) || !is_string($item['title']) || $item['title'] === '') {
                $item['title'] = $id;
            }
            $list[] = $item;
        }
        return $list;
    }

    /**
     * 逻辑模板名:优先配置的 name,否则用 title
     * @param array $item
     * @return string
     */
    public static function templateId(array $item): string
    {
        foreach (['name', 'title'] as $key) {
            $value = $item[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    /**
     * 把客户端提交的 template 解析为配置中的 code_template 项。
     * 按逻辑模板名/标题匹配;legacy 路由额外允许按配置路径匹配(旧 UI 回传配置项)。
     * 未知/外部路径一律拒绝。
     * @param mixed $requested
     * @param bool $allowConfiguredPath
     * @throws ErrorException
     */
    protected function resolveCodeTemplate($requested, bool $allowConfiguredPath = true): array
    {
        if (is_string($requested)) {
            $requested = ['template' => $requested];
        }
        if (!is_array($requested)) {
            $requested = [];
        }
        $path = isset($requested['template']) && is_string($requested['template']) ? $requested['template'] : '';
        $title = isset($requested['title']) && is_string($requested['title']) ? $requested['title'] : '';
        foreach ((array)($this->config['code_template'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = static::templateId($item);
            $configuredPath = isset($item['template']) && is_string($item['template']) ? $item['template'] : '';
            if ($path !== '' && ($path === $id || ($allowConfiguredPath && $configuredPath !== '' && $path === $configuredPath))) {
                return $item;
            }
            $configuredTitle = isset($item['title']) && is_string($item['title']) ? $item['title'] : '';
            if ($path === '' && $title !== '' && ($title === $id || ($allowConfiguredPath && $configuredTitle === $title))) {
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