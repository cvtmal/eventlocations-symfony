<?php

declare(strict_types=1);

namespace App\Crm\Console;

use App\Crm\Command\IngestInquiry;
use App\Crm\ReadModel\InquiryInboxQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Repairs what the listener missed (a process death, a lost connection). Run daily by `bin/cron-daily` without `--quiet`:
 * its output is the point, so it prints nothing when nothing happened, and cron mails it otherwise. One dispatch per
 * inquiry, so one failure costs one inquiry, and the next night tries it again. A failure line names the class only,
 * because a driver's message can quote an address.
 */
#[AsCommand(name: 'app:crm:backfill-inquiries', description: 'Ingest the inquiries the CRM has no link for')]
final class BackfillInquiriesCommand
{
    public function __construct(
        private readonly InquiryInboxQuery $inbox,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $ingested = 0;
        $failed = 0;
        foreach ($this->inbox->unlinkedInquiryIds() as $id) {
            try {
                if (true === $this->commandBus->dispatch(new IngestInquiry($id))->last(HandledStamp::class)?->getResult()) {
                    ++$ingested;
                }
            } catch (\Exception $failure) {
                ++$failed;
                $io->writeln(\sprintf('%s: %s', $id, self::cause($failure)), OutputInterface::VERBOSITY_QUIET);
            }
        }

        if ($ingested > 0 || $failed > 0) {
            $io->writeln(\sprintf('%d inquiries ingested, %d failed.', $ingested, $failed));
        }

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return class-string
     */
    private static function cause(\Exception $failure): string
    {
        return $failure instanceof HandlerFailedException
            ? (array_values($failure->getWrappedExceptions())[0] ?? $failure)::class
            : $failure::class;
    }
}
