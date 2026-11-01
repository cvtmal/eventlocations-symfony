<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Entity\Deal;
use App\Factory\AdminUserFactory;
use App\Factory\DealFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:crm:renewal-tasks` (CRM-8, D24, N7): idempotent, one failure costs one deal and exit 1, a good night under
 * `--quiet` prints nothing.
 */
final class RenewalTasksCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use RollsBackDatabase {
        setUp as rollBackSetUp;
    }

    protected function setUp(): void
    {
        $this->rollBackSetUp();
        self::mockTime('2026-11-01 10:00:00 UTC');
    }

    public function test_it_writes_one_task_per_deal_and_a_second_run_writes_none(): void
    {
        $deal = $this->ending('2026-12-31');
        $this->ending('2026-11-15');
        $this->ending('2027-03-01');

        [$status, $output] = $this->renewalTasks();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('2 renewal tasks created, 0 skipped, 0 failed.', trim($output));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_tasks WHERE deal_id = ?', [$deal->id()]));

        self::assertSame('0 renewal tasks created, 0 skipped, 0 failed.', trim($this->renewalTasks()[1]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_tasks WHERE is_renewal'));
    }

    public function test_a_done_task_stays_done(): void
    {
        $deal = $this->ending('2026-12-31');
        $this->renewalTasks();
        $this->connection->executeStatement('UPDATE crm_tasks SET done_at = now() WHERE deal_id = ?', [$deal->id()]);

        self::assertSame('0 renewal tasks created, 0 skipped, 0 failed.', trim($this->renewalTasks()[1]));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->ending('2026-12-31');

        [$status, $output] = $this->renewalTasks(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('1 renewal tasks to write.', trim($output));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_tasks'));
    }

    public function test_one_failing_deal_is_named_the_run_goes_on_and_it_exits_1(): void
    {
        $failing = DealFactory::new()->inStage('won')->create(['contractEndOn' => new \DateTimeImmutable('2026-11-10')]);
        $this->connection->executeStatement('UPDATE admin_users SET is_active = false');
        $fine = $this->ending('2026-11-20');

        [$status, $output] = $this->renewalTasks();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString($failing->id().': Nobody active can take the renewal task', $output);
        self::assertStringContainsString('1 renewal tasks created, 0 skipped, 1 failed.', $output);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_tasks WHERE deal_id = ?', [$fine->id()]));
    }

    public function test_a_good_night_under_quiet_prints_nothing(): void
    {
        $this->ending('2026-12-31');

        [$status, $output] = $this->renewalTasks([], OutputInterface::VERBOSITY_QUIET);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('', $output);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_tasks'));
    }

    public function test_a_failure_under_quiet_prints_only_its_line(): void
    {
        $failing = DealFactory::new()->inStage('won')->create(['contractEndOn' => new \DateTimeImmutable('2026-11-10')]);
        $this->connection->executeStatement('UPDATE admin_users SET is_active = false');

        [$status, $output] = $this->renewalTasks([], OutputInterface::VERBOSITY_QUIET);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(1, substr_count(trim($output), \PHP_EOL) + 1);
        self::assertStringStartsWith($failing->id().': ', trim($output));
    }

    private function ending(string $on): Deal
    {
        return DealFactory::new()->inStage('won')->create(['contractEndOn' => new \DateTimeImmutable($on), 'owner' => AdminUserFactory::createOne()]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function renewalTasks(array $input = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:crm:renewal-tasks'));
        $status = $tester->execute($input, ['verbosity' => $verbosity]);

        return [$status, $tester->getDisplay()];
    }
}
