<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * Turns a venue's monthly report (G10) off, from the one-click link in the mail. Idempotent: a second click (or the
 * mail gateway's POST next to the reader's) changes nothing. Not audited: `opted_out_at` is the record.
 */
final readonly class OptOutOfOwnerReports
{
    public function __construct(
        public string $venueId,
    ) {
    }
}
