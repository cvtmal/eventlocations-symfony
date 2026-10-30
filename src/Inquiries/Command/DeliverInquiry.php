<?php

declare(strict_types=1);

namespace App\Inquiries\Command;

/**
 * Sends what a stored inquiry still lacks and records the outcome; the handler returns a
 * `DeliveryOutcome`. Its own command so the row is committed before any SMTP call. Safe to
 * dispatch for a row that may already be delivered, or being delivered: the handler claims it.
 */
final readonly class DeliverInquiry
{
    public function __construct(
        public string $inquiryId,
    ) {
    }
}
