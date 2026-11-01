<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\TimelineActivityType;
use App\Crm\Entity\ActivityType;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * An inquiry from the public site (CRM-9). Only `IngestInquiry` writes it, so nobody composes one.
 */
final class InquiryActivityType implements TimelineActivityType
{
    public function code(): string
    {
        return ActivityType::INQUIRY;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('activity.type.inquiry', [], 'crm');
    }

    public function priority(): int
    {
        return 75;
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
