<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFeedbackFactory;
use App\Factory\VenueFactory;
use App\Inquiries\ReadModel\FeedbackState;
use App\Inquiries\ReadModel\InquiryFeedbackCounts;
use App\Inquiries\ReadModel\InquiryFeedbackQuery;
use App\Inquiries\ValueObject\FeedbackOutcome;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\StoresDeliveredInquiries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * G16's read side: one inquiry's feedback (answer page, CRM) and «x Rückmeldungen» of a venue's month (G10), which
 * counts answers by the Zurich month of the first answer, whatever they say.
 */
final class InquiryFeedbackQueriesTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use StoresDeliveredInquiries;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_the_state_of_one_inquiry(): void
    {
        $inquiry = $this->delivered('2026-06-01 10:00:00 UTC');
        $query = self::getContainer()->get(InquiryFeedbackQuery::class);
        self::assertInstanceOf(InquiryFeedbackQuery::class, $query);
        self::assertNull($query->forInquiry($inquiry->id()));
        self::assertNull($query->forInquiry('not-a-uuid'));

        InquiryFeedbackFactory::new()
            ->answered(false, FeedbackOutcome::Open, new \DateTimeImmutable('2026-06-09 08:00:00 UTC'))
            ->create(['inquiry' => $inquiry, 'at' => new \DateTimeImmutable('2026-06-08 03:15:00 UTC')]);

        self::assertEquals(
            new FeedbackState($inquiry->id(), $inquiry->venueName(), true, false, false, FeedbackOutcome::Open, new \DateTimeImmutable('2026-06-09 08:00:00 UTC')),
            $query->forInquiry($inquiry->id()),
        );
    }

    public function test_the_count_takes_the_answers_of_the_venue_in_the_zurich_month(): void
    {
        $venue = VenueFactory::new()->withContact()->create();
        // 23:59:59 in Zurich (May), midnight (June), the last second of June, the first of July.
        foreach (['2026-05-31 21:59:59 UTC', '2026-05-31 22:00:00 UTC', '2026-06-30 21:59:59 UTC', '2026-06-30 22:00:00 UTC'] as $answeredAt) {
            InquiryFeedbackFactory::new()
                ->answered(true, FeedbackOutcome::No, new \DateTimeImmutable($answeredAt))
                ->create(['inquiry' => $this->delivered('2026-05-20 10:00:00 UTC', ['venue' => $venue]), 'at' => new \DateTimeImmutable('2026-05-27 03:15:00 UTC')]);
        }
        InquiryFeedbackFactory::createOne(['inquiry' => $this->delivered('2026-06-01 10:00:00 UTC', ['venue' => $venue]), 'at' => new \DateTimeImmutable('2026-06-08 03:15:00 UTC')]);
        InquiryFeedbackFactory::new()
            ->answered(true, FeedbackOutcome::Yes, new \DateTimeImmutable('2026-06-10 10:00:00 UTC'))
            ->create(['inquiry' => $this->delivered('2026-06-01 10:00:00 UTC'), 'at' => new \DateTimeImmutable('2026-06-08 03:15:00 UTC')]);

        // No service uses it before G10 wires it into the report, so the container has removed it.
        $counts = new InquiryFeedbackCounts($this->connection);

        self::assertSame(2, $counts->forVenue($venue->id(), new \DateTimeImmutable('2026-06-15 12:00:00 UTC')));
        self::assertSame(1, $counts->forVenue($venue->id(), new \DateTimeImmutable('2026-05-02 12:00:00 UTC')));
    }
}
