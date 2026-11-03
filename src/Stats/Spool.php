<?php

declare(strict_types=1);

namespace App\Stats;

use App\Stats\ValueObject\SpoolEvent;

/**
 * Appends events to the spool day files `<spool>/<Y-m-d>.ndjson` (#47, contracts C2 and C3), the
 * day in Zurich. A file, never a database row: the beacon must not wake Neon. The ingest claims a
 * file by renaming it, so after taking the lock the writer checks that its handle is still the file
 * at the path, and opens the path again when it is not; the ingest locks a claimed file before
 * reading it, so a writer that locked first always finishes before the read.
 */
final readonly class Spool
{
    private const int TRIES = 3;

    public function __construct(
        private StatsPaths $paths,
    ) {
    }

    /**
     * @param list<SpoolEvent> $events
     */
    public function append(array $events): void
    {
        $lines = [];
        foreach ($events as $event) {
            $lines[$event->day()] = ($lines[$event->day()] ?? '').$event->toJson()."\n";
        }

        foreach ($lines as $day => $data) {
            $this->write($this->paths->spoolDir().'/'.$day.'.ndjson', $data);
        }
    }

    private function write(string $path, string $data): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create the spool directory %s.', $directory));
        }

        for ($try = 1; $try <= self::TRIES; ++$try) {
            $handle = @fopen($path, 'a');
            if (false === $handle) {
                throw new \RuntimeException(\sprintf('Cannot open the spool file %s.', $path));
            }

            try {
                if (!flock($handle, \LOCK_EX)) {
                    throw new \RuntimeException(\sprintf('Cannot lock the spool file %s.', $path));
                }
                clearstatcache(true, $path);
                $open = fstat($handle);
                $current = @stat($path);
                if (false === $open || false === $current || $open['ino'] !== $current['ino']) {
                    // An ingest renamed the file between our open and our lock: write to the new one.
                    continue;
                }

                if (fwrite($handle, $data) !== \strlen($data)) {
                    throw new \RuntimeException(\sprintf('Cannot write the spool file %s.', $path));
                }
                fflush($handle);
                flock($handle, \LOCK_UN);

                return;
            } finally {
                fclose($handle);
            }
        }

        throw new \RuntimeException(\sprintf('The spool file %s was renamed %d times while writing.', $path, self::TRIES));
    }
}
