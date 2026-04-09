<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use Doctrine\Common\Annotations\Annotation;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 描述
 * @package erikwang2013\apidoc\annotation
 * @Annotation
 * @Target({"METHOD","CLASS"})
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Desc extends AbstractAnnotation
{
    /**
     * @param string $value 描述
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
