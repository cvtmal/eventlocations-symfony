<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Firmen" in the CRM section. It stays active on every route named `admin_crm_companies_…`, so the
 * record and create pages keep the item highlighted.
 */
final class CompaniesNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Crm;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.companies', [], 'crm');
    }

    public function icon(): string
    {
        return 'building-2';
    }

    public function route(): string
    {
        return 'admin_crm_companies';
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
     * Firmen in the CRM section, first (D20): above #28's Deals (300), #29's Anfragen (200) and #27b's Aufgaben (100), with Kontakte (400) between.
     */
    public function priority(): int
    {
        return 500;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
