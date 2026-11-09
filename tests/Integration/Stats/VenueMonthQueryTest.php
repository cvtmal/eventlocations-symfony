<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Factory\InquiryFactory;
use App\Factory\InquiryFeedbackFactory;
use App\Factory\RecordedFunnelEventFactory;
use App\Factory\VenueFactory;
use App\Inquiries\Command\DeliverInquiry;
use App\Inquiries\Command\SubmitInquiryBrief;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\ReadModel\InquiryFeedbackCounts;
use App\Inquiries\ValueObject\FeedbackOutcome;
use App\Inquiries\ValueObject\InquiryOverride;
use App\Inquiries\ValueObject\QualificationReason;
use App\Inquiries\ValueObject\SubmittedBrief;
use App\Shared\Funnel\FunnelEvent;
use App\Stats\ReadModel\VenueMonthQuery;
use App\Stats\ReadModel\VenueStatsQuery;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * One venue's months for the G10 report: `stats_daily` summed per Zurich month, delivered inquiries from
 * `funnel_events` by Zurich month, qualified inquiries (G7) by the Zurich month of the venue mail, G16's feedback
 * answers by the month of the answer, newest first, zero-filled; another venue never leaks in.
 */
final class VenueMonthQueryTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private Connection $connection;
    private VenueMonthQuery $query;
    private Venue $venue;
    private Venue $other;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM stats_daily');
        $this->connection->executeStatement('DELETE FROM funnel_events');

        // Built by hand: the test container drops a private service no other service uses.
        $this->query = new VenueMonthQuery(new VenueStatsQuery($connection), new InquiryFeedbackCounts($connection), $connection);

        $this->venue = VenueFactory::new()->withContact()->create();
        $this->other = VenueFactory::new()->withContact()->create();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_months_are_summed_per_zurich_month_newest_first(): void
    {
        $this->row('2026-11-01', $this->venue->number(), 'detail_view', 9, 9);
        $this->row('2026-10-01', $this->venue->number(), 'detail_view', 5, 4);
        $this->row('2026-10-31', $this->venue->number(), 'detail_view', 3, 2);
        $this->row('2026-10-15', $this->venue->number(), 'click_phone', 2, 2);
        $this->row('2026-10-15', $this->venue->number(), 'merkliste_add', 1, 1);
        $this->row('2026-08-20', $this->venue->number(), 'detail_view', 7, 7);
        $this->row('2026-10-15', $this->other->number(), 'detail_view', 100, 100);

        // 23:30 UTC on 31 October is 00:30 on 1 November in Zurich: November.
        $this->delivered($this->venue, '2026-10-31 23:30:00 UTC');
        $this->delivered($this->venue, '2026-10-31 22:30:00 UTC');
        $this->delivered($this->venue, '2026-10-01 00:30:00 Europe/Zurich');
        $this->delivered($this->other, '2026-10-10 12:00:00 UTC');

        $months = $this->query->months($this->venue->id(), $this->venue->number(), self::day('2026-10-01'), 3);

        self::assertSame(['2026-10-01', '2026-09-01', '2026-08-01'], array_map(static fn ($m) => $m->month->format('Y-m-d'), $months));
        self::assertSame(6, $months[0]->summary->detailVisitors);
        self::assertSame(8, $months[0]->summary->detailViews);
        self::assertSame(2, $months[0]->summary->clickTotal);
        self::assertSame(1, $months[0]->summary->merklisteAdds);
        self::assertSame(2, $months[0]->deliveredInquiries);
        self::assertSame(0, $months[0]->qualifiedInquiries);
        self::assertSame(0, $months[0]->feedbackAnswers);

        self::assertSame(0, $months[1]->summary->detailVisitors);
        self::assertSame(0, $months[1]->deliveredInquiries);

        self::assertSame(7, $months[2]->summary->detailVisitors);
        self::assertSame(0, $months[2]->deliveredInquiries);
    }

    /**
     * Only `QualifiedInquirySql::where()` decides: an unqualified, a spam, a test and a never qualified inquiry are left
     * out, and the venue mail's Zurich month places the row.
     */
    public function test_qualified_inquiries_are_counted_by_the_zurich_month_of_the_venue_mail(): void
    {
        $this->inquiry($this->venue, '2026-10-03 12:00:00 UTC');
        $this->inquiry($this->venue, '2026-09-30 22:30:00 UTC'); // 00:30 on 1 October in Zurich
        $this->inquiry($this->venue, '2026-09-30 21:30:00 UTC');
        $this->inquiry($this->venue, '2026-10-31 23:30:00 UTC'); // 00:30 on 1 November in Zurich
        $this->inquiry($this->venue, '2026-10-04 12:00:00 UTC', reason: QualificationReason::GuestsOutsideCapacity);
        $this->inquiry($this->venue, '2026-10-05 12:00:00 UTC', mark: InquiryOverride::Spam);
        $this->inquiry($this->venue, '2026-10-06 12:00:00 UTC', mark: InquiryOverride::Test);
        InquiryFactory::createOne(['venue' => $this->venue, 'createdAt' => new \DateTimeImmutable('2026-10-07 12:00:00 UTC')]);
        $this->inquiry($this->other, '2026-10-03 12:00:00 UTC');

        $months = $this->query->months($this->venue->id(), $this->venue->number(), self::day('2026-10-01'), 2);

        self::assertSame([2, 1], [$months[0]->qualifiedInquiries, $months[1]->qualifiedInquiries]);
    }

    /**
     * G16's count, read through `InquiryFeedbackCounts`: the month of the answer, not of the inquiry; an unanswered
     * mail and a spam-marked inquiry's answer count nothing.
     */
    public function test_feedback_answers_are_counted_by_the_month_of_the_answer(): void
    {
        $this->inquiry($this->venue, '2026-09-25 12:00:00 UTC', answeredAt: '2026-10-02 08:00:00 UTC');
        $this->inquiry($this->venue, '2026-10-03 12:00:00 UTC', answeredAt: '2026-10-12 08:00:00 UTC');
        $this->inquiry($this->venue, '2026-09-03 12:00:00 UTC', answeredAt: '2026-09-12 08:00:00 UTC');
        $this->inquiry($this->venue, '2026-10-20 12:00:00 UTC', answeredAt: '2026-10-31 23:30:00 UTC'); // November in Zurich
        $this->inquiry($this->venue, '2026-10-05 12:00:00 UTC', mark: InquiryOverride::Spam, answeredAt: '2026-10-14 08:00:00 UTC');
        $mailed = $this->inquiry($this->venue, '2026-10-06 12:00:00 UTC');
        InquiryFeedbackFactory::createOne(['inquiry' => $mailed, 'at' => new \DateTimeImmutable('2026-10-13 03:15:00 UTC')]);
        $this->inquiry($this->other, '2026-10-03 12:00:00 UTC', answeredAt: '2026-10-12 08:00:00 UTC');

        $months = $this->query->months($this->venue->id(), $this->venue->number(), self::day('2026-10-01'), 2);

        self::assertSame([2, 1], [$months[0]->feedbackAnswers, $months[1]->feedbackAnswers]);
    }

    public function test_the_month_after_counts_the_late_utc_row(): void
    {
        $this->delivered($this->venue, '2026-10-31 23:30:00 UTC');

        $months = $this->query->months($this->venue->id(), $this->venue->number(), self::day('2026-11-01'), 1);

        self::assertCount(1, $months);
        self::assertSame(1, $months[0]->deliveredInquiries);
    }

    /**
     * F13: a brief to two venues is one `brief_sent` row without a venue and one `inquiry_delivered` per child,
     * written by the real submit and deliveries; each venue counts its own child once, delivered and qualified, the
     * brief row for none.
     */
    public function test_a_brief_to_two_venues_counts_one_delivery_for_each_venue(): void
    {
        self::mockTime('2026-10-15 12:00:00 UTC');
        $first = VenueFactory::new()->withContact()->create(['status' => VenueStatus::Published]);
        $second = VenueFactory::new()->withContact()->create(['status' => VenueStatus::Published]);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $brief = $bus->dispatch(new SubmitInquiryBrief(
            [$first->id() => 'slug-'.$first->number(), $second->id() => 'slug-'.$second->number()],
            'de',
            'corporate_event',
            null,
            80,
            null,
            true,
            null,
            null,
            null,
            'Liebes Team',
            'Anna',
            'Muster',
            'anna@example.ch',
            null,
        ))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(SubmittedBrief::class, $brief);
        foreach ($brief->inquiryIds as $inquiryId) {
            $bus->dispatch(new DeliverInquiry($inquiryId));
        }

        self::assertSame(
            ['brief_sent' => 1, 'inquiry_delivered' => 2],
            array_map('intval', $this->connection->fetchAllKeyValue('SELECT event, count(*) FROM funnel_events GROUP BY event ORDER BY event')),
        );
        foreach ([$first, $second] as $venue) {
            $months = $this->query->months($venue->id(), $venue->number(), self::day('2026-10-01'), 1);
            self::assertSame(1, $months[0]->deliveredInquiries, 'one delivery for '.$venue->name());
            self::assertSame(1, $months[0]->qualifiedInquiries, 'one qualified inquiry for '.$venue->name());
        }
        $untouched = $this->query->months($this->venue->id(), $this->venue->number(), self::day('2026-10-01'), 1)[0];
        self::assertSame([0, 0], [$untouched->deliveredInquiries, $untouched->qualifiedInquiries]);
    }

    /**
     * A delivered inquiry, qualified unless `reason` or `mark` say otherwise, answered when `answeredAt` is set.
     */
    private function inquiry(Venue $venue, string $sentAt, ?QualificationReason $reason = null, ?InquiryOverride $mark = null, ?string $answeredAt = null): Inquiry
    {
        $at = new \DateTimeImmutable($sentAt);
        $inquiry = InquiryFactory::new()
            ->afterInstantiate(static function (Inquiry $inquiry) use ($at, $reason, $mark): void {
                $inquiry->recordVenueMailSent('team@example.ch', $at);
                $inquiry->recordQualification($reason, $at);
                $inquiry->mark($mark, $at);
            })
            ->create(['venue' => $venue, 'createdAt' => $at]);
        if (null !== $answeredAt) {
            InquiryFeedbackFactory::new()
                ->answered(false, FeedbackOutcome::No, new \DateTimeImmutable($answeredAt))
                ->create(['inquiry' => $inquiry, 'at' => $at->modify('+7 days')]);
        }

        return $inquiry;
    }

    private function delivered(Venue $venue, string $at): void
    {
        RecordedFunnelEventFactory::createOne([
            'event' => FunnelEvent::InquiryDelivered,
            'venueId' => $venue->id(),
            'occurredAt' => new \DateTimeImmutable($at),
        ]);
    }

    private function row(string $day, int $venue, string $metric, int $events, int $visitors): void
    {
        $this->connection->insert('stats_daily', [
            'day' => $day,
            'venue_number' => $venue,
            'metric' => $metric,
            'events' => $events,
            'visitors' => $visitors,
        ]);
    }

    private static function day(string $day): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('Europe/Zurich'));
        self::assertNotFalse($date);

        return $date;
    }
}
