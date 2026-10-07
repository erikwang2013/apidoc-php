<?php

namespace erikwang2013\apidoc\generator;
use erikwang2013\apidoc\exception\ErrorException;
use erikwang2013\apidoc\generator\ParseTemplate;
use erikwang2013\apidoc\utils\DirAndFile;
use erikwang2013\apidoc\utils\Helper;

class Index
{
    protected $config = [];

    protected $middlewares = [];

    protected $databaseConfig = [
        // 数据库表前缀
        'prefix'          => '',
        // 数据库编码，默认为utf8
        'charset'         =>  'utf8',
        // 数据库引擎，默认为 InnoDB
        'engine'          => 'InnoDB',
    ];

    protected  $systemDefaultValues = [
        'CURRENT_TIMESTAMP'
    ];

    public function __construct($config)
    {
        $this->config = $config;
        if (!empty($config['database'])){
            if (!empty($config['database']['prefix'])){
                $this->databaseConfig['prefix'] = $config['database']['prefix'];
            }
            if (!empty($config['database']['charset'])){
                $this->databaseConfig['charset'] = $config['database']['charset'];
            }
            if (!empty($config['database']['engine'])){
                $this->databaseConfig['engine'] = $config['database']['engine'];
            }
        }
    }

    public function create($params){
        $appKey = $params['form']['appKey'];
        $currentAppConfig = Helper::getCurrentAppConfig($appKey);
        $currentApps = $currentAppConfig['apps'];
        $currentApp  = $currentAppConfig['appConfig'];
        // 生成器未配置时拒绝执行
        $index = $params['index'] ?? null;
        if (empty($this->config['generator']) || !is_scalar($index) || !isset($this->config['generator'][$index])){
            throw new ErrorException("generator config is empty");
        }
        $generatorItem = $this->config['generator'][$index];

        $checkParams = $this->checkFilesAndHandleParams($generatorItem,$params,$currentApps);
        $tplParams = $checkParams['tplParams'];
        // 注册中间件并执行before
        if (!empty($generatorItem['middleware']) && count($generatorItem['middleware'])){
            foreach ($generatorItem['middleware'] as $middleware) {
                $instance = new $middleware;
                $this->middlewares[] = $instance;
                if (method_exists($instance, 'before')) {
                    $middlewareRes = $instance->before($tplParams);
                    if (!empty($middlewareRes)){
                        $tplParams = $middlewareRes;
                    }
                }
            }
        }

        $this->createModels($checkParams['createModels'],$tplParams);
        $this->createFiles($checkParams['createFiles'],$tplParams);
         //执行after
        if (count($this->middlewares)){
            foreach ($this->middlewares as $middleware) {
                if (method_exists($middleware, 'after')) {
                    $middleware->after($tplParams);
                }
            }
        }
        return $tplParams;
    }

    /**
     * 验证文件及处理模板数据
     * @param $generatorItem
     * @param $params
     * @param $currentApps
     * @return array
     */
    protected function checkFilesAndHandleParams($generatorItem,$params,$currentApps){
        // 组成模板参数
        $tplParams=[
            'form'=>$params['form'],
            'tables'=>$params['tables'],
            'app'=>$currentApps
        ];
        $createFiles = [];
        if (!empty($params['files']) && count($params['files'])>0) {
            $files = $params['files'];
            foreach ($files as $file) {
                $fileConfig = Helper::getArrayFind($generatorItem['files'], function ($item) use ($file) {
                    if ($file['name'] === $item['name']) {
                        return true;
                    }
                    return false;
                });

                $filePath = $file['path'];
                if (!empty($fileConfig['namespace'])) {
                    $fileNamespace = Helper::replaceCurrentAppTemplate($fileConfig['namespace'], $currentApps);
                    $fileNamespace = Helper::replaceTemplate($fileNamespace, $params['form'],"form.");
                } else {
                    $fileNamespace = $filePath;
                }
                $fileNamespaceEndStr = substr($fileNamespace, -1);
                if ($fileNamespaceEndStr == '\\') {
                    $fileNamespace = substr($fileNamespace, 0, strlen($fileNamespace) - 1);
                }
                $template = Helper::replaceCurrentAppTemplate($fileConfig['template'], $currentApps);
                $template = Helper::replaceTemplate($template, $params['form'],"form.");
                $tplParams[$file['name']] = [
                    'class_name' => $file['value'],
                    'path' => $filePath,
                    'namespace' => $fileNamespace,
                    'template' => $template
                ];

                // 验证模板是否存在(路径必须位于 APIDOC_ROOT_PATH 内，防路径穿越读取)
                $templatePath = $this->resolvePath($template);
                if ($templatePath === false || is_readable($templatePath) == false) {
                    throw new ErrorException("template not found",  [
                        'template' => $template
                    ]);
                }
                // 验证是否已存在生成的文件(路径必须位于 APIDOC_ROOT_PATH 内，防路径穿越写入)
                $fileFullPath = $this->resolvePath($filePath);
                if ($fileFullPath === false) {
                    throw new ErrorException("path not found",  [
                        'path' => $filePath
                    ]);
                }
                $type = "folder";
                if (strpos($fileFullPath, '.php') !== false) {
                    // 路径为php文件，则验证文件是否存在
                    if (is_readable($fileFullPath) == false) {
                        throw new ErrorException("file not exists",  [
                            'filepath' => $filePath
                        ]);
                    }
                    $type = "file";
                } else {
                    $fileName = !empty($file['value']) ? $file['value'] : "";
                    // 拼接文件名后重新校验，防止 value 中的 .. 穿越
                    $fileFullPath = $this->resolvePath($filePath . "/" . $fileName . ".php");
                    if ($fileFullPath === false) {
                        throw new ErrorException("path not found",  [
                            'path' => $filePath
                        ]);
                    }
                    if (is_readable($fileFullPath)) {
                        throw new ErrorException("file already exists",[
                            'filepath' => DirAndFile::formatPath($filePath) . $fileName . ".php"
                        ]);
                    }
                }
                $createFiles[] = [
                    'fileFullPath' => $fileFullPath,
                    'template' => $template,
                    'templatePath'=>$templatePath,
                    'type' => $type
                ];
            }
        }

        $createModels = $this->checkModels($generatorItem,$tplParams,$currentApps,$params);
        $tplParams['tables'] = $createModels['tables'];
        return [
            'tplParams'=>$tplParams,
            'createFiles'=>$createFiles,
            'createModels' =>$createModels['createModels']
        ];
    }

