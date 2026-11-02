<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\GrantVenueAccess;
use App\Portal\Entity\PortalUser;
use App\Portal\Entity\PortalVenueAccess;
use App\Portal\Repository\PortalVenueAccessRepository;
use App\Portal\ValueObject\PortalVenueAccessStatus;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class GrantVenueAccessHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalVenueAccessRepository $access,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
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

        if (PortalVenueAccessStatus::Active !== $access->status()) {
            $access->confirm($now);
        }
    }
}
