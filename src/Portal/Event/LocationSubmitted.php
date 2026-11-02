<?php

declare(strict_types=1);

namespace App\Portal\Event;

/**
 * A manager sent a location to review, and it really started (POR-12). It carries plain values, so a subscriber reads
 * nothing else of Portal. `$venueName` is here for the CRM's timeline text.
 */
final readonly class LocationSubmitted
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
