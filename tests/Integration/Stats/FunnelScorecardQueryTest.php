<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Factory\InquiryFactory;
use App\Factory\RecordedFunnelEventFactory;
use App\Factory\VenueFactory;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Uid\EntityId;
use App\Stats\ReadModel\FunnelCampaignRow;
use App\Stats\ReadModel\FunnelScorecard;
use App\Stats\ReadModel\FunnelScorecardQuery;
use App\Stats\ReadModel\FunnelWeek;
use App\Stats\ValueObject\FunnelFilter;
use App\Stats\ValueObject\FunnelWindow;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The funnel scorecard's read model (G8) against app_test: what a week, a campaign and the window count.
 * "Now" is Wednesday 2026-11-04 in Zurich, so the 4-week window starts on Monday 2026-10-12.
 */
final class FunnelScorecardQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase {
        setUp as private beginTransaction;
    }

    private const string ZONE = 'Europe/Zurich';

    protected function setUp(): void
    {
        $this->beginTransaction();
        $this->connection->executeStatement('DELETE FROM funnel_events');
        $this->connection->executeStatement('DELETE FROM inquiries');
    }

    public function test_an_empty_log_gives_every_week_of_the_window_as_zeros(): void
    {
        $card = $this->scorecard();

        self::assertSame(['2026-11-02', '2026-10-26', '2026-10-19', '2026-10-12'], array_map(static fn (FunnelWeek $week): string => $week->start->format('Y-m-d'), $card->weeks));
        self::assertSame('Europe/Zurich', $card->weeks[0]->start->getTimezone()->getName());
        self::assertSame(0, $card->totals->total());
        self::assertSame([], $card->campaigns);
        self::assertSame(0, $card->paying->paying);
        self::assertNull($card->paying->deliveredPercent());
        self::assertSame(0, $card->paying->withQualifiedInquiry);
        self::assertNull($card->paying->qualifiedPercent());
        self::assertSame(0, $card->qualifiedInquiries);
    }

    public function test_the_window_sets_the_number_of_weeks(): void
    {
        foreach ([FunnelWindow::Weeks4, FunnelWindow::Weeks12, FunnelWindow::Weeks26, FunnelWindow::Weeks52] as $window) {
            $card = $this->scorecard($window);

            self::assertCount($window->value, $card->weeks);
            self::assertSame('2026-11-02', $card->weeks[0]->start->format('Y-m-d'));
        }
    }

    public function test_a_venue_step_counts_distinct_venues_and_an_inquiry_counts_rows(): void
    {
        $a = EntityId::generate();
        $b = EntityId::generate();
        $this->event(FunnelEvent::PreviewOpened, '2026-11-02 09:00', $a);
        $this->event(FunnelEvent::PreviewOpened, '2026-11-03 10:00', $a);
        $this->event(FunnelEvent::PreviewOpened, '2026-11-04 08:00', $a);
        $this->event(FunnelEvent::PreviewOpened, '2026-11-04 09:00', $b);
        $this->event(FunnelEvent::InquiryDelivered, '2026-11-03 11:00', $a);
        $this->event(FunnelEvent::InquiryDelivered, '2026-11-03 12:00', $a);
        $this->event(FunnelEvent::InquiryDelivered, '2026-11-03 13:00', null);
        $this->event(FunnelEvent::BriefSent, '2026-11-03 14:00', null);
        $this->event(FunnelEvent::BriefSent, '2026-11-03 15:00', null);

        $card = $this->scorecard();

        self::assertSame(2, $card->weeks[0]->counts->of(FunnelEvent::PreviewOpened));
        self::assertSame(3, $card->weeks[0]->counts->of(FunnelEvent::InquiryDelivered));
        self::assertSame(2, $card->weeks[0]->counts->of(FunnelEvent::BriefSent));
        self::assertSame(0, $card->weeks[0]->counts->of(FunnelEvent::ProPaid));
        self::assertSame(2, $card->totals->of(FunnelEvent::PreviewOpened));
    }

    public function test_a_venue_in_two_weeks_counts_once_per_week_and_once_in_the_window(): void
    {
        $venue = EntityId::generate();
        $this->event(FunnelEvent::PreviewOpened, '2026-10-27 09:00', $venue);
        $this->event(FunnelEvent::PreviewOpened, '2026-11-03 09:00', $venue);

        $card = $this->scorecard();

        self::assertSame(1, $card->weeks[0]->counts->of(FunnelEvent::PreviewOpened));
        self::assertSame(1, $card->weeks[1]->counts->of(FunnelEvent::PreviewOpened));
        self::assertSame(1, $card->totals->of(FunnelEvent::PreviewOpened));
    }

    public function test_a_venue_step_without_a_venue_counts_each_row(): void
    {
        $this->event(FunnelEvent::ClaimStarted, '2026-11-03 09:00', null);
        $this->event(FunnelEvent::ClaimStarted, '2026-11-03 10:00', null);

        self::assertSame(2, $this->scorecard()->totals->of(FunnelEvent::ClaimStarted));
    }

    public function test_weeks_run_from_monday_to_monday_in_zurich_time(): void
    {
        // 23:30 on Sunday in Zurich is 22:30 UTC the same day; 00:10 on Monday is 23:10 UTC on Sunday.
        $this->event(FunnelEvent::VenuePublished, '2026-11-01 23:30', EntityId::generate());
        $this->event(FunnelEvent::VenuePublished, '2026-11-02 00:10', EntityId::generate());

        $card = $this->scorecard();

        self::assertSame(1, $card->weeks[0]->counts->of(FunnelEvent::VenuePublished), 'Monday 00:10 is the new week');
        self::assertSame(1, $card->weeks[1]->counts->of(FunnelEvent::VenuePublished), 'Sunday 23:30 is the old one');
    }

    public function test_events_outside_the_window_are_left_out(): void
    {
        $this->event(FunnelEvent::VenuePublished, '2026-10-11 23:59', EntityId::generate());
        $this->event(FunnelEvent::VenuePublished, '2026-11-09 00:00', EntityId::generate());

        self::assertSame(0, $this->scorecard()->totals->total());
        self::assertSame(1, $this->scorecard(FunnelWindow::Weeks12)->totals->total(), 'the first one is inside 12 weeks, the second is in the future');
    }

    public function test_an_event_takes_the_first_campaign_of_its_venue(): void
    {
        $one = EntityId::generate();
        $two = EntityId::generate();
        $three = EntityId::generate();
        $this->event(FunnelEvent::PreviewOpened, '2026-10-13 09:00', $one, 'zh-brief');
        $this->event(FunnelEvent::ClaimStarted, '2026-10-20 09:00', $one);
        $this->event(FunnelEvent::VenuePublished, '2026-11-03 09:00', $one);
        $this->event(FunnelEvent::InquiryDelivered, '2026-11-03 10:00', $one);
        $this->event(FunnelEvent::PreviewOpened, '2026-10-14 09:00', $two, 'ag-mail');
        // Its own code wins for this event; the venue's first campaign stays ag-mail.
        $this->event(FunnelEvent::PreviewOpened, '2026-10-21 09:00', $two, 'zh-brief');
        $this->event(FunnelEvent::VenuePublished, '2026-11-03 11:00', $three);
        $this->event(FunnelEvent::InquiryDelivered, '2026-11-03 12:00', null);

        $rows = $this->scorecard()->campaigns;

        self::assertSame(['ag-mail', 'zh-brief', null], array_map(static fn (FunnelCampaignRow $row): ?string => $row->campaign, $rows));
        [$ag, $zh, $none] = $rows;
        self::assertSame(1, $ag->counts->of(FunnelEvent::PreviewOpened));
        self::assertSame(2, $zh->counts->of(FunnelEvent::PreviewOpened));
        self::assertSame(1, $zh->counts->of(FunnelEvent::ClaimStarted));
        self::assertSame(1, $zh->counts->of(FunnelEvent::VenuePublished));
        self::assertSame(1, $zh->counts->of(FunnelEvent::InquiryDelivered));
        self::assertSame(1, $none->counts->of(FunnelEvent::VenuePublished));
        self::assertSame(1, $none->counts->of(FunnelEvent::InquiryDelivered));
    }

    public function test_what_a_venue_did_before_its_first_campaign_touch_has_no_campaign(): void
    {
        $venue = EntityId::generate();
        $this->event(FunnelEvent::InquiryDelivered, '2026-10-13 09:00', $venue);
        $this->event(FunnelEvent::PreviewOpened, '2026-10-20 09:00', $venue, 'zh-brief');
        $this->event(FunnelEvent::InquiryDelivered, '2026-10-27 09:00', $venue);

        [$campaign, $none] = $this->scorecard()->campaigns;

        self::assertSame('zh-brief', $campaign->campaign);
        self::assertSame(1, $campaign->counts->of(FunnelEvent::PreviewOpened));
        self::assertSame(1, $campaign->counts->of(FunnelEvent::InquiryDelivered), 'after the touch');
        self::assertNull($none->campaign);
        self::assertSame(1, $none->counts->of(FunnelEvent::InquiryDelivered), 'before the touch');
    }

    public function test_the_filter_narrows_weeks_and_totals_but_not_the_campaign_list(): void
    {
        $one = EntityId::generate();
        $two = EntityId::generate();
        $this->event(FunnelEvent::PreviewOpened, '2026-10-13 09:00', $one, 'zh-brief');
        $this->event(FunnelEvent::VenuePublished, '2026-11-03 09:00', $one);
        $this->event(FunnelEvent::PreviewOpened, '2026-10-14 09:00', $two, 'ag-mail');
        $this->event(FunnelEvent::VenuePublished, '2026-11-03 10:00', EntityId::generate());

        $all = $this->scorecard();
        $zurich = $this->scorecard(filter: FunnelFilter::campaign('zh-brief'));
        $without = $this->scorecard(filter: FunnelFilter::withoutCampaign());
        $unknown = $this->scorecard(filter: FunnelFilter::campaign('unbekannt'));

        self::assertSame(2, $all->totals->of(FunnelEvent::PreviewOpened));
        self::assertSame(2, $all->totals->of(FunnelEvent::VenuePublished));
        self::assertSame(1, $zurich->totals->of(FunnelEvent::PreviewOpened));
        self::assertSame(1, $zurich->totals->of(FunnelEvent::VenuePublished));
        self::assertSame(1, $zurich->weeks[0]->counts->of(FunnelEvent::VenuePublished));
        self::assertSame(0, $without->totals->of(FunnelEvent::PreviewOpened));
        self::assertSame(1, $without->totals->of(FunnelEvent::VenuePublished));
        self::assertSame(0, $unknown->totals->total());
        self::assertCount(3, $zurich->campaigns);
        self::assertCount(3, $unknown->campaigns);
    }

    public function test_paying_venues_with_a_delivered_inquiry_since_their_first_payment(): void
    {
        [$paidAndServed, $paidBeforeTheInquiry, $lapsed, $paidWithoutInquiry] = [EntityId::generate(), EntityId::generate(), EntityId::generate(), EntityId::generate()];
        $this->event(FunnelEvent::ProPaid, '2026-10-20 09:00', $paidAndServed);
        $this->event(FunnelEvent::InquiryDelivered, '2026-10-28 09:00', $paidAndServed);
        $this->event(FunnelEvent::ProPaid, '2026-10-25 09:00', $paidBeforeTheInquiry);
        $this->event(FunnelEvent::InquiryDelivered, '2026-10-01 09:00', $paidBeforeTheInquiry);
        $this->event(FunnelEvent::ProPaid, '2025-10-01 09:00', $lapsed);
        $this->event(FunnelEvent::InquiryDelivered, '2026-10-28 10:00', $lapsed);
        $this->event(FunnelEvent::ProPaid, '2026-10-15 09:00', $paidWithoutInquiry);

        $paying = $this->scorecard()->paying;

        self::assertSame(3, $paying->paying);
        self::assertSame(1, $paying->withDeliveredInquiry);
        self::assertSame(33, $paying->deliveredPercent());
        self::assertSame(0, $paying->withQualifiedInquiry);
    }

    public function test_the_qualified_tile_counts_counted_qualified_inquiries_by_their_venue_mail_week(): void
    {
        $venue = VenueFactory::new()->withContact()->create()->id();
        $this->inquiry($venue, '2026-11-03 10:00');
        $this->inquiry($venue, '2026-10-12 00:00');
        $this->inquiry($venue, '2026-11-03 11:00', override: 'spam');
        $this->inquiry($venue, '2026-11-03 12:00', override: 'test');
        $this->inquiry($venue, '2026-11-03 13:00', qualified: false);
        $this->inquiry($venue, '2026-10-11 23:59');
        $this->inquiry($venue, '2026-11-09 00:00');

        self::assertSame(2, $this->scorecard()->qualifiedInquiries);
    }

    public function test_the_qualified_tile_ignores_the_campaign_filter(): void
    {
        $this->inquiry(VenueFactory::new()->withContact()->create()->id(), '2026-11-03 10:00');

        self::assertSame(1, $this->scorecard(filter: FunnelFilter::campaign('zh-brief'))->qualifiedInquiries);
        self::assertSame(1, $this->scorecard(filter: FunnelFilter::withoutCampaign())->qualifiedInquiries);
    }

    public function test_paying_venues_with_a_qualified_inquiry_since_their_first_payment(): void
    {
        [$served, $onlyMarked, $onlyUnqualified, $servedBefore] = array_map(
            static fn (): string => VenueFactory::new()->withContact()->create()->id(),
            range(1, 4),
        );
        foreach ([$served, $onlyMarked, $onlyUnqualified, $servedBefore] as $venue) {
            $this->event(FunnelEvent::ProPaid, '2026-10-20 09:00', $venue);
        }
        $this->inquiry($served, '2026-10-28 09:00');
        $this->inquiry($onlyMarked, '2026-10-28 09:00', override: 'spam');
        $this->inquiry($onlyUnqualified, '2026-10-28 09:00', qualified: false);
        $this->inquiry($servedBefore, '2026-10-19 09:00');

        $paying = $this->scorecard()->paying;

        self::assertSame(4, $paying->paying);
        self::assertSame(1, $paying->withQualifiedInquiry);
        self::assertSame(25, $paying->qualifiedPercent());
    }

    public function test_the_screen_asks_the_database_five_times(): void
    {
        $this->event(FunnelEvent::PreviewOpened, '2026-11-03 09:00', EntityId::generate(), 'zh-brief');
        $before = $this->statements();

        $this->scorecard(FunnelWindow::Weeks52, FunnelFilter::campaign('zh-brief'));

        self::assertSame($before + 5, $this->statements());
    }

    private function scorecard(FunnelWindow $window = FunnelWindow::Weeks4, ?FunnelFilter $filter = null): FunnelScorecard
    {
        $query = self::getContainer()->get(FunnelScorecardQuery::class);
        self::assertInstanceOf(FunnelScorecardQuery::class, $query);

        return $query->scorecard($window, $filter ?? FunnelFilter::all(), new \DateTimeImmutable('2026-11-04 12:00:00', new \DateTimeZone(self::ZONE)));
    }

    private function event(FunnelEvent $event, string $at, ?string $venue, ?string $campaign = null): void
    {
        RecordedFunnelEventFactory::createOne([
            'event' => $event,
            'occurredAt' => new \DateTimeImmutable($at, new \DateTimeZone(self::ZONE)),
            'venueId' => $venue,
            'campaignCode' => $campaign,
        ]);
    }

    /**
     * A delivered inquiry whose venue mail went out at `$sentAt` (Zurich), qualified unless told otherwise.
     */
    private function inquiry(string $venue, string $sentAt, bool $qualified = true, ?string $override = null): void
    {
        $id = InquiryFactory::createOne(['venue' => VenueFactory::find($venue)])->id();
        $this->connection->executeStatement(
            "UPDATE inquiries SET venue_mail_status = 'sent', venue_mail_sent_at = ?, qualification = ?, qualification_reason = ?, qualification_override = ? WHERE id = ?",
            [
                new \DateTimeImmutable($sentAt, new \DateTimeZone(self::ZONE))->format(\DATE_ATOM),
                $qualified ? 'qualified' : 'unqualified',
                $qualified ? null : 'duplicate',
                $override,
                $id,
            ],
        );
    }

    private function statements(): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return \count($holder->getData()['default'] ?? []);
    }
}
