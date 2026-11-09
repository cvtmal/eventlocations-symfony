<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFactory;
use App\Factory\VenueFactory;
use App\Inquiries\ReadModel\StuckInquiries;
use App\Inquiries\ReadModel\StuckInquiry;
use App\Inquiries\ValueObject\InquiryOverride;
use App\Inquiries\ValueObject\MailStatus;
use App\Tests\Integration\Geo\ImportsTownDataset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * #20 PD7, G7 (D56): what the hourly staff alert is due to report. `pending` is the only sign of a process death
 * between the SMTP call and the commit; `failed` is reported at once. Each inquiry once, unmarked, of the last week.
 */
final class StuckInquiriesTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const NOW = '2026-06-01 12:00:00 UTC';

    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_it_lists_pending_rows_older_than_the_bound(): void
    {
        $old = $this->inquiry('2026-06-01 11:44:00 UTC');
        $this->inquiry('2026-06-01 11:55:00 UTC');

        $stuck = $this->stuck();

        self::assertCount(1, $stuck);
        self::assertSame($old, $stuck[0]->id);
    }

    public function test_the_bound_itself_is_not_stuck_yet(): void
    {
        $this->inquiry('2026-06-01 11:45:00 UTC');

        self::assertSame([], $this->stuck());
    }

    public function test_a_failed_row_is_due_at_once(): void
    {
        $failed = $this->inquiry('2026-06-01 11:59:00 UTC', MailStatus::Failed);

        $stuck = $this->stuck();

        self::assertSame([$failed], array_map(static fn (StuckInquiry $inquiry): string => $inquiry->id, $stuck));
        self::assertSame(MailStatus::Failed, $stuck[0]->venueMailStatus);
    }

    public function test_a_sent_row_is_never_stuck(): void
    {
        $this->inquiry('2026-06-01 11:00:00 UTC', MailStatus::Sent);

        self::assertSame([], $this->stuck());
    }

    public function test_a_marked_row_is_left_out(): void
    {
        foreach ([InquiryOverride::Spam, InquiryOverride::Test] as $override) {
            $id = $this->inquiry('2026-06-01 11:00:00 UTC', MailStatus::Failed);
            $this->connection->executeStatement('UPDATE inquiries SET qualification_override = ? WHERE id = ?', [$override->value, $id]);
            $id = $this->inquiry('2026-06-01 11:00:00 UTC');
            $this->connection->executeStatement('UPDATE inquiries SET qualification_override = ? WHERE id = ?', [$override->value, $id]);
        }

        self::assertSame([], $this->stuck());
    }

    public function test_an_alerted_row_is_left_out(): void
    {
        $id = $this->inquiry('2026-06-01 11:00:00 UTC', MailStatus::Failed);
        $this->connection->executeStatement("UPDATE inquiries SET stuck_alerted_at = '2026-06-01 11:40:00+00' WHERE id = ?", [$id]);

        self::assertSame([], $this->stuck());
    }

    public function test_a_row_older_than_the_window_is_left_out(): void
    {
        $this->inquiry('2026-05-25 11:59:00 UTC', MailStatus::Failed);
        $this->inquiry('2026-05-25 11:59:00 UTC');
        $inside = $this->inquiry('2026-05-25 12:00:00 UTC', MailStatus::Failed);

        self::assertSame([$inside], array_map(static fn (StuckInquiry $inquiry): string => $inquiry->id, $this->stuck()));
    }

    public function test_the_oldest_row_comes_first(): void
    {
        $newer = $this->inquiry('2026-06-01 11:30:00 UTC');
        $oldest = $this->inquiry('2026-06-01 09:00:00 UTC');
        $middle = $this->inquiry('2026-06-01 10:00:00 UTC');

        self::assertSame([$oldest, $middle, $newer], array_map(static fn (StuckInquiry $inquiry): string => $inquiry->id, $this->stuck()));
    }

    public function test_a_row_carries_its_venue_number_name_status_and_time(): void
    {
        $this->inquiry('2026-06-01 11:00:00 UTC');

        $row = $this->stuck()[0];
        $expected = $this->connection->fetchAssociative('SELECT venue_number, venue_name FROM inquiries');
        self::assertIsArray($expected);

        self::assertSame($expected['venue_number'], $row->venueNumber);
        self::assertSame($expected['venue_name'], $row->venueName);
        self::assertSame(MailStatus::Pending, $row->venueMailStatus);
        self::assertSame(
            (new \DateTimeImmutable('2026-06-01 11:00:00 UTC'))->getTimestamp(),
            $row->createdAt->getTimestamp(),
        );
    }

    public function test_the_list_is_bounded(): void
    {
        InquiryFactory::createMany(51, [
            'venue' => VenueFactory::new()->withContact()->create(),
            'createdAt' => new \DateTimeImmutable('2026-06-01 11:00:00 UTC'),
        ]);

        self::assertCount(50, $this->stuck());
    }

    /**
     * @return list<StuckInquiry>
     */
    private function stuck(): array
    {
        $this->em->flush();
        $query = self::getContainer()->get(StuckInquiries::class);
        self::assertInstanceOf(StuckInquiries::class, $query);

        $now = new \DateTimeImmutable(self::NOW);

        return $query->due($now->modify('-15 minutes'), $now->modify(\sprintf('-%d days', StuckInquiries::WINDOW_DAYS)));
    }

    private function inquiry(string $createdAt, MailStatus $status = MailStatus::Pending): string
    {
        $inquiry = InquiryFactory::createOne(['createdAt' => new \DateTimeImmutable($createdAt)]);
        $this->em->flush();

        if (MailStatus::Failed === $status) {
            $inquiry->recordVenueMailFailed(new \DateTimeImmutable($createdAt));
        }
        if (MailStatus::Sent === $status) {
            $inquiry->recordVenueMailSent('venue@example.ch', new \DateTimeImmutable($createdAt));
        }

        return $inquiry->id();
    }
}
