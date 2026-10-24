<?php

namespace Tests\Fixtures\Protocol;

use erikwang2013\apidoc\annotation\{Protocol, Channel, Debug, Param, Title, Method, Url};

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
