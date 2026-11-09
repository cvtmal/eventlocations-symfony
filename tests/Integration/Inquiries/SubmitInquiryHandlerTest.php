<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\VenueFactory;
use App\Inquiries\Command\SubmitInquiry;
use App\Inquiries\CommandHandler\SubmitInquiryHandler;
use App\Inquiries\Exception\BudgetOutOfRange;
use App\Inquiries\Exception\EventDateInPast;
use App\Inquiries\Exception\EventDateMissing;
use App\Inquiries\Exception\GuestCountOutOfRange;
use App\Inquiries\Exception\InquiryNotAccepted;
use App\Inquiries\Exception\UnknownInquiryTerm;
use App\Inquiries\InquiryRules;
use App\Inquiries\ReadModel\InquiryVocabularyQuery;
use App\Inquiries\Repository\InquiryRepository;
use App\Inquiries\ValueObject\MailStatus;
use App\Inquiries\ValueObject\SubmittedInquiry;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\Repository\VenueRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

final class SubmitInquiryHandlerTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    /** 00:30 on 2 June in Zurich, still 1 June in UTC. */
    private const NOW = '2026-06-01 22:30:00 UTC';

    private Connection $connection;
    private EntityManagerInterface $em;
    private MockClock $clock;
    private SubmitInquiryHandler $handler;
    private InquiryRepository $inquiries;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();

        $venues = $container->get(VenueRepository::class);
        self::assertInstanceOf(VenueRepository::class, $venues);
        $inquiries = $container->get(InquiryRepository::class);
        self::assertInstanceOf(InquiryRepository::class, $inquiries);
        $this->inquiries = $inquiries;
        $this->clock = new MockClock(self::NOW);
        $vocabulary = $container->get(InquiryVocabularyQuery::class);
        self::assertInstanceOf(InquiryVocabularyQuery::class, $vocabulary);
        $this->handler = new SubmitInquiryHandler($venues, $inquiries, new InquiryRules($vocabulary), $this->clock, 'a secret');
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_stores_the_inquiry_with_a_snapshot_of_the_venue(): void
    {
        $venue = $this->venue();

        $id = ($this->handler)($this->command($venue->id(), eventDate: '2026-09-12', phone: '044 123 45 67', company: 'Muster AG', startTime: '18:30', cateringWish: 'external_allowed', budgetChf: 12000))->id;
        $this->em->flush();
        $this->em->clear();

        $inquiry = $this->inquiries->get($id);
        self::assertSame($venue->id(), $inquiry->venue()?->id());
        self::assertSame($venue->number(), $inquiry->venueNumber());
        self::assertSame($venue->name(), $inquiry->venueName());
        self::assertSame('schloss-seeblick', $inquiry->venueSlug());
        self::assertSame($venue->inquiryEmail(), $inquiry->recipientEmail());
        self::assertNull($inquiry->deliveredTo());
        self::assertSame('de', $inquiry->locale());
        self::assertSame('seminar', $inquiry->eventType());
        self::assertSame('Muster AG', $inquiry->company());
        self::assertSame(120, $inquiry->guestCount());
        self::assertSame('2026-09-12', $inquiry->eventDate()?->format('Y-m-d'));
        self::assertFalse($inquiry->isDateFlexible());
        self::assertSame('18:30', $inquiry->startTime()?->format('H:i'));
        self::assertSame('external_allowed', $inquiry->cateringWish());
        self::assertSame(12000, $inquiry->budgetChf());
        self::assertSame('Liebes Team', $inquiry->message());
        self::assertSame('Anna', $inquiry->firstName());
        self::assertSame('Muster', $inquiry->lastName());
        self::assertSame('anna@example.ch', $inquiry->email());
        self::assertSame('044 123 45 67', $inquiry->phone());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $inquiry->fingerprint());
        self::assertSame(MailStatus::Pending, $inquiry->venueMailStatus());
        self::assertSame(MailStatus::Pending, $inquiry->guestMailStatus());
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp(), $inquiry->createdAt()->getTimestamp());
    }

    public function test_an_open_date_and_no_phone_stay_null(): void
    {
        $id = ($this->handler)($this->command($this->venue()->id()))->id;
        $this->em->flush();
        $this->em->clear();

        $inquiry = $this->inquiries->get($id);
        self::assertNull($inquiry->eventDate());
        self::assertTrue($inquiry->isDateFlexible());
        self::assertNull($inquiry->phone());
        self::assertNull($inquiry->company());
        self::assertNull($inquiry->startTime());
        self::assertNull($inquiry->cateringWish());
        self::assertNull($inquiry->budgetChf());
    }

    public function test_a_flexible_date_in_the_past_is_still_refused(): void
    {
        $this->expectException(EventDateInPast::class);

        ($this->handler)($this->command($this->venue()->id(), eventDate: '2020-01-01', dateFlexible: true));
    }

    public function test_a_start_time_off_its_format_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ($this->handler)($this->command($this->venue()->id(), startTime: '25:00'));
    }

    public function test_neither_a_date_nor_flexible_is_refused(): void
    {
        $this->expectException(EventDateMissing::class);

        ($this->handler)($this->command($this->venue()->id(), dateFlexible: false));
    }

    public function test_an_event_type_outside_the_vocabulary_is_refused(): void
    {
        $this->expectException(UnknownInquiryTerm::class);

        ($this->handler)($this->command($this->venue()->id(), eventType: 'civil_wedding'));
    }

    public function test_a_catering_term_that_is_no_wish_is_refused(): void
    {
        $this->expectException(UnknownInquiryTerm::class);

        ($this->handler)($this->command($this->venue()->id(), cateringWish: 'buffet'));
    }

    public function test_a_budget_out_of_range_is_refused(): void
    {
        $this->expectException(BudgetOutOfRange::class);

        ($this->handler)($this->command($this->venue()->id(), budgetChf: 1_000_001));
    }

    public function test_an_unknown_venue_is_refused(): void
    {
        $this->expectException(InquiryNotAccepted::class);

        ($this->handler)($this->command('019a0000-0000-7000-8000-000000000999'));
    }

    public function test_an_unpublished_venue_is_refused(): void
    {
        $venue = $this->venue();
        $venue->unpublish();
        $this->em->flush();

        $this->expectException(InquiryNotAccepted::class);

        ($this->handler)($this->command($venue->id()));
    }

    public function test_a_venue_without_an_address_is_refused(): void
    {
        $venue = VenueFactory::createOne();

        $this->expectException(InquiryNotAccepted::class);

        ($this->handler)($this->command($venue->id()));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function guestCountsOutOfRange(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above the limit' => [5001];
    }

    #[DataProvider('guestCountsOutOfRange')]
    public function test_a_guest_count_out_of_range_is_refused(int $guests): void
    {
        $venue = $this->venue();

        $this->expectException(GuestCountOutOfRange::class);

        ($this->handler)($this->command($venue->id(), guests: $guests));
    }

    public function test_yesterday_in_zurich_is_refused_although_it_is_today_in_utc(): void
    {
        $venue = $this->venue();

        $this->expectException(EventDateInPast::class);

        ($this->handler)($this->command($venue->id(), eventDate: '2026-06-01'));
    }

    public function test_today_in_zurich_is_accepted(): void
    {
        $submitted = ($this->handler)($this->command($this->venue()->id(), eventDate: '2026-06-02'));

        self::assertNotSame('', $submitted->id);
    }

    public function test_the_same_inquiry_again_finds_the_row_and_stores_nothing(): void
    {
        $venueId = $this->venue()->id();

        $first = $this->submit($this->command($venueId));
        $this->clock->sleep(9 * 60);
        $repeat = $this->submit($this->command($venueId));

        self::assertFalse($first->isRepeat);
        self::assertTrue($repeat->isRepeat);
        self::assertSame($first->id, $repeat->id);
        self::assertSame(1, $this->rowCount());
    }

    public function test_a_repeat_finds_a_row_whatever_its_mail_status(): void
    {
        $venueId = $this->venue()->id();
        $first = $this->submit($this->command($venueId));
        $this->connection->executeStatement("UPDATE inquiries SET venue_mail_status = 'failed', guest_mail_status = 'skipped'");

        self::assertSame($first->id, $this->submit($this->command($venueId))->id);
    }

    public function test_the_same_inquiry_after_the_window_is_a_new_one(): void
    {
        $venueId = $this->venue()->id();

        $first = $this->submit($this->command($venueId));
        $this->clock->sleep(10 * 60);
        $later = $this->submit($this->command($venueId));

        self::assertFalse($later->isRepeat);
        self::assertNotSame($first->id, $later->id);
        self::assertSame(2, $this->rowCount());
    }

    public function test_a_repeat_after_a_new_row_finds_the_newest(): void
    {
        $venueId = $this->venue()->id();
        $this->submit($this->command($venueId));
        $this->clock->sleep(10 * 60);
        $newest = $this->submit($this->command($venueId));
        $this->clock->sleep(60);

        self::assertSame($newest->id, $this->submit($this->command($venueId))->id);
    }

    public function test_the_guests_other_inquiries_are_not_repeats(): void
    {
        $venueId = $this->venue()->id();

        $this->submit($this->command($venueId));
        $otherDate = $this->submit($this->command($venueId, eventDate: '2026-09-12'));
        $otherVenue = $this->submit($this->command($this->venue()->id()));

        self::assertFalse($otherDate->isRepeat);
        self::assertFalse($otherVenue->isRepeat);
        self::assertSame(3, $this->rowCount());
    }

    private function submit(SubmitInquiry $command): SubmittedInquiry
    {
        $submitted = ($this->handler)($command);
        $this->em->flush();

        return $submitted;
    }

    private function rowCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM inquiries');
    }

    private function venue(): Venue
    {
        return VenueFactory::new()->withContact()->create();
    }

    private function command(
        string $venueId,
        int $guests = 120,
        ?string $eventDate = null,
        ?string $phone = null,
        string $eventType = 'seminar',
        ?string $company = null,
        ?bool $dateFlexible = null,
        ?string $startTime = null,
        ?string $cateringWish = null,
        ?int $budgetChf = null,
    ): SubmitInquiry {
        return new SubmitInquiry(
            $venueId,
            'schloss-seeblick',
            'de',
            $eventType,
            $company,
            $guests,
            $eventDate,
            $dateFlexible ?? null === $eventDate,
            $startTime,
            $cateringWish,
            $budgetChf,
            'Liebes Team',
            'Anna',
            'Muster',
            'anna@example.ch',
            $phone,
        );
    }
}
