<?php

declare(strict_types=1);

namespace App\Inquiries\Console;

use App\Inquiries\Command\RequestInquiryFeedback;
use App\Inquiries\ReadModel\FeedbackRequestsDue;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Nightly step `inquiry-feedback` of `bin/cron-daily` (G16): the feedback mail to every guest whose delivered,
 * qualified inquiry is a week old, one mail per address, each address in its own transaction. A failed mail leaves
 * its inquiries for the next night and prints one line even under `--quiet`; a second run sends and writes nothing.
 * Prints counts only, never an address.
 */
#[AsCommand(name: 'app:inquiries:request-feedback', description: 'Send the feedback mail a week after a delivered inquiry')]
final class RequestFeedbackCommand
{
    public function __construct(
        private readonly FeedbackRequestsDue $due,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $mailed = $skipped = $failed = 0;
        foreach ($this->due->due($this->clock->now()) as $request) {
            $ok = $this->commandBus->dispatch(new RequestInquiryFeedback($request->inquiryIds, $request->optedOut))
                ->last(HandledStamp::class)?->getResult();
            if (true !== $ok) {
                ++$failed;
            } elseif ($request->optedOut) {
                ++$skipped;
            } else {
                ++$mailed;
            }
        }

        $io->writeln(\sprintf('%d feedback mails sent, %d addresses opted out, %d mails failed.', $mailed, $skipped, $failed));
        if ($failed > 0) {
            $io->writeln(\sprintf('%d feedback mails failed; they are retried next night.', $failed), OutputInterface::VERBOSITY_QUIET);
        }

        return Command::SUCCESS;
    }
}
