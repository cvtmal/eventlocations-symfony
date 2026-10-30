<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\FakeModule;

use App\Admin\Extension\DashboardWidget;
use App\Admin\Extension\WidgetSize;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class FakeDashboardWidget implements DashboardWidget
{
    public function title(): TranslatableInterface
    {
        return new TranslatableMessage('widget.title', [], 'probe_admin');
    }

    public function template(): string
    {
        return '@admin_probe/widget.html.twig';
    }

    public function context(): array
    {
        return ['count' => 42];
    }

    public function size(): WidgetSize
    {
        return WidgetSize::Wide;
    }

    public function priority(): int
    {
        return 0;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
