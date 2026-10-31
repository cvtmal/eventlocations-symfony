<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RemoveVenueReview;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #25, CMS-8, D17: the review goes; rating_score and rating_count stay (they are not derived
 * from the rows). Only the venue is purged (D19).
 */
final class RemoveVenueReviewHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws VenueRowNotFound
     */
    public function __invoke(RemoveVenueReview $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $this->em->remove($this->rows->review($venue, $command->reviewId));

        $this->editing->close($venue, purgeListings: false);
    }
}
