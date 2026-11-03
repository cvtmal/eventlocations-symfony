<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Statistik" (#47) in the Übersicht section, below the dashboard. It stays active on every route named
 * `admin_stats_…`, the media kit included.
 */
final class StatsNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Overview;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('stats.nav', [], 'cms');
    }

    public function icon(): string
    {
        return 'chart-column';
    }

    public function route(): string
    {
        return 'admin_stats';
    }

    public function routeParameters(): array
    {
        return [];
    }

    public function badge(): ?int
    {
        return null;
    }

    /**
     * After the dashboard's Übersicht (100).
     */
    public function priority(): int
    {
        return 90;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
