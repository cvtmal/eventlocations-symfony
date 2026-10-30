<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

final readonly class VenueListPage
{
    /**
     * @param list<VenueListRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $perPage,
        public int $lastPage,
        public VenueStatusCounts $counts,
    ) {
    }
}
