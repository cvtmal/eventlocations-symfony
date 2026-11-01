<?php

declare(strict_types=1);

namespace App\Crm\Command;

/**
 * Brings every derived company stage up to date (D10): a contract ending flips a stage with no write of anybody. The
 * daily cron dispatches it, so it is not audited, like `PurgeInquiries`.
 */
final readonly class RefreshCompanyStages
{
}
