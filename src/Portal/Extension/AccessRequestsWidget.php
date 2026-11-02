<?php

declare(strict_types=1);

namespace App\Portal\Extension;

use App\Admin\Extension\DashboardWidget;
use App\Admin\Extension\WidgetSize;
use App\Portal\ReadModel\PortalAccessRequestBadge;
use App\Portal\ReadModel\PortalAccessRequestQuery;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Zugriffsanfragen": the oldest open requests. The sidebar has already counted them, so an empty queue costs nothing
 * here. The decision stays in the queue, so the widget has no buttons.
 */
final readonly class AccessRequestsWidget implements DashboardWidget
{
    public const int LIMIT = 5;

    public function __construct(
        private PortalAccessRequestBadge $badge,
        private PortalAccessRequestQuery $requests,
    ) {
    }

    public function title(): TranslatableInterface
    {
        return new TranslatableMessage('review.widget.title', [], 'portal');
    }

    public function template(): string
    {
        return 'admin/portal/widgets/_requests.html.twig';
    }

    public function context(): array
    {
        $count = $this->badge->count();

        return ['total' => $count, 'rows' => 0 === $count ? [] : $this->requests->oldest(self::LIMIT)];
    }

    public function size(): WidgetSize
    {
        return WidgetSize::Medium;
    }

    /**
     * Second on the dashboard: below "Neue Anfragen" (500), above "Meine Aufgaben" (400).
     */
    public function priority(): int
    {
        return 450;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
