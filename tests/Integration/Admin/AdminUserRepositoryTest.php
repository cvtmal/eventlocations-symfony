<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\Repository\AdminUserRepository;
use App\Admin\ValueObject\AdminRole;
use App\Factory\AdminUserFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class AdminUserRepositoryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_find_by_email_ignores_case(): void
    {
        $user = AdminUserFactory::createOne(['email' => 'Anna@Example.CH']);

        self::assertSame('anna@example.ch', $user->email());
        self::assertSame($user->id(), self::repository()->findOneByEmail('ANNA@example.ch')?->id());
        self::assertNull(self::repository()->findOneByEmail('nobody@example.ch'));
    }

    public function test_email_exists_can_exclude_one_user(): void
    {
        $user = AdminUserFactory::createOne(['email' => 'anna@example.ch']);

        self::assertTrue(self::repository()->emailExists('ANNA@example.ch'));
        self::assertFalse(self::repository()->emailExists('anna@example.ch', $user->id()));
        self::assertFalse(self::repository()->emailExists('other@example.ch'));
    }

    public function test_count_active_admins_ignores_editors_and_inactive_admins(): void
    {
        AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        AdminUserFactory::createOne(['role' => AdminRole::Admin])->deactivate();
        AdminUserFactory::createOne(['role' => AdminRole::Editor]);
        $this->em->flush();

        self::assertSame(1, self::repository()->countActiveAdmins());
    }

    private static function repository(): AdminUserRepository
    {
        $repository = self::getContainer()->get(AdminUserRepository::class);
        self::assertInstanceOf(AdminUserRepository::class, $repository);

        return $repository;
    }
}
