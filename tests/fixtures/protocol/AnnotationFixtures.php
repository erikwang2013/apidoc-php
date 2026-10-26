<?php

namespace Tests\Fixtures\Protocol;

use erikwang2013\apidoc\annotation\{Protocol, Channel, Debug, Definition, Param, Returned, Title, Method, Url};

#[Protocol('mqtt')]
class MqttFixture
{
    #[Channel('/sensors/+/temp', action: 'publish')]
    #[Debug(driver: 'mqtt', target: 'tcp://broker:1883', encoding: 'json', meta: ['qos' => 1])]
    #[Param(name: 'value', type: 'number', require: true)]
    #[Title('Publish temperature')]
    public function publishTemperature() {}
}

#[Protocol('grpc')]
class GrpcFixture
{
    #[Channel('helloworld.Greeter/SayHello', action: 'call')]
    #[Debug(driver: 'grpc', target: 'localhost:50051')]
    #[Title('SayHello')]
    public function sayHello() {}
}

#[Protocol('thrift')]
#[Debug(driver: 'thrift', target: 'tcp://127.0.0.1:19014', meta: ['method' => 'ping'])]
class ThriftFixture
{
    #[Channel('ping', action: 'call')]
    #[Title('Thrift ping')]
    public function ping() {}
}

#[Protocol('dubbo')]
#[Debug(driver: 'dubbo', target: 'tcp://127.0.0.1:19015', meta: ['method' => 'echo', 'service' => 'com.demo.Echo'])]
class DubboFixture
{
    #[Channel('com.demo.Echo#echo', action: 'call')]
    #[Title('Dubbo echo')]
    public function echo() {}
}

#[Protocol('http2')]
#[Debug(target: 'http://127.0.0.1:19016/echo')]
class Http2Fixture
{
    #[Channel('/echo', action: 'call')]
    #[Title('HTTP/2 echo')]
    public function echo() {}
}

#[Protocol('http3')]
#[Debug(target: 'https://127.0.0.1:19016/echo')]
class Http3Fixture
{
    #[Channel('/echo', action: 'call')]
    #[Title('HTTP/3 echo')]
    public function echo() {}
}

#[Protocol('tcp')]
class PositionalDebugFixture
{
    #[Channel('tcp://127.0.0.1:9000', action: 'send')]
    #[Debug('raw')]
    public function send() {}
}

#[Protocol('mqtt')]
class DefaultDriverFixture
{
    #[Debug(target: 'tcp://broker:1883')]
    public function publish() {}
}

class PlainHttpFixture
{
    #[Method('GET')] #[Url('/plain')] #[Title('Plain')]
    public function plain() {}
}

class DebugOnlyFixture
{
    #[Method('GET')] #[Url('/ping')] #[Title('Ping')]
    #[Debug(driver: 'http', target: 'https://api.example.com/ping')]
    public function ping() {}
}

// 本机 19001 已被 minio 占用,测试 TCP 回显改用 19011
#[Protocol('tcp')]
#[Debug(driver:'raw', target:'tcp://127.0.0.1:19011')]
class RawFixture {
    #[Channel('echo')] #[Title('Echo')]
    public function echo() {}
}

#[Protocol('udp')]
#[Debug(driver:'raw', target:'udp://127.0.0.1:19002')]
class UdpRawFixture {
    #[Channel('echo')] #[Title('UDP echo')]
    public function echo() {}
}

#[Protocol('http')]
#[Debug(driver:'http-relay', target:'http://127.0.0.1:19005/echo.php')]
class HttpRelayFixture {
    #[Channel('/echo.php')] #[Title('HTTP echo')]
    public function echo() {}
}

#[Protocol('http')]
#[Debug(driver:'http-relay', target:'http://127.0.0.1:19005/redirect.php')]
class HttpRedirectFixture {
    #[Channel('/redirect.php')] #[Title('HTTP cross-host redirect')]
    public function echo() {}
}

#[Protocol('http')]
#[Debug(driver:'http-relay', target:'http://127.0.0.1:19005/redirect-same.php')]
class HttpSameOriginRedirectFixture {
    #[Channel('/redirect-same.php')] #[Title('HTTP same-origin redirect')]
    public function echo() {}
}

