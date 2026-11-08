<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * One reminder mail before a paid term ends (G9, `app:subscriptions:remind`): 30 or 7 days ahead. The handler sends it
 * to the location's managers (or its inquiry address) and writes the marker, and answers false when the reminder is
 * no longer due or no mail went out (then the next night tries again). Not audited: it changes no venue.
 */
final readonly class SendSubscriptionReminder
{
    public function __construct(
        public string $subscriptionId,
        public int $daysBefore,
    ) {
    }
}
