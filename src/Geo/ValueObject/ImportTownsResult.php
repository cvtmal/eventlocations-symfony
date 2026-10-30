<?php

declare(strict_types=1);

namespace App\Geo\ValueObject;

/**
 * What app:geo:import-towns changed.
 */
final readonly class ImportTownsResult
{
    /**
     * @param list<string> $staleTowns towns in the database but no longer in the dataset (kept)
     */
    public function __construct(
        public int $inserted,
        public int $updated,
        public int $unchanged,
        public int $zips,
        public int $zipsAdded,
        public int $zipsRemoved,
        public array $staleTowns,
    ) {
    }
}
