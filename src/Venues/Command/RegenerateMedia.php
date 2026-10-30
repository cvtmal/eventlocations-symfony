<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * Re-render the image variants and re-publish the documents of one venue (by its public
 * number) or of all venues (null).
 */
final readonly class RegenerateMedia
{
    public function __construct(
        public ?int $venueNumber = null,
    ) {
    }
}
