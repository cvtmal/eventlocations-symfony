<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\DashboardWidget;
use App\Admin\Extension\WidgetSize;
use App\Cms\ReadModel\VenueListQuery;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The first five venues that cannot be published or lack an inquiry address, with what is missing, and a link
 * to the list filtered to all of them.
 */
final readonly class IncompleteVenuesWidget implements DashboardWidget
{
    public const int LIMIT = 5;

    public function __construct(private VenueListQuery $venues)
    {
    }

    public function title(): TranslatableInterface
    {
        return new TranslatableMessage('dashboard.incomplete.title', [], 'cms');
    }

    public function template(): string
    {
        return 'admin/cms/widgets/_incomplete_venues.html.twig';
    }

    public function context(): array
    {
        return ['incomplete' => $this->venues->incomplete(self::LIMIT)];
    }

    public function size(): WidgetSize
    {
        return WidgetSize::Medium;
    }

    public function priority(): int
    {
        return 200;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
