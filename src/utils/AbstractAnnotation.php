<?php

declare (strict_types=1);

namespace erikwang2013\apidoc\utils;

abstract class AbstractAnnotation
{

    public $name;

    public function __construct(...$value)
    {
        $formattedValue = $this->formatParams($value);
        foreach ($formattedValue as $key => $val) {
            if (is_int($key)) {
                // 数字键跳过,避免 PHP 8.2+ 动态属性 deprecated;位置参数由 formatParams 取 $value[0] 映射为 name
                continue;
            }
            if ($key=="value" && !property_exists($this, $key)){
                $this->name = $val;
            }else{
                $this->{$key} = $val;
            }
        }
    }

    protected function formatParams($value): array
    {
        if (isset($value[0])) {
            $value = $value[0];
        }
        if (!is_array($value)) {
            $value = ['name' => $value];
        }
        return $value;
    }
}
