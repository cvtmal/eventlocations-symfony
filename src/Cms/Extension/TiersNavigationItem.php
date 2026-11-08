<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Tarife" in the CMS section (F12). It stays active on `admin_tiers_edit` (the registry's prefix rule).
 */
final class TiersNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Cms;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('tiers.nav', [], 'cms');
    }

    public function icon(): string
    {
        return 'sticky-note';
    }

    public function route(): string
    {
        return 'admin_tiers';
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
     * Last in the CMS section, after Locations (300), Landingpages (200) and Textbausteine (100).
     */
    public function priority(): int
    {
        return 50;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
