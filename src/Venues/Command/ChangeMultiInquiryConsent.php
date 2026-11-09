<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * The venue's one-click opt-out of briefs to several venues from the link in such a mail (F13). The contact card
 * carries the same switch in `ChangeVenueContact`. Not audited: the venue itself sends it, no staff member.
 */
final readonly class ChangeMultiInquiryConsent
{
    public function __construct(
        public int $venueNumber,
        public bool $accepts,
    ) {
    }
}
