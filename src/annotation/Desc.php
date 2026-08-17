<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 描述
 * @package erikwang2013\apidoc\annotation
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
