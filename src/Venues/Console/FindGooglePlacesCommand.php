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
 * One-off (#53, D6): suggests a Google place ID for every venue without one, into
 * content/owner/google-places-report.csv, and writes nothing to the database. A person checks the
 * report, writes `yes` in `apply`, and runs app:venues:apply-google-places. In production it runs
 * through `bin/prod-import places-find` against Neon, so the numbers are production's.
 * GOOGLE_PLACES_API_KEY comes from the shell only, never from a file.
 */
#[AsCommand(name: 'app:venues:find-google-places', description: 'Suggest a Google place ID per venue without one, into a report (one-off, Google Places)')]
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

        $io->writeln(\sprintf('Looked up: %d found, %d not found, %d failed.', $counts['found'], $counts['missed'], $counts['failed']));
        $io->writeln('Report: '.$this->contentRoot.'/'.GooglePlaceFinder::REPORT_FILE);

        return 0 === $counts['failed'] ? Command::SUCCESS : Command::FAILURE;
    }
}
