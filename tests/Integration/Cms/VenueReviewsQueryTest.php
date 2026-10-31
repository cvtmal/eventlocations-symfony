<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\ReadModel\VenueIdQuery;
use App\Cms\ReadModel\VenueReviewsQuery;
use App\Factory\VenueFactory;
use App\Factory\VenueReviewFactory;
use App\Venues\ValueObject\ReviewerRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class VenueReviewsQueryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private Connection $connection;
    private VenueReviewsQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->query = new VenueReviewsQuery($this->connection, new VenueIdQuery($this->connection));
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_an_unknown_venue_is_null_and_a_venue_without_reviews_an_empty_list(): void
    {
        self::assertNull($this->query->forVenue(999_999_999));

        $venue = VenueFactory::new()->withoutLocation()->create();
        self::assertSame([], $this->query->forVenue($venue->number()));
    }

    public function test_reviews_newest_month_first_with_every_field_and_token(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $older = VenueReviewFactory::createOne(['venue' => $venue, 'reviewedMonth' => new \DateTimeImmutable('2025-03-17')]);
        $newer = VenueReviewFactory::createOne([
            'venue' => $venue,
            'locale' => 'de',
            'firstName' => 'Lea',
            'role' => ReviewerRole::OrganiserF,
            'reviewedMonth' => new \DateTimeImmutable('2026-06-05'),
            'text' => 'Ein wunderbarer Tag am See.',
        ]);
        VenueReviewFactory::createOne([]);
        $this->connection->executeStatement("UPDATE venue_reviews SET updated_at = '2026-10-04 09:15:00+00' WHERE id = :id", ['id' => $newer->id()]);

        $reviews = $this->query->forVenue($venue->number());

        self::assertNotNull($reviews);
        self::assertSame([$newer->id(), $older->id()], array_map(static fn ($review): string => $review->id, $reviews));
        self::assertSame(
            ['de', 'Lea', ReviewerRole::OrganiserF, '2026-06', 'Ein wunderbarer Tag am See.', (string) (new \DateTimeImmutable('2026-10-04 09:15:00+00'))->getTimestamp()],
            [$reviews[0]->locale, $reviews[0]->firstName, $reviews[0]->role, $reviews[0]->month, $reviews[0]->text, $reviews[0]->token],
        );
        self::assertSame('2025-03', $reviews[1]->month);
    }

    public function test_one_review_only_of_its_own_venue(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $review = VenueReviewFactory::createOne(['venue' => $venue]);
        $foreign = VenueReviewFactory::createOne([]);

        self::assertSame($review->id(), $this->query->review($venue->number(), $review->id())?->id);
        self::assertNull($this->query->review($venue->number(), $foreign->id()));
        self::assertNull($this->query->review($venue->number(), 'x'));
        self::assertNull($this->query->review(0, $review->id()));
    }
}
