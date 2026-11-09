<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

/**
 * The venue an opt-out link names, and whether it still takes briefs.
 */
final readonly class BriefOptOutVenue
{
    public function __construct(
        public int $number,
        public string $name,
        public bool $acceptsMultiInquiries,
    ) {
    }
}
