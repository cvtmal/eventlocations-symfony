<?php

declare(strict_types=1);

namespace App\Stats\Console;

use App\Stats\Command\PurgeSearchMisses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Run nightly by `bin/cron-daily` (step `search-misses-purge`), D46. Safe to repeat: a second run deletes nothing.
 */
#[AsCommand(name: 'app:stats:purge-search-misses', description: 'Delete the weekly counts of searches without results after 12 months')]
final class PurgeSearchMissesCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $count = $this->commandBus->dispatch(new PurgeSearchMisses())->last(HandledStamp::class)?->getResult();
        \assert(\is_int($count));

        $io->writeln(\sprintf('Search misses deleted: %d.', $count));

        return Command::SUCCESS;
    }
}
