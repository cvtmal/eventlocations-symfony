<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

/**
 * What `SendOwnerReport` did for one venue (G10): sent (send-log row written), skipped (no longer due or nobody to
 * mail), or mail failed (no mail went out, no row, so the next night tries again).
 */
enum OwnerReportOutcome
{
    case Sent;
    case Skipped;
    case MailFailed;
}
