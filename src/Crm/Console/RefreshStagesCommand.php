<?php

declare(strict_types=1);

namespace App\Crm\Console;

use App\Crm\Command\RefreshCompanyStages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Run daily by `bin/cron-daily` with `--quiet` (N7): the count is for a person at the console, and a failure prints
 * anyway, because the exception renderer ignores `--quiet`. Safe to repeat.
 */
#[AsCommand(name: 'app:crm:refresh-stages', description: 'Bring the derived company stages up to date')]
final class RefreshStagesCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $count = $this->commandBus->dispatch(new RefreshCompanyStages())->last(HandledStamp::class)?->getResult();
        \assert(\is_int($count));

        $io->writeln(\sprintf('%d company stages changed.', $count));

        return Command::SUCCESS;
    }
}
