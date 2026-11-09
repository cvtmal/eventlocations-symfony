<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

use App\Inquiries\ReadModel\StuckInquiry;

/**
 * What `AlertStuckInquiries` tells the console: the inquiries it was due to report, and whether the staff mail went
 * out (true when there was nothing to report). Unmailed rows stay unreported, so the next run tries again.
 */
final readonly class StuckAlert
{
    /**
     * @param list<StuckInquiry> $rows
     */
    public function __construct(
        public array $rows,
        public bool $mailed,
    ) {
    }
}
