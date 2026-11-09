<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * Deletes the send-log rows of the monthly report (G10) older than `OwnerReport::RETENTION_MONTHS`, counted from the
 * first day of the current Zurich month. The handler returns how many it deleted; a second run deletes nothing. Run
 * nightly by `app:portal:owner-reports` (step `owner-reports` of `bin/cron-daily`).
 */
final readonly class PurgeOwnerReports
{
}
