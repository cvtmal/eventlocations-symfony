<?php

declare(strict_types=1);

namespace App\Portal\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class PortalAccountsNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Settings;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('accounts.nav', [], 'portal');
    }

    public function icon(): string
    {
        return 'users';
    }

    public function route(): string
    {
        return 'admin_portal_accounts';
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
        return 250;
    }

    public function requiredRole(): string
    {
        return 'ROLE_ADMIN';
    }
}
