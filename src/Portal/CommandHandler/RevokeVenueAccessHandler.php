<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\RevokeVenueAccess;
use App\Portal\Repository\PortalVenueAccessRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\PurgeVenueCache;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

final class RevokeVenueAccessHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalVenueAccessRepository $access,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(RevokeVenueAccess $command): void
    {
        $access = $this->access->findAccess($command->portalUserId, $command->venueId);

        if (null !== $access) {
            $number = $access->venue()->number();
            $this->em->remove($access);
            // The last manager gone, the detail page says «Noch nicht vom Betreiber bestätigt» again (D18).
            $this->commandBus->dispatch(new PurgeVenueCache($number), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
