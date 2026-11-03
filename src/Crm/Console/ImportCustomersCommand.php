<?php

declare(strict_types=1);

namespace App\Crm\Console;

use App\Crm\CustomerImport;
use App\Crm\Exception\InvalidCustomerSheet;
use App\Crm\ValueObject\CustomerRowClass;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports the owner's customer sheet into the CRM (#48): one company, contact and logged mails per new
 * customer, every other row skipped and listed. The output names row numbers and classes only, never a
 * cell; the report file under CONTENT_ROOT/owner/ adds the company and venue names. Conflicts never fail a
 * run, a failed customer on a real run does. Run it with `--no-debug`: Doctrine's debug data holder grows
 * with every statement.
 */
#[AsCommand(name: 'app:crm:import-customers', description: 'Import the owner\'s customer sheet into the CRM')]
final class ImportCustomersCommand
{
    public function __construct(
        private readonly CustomerImport $import,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The customer sheet, a CSV file')]
        string $csv,
        #[Option('Classify the rows and write the report without writing to the CRM')]
        bool $dryRun = false,
    ): int {
        try {
            $report = $this->import->run($csv, $dryRun);
        } catch (InvalidCustomerSheet $invalid) {
            $io->error($invalid->getMessage());

            return Command::FAILURE;
        }

        $io->writeln($report->summary());
        $io->writeln($report->skippedRows());
        $io->writeln($report->failures);
        $io->writeln('Report: '.$this->import->reportFile());

        $blocked = $report->count(CustomerRowClass::Blocked);
        if ($blocked > 0) {
            $io->writeln(\sprintf('%d row%s match%s a venue without a company: run bin/prod-import crm-bootstrap first', $blocked, 1 === $blocked ? '' : 's', 1 === $blocked ? 'es' : ''));
        }

        return [] === $report->failures ? Command::SUCCESS : Command::FAILURE;
    }
}
