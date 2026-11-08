<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\CancelSubscription;
use App\Venues\Exception\SubscriptionNotFound;
use App\Venues\Exception\SubscriptionRefused;
use App\Venues\Repository\SubscriptionRepository;
use App\Venues\ValueObject\TermDay;
use App\Venues\VenueEditing;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Nothing public changes (the term keeps its tier to its last day), so there is no purge and no `cms_edited_at`.
 * `subscription_cancelled` is recorded once, after the commit (G8).
 */
final class CancelSubscriptionHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly SubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws SubscriptionNotFound
     * @throws SubscriptionRefused
     */
    public function __invoke(CancelSubscription $command): bool
    {
        $venue = $this->editing->lock($command->venueNumber);
        $subscription = $this->subscriptions->get($command->subscriptionId);
        if ($subscription->venue()->id() !== $venue->id()) {
            throw SubscriptionNotFound::forId($command->subscriptionId);
        }
        $now = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        if ($subscription->endsOn() < TermDay::of($now)) {
            throw SubscriptionRefused::ended($subscription->id());
        }
        if (!$subscription->requestCancellation($now)) {
            return false;
        }
        $this->em->flush();
        $this->commandBus->dispatch(new RecordFunnelEvent(FunnelEvent::SubscriptionCancelled, $venue->id()), [new DispatchAfterCurrentBusStamp()]);

        return true;
    }
}
