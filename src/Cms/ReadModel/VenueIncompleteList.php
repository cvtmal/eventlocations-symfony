<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * The first incomplete venues and how many there are in all, for the dashboard widget.
 */
final readonly class VenueIncompleteList
{
    /**
     * @param list<VenueListRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
    ) {
    }
}
