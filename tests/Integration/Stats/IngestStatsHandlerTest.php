<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Stats\Command\IngestStats;
use App\Stats\Spool;
use App\Stats\SpoolReader;
use App\Stats\StatsPaths;
use App\Stats\ValueObject\ClickKind;
use App\Stats\ValueObject\EventType;
use App\Stats\ValueObject\IngestResult;
use App\Stats\ValueObject\PageType;
use App\Stats\ValueObject\SpoolEvent;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * The ingest of #47 end to end: spool files written by `Spool`, loaded once into `stats_events`, rolled up
 * into `stats_daily` by C5, and pruned after the commit. Idempotent and crash-safe.
 */
final class IngestStatsHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const string H1 = '11111111111111111111111111111111';
    private const string H2 = '22222222222222222222222222222222';
    private const string H3 = '33333333333333333333333333333333';
    private const int UNKNOWN = 2000000000;

    private Connection $connection;
    private EntityManagerInterface $em;
    private StatsPaths $paths;
    private Spool $spool;
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        self::mockTime('2026-11-03T14:00:00+01:00');

        $directory = self::getContainer()->getParameter('stats.dir');
        self::assertIsString($directory);
        $this->paths = new StatsPaths($directory);
        $this->spool = new Spool($this->paths);
        $this->clean();

        $this->a = VenueFactory::createOne()->number();
        $this->b = VenueFactory::createOne()->number();
    }

    protected function tearDown(): void
    {
        $this->clean();
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_events_reach_stats_events_with_every_column(): void
    {
        $this->spool->append([
            new SpoolEvent(self::at('2026-11-03T10:15:30'), EventType::Click, ClickKind::Website, [$this->a], PageType::Detail, '/hochzeitslocation/a', 'www.google.com', 'Mozilla/5.0', self::H1, true),
            new SpoolEvent(self::at('2026-11-03T10:16:00'), EventType::PageView, null, null, PageType::Home, '/', null, null, null, false),
        ]);
        file_put_contents($this->paths->spoolDir().'/2026-11-03.ndjson', "{\"broken\n", \FILE_APPEND);

        self::assertEquals(new IngestResult(1, 2, 1, 1), $this->ingest());

        self::assertSame([
            [
                'occurred_at' => '2026-11-03 10:15:30',
                'type' => 'click',
                'kind' => 'website',
                'page' => 'detail',
                'venues' => '['.$this->a.']',
                'path' => '/hochzeitslocation/a',
                'referrer_host' => 'www.google.com',
                'user_agent' => 'Mozilla/5.0',
                'visitor_hash' => self::H1,
                'automated' => true,
            ],
            [
                'occurred_at' => '2026-11-03 10:16:00',
                'type' => 'page_view',
                'kind' => null,
                'page' => 'home',
                'venues' => null,
                'path' => '/',
                'referrer_host' => null,
                'user_agent' => null,
                'visitor_hash' => null,
                'automated' => false,
            ],
        ], $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT to_char(occurred_at AT TIME ZONE 'Europe/Zurich', 'YYYY-MM-DD HH24:MI:SS') AS occurred_at,
                   type, kind, page, venues::text AS venues, path, referrer_host, user_agent, visitor_hash, automated
            FROM stats_events ORDER BY occurred_at
            SQL));
        self::assertSame(
            [['events' => 2, 'created_at' => '2026-11-03 14:00:00']],
            $this->connection->fetchAllAssociative("SELECT events, to_char(created_at AT TIME ZONE 'Europe/Zurich', 'YYYY-MM-DD HH24:MI:SS') AS created_at FROM stats_ingest_files"),
        );
    }

    public function test_the_rollup_counts_by_c5(): void
    {
        $this->writeDay();

        self::assertEquals(new IngestResult(2, 10, 0, 2), $this->ingest());

        self::assertSame([
            ['2026-11-02', 'site', 'page_view', 1, 1],
            ['2026-11-02', 'site', 'page_view_home', 1, 1],
            ['2026-11-03', 'site', 'page_view', 3, 2],
            ['2026-11-03', 'site', 'page_view_detail', 1, 1],
            ['2026-11-03', 'site', 'page_view_home', 1, 1],
            ['2026-11-03', 'site', 'page_view_listing', 1, 1],
            ['2026-11-03', 'a', 'click_website', 1, 1],
            ['2026-11-03', 'a', 'detail_view', 1, 1],
            ['2026-11-03', 'a', 'impression', 1, 1],
            ['2026-11-03', 'a', 'inquiry', 1, 0],
            ['2026-11-03', 'b', 'impression', 1, 1],
            ['2026-11-03', 'b', 'merkliste_add', 1, 1],
        ], $this->daily());
    }

    public function test_a_second_run_without_a_new_file_changes_nothing(): void
    {
        $this->writeDay();
        $this->ingest();
        $daily = $this->daily();

        self::assertEquals(new IngestResult(0, 0, 0, 0), $this->ingest());

        self::assertSame($daily, $this->daily());
        self::assertSame(10, $this->connection->fetchOne('SELECT count(*) FROM stats_events'));
        self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM stats_ingest_files'));
    }

    public function test_a_line_after_the_first_run_is_added_and_its_day_rebuilt_not_doubled(): void
    {
        $this->writeDay();
        $this->ingest();
        $before = $this->daily();

        $this->spool->append([self::view('2026-11-03T13:00:00', PageType::Home, null, self::H1)]);

        self::assertEquals(new IngestResult(1, 1, 0, 1), $this->ingest());

        $expected = $before;
        $expected[2] = ['2026-11-03', 'site', 'page_view', 4, 2];
        $expected[4] = ['2026-11-03', 'site', 'page_view_home', 2, 1];
        self::assertSame($expected, $this->daily());
        self::assertSame(11, $this->connection->fetchOne('SELECT count(*) FROM stats_events'));
    }

    public function test_a_file_claimed_by_a_crashed_run_is_loaded_once(): void
    {
        // Claimed, never logged: the run crashed before its commit.
        $this->spool->append([self::view('2026-11-03T09:00:00', PageType::Home, null, self::H1)]);
        (new SpoolReader($this->paths))->claimAll();
        // Logged, never deleted: the run crashed after its commit, before the prune.
        $this->spool->append([self::view('2026-11-02T09:00:00', PageType::Home, null, self::H2)]);
        (new SpoolReader($this->paths))->claimAll();
        $logged = array_values(array_filter((new SpoolReader($this->paths))->claimed(), static fn (string $name): bool => str_starts_with($name, '2026-11-02.')));
        $this->connection->insert('stats_ingest_files', ['id' => EntityId::generate(), 'file_name' => $logged[0], 'events' => 1]);

        self::assertEquals(new IngestResult(1, 1, 0, 1), $this->ingest());
        self::assertEquals(new IngestResult(0, 0, 0, 0), $this->ingest());

        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM stats_events'));
        self::assertSame([
            ['2026-11-03', 'site', 'page_view', 1, 1],
            ['2026-11-03', 'site', 'page_view_home', 1, 1],
        ], $this->daily());
        self::assertSame([], $this->files($this->paths->spoolDir()));
    }

    public function test_the_prune_deletes_loaded_files_and_past_salts_after_the_commit(): void
    {
        $this->writeDay();
        mkdir($this->paths->saltDir(), 0o775, true);
        foreach (['2026-10-31', '2026-11-02', '2026-11-03'] as $day) {
            file_put_contents($this->paths->saltDir().'/'.$day, random_bytes(32));
        }

        $this->ingest();

        self::assertSame([], $this->files($this->paths->spoolDir()));
        self::assertSame(['2026-11-03'], $this->files($this->paths->saltDir()));
    }

    /**
     * Ten events: nine on 3 November (two of them automated), one at 23:30 on 2 November in Zurich, which is
     * 22:30 UTC, to prove the day bounds are Zurich's.
     */
    private function writeDay(): void
    {
        $this->spool->append([
            self::view('2026-11-02T23:30:00', PageType::Home, null, self::H1),
            self::view('2026-11-03T09:00:00', PageType::Home, null, self::H1),
            self::view('2026-11-03T09:01:00', PageType::Detail, [$this->a], self::H1),
            self::view('2026-11-03T09:02:00', PageType::Listing, null, self::H2),
            new SpoolEvent(self::at('2026-11-03T09:02:05'), EventType::Impression, null, [$this->a, $this->b, self::UNKNOWN], PageType::Listing, '/hochzeitslocations', null, 'UA', self::H2, false),
            new SpoolEvent(self::at('2026-11-03T09:03:00'), EventType::Click, ClickKind::Website, [$this->a], PageType::Detail, '/x', null, 'UA', self::H1, false),
            new SpoolEvent(self::at('2026-11-03T09:04:00'), EventType::MerklisteAdd, null, [$this->b], PageType::Listing, '/hochzeitslocations', null, 'UA', self::H2, false),
            SpoolEvent::inquiry(self::at('2026-11-03T09:05:00'), $this->a),
            new SpoolEvent(self::at('2026-11-03T09:06:00'), EventType::PageView, null, [$this->b], PageType::Detail, '/y', null, 'HeadlessChrome', self::H3, true),
            new SpoolEvent(self::at('2026-11-03T09:07:00'), EventType::Click, ClickKind::Phone, [$this->b], PageType::Detail, '/y', null, 'HeadlessChrome', self::H3, true),
        ]);
    }

    private function ingest(): IngestResult
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $result = $bus->dispatch(new IngestStats())->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(IngestResult::class, $result);

        return $result;
    }

    /**
     * @return list<array{string, string, string, int, int}>
     */
    private function daily(): array
    {
        $labels = [0 => 'site', $this->a => 'a', $this->b => 'b'];
        $rows = [];
        foreach ($this->connection->fetchAllAssociative('SELECT day::text AS day, venue_number, metric, events, visitors FROM stats_daily ORDER BY day, venue_number, metric COLLATE "C"') as $row) {
            self::assertIsString($row['day']);
            self::assertIsInt($row['venue_number']);
            self::assertIsString($row['metric']);
            self::assertIsInt($row['events']);
            self::assertIsInt($row['visitors']);
            $rows[] = [$row['day'], $labels[$row['venue_number']] ?? (string) $row['venue_number'], $row['metric'], $row['events'], $row['visitors']];
        }

        return $rows;
    }

    /**
     * @param list<int>|null $venues
     */
    private static function view(string $at, PageType $page, ?array $venues, string $hash): SpoolEvent
    {
        return new SpoolEvent(self::at($at), EventType::PageView, null, $venues, $page, '/', null, 'UA', $hash, false);
    }

    private static function at(string $zurich): \DateTimeImmutable
    {
        return new \DateTimeImmutable($zurich, new \DateTimeZone('Europe/Zurich'));
    }

    /**
     * @return list<string>
     */
    private function files(string $directory): array
    {
        $files = is_dir($directory) ? array_values(array_diff((array) scandir($directory), ['.', '..'])) : [];

        return array_map('strval', $files);
    }

    private function clean(): void
    {
        (new Filesystem())->remove([$this->paths->spoolDir(), $this->paths->saltDir()]);
    }
}
