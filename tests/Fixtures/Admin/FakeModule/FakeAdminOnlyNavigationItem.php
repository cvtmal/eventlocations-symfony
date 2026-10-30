<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\FakeModule;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class FakeAdminOnlyNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Crm;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.admin_only', [], 'probe_admin');
    }

    public function icon(): string
    {
        return 'users';
    }

    public function route(): string
    {
        return 'admin_dashboard';
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
        return 0;
    }

    public function requiredRole(): string
    {
        return 'ROLE_ADMIN';
    }
}
