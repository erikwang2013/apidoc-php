<?php
declare(strict_types=1);

namespace Tests\Fixtures\Other;

use erikwang2013\apidoc\annotation\Group;
use erikwang2013\apidoc\annotation\Method;
use erikwang2013\apidoc\annotation\Title;
use erikwang2013\apidoc\annotation\Url;

/**
 * Outside the default fixture app path; used to prove selected-id scope filtering.
 */
#[Title('Other')]
#[Group('Other')]
class OtherController
{
    #[Method('GET')]
    #[Url('/other')]
    #[Title('Other endpoint')]
    public function listOther(): void
    {
    }
}
