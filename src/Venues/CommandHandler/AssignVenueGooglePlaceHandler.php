<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\AssignVenueGooglePlace;
use App\Venues\ValueObject\GooglePlaceId;
use App\Venues\VenueEditing;

/**
 * Sets a reviewed lookup result (#53). A venue that already has a place ID keeps it, so the lookup never overwrites
 * staff work. Like the CMS card, it closes without stamping `cms_edited_at` and leaves the listings' cache alone.
 */
final class AssignVenueGooglePlaceHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
    ) {
    }

    /**
     * @return bool whether the place ID was set; false when the venue already had one
     */
    public function __invoke(AssignVenueGooglePlace $command): bool
    {
        $venue = $this->editing->lock($command->venueNumber);
        if (null !== $venue->googlePlaceId()) {
            return false;
        }

        $placeId = GooglePlaceId::fromInput($command->placeId) ?? throw new \InvalidArgumentException('A place ID to assign must not be empty.');
        $venue->changeGooglePlace($placeId->value, false);
        $this->editing->close($venue, purgeListings: false, markCmsEdited: false);

        return true;
    }
}
