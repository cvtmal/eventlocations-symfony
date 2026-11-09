<?php

declare(strict_types=1);

namespace App\Crm\Console;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Command\RecordConsent;
use App\Crm\ReadModel\ConsentQuery;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Crm\ValueObject\ContactEmail;
use App\Shared\Uid\EntityId;
use App\Venues\ReadModel\VenueKeyQuery;
use App\Venues\Sheet\VenueSheet;
use App\Venues\Sheet\VenueSheetRow;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Puts the yes of each consenting hochzeitslocation.ch customer (F14, D29) on the timeline of its Location: reads
 * the venue sheet's rows with source hochzeitslocation, after `app:venues:import` made their drafts, finds each
 * venue by its content key, creates its Location when it has none (as `app:crm:bootstrap` does) and dispatches
 * `RecordConsent`.
 *
 * Per key: `recorded`, `unchanged` (that yes is already there), `not_imported` (no venue with the key yet: import
 * first) or `failed` (the row breaks the sheet's rules, or the write failed). Prints keys and reason codes only,
 * never an address. Exit 1 when any row is `not_imported` or `failed`. A dry run writes nothing.
 */
#[AsCommand(name: 'app:crm:record-consents', description: 'Record the consent of the hochzeitslocation rows of the venue sheet on their Locations')]
final class RecordConsentsCommand
{
    public function __construct(
        private readonly VenueSheet $sheet,
        private readonly VenueKeyQuery $venues,
        private readonly VenueCompanyQuery $companies,
        private readonly ConsentQuery $consents,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The venue sheet (CSV, comma or semicolon)')]
        string $csv,
        #[Option('Say what would be recorded; write nothing')]
        bool $dryRun = false,
    ): int {
        try {
            $rows = VenueSheet::read($csv);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $counts = ['recorded' => 0, 'unchanged' => 0, 'not_imported' => 0, 'failed' => 0];
        $problems = [];
        foreach ($rows as $row) {
            if (VenueSheet::SOURCE_CONSENT !== $row->source()) {
                continue;
            }
            $errors = $this->sheet->check($row);
            if ([] !== $errors) {
                $status = 'failed';
                $problems = [...$problems, ...$errors];
            } else {
                [$status, $reason] = $this->record($row, $dryRun);
                if (null !== $reason) {
                    $problems[] = \sprintf('row %d: %s', $row->line, $reason);
                }
            }
            ++$counts[$status];
            $io->writeln(\sprintf('%-12s  %s', $status, '' === $row->key() ? 'row '.$row->line : $row->key()));
        }

        $io->writeln(\sprintf(
            '%sConsents: %d recorded, %d unchanged, %d not imported, %d failed.',
            $dryRun ? 'Dry run: ' : '',
            $counts['recorded'],
            $counts['unchanged'],
            $counts['not_imported'],
            $counts['failed'],
        ));
        if ([] !== $problems) {
            $io->error('Not recorded:');
            $io->listing($problems);
        }

        return 0 === $counts['not_imported'] + $counts['failed'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array{0: 'recorded'|'unchanged'|'not_imported'|'failed', 1: ?string} the status and, unless recorded or
     *                                                                              unchanged, why
     */
    private function record(VenueSheetRow $row, bool $dryRun): array
    {
        $number = $this->venues->numberOf($row->key());
        $venue = null === $number ? null : $this->venues->find($number);
        if (null === $venue) {
            return ['not_imported', 'no venue with key '.$row->key().' yet; run app:venues:import first'];
        }
        $companyId = $this->companies->companyIdOf($venue->id);
        if ($dryRun) {
            $known = null !== $companyId && $this->consents->has($companyId, ContactEmail::normalise($row->cell('consent_from')), $row->cell('consent_on'));

            return [$known ? 'unchanged' : 'recorded', null];
        }

        try {
            if (null === $companyId) {
                $companyId = EntityId::generate();
                $this->commandBus->dispatch(new CreateCompanyForVenue($venue->id, $companyId));
                $companyId = $this->companies->companyIdOf($venue->id) ?? $companyId;
            }
            $written = $this->commandBus->dispatch(new RecordConsent($companyId, $row->cell('consent_from'), $row->cell('consent_on')))->last(HandledStamp::class)?->getResult();
        } catch (\Exception $exception) {
            $cause = $exception instanceof HandlerFailedException ? (array_values($exception->getWrappedExceptions())[0] ?? $exception) : $exception;
            if ($cause instanceof \Error) {
                throw $exception;
            }

            // Never the driver's message: it may quote the row.
            return ['failed', (new \ReflectionClass($cause))->getShortName()];
        }

        return [true === $written ? 'recorded' : 'unchanged', null];
    }
}
