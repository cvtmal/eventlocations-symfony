<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class AuditLogNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Settings;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.audit_log', [], 'admin');
    }

    public function icon(): string
    {
        return 'history';
    }

    public function route(): string
    {
        return 'admin_audit_log';
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
        return 200;
    }

    public function requiredRole(): string
    {
        return 'ROLE_ADMIN';
    }
}
