<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueGooglePlace;
use App\Venues\ValueObject\GooglePlaceId;
use App\Venues\VenueEditing;

/**
 * Saves the CMS card "Google-Bewertung" (#53). It closes without stamping `cms_edited_at` (see VenueEditing), and
 * only the venue's page shows the rating, so the listings keep their cache.
 */
final class ChangeVenueGooglePlaceHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
    ) {
    }

    public function __invoke(ChangeVenueGooglePlace $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);

        $placeId = GooglePlaceId::fromInput($command->placeId);
        if (!$venue->changeGooglePlace($placeId?->value, $command->ratingHidden)) {
            return;
        }

        $this->editing->close($venue, purgeListings: false, markCmsEdited: false);
    }
}
