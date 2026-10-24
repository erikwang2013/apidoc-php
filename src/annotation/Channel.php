<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 协议通道
 * @package erikwang2013\apidoc\annotation
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Channel extends AbstractAnnotation
{
    /**
     * @var string 通道地址(主题/路径/服务方法)
     */
    public $address = '';

    /**
     * @var string 动作: call/publish/subscribe/send/receive/request/response/notify/webhook
     */
    public $action = '';

    /**
     * @var array 协议专属信息(exchange/routingKey/qos/service/method 等)
     */
    public $meta = [];

    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
