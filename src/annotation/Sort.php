<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 排序
 * @package erikwang2013\apidoc\annotation
 */
#[Attribute(Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Sort extends AbstractAnnotation
{
    /**
     * @param string|int $value 排序
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
