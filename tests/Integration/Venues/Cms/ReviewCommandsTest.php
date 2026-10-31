<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\VenueFactory;
use App\Factory\VenueReviewFactory;
use App\Venues\Command\AddVenueReview;
use App\Venues\Command\ChangeVenueReview;
use App\Venues\Command\RemoveVenueReview;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\ValueObject\ReviewerRole;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * AddVenueReview, ChangeVenueReview, RemoveVenueReview (#25, Step 5.5, CMS-8, D17, D19).
 */
final class ReviewCommandsTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;

    public function test_add_change_and_remove_a_review_without_touching_the_rating(): void
    {
        $venue = VenueFactory::createOne();
        $number = $venue->number();
        $this->connection->executeStatement('UPDATE venues SET rating_score = 8.4, rating_count = 12 WHERE number = ?', [$number]);
        $this->em->clear();

        $id = $this->dispatch(new AddVenueReview($number, 'de', ' Anna ', 'visitor_f', '2026-09', ' Ein schöner Tag. '));
        self::assertIsString($id);
        self::assertSame(
            ['locale' => 'de', 'first_name' => 'Anna', 'role' => 'visitor_f', 'reviewed_month' => '2026-09-01', 'text' => 'Ein schöner Tag.'],
            $this->connection->fetchAssociative('SELECT locale, first_name, role, reviewed_month, text FROM venue_reviews WHERE id = ?', [$id]),
        );
        self::assertTrue($this->cmsEdited($number));

        $this->ageRow('venue_reviews', $id);
        $this->em->clear();
        $token = $this->rowToken('venue_reviews', $id);
        $this->dispatch(new ChangeVenueReview($number, $id, $token, 'Anna', 'visitor_f', '2026-09', 'Ein schöner Tag.'));
        self::assertSame($token, $this->rowToken('venue_reviews', $id), 'an unchanged save is a no-op');

        $this->em->clear();
        $this->dispatch(new ChangeVenueReview($number, $id, $token, 'Beat', 'organiser_m', '2025-12', 'Alles bestens.'));
        self::assertSame(
            ['first_name' => 'Beat', 'role' => 'organiser_m', 'reviewed_month' => '2025-12-01', 'text' => 'Alles bestens.'],
            $this->connection->fetchAssociative('SELECT first_name, role, reviewed_month, text FROM venue_reviews WHERE id = ?', [$id]),
        );

        $this->em->clear();
        $this->dispatch(new RemoveVenueReview($number, $id));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM venue_reviews WHERE id = ?', [$id]));

        self::assertSame(['rating_score' => '8.4', 'rating_count' => 12], $this->connection->fetchAssociative('SELECT rating_score, rating_count FROM venues WHERE number = ?', [$number]));
        self::assertSame([$number, $number, $number], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);
    }

    public function test_a_stale_token_a_foreign_review_and_bad_input_are_refused(): void
    {
        $venue = VenueFactory::createOne();
        $review = VenueReviewFactory::createOne(['venue' => $venue, 'firstName' => 'Anna', 'role' => ReviewerRole::VisitorF]);
        $foreign = VenueReviewFactory::createOne()->id();
        $this->ageRow('venue_reviews', $review->id());
        $this->em->clear();
        $stale = (string) ((int) $this->rowToken('venue_reviews', $review->id()) - 1);

        self::assertInstanceOf(StaleEdit::class, $this->failure(new ChangeVenueReview($venue->number(), $review->id(), $stale, 'Beat', 'visitor_m', '2026-01', 'Text')));
        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new ChangeVenueReview($venue->number(), $foreign, '1', 'Beat', 'visitor_m', '2026-01', 'Text')));
        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new RemoveVenueReview($venue->number(), $foreign)));
        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new RemoveVenueReview($venue->number(), 'kein-uuid')));
        foreach ([['xx', 'visitor_f', '2026-01'], ['de', 'guest', '2026-01'], ['de', 'visitor_f', '2026-13'], ['de', 'visitor_f', '2026-01-15']] as [$locale, $role, $month]) {
            self::assertInstanceOf(\InvalidArgumentException::class, $this->failure(new AddVenueReview($venue->number(), $locale, 'Anna', $role, $month, 'Text')));
        }

        self::assertSame('Anna', $this->connection->fetchOne('SELECT first_name FROM venue_reviews WHERE id = ?', [$review->id()]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_reviews WHERE venue_id = ?', [$venue->id()]));
        self::assertFalse($this->cmsEdited($venue->number()));
    }
}
