<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use App\Inquiries\ValueObject\FeedbackOutcome;

/**
 * An inquiry's feedback row as the answer page and the CRM detail show it (G16). `venueReplied` and `outcome` are
 * both null until the guest answers.
 */
final readonly class FeedbackState
{
    public function __construct(
        public string $inquiryId,
        public string $venueName,
        public bool $mailed,
        public bool $optedOut,
        public ?bool $venueReplied,
        public ?FeedbackOutcome $outcome,
        public ?\DateTimeImmutable $answeredAt,
    ) {
    }

    public function answered(): bool
    {
        return null !== $this->answeredAt;
    }
}
