<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Meldungen" in the CMS section (G2). It stays active on `admin_moderation_case` (the registry's prefix rule).
 *
 * No badge: a count would be one more statement on every back-office page, past the statement ceilings the CRM and
 * dashboard tests pin (D16, P12), and staff get a mail for every complaint anyway. The queue shows the open count.
 */
final class ModerationNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Cms;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('moderation.nav', [], 'cms');
    }

    public function icon(): string
    {
        return 'bell';
    }

    public function route(): string
    {
        return 'admin_moderation';
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
     * After Locations (300) and Prüfung (250), before Landingpages (200).
     */
    public function priority(): int
    {
        return 240;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
