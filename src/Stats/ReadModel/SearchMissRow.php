<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

/**
 * One query of the zero-result list (G12): the canonical query string (for the link to the listing), the
 * labelled parts of it in query order ("Anlass: Seminar") and how often it was searched in the week.
 */
final readonly class SearchMissRow
{
    /**
     * @param list<string> $parts
     */
    public function __construct(
        public string $query,
        public array $parts,
        public int $searches,
    ) {
    }
}
