<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use Doctrine\Common\Annotations\Annotation;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 排除Ref的字段
 * @package erikwang2013\apidoc\annotation
 * @Annotation
 * @Target({"METHOD"})
 */
#[Attribute(Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class WithoutField extends AbstractAnnotation
{
    /**
     * @param string|array $value 排除Ref的字段，逗号分割
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
