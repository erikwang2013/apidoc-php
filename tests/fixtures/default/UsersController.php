<?php
declare(strict_types=1);

namespace Tests\Fixtures\Ui;

use erikwang2013\apidoc\annotation\Group;
use erikwang2013\apidoc\annotation\Method;
use erikwang2013\apidoc\annotation\Param;
use erikwang2013\apidoc\annotation\Returned;
use erikwang2013\apidoc\annotation\Title;
use erikwang2013\apidoc\annotation\Url;

/**
 * Minimal annotated controller so the E2E server can serve non-empty menus/detail.
 */
#[Title('Users')]
#[Group('Core')]
class UsersController
{
    #[Method('GET')]
    #[Url('/users')]
    #[Title('List users')]
    #[Param(name: 'limit', type: 'int', desc: 'page size')]
    #[Returned(name: 'id', type: 'int', desc: 'user id')]
    public function listUsers(): void
    {
    }
}
