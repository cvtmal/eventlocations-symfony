<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\UnpublishVenue;
use App\Venues\VenueEditing;

/**
 * Takes a venue offline; it needs nothing. Unpublishing an offline venue changes nothing.
 */
final class UnpublishVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
    ) {
    }

    public function __invoke(UnpublishVenue $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        if (!$venue->isPublished()) {
            return;
        }

        $venue->unpublish();
        $this->editing->close($venue);
    }
}
