<?php

declare(strict_types=1);

namespace App\Portal\Console;

use App\Portal\Command\SendSubscriptionReminder;
use App\Portal\ReadModel\SubscriptionReminderQuery;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Run daily by `bin/cron-daily` with `--quiet` (G9, Wave G §4a.5): the reminder mails 30 and 7 days before a paid term
 * ends. One dispatch per reminder, so one failure costs one (`<id>: <reason>` prints under `--quiet`, exit 1). A
 * reminder with no recipient or whose mail did not go out is skipped and tried again the next night; a sent one has
 * its marker, so a second run sends nothing.
 */
#[AsCommand(name: 'app:subscriptions:remind', description: 'Send the reminder mails before paid terms end')]
final class RemindSubscriptionsCommand
{
    public function __construct(
        private readonly SubscriptionReminderQuery $reminders,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Name the number of due reminders without sending anything')]
        bool $dryRun = false,
    ): int {
        $due = $this->reminders->due($this->clock->now());
        if ($dryRun) {
            $io->writeln(\sprintf('%d reminders due.', \count($due)));

            return Command::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($due as $reminder) {
            try {
                $result = $this->commandBus->dispatch(new SendSubscriptionReminder($reminder['subscriptionId'], $reminder['daysBefore']))->last(HandledStamp::class)?->getResult();
                true === $result ? ++$sent : ++$skipped;
            } catch (\Exception $failure) {
                ++$failed;
                $io->writeln(\sprintf('%s: %s', $reminder['subscriptionId'], self::reason($failure)), OutputInterface::VERBOSITY_QUIET);
            }
        }
        $io->writeln(\sprintf('%d reminders sent, %d skipped, %d failed.', $sent, $skipped, $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    private static function reason(\Exception $failure): string
    {
        return $failure instanceof HandlerFailedException
            ? (array_values($failure->getWrappedExceptions())[0] ?? $failure)->getMessage()
            : $failure->getMessage();
    }
}
