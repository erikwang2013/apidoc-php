<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug;

/**
 * 流式调试驱动接口
 * @package erikwang2013\apidoc\protocol\debug
 */
interface StreamingDriverInterface extends DebugDriverInterface
{
    /**
     * 建立流式会话
     * @param array $input 请求输入
     * @param array $context 目标/超时/限制等上下文
     * @return array
     */
    public function start(array $input, array $context): array;

    /**
     * 拉取会话消息
     * @param string $sessionId 会话 id
     * @param array $context 目标/超时/限制等上下文
     * @return array
     */
    public function poll(string $sessionId, array $context): array;

    /**
     * 结束流式会话
     * @param string $sessionId 会话 id
     * @param array $context 目标/超时/限制等上下文
     * @return void
     */
    public function stop(string $sessionId, array $context): void;
}
