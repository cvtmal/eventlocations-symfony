<?php

declare(strict_types=1);

namespace App\Inquiries\Form;

use App\Inquiries\ValueObject\FeedbackOutcome;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The two questions of the feedback page (G16); both are needed, as the table stores them together.
 */
final class FeedbackData
{
    #[Assert\NotNull(message: 'inquiry.feedback.venue_replied.required')]
    public ?bool $venueReplied = null;

    #[Assert\NotNull(message: 'inquiry.feedback.outcome.required')]
    public ?FeedbackOutcome $outcome = null;
}
