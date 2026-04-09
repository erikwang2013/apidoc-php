<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use Doctrine\Common\Annotations\Annotation;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 请求类型
 * @package erikwang2013\apidoc\annotation
 * @Annotation
 * @Target({"METHOD"})
 */
#[Attribute(Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Method extends AbstractAnnotation
{
    const GET = 'GET';

    const POST = 'POST';

    const PUT = 'PUT';

    const PATCH = 'PATCH';

    const DELETE = 'DELETE';

    /**
     * @param string|array $value 请求类型
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
