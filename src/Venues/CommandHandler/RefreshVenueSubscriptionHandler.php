<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RefreshVenueSubscription;
use App\Venues\Repository\SubscriptionRepository;
use App\Venues\SubscriptionTiers;
use App\Venues\ValueObject\TermDay;
use App\Venues\VenueEditing;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Under the venue's lock, like every term write. The venue stays online whatever happens: only the tier moves, and
 * with it what the pages show (photos above the Free budget hidden, never deleted; F12's read cut).
 */
final class RefreshVenueSubscriptionHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionTiers $subscriptionTiers,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether a term or the tier changed
     */
    public function __invoke(RefreshVenueSubscription $command): bool
    {
        $venue = $this->editing->lock($command->venueNumber);
        $now = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        $ended = $this->subscriptions->endedBefore($venue, TermDay::of($now));
        foreach ($ended as $subscription) {
            $subscription->expire($now);
        }
        $this->em->flush();

        return $this->subscriptionTiers->sync($venue, $now) || [] !== $ended;
    }
}
