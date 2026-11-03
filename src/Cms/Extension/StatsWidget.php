<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\DashboardWidget;
use App\Admin\Extension\WidgetSize;
use App\Stats\ReadModel\SiteStatsQuery;
use App\Stats\ValueObject\StatsPeriod;
use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Statistik, letzte 7 Tage" (#47): four `_kpi` tiles of the site's week, with "Stand" and a link to the
 * statistics screen.
 */
final readonly class StatsWidget implements DashboardWidget
{
    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private SiteStatsQuery $stats,
        private ClockInterface $clock,
    ) {
    }

    public function title(): TranslatableInterface
    {
        return new TranslatableMessage('stats.widget.title', [], 'cms');
    }

    public function template(): string
    {
        return 'admin/cms/widgets/_stats.html.twig';
    }

    public function context(): array
    {
        $today = $this->clock->now()->setTimezone(new \DateTimeZone(self::ZONE))->setTime(0, 0);

        return [
            'summary' => $this->stats->summary(StatsPeriod::Days7->firstDay($today), $today),
            'as_of' => $this->stats->lastIngestAt(),
        ];
    }

    public function size(): WidgetSize
    {
        return WidgetSize::Wide;
    }

    /**
     * First on the dashboard, above #27's Neue Anfragen (500).
     */
    public function priority(): int
    {
        return 600;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
