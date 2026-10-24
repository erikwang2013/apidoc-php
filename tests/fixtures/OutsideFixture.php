<?php

namespace Tests\Fixtures;

use erikwang2013\apidoc\annotation\Title;

class OutsideFixture
{
    #[Title('Outside app path')]
    public function ping() {}
}
