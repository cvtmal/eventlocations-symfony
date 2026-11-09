<?php

declare(strict_types=1);

namespace App\Crm\Console;

use App\Crm\ContactSheet;
use App\Crm\Exception\LocationSuppressed;
use App\Crm\ReadModel\LocationMatchQuery;
use App\Crm\ReadModel\SuppressionQuery;
use App\Shared\Uid\EntityId;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Imports the owner's contact list into the CRM (F14, D49), one Location per row through `ImportLocation`. Never
 * sends anything (D30): letters and one-to-one mails stay staff's work.
 *
 * Per row: `created`, `exists` (a Location of the same name, or a Location or contact holding one of the row's
 * addresses: a rerun writes nothing), `suppressed` (an address is on the suppression list, G11) or `failed` (the row
 * breaks the rules of the CRM's create form and Akquise tab, or the write failed). Prints row numbers, counts and
 * reason codes only, never a name or an address. Exit 1 when any row failed. A dry run writes nothing.
 */
#[AsCommand(name: 'app:crm:import-contacts', description: 'Import the contact list (CSV) as CRM Locations with their contact and outreach fields')]
final class ImportContactsCommand
{
    public function __construct(
        private readonly ContactSheet $sheet,
        private readonly SuppressionQuery $suppressions,
        private readonly LocationMatchQuery $matches,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The contact list (CSV, comma or semicolon)')]
        string $csv,
        #[Option('Check every row and say what would be imported; write nothing')]
        bool $dryRun = false,
    ): int {
        try {
            $rows = ContactSheet::read($csv);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $counts = ['created' => 0, 'exists' => 0, 'suppressed' => 0, 'failed' => 0];
        $problems = [];
        foreach ($rows as $row) {
            ['errors' => $errors, 'venueNumber' => $venueNumber] = $this->sheet->check($row);
            $emails = $row->emails();
            if ([] !== $errors) {
                $status = 'failed';
                $problems = [...$problems, ...$errors];
            } elseif ([] !== array_filter($emails, $this->suppressions->isAddressSuppressed(...))) {
                $status = 'suppressed';
            } elseif ($this->matches->exists((string) $row->value('location'), $emails)) {
                $status = 'exists';
            } elseif ($dryRun) {
                $status = 'created';
            } else {
                $status = $this->import($row->toCommand(EntityId::generate(), EntityId::generate(), $venueNumber), $row->line, $problems);
            }
            ++$counts[$status];
            $io->writeln(\sprintf('%-10s  row %d', $status, $row->line));
        }

        $io->writeln(\sprintf(
            '%sLocations: %d created, %d exist, %d suppressed, %d failed.',
            $dryRun ? 'Dry run: ' : '',
            $counts['created'],
            $counts['exists'],
            $counts['suppressed'],
            $counts['failed'],
        ));
        if ([] !== $problems) {
            $io->error('Not imported:');
            $io->listing($problems);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $problems
     *
     * @return 'created'|'suppressed'|'failed'
     */
    private function import(object $command, int $line, array &$problems): string
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (\Exception $exception) {
            $cause = $exception instanceof HandlerFailedException ? (array_values($exception->getWrappedExceptions())[0] ?? $exception) : $exception;
            if ($cause instanceof \Error) {
                throw $exception;
            }
            if ($cause instanceof LocationSuppressed) {
                return 'suppressed';
            }
            // The exception's class only: a driver's message may quote the row.
            $problems[] = \sprintf('row %d: %s', $line, (new \ReflectionClass($cause))->getShortName());

            return 'failed';
        }

        return 'created';
    }
}
