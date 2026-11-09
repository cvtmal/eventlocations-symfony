<?php

declare(strict_types=1);

namespace App\Inquiries\Command;

use App\Inquiries\ValueObject\FeedbackOutcome;

/**
 * The guest's answer from the feedback page (G16); a second answer replaces the first.
 */
final readonly class AnswerInquiryFeedback
{
    public function __construct(
        public string $inquiryId,
        public bool $venueReplied,
        public FeedbackOutcome $outcome,
    ) {
    }
}
