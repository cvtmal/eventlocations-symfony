<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Search\ReadModel\SearchCriteria;
use App\Shared\Command\RecordSearchMiss;
use App\Stats\CommandHandler\RecordSearchMissHandler;
use App\Stats\ReadModel\SearchMissQuery;
use App\Stats\ValueObject\FunnelWindow;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * G12: a search without results is counted per ISO week and canonical query (no person, best effort), and the
 * scorecard reads the window's weeks with the most frequent queries, labelled.
 */
final class SearchMissTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ImportsTownDataset;
    use RollsBackDatabase;

    /** A Sunday evening in Zurich, still ISO week 45 (Monday 2026-11-02). */
    private const string SUNDAY = '2026-11-08 22:30:00 Europe/Zurich';

    public function test_the_same_query_in_one_week_is_one_row_with_a_count(): void
    {
        self::mockTime(self::SUNDAY);
        $this->connection->executeStatement('DELETE FROM search_misses');

        $this->bus()->dispatch(new RecordSearchMiss('canton=zh&guests=900'));
        $this->bus()->dispatch(new RecordSearchMiss('canton=zh&guests=900'));
        $this->bus()->dispatch(new RecordSearchMiss('q=Schloss'));
        self::mockTime('2026-11-09 08:00:00 Europe/Zurich');
        $this->bus()->dispatch(new RecordSearchMiss('canton=zh&guests=900'));

        self::assertSame(
            [
                ['week_start' => '2026-11-02', 'query' => 'canton=zh&guests=900', 'searches' => 2],
                ['week_start' => '2026-11-02', 'query' => 'q=Schloss', 'searches' => 1],
                ['week_start' => '2026-11-09', 'query' => 'canton=zh&guests=900', 'searches' => 1],
            ],
            $this->connection->fetchAllAssociative('SELECT week_start::text AS week_start, query, searches FROM search_misses ORDER BY week_start, query'),
        );
    }

    public function test_a_query_that_is_no_canonical_query_string_is_dropped_without_its_value(): void
    {
        $this->connection->executeStatement('DELETE FROM search_misses');
        $logger = $this->recordingLogger();
        $handler = new RecordSearchMissHandler($this->connection, new MockClock(self::SUNDAY), $logger);

        $handler(new RecordSearchMiss(''));
        $handler(new RecordSearchMiss('q=Zürich'));
        $handler(new RecordSearchMiss(str_repeat('a', RecordSearchMissHandler::MAX_LENGTH + 1)));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM search_misses'));
        self::assertCount(3, $logger->records);
        foreach ($logger->records as $record) {
            self::assertStringNotContainsString('Zürich', $record['message'].json_encode($record['context']));
        }
    }

    public function test_a_failing_insert_is_logged_and_never_thrown(): void
    {
        $logger = $this->recordingLogger();
        $this->connection->executeStatement('ALTER TABLE search_misses RENAME TO search_misses_gone');
        $handler = new RecordSearchMissHandler($this->connection, new MockClock(self::SUNDAY), $logger);

        $handler(new RecordSearchMiss('q=Schloss'));

        self::assertCount(1, $logger->records);
        self::assertStringStartsWith('Search miss: not recorded', $logger->records[0]['message']);
        self::assertStringNotContainsString('Schloss', json_encode($logger->records[0]['context']) ?: '');
        self::assertTrue($this->connection->isTransactionActive(), 'the savepoint kept the caller\'s transaction usable');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT 1'));
    }

    public function test_the_scorecard_lists_the_weeks_of_the_window_with_labelled_queries(): void
    {
        $this->connection->executeStatement('DELETE FROM search_misses');
        $townId = (string) $this->connection->fetchOne("SELECT id::text FROM towns WHERE name = 'Zürich' LIMIT 1");
        $this->miss('2026-11-02', 'canton=zh&event%5B0%5D=seminar&guests=900', 3);
        $this->miss('2026-11-02', 'q=Schloss&access%5B0%5D=station', 1);
        self::assertNotSame('', $townId);
        $this->miss('2026-11-02', 'place='.$townId.'&radius=0', 1);
        $this->miss('2026-10-26', 'q=Turnhalle', 2);
        $this->miss('2026-06-01', 'q=zu-alt', 9);

        $weeks = $this->query()->weeks(FunnelWindow::Weeks4, new \DateTimeImmutable(self::SUNDAY));

        self::assertCount(2, $weeks, 'only weeks with a miss, and none outside the window');
        self::assertSame('2026-11-02', $weeks[0]->start->format('Y-m-d'));
        self::assertSame(5, $weeks[0]->searches);
        self::assertSame('canton=zh&event%5B0%5D=seminar&guests=900', $weeks[0]->top[0]->query);
        self::assertSame(['Kanton: ZH', 'Anlass: Seminar', 'Gäste: 900'], $weeks[0]->top[0]->parts);
        self::assertSame(3, $weeks[0]->top[0]->searches);
        $parts = array_merge(...array_map(static fn ($row): array => $row->parts, $weeks[0]->top));
        self::assertContains('Suchtext: Schloss', $parts);
        self::assertContains('Erreichbarkeit: Nähe Bahnhof', $parts);
        self::assertContains('Ort: Zürich', $parts);
        self::assertSame(['Suchtext: Turnhalle'], $weeks[1]->top[0]->parts);
    }

    public function test_a_week_shows_its_top_queries_and_counts_all(): void
    {
        $this->connection->executeStatement('DELETE FROM search_misses');
        for ($i = 1; $i <= SearchMissQuery::TOP + 3; ++$i) {
            $this->miss('2026-11-02', 'q=suche'.$i, $i);
        }

        $week = $this->query()->weeks(FunnelWindow::Weeks4, new \DateTimeImmutable(self::SUNDAY))[0];

        self::assertCount(SearchMissQuery::TOP, $week->top);
        self::assertSame('q=suche23', $week->top[0]->query);
        self::assertSame(SearchMissQuery::TOP + 3, $week->queries);
        self::assertSame(276, $week->searches);
    }

    public function test_the_term_parameters_match_the_search_criteria(): void
    {
        self::assertSame(
            array_map(static fn ($group): string => $group->value, SearchCriteria::PARAMS),
            SearchMissQuery::TERM_PARAMS,
        );
    }

    private function miss(string $week, string $query, int $searches): void
    {
        $this->connection->insert('search_misses', [
            'id' => \App\Shared\Uid\EntityId::generate(),
            'week_start' => $week,
            'query' => $query,
            'searches' => $searches,
            'last_searched_at' => $week.' 12:00:00+01',
        ]);
    }

    private function query(): SearchMissQuery
    {
        $query = self::getContainer()->get(SearchMissQuery::class);
        self::assertInstanceOf(SearchMissQuery::class, $query);

        return $query;
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    /**
     * @return AbstractLogger&object{records: list<array{message: string, context: array<string, mixed>}>}
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
    }
}
