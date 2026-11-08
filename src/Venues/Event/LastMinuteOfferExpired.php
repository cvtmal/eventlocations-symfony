<?php

declare(strict_types=1);

namespace App\Venues\Event;

/**
 * An offer «Kurzfristig frei» (G14) passed its last day and the nightly `app:last-minute:expire` deleted it. Portal
 * tells the venue's managers; the row is gone, so the event carries what the mail names.
 */
final readonly class LastMinuteOfferExpired
{
    public function __construct(
        public string $venueId,
        public int $venueNumber,
        public string $venueName,
        public \DateTimeImmutable $startsOn,
        public \DateTimeImmutable $endsOn,
    ) {
    }
}
