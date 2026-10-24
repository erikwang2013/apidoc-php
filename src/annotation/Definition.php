<?php

namespace erikwang2013\apidoc\annotation;

use Attribute;
use erikwang2013\apidoc\utils\AbstractAnnotation;

/**
 * 协议定义文件(由定义解析计划消费)
 * @package erikwang2013\apidoc\annotation
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Definition extends AbstractAnnotation
{
    /**
     * @var string 定义文件路径(位置参数会落在继承的 name 上,由定义解析归一)
     */
    public $file = '';

    /**
     * @var string 定义格式
     */
    public $format = '';

    /**
     * @var string 操作名
     */
    public $operation = '';

    /**
     * @var string 消息名
     */
    public $message = '';

    /**
     * @var string 消息方向: request/response/event
     */
    public $direction = '';

    /**
     * @var string 内联样例
     */
    public $sample = '';

    /**
     * @var string 样例文件
     */
    public $sampleFile = '';

    /**
     * @var string 样例编码
     */
    public $sampleEncoding = '';

    /**
     * @var bool 严格模式
     */
    public $strict = true;

    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
