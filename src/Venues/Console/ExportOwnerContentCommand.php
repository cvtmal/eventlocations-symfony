<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Media\ImageVariantGenerator;
use App\Venues\Exception\InvalidWixExport;
use App\Venues\OwnerContentExporter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Overlays the owner's S14 facts and Wix photos on the folders under <path>/venues/ (#32).
 * The source is the owner's Wix CSV and its image links only: no other data, nothing
 * inferred. Run order: app:legacy:export-content, then this, then app:venues:import.
 *
 * Photos are downloaded one at a time into <path>/owner/photos/, a cache, so a rerun asks Wix
 * only for what it lacks. The details (unmapped answers, photos left out with their ids, the
 * spot-check list) go to <path>/owner/export-report.md, in a dry run too, because they are
 * cells. The console holds counts and `<key>: <reason>`, never a cell. Everything stays
 * git-ignored. Exit code 1 when a row is unresolved, a download failed or the file is
 * unusable; a photo left out by a check is no failure.
 */
#[AsCommand(name: 'app:owner:export-content', description: 'Overlay the owner\'s wedding facts and Wix photos on the venue content folders')]
final class ExportOwnerContentCommand
{
    public function __construct(
        private readonly OwnerContentExporter $exporter,
        #[Autowire('%app.content_root%')]
        private readonly string $contentRoot,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The Wix CSV, e.g. content/owner/Locations+Startseite.csv')]
        string $csv,
        #[Argument('The content root whose venues/ to overlay (default: CONTENT_ROOT)')]
        ?string $path = null,
        #[Option('Report what would change; download and write nothing but the report file')]
        bool $dryRun = false,
    ): int {
        // The photo check decodes every photo, up to BATCH_MAX_PIXELS.
        if (!ImageVariantGenerator::raiseBatchMemoryLimit()) {
            $io->warning(ImageVariantGenerator::BATCH_MEMORY_WARNING);
        }
        $root = rtrim($path ?? $this->contentRoot, '/');

        try {
            $report = $this->exporter->export($csv, $root, $dryRun);
        } catch (InvalidWixExport $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        foreach ([...$report->lines(), ...$report->notes, ...$report->unresolved] as $line) {
            $io->writeln(OutputFormatter::escape($line));
        }
        $io->writeln(OutputFormatter::escape('Report: '.$root.'/'.OwnerContentExporter::REPORT_FILE));

        return [] === $report->unresolved && 0 === $report->counts['failed'] ? Command::SUCCESS : Command::FAILURE;
    }
}
