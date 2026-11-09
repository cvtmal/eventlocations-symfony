<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * One venue's monthly report mail (G10, `app:portal:owner-reports`) for the month that starts on `month` (`Y-m-d`).
 * The handler sends it to the venue's active managers and writes the send-log row, and answers false when the report
 * is no longer due, nobody is there to mail, or no mail went out (then the next night tries again, up to the 7th).
 * Not audited: it changes no venue.
 */
final readonly class SendOwnerReport
{
    public function __construct(
        public string $venueId,
        public string $month,
    ) {
    }
}
