<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 分组
 * @package erikwang2013\apidoc\annotation
 * @Annotation
 * @Target({"CLASS"})
 */
#[Attribute(Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Group extends AbstractAnnotation
{
    /**
     * @param string $name 分组
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
