<?php

declare(strict_types=1);

namespace App\Stats\Console;

use App\Stats\Command\IngestStats;
use App\Stats\ValueObject\IngestResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Run nightly by cron (#47); the button on /admin/statistik dispatches the same command. Safe to repeat.
 */
#[AsCommand(name: 'app:stats:ingest', description: 'Load the statistics spool files and rebuild the daily figures')]
final class IngestStatsCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->commandBus->dispatch(new IngestStats())->last(HandledStamp::class)?->getResult();
        \assert($result instanceof IngestResult);

        $io->writeln(\sprintf(
            '%d files, %d events loaded, %d lines skipped, %d days rebuilt.',
            $result->files,
            $result->events,
            $result->skipped,
            $result->days,
        ));

        return Command::SUCCESS;
    }
}
