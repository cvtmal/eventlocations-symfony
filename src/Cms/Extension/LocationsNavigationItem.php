<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Locations" in the CMS section. It stays active on every route named `admin_locations_…`, so the venue
 * editor's pages (#25's too) keep the item highlighted.
 */
final class LocationsNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Cms;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.locations', [], 'cms');
    }

    public function icon(): string
    {
        return 'landmark';
    }

    public function route(): string
    {
        return 'admin_locations';
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
     * First in the CMS section, above #26's Landingpages (200) and Textbausteine (100).
     */
    public function priority(): int
    {
        return 300;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
