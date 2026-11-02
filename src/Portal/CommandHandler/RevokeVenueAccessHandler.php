<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\RevokeVenueAccess;
use App\Portal\Repository\PortalVenueAccessRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

final class RevokeVenueAccessHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalVenueAccessRepository $access,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RevokeVenueAccess $command): void
    {
        $access = $this->access->findAccess($command->portalUserId, $command->venueId);

        if (null !== $access) {
            $this->em->remove($access);
        }
    }
}
