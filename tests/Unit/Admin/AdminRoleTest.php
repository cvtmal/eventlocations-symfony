<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\ValueObject\AdminRole;
use PHPUnit\Framework\TestCase;

final class AdminRoleTest extends TestCase
{
    public function test_each_role_maps_to_its_security_role(): void
    {
        self::assertSame('ROLE_ADMIN', AdminRole::Admin->securityRole());
        self::assertSame('ROLE_EDITOR', AdminRole::Editor->securityRole());
    }

    public function test_label_is_a_key_of_the_admin_catalogue(): void
    {
        self::assertSame('role.editor', AdminRole::Editor->label()->getMessage());
        self::assertSame('admin', AdminRole::Editor->label()->getDomain());
    }
}
