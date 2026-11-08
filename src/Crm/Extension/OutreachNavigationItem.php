<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The CRM's "Akquise" page (G11): cohort funnel, postal campaigns with their print lists, suppression list.
 */
final readonly class OutreachNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Crm;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.outreach', [], 'crm');
    }

    public function icon(): string
    {
        return 'mail';
    }

    public function route(): string
    {
        return 'admin_crm_outreach';
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
     * Between Anfragen (200) and Aufgaben (100).
     */
    public function priority(): int
    {
        return 150;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
