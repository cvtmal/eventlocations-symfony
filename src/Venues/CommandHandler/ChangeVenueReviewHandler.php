<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueReview;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\ReviewInput;
use App\Venues\RowTokens;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;

/**
 * #25, CMS-8, PF2: the review's own token decides staleness; trimmed texts; nothing changed
 * means no revise() and no close(). Only the venue is purged (D19).
 */
final class ChangeVenueReviewHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
    ) {
    }

    /**
     * @throws VenueRowNotFound
     * @throws StaleEdit
     */
    public function __invoke(ChangeVenueReview $command): void
    {
        $role = ReviewInput::role($command->role);
        $month = ReviewInput::month($command->month);
        $firstName = trim($command->firstName);
        $text = trim($command->text);

        $venue = $this->editing->lock($command->venueNumber);
        $review = $this->rows->review($venue, $command->reviewId);
        RowTokens::assertFresh($review->updatedAt(), $command->loadedAt, $command->venueNumber);

        if ($review->firstName() === $firstName && $review->role() === $role && $review->text() === $text
            && $review->reviewedMonth()->format('Y-m') === $command->month) {
            return;
        }

        $review->revise($firstName, $role, $month, $text);
        $this->editing->close($venue, purgeListings: false);
    }
}
