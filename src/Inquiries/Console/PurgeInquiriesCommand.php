<?php

declare(strict_types=1);

namespace App\Inquiries\Console;

use App\Inquiries\Command\PurgeInquiries;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Run daily by cron (#20), LEG-2. Safe to repeat.
 */
#[AsCommand(name: 'app:inquiries:purge', description: 'Delete inquiries past the retention period')]
final class PurgeInquiriesCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only report how many inquiries would be deleted')]
        bool $dryRun = false,
    ): int {
        $count = $this->commandBus->dispatch(new PurgeInquiries($dryRun))->last(HandledStamp::class)?->getResult();
        \assert(\is_int($count));

        $io->writeln(\sprintf($dryRun ? '%d inquiries would be deleted.' : '%d inquiries deleted.', $count));

        return Command::SUCCESS;
    }
}
