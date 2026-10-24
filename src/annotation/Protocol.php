<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 协议身份
 * @package erikwang2013\apidoc\annotation
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Protocol extends AbstractAnnotation
{
    /**
     * @var string 协议版本
     */
    public $version = '';

    /**
     * @var string 传输层: http/tcp/udp/http2/unix
     */
    public $transport = '';

    /**
     * @var string 协议模式(如 unary/pub_sub),缺省从 ProtocolCatalog 推导
     */
    public $mode = '';

    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
