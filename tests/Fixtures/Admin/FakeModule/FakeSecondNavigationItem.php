<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\FakeModule;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class FakeSecondNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Cms;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.second', [], 'probe_admin');
    }

    public function icon(): string
    {
        return 'type';
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
        return 20;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
