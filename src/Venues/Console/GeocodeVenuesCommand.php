<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Venues\Exception\InvalidWixExport;
use App\Venues\VenueCoordinateFiller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One-off: writes `geo:` (lat, lng) into every venue folder that has none, found by name and
 * region with Google Places. Run it after app:owner:export-content and before app:venues:import
 * (bin/prod-import all), and before staff edit: the import skips a venue staff changed.
 * GOOGLE_PLACES_API_KEY comes from the shell only, never from a file. The report at
 * content/owner/geocode-report.csv lists each venue with the name Google matched.
 */
#[AsCommand(name: 'app:venues:geocode', description: 'Write coordinates into the venue content folders that have none (one-off, Google Places)')]
final class GeocodeVenuesCommand
{
    public function __construct(
        private readonly VenueCoordinateFiller $filler,
        #[Autowire('%app.content_root%')]
        private readonly string $contentRoot,
        #[Autowire('%env(string:default::GOOGLE_PLACES_API_KEY)%')]
        private readonly string $apiKey,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Look up and report, write no venue.yaml')]
        bool $dryRun = false,
        #[Option('Ask Google for this many venues at most')]
        ?int $limit = null,
    ): int {
        if ('' === $this->apiKey) {
            $io->error('GOOGLE_PLACES_API_KEY is not set. Put it in the shell, not in a file.');

            return Command::FAILURE;
        }

        try {
            $counts = $this->filler->fill($this->contentRoot, $dryRun, $limit);
        } catch (InvalidWixExport $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(\sprintf(
            '%s: %d found, %d not found, %d failed, %d already had coordinates.',
            $dryRun ? 'Dry run' : 'Geocoded',
            $counts['found'],
            $counts['missed'],
            $counts['failed'],
            $counts['skipped'],
        ));
        $io->writeln('Report: '.$this->contentRoot.'/'.VenueCoordinateFiller::REPORT_FILE);

        return 0 === $counts['failed'] ? Command::SUCCESS : Command::FAILURE;
    }
}
