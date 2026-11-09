<?php

declare(strict_types=1);

namespace App\Inquiries\Command;

/**
 * Stops the feedback mails to the address of this inquiry (G16): `FeedbackRequestsDue` sees the opt-out on this row
 * and records later inquiries of the address without a mail. A second opt-out changes nothing.
 */
final readonly class OptOutOfInquiryFeedback
{
    public function __construct(
        public string $inquiryId,
    ) {
    }
}
