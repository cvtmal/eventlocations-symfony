<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use App\Crm\ReadModel\InquiryBadge;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Anfragen" in the CRM section, with the new inquiries as its badge. It stays active on every route named
 * `admin_crm_inquiries_…`, so the detail page keeps the item highlighted.
 */
final readonly class InquiriesNavigationItem implements NavigationItem
{
    public function __construct(private InquiryBadge $badge)
    {
    }

    public function section(): NavigationSection
    {
        return NavigationSection::Crm;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.inquiries', [], 'crm');
    }

    public function icon(): string
    {
        return 'inbox';
    }

    public function route(): string
    {
        return 'admin_crm_inquiries';
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
     * Below #28's Deals (300), above #27's Aufgaben (100).
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
