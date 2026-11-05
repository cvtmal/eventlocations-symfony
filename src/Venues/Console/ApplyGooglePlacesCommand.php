<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Venues\Command\AssignVenueGooglePlace;
use App\Venues\Exception\VenueNotFound;
use App\Venues\GooglePlaceFinder;
use App\Venues\GooglePlaceReport;
use App\Venues\ReadModel\GooglePlaceLookupQuery;
use App\Venues\ValueObject\GooglePlaceId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * One-off (#53, D6): sets the place IDs a person marked `apply=yes` in content/owner/google-places-report.csv,
 * through `AssignVenueGooglePlace` (audited as `console`), which never overwrites a venue's place ID. The report
 * is never trusted beyond `yes`: an ID that is not a place ID is refused. Prints venue numbers only, never an ID.
 * The handlers' purge empties this process's cache store only; `bin/prod-import places-apply` purges the server's.
 */
#[AsCommand(name: 'app:venues:apply-google-places', description: 'Set the place IDs a person marked apply=yes in the report')]
final class ApplyGooglePlacesCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly GooglePlaceLookupQuery $venues,
        #[Autowire('%app.content_root%')]
        private readonly string $contentRoot,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Check the report and say what would happen, set nothing')]
        bool $dryRun = false,
    ): int {
        try {
            $rows = GooglePlaceReport::read($this->contentRoot.'/'.GooglePlaceFinder::REPORT_FILE);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $outcomes = ['assigned' => [], 'kept' => [], 'refused' => [], 'unknown' => []];
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['apply']));
        $hasPlace = $dryRun ? $this->venues->havePlace(array_column($rows, 'number')) : [];
        foreach ($rows as $row) {
            try {
                $placeId = GooglePlaceId::fromInput($row['placeId']);
            } catch (\InvalidArgumentException) {
                $placeId = null;
            }
            if (null === $placeId) {
                $outcomes['refused'][] = $row['number'];
            } elseif ($dryRun) {
                $outcomes[match ($hasPlace[$row['number']] ?? null) {
                    null => 'unknown',
                    true => 'kept',
                    false => 'assigned',
                }][] = $row['number'];
            } else {
                $outcomes[$this->assign($row['number'], $placeId->value)][] = $row['number'];
            }
        }

        $io->writeln(\sprintf(
            '%s%d assigned, %d kept (already had a place ID), %d refused (not a place ID), %d unknown venue.',
            $dryRun ? 'Dry run: ' : '',
            \count($outcomes['assigned']),
            \count($outcomes['kept']),
            \count($outcomes['refused']),
            \count($outcomes['unknown']),
        ));
        foreach (['kept', 'refused', 'unknown'] as $outcome) {
            if ([] !== $outcomes[$outcome]) {
                $io->writeln(\sprintf('%s: %s', ucfirst($outcome), implode(', ', $outcomes[$outcome])));
            }
        }

        return [] === $outcomes['refused'] && [] === $outcomes['unknown'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return 'assigned'|'kept'|'unknown'
     */
    private function assign(int $number, string $placeId): string
    {
        try {
            $assigned = $this->commandBus->dispatch(new AssignVenueGooglePlace($number, $placeId))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(VenueNotFound::class)) {
                return 'unknown';
            }

            throw $failure;
        }

        return true === $assigned ? 'assigned' : 'kept';
    }
}
