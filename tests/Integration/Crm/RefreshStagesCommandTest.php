<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ValueObject\CompanyStage;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:crm:refresh-stages` (D10, L3, N7): a contract ending flips a stage with no write of anybody, and the daily
 * chain's `step` prints any output, so a good night under `--quiet` prints nothing.
 */
final class RefreshStagesCommandTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_it_flips_an_ended_contract_and_reports_the_count(): void
    {
        $company = $this->companyWithEndedContract();

        [$status, $output] = $this->refresh();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('1 company stages changed.', trim($output));
        self::assertSame('former_customer', $this->connection->fetchOne('SELECT stage FROM crm_companies WHERE id = ?', [$company]));
        self::assertSame('0 company stages changed.', trim($this->refresh()[1]));
    }

    public function test_it_is_silent_under_quiet(): void
    {
        $company = $this->companyWithEndedContract();

        [$status, $output] = $this->refresh(OutputInterface::VERBOSITY_QUIET);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('', $output);
        self::assertSame('former_customer', $this->connection->fetchOne('SELECT stage FROM crm_companies WHERE id = ?', [$company]));
    }

    private function companyWithEndedContract(): string
    {
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create();
        DealFactory::new()->inStage('won')->create(['company' => $company, 'contractEndOn' => new \DateTimeImmutable('-1 day')]);

        return $company->id();
    }

    /**
     * @return array{int, string}
     */
    private function refresh(int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:crm:refresh-stages'));
        $status = $tester->execute([], ['verbosity' => $verbosity]);

        return [$status, $tester->getDisplay()];
    }
}
