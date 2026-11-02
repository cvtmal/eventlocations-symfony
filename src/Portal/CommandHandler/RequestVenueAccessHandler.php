<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Cms\ReadModel\VenueHeaderQuery;
use App\Portal\Command\RequestVenueAccess;
use App\Portal\Exception\VenueNotClaimable;
use App\Portal\Repository\PortalVenueAccessRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;

final class RequestVenueAccessHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueHeaderQuery $venues,
        private readonly PortalVenueAccessRepository $access,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestVenueAccess $command): void
    {
        $venue = $this->venues->find($command->venueNumber);
        if (null === $venue || !$venue->isPublished) {
            throw VenueNotClaimable::forNumber($command->venueNumber);
        }

        $this->access->insertPending($command->portalUserId, $venue->id, $this->clock->now());
    }
}
