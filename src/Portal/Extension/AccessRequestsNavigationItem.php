<?php

declare(strict_types=1);

namespace App\Portal\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use App\Portal\ReadModel\PortalAccessRequestBadge;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Prüfung" in the CMS section, with the open access requests as its badge. It stays active on every route named
 * `admin_portal_review_…`.
 */
final readonly class AccessRequestsNavigationItem implements NavigationItem
{
    public function __construct(private PortalAccessRequestBadge $badge)
    {
    }

    public function section(): NavigationSection
    {
        return NavigationSection::Cms;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('review.nav', [], 'portal');
    }

    public function icon(): string
    {
        return 'shield';
    }

    public function route(): string
    {
        return 'admin_portal_review';
    }

    public function routeParameters(): array
    {
        return [];
    }

    public function badge(): ?int
    {
        $count = $this->badge->count();

        return 0 === $count ? null : $count;
    }

    /**
     * Below Locations (300), above Landingpages (200).
     */
    public function priority(): int
    {
        return 250;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
