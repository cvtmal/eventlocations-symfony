<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\VerifyClaim;
use App\Portal\Entity\ClaimChallenge;
use App\Portal\Entity\PortalUser;
use App\Portal\Entity\PortalVenueAccess;
use App\Portal\Repository\ClaimChallengeRepository;
use App\Portal\Repository\PortalUserRepository;
use App\Portal\Security\ClaimCode;
use App\Portal\ValueObject\ClaimDomain;
use App\Portal\ValueObject\ClaimOutcome;
use App\Portal\ValueObject\ClaimVerification;
use App\Portal\ValueObject\PortalVenueAccessStatus;
use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Uid\EntityId;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\RevokeVenuePreviews;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Checks a claim's code (G6). The challenge row is locked, so parallel tries all count; a wrong code is counted and
 * answered, never thrown, so the count commits. The right code proves the address: its account is found or created
 * (no password yet; the claim flow asks for one), and the venue row is locked while the access rows are read, so of
 * two claims at once one sees the other.
 *
 * Access comes at once only for an address on the website's domain (`ClaimDomain`) on a venue nobody else manages
 * or asked for; then every open preview link ends. Anything else is a pending request in #43's queue; one that met
 * somebody else's row is `competing`, and while it waits nobody on the venue can edit (`PortalVenueAccessQuery`),
 * so staff decide between them.
 */
final class VerifyClaimHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ClaimChallengeRepository $challenges,
        private readonly PortalUserRepository $users,
        private readonly ClaimCode $codes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(VerifyClaim $command): ClaimVerification
    {
        $now = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        $challenge = $this->challenges->findForUpdate($command->challengeId);
        if (null === $challenge || !$challenge->isOpen($now)) {
            return new ClaimVerification(ClaimOutcome::Dead);
        }
        if (!$this->codes->matches($challenge->codeHash(), $challenge->id(), $command->code)) {
            $challenge->failAttempt($now);
            $left = ClaimChallenge::MAX_ATTEMPTS - $challenge->attempts();

            return new ClaimVerification(0 === $left ? ClaimOutcome::Dead : ClaimOutcome::WrongCode, attemptsLeft: $left);
        }
        $challenge->verify($now);

        $venue = $challenge->venue();
        $user = $this->users->findOneByEmail($challenge->email());
        if (null === $user) {
            $user = new PortalUser(EntityId::generate(), $challenge->email(), $challenge->firstName(), $challenge->lastName());
            $this->em->persist($user);
        }
        if (!$user->isActive()) {
            return new ClaimVerification(ClaimOutcome::Deactivated);
        }

        $this->commandBus->dispatch(new RecordFunnelEvent(FunnelEvent::ClaimVerified, $venue->id(), $challenge->campaignCode()), [new DispatchAfterCurrentBusStamp()]);
        $statuses = $this->lockAndRead($venue->id());
        $own = $statuses[$user->id()] ?? null;
        unset($statuses[$user->id()]);
        $competing = [] !== $statuses;
        $answer = static fn (ClaimOutcome $outcome, bool $wroteRequest = false): ClaimVerification => new ClaimVerification(
            $outcome,
            $user->id(),
            $venue->number(),
            $venue->name(),
            null === $user->passwordHash(),
            $competing && ClaimOutcome::Queued === $outcome,
            0,
            $user->email(),
            $user->firstName(),
            $user->lastName(),
            $wroteRequest,
        );

        if (PortalVenueAccessStatus::Active->value === $own) {
            return $answer(ClaimOutcome::AlreadyManager);
        }
        if (null !== $own) {
            return $answer(ClaimOutcome::Queued);
        }

        $access = new PortalVenueAccess($user, $venue, $now);
        $this->em->persist($access);
        if ($competing || !ClaimDomain::matches($challenge->email(), $venue->website())) {
            if ($competing) {
                $access->markCompeting();
            }

            return $answer(ClaimOutcome::Queued, true);
        }

        $access->confirm($now);
        $this->commandBus->dispatch(new RevokeVenuePreviews($venue->id()));
        // The detail page loses its «Noch nicht vom Betreiber bestätigt» (D18).
        $this->commandBus->dispatch(new PurgeVenueCache($venue->number()), [new DispatchAfterCurrentBusStamp()]);

        return $answer(ClaimOutcome::Granted);
    }

    /**
     * @return array<string, string> portal user id => access status, read under the venue's row lock
     */
    private function lockAndRead(string $venueId): array
    {
        $connection = $this->em->getConnection();
        $connection->executeQuery('SELECT 1 FROM venues WHERE id = :id FOR UPDATE', ['id' => $venueId]);

        /* @var array<string, string> */
        return $connection->fetchAllKeyValue(
            'SELECT portal_user_id, status FROM portal_venue_access WHERE venue_id = :id',
            ['id' => $venueId],
        );
    }
}
