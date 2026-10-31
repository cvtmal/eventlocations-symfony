<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Landingpages" in the CMS section (#26). It stays active on `admin_landing_pages_edit` (the registry's
 * prefix rule).
 */
final class LandingPagesNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Cms;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('landing.nav', [], 'cms');
    }

    public function icon(): string
    {
        return 'file-text';
    }

    public function route(): string
    {
        return 'admin_landing_pages';
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
     * After Locations (300), before Textbausteine (100), as on the Sidebar artboard.
     */
    public function priority(): int
    {
        return 200;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
