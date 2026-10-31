<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The two ways to discard the HttpCache store (S10, #19), the store framework.http_cache uses in
 * prod: invalidate() makes every entry miss, clear() empties it.
 *
 * invalidate() is the purge after a committed change: it moves only the metadata (md/) away, so
 * every URL misses at once and a request already holding a body path (Store::lookup(), read
 * later by HttpCache::restoreResponseBody()) still finds its body. A body is content-addressed
 * and never rewritten, so it cannot outlive its metadata as a stale page.
 *
 * clear() also drops the bodies (en/): the nightly cron and the size guard, the only callers that
 * free disk. A request between lookup and read at that moment answers a raw 500, about 1–3.5e-4
 * per clear per request/s (at 1 request/s, one 500 in about 3,000–9,000 clears;
 * docs/performance-baseline.md §5).
 *
 * A directory is renamed first, so nothing reads half-deleted entries; then it is removed,
 * together with any copy an earlier clear left behind. Neither method throws: a purge runs after
 * a committed change, and an exception would report that change as failed. clear() returns false
 * on a failure, for the console command; invalidate() logs it and has nobody to tell.
 */
final readonly class HttpCacheClearer
{
    private const PURGED_SUFFIX = '.purged-';
    private const METADATA = '/md';

    public function __construct(
        #[Autowire('%kernel.share_dir%/http_cache')]
        private string $directory,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
    ) {
    }

    public function invalidate(): void
    {
        $this->discard($this->directory.self::METADATA);
    }

    public function clear(): bool
    {
        return $this->discard($this->directory);
    }

    /**
     * Bytes and files in the store and any copy a clear left behind, for the size guard. The
     * store changes during the walk, so an entry that vanishes meanwhile simply does not count.
     *
     * @return array{bytes: int, files: int}
     */
    public function usage(): array
    {
        $usage = ['bytes' => 0, 'files' => 0];
        foreach ([$this->directory, ...$this->leftovers()] as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            /** @var \SplFileInfo $file */
            foreach ($files as $file) {
                $usage['bytes'] += (int) @filesize($file->getPathname());
                ++$usage['files'];
            }
        }

        return $usage;
    }

    private function discard(string $path): bool
    {
        try {
            if (is_dir($path)) {
                $this->filesystem->rename($path, $this->directory.self::PURGED_SUFFIX.bin2hex(random_bytes(6)));
            }

            $this->filesystem->remove($this->leftovers());

            return true;
        } catch (\Throwable $exception) {
            // The values go into placeholders: the default logger prints nothing else of the context.
            // Never the message: it names paths, and the class and line are enough to find the cause.
            $this->logger->error('HTTP cache clear failed ({exception_class} at {file}:{line}).', [
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function leftovers(): array
    {
        return glob($this->directory.self::PURGED_SUFFIX.'*', \GLOB_NOSORT | \GLOB_ONLYDIR) ?: [];
    }
}
