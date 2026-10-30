<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\DashboardWidget;
use App\Admin\Extension\WidgetSize;
use App\Cms\ReadModel\VenueListQuery;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The five venues staff changed most recently (`cms_edited_at`), with who and when.
 */
final readonly class RecentlyEditedVenuesWidget implements DashboardWidget
{
    public const int LIMIT = 5;

    public function __construct(private VenueListQuery $venues)
    {
    }

    public function title(): TranslatableInterface
    {
        return new TranslatableMessage('dashboard.recent.title', [], 'cms');
    }

    public function template(): string
    {
        return 'admin/cms/widgets/_recently_edited_venues.html.twig';
    }

    public function context(): array
    {
        return ['rows' => $this->venues->recentlyEdited(self::LIMIT)];
    }

    public function size(): WidgetSize
    {
        return WidgetSize::Medium;
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
