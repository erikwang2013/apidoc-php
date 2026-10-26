<?php

namespace erikwang2013\apidoc\protocol;

/**
 * 协议元数据目录
 * @package erikwang2013\apidoc\protocol
 */
class ProtocolCatalog
{
    /**
     * P1 协议带专用驱动；P2 协议回退到通用驱动(WebRTC 暂无可用回退)
     * @var array
     */
    protected static $protocols = [
        'http' => ['label' => 'HTTP', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'http', 'defaultMode' => 'browser-http'],
        'rest' => ['label' => 'REST', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'http', 'defaultMode' => 'browser-http'],
        'soap' => ['label' => 'SOAP', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'http', 'defaultMode' => 'browser-http'],
        'graphql' => ['label' => 'GraphQL', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'http', 'defaultMode' => 'browser-http'],
        'json-rpc' => ['label' => 'JSON-RPC', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'http', 'defaultMode' => 'browser-http'],
        'xml-rpc' => ['label' => 'XML-RPC', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'http', 'defaultMode' => 'browser-http'],
        'webhooks' => ['label' => 'Webhooks', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'http', 'defaultMode' => 'browser-http'],
        'odata' => ['label' => 'OData', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'http', 'defaultMode' => 'browser-http'],
        'mcp' => ['label' => 'MCP', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'http', 'defaultMode' => 'browser-http'],
        'a2a' => ['label' => 'A2A', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'http', 'defaultMode' => 'browser-http'],
        'websocket' => ['label' => 'WebSocket', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'websocket', 'defaultMode' => 'browser-websocket'],
        'sse' => ['label' => 'SSE', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'sse', 'defaultMode' => 'browser-sse'],
        'mqtt' => ['label' => 'MQTT', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'mqtt', 'defaultMode' => 'relay-stream'],
        'stomp' => ['label' => 'STOMP', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'stomp', 'defaultMode' => 'relay-stream'],
        'coap' => ['label' => 'CoAP', 'transport' => 'udp', 'http' => false, 'defaultDriver' => 'coap', 'defaultMode' => 'relay-unary'],
        'modbus-tcp' => ['label' => 'Modbus TCP', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'modbus-tcp', 'defaultMode' => 'relay-unary'],
        'iso8583' => ['label' => 'ISO 8583', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'iso8583', 'defaultMode' => 'relay-unary'],
        'rtsp' => ['label' => 'RTSP', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'rtsp', 'defaultMode' => 'relay-unary'],
        'sip' => ['label' => 'SIP', 'transport' => 'udp', 'http' => false, 'defaultDriver' => 'sip', 'defaultMode' => 'relay-unary'],
        'hls' => ['label' => 'HLS', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'hls', 'defaultMode' => 'browser-http'],
        'grpc' => ['label' => 'gRPC', 'transport' => 'http2', 'http' => false, 'defaultDriver' => 'grpc', 'defaultMode' => 'relay-unary'],
        'hessian' => ['label' => 'Hessian', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'hessian', 'defaultMode' => 'relay-unary'],
        'tcp' => ['label' => 'TCP', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'udp' => ['label' => 'UDP', 'transport' => 'udp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'unix-socket' => ['label' => 'Unix Domain Socket', 'transport' => 'unix', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'raw-socket' => ['label' => 'Raw Socket', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'thrift' => ['label' => 'Thrift', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'thrift', 'defaultMode' => 'relay-unary'],
        'dubbo' => ['label' => 'Dubbo', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'dubbo', 'defaultMode' => 'relay-unary'],
        'amqp' => ['label' => 'AMQP', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'kafka' => ['label' => 'Kafka', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        // socket.io 回退到浏览器 WebSocket 面板,Engine.IO 驱动仍为 P2
        'socket.io' => ['label' => 'Socket.IO', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'websocket', 'defaultMode' => 'browser-websocket'],
        'xmpp' => ['label' => 'XMPP', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'webrtc' => ['label' => 'WebRTC', 'transport' => 'udp', 'http' => false, 'defaultDriver' => '', 'defaultMode' => '', 'debugReason' => 'WebRTC 需要手动 SDP/信令（P2）'],
        'webrtc-datachannel' => ['label' => 'WebRTC DataChannel', 'transport' => 'udp', 'http' => false, 'defaultDriver' => '', 'defaultMode' => '', 'debugReason' => 'WebRTC 需要手动 SDP/信令（P2）'],
        'rtmp' => ['label' => 'RTMP', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'rtp' => ['label' => 'RTP', 'transport' => 'udp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'rtcp' => ['label' => 'RTCP', 'transport' => 'udp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'lwm2m' => ['label' => 'LwM2M', 'transport' => 'udp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'opc-ua' => ['label' => 'OPC UA', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'dnp3' => ['label' => 'DNP3', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'iec-104' => ['label' => 'IEC 104', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'bacnet-ip' => ['label' => 'BACnet/IP', 'transport' => 'udp', 'http' => false, 'defaultDriver' => 'raw', 'defaultMode' => 'relay-unary'],
        'binary-body' => ['label' => 'Binary Body', 'transport' => 'http', 'http' => true, 'defaultDriver' => 'http-relay', 'defaultMode' => 'relay-unary'],
        'websocket-binary' => ['label' => 'WebSocket Binary', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'websocket', 'defaultMode' => 'browser-websocket'],
        // publish/send 由 action 规则改判 relay-unary
        'stomp-binary' => ['label' => 'STOMP Binary', 'transport' => 'tcp', 'http' => false, 'defaultDriver' => 'stomp', 'defaultMode' => 'relay-stream'],
        'http2' => ['label' => 'HTTP/2', 'transport' => 'http2', 'http' => true, 'defaultDriver' => 'http2', 'defaultMode' => 'relay-unary'],
        'http3' => ['label' => 'HTTP/3', 'transport' => 'http3', 'http' => true, 'defaultDriver' => 'http3', 'defaultMode' => 'relay-unary'],
    ];

    /**
     * @var array 定义解析计划使用的默认格式
     */
    protected static $formats = [
        'rest' => 'openapi',
        'soap' => 'wsdl',
        'graphql' => 'graphql',
        'grpc' => 'proto',
        'dubbo' => 'proto',
        'json-rpc' => 'json-rpc',
        'xml-rpc' => 'xml-rpc',
        'webhooks' => 'json-schema',
        'mcp' => 'json-schema',
        'a2a' => 'json-schema',
        'odata' => 'edmx',
        'hessian' => 'hessian',
        'thrift' => 'thrift',
    ];

    /**
     * @param string $name 协议 id
     * @return array
     */
    public static function get(string $name): array
    {
        if (!isset(static::$protocols[$name])) {
            return [
                'name' => $name,
                'label' => strtoupper($name),
                'transport' => '',
                'http' => false,
                'defaultDriver' => '',
                'defaultMode' => '',
                'defaultFormat' => '',
                'debuggable' => false,
                'debugReason' => '未支持的协议驱动（P2）',
            ];
        }
        $entry = static::$protocols[$name];
        $driver = isset($entry['defaultDriver']) ? $entry['defaultDriver'] : '';
        return [
            'name' => $name,
            'label' => $entry['label'],
            'transport' => $entry['transport'],
            'http' => $entry['http'],
            'defaultDriver' => $driver,
            'defaultMode' => isset($entry['defaultMode']) ? $entry['defaultMode'] : '',
            'defaultFormat' => isset(static::$formats[$name]) ? static::$formats[$name] : '',
            'debuggable' => $driver !== '',
            'debugReason' => isset($entry['debugReason']) ? $entry['debugReason'] : ($driver !== '' ? '' : '未支持的协议驱动（P2）'),
        ];
    }

    /**
     * @return array 协议 id 列表
     */
    public static function ids(): array
    {
        return array_keys(static::$protocols);
    }
}
