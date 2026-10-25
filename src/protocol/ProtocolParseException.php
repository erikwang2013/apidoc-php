<?php

namespace erikwang2013\apidoc\protocol;

/**
 * 协议定义解析异常(带源文件行列号)
 * @package erikwang2013\apidoc\protocol
 */
class ProtocolParseException extends \RuntimeException
{
    /**
     * @var int
     */
    protected $parseLine;

    /**
     * @var int
     */
    protected $parseColumn;

    public function __construct(string $message, int $line = 0, int $column = 0)
    {
        parent::__construct($message);
        $this->parseLine = $line;
        $this->parseColumn = $column;
    }

    public function line(): int
    {
        return $this->parseLine;
    }

    public function column(): int
    {
        return $this->parseColumn;
    }
}
