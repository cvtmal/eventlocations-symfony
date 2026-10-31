<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\AddVenueReview;
use App\Venues\Entity\VenueReview;
use App\Venues\Exception\VenueNotFound;
use App\Venues\ReviewInput;
use App\Venues\VenueEditing;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #25, CMS-8, D17: lock, a new review with trimmed texts, close() without the listings purge
 * (reviews are not on the cards; the score is RateVenue's).
 */
final class AddVenueReviewHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly EnabledLocale $locales,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return string the new review's id
     *
     * @throws VenueNotFound
     */
    public function __invoke(AddVenueReview $command): string
    {
        if (!$this->locales->isEnabled($command->locale)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an enabled locale.', $command->locale));
        }
        $role = ReviewInput::role($command->role);
        $month = ReviewInput::month($command->month);

        $venue = $this->editing->lock($command->venueNumber);
        $review = new VenueReview($venue, $command->locale, trim($command->firstName), $role, $month, trim($command->text));
        $this->em->persist($review);

        $this->editing->close($venue, purgeListings: false);

        return $review->id();
    }
}
