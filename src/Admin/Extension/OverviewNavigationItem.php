<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class OverviewNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Overview;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.overview', [], 'admin');
    }

    public function icon(): string
    {
        return 'layout-dashboard';
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
        return 100;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
