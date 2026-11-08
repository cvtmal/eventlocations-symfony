<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * Deletes every offer «Kurzfristig frei» (G14) whose last day lies before `today` (a Zurich day); the handler answers
 * what it deleted, as `LastMinuteOfferExpired` events for the caller to publish after the commit. Not audited: the
 * rows were already hidden on every page, and a second run finds nothing.
 */
final readonly class ExpireLastMinuteOffers
{
    public function __construct(
        public \DateTimeImmutable $today,
    ) {
    }
}
