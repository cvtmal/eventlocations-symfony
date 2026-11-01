<?php

declare(strict_types=1);

namespace App\Crm\Console;

use App\Crm\Command\CreateRenewalTask;
use App\Crm\ReadModel\RenewalsQuery;
use App\Shared\Uid\EntityId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Run daily by `bin/cron-daily` with `--quiet` (CRM-8, N7): the counts are for a person at the console, a failure line
 * `<id>: <reason>` prints anyway, and the exit code is 1, which is how the chain surfaces it. One dispatch per deal, so
 * one failure costs one deal and the run goes on (D24). Safe to repeat: the window is 60 days, so a missed night loses
 * nothing.
 */
#[AsCommand(name: 'app:crm:renewal-tasks', description: 'Write the renewal task of every won deal whose contract ends soon')]
final class RenewalTasksCommand
{
    public function __construct(
        private readonly RenewalsQuery $renewals,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Name the deals without writing anything')]
        bool $dryRun = false,
    ): int {
        $ids = $this->renewals->withoutTask();
        if ($dryRun) {
            $io->writeln(\sprintf('%d renewal tasks to write.', \count($ids)));

            return Command::SUCCESS;
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $wrote = $this->commandBus->dispatch(new CreateRenewalTask(EntityId::generate(), $id))->last(HandledStamp::class)?->getResult();
                $wrote ? ++$created : ++$skipped;
            } catch (\Exception $failure) {
                ++$failed;
                $io->writeln(\sprintf('%s: %s', $id, self::reason($failure)), OutputInterface::VERBOSITY_QUIET);
            }
        }

        $io->writeln(\sprintf('%d renewal tasks created, %d skipped, %d failed.', $created, $skipped, $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    private static function reason(\Exception $failure): string
    {
        return $failure instanceof HandlerFailedException
            ? (array_values($failure->getWrappedExceptions())[0] ?? $failure)->getMessage()
            : $failure->getMessage();
    }
}
