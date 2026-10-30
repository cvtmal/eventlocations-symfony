<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Venues\Exception\InvalidWixExport;
use App\Venues\LegacyContentExporter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns the owner's Wix CSV into content folders under <path>/venues/ (#21); then run
 * app:venues:import. The CSV and the folders hold real contact data and stay git-ignored
 * (content/owner/ and CONTENT_ROOT). Exit code 1 when a row is unresolved or the file is
 * unusable. The output holds counts and reasons, never a contact value.
 */
#[AsCommand(name: 'app:legacy:export-content', description: 'Turn the owner\'s Wix CSV into venue content folders')]
final class ExportLegacyContentCommand
{
    public function __construct(
        private readonly LegacyContentExporter $exporter,
        #[Autowire('%app.content_root%')]
        private readonly string $contentRoot,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The Wix CSV, e.g. content/owner/Locations+Startseite.csv')]
        string $csv,
        #[Argument('The content root to write venues/ into (default: CONTENT_ROOT)')]
        ?string $path = null,
        #[Option('Report what would change; write nothing')]
        bool $dryRun = false,
    ): int {
        $root = rtrim($path ?? $this->contentRoot, '/');
        if (!$dryRun && !is_dir($root.'/venues') && !mkdir($root.'/venues', 0775, true) && !is_dir($root.'/venues')) {
            $io->error(\sprintf('"%s" cannot be created.', $root.'/venues'));

            return Command::FAILURE;
        }

        try {
            $report = $this->exporter->export($csv, $root, $dryRun);
        } catch (InvalidWixExport $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        foreach ([...$report->lines(), ...$report->notes, ...$report->unresolved] as $line) {
            $io->writeln(OutputFormatter::escape($line));
        }

        return [] === $report->unresolved ? Command::SUCCESS : Command::FAILURE;
    }
}
