<?php

declare(strict_types=1);

namespace App\Inquiries\Command;

/**
 * Sends one guest address its feedback mail about these inquiries and records it (G16), or, when the address opted
 * out, records them without a mail. Dispatched per address by `app:inquiries:request-feedback`, so each address is
 * its own transaction.
 */
final readonly class RequestInquiryFeedback
{
    /**
     * @param non-empty-list<string> $inquiryIds
     */
    public function __construct(
        public array $inquiryIds,
        public bool $optedOut,
    ) {
    }
}
