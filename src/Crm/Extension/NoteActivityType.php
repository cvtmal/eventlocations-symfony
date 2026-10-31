<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\TimelineActivityType;
use App\Crm\Entity\ActivityType;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The `note` entry of the CRM timeline. Order follows the design: Notiz, E-Mail, Anruf, Meeting.
 */
final class NoteActivityType implements TimelineActivityType
{
    public function code(): string
    {
        return ActivityType::NOTE;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('activity.type.note', [], 'crm');
    }

    public function priority(): int
    {
        return 400;
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
