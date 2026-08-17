<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 作者
 * @package erikwang2013\apidoc\annotation
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Author extends AbstractAnnotation
{
    /**
     * @param string $value 作者名称
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
