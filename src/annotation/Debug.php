<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 调试方式
 * @package erikwang2013\apidoc\annotation
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Debug extends AbstractAnnotation
{
    /**
     * @var string 驱动名
     */
    public $driver = '';

    /**
     * @var string 调试目标(host:port 或 URL)
     */
    public $target = '';

    /**
     * @var string 请求体编码: text/json/hex/base64
     */
    public $encoding = '';

    /**
     * @var int|string 超时秒数
     */
    public $timeout = 0;

    /**
     * @var array 协议扩展信息(responseEncoding/hints 等)
     */
    public $meta = [];

    /**
     * @var array 驱动额外字段
     */
    public $fields = [];

    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
