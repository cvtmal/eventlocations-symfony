<?php

declare(strict_types=1);

namespace App\Venues\Event;

/**
 * A venue `CreateVenue` made, from the CMS or the portal's wizard, went online for the first time (Q20). Fired once per
 * venue: never for an imported or seeded venue, never for a return from offline.
 */
final readonly class NewVenuePublished
{
    public function __construct(
        public string $venueId,
    ) {
    }
}
