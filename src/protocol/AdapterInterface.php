<?php

namespace erikwang2013\apidoc\protocol;

/**
 * 协议定义适配器接口
 * @package erikwang2013\apidoc\protocol
 */
interface AdapterInterface
{
    /**
     * @return string 格式标识(proto/openapi/asyncapi/graphql/wsdl/edmx/json-schema/json-rpc/xml-rpc)
     */
    public function format(): string;

    /**
     * 解析定义内容
     * @param ProtocolContext $ctx 解析上下文
     * @param string $content 文件内容
     * @return array ['operation'=>string,'messages'=>array,'meta'=>array]
     */
    public function parse(ProtocolContext $ctx, string $content): array;
}
