<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use Doctrine\Common\Annotations\Annotation;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 参数类型
 * @package erikwang2013\apidoc\annotation
 * @Annotation
 * @Target({"METHOD"})
 */
#[Attribute(Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ParamType extends AbstractAnnotation
{

    const formdata = 'formdata';

    /**
     * @param string $value 参数类型，formdata
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
