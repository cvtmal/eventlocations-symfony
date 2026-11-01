<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Deals" in the CRM section. It stays active on every route named `admin_crm_deals_…`, so the record and
 * create pages keep the item highlighted.
 */
final class DealsNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Crm;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.deals', [], 'crm');
    }

    public function icon(): string
    {
        return 'square-kanban';
    }

    public function route(): string
    {
        return 'admin_crm_deals';
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
     * Deals, below Kontakte (400) and above #29's Anfragen (200) (D27).
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
