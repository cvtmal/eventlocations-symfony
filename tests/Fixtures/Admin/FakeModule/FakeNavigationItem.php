<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\FakeModule;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class FakeNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Cms;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.first', [], 'probe_admin');
    }

    public function icon(): string
    {
        return 'landmark';
    }

    public function route(): string
    {
        return 'admin_probe_record';
    }

    public function routeParameters(): array
    {
        return ['id' => '0199f0d0-0000-7000-8000-000000000001'];
    }

    public function badge(): int
    {
        return 7;
    }

    public function priority(): int
    {
        return 10;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
