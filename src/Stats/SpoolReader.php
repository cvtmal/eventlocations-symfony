<?php

declare(strict_types=1);

namespace App\Stats;

use App\Stats\ValueObject\SpoolEvent;

/**
 * The ingest's side of the spool (#47, contract C3). A claim renames every day file
 * `<Y-m-d>.ndjson`, today's open one included, to `<Y-m-d>.<8 hex>.claimed`, so the beacon's next
 * write starts a new file and a claimed file only ever shrinks to what was in it. Reading locks
 * the claimed file first, so a writer that locked before the rename finishes its line. A claimed
 * file is deleted only once its name is logged in `stats_ingest_files` (`PruneStatsSpool`), so a
 * run that crashed leaves it for the next run.
 */
final readonly class SpoolReader
{
    private const string DAY_FILE = '/^\d{4}-\d{2}-\d{2}\.ndjson$/';
    private const string CLAIMED_FILE = '/^\d{4}-\d{2}-\d{2}\.[0-9a-f]{8}\.claimed$/';
    /** Deeper than a spool line ever is (an object holding one list). */
    private const int JSON_DEPTH = 8;

    public function __construct(
        private StatsPaths $paths,
    ) {
    }

    public function claimAll(): void
    {
        foreach ($this->files(self::DAY_FILE) as $file) {
            $day = substr($file, 0, 10);
            do {
                $claimed = $day.'.'.bin2hex(random_bytes(4)).'.claimed';
            } while (file_exists($this->path($claimed)));

            if (!@rename($this->path($file), $this->path($claimed))) {
                throw new \RuntimeException(\sprintf('Cannot claim the spool file %s.', $file));
            }
        }
    }

    /**
     * Every claimed file, loaded or not, sorted by name (so by day).
     *
     * @return list<string>
     */
    public function claimed(): array
    {
        return $this->files(self::CLAIMED_FILE);
    }

    /**
     * The claimed files whose names are not in `$logged`, sorted by name.
     *
     * @param list<string> $logged the names already in `stats_ingest_files`
     *
     * @return list<string>
     */
    public function unloaded(array $logged): array
    {
        return array_values(array_diff($this->claimed(), $logged));
    }

    /**
     * The events of one claimed file. A line that does not parse, or no longer matches the enums or
     * the column rules, is skipped; the generator's return value is how many were.
     *
     * @return \Generator<int, SpoolEvent, mixed, int>
     */
    public function read(string $file): \Generator
    {
        $path = $this->path($file);
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Cannot open the spool file %s.', $file));
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException(\sprintf('Cannot lock the spool file %s.', $file));
            }

            $skipped = 0;
            while (false !== ($line = fgets($handle))) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                try {
                    $data = json_decode($line, true, self::JSON_DEPTH, \JSON_THROW_ON_ERROR);
                    $event = \is_array($data) ? SpoolEvent::fromArray($data) : throw new \InvalidArgumentException('A spool line is an object.');
                } catch (\JsonException|\InvalidArgumentException) {
                    ++$skipped;
                    continue;
                }

                yield $event;
            }

            return $skipped;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Deletes one claimed file; one already gone is fine (two prunes in a row).
     */
    public function delete(string $file): void
    {
        $path = $this->path($file);
        if (!@unlink($path) && file_exists($path)) {
            throw new \RuntimeException(\sprintf('Cannot delete the spool file %s.', $file));
        }
    }

    /**
     * @return list<string>
     */
    private function files(string $pattern): array
    {
        $names = @scandir($this->paths->spoolDir());
        if (false === $names) {
            return [];
        }

        // scandir() sorts by name.
        return array_values(array_filter($names, static fn (string $name): bool => 1 === preg_match($pattern, $name)));
    }

    private function path(string $file): string
    {
        if (1 !== preg_match(self::CLAIMED_FILE, $file) && 1 !== preg_match(self::DAY_FILE, $file)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a spool file name.', $file));
        }

        return $this->paths->spoolDir().'/'.$file;
    }
}
