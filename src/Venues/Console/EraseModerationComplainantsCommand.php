<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Venues\Command\EraseModerationComplainants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Run nightly by `bin/cron-daily` (step `moderation-erase`), D36. Safe to repeat: a second run erases nothing.
 */
#[AsCommand(name: 'app:venues:erase-complainants', description: 'Erase the complainant of complaints decided three years ago')]
final class EraseModerationComplainantsCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only report how many complaints would be erased')]
        bool $dryRun = false,
    ): int {
        $count = $this->commandBus->dispatch(new EraseModerationComplainants($dryRun))->last(HandledStamp::class)?->getResult();
        \assert(\is_int($count));

        $io->writeln(\sprintf($dryRun ? '%d complainants would be erased.' : '%d complainants erased.', $count));

        return Command::SUCCESS;
    }
}
