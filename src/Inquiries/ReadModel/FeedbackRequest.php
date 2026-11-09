<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

/**
 * The inquiries of one guest address that are due for the feedback mail in this run (G16): one mail asks about all
 * of them. `optedOut`: the address stopped the feedback mails, so the run records them without a mail.
 */
final readonly class FeedbackRequest
{
    /**
     * @param non-empty-list<string> $inquiryIds oldest venue mail first
     */
    public function __construct(
        public array $inquiryIds,
        public bool $optedOut,
    ) {
    }
}
