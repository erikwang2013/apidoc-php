<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 接口Url
 * @package erikwang2013\apidoc\annotation
 */
#[Attribute(Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Url extends AbstractAnnotation
{
    /**
     * @param string $value 接口Url
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
