<?php

declare(strict_types=1);

namespace App\Crm\Console;

use App\Crm\EventlokaleCache;
use App\Crm\EventlokaleCrawl;
use App\Crm\EventlokaleCsv;
use App\Crm\EventlokaleSheet;
use App\Crm\ReadModel\SuppressionQuery;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Owner-run (#49, F14): every venue of eventlokale.ch, which permitted the crawl, in every event
 * category, into the private list content/owner/eventlokale.csv and the facts-only draft sheet
 * content/owner/eventlokale-entwuerfe.csv (EventlokaleSheet, the venue sheet's columns, for
 * app:venues:convert-sheet after a review). Several hours at the default pace; the raw payloads
 * under content/eventlokale/ are the checkpoint, so a rerun resumes. Both files stay out of the
 * repository, the demo data and the production content (docs/rpi-cycles.md, Q12; D11, D12).
 */
#[AsCommand(name: 'app:crm:crawl-eventlokale', description: 'Crawls every eventlokale category into a private CSV and a facts-only draft sheet (owner-run)')]
final class CrawlEventlokaleCommand
{
    public function __construct(
        private readonly EventlokaleCrawl $crawl,
        private readonly EventlokaleCsv $csv,
        private readonly EventlokaleSheet $sheet,
        private readonly SuppressionQuery $suppressions,
        #[Autowire('%app.content_root%')]
        private readonly string $contentRoot,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The content root to write eventlokale/ and owner/ into (default: CONTENT_ROOT)')]
        ?string $path = null,
        #[Option('Pause between two requests, in milliseconds')]
        int $delayMs = 1500,
        #[Option('Up to this many milliseconds added to each pause at random')]
        int $jitterMs = 400,
        #[Option('Fetch this many venue details at most')]
        ?int $limit = null,
        #[Option('Fetch the list, the terms and every detail again, cached or not')]
        bool $refresh = false,
        #[Option('Fetch the list and the terms only, no details')]
        bool $listOnly = false,
        #[Option('Write the CSV from the cache alone, without any request')]
        bool $fromCache = false,
    ): int {
        if ($delayMs < 0 || $jitterMs < 0 || (null !== $limit && $limit < 0)) {
            $io->error('--delay-ms, --jitter-ms and --limit cannot be negative.');

            return Command::FAILURE;
        }
        if ($fromCache && ($refresh || $listOnly || null !== $limit)) {
            $io->error('--from-cache makes no request, so it cannot be combined with --refresh, --list-only or --limit.');

            return Command::FAILURE;
        }

        $root = rtrim($path ?? $this->contentRoot, '/');
        $failed = false;
        if (!$fromCache) {
            $bar = null;
            $result = $this->crawl->run($root, $delayMs, $jitterMs, $limit, $refresh, $listOnly, static function (int $done, int $toFetch) use ($io, &$bar): void {
                $bar ??= $io->createProgressBar($toFetch);
                $bar->setProgress($done);
            });
            if ($bar instanceof ProgressBar) {
                $bar->finish();
                $io->newLine();
            }
            $io->writeln(\sprintf(
                '%d listed in %d categories (API totals %d, a venue in several categories counts in each), %d fetched, %d from cache, %d gone, %d failed.',
                $result->listed,
                $result->categories,
                $result->total,
                $result->fetched,
                $result->skipped,
                $result->gone,
                $result->failed,
            ));
            if ($result->rateLimited) {
                $io->error('Stopped after 3 rate-limit answers in a row. Rerun later to resume.');
            }
            $failed = $result->failed > 0 || $result->rateLimited;
        }

        if (!(new EventlokaleCache($root))->hasIndex()) {
            $io->error(\sprintf('No venue list in %s/eventlokale/ yet: run the command without --from-cache first.', $root));

            return Command::FAILURE;
        }
        $suppressed = $this->suppressions->addresses();
        $written = $this->csv->write($root, $suppressed);
        $io->writeln(\sprintf('CSV: %s/%s (%d rows)', $root, EventlokaleCsv::FILE, $written['rows']));
        if ([] !== $written['emptyColumns']) {
            $io->writeln('Empty in every row: '.implode(', ', $written['emptyColumns']));
        }
        $drafts = $this->sheet->write($root, $suppressed);
        $io->writeln(\sprintf('Draft sheet: %s/%s (%d rows, facts only; review it, then app:venues:convert-sheet)', $root, EventlokaleSheet::FILE, $drafts));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
