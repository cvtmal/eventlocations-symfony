<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "E-Mail-Vorlagen" in the settings, below Benutzer (300) and Audit-Log (200).
 */
final readonly class EmailTemplatesNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Settings;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.email_templates', [], 'crm');
    }

    public function icon(): string
    {
        return 'mail';
    }

    public function route(): string
    {
        return 'admin_crm_email_templates';
    }

    public function routeParameters(): array
    {
        return [];
    }

    public function badge(): ?int
    {
        return null;
    }

    public function priority(): int
    {
        return 100;
    }

    public function requiredRole(): string
    {
        return 'ROLE_ADMIN';
    }
}
