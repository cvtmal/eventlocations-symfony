<?php

declare(strict_types=1);

namespace App\Crm\Command;

/**
 * Turns a stored inquiry into a timeline entry on the venue's Location and a link row (CRM-9, #55), from
 * `IngestInquiryListener` and `app:crm:backfill-inquiries`. Not auditable: it is a guest action, and
 * the link row is its record. The handler answers whether it wrote one.
 */
final readonly class IngestInquiry
{
    public function __construct(
        public string $inquiryId,
    ) {
    }
}
