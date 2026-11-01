<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\TimelineActivityType;
use App\Crm\Entity\ActivityType;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A deal moved to another stage (S13). Only `MoveDealStage` writes it, so nobody composes one.
 */
final class DealStageChangedActivityType implements TimelineActivityType
{
    public function code(): string
    {
        return ActivityType::DEAL_STAGE_CHANGED;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('activity.type.deal_stage_changed', [], 'crm');
    }

    public function priority(): int
    {
        return 50;
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
