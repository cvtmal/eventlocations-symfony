<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\EndLastMinuteOffer;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;

/**
 * G14: lock, the offer of this venue (an offer of another venue is not found), delete it, close() with the listings
 * purge like the publish.
 */
final class EndLastMinuteOfferHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws VenueNotFound
     * @throws VenueRowNotFound
     */
    public function __invoke(EndLastMinuteOffer $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $offer = $this->rows->lastMinuteOffer($venue, $command->offerId);

        $this->em->remove($offer);
        $this->em->flush();
        $this->editing->close($venue, purgeListings: true, markCmsEdited: false);
    }
}
