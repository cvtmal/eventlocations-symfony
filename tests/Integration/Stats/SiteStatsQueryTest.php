<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Stats\ReadModel\DailyPoint;
use App\Stats\ReadModel\MonthRow;
use App\Stats\ReadModel\PageTypeRow;
use App\Stats\ReadModel\SiteStatsQuery;
use App\Stats\ReadModel\SiteSummary;
use App\Stats\ReadModel\VenueRow;
use App\Stats\ValueObject\PageType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The site-wide read side of #47 over seeded `stats_daily` rows (contract C5).
 */
final class SiteStatsQueryTest extends KernelTestCase
{
    use Factories;

    private Connection $connection;
    private SiteStatsQuery $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM stats_daily');
        $this->connection->executeStatement('DELETE FROM stats_ingest_files');

        // Built by hand: the test container drops a private service no other service uses.
        $this->query = new SiteStatsQuery($connection);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_summary_sums_the_range_both_ends_included(): void
    {
        $a = VenueFactory::createOne()->number();
        $b = VenueFactory::createOne()->number();

        foreach (['2026-11-01', '2026-11-03'] as $day) {
            $this->row($day, 0, 'page_view', 10, 4);
            $this->row($day, 0, 'page_view_home', 6, 3);
            $this->row($day, $a, 'detail_view', 3, 2);
            $this->row($day, $a, 'click_website', 2, 1);
            $this->row($day, $b, 'click_phone', 1, 1);
            $this->row($day, $b, 'merkliste_add', 1, 1);
            $this->row($day, $b, 'inquiry', 1, 0);
        }
        // Outside the range on both sides.
        $this->row('2026-10-31', 0, 'page_view', 100, 100);
        $this->row('2026-11-04', $a, 'detail_view', 100, 100);

        self::assertEquals(
            new SiteSummary(visits: 8, pageViews: 20, detailViews: 6, contactClicks: 6, merklisteAdds: 2, inquiries: 2),
            $this->query->summary(self::day('2026-11-01'), self::day('2026-11-03')),
        );
    }

    public function test_summary_without_rows_is_all_zero(): void
    {
        self::assertEquals(new SiteSummary(0, 0, 0, 0, 0, 0), $this->query->summary(self::day('2026-11-01'), self::day('2026-11-03')));
    }

    public function test_daily_points_are_zero_filled_for_every_day(): void
    {
        $this->row('2026-11-02', 0, 'page_view', 7, 5);
        $this->row('2026-11-02', 0, 'page_view_home', 7, 5);

        self::assertEquals(
            [new DailyPoint(self::day('2026-11-01'), 0), new DailyPoint(self::day('2026-11-02'), 7), new DailyPoint(self::day('2026-11-03'), 0)],
            $this->query->dailyPageViews(self::day('2026-11-01'), self::day('2026-11-03')),
        );
        self::assertSame([0, 5, 0], array_map(
            static fn (DailyPoint $point): int => $point->value,
            $this->query->dailyVisits(self::day('2026-11-01'), self::day('2026-11-03')),
        ));
    }

    public function test_daily_points_span_the_dst_change(): void
    {
        $points = $this->query->dailyPageViews(self::day('2026-10-24'), self::day('2026-10-26'));

        self::assertSame(['2026-10-24', '2026-10-25', '2026-10-26'], array_map(static fn (DailyPoint $p): string => $p->day->format('Y-m-d'), $points));
    }

    public function test_page_types_list_every_type_most_views_first(): void
    {
        $this->row('2026-11-01', 0, 'page_view_listing', 5, 4);
        $this->row('2026-11-02', 0, 'page_view_listing', 5, 3);
        $this->row('2026-11-02', 0, 'page_view_detail', 8, 2);
        $this->row('2026-11-02', 0, 'page_view', 18, 6);

        self::assertEquals(
            [
                new PageTypeRow(PageType::Listing, 10, 7),
                new PageTypeRow(PageType::Detail, 8, 2),
                new PageTypeRow(PageType::Home, 0, 0),
                new PageTypeRow(PageType::Landing, 0, 0),
                new PageTypeRow(PageType::Merkliste, 0, 0),
                new PageTypeRow(PageType::Other, 0, 0),
            ],
            $this->query->pageTypes(self::day('2026-11-01'), self::day('2026-11-03')),
        );
    }

