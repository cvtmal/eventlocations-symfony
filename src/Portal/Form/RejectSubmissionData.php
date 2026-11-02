<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Staff's reason for sending a location back (#45, POR-13). It goes to the manager; the column holds 1000 characters.
 */
final class RejectSubmissionData
{
    public const int REASON_MAX = 1000;

    #[Assert\NotBlank(message: 'portal.onboarding.reason_required')]
    #[Assert\Length(max: self::REASON_MAX, maxMessage: 'portal.onboarding.reason_too_long')]
    public ?string $reason = null;
}
