<?php

declare(strict_types=1);

namespace App\Inquiries\Command;

/**
 * Deletes inquiries past the retention period (LEG-2); the handler returns how many it
 * deleted, or would delete with `dryRun`.
 */
final readonly class PurgeInquiries
{
    public function __construct(
        public bool $dryRun = false,
    ) {
    }
}
