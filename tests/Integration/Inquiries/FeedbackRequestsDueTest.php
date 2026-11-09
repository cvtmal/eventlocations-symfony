<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFactory;
use App\Factory\InquiryFeedbackFactory;
use App\Inquiries\ReadModel\FeedbackRequest;
use App\Inquiries\ReadModel\FeedbackRequestsDue;
use App\Inquiries\ValueObject\InquiryOverride;
use App\Inquiries\ValueObject\QualificationReason;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\StoresDeliveredInquiries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * G16: which inquiries get the feedback mail. Delivered, counted qualified (never spam or test), the venue mail 7 to
 * 14 days ago, not handled before; grouped by address, case-insensitive.
 */
final class FeedbackRequestsDueTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use StoresDeliveredInquiries;

    private const string NOW = '2026-06-15 03:15:00 UTC';

    private Connection $connection;
    private FeedbackRequestsDue $due;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $due = self::getContainer()->get(FeedbackRequestsDue::class);
        self::assertInstanceOf(FeedbackRequestsDue::class, $due);
        $this->due = $due;
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_qualified_inquiry_is_due_from_seven_until_fourteen_days_after_its_venue_mail(): void
    {
        $sevenDays = $this->delivered('2026-06-08 03:15:00 UTC');
        $thirteenDays = $this->delivered('2026-06-01 04:00:00 UTC');
        $this->delivered('2026-06-08 03:16:00 UTC');
        $this->delivered('2026-06-01 03:15:00 UTC');

        self::assertEqualsCanonicalizing([$sevenDays->id(), $thirteenDays->id()], $this->dueIds());
    }

    public function test_spam_test_unqualified_undelivered_and_handled_inquiries_are_never_due(): void
    {
        $this->delivered('2026-06-05 10:00:00 UTC', mark: InquiryOverride::Spam);
        $this->delivered('2026-06-05 10:00:00 UTC', mark: InquiryOverride::Test);
        $this->delivered('2026-06-05 10:00:00 UTC', reason: QualificationReason::Duplicate);
        InquiryFactory::createOne(['createdAt' => new \DateTimeImmutable('2026-06-05 10:00:00 UTC')]);
        InquiryFeedbackFactory::createOne(['inquiry' => $this->delivered('2026-06-05 10:00:00 UTC'), 'at' => new \DateTimeImmutable('2026-06-12 03:15:00 UTC')]);

        self::assertSame([], $this->due->due(new \DateTimeImmutable(self::NOW)));
    }

    public function test_one_address_in_any_case_is_one_request_oldest_first(): void
    {
        $older = $this->delivered('2026-06-04 10:00:00 UTC', ['email' => 'Anna.Muster@example.ch']);
        $newer = $this->delivered('2026-06-06 10:00:00 UTC', ['email' => 'anna.muster@example.ch']);
        $other = $this->delivered('2026-06-05 10:00:00 UTC', ['email' => 'beat@example.ch']);

        $requests = $this->due->due(new \DateTimeImmutable(self::NOW));

        self::assertEquals([
            new FeedbackRequest([$older->id(), $newer->id()], false),
            new FeedbackRequest([$other->id()], false),
        ], $requests);
    }

    public function test_an_address_that_opted_out_on_any_inquiry_is_marked(): void
    {
        $earlier = $this->delivered('2026-05-01 10:00:00 UTC', ['email' => 'anna@example.ch']);
        InquiryFeedbackFactory::new()->optedOut()->create(['inquiry' => $earlier, 'at' => new \DateTimeImmutable('2026-05-08 03:15:00 UTC')]);
        $later = $this->delivered('2026-06-05 10:00:00 UTC', ['email' => 'ANNA@example.ch']);

        self::assertEquals([new FeedbackRequest([$later->id()], true)], $this->due->due(new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @return list<string>
     */
    private function dueIds(): array
    {
        return array_merge(...array_map(
            static fn (FeedbackRequest $request): array => $request->inquiryIds,
            $this->due->due(new \DateTimeImmutable(self::NOW)),
        ));
    }
}
