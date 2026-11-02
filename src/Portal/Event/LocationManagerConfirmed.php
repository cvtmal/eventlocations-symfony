<?php

declare(strict_types=1);

namespace App\Portal\Event;

/**
 * A manager was confirmed for a location, in the review queue or the staff's panel. It carries plain values, so
 * a subscriber reads nothing else of Portal. `$venueName` is here for the CRM's timeline text.
 */
final readonly class LocationManagerConfirmed
{
    public function __construct(
        public string $venueId,
        public string $venueName,
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
    ) {
    }
}
