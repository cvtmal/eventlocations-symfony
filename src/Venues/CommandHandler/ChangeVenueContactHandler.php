<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueContact;
use App\Venues\VenueEditing;

final class ChangeVenueContactHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
    ) {
    }

    public function __invoke(ChangeVenueContact $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);

        $venue->changeContact($command->phone, $command->inquiryEmail, $command->website, $command->contactPerson);
        $venue->changeInstagram($command->instagram);

        $this->editing->close($venue);
    }
}
