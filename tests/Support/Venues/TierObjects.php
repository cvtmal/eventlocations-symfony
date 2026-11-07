<?php

declare(strict_types=1);

namespace App\Tests\Support\Venues;

use App\Venues\Entity\Tier;
use App\Venues\ValueObject\KnownTiers;

/**
 * Unpersisted `Tier` entities with the values the migration seeds (F12), for the unit tests that build a `Venue`
 * without a database. Integration and application tests read the seeded rows instead (`VenueFactory` takes
 * `'tier' => 'free'|'pro'`).
 */
final class TierObjects
{
    public static function free(): Tier
    {
        return new Tier(KnownTiers::FREE, 'Free', 0, false, 0, 6, 1, 1, 1, false, false, false, 3);
    }

    public static function pro(): Tier
    {
        return new Tier(KnownTiers::PRO, 'Pro', 19900, false, 10, 60, 20, 5, 5, true, true, true, null);
    }
}
