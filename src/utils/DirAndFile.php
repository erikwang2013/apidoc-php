<?php

namespace erikwang2013\apidoc\utils;

use erikwang2013\apidoc\exception\ErrorException;

class DirAndFile
{


    public static function getClassList($dir){
        if ($handle = opendir($dir)) {
            $file_list=[];
            while (false !== ($file = readdir($handle))) {
                if($file=='..' || $file=='.') continue;
                $filePath = static::formatPath($dir.'/'.$file,"/");
                if(is_file($filePath)) {
                    if ('php' !== pathinfo($filePath, \PATHINFO_EXTENSION)) {
                        continue;
                    }
                    $classes = self::findClasses($filePath);
                    if (!empty($classes) && count($classes)){
                        $file_list[] = [
                            'name'=>$classes[0],
                            'path'=>$filePath
                        ];
                    }
                    continue;
                }
                $file_list[$file] = static::getClassList($filePath);
                foreach($file_list[$file] as $infile) {
                    $file_list[] = $infile;
                }
                unset($file_list[$file]);
            }
            closedir($handle);
            return $file_list;
        }
        return [];
    }
    public static function getFileList($path){
        if(is_dir($path)) {
            $dirList = scandir($path);
            $list = [];
            foreach ($dirList as $dir) {
                if ($dir == '.' || $dir == '..') {
                    continue;
                }
                $sub_path = DirAndFile::formatPath($path . '/' . $dir, "/");
                if (is_file($sub_path)){
                    $list[]=[
                        'name'=>$dir,
                        'path'=>$sub_path
                    ];;
                }
            }
            return $list;
        }
        return [];
    }

    public static function formatPath($path,$type="/"){
        if ($type==="/"){
            $path = str_replace("\\","/",$path);
        }else{
            $path = str_replace("/","\\",$path);
            $path = str_replace("\\\\","\\",$path);
            $endStr = substr($path, -1);
            if ($endStr=='\\'){
                $path = substr($path,0,strlen($path)-1);
            }
        }
        return $path;
    }

    /**
     * 解析路径并校验必须位于根目录内(防路径穿越)
     * 词法规范化(按 / 与 \ 分段，遇 .. 弹出上一段)后，结果必须位于 $root 内(带分隔符比较，防 /app-evil 前缀误判)
     * 注意: 词法校验不跟随软链(项目内指向根外的软链不受此检查约束，属已知边界)
     * @param mixed $path 相对于 $root 的路径(允许以 / 开头)
     * @param string $root 根目录
     * @return string|false 非法或越界时返回 false
     */
    public static function resolvePathWithinRoot($path, string $root)
    {
        if (!is_string($path)) {
            return false;
        }
        $rootPath = realpath($root);
        if ($rootPath === false) {
            return false;
        }
        $rootPath = rtrim(static::formatPath($rootPath, "/"), "/");
        $fullPath = static::formatPath($rootPath . "/" . $path, "/");
        // 词法规范化
        $segments = [];
        foreach (explode("/", $fullPath) as $segment) {
            if ($segment === "" || $segment === ".") {
                continue;
            }
            if (strpos($segment, "\0") !== false) {
                return false;
            }
            if ($segment === "..") {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        $realPath = (strpos($fullPath, "/") === 0 ? "/" : "") . implode("/", $segments);
        // 必须位于根目录内(带分隔符比较)
        if (strpos($realPath . "/", $rootPath . "/") !== 0) {
            return false;
        }
        return $realPath;
    }

    private static function findClasses($path)
    {
        $contents = file_get_contents($path);
        $tokens = token_get_all($contents);

        $nsTokens = [\T_STRING => true, \T_NS_SEPARATOR => true];
        if (\defined('T_NAME_QUALIFIED')) {
            $nsTokens[T_NAME_QUALIFIED] = true;
        }

        $classes = [];

        $namespace = '';
        for ($i = 0; isset($tokens[$i]); ++$i) {
            $token = $tokens[$i];

            if (!isset($token[1])) {
                continue;
            }

            $class = '';

            switch ($token[0]) {
                case \T_NAMESPACE:
                    $namespace = '';
                    // If there is a namespace, extract it
                    while (isset($tokens[++$i][1])) {
                        if (isset($nsTokens[$tokens[$i][0]])) {
                            $namespace .= $tokens[$i][1];
                        }
                    }
                    $namespace .= '\\';
                    break;
                case \T_CLASS:
                case \T_INTERFACE:
                case \T_TRAIT:
                    // Skip usage of ::class constant
                    $isClassConstant = false;
                    for ($j = $i - 1; $j > 0; --$j) {
                        if (!isset($tokens[$j][1])) {
                            break;
                        }

                        if (\T_DOUBLE_COLON === $tokens[$j][0]) {
                            $isClassConstant = true;
                            break;
                        } elseif (!\in_array($tokens[$j][0], [\T_WHITESPACE, \T_DOC_COMMENT, \T_COMMENT])) {
                            break;
                        }
                    }

                    if ($isClassConstant) {
                        break;
                    }

                    // Find the classname
                    while (isset($tokens[++$i][1])) {
                        $t = $tokens[$i];
                        if (\T_STRING === $t[0]) {
                            $class .= $t[1];
                        } elseif ('' !== $class && \T_WHITESPACE === $t[0]) {
                            break;
                        }
                    }

                    $classes[] = ltrim($namespace.$class, '\\');
                    break;
                default:
                    break;
            }
        }

        return $classes;
    }


    /**
     * 读取文件内容
     * @param $fileName
     * @return false|string
     */
    public static function getFileContent(string $fileName): string
    {
        if (!is_file($fileName) || !is_readable($fileName)) {
            return "";
        }
        // 原实现 fopen+fread(filesize):文件为空时 fread 长度为 0 会报错
        return (string) file_get_contents($fileName);
    }

    /**
     * 保存文件
     * @param $path
     * @param $str_tmp
     * @return bool
     */
    public static function createFile(string $path, string $str_tmp): bool
    {
        $pathArr = explode("/", $path);
        unset($pathArr[count($pathArr) - 1]);
        $dir = implode("/", $pathArr);
        if (!file_exists($dir)) {
            mkdir($dir, 0775, true);
        }
        // 库代码不允许 die,写入失败时抛异常由上层处理
        $fp = @fopen($path, "w");
        if ($fp === false) {
            throw new ErrorException("Unable to open file: " . $path);
        }
        fwrite($fp, $str_tmp); //存入内容
        fclose($fp);
        return true;
    }

    /**
     * 判断文件是否存在后，删除
     * @access private
     * @param string $path
     * @return bool
     */
    public static function unlink(string $path): bool
    {
        try {
            return is_file($path) && unlink($path);
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function deleteDir($path) {
        if (!is_dir($path)) {
            return false;
        }
        $open = opendir($path);
        if (!$open) {
            return false;
        }
        while (($v = readdir($open)) !== false) {
            if ('.' == $v || '..' == $v) {
                continue;
            }
            $item = $path . '/' . $v;
            if (is_file($item)) {
                unlink($item);
                continue;
            }
           static::deleteDir($item);
        }
        closedir($open);
        return rmdir($path);
    }
}