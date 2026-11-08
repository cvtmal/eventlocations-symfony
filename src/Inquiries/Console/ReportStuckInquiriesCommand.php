<?php

declare(strict_types=1);

namespace App\Inquiries\Console;

use App\Inquiries\Command\AlertStuckInquiries;
use App\Inquiries\ReadModel\StuckInquiries;
use App\Inquiries\ValueObject\StuckAlert;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Run hourly by `bin/cron-hourly` (G7, D56): mails staff the inquiries whose venue mail failed or has no outcome,
 * each once. Silent when that mail went out or nothing was due: cron mails whatever this prints, so it prints only
 * when the staff mail itself failed, and still exits 0 (a non-zero exit would add nothing to that cron mail). Ids
 * and numbers only, never a guest's data.
 */
#[AsCommand(name: 'app:inquiries:report-stuck', description: 'Mail staff the inquiries whose venue mail failed or has no outcome')]
final class ReportStuckInquiriesCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Report inquiries pending for longer than this many minutes')]
        int $minutes = 15,
    ): int {
        $now = $this->clock->now();
        $alert = $this->commandBus->dispatch(new AlertStuckInquiries(
            $now->modify(\sprintf('-%d minutes', $minutes)),
            $now->modify(\sprintf('-%d days', StuckInquiries::WINDOW_DAYS)),
        ))->last(HandledStamp::class)?->getResult();
        \assert($alert instanceof StuckAlert);

        if ($alert->mailed) {
            return Command::SUCCESS;
        }

        $utc = new \DateTimeZone('UTC');
        foreach ($alert->rows as $inquiry) {
            $io->writeln(\sprintf(
                '%s venue %d %s since %s UTC',
                $inquiry->id,
                $inquiry->venueNumber,
                $inquiry->venueMailStatus->value,
                $inquiry->createdAt->setTimezone($utc)->format('Y-m-d H:i'),
            ));
        }
        $io->writeln('Mail to staff failed: resend them from the back office, or dispatch DeliverInquiry for each id.');

        return Command::SUCCESS;
    }
}
