<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\TimelineActivityType;
use App\Crm\Entity\ActivityType;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The `call` entry of the CRM timeline. Order follows the design: Notiz, E-Mail, Anruf, Meeting.
 */
final class CallActivityType implements TimelineActivityType
{
    public function code(): string
    {
        return ActivityType::CALL;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('activity.type.call', [], 'crm');
    }

    public function priority(): int
    {
        return 200;
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
