<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use Doctrine\Common\Annotations\Annotation;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * Tag
 * @package erikwang2013\apidoc\annotation
 * @Annotation
 * @Target({"METHOD"})
 */
#[Attribute(Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Tag extends AbstractAnnotation
{
    /**
     * @param string $value Tag
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
