<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug;

/**
 * 调试驱动接口
 * @package erikwang2013\apidoc\protocol\debug
 */
interface DebugDriverInterface
{
    /**
     * 驱动名(与 Debug.driver 对应)
     * @return string
     */
    public function name(): string;

    /**
     * 调试模式:browser-http|browser-websocket|browser-sse|relay-unary|relay-stream
     * @return string
     */
    public function mode(): string;

    /**
     * 驱动字段定义
     * @param array $context 目标/协议等上下文
     * @return array
     */
    public function fields(array $context): array;

    /**
     * 发送一次调试请求
     * @param array $input 请求输入
     * @param array $context 目标/超时/限制等上下文
     * @return array
     */
    public function send(array $input, array $context): array;
}
