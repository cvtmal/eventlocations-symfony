<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Venues\GooglePlaceFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One-off (#53, D6; #57): looks up a Google place ID for every venue without one, into
 * content/owner/google-places-report.csv, and writes nothing to the database. Confident matches come
 * pre-marked `apply=yes`; a person checks the rest with the row's reason and Maps link, and runs
 * app:venues:apply-google-places. Prints counts and reason codes only, never a Google value or ID. In
 * production it runs through `bin/prod-import places-find` against Neon, so the numbers are production's.
 * GOOGLE_PLACES_API_KEY comes from the shell only, never from a file.
 */
#[AsCommand(name: 'app:venues:find-google-places', description: 'Find a Google place ID per venue without one and pre-mark confident matches, into a report (one-off, Google Places)')]
final class FindGooglePlacesCommand
{
    public function __construct(
        private readonly GooglePlaceFinder $finder,
        #[Autowire('%app.content_root%')]
        private readonly string $contentRoot,
        #[Autowire('%env(string:default::GOOGLE_PLACES_API_KEY)%')]
        private readonly string $apiKey,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Ask Google for this many venues at most')]
        ?int $limit = null,
    ): int {
        if ('' === $this->apiKey) {
            $io->error('GOOGLE_PLACES_API_KEY is not set. Put it in the shell, not in a file.');

            return Command::FAILURE;
        }

        $counts = $this->finder->find($this->contentRoot, $limit);

        $io->writeln(\sprintf('Looked up: %d confident, %d doubtful, %d not found, %d failed.', $counts['confident'], $counts['doubtful'], $counts['missed'], $counts['failed']));
        if ([] !== $counts['reasons']) {
            $io->writeln('Doubtful: '.implode(', ', array_map(
                static fn (string $reason, int $rows): string => $reason.' '.$rows,
                array_keys($counts['reasons']),
                $counts['reasons'],
            )));
        }
        $io->writeln('Report: '.$this->contentRoot.'/'.GooglePlaceFinder::REPORT_FILE);

        return 0 === $counts['failed'] ? Command::SUCCESS : Command::FAILURE;
    }
}
