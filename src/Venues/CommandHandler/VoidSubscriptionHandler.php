<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Command\WithdrawFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\VoidSubscription;
use App\Venues\Exception\SubscriptionNotFound;
use App\Venues\Exception\SubscriptionRefused;
use App\Venues\Repository\SubscriptionRepository;
use App\Venues\SubscriptionTiers;
use App\Venues\ValueObject\TermDay;
use App\Venues\VenueEditing;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Under the venue's lock. The tier follows at once (`SubscriptionTiers`, whose `ChangeVenueTier` purges); when it does
 * not move (a term paid in advance, or another term still covers today) the venue and the listings are purged anyway,
 * so every page reads the terms afresh. A paid term's `pro_paid` is withdrawn after the commit (D18).
 */
final class VoidSubscriptionHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionTiers $subscriptionTiers,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws SubscriptionNotFound
     * @throws SubscriptionRefused
     */
    public function __invoke(VoidSubscription $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $subscription = $this->subscriptions->get($command->subscriptionId);
        if ($subscription->venue()->id() !== $venue->id()
            || $subscription->tier()->code() !== $command->tierCode
            || $subscription->startsOn()->format('Y-m-d') !== $command->startsOn->format('Y-m-d')
            || $subscription->endsOn()->format('Y-m-d') !== $command->endsOn->format('Y-m-d')
            || $subscription->amountRappen() !== $command->amountRappen) {
            throw SubscriptionNotFound::forId($command->subscriptionId);
        }
        $now = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        if ($subscription->endsOn() < TermDay::of($now)) {
            throw SubscriptionRefused::ended($subscription->id());
        }
        // Only the latest term goes, so voiding never leaves a gap between two paid ones.
        if ($this->subscriptions->laterThan($subscription)) {
            throw SubscriptionRefused::laterTermFollows($subscription->id());
        }
        $paidAt = $subscription->isFreeOfCharge() ? null : $subscription->paidAt();
        $this->em->remove($subscription);
        $this->em->flush();

        if (!$this->subscriptionTiers->sync($venue, $now)) {
            $this->editing->close($venue, markCmsEdited: false);
        }
        if (null !== $paidAt) {
            $this->commandBus->dispatch(new WithdrawFunnelEvent(FunnelEvent::ProPaid, $venue->id(), $paidAt), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