    /**
     * 验证模型及表
     * @param $generatorItem
     * @param $tplParams
     * @return array
     */
    protected function checkModels($generatorItem,$tplParams,$currentApps,$params=[]){
        if (empty($this->config['database_query_function'])){
            throw new ErrorException("not datatable_query_function config");
        }

        $res="";
        $tabls = $tplParams['tables'];
        $newTables = [];
        $createModels = [];
        if (!empty($tabls) && count($tabls)){
            foreach ($tabls as $k=>$table) {
                $tableConfig = $generatorItem['table'];
                // 模型相关变量默认空值，仅 model_name 非空时组装，避免使用未定义变量
                $fileFullPath="";
                $namespace = "";
                $path = "";
                $template = "";
                $templatePath = "";
                if (!empty($table['model_name'])){
                    $this->assertIdentifier($table['model_name'],'model_name');
                    $namespace = Helper::replaceCurrentAppTemplate($tableConfig['items'][$k]['namespace'], $currentApps);
                    $namespace = Helper::replaceTemplate($namespace, $params['form'],"form.");
                    $path = Helper::replaceCurrentAppTemplate($tableConfig['items'][$k]['path'], $currentApps);
                    $path = Helper::replaceTemplate($path, $params['form'],"form.");
                    $template = $tableConfig['items'][$k]['template'];

                    // 验证模板是否存在
                    $templatePath = $this->resolvePath($template);
                    if ($templatePath === false || is_readable($templatePath) == false) {
                        throw new ErrorException("template not found", [
                            'template' => $template
                        ]);
                    }
                    $tplParams['tables'][$k]['class_name'] =$table['model_name'];
                    // 验证模型是否已存在(路径必须位于 APIDOC_ROOT_PATH 内，防路径穿越写入)
                    $fileName = $table['model_name'];
                    $fileFullPath = $this->resolvePath($path . "/" . $fileName . ".php");
                    if ($fileFullPath === false) {
                        throw new ErrorException("path not found", [
                            'path' => $path
                        ]);
                    }
                    if (is_readable($fileFullPath)) {
                        throw new ErrorException("file already exists", [
                            'filepath' => DirAndFile::formatPath($path) . "/" . $fileName . ".php"
                        ]);
                    }
                    $table['namespace']=$namespace;
                    $table['path']=$path;
                    $table['model_path']=$path;
                }
                // 验证表是否存在
                if ($table['table_name']){
                    $this->assertIdentifier($table['table_name'],'table_name');
                    $table_name = $this->databaseConfig['prefix'].$table['table_name'];
                    $isTable = $this->config['database_query_function']('SHOW TABLES LIKE '."'".$table_name."'");
                    if ($isTable){
                        throw new ErrorException("datatable already exists",  [
                            'table' => $table_name
                        ]);
                    }
                }
                $newTables[]=$table;
                $createModels[]=[
                    'namespace'=>$namespace,
                    'template'=>$template,
                    'path'=>$path,
                    'templatePath' =>$templatePath,
                    'table'=>$table,
                    'fileFullPath'=>$fileFullPath
                ];
            }
        }
        return ['createModels'=>$createModels,'tables'=>$newTables];

    }

    /**
     * 创建文件
     * @param $createFiles
     * @param $tplParams
     * @return mixed
     */
    protected function createFiles($createFiles,$tplParams){

        if (!empty($createFiles) && count($createFiles)>0){
            foreach ($createFiles as $fileItem) {
                $html = (new ParseTemplate())->compile($fileItem['templatePath'],$tplParams);
                if ($fileItem['type'] === "file"){
                    // 路径为文件，则添加到该文件
                    $pathFileContent = DirAndFile::getFileContent($fileItem['fileFullPath']);
                    $content = $pathFileContent."\r\n".$html;
                    DirAndFile::createFile($fileItem['fileFullPath'],$content);
                }else{
                    DirAndFile::createFile($fileItem['fileFullPath'],$html);
                }
            }
        }
        return $tplParams;
    }

