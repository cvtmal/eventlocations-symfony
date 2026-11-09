<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

/**
 * A venue of a sent brief as the guest's confirmation page names it: reached when its mail went out.
 */
final readonly class BriefRecipient
{
    public function __construct(
        public string $venueName,
        public string $venueSlug,
        public bool $reached,
    ) {
    }
}
