<?php

declare(strict_types=1);

namespace App\Geo\Command;

/**
 * Import (or refresh) the official towns dataset from a CSV file. Idempotent.
 */
final readonly class ImportTowns
{
    public function __construct(
        public string $csvPath,
    ) {
    }
}
