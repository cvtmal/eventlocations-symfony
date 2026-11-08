<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * One ISO week of searches without results (G12): all of them (`searches`, `queries` distinct) and the most
 * frequent `SearchMissQuery::TOP` queries.
 */
final readonly class SearchMissWeek
{
    /**
     * @param list<SearchMissRow> $top by searches, most first
     */
    public function __construct(
        public \DateTimeImmutable $start,
        public int $searches,
        public int $queries,
        public array $top,
    ) {
    }
}
