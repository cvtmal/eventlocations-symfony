<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * One venue's figures over a period on the staff statistics screen (#47). `clicks` is the sum
 * over every `ClickKind`.
 */
final readonly class VenueRow
{
    public function __construct(
        public int $number,
        public string $name,
        public int $detailViews,
        public int $impressions,
        public int $clicks,
        public int $merklisteAdds,
        public int $inquiries,
    ) {
    }
}
