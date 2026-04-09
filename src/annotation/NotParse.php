<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 标记不解析的控制器/方法
 * @package erikwang2013\apidoc\annotation
 * @Annotation
 * @Target({"METHOD","CLASS"})
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NotParse extends AbstractAnnotation
{
    /**
     * @param string $value 不解析
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
