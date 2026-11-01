<?php

declare(strict_types=1);

namespace App\Inquiries\Console;

use App\Inquiries\ReadModel\StuckInquiries;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run daily by cron (#20, PD7). Silent when nothing is stuck: cron mails whatever this prints, and
 * a non-zero exit would add nothing to that mail. Ids and numbers only, never a guest's data.
 */
#[AsCommand(name: 'app:inquiries:report-stuck', description: 'List inquiries whose venue mail has no outcome')]
final class ReportStuckInquiriesCommand
{
    public function __construct(
        private readonly StuckInquiries $stuckInquiries,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Report inquiries pending for longer than this many minutes')]
        int $minutes = 15,
    ): int {
        $stuck = $this->stuckInquiries->olderThan($this->clock->now()->modify(\sprintf('-%d minutes', $minutes)));

        if ([] === $stuck) {
            return Command::SUCCESS;
        }

        $utc = new \DateTimeZone('UTC');
        foreach ($stuck as $inquiry) {
            $io->writeln(\sprintf('%s venue %d since %s UTC', $inquiry->id, $inquiry->venueNumber, $inquiry->createdAt->setTimezone($utc)->format('Y-m-d H:i')));
        }
        $io->writeln('Their venues may have no mail: resend them from the back office, or dispatch DeliverInquiry for each id.');

        return Command::SUCCESS;
    }
}
