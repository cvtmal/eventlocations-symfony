<?php

declare(strict_types=1);

namespace App\Portal\Console;

use App\Portal\Command\PurgeOwnerReports;
use App\Portal\Command\SendOwnerReport;
use App\Portal\ReadModel\OwnerReportQuery;
use App\Portal\ValueObject\ReportMonth;
use App\Stats\ReadModel\SiteStatsQuery;
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
 * Run daily by `bin/cron-daily` with `--quiet`, after `stats-ingest` (G10, Wave G §4a.5): last month's report mail per
 * published venue, from the 1st up to the 7th of the month (Zurich), and only once the stats ingest has run past the
 * month's last day. One dispatch per venue, so one failure costs one (`<id>: <reason>` prints under `--quiet`, exit
 * 1). A venue with nobody to mail, or whose mail did not go out, is skipped; a sent one has its row, so a second run
 * sends nothing. The same step purges send-log rows older than 24 months.
 *
 * `--month=YYYY-MM` names a month outside the window: an operator's resend after an outage, or the demo data after
 * `make seed`. It still honours the send log, the opt-outs and the ingest check.
 */
#[AsCommand(name: 'app:portal:owner-reports', description: 'Send last month\'s report mail per published venue')]
final class SendOwnerReportsCommand
{
    public function __construct(
        private readonly OwnerReportQuery $reports,
        private readonly SiteStatsQuery $siteStats,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Name the number of due reports without sending or deleting anything')]
        bool $dryRun = false,
        #[Option('Send the reports of this month (YYYY-MM) instead of last month, on any day')]
        ?string $month = null,
    ): int {
        $now = $this->clock->now();
        $reportMonth = null === $month ? ReportMonth::before($now) : ReportMonth::fromString($month);
        if (null === $reportMonth) {
            $io->writeln(\sprintf('The month "%s" is not YYYY-MM.', $month), OutputInterface::VERBOSITY_QUIET);

            return Command::INVALID;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        if (null !== $month || ReportMonth::catchUpOpen($now)) {
            $ingest = $this->siteStats->lastIngestAt();
            if (null === $ingest || $ingest < $reportMonth->next()->firstDay()) {
                $io->writeln(\sprintf('Stats not ingested past %s; waiting.', $reportMonth->firstDay()->format('Y-m')));
            } else {
                $due = $this->reports->due($reportMonth);
                if ($dryRun) {
                    $io->writeln(\sprintf('%d reports due for %s.', \count($due), $reportMonth->firstDay()->format('Y-m')));

                    return Command::SUCCESS;
                }
                foreach ($due as $venueId) {
                    try {
                        $result = $this->commandBus->dispatch(new SendOwnerReport($venueId, $reportMonth->sql()))->last(HandledStamp::class)?->getResult();
                        true === $result ? ++$sent : ++$skipped;
                    } catch (\Exception $failure) {
                        ++$failed;
                        $io->writeln(\sprintf('%s: %s', $venueId, self::reason($failure)), OutputInterface::VERBOSITY_QUIET);
                    }
                }
            }
        }
        if ($dryRun) {
            return Command::SUCCESS;
        }

        $purged = $this->commandBus->dispatch(new PurgeOwnerReports())->last(HandledStamp::class)?->getResult();
        $io->writeln(\sprintf('%d reports sent, %d skipped, %d failed, %d old rows purged.', $sent, $skipped, $failed, \is_int($purged) ? $purged : 0));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    private static function reason(\Exception $failure): string
    {
        return $failure instanceof HandlerFailedException
            ? (array_values($failure->getWrappedExceptions())[0] ?? $failure)->getMessage()
            : $failure->getMessage();
    }
}
