<?php
declare(strict_types = 1);

namespace Tests\Fixtures\Protocol;

use erikwang2013\apidoc\annotation\Definition;
use erikwang2013\apidoc\annotation\Protocol;
use erikwang2013\apidoc\annotation\Title;

#[Protocol('rest')]
class OpenApiFixture
{
    #[Definition(file: 'tests/fixtures/protocol/openapi/api.yaml', operation: 'getUser')]
    #[Title('Get user')]
    public function getUser() {}
}

#[Protocol('mqtt')]
class AsyncApiFixture
{
    #[Channel('/sensors/+/temp', action: 'subscribe')]
    #[Definition(file: 'tests/fixtures/protocol/asyncapi/asyncapi.yaml', operation: 'sensors/+/temp:publish')]
    #[Title('Temperature')]
    public function onTemp() {}
}

#[Protocol('graphql')]
class GraphqlFixture
{
    #[Definition(file: 'tests/fixtures/protocol/graphql/schema.graphql', operation: 'Query.user')]
    #[Title('User')]
    public function user() {}
}

#[Protocol('grpc')]
class ProtoFixture
{
    #[Definition(file: 'tests/fixtures/protocol/proto/helloworld.proto', operation: 'helloworld.Greeter/SayHello')]
    #[Title('SayHello')]
    public function sayHello() {}
}

#[Protocol('grpc')]
class ProtoBadFixture
{
    #[Definition(file: 'tests/fixtures/protocol/proto/unsupported.proto')]
    #[Title('Bad')]
    public function bad() {}
}

#[Protocol('soap')]
class WsdlFixture
{
    #[Definition(file: 'tests/fixtures/protocol/wsdl/calculator.wsdl', operation: 'CalculatorService/CalculatorPort/Add')]
    #[Title('Add')]
    public function add() {}
}
