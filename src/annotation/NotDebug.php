<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 关闭接口调试
 * @package erikwang2013\apidoc\annotation
 * @Annotation
 * @Target({"METHOD","CLASS"})
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NotDebug extends AbstractAnnotation
{

    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
