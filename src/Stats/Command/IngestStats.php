<?php

declare(strict_types=1);

namespace App\Stats\Command;

/**
 * Loads the beacon's spool files into `stats_events` and rebuilds `stats_daily` for their days (#47). The
 * nightly cron (`app:stats:ingest`) and the staff button dispatch the same command; it is safe to run
 * twice or at once. The handler returns an `IngestResult`.
 */
final readonly class IngestStats
{
}
