<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * A manager creates a location of their own (#45): unpublished, Basic, at the town's centre, with active access
 * and a draft submission. Not auditable itself: the two commands it nests leave the audit rows.
 */
final readonly class CreateOwnLocation
{
    public const int MAX_UNFINISHED = 10;

    public function __construct(
        public string $portalUserId,
        public string $name,
        public int $capacityMax,
        public string $town,
    ) {
    }
}
