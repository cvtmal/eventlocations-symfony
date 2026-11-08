<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * The nightly step of one venue (G9, `app:subscriptions:expire`): its ended terms turn `expired` and `venues.tier_id`
 * follows the tier the terms give today (Free after the last one, the new tier on the first day of a term paid in
 * advance). Answers whether anything changed, so a second run of the job writes nothing. Not audited: a tier change
 * is audited by the nested `ChangeVenueTier`.
 */
final readonly class RefreshVenueSubscription
{
    public function __construct(
        public int $venueNumber,
    ) {
    }
}
