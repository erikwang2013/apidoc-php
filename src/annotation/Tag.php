<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * Tag
 * @package erikwang2013\apidoc\annotation
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
