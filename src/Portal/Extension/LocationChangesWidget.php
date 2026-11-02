<?php

declare(strict_types=1);

namespace App\Portal\Extension;

use App\Admin\Extension\DashboardWidget;
use App\Admin\Extension\WidgetSize;
use App\Portal\ReadModel\LocationChangesQuery;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The five newest changes managers made to published locations, for staff.
 */
final readonly class LocationChangesWidget implements DashboardWidget
{
    public const int LIMIT = 5;

    public function __construct(private LocationChangesQuery $changes)
    {
    }

    public function title(): TranslatableInterface
    {
        return new TranslatableMessage('editor.changes.title', [], 'portal');
    }

    public function template(): string
    {
        return 'portal/widgets/_location_changes.html.twig';
    }

    public function context(): array
    {
        return ['rows' => $this->changes->recent(self::LIMIT)];
    }

    public function size(): WidgetSize
    {
        return WidgetSize::Medium;
    }

    public function priority(): int
    {
        return 150;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
