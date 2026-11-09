<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * A venue as VenueKeyQuery finds it: enough to name it and to take it down.
 */
final readonly class VenueKey
{
    public function __construct(
        public string $id,
        public int $number,
        public string $name,
        public ?string $contentKey,
        public bool $isPublished,
    ) {
    }
}
