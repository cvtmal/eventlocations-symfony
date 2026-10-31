<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Kontakte" in the CRM section. It stays active on every route named `admin_crm_contacts_…`, so the
 * record and create pages keep the item highlighted.
 */
final class ContactsNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Crm;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.contacts', [], 'crm');
    }

    public function icon(): string
    {
        return 'users';
    }

    public function route(): string
    {
        return 'admin_crm_contacts';
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
     * Kontakte, below Firmen (500) and above #28's Deals (300) (D20).
     */
    public function priority(): int
    {
        return 400;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
