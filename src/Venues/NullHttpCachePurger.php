<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * No HTTP cache exists yet (#19 adds one), so there is nothing to purge.
 */
final class NullHttpCachePurger implements HttpCachePurger
{
    public function purgeVenue(int $number): void
    {
    }

    public function purgeListings(): void
    {
    }
}
