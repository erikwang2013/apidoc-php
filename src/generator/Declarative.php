<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\generator;

use erikwang2013\apidoc\exception\ErrorException;
use erikwang2013\apidoc\utils\DirAndFile;
use erikwang2013\apidoc\utils\Helper;
use erikwang2013\apidoc\utils\Lang;

/**
 * UIC-1 声明式生成器:模板只从 ui_generator 配置读取,渲染结果只留在内存,
 * 不执行 middleware、不写文件、不建表/查库。
 */
class Declarative
{
    protected $config = [];

    public function __construct($config)
    {
        $this->config = is_array($config) ? $config : [];
    }

    /**
     * /ui/v1/config 的 generator 描述。模板路径仅服务端使用,不下发客户端。
     * @return array
     */
    public function descriptors(): array
    {
        $list = [];
        foreach ($this->items() as $item) {
            $files = [];
            foreach ($this->fileSpecs($item) as $file) {
                $entry = [
                    'name' => (string)$file['name'],
                    'path' => (string)($file['path'] ?? ''),
                ];
                if (!empty($file['namespace'])) {
                    $entry['namespace'] = $file['namespace'];
                }
                if (!empty($file['rules'])) {
                    $entry['rules'] = $file['rules'];
                }
                $files[] = $entry;
            }
            $form = !empty($item['form']) && is_array($item['form']) ? $item['form'] : [];
            if (!empty($form['items']) && is_array($form['items'])) {
                $form['items'] = Helper::handleArrayParams($form['items'], 'title', $this->config);
            }
            $descriptor = [
                'name' => (string)($item['name'] ?? ''),
                'title' => Lang::getLang((string)($item['title'] ?? ''), $this->config),
                'files' => $files,
            ];
            if ($form !== []) {
                $descriptor['form'] = $form;
            }
            if (!empty($item['table']) && is_array($item['table'])) {
                $descriptor['table'] = $item['table'];
            }
            $list[] = $descriptor;
        }
        return $list;
    }

    /**
     * 按配置渲染文件内容(仅内存)
     * @param array $params {index,form,files,tables}
     * @return array<int,array{name:string,path:string,content:string}>
     * @throws ErrorException
     */
    public function render(array $params): array
    {
        $index = $params['index'] ?? null;
        if (is_string($index) && ctype_digit($index)) {
            $index = (int)$index;
        }
        $items = $this->items();
        if (!is_int($index) || !isset($items[$index])) {
            throw new ErrorException('generator not found');
        }
        $generatorItem = $items[$index];

        $form = $params['form'] ?? [];
        if (!is_array($form)) {
            throw new ErrorException('invalid params', ['field' => 'form']);
        }
        // 表单值只作为模板变量;数组/对象会让字符串替换不可预测,直接按参数错误拒绝
        foreach ($form as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new ErrorException('invalid params', ['field' => (string)$key]);
            }
        }
        $tables = $params['tables'] ?? [];
        if (!is_array($tables)) {
            throw new ErrorException('invalid params', ['field' => 'tables']);
        }
        $requestFiles = $params['files'] ?? [];
        if (!is_array($requestFiles)) {
            throw new ErrorException('invalid params', ['field' => 'files']);
        }
        // 客户端 files 只能按 name 覆盖 value/path,不能新增文件
        $requestFileMap = [];
        foreach ($requestFiles as $file) {
            if (!is_array($file) || !is_string($file['name'] ?? null) || $file['name'] === '') {
                throw new ErrorException('field not found', ['field' => 'name']);
            }
            if (isset($requestFileMap[$file['name']])) {
                throw new ErrorException('duplicate file name', ['file' => $file['name']]);
            }
            $requestFileMap[$file['name']] = $file;
        }

        $specs = $this->fileSpecs($generatorItem);
        foreach ($requestFileMap as $name => $_) {
            if (!isset($specs[$name])) {
                throw new ErrorException('file not found', ['file' => $name]);
            }
        }

        $appKey = is_string($form['appKey'] ?? null) ? $form['appKey'] : '';
        $currentApps = $appKey !== '' ? Helper::getCurrentAppConfig($appKey)['apps'] : [];
        $tplParams = ['form' => $form, 'tables' => $tables, 'app' => $currentApps];
        $files = [];
        foreach ($specs as $name => $fileConfig) {
            $request = $requestFileMap[$name] ?? [];
            $value = $request['value'] ?? '';
            if ($value !== null && !is_scalar($value)) {
                throw new ErrorException('invalid params', ['field' => $name]);
            }
            $path = $request['path'] ?? '';
            if (!is_string($path) || $path === '') {
                $path = (string)($fileConfig['path'] ?? '');
            }
            // 输出路径也做根目录校验:请求不能借 .. 指向项目外
            if ($path === '' || $this->resolvePath($path) === false) {
                throw new ErrorException('path not found', ['path' => $path]);
            }
            $namespace = !empty($fileConfig['namespace']) ? (string)$fileConfig['namespace'] : $path;
            $namespace = Helper::replaceCurrentAppTemplate($namespace, $currentApps);
            $namespace = Helper::replaceTemplate($namespace, $form, 'form.');
            if (substr($namespace, -1) === '\\') {
                $namespace = substr($namespace, 0, -1);
            }
            // 模板路径只取服务端配置,不能由 form/appKey 选择文件(否则可读取项目内任意文件)
            $template = (string)($fileConfig['template'] ?? '');
            $templatePath = $this->resolvePath($template);
            if ($templatePath === false || !is_readable($templatePath)) {
                throw new ErrorException('template not found', ['template' => $template]);
            }
            $tplParams[$name] = [
                'class_name' => (string)$value,
                'path' => $path,
                'namespace' => $namespace,
                'template' => $template,
            ];
            $files[] = ['name' => $name, 'path' => $path, 'templatePath' => $templatePath];
        }

        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'name' => $file['name'],
                'path' => $file['path'],
                'content' => (new ParseTemplate())->compile($file['templatePath'], $tplParams),
            ];
        }
        return $out;
    }

    /**
     * 配置里的文件规格;重名会让后项静默覆盖前项,直接拒绝
     * @param array $item ui_generator 项
     * @return array<string,array>
     * @throws ErrorException
     */
    protected function fileSpecs(array $item): array
    {
        $specs = [];
        foreach ((array)($item['files'] ?? []) as $file) {
            if (!is_array($file) || (string)($file['name'] ?? '') === '') {
                continue;
            }
            $name = (string)$file['name'];
            if (isset($specs[$name])) {
                throw new ErrorException('duplicate file name', ['file' => $name]);
            }
            $specs[$name] = $file;
        }
        return $specs;
    }

    /**
     * 启用的 ui_generator 项,索引与 /ui/v1/config 下发的描述顺序一致
     * @return array
     */
    protected function items(): array
    {
        $list = [];
        foreach ((array)($this->config['ui_generator'] ?? []) as $item) {
            if (!is_array($item) || (isset($item['enable']) && $item['enable'] === false)) {
                continue;
            }
            $list[] = $item;
        }
        return $list;
    }

    /**
     * @param mixed $path
     * @return string|false
     */
    protected function resolvePath($path)
    {
        return DirAndFile::resolvePathWithinRoot($path, APIDOC_ROOT_PATH);
    }
}