    /**
     * 创建模型文件
     * @param $createModels
     * @param $tplParams
     */
    protected function createModels($createModels,$tplParams){
        if (!empty($createModels) && count($createModels)>0){
            foreach ($createModels as $k=>$item) {
                $table = $item['table'];
                if (!empty($table['table_name'])){
                    $res =  $this->createTable($table);
                }
                if (!empty($table['model_name'])){
                    $tplParams['tables'][$k]['class_name'] =$table['model_name'];
                    $html = (new ParseTemplate())->compile($item['templatePath'],$tplParams);
                    DirAndFile::createFile($item['fileFullPath'],$html);
                }

            }
        }
    }

    /**
     * 创建数据表
     * @return mixed
     */
    protected function createTable($table){
        $datas = $table['datas'];
        $comment= "";
        if (!empty($table['table_comment'])){
            $comment =$table['table_comment'];
        }
        $table_name = $this->databaseConfig['prefix'].$table['table_name'];
        $table_data = '';
        $main_keys = '';
        $defaultNullTypes = ['timestamp'];
        foreach ($datas as $k=>$item){
            if (!empty($item['not_table_field'])){
                continue;
            }
            // 字段名/类型/长度均为请求参数，需严格校验后才可拼入SQL
            $item['field'] = $this->assertIdentifier($item['field'],'field');
            $item['type'] = $this->assertIdentifier($item['type'],'type','/^[A-Za-z0-9_(), ]+$/');
            $table_field="`".$item['field']."` ".$item['type'];
            if (!empty($item['length'])){
                $item['length'] = $this->assertIdentifier($item['length'],'length','/^\d+(,\d+)?$/');
                $table_field.="(".$item['length'].")";
            }

            if (!empty($item['main_key'])){
                $main_keys.=$item['field'];
                $table_field.=" NOT NULL";
            }else if (!empty($item['not_null'])){
                $table_field.=" NOT NULL";
            }
            if (!empty($item['incremental']) && !empty($item['main_key'])){
                $table_field.=" AUTO_INCREMENT";
            }
            if (!empty($item['default']) || (isset($item['default']) && $item['default']=="0")){
                $defaultValue = "'".$this->escapeSqlString($item['default'])."'";
                if (in_array($item['default'],$this->systemDefaultValues)){
                    $defaultValue = $item['default'];
                }
                $table_field.=" DEFAULT ".$defaultValue."";
            }else if (!empty($item['main_key']) && empty($item['not_null'])){
                $table_field.=" DEFAULT NULL";
            }else if (in_array($item['type'],$defaultNullTypes) && empty($item['not_null'])){
                $table_field.=" NULL DEFAULT NULL";
            }
            $fh = $k < (count($datas)-1)?",":"";
            $table_field.=" COMMENT '".$this->escapeSqlString($item['desc'])."'".$fh;
            $table_data.=$table_field;
        }
        $primaryKey = "";
        if (!empty($main_keys)){
            $table_data.=",";
            $primaryKey = "PRIMARY KEY (`$main_keys`)";
        }

        $charset = $this->databaseConfig['charset'];
        $engine = $this->databaseConfig['engine'];
        $sql = "CREATE TABLE IF NOT EXISTS `$table_name` (
        $table_data
        $primaryKey
        ) ENGINE=$engine DEFAULT CHARSET=$charset COMMENT='".$this->escapeSqlString($comment)."' AUTO_INCREMENT=1 ;";

        try {
            $this->config['database_query_function']($sql);
            return true;
        } catch (\Exception $e) {
            throw new ErrorException("datatable create error",  [
                'table' => $table_name,
                'message'=>$e->getMessage(),
                'sql'=>$sql
            ]);
        }
    }

    /**
     * 校验请求参数是否为合法的SQL标识符/片段，不合法直接抛错
     * @param mixed $value
     * @param string $name 参数名(错误提示用)
     * @param string $pattern
     * @return string
     */
    protected function assertIdentifier($value,$name,$pattern='/^[A-Za-z0-9_]+$/'){
        if (!is_scalar($value) || !preg_match($pattern, (string)$value)){
            throw new ErrorException("classname error", [
                'classname' => $name
            ]);
        }
        return (string)$value;
    }

    /**
     * 转义SQL字符串字面量中的 \ 与 '
     * @param mixed $value
     * @return string
     */
    protected function escapeSqlString($value){
        if (!is_scalar($value)){
            return "";
        }
        return str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$value);
    }

    /**
     * 解析生成路径(防路径穿越)，统一走 DirAndFile::resolvePathWithinRoot
     * @param string $path 相对于 APIDOC_ROOT_PATH 的路径(允许以 / 开头)
     * @return string|false 非法或越界时返回 false
     */
    protected function resolvePath($path){
        return DirAndFile::resolvePathWithinRoot($path, APIDOC_ROOT_PATH);
    }
}