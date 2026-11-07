<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\StartClaim;
use App\Portal\Entity\ClaimChallenge;
use App\Portal\Exception\ClaimThrottled;
use App\Portal\Repository\ClaimChallengeRepository;
use App\Portal\Security\ClaimCode;
use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Stores a new challenge and answers its code for the mail. Rows older than a day go first: they only serve the
 * limits below, and nobody's name and address should stay longer. The limits hold whether or not the address has
 * an account, so they tell nothing about one.
 */
final class StartClaimHandler implements CommandHandlerInterface
{
    public const int PER_ADDRESS_PER_HOUR = 3;
    public const int PER_VENUE_PER_DAY = 10;

    public function __construct(
        private readonly ClaimChallengeRepository $challenges,
        private readonly ClaimCode $codes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @throws ClaimThrottled
     */
    public function __invoke(StartClaim $command): string
    {
        $now = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        $this->challenges->deleteCreatedBefore($now->modify('-1 day'));
        // Counted under the venue's row lock, so parallel posts cannot slip past the limits.
        $this->em->getConnection()->executeQuery('SELECT 1 FROM venues WHERE id = :id FOR UPDATE', ['id' => $command->venueId]);

        if ($this->challenges->countForEmailSince($command->email, $now->modify('-1 hour')) >= self::PER_ADDRESS_PER_HOUR) {
            throw ClaimThrottled::forAddress();
        }
        if ($this->challenges->countForVenueSince($command->venueId, $now->modify('-1 day')) >= self::PER_VENUE_PER_DAY) {
            throw ClaimThrottled::forVenue();
        }

        $code = $this->codes->generate();
        $venue = $this->em->getReference(Venue::class, $command->venueId) ?? throw new \LogicException('Venue has no subclass, so a reference always resolves.');
        $this->em->persist(new ClaimChallenge(
            $command->id,
            $venue,
            $command->email,
            $command->firstName,
            $command->lastName,
            $this->codes->hash($command->id, $code),
            $command->campaignCode,
            $now,
        ));

        $this->commandBus->dispatch(new RecordFunnelEvent(FunnelEvent::ClaimStarted, $command->venueId, $command->campaignCode), [new DispatchAfterCurrentBusStamp()]);

        return $code;
    }
}
