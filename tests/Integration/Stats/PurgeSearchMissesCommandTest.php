<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * D46: a week of searches without results is deleted on the last night before its Monday (00:00 Zurich, the
 * earliest search the row can hold) turns 12 months old (cutoff `now - 12 months + 1 day`), so no search stays
 * longer; a younger week stays, and a second run deletes nothing.
 */
final class PurgeSearchMissesCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use RollsBackDatabase {
        setUp as rollsBackSetUp;
    }

    /** The 03:15 run on a Monday: now - 12 months + 1 day is 2026-11-09 03:15 Zurich, so the cutoff date is 2026-11-09. */
    private const string NOW = '2027-11-08 03:15:00 Europe/Zurich';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rollsBackSetUp();
        self::mockTime(self::NOW);
        $this->connection->executeStatement('DELETE FROM search_misses');

        // 2026-11-09 turns 12 months old at midnight tonight, before the next run: due. 2026-11-16 is a week younger.
        foreach (['2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16', '2027-11-08'] as $week) {
            $this->connection->insert('search_misses', [
                'id' => EntityId::generate(),
                'week_start' => $week,
                'query' => 'q=Schloss',
                'searches' => 3,
            ]);
        }
    }

    public function test_deletes_the_weeks_whose_monday_turns_12_months_old_before_the_next_night(): void
    {
        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame("Search misses deleted: 3.\n", $tester->getDisplay());
        self::assertSame(['2026-11-16', '2027-11-08'], $this->weeks());
    }

    public function test_a_second_run_deletes_nothing(): void
    {
        $this->runCommand();

        $tester = $this->runCommand();

        self::assertSame("Search misses deleted: 0.\n", $tester->getDisplay());
        self::assertSame(['2026-11-16', '2027-11-08'], $this->weeks());
    }

    public function test_the_next_night_takes_the_next_week_only_when_it_is_due(): void
    {
        $this->runCommand();
        self::mockTime('2027-11-14 03:15:00 Europe/Zurich');
        self::assertSame("Search misses deleted: 0.\n", $this->runCommand()->getDisplay(), 'Sunday: 2026-11-16 is due tomorrow night');

        self::mockTime('2027-11-15 03:15:00 Europe/Zurich');
        self::assertSame("Search misses deleted: 1.\n", $this->runCommand()->getDisplay());
        self::assertSame(['2027-11-08'], $this->weeks());
    }

    public function test_the_cutoff_is_a_zurich_date(): void
    {
        // 00:30 Zurich is still the previous day in UTC: a cutoff taken in UTC would keep week 2026-11-09 a night too long.
        self::mockTime('2027-11-08 00:30:00 Europe/Zurich');

        self::assertSame("Search misses deleted: 3.\n", $this->runCommand()->getDisplay());
        self::assertSame(['2026-11-16', '2027-11-08'], $this->weeks());
    }

    public function test_a_leap_day_never_moves_the_cutoff_past_12_months(): void
    {
        // 2028-02-29 - 12 months overflows to 2027-03-01 in PHP, + 1 day is 2027-03-02: the overflow only deletes earlier.
        $this->connection->executeStatement('DELETE FROM search_misses');
        foreach (['2027-02-22', '2027-03-01', '2027-03-08'] as $week) {
            $this->connection->insert('search_misses', ['id' => EntityId::generate(), 'week_start' => $week, 'query' => 'q=Schloss', 'searches' => 1]);
        }
        self::mockTime('2028-02-29 03:15:00 Europe/Zurich');

        self::assertSame("Search misses deleted: 2.\n", $this->runCommand()->getDisplay());
        self::assertSame(['2027-03-08'], $this->weeks());
    }

    private function runCommand(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:stats:purge-search-misses'));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @return list<string>
     */
    private function weeks(): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT week_start::text FROM search_misses ORDER BY week_start');
    }
}
