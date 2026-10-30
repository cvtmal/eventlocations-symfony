<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class UsersNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Settings;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.users', [], 'admin');
    }

    public function icon(): string
    {
        return 'shield';
    }

    public function route(): string
    {
        return 'admin_users';
    }

    public function routeParameters(): array
    {
        return [];
    }

    public function badge(): ?int
    {
        return null;
    }

    public function priority(): int
    {
        return 300;
    }

    public function requiredRole(): string
    {
        return 'ROLE_ADMIN';
    }
}
