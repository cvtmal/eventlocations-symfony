<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Factory\VenueFactory;
use App\Stats\ReadModel\DailyPoint;
use App\Stats\ReadModel\VenueStatsQuery;
use App\Stats\ReadModel\VenueSummary;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * One venue's read side of #47 over seeded `stats_daily` rows (contract C5): another venue's rows
 * and the site rows never leak in.
 */
final class VenueStatsQueryTest extends KernelTestCase
{
    use Factories;

    private Connection $connection;
    private VenueStatsQuery $query;
    private int $venue;
    private int $other;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM stats_daily');

        // Built by hand: the test container drops a private service no other service uses.
        $this->query = new VenueStatsQuery($connection);

        $this->venue = VenueFactory::createOne()->number();
        $this->other = VenueFactory::createOne()->number();

        // Every venue figure on the other venue and on the site row (0), so a leak shows.
        foreach ([$this->other, 0] as $number) {
            foreach (['detail_view', 'impression', 'click_website', 'click_instagram', 'merkliste_add', 'inquiry'] as $metric) {
                $this->row('2026-11-02', $number, $metric, 100, 100);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_summary_counts_only_this_venue_in_the_range(): void
    {
        foreach (['2026-11-01', '2026-11-03'] as $day) {
            $this->row($day, $this->venue, 'detail_view', 4, 3);
            $this->row($day, $this->venue, 'impression', 20, 9);
            $this->row($day, $this->venue, 'click_website', 2, 2);
            $this->row($day, $this->venue, 'click_phone', 1, 1);
            $this->row($day, $this->venue, 'merkliste_add', 1, 1);
            $this->row($day, $this->venue, 'inquiry', 1, 0);
        }
        $this->row('2026-10-31', $this->venue, 'detail_view', 1000, 1000);
        $this->row('2026-11-04', $this->venue, 'click_map', 1000, 1000);

        self::assertEquals(
            new VenueSummary(
                detailViews: 8,
                detailVisitors: 6,
                impressions: 40,
                clicks: ['website' => 4, 'email' => 0, 'phone' => 2, 'map' => 0, 'instagram' => 0],
                clickTotal: 6,
                merklisteAdds: 2,
                inquiries: 2,
            ),
            $this->query->summary($this->venue, self::day('2026-11-01'), self::day('2026-11-03')),
        );
    }

    public function test_clicks_keep_the_click_kind_order(): void
    {
        $summary = $this->query->summary($this->venue, self::day('2026-11-01'), self::day('2026-11-03'));

        self::assertSame(['website', 'email', 'phone', 'map', 'instagram'], array_keys($summary->clicks));
        self::assertSame(0, $summary->clickTotal);
        self::assertSame(0, $summary->detailViews);
    }

    public function test_daily_detail_views_are_zero_filled_and_this_venues_only(): void
    {
        $this->row('2026-11-03', $this->venue, 'detail_view', 5, 4);
        $this->row('2026-11-03', $this->venue, 'impression', 50, 40);

        self::assertEquals(
            [new DailyPoint(self::day('2026-11-01'), 0), new DailyPoint(self::day('2026-11-02'), 0), new DailyPoint(self::day('2026-11-03'), 5)],
            $this->query->dailyDetailViews($this->venue, self::day('2026-11-01'), self::day('2026-11-03')),
        );
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