    public function test_venues_are_ordered_by_detail_views_then_number(): void
    {
        $a = VenueFactory::createOne(['name' => 'Alpha'])->number();
        $b = VenueFactory::createOne(['name' => 'Beta'])->number();
        $c = VenueFactory::createOne(['name' => 'Gamma'])->number();
        $d = VenueFactory::createOne(['name' => 'Ausserhalb'])->number();

        $this->row('2026-11-02', $a, 'detail_view', 2, 2);
        $this->row('2026-11-02', $a, 'impression', 9, 4);
        $this->row('2026-11-02', $a, 'click_email', 1, 1);
        $this->row('2026-11-02', $a, 'click_map', 2, 1);
        $this->row('2026-11-02', $b, 'detail_view', 5, 3);
        $this->row('2026-11-02', $c, 'detail_view', 2, 1);
        $this->row('2026-11-02', $c, 'merkliste_add', 1, 1);
        $this->row('2026-11-02', $c, 'inquiry', 3, 0);
        // A venue whose only row is outside the range, and a number no venue has, do not appear.
        $this->row('2026-10-01', $d, 'detail_view', 50, 50);
        $this->row('2026-11-02', 2000000000, 'detail_view', 50, 50);

        self::assertEquals(
            [
                new VenueRow($b, 'Beta', 5, 0, 0, 0, 0),
                new VenueRow($a, 'Alpha', 2, 9, 3, 0, 0),
                new VenueRow($c, 'Gamma', 2, 0, 0, 1, 3),
            ],
            $this->query->venues(self::day('2026-11-01'), self::day('2026-11-03')),
        );
    }

    public function test_months_cover_the_current_month_to_date_and_the_eleven_before(): void
    {
        $this->row('2025-11-30', 0, 'page_view', 1000, 1000); // before the first month
        $this->row('2025-12-01', 0, 'page_view', 3, 2);
        $this->row('2026-10-31', 0, 'page_view', 4, 3);
        $this->row('2026-11-01', 0, 'page_view', 5, 4);
        $this->row('2026-11-03', 0, 'page_view', 6, 5);
        $this->row('2026-11-04', 0, 'page_view', 1000, 1000); // after today

        $months = $this->query->months(self::day('2026-11-03'));

        self::assertCount(12, $months);
        self::assertSame('2025-12-01', $months[0]->month->format('Y-m-d'));
        self::assertSame('2026-11-01', $months[11]->month->format('Y-m-d'));
        self::assertSame(
            [3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 4, 11],
            array_map(static fn (MonthRow $month): int => $month->pageViews, $months),
        );
        self::assertEquals(new MonthRow(self::day('2026-11-01'), 9, 11, 0, 0, 0, 0), $months[11]);
    }

    public function test_last_ingest_at_is_the_latest_logged_file(): void
    {
        self::assertNull($this->query->lastIngestAt());

        $this->ingested('2026-11-02.aaaaaaaa.claimed', '2026-11-02T03:15:00+01:00');
        $this->ingested('2026-11-03.bbbbbbbb.claimed', '2026-11-03T09:30:00+01:00');

        $at = $this->query->lastIngestAt();
        self::assertNotNull($at);
        self::assertSame(1793694600, $at->getTimestamp());
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

    private function ingested(string $file, string $at): void
    {
        $this->connection->insert('stats_ingest_files', ['id' => EntityId::generate(), 'file_name' => $file, 'events' => 1, 'created_at' => $at]);
    }

    private static function day(string $day): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('Europe/Zurich'));
        self::assertNotFalse($date);

        return $date;
    }
}
