<?php

declare(strict_types=1);

namespace App\Crm\Console;

use App\Crm\CompanyBootstrap;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One company per venue that has none (CRM-1, D19). Idempotent, so it can run again at any time. No
 * export writes `contact_person`, so a production run creates almost no contacts, and the output
 * says so rather than leaving the low count to be read as a failure. Run it with `--no-debug`:
 * Doctrine's debug data holder grows with every statement.
 */
#[AsCommand(name: 'app:crm:bootstrap', description: 'Create one company per venue that has none, with its contact person')]
final class BootstrapCommand
{
    public function __construct(
        private readonly CompanyBootstrap $bootstrap,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $report = $this->bootstrap->run();

        $io->writeln([
            \sprintf('companies created: %d', $report->companies),
            \sprintf('contacts created: %d', $report->contacts),
            \sprintf('venues without a contact person: %d (expected: no export writes contact_person)', $report->withoutContactPerson),
            \sprintf('e-mails taken: %d', $report->emailsTaken),
            \sprintf('venues sharing an inquiry e-mail: %d', $report->sharedEmails),
            \sprintf('skipped: %d', $report->skipped),
            \sprintf('failed: %d', $report->failed()),
        ]);
        $io->writeln($report->failures);

        return [] === $report->failures ? Command::SUCCESS : Command::FAILURE;
    }
}
