<?php

namespace Tests\Fixtures\Protocol;

use erikwang2013\apidoc\annotation\{Protocol, Channel, Debug, Param, Title};

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
