<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\TimelineActivityType;
use App\Crm\Entity\ActivityType;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The `email_logged` entry of the CRM timeline. Order follows the design: Notiz, E-Mail, Anruf, Meeting.
 */
final class LoggedEmailActivityType implements TimelineActivityType
{
    public function code(): string
    {
        return ActivityType::EMAIL_LOGGED;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('activity.type.email_logged', [], 'crm');
    }

    public function priority(): int
    {
        return 300;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }

    public function composable(): bool
    {
        return true;
    }
}
