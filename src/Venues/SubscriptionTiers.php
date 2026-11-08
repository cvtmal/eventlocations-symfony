<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Command\ChangeVenueTier;
use App\Venues\Entity\Venue;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps `venues.tier_id`, the tier the listings order by, in step with the tier the paid terms give at `$now`
 * (G9). Call it from a term handler after its flush, under the venue's lock: the nested `ChangeVenueTier` commits
 * with that handler, is audited (the CRM's company timeline shows it) and purges the venue and the listings
 * after the commit (Wave G §4a.1).
 */
final readonly class SubscriptionTiers
{
    public function __construct(
        private EntitlementResolver $entitlements,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @return bool whether the venue's tier changed
     */
    public function sync(Venue $venue, \DateTimeImmutable $now): bool
    {
        $code = $this->entitlements->forVenue($venue->id(), $now)->tierCode;
        if ($code === $venue->tier()->code()) {
            return false;
        }
        $this->commandBus->dispatch(new ChangeVenueTier($venue->number(), $code));

        return true;
    }
}
