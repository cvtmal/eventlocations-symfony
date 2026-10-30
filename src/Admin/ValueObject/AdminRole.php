<?php

declare(strict_types=1);

namespace App\Admin\ValueObject;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * Staff roles (S12, ADM-2), stored in `admin_users.role` (chk_admin_users_role).
 * `admin` includes everything `editor` may do (security.role_hierarchy).
 */
enum AdminRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';

    public function securityRole(): string
    {
        return match ($this) {
            self::Admin => 'ROLE_ADMIN',
            self::Editor => 'ROLE_EDITOR',
        };
    }

    public function label(): TranslatableMessage
    {
        return new TranslatableMessage('role.'.$this->value, [], 'admin');
    }
}
