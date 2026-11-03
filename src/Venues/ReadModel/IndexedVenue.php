<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Geo\ValueObject\Canton;

/**
 * One published venue in the site's index for language models (`/llms.txt`): what its line
 * shows, in the default locale.
 */
final readonly class IndexedVenue
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $zip,
        public ?string $townName,
        public ?Canton $canton,
        public int $capacityMax,
    ) {
    }
}
