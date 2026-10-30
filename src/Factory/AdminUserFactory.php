<?php

declare(strict_types=1);

namespace App\Factory;

use App\Admin\Entity\AdminUser;
use App\Admin\ValueObject\AdminRole;
use App\Shared\Uid\EntityId;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * An active editor who has not enrolled in 2FA. The default hash matches no password: tests that log in
 * use AdminSession::createStaff(), and AdminStory hashes its own.
 *
 * @extends PersistentObjectFactory<AdminUser>
 */
final class AdminUserFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AdminUser::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => EntityId::generate(),
            'email' => self::faker()->unique()->safeEmail(),
            'displayName' => self::faker()->name(),
            'passwordHash' => '!',
            'role' => AdminRole::Editor,
        ];
    }
}
