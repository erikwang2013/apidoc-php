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
