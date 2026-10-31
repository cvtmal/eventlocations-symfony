<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * An open task on the venue panel. `dueOn` is `YYYY-MM-DD`.
 */
final readonly class VenueCompanyTask
{
    public function __construct(
        public string $title,
        public string $dueOn,
    ) {
    }
}
