<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * The status tabs' numbers, counted under every filter but the status, so a tab says what clicking it
 * gives. `incomplete` overlaps the other two.
 */
final readonly class VenueStatusCounts
{
    public function __construct(
        public int $all,
        public int $published,
        public int $unpublished,
        public int $incomplete,
    ) {
    }

    public function forStatus(?string $status): int
    {
        return match ($status) {
            VenueListFilter::STATUS_PUBLISHED => $this->published,
            VenueListFilter::STATUS_UNPUBLISHED => $this->unpublished,
            VenueListFilter::STATUS_INCOMPLETE => $this->incomplete,
            default => $this->all,
        };
    }
}
