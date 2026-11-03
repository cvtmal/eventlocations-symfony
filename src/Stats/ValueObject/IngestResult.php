<?php

declare(strict_types=1);

namespace App\Stats\ValueObject;

/**
 * What one `IngestStats` run did (#47): the spool files loaded, the events they held, the lines skipped
 * because they no longer parse, and the Zurich days of `stats_daily` rebuilt.
 */
final readonly class IngestResult
{
    public function __construct(
        public int $files,
        public int $events,
        public int $skipped,
        public int $days,
    ) {
    }
}
