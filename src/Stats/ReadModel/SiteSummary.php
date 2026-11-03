<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * The site's figures over a period (#47). `visits` adds up each day's distinct visitors, so a
 * visitor who comes back on another day counts again (Open Decision 5).
 */
final readonly class SiteSummary
{
    public function __construct(
        public int $visits,
        public int $pageViews,
        public int $detailViews,
        public int $contactClicks,
        public int $merklisteAdds,
        public int $inquiries,
    ) {
    }
}
