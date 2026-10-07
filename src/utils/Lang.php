<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\utils;

use erikwang2013\apidoc\exception\ErrorException;

class Lang
{

    /**
     * 获取多语言变量值
     * @param $string
     * @return mixed
     */
    public static function getLang($string,$config="") {
        if (!$string){
            return $string;
        }
        if (empty($config)){
            $config = ConfigProvider::get();
        }
        if (empty($config["lang_get_function"])){
            return $string;
        }
        $langGetFunction = $config["lang_get_function"];
        if (empty($langGetFunction)){
            return $string;
        }
        if (is_string($string) && strpos($string, 'lang(') !== false) {
            // 非贪婪匹配,取第一个 lang(...);preg_match 成功返回 1,失败返回 0
            if (preg_match('#lang\((.*?)\)#s', $string, $key) === 1){
                $langKey = $key && count($key)>1 ? trim($key[1]):"";
                if (!empty($langKey)){
                    return $langGetFunction($langKey);
                }
            }
        }
        return $string;
    }


}