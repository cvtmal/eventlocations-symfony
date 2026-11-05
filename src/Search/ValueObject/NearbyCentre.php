<?php

declare(strict_types=1);

namespace App\Search\ValueObject;

/**
 * The centre of the near-me page's 30 km circle (#52): a browser position (no label, no
 * listing place) or a typed PLZ/Ort, labelled "8712 Stäfa", whose `listingPlace` (the ZIP or
 * the town UUID) feeds the "50 km" link when nothing is near.
 */
final readonly class NearbyCentre
{
    public function __construct(
        public NearbyPoint $point,
        public ?string $label,
        public ?string $listingPlace,
    ) {
    }
}
