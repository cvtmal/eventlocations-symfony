<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\TimelineActivityType;
use App\Crm\Entity\ActivityType;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A mail sent from a record (CRM-10). Only `SendCrmEmail` writes it, so nobody composes one and the entry is read-only.
 */
final class EmailSentActivityType implements TimelineActivityType
{
    public function code(): string
    {
        return ActivityType::EMAIL_SENT;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('activity.type.email_sent', [], 'crm');
    }

    public function priority(): int
    {
        return 250;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }

    public function composable(): bool
    {
        return false;
    }
}
