<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueCapacity;
use App\Venues\VenueEditing;

/**
 * Sets the maximum. A staff minimum above the new maximum is cleared, because
 * `chk_venues_capacity` refuses it and the manager has no field for it.
 */
final class ChangeVenueCapacityHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
    ) {
    }

    public function __invoke(ChangeVenueCapacity $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);

        $min = $venue->capacityMin();
        $venue->changeCapacity($command->capacityMax, null !== $min && $min > $command->capacityMax ? null : $min);

        $this->editing->close($venue);
    }
}
