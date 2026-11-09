<?php

declare(strict_types=1);

namespace App\Inquiries\Command;

/**
 * Sends the guest the one confirmation of a brief once its children are delivered (F13). Returns a `BriefOutcome`.
 */
final readonly class ConfirmInquiryBrief
{
    public function __construct(
        public string $briefId,
    ) {
    }
}
