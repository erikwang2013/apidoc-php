<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use Doctrine\Common\Annotations\Annotation;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 成功响应体
 * @package erikwang2013\apidoc\annotation
 * @Annotation
 * @Target({"METHOD","ANNOTATION"})
 */
#[Attribute(Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ResponseStatus extends AbstractAnnotation
{

    /**
     * 状态码
     * @var number
     */
    public $name;

    /**
     * 描述
     * @var string
     */
    public $desc;

    public $contentType;

    /**
     * @param string $name 状态码
     * @param string $desc 描述
     * @param string $contentType 内容类型
     */
    public function __construct(
        $name = '',
        string $desc = '',
        string $contentType = '',
        ...$attrs
    )
    {
        parent::__construct(...func_get_args());
    }

}