#[Protocol('http')]
#[Debug(driver:'http-relay', target:'http://127.0.0.1:19005/headers.php')]
class HttpHeadersFixture {
    #[Channel('/headers.php')] #[Title('HTTP headers echo')]
    public function echo() {}
}

#[Protocol('mqtt')]
class MqttPublishFixture
{
    #[Channel('/debug/topic', action: 'publish')]
    #[Debug(driver: 'mqtt', target: 'tcp://127.0.0.1:19003')]
    public function publish() {}
}

#[Protocol('mqtt')]
class MqttSubscribeFixture
{
    #[Channel('/debug/topic', action: 'subscribe')]
    #[Debug(driver: 'mqtt', target: 'tcp://127.0.0.1:19003')]
    public function subscribe() {}
}

#[Protocol('stomp')]
class StompSubscribeFixture
{
    #[Channel('/debug/queue', action: 'receive')]
    #[Debug(driver: 'stomp', target: 'tcp://127.0.0.1:19004')]
    public function receive() {}
}

#[Protocol('coap')]
class CoapDebugFixture
{
    #[Channel('/debug/coap', action: 'send')]
    #[Debug(driver: 'coap', target: 'udp://127.0.0.1:19006')]
    public function echo() {}
}

#[Protocol('modbus-tcp')]
class ModbusDebugFixture
{
    #[Channel('/debug/modbus', action: 'send')]
    #[Debug(driver: 'modbus-tcp', target: 'tcp://127.0.0.1:19007')]
    public function read() {}
}

#[Protocol('iso8583')]
class Iso8583ProxyFixture
{
    #[Channel('iso')]
    #[Title('ISO')]
    #[Debug(driver: 'iso8583', target: 'tcp://127.0.0.1:19008')]
    public function send() {}
}

// P2 协议无专用驱动,回退到原始套接字面板
#[Protocol('amqp')]
#[Channel('tcp://broker:5672')]
#[Debug(target: 'tcp://broker:5672')]
class P2RawFixture
{
    #[Title('Publish AMQP frame')]
    public function publish() {}
}

#[Protocol('webrtc')]
class P2NoDriverFixture
{
    #[Title('Send WebRTC offer')]
    public function offer() {}
}

#[Protocol('json-rpc')]
class InlineFixture
{
    #[Param(name: 'id', type: 'integer', require: true, desc: 'ID')]
    #[Returned(name: 'ok', type: 'boolean', desc: 'OK')]
    #[Title('Inline')]
    public function call() {}
}

#[Protocol('webhooks')]
class JsonSchemaFixture
{
    #[Definition(file: 'tests/fixtures/protocol/json-schema/user.json', sample: '{"id":1,"name":"a"}')]
    #[Title('User')]
    public function user() {}

    #[Definition(file: 'tests/fixtures/protocol/json-schema/invalid.json')]
    #[Title('Broken')]
    public function broken() {}

    #[Definition(file: 'tests/fixtures/protocol/json-schema/webhook.json')]
    #[Title('Hook')]
    public function hook() {}

    #[Definition(file: 'tests/fixtures/protocol/json-schema/user.json', sampleFile: 'tests/fixtures/protocol/json-schema/user-sample.json')]
    #[Title('User from file')]
    public function userFile() {}
}

#[Protocol('webhooks')]
class JsonSchemaRefsFixture
{
    #[Definition(file: 'tests/fixtures/protocol/json-schema/recursive.json')]
    #[Title('Recursive')]
    public function recursive() {}

    #[Definition(file: 'tests/fixtures/protocol/json-schema/multi.json')]
    #[Title('Multi')]
    public function multi() {}

    #[Definition(file: 'tests/fixtures/protocol/json-schema/chain.json', sample: '{"name":"a"}')]
    #[Title('Chain')]
    public function chain() {}

    #[Definition(file: 'tests/fixtures/protocol/json-schema/chain.json', sample: '{"name":5}')]
    #[Title('Chain invalid sample')]
    public function chainBad() {}
}

#[Protocol('webhooks')]
#[Definition(file: 'tests/fixtures/protocol/json-schema/user.json')]
class ClassDefinitionFixture
{
    #[Title('Inherited definition')]
    public function inherited() {}
}

#[Protocol('webhooks')]
class CacheFixture
{
    #[Definition(file: 'tests/fixtures/protocol/json-schema/cache.json')]
    #[Title('Cache')]
    public function user() {}
}
