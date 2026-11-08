<?php

declare(strict_types=1);

namespace App\Stats\Command;

/**
 * D44: deletes the weekly rows of `search_misses` (G12) whose week began `RETENTION_MONTHS` ago within a day
 * (`week_start <= now - 12 months + 1 day`, dates in Europe/Zurich), so a nightly run is never late and no search,
 * not even the first of its week, stays longer than /datenschutz's "höchstens 12 Monate". The handler returns how
 * many rows it deleted; a deleted row is gone, so a second run deletes nothing. Run nightly by `bin/cron-daily`
 * (step `search-misses-purge`).
 */
final readonly class PurgeSearchMisses
{
    /** /datenschutz says "nach höchstens 12 Monaten" (`LegalPagesTest` pins both). */
    public const int RETENTION_MONTHS = 12;
}
