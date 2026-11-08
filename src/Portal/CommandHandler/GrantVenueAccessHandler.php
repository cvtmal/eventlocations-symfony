<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\GrantVenueAccess;
use App\Portal\Entity\PortalUser;
use App\Portal\Entity\PortalVenueAccess;
use App\Portal\Repository\PortalVenueAccessRepository;
use App\Portal\ValueObject\PortalVenueAccessStatus;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\CheckVenueFacts;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\RevokeVenuePreviews;
use App\Venues\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Staff confirm a request or add a manager. A venue somebody manages is claimed, so its open preview links end (G6):
 * their banner says the entry is not confirmed by its operator; the detail page drops the same label (D18). An approval
 * from the review queue also checks the facts (D45).
 */
final class GrantVenueAccessHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalVenueAccessRepository $access,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(GrantVenueAccess $command): void
    {
        $now = $this->clock->now();
        $access = $this->access->findAccess($command->portalUserId, $command->venueId);

        if (null === $access) {
            $access = new PortalVenueAccess(
                $this->em->getReference(PortalUser::class, $command->portalUserId) ?? throw new \LogicException('PortalUser has no subclass, so a reference always resolves.'),
                $this->em->getReference(Venue::class, $command->venueId) ?? throw new \LogicException('Venue has no subclass, so a reference always resolves.'),
                $now,
            );
            $this->em->persist($access);
        }

        $venue = $access->venue();
        if (PortalVenueAccessStatus::Active !== $access->status()) {
            $access->confirm($now);
            // D45: staff approved a claim from the review queue, so the operator confirmed the facts. A manager
            // added in the panel is staff's own act; they tick «Fakten geprüft» themselves.
            if (GrantVenueAccess::VIA_REVIEW === $command->via && null === $venue->factsCheckedAt()) {
                $this->commandBus->dispatch(new CheckVenueFacts($venue->number(), true, CheckVenueFacts::VIA_CLAIM));
            }
        }
        $this->commandBus->dispatch(new RevokeVenuePreviews($command->venueId));
        $this->commandBus->dispatch(new PurgeVenueCache($venue->number()), [new DispatchAfterCurrentBusStamp()]);
    }
}
