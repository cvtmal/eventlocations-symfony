<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RecordSubscriptionPayment;
use App\Venues\Entity\Subscription;
use App\Venues\Exception\SubscriptionRefused;
use App\Venues\Repository\SubscriptionRepository;
use App\Venues\Repository\TierRepository;
use App\Venues\SubscriptionTiers;
use App\Venues\ValueObject\TermDay;
use App\Venues\VenueEditing;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Under the venue's lock, so two clicks cannot store two overlapping terms (at most two staff users; the unique
 * `(venue_id, starts_on)` is the backstop for the same start). The tier follows at once when the term covers today
 * (`SubscriptionTiers`, which purges); a term paid in advance moves the tier on its first day, in the nightly job.
 * `pro_paid` is recorded after the commit (G8), and only for a term that was paid: a term free of charge is no payment (D18).
 */
final class RecordSubscriptionPaymentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly TierRepository $tiers,
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionTiers $subscriptionTiers,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return string the new term's id
     *
     * @throws SubscriptionRefused
     */
    public function __invoke(RecordSubscriptionPayment $command): string
    {
        $tier = $this->tiers->byCode($command->tierCode);
        if ($tier->priceRappen() <= 0) {
            throw SubscriptionRefused::tierWithoutPrice($tier->code());
        }
        // Whole seconds, as the TIMESTAMP(0) columns keep them (PostgreSQL would round, not cut).
        $now = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        if ($command->endsOn < TermDay::of($now)) {
            throw SubscriptionRefused::inThePast($command->endsOn);
        }
        $venue = $this->editing->lock($command->venueNumber);
        if ($this->subscriptions->overlaps($venue, $command->startsOn, $command->endsOn)) {
            throw SubscriptionRefused::overlaps($venue->number(), $command->startsOn, $command->endsOn);
        }
        $invoiceRef = self::trimmed($command->invoiceRef);
        $note = self::trimmed($command->note);
        $amount = $command->amountRappen ?? $tier->priceRappen();
        if ($amount < 0 || $amount > Subscription::AMOUNT_MAX) {
            throw SubscriptionRefused::amountOutOfRange($venue->number(), $amount);
        }
        if (0 === $amount && null === $note) {
            throw SubscriptionRefused::freeWithoutNote($venue->number());
        }
        $subscription = new Subscription($venue, $tier, $command->startsOn, $command->endsOn, $amount, $invoiceRef, $note, $now);
        $this->em->persist($subscription);
        $this->em->flush();

        $this->subscriptionTiers->sync($venue, $now);
        if (!$subscription->isFreeOfCharge()) {
            $this->commandBus->dispatch(new RecordFunnelEvent(FunnelEvent::ProPaid, $venue->id()), [new DispatchAfterCurrentBusStamp()]);
        }

        return $subscription->id();
    }

    private static function trimmed(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
