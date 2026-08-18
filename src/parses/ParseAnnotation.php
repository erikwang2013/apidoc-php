<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\parses;

use erikwang2013\apidoc\utils\Helper;
use erikwang2013\apidoc\exception\ErrorException;
use ReflectionMethod;
use ReflectionParameter;
use support\Log;

class ParseAnnotation
{

    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }
    /**
     * 解析非@注解的文本注释
     * @param $refMethod
     * @param $isAll bool 是否获取全部，true则将带@开头的注释也包含
     * @return array|false
     */
    public static function parseTextAnnotation($refMethod,$isAll=false): array
    {
        $annotation = $refMethod->getDocComment();
        if (empty($annotation)) {
            return [];
        }
        if (preg_match('#^/\*\*(.*)\*/#s', $annotation, $comment) === false)
            return [];
        $comment = trim($comment [1]);
        if (preg_match_all('#^\s*\*(.*)#m', $comment, $lines) === false)
            return [];
        $data = [];
        foreach ($lines[1] as $line) {
            $line = trim($line);
            if (!empty ($line) && ($isAll===true || ($isAll===false && strpos($line, '@') !== 0))) {
                $data[] = $line;
            }
        }
        return $data;
    }

    /**
     * 根据路径获取类名
     * @param $path
     * @return string
     */
    protected function getClassName($path){
        $NameArr = explode("\\", $path);
        $name    = lcfirst($NameArr[count($NameArr) - 1]);
        return $name;
    }

    /**
     * 获取并处理注解参数
     * @param $attrList
     * @return array
     */
    protected function getParameters($attrList){
        $attrs = [];
        foreach ($attrList as $item) {
            $value = "";
            $attributeName = $item->getName();
            if (strpos($attributeName, 'apidoc') === false){
                continue;
            }
            $name    = $this->getClassName($attributeName);
            $params = $item->getArguments();
            if (!empty($params)){
                if (is_array($params) && !empty($params[0]) && is_string($params[0]) && count($params)===1){
                    $value = $params[0];
                }else{
                    if (isset($params[0])){
                        $paramObj = [];
                        foreach ($params as $k=>$value) {
                            // 位置参数只取第一个映射为 name,其余数字键丢弃(与 AbstractAnnotation 行为一致)
                            if (is_int($k)) {
                                if ($k === 0) {
                                    $paramObj['name'] = $value;
                                }
                                continue;
                            }
                            $paramObj[$k]=$value;
                        }
                    }else{
                        $paramObj = $params;
                    }
                    $value = $paramObj;
                }
            }
            // 有意行为:两个全空参数(如 #[Param()] 连写两次)塌缩为单个空值。
            // 空值在下游被 !empty() 门控丢弃,塌缩与保留两空项输出无差异;
            // 反之保留两空项会产出 truthy 的 ["",""] 并触发下游 array_key_first 于字符串的 TypeError。
            if (!empty($attrs[$name]) && is_array($attrs[$name]) && Helper::arrayKeyFirst($attrs[$name])===0){
                $attrs[$name][]=$value;
            }else if(!empty($attrs[$name])){
                $attrs[$name] = [$attrs[$name],$value];
            }else{
                $attrs[$name]=$value;
            }
        }
        return $attrs;
    }

    /**
     * 获取类的注解参数
     * @param ReflectionMethod $refMethod
     * @return array
     */
    public function getClassAnnotation($refClass){
        return $this->getParameters($refClass->getAttributes());
    }

    /**
     * 获取方法的注解参数
     * @param ReflectionMethod $refMethod
     * @return array
     */
    public function getMethodAnnotation(ReflectionMethod $refMethod){
        return $this->getParameters($refMethod->getAttributes());
    }

    /**
     * 获取属性的注解参数
     * @param $property
     * @return array
     */
    public function getPropertyAnnotation($property){
        return $this->getParameters($property->getAttributes());
    }

    /**
     * 解析类的属性文本注释的var
     * @param $propertyTextAnnotations
     * @return array
     */
    protected static function parsesPropertyTextAnnotation($propertyTextAnnotations){
        $varLine = "";
        foreach ($propertyTextAnnotations as $item) {
            if (strpos($item, '@var') !== false){
                $varLine = $item;
                break;
            }
        }
        $type = "";
        $desc = "";
        if ($varLine){
            $varLineArr = preg_split('/\\s+/', $varLine);
            $type = !empty($varLineArr[1])?$varLineArr[1]:"";
            $desc = !empty($varLineArr[2])?$varLineArr[2]:"";
        }
        if (empty($desc) && strpos($propertyTextAnnotations[0], '@var') === false){
            $desc = $propertyTextAnnotations[0];
        }
        return [
            'type'=>$type,
            'desc'=>$desc,
        ];
    }

    /**
     * 获取类的属性参数
     * @param $classReflect
     * @return array
     */
    public function getClassPropertiesy($classReflect){
        $publicProperties = $classReflect->getProperties(\ReflectionProperty::IS_PUBLIC);
        $arr=[];
        foreach ($publicProperties as $property) {
              $propertyAnnotations = $this->getPropertyAnnotation($property);
              $item = [];
              if (!empty($propertyAnnotations['property'])){
                  // 有apidoc注解
                  $arr[] = $propertyAnnotations['property'];
                  continue;
              }
              $propertyTextAnnotations = self::parseTextAnnotation($property,true);
              if (empty($propertyTextAnnotations)){
                  // 无注释
                  continue;
              }
              $textAnnotationsParams=static::parsesPropertyTextAnnotation($propertyTextAnnotations);
              $textAnnotationsParams['name'] =$property->getName();
              $arr[]=$textAnnotationsParams;
        }
        return $arr;
    }



}