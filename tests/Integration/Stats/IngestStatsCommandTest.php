<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Stats\Spool;
use App\Stats\StatsPaths;
use App\Stats\ValueObject\EventType;
use App\Stats\ValueObject\PageType;
use App\Stats\ValueObject\SpoolEvent;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `app:stats:ingest` (#47), the nightly cron's step: one English line, nothing with `--quiet`.
 */
final class IngestStatsCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private Connection $connection;
    private EntityManagerInterface $em;
    private StatsPaths $paths;

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
        $this->clean();

        $at = new \DateTimeImmutable('2026-11-03T10:00:00+01:00');
        $view = new SpoolEvent($at, EventType::PageView, null, null, PageType::Home, '/', null, 'UA', '0123456789abcdef0123456789abcdef', false);
        (new Spool($this->paths))->append([$view, $view]);
        file_put_contents($this->paths->spoolDir().'/2026-11-03.ndjson', "garbage\n", \FILE_APPEND);
    }

    protected function tearDown(): void
    {
        $this->clean();
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_it_prints_what_it_loaded(): void
    {
        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame("1 files, 2 events loaded, 1 lines skipped, 1 days rebuilt.\n", $tester->getDisplay());
        self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM stats_events'));
    }

    public function test_quiet_prints_nothing(): void
    {
        $tester = $this->runCommand(OutputInterface::VERBOSITY_QUIET);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM stats_events'));
    }

    private function runCommand(int $verbosity = OutputInterface::VERBOSITY_NORMAL): CommandTester
    {
        \assert(null !== self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:stats:ingest'));
        $tester->execute([], ['verbosity' => $verbosity]);

        return $tester;
    }

    private function clean(): void
    {
        (new Filesystem())->remove([$this->paths->spoolDir(), $this->paths->saltDir()]);
    }
}
