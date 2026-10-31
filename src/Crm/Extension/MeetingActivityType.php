<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\TimelineActivityType;
use App\Crm\Entity\ActivityType;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The `meeting` entry of the CRM timeline. Order follows the design: Notiz, E-Mail, Anruf, Meeting.
 */
final class MeetingActivityType implements TimelineActivityType
{
    public function code(): string
    {
        return ActivityType::MEETING;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('activity.type.meeting', [], 'crm');
    }

    public function priority(): int
    {
        return 100;
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
