<?php

declare(strict_types=1);

namespace App\Stats\Command;

/**
 * Deletes the claimed spool files already logged in `stats_ingest_files` and the salts of past days
 * (#47, contract C3). `IngestStats` dispatches it after its commit.
 */
final readonly class PruneStatsSpool
{
}
