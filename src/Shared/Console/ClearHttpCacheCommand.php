<?php

declare(strict_types=1);

namespace App\Shared\Console;

use App\Shared\Http\HttpCacheClearer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `cache:clear` does not empty the HttpCache store (it lives in %kernel.share_dir%/http_cache,
 * var/share/<env>/), this does.
 * #20's cron runs it daily at 00:05 Europe/Zurich, when the listings' day seed changes, and
 * after `app:geo:import-towns` and `app:media:regenerate`, which change pages without a purge.
 *
 * --if-over-limit is the size guard, on #20's 5-minute cron: Store never evicts, and every
 * filter state is a new key, so only a size limit bounds the disk. It walks the store once and
 * clears it above HTTP_CACHE_MAX_BYTES or HTTP_CACHE_MAX_FILES; the second limit is there
 * because small pages use up inodes rather than bytes. It prints nothing while the store is
 * within both limits, because cron mails any output and this runs 288 times a day.
 *
 * It calls the clearer directly, without the bus: `command.bus` opens a transaction, and a
 * nightly file operation should not wake the database. A failed clear exits 1, because
 * #20's cron can see nothing else.
 */
#[AsCommand(name: 'app:http-cache:clear', description: 'Empty the HTTP cache store (S10)')]
final class ClearHttpCacheCommand
{
    public function __construct(
        private readonly HttpCacheClearer $clearer,
        #[Autowire('%env(int:HTTP_CACHE_MAX_BYTES)%')]
        private readonly int $maxBytes,
        #[Autowire('%env(int:HTTP_CACHE_MAX_FILES)%')]
        private readonly int $maxFiles,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Clear only above HTTP_CACHE_MAX_BYTES or HTTP_CACHE_MAX_FILES')]
        bool $ifOverLimit = false,
    ): int {
        if ($ifOverLimit) {
            ['bytes' => $bytes, 'files' => $files] = $this->clearer->usage();

            if ($bytes <= $this->maxBytes && $files <= $this->maxFiles) {
                return Command::SUCCESS;
            }

            $io->writeln(\sprintf('HTTP cache store: %d bytes, %d files.', $bytes, $files));
        }

        if (!$this->clearer->clear()) {
            $io->error('The HTTP cache store could not be emptied; the log has the cause.');

            return Command::FAILURE;
        }

        $io->writeln('HTTP cache store emptied.');

        return Command::SUCCESS;
    }
}
