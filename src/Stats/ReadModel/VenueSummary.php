<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * One venue's figures over a period (#47), for the CMS tab and `/konto`. `detailVisitors` adds up
 * each day's distinct visitors of the detail page.
 */
final readonly class VenueSummary
{
    /**
     * @param array<string, int> $clicks every `ClickKind` value in case order (website, email, phone, map, instagram), 0 when none
     */
    public function __construct(
        public int $detailViews,
        public int $detailVisitors,
        public int $impressions,
        public array $clicks,
        public int $clickTotal,
        public int $merklisteAdds,
        public int $inquiries,
    ) {
    }
}
