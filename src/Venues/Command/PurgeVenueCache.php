<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * Purge every public page of one venue from the HTTP cache (HttpCachePurger::purgeVenue()).
 * Dispatch it from a Venues handler with DispatchAfterCurrentBusStamp, so it runs only after
 * the change committed (#9 import, #24–#26 CMS).
 */
final readonly class PurgeVenueCache
{
    public function __construct(
        public int $venueNumber,
    ) {
    }
}
